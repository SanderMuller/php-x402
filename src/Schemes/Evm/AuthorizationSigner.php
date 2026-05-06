<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use Elliptic\EC;
use Random\RandomException;

/**
 * Client-side signer for EIP-3009 transferWithAuthorization.
 *
 * Operator-supplied private key signs a 32-byte digest produced by Eip712Hasher.
 * The output is the 65-byte (r || s || v) signature suitable for the
 * `signature` field of the EVM exact-scheme payload.
 */
final readonly class AuthorizationSigner
{
    public function __construct(
        private Eip712Hasher $hasher = new Eip712Hasher(),
    ) {}

    /**
     * @param  array{name: string, version: string, chainId: int, verifyingContract: string}  $domain
     * @param  array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}  $message
     * @param  string  $privateKey  0x-prefixed 32-byte hex private key.
     * @return array{signature: string, authorization: array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}}
     */
    public function sign(array $domain, array $message, string $privateKey): array
    {
        $digest = $this->hasher->digest($domain, $message);
        $digestHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;

        $key = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        $ec = new EC('secp256k1');
        $signingKey = $ec->keyFromPrivate($key, 'hex');
        $sig = $signingKey->sign($digestHex, false, ['canonical' => true]);

        return [
            'signature' => SignatureExporter::toHex65($sig),
            'authorization' => $message,
        ];
    }

    /**
     * Generate a random 32-byte nonce for EIP-3009. The nonce only needs to be
     * unique per (token, from) — not sequential.
     *
     * @throws RandomException
     */
    public static function randomNonce(): string
    {
        return '0x' . bin2hex(random_bytes(32));
    }
}
