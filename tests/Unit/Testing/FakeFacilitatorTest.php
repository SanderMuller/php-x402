<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Testing\FakeFacilitator;

function fakeChallenge(string $resource = 'https://example.test/premium'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
        resource: $resource,
    );
}

function fakeSignature(): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce']],
    );
}

it('returns successful verify + settle by default', function (): void {
    $fake = new FakeFacilitator();

    $verify = $fake->verify(fakeSignature(), fakeChallenge());
    $settle = $fake->settle(fakeSignature(), fakeChallenge());

    expect($verify->isValid)->toBeTrue()
        ->and($verify->invalidReason)->toBeNull()
        ->and($verify->payer)->toBe('0xpayer')
        ->and($settle->success)->toBeTrue()
        ->and($settle->transaction)->toBe('0xtxhash')
        ->and($settle->errorReason)->toBeNull();
});

it('rejects verify with the configured reason via rejectVerify()', function (): void {
    $fake = (new FakeFacilitator())->rejectVerify('insufficient-funds');

    $verify = $fake->verify(fakeSignature(), fakeChallenge());

    expect($verify->isValid)->toBeFalse()
        ->and($verify->invalidReason)->toBe('insufficient-funds');
});

it('fails settle with the configured reason via failSettle()', function (): void {
    $fake = (new FakeFacilitator())->failSettle('on-chain-revert');

    $settle = $fake->settle(fakeSignature(), fakeChallenge());

    expect($settle->success)->toBeFalse()
        ->and($settle->transaction)->toBe('')
        ->and($settle->errorReason)->toBe('on-chain-revert');
});

it('records verify and settle calls with full signature + challenge payload', function (): void {
    $fake = new FakeFacilitator();
    $sig = fakeSignature();
    $ch = fakeChallenge();

    $fake->verify($sig, $ch);
    $fake->settle($sig, $ch);
    $fake->settle($sig, fakeChallenge('https://example.test/other'));

    expect($fake->verifyCalls())->toHaveCount(1)
        ->and($fake->settleCalls())->toHaveCount(2)
        ->and($fake->verifyCalls()[0])->toMatchArray(['signature' => $sig, 'challenge' => $ch])
        ->and($fake->settleCalls()[1]['challenge']->resource)->toBe('https://example.test/other');
});

it('assertVerified passes when verify was called', function (): void {
    $fake = new FakeFacilitator();
    $fake->verify(fakeSignature(), fakeChallenge());

    $fake->assertVerified();
});

it('assertVerified fails when verify was never called', function (): void {
    (new FakeFacilitator())->assertVerified();
})->throws(AssertionFailedError::class, 'Expected facilitator->verify');

it('assertVerified with a resource filters by challenge resource', function (): void {
    $fake = new FakeFacilitator();
    $fake->verify(fakeSignature(), fakeChallenge('https://example.test/A'));

    $fake->assertVerified('https://example.test/A');
});

it('assertVerified with a non-matching resource fails', function (): void {
    $fake = new FakeFacilitator();
    $fake->verify(fakeSignature(), fakeChallenge('https://example.test/A'));

    $fake->assertVerified('https://example.test/B');
})->throws(AssertionFailedError::class, 'Expected verify for resource');

it('assertSettled passes when settle was called for the resource', function (): void {
    $fake = new FakeFacilitator();
    $fake->settle(fakeSignature(), fakeChallenge('https://example.test/premium'));

    $fake->assertSettled('https://example.test/premium');
});

it('assertNothingSettled passes when settle was never called', function (): void {
    (new FakeFacilitator())->assertNothingSettled();
});

it('assertNothingSettled fails when settle was called', function (): void {
    $fake = new FakeFacilitator();
    $fake->settle(fakeSignature(), fakeChallenge());

    $fake->assertNothingSettled();
})->throws(AssertionFailedError::class, 'Expected no settlement calls');

it('uses the network from the matched challenge in the SettleResult', function (): void {
    $fake = new FakeFacilitator();
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:84532', // Base Sepolia
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    $settle = $fake->settle(fakeSignature(), $challenge);

    expect($settle->network)->toBe('eip155:84532');
});

it('honours configurable payer + transaction defaults', function (): void {
    $fake = new FakeFacilitator();
    $fake->payer = '0xCustomPayer';
    $fake->transaction = '0xCustomTxHash';

    $verify = $fake->verify(fakeSignature(), fakeChallenge());
    $settle = $fake->settle(fakeSignature(), fakeChallenge());

    expect($verify->payer)->toBe('0xCustomPayer')
        ->and($settle->payer)->toBe('0xCustomPayer')
        ->and($settle->transaction)->toBe('0xCustomTxHash');
});

it('starts with empty recording arrays before any call', function (): void {
    $fake = new FakeFacilitator();

    expect($fake->verifyResults())->toBe([])
        ->and($fake->settleResults())->toBe([]);
});

it('records the VerifyResult instance returned from each verify() call', function (): void {
    $fake = new FakeFacilitator();
    $sig = fakeSignature();
    $chA = fakeChallenge('https://example.test/A');
    $chB = fakeChallenge('https://example.test/B');
    $chC = fakeChallenge('https://example.test/C');

    $first = $fake->verify($sig, $chA);
    $fake->rejectVerify('bad-nonce');
    $second = $fake->verify($sig, $chB);
    $fake->verifyOk = true;
    $fake->verifyReason = null;

    $third = $fake->verify($sig, $chC);

    expect($fake->verifyResults())->toHaveCount(3)
        ->and($fake->verifyResults()[0])->toBe($first)
        ->and($fake->verifyResults()[1])->toBe($second)
        ->and($fake->verifyResults()[2])->toBe($third)
        ->and($fake->verifyResults()[0]->isValid)->toBeTrue()
        ->and($fake->verifyResults()[1]->isValid)->toBeFalse()
        ->and($fake->verifyResults()[1]->invalidReason)->toBe('bad-nonce')
        ->and($fake->verifyResults()[2]->isValid)->toBeTrue()
        ->and($fake->verifyCalls())->toHaveCount(3)
        ->and($fake->verifyCalls()[0]['challenge']->resource)->toBe('https://example.test/A')
        ->and($fake->verifyCalls()[1]['challenge']->resource)->toBe('https://example.test/B')
        ->and($fake->verifyCalls()[2]['challenge']->resource)->toBe('https://example.test/C');
});

it('records the SettleResult instance returned from each settle() call', function (): void {
    $fake = new FakeFacilitator();
    $sig = fakeSignature();
    $chA = fakeChallenge('https://example.test/A');
    $chB = fakeChallenge('https://example.test/B');

    $first = $fake->settle($sig, $chA);
    $fake->failSettle('on-chain-revert');
    $second = $fake->settle($sig, $chB);

    expect($fake->settleResults())->toHaveCount(2)
        ->and($fake->settleResults()[0])->toBe($first)
        ->and($fake->settleResults()[1])->toBe($second)
        ->and($fake->settleResults()[0]->success)->toBeTrue()
        ->and($fake->settleResults()[1]->success)->toBeFalse()
        ->and($fake->settleResults()[1]->errorReason)->toBe('on-chain-revert')
        ->and($fake->settleCalls())->toHaveCount(2)
        ->and($fake->settleCalls()[0]['challenge']->resource)->toBe('https://example.test/A')
        ->and($fake->settleCalls()[1]['challenge']->resource)->toBe('https://example.test/B');
});

it('records the same instance that was returned (no clone)', function (): void {
    $fake = new FakeFacilitator();

    $verify = $fake->verify(fakeSignature(), fakeChallenge());
    $settle = $fake->settle(fakeSignature(), fakeChallenge());

    expect($fake->verifyResults()[0])->toBe($verify)
        ->and($fake->settleResults()[0])->toBe($settle);
});

it('returns empty supported() and discoverResources() pages', function (): void {
    $fake = new FakeFacilitator();

    expect($fake->supported()->kinds)->toBe([])
        ->and($fake->discoverResources()->items)->toBe([])
        ->and($fake->discoverResources()->total)->toBe(0);
});
