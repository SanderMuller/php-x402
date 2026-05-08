<?php

declare(strict_types=1);

namespace X402\Server;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
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
    public function __construct(
        private CacheInterface $cache,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private Version $version = Version::V1,
        private int $ttl = 3600,
        private string $prefix = 'x402:idem:',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->keyFor($request);

        if ($key === null) {
            return $handler->handle($request);
        }

        $cached = $this->cache->get($key);

        if (is_array($cached) && $this->isValidSnapshot($cached)) {
            return $this->rebuild($cached);
        }

        $response = $handler->handle($request);

        if ($this->shouldCache($response)) {
            $this->cache->set($key, $this->snapshot($response), $this->ttl);
            // Snapshotting consumed the body stream; rewind for the emitter.
            $response->getBody()->rewind();
        }

        return $response;
    }

    private function keyFor(ServerRequestInterface $request): ?string
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

        $auth = JsonReader::arrayOrEmpty($signature->payload, 'authorization');
        $from = JsonReader::stringOrNull($auth, 'from');
        $nonce = JsonReader::stringOrNull($auth, 'nonce');

        if ($from === null || $from === '' || $nonce === null || $nonce === '') {
            return null;
        }

        // Critical: the key MUST include the raw header bytes, not just
        // the (network, from, nonce) tuple. `from` and `nonce` become
        // public on-chain after settlement; an attacker who observes a
        // paid request could otherwise forge ANY header with that tuple,
        // hit the cache, and receive the cached protected response
        // without paying. Hashing the headerLine binds the cache entry
        // to the actual signed authorization bytes.
        return $this->prefix . hash(
            'sha256',
            $signature->network . '|' . strtolower($from) . '|' . strtolower($nonce) . '|' . $headerLine,
        );
    }

    /**
     * Verify the cached payload at least carries the keys we'll use to
     * rebuild a response. A poisoned / partially-corrupted cache entry
     * falls through to the handler instead of replaying garbage.
     *
     * @param  array<array-key, mixed>  $cached
     */
    private function isValidSnapshot(array $cached): bool
    {
        return isset($cached['status'], $cached['body'])
            && is_int($cached['status'])
            && $cached['status'] >= 100
            && $cached['status'] < 600
            && is_string($cached['body']);
    }

    private function shouldCache(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        // Only cache successful paid responses. 402 / 4xx must re-prompt
        // for payment on retry. 5xx is also not cached — the next
        // attempt deserves a fresh try at the inner handler.
        if ($status < 200 || $status >= 300) {
            return false;
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

        return [
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'headers' => $response->getHeaders(),
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

            $headerValues = is_array($values) ? array_values(array_filter($values, is_string(...))) : [];

            if ($headerValues !== []) {
                $response = $response->withHeader($name, $headerValues);
            }
        }

        $body = JsonReader::stringOrNull($stringKeyed, 'body') ?? '';

        return $response->withBody($this->streamFactory->createStream($body));
    }
}
