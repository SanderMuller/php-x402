<?php

declare(strict_types=1);

namespace X402\Protocol;

/**
 * Server-issued payment challenge sent with HTTP 402.
 *
 * Maps to the `accepts[]` entry in the x402 spec.
 */
final readonly class PaymentRequired
{
    /**
     * @param  string  $scheme  Payment scheme (e.g. "exact").
     * @param  string  $network  CAIP-2 network identifier (e.g. "eip155:8453" for Base).
     * @param  string  $maxAmountRequired  Atomic-unit amount (e.g. USDC = 6 decimals → "10000" = $0.01).
     * @param  string  $asset  ERC-20 contract address of the settlement asset.
     * @param  string  $payTo  Recipient EVM address.
     * @param  int  $maxTimeoutSeconds  Validity window for the authorization.
     * @param  string|null  $resource  Resource URI being paid for.
     * @param  string|null  $description  Human-readable description.
     * @param  string|null  $mimeType  Expected response mime-type.
     * @param  array<string, mixed>  $extra  Scheme-specific extra fields.
     */
    public function __construct(
        public string $scheme,
        public string $network,
        public string $maxAmountRequired,
        public string $asset,
        public string $payTo,
        public int $maxTimeoutSeconds = 60,
        public ?string $resource = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'scheme' => $this->scheme,
            'network' => $this->network,
            'maxAmountRequired' => $this->maxAmountRequired,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'resource' => $this->resource,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
            'extra' => $this->extra === [] ? null : $this->extra,
        ], static fn ($v) => $v !== null);
    }
}
