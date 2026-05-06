<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Single resource entry returned by `GET /discovery/resources` (Bazaar).
 *
 * Shape mirrors the Coinbase Go reference (`go/extensions/bazaar/facilitator_client.go:29-47`).
 */
final readonly class DiscoveryResource
{
    /**
     * @param  string  $resource  URL or identifier of the paid endpoint.
     * @param  string  $type  Protocol type — "http" or "mcp".
     * @param  int  $x402Version  Version supported by the resource (typically 2).
     * @param  list<array<string, mixed>>  $accepts  Raw `accepts[]` from the resource's PaymentRequired.
     * @param  string|null  $lastUpdated  ISO 8601 timestamp.
     * @param  array<string, mixed>  $metadata  Opaque metadata block (filterable in some facilitator impls).
     * @param  array<string, mixed>|null  $discoveryInfo  Bazaar `info` block from the original PaymentRequired extension.
     */
    public function __construct(
        public string $resource,
        public string $type,
        public int $x402Version,
        public array $accepts,
        public ?string $lastUpdated = null,
        public array $metadata = [],
        public ?array $discoveryInfo = null,
    ) {}
}
