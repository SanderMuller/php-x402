<?php

declare(strict_types=1);

namespace X402\Server;

use X402\Protocol\PaymentRequired;

/**
 * Map an inbound resource (e.g. URI path or MCP tool name) to the payment
 * challenges the server is willing to accept for it.
 *
 * Hosts typically wrap config in an implementation; the contract stays
 * deliberately small so frameworks can inject their own routing-aware
 * resolvers (Laravel route name → price; MCP tool name → price; …).
 */
interface PriceTable
{
    /**
     * Returns one or more PaymentRequired challenges for the given resource,
     * or an empty list when the resource is free.
     *
     * @return list<PaymentRequired>
     */
    public function challengesFor(string $resource): array;
}
