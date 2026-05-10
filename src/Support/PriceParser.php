<?php

declare(strict_types=1);

namespace X402\Support;

use InvalidArgumentException;

/**
 * Convert human-readable decimal amounts (e.g. `"0.01"` USDC) to atomic
 * unit strings (e.g. `"10000"` for 6 decimals).
 *
 * Adopters working in `PaymentRequired::amount` (always a base-10 string
 * of atomic units per spec) need the conversion at the boundary between
 * their pricing config (`"$0.01"`, `"1.5 USDC"`) and the wire format.
 * The atomic-unit string has to be exact at any decimal count — float
 * math overflows at 18 decimals (`100.0 * 10^18` exceeds the double
 * mantissa) and `round()` silently bumps cents on sub-cent amounts.
 *
 * **Strict-by-default validation** (since 0.5.1): rejects negative
 * amounts, fractional digits past `$decimals`, and any input shape
 * other than `^\d+(\.\d+)?$` (no scientific notation, no thousands
 * separators, no leading `+`). These guards exist because each one is
 * a different way to silently lose buyer funds:
 *
 * - Negative amount via config typo → server records a credit, buyer
 *   notices on the facilitator side or never.
 * - Overflow via more frac digits than the asset supports → buyer
 *   signs `0.0001` for a 2-decimal asset, server records `0` atomic,
 *   facilitator settles `0` or rejects via typed-data hash mismatch.
 * - Scientific notation via `is_numeric()` accepting `'1e5'` →
 *   surprising amplification when callers pass user-supplied input.
 *
 * Opt into the looser behaviour via `allowNegative: true` and
 * `truncate: true` flags when you have a deliberate reason (refunds,
 * "round to the nearest cent" pricing). The defaults are the
 * protocol-safe choice.
 *
 * Implementation prefers `bcmul()` when ext-bcmath is available; falls
 * back to a pure-string decimal shift otherwise. Both paths are exact;
 * neither introduces float rounding.
 *
 * Used internally by `X402\Testing\PaymentRequiredBuilder`. Public so
 * downstream adapters (laravel-x402, custom Symfony / Slim integrations)
 * don't reinvent the conversion.
 */
final class PriceParser
{
    /**
     * Convert `$amount` (decimal string or float) into an atomic-unit
     * string given `$decimals`.
     *
     * @param  bool  $allowNegative  When false (default), `'-0.5'` throws. Set true for refund / credit math.
     * @param  bool  $truncate  When false (default), more fractional digits than `$decimals` throws (silent truncation = silent fund loss). Set true to drop trailing digits past `$decimals` without rounding.
     *
     * @throws InvalidArgumentException When the amount is not a base-10 decimal, when negative without `allowNegative`, or when frac digits exceed `$decimals` without `truncate`.
     */
    public static function toAtomic(
        float|string $amount,
        int $decimals,
        bool $allowNegative = false,
        bool $truncate = false,
    ): string {
        if ($decimals < 0) {
            throw new InvalidArgumentException(sprintf('Decimals must be >= 0, got %d.', $decimals));
        }

        $human = is_string($amount) ? $amount : sprintf('%.' . $decimals . 'F', $amount);

        // Strict shape: optional leading `-`, digits, optional `.digits`.
        // Rejects scientific notation, thousands separators, leading `+`,
        // hex / oct prefixes, whitespace — all of which `is_numeric()`
        // would accept, and all of which are config typos in the
        // protocol-amount context.
        if (preg_match('/^-?\d+(\.\d+)?$/', $human) !== 1) {
            throw new InvalidArgumentException(sprintf('Amount must be a base-10 decimal (^-?\\d+(\\.\\d+)?$), got "%s".', $human));
        }

        $negative = str_starts_with($human, '-');

        if ($negative && ! $allowNegative) {
            throw new InvalidArgumentException(sprintf('Amount must be non-negative, got "%s". Pass allowNegative: true if you need refund / credit math.', $human));
        }

        $unsigned = $negative ? substr($human, 1) : $human;

        // Frac-digit overflow check happens here rather than inside
        // shiftDecimalPoint so the strict-default error message can
        // reference the original input (with sign).
        $fracPart = str_contains($unsigned, '.') ? explode('.', $unsigned, 2)[1] : '';

        if (\strlen($fracPart) > $decimals && ! $truncate) {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" has %d fractional digits but the asset only supports %d. Pass truncate: true to drop trailing digits, or fix the input.',
                $human,
                \strlen($fracPart),
                $decimals,
            ));
        }

        // The pure-string shift handles every case bcmath would —
        // negative, fractional, 18-decimal — exactly and without
        // float math. A bcmath branch was tested earlier but added no
        // measurable speedup for the per-challenge workload while
        // tripping `numeric-string` typing on PHPStan max + bleeding
        // edge. One code path, no float, easy to reason about.
        return self::shiftDecimalPoint($unsigned, $decimals, $negative);
    }

    /**
     * Pure-string decimal shift — no float math involved, exact at any
     * decimal count. Truncation past `$decimals` is strict (no rounding)
     * so atomic conversions don't silently bump cents. Caller is
     * responsible for the `truncate` decision; this method always
     * truncates the frac part to `$decimals` chars (validation already
     * gated this upstream).
     */
    private static function shiftDecimalPoint(string $unsigned, int $decimals, bool $negative): string
    {
        [$intPart, $fracPart] = str_contains($unsigned, '.') ? explode('.', $unsigned, 2) : [$unsigned, ''];

        if (\strlen($fracPart) > $decimals) {
            $fracPart = substr($fracPart, 0, $decimals);
        } else {
            $fracPart = str_pad($fracPart, $decimals, '0', \STR_PAD_RIGHT);
        }

        $combined = ltrim($intPart . $fracPart, '0');
        $result = $combined === '' ? '0' : $combined;

        return $negative && $result !== '0' ? '-' . $result : $result;
    }
}
