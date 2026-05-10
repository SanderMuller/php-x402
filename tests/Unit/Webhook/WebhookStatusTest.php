<?php

declare(strict_types=1);

use X402\Webhook\WebhookStatus;

it('parses canonical statuses', function (): void {
    expect(WebhookStatus::from('settled'))->toBe(WebhookStatus::Settled)
        ->and(WebhookStatus::from('rejected'))->toBe(WebhookStatus::Rejected)
        ->and(WebhookStatus::from('cancelled'))->toBe(WebhookStatus::Cancelled)
        ->and(WebhookStatus::from('pending'))->toBe(WebhookStatus::Pending)
        ->and(WebhookStatus::from('unknown'))->toBe(WebhookStatus::Unknown);
});

it('falls back to Unknown for unrecognised values via tryFrom', function (): void {
    $resolved = WebhookStatus::tryFrom('made-up-status') ?? WebhookStatus::Unknown;

    expect($resolved)->toBe(WebhookStatus::Unknown);
});

it('tryFrom returns null for unrecognised values without the fallback shim', function (): void {
    expect(WebhookStatus::tryFrom('made-up-status'))->toBeNull();
});
