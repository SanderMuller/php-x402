<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\Constants;
use X402\Schemes\Evm\Erc7710\Erc7710Scheme;

function erc7710Challenge(): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: '0x000000000000000000000000000000000000beef',
        extra: ['assetTransferMethod' => Constants::TRANSFER_METHOD_ERC7710],
    );
}

/**
 * @param  list<array<string, mixed>>  $delegations
 */
function erc7710Signature(array $delegations): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xdead', 'delegations' => $delegations],
    );
}

/**
 * @return array<string, lowercase-string|array<int, array<string, string>>>
 */
function validDelegation(): array
{
    return [
        'delegate' => '0x' . str_repeat('11', 20),
        'delegator' => '0x' . str_repeat('22', 20),
        'authority' => '0x' . str_repeat('ff', 32),
        'caveats' => [
            ['enforcer' => '0x' . str_repeat('33', 20), 'terms' => '0xdead', 'args' => '0x'],
        ],
        'salt' => '0x' . str_repeat('00', 32),
        'signature' => '0x' . str_repeat('aa', 65),
    ];
}

it('accepts a valid delegation chain', function (): void {
    (new Erc7710Scheme())->verifyShape(
        erc7710Signature([validDelegation()]),
        erc7710Challenge(),
    );

    expect(true)->toBeTrue();
});

it('rejects when transfer method is not erc7710', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    (new Erc7710Scheme())->verifyShape(erc7710Signature([validDelegation()]), $challenge);
})->throws(InvalidPaymentException::class, 'erc7710');

it('rejects empty delegations array', function (): void {
    (new Erc7710Scheme())->verifyShape(erc7710Signature([]), erc7710Challenge());
})->throws(InvalidPaymentException::class, 'non-empty');

it('rejects delegation without caveats', function (): void {
    $delegation = validDelegation();
    $delegation['caveats'] = [];

    (new Erc7710Scheme())->verifyShape(erc7710Signature([$delegation]), erc7710Challenge());
})->throws(InvalidPaymentException::class, 'at least one caveat');

it('rejects delegation missing required fields', function (): void {
    $delegation = validDelegation();
    unset($delegation['signature']);

    (new Erc7710Scheme())->verifyShape(erc7710Signature([$delegation]), erc7710Challenge());
})->throws(InvalidPaymentException::class, 'signature');

it('rejects malformed caveat', function (): void {
    $delegation = validDelegation();
    $delegation['caveats'] = [['enforcer' => '0xabc']];

    (new Erc7710Scheme())->verifyShape(erc7710Signature([$delegation]), erc7710Challenge());
})->throws(InvalidPaymentException::class, 'terms');
