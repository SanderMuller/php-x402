<?php

declare(strict_types=1);

use X402\Support\DecimalCompare;

it('returns 0 for equal values', function (): void {
    expect(DecimalCompare::compare('100', '100'))->toBe(0);
});

it('compares by length when digit counts differ', function (): void {
    expect(DecimalCompare::compare('1000', '999'))->toBe(1)
        ->and(DecimalCompare::compare('99', '1000'))->toBe(-1);
});

it('strips leading zeros before length comparison', function (): void {
    expect(DecimalCompare::compare('0099', '100'))->toBe(-1)
        ->and(DecimalCompare::compare('00100', '100'))->toBe(0);
});

it('treats empty / all-zero strings as 0', function (): void {
    expect(DecimalCompare::compare('', '0'))->toBe(0)
        ->and(DecimalCompare::compare('000', ''))->toBe(0);
});

it('compares lexicographically when same length', function (): void {
    expect(DecimalCompare::compare('500', '499'))->toBe(1)
        ->and(DecimalCompare::compare('123', '124'))->toBe(-1);
});

it('handles values that overflow PHP int', function (): void {
    $a = '99999999999999999999999999999999';
    $b = '99999999999999999999999999999998';

    expect(DecimalCompare::compare($a, $b))->toBe(1)
        ->and(DecimalCompare::compare($b, $a))->toBe(-1)
        ->and(DecimalCompare::compare($a, $a))->toBe(0);
});

it('normalizes to spaceship range', function (): void {
    expect(DecimalCompare::compare('1', '2'))->toBe(-1)
        ->and(DecimalCompare::compare('2', '1'))->toBe(1)
        ->and(DecimalCompare::compare('1', '1'))->toBe(0);
});
