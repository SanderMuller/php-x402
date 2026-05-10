<?php

declare(strict_types=1);

namespace X402\Support;

use InvalidArgumentException;

/**
 * Minimal strict ASN.1 DER decoder for the two shapes KMS-backed wallets
 * actually need:
 *
 * 1. ECDSA signatures — `SEQUENCE { r INTEGER, s INTEGER }`.
 *    AWS KMS, GCP KMS, and Azure Key Vault all return signatures in this
 *    form. Callers want the raw 32-byte r and s components to pack into
 *    the EVM `r || s || v` wire format.
 *
 * 2. secp256k1 SubjectPublicKeyInfo — outer `SEQUENCE`, AlgorithmIdentifier
 *    `SEQUENCE`, then a `BIT STRING` carrying `0x04 || X || Y` (65 bytes
 *    uncompressed point). Callers want the 65-byte point so they can
 *    derive the EVM address.
 *
 * Both parsers fail closed: malformed length prefixes, wrong tags,
 * trailing bytes, oversized integers, or non-canonical points throw
 * `InvalidArgumentException`. There is intentionally no recovery — KMS
 * input that doesn't match the documented shape is a configuration bug,
 * not a runtime case to be papered over.
 */
final class Asn1DerDecoder
{
    /**
     * Canonical AlgorithmIdentifier prefix for secp256k1 SubjectPublicKeyInfo:
     *
     *     SEQUENCE (16 bytes)
     *         OID 1.2.840.10045.2.1 (id-ecPublicKey)
     *         OID 1.3.132.0.10      (secp256k1)
     *
     * Adopted by AWS KMS, GCP KMS, Azure Key Vault, OpenSSL, and PKCS#11 HSMs
     * for secp256k1 keys. A KMS that emits a different prefix is either on
     * the wrong curve (P-256, P-384, …) or wraps the key in a non-standard
     * envelope — both are configuration bugs the wallet must catch up-front,
     * not surface as a signature-recovery mismatch six lines later.
     */
    private const SECP256K1_ALGORITHM_IDENTIFIER = "\x30\x10"
        . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        . "\x06\x05\x2b\x81\x04\x00\x0a";

    /**
     * Decode `SEQUENCE { r INTEGER, s INTEGER }` into the two 32-byte hex
     * components used by EVM signatures.
     *
     * @return array{r: string, s: string}  Lowercase 64-char hex (no `0x` prefix).
     */
    public static function decodeSignature(string $der): array
    {
        $pos = 0;
        $len = \strlen($der);

        self::expectTag($der, $pos, 0x30, 'SEQUENCE');
        [$seqLen, $pos] = self::readLength($der, $pos);

        if ($pos + $seqLen !== $len) {
            throw new InvalidArgumentException('DER signature has trailing bytes after the outer SEQUENCE.');
        }

        self::expectTag($der, $pos, 0x02, 'INTEGER (r)');
        [$rLen, $pos] = self::readLength($der, $pos);
        $r = self::readBytes($der, $pos, $rLen);
        $pos += $rLen;

        self::expectTag($der, $pos, 0x02, 'INTEGER (s)');
        [$sLen, $pos] = self::readLength($der, $pos);
        $s = self::readBytes($der, $pos, $sLen);
        $pos += $sLen;

        if ($pos !== $len) {
            throw new InvalidArgumentException('DER signature has trailing bytes after the s INTEGER.');
        }

        return [
            'r' => self::normalizeInteger32($r),
            's' => self::normalizeInteger32($s),
        ];
    }

    /**
     * Extract the 65-byte uncompressed secp256k1 point (`0x04 || X || Y`)
     * from a `SubjectPublicKeyInfo` DER blob. Returned as binary bytes —
     * callers Keccak-256 it directly to derive an EVM address.
     */
    public static function decodeSpkiUncompressedPoint(string $spki): string
    {
        $pos = 0;
        $len = \strlen($spki);

        self::expectTag($spki, $pos, 0x30, 'outer SEQUENCE');
        [$outerLen, $pos] = self::readLength($spki, $pos);

        if ($pos + $outerLen !== $len) {
            throw new InvalidArgumentException('SPKI has trailing bytes after the outer SEQUENCE.');
        }

        // AlgorithmIdentifier — must be byte-for-byte the canonical secp256k1
        // prefix. Accepting any AlgorithmIdentifier (P-256, P-384, an attacker-
        // chosen OID, …) means the address we derive from the BIT STRING isn't
        // tied to a key the KMS will sign for, and the mismatch surfaces only
        // after we've already paid for a sign call.
        $algIdentifier = self::SECP256K1_ALGORITHM_IDENTIFIER;
        $algLength = \strlen($algIdentifier);

        if ($pos + $algLength > \strlen($spki) || substr($spki, $pos, $algLength) !== $algIdentifier) {
            throw new InvalidArgumentException('SPKI AlgorithmIdentifier is not the canonical secp256k1 / id-ecPublicKey prefix.');
        }

        $pos += $algLength;

        self::expectTag($spki, $pos, 0x03, 'BIT STRING');
        [$bsLen, $pos] = self::readLength($spki, $pos);

        if ($bsLen < 2) {
            throw new InvalidArgumentException('SPKI BIT STRING is too short to carry an EC point.');
        }

        $unusedBits = \ord($spki[$pos]);
        ++$pos;

        if ($unusedBits !== 0) {
            throw new InvalidArgumentException('SPKI BIT STRING declares non-zero unused bits.');
        }

        $point = self::readBytes($spki, $pos, $bsLen - 1);

        if (\strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new InvalidArgumentException('SPKI does not carry a 65-byte uncompressed secp256k1 point.');
        }

        return $point;
    }

    private static function expectTag(string $der, int $pos, int $expected, string $label): void
    {
        if ($pos >= \strlen($der)) {
            throw new InvalidArgumentException(sprintf('Unexpected end of DER input while reading %s tag.', $label));
        }

        $tag = \ord($der[$pos]);

        if ($tag !== $expected) {
            throw new InvalidArgumentException(sprintf('Expected %s tag 0x%02x, got 0x%02x.', $label, $expected, $tag));
        }
    }

    /**
     * Read an ASN.1 DER length prefix at $pos. Supports short form (0-127)
     * and long form for up to four length bytes — KMS payloads never need
     * more.
     *
     * @return array{0: int, 1: int}  [length, advancedPos]
     */
    private static function readLength(string $der, int $pos): array
    {
        ++$pos; // skip the tag byte already consumed by expectTag

        if ($pos >= \strlen($der)) {
            throw new InvalidArgumentException('Unexpected end of DER input while reading length.');
        }

        $first = \ord($der[$pos]);
        ++$pos;

        if ($first < 0x80) {
            return [$first, $pos];
        }

        $numBytes = $first & 0x7F;

        if ($numBytes === 0 || $numBytes > 4) {
            throw new InvalidArgumentException(sprintf('Unsupported DER length form (0x%02x).', $first));
        }

        if ($pos + $numBytes > \strlen($der)) {
            throw new InvalidArgumentException('Unexpected end of DER input while reading long-form length.');
        }

        $length = 0;

        for ($i = 0; $i < $numBytes; ++$i) {
            $length = ($length << 8) | \ord($der[$pos]);
            ++$pos;
        }

        return [$length, $pos];
    }

    private static function readBytes(string $der, int $pos, int $length): string
    {
        if ($length < 0 || $pos + $length > \strlen($der)) {
            throw new InvalidArgumentException('DER length exceeds remaining input.');
        }

        return substr($der, $pos, $length);
    }

    /**
     * Decode a positive 256-bit DER INTEGER into a 32-byte big-endian hex
     * string, enforcing canonical encoding per X.690:
     *
     *   - Non-empty.
     *   - No redundant leading `0x00` bytes — a positivity pad is allowed
     *     only when the following byte has the high bit set; otherwise the
     *     encoding is over-padded and a strict ASN.1 decoder must reject it.
     *   - A 32-byte INTEGER with the high bit set is an ASN.1 *negative*
     *     value and is rejected — secp256k1 r/s are always positive.
     *   - Length capped at 33 bytes (32 + the one allowed positivity pad).
     */
    private static function normalizeInteger32(string $bytes): string
    {
        $len = \strlen($bytes);

        if ($len === 0) {
            throw new InvalidArgumentException('DER INTEGER is empty.');
        }

        if ($len > 1 && $bytes[0] === "\x00" && (\ord($bytes[1]) & 0x80) === 0) {
            throw new InvalidArgumentException('DER INTEGER has a non-canonical leading 0x00 byte (over-padded).');
        }

        if ($len === 32 && (\ord($bytes[0]) & 0x80) !== 0) {
            throw new InvalidArgumentException('DER INTEGER is negative (high bit set without positivity pad).');
        }

        if ($len === 33 && $bytes[0] === "\x00") {
            $bytes = substr($bytes, 1);
            $len = 32;
        }

        if ($len > 32) {
            throw new InvalidArgumentException(sprintf('DER INTEGER exceeds 32 bytes (got %d).', $len));
        }

        return bin2hex(str_pad($bytes, 32, "\x00", \STR_PAD_LEFT));
    }
}
