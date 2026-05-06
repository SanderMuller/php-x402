<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Settlement receipt from the facilitator.
 *
 * Spec v2 §5.3.2 fields: `success` (bool, required), `transaction` (string,
 * required, empty on failure), `network` (string, required), `payer`
 * (optional), `amount` (optional — actual settled atomic units, used by
 * the `upto` scheme), `errorReason` (optional).
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
    ) {}
}
