<?php

declare(strict_types=1);

namespace X402\Replay;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (simple-cache) backed nonce store. Pass an Illuminate / Symfony
 * cache adapter to share state across processes.
 *
 * Note — PSR-16 has no atomic "set-if-absent" primitive. This implementation
 * uses get + set, which has a tiny TOCTOU window. For strict correctness
 * under high concurrency, ship a Redis-native adapter (e.g.
 * `LaravelNonceStore` in laravel-x402 uses `Cache::add()` which IS atomic).
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
