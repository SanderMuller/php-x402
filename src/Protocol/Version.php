<?php

declare(strict_types=1);

namespace X402\Protocol;

use InvalidArgumentException;

enum Version: string
{
    case V1 = 'v1';
    case V2 = 'v2';

    /**
     * Wire integer for the `x402Version` envelope field — spec mandates a
     * JSON number, NOT a string. Reference impls (TS / Go / Python) all
     * emit it as an integer.
     */
    public function toInt(): int
    {
        return match ($this) {
            self::V1 => 1,
            self::V2 => 2,
        };
    }

    public static function fromInt(int $version): self
    {
        return match ($version) {
            1 => self::V1,
            2 => self::V2,
            default => throw new InvalidArgumentException(sprintf('Unsupported x402 version: %d', $version)),
        };
    }

    /**
     * v1 has NO server→client challenge header — the 402 body alone carries
     * the challenge JSON. v2 introduced the dedicated `PAYMENT-REQUIRED`
     * header (and the body itself becomes "an implementation concern").
     *
     * Returning `X-PAYMENT` for v1 here would collide with the v1 client→
     * server SIGNATURE header — same key, opposite direction.
     */
    public function challengeHeader(): ?string
    {
        return match ($this) {
            self::V1 => null,
            self::V2 => 'PAYMENT-REQUIRED',
        };
    }

    public function signatureHeader(): string
    {
        return match ($this) {
            self::V1 => 'X-PAYMENT',
            self::V2 => 'PAYMENT-SIGNATURE',
        };
    }

    public function responseHeader(): string
    {
        return match ($this) {
            self::V1 => 'X-PAYMENT-RESPONSE',
            self::V2 => 'PAYMENT-RESPONSE',
        };
    }
}
