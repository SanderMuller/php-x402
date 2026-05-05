<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentSignature;

it('round-trips through header encoding', function (): void {
    $original = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xdead', 'authorization' => ['from' => '0x1']],
        x402Version: '1',
    );

    $decoded = PaymentSignature::fromHeader($original->toHeader());

    expect($decoded->scheme)->toBe($original->scheme)
        ->and($decoded->network)->toBe($original->network)
        ->and($decoded->payload)->toBe($original->payload)
        ->and($decoded->x402Version)->toBe('1');
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
