<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use Elliptic\EC;

/**
 * Client-side signer for Permit2 `permitWitnessTransferFrom`.
 *
 * Produces the 65-byte `(r || s || v)` signature that goes into the
 * `signature` field alongside the `permit2Authorization` body.
 *
 * Counterpart to `AuthorizationSigner` (EIP-3009). Use this when the
 * challenge declares `extra.assetTransferMethod = "permit2"`.
 */
final readonly class Permit2Signer
{
    public function __construct(
        private Permit2Hasher $hasher = new Permit2Hasher(),
    ) {}

    /**
     * @param  string  $privateKey  0x-prefixed 32-byte hex private key.
     * @return array{
     *     signature: string,
     *     permit2Authorization: array{
     *         permitted: array{token: string, amount: string},
     *         from: string,
     *         spender: string,
     *         nonce: string,
     *         deadline: string,
     *         witness: array{to: string, validAfter: string},
     *     },
     * }
     */
    public function sign(int $chainId, Permit2Authorization $auth, string $privateKey): array
    {
        $digest = $this->hasher->digest(
            $chainId,
            ['token' => $auth->token, 'amount' => $auth->amount],
            ['spender' => $auth->spender, 'nonce' => $auth->nonce, 'deadline' => $auth->deadline],
            ['to' => $auth->witnessTo, 'validAfter' => $auth->validAfter],
        );

        $digestHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;
        $key = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        $ec = new EC('secp256k1');
        $signingKey = $ec->keyFromPrivate($key, 'hex');
        $sig = $signingKey->sign($digestHex, false, ['canonical' => true]);

        return [
            'signature' => SignatureExporter::toHex65($sig),
            'permit2Authorization' => $auth->toArray(),
        ];
    }
}
