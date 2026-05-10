<?php

declare(strict_types=1);

namespace X402\Webhook;

/**
 * Inbound settlement-webhook DTO. Adapter controllers build one of
 * these via a host-registered mapper closure that knows the
 * facilitator's payload shape — there is no upstream factory because
 * facilitators differ.
 *
 * **Dedup key.** `nonce` (EIP-3009 or scheme-equivalent) is the
 * dedup-store claim key. EIP-3009 nonces are globally unique per
 * authorization; reusing them as the webhook idempotency key avoids
 * a second facilitator-specific eventId field and aligns with the
 * existing `(network, from, nonce)` replay-protection contract.
 *
 * **Why no `payee` field.** The host knows the recipient from
 * `PaymentRequired::$payTo` at sync time and persists it on the
 * pending row. Echoing it back over the webhook saves wire bytes
 * and a column adapters never read.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload  Decoded JSON body verbatim — adopters
     *                                            consume facilitator-specific extras here.
     */
    public function __construct(
        public WebhookStatus $status,
        public string $nonce,
        public string $payer,
        public string $transaction,
        public string $network,
        public ?string $tracker = null,
        public ?string $resource = null,
        public ?string $amount = null,
        public ?string $reason = null,
        public array $rawPayload = [],
    ) {}
}
