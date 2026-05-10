<?php

declare(strict_types=1);

namespace X402\Webhook;

use X402\Exceptions\X402Exception;

/**
 * Webhook payload was malformed or missing required fields. Adapter
 * controllers convert this to HTTP 400 — the facilitator's retry
 * policy should treat 4xx as no-retry, so the malformed delivery is
 * dropped instead of looping.
 */
final class InvalidWebhookException extends X402Exception {}
