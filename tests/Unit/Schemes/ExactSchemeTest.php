<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\ExactScheme;

function challenge(string $maxAmount = '10000', string $payTo = '0x000000000000000000000000000000000000beef'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        maxAmountRequired: $maxAmount,
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: $payTo,
    );
}

function signature(array $authorization, string $network = 'eip155:8453'): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: $network,
        payload: ['signature' => '0xdeadbeef', 'authorization' => $authorization],
    );
}

it('accepts a valid authorization', function (): void {
    (new ExactScheme)->verifyShape(
        signature([
            'from' => '0xabc',
            'to' => '0x000000000000000000000000000000000000beef',
            'value' => '10000',
            'validAfter' => time() - 10,
            'validBefore' => time() + 60,
            'nonce' => '0x'.str_repeat('a', 64),
        ]),
        challenge(),
    );

    expect(true)->toBeTrue();
});

it('rejects mismatched scheme', function (): void {
    $sig = new PaymentSignature(scheme: 'other', network: 'eip155:8453', payload: []);

    (new ExactScheme)->verifyShape($sig, challenge());
})->throws(InvalidPaymentException::class, 'Expected scheme "exact"');

it('rejects mismatched network', function (): void {
    $sig = signature([
        'from' => '0xabc', 'to' => '0xbeef', 'value' => '1',
        'validAfter' => 0, 'validBefore' => time() + 60,
        'nonce' => '0x'.str_repeat('0', 64),
    ], network: 'eip155:1');

    (new ExactScheme)->verifyShape($sig, challenge());
})->throws(InvalidPaymentException::class, 'network');

it('rejects authorization to wrong recipient', function (): void {
    (new ExactScheme)->verifyShape(
        signature([
            'from' => '0xabc',
            'to' => '0xnotthecorrectrecipient000000000000000000',
            'value' => '10000',
            'validAfter' => 0,
            'validBefore' => time() + 60,
            'nonce' => '0x'.str_repeat('0', 64),
        ]),
        challenge(),
    );
})->throws(InvalidPaymentException::class, 'payTo');

it('rejects amount over maxAmountRequired', function (): void {
    (new ExactScheme)->verifyShape(
        signature([
            'from' => '0xabc',
            'to' => '0x000000000000000000000000000000000000beef',
            'value' => '10001',
            'validAfter' => 0,
            'validBefore' => time() + 60,
            'nonce' => '0x'.str_repeat('0', 64),
        ]),
        challenge('10000'),
    );
})->throws(InvalidPaymentException::class, 'exceeds maxAmountRequired');

it('rejects expired authorization', function (): void {
    (new ExactScheme)->verifyShape(
        signature([
            'from' => '0xabc',
            'to' => '0x000000000000000000000000000000000000beef',
            'value' => '1',
            'validAfter' => 0,
            'validBefore' => time() - 1,
            'nonce' => '0x'.str_repeat('0', 64),
        ]),
        challenge(),
    );
})->throws(InvalidPaymentException::class, 'expired');
