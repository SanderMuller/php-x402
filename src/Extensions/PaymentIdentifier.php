<?php

declare(strict_types=1);

namespace X402\Extensions;

use InvalidArgumentException;
use Random\RandomException;

/**
 * `payment_identifier` extension — client-side idempotency key.
 *
 * Spec: `specs/extensions/payment_identifier.md`. The extension lives in
 * `extensions["payment-identifier"]` on the wire — `info.required` flag
 * on PaymentRequired, `info.id` (16-128 char string) on PaymentPayload.
 *
 * Server / facilitator semantics:
 *   - same id + same payload → returns cached prior response
 *   - same id + different payload → HTTP 409 Conflict
 *   - new id → processed normally
 *
 * Storage strategy is implementation-defined per spec.
 */
final class PaymentIdentifier
{
    public const EXTENSION_KEY = 'payment-identifier';

    /**
     * Generate a new payment identifier — `pay_` prefix + 32 hex chars
     * (UUIDv4 without dashes), 36 chars total. Fits the spec's 16-128
     * length window with room for prefixes.
     *
     * @throws RandomException
     */
    public static function generate(): string
    {
        return 'pay_' . bin2hex(random_bytes(16));
    }

    /**
     * Validate format per spec — 16-128 chars, alphanumeric + hyphen + underscore.
     */
    public static function isValid(string $id): bool
    {
        $length = \strlen($id);
        if ($length < 16 || $length > 128) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_\-]+$/', $id) === 1;
    }

    /**
     * Build the extensions block to attach to a PaymentRequired challenge.
     *
     * @return array<string, array{info: array{required: bool}, schema: array<string, mixed>}>
     */
    public static function challengeExtension(bool $required = false): array
    {
        return [
            self::EXTENSION_KEY => [
                'info' => ['required' => $required],
                'schema' => self::schema(),
            ],
        ];
    }

    /**
     * Build the extensions block to attach to a PaymentSignature payload.
     *
     * @return array<string, array{info: array{id: string}}>
     */
    public static function paymentExtension(string $id): array
    {
        if (! self::isValid($id)) {
            throw new InvalidArgumentException(sprintf(
                'payment-identifier "%s" is invalid: must be 16-128 alphanumeric + hyphen + underscore characters.',
                $id,
            ));
        }

        return [
            self::EXTENSION_KEY => [
                'info' => ['id' => $id],
            ],
        ];
    }

    /**
     * Read `info.id` from a wire-format extensions block.
     *
     * @param  array<string, mixed>|null  $extensions
     */
    public static function extractId(?array $extensions): ?string
    {
        if ($extensions === null) {
            return null;
        }

        $entry = $extensions[self::EXTENSION_KEY] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $info = $entry['info'] ?? null;
        if (! is_array($info)) {
            return null;
        }

        $id = $info['id'] ?? null;

        return is_string($id) && self::isValid($id) ? $id : null;
    }

    /**
     * Read `info.required` from a challenge's extensions block.
     *
     * @param  array<string, mixed>|null  $extensions
     */
    public static function isRequired(?array $extensions): bool
    {
        if ($extensions === null) {
            return false;
        }

        $entry = $extensions[self::EXTENSION_KEY] ?? null;
        if (! is_array($entry)) {
            return false;
        }

        $info = $entry['info'] ?? null;
        if (! is_array($info)) {
            return false;
        }

        return ($info['required'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'required' => ['type' => 'boolean'],
                'id' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128],
            ],
            'required' => ['required'],
        ];
    }
}
