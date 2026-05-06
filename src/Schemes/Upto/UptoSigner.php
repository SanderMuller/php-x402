<?php

declare(strict_types=1);

namespace X402\Schemes\Upto;

use Elliptic\EC;
use X402\Schemes\Evm\SignatureExporter;

/**
 * Client-side signer for the `upto` scheme. Produces the 65-byte
 * `(r || s || v)` signature plus the `uptoAuthorization` body for
 * `PaymentSignature.payload`.
 */
final readonly class UptoSigner
{
    public function __construct(
        private UptoHasher $hasher = new UptoHasher(),
    ) {}

    /**
     * @param  string  $privateKey  0x-prefixed 32-byte hex private key.
     * @return array{
     *     signature: string,
     *     uptoAuthorization: array{
     *         permitted: array{token: string, amount: string},
     *         from: string,
     *         spender: string,
     *         nonce: string,
     *         deadline: string,
     *         witness: array{to: string, validAfter: string, facilitator: string},
     *     },
     * }
     */
    public function sign(int $chainId, UptoAuthorization $auth, string $privateKey): array
    {
        $digest = $this->hasher->digest(
            $chainId,
            ['token' => $auth->token, 'amount' => $auth->maxAmount],
            ['spender' => $auth->spender, 'nonce' => $auth->nonce, 'deadline' => $auth->deadline],
            ['to' => $auth->witnessTo, 'validAfter' => $auth->validAfter, 'facilitator' => $auth->facilitator],
        );

        $digestHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;
        $key = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        $ec = new EC('secp256k1');
        $signingKey = $ec->keyFromPrivate($key, 'hex');
        $sig = $signingKey->sign($digestHex, false, ['canonical' => true]);

        return [
            'signature' => SignatureExporter::toHex65($sig),
            'uptoAuthorization' => $auth->toArray(),
        ];
    }
}
