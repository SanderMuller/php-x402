<?php

declare(strict_types=1);

namespace X402\Client;

use Aws\Kms\KmsClient;
use RuntimeException;

/**
 * AWS KMS implementation of {@see KmsWallet}.
 *
 * Wires the abstract's `fetchPublicKeySpki()` to `kms:GetPublicKey` and
 * `rawSign()` to `kms:Sign` with the parameters AWS requires for EVM
 * EIP-712 typed-data signing:
 *
 *   - `MessageType = DIGEST` — we hand AWS an already-hashed digest;
 *     without this flag AWS would SHA-256 the input again and produce a
 *     signature over the wrong value.
 *   - `SigningAlgorithm = ECDSA_SHA_256` — secp256k1 keys in KMS sign
 *     with SHA-256 regardless of the inner hash you used to build the
 *     digest. The EVM digest comes from Keccak-256, but the wrapping AWS
 *     signing parameter is named after the post-hash AWS itself would
 *     apply if `MessageType` were `RAW`. The actual signing primitive
 *     operates on the 32-byte digest you provide.
 *
 * The KMS key MUST be `ECC_SECG_P256K1` with a usage of `SIGN_VERIFY`.
 * Any other curve or usage fails at the first signing attempt.
 *
 * Authentication, region selection, retry policy, and the rest of the
 * AWS client configuration live entirely in the `KmsClient` instance you
 * pass in — this class is intentionally policy-free.
 */
final class AwsKmsWallet extends KmsWallet
{
    public function __construct(
        private readonly KmsClient $kms,
        private readonly string $keyId,
    ) {}

    protected function fetchPublicKeySpki(): string
    {
        $result = $this->kms->getPublicKey(['KeyId' => $this->keyId]);
        $spki = $result->get('PublicKey');

        if (! is_string($spki) || $spki === '') {
            throw new RuntimeException(sprintf(
                'AWS KMS GetPublicKey returned no PublicKey blob for key "%s".',
                $this->keyId,
            ));
        }

        return $spki;
    }

    protected function rawSign(string $digestBytes): string
    {
        $result = $this->kms->sign([
            'KeyId' => $this->keyId,
            'Message' => $digestBytes,
            'MessageType' => 'DIGEST',
            'SigningAlgorithm' => 'ECDSA_SHA_256',
        ]);

        $sig = $result->get('Signature');

        if (! is_string($sig) || $sig === '') {
            throw new RuntimeException(sprintf(
                'AWS KMS Sign returned no Signature blob for key "%s".',
                $this->keyId,
            ));
        }

        return $sig;
    }
}
