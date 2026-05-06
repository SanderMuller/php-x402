<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\Constants;
use X402\Schemes\Evm\Permit2Scheme;

function permit2Challenge(string $maxAmount = '10000', string $payTo = '0x000000000000000000000000000000000000beef'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: $maxAmount,
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: $payTo,
        extra: ['assetTransferMethod' => Constants::TRANSFER_METHOD_PERMIT2],
    );
}

/**
 * @param  array<string, mixed>  $authOverrides
 */
function permit2Signature(array $authOverrides = []): PaymentSignature
{
    $auth = array_merge([
        'permitted' => [
            'token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            'amount' => '10000',
        ],
        'from' => '0xabc0000000000000000000000000000000000abc',
        'spender' => Constants::X402_EXACT_PERMIT2_PROXY,
        'nonce' => '12345',
        'deadline' => (string) (time() + 60),
        'witness' => [
            'to' => '0x000000000000000000000000000000000000beef',
            'validAfter' => (string) (time() - 10),
        ],
    ], $authOverrides);

    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xdeadbeef', 'permit2Authorization' => $auth],
    );
}

it('accepts a valid Permit2 payload', function (): void {
    (new Permit2Scheme())->verifyShape(permit2Signature(), permit2Challenge());

    expect(true)->toBeTrue();
});

it('rejects when challenge does not declare permit2 transfer method', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    (new Permit2Scheme())->verifyShape(permit2Signature(), $challenge);
})->throws(InvalidPaymentException::class, 'permit2');

it('rejects non-canonical spender', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['spender' => '0x0000000000000000000000000000000000000bad']),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'x402ExactPermit2Proxy');

it('rejects token mismatch', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['permitted' => ['token' => '0xdeadbeef00000000000000000000000000000000', 'amount' => '10000']]),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'token');

it('rejects amount over challenge', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['permitted' => ['token' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'amount' => '99999999']]),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'amount');

it('rejects witness.to mismatch', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['witness' => ['to' => '0x000000000000000000000000000000000000dead', 'validAfter' => (string) (time() - 10)]]),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'witness.to');

it('rejects future validAfter', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['witness' => ['to' => '0x000000000000000000000000000000000000beef', 'validAfter' => (string) (time() + 3600)]]),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'validAfter');

it('rejects expired deadline', function (): void {
    (new Permit2Scheme())->verifyShape(
        permit2Signature(['deadline' => (string) (time() - 1)]),
        permit2Challenge(),
    );
})->throws(InvalidPaymentException::class, 'deadline');
