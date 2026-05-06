<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use InvalidArgumentException;

/**
 * ERC-6492 wrapped-signature parser.
 *
 * The wrapper layout is:
 *
 *   abi.encode((address factory, bytes factoryCalldata, bytes signature)) || magicBytes
 *
 * The 32-byte magic suffix `0x6492...6492` (16 repetitions of `0x6492`)
 * is the discriminator. Consumers of an ERC-6492 signature must detect
 * the suffix, ABI-decode the prefix, then deploy-and-verify the inner
 * signature against the deployed account via ERC-1271 `isValidSignature`.
 *
 * This decoder handles the off-chain part (detection + ABI unpack).
 * On-chain `eth_call` for deploy-and-verify is delegated to the
 * facilitator — it has the RPC; we deliberately don't ship one to keep
 * php-x402 PSR-only.
 *
 * Reference: `go/mechanisms/evm/erc6492.go`.
 */
final class Erc6492Decoder
{
    /**
     * 32-byte magic suffix (lowercase hex, no 0x prefix). 16 repetitions
     * of `0x6492`.
     */
    public const MAGIC_HEX = '6492649264926492649264926492649264926492649264926492649264926492';

    /**
     * Detect the trailing magic suffix on a hex-encoded signature.
     * Returns true for any signature whose last 32 bytes equal the magic.
     */
    public static function isWrapped(string $hexSignature): bool
    {
        $hex = strtolower(str_starts_with($hexSignature, '0x') ? substr($hexSignature, 2) : $hexSignature);

        // 32 bytes = 64 hex chars. Anything shorter can't carry the magic.
        if (\strlen($hex) < 64) {
            return false;
        }

        return str_ends_with($hex, self::MAGIC_HEX);
    }

    /**
     * Decode a wrapped signature into `(factory, factoryCalldata, innerSignature)`.
     * All return values are 0x-prefixed hex strings.
     *
     * @return array{factory: string, factoryCalldata: string, innerSignature: string}
     *
     * @throws InvalidArgumentException When the signature doesn't carry the magic suffix or the ABI body is malformed.
     */
    public static function decode(string $hexSignature): array
    {
        if (! self::isWrapped($hexSignature)) {
            throw new InvalidArgumentException('Signature does not carry the ERC-6492 magic suffix.');
        }

        $hex = strtolower(str_starts_with($hexSignature, '0x') ? substr($hexSignature, 2) : $hexSignature);
        // Strip the 64-hex-char (32 byte) magic suffix.
        $payload = substr($hex, 0, -64);

        // ABI encoding of `(address, bytes, bytes)` is:
        //   word[0]: address (20 bytes right-padded to 32)
        //   word[1]: offset to bytes#1 (relative to start of tuple — 0x60 typical)
        //   word[2]: offset to bytes#2
        //   word[N]: bytes#1 length, then content padded to 32-byte boundary
        //   word[M]: bytes#2 length, then content padded to 32-byte boundary
        //
        // We parse this minimally — sufficient for canonical wrapped sigs
        // produced by the standard ERC-6492 SDKs.

        if (\strlen($payload) < 192) {
            throw new InvalidArgumentException('ERC-6492 wrapper too short to contain the 3-tuple head.');
        }

        // Address: last 40 hex chars of word[0].
        $factory = '0x' . substr($payload, 24, 40);

        $offset1 = self::readUint256At($payload, 1);
        $offset2 = self::readUint256At($payload, 2);

        $factoryCalldata = self::readDynamicBytes($payload, $offset1);
        $innerSignature = self::readDynamicBytes($payload, $offset2);

        return [
            'factory' => $factory,
            'factoryCalldata' => '0x' . $factoryCalldata,
            'innerSignature' => '0x' . $innerSignature,
        ];
    }

    /**
     * Read a 32-byte word at the given word index (0-based) and return
     * its decimal-string uint256 value. We only call this on offset
     * fields which fit comfortably in a 64-bit int, so we cast.
     */
    private static function readUint256At(string $hexBody, int $wordIndex): int
    {
        $start = $wordIndex * 64;
        $word = substr($hexBody, $start, 64);

        return (int) hexdec($word);
    }

    /**
     * Read a dynamic `bytes` field at `$byteOffset` (offset is in bytes
     * from the start of the ABI tuple). The first 32-byte word at that
     * offset is the byte length; the next ceil(length/32)*32 bytes are
     * the content (right-padded with zeros).
     */
    private static function readDynamicBytes(string $hexBody, int $byteOffset): string
    {
        $hexOffset = $byteOffset * 2;
        $lengthHex = substr($hexBody, $hexOffset, 64);
        $length = (int) hexdec($lengthHex);

        return substr($hexBody, $hexOffset + 64, $length * 2);
    }
}
