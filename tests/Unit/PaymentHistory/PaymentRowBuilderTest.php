<?php

declare(strict_types=1);

use X402\Facilitator\PaymentOutcome;
use X402\Facilitator\PaymentOutcomeKind;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\PaymentHistory\PaymentRowBuilder;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

function rowBuilderChallenge(string $resource = 'https://example.test/premium'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0xrecipient',
        resource: $resource,
    );
}

function rowBuilderEip3009Signature(): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce', 'validBefore' => 9999999999]],
        extensions: ['source' => 'sig-ext'],
    );
}

it('builds a settled row using SettleResult fields and the captured resource', function (): void {
    $now = new DateTimeImmutable('2026-05-10 12:00:00');
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(
            success: true,
            transaction: '0xtxhash',
            network: 'eip155:8453',
            payer: '0xpayerFromFacilitator',
            amount: '9999',
            extensions: ['receipt' => 'ok'],
        ),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, ['user_id' => 7], now: $now);

    expect($row)->toBe([
        'status' => 'settled',
        'resource' => 'route:premium',
        'payer' => '0xpayerFromFacilitator',
        'pay_to' => '0xrecipient',
        'amount' => '9999',
        'asset' => '0xasset',
        'network' => 'eip155:8453',
        'transaction' => '0xtxhash',
        'nonce' => '0xnonce',
        'tracker' => null,
        'reason' => null,
        'extensions' => ['receipt' => 'ok'],
        'meta' => ['user_id' => 7],
        'settled_at' => $now,
    ]);
});

it('falls back to challenge.amount when SettleResult.amount is null', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(
            success: true,
            transaction: '0xtxhash',
            network: 'eip155:8453',
            payer: '0xpayer',
            amount: null,
        ),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['amount'])->toBe('10000'); // challenge amount
});

it('falls back to authorization.from when SettleResult.payer is empty string', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtxhash', network: 'eip155:8453', payer: ''),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['payer'])->toBe('0xfrom');
});

it('sets transaction to null when SettleResult.transaction is empty string', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '', network: 'eip155:8453', payer: '0xpayer'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['transaction'])->toBeNull();
});

it('falls back to challenge.network when SettleResult.network is empty string', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtxhash', network: '', payer: '0xpayer'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['network'])->toBe('eip155:8453');
});

it('builds a rejected row with reason from VerifyRejected outcome', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'insufficient-funds',
        verify: new VerifyResult(isValid: false, invalidReason: 'insufficient-funds'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, ['ip' => '1.2.3.4']);

    expect($row)->toBe([
        'status' => 'rejected',
        'resource' => 'route:premium',
        'payer' => '0xfrom',
        'pay_to' => '0xrecipient',
        'amount' => '10000',
        'asset' => '0xasset',
        'network' => 'eip155:8453',
        'transaction' => null,
        'nonce' => '0xnonce',
        'tracker' => null,
        'reason' => 'insufficient-funds',
        'extensions' => ['source' => 'sig-ext'],
        'meta' => ['ip' => '1.2.3.4'],
        'settled_at' => null,
    ]);
});

it('truncates reason to default 255 chars (silent fund-loss guard for *Error kinds)', function (): void {
    $longReason = 'verify-error: RuntimeException: ' . str_repeat('socket-timeout-detail-', 50); // ~1100 chars

    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyError,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: $longReason,
        exception: new RuntimeException('socket timeout'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    $reason = $row['reason'];
    expect($reason)->toBeString();
    /** @var string $reason */
    expect(mb_strlen($reason))->toBe(255)
        ->and($reason)->toStartWith('verify-error: RuntimeException: ');
});

it('honours a custom reasonMaxLength when adopters override the schema cap', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: str_repeat('A', 1000),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, reasonMaxLength: 50);

    $reason = $row['reason'];
    expect($reason)->toBeString();
    /** @var string $reason */
    expect(mb_strlen($reason))->toBe(50);
});

it('does not truncate when reasonMaxLength is 0 (unlimited)', function (): void {
    $longReason = str_repeat('A', 500);
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: $longReason,
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, reasonMaxLength: 0);

    expect($row['reason'])->toBe($longReason);
});

it('preserves null reason on settled rows even when reasonMaxLength is set', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['reason'])->toBeNull();
});

it('uses replayKey from/nonce when provided (scheme-aware extraction for non-EIP-3009)', function (): void {
    // Permit2-style signature — payload uses permit2Authorization, not authorization.
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'permit2Authorization' => ['from' => '0xpermit2from', 'nonce' => '0xpermit2nonce', 'deadline' => 9999999999]],
    );

    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: $signature,
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'not-allowed',
    );

    $row = PaymentRowBuilder::fromOutcome(
        $outcome,
        replayKey: ['from' => '0xpermit2from', 'nonce' => '0xpermit2nonce', 'expiresAt' => 9999999999],
    );

    expect($row['payer'])->toBe('0xpermit2from')
        ->and($row['nonce'])->toBe('0xpermit2nonce');
});

it('falls back to authorization() for from/nonce when replayKey is null', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'rejected',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, replayKey: null);

    expect($row['payer'])->toBe('0xfrom')
        ->and($row['nonce'])->toBe('0xnonce');
});

it('writes null from/nonce when both replayKey is null AND authorization() returns null (non-EIP-3009 fallthrough)', function (): void {
    // Permit2-shaped payload + no replayKey supplied.
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'permit2Authorization' => ['from' => '0xperm', 'nonce' => '0xpn']],
    );

    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: $signature,
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'rejected',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['payer'])->toBeNull()
        ->and($row['nonce'])->toBeNull();
});

it('uses signature.extensions on rejected rows (and SettleResult.extensions on settled rows)', function (): void {
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce']],
        extensions: ['origin' => 'sig'],
    );

    $rejected = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: $signature,
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'rejected',
    );
    $settled = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: $signature,
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(
            success: true,
            transaction: '0xtx',
            network: 'eip155:8453',
            payer: '0xpayer',
            extensions: ['origin' => 'settle'],
        ),
    );

    $rejectedRow = PaymentRowBuilder::fromOutcome($rejected);
    $settledRow = PaymentRowBuilder::fromOutcome($settled);

    expect($rejectedRow['extensions'])->toBe(['origin' => 'sig'])
        ->and($settledRow['extensions'])->toBe(['origin' => 'settle']);
});

it('falls back to empty array when signature.extensions is null on rejected rows', function (): void {
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce']],
    );

    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: $signature,
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'rejected',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['extensions'])->toBe([]);
});

it('falls back to empty array when SettleResult.extensions is null', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['extensions'])->toBe([]);
});

it('uses injected $now for testability', function (): void {
    $now = new DateTimeImmutable('2026-01-01 00:00:00');
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, now: $now);

    expect($row['settled_at'])->toBe($now);
});

it('defaults $now to current time when not injected', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettleSucceeded,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
    );

    $before = new DateTimeImmutable();
    $row = PaymentRowBuilder::fromOutcome($outcome);
    $after = new DateTimeImmutable();

    expect($row['settled_at'])->toBeInstanceOf(DateTimeImmutable::class);
    /** @var DateTimeImmutable $settledAt */
    $settledAt = $row['settled_at'];
    expect($settledAt >= $before && $settledAt <= $after)->toBeTrue();
});

it('uses outcome.resource verbatim regardless of challenge.resource', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge('https://example.test/raw-challenge-url'),
        resource: 'route:formatted-by-dispatcher',
        reason: 'rejected',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['resource'])->toBe('route:formatted-by-dispatcher');
});

it('writes empty meta array when no context is supplied', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: 'rejected',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['meta'])->toBe([]);
});

it('builds a pending row with status=pending, transaction=null, tracker populated', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettlePending,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: SettleResult::pending('tracker-abc', 'eip155:8453', '0xpayerFromFacilitator'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, ['user_id' => 7]);

    expect($row)->toBe([
        'status' => 'pending',
        'resource' => 'route:premium',
        'payer' => '0xpayerFromFacilitator',
        'pay_to' => '0xrecipient',
        'amount' => '10000',
        'asset' => '0xasset',
        'network' => 'eip155:8453',
        'transaction' => null,
        'nonce' => '0xnonce',
        'tracker' => 'tracker-abc',
        'reason' => null,
        'extensions' => [],
        'meta' => ['user_id' => 7],
        'settled_at' => null,
    ]);
});

it('uses settle.network and settle.amount on pending rows when present', function (): void {
    $settle = new SettleResult(
        success: false,
        transaction: '',
        network: 'eip155:42161',
        payer: '0xpayer',
        amount: '7777',
        extensions: ['hint' => 'arb'],
        tracker: 'tracker-xyz',
    );

    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettlePending,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: $settle,
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['network'])->toBe('eip155:42161')
        ->and($row['amount'])->toBe('7777')
        ->and($row['extensions'])->toBe(['hint' => 'arb'])
        ->and($row['tracker'])->toBe('tracker-xyz');
});

it('preserves null settled_at and null transaction on pending rows', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettlePending,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        settle: SettleResult::pending('t', 'eip155:8453'),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome, now: new DateTimeImmutable('2026-05-10'));

    expect($row['settled_at'])->toBeNull()
        ->and($row['transaction'])->toBeNull()
        ->and($row['reason'])->toBeNull();
});

it('falls back to challenge fields on pending rows when SettleResult fields are empty', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::SettlePending,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        // SettleResult::pending() defaults network from arg, payer to ''.
        settle: SettleResult::pending('tracker-abc', ''),
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['network'])->toBe('eip155:8453') // challenge fallback
        ->and($row['payer'])->toBe('0xfrom') // authorization() fallback
        ->and($row['amount'])->toBe('10000'); // challenge fallback
});

it('writes null reason when outcome.reason is empty string after truncation', function (): void {
    $outcome = new PaymentOutcome(
        kind: PaymentOutcomeKind::VerifyRejected,
        signature: rowBuilderEip3009Signature(),
        challenge: rowBuilderChallenge(),
        resource: 'route:premium',
        reason: '',
    );

    $row = PaymentRowBuilder::fromOutcome($outcome);

    expect($row['reason'])->toBeNull();
});
