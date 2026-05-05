<?php

declare(strict_types=1);

namespace X402\Protocol;

use X402\Exceptions\InvalidPaymentException;

/**
 * Client-supplied signed payment payload (decoded from PAYMENT-SIGNATURE header).
 */
final readonly class PaymentSignature
{
    /**
     * @param  array<string, mixed>  $payload  Scheme-specific signed payload (e.g. EIP-3009 authorization).
     */
    public function __construct(
        public string $scheme,
        public string $network,
        public array $payload,
        public string $x402Version = '1',
    ) {}

    /**
     * Decode a base64-encoded JSON header value into a PaymentSignature.
     *
     * @throws InvalidPaymentException When header is malformed or missing required fields.
     */
    public static function fromHeader(string $headerValue): self
    {
        $decoded = base64_decode($headerValue, true);

        if ($decoded === false) {
            throw new InvalidPaymentException('Payment signature header is not valid base64.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPaymentException('Payment signature header is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        foreach (['scheme', 'network', 'payload'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidPaymentException(sprintf('Payment signature missing required field "%s".', $required));
            }
        }

        return new self(
            scheme: (string) $data['scheme'],
            network: (string) $data['network'],
            payload: (array) $data['payload'],
            x402Version: isset($data['x402Version']) ? (string) $data['x402Version'] : '1',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'x402Version' => $this->x402Version,
            'scheme' => $this->scheme,
            'network' => $this->network,
            'payload' => $this->payload,
        ];
    }

    public function toHeader(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
