<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * secp256k1 signature recovery for EVM signatures.
 *
 * For high-volume verification, install ext-secp256k1 (PECL) — pure PHP is
 * ~50× slower than the native binding. The package falls back to pure PHP
 * (simplito/elliptic-php) when the extension is missing.
 *
 * @internal
 */
final class SignatureVerifier
{
    /**
     * Recover the EVM address that signed `digest` to produce `signature`.
     *
     * @param  string  $digest  0x-prefixed 32-byte hex digest.
     * @param  string  $signature  0x-prefixed 65-byte hex (r || s || v).
     */
    public function recover(string $digest, string $signature): string
    {
        $sig = str_starts_with($signature, '0x') ? substr($signature, 2) : $signature;

        if (\strlen($sig) !== 130) {
            throw new \InvalidArgumentException(sprintf('Signature must be 65 bytes hex (got %d chars).', \strlen($sig)));
        }

        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = (int) hexdec(substr($sig, 128, 2));

        $recId = $v >= 27 ? $v - 27 : $v;

        if ($recId !== 0 && $recId !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid recovery id "%d".', $recId));
        }

        $msgHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;

        $ec = new EC('secp256k1');
        $publicKey = $ec->recoverPubKey($msgHex, ['r' => $r, 's' => $s], $recId);

        /** @var string $pubHex */
        $pubHex = $publicKey->encode('hex');

        // Drop the leading 0x04 marker, keccak256 of the 64-byte (X||Y), take last 20 bytes.
        $stripped = substr($pubHex, 2);
        $hash = Keccak::hash(hex2bin($stripped), 256);

        return '0x'.substr($hash, 24);
    }

    /**
     * Constant-time-ish address comparison (case-insensitive).
     */
    public function addressesEqual(string $a, string $b): bool
    {
        return hash_equals(strtolower($a), strtolower($b));
    }
}
