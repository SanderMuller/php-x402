<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * Client-side helper for producing **ERC-6492 wrapped** signatures.
 *
 * Counterfactual smart wallets (Coinbase Smart Wallet, Safe, etc.) may
 * not be deployed at sign time — the wallet address is derived via
 * CREATE2 from `(factory, factoryCalldata)`. ERC-6492 wraps an inner
 * EIP-1271 signature with the deploy bundle so the facilitator can
 * `eth_call` the factory + verifier in one shot.
 *
 * Wire format (all hex, no `0x` prefix when concatenated):
 *
 *   concat(
 *     abi.encode(address factory, bytes factoryCalldata, bytes signature),
 *     0x6492649264926492649264926492649264926492649264926492649264926492
 *   )
 *
 * **Scope of this class:**
 *   - Builds the ABI-encoded `(address, bytes, bytes)` tuple
 *   - Appends the magic suffix
 *
 * **Out of scope (host responsibilities):**
 *   - Producing the inner EIP-1271 signature — the wallet's SDK does this
 *   - On-chain verification — facilitator's `/verify` does this
 *
 * Counterpart to `Erc6492Decoder` (read side).
 */
final readonly class SmartWalletSigner
{
    /**
     * Wrap an inner EIP-1271 signature with the deploy bundle + magic suffix.
     *
     * @param  string  $factory  0x-prefixed factory address (20 bytes).
     * @param  string  $factoryCalldata  0x-prefixed bytes payload (the
     *                                   factory's `createAccount(...)` call).
     * @param  string  $innerSignature  0x-prefixed bytes — what the wallet's
     *                                   off-chain signer produced for the EIP-712
     *                                   digest from `Eip712Hasher` / `Permit2Hasher`.
     * @return string 0x-prefixed wrapped signature ready for `payload.signature`.
     */
    public function wrap(string $factory, string $factoryCalldata, string $innerSignature): string
    {
        $factoryHex = $this->stripPrefix($factory);
        $calldataHex = $this->stripPrefix($factoryCalldata);
        $sigHex = $this->stripPrefix($innerSignature);

        if (\strlen($factoryHex) !== 40) {
            throw new InvalidArgumentException(sprintf('factory must be a 20-byte address, got %d hex chars.', \strlen($factoryHex)));
        }

        $tuple = $this->encodeTuple($factoryHex, $calldataHex, $sigHex);

        return '0x' . $tuple . Erc6492Decoder::MAGIC_HEX;
    }

    public function isWrapped(string $signature): bool
    {
        return Erc6492Decoder::isWrapped($signature);
    }

    /**
     * Compute a counterfactual smart-wallet address via CREATE2.
     *
     * Per EIP-1014: `keccak256(0xff ++ deployer ++ salt ++ keccak256(initCode))[12..]`.
     *
     * Provided as a convenience for hosts that need to display the wallet
     * address before deploy — verification still goes through the
     * facilitator, which performs the same derivation in its `eth_call`.
     */
    public function counterfactualAddress(string $deployer, string $salt, string $initCode): string
    {
        $deployerHex = $this->stripPrefix($deployer);
        $saltHex = $this->stripPrefix($salt);
        $initCodeHex = $this->stripPrefix($initCode);

        if (\strlen($deployerHex) !== 40) {
            throw new InvalidArgumentException('deployer must be a 20-byte address.');
        }

        if (\strlen($saltHex) !== 64) {
            throw new InvalidArgumentException('salt must be a 32-byte value.');
        }

        $initCodeHash = Keccak::hash($this->hex2binStrict($initCodeHex), 256);
        $packed = $this->hex2binStrict('ff' . $deployerHex . $saltHex . $initCodeHash);

        return '0x' . substr(Keccak::hash($packed, 256), 24);
    }

    /**
     * Solidity ABI encoding of `(address factory, bytes factoryCalldata, bytes signature)`.
     *
     * Layout:
     *   - head[0]: factory (32 bytes, left-padded)
     *   - head[1]: offset to factoryCalldata (uint256, decimal)
     *   - head[2]: offset to signature       (uint256, decimal)
     *   - tail:    length-prefixed dynamic-bytes blocks for each
     */
    private function encodeTuple(string $factoryHex, string $calldataHex, string $sigHex): string
    {
        $headSize = 3 * 32;

        $calldataBlock = $this->encodeBytes($calldataHex);
        $sigOffset = $headSize + \intdiv(\strlen($calldataBlock), 2);

        $head = str_pad($factoryHex, 64, '0', STR_PAD_LEFT)
            . $this->encodeUint256Hex($headSize)
            . $this->encodeUint256Hex($sigOffset);

        return $head . $calldataBlock . $this->encodeBytes($sigHex);
    }

    private function encodeBytes(string $hex): string
    {
        $byteLen = \intdiv(\strlen($hex), 2);
        $padded = str_pad($hex, (int) (ceil(\strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);

        return $this->encodeUint256Hex($byteLen) . $padded;
    }

    private function encodeUint256Hex(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('uint256 must be non-negative.');
        }

        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    private function stripPrefix(string $hex): string
    {
        return strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex);
    }

    private function hex2binStrict(string $hex): string
    {
        $bin = hex2bin($hex);

        if ($bin === false) {
            throw new InvalidArgumentException(sprintf('Invalid hex: "%s".', $hex));
        }

        return $bin;
    }
}
