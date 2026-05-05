<?php

declare(strict_types=1);

use X402\Protocol\PaymentRequired;
use X402\Server\StaticPriceTable;

it('returns the registered challenges for a resource', function (): void {
    $table = new StaticPriceTable;
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        maxAmountRequired: '10000',
        asset: '0xabc',
        payTo: '0xdef',
    );

    $table->set('/premium', $challenge);

    expect($table->challengesFor('/premium'))->toHaveCount(1);
    expect($table->challengesFor('/premium')[0])->toBe($challenge);
});

it('returns an empty list for unknown resources', function (): void {
    $table = new StaticPriceTable;

    expect($table->challengesFor('/unknown'))->toBe([]);
});

it('supports multiple challenges per resource', function (): void {
    $table = new StaticPriceTable;
    $base = new PaymentRequired('exact', 'eip155:8453', '10000', '0xa', '0xb');
    $polygon = new PaymentRequired('exact', 'eip155:137', '10000', '0xa', '0xb');

    $table->set('/premium', $base, $polygon);

    expect($table->challengesFor('/premium'))->toHaveCount(2);
});
