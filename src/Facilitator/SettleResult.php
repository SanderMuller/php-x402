<?php

declare(strict_types=1);

namespace X402\Facilitator;

use InvalidArgumentException;

/**
 * Settlement receipt from the facilitator.
 *
 * Spec v2 §5.3.2 fields: `success` (bool, required), `transaction` (string,
 * required, empty on failure), `network` (string, required), `payer`
 * (optional), `amount` (optional — actual settled atomic units, used by
 * the `upto` scheme), `errorReason` (optional).
 *
 * **Pending state.** Async-settlement facilitators accept the
 * authorization synchronously and post the on-chain outcome later via
 * webhook. Build a pending receipt with `SettleResult::pending(...)` and
 * detect it with `isPending()`. The ctor enforces the invariant that
 * `success === true` and a non-empty `tracker` are mutually exclusive —
 * a code path short-circuiting on `success` would otherwise persist a
 * settled row for an unsettled payment.
 */
final readonly class SettleResult
{
    /**
     * @param  array<string, mixed>|null  $extensions  v2 extension payloads echoed by the facilitator.
     */
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public string $payer,
        public ?string $errorReason = null,
        /**
         * v2 only — actual atomic-unit amount settled. Equals the
         * authorized maximum on `exact` schemes; can be less on `upto`.
         */
        public ?string $amount = null,
        public ?array $extensions = null,
        /**
         * Facilitator-issued correlation id surfaced for async settlement.
         * Non-null + non-empty on pending receipts; null otherwise. The
         * webhook consumer matches inbound deliveries against this value.
         */
        public ?string $tracker = null,
    ) {
        if ($success && $tracker !== null && $tracker !== '') {
            throw new InvalidArgumentException(
                'SettleResult cannot be both success=true and have a non-empty tracker; '
                . 'use SettleResult::pending() for in-flight settlements.',
            );
        }
    }

    /**
     * Factory for an in-flight (async) settlement receipt. The buyer has
     * a signed authorization but the on-chain transfer has not confirmed
     * — the host MUST NOT deliver the wrapped response until a settlement
     * webhook resolves the tracker.
     */
    public static function pending(string $tracker, string $network, string $payer = ''): self
    {
        if ($tracker === '') {
            throw new InvalidArgumentException('SettleResult::pending() requires a non-empty tracker.');
        }

        return new self(
            success: false,
            transaction: '',
            network: $network,
            payer: $payer,
            tracker: $tracker,
        );
    }

    public function isPending(): bool
    {
        return ! $this->success && $this->tracker !== null && $this->tracker !== '';
    }
}
