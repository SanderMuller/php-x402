<?php

declare(strict_types=1);

namespace X402\Replay;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (simple-cache) backed nonce store. Pass an Illuminate / Symfony
 * cache adapter to share state across processes.
 *
 * **Security-critical** — PSR-16 has no atomic "set-if-absent" primitive,
 * so `claim()` is implemented as `has() + set()`. Two concurrent requests
 * carrying the same `(network, from, nonce)` can both observe a cache
 * miss and both succeed, defeating replay protection for that window.
 * The window is small but non-zero on every backend.
 *
 * Production deployments MUST use a nonce store with atomic
 * compare-and-set semantics — `LaravelNonceStore` (laravel-x402) uses
 * `Cache::add()` which is atomic on Redis / Memcached, or wire a
 * Redis-native `SET key value NX EX ttl` adapter directly. Treat this
 * class as fit for tests and single-worker dev environments only.
 */
final readonly class Psr16NonceStore implements NonceStoreContract
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'x402:nonce:',
    ) {}

    public function claim(string $network, string $from, string $nonce, int $ttlSeconds): bool
    {
        $key = $this->prefix . sprintf('%s:%s:%s', $network, strtolower($from), strtolower($nonce));

        if ($this->cache->has($key)) {
            return false;
        }

        return $this->cache->set($key, 1, $ttlSeconds);
    }
}
