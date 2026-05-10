<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\SimpleCache\CacheInterface;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\Evm\ExactScheme;
use X402\Schemes\Evm\Permit2Scheme;
use X402\Schemes\SchemeContract;
use X402\Server\IdempotencyKeyBuilder;
use X402\Server\PaymentResponseCache;
use X402\Server\PaymentResponseCacheOptions;

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
            'authorization' => ['from' => $from, 'nonce' => $nonce, 'validBefore' => 9999999999],
            'signature' => '0xsig',
        ],
    );

    return (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), $signature->toHeader());
}

it('passes through when no payment header is present', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);
    $handler = new CountingHandler(new PsrResponse(200));

    $middleware->process(new ServerRequest('GET', '/premium'), $handler);

    expect($handler->calls)->toBe(1)
        ->and($cache->store)->toBe([]);
});

it('caches a paid 200 response and replays it on duplicate auth', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);

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
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);
    $handler = new CountingHandler(new PsrResponse(402, [], 'payment required'));

    $middleware->process(signedPaymentRequest(), $handler);

    expect($cache->store)->toBe([]);
});

it('does not cache 200 responses without a PAYMENT-RESPONSE receipt', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);
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
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);
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
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);
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
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);

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
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce', 'validBefore' => 9999999999],
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
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce', 'validBefore' => 9999999999],
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
    $middleware = new PaymentResponseCache(cache: $cache, responseFactory: $factory, streamFactory: $factory, schemes: ['exact' => new ExactScheme()]);

    // Pre-poison the cache with garbage at the key for our request.
    $request = signedPaymentRequest();
    $headerLine = $request->getHeaderLine(Version::V1->signatureHeader());
    $key = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xabc',
        bindingBytes: $headerLine,
        scope: ['GET', (string) $request->getUri()],
    );
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

it('caches Permit2 paid responses (regression — pre-0.3.0 this missed)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new Permit2Scheme()],
    );

    $paid = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'permit2-response',
    );
    $handler = new CountingHandler($paid);

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xsig',
            'permit2Authorization' => [
                'from' => '0xpermit2from',
                'nonce' => '0xpermit2nonce',
                'deadline' => 9999999999,
            ],
        ],
    );
    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), $signature->toHeader());

    $middleware->process($request, $handler);
    $second = $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)                                            // cached on retry
        ->and((string) $second->getBody())->toBe('permit2-response');
});

it('caches numeric-nonce exact-scheme responses (regression — stringOrNull would have missed)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'numeric-nonce-response',
    );
    $handler = new CountingHandler($paid);

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xsig',
            'authorization' => [
                'from' => '0xfrom',
                'nonce' => 12345, // numeric JSON
                'validBefore' => 9999999999,
            ],
        ],
    );
    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), $signature->toHeader());

    $middleware->process($request, $handler);
    $second = $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)
        ->and((string) $second->getBody())->toBe('numeric-nonce-response');
});

it('warns and skips cache when scheme is registered but extractor cannot parse the payload (drift detection)', function (): void {
    // Operator wired ExactScheme into the cache but the inbound
    // header carries a Permit2 payload. Outer scheme key matches
    // ('exact'), so $schemes[scheme] lookup succeeds, but
    // ExactScheme::replayKey throws on the missing `authorization`
    // field. Without an explicit warning, the cache silently drops
    // this — and a dropped-response retry would 402 as a replay.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();

    /** @var list<array{level: string, message: string}> */
    $logged = [];
    $logger = new class ($logged) extends AbstractLogger {
        /** @param  list<array{level: string, message: string}>  $logged */
        public function __construct(public array &$logged) {}

        /** @param  array<array-key, mixed>  $context */
        public function log(mixed $level, Stringable|string $message, array $context = []): void
        {
            $this->logged[] = [
                'level' => is_string($level) ? $level : 'unknown',
                'message' => (string) $message,
            ];
        }
    };

    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()], // wrong extractor for Permit2 payloads
        options: new PaymentResponseCacheOptions(logger: $logger),
    );
    $handler = new CountingHandler(new PsrResponse(200));

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xsig',
            'permit2Authorization' => ['from' => '0xf', 'nonce' => '0xn', 'deadline' => 9999999999],
        ],
    );
    $request = (new ServerRequest('GET', '/premium'))
        ->withHeader(Version::V1->signatureHeader(), $signature->toHeader());

    $middleware->process($request, $handler);

    expect($logged)->not->toBe([])
        ->and($logged[0]['level'])->toBe('debug')
        ->and($logged[0]['message'])->toContain('extractor rejected payload');
});

it('does not emit warning-level logs from public traffic (drift signal stays at debug)', function (): void {
    // Cache sits on the unauthenticated edge — an attacker can send any
    // X-PAYMENT header. Drift detection fires on every skip path, but
    // must stay at debug so warning-level logs are not spoofable from
    // public traffic.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();

    /** @var list<array{level: string, message: string}> */
    $logged = [];
    $logger = new class ($logged) extends AbstractLogger {
        /** @param  list<array{level: string, message: string}>  $logged */
        public function __construct(public array &$logged) {}

        /** @param  array<array-key, mixed>  $context */
        public function log(mixed $level, Stringable|string $message, array $context = []): void
        {
            $this->logged[] = [
                'level' => is_string($level) ? $level : 'unknown',
                'message' => (string) $message,
            ];
        }
    };

    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
        options: new PaymentResponseCacheOptions(logger: $logger),
    );
    $handler = new CountingHandler(new PsrResponse(200));

    // Public traffic with an unknown scheme name — would have hit the
    // "not registered" branch.
    $unknownScheme = (new PaymentSignature(
        scheme: 'attacker-spoofed-scheme',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'authorization' => ['from' => '0xa', 'nonce' => '0xb', 'validBefore' => 9999999999]],
    ))->toHeader();
    $middleware->process(
        (new ServerRequest('GET', '/premium'))->withHeader(Version::V1->signatureHeader(), $unknownScheme),
        $handler,
    );

    // Public traffic with the right scheme key but mismatched payload —
    // would have hit the "extractor throws" branch.
    $skewed = (new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['signature' => '0xsig', 'permit2Authorization' => ['from' => '0xa', 'nonce' => '0xb']],
    ))->toHeader();
    $middleware->process(
        (new ServerRequest('GET', '/premium'))->withHeader(Version::V1->signatureHeader(), $skewed),
        $handler,
    );

    $warningLevels = array_values(array_filter($logged, static fn (array $entry): bool => $entry['level'] === 'warning'));

    expect($warningLevels)->toBe([]);
});

it('strips Set-Cookie and Authorization from the cached snapshot (privacy)', function (): void {
    // Without the allow-list filter, a replay of the same X-PAYMENT
    // header (e.g. stolen from logs) would hand the replayer the
    // original buyer's session cookies. Hard-block these regardless of
    // allow-list.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        200,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Content-Type' => 'application/json',
            'Set-Cookie' => 'session=secret-buyer-session',
            'Authorization' => 'Bearer secret-buyer-token',
        ],
        '{"data":1}',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $replay = $middleware->process(signedPaymentRequest(), $handler);

    expect($replay->hasHeader('Set-Cookie'))->toBeFalse()
        ->and($replay->hasHeader('Authorization'))->toBeFalse()
        ->and($replay->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($replay->getHeaderLine(Version::V1->responseHeader()))->toBe('eyJ4IjoxfQ==');
});

it('hard-blocks Set-Cookie even when caller adds it to the allow-list', function (): void {
    // Callers can extend the allow-list (e.g. for ETag) but cannot
    // remove the hard-block — Set-Cookie / Authorization stay dropped
    // regardless of constructor input.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
        options: new PaymentResponseCacheOptions(
            responseHeadersAllowList: ['Content-Type', 'Set-Cookie', 'Authorization'], // attacker / mistake
        ),
    );

    $paid = new PsrResponse(
        200,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Set-Cookie' => 'session=secret',
        ],
        'paid',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $replay = $middleware->process(signedPaymentRequest(), $handler);

    expect($replay->hasHeader('Set-Cookie'))->toBeFalse();
});

it('honours an extended allow-list for app-specific headers (e.g. ETag)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
        options: new PaymentResponseCacheOptions(
            responseHeadersAllowList: [...PaymentResponseCache::DEFAULT_RESPONSE_HEADER_ALLOWLIST, 'X-Request-Id'],
        ),
    );

    $paid = new PsrResponse(
        200,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'X-Request-Id' => 'req-abc',
        ],
        'paid',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $replay = $middleware->process(signedPaymentRequest(), $handler);

    expect($replay->getHeaderLine('X-Request-Id'))->toBe('req-abc');
});

it('accepts mixed scheme maps (RKE + non-RKE), filters non-RKEs out internally', function (): void {
    // Adopters pass the same map they wire into PaymentEnforcer, which
    // legitimately includes non-RKE schemes (Erc7710, Stellar, Svm)
    // that defer replay protection to the facilitator. The cache
    // filters them out silently and serves them as cache-miss
    // pass-throughs, rather than throwing at boot.
    $factory = new Psr17Factory();
    $cache = new IdempotencyArrayCache();

    $nonRke = new class implements SchemeContract {
        public function name(): string
        {
            return 'custom';
        }

        /**
         * @return list<string>
         */
        public function supportedNetworks(): array
        {
            return [];
        }

        public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void {}
    };

    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme(), 'custom' => $nonRke],
    );

    // exact (RKE) still caches; custom (non-RKE) falls through.
    expect($middleware)->toBeInstanceOf(PaymentResponseCache::class);
});

it('does not cache responses with Content-Encoding (variant-specific representation)', function (): void {
    // Cached gzipped body would replay to a non-gzip-capable retry as
    // garbled data — cache key doesn't bind request Accept-Encoding.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        200,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
        ],
        'gzipped-bytes',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $middleware->process(signedPaymentRequest(), $handler);

    expect($handler->calls)->toBe(2)
        ->and($cache->store)->toBe([]);
});

it('does not cache 206 Partial Content responses (range mismatch on retry)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        206,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Content-Range' => 'bytes 0-499/1000',
        ],
        'partial',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $middleware->process(signedPaymentRequest(), $handler);

    expect($handler->calls)->toBe(2)
        ->and($cache->store)->toBe([]);
});

it('does not cache responses with Vary header (content-negotiated representation)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        200,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Vary' => 'Accept-Language',
        ],
        'bonjour',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $middleware->process(signedPaymentRequest(), $handler);

    expect($handler->calls)->toBe(2)
        ->and($cache->store)->toBe([]);
});

it('strips hard-blocked headers on rebuild from stale (pre-0.3.0) cached snapshots', function (): void {
    // Simulate a snapshot written by an older version that still
    // stored Set-Cookie + Authorization. After upgrade, rebuild() must
    // drop them on the read path so the leak window closes immediately
    // rather than persisting until TTL expiry.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );
    $handler = new CountingHandler(new PsrResponse(500)); // shouldn't be hit

    // Build the cache key the middleware would use. Goes through
    // IdempotencyKeyBuilder so this test stays correct as the key
    // shape evolves — pinning the raw `sha256(...)` preimage would
    // mean every cache-key refactor silently invalidates the test's
    // intent (we want to test rebuild()'s hard-block, not the hash
    // derivation).
    $request = signedPaymentRequest(nonce: '0xstale-nonce');
    $headerLine = $request->getHeaderLine(Version::V1->signatureHeader());
    $key = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xstale-nonce',
        bindingBytes: $headerLine,
        scope: ['GET', (string) $request->getUri()],
    );

    // Inject a stale snapshot with sensitive headers (as a pre-0.3.0
    // version would have written).
    $cache->store[$key] = [
        'status' => 200,
        'reason' => 'OK',
        'headers' => [
            'Content-Type' => ['application/json'],
            'X-PAYMENT-RESPONSE' => ['eyJ4IjoxfQ=='],
            'Set-Cookie' => ['session=secret-old-buyer'],
            'Authorization' => ['Bearer secret-old-token'],
        ],
        'body' => '{"data":1}',
    ];

    $response = $middleware->process($request, $handler);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->hasHeader('Set-Cookie'))->toBeFalse()
        ->and($response->hasHeader('Authorization'))->toBeFalse()
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json');
});

it('preserves Location header for paid POST 201 responses (allow-list covers 2xx semantics)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $paid = new PsrResponse(
        201,
        [
            Version::V1->responseHeader() => 'eyJ4IjoxfQ==',
            'Location' => '/created/42',
            'Content-Type' => 'application/json',
        ],
        '{"id":42}',
    );
    $handler = new CountingHandler($paid);

    $middleware->process(signedPaymentRequest(), $handler);
    $replay = $middleware->process(signedPaymentRequest(), $handler);

    expect($replay->getStatusCode())->toBe(201)
        ->and($replay->getHeaderLine('Location'))->toBe('/created/42');
});

it('rejects pre-0.3.0 cached snapshots with 206 status (fails closed on read path)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );
    $handler = new CountingHandler(new PsrResponse(200, [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='], 'fresh'));

    $request = signedPaymentRequest(nonce: '0xstale-206');
    $headerLine = $request->getHeaderLine(Version::V1->signatureHeader());
    $key = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xstale-206',
        bindingBytes: $headerLine,
        scope: ['GET', (string) $request->getUri()],
    );

    $cache->store[$key] = [
        'status' => 206,
        'reason' => 'Partial Content',
        'headers' => ['Content-Range' => ['bytes 0-499/1000']],
        'body' => 'partial',
    ];

    $response = $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)                                            // stale 206 rejected → handler runs
        ->and((string) $response->getBody())->toBe('fresh');
});

it('rejects pre-0.3.0 cached snapshots with variant headers (Content-Encoding)', function (): void {
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );
    $handler = new CountingHandler(new PsrResponse(200, [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='], 'fresh'));

    $request = signedPaymentRequest(nonce: '0xstale-gzip');
    $headerLine = $request->getHeaderLine(Version::V1->signatureHeader());
    $key = IdempotencyKeyBuilder::build(
        network: 'eip155:8453',
        from: '0xfrom',
        nonce: '0xstale-gzip',
        bindingBytes: $headerLine,
        scope: ['GET', (string) $request->getUri()],
    );

    $cache->store[$key] = [
        'status' => 200,
        'reason' => 'OK',
        'headers' => ['Content-Encoding' => ['gzip'], 'Content-Type' => ['application/json']],
        'body' => 'gzipped-bytes',
    ];

    $response = $middleware->process($request, $handler);

    expect($handler->calls)->toBe(1)
        ->and((string) $response->getBody())->toBe('fresh');
});

it('does not replay a paid response across different request URIs even with the same X-PAYMENT', function (): void {
    // A signed `PaymentSignature` does not bind the resource URI it was
    // signed against — it only commits to (network, scheme, payload).
    // Without route scoping in the cache key, a paid response for
    // `GET /premium-A` could be served on `GET /premium-B` if both
    // routes share the same payTo/asset/amount and a caller reuses
    // the signed authorization. PaymentEnforcer would normally reject
    // the replayed nonce, but the cache lookup runs before the
    // enforcer — so a route-agnostic cache hit returns A's body
    // before B's challenge match check ever runs.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xshared', 'validBefore' => 9999999999],
            'signature' => '0xsig',
        ],
    );
    $headerValue = $signature->toHeader();

    $aResponse = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJhIjoxfQ=='],
        'route-A-body',
    );
    $bResponse = new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJiIjoyfQ=='],
        'route-B-body',
    );

    $aHandler = new CountingHandler($aResponse);
    $bHandler = new CountingHandler($bResponse);

    $aRequest = (new ServerRequest('GET', '/premium-A'))
        ->withHeader(Version::V1->signatureHeader(), $headerValue);
    $bRequest = (new ServerRequest('GET', '/premium-B'))
        ->withHeader(Version::V1->signatureHeader(), $headerValue);

    $middleware->process($aRequest, $aHandler);
    $bResult = $middleware->process($bRequest, $bHandler);

    expect($bHandler->calls)->toBe(1)                                            // B's handler ran (cache miss)
        ->and((string) $bResult->getBody())->toBe('route-B-body')                // B's body returned
        ->and($cache->store)->toHaveCount(2);                                    // distinct cache entries per route
});

it('does not replay a paid response across different HTTP methods on the same URI', function (): void {
    // Same defense, different axis: GET /resource and POST /resource
    // are distinct operations even when the signed payment is identical.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();
    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
    );

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xshared', 'validBefore' => 9999999999],
            'signature' => '0xsig',
        ],
    );
    $headerValue = $signature->toHeader();

    $getHandler = new CountingHandler(new PsrResponse(200, [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='], 'GET-body'));
    $postHandler = new CountingHandler(new PsrResponse(201, [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='], 'POST-body'));

    $middleware->process(
        (new ServerRequest('GET', '/resource'))->withHeader(Version::V1->signatureHeader(), $headerValue),
        $getHandler,
    );
    $postResult = $middleware->process(
        (new ServerRequest('POST', '/resource'))->withHeader(Version::V1->signatureHeader(), $headerValue),
        $postHandler,
    );

    expect($postHandler->calls)->toBe(1)
        ->and((string) $postResult->getBody())->toBe('POST-body')
        ->and($postResult->getStatusCode())->toBe(201);
});

it('uses the configured resourceResolver so cache scoping aligns with PaymentEnforcer', function (): void {
    // PaymentEnforcer prices on resolveResource(); PaymentResponseCache
    // MUST hash the same logical resource so a dropped-response retry
    // hits the cache. If the cache used raw URI but the enforcer used
    // a normalized path, equivalent retries (host alias, query
    // normalization, custom name-based resolver) would miss the
    // cache and 402 as a replay.
    $cache = new IdempotencyArrayCache();
    $factory = new Psr17Factory();

    // Resolver maps anything under `/api/v\d+/premium` to a single
    // logical resource — what the enforcer would also do.
    $resolver = (static fn (ServerRequestInterface $request): string => preg_replace('#^/api/v\d+/#', '/api/', $request->getUri()->getPath()) ?? $request->getUri()->getPath());

    $middleware = new PaymentResponseCache(
        cache: $cache,
        responseFactory: $factory,
        streamFactory: $factory,
        schemes: ['exact' => new ExactScheme()],
        options: new PaymentResponseCacheOptions(resourceResolver: $resolver),
    );

    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xshared', 'validBefore' => 9999999999],
            'signature' => '0xsig',
        ],
    );
    $headerValue = $signature->toHeader();

    $handler = new CountingHandler(new PsrResponse(
        200,
        [Version::V1->responseHeader() => 'eyJ4IjoxfQ=='],
        'paid',
    ));

    // Two URIs that the resolver collapses to the same logical resource.
    $v1 = (new ServerRequest('GET', '/api/v1/premium'))->withHeader(Version::V1->signatureHeader(), $headerValue);
    $v2 = (new ServerRequest('GET', '/api/v2/premium'))->withHeader(Version::V1->signatureHeader(), $headerValue);

    $middleware->process($v1, $handler);
    $middleware->process($v2, $handler);

    expect($handler->calls)->toBe(1)                                            // resolver collapsed to same resource → cache hit on retry
        ->and($cache->store)->toHaveCount(1);
});
