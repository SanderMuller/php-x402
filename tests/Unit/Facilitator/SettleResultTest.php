<?php

declare(strict_types=1);

use X402\Facilitator\SettleResult;

it('builds a pending SettleResult via ::pending', function (): void {
    $pending = SettleResult::pending('tracker-abc', 'eip155:8453', '0xpayer');

    expect($pending->success)->toBeFalse()
        ->and($pending->transaction)->toBe('')
        ->and($pending->network)->toBe('eip155:8453')
        ->and($pending->payer)->toBe('0xpayer')
        ->and($pending->tracker)->toBe('tracker-abc')
        ->and($pending->isPending())->toBeTrue();
});

it('defaults pending payer to empty string when not supplied', function (): void {
    $pending = SettleResult::pending('tracker-xyz', 'eip155:8453');

    expect($pending->payer)->toBe('')
        ->and($pending->isPending())->toBeTrue();
});

it('isPending() returns true only when not success and tracker is non-empty', function (): void {
    $settled = new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer');
    $failed = new SettleResult(success: false, transaction: '', network: 'eip155:8453', payer: '');
    $failedWithEmptyTracker = new SettleResult(success: false, transaction: '', network: 'eip155:8453', payer: '', tracker: '');
    $pending = SettleResult::pending('t', 'eip155:8453');

    expect($settled->isPending())->toBeFalse()
        ->and($failed->isPending())->toBeFalse()
        ->and($failedWithEmptyTracker->isPending())->toBeFalse()
        ->and($pending->isPending())->toBeTrue();
});

it('throws when success=true and tracker is non-empty', function (): void {
    new SettleResult(
        success: true,
        transaction: '0xtx',
        network: 'eip155:8453',
        payer: '0xpayer',
        tracker: 'tracker-abc',
    );
})->throws(InvalidArgumentException::class, 'success=true');

it('allows success=true with explicit null tracker', function (): void {
    $settled = new SettleResult(
        success: true,
        transaction: '0xtx',
        network: 'eip155:8453',
        payer: '0xpayer',
        tracker: null,
    );

    expect($settled->success)->toBeTrue()
        ->and($settled->tracker)->toBeNull();
});

it('allows success=true with empty-string tracker (no programming-error signal)', function (): void {
    $settled = new SettleResult(
        success: true,
        transaction: '0xtx',
        network: 'eip155:8453',
        payer: '0xpayer',
        tracker: '',
    );

    expect($settled->success)->toBeTrue()
        ->and($settled->tracker)->toBe('');
});

it('throws when SettleResult::pending() called with empty tracker', function (): void {
    SettleResult::pending('', 'eip155:8453');
})->throws(InvalidArgumentException::class, 'non-empty tracker');
