<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use BN\BN;
use Elliptic\EC\Signature;
use RuntimeException;

/**
 * Convert an `Elliptic\EC\Signature` (with its untyped `$r`/`$s`/`$recoveryParam`
 * properties) into the canonical 0x-prefixed 65-byte hex string expected by
 * EIP-712 / EIP-3009 verifiers.
 *
 * The elliptic-php library has no PHPDoc types, so we narrow the property
 * accesses with `instanceof` checks here and let PHPStan's stub-driven method
 * return types take it from there. Keeps the noise out of the call sites.
 *
 * EIP-2 (low-s) is enforced here as a belt-and-braces measure: simplito's
 * `canonical: true` option is silently dropped when callers pass options as
 * the third positional argument to `KeyPair::sign($enc=false, $options=…)` —
 * `EC::sign`'s first branch swaps the args and clobbers them. We normalise
 * defensively rather than rely on the upstream option staying live.
 */
final class SignatureExporter
{
    /** secp256k1 curve order (n). */
    private const SECP256K1_N_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /** Half of n — the EIP-2 low-s threshold. */
    private const SECP256K1_HALF_N_HEX = '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0';

    public static function toHex65(Signature $signature): string
    {
        $r = $signature->r;
        $s = $signature->s;
        $v = $signature->recoveryParam;

        if (! $r instanceof BN || ! $s instanceof BN) {
            throw new RuntimeException('Signature components are not BN values.');
        }

        if (! is_int($v)) {
            throw new RuntimeException('Signature recoveryParam is not an integer.');
        }

        $rRaw = $r->toString(16);
        $sRaw = $s->toString(16);

        if (! is_string($rRaw) || ! is_string($sRaw)) {
            throw new RuntimeException('BN::toString did not return a string.');
        }

        [$sHex, $v] = self::canonicalise($sRaw, $v);

        $rHex = str_pad($rRaw, 64, '0', STR_PAD_LEFT);
        $vHex = str_pad(dechex($v + 27), 2, '0', STR_PAD_LEFT);

        return '0x' . $rHex . $sHex . $vHex;
    }

    /**
     * If s > n/2, replace it with (n - s) and flip the recovery parity.
     *
     * @return array{0: string, 1: int}  [s as 64-char hex, recoveryParam ∈ {0,1}]
     */
    private static function canonicalise(string $sRaw, int $recoveryParam): array
    {
        $sHex = str_pad($sRaw, 64, '0', STR_PAD_LEFT);

        if (\gmp_cmp(\gmp_init($sHex, 16), \gmp_init(self::SECP256K1_HALF_N_HEX, 16)) <= 0) {
            return [$sHex, $recoveryParam];
        }

        $flipped = \gmp_sub(\gmp_init(self::SECP256K1_N_HEX, 16), \gmp_init($sHex, 16));
        $flippedHex = str_pad(\gmp_strval($flipped, 16), 64, '0', STR_PAD_LEFT);

        return [$flippedHex, $recoveryParam ^ 1];
    }
}
