<?php

declare(strict_types=1);

use X402\Protocol\PaymentRequired;
use X402\Server\RegexPriceTable;

function premiumChallenge(string $amount = '10000'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: $amount,
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
}

it('returns the registered challenges when the pattern matches', function (): void {
    $table = new RegexPriceTable();
    $table->add('#^/api/v\d+/users/\d+$#', premiumChallenge());

    expect($table->challengesFor('/api/v1/users/42'))->toHaveCount(1);
});

it('returns an empty list when no pattern matches', function (): void {
    $table = new RegexPriceTable();
    $table->add('#^/paid#', premiumChallenge());

    expect($table->challengesFor('/free'))->toBe([]);
});

it('uses the first matching pattern (insertion order)', function (): void {
    $table = new RegexPriceTable();
    $table->add('#^/api/.*$#', premiumChallenge('10000'));
    $table->add('#^/api/v1/users/\d+$#', premiumChallenge('99999'));

    $challenges = $table->challengesFor('/api/v1/users/42');

    expect($challenges)->toHaveCount(1)
        ->and($challenges[0]->amount)->toBe('10000');
});

it('supports multiple challenges per pattern', function (): void {
    $table = new RegexPriceTable();
    $table->add('#^/premium$#', premiumChallenge('10000'), premiumChallenge('20000'));

    expect($table->challengesFor('/premium'))->toHaveCount(2);
});

it('rejects a malformed PCRE pattern at registration time', function (): void {
    $table = new RegexPriceTable();

    expect(fn () => $table->add('garbage-not-pcre', premiumChallenge()))
        ->toThrow(InvalidArgumentException::class, 'Invalid PCRE pattern');
});
