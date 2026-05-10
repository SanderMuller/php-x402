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

it('accepts numeric strings AND floats with the same precision contract', function (): void {
    expect(PriceParser::toAtomic('0.01', 6))->toBe(PriceParser::toAtomic(0.01, 6));
});

it('rejects non-numeric input', function (): void {
    PriceParser::toAtomic('not-a-number', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');

it('rejects negative decimals (asset config error)', function (): void {
    PriceParser::toAtomic('1', -1);
})->throws(InvalidArgumentException::class, 'Decimals must be >= 0');

it('handles a zero amount', function (): void {
    expect(PriceParser::toAtomic('0', 6))->toBe('0')
        ->and(PriceParser::toAtomic('0.000', 6))->toBe('0');
});

// --- Strict-validation guards (since 0.5.1, peer-driven) ---

it('rejects negative amounts by default (silent fund-loss guard)', function (): void {
    PriceParser::toAtomic('-0.5', 6);
})->throws(InvalidArgumentException::class, 'Amount must be non-negative');

it('allows negative amounts when allowNegative: true is passed', function (): void {
    expect(PriceParser::toAtomic('-0.5', 6, allowNegative: true))->toBe('-500000')
        ->and(PriceParser::toAtomic('-1.5', 18, allowNegative: true))->toBe('-1500000000000000000');
});

it('rejects amounts with more fractional digits than the asset supports (silent fund-loss guard)', function (): void {
    // 0.0001 USDC with decimals=2 would silently truncate to "0" atomic.
    // A buyer signing this authorization gets debited for 0; the seller
    // sees a free request. Throw by default; opt into truncate explicitly.
    PriceParser::toAtomic('0.0001', 2);
})->throws(InvalidArgumentException::class, 'has 4 fractional digits but the asset only supports 2');

it('truncates fractional digits when truncate: true is passed (no rounding)', function (): void {
    // Strict truncation: 0.0000019 with 6 decimals → "1" (drops the
    // trailing 9 instead of rounding up to "2"). Atomic conversions
    // must not silently bump cents.
    expect(PriceParser::toAtomic('0.0000019', 6, truncate: true))->toBe('1')
        ->and(PriceParser::toAtomic('0.0000005', 6, truncate: true))->toBe('0');
});

it('rejects scientific notation (is_numeric would have accepted it)', function (): void {
    // '1e5' is_numeric == true, but in protocol-amount context it's a
    // config typo — silent acceleration to a much larger amount than
    // the caller intended.
    PriceParser::toAtomic('1e5', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');

it('rejects thousands separators', function (): void {
    PriceParser::toAtomic('1,000', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');

it('rejects leading-plus sign (strict regex, not is_numeric)', function (): void {
    // Pre-0.5.1 the leading `+` was tolerated via is_numeric. Strict
    // shape now mirrors what callers actually mean to write.
    PriceParser::toAtomic('+1.5', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');

it('rejects whitespace-padded input', function (): void {
    PriceParser::toAtomic(' 1.5 ', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');
