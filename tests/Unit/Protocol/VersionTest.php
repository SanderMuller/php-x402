<?php

declare(strict_types=1);

use X402\Protocol\Version;

it('uses X-PAYMENT* headers in v1', function (): void {
    expect(Version::V1->challengeHeader())->toBe('X-PAYMENT')
        ->and(Version::V1->signatureHeader())->toBe('X-PAYMENT')
        ->and(Version::V1->responseHeader())->toBe('X-PAYMENT-RESPONSE');
});

it('uses PAYMENT-* headers in v2', function (): void {
    expect(Version::V2->challengeHeader())->toBe('PAYMENT-REQUIRED')
        ->and(Version::V2->signatureHeader())->toBe('PAYMENT-SIGNATURE')
        ->and(Version::V2->responseHeader())->toBe('PAYMENT-RESPONSE');
});
