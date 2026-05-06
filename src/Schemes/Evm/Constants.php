<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

/**
 * Canonical contract addresses + EIP-712 constants used across the EVM
 * `exact` and `upto` scheme implementations.
 *
 * Sourced from the Coinbase Go reference (`go/mechanisms/evm/constants.go`).
 * Critical: addresses are the SAME on every EVM chain (CREATE2 deploy).
 */
final class Constants
{
    /**
     * Canonical Permit2 contract — same address on every EVM chain.
     * Reference: https://github.com/Uniswap/permit2
     */
    public const PERMIT2_CONTRACT = '0x000000000022D473030F116dDEE9F6B43aC78BA3';

    /**
     * x402 Permit2 proxy for `exact` scheme — CREATE2-deployed to the
     * same address on every EVM chain.
     */
    public const X402_EXACT_PERMIT2_PROXY = '0x402085c248EeA27D92E8b30b2C58ed07f9E20001';

    /**
     * x402 Permit2 proxy for `upto` scheme — CREATE2-deployed.
     */
    public const X402_UPTO_PERMIT2_PROXY = '0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002';

    /**
     * EIP-1271 magic value — `bytes4(keccak256("isValidSignature(bytes32,bytes)"))`.
     * Smart contract wallets return this from `isValidSignature` to indicate
     * an off-chain signature is valid.
     *
     * Reference: https://eips.ethereum.org/EIPS/eip-1271
     */
    public const EIP1271_MAGIC_VALUE = '0x1626ba7e';

    /**
     * The three transfer methods exposed via `extra.assetTransferMethod`
     * on the `exact` EVM scheme.
     */
    public const TRANSFER_METHOD_EIP3009 = 'eip3009';

    public const TRANSFER_METHOD_PERMIT2 = 'permit2';

    public const TRANSFER_METHOD_ERC7710 = 'erc7710';
}
