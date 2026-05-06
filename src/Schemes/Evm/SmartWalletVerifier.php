<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

/**
 * Smart-wallet (ERC-1271 + ERC-6492) signature support reference.
 *
 * **Verification is delegated to the facilitator**, not performed in this
 * package. Reasons:
 *
 * 1. **ERC-6492** signatures wrap the actual signature in a deploy-and-verify
 *    blob: `concat(initCode, validatorData, magic)`. To verify, the
 *    facilitator must (a) detect the magic suffix, (b) `eth_call` the
 *    factory's `createAndCall` with the wrapped signature, (c) check the
 *    returned magic value matches `EIP1271_MAGIC_VALUE` (0x1626ba7e). All
 *    of which need an Ethereum RPC client we deliberately don't ship in
 *    php-x402 to keep the runtime dep set minimal (PSR-only).
 *
 * 2. **ERC-1271** signatures from already-deployed smart wallets need an
 *    `eth_call` to `wallet.isValidSignature(hash, signature)` returning
 *    the magic value. Same RPC-needed argument.
 *
 * 3. **Signing** smart-wallet payments happens in the wallet's own UI/SDK
 *    (Coinbase Smart Wallet, Argent, Safe, etc.), not in our buyer client.
 *    The signature blob arrives at our `PaymentSignature` already wrapped.
 *
 * What php-x402 DOES do:
 *
 * - Pass the wrapped signature through to the facilitator unchanged in
 *   `payload.signature` — the facilitator does the verification dance.
 * - Recognise `0x1626ba7e` as the magic value via the constant in
 *   `Constants::EIP1271_MAGIC_VALUE`.
 *
 * What php-x402 does NOT do:
 *
 * - On-chain `eth_call`. Out of scope for the PSR-only core.
 * - ERC-6492 signature unwrapping. Defer to facilitator.
 * - Smart-wallet payment generation client-side. Use the wallet's SDK.
 *
 * Reference impls: `go/mechanisms/evm/erc6492.go`, the Coinbase facilitator
 * implements both layers.
 */
final class SmartWalletVerifier
{
    /**
     * Detect ERC-6492 magic suffix `0x6492649264926492649264926492649264926492649264926492649264926492`
     * appended to a signature. Hosts that want to surface "this is a smart
     * wallet sig, expect facilitator to handle deploy" can branch on this.
     */
    public static function isErc6492Wrapped(string $signature): bool
    {
        $hex = str_starts_with($signature, '0x') ? substr($signature, 2) : $signature;

        return str_ends_with(strtolower($hex), '6492649264926492649264926492649264926492649264926492649264926492');
    }
}
