<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Replay\InMemoryNonceStore;
use X402\Schemes\Evm\ExactScheme;
use X402\Schemes\SchemeContract;
use X402\Server\EnforcementPolicy;
use X402\Server\PaymentEnforcer;
use X402\Server\ResourceResolver;
use X402\Server\StaticPriceTable;
use X402\Testing\FakeFacilitator;

final class OkHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new PsrResponse(200, ['Content-Type' => 'text/plain'], 'protected resource');
    }
}

final class EnforcerCountingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        return new PsrResponse(200, ['Content-Type' => 'text/plain'], 'protected resource');
    }
}

final readonly class PendingFacilitator implements FacilitatorClient
{
    public function __construct(
        public string $tracker = 'tracker-async-1',
        public string $network = 'eip155:8453',
        public string $payer = '0xpayerFromFac',
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        return new VerifyResult(isValid: true, payer: $this->payer);
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        return SettleResult::pending($this->tracker, $this->network, $this->payer);
    }

    public function supported(): SupportedKinds
    {
        return new SupportedKinds(kinds: []);
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
    }
}

function buildEnforcer(?FacilitatorClient $facilitator = null, Version $version = Version::V1): PaymentEnforcer
{
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();

    return new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: $facilitator ?? new FakeFacilitator(),
        nonceStore: new InMemoryNonceStore(),
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
        version: $version,
    );
}

function buildSignedRequest(string $signatureHeader): ServerRequestInterface
{
    $payload = [
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => '0x000000000000000000000000000000000000beef',
                'value' => '10000',
                'validAfter' => time() - 10,
                'validBefore' => time() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ];

    $headerValue = base64_encode((string) json_encode($payload));

    return (new ServerRequest('GET', '/premium'))
        ->withHeader($signatureHeader, $headerValue);
}

it('returns 402 when no payment header is present (v1: body-only, no header)', function (): void {
    $response = buildEnforcer()->process(new ServerRequest('GET', '/premium'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
    // v1 has no challenge header — the spec puts the challenge in the body.
    expect($response->hasHeader('X-PAYMENT'))->toBeFalse();
    expect((string) $response->getBody())->toContain('"x402Version":1');
});

it('passes through when the resource is not priced', function (): void {
    $response = buildEnforcer()->process(new ServerRequest('GET', '/free'), new OkHandler());

    expect($response->getStatusCode())->toBe(200);
});

it('settles and returns the inner response with PAYMENT-RESPONSE on a valid payment', function (): void {
    $response = buildEnforcer()->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('X-PAYMENT-RESPONSE'))->not->toBe('');
});

it('returns 402 when facilitator verify fails', function (): void {
    $response = buildEnforcer((new FakeFacilitator())->rejectVerify())->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
});

it('returns 402 when facilitator settle fails', function (): void {
    $response = buildEnforcer((new FakeFacilitator())->failSettle())->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
});

it('returns 202 Accepted with tracker body when settle returns pending', function (): void {
    $facilitator = new PendingFacilitator(tracker: 'tracker-async-7');
    $response = buildEnforcer($facilitator)->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(202);

    $body = (string) $response->getBody();
    /** @var array{status: string, tracker: string} $decoded */
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBe(['status' => 'pending', 'tracker' => 'tracker-async-7']);

    $headerValue = $response->getHeaderLine('X-PAYMENT-RESPONSE');
    expect($headerValue)->not->toBe('');

    /** @var array{success: bool, transaction: string, tracker: string} $receipt */
    $receipt = json_decode((string) base64_decode($headerValue, true), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['success'])->toBeFalse()
        ->and($receipt['transaction'])->toBe('')
        ->and($receipt['tracker'])->toBe('tracker-async-7');
});

it('does not invoke the inner handler when settle is pending', function (): void {
    $handler = new EnforcerCountingHandler();
    $facilitator = new PendingFacilitator();

    $response = buildEnforcer($facilitator)->process(buildSignedRequest('X-PAYMENT'), $handler);

    expect($response->getStatusCode())->toBe(202)
        ->and($handler->calls)->toBe(0);
});

it('rejects nonce reuse', function (): void {
    $request = buildSignedRequest('X-PAYMENT');
    $enforcer = buildEnforcer();

    $first = $enforcer->process($request, new OkHandler());
    $second = $enforcer->process($request, new OkHandler());

    expect($first->getStatusCode())->toBe(200);
    // Replay → InvalidPaymentException → 400 per spec transport status table.
    expect($second->getStatusCode())->toBe(400);
});

it('uses v2 headers when configured for v2', function (): void {
    $response = buildEnforcer(version: Version::V2)->process(new ServerRequest('GET', '/premium'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
    expect($response->hasHeader('PAYMENT-REQUIRED'))->toBeTrue();
});

it('skips the entire pipeline when shouldEnforce returns false', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $facilitator = new FakeFacilitator();
    $nonceStore = new InMemoryNonceStore();
    $factory = new Psr17Factory();

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: $facilitator,
        nonceStore: $nonceStore,
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
        shouldEnforce: static fn (ServerRequestInterface $request): bool => $request->getHeaderLine('User-Agent') !== 'human',
    );

    $response = $enforcer->process(
        (new ServerRequest('GET', '/premium'))->withHeader('User-Agent', 'human'),
        new OkHandler(),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('protected resource')
        ->and($response->hasHeader('X-PAYMENT-RESPONSE'))->toBeFalse()
        ->and($facilitator->verifyCalls())->toBe([])
        ->and($facilitator->settleCalls())->toBe([])
        ->and($nonceStore->claim('eip155:8453', '0xfrom', '0xanynonce', 60))->toBeTrue();
});

it('still enforces when shouldEnforce returns true', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );

    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        nonceStore: new InMemoryNonceStore(),
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
        shouldEnforce: static fn (): bool => true,
    );

    $response = $enforcer->process(new ServerRequest('GET', '/premium'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
});

it('propagates exceptions thrown by shouldEnforce', function (): void {
    $factory = new Psr17Factory();

    $enforcer = new PaymentEnforcer(
        priceTable: new StaticPriceTable(),
        facilitator: new FakeFacilitator(),
        nonceStore: new InMemoryNonceStore(),
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
        shouldEnforce: static function (): bool {
            throw new RuntimeException('predicate boom');
        },
    );

    expect(fn () => $enforcer->process(new ServerRequest('GET', '/premium'), new OkHandler()))
        ->toThrow(RuntimeException::class, 'predicate boom');
});

it('builds via PaymentEnforcer::forTesting() with in-process defaults', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $enforcer = PaymentEnforcer::forTesting(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        factory: new Psr17Factory(),
    );

    $response = $enforcer->process(new ServerRequest('GET', '/premium'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
});

it('still claims the nonce when challenge.extra.assetTransferMethod is non-string (matches ExactScheme normalization)', function (): void {
    // Regression: a non-string assetTransferMethod is normalized to
    // eip3009 by ExactScheme::verifyShape (so settlement still happens),
    // and guardReplay must normalize identically — otherwise a
    // malformed challenge would pass settlement while bypassing the
    // in-process replay gate.
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
        extra: ['assetTransferMethod' => 42], // non-string — normalizes to default
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();
    $store = new InMemoryNonceStore();

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        nonceStore: $store,
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
    );

    $request = buildSignedRequest('X-PAYMENT');
    $payload = json_decode((string) base64_decode($request->getHeaderLine('X-PAYMENT'), true), true);
    /** @var array{payload: array{authorization: array{from: string, nonce: string}}} $payload */
    $from = $payload['payload']['authorization']['from'];
    $nonce = $payload['payload']['authorization']['nonce'];

    $enforcer->process($request, new OkHandler());

    // Slot must be consumed — second claim returns false.
    expect($store->claim('eip155:8453', $from, $nonce, 60))->toBeFalse();
});

it('reaches the facilitator on a valid upto-EVM payment without hitting the in-process nonce store', function (): void {
    // Regression: a previous gate gated only on network=eip155:* +
    // assetTransferMethod=eip3009 (the default), which made `upto`
    // EVM signatures (scheme="upto", no transferMethod set) trip the
    // EIP-3009 path and 400 because their payload uses
    // `uptoAuthorization` instead of `authorization`.
    $challenge = new PaymentRequired(
        scheme: 'upto',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();
    $store = new InMemoryNonceStore();

    $passthroughUpto = new class implements SchemeContract {
        public function name(): string
        {
            return 'upto';
        }

        /**
         * @return list<string>
         */
        public function supportedNetworks(): array
        {
            return ['eip155:8453'];
        }

        public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void {}
    };

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        nonceStore: $store,
        schemes: ['upto' => $passthroughUpto],
        responseFactory: $factory,
        streamFactory: $factory,
    );

    $payload = [
        'scheme' => 'upto',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'uptoAuthorization' => ['from' => '0xfrom', 'nonce' => '0xnonce'],
        ],
    ];
    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader('X-PAYMENT', base64_encode((string) json_encode($payload)));

    $response = $enforcer->process($request, new OkHandler());

    expect($response->getStatusCode())->toBe(200);
});

it('does not claim on payload.authorization when the challenge declares a non-eip3009 transfer method', function (): void {
    // Regression: a Permit2 / Upto challenge ships `extra.assetTransferMethod`
    // pointing to a non-EIP-3009 path. A caller who packs both a real
    // `permit2Authorization` AND a forged top-level `authorization` block
    // must NOT have the forged (from, nonce) claimed by the in-process
    // store — that would let the attacker vary the dummy nonce per
    // request and replay the real Permit2 signature unbounded.
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
        extra: ['assetTransferMethod' => 'permit2'],
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();
    $store = new InMemoryNonceStore();

    $passthroughScheme = new class implements SchemeContract {
        public function name(): string
        {
            return 'exact';
        }

        /**
         * @return list<string>
         */
        public function supportedNetworks(): array
        {
            return ['eip155:8453'];
        }

        public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void {}
    };

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        nonceStore: $store,
        schemes: ['exact' => $passthroughScheme],
        responseFactory: $factory,
        streamFactory: $factory,
    );

    $forgedNonce = '0x' . str_repeat('ab', 32);
    $payload = [
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            // Real Permit2 path data that the host scheme would validate.
            'permit2Authorization' => ['real' => 'fields'],
            // Forged extra block — must be ignored by the replay gate.
            'authorization' => [
                'from' => '0xattacker',
                'to' => '0x000000000000000000000000000000000000beef',
                'value' => '10000',
                'validAfter' => time() - 10,
                'validBefore' => time() + 60,
                'nonce' => $forgedNonce,
            ],
        ],
    ];
    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader('X-PAYMENT', base64_encode((string) json_encode($payload)));

    $enforcer->process($request, new OkHandler());

    // If the enforcer wrongly claimed on the forged authorization, the
    // store would already have ('eip155:8453', '0xattacker', $forgedNonce).
    // Verify the slot is still free.
    expect($store->claim('eip155:8453', '0xattacker', $forgedNonce, 60))->toBeTrue();
});

it('accepts a ResourceResolver instance and an EnforcementPolicy instance', function (): void {
    $resolver = new class implements ResourceResolver {
        public function __invoke(ServerRequestInterface $request): string
        {
            return '/static-resource';
        }
    };

    $policy = new class implements EnforcementPolicy {
        public function __invoke(ServerRequestInterface $request): bool
        {
            return $request->getHeaderLine('X-Bot') === '1';
        }
    };

    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/static-resource', $challenge);

    $factory = new Psr17Factory();
    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: new FakeFacilitator(),
        nonceStore: new InMemoryNonceStore(),
        schemes: ['exact' => new ExactScheme()],
        responseFactory: $factory,
        streamFactory: $factory,
        resourceResolver: $resolver,
        shouldEnforce: $policy,
    );

    // Bot → enforcement runs → 402 (resolver routes any path to /static-resource)
    $bot = (new ServerRequest('GET', '/anywhere'))->withHeader('X-Bot', '1');
    expect($enforcer->process($bot, new OkHandler())->getStatusCode())->toBe(402);

    // Human → policy returns false → inner handler runs → 200
    $human = new ServerRequest('GET', '/anywhere');
    expect($enforcer->process($human, new OkHandler())->getStatusCode())->toBe(200);
});

it('does NOT claim nonces for custom non-RKE schemes — defers to facilitator (0.4.0 BC removal regression guard)', function (): void {
    // 0.3.x BC fallback (custom scheme named "exact" on eip155:* with
    // assetTransferMethod=eip3009 reading payload.authorization
    // directly) was REMOVED in 0.4.0 — see
    // internal/spec-0.4-drop-replay-bc-fallback.md. Custom schemes
    // that want in-process replay protection now MUST implement
    // X402\Schemes\ReplayKeyExtractor.
    //
    // This test pins the new contract: same setup as the 0.3.x
    // "BC fallback claims nonces" test, but assert (a) the local
    // nonce store is never touched, (b) the request still settles
    // (no in-process gate, defers to facilitator's nonce check),
    // (c) a *second* request with the same signature ALSO reaches
    // the facilitator — proof that the in-process replay block is
    // gone for non-RKE custom schemes.
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $factory = new Psr17Factory();
    $store = new InMemoryNonceStore();
    $facilitator = new FakeFacilitator();

    // Custom scheme that does NOT implement ReplayKeyExtractor.
    // Validates the same EIP-3009 shape ExactScheme does.
    $legacyExact = new class implements SchemeContract {
        public function name(): string
        {
            return 'exact';
        }

        /**
         * @return list<string>
         */
        public function supportedNetworks(): array
        {
            return ['eip155:8453'];
        }

        public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void {}
    };

    $enforcer = new PaymentEnforcer(
        priceTable: $priceTable,
        facilitator: $facilitator,
        nonceStore: $store,
        schemes: ['exact' => $legacyExact],
        responseFactory: $factory,
        streamFactory: $factory,
    );

    $request = buildSignedRequest('X-PAYMENT');
    $payload = json_decode((string) base64_decode($request->getHeaderLine('X-PAYMENT'), true), true);
    /** @var array{payload: array{authorization: array{from: string, nonce: string}}} $payload */
    $from = $payload['payload']['authorization']['from'];
    $nonce = $payload['payload']['authorization']['nonce'];

    $first = $enforcer->process($request, new OkHandler());
    $second = $enforcer->process($request, new OkHandler());

    // (a) Local nonce store untouched — slot is still claimable.
    expect($store->claim('eip155:8453', $from, $nonce, 60))->toBeTrue();

    // (b) + (c) Both requests reached verify+settle (facilitator is
    // the only nonce-uniqueness check now for non-RKE schemes). If a
    // future change reintroduces an in-process gate, this fails.
    expect($facilitator->verifyCalls())->toHaveCount(2)
        ->and($facilitator->settleCalls())->toHaveCount(2)
        ->and($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(200);
});
