<?php

declare(strict_types=1);

namespace X402\Support;

use X402\Exceptions\InvalidPaymentException;

/**
 * Type-narrowing accessor for JSON-decoded `array<string, mixed>` shapes.
 *
 * The x402 wire format is JSON; we decode it once at the boundary and then
 * funnel every per-field read through this class so PHPStan can infer the
 * target type and validation lives in one place.
 */
final class JsonReader
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function string(array $data, string $key, string $context = ''): string
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidPaymentException(self::missing($key, $context));
        }

        $value = $data[$key];

        if (! is_string($value)) {
            // Coerce numeric values written as JSON numbers back to string so
            // callers don't have to special-case "value": 10000 vs "10000".
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            throw new InvalidPaymentException(self::badType($key, 'string', $value, $context));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function stringOrNull(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $value = $data[$key];

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function int(array $data, string $key, ?int $default = null, string $context = ''): int
    {
        if (! array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            throw new InvalidPaymentException(self::missing($key, $context));
        }

        $value = $data[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidPaymentException(self::badType($key, 'int', $value, $context));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function array(array $data, string $key, string $context = ''): array
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidPaymentException(self::missing($key, $context));
        }

        $value = $data[$key];

        if (! is_array($value)) {
            throw new InvalidPaymentException(self::badType($key, 'array', $value, $context));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function arrayOrEmpty(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            return [];
        }

        /** @var array<string, mixed> $arr */
        $arr = $data[$key];

        return $arr;
    }

    private static function missing(string $key, string $context): string
    {
        return $context === ''
            ? sprintf('Missing required field "%s".', $key)
            : sprintf('%s: missing required field "%s".', $context, $key);
    }

    private static function badType(string $key, string $expected, mixed $actual, string $context): string
    {
        $actualType = get_debug_type($actual);
        $prefix = $context === '' ? '' : $context . ': ';

        return sprintf('%sfield "%s" expected %s, got %s.', $prefix, $key, $expected, $actualType);
    }
}
