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
     * @throws InvalidArgumentException When the amount is not a numeric value.
     */
    public static function toAtomic(float|string $amount, int $decimals): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException(sprintf('Decimals must be >= 0, got %d.', $decimals));
        }

        $human = is_string($amount) ? $amount : sprintf('%.' . $decimals . 'F', $amount);

        if (! is_numeric($human)) {
            throw new InvalidArgumentException(sprintf('Amount must be numeric, got "%s".', $human));
        }

        if (function_exists('bcmul')) {
            return bcmul($human, (string) (10 ** $decimals), 0);
        }

        return self::shiftDecimalPoint($human, $decimals);
    }

    /**
     * Pure-string decimal shift — no float math involved, exact at any
     * decimal count. Truncation past `$decimals` is strict (no rounding)
     * so atomic conversions don't silently bump cents.
     */
    private static function shiftDecimalPoint(string $human, int $decimals): string
    {
        $negative = str_starts_with($human, '-');

        if ($negative || str_starts_with($human, '+')) {
            $human = substr($human, 1);
        }

        [$intPart, $fracPart] = str_contains($human, '.') ? explode('.', $human, 2) : [$human, ''];

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
