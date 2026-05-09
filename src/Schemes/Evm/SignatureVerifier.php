<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * secp256k1 signature recovery for EVM signatures.
 *
 * For high-volume verification, install ext-secp256k1 (PECL) — pure PHP is
 * ~50× slower than the native binding. The package falls back to pure PHP
 * (simplito/elliptic-php) when the extension is missing.
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
            throw new InvalidArgumentException(sprintf('Signature must be 65 bytes hex (got %d chars).', \strlen($sig)));
        }

        // hexdec() silently treats non-hex chars as 0 (e.g. "gg" → 0),
        // which would coerce a malformed v byte into a valid recovery
        // id. Validate the full hex string up front.
        if (! ctype_xdigit($sig)) {
            throw new InvalidArgumentException('Signature must be hex-encoded (non-hex characters present).');
        }

        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = (int) hexdec(substr($sig, 128, 2));

        // EIP-712 typed-data signatures use raw {0,1} or {27,28}. EIP-155
        // chainId-offset v values apply to transaction signing, not typed
        // data — reject them explicitly so callers see a clear message
        // rather than a downstream "invalid recovery id" from the offset
        // arithmetic.
        $recId = match ($v) {
            0, 27 => 0,
            1, 28 => 1,
            default => throw new InvalidArgumentException(sprintf('Invalid signature v byte "%d" — expected 0, 1, 27, or 28.', $v)),
        };

        $msgHex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;

        $ec = new EC('secp256k1');
        $publicKey = $ec->recoverPubKey($msgHex, ['r' => $r, 's' => $s], $recId);

        $pubHex = $publicKey->encode('hex');

        if (! is_string($pubHex)) {
            throw new InvalidArgumentException('Point::encode did not return a string.');
        }

        // Drop the leading 0x04 marker, keccak256 of the 64-byte (X||Y), take last 20 bytes.
        $stripped = substr($pubHex, 2);
        $pubBin = hex2bin($stripped);

        if ($pubBin === false) {
            throw new InvalidArgumentException('Recovered public key is not valid hex.');
        }

        $hash = Keccak::hash($pubBin, 256);

        return '0x' . substr($hash, 24);
    }

    /**
     * Constant-time-ish address comparison (case-insensitive).
     */
    public function addressesEqual(string $a, string $b): bool
    {
        return hash_equals(strtolower($a), strtolower($b));
    }
}
