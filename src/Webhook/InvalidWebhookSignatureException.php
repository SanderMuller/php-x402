<?php

declare(strict_types=1);

namespace X402\Webhook;

use X402\Exceptions\X402Exception;

/**
 * Webhook signature verification failed: HMAC mismatch, expired
 * timestamp outside the configured clock-skew window, or malformed
 * signature header. Adapter controllers convert this to HTTP 401 with
 * an empty body — never echo the reason back to the caller, since the
 * caller is adversarial by definition when this fires.
 */
final class InvalidWebhookSignatureException extends X402Exception {}
