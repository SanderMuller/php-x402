# Changelog

All notable changes to `php-x402` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
