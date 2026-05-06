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
 * the `upto` scheme), `extensions` (optional).
 */
final readonly class PaymentResponse
{
    /**
     * @param  array<string, mixed>|null  $extensions  v2 extension payloads echoed back from settlement.
     */
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public string $payer,
        public ?string $amount = null,
        public ?array $extensions = null,
    ) {}

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

        return $out;
    }

    public function toHeader(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
