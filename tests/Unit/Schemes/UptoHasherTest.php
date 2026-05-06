<?php

declare(strict_types=1);

use X402\Schemes\Evm\Permit2Hasher;
use X402\Schemes\Upto\UptoHasher;

it('produces a deterministic 32-byte digest', function (): void {
    $hasher = new UptoHasher();

    $args = [
        ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        ['spender' => '0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002', 'nonce' => '12345', 'deadline' => '1735689600'],
        ['to' => '0x000000000000000000000000000000000000beef', 'validAfter' => '1735689500', 'facilitator' => '0x' . str_repeat('cd', 20)],
    ];

    $a = $hasher->digest(8453, ...$args);
    $b = $hasher->digest(8453, ...$args);

    expect($a)->toStartWith('0x')->and(\strlen($a))->toBe(66)->and($a)->toBe($b);
});

it('produces a digest distinct from Permit2Hasher (different witness type)', function (): void {
    $upto = new UptoHasher();
    $permit2 = new Permit2Hasher();

    $uptoDigest = $upto->digest(
        8453,
        ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        ['spender' => '0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002', 'nonce' => '0', 'deadline' => '999'],
        ['to' => '0x000000000000000000000000000000000000beef', 'validAfter' => '0', 'facilitator' => '0x' . str_repeat('cd', 20)],
    );

    $permit2Digest = $permit2->digest(
        8453,
        ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        ['spender' => '0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002', 'nonce' => '0', 'deadline' => '999'],
        ['to' => '0x000000000000000000000000000000000000beef', 'validAfter' => '0'],
    );

    expect($uptoDigest)->not->toBe($permit2Digest);
});

it('produces different digests when facilitator address changes', function (): void {
    $hasher = new UptoHasher();
    $base = [
        ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '10000'],
        ['spender' => '0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002', 'nonce' => '0', 'deadline' => '999'],
    ];

    $a = $hasher->digest(8453, ...[...$base, ['to' => '0x' . str_repeat('be', 20), 'validAfter' => '0', 'facilitator' => '0x' . str_repeat('aa', 20)]]);
    $b = $hasher->digest(8453, ...[...$base, ['to' => '0x' . str_repeat('be', 20), 'validAfter' => '0', 'facilitator' => '0x' . str_repeat('bb', 20)]]);

    expect($a)->not->toBe($b);
});
