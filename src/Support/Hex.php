<?php

declare(strict_types=1);

namespace X402\Support;

use InvalidArgumentException;

/**
 * Hex-string helpers shared across the EVM/Upto schemes and the HD wallet.
 *
 * `hex2bin` returns `false` on odd-length or non-hex input AND raises an
 * E_WARNING — surfacing those as a typed exception keeps callers from having
 * to guard the boolean themselves.
 */
final class Hex
{
    public static function toBinary(string $hex): string
    {
        $bin = hex2bin($hex);

        if ($bin === false) {
            throw new InvalidArgumentException(sprintf('Invalid hex input: "%s".', $hex));
        }

        return $bin;
    }
}
