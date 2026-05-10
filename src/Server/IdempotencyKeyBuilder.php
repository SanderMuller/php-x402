<?php

declare(strict_types=1);

namespace X402\Server;

use InvalidArgumentException;

/**
 * Transport-agnostic builder for paid-response idempotency cache keys.
 *
 * Two consumers share this:
 *
 *   - `PaymentResponseCache` (PSR-15) — passes the raw `X-PAYMENT` header
 *     line as `bindingBytes`, plus `[$method, $resolvedResource]` as
 *     `scope` so paid responses don't replay across routes.
 *   - `laravel-x402-mcp` (JSON-RPC) — passes the EIP-3009 `signature`
 *     bytes as `bindingBytes` and `[method, challenge_resource]` as
 *     `scope` (e.g. `tools/call`, `mcp://tool/fetch-premium-data`).
 *     JSON re-encoding is non-canonical, so a JSON-RPC consumer cannot
 *     rely on the raw payload bytes; pinning to the signature gives
 *     the same forge-resistance because only the private-key holder
 *     can produce it.
 *
 * Why both halves matter:
 *
 *   - `(network, from, nonce)` identifies the on-chain authorization,
 *     but `from` and `nonce` become public after settlement — they're
 *     insufficient on their own.
 *   - `bindingBytes` is the security pin: an attacker who observed a
 *     paid request cannot produce a different request that hashes to
 *     the same key without the private key.
 *   - `scope` prevents cross-transport / cross-primitive replay (e.g.
 *     a settled `tools/call` cache entry must not replay into a
 *     `resources/read` retry that reuses the same payment payload).
 *
 * Recommendations for downstream consumers:
 *
 *   - **Pre-derive scope segments via a transport-specific value
 *     object** rather than building the list inline at the call site.
 *     JSON-RPC consumers in particular should canonicalise the
 *     resource fingerprint (e.g. `sha256` of sort-keys-recursive
 *     canonical-JSON for `tools/call` argument bags) so client-side
 *     re-serialisation can't split keys across cache entries.
 *   - **Set an explicit transport-namespaced `prefix`** in your
 *     adapter wiring (e.g. `x402:idem:mcp:`, `x402:idem:http:`) so
 *     PSR-15 and JSON-RPC consumers sharing one Redis store cannot
 *     collide. The `DEFAULT_PREFIX` is `x402:idem:` — fine for
 *     single-transport deployments, recipe for footgun in mixed.
 */
final readonly class IdempotencyKeyBuilder
{
    public const DEFAULT_PREFIX = 'x402:idem:';

    /**
     * Build a cache key for a paid response.
     *
     * @param  string  $network          CAIP-2 network ID from the signature.
     * @param  string  $from             Authorization `from` address (lowercased internally).
     * @param  string  $nonce            Authorization nonce (lowercased internally).
     * @param  string  $bindingBytes     Bytes only the private-key holder can produce — raw header line for PSR-15, EIP-3009 signature field for JSON-RPC. MUST be non-empty.
     * @param  list<string>  $scope      Transport-specific scope strings mixed into the hash. `[]` for PSR-15. `[method, challengeResource]` for JSON-RPC.
     * @param  string  $prefix           Cache-key prefix. Defaults to `x402:idem:`.
     */
    public static function build(
        string $network,
        string $from,
        string $nonce,
        string $bindingBytes,
        array $scope = [],
        string $prefix = self::DEFAULT_PREFIX,
    ): string {
        // Empty bindingBytes would collapse forge-resistance — the
        // hash would reduce to (network, from, nonce, scope), which
        // becomes guessable once the on-chain settlement makes those
        // public. PSR-15 callers gate this at the header-presence
        // check, but JSON-RPC callers pass the EIP-3009 signature
        // field directly; defense-in-depth here catches a missing
        // signature field that a transport adapter forgot to validate.
        if ($bindingBytes === '') {
            throw new InvalidArgumentException('IdempotencyKeyBuilder::build requires non-empty $bindingBytes — a paid response cache key without forge-resistant pinning would let any caller with the public (network, from, nonce) tuple replay the cached response.');
        }

        // Inputs are serialised through `json_encode` (not `implode`)
        // because callers control `$scope` and may pass strings that
        // contain delimiter characters. A plain `|` join is not
        // injective: `['a|b', 'c']` and `['a', 'b|c']` collapse to the
        // same preimage `"a|b|c"`. JSON encoding length-prefixes each
        // string via the surrounding quotes + escape sequences, so two
        // distinct input tuples always produce distinct preimages.
        $payload = json_encode(
            [
                'v' => 1, // bump if the key shape ever needs to change deliberately
                'network' => $network,
                'from' => strtolower($from),
                'nonce' => strtolower($nonce),
                'scope' => array_values($scope),
                'binding' => $bindingBytes,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );

        return $prefix . hash('sha256', $payload);
    }
}
