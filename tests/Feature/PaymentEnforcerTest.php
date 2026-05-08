<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use X402\Facilitator\FacilitatorClient;
use X402\Protocol\PaymentRequired;
use X402\Protocol\Version;
use X402\Replay\InMemoryNonceStore;
use X402\Schemes\Evm\ExactScheme;
use X402\Server\EnforcementPolicy;
use X402\Server\PaymentEnforcer;
use X402\Server\ResourceResolver;
use X402\Server\StaticPriceTable;
use X402\Testing\RecordingFacilitator;
use X402\Testing\StubFacilitator;

final class OkHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new PsrResponse(200, ['Content-Type' => 'text/plain'], 'protected resource');
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
        facilitator: $facilitator ?? new StubFacilitator(),
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
    $response = buildEnforcer(new StubFacilitator(verifyOk: false))->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
});

it('returns 402 when facilitator settle fails', function (): void {
    $response = buildEnforcer(new StubFacilitator(verifyOk: true, settleOk: false))->process(buildSignedRequest('X-PAYMENT'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
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

    $facilitator = new RecordingFacilitator();
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
        ->and($facilitator->verifyCalls)->toBe(0)
        ->and($facilitator->settleCalls)->toBe(0)
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
        facilitator: new StubFacilitator(),
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
        facilitator: new StubFacilitator(),
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

it('builds via PaymentEnforcer::default() with sensible defaults', function (): void {
    $challenge = new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xasset',
        payTo: '0x000000000000000000000000000000000000beef',
    );
    $priceTable = new StaticPriceTable();
    $priceTable->set('/premium', $challenge);

    $enforcer = PaymentEnforcer::default(
        priceTable: $priceTable,
        facilitator: new StubFacilitator(),
        factory: new Psr17Factory(),
    );

    $response = $enforcer->process(new ServerRequest('GET', '/premium'), new OkHandler());

    expect($response->getStatusCode())->toBe(402);
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
        facilitator: new StubFacilitator(),
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
