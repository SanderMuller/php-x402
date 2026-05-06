<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Paginated result from `GET /discovery/resources`.
 *
 * Wraps `{x402Version: 1, items: [...], pagination: {limit, offset, total}}` —
 * the outer envelope's `x402Version` is 1 because it's the API version (NOT
 * the per-item resource's `x402Version`, which is typically 2).
 */
final readonly class DiscoveryPage
{
    /**
     * @param  list<DiscoveryResource>  $items
     */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
        public int $total,
    ) {}

    public function hasMore(): bool
    {
        return $this->offset + count($this->items) < $this->total;
    }
}
