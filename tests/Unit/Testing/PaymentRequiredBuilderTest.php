<?php

declare(strict_types=1);

use X402\Testing\PaymentRequiredBuilder;

it('builds a USDC-on-Base challenge with correct atomic-unit conversion', function (): void {
    $challenge = PaymentRequiredBuilder::usdcOnBase(0.01, '0xPayTo')->build();

    expect($challenge->scheme)->toBe('exact')
        ->and($challenge->network)->toBe('eip155:8453')
        ->and($challenge->amount)->toBe('10000')                         // 0.01 USDC = 10000 atomic units (6 decimals)
        ->and($challenge->asset)->toBe(PaymentRequiredBuilder::USDC_BASE_MAINNET)
        ->and($challenge->payTo)->toBe('0xPayTo');
});

it('builds USDC-on-Base-Sepolia for testnet', function (): void {
    $challenge = PaymentRequiredBuilder::usdcOnBaseSepolia('1.50', '0xPayTo')->build();

    expect($challenge->network)->toBe('eip155:84532')
        ->and($challenge->amount)->toBe('1500000')                       // 1.50 USDC = 1_500_000
        ->and($challenge->asset)->toBe(PaymentRequiredBuilder::USDC_BASE_SEPOLIA);
});

it('threads optional fields via fluent setters', function (): void {
    $challenge = PaymentRequiredBuilder::usdcOnBase(0.01, '0xPayTo')
        ->withMaxTimeoutSeconds(120)
        ->withResource('https://api.example.com/premium')
        ->withDescription('Premium API access')
        ->withMimeType('application/json')
        ->withExtra(['custom' => 'value'])
        ->build();

    expect($challenge->maxTimeoutSeconds)->toBe(120)
        ->and($challenge->resource)->toBe('https://api.example.com/premium')
        ->and($challenge->description)->toBe('Premium API access')
        ->and($challenge->mimeType)->toBe('application/json')
        ->and($challenge->extra)->toBe(['custom' => 'value']);
});

it('supports custom networks via the generic for() factory', function (): void {
    $challenge = PaymentRequiredBuilder::for(
        network: 'eip155:1',
        asset: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',  // USDC mainnet
        amount: 5,
        payTo: '0xPayTo',
        decimals: 6,
    )->build();

    expect($challenge->amount)->toBe('5000000');
});

it('rejects non-numeric amounts', function (): void {
    expect(fn () => PaymentRequiredBuilder::usdcOnBase('notanumber', '0xPayTo')->build())
        ->toThrow(InvalidArgumentException::class);
});

it('overrides the default scheme via withScheme', function (): void {
    $challenge = PaymentRequiredBuilder::usdcOnBase(0.01, '0xPayTo')
        ->withScheme('upto')
        ->build();

    expect($challenge->scheme)->toBe('upto');
});
