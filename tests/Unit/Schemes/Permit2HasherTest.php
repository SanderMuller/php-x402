<?php

declare(strict_types=1);

use X402\Schemes\Evm\Permit2Hasher;

it('produces a deterministic 32-byte digest', function (): void {
    $hasher = new Permit2Hasher();

    $digest = $hasher->digest(
        chainId: 8453,
        permitted: ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        permit: [
            'spender' => '0x402085c248EeA27D92E8b30b2C58ed07f9E20001',
            'nonce' => '12345',
            'deadline' => '1735689600',
        ],
        witness: [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => '1735689500',
        ],
    );

    expect($digest)->toStartWith('0x')->and(\strlen($digest))->toBe(66);

    $digest2 = $hasher->digest(
        chainId: 8453,
        permitted: ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        permit: [
            'spender' => '0x402085c248EeA27D92E8b30b2C58ed07f9E20001',
            'nonce' => '12345',
            'deadline' => '1735689600',
        ],
        witness: [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => '1735689500',
        ],
    );

    expect($digest)->toBe($digest2);
});

it('produces different digests across chainIds (no version field)', function (): void {
    $hasher = new Permit2Hasher();
    $args = [
        ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '1'],
        ['spender' => '0x402085c248EeA27D92E8b30b2C58ed07f9E20001', 'nonce' => '0', 'deadline' => '999'],
        ['to' => '0x000000000000000000000000000000000000beef', 'validAfter' => '0'],
    ];

    expect($hasher->digest(8453, ...$args))
        ->not->toBe($hasher->digest(1, ...$args));
});
