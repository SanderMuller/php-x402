<?php

declare(strict_types=1);

namespace X402\Facilitator;

use Throwable;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Snapshot of a verify or settle call's outcome, surfaced to the
 * `DispatchingFacilitator` `onOutcome` closure for downstream event
 * dispatch / payment-history persistence.
 *
 * Field population per `kind`:
 *
 *   - `VerifyRejected`  : `verify` populated, `settle` null, `reason` =
 *                         `invalidReason`, `exception` null.
 *   - `VerifyError`     : both result fields null, `reason` =
 *                         `'verify-error: ' . get_class($e) . ': ' . $e->getMessage()`,
 *                         `exception` populated.
 *   - `SettleSucceeded` : `settle` populated, `verify` null, `reason`
 *                         null, `exception` null.
 *   - `SettleFailed`    : `settle` populated (with `success=false`),
 *                         `verify` null, `reason` = `errorReason`,
 *                         `exception` null.
 *   - `SettleError`     : both result fields null, `reason` =
 *                         `'settle-error: ' . get_class($e) . ': ' . $e->getMessage()`,
 *                         `exception` populated.
 *
 * `$resource` is the dispatcher-formatted resource string (raw
 * `challenge->resource ?? ''` if no `resourceFormatter` is wired).
 * Adopters consume `$outcome->resource` directly; `PaymentRowBuilder`
 * persists it verbatim. Don't re-format downstream.
 */
final readonly class PaymentOutcome
{
    public function __construct(
        public PaymentOutcomeKind $kind,
        public PaymentSignature $signature,
        public PaymentRequired $challenge,
        public string $resource,
        public ?string $reason = null,
        public ?VerifyResult $verify = null,
        public ?SettleResult $settle = null,
        public ?Throwable $exception = null,
    ) {}
}
