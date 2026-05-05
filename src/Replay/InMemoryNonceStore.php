<?php

declare(strict_types=1);

namespace X402\Replay;

/**
 * In-process nonce store — useful for tests and single-worker setups. NOT
 * safe across processes; use a PSR-16-backed adapter (e.g. Redis) in
 * production.
 */
final class InMemoryNonceStore implements NonceStoreContract
{
    /** @var array<string, int> key => expiresAt */
    private array $store = [];

    public function claim(string $network, string $from, string $nonce, int $ttlSeconds): bool
    {
        $key = sprintf('%s:%s:%s', $network, strtolower($from), strtolower($nonce));
        $now = time();

        // Expire stale entries lazily.
        foreach ($this->store as $k => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->store[$k]);
            }
        }

        if (isset($this->store[$key])) {
            return false;
        }

        $this->store[$key] = $now + $ttlSeconds;

        return true;
    }
}
