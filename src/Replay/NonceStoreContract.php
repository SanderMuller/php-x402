<?php

declare(strict_types=1);

namespace X402\Replay;

/**
 * Replay-protection store. Implementations must guarantee atomic
 * "set-if-absent with TTL" semantics — Redis SETNX EX is the canonical
 * backing.
 */
interface NonceStoreContract
{
    /**
     * Try to claim a (network, from, nonce) tuple. Returns true on the FIRST
     * call only; subsequent calls within the TTL return false.
     *
     * @param  int  $ttlSeconds  Should be >= (validBefore - now) of the authorization.
     */
    public function claim(string $network, string $from, string $nonce, int $ttlSeconds): bool;
}
