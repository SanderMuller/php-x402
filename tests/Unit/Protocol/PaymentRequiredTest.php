<?php

declare(strict_types=1);

use X402\Protocol\PaymentRequired;

it('serialises to the spec-shaped array', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        payTo: '0x0000000000000000000000000000000000000001',
        maxTimeoutSeconds: 60,
        resource: 'https://api.example.com/data',
        description: 'Premium endpoint',
    );

    // toArray() defaults to v1 wire shape — uses `maxAmountRequired`, inlines resource fields.
    expect($challenge->toArray())->toBe([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'maxAmountRequired' => '10000',
        'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'payTo' => '0x0000000000000000000000000000000000000001',
        'maxTimeoutSeconds' => 60,
        'resource' => 'https://api.example.com/data',
        'description' => 'Premium endpoint',
    ]);
});

it('emits the v2 wire shape via toArrayV2 — `amount` field, no resource block', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        payTo: '0x0000000000000000000000000000000000000001',
        resource: 'https://api.example.com/data',
        description: 'Premium endpoint',
    );

    $v2 = $challenge->toArrayV2();

    expect($v2)->toHaveKey('amount', '10000');
    expect($v2)->not->toHaveKey('maxAmountRequired');
    expect($v2)->not->toHaveKey('resource');
    expect($v2)->not->toHaveKey('description');
    expect($v2)->not->toHaveKey('mimeType');
});

it('omits null and empty extra from toArray', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xabc',
        payTo: '0xdef',
    );

    expect($challenge->toArray())->not->toHaveKey('resource');
    expect($challenge->toArray())->not->toHaveKey('extra');
});
