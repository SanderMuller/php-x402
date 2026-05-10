<?php

declare(strict_types=1);

namespace X402\Protocol;

/**
 * Server-issued settlement receipt (PAYMENT-RESPONSE header on HTTP transport,
 * `result._meta["x402/payment-response"]` on MCP transport).
 *
 * Spec v2 §5.3.2 fields: `success` (bool, required), `transaction` (string,
 * required, empty on failure), `network` (string, required), `payer`
 * (optional), `amount` (optional — actual settled atomic units, used by
 * the `upto` scheme), `extensions` (optional), `tracker` (optional —
 * facilitator-issued correlation id surfaced on async-settlement
 * pending receipts; mirrors `SettleResult::$tracker`).
 *
 * **Wire-shape stability.** `tracker` is only emitted by `toArray()`
 * when non-null and non-empty, so settled-state receipts (which never
 * carry a tracker) serialize byte-for-byte identical to pre-tracker
 * versions. The new field appears only on the new pending state,
 * which previously had no on-the-wire representation; consumers that
 * don't handle pending continue to deserialize successful receipts
 * unchanged.
 */
final readonly class PaymentResponse
{
    /**
     * @param  array<string, mixed>|null  $extensions  v2 extension payloads echoed back from settlement.
     * @param  string|null  $tracker  Facilitator-issued correlation id surfaced on async-settlement
     *                                receipts. Non-null + non-empty when the settlement is pending;
     *                                null for terminal-state receipts. Mirrors `SettleResult::$tracker`.
     */
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public string $payer,
        public ?string $amount = null,
        public ?array $extensions = null,
        public ?string $tracker = null,
    ) {}

    public function isPending(): bool
    {
        return ! $this->success && $this->tracker !== null && $this->tracker !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'success' => $this->success,
            'transaction' => $this->transaction,
            'network' => $this->network,
            'payer' => $this->payer,
        ];

        if ($this->amount !== null) {
            $out['amount'] = $this->amount;
        }

        if ($this->extensions !== null && $this->extensions !== []) {
            $out['extensions'] = $this->extensions;
        }

        if ($this->tracker !== null && $this->tracker !== '') {
            $out['tracker'] = $this->tracker;
        }

        return $out;
    }

    public function toHeader(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
