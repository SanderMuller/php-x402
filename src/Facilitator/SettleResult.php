<?php

declare(strict_types=1);

namespace X402\Facilitator;

final readonly class SettleResult
{
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public string $payer,
        public ?string $errorReason = null,
    ) {}
}
