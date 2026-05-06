<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Facilitator `/verify` response (spec v2 §5.4.2).
 */
final readonly class VerifyResult
{
    /**
     * @param  array<string, mixed>|null  $extensions  v2 extension payloads echoed by the facilitator.
     */
    public function __construct(
        public bool $isValid,
        public ?string $invalidReason = null,
        public ?string $payer = null,
        public ?array $extensions = null,
    ) {}
}
