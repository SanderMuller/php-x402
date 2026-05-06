<?php

declare(strict_types=1);

namespace X402\Schemes\Stellar;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\SchemeContract;

/**
 * Stellar `exact` scheme — Soroban auth-entries-only signing.
 *
 * Spec: `specs/schemes/exact/scheme_exact_stellar.md`.
 *
 * Stellar is **v2-only** — the spec explicitly does not support v1.
 *
 * **Status: scaffold only.** Stellar signing uses ed25519 over Soroban
 * authorization entries. The implementation needs:
 *
 * - Stellar SDK (e.g. soneso/stellar-php-sdk)
 * - Soroban auth entry construction + signing
 * - Ledger-time math for `validBefore` (Stellar uses ledger sequence
 *   numbers, not unix timestamps)
 *
 * Tracked as a v0.x roadmap item.
 */
final class ExactScheme implements SchemeContract
{
    public const NAME = 'exact';

    /**
     * @return list<string>
     */
    public function supportedNetworks(): array
    {
        return ['stellar:pubnet', 'stellar:testnet'];
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void
    {
        throw InvalidPaymentException::with(
            ErrorReason::UnsupportedScheme,
            'Stellar scheme is not yet implemented in php-x402. Stellar is v2-only — use a different network for v0.1.',
        );
    }
}
