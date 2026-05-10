<?php

declare(strict_types=1);

namespace X402\Facilitator;

/**
 * Discriminator on `PaymentOutcome` — drives the `match` adopters write
 * inside their `DispatchingFacilitator` `onOutcome` closure.
 *
 * Currently `SettleSucceeded` is the only "happy path" kind; everything
 * else is a rejection or transport error and carries a `reason` string.
 *
 * **Forward-compat:** new cases may be added in any minor version
 * (e.g. async-settlement support adds `SettlePending` in a future
 * minor). Adopters writing `match ($outcome->kind) { ... }` SHOULD
 * include a `default` arm — exhaustive matches without a default
 * will `UnhandledMatchError` on upgrade. The string backing is
 * stable: a kind's string value never changes once introduced, so
 * serialised event payloads / DB columns / log lines stay forward
 * compatible.
 */
enum PaymentOutcomeKind: string
{
    public const REASON_PREFIX_VERIFY_ERROR = 'verify-error: ';

    public const REASON_PREFIX_SETTLE_ERROR = 'settle-error: ';

    case VerifyRejected = 'verify-rejected';

    case VerifyError = 'verify-error';

    case SettleSucceeded = 'settle-succeeded';

    /**
     * Async-settlement in flight. The facilitator accepted the
     * authorization synchronously and will deliver the on-chain outcome
     * via webhook. `PaymentOutcome::$settle` carries a `SettleResult`
     * with `success=false`, empty `transaction`, and a non-empty
     * `tracker`. `reason` is null — pending is not a failure.
     */
    case SettlePending = 'settle-pending';

    case SettleFailed = 'settle-failed';

    case SettleError = 'settle-error';
}
