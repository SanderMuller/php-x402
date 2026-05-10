<?php

declare(strict_types=1);

use X402\Protocol\PaymentResponse;

it('round-trips through header encoding', function (): void {
    $original = new PaymentResponse(
        success: true,
        transaction: '0xabc123',
        network: 'eip155:8453',
        payer: '0x000000000000000000000000000000000000beef',
    );

    $bin = base64_decode($original->toHeader(), true);
    $decoded = json_decode($bin === false ? '' : $bin, true);

    expect($decoded)->toBe([
        'success' => true,
        'transaction' => '0xabc123',
        'network' => 'eip155:8453',
        'payer' => '0x000000000000000000000000000000000000beef',
    ]);
});

it('serialises failures with success=false', function (): void {
    $response = new PaymentResponse(
        success: false,
        transaction: '',
        network: 'eip155:8453',
        payer: '0xabc',
    );

    expect($response->toArray()['success'])->toBeFalse();
});

it('omits tracker from toArray when null or empty', function (): void {
    $nullTracker = new PaymentResponse(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer', tracker: null);
    $emptyTracker = new PaymentResponse(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer', tracker: '');

    expect($nullTracker->toArray())->not->toHaveKey('tracker')
        ->and($emptyTracker->toArray())->not->toHaveKey('tracker');
});

it('emits tracker on toArray when non-empty', function (): void {
    $pending = new PaymentResponse(
        success: false,
        transaction: '',
        network: 'eip155:8453',
        payer: '0xpayer',
        tracker: 'tracker-async-1',
    );

    expect($pending->toArray())->toBe([
        'success' => false,
        'transaction' => '',
        'network' => 'eip155:8453',
        'payer' => '0xpayer',
        'tracker' => 'tracker-async-1',
    ]);
});

it('isPending() returns true only when not success and tracker is non-empty', function (): void {
    $settled = new PaymentResponse(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer');
    $failed = new PaymentResponse(success: false, transaction: '', network: 'eip155:8453', payer: '');
    $failedEmptyTracker = new PaymentResponse(success: false, transaction: '', network: 'eip155:8453', payer: '', tracker: '');
    $pending = new PaymentResponse(success: false, transaction: '', network: 'eip155:8453', payer: '0xpayer', tracker: 'tracker-x');

    expect($settled->isPending())->toBeFalse()
        ->and($failed->isPending())->toBeFalse()
        ->and($failedEmptyTracker->isPending())->toBeFalse()
        ->and($pending->isPending())->toBeTrue();
});
