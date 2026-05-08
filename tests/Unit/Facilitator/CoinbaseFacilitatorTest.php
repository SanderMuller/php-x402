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
