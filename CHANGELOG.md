# Changelog

All notable changes to `php-x402` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
