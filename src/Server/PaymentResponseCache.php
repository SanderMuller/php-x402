<?php

declare(strict_types=1);

namespace X402\Server;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\ReplayKeyExtractor;
use X402\Schemes\SchemeContract;
use X402\Support\JsonReader;

/**
 * PSR-15 middleware that closes the "paid but didn't receive content"
 * gap left open by `PaymentEnforcer`'s replay protection.
 *
 * Failure mode without this middleware:
 *   1. Client signs an EIP-3009 authorization, sends the request.
 *   2. PaymentEnforcer claims the nonce, settles via the facilitator,
 *      hands off to the inner handler, attaches PAYMENT-RESPONSE.
 *   3. The client's connection drops between settle-success and
 *      response-received. The user paid; they got nothing.
 *   4. Client retries with the same signed authorization.
 *   5. PaymentEnforcer's NonceStore rejects the duplicate (replay
 *      guard does its job), returns 402. Client is now confused
 *      and out the money.
 *
 * Fix: install this middleware **before** `PaymentEnforcer` in the
 * chain. It keys on `(network, from, nonce)` from the inbound payment
 * header, replays the cached prior success on duplicates, and only
 * caches responses that actually carried a PAYMENT-RESPONSE receipt
 * (so failed settlements don't poison the cache).
 *
 * **Storage contract:** any PSR-16 implementation. Use a Redis-backed
 * store in production — the same one you use for `Psr16NonceStore`.
 *
 * **TTL guidance:** default 1 hour. Should comfortably exceed the
 * nonce store TTL so a retry that arrives after the nonce expires
 * still hits the response cache. If your facilitator's authorizations
 * have a longer `validBefore` window, raise this.
 */
final readonly class PaymentResponseCache implements MiddlewareInterface
{
    /**
     * Default response headers retained in the cached snapshot. Anything
     * not on this list — most importantly `Set-Cookie` and `Authorization`
     * — is dropped so a stolen `X-PAYMENT` header replayed against this
     * cache cannot leak the original buyer's session cookies / bearer
     * tokens to the replayer.
     *
     * Header names are compared case-insensitively.
     */
    public const DEFAULT_RESPONSE_HEADER_ALLOWLIST = [
        // Representation-invariant body-shape headers.
        'Content-Type',
        'Content-Language',
        'Content-Length',
        'Content-Disposition',
        // Cache-coordination headers.
        'Cache-Control',
        'ETag',
        'Last-Modified',
        // 201 Created on paid POST flows. URL is not user-tied state.
        'Location',
        // v1 + v2 settlement receipts — the response is meaningless
        // without these, so they're always retained.
        'X-PAYMENT-RESPONSE',
        'PAYMENT-RESPONSE',
    ];

    /**
     * Response headers that signal the response is variant-specific
     * (content negotiation, range request, encoding). Caching these
     * would replay the wrong representation to a retry with different
     * negotiation inputs (e.g. gzip body served to a client that
     * didn't request gzip), and the cache key only binds the signed
     * authorization — not request `Range` / `Accept-Encoding` / etc.
     *
     * `shouldCache()` skips caching when any of these are present.
     * The allow-list does not include them either; the two layers are
     * belt-and-suspenders.
     */
    private const VARIANT_HEADERS = [
        'content-encoding',
        'content-range',
        'accept-ranges',
        'vary',
    ];

    /**
     * Headers that are NEVER stored in the snapshot regardless of the
     * configured allow-list. These carry user-tied state that, if
     * replayed against another viewer of the same `X-PAYMENT` header,
     * hands them a session under the original buyer's identity.
     */
    private const HARD_BLOCKED_HEADERS = [
        'set-cookie',
        'authorization',
        'proxy-authorization',
        'www-authenticate',
        'cookie',
    ];

    private LoggerInterface $logger;

    private Version $version;

    private int $ttl;

    private string $prefix;

    private Closure|ResourceResolver|null $resourceResolver;

    /** @var array<string, ReplayKeyExtractor> */
    private array $extractors;

    /** @var list<string> */
    private array $allowedHeadersLower;

    /**
     * @param  array<string, SchemeContract>  $schemes  Same map you wire into PaymentEnforcer. Entries that implement `ReplayKeyExtractor` (Evm `ExactScheme` / `Permit2Scheme`, `UptoEvmScheme`) get cache keying. Entries that don't (`Erc7710Scheme`, Stellar / Svm `ExactScheme`) are kept out of the internal extractor map so requests routed to them simply fall through to the inner handler with a `debug` log line — passing the full enforcer map is the supported path.
     * @param  PaymentResponseCacheOptions  $options  Optional knobs (`version`, `ttl`, `prefix`, `logger`, `responseHeadersAllowList`, `resourceResolver`). See `PaymentResponseCacheOptions` for per-field docs. Resource-resolver trade-off: any URI the resolver collapses will replay the SAME cached body, which is correct for paid endpoints whose response is fully determined by the resolved resource (e.g. `/api/v1/premium` and `/api/v2/premium` returning identical bytes), but wrong when those URIs return different content. When in doubt, leave the resolver default — pricing-collapse and content-collapse are different invariants.
     */
    public function __construct(
        private CacheInterface $cache,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $schemes,
        PaymentResponseCacheOptions $options = new PaymentResponseCacheOptions(),
    ) {
        $this->version = $options->version;
        $this->ttl = $options->ttl;
        $this->prefix = $options->prefix;
        $this->resourceResolver = $options->resourceResolver;

        // Filter to ReplayKeyExtractors silently. Adopters typically
        // pass the same map they wire into PaymentEnforcer, which can
        // legitimately include non-RKE schemes (Erc7710, Stellar, Svm)
        // that defer replay protection to the facilitator. Throwing
        // would force callers to maintain a separate filtered map and
        // re-introduce drift between the two middlewares — exactly
        // what consolidating the map was meant to prevent.
        $extractors = [];
        foreach ($schemes as $name => $scheme) {
            if ($scheme instanceof ReplayKeyExtractor) {
                $extractors[$name] = $scheme;
            }
        }

        $this->extractors = $extractors;

        // Normalize the allow-list to lowercase once so per-request
        // filtering is a hash lookup. Strip any caller-supplied entry
        // that overlaps the hard-block set so the hard-block can never
        // be opted out of.
        $this->allowedHeadersLower = array_values(array_diff(
            array_unique(array_map(strtolower(...), $options->responseHeadersAllowList)),
            self::HARD_BLOCKED_HEADERS,
        ));

        $this->logger = $options->logger ?? new NullLogger();
    }

    /**
     * Attribute name under which `PaymentResponseCache` stashes the
     * parsed `PaymentSignature` on the request. `PaymentEnforcer` reads
     * this attribute to skip a redundant `PaymentSignature::fromHeader`
     * parse on cache misses.
     */
    public const PARSED_SIGNATURE_ATTR = 'x402.parsed-signature';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsed = $this->parseAndKey($request);

        if ($parsed === null) {
            return $handler->handle($request);
        }

        ['key' => $key, 'signature' => $signature] = $parsed;

        $cached = $this->cache->get($key);

        if (is_array($cached) && $this->isValidSnapshot($cached)) {
            return $this->rebuild($cached);
        }

        // Stash the parsed signature so PaymentEnforcer (next in the
        // chain) can reuse it instead of re-parsing the header.
        $response = $handler->handle($request->withAttribute(self::PARSED_SIGNATURE_ATTR, $signature));

        if ($this->shouldCache($response)) {
            $this->cache->set($key, $this->snapshot($response), $this->ttl);
            // Snapshotting consumed the body stream; rewind for the emitter.
            $response->getBody()->rewind();
        }

        return $response;
    }

    /**
     * @return array{key: string, signature: PaymentSignature}|null
     */
    private function parseAndKey(ServerRequestInterface $request): ?array
    {
        $headerLine = $request->getHeaderLine($this->version->signatureHeader());

        if ($headerLine === '') {
            return null;
        }

        try {
            $signature = PaymentSignature::fromHeader($headerLine);
        } catch (Throwable) {
            // Malformed payment header → let PaymentEnforcer issue the
            // 400. Don't cache, don't pre-empt.
            return null;
        }

        // Use the scheme's own replay-key extractor — same source of
        // truth as PaymentEnforcer's nonce claim. Without this, schemes
        // whose payload uses non-`authorization` keys (Permit2 / Upto)
        // would have their nonces burned by the enforcer but no cache
        // entry to recover from on a dropped-response retry.
        // Constructor guaranteed every entry in $schemes is a
        // ReplayKeyExtractor; the only "scheme not handled" case at
        // request time is an unknown scheme name from public traffic.
        // Skip-cache paths are emitted at `debug` (not `warning`)
        // because this middleware sits on the unauthenticated edge and
        // any caller can send a payment header with an unknown scheme
        // — warning-level logs would be public-traffic-spoofable and
        // drown out the real drift signal.
        $scheme = $this->extractors[$signature->scheme] ?? null;
        if (! $scheme instanceof ReplayKeyExtractor) {
            $this->logger->debug('x402: response cache cannot derive replay key — scheme not registered; idempotent retry disabled for this request', [
                'scheme' => $signature->scheme,
                'network' => $signature->network,
                'registered' => array_keys($this->extractors),
            ]);

            return null;
        }

        try {
            $replayKey = $scheme->replayKey($signature);
        } catch (InvalidPaymentException $invalidPaymentException) {
            // Caller-side validation failure — bad public input or
            // extractor/payload skew (operator wired ExactScheme here
            // while PaymentEnforcer has Permit2Scheme under the same
            // scheme key). PaymentEnforcer will produce the actual 400.
            // Other exception types (LogicError, RuntimeException, …)
            // intentionally bubble up — those are extractor bugs that
            // should NOT masquerade as harmless cache skips.
            $this->logger->debug('x402: response cache extractor rejected payload — possible scheme/extractor drift or malformed input', [
                'scheme' => $signature->scheme,
                'network' => $signature->network,
                'extractor' => $scheme::class,
                'reason' => $invalidPaymentException->getMessage(),
            ]);

            return null;
        }

        if ($replayKey === null) {
            // Scheme implements ReplayKeyExtractor but signalled
            // "no replay key" for this signature.
            $this->logger->debug('x402: response cache extractor returned null — replay key not derivable; idempotent retry disabled for this request', [
                'scheme' => $signature->scheme,
                'network' => $signature->network,
                'extractor' => $scheme::class,
            ]);

            return null;
        }

        // Key derivation lives in `IdempotencyKeyBuilder` so JSON-RPC
        // transports (laravel-x402-mcp) can hash the same way. PSR-15
        // pins forge-resistance to the raw header bytes — an attacker
        // who observed a paid request cannot reproduce them without the
        // private key. JSON-RPC consumers pass the EIP-3009 signature
        // field instead, since `params._meta` is JSON-decoded by the
        // transport and the original bytes are no longer available.
        //
        // The `scope` mixes in HTTP method + request URI so a paid
        // response for `GET /premium-A` cannot be served back on
        // `GET /premium-B` (or `POST /premium-A`) just because the
        // same signed authorization was reused — `PaymentSignature`
        // does not bind the resource it was signed against, so the
        // cache key must do that work itself.
        $resource = $this->resolveResource($request);

        $key = IdempotencyKeyBuilder::build(
            network: $signature->network,
            from: $replayKey['from'],
            nonce: $replayKey['nonce'],
            bindingBytes: $headerLine,
            scope: [$request->getMethod(), $resource],
            prefix: $this->prefix,
        );

        return ['key' => $key, 'signature' => $signature];
    }

    /**
     * Verify the cached payload at least carries the keys we'll use to
     * rebuild a response. A poisoned / partially-corrupted cache entry
     * falls through to the handler instead of replaying garbage.
     *
     * Also rejects pre-0.3.0 snapshots that carry status 206 or any
     * variant-specific header — `shouldCache()` blocks new writes for
     * those, but persistent PSR-16 stores can still hold older entries
     * after upgrade. Failing closed on the read path closes that
     * window without requiring a manual cache purge.
     *
     * @param  array<array-key, mixed>  $cached
     */
    private function isValidSnapshot(array $cached): bool
    {
        if (! isset($cached['status'], $cached['body'])
            || ! is_int($cached['status'])
            || $cached['status'] < 100
            || $cached['status'] >= 600
            || ! is_string($cached['body'])) {
            return false;
        }

        if ($cached['status'] === 206) {
            return false;
        }

        $headers = $cached['headers'] ?? [];
        if (is_array($headers)) {
            foreach (array_keys($headers) as $name) {
                if (is_string($name) && in_array(strtolower($name), self::VARIANT_HEADERS, strict: true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function shouldCache(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        // Only cache successful paid responses. 402 / 4xx must re-prompt
        // for payment on retry. 5xx is also not cached — the next
        // attempt deserves a fresh try at the inner handler. 206 Partial
        // Content is rejected because the cache key doesn't bind the
        // request `Range`; replaying a 206 to a full-body retry would
        // serve a partial response.
        if ($status < 200 || $status >= 300 || $status === 206) {
            return false;
        }

        // Reject variant-specific representations. The cache key binds
        // the signed authorization but not request `Range` /
        // `Accept-Encoding` / Vary inputs, so replaying a gzipped
        // response to a non-gzip-capable retry, or a French
        // representation to an English retry, would corrupt the
        // dropped-response recovery guarantee.
        foreach (array_keys($response->getHeaders()) as $name) {
            if (! is_string($name)) {
                continue;
            }

            if (in_array(strtolower($name), self::VARIANT_HEADERS, strict: true)) {
                return false;
            }
        }

        // The PAYMENT-RESPONSE receipt is the proof that this 2xx came
        // from an enforcer-mediated settlement, not an unrelated free
        // resource accidentally pipelined behind the cache.
        return $response->hasHeader($this->version->responseHeader());
    }

    /**
     * The returned shape is intentionally loose — the cache backend may
     * round-trip it through serialize/unserialize, JSON, or igbinary.
     * `rebuild()` re-validates each field via JsonReader.
     *
     * @return array<string, mixed>
     */
    private function snapshot(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        // Filter at write time so the stored snapshot is already lean.
        // Read-path filtering (rebuild()) ALSO enforces the hard-block
        // for two reasons: (a) snapshots written by older versions of
        // this class may still be in a persistent PSR-16 store after
        // the upgrade, and (b) the hard-block list itself may grow in
        // future releases — applying at both ends keeps stale entries
        // from re-leaking sensitive headers.
        $filtered = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            if (in_array(strtolower($name), $this->allowedHeadersLower, strict: true)) {
                $filtered[$name] = $values;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'headers' => $filtered,
            'body' => $body,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $cached
     */
    private function rebuild(array $cached): ResponseInterface
    {
        $stringKeyed = array_filter($cached, is_string(...), \ARRAY_FILTER_USE_KEY);

        $response = $this->responseFactory->createResponse(
            JsonReader::int($stringKeyed, 'status', default: 200),
            JsonReader::stringOrNull($stringKeyed, 'reason') ?? '',
        );

        $headers = JsonReader::arrayOrEmpty($stringKeyed, 'headers');

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            // Hard-block on the read path too. Snapshots written by
            // pre-0.3.0 versions (or by a future version with a wider
            // hard-block) may carry Set-Cookie / Authorization / etc.;
            // strip them on rebuild so an upgrade closes the leak
            // window immediately rather than waiting for cache TTL.
            if (in_array(strtolower($name), self::HARD_BLOCKED_HEADERS, strict: true)) {
                continue;
            }

            $headerValues = is_array($values) ? array_values(array_filter($values, is_string(...))) : [];

            if ($headerValues !== []) {
                $response = $response->withHeader($name, $headerValues);
            }
        }

        $body = JsonReader::stringOrNull($stringKeyed, 'body') ?? '';

        return $response->withBody($this->streamFactory->createStream($body));
    }

    private function resolveResource(ServerRequestInterface $request): string
    {
        return InvokeResourceResolver::resolve($this->resourceResolver, $request, self::class);
    }
}
