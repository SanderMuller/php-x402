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
 */
final class SignatureExporter
{
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

        $rHex = str_pad($rRaw, 64, '0', STR_PAD_LEFT);
        $sHex = str_pad($sRaw, 64, '0', STR_PAD_LEFT);
        $vHex = str_pad(dechex($v + 27), 2, '0', STR_PAD_LEFT);

        return '0x' . $rHex . $sHex . $vHex;
    }
}
