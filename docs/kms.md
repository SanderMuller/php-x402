# KMS-backed wallets

`PrivateKeyWallet` stores the signing key in process memory — fine for
tests and CLI tools, **not** fine for production. This page shows the
KMS-backed implementations the package ships, and how to plug in any
other KMS / HSM / remote signer by extending `X402\Client\KmsWallet`.

## The contract

```php
namespace X402\Client;

interface Wallet
{
    public function address(): string;                 // 0x-prefixed 20-byte hex
    public function signDigest(string $digest): string; // 0x-prefixed 65-byte hex (r || s || v)
}
```

The signer takes a 32-byte EIP-712 digest (already hashed by
`Eip712Hasher::digest()`) and returns a 65-byte `r || s || v` signature.
`v` is the **recovery id**. Emit `27` or `28` for compatibility with
every Ethereum tool that consumes the signature (`SignatureVerifier`
also accepts the raw recovery id `0` or `1`, but most downstream
consumers expect `27`/`28`).

> [!IMPORTANT]
> Do **not** use the EIP-155 transaction-style `v = 27 + chainId * 2 + 35`
> here. That form is for raw transaction signing, not EIP-712 typed
> data. `SignatureVerifier` accepts only `{0, 1, 27, 28}` and fails
> fast on any other value — EIP-155-shaped `v` (29, 30, 4393, …)
> trips an `Invalid signature v byte` error before public-key
> recovery runs.

Three rules every KMS adapter must follow:

1. **Fetch the address once.** Calling the KMS for every `address()`
   invocation is wasteful — the address is derived from the public key
   and never changes for a given key.
2. **Return raw `r || s || v`, not DER-encoded ASN.1.** AWS / GCP /
   Azure all return DER by default; you must convert.
3. **Normalize `s` to the lower half of the secp256k1 curve order.**
   Ethereum rejects "high-s" signatures (EIP-2). KMS providers don't do
   this for you.

`X402\Client\KmsWallet` is an abstract that owns all three rules.
Subclasses only implement two operations:

```php
abstract protected function fetchPublicKeySpki(): string;
abstract protected function rawSign(string $digestBytes): string;
```

The abstract handles SPKI → address derivation (memoised), DER → raw
`(r, s)` decoding via `X402\Support\Asn1DerDecoder`, EIP-2 low-s
normalisation, and recovery-id brute force via
`X402\Schemes\Evm\EcdsaRecovery`.

## AWS KMS

```php
use Aws\Kms\KmsClient;
use X402\Client\AwsKmsWallet;
use X402\Client\PayingClient;

$wallet = new AwsKmsWallet(
    kms:   new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
    keyId: 'arn:aws:kms:us-east-1:123:key/abcd-...',
);

$client = new PayingClient(
    inner:  $psr18Client,
    wallet: $wallet,
);
```

The KMS key MUST be `ECC_SECG_P256K1` with a usage of `SIGN_VERIFY`.
Any other curve or usage fails at the first signing attempt with a
KMS-side `InvalidKeyUsageException`.

`aws/aws-sdk-php` is in `suggest`, not `require` — adopters using
PrivateKey / Hd / a different KMS don't need it. Add it explicitly
in your application:

```bash
composer require aws/aws-sdk-php
```

## Other providers

The shape is identical for any KMS that exposes ECDSA secp256k1 — extend
`KmsWallet` and wire two methods. GCP Cloud KMS sketch:

```php
use Google\Cloud\Kms\V1\Client\KeyManagementServiceClient;
use Google\Cloud\Kms\V1\AsymmetricSignRequest;
use Google\Cloud\Kms\V1\GetPublicKeyRequest;
use X402\Client\KmsWallet;

final class GcpKmsWallet extends KmsWallet
{
    public function __construct(
        private readonly KeyManagementServiceClient $kms,
        private readonly string $keyVersionName,
    ) {}

    protected function fetchPublicKeySpki(): string
    {
        $pem = $this->kms->getPublicKey(
            (new GetPublicKeyRequest())->setName($this->keyVersionName)
        )->getPem();

        // GCP returns PEM; strip the BEGIN/END envelope and base64-decode.
        $body = preg_replace('/-----.*?-----|\s+/', '', $pem);
        $der = base64_decode($body, true);

        if ($der === false) {
            throw new RuntimeException('Malformed PEM from GCP KMS.');
        }

        return $der;
    }

    protected function rawSign(string $digestBytes): string
    {
        $digest = new \Google\Cloud\Kms\V1\Digest();
        $digest->setSha256($digestBytes);

        $response = $this->kms->asymmetricSign(
            (new AsymmetricSignRequest())
                ->setName($this->keyVersionName)
                ->setDigest($digest)
        );

        return $response->getSignature();
    }
}
```

The same pattern fits **Azure Key Vault**
(`microsoft/azure-key-vault-keys`, algorithm `ES256K`), **HashiCorp
Vault** (Transit secrets engine with `ecdsa-p256k1`), **HSMs over
PKCS#11**, and remote signers like Web3Signer / Vouch. Pull request
welcome for first-party `GcpKmsWallet` / `HashicorpVaultWallet` once
real adoption is in place — until then the sketch above is the
canonical shape.

## Don't

- **Don't cache the digest → signature mapping.** Every signature is
  per-nonce; caching defeats replay protection.
- **Don't let `signDigest()` block the request loop.** KMS calls take
  ~50–200 ms; if you're signing in a hot path, consider pre-signing
  authorizations and queuing them.
- **Don't sign without confirming the digest came from
  `Eip712Hasher::digest()`.** Signing arbitrary 32-byte strings turns
  the wallet into an oracle for whatever the attacker wants signed.

## Testing

Use `X402\Testing\FakeFacilitator` to avoid network during tests, and
`X402\Client\PrivateKeyWallet` (with a throwaway key) to verify the
round-trip without real KMS calls. Mock the underlying SDK client with
Mockery / PHPUnit's `createMock()` when you need to exercise the
`AwsKmsWallet` / `GcpKmsWallet` path itself — see
`tests/Unit/Client/AwsKmsWalletTest.php` for the canonical pattern.
