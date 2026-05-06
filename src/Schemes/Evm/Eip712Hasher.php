<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use InvalidArgumentException;
use kornrunner\Keccak;
use RuntimeException;

/**
 * EIP-712 typed-data hashing for EIP-3009 transferWithAuthorization.
 *
 * Reference vectors MUST match the TypeScript x402 SDK byte-for-byte. See
 * tests/Fixtures/eip712-vectors.json — sourced from the official TS SDK.
 */
final class Eip712Hasher
{
    /**
     * Build the EIP-712 typed-data digest for an EIP-3009 transferWithAuthorization.
     *
     * @param  array{name: string, version: string, chainId: int, verifyingContract: string}  $domain
     * @param  array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}  $message
     */
    public function digest(array $domain, array $message): string
    {
        $domainSeparator = $this->hashDomain($domain);
        $messageHash = $this->hashTransferWithAuthorization($message);

        return '0x' . bin2hex(Keccak::hash(
            "\x19\x01" . hex2bin(substr($domainSeparator, 2)) . hex2bin(substr($messageHash, 2)),
            256,
            true,
        ));
    }

    /**
     * @param  array{name: string, version: string, chainId: int, verifyingContract: string}  $domain
     */
    private function hashDomain(array $domain): string
    {
        $typeHash = '0x' . Keccak::hash(
            'EIP712Domain(string name,string version,uint256 chainId,address verifyingContract)',
            256,
        );

        $encoded = hex2bin(substr($typeHash, 2))
            . hex2bin(substr('0x' . Keccak::hash($domain['name'], 256), 2))
            . hex2bin(substr('0x' . Keccak::hash($domain['version'], 256), 2))
            . $this->encodeUint256($domain['chainId'])
            . $this->encodeAddress($domain['verifyingContract']);

        return '0x' . Keccak::hash($encoded, 256);
    }

    /**
     * @param  array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}  $message
     */
    private function hashTransferWithAuthorization(array $message): string
    {
        $typeHash = '0x' . Keccak::hash(
            'TransferWithAuthorization(address from,address to,uint256 value,uint256 validAfter,uint256 validBefore,bytes32 nonce)',
            256,
        );

        $encoded = hex2bin(substr($typeHash, 2))
            . $this->encodeAddress($message['from'])
            . $this->encodeAddress($message['to'])
            . $this->encodeUint256String($message['value'])
            . $this->encodeUint256($message['validAfter'])
            . $this->encodeUint256($message['validBefore'])
            . $this->encodeBytes32($message['nonce']);

        return '0x' . Keccak::hash($encoded, 256);
    }

    private function encodeAddress(string $address): string
    {
        $hex = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);

        if (\strlen($hex) !== 40) {
            throw new InvalidArgumentException(sprintf('Invalid EVM address: "%s".', $address));
        }

        return str_repeat("\x00", 12) . hex2bin($hex);
    }

    private function encodeUint256(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('uint256 values must be non-negative.');
        }

        $hex = dechex($value);
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);

        return $this->hex2binStrict($hex);
    }

    /**
     * Encode a stringified uint256 (may exceed PHP_INT_MAX) as 32-byte big-endian.
     */
    private function encodeUint256String(string $value): string
    {
        if (! \extension_loaded('gmp')) {
            throw new RuntimeException('ext-gmp is required for uint256 string encoding.');
        }

        $trimmed = ltrim($value, '0');
        if ($trimmed === '') {
            $trimmed = '0';
        }

        $hex = gmp_strval(gmp_init($trimmed, 10), 16);
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);

        return $this->hex2binStrict($hex);
    }

    private function encodeBytes32(string $value): string
    {
        $hex = str_starts_with($value, '0x') ? substr($value, 2) : $value;

        if (\strlen($hex) !== 64) {
            throw new InvalidArgumentException(sprintf('bytes32 must be 32 bytes hex-encoded, got "%s".', $value));
        }

        return $this->hex2binStrict($hex);
    }

    private function hex2binStrict(string $hex): string
    {
        $bin = hex2bin($hex);

        if ($bin === false) {
            throw new InvalidArgumentException(sprintf('Invalid hex input: "%s".', $hex));
        }

        return $bin;
    }
}
