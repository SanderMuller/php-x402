<?php

declare(strict_types=1);

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Svm\ExactScheme;

function svmChallenge(string $network = ExactScheme::NETWORK_MAINNET): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: $network,
        amount: '1000000',
        asset: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', // USDC SPL mint
        payTo: '11111111111111111111111111111111',
    );
}

function svmSignature(string $transactionBase64): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: ExactScheme::NETWORK_MAINNET,
        payload: ['transaction' => $transactionBase64],
    );
}

it('accepts a plausible base64 partial-signed transaction', function (): void {
    // 200 random bytes — comfortably above the 100-byte smoke check
    // (a real minimal transfer tx is ≥ ~200 bytes anyway).
    $tx = base64_encode(random_bytes(200));

    (new ExactScheme())->verifyShape(svmSignature($tx), svmChallenge());

    expect(true)->toBeTrue();
});

it('rejects empty transaction', function (): void {
    (new ExactScheme())->verifyShape(svmSignature(''), svmChallenge());
})->throws(InvalidPaymentException::class, 'non-empty');

it('rejects invalid base64', function (): void {
    (new ExactScheme())->verifyShape(svmSignature('!!!not-base64!!!'), svmChallenge());
})->throws(InvalidPaymentException::class, 'base64');

it('rejects implausibly short tx', function (): void {
    (new ExactScheme())->verifyShape(svmSignature(base64_encode('tiny')), svmChallenge());
})->throws(InvalidPaymentException::class, 'implausibly short');

it('rejects a bare 65-byte signature blob (no message)', function (): void {
    // 1-byte sig-count + 64-byte signature, no message — passes base64
    // and length-≥-64 checks but is not a real transaction.
    $bareSig = base64_encode(random_bytes(65));

    (new ExactScheme())->verifyShape(svmSignature($bareSig), svmChallenge());
})->throws(InvalidPaymentException::class, 'implausibly short');

it('rejects non-Solana network', function (): void {
    $sig = new PaymentSignature(scheme: 'exact', network: 'eip155:8453', payload: ['transaction' => base64_encode(random_bytes(200))]);

    (new ExactScheme())->verifyShape($sig, svmChallenge('eip155:8453'));
})->throws(InvalidPaymentException::class, 'does not support network');

it('rejects scheme mismatch', function (): void {
    $sig = new PaymentSignature(scheme: 'upto', network: ExactScheme::NETWORK_MAINNET, payload: ['transaction' => base64_encode(random_bytes(200))]);

    (new ExactScheme())->verifyShape($sig, svmChallenge());
})->throws(InvalidPaymentException::class, 'Expected scheme');
