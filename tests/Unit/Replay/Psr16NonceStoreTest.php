<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use X402\Replay\Psr16NonceStore;

/**
 * Bare-bones in-memory PSR-16 cache for the test — Pest doesn't ship one
 * by default and pulling Symfony cache for one test would be overkill.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}

it('claims a nonce and rejects duplicates', function (): void {
    $store = new Psr16NonceStore(new ArrayCache);

    expect($store->claim('eip155:8453', '0xabc', '0xdead', 60))->toBeTrue()
        ->and($store->claim('eip155:8453', '0xabc', '0xdead', 60))->toBeFalse();
});

it('uses the configured prefix', function (): void {
    $cache = new ArrayCache;
    $store = new Psr16NonceStore($cache, 'custom:');

    $store->claim('eip155:8453', '0xabc', '0xdead', 60);

    expect($cache->has('custom:eip155:8453:0xabc:0xdead'))->toBeTrue();
});
