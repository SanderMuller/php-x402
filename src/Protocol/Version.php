<?php

declare(strict_types=1);

namespace X402\Protocol;

enum Version: string
{
    case V1 = 'v1';
    case V2 = 'v2';

    public function challengeHeader(): string
    {
        return match ($this) {
            self::V1 => 'X-PAYMENT',
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
