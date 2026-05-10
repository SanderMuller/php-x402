<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Client\PayingClient;
use X402\Client\Wallet;
use X402\Exceptions\X402Exception;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Support\JsonReader;

/**
 * Scripted PSR-18 inner — returns the next response in `$responses` for each
 * `sendRequest()` call. Records each request for post-hoc assertion.
 */
final class ScriptedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /**
     * @param  list<ResponseInterface>  $responses
     */
    public function __construct(public array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        if ($this->responses === []) {
            throw new RuntimeException('ScriptedHttpClient ran out of responses.');
        }

        return array_shift($this->responses);
    }
}

final readonly class StubWallet implements Wallet
{
    public function __construct(
        public string $address = '0x0000000000000000000000000000000000000001',
        public string $signature = '0xabababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababab1c',
    ) {}

    public function address(): string
    {
        return $this->address;
    }

    public function signDigest(string $digest): string
    {
        return $this->signature;
    }
}

/**
 * Build a v2 PAYMENT-REQUIRED header value: base64-encoded JSON envelope.
 *
 * @param  list<array<string, mixed>>  $accepts
 */
function v2ChallengeHeader(array $accepts): string
{
    return base64_encode(json_encode(['accepts' => $accepts], JSON_THROW_ON_ERROR));
}

/**
 * @param  list<array<string, mixed>>  $accepts
 */
function v1ChallengeBody(array $accepts): string
{
    return json_encode(['accepts' => $accepts], JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>|null  $extraOverride
 * @return array<string, string|array<string, mixed>|int>
 */
function baseChallenge(string $networkOverride = 'eip155:8453', ?array $extraOverride = null): array
{
    return [
        'scheme' => 'exact',
        'network' => $networkOverride,
        'amount' => '1000000',
        'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'payTo' => '0x00000000000000000000000000000000000000ff',
        'maxTimeoutSeconds' => 60,
        'extra' => $extraOverride ?? ['name' => 'USD Coin', 'version' => '2'],
    ];
}

function makeRequest(): RequestInterface
{
    return (new Psr17Factory())->createRequest('GET', 'https://example.test/api/premium');
}

it('passes non-402 responses through unchanged without signing', function (): void {
    $inner = new ScriptedHttpClient([new Response(200, [], 'ok')]);
    $client = new PayingClient($inner, new StubWallet());

    $response = $client->sendRequest(makeRequest());

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('ok')
        ->and($inner->sent)->toHaveCount(1)
        ->and($inner->sent[0]->hasHeader('X-PAYMENT'))->toBeFalse()
        ->and($inner->sent[0]->hasHeader('PAYMENT-SIGNATURE'))->toBeFalse();
});

it('reads the v2 challenge from the PAYMENT-REQUIRED header and resends with PAYMENT-SIGNATURE', function (): void {
    $challenge = baseChallenge();
    $inner = new ScriptedHttpClient([
        new Response(402, ['PAYMENT-REQUIRED' => v2ChallengeHeader([$challenge])], 'irrelevant body'),
        new Response(200, [], 'paid content'),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V2);

    $response = $client->sendRequest(makeRequest());

    expect($response->getStatusCode())->toBe(200)
        ->and($inner->sent)->toHaveCount(2)
        ->and($inner->sent[1]->hasHeader('PAYMENT-SIGNATURE'))->toBeTrue()
        ->and($inner->sent[1]->hasHeader('X-PAYMENT'))->toBeFalse();

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('PAYMENT-SIGNATURE'));

    expect($signed->scheme)->toBe('exact')
        ->and($signed->network)->toBe('eip155:8453')
        ->and($signed->x402Version)->toBe(2);

    $accepted = $signed->accepted ?? throw new RuntimeException('expected v2 accepted echo');

    expect($accepted)->toBeInstanceOf(PaymentRequired::class)
        ->and($accepted->amount)->toBe('1000000');
});

it('falls back to the body when the v2 header is absent and honors configured V1', function (): void {
    $challenge = baseChallenge();
    $inner = new ScriptedHttpClient([
        new Response(402, [], v1ChallengeBody([['scheme' => $challenge['scheme'], 'network' => $challenge['network'], 'maxAmountRequired' => '1000000', 'asset' => $challenge['asset'], 'payTo' => $challenge['payTo'], 'extra' => $challenge['extra']]])),
        new Response(200, [], 'paid content'),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V1);

    $response = $client->sendRequest(makeRequest());

    expect($response->getStatusCode())->toBe(200)
        ->and($inner->sent[1]->hasHeader('X-PAYMENT'))->toBeTrue()
        ->and($inner->sent[1]->hasHeader('PAYMENT-SIGNATURE'))->toBeFalse();

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('X-PAYMENT'));

    expect($signed->x402Version)->toBe(1)
        ->and($signed->accepted)->toBeNull();
});

it('upgrades to v2 wire when client is configured V1 but server returns PAYMENT-REQUIRED header', function (): void {
    // Regression guard: server wire wins over client config (PayingClient.php:58-65).
    $challenge = baseChallenge();
    $inner = new ScriptedHttpClient([
        new Response(402, ['PAYMENT-REQUIRED' => v2ChallengeHeader([$challenge])], ''),
        new Response(200, [], 'paid'),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V1);

    $client->sendRequest(makeRequest());

    expect($inner->sent[1]->hasHeader('PAYMENT-SIGNATURE'))->toBeTrue()
        ->and($inner->sent[1]->hasHeader('X-PAYMENT'))->toBeFalse();

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('PAYMENT-SIGNATURE'));

    expect($signed->x402Version)->toBe(2)
        ->and($signed->accepted)->not->toBeNull();
});

it('honors configured V2 when server returns body-only 402 (no PAYMENT-REQUIRED header)', function (): void {
    // Note: no actual "downgrade" — when v2 header absent, code falls back
    // to $this->version, NOT to v1 specifically. Server-wire-wins only
    // applies in the upgrade direction (PayingClient.php:58-65).
    $challenge = baseChallenge();
    $body = v1ChallengeBody([['scheme' => $challenge['scheme'], 'network' => $challenge['network'], 'maxAmountRequired' => '1000000', 'asset' => $challenge['asset'], 'payTo' => $challenge['payTo'], 'extra' => $challenge['extra']]]);
    $inner = new ScriptedHttpClient([
        new Response(402, [], $body),
        new Response(200, [], 'paid'),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V2);

    $client->sendRequest(makeRequest());

    expect($inner->sent[1]->hasHeader('PAYMENT-SIGNATURE'))->toBeTrue()
        ->and($inner->sent[1]->hasHeader('X-PAYMENT'))->toBeFalse();

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('PAYMENT-SIGNATURE'));

    expect($signed->x402Version)->toBe(2);
});

it('throws X402Exception when accepts[] is empty', function (): void {
    $inner = new ScriptedHttpClient([new Response(402, [], json_encode(['accepts' => []], JSON_THROW_ON_ERROR))]);
    $client = new PayingClient($inner, new StubWallet());

    $client->sendRequest(makeRequest());
})->throws(X402Exception::class, 'no challenges');

it('throws X402Exception on malformed challenge body JSON', function (): void {
    $inner = new ScriptedHttpClient([new Response(402, [], 'not-json{{{')]);
    $client = new PayingClient($inner, new StubWallet());

    $client->sendRequest(makeRequest());
})->throws(X402Exception::class, 'Could not decode 402 challenge body');

it('accepts the v1 wire maxAmountRequired field', function (): void {
    $inner = new ScriptedHttpClient([
        new Response(402, [], v1ChallengeBody([[
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'maxAmountRequired' => '5000000',
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'payTo' => '0x00000000000000000000000000000000000000ff',
            'extra' => ['name' => 'USD Coin', 'version' => '2'],
        ]])),
        new Response(200, [], ''),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V1);

    $client->sendRequest(makeRequest());

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('X-PAYMENT'));
    $authorization = JsonReader::arrayOrEmpty($signed->payload, 'authorization');

    expect(JsonReader::stringOrNull($authorization, 'value'))->toBe('5000000');
});

it('accepts the v2 wire amount field', function (): void {
    $inner = new ScriptedHttpClient([
        new Response(402, ['PAYMENT-REQUIRED' => v2ChallengeHeader([baseChallenge()])], ''),
        new Response(200, [], ''),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V2);

    $client->sendRequest(makeRequest());

    $signed = PaymentSignature::fromHeader($inner->sent[1]->getHeaderLine('PAYMENT-SIGNATURE'));
    $authorization = JsonReader::arrayOrEmpty($signed->payload, 'authorization');

    expect(JsonReader::stringOrNull($authorization, 'value'))->toBe('1000000');
});

it('throws X402Exception when EIP-712 domain is unresolvable', function (): void {
    // Network not in NetworkRegistry AND extra lacks name/version.
    $inner = new ScriptedHttpClient([new Response(402, ['PAYMENT-REQUIRED' => v2ChallengeHeader([
        baseChallenge(networkOverride: 'eip155:99999', extraOverride: []),
    ])], '')]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V2);

    $client->sendRequest(makeRequest());
})->throws(X402Exception::class, 'EIP-712 domain unresolved');

it('falls back to NetworkRegistry domain when extra omits name/version on a known network', function (): void {
    // Base Sepolia in registry — extra empty → domain comes from registry (USDC / 2).
    $inner = new ScriptedHttpClient([
        new Response(402, ['PAYMENT-REQUIRED' => v2ChallengeHeader([
            baseChallenge(networkOverride: 'eip155:84532', extraOverride: []),
        ])], ''),
        new Response(200, [], ''),
    ]);
    $client = new PayingClient($inner, new StubWallet(), version: Version::V2);

    $response = $client->sendRequest(makeRequest());

    expect($response->getStatusCode())->toBe(200)
        ->and($inner->sent)->toHaveCount(2);
});
