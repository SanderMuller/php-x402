<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

it('round-trips through header encoding', function (): void {
    $original = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xdead', 'authorization' => ['from' => '0x1']],
        x402Version: 1,
    );

    $decoded = PaymentSignature::fromHeader($original->toHeader());

    expect($decoded->scheme)->toBe($original->scheme)
        ->and($decoded->network)->toBe($original->network)
        ->and($decoded->payload)->toBe($original->payload)
        ->and($decoded->x402Version)->toBe(1);
});

it('round-trips a v2 payload including the accepted echo', function (): void {
    $accepted = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0xrecipient',
    );

    $original = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xdead', 'authorization' => []],
        x402Version: 2,
        accepted: $accepted,
    );

    $decoded = PaymentSignature::fromHeader($original->toHeader());

    expect($decoded->x402Version)->toBe(2)
        ->and($decoded->accepted)->not->toBeNull()
        ->and($decoded->accepted?->amount)->toBe('10000');
});

it('tolerates string-typed x402Version on the wire (pre-spec clients)', function (): void {
    $sig = PaymentSignature::fromHeader(base64_encode((string) json_encode([
        'x402Version' => '1',  // string, not int
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [],
    ])));

    expect($sig->x402Version)->toBe(1);
});

it('rejects malformed base64', function (): void {
    PaymentSignature::fromHeader('@@@not-valid-base64@@@');
})->throws(InvalidPaymentException::class);

it('rejects valid base64 of invalid JSON', function (): void {
    PaymentSignature::fromHeader(base64_encode('{not json'));
})->throws(InvalidPaymentException::class);

it('rejects payloads missing required fields', function (): void {
    PaymentSignature::fromHeader(base64_encode('{"scheme":"exact"}'));
})->throws(InvalidPaymentException::class, 'missing required field "network"');
