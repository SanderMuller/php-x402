<?php

declare(strict_types=1);

namespace X402\Server;

use X402\Protocol\PaymentRequired;

/**
 * Trivial in-memory PriceTable backed by an array. Useful for tests and
 * for frameworks that resolve a resource to its challenges before invoking
 * the middleware.
 */
final class StaticPriceTable implements PriceTable
{
    /**
     * @param  array<string, list<PaymentRequired>>  $entries
     */
    public function __construct(private array $entries = []) {}

    public function set(string $resource, PaymentRequired ...$challenges): void
    {
        $this->entries[$resource] = array_values($challenges);
    }

    public function challengesFor(string $resource): array
    {
        return $this->entries[$resource] ?? [];
    }
}
