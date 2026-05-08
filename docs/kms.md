# KMS-backed wallets

`PrivateKeyWallet` stores the signing key in process memory — fine for
tests and CLI tools, **not** fine for production. This page shows how to
implement `X402\Client\Wallet` against a KMS so the private key never
leaves the secure enclave.

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
`v` is the **recovery id**, always `27` or `28` (equivalent to `0` or
`1` shifted by 27).

> [!IMPORTANT]
> Do **not** use the EIP-155 transaction-style `v = 27 + chainId * 2 + 35`
> here. That form is for raw transaction signing, not EIP-712 typed
> data. The x402 verifier (`SignatureVerifier`) accepts only `27/28`
> (or `0/1`); EIP-155-shaped values produce signatures the rest of
> this library treats as invalid.

Three rules every KMS adapter must follow:

1. **Fetch the address once at construction.** Calling the KMS for every
   `address()` invocation is wasteful — the address is derived from the
   public key and never changes for a given key.
2. **Return raw `r || s || v`, not DER-encoded ASN.1.** AWS KMS returns
   DER by default; you must convert.
3. **Normalize `s` to the lower half of the secp256k1 curve order.**
   Ethereum rejects "high-s" signatures (EIP-2). KMS providers don't do
   this for you.

## AWS KMS reference impl

```php
<?php

declare(strict_types=1);

namespace App\Wallets;

use Aws\Kms\KmsClient;
use kornrunner\Keccak;
use X402\Client\Wallet;

final class AwsKmsWallet implements Wallet
{
    /** secp256k1 curve order (half) — see EIP-2. */
    private const string SECP256K1_HALF_N = '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0';

    private readonly string $address;

    public function __construct(
        private readonly KmsClient $kms,
        private readonly string $keyId,
    ) {
        $this->address = $this->deriveAddress();
    }

    public function address(): string
    {
        return $this->address;
    }

    public function signDigest(string $digest): string
    {
        $digestBytes = hex2bin(self::strip0x($digest));
        if ($digestBytes === false || strlen($digestBytes) !== 32) {
            throw new \InvalidArgumentException('digest must be 0x-prefixed 32-byte hex.');
        }

        $result = $this->kms->sign([
            'KeyId' => $this->keyId,
            'Message' => $digestBytes,
            'MessageType' => 'DIGEST',                    // skip KMS's own SHA-256 wrap
            'SigningAlgorithm' => 'ECDSA_SHA_256',
        ]);

        // KMS returns DER-encoded { r, s }. Parse into raw bytes.
        [$r, $s] = self::parseDerSignature($result->get('Signature'));

        // EIP-2: enforce low-s. Flip if needed.
        if (gmp_cmp(gmp_init($s, 16), gmp_init(self::SECP256K1_HALF_N, 16)) > 0) {
            $n = gmp_init('fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141', 16);
            $s = str_pad(gmp_strval(gmp_sub($n, gmp_init($s, 16)), 16), 64, '0', STR_PAD_LEFT);
        }

        // v = recovery id (27 or 28). Recover by trying both candidates and
        // matching against the known address. Implementation left as exercise —
        // see simplito/elliptic-php's KeyPair::recoverPubKey, or call
        // EthereumECDSARecovery from any of the published recovery libraries.
        $v = $this->recoveryId($digestBytes, $r, $s);

        return '0x'.$r.$s.dechex($v);
    }

    private function deriveAddress(): string
    {
        $der = $this->kms->getPublicKey(['KeyId' => $this->keyId])->get('PublicKey');
        // GetPublicKey returns DER-encoded SubjectPublicKeyInfo. Strip the
        // 23-byte prefix (algorithm OID + bit-string header) to get the
        // raw 65-byte uncompressed point (0x04 || X || Y).
        $uncompressed = substr($der, -65);
        $xy = substr($uncompressed, 1);                   // drop 0x04 prefix
        $hash = Keccak::hash($xy, 256);

        return '0x'.substr($hash, -40);
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }

    /**
     * Parse `SEQUENCE { r INTEGER, s INTEGER }` into [r-hex, s-hex].
     *
     * @return array{0: string, 1: string}
     */
    private static function parseDerSignature(string $der): array
    {
        // Minimal DER parser — production code should use a battle-tested
        // ASN.1 library (phpseclib3\File\ASN1, etc).
        // ... (omitted for brevity — see phpseclib3 ASN.1 utilities)
        throw new \RuntimeException('Implement DER parser; reference: phpseclib3\File\ASN1.');
    }

    private function recoveryId(string $digest, string $rHex, string $sHex): int
    {
        // For each candidate v in {27, 28}, recover the public key and
        // compare the derived address to $this->address. Return the
        // matching v.
        // ... (omitted — see simplito/elliptic-php KeyPair::recoverPubKey)
        throw new \RuntimeException('Implement v recovery; reference: simplito/elliptic-php.');
    }
}
```

Wire it up:

```php
$wallet = new AwsKmsWallet(
    kms: new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
    keyId: 'arn:aws:kms:us-east-1:123:key/abcd-...',
);

$client = new \X402\Client\PayingClient(
    inner: $psr18Client,
    wallet: $wallet,
);
```

## Other providers

The shape is identical for any KMS that exposes ECDSA secp256k1:

- **GCP Cloud KMS** — `google/cloud-kms`. `asymmetricSign` returns DER;
  same `s` normalization + `v` recovery.
- **Azure Key Vault** — `microsoft/azure-key-vault-keys`. Use
  `algorithm: 'ES256K'`; same handling.
- **HSM (PKCS#11)** — `mxgmn/pkcs11-php` or a sidecar service. Same
  shape, but address derivation may need an `EC_POINT_OCT` query.
- **Remote signer** (Web3Signer, Vouch) — wrap the HTTP API; the
  remote already returns `r || s || v` typically, no DER parsing.

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

Use `X402\Testing\StubFacilitator` to avoid network during tests, and
`X402\Client\PrivateKeyWallet` (with a throwaway key) to verify the
round-trip without real KMS calls. Production hosts swap in their KMS
adapter at the boundary.
