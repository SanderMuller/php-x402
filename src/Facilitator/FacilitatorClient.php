<?php

declare(strict_types=1);

namespace X402\Facilitator;

use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Pluggable facilitator. The default implementation calls Coinbase's hosted
 * facilitator at https://x402.org/facilitator (or the CDP-managed variant
 * for authenticated/higher-volume use).
 */
interface FacilitatorClient
{
    /**
     * Verify the signed payload's correctness off-chain (signature, balance,
     * nonce uniqueness, simulation). Does NOT settle.
     *
     * @throws \X402\Exceptions\FacilitatorException On transport failure.
     */
    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult;

    /**
     * Broadcast settlement on-chain. Idempotent on (nonce, from) per the
     * EIP-3009 spec — duplicate submissions return the original transaction.
     *
     * @throws \X402\Exceptions\FacilitatorException On transport failure.
     */
    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult;
}
