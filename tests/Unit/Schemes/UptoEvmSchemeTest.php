<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\Constants;
use X402\Schemes\Upto\UptoEvmScheme;

function uptoChallenge(string $maxAmount = '10000'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'upto',
        network: 'eip155:8453',
        amount: $maxAmount,
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: '0x000000000000000000000000000000000000beef',
    );
}

/**
 * @param  array<string, mixed>  $authOverrides
 */
function uptoSignature(array $authOverrides = []): PaymentSignature
{
    $auth = array_merge([
        'permitted' => [
            'token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            'amount' => '10000',
        ],
        'from' => '0xabc0000000000000000000000000000000000abc',
        'spender' => Constants::X402_UPTO_PERMIT2_PROXY,
        'nonce' => '12345',
        'deadline' => (string) (time() + 60),
        'witness' => [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => (string) (time() - 10),
            'facilitator' => '0x' . str_repeat('cd', 20),
        ],
    ], $authOverrides);

    return new PaymentSignature(
        scheme: 'upto',
        network: 'eip155:8453',
        payload: ['signature' => '0xdeadbeef', 'uptoAuthorization' => $auth],
    );
}

it('accepts a valid upto payload', function (): void {
    (new UptoEvmScheme())->verifyShape(uptoSignature(), uptoChallenge());

    expect(true)->toBeTrue();
});

it('rejects non-canonical upto spender', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['spender' => Constants::X402_EXACT_PERMIT2_PROXY]),
        uptoChallenge(),
    );
})->throws(InvalidPaymentException::class, 'x402UptoPermit2Proxy');

it('rejects permitted.amount lower than ceiling', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['permitted' => ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '5000']]),
        uptoChallenge('10000'),
    );
})->throws(InvalidPaymentException::class, 'must equal challenge amount');

it('rejects permitted.amount higher than ceiling', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['permitted' => ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '99999999']]),
        uptoChallenge('10000'),
    );
})->throws(InvalidPaymentException::class, 'must equal challenge amount');

it('rejects missing facilitator field on witness', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['witness' => [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => (string) (time() - 10),
        ]]),
        uptoChallenge(),
    );
})->throws(InvalidPaymentException::class, 'facilitator');

it('rejects expired deadline', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['deadline' => (string) (time() - 1)]),
        uptoChallenge(),
    );
})->throws(InvalidPaymentException::class, 'deadline');

it('rejects non-numeric deadline (e.g. "abc")', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['deadline' => 'abc']),
        uptoChallenge(),
    );
})->throws(InvalidPaymentException::class, 'int');

it('rejects non-numeric validAfter', function (): void {
    (new UptoEvmScheme())->verifyShape(
        uptoSignature(['witness' => [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => 'not-a-number',
            'facilitator' => '0x' . str_repeat('cd', 20),
        ]]),
        uptoChallenge(),
    );
})->throws(InvalidPaymentException::class, 'int');

it('exposes replayKey from payload.uptoAuthorization', function (): void {
    $key = (new UptoEvmScheme())->replayKey(uptoSignature([
        'from' => '0xFROM',
        'nonce' => '0xNONCE',
        'deadline' => 9999999999,
    ]));

    expect($key)->toBe([
        'from' => '0xFROM',
        'nonce' => '0xNONCE',
        'expiresAt' => 9999999999,
    ]);
});

it('coerces numeric nonce in replayKey (mirrors verifyShape)', function (): void {
    $key = (new UptoEvmScheme())->replayKey(uptoSignature([
        'nonce' => 7,
    ]));

    expect($key['nonce'])->toBe('7');
});
