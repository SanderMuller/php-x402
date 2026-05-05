<?php

declare(strict_types=1);

namespace X402\Schemes;

use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Pluggable payment scheme. Each scheme owns its own signing + verification rules.
 *
 * Built-in: "exact" on EVM (EIP-3009 transferWithAuthorization). Solana scheme is
 * a v0.4 follow-up — see internal/roadmap.md.
 */
interface SchemeContract
{
    /**
     * Identifier used in the `scheme` field of PaymentRequired / PaymentSignature.
     */
    public function name(): string;

    /**
     * CAIP-2 networks this scheme supports (e.g. ["eip155:8453"]).
     *
     * @return list<string>
     */
    public function supportedNetworks(): array;

    /**
     * Local sanity-check on a signed payload before calling the facilitator. Pure —
     * does NOT consult chain state. The facilitator is the source of truth on
     * balance/nonce-uniqueness/etc.
     *
     * @throws \X402\Exceptions\InvalidPaymentException
     */
    public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void;
}
