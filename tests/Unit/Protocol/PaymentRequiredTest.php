<?php

declare(strict_types=1);

use X402\Protocol\PaymentRequired;

it('serialises to the spec-shaped array', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        maxAmountRequired: '10000',
        asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        payTo: '0x0000000000000000000000000000000000000001',
        maxTimeoutSeconds: 60,
        resource: 'https://api.example.com/data',
        description: 'Premium endpoint',
    );

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

it('omits null and empty extra from toArray', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        maxAmountRequired: '10000',
        asset: '0xabc',
        payTo: '0xdef',
    );

    expect($challenge->toArray())->not->toHaveKey('resource');
    expect($challenge->toArray())->not->toHaveKey('extra');
});
