<?php

declare(strict_types=1);

use X402\Replay\InMemoryNonceStore;

it('claims a nonce on first use', function (): void {
    $store = new InMemoryNonceStore();

    expect($store->claim('eip155:8453', '0xabc', '0xdeadbeef', 60))->toBeTrue();
});

it('rejects a duplicate nonce within TTL', function (): void {
    $store = new InMemoryNonceStore();

    $store->claim('eip155:8453', '0xabc', '0xdeadbeef', 60);

    expect($store->claim('eip155:8453', '0xabc', '0xdeadbeef', 60))->toBeFalse();
});

it('treats different networks as independent namespaces', function (): void {
    $store = new InMemoryNonceStore();

    $store->claim('eip155:1', '0xabc', '0xdeadbeef', 60);

    expect($store->claim('eip155:8453', '0xabc', '0xdeadbeef', 60))->toBeTrue();
});

it('is case-insensitive on the from-address and nonce', function (): void {
    $store = new InMemoryNonceStore();

    $store->claim('eip155:8453', '0xABC', '0xDEADBEEF', 60);

    expect($store->claim('eip155:8453', '0xabc', '0xdeadbeef', 60))->toBeFalse();
});
