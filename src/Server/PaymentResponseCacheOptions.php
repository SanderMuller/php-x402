<?php

declare(strict_types=1);

namespace X402\Server;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use X402\Protocol\Version;

/**
 * Optional knobs for `PaymentResponseCache`. Bundled into a single DTO
 * so the middleware constructor stays at a tractable shape (4 required
 * args + 1 options arg) and so adding future knobs does not become
 * itself a breaking change for positional / named-arg callers.
 *
 * Construct with named args:
 *
 * ```php
 * new PaymentResponseCacheOptions(
 *     ttl: 7200,
 *     prefix: 'app:idem:',
 *     responseHeadersAllowList: [...PaymentResponseCache::DEFAULT_RESPONSE_HEADER_ALLOWLIST, 'X-Request-Id'],
 * );
 * ```
 */
final readonly class PaymentResponseCacheOptions
{
    /**
     * @param  list<string>  $responseHeadersAllowList  Response header names retained in the cached snapshot. Compared case-insensitively. Override the default to keep app-specific headers (e.g. CORS); the hard-block list (`set-cookie`, `authorization`, …) is enforced regardless.
     * @param  Closure(ServerRequestInterface): string|ResourceResolver|null  $resourceResolver  Optional. Resolves a request to the cache-identity string mixed into the response-cache key alongside HTTP method. Default = `$request->getUri()->getPath()` (matches `PaymentEnforcer`'s default; a paid retry on the same URI hits the cache). Pass a custom resolver only when you want pricing-equivalent URIs to also share cached responses — see `PaymentResponseCache` constructor docs for the trade-offs.
     */
    public function __construct(
        public Version $version = Version::V1,
        public int $ttl = 3600,
        public string $prefix = 'x402:idem:',
        public ?LoggerInterface $logger = null,
        public array $responseHeadersAllowList = PaymentResponseCache::DEFAULT_RESPONSE_HEADER_ALLOWLIST,
        public Closure|ResourceResolver|null $resourceResolver = null,
    ) {}
}
