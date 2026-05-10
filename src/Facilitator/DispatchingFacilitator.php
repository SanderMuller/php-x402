<?php

declare(strict_types=1);

namespace X402\Facilitator;

use Closure;
use Throwable;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Wraps any `FacilitatorClient` and surfaces verify / settle outcomes
 * to a single `onOutcome` closure for downstream event dispatch,
 * payment-history persistence, metrics emission, etc.
 *
 * The wrapper is framework-agnostic. Adopters wire their host-specific
 * concerns (Laravel events, Symfony EventDispatcher, log channels) by
 * branching on `$outcome->kind` inside the closure:
 *
 *     new DispatchingFacilitator(
 *         inner: $coinbase,
 *         onOutcome: function (PaymentOutcome $o, array $ctx): void {
 *             match ($o->kind) {
 *                 PaymentOutcomeKind::SettleSucceeded => $events->dispatch(new PaymentSettled(...)),
 *                 default => $events->dispatch(new PaymentRejected(...)),
 *             };
 *         },
 *         captureContext: fn (): array => $registry->captureFor($container->make('request')),
 *         resourceFormatter: fn (string $url): string => $registry->formatResource($url),
 *     );
 *
 * Why a single `onOutcome` instead of separate `onSettled` /
 * `onRejected` / `onError` callbacks: adopters always need the same
 * context-capture closure for every path, and `match ($o->kind)` is
 * one statement; three callable references are three indirection
 * layers and three context-capture sites. If you want fan-out, wire
 * an event dispatcher inside the closure.
 *
 * Why no-arg `captureContext`: `FacilitatorClient::verify/settle`
 * don't carry a request, and growing the interface to add one
 * breaks every implementor (Coinbase, fakes, custom). A no-arg
 * closure with lexical scope solves it framework-agnostically — the
 * caller materialises whatever request reference they have access to
 * (Laravel container, Symfony RequestStack, ASGI request-local) and
 * returns the captured `array<string, mixed>`.
 *
 * Behavioural rules:
 *
 *   - `supported()` and `discoverResources()` pass through unchanged
 *     — they don't fire outcomes.
 *   - `onOutcome` exceptions propagate from `VerifyRejected`,
 *     `SettleSucceeded`, and `SettleFailed` paths — listener throws
 *     are treated as programmer error and surface to the caller
 *     unchanged.
 *   - `onOutcome` exceptions are *swallowed* on `VerifyError` and
 *     `SettleError` paths so the original facilitator throwable
 *     always wins. A buggy hook on an error path otherwise hides
 *     the transport / network failure that actually triggered the
 *     `*-error` outcome, which makes operational triage impossible.
 *   - `captureContext` exceptions follow the same split: propagate
 *     on success / rejection paths, swallowed on `*-error` paths.
 *     Defensive `?? []` or try/catch belongs inside the closure body
 *     if the caller's request lookup can fail.
 *   - `captureContext` runs once per outcome. A single paid request
 *     causes two invocations (one before verify, one before settle).
 *     If you want memoisation, capture the result inside lexical
 *     scope yourself; the dispatcher does not memoise.
 */
final readonly class DispatchingFacilitator implements FacilitatorClient
{
    /**
     * @param  Closure(PaymentOutcome, array<string, mixed>): void|null  $onOutcome  Invoked on every non-trivial outcome (`VerifyRejected`, `VerifyError`, `SettleSucceeded`, `SettleFailed`, `SettleError`). Pass `null` to disable dispatch (the wrapper then behaves like a passthrough).
     * @param  Closure(): array<string, mixed>|null  $captureContext  Returns host-supplied context (request user id, IP, trace id). Defaults to returning `[]` when null.
     * @param  Closure(string): string|null  $resourceFormatter  Maps `challenge->resource ?? ''` to the canonical resource string surfaced on `PaymentOutcome::$resource`. Defaults to identity.
     */
    public function __construct(
        private FacilitatorClient $inner,
        private ?Closure $onOutcome = null,
        private ?Closure $captureContext = null,
        private ?Closure $resourceFormatter = null,
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        try {
            $result = $this->inner->verify($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->emitOnError(fn (): PaymentOutcome => new PaymentOutcome(
                kind: PaymentOutcomeKind::VerifyError,
                signature: $signature,
                challenge: $challenge,
                resource: $this->formatResource($challenge),
                reason: PaymentOutcomeKind::REASON_PREFIX_VERIFY_ERROR . $throwable::class . ': ' . $throwable->getMessage(),
                exception: $throwable,
            ));

            throw $throwable;
        }

        if (! $result->isValid && $this->onOutcome instanceof Closure) {
            $this->emit(new PaymentOutcome(
                kind: PaymentOutcomeKind::VerifyRejected,
                signature: $signature,
                challenge: $challenge,
                resource: $this->formatResource($challenge),
                reason: $result->invalidReason ?? 'Payment rejected by facilitator.',
                verify: $result,
            ));
        }

        return $result;
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        try {
            $result = $this->inner->settle($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->emitOnError(fn (): PaymentOutcome => new PaymentOutcome(
                kind: PaymentOutcomeKind::SettleError,
                signature: $signature,
                challenge: $challenge,
                resource: $this->formatResource($challenge),
                reason: PaymentOutcomeKind::REASON_PREFIX_SETTLE_ERROR . $throwable::class . ': ' . $throwable->getMessage(),
                exception: $throwable,
            ));

            throw $throwable;
        }

        if (! ($this->onOutcome instanceof Closure)) {
            return $result;
        }

        if ($result->success) {
            $this->emit(new PaymentOutcome(
                kind: PaymentOutcomeKind::SettleSucceeded,
                signature: $signature,
                challenge: $challenge,
                resource: $this->formatResource($challenge),
                settle: $result,
            ));
        } else {
            $this->emit(new PaymentOutcome(
                kind: PaymentOutcomeKind::SettleFailed,
                signature: $signature,
                challenge: $challenge,
                resource: $this->formatResource($challenge),
                reason: $result->errorReason ?? 'Settlement failed.',
                settle: $result,
            ));
        }

        return $result;
    }

    public function supported(): SupportedKinds
    {
        return $this->inner->supported();
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return $this->inner->discoverResources($query);
    }

    private function formatResource(PaymentRequired $challenge): string
    {
        $raw = $challenge->resource ?? '';

        if (! $this->resourceFormatter instanceof Closure) {
            return $raw;
        }

        return ($this->resourceFormatter)($raw);
    }

    /**
     * Entry point for `VerifyRejected` / `SettleSucceeded` / `SettleFailed`
     * outcomes — non-error paths where listener / context-capture
     * exceptions are allowed to propagate as programmer error. Use
     * `emitOnError()` instead from `*-error` catch blocks: that variant
     * takes a `Closure(): PaymentOutcome` (deferred build) and silently
     * swallows hook exceptions so the underlying facilitator throwable
     * always wins.
     */
    private function emit(PaymentOutcome $outcome): void
    {
        if (! $this->onOutcome instanceof Closure) {
            return;
        }

        $context = $this->captureContext instanceof Closure ? ($this->captureContext)() : [];

        ($this->onOutcome)($outcome, $context);
    }

    /**
     * Emit a `*-error` outcome without letting listener, context-capture,
     * OR resource-formatter exceptions mask the original facilitator
     * failure. Hook bugs on error paths are silently swallowed — the
     * caller still sees the underlying transport / facilitator exception
     * that mattered.
     *
     * Takes a closure so PaymentOutcome construction (which calls
     * `formatResource()`) is also inside the swallow scope. Building
     * the outcome eagerly and passing it in would let a throwing
     * `resourceFormatter` escape before the swallow took effect.
     *
     * Owns its own no-listener early-return so callers don't repeat
     * the `instanceof Closure` guard.
     *
     * @param  Closure(): PaymentOutcome  $build
     */
    private function emitOnError(Closure $build): void
    {
        if (! $this->onOutcome instanceof Closure) {
            return;
        }

        try {
            $this->emit($build());
        } catch (Throwable) {
            // intentional: original facilitator throwable wins on *-error paths
        }
    }
}
