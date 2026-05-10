<?php

declare(strict_types=1);

namespace X402\Protocol;

use JsonException;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Support\JsonReader;

/**
 * Client-supplied signed payment payload.
 *
 * v1 wire (`X-PAYMENT` header, base64-JSON):
 *   { x402Version: 1, scheme, network, payload }
 *
 * v2 wire (`PAYMENT-SIGNATURE` header, base64-JSON):
 *   { x402Version: 2, scheme, network, payload, accepted: PaymentRequirements }
 *
 * v2 added the `accepted` field — clients echo back the chosen
 * requirement so servers can disambiguate when multiple `accepts[]`
 * entries were offered (spec v2 §5.2).
 */
final readonly class PaymentSignature
{
    /**
     * @param  array<string, mixed>  $payload  Scheme-specific signed payload (e.g. EIP-3009 authorization).
     * @param  PaymentRequired|null  $accepted  v2 echo of the chosen PaymentRequirements; null on v1.
     * @param  array<string, mixed>|null  $extensions  v2 extension payloads echoed back from the challenge. Spec v2 §5.2: client may append, never delete.
     */
    public function __construct(
        public string $scheme,
        public string $network,
        public array $payload,
        public int $x402Version = 1,
        public ?PaymentRequired $accepted = null,
        public ?array $extensions = null,
    ) {}

    /**
     * Extract the `(from, nonce, validBefore)` triple from a signed
     * `exact`-scheme `authorization` payload. Returns null if any
     * required field is missing or empty.
     *
     * Adopter-facing helper for callers that need the EIP-3009 triple
     * outside the `PaymentEnforcer` / `PaymentResponseCache` flow
     * (e.g. logging, audit hooks, debugging). The two middlewares now
     * use scheme-specific `ReplayKeyExtractor::replayKey()` instead,
     * which differs from this method in that it coerces numeric JSON
     * `nonce` values via `JsonReader::string()`. This helper sticks
     * with `stringOrNull()` semantics — null on any missing or
     * non-string-typed field — so adopters get the strict-typed view.
     *
     * @return array{from: string, nonce: string, validBefore: int}|null
     */
    public function authorization(): ?array
    {
        $auth = JsonReader::arrayOrEmpty($this->payload, 'authorization');
        $from = JsonReader::stringOrNull($auth, 'from');
        $nonce = JsonReader::stringOrNull($auth, 'nonce');

        if ($from === null || $from === '' || $nonce === null || $nonce === '') {
            return null;
        }

        return [
            'from' => $from,
            'nonce' => $nonce,
            'validBefore' => JsonReader::int($auth, 'validBefore', default: 0),
        ];
    }

    /**
     * Decode a base64-encoded JSON header value into a PaymentSignature.
     *
     * Accepts both v1 and v2 envelope shapes — the client's declared
     * `x402Version` discriminates.
     *
     * @throws InvalidPaymentException When header is malformed or missing required fields.
     */
    public static function fromHeader(string $headerValue): self
    {
        $decoded = base64_decode($headerValue, true);

        if ($decoded === false) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                'Payment signature header is not valid base64.',
            );
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidPaymentException(
                'Payment signature header is not valid JSON: ' . $jsonException->getMessage(),
                ErrorReason::InvalidPayload,
                $jsonException,
            );
        }

        return self::fromArray($data);
    }

    /**
     * Hydrate a PaymentSignature from an already-decoded envelope.
     *
     * Symmetric to `fromHeader()` but skips the base64 + JSON decode
     * step. Designed for transports that decode the envelope earlier
     * in the pipeline:
     *
     * - JSON-RPC / MCP consumers (`params._meta["x402/payment"]` is
     *   already a PHP array by the time the method handler sees it).
     * - A2A consumers (`metadata` decoded by the agent transport).
     * - Custom test harnesses that build the envelope as an array.
     *
     * @param  array<string, mixed>  $data  v1 or v2 envelope; `x402Version` discriminates.
     *
     * @throws InvalidPaymentException When required fields are missing or wrong-typed.
     */
    public static function fromArray(array $data): self
    {
        $version = self::resolveVersion($data);

        $accepted = null;
        if (isset($data['accepted']) && is_array($data['accepted'])) {
            /** @var array<string, mixed> $acceptedRaw */
            $acceptedRaw = $data['accepted'];
            $accepted = self::hydrateAccepted($acceptedRaw);
        }

        $extensions = null;
        if (isset($data['extensions']) && is_array($data['extensions'])) {
            /** @var array<string, mixed> $extensions */
            $extensions = $data['extensions'];
        }

        return new self(
            scheme: JsonReader::string($data, 'scheme', 'Payment signature'),
            network: JsonReader::string($data, 'network', 'Payment signature'),
            payload: JsonReader::array($data, 'payload', 'Payment signature'),
            x402Version: $version,
            accepted: $accepted,
            extensions: $extensions,
        );
    }

    /**
     * Emit the v1 wire shape (no `accepted` echo).
     *
     * @return array<string, mixed>
     */
    public function toArrayV1(): array
    {
        return [
            'x402Version' => 1,
            'scheme' => $this->scheme,
            'network' => $this->network,
            'payload' => $this->payload,
        ];
    }

    /**
     * Emit the v2 wire shape — includes the `accepted` echo when set.
     *
     * @return array<string, mixed>
     */
    public function toArrayV2(): array
    {
        $out = [
            'x402Version' => 2,
            'scheme' => $this->scheme,
            'network' => $this->network,
            'payload' => $this->payload,
        ];

        if ($this->accepted instanceof PaymentRequired) {
            $out['accepted'] = $this->accepted->toArrayV2();
        }

        if ($this->extensions !== null && $this->extensions !== []) {
            $out['extensions'] = $this->extensions;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->x402Version === 2 ? $this->toArrayV2() : $this->toArrayV1();
    }

    public function toHeader(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveVersion(array $data): int
    {
        if (! array_key_exists('x402Version', $data)) {
            return 1;
        }

        $raw = $data['x402Version'];

        if (is_int($raw)) {
            return $raw;
        }

        // Tolerate string-typed version from clients still on the old
        // shape; spec mandates int but reference TS shipped strings for
        // a while in pre-spec releases.
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $accepted
     */
    private static function hydrateAccepted(array $accepted): PaymentRequired
    {
        // v2 uses `amount`, v1 uses `maxAmountRequired`. Read either —
        // the client may have echoed in either shape.
        $amount = JsonReader::stringOrNull($accepted, 'amount')
            ?? JsonReader::string($accepted, 'maxAmountRequired', 'PaymentSignature.accepted');

        return new PaymentRequired(
            scheme: JsonReader::string($accepted, 'scheme', 'PaymentSignature.accepted'),
            network: JsonReader::string($accepted, 'network', 'PaymentSignature.accepted'),
            amount: $amount,
            asset: JsonReader::string($accepted, 'asset', 'PaymentSignature.accepted'),
            payTo: JsonReader::string($accepted, 'payTo', 'PaymentSignature.accepted'),
            maxTimeoutSeconds: JsonReader::int($accepted, 'maxTimeoutSeconds', default: 60),
            extra: JsonReader::arrayOrEmpty($accepted, 'extra'),
        );
    }
}
