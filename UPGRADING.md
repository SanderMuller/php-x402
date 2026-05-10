# Upgrading

## From 0.6.x to 0.7.0

### `PaymentResponseCache` constructor — optional knobs moved into a `PaymentResponseCacheOptions` DTO

The 10-parameter constructor was unwieldy and adding a further knob was itself a breaking change for positional and named-arg callers. 0.7.0 collapses the six optional parameters (`version`, `ttl`, `prefix`, `logger`, `responseHeadersAllowList`, `resourceResolver`) into a single `PaymentResponseCacheOptions` DTO.

The four required arguments (`cache`, `responseFactory`, `streamFactory`, `schemes`) are unchanged. Only callers that passed any of the six optional knobs need to update.

```diff
 use X402\Server\PaymentResponseCache;
+use X402\Server\PaymentResponseCacheOptions;

 $cache = new PaymentResponseCache(
     cache:           $psr16,
     responseFactory: $psr17,
     streamFactory:   $psr17,
     schemes:         ['exact' => new ExactScheme()],
-    ttl:             7200,
-    prefix:          'app:idem:',
-    logger:          $logger,
+    options: new PaymentResponseCacheOptions(
+        ttl:    7200,
+        prefix: 'app:idem:',
+        logger: $logger,
+    ),
 );
```

Callers passing zero optional knobs (just the four required arguments) need no change. The `DEFAULT_RESPONSE_HEADER_ALLOWLIST` and `PARSED_SIGNATURE_ATTR` constants stay on `PaymentResponseCache`; reference them as before.

## From 0.3.x to 0.4.0

### `PaymentResponseCache` cache-key format changed — purge or accept a one-time miss window

0.4.0 changes the idempotency-cache key derivation in two ways:

1. The serialisation switched from `implode('|', …)` to `json_encode(…)` so caller-supplied scope strings can't collide via embedded delimiters.
2. The PSR-15 cache key now mixes in HTTP method + resolved resource (URI path by default), preventing cross-route replay of cached paid responses.

Both changes invalidate the on-disk format: cached entries written by 0.3.x are unreadable by 0.4.0. Two options on upgrade:

- **Purge the cache** before deploying 0.4.0. Recommended when you can take the operational hit. Adopters using a per-app prefix can `redis-cli del` the namespace.
- **Accept a transient cache-miss window**. Stale 0.3.x entries become invisible; in-flight retries during the deploy that would have hit the old cache fall through to `PaymentEnforcer`. If the original nonce was already claimed, the retry returns 402. Affected users were already at risk on the 0.3.x path; this isn't worse, just different.

For mixed-version rolling deploys against a shared cache, the old/new node pair will not see each other's writes during the rollout window. Plan accordingly — drain in-flight before flipping, or use distinct prefixes per release.

### `PaymentEnforcer` BC fallback for non-`ReplayKeyExtractor` EIP-3009 schemes is removed

> [!CAUTION]
> **Silent security-behavior change.** Custom `SchemeContract`
> implementations that validate an EIP-3009-shaped `payload.authorization`
> on `eip155:*` networks lost their in-process replay protection in
> 0.4.0. Settlements still succeed, so no error surfaces — but the
> nonce store is no longer claimed for those requests, so replay
> protection collapses back to whatever the facilitator enforces.
>
> 0.3.1 logged a `warning`-level deprecation line whenever this path
> fired (`x402: PaymentEnforcer BC fallback in use …`). Grep your
> production logs for `BC fallback in use` before upgrading. Any
> scheme class named there must implement `ReplayKeyExtractor` to
> retain in-process protection.

To migrate a custom scheme:

```diff
+use X402\Schemes\ReplayKeyExtractor;
+
-final class MyExactScheme implements SchemeContract
+final class MyExactScheme implements ReplayKeyExtractor, SchemeContract
 {
     public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void
     {
         // …existing JsonReader::string($auth, 'from', …) etc.
     }
+
+    /**
+     * @return array{from: string, nonce: string, expiresAt: int}
+     */
+    public function replayKey(PaymentSignature $signature): array
+    {
+        $auth = JsonReader::array($signature->payload, 'authorization', 'my exact payload');
+
+        return [
+            'from'      => JsonReader::string($auth, 'from', 'my exact authorization'),
+            'nonce'     => JsonReader::string($auth, 'nonce', 'my exact authorization'),
+            'expiresAt' => JsonReader::int($auth, 'validBefore', context: 'my exact authorization'),
+        ];
+    }
 }
```

`replayKey()` MUST mirror the field-reading rules of `verifyShape()` —
in particular, use `JsonReader::string()` (which coerces numeric JSON
to string) rather than `stringOrNull()` so a numeric `nonce` doesn't
silently skip the in-process claim while still settling. See
`X402\Schemes\Evm\ExactScheme::replayKey()` for the canonical EIP-3009
implementation.

Schemes that genuinely defer replay protection to the facilitator's
on-chain nonce check (no `payload.authorization` shape) are
unaffected. Built-in `Evm\ExactScheme`, `Evm\Permit2Scheme`, and
`Upto\UptoEvmScheme` already implement `ReplayKeyExtractor` since
0.3.0 and require no migration.

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
triple for in-process replay claiming.

> [!IMPORTANT]
> **Custom `SchemeContract` implementations that validate an
> EIP-3009-shaped `payload.authorization` on `eip155:*` networks**
> got in-process replay protection in 0.3.x via a BC fallback in
> `PaymentEnforcer::guardReplay()`. **That fallback is removed in
> 0.4.0** — see the [0.3.x → 0.4.0 section](#from-03x-to-040) above
> for the migration diff. If you operate a custom EIP-3009-shaped
> scheme, plan the `ReplayKeyExtractor` migration before upgrading
> past 0.3.x.

If you want in-process replay protection for a custom scheme, add
`implements ReplayKeyExtractor` and a `replayKey()` method. The
extractor MUST mirror the validation rules of your `verifyShape()` —
in particular, use `JsonReader::string()` (which coerces numeric JSON
to string) rather than `stringOrNull()` so a numeric `nonce` doesn't
silently skip the in-process claim while still settling.

Schemes that genuinely defer replay protection to the facilitator's
on-chain nonce check (no `payload.authorization` shape, or chains
where on-chain sequence is the source of truth) keep working without
implementing `ReplayKeyExtractor` — they're outside the migration
scope.

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
