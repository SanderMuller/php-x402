<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use InvalidArgumentException;
use kornrunner\Keccak;
use RuntimeException;
use X402\Support\Hex;

/**
 * EIP-712 typed-data hashing for Permit2 `permitWitnessTransferFrom`.
 *
 * Spec: `specs/schemes/exact/scheme_exact_evm.md` Permit2 section.
 *
 * **Critical differences from EIP-3009 / Eip712Hasher:**
 *
 *   - Domain has THREE fields only: `name="Permit2"` (fixed),
 *     `chainId`, `verifyingContract = PERMIT2_CONTRACT`. NO `version`.
 *   - Primary type is `PermitWitnessTransferFrom` with nested
 *     `TokenPermissions` struct + `Witness(address to, uint256 validAfter)`.
 *   - All numeric fields on the wire are decimal strings (uint256 fits).
 */
final class Permit2Hasher
{
    /**
     * Build the EIP-712 typed-data digest for a Permit2 permitWitnessTransferFrom.
     *
     * @param  array{token: string, amount: string}  $permitted
     * @param  array{spender: string, nonce: string, deadline: string}  $permit
     * @param  array{to: string, validAfter: string}  $witness
     */
    public function digest(int $chainId, array $permitted, array $permit, array $witness): string
    {
        $domainSeparator = $this->hashDomain($chainId);
        $messageHash = $this->hashPermitWitnessTransferFrom($permitted, $permit, $witness);

        return '0x' . bin2hex(Keccak::hash(
            "\x19\x01" . hex2bin(substr($domainSeparator, 2)) . hex2bin(substr($messageHash, 2)),
            256,
            true,
        ));
    }

    private function hashDomain(int $chainId): string
    {
        // EIP712Domain(string name,uint256 chainId,address verifyingContract)
        // — three fields exactly. NO `version`.
        $typeHash = '0x' . Keccak::hash(
            'EIP712Domain(string name,uint256 chainId,address verifyingContract)',
            256,
        );

        $encoded = Hex::toBinary(substr($typeHash, 2))
            . Hex::toBinary(substr('0x' . Keccak::hash('Permit2', 256), 2))
            . $this->encodeUint256($chainId)
            . $this->encodeAddress(Constants::PERMIT2_CONTRACT);

        return '0x' . Keccak::hash($encoded, 256);
    }

    /**
     * @param  array{token: string, amount: string}  $permitted
     * @param  array{spender: string, nonce: string, deadline: string}  $permit
     * @param  array{to: string, validAfter: string}  $witness
     */
    private function hashPermitWitnessTransferFrom(array $permitted, array $permit, array $witness): string
    {
        // PermitWitnessTransferFrom(TokenPermissions permitted,address spender,
        //   uint256 nonce,uint256 deadline,Witness witness)
        // TokenPermissions(address token,uint256 amount)
        // Witness(address to,uint256 validAfter)
        //
        // Per EIP-712, struct types are concatenated alphabetically AFTER
        // the primary type when computing the type hash.
        $typeHash = '0x' . Keccak::hash(
            'PermitWitnessTransferFrom(TokenPermissions permitted,address spender,uint256 nonce,uint256 deadline,Witness witness)TokenPermissions(address token,uint256 amount)Witness(address to,uint256 validAfter)',
            256,
        );

        $encoded = Hex::toBinary(substr($typeHash, 2))
            . Hex::toBinary(substr($this->hashTokenPermissions($permitted), 2))
            . $this->encodeAddress($permit['spender'])
            . $this->encodeUint256String($permit['nonce'])
            . $this->encodeUint256String($permit['deadline'])
            . Hex::toBinary(substr($this->hashWitness($witness), 2));

        return '0x' . Keccak::hash($encoded, 256);
    }

    /**
     * @param  array{token: string, amount: string}  $permitted
     */
    private function hashTokenPermissions(array $permitted): string
    {
        $typeHash = '0x' . Keccak::hash('TokenPermissions(address token,uint256 amount)', 256);

        $encoded = Hex::toBinary(substr($typeHash, 2))
            . $this->encodeAddress($permitted['token'])
            . $this->encodeUint256String($permitted['amount']);

        return '0x' . Keccak::hash($encoded, 256);
    }

    /**
     * @param  array{to: string, validAfter: string}  $witness
     */
    private function hashWitness(array $witness): string
    {
        $typeHash = '0x' . Keccak::hash('Witness(address to,uint256 validAfter)', 256);

        $encoded = Hex::toBinary(substr($typeHash, 2))
            . $this->encodeAddress($witness['to'])
            . $this->encodeUint256String($witness['validAfter']);

        return '0x' . Keccak::hash($encoded, 256);
    }

    private function encodeAddress(string $address): string
    {
        $hex = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);

        if (\strlen($hex) !== 40) {
            throw new InvalidArgumentException(sprintf('Invalid EVM address: "%s".', $address));
        }

        return str_repeat("\x00", 12) . Hex::toBinary($hex);
    }

    private function encodeUint256(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('uint256 values must be non-negative.');
        }

        $hex = dechex($value);
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);

        return Hex::toBinary($hex);
    }

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

        return Hex::toBinary($hex);
    }
}
