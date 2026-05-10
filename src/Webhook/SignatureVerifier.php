<?php

declare(strict_types=1);

namespace X402\Webhook;

/**
 * Stripe-style HMAC-SHA256 webhook signature verifier.
 *
 * Header format: `t=<unix>,v1=<hex>`
 *
 * Signed payload: `"$t.$rawBody"` — the timestamp prefix prevents an
 * attacker from replaying an old signature against a fresh body, and
 * binds the signature to the moment of issuance so the clock-skew
 * window can reject stale deliveries.
 *
 * Comparison uses `hash_equals()` to avoid timing oracles on the
 * computed-vs-supplied hex digest.
 */
final readonly class SignatureVerifier
{
    public function __construct(
        private string $secret,
        /**
         * Maximum allowed difference between the local clock and the
         * `t=` timestamp on the inbound delivery, in seconds. Default
         * ±5 minutes matches Stripe's documented tolerance and absorbs
         * NTP drift / facilitator-side queue latency without admitting
         * stale-signature replays.
         */
        private int $maxClockSkewSeconds = 300,
    ) {}

    /**
     * @throws InvalidWebhookSignatureException
     */
    public function verify(string $rawBody, string $signatureHeader): void
    {
        [$timestamp, $supplied] = $this->parse($signatureHeader);

        $now = time();
        if (abs($now - $timestamp) > $this->maxClockSkewSeconds) {
            throw new InvalidWebhookSignatureException(
                'Webhook signature timestamp outside the allowed clock-skew window.',
            );
        }

        $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);

        if (! hash_equals($computed, $supplied)) {
            throw new InvalidWebhookSignatureException('Webhook signature mismatch.');
        }
    }

    /**
     * @return array{0: int, 1: string}
     *
     * @throws InvalidWebhookSignatureException
     */
    private function parse(string $header): array
    {
        if ($header === '') {
            throw new InvalidWebhookSignatureException('Webhook signature header is empty.');
        }

        $timestamp = null;
        $hex = null;

        foreach (explode(',', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }

            $key = substr($part, 0, $eq);
            $value = substr($part, $eq + 1);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $hex = $value;
            }
        }

        if ($timestamp === null || $hex === null || $timestamp === '' || $hex === '') {
            throw new InvalidWebhookSignatureException(
                'Webhook signature header malformed; expected `t=<unix>,v1=<hex>`.',
            );
        }

        // Strict integer parse — `(int) "12abc"` would silently coerce
        // to 12 and let an attacker shift the validation window with
        // crafted suffix bytes. Reject anything that is not pure digits.
        if (! ctype_digit($timestamp)) {
            throw new InvalidWebhookSignatureException(
                'Webhook signature timestamp is not an integer.',
            );
        }

        return [(int) $timestamp, $hex];
    }
}
