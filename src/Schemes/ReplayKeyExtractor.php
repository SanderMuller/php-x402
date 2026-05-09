<?php

declare(strict_types=1);

namespace X402\Schemes;

use X402\Protocol\PaymentSignature;

/**
 * Optional capability — a scheme that can surface its (from, nonce,
 * expiresAt) triple for in-process replay claiming via
 * `NonceStoreContract::claim()`.
 *
 * Schemes that do NOT implement this interface (or whose implementation
 * returns null) defer replay protection to the facilitator's on-chain
 * nonce check. That is correct for chains where in-process pre-claim
 * adds no value (Stellar / Solana settlement is sequential per source
 * account) or where the wire shape has no compact replay coordinate
 * (ERC-7710 delegations).
 *
 * `PaymentEnforcer::guardReplay()` checks `instanceof` and skips the
 * claim when the scheme has not opted in. This keeps the addition
 * non-breaking for custom downstream `SchemeContract` implementations.
 */
interface ReplayKeyExtractor
{
    /**
     * Extract the replay coordinate for `$signature`. Return null when
     * the signature does not carry a usable triple (malformed payload,
     * or this scheme defers entirely to the facilitator).
     *
     * Implementations MUST read only fields they themselves validate
     * in `verifyShape()` — caller-injected extra payload keys are
     * ignored on schemes that don't recognize them.
     *
     * `expiresAt` is the absolute epoch second past which the
     * authorization is no longer settle-able (e.g. EIP-3009
     * `validBefore`, Permit2 / Upto `deadline`). The enforcer derives
     * the nonce-store TTL from it.
     *
     * @return array{from: string, nonce: string, expiresAt: int}|null
     */
    public function replayKey(PaymentSignature $signature): ?array;
}
