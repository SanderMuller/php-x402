<?php

declare(strict_types=1);

namespace X402\Support;

/**
 * Spaceship-semantics comparison of stringified non-negative decimal
 * integers — handles values that overflow PHP int (USDC `amount`,
 * EIP-3009 `value`, Permit2 `permitted.amount`, upto authorizations).
 *
 * Inputs are assumed to be base-10 with no sign or fraction. Leading
 * zeros are tolerated.
 */
final class DecimalCompare
{
    public static function compare(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if ($a === '') {
            $a = '0';
        }

        if ($b === '') {
            $b = '0';
        }

        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }
}
