# Changelog

All notable changes to `php-x402` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.8.0 - 2026-05-10

### What's new

- **`X402\Client\KmsWallet` (abstract).** Owns the three rules from `docs/kms.md`: memoised address derivation from the KMS-provided `SubjectPublicKeyInfo`, EIP-2 low-s normalisation, and recovery-id derivation via brute force against the expected address. Subclasses provide two thin methods:
  
  ```php
  abstract protected function fetchPublicKeySpki(): string;          // binary SPKI DER
  abstract protected function rawSign(string $digestBytes): string;  // binary signature DER
  
  ```
  The abstract handles the rest — DER decoding, canonical-s flip, `r ‖ s ‖ v` packing — so a new KMS / HSM / remote-signer integration is ~40 LOC instead of the ~250 LOC adopters previously wrote by hand against the 0.7.x `Wallet` interface. The address materialises before the sign call so a `kms:GetPublicKey` failure surfaces before a `kms:Sign` quota is consumed, and the SPKI's 65-byte point is validated against secp256k1 curve membership (catches a misconfigured KMS that returns a syntactically-valid but off-curve payload).
  
- **`X402\Client\AwsKmsWallet`.** First-party AWS KMS subclass. Wires `kms:GetPublicKey` and `kms:Sign` with the parameters AWS requires for EVM signing (`MessageType = DIGEST`, `SigningAlgorithm = ECDSA_SHA_256`). Authentication, region selection, retry policy, and the rest of the AWS client configuration live entirely in the `Aws\Kms\KmsClient` instance you pass in — the class is intentionally policy-free.
  
  `aws/aws-sdk-php` is in `composer suggest`, not `require` — adopters on raw private keys, HD wallets, or other KMS providers don't carry the dep. Add it explicitly when you want the AWS path:
  
  ```bash
  composer require aws/aws-sdk-php
  
  ```
  Usage:
  
  ```php
  use Aws\Kms\KmsClient;
  use X402\Client\AwsKmsWallet;
  use X402\Client\PayingClient;
  
  $wallet = new AwsKmsWallet(
      kms:   new KmsClient(['region' => 'us-east-1', 'version' => 'latest']),
      keyId: 'arn:aws:kms:us-east-1:123:key/abcd-...',
  );
  
  $client = new PayingClient(inner: $psr18Client, wallet: $wallet);
  
  ```
  The KMS key MUST be `ECC_SECG_P256K1` with usage `SIGN_VERIFY`. Other curves trip a fail-fast `InvalidArgumentException` on the first `address()` call thanks to the strict AlgorithmIdentifier check below.
  
- **`X402\Support\Asn1DerDecoder`.** Strict canonical ASN.1 DER parser for the two shapes KMS wallets actually need: ECDSA signatures (`SEQUENCE { r INTEGER, s INTEGER }`) and secp256k1 SubjectPublicKeyInfo. Rejects:
  
  - Wrong outer / inner tags.
  - Negative ASN.1 integers (32-byte INTEGER with the high bit set and no positivity pad).
  - Non-canonical leading-zero encodings (over-padded INTEGERs).
  - INTEGER values exceeding 32 bytes.
  - SPKI AlgorithmIdentifier bytes that aren't the canonical `id-ecPublicKey` + `secp256k1` prefix — wrong-curve KMS keys (P-256, P-384, arbitrary OID) surface up-front instead of as a downstream recovery mismatch.
  - SPKI BIT STRING contents that aren't a 65-byte `0x04`-prefixed uncompressed point.
  - Trailing bytes after the outer SEQUENCE.
  - Truncated length prefixes.
  
  Strictness is enforced at the trust boundary rather than papered over downstream — a malformed KMS response is a configuration bug, and the wallet refuses to sign rather than emit a "valid-looking" signature.
  
- **`X402\Schemes\Evm\EcdsaRecovery`.** Brute-forces the recovery-id byte for a `(digest, r, s)` triple by recovering the public key under each candidate `v ∈ {27, 28}` and matching the derived address against the expected signer. Throws when neither candidate resolves — the KMS signature does not belong to the configured public key. Reach for it from any KMS-backed `Wallet`; pure-PHP signers like `PrivateKeyWallet` get the recovery id directly from simplito.
  
- **Wallet conformance test suite.** `tests/Unit/Client/WalletConformanceTest.php` is a dataset-driven harness. Every `Wallet` implementation gets a row; every row must satisfy:
  
  - EIP-2 canonical low-s for any digest.
  - Deterministic signing (RFC 6979) — same digest twice → same signature byte-for-byte.
  - Signature recovers to the wallet's address.
  - `v ∈ {27, 28}`.
  - Signature is `0x` + 130 hex chars; address is `0x` + 40 hex chars.
  
  New `Wallet` subclasses add a row in the dataset; bespoke implementation-specific tests stay focused on implementation-specific edge cases. This is the gap that let the high-s bug below live undetected for six releases — the prior tests only asserted *recovery*, not *canonicality*.
  

### Bug fixes

- **`SignatureExporter::toHex65` now enforces EIP-2 canonical low-s for every emitted signature.** `PrivateKeyWallet` and `HdWallet` relied on simplito's `canonical: true` option to keep `s` in the low half of the curve order. That option is silently dropped when called via `KeyPair::sign($enc = false, $options = ['canonical' => true])` — `EC::sign`'s first branch swaps the args when `$enc` isn't a string and clobbers the options. ~50% of digests therefore produced high-s signatures that EIP-2-enforcing contracts (USDC's `transferWithAuthorization`, OpenZeppelin's `ECDSA.recover`, and most modern verifiers) reject silently. `SignatureExporter` now flips `s` to `n - s` and parity-toggles the recovery byte when `s > n / 2`, regardless of what the underlying signer emits. Recovery still resolves to the same address; the raw `s` and `v` bytes differ for what used to be the high-s half.

### Breaking changes

- **Signature `s` / `v` bytes change for ~50% of digests** as a consequence of the EIP-2 low-s fix. Adopters who pinned signature output byte-for-byte in their test suite (`expect($wallet->signDigest(...))->toBe('0x…')`) will need to re-generate those fixtures or replace the assertion with a recovery + low-s check. The wire format is identical; the recovered address is identical; only the canonical-s representation has changed. Marked breaking because byte-output is part of the observed contract for some adopters.
  
- **`X402\Support\Asn1DerDecoder` rejects non-canonical DER**. If you wrote a custom adapter that called `decodeSignature()` against a non-strict-DER blob — over-padded INTEGER, ASN.1-negative INTEGER, wrong AlgorithmIdentifier OID — it now throws `InvalidArgumentException` instead of silently decoding. Real KMSes emit canonical DER, so production paths are unaffected.
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.7.0...0.8.0

## 0.7.0 - 2026-05-10

### What's new

- **Async settlement path on `SettleResult` and `PaymentEnforcer`.** New `SettleResult::pending(tracker: …)` factory and `isPending()` predicate, plus a constructor invariant that rejects the contradictory shape (`success: true` together with a non-empty tracker). `PaymentEnforcer` now returns **202 Accepted** with a pending `PAYMENT-RESPONSE` receipt body and **skips the inner handler** when the facilitator returns a pending result — delivering paid content for an unsettled payment defeats x402. The path is opt-in from the facilitator side: the bundled synchronous facilitators (`CoinbaseFacilitator`, `FakeFacilitator`) keep emitting `success: true` / `success: false` and the existing 2xx / 402 flow is byte-identical.
  
- **`X402\Webhook\*` primitives.** The upstream surfaces required to verify the inbound notification that confirms an async settlement:
  
  - `WebhookEvent` — value object carrying `id`, `tracker`, `status`, `transaction`, `network`, `payer`, `amount`, raw `meta`, and `signedAt`.
  - `WebhookStatus` — string-backed enum with the canonical statuses plus an `Unknown` fallback (forward-compatible — adopters never crash on a status the facilitator added later).
  - `SignatureVerifier` — Stripe-style `t=<unix>,v1=<hmac>` header verification with `hash_equals`, strict-integer timestamp parse, and a configurable clock-skew window. Rejects malformed headers, mismatched secrets, tampered bodies, and replays outside the skew window.
  - `WebhookDedupStore` — interface for the "claim webhook id once" guarantee. Concrete Redis / cache-backed implementations ship downstream in `sandermuller/laravel-x402` alongside the inbound route and Laravel-event dispatch.
  - `InvalidWebhookException` + `InvalidWebhookSignatureException` for adopters to branch on at the handler boundary.
  
- **`PaymentOutcomeKind::SettlePending` + `DispatchingFacilitator` routing.** Outcome closures see the new kind when the wrapped facilitator returns a pending result. `SettlePending` takes precedence over `SettleFailed` when the tracker is non-empty, so the audit row reflects the actual disposition rather than a misleading failure. `PaymentRowBuilder::pendingRow()` produces a `STATUS_PENDING` row with `transaction: null` and the tracker populated; the `tracker` column is added uniformly to settled / rejected / pending rows.
  
- **`PaymentResponseCache` is pending-aware.** Reads `PaymentResponse::isPending()` directly off the wire and skips caching the 202 response — replaying a pending receipt would surface an unsettled payment as paid. Lost-receipt tradeoff (a dropped 202 in flight cannot be replayed from the cache) is documented inline. Synchronous 200s with non-pending receipts are unaffected.
  
- **`X402\Client\HdWallet` — BIP-32 hierarchical-deterministic wallet.** A per-process root key derives unlimited child wallets along the standard EVM path `m/44'/60'/0'/0/N` (or any custom path). Signs EIP-712 typed data exactly like `PrivateKeyWallet` — produced signatures recover to the HD-derived address, byte-for-byte identical to a fresh `PrivateKeyWallet` initialised with the same derived key. Verified against the BIP-32 published test vectors (8 entries pinned in `tests/Fixtures/bip32-vectors.json`).
  
  Use case: per-tenant signing keys in framework adapters. The HD root sits in env / KMS; child wallets are derived deterministically per tenant id, so adopters never persist a per-tenant key. The Laravel-side `TenantHdWalletResolver` wiring lands in `sandermuller/laravel-x402` next.
  
  Rejects malformed input: seeds shorter than 128 bits or longer than 512 bits, odd-length hex, non-hex chars, empty / malformed paths (`m/`, `m//`, non-numeric components, mixed-apostrophe placement, child index ≥ 2^31). `xprv` / `xpub` import/export is deferred to a follow-up minor on adopter pull.
  

### Maintainability pass

- **`PaymentResponseCache` constructor — optional knobs moved into `PaymentResponseCacheOptions`.** See "Breaking changes" below. The required arguments are unchanged; only callers that passed any of the six optional knobs (`version`, `ttl`, `prefix`, `logger`, `responseHeadersAllowList`, `resourceResolver`) need to update.
  
- **`CoinbaseFacilitator` hygiene.** Three duplicated `defaultHeaders` foreach loops collapse into `applyDefaultHeaders()`. The 33-line, 3-level-nested discovery item parser extracts into `parseDiscoveryItem()`, leaving `discoverResources()` six lines.
  
- **38 new tests for previously-untested public API.** `PayingClient` (11 cases — pass-through, v1 / v2 wire negotiation precedence, error paths, EIP-712 domain resolution and fallback) and `PaymentIdentifier` (27 cases — generate / isValid / extractId / isRequired / wire envelopes). Also explicit per-method tests for the refactored `CoinbaseFacilitator::discoverResources()` and `supported()` so the extracted helpers stop relying on transitive coverage.
  
- **`X402\Support\Hex` consolidates four duplicated `hex2binStrict` copies** across the scheme classes into one helper. No behaviour change.
  
- **`FakeFacilitator::verifyResults()` / `settleResults()`.** Recording arrays indexed in lockstep with `verifyCalls()` / `settleCalls()`. Useful for tests that toggle `rejectVerify('reason-A')` mid-flight and need to confirm the right reason landed on the right call, or for verifying tracker propagation through the pending path.
  

### Breaking changes

- **`PaymentResponseCache` constructor — six optional knobs moved into a `PaymentResponseCacheOptions` DTO.** The 10-parameter constructor was unwieldy and adding any further knob was itself a breaking change for positional and named-arg callers. Required arguments (`cache`, `responseFactory`, `streamFactory`, `schemes`) are unchanged. Migration:
  
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
  Full migration steps in [`UPGRADING.md`](https://github.com/SanderMuller/php-x402/blob/main/UPGRADING.md#from-06x-to-070).
  
- **`PaymentEnforcer` returns 202 on `SettleResult::pending(...)` and skips the inner handler.** Only affects adopters whose `FacilitatorClient` returns a pending result; synchronous facilitators see the existing 2xx / 402 flow unchanged. Adopters wiring async settlement should add a `SettlePending` arm to any `match ($outcome->kind) { ... }` over `PaymentOutcomeKind` (or rely on the `default` arm the 0.6.0 notes recommended for forward-compat).
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.6.0...0.7.0

## 0.6.0 - 2026-05-10

### What's new

- **`X402\Facilitator\DispatchingFacilitator`** — wraps any `FacilitatorClient` and surfaces a `PaymentOutcome` to a single `onOutcome` closure on every non-trivial outcome (`VerifyRejected`, `VerifyError`, `SettleSucceeded`, `SettleFailed`, `SettleError`):
  
  ```php
  use X402\Facilitator\DispatchingFacilitator;
  use X402\Facilitator\PaymentOutcome;
  use X402\Facilitator\PaymentOutcomeKind;
  
  $facilitator = new DispatchingFacilitator(
      inner: CoinbaseFacilitator::default($psr18Client, $psr17),
      onOutcome: function (PaymentOutcome $outcome, array $context) use ($events): void {
          match ($outcome->kind) {
              PaymentOutcomeKind::SettleSucceeded => $events->dispatch(new PaymentSettled(...)),
              default                              => $events->dispatch(new PaymentRejected(...)),
          };
      },
      captureContext: fn (): array => ['user_id' => currentUser()?->id, 'ip' => clientIp()],
      resourceFormatter: fn (string $url): string => $registry->formatResource($url),
  );
  
  
  
  ```
  Three optional ctor closures: `onOutcome` (the listener), `captureContext` (no-arg, returns adopter-supplied context — request user id, IP, trace id — once per outcome), `resourceFormatter` (maps `challenge->resource ?? ''` to the canonical resource string surfaced on `PaymentOutcome::$resource`).
  
  Listener / context-capture / formatter exceptions on `*-error` paths are silently swallowed so the original facilitator throwable always propagates to the caller. Hook bugs on the slow path can't mask a transport / network failure that operations need to triage.
  
- **`X402\Facilitator\PaymentOutcome`** — snapshot DTO surfaced to the `onOutcome` closure. Carries `kind`, `signature`, `challenge`, `resource`, `reason`, `verify`, `settle`, `exception`. Per-kind population documented on the class — adopters branch on `$outcome->kind` and consume the relevant result field.
  
- **`X402\Facilitator\PaymentOutcomeKind`** — string-backed enum (`verify-rejected` / `verify-error` / `settle-succeeded` / `settle-failed` / `settle-error`). Constants `REASON_PREFIX_VERIFY_ERROR` / `REASON_PREFIX_SETTLE_ERROR` for the prefixed reason format. New cases may be added in any minor version (e.g. async-settlement support down the road), so adopters writing `match ($outcome->kind) { ... }` SHOULD include a `default` arm.
  
- **`X402\PaymentHistory\PaymentRowBuilder::fromOutcome()`** — static helper that turns a `PaymentOutcome` into a flat array row matching the laravel-x402 `x402_payments` migration shape:
  
  ```php
  use X402\PaymentHistory\PaymentRowBuilder;
  
  $row = PaymentRowBuilder::fromOutcome($outcome, $context);
  // ['status' => 'settled', 'resource' => '...', 'payer' => '0x...', 'amount' => '10000', ...]
  
  Payment::query()->updateOrCreate(['transaction' => $row['transaction']], $row);
  
  
  
  ```
  Constants: `STATUS_SETTLED`, `STATUS_REJECTED`, `DEFAULT_REASON_MAX_LENGTH = 255`. Reason truncation defaults to 255 chars (matches the migration column width) — without it, a long `*-error` reason exceeds the column and the audit-row write fails after the payment path already failed once. Pass `replayKey: $extracted` from the matched `ReplayKeyExtractor` for scheme-aware nonce / payer extraction; falls back to `PaymentSignature::authorization()` for the EIP-3009 path.
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.5.1...0.6.0

## 0.5.1 - 2026-05-10

### Bug fixes

- **`X402\Support\PriceParser` rejects negative amounts, decimal-overflow, and non-strict-shape inputs by default.** Surfaced from a downstream adapter cutover that pinned three silent-conversion vectors:
  
  1. **Negative amounts** used to produce a negative atomic string (`'-0.5'` → `'-500000'`). The wire format accepts it; adopters notice on the facilitator side, or never. Now throws by default.
  2. **More fractional digits than the asset supports** used to silently truncate (`'0.0001'` with `decimals=2` → `'0'`). A buyer signing this authorization gets debited for zero atomic units while the seller serves the request. Now throws by default.
  3. **`is_numeric()` shape** used to accept scientific notation (`'1e5'` silently accelerates to 100000), thousands separators (`'1,000'`), leading `+`, hex/oct prefixes, and whitespace. Now uses strict regex `^-?\d+(\.\d+)?$` and rejects the lot.
  
  Opt into the looser behaviour explicitly when you have a deliberate reason:
  
  ```php
  // Refund / credit math
  PriceParser::toAtomic('-0.5', 6, allowNegative: true);  // → '-500000'
  
  // "Round to the nearest cent" pricing
  PriceParser::toAtomic('0.0000019', 6, truncate: true);  // → '1' (drops the 9)
  
  
  
  
  ```
  `PaymentRequiredBuilder` (test helper) passes `truncate: true` internally — fixture amounts like `'0.0123456789'` keep working there. Production callers reach for `PriceParser::toAtomic()` directly and get the strict defaults.
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.5.0...0.5.1

## 0.5.0 - 2026-05-10

### What's new

- **`X402\Server\BotDetector`** — curated User-Agent matcher for bot-only-payment endpoints. Wires straight into `PaymentEnforcer`'s `shouldEnforce` predicate:
  
  ```php
  use X402\Server\BotDetector;
  
  $detector = new BotDetector(extra: ['MyCustomCrawler']);
  
  $middleware = new PaymentEnforcer(
      // ...
      shouldEnforce: static fn ($request): bool
          => $detector->isBot($request->getHeaderLine('User-Agent')),
  );
  
  
  
  
  
  ```
  Ships ~70 default patterns (Agents / Assistants / Scrapers / Search crawlers / Undocumented) sourced from [https://knownagents.com](https://knownagents.com). Override the list with `patterns:`, extend it with `extra:`, or pass `patterns: []` to disable detection. Match is case-insensitive substring on the User-Agent.
  
- **`X402\Testing\FakeFacilitator`** — canonical test double, functional superset of the existing `StubFacilitator` (configurable outcomes) and `RecordingFacilitator` (call capture). One instance covers both:
  
  ```php
  $fake = new FakeFacilitator();
  
  // Tweak outcomes mid-test
  $fake->rejectVerify('insufficient-funds');
  $fake->failSettle('on-chain-revert');
  
  // Run the system under test, then assert
  $fake->assertVerified();                              // any verify call
  $fake->assertSettled('https://example.test/premium'); // settle for a specific resource
  $fake->assertNothingSettled();                        // no-settle paths
  
  
  
  
  
  ```
  Records full signature + challenge payload per call (not just counts) — accessible via `verifyCalls()` / `settleCalls()` for custom assertions. The `assert*()` helpers use `PHPUnit\Framework\Assert`; php-x402 already pulls phpunit transitively via `pestphp/pest` in dev, so this is available wherever you run a Pest or PHPUnit suite.
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.4.1...0.5.0

## 0.4.1 - 2026-05-10

### What's new

- **`X402\Protocol\PaymentSignature::fromArray(array $data): self`** — symmetric to `fromHeader()` but skips the base64 + JSON decode step. Designed for transports that decode the envelope earlier in the pipeline:
  
  - JSON-RPC / MCP consumers (`params._meta["x402/payment"]` is already a PHP array by the time the method handler sees it — this lets `laravel-x402-mcp`'s `X402CallTool` / `X402ReadResource` / `X402GetPrompt` delete their private `hydrateSignature()` duplicates).
  - A2A consumers (`metadata` decoded by the agent transport).
  - Custom test harnesses that build the envelope as an array.
  
  `fromHeader()` now thin-wraps `fromArray()` so the hydration path is single-source — same `accepted` / `extensions` / version-tolerance behaviour, same `InvalidPaymentException` errors on missing-fields, just one input format earlier in the pipeline.
  
- **`X402\Support\PriceParser::toAtomic(float|string $amount, int $decimals): string`** — public extraction of the decimal-to-atomic-unit conversion that was previously private inside `X402\Testing\PaymentRequiredBuilder::toAtomicUnits()`. Adapters that need to parse `"0.01"` → `"10000"` for USDC config (laravel-x402's `Support\PriceParser`, future Symfony / Slim / raw-PSR-15 integrations) now share one bcmath-or-string-shift implementation. Prefers `bcmul()` when ext-bcmath is available; falls back to a pure-string decimal shift otherwise. Both paths are exact; neither introduces float rounding. Truncation past `$decimals` is strict (no rounding) so atomic conversions don't silently bump cents.
  
  `PaymentRequiredBuilder` now delegates to it — no behaviour change for existing callers.
  
- **`X402\Server\IdempotencyKeyBuilder` docblock recommendations** — two pieces of guidance surfaced from `laravel-x402-mcp`'s cache-adapter spec work are now codified in the class docblock:
  
  - **Pre-derive scope segments via a transport-specific value object** rather than building the list inline at the call site. JSON-RPC consumers in particular should canonicalise the resource fingerprint (e.g. `sha256` of sort-keys-recursive canonical-JSON for `tools/call` argument bags) so client-side re-serialisation can't split keys across cache entries.
  - **Set an explicit transport-namespaced `prefix`** in your adapter wiring (`x402:idem:mcp:`, `x402:idem:http:`) when multiple transports share one Redis store. The `DEFAULT_PREFIX` is `x402:idem:` — fine for single-transport deployments, recipe for footgun in mixed.
  

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.4.0...0.4.1

## 0.4.0 - 2026-05-09

### Breaking

- **Custom non-`ReplayKeyExtractor` schemes lose in-process replay protection.** The 0.3.x BC fallback (custom `SchemeContract` implementations validating EIP-3009-shaped `payload.authorization` on `eip155:*` networks) is removed. Such schemes now defer replay protection entirely to the facilitator's on-chain nonce check. To restore in-process protection, implement `X402\Schemes\ReplayKeyExtractor::replayKey()` on your custom scheme — see `UPGRADING.md` for a migration diff. Built-in `Evm\ExactScheme`, `Evm\Permit2Scheme`, and `Upto\UptoEvmScheme` are unaffected (they implement the interface since 0.3.0). The 0.3.1 deprecation hop the spec called for did not ship — adopters with custom EIP-3009-shaped schemes upgrade directly from 0.3.x to 0.4.0 and need to plan the `ReplayKeyExtractor` migration before the bump.
- **`PaymentResponseCache` constructor adds an optional `resourceResolver:` parameter** for cache-identity scoping, and the cache-key format changed (see Notes below). 0.3.x cached snapshots are unreadable by 0.4.0 readers — purge the cache namespace before deploying, or accept a one-time miss window during the upgrade. `UPGRADING.md` documents both paths.

### What's new

- **`X402\Server\IdempotencyKeyBuilder`** — transport-agnostic builder for paid-response idempotency cache keys. PSR-15 (`PaymentResponseCache`) and JSON-RPC (laravel-x402-mcp) consumers share the derivation. Uses `json_encode` (not `implode`) so caller-supplied scope strings can't collide via embedded delimiters; throws on empty `bindingBytes` so a transport adapter that forgets to validate the EIP-3009 signature field can't ship a forge-resistance hole. Schema versioned via an internal `v: 1` marker so the key shape can evolve deliberately.
- **`X402\Support\DecimalCompare::compare()`** — extracted from three near-identical `compareAmount()` private methods on `Evm\ExactScheme`, `Evm\Permit2Scheme`, and `Upto\UptoEvmScheme`. Spaceship comparison of stringified non-negative decimals; side-steps PHP-int overflow on USDC / EIP-3009 / Permit2 amount fields.
- **`X402\Server\InvokeResourceResolver`** — null-default + return-type guard for the `Closure|ResourceResolver|null` shape `PaymentEnforcer` and `PaymentResponseCache` both accept. Centralises the resolution path so the two middlewares can't drift on edge cases.
- **`PaymentResponseCache` cross-route scoping.** The cache key now binds HTTP method + resolved resource alongside the signed-authorisation header bytes, so a paid response on `GET /premium-A` cannot replay onto `GET /premium-B` when the same X-PAYMENT header is reused. `PaymentSignature` does not bind the resource it was signed against, so the cache key has to do that work itself. The new optional `resourceResolver:` constructor argument controls how the resource is derived; default = `$request->getUri()->getPath()` (matches `PaymentEnforcer`'s default). Pass a custom resolver only when pricing-equivalent URIs should also share cached responses — pricing-collapse and content-collapse are different invariants.

### Bug fixes

- **`IdempotencyKeyBuilder` non-injective serialisation** — the inlined `implode('|', …)` `PaymentResponseCache` used in 0.3.x let two different scope tuples collapse to the same preimage when any segment contained `|` (URIs and JSON-RPC method names can both legally contain it). The extracted helper switches to a structured `json_encode` payload with explicit keys + length-prefixed string encoding via JSON quoting.
- **PSR-15 idempotency key ignored the protected resource** — `PaymentSignature::fromHeader()` only decodes `(scheme, network, payload)` and the cache key derived only from the header bytes, so two endpoints accepting the same X-PAYMENT header produced the same cache entry. The new method + resource scope closes the cross-route replay window.

### Notes

- `PaymentEnforcer::guardReplay()` reduced from ~50 lines to ~20. The hardcoded EIP-3009 normalisation (`Constants::TRANSFER_METHOD_EIP3009`, `eip155:` prefix matching) is gone — replay-key extraction is now entirely scheme-owned via `ReplayKeyExtractor`.
- Cache-key format changed in two ways: serialisation switched from `implode` to `json_encode`, and the PSR-15 path now mixes in HTTP method + resolved resource. Both invalidate 0.3.x on-disk format. `UPGRADING.md` describes a per-prefix bump or one-time miss-window strategy for adopters with persistent caches.
- Eight new tests across `IdempotencyKeyBuilderTest`, `DecimalCompareTest`, and the existing `PaymentEnforcerTest` / `PaymentResponseCacheTest` — covering the BC fallback fall-through, cross-route replay, cross-method replay, resolver alignment, delimiter-bearing scope collisions, and empty-`bindingBytes` rejection.
- No DTO shape change. No `NonceStoreContract` change. No behaviour change for built-in schemes (`Evm\ExactScheme`, `Evm\Permit2Scheme`, `Upto\UptoEvmScheme`, `Evm\Erc7710Scheme`, Stellar / Svm `ExactScheme`).

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.3.0...0.4.0

## 0.3.0 - 2026-05-09

### What's new

- **`X402\Schemes\ReplayKeyExtractor`** — optional capability interface schemes opt into to surface their `(from, nonce, expiresAt)` triple for in-process nonce claiming. Built-in `Evm\ExactScheme`, `Evm\Permit2Scheme`, and `Upto\UptoEvmScheme` implement it; their `replayKey()` mirrors `verifyShape()` exactly (uses `JsonReader::string()`'s numeric→string coercion, so a numeric JSON `nonce: 123` no longer silently skips the in-process claim while still settling). `PaymentEnforcer::guardReplay()` checks `instanceof` and routes per-scheme, so a Permit2 caller cannot inject a forged top-level `authorization` block to redirect the claim onto attacker-chosen `(from, nonce)` — each scheme reads only the payload fields it itself validates. `Erc7710Scheme`, Stellar / Svm `ExactScheme` don't implement the interface and defer replay protection to the facilitator's on-chain nonce check, same as before.
- **`X402\Replay\CallbackNonceStore`** — closure-injected adapter for any Redis-compatible client that implements `SET key value NX EX ttl` semantics. Dep-free; works with phpredis, Predis, or hand-rolled clients. Fills the production gap left by `Psr16NonceStore`, which cannot satisfy the `NonceStoreContract` atomicity claim because PSR-16 has no add-if-absent primitive (see 0.2.1 notes for the underlying race).
  ```php
  $redis = new \Redis();
  $store = new CallbackNonceStore(
      static fn (string $key, int $ttl): bool
          => (bool) $redis->set($key, '1', ['NX', 'EX' => $ttl]),
  );
  
  
  
  
  
  
  
  
  ```
- **`PaymentResponseCache` response-header allow-list + hard-block.** Cached snapshots no longer include `Set-Cookie`, `Authorization`, `Proxy-Authorization`, `Www-Authenticate`, or `Cookie` regardless of caller-supplied allow-list — these would otherwise let a stolen `X-PAYMENT` header replayer inherit the original buyer's session. Hard-block runs on the read path too, so any pre-0.3.0 snapshots in your persistent PSR-16 store get sanitised on first hit after upgrade. Default allow-list (`Content-Type`, `Content-Language`, `Content-Length`, `Content-Disposition`, `Cache-Control`, `ETag`, `Last-Modified`, `Location`, `X-PAYMENT-RESPONSE`, `PAYMENT-RESPONSE`) is overrideable via the new `responseHeadersAllowList` constructor arg; the hard-block list is enforced regardless.

### Bug fixes

- **`PaymentResponseCache` was EIP-3009-only on the cache-key path.** It derived its key from `PaymentSignature::authorization()` (which only reads `payload.authorization`), so Permit2 and Upto signatures had their nonces burned by `PaymentEnforcer` but no matching cache entry — a dropped-response retry on those schemes would 402 as a replay. Same gap on numeric JSON `nonce` values for the EIP-3009 path because `stringOrNull()` doesn't coerce. The cache now uses the same `ReplayKeyExtractor` path as the enforcer, so Permit2 / Upto / numeric-nonce all key correctly.
- **`PaymentResponseCache` no longer caches variant-specific responses.** Status `206 Partial Content` and responses carrying `Content-Encoding`, `Content-Range`, `Accept-Ranges`, or `Vary` are skipped at write time. The cache key binds the signed authorization but not request `Range` / `Accept-Encoding` / negotiation inputs, so replaying a gzipped response to a non-gzip retry, or a partial-content snapshot to a full-body retry, would corrupt the dropped-response recovery guarantee. `isValidSnapshot()` rejects pre-0.3.0 entries carrying these headers/status on the read path too.

### Notes

- **BREAKING:** `PaymentResponseCache::__construct()` now takes a `$schemes: array<string, SchemeContract>` argument (same map you wire into `PaymentEnforcer`). Pass the full enforcer map — non-`ReplayKeyExtractor` entries are filtered out internally so mixed deployments keep working without a separate map. Adopters using named arguments only need to add `schemes:`; positional callers need to re-order. See `UPGRADING.md` for diffs.
- **BC fallback retained for custom schemes.** Custom `SchemeContract` implementations that haven't migrated to `ReplayKeyExtractor` but still validate an EIP-3009-shaped `payload.authorization` keep their 0.2.x in-process replay protection via a server-controlled fallback gate (challenge `assetTransferMethod=eip3009` + `eip155:*` network). Schemes that genuinely defer to the facilitator are unaffected.
- **Drift signals at debug, not warning.** `PaymentResponseCache` emits `debug`-level log lines when a payment header arrives for a scheme it can't extract a key for. The cache sits on the unauthenticated edge — warning-level would be public-traffic-spoofable and drown out the real drift signal. Operators detect cache/enforcer drift by enabling debug logging temporarily.
- **Twenty-nine new tests** covering scheme `replayKey` extraction (numeric-nonce coercion, missing-field fail-closed, forged-payload-key isolation), non-EIP-3009 paths skipping cache, `CallbackNonceStore` semantics, response-header allow-list + hard-block on both write and read, mixed scheme map acceptance, variant-response skip, stale snapshot sanitisation, BC fallback for legacy custom EIP-3009 schemes.
- `docs/kms.md` clarified — `SignatureVerifier` accepts `{0, 1, 27, 28}` and rejects EIP-155 chainId-offset values fast (no behavior change vs 0.2.1; doc was less precise).

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.2.1...0.3.0

## 0.2.1 - 2026-05-09

Security/correctness patch. Closes a replay-protection bypass on the EIP-3009 path, tightens signature `v`-byte validation against silent hex coercion, and fixes a misleading production recommendation around `Psr16NonceStore`. Tests pass on the CI matrix (PHP 8.2 / 8.3 / 8.4 × prefer-stable / prefer-lowest).

### Bug fixes

- **`PaymentEnforcer` replay gate was bypassable via injected `payload.authorization`** — `guardReplay()` keyed on the presence of an EIP-3009 `payload.authorization` block, which Permit2/Upto's `verifyShape()` does not reject as an extra payload key. A caller could ship a real `permit2Authorization` (or `uptoAuthorization`) alongside a forged top-level `authorization` with attacker-chosen `(from, nonce)`; the in-process store would claim the forged tuple and the real permit/witness signature stayed replayable. The gate now keys on the matched challenge (server-controlled): `scheme === "exact"` AND `network` starts with `eip155:` AND `extra.assetTransferMethod` normalized to `"eip3009"` (matching `ExactScheme::verifyShape`'s normalization, including non-string fallbacks). Permit2/Upto/Erc7710/Stellar/Svm paths route to the facilitator for nonce uniqueness as before.
- **`SignatureVerifier` accepted any bytes for the `v` field** — `hexdec('gg')` silently coerces to `0`, so a malformed trailing `v` on a 65-byte signature would pass through as recovery id 0 and proceed to public-key recovery against the wrong byte instead of being rejected. Added `ctype_xdigit` validation on the full hex string up front and replaced the implicit `$v >= 27 ? $v - 27 : $v` arithmetic with an explicit `match` over `{0, 1, 27, 28}`. EIP-155 chainId-offset values (29/30/etc., used for raw transactions, not typed data) now fail with a clear error rather than tripping a downstream "invalid recovery id". Recovered public-key hex is also explicitly validated before `Keccak::hash`.
- **`Psr16NonceStore` cannot satisfy `NonceStoreContract` atomicity** — PSR-16 has no add-if-absent primitive; the `has() + set()` implementation has a small race window that lets two concurrent workers both claim the same `(network, from, nonce)` and both settle. The contract's docblock requires atomic SETNX-EX semantics, but the in-package PSR-16 adapter never delivered them. Class is unchanged in behavior but its docblock now states it is fit for tests and single-worker dev only. The README pivots the production recommendation away from `Psr16NonceStore` and toward `LaravelNonceStore` (in `sandermuller/laravel-x402`, backed by `Cache::add()`) or a Redis `SET key value NX EX ttl` adapter implementing `NonceStoreContract` directly. The `Surface` table now lists the contract, not the adapter, as the production entry point.

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.2.0...0.2.1

## 0.2.0 - 2026-05-08

Idempotency past nonce, route patterns by regex, a CLI, and an `X402\Testing` namespace for adopter integration tests. All additive; one rename (`PaymentEnforcer::default()` → `forTesting()`) to remove a footgun. Tests pass on the CI matrix (PHP 8.2 / 8.3 / 8.4 × prefer-stable / prefer-lowest).

### What's new

- **`PaymentResponseCache`** — separate PSR-15 middleware that sits **before** `PaymentEnforcer` in the chain. Caches paid 2xx responses keyed by `(network, from, nonce, signature bytes)` and replays the cached body on duplicates. Closes the "paid but didn't receive content" gap when a client's connection drops between facilitator settle and response delivery — `PaymentEnforcer`'s nonce guard correctly rejects the retried auth, so without this middleware the user paid and gets 402 on retry. PSR-16-backed; same store as `Psr16NonceStore` in production.
- **`RegexPriceTable`** — pattern-matched `PriceTable` for hosts that price by URL shape (`'#^/api/v\d+/users/\d+$#'`) rather than by exact resource string. PCRE validated at `add()` time; runtime PCRE errors (backtrack-limit exhaustion) fail closed with a thrown `RuntimeException` so a poorly-written pattern can't bypass payment via fail-open. Layer with `StaticPriceTable` for mixed setups (exact first, regex fallback).
- **`bin/x402 decode <header>`** — CLI to decode a base64 `PAYMENT-SIGNATURE` header into JSON. Useful for wire-shape debugging without writing a script. Resolves autoload whether installed under `vendor/` or run from the package root.
- **`X402\Testing` namespace** — adopter integration-test helpers: `PaymentRequiredBuilder` (USDC-on-Base / Base-Sepolia factories with atomic-unit conversion via pure-string decimal shift, no bcmath), `StubFacilitator` (settles locally), `RecordingFacilitator` (assertion).
- **`PaymentEnforcer` adapter contracts** — `ResourceResolver` and `EnforcementPolicy` interfaces let adopters slot framework-specific resolvers (Laravel route name, Symfony attribute) without subclassing. Both `Closure` and instance forms accepted.
- **`PaymentEnforcer::forTesting()`** factory — wires `InMemoryNonceStore` + a single `Psr17Factory`. Renamed from `default()` (see below — the old name read as production-ready).
- **`CoinbaseFacilitator::default()`** factory — single intersection-typed `RequestFactoryInterface&StreamFactoryInterface` slot replaces the three-PSR-factory ctor for the 99% case where one `Psr17Factory` plays both roles.
- **`composer ci`** — bundles all five gates (`rector --dry-run`, `pint --test`, `phpstan`, `pest`, `lean-package-validator`) into one pre-push / CI command. `composer qa` (auto-fix variants) stays for local cleanup.
- **Runnable example** under `examples/` — single-file PSR-15 server + Guzzle-backed paying client, ~80 LOC each. `StubFacilitator` settles locally so a new adopter sees the full 402 → sign → 200 flow without external services. Export-ignored from the dist tarball.

### Bug fixes

- **`PaymentResponseCache` forge guard** — keying on `(network, from, nonce)` alone meant an attacker who observed a paid request on-chain could construct any `PAYMENT-SIGNATURE` header with the public tuple and replay the cached response without paying. Cache key now binds the raw header bytes; same authorization replays cleanly (idempotency preserved), forged tuple-match falls through to `PaymentEnforcer` and is rejected by the nonce store.
- **`PaymentResponseCache` poisoned-snapshot fallthrough** — `isValidSnapshot()` checks for required keys + a sane HTTP status range before rebuilding. Garbage in the cache falls through to the handler instead of replaying an empty 200.
- **`RegexPriceTable` fail-closed on PCRE runtime errors** — `preg_match()` returns `false` on backtrack-limit exhaustion. Previous `=== 1` check correctly rejected, but treated the route as "no match" → empty challenge list → free. Now throws so the host returns 5xx and the operator sees the failure.
- **`PaymentRequiredBuilder::for()` overflow on 18-decimal fixtures** — float+`round()` lost precision at `1.5 * 10^18` and overflowed the double mantissa at `100.0 * 10^18`. Replaced with pure-string decimal shift — exact at any decimal count, truncates fractional digits past `decimals` without rounding.
- **`docs/kms.md` v-value** — documented `v = 27 + chainId * 2 + 35` for EIP-155 alongside 27/28. That EIP-155 form is for raw transactions; EIP-712 typed data (which x402 uses) accepts only 27/28. A reader's KMS adapter following the doc would emit signatures the verifier rejects. Struck the EIP-155 mention.
- **PHP 8.2 ParseError on typed class constants** — three sites used `public const string FOO = ...` (PHP 8.3+ syntax) under a `^8.2` package floor; local pest passed because the dev box runs 8.3+, but the run-tests matrix on PHP 8.2 ParseError'd. Dropped the explicit type and added `phpVersion: { min: 80200, max: 80499 }` to `phpstan.neon.dist` so 8.3-only syntax is now caught at static-analysis time on the dev box, not after a matrix red.

### Notes

- **Rename:** `PaymentEnforcer::default()` → `PaymentEnforcer::forTesting()`. The factory wires `InMemoryNonceStore` (process-local, replay-unsafe across workers); the docblock warned, but the name `default()` signaled "production-ready" to fresh readers. The new name reads as obviously wrong if shipped to production. Pre-1.0, no deprecation period — update call sites.
- **Internal cleanups (no behavior change):** `PaymentSignature::authorization()` helper extracts the `(from, nonce, validBefore)` triple now used by both `PaymentEnforcer::guardReplay` and `PaymentResponseCache::parseAndKey`; `PaymentResponseCache` stashes the parsed `PaymentSignature` on the request via attribute so `PaymentEnforcer` skips a redundant base64-decode + JSON-parse on the common cache-miss path.
- **Dist hygiene:** `docs/`, `examples/`, `testbench.yaml`, `workbench/`, `internal/`, and root-level project docs (`ROADMAP.md`, `CONTRIBUTING.md`, `SECURITY.md`) are export-ignored — vendor consumers don't pull them into `vendor/`. Lean-package-validator gates leanness on PR.

**Full Changelog**: https://github.com/SanderMuller/php-x402/compare/0.1.0...0.2.0

## 0.1.0 - 2026-05-08

### 0.1.0

#### What's new

- **PSR-15 server middleware** — `X402\Server\PaymentEnforcer` drops a 402 challenge on protected resources, verifies + settles signed payments via a facilitator, attaches `PAYMENT-RESPONSE`, then hands off to the inner handler.
- **PSR-18 client decorator** — `X402\Client\PayingClient` auto-pays 402 responses by signing an EIP-3009 authorization with the operator wallet and retrying.
- **`shouldEnforce` predicate** — optional `?Closure(ServerRequestInterface): bool` slot on `PaymentEnforcer` lets adapters compose policy (bot detection, IP allowlists, geo, plan tier) inside the enforcer instead of wrapping it. Returns `false` → inner handler runs, no challenge / no nonce claim / no facilitator call. Default (`null`) = always enforce.
- **EVM schemes** — `exact` (EIP-3009 + Permit2 transfer methods), `upto` (Permit2 + facilitator-bound witness, canonical `X402_UPTO_PERMIT2_PROXY` spender), ERC-7710 delegation (shape-only, signing deferred — see ROADMAP).
- **SVM scheme** — `exact` on Solana (pass-through with shape validation; client-side signing deferred).
- **Smart-wallet support** — ERC-6492 wrapped-signature decoder + signer wrapper; ERC-1271 magic-value awareness (verification delegated to facilitator).
- **Replay protection** — `NonceStoreContract` with `InMemoryNonceStore` (in-process, default for tests) and `Psr16NonceStore` (Redis-backed for production).
- **Bazaar discovery** — `/discovery/resources` client + DTOs (`DiscoveryQuery`, `DiscoveryPage`, `DiscoveryResource`).
- **Transports** — HTTP (v1 + v2 negotiation via `Version` enum), MCP (`_meta["x402/payment"]`), A2A metadata helpers.
- **Facilitator** — `CoinbaseFacilitator` targeting `x402.org/facilitator` by default; compatible with self-hosted `x402-rs`. Custom-headers slot for CDP authentication.
- **`payment-identifier` extension** — DTO support for the v2 extension.

#### Framework adapters

- Laravel: [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402)
- Laravel MCP: [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp)

**Full Changelog**: https://github.com/SanderMuller/php-x402/commits/0.1.0

## [Unreleased]
