<?php

declare(strict_types=1);

namespace X402\Webhook;

/**
 * Atomic claim store for inbound webhook deliveries. Implementations
 * MUST guarantee that concurrent claims of the same nonce return
 * `true` exactly once across all racers — facilitators routinely
 * retry deliveries during outages, so the framework MUST treat
 * duplicate-nonce arrivals as already-processed.
 *
 * **Recommended TTL:** 86400s (24h). Facilitators retry within
 * minutes; a 24h window covers retry storms across multi-hour
 * outages without growing the dedup keyspace unboundedly.
 *
 * **Reference impls.** No concrete impl ships upstream:
 *
 *   - laravel-x402 wires `Cache::add('x402:webhook:processed:' . strtolower($nonce), 1, $ttl)`
 *     (`Cache::add` is atomic on Redis / Memcached / array drivers).
 *   - Non-Laravel adopters wire Redis `SETNX EX` directly.
 */
interface WebhookDedupStore
{
    /**
     * Atomically claim a webhook delivery by nonce.
     *
     * @return bool `true` if the nonce was newly claimed (process the
     *              webhook), `false` if a previous delivery already
     *              claimed it (return 200-no-op to the facilitator).
     */
    public function claim(string $nonce, int $ttlSeconds): bool;
}
