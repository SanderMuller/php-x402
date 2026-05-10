<?php

declare(strict_types=1);

use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\DispatchingFacilitator;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\PaymentOutcome;
use X402\Facilitator\PaymentOutcomeKind;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

function dispatchChallenge(string $resource = 'https://example.test/premium'): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
        resource: $resource,
    );
}

function dispatchSignature(): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce', 'validBefore' => 9999999999]],
    );
}

function alwaysThrowsVerify(Throwable $exception): Closure
{
    return function () use ($exception): VerifyResult {
        throw $exception;
    };
}

function alwaysThrowsSettle(Throwable $exception): Closure
{
    return function () use ($exception): SettleResult {
        throw $exception;
    };
}

function alwaysThrowsResource(Throwable $exception): Closure
{
    return function (string $url) use ($exception): string {
        throw $exception;
    };
}

function programmableInner(Closure $onVerify, Closure $onSettle): FacilitatorClient
{
    return new class ($onVerify, $onSettle) implements FacilitatorClient {
        public function __construct(
            private readonly Closure $onVerify,
            private readonly Closure $onSettle,
        ) {}

        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            $result = ($this->onVerify)($signature, $challenge);
            if (! $result instanceof VerifyResult) {
                throw new RuntimeException('test stub returned non-VerifyResult');
            }

            return $result;
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            $result = ($this->onSettle)($signature, $challenge);
            if (! $result instanceof SettleResult) {
                throw new RuntimeException('test stub returned non-SettleResult');
            }

            return $result;
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
        }
    };
}

it('passes verify success through without firing onOutcome', function (): void {
    $captured = [];
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true, payer: '0xpayer'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o, array $ctx) use (&$captured): void {
            $captured[] = ['kind' => $o->kind, 'reason' => $o->reason];
        },
    );

    $verify = $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($verify->isValid)->toBeTrue()
        ->and($captured)->toBe([]);
});

it('fires VerifyRejected with invalidReason as reason when verify returns isValid=false', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'insufficient-funds'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($captured)->toBeInstanceOf(PaymentOutcome::class)
        ->and($captured?->kind)->toBe(PaymentOutcomeKind::VerifyRejected)
        ->and($captured?->reason)->toBe('insufficient-funds')
        ->and($captured?->verify)->not->toBeNull()
        ->and($captured?->settle)->toBeNull()
        ->and($captured?->exception)->toBeNull();
});

it('fires VerifyError with prefixed reason and re-throws when inner verify throws', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: alwaysThrowsVerify(new RuntimeException('socket timeout')),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $thrown = null;
    try {
        $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('socket timeout')
        ->and($captured?->kind)->toBe(PaymentOutcomeKind::VerifyError)
        ->and($captured?->reason)->toBe(PaymentOutcomeKind::REASON_PREFIX_VERIFY_ERROR . 'RuntimeException: socket timeout')
        ->and($captured?->exception)->toBeInstanceOf(RuntimeException::class);
});

it('fires SettleSucceeded on settle success with no reason', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->settle(dispatchSignature(), dispatchChallenge());

    expect($captured?->kind)->toBe(PaymentOutcomeKind::SettleSucceeded)
        ->and($captured?->reason)->toBeNull()
        ->and($captured?->settle)->not->toBeNull()
        ->and($captured?->verify)->toBeNull();
});

it('fires SettleFailed with errorReason as reason when settle returns success=false', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true),
            onSettle: fn (): SettleResult => new SettleResult(success: false, transaction: '', network: 'eip155:8453', payer: '', errorReason: 'on-chain-revert'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->settle(dispatchSignature(), dispatchChallenge());

    expect($captured?->kind)->toBe(PaymentOutcomeKind::SettleFailed)
        ->and($captured?->reason)->toBe('on-chain-revert')
        ->and($captured?->settle?->success)->toBeFalse();
});

it('fires SettleError with prefixed reason and re-throws when inner settle throws', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true),
            onSettle: alwaysThrowsSettle(new LogicException('bad nonce')),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $thrown = null;
    try {
        $dispatcher->settle(dispatchSignature(), dispatchChallenge());
    } catch (LogicException $logicException) {
        $thrown = $logicException;
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($thrown?->getMessage())->toBe('bad nonce')
        ->and($captured?->kind)->toBe(PaymentOutcomeKind::SettleError)
        ->and($captured?->reason)->toBe(PaymentOutcomeKind::REASON_PREFIX_SETTLE_ERROR . 'LogicException: bad nonce')
        ->and($captured?->exception)->toBeInstanceOf(LogicException::class);
});

it('falls back to a default reason when verify invalidReason is null', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($captured?->reason)->toBe('Payment rejected by facilitator.');
});

it('falls back to a default reason when settle errorReason is null', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true),
            onSettle: fn (): SettleResult => new SettleResult(success: false, transaction: '', network: 'eip155:8453', payer: ''),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->settle(dispatchSignature(), dispatchChallenge());

    expect($captured?->reason)->toBe('Settlement failed.');
});

it('formats the outcome resource via resourceFormatter when wired', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
        resourceFormatter: fn (string $url): string => 'route:premium#' . hash('sha256', $url),
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge('https://example.test/premium?utm=x'));

    expect($captured?->resource)->toStartWith('route:premium#');
});

it('passes the raw challenge resource through when resourceFormatter is null', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge('https://example.test/premium'));

    expect($captured?->resource)->toBe('https://example.test/premium');
});

it('emits empty string for resource when challenge has no resource and no formatter', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o) use (&$captured): void {
            $captured = $o;
        },
    );

    $challenge = new PaymentRequired(scheme: 'exact', network: 'eip155:8453', amount: '10000', asset: '0xasset', payTo: '0xbeef');
    $dispatcher->verify(dispatchSignature(), $challenge);

    expect($captured?->resource)->toBe('');
});

it('passes captureContext result as second arg to onOutcome', function (): void {
    $invocations = 0;
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o, array $ctx) use (&$captured): void {
            $captured = $ctx;
        },
        captureContext: function () use (&$invocations): array {
            ++$invocations;

            return ['user_id' => 42, 'trace_id' => 'abc', 'invocation' => $invocations];
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($captured)->toBe(['user_id' => 42, 'trace_id' => 'abc', 'invocation' => 1]);
});

it('invokes captureContext once per outcome (twice per request: verify + settle)', function (): void {
    $invocations = 0;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (): void {},
        captureContext: function () use (&$invocations): array {
            ++$invocations;

            return [];
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    $dispatcher->settle(dispatchSignature(), dispatchChallenge());

    expect($invocations)->toBe(2);
});

it('passes empty context array when captureContext is null', function (): void {
    $captured = null;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (PaymentOutcome $o, array $ctx) use (&$captured): void {
            $captured = $ctx;
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($captured)->toBe([]);
});

it('does not invoke captureContext when onOutcome is null (the wrapper short-circuits emit)', function (): void {
    $captureCalls = 0;
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: null,
        captureContext: function () use (&$captureCalls): array {
            ++$captureCalls;

            return [];
        },
    );

    $dispatcher->verify(dispatchSignature(), dispatchChallenge());

    expect($captureCalls)->toBe(0);
});

it('swallows onOutcome exceptions on VerifyError so the original facilitator throwable wins', function (): void {
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: alwaysThrowsVerify(new RuntimeException('socket timeout')),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (): never {
            throw new DomainException('listener bug');
        },
    );

    $thrown = null;
    try {
        $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('socket timeout');
});

it('swallows onOutcome exceptions on SettleError so the original facilitator throwable wins', function (): void {
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: true),
            onSettle: alwaysThrowsSettle(new LogicException('bad nonce')),
        ),
        onOutcome: function (): never {
            throw new DomainException('listener bug');
        },
    );

    $thrown = null;
    try {
        $dispatcher->settle(dispatchSignature(), dispatchChallenge());
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($thrown?->getMessage())->toBe('bad nonce');
});

it('swallows resourceFormatter exceptions on *-error paths so the original throwable wins', function (): void {
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: alwaysThrowsVerify(new RuntimeException('socket timeout')),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (): void {},
        resourceFormatter: alwaysThrowsResource(new DomainException('formatter bug')),
    );

    $thrown = null;
    try {
        $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('socket timeout');
});

it('swallows captureContext exceptions on *-error paths so the original throwable wins', function (): void {
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: alwaysThrowsVerify(new RuntimeException('socket timeout')),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (): void {},
        captureContext: function (): never {
            throw new DomainException('context lookup failed');
        },
    );

    $thrown = null;
    try {
        $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('socket timeout');
});

it('propagates exceptions thrown by onOutcome (does not catch programmer error)', function (): void {
    $dispatcher = new DispatchingFacilitator(
        inner: programmableInner(
            onVerify: fn (): VerifyResult => new VerifyResult(isValid: false, invalidReason: 'x'),
            onSettle: fn (): SettleResult => new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer'),
        ),
        onOutcome: function (): never {
            throw new DomainException('listener bug');
        },
    );

    $thrown = null;
    try {
        $dispatcher->verify(dispatchSignature(), dispatchChallenge());
    } catch (DomainException $domainException) {
        $thrown = $domainException;
    }

    expect($thrown)->toBeInstanceOf(DomainException::class)
        ->and($thrown?->getMessage())->toBe('listener bug');
});

it('passes supported() through to the inner facilitator unchanged', function (): void {
    $supported = new SupportedKinds(kinds: []);
    $inner = new class ($supported) implements FacilitatorClient {
        public function __construct(private readonly SupportedKinds $supportedKinds) {}

        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            return new VerifyResult(isValid: true);
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            return new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer');
        }

        public function supported(): SupportedKinds
        {
            return $this->supportedKinds;
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
        }
    };
    $dispatcher = new DispatchingFacilitator(inner: $inner);

    expect($dispatcher->supported())->toBe($supported);
});

it('passes discoverResources() through to the inner facilitator unchanged', function (): void {
    $page = new DiscoveryPage(items: [], limit: 50, offset: 0, total: 0);
    $inner = new class ($page) implements FacilitatorClient {
        public function __construct(private readonly DiscoveryPage $page) {}

        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            return new VerifyResult(isValid: true);
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            return new SettleResult(success: true, transaction: '0xtx', network: 'eip155:8453', payer: '0xpayer');
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return $this->page;
        }
    };
    $dispatcher = new DispatchingFacilitator(inner: $inner);

    expect($dispatcher->discoverResources())->toBe($page);
});
