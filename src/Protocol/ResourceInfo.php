<?php

declare(strict_types=1);

namespace X402\Protocol;

/**
 * v2 resource descriptor — hoisted out of `accepts[]` entries (where it
 * lived in v1) into a single top-level object on the 402 challenge.
 *
 * Reference: x402 spec v2 §5.1 (PaymentRequired body).
 */
final readonly class ResourceInfo
{
    public function __construct(
        public string $url,
        public ?string $description = null,
        public ?string $mimeType = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'url' => $this->url,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
