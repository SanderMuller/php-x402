<?php

declare(strict_types=1);

namespace X402\Transport\A2a;

/**
 * Lifecycle status values carried in `x402.payment.status` on every
 * A2A message during a paid task.
 *
 * Spec: `specs/transports-v2/a2a.md`.
 */
enum A2aPaymentStatus: string
{
    /** Server has issued a PaymentRequired and is waiting for client signature. */
    case PaymentRequired = 'payment-required';

    /** Server rejected the client's payload at validation/verify. */
    case PaymentRejected = 'payment-rejected';

    /** Client has submitted a signed payload; server hasn't verified yet. */
    case PaymentSubmitted = 'payment-submitted';

    /** Facilitator verify returned isValid=true, settle in progress. */
    case PaymentVerified = 'payment-verified';

    /** Settlement succeeded, task completed. */
    case PaymentCompleted = 'payment-completed';

    /** Settlement failed (transport / on-chain / facilitator error). */
    case PaymentFailed = 'payment-failed';
}
