<?php

declare(strict_types=1);

use BN\BN;
use Elliptic\EC;
use X402\Support\Asn1DerDecoder;

/**
 * Build a canonical AWS-KMS-shaped SubjectPublicKeyInfo for the given
 * 65-byte uncompressed secp256k1 point. AWS / GCP / Azure all return SPKI
 * blobs that match this layout — fixture once, test many.
 */
/** Canonical secp256k1 AlgorithmIdentifier — id-ecPublicKey + secp256k1 OID. */
const SECP256K1_ALG = "\x30\x10"
    . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"   // 1.2.840.10045.2.1 id-ecPublicKey
    . "\x06\x05\x2b\x81\x04\x00\x0a";          // 1.3.132.0.10 secp256k1

function makeSpki(string $uncompressedPoint): string
{
    if (strlen($uncompressedPoint) !== 65 || $uncompressedPoint[0] !== "\x04") {
        throw new InvalidArgumentException('Expected 65-byte uncompressed point.');
    }

    $bitString = "\x03\x42\x00" . $uncompressedPoint;
    $body = SECP256K1_ALG . $bitString;

    return "\x30" . chr(strlen($body)) . $body;
}

function makeSpkiWithBitString(string $bitString): string
{
    $body = SECP256K1_ALG . $bitString;

    return "\x30" . chr(strlen($body)) . $body;
}

it('decodes a real DER signature into 32-byte hex r and s', function (): void {
    $ec = new EC('secp256k1');
    $key = $ec->keyFromPrivate(str_repeat('11', 32), 'hex');
    $signature = $key->sign(str_repeat('aa', 32), false, ['canonical' => true]);

    $derHex = $signature->toDER('hex');
    expect($derHex)->toBeString();
    /** @var string $derHex */
    $der = hex2bin($derHex);
    expect($der)->not->toBeFalse();
    /** @var string $der */
    $decoded = Asn1DerDecoder::decodeSignature($der);

    expect($decoded)
        ->toHaveKeys(['r', 's'])
        ->and(strlen($decoded['r']))->toBe(64)
        ->and(strlen($decoded['s']))->toBe(64)
        ->and(ctype_xdigit($decoded['r']))->toBeTrue()
        ->and(ctype_xdigit($decoded['s']))->toBeTrue();

    /** @var BN $rBn */
    $rBn = $signature->r;
    /** @var BN $sBn */
    $sBn = $signature->s;

    expect($decoded['r'])->toBe(str_pad($rBn->toString(16), 64, '0', STR_PAD_LEFT))
        ->and($decoded['s'])->toBe(str_pad($sBn->toString(16), 64, '0', STR_PAD_LEFT));
});

it('handles the leading-0x00 positivity pad on high-bit integers', function (): void {
    // r with high bit set → DER prepends 0x00 → 33-byte INTEGER.
    $r = "\x00" . str_repeat("\xff", 32);  // 33 bytes
    $s = str_repeat("\x7f", 32);            // 32 bytes, high bit clear

    $der = "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;

    $decoded = Asn1DerDecoder::decodeSignature($der);

    expect($decoded['r'])->toBe(str_repeat('ff', 32))
        ->and($decoded['s'])->toBe(str_repeat('7f', 32));
});

it('left-pads short-form integers to 32 bytes', function (): void {
    // r = 1 byte, s = 1 byte — both should pad out to 32 bytes hex.
    $der = "\x30\x06\x02\x01\x05\x02\x01\x03";

    $decoded = Asn1DerDecoder::decodeSignature($der);

    expect($decoded['r'])->toBe(str_repeat('0', 62) . '05')
        ->and($decoded['s'])->toBe(str_repeat('0', 62) . '03');
});

it('rejects DER signatures with the wrong outer tag', function (): void {
    $der = "\x31\x06\x02\x01\x05\x02\x01\x03"; // 0x31 = SET, not SEQUENCE

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'Expected SEQUENCE tag');
});

it('rejects DER signatures with the wrong inner tag for r', function (): void {
    $der = "\x30\x06\x04\x01\x05\x02\x01\x03"; // 0x04 = OCTET STRING, not INTEGER

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'Expected INTEGER (r)');
});

it('rejects DER signatures with trailing bytes', function (): void {
    $der = "\x30\x06\x02\x01\x05\x02\x01\x03\xff"; // trailing 0xff

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'trailing bytes');
});

it('rejects DER signatures whose r is a bare 32-byte integer with the high bit set (ASN.1 negative)', function (): void {
    // A canonical positive secp256k1 r with the high bit set would have a
    // leading 0x00 positivity pad. Without it, this is an ASN.1 negative —
    // strict DER must reject so it never gets interpreted as a positive
    // scalar on the wire.
    $r = str_repeat("\xff", 32);
    $s = str_repeat("\x01", 32);
    $der = "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'negative');
});

it('rejects DER signatures with redundant leading 0x00 on r (over-padded)', function (): void {
    // Two leading 0x00 bytes where one would do — non-minimal encoding.
    $r = "\x00\x00\xff" . str_repeat("\x11", 30);  // 33 bytes, double-padded
    $s = str_repeat("\x01", 32);
    $der = "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'non-canonical');
});

it('rejects DER signatures with a leading 0x00 followed by a high-bit-clear byte (gratuitous pad)', function (): void {
    // Single 0x00 pad in front of a value whose top byte's high bit is 0 —
    // the pad is non-canonical because the value didn't need positivity
    // protection.
    $r = "\x00\x12" . str_repeat("\x34", 30);  // 32 bytes after pad strip, but didn't need the pad
    $s = str_repeat("\x01", 32);
    $der = "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'non-canonical');
});

it('rejects DER signatures with integers exceeding 32 bytes after pad strip', function (): void {
    // 34 bytes — even after stripping a single positivity pad, still > 32.
    $r = str_repeat("\xff", 34);
    $s = str_repeat("\x01", 32);
    $der = "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'exceeds 32 bytes');
});

it('rejects an empty INTEGER body', function (): void {
    $der = "\x30\x05\x02\x00\x02\x01\x03";

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'empty');
});

it('rejects DER signatures with truncated length prefix', function (): void {
    // Long-form length declared but bytes missing.
    $der = "\x30\x82"; // 0x82 = "next 2 bytes are the length", but no more bytes follow

    expect(fn () => Asn1DerDecoder::decodeSignature($der))
        ->toThrow(InvalidArgumentException::class, 'long-form length');
});

it('decodes a real SPKI public-key blob into a 65-byte uncompressed point', function (): void {
    $ec = new EC('secp256k1');
    $key = $ec->keyFromPrivate(str_repeat('22', 32), 'hex');
    $pubHex = $key->getPublic(false, 'hex');
    $pubBin = hex2bin($pubHex);
    expect($pubBin)->not->toBeFalse();
    assert(is_string($pubBin));

    $spki = makeSpki($pubBin);

    $point = Asn1DerDecoder::decodeSpkiUncompressedPoint($spki);

    expect($point)->toBe($pubBin)
        ->and(strlen($point))->toBe(65)
        ->and($point[0])->toBe("\x04");
});

it('rejects SPKI with non-zero unused bits in the BIT STRING', function (): void {
    $point = "\x04" . str_repeat("\x01", 64);
    $spki = makeSpkiWithBitString("\x03\x42\x01" . $point); // 0x01 unused bits — must be 0

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'unused bits');
});

it('rejects SPKI whose BIT STRING contents are not a 65-byte 0x04-prefixed point', function (): void {
    // 65 bytes but first byte is 0x02 (compressed), not 0x04.
    $point = "\x02" . str_repeat("\x01", 64);
    $spki = makeSpkiWithBitString("\x03\x42\x00" . $point);

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'uncompressed');
});

it('rejects SPKI with trailing bytes after the outer SEQUENCE', function (): void {
    $point = "\x04" . str_repeat("\x01", 64);
    $spki = makeSpkiWithBitString("\x03\x42\x00" . $point) . "\xff"; // trailing

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'trailing bytes');
});

it('rejects SPKI with the wrong outer tag', function (): void {
    $spki = "\x31\x00";

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'outer SEQUENCE');
});

it('rejects SPKI whose AlgorithmIdentifier is not the canonical secp256k1 prefix', function (): void {
    // AlgorithmIdentifier declares an unrelated OID (id-rsaEncryption). Any
    // non-secp256k1 alg means the key isn't one we can sign with, and the
    // mismatch must surface up-front rather than at deriveV time.
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $point = "\x04" . str_repeat("\x01", 64);
    $bitString = "\x03\x42\x00" . $point;
    $body = $alg . $bitString;
    $spki = "\x30" . chr(strlen($body)) . $body;

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'canonical secp256k1');
});

it('rejects SPKI for a sibling NIST curve (P-256 / prime256v1)', function (): void {
    // Same id-ecPublicKey but with the prime256v1 OID instead of secp256k1.
    // Wrong-curve KMS keys are exactly the silent-failure shape the strict
    // AlgorithmIdentifier check is meant to close.
    $alg = "\x30\x13"
        . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"     // id-ecPublicKey
        . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1
    $point = "\x04" . str_repeat("\x01", 64);
    $bitString = "\x03\x42\x00" . $point;
    $body = $alg . $bitString;
    $spki = "\x30" . chr(strlen($body)) . $body;

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'canonical secp256k1');
});

it('rejects SPKI whose BIT STRING declares a single byte (unused-bits only, no point)', function (): void {
    // bsLen = 1 — only enough for the unused-bits prefix, no point follows.
    $spki = makeSpkiWithBitString("\x03\x01\x00");

    expect(fn () => Asn1DerDecoder::decodeSpkiUncompressedPoint($spki))
        ->toThrow(InvalidArgumentException::class, 'too short');
});
