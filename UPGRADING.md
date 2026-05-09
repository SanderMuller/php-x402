# Upgrading

## From 0.2.x to 0.3.0

### `PaymentResponseCache` constructor now requires a `schemes` map

`PaymentResponseCache` previously derived its cache key from
`PaymentSignature::authorization()` (EIP-3009 only) with no scheme
input. Permit2 / Upto signatures had their nonces claimed by
`PaymentEnforcer` but no matching cache entry, so a dropped-response
retry could not be served idempotently. Numeric JSON `nonce` values
hit the same gap because `authorization()` uses `stringOrNull()` (no
coercion).

The constructor now takes the same `$schemes` map you pass to
`PaymentEnforcer` and uses each scheme's `replayKey()` (when the scheme
implements `ReplayKeyExtractor`) to build the cache key.

```diff
 $cache = new PaymentResponseCache(
     cache:           $psr16,
     responseFactory: $psr17,
     streamFactory:   $psr17,
+    schemes:         ['exact' => new ExactScheme()], // same map as PaymentEnforcer
     ttl:             3600,
 );
```

Pass the same map you wire into `PaymentEnforcer`. Schemes that don't
implement `ReplayKeyExtractor` (`Erc7710Scheme`, Stellar / Svm
`ExactScheme`) are filtered out internally and their requests fall
through to the inner handler — passing the full enforcer map is the
supported path, not a maintenance burden. The cache emits
`debug`-level log lines for each skip path so operators can detect
cache/enforcer drift by enabling debug logging temporarily; `warning`
is avoided because the cache sits on the unauthenticated edge and any
caller can trigger these paths with a malformed header.

### `PaymentResponseCache` no longer caches variant-specific responses

The previous `shouldCache()` accepted any 2xx with a PAYMENT-RESPONSE
header. 0.3.0 also skips:

- Status `206 Partial Content` (range request — replay would serve
  partial bytes to a full-body retry)
- Responses with `Content-Encoding`, `Content-Range`, `Accept-Ranges`,
  or `Vary` headers (content-negotiated representations — the cache
  key binds the signed authorization but not request `Range` /
  `Accept-Encoding` / negotiation inputs, so replaying a gzipped
  response to a non-gzip retry would corrupt the response)

Paid endpoints serving compressed / range / negotiated content keep
working — they just bypass the idempotent-replay cache. If a host
needs caching on these paths, they need a custom keying strategy that
includes the negotiation inputs (out of scope for this middleware).

### `PaymentResponseCache` snapshot strips request-tied response headers

Stored snapshots no longer include `Set-Cookie`, `Authorization`,
`Proxy-Authorization`, `Www-Authenticate`, or `Cookie` regardless of
allow-list. The hard-block also runs on the read path (`rebuild()`),
so any pre-0.3.0 snapshots in your persistent PSR-16 store get
sanitised on first hit after upgrade — no manual cache purge needed.

The default response-header allow-list is:

```
Content-Type, Content-Language, Content-Length, Content-Disposition,
Cache-Control, ETag, Last-Modified, Location,
X-PAYMENT-RESPONSE, PAYMENT-RESPONSE
```

Override via the `responseHeadersAllowList` constructor arg to keep
app-specific headers (e.g. CORS, custom `X-Request-Id`); the
hard-block list is enforced regardless.

### `SchemeContract` is now optionally extended by `ReplayKeyExtractor`

`X402\Schemes\ReplayKeyExtractor` is a new optional capability
interface. Schemes that opt in surface their `(from, nonce, expiresAt)`
triple for in-process replay claiming. **Custom `SchemeContract`
implementations do not need to change** — schemes that don't implement
`ReplayKeyExtractor` continue to defer replay protection to the
facilitator's on-chain nonce check (the same behavior 0.2.x had for
non-EIP-3009 schemes).

If you want in-process replay protection for a custom scheme, add
`implements ReplayKeyExtractor` and a `replayKey()` method. The
extractor MUST mirror the validation rules of your `verifyShape()` —
in particular, use `JsonReader::string()` (which coerces numeric JSON
to string) rather than `stringOrNull()` so a numeric `nonce` doesn't
silently skip the in-process claim while still settling.

### Built-in atomic `CallbackNonceStore`

`X402\Replay\CallbackNonceStore` is new — a closure-injected adapter
for any Redis-compatible client. Use this for production replay
protection instead of `Psr16NonceStore` (which cannot satisfy the
`NonceStoreContract` atomicity claim — see `0.2.1` notes).

```php
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$store = new CallbackNonceStore(
    static fn (string $key, int $ttl): bool
        => (bool) $redis->set($key, '1', ['NX', 'EX' => $ttl]),
);
```
