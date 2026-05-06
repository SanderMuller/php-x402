<?php

declare(strict_types=1);

use X402\Protocol\Version;

it('uses X-PAYMENT* headers in v1 (no challenge header — body-only)', function (): void {
    // v1 has NO server→client challenge header — the body alone carries the
    // challenge JSON. Returning `X-PAYMENT` here would collide with the v1
    // client→server signature header.
    expect(Version::V1->challengeHeader())->toBeNull()
        ->and(Version::V1->signatureHeader())->toBe('X-PAYMENT')
        ->and(Version::V1->responseHeader())->toBe('X-PAYMENT-RESPONSE');
});

it('uses PAYMENT-* headers in v2', function (): void {
    expect(Version::V2->challengeHeader())->toBe('PAYMENT-REQUIRED')
        ->and(Version::V2->signatureHeader())->toBe('PAYMENT-SIGNATURE')
        ->and(Version::V2->responseHeader())->toBe('PAYMENT-RESPONSE');
});
