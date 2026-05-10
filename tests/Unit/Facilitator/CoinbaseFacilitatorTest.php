<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Exceptions\FacilitatorException;
use X402\Facilitator\CoinbaseFacilitator;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return $this->response;
    }
}

/**
 * @return array{0: CoinbaseFacilitator, 1: FakeHttpClient}
 */
function makeFacilitator(ResponseInterface $response): array
{
    $client = new FakeHttpClient($response);
    $factory = new Psr17Factory();

    return [new CoinbaseFacilitator($client, $factory, $factory), $client];
}

function makeChallenge(): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: '10000',
        asset: '0xabc',
        payTo: '0xdef',
    );
}

function makeSignature(): PaymentSignature
{
    return new PaymentSignature('exact', 'eip155:8453', ['signature' => '0xdead', 'authorization' => []]);
}

it('decodes a verify response into a VerifyResult', function (): void {
    [$facilitator] = makeFacilitator(new Response(200, [], (string) json_encode([
        'isValid' => true,
        'payer' => '0xpayer',
    ])));

    $result = $facilitator->verify(makeSignature(), makeChallenge());

    expect($result->isValid)->toBeTrue()
        ->and($result->payer)->toBe('0xpayer');
});

it('decodes a settle response into a SettleResult', function (): void {
    [$facilitator] = makeFacilitator(new Response(200, [], (string) json_encode([
        'success' => true,
        'transaction' => '0xtxhash',
        'network' => 'eip155:8453',
        'payer' => '0xpayer',
    ])));

    $result = $facilitator->settle(makeSignature(), makeChallenge());

    expect($result->success)->toBeTrue()
        ->and($result->transaction)->toBe('0xtxhash');
});

it('throws on HTTP error status', function (): void {
    [$facilitator] = makeFacilitator(new Response(500, [], 'oops'));

    $facilitator->verify(makeSignature(), makeChallenge());
})->throws(FacilitatorException::class, 'HTTP 500');

it('throws on non-JSON body', function (): void {
    [$facilitator] = makeFacilitator(new Response(200, [], '<html>503</html>'));

    $facilitator->verify(makeSignature(), makeChallenge());
})->throws(FacilitatorException::class, 'non-JSON');

it('forwards default headers (e.g. CDP auth)', function (): void {
    $client = new FakeHttpClient(new Response(200, [], '{"isValid":true}'));
    $factory = new Psr17Factory();

    $facilitator = new CoinbaseFacilitator(
        http: $client,
        requestFactory: $factory,
        streamFactory: $factory,
        defaultHeaders: ['Authorization' => 'Bearer secret-token'],
    );

    $facilitator->verify(makeSignature(), makeChallenge());

    expect($client->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer secret-token');
});

it('discoverResources forwards default headers and parses a multi-item body', function (): void {
    $body = json_encode([
        'items' => [
            // Entry with discoveryInfo + accepts.
            [
                'resource' => 'https://api.example/premium-a',
                'type' => 'http',
                'x402Version' => 2,
                'accepts' => [
                    ['scheme' => 'exact', 'network' => 'eip155:8453', 'amount' => '1000'],
                    ['scheme' => 'upto', 'network' => 'eip155:8453', 'amount' => '5000'],
                ],
                'lastUpdated' => '2026-05-10T00:00:00Z',
                'metadata' => ['region' => 'us-west'],
                'discoveryInfo' => ['category' => 'data'],
            ],
            // Entry intentionally missing `accepts` — should produce empty list.
            [
                'resource' => 'https://api.example/premium-b',
                'type' => 'http',
            ],
            // Non-array entry — should be skipped (defensive parse).
            'not-an-object',
        ],
        'pagination' => ['limit' => 50, 'offset' => 0, 'total' => 2],
    ], JSON_THROW_ON_ERROR);

    $client = new FakeHttpClient(new Response(200, [], $body));
    $factory = new Psr17Factory();

    $facilitator = new CoinbaseFacilitator(
        http: $client,
        requestFactory: $factory,
        streamFactory: $factory,
        defaultHeaders: ['Authorization' => 'Bearer cdp-token'],
    );

    $page = $facilitator->discoverResources();

    expect($client->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer cdp-token')
        ->and($client->sent[0]->getHeaderLine('Accept'))->toBe('application/json')
        ->and((string) $client->sent[0]->getUri())->toContain('/discovery/resources')
        ->and($page->items)->toHaveCount(2)
        ->and($page->items[0]->resource)->toBe('https://api.example/premium-a')
        ->and($page->items[0]->accepts)->toHaveCount(2)
        ->and($page->items[0]->discoveryInfo)->toBe(['category' => 'data'])
        ->and($page->items[0]->metadata)->toBe(['region' => 'us-west'])
        ->and($page->items[1]->accepts)->toBe([])
        ->and($page->items[1]->discoveryInfo)->toBeNull()
        ->and($page->items[1]->x402Version)->toBe(2);
});

it('supported forwards default headers and parses kinds + extensions + signers', function (): void {
    $body = json_encode([
        'kinds' => [
            ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:8453'],
            ['x402Version' => 2, 'scheme' => 'upto', 'network' => 'eip155:*'],
        ],
        'extensions' => [
            ['name' => 'payment-identifier', 'info' => ['required' => false]],
        ],
        'signers' => [
            'eip155:*' => ['0xabc', '0xdef'],
        ],
    ], JSON_THROW_ON_ERROR);

    $client = new FakeHttpClient(new Response(200, [], $body));
    $factory = new Psr17Factory();

    $facilitator = new CoinbaseFacilitator(
        http: $client,
        requestFactory: $factory,
        streamFactory: $factory,
        defaultHeaders: ['Authorization' => 'Bearer cdp-token'],
    );

    $supported = $facilitator->supported();

    expect($client->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer cdp-token')
        ->and($client->sent[0]->getHeaderLine('Accept'))->toBe('application/json')
        ->and((string) $client->sent[0]->getUri())->toEndWith('/supported')
        ->and($supported->kinds)->toHaveCount(2)
        ->and($supported->kinds[0]['scheme'])->toBe('exact')
        ->and($supported->extensions)->toHaveCount(1)
        ->and($supported->signers)->toBe(['eip155:*' => ['0xabc', '0xdef']]);
});

it('builds via CoinbaseFacilitator::default() with a single combined factory', function (): void {
    $client = new FakeHttpClient(new Response(
        200,
        ['Content-Type' => 'application/json'],
        json_encode(['isValid' => true, 'payer' => '0xpayer'], JSON_THROW_ON_ERROR),
    ));
    $factory = new Psr17Factory();

    $facilitator = CoinbaseFacilitator::default(
        http: $client,
        factory: $factory,
        defaultHeaders: ['Authorization' => 'Bearer secret-token'],
    );

    $result = $facilitator->verify(makeSignature(), makeChallenge());

    expect($result->isValid)->toBeTrue()
        ->and($client->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer secret-token')
        ->and((string) $client->sent[0]->getUri())->toContain('x402.org/facilitator/verify');
});
