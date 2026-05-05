<?php

declare(strict_types=1);

namespace X402\Protocol;

/**
 * Server-issued settlement receipt (PAYMENT-RESPONSE header).
 */
final readonly class PaymentResponse
{
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public string $payer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction' => $this->transaction,
            'network' => $this->network,
            'payer' => $this->payer,
        ];
    }

    public function toHeader(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
