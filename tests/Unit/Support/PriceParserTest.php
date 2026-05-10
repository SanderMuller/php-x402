<?php

declare(strict_types=1);

use X402\Support\PriceParser;

it('converts USDC decimal strings to 6-decimal atomic units', function (): void {
    expect(PriceParser::toAtomic('0.01', 6))->toBe('10000')
        ->and(PriceParser::toAtomic('1', 6))->toBe('1000000')
        ->and(PriceParser::toAtomic('1.5', 6))->toBe('1500000')
        ->and(PriceParser::toAtomic('0.000001', 6))->toBe('1');
});

it('handles 18-decimal amounts without float overflow', function (): void {
    // 100 * 10^18 overflows the double-precision mantissa — verify the
    // builder produces an exact string instead of a rounded one.
    expect(PriceParser::toAtomic('100', 18))->toBe('100000000000000000000')
        ->and(PriceParser::toAtomic('1.5', 18))->toBe('1500000000000000000');
});

it('truncates fractional digits past decimals (no rounding)', function (): void {
    // Strict truncation: 0.0000019 with 6 decimals → "1" (drops the
    // trailing 9 instead of rounding up to "2"). Atomic conversions
    // must not silently bump cents.
    expect(PriceParser::toAtomic('0.0000019', 6))->toBe('1')
        ->and(PriceParser::toAtomic('0.0000005', 6))->toBe('0');
});

it('accepts numeric strings AND floats with the same precision contract', function (): void {
    expect(PriceParser::toAtomic('0.01', 6))->toBe(PriceParser::toAtomic(0.01, 6));
});

it('rejects non-numeric input with InvalidArgumentException', function (): void {
    PriceParser::toAtomic('not-a-number', 6);
})->throws(InvalidArgumentException::class, 'Amount must be numeric');

it('rejects negative decimals', function (): void {
    PriceParser::toAtomic('1', -1);
})->throws(InvalidArgumentException::class, 'Decimals must be >= 0');

it('handles a zero amount', function (): void {
    expect(PriceParser::toAtomic('0', 6))->toBe('0')
        ->and(PriceParser::toAtomic('0.000', 6))->toBe('0');
});

it('handles a leading-plus sign', function (): void {
    expect(PriceParser::toAtomic('+1.5', 6))->toBe('1500000');
});
