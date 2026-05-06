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
use X402\Server\PaymentEnforcer;
use X402\Server\StaticPriceTable;

final readonly class StubFacilitator implements FacilitatorClient
{
    public function __construct(
        public bool $verifyOk = true,
        public bool $settleOk = true,
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        return new VerifyResult(
            isValid: $this->verifyOk,
            invalidReason: $this->verifyOk ? null : 'rejected',
            payer: '0xpayer',
        );
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        return new SettleResult(
            success: $this->settleOk,
            transaction: $this->settleOk ? '0xtxhash' : '',
            network: $challenge->network,
            payer: '0xpayer',
            errorReason: $this->settleOk ? null : 'settlement-failed',
        );
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
