<?php

declare(strict_types=1);

namespace X402\Replay;

use Closure;

/**
 * Atomic-set-if-absent nonce store that delegates the actual claim to
 * a caller-provided closure. Lets hosts wire any Redis-compatible
 * client (phpredis, Predis, custom) without taking a hard dependency
 * on a specific driver here.
 *
 * The closure MUST implement Redis `SET key value NX EX ttl` semantics:
 * return true on the first call for `$key`, false if `$key` already
 * exists. Anything weaker (a plain `SET` or a non-atomic `EXISTS + SET`)
 * silently breaks replay protection under concurrency.
 *
 * Example wiring with phpredis:
 *
 *     $redis = new \Redis();
 *     $redis->connect('127.0.0.1', 6379);
 *
 *     $store = new CallbackNonceStore(
 *         static fn (string $key, int $ttl): bool
 *             => (bool) $redis->set($key, '1', ['NX', 'EX' => $ttl]),
 *     );
 *
 * Example wiring with Predis:
 *
 *     $redis = new \Predis\Client(...);
 *
 *     $store = new CallbackNonceStore(
 *         static fn (string $key, int $ttl): bool
 *             => $redis->set($key, '1', 'EX', $ttl, 'NX') !== null,
 *     );
 */
final readonly class CallbackNonceStore implements NonceStoreContract
{
    /**
     * @param  Closure(string $key, int $ttlSeconds): bool  $atomicSetIfAbsent  Must implement SETNX-EX semantics — true on first claim, false on duplicate.
     */
    public function __construct(
        private Closure $atomicSetIfAbsent,
        private string $prefix = 'x402:nonce:',
    ) {}

    public function claim(string $network, string $from, string $nonce, int $ttlSeconds): bool
    {
        $key = $this->prefix . sprintf('%s:%s:%s', $network, strtolower($from), strtolower($nonce));

        return ($this->atomicSetIfAbsent)($key, $ttlSeconds);
    }
}
