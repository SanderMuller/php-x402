<?php

declare(strict_types=1);

namespace X402\Protocol;

/**
 * Server-issued payment requirement (one entry in `accepts[]`).
 *
 * Stores fields in a version-neutral shape; serialization is split into
 * `toArrayV1()` (legacy field names + inlined resource fields) and
 * `toArrayV2()` (canonical v2 fields, no resource — the resource block
 * is hoisted to the top-level challenge body).
 */
final readonly class PaymentRequired
{
    /**
     * @param  string  $scheme  Payment scheme (e.g. "exact").
     * @param  string  $network  CAIP-2 network identifier (e.g. "eip155:8453" for Base mainnet).
     * @param  string  $amount  Atomic-unit amount (USDC = 6 decimals → "10000" = $0.01). v1 wire name: `maxAmountRequired`. v2 wire name: `amount`.
     * @param  string  $asset  ERC-20 contract address of the settlement asset.
     * @param  string  $payTo  Recipient EVM address.
     * @param  int  $maxTimeoutSeconds  Validity window for the authorization.
     * @param  string|null  $resource  v1: resource URI; v2: hoisted to ResourceInfo on the challenge envelope.
     * @param  string|null  $description  v1: per-entry description; v2: hoisted.
     * @param  string|null  $mimeType  v1: per-entry mime-type; v2: hoisted.
     * @param  array<string, mixed>  $extra  Scheme-specific extra fields (e.g. EIP-712 domain `name` / `version`, `assetTransferMethod`).
     * @param  array<string, array{info: array<string, mixed>, schema?: array<string, mixed>}>|null  $extensions  v2 extensions block.
     */
    public function __construct(
        public string $scheme,
        public string $network,
        public string $amount,
        public string $asset,
        public string $payTo,
        public int $maxTimeoutSeconds = 60,
        public ?string $resource = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public array $extra = [],
        public ?array $extensions = null,
    ) {}

    /**
     * v1 `accepts[]` entry — inlines `resource`/`description`/`mimeType`,
     * uses `maxAmountRequired` for the value field.
     *
     * @return array<string, mixed>
     */
    public function toArrayV1(): array
    {
        return array_filter([
            'scheme' => $this->scheme,
            'network' => $this->network,
            'maxAmountRequired' => $this->amount,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'resource' => $this->resource,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
            'extra' => $this->extra === [] ? null : $this->extra,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * v2 `PaymentRequirements` — uses `amount`, drops resource fields
     * (they live on the top-level `resource: ResourceInfo`).
     *
     * @return array<string, mixed>
     */
    public function toArrayV2(): array
    {
        return array_filter([
            'scheme' => $this->scheme,
            'network' => $this->network,
            'amount' => $this->amount,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'extra' => $this->extra === [] ? null : $this->extra,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Default to v1 wire shape for backwards compat with code that
     * pre-dates the version split. New code should call the explicit
     * `toArrayV1()` / `toArrayV2()` methods.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toArrayV1();
    }

    public function resourceInfo(): ?ResourceInfo
    {
        if ($this->resource === null) {
            return null;
        }

        return new ResourceInfo(
            url: $this->resource,
            description: $this->description,
            mimeType: $this->mimeType,
        );
    }
}
