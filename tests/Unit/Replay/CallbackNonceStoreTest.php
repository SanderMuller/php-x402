<?php

declare(strict_types=1);

use X402\Replay\CallbackNonceStore;

it('delegates the claim to the supplied closure', function (): void {
    $calls = [];
    $store = new CallbackNonceStore(static function (string $key, int $ttl) use (&$calls): bool {
        $calls[] = [$key, $ttl];

        return true;
    });

    expect($store->claim('eip155:8453', '0xFROM', '0xNONCE', 90))->toBeTrue();
    expect($calls)->toBe([['x402:nonce:eip155:8453:0xfrom:0xnonce', 90]]);
});

it('lower-cases the from + nonce coordinates so case variants share a slot', function (): void {
    $seen = [];
    $store = new CallbackNonceStore(static function (string $key) use (&$seen): bool {
        if (in_array($key, $seen, strict: true)) {
            return false;
        }

        $seen[] = $key;

        return true;
    });

    expect($store->claim('eip155:8453', '0xABC', '0xDEF', 60))->toBeTrue();
    expect($store->claim('eip155:8453', '0xabc', '0xdef', 60))->toBeFalse();
});

it('honours a custom prefix', function (): void {
    $captured = '';
    $store = new CallbackNonceStore(
        static function (string $key) use (&$captured): bool {
            $captured = $key;

            return true;
        },
        prefix: 'app:replay:',
    );

    $store->claim('eip155:8453', '0xfrom', '0xnonce', 60);

    expect($captured)->toStartWith('app:replay:');
});

it('returns false when the closure reports the key was already set', function (): void {
    $store = new CallbackNonceStore(static fn (): bool => false);

    expect($store->claim('eip155:8453', '0xfrom', '0xnonce', 60))->toBeFalse();
});
