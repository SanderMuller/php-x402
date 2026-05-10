<?php

declare(strict_types=1);

use X402\Server\IdempotencyKeyBuilder;

it('builds a deterministic key for the same inputs', function (): void {
    $first = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'header-line-bytes',
    );
    $second = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'header-line-bytes',
    );

    expect($first)->toBe($second)
        ->and($first)->toStartWith('x402:idem:');
});

it('lowercases from and nonce so checksummed input does not collide', function (): void {
    $lower = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xabcdef',
        nonce: '0xnonce',
        bindingBytes: 'h',
    );
    $mixed = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xABCDEF',
        nonce: '0xNonce',
        bindingBytes: 'h',
    );

    expect($lower)->toBe($mixed);
});

it('produces different keys when binding bytes differ (forge-resistance)', function (): void {
    $legit = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'legit-signed-bytes',
    );
    $forged = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'attacker-forged-bytes',
    );

    expect($legit)->not->toBe($forged);
});

it('separates keys per network so a cross-chain reuse cannot collide', function (): void {
    $base = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'h',
    );
    $polygon = IdempotencyKeyBuilder::build(
        network: 'eip155:137',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'h',
    );

    expect($base)->not->toBe($polygon);
});

it('mixes the scope segments into the hash so different transports do not collide', function (): void {
    $http = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
        scope: [],
    );
    $jsonRpcCall = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
        scope: ['tools/call', 'mcp://tool/foo'],
    );
    $jsonRpcRead = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
        scope: ['resources/read', 'mcp://tool/foo'],
    );

    expect($http)->not->toBe($jsonRpcCall)
        ->and($jsonRpcCall)->not->toBe($jsonRpcRead);
});

it('honours a custom prefix so different consumers can co-exist in the same store', function (): void {
    $key = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xf',
        nonce: '0xn',
        bindingBytes: 's',
        prefix: 'custom:idem:',
    );

    expect($key)->toStartWith('custom:idem:');
});

it('throws on empty bindingBytes (forge-resistance pin missing)', function (): void {
    IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: '',
    );
})->throws(InvalidArgumentException::class, 'non-empty $bindingBytes');

it('uses an injective serialisation so delimiter-bearing scope segments cannot collide', function (): void {
    // A naive implode('|', $parts) would let
    //   ['tools/call', 'mcp://a|b']  and  ['tools/call|mcp://a', 'b']
    // collapse to the same preimage. Both are valid scope tuples for
    // a JSON-RPC consumer (URIs can carry `|` legally), so the encoding
    // must distinguish them.
    $a = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
        scope: ['tools/call', 'mcp://a|b'],
    );
    $b = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
        scope: ['tools/call|mcp://a', 'b'],
    );

    expect($a)->not->toBe($b);
});

it('separates from-vs-bindingBytes spillover that a plain delimiter join would collapse', function (): void {
    // Past `implode('|', ...)` would also collapse a `from` ending in
    // `|0xnonce` and an empty nonce vs the canonical (from, nonce).
    // The structured serialisation makes that impossible.
    $natural = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: 'sig',
    );
    $smuggled = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom|0xnonce|sig',
        nonce: '',
        bindingBytes: 'sig',
    );

    expect($natural)->not->toBe($smuggled);
});

it('documents the recommended JSON-RPC consumer shape: [method, sha256(canonical_args)]', function (): void {
    // laravel-x402-mcp's spec settles on a CacheScope value object that
    // produces `[method, sha256(canonical_args)]` segments — the args
    // hash uses sort-keys-recursive canonical-JSON encoding so
    // client-side reserialisation can't split keys. This test pins
    // the expected shape so a future API drift breaks loudly.

    $canonicalArgs = static function (array $args): string {
        ksort($args, SORT_STRING);

        return hash('sha256', json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    };

    $a = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: '0xeip3009-signature-bytes',
        scope: ['tools/call', $canonicalArgs(['name' => 'fetch-premium-data', 'limit' => 10])],
        prefix: 'x402:idem:mcp:',
    );
    $b = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xnonce',
        bindingBytes: '0xeip3009-signature-bytes',
        // Same args, keys reordered — canonical hash must collapse.
        scope: ['tools/call', $canonicalArgs(['limit' => 10, 'name' => 'fetch-premium-data'])],
        prefix: 'x402:idem:mcp:',
    );

    expect($a)->toBe($b)
        ->and($a)->toStartWith('x402:idem:mcp:');
});
