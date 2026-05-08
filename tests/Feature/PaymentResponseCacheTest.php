<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Server\PaymentResponseCache;

final class IdempotencyArrayCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * @return array{}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param  iterable<mixed>  $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    /**
     * @param  iterable<mixed>  $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}

final class CountingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        return $this->response;
    }
}

function signedPaymentRequest(string $nonce = '0xabc', string $from = '0xfrom'): ServerRequestInterface
{
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => $from, 'nonce' => $nonce],
            'signature' => '0xsig',
        ],
    );

    return (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), $signature->toHeader());
}

it('passes through when no payment header is present', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);
    $handler = new CountingHandler(new PsrResponse(200));

    $middleware->process(new ServerRequest('GET', '/premium'), $handler);

    expect($handler->calls)->toBe(1)
        ->and($cache->store)->toBe([]);
});

it('caches a paid 200 response and replays it on duplicate auth', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);

    $paidResponse = new PsrResponse(
        200,
        ['Content-Type' => 'text/plain', Version::V1->responseHeader() => 'eyJ0eCI6IjB4Li4uIn0='],
        'protected resource',
    );
    $handler = new CountingHandler($paidResponse);

    $first = $middleware->process(signedPaymentRequest(), $handler);
    $second = $middleware->process(signedPaymentRequest(), $handler);

    expect($handler->calls)->toBe(1)                                            // inner handler hit ONCE total
        ->and($first->getStatusCode())->toBe(200)
        ->and((string) $second->getBody())->toBe('protected resource')
        ->and($second->getHeaderLine(Version::V1->responseHeader()))->toBe('eyJ0eCI6IjB4Li4uIn0=');
});

it('does not cache 402 responses', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);
    $handler = new CountingHandler(new PsrResponse(402, [], 'payment required'));

    $middleware->process(signedPaymentRequest(), $handler);

    expect($cache->store)->toBe([]);
});

it('does not cache 200 responses without a PAYMENT-RESPONSE receipt', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);
    // 200 OK but no PAYMENT-RESPONSE header — the request happened to
    // carry a payment header but the response wasn't enforcer-mediated
    // (e.g. the route was accidentally free).
    $handler = new CountingHandler(new PsrResponse(200, ['Content-Type' => 'text/plain'], 'free'));

    $middleware->process(signedPaymentRequest(), $handler);

    expect($cache->store)->toBe([]);
});

it('keys per nonce — different nonces are independent', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);
    $handler = new CountingHandler(new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'paid',
    ));

    $middleware->process(signedPaymentRequest(nonce: '0xnonce-A'), $handler);
    $middleware->process(signedPaymentRequest(nonce: '0xnonce-B'), $handler);

    expect($handler->calls)->toBe(2)
        ->and($cache->store)->toHaveCount(2);
});

it('skips on a malformed payment header — lets PaymentEnforcer issue the 400', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);
    $handler = new CountingHandler(new PsrResponse(400));

    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), 'not-a-valid-base64-payload!!!');

    $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)
        ->and($cache->store)->toBe([]);
});

it('does not replay when the signature differs but tuple is identical (forge guard)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);

    $paid = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'protected',
    );
    $handler = new CountingHandler($paid);

    // Legit request paid + cached.
    $legit = (new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce'],
            'signature' => '0xLEGIT-SIG',
        ],
    ))->toHeader();

    $middleware->process(
        (new ServerRequest('GET', '/premium'))->withHeader(Version::V1->signatureHeader(), $legit),
        $handler,
    );

    // Attacker constructs a header with the SAME from + nonce but a
    // forged signature. Different bytes → different cache key → miss →
    // inner handler runs again (in production: PaymentEnforcer rejects
    // because the nonce store has already claimed it).
    $forged = (new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce'],
            'signature' => '0xATTACKER-FORGED',
        ],
    ))->toHeader();

    $middleware->process(
        (new ServerRequest('GET', '/premium'))->withHeader(Version::V1->signatureHeader(), $forged),
        $handler,
    );

    expect($handler->calls)->toBe(2)                                            // forged path NOT served from cache
        ->and($cache->store)->toHaveCount(2);                                   // distinct cache entries
});

it('falls through to handler when the cached snapshot is malformed', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory);

    // Pre-poison the cache with garbage at the key for our request.
    $request = signedPaymentRequest();
    $headerLine = $request->getHeaderLine(Version::V1->signatureHeader());
    $key = 'x402:idem:' . hash('sha256', 'eip155:8453|0xfrom|0xabc|' . $headerLine);
    $cache->store[$key] = ['this is not a valid snapshot', 999];                // bogus shape

    $paid = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'fresh-response',
    );
    $handler = new CountingHandler($paid);

    $response = $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)                                            // not served from poisoned cache
        ->and((string) $response->getBody())->toBe('fresh-response');
});
