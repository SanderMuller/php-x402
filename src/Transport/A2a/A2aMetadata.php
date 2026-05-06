<?php

declare(strict_types=1);

namespace X402\Transport\A2a;

/**
 * Wire-format constants and helpers for the A2A (agent-to-agent) transport.
 *
 * Spec: `specs/transports-v2/a2a.md`.
 *
 * A2A piggy-backs on the A2A protocol's task-lifecycle JSON-RPC messages.
 * Payment travels as **message metadata** (NOT HTTP headers, NOT JSON-RPC
 * params). Each phase rides as a top-level key inside:
 *   - client → server: `params.message.metadata`
 *   - server → client: `result.status.message.metadata`
 *
 * Activation:
 *   - AgentCard declares `capabilities.extensions[].uri = self::EXTENSION_URI`
 *   - Client sends `X-A2A-Extensions: <uri>` HTTP header to activate (the
 *     only HTTP header involved)
 */
final class A2aMetadata
{
    public const EXTENSION_URI = 'https://github.com/google-a2a/a2a-x402/v0.1';

    public const ACTIVATION_HEADER = 'X-A2A-Extensions';

    /** Carries lifecycle status string (see A2aPaymentStatus). */
    public const KEY_STATUS = 'x402.payment.status';

    /** Carries the PaymentRequired schema (server → client). */
    public const KEY_REQUIRED = 'x402.payment.required';

    /** Carries the PaymentPayload schema (client → server). */
    public const KEY_PAYLOAD = 'x402.payment.payload';

    /** Carries an array of SettlementResponse on completion/failure. */
    public const KEY_RECEIPTS = 'x402.payment.receipts';

    /** Carries an error code string on failed task. */
    public const KEY_ERROR = 'x402.payment.error';

    /**
     * Read a top-level x402 key from an A2A message metadata bag.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function read(array $metadata, string $key): mixed
    {
        return $metadata[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    public static function readArray(array $metadata, string $key): ?array
    {
        $value = $metadata[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public static function readString(mixed $metadata, string $key): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        $value = $metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
