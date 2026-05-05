<?php

declare(strict_types=1);

namespace X402\Facilitator;

final readonly class VerifyResult
{
    public function __construct(
        public bool $isValid,
        public ?string $invalidReason = null,
        public ?string $payer = null,
    ) {}
}
