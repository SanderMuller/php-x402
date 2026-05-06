# Security Policy

## Reporting a vulnerability

Open a private advisory on GitHub (`Security` → `Report a vulnerability`) or email `sander@hihaho.com`. Please do not file public issues for security bugs.

## Supported versions

Only the latest minor release receives security fixes. Pin to a version you can keep updated.

## Key custody — operator responsibility

This package signs and verifies x402 payment authorizations using EVM private keys supplied by the operator. The package itself does not custody funds, but **the operator's signing key is what authorizes transfers**. Treat it like a production credential:

- Inject via environment variable or a KMS-backed `Wallet` implementation; never hard-code.
- The default `PrivateKeyWallet` keeps the raw key in process memory for the duration of the request — fine for development, **not** appropriate for production hosts that handle multiple tenants without isolation.
- Consider running the signing path in a dedicated service / worker process so the key is never present in the same memory space as untrusted user input.

## Replay protection — required

Server-side enforcement (`PaymentEnforcer`) refuses a payment whose `(network, from, nonce)` tuple has already been used within the authorization's `validBefore` window. Skipping the `NonceStoreContract` injection or wiring an in-memory store across multiple worker processes **breaks replay protection** — funds could be re-charged on the same authorization.

The shipped `Psr16NonceStore` uses get + set (small TOCTOU window). For strict correctness under high concurrency, wire a Redis-backed PSR-16 cache (Redis SETNX is atomic) or use the Laravel adapter's `LaravelNonceStore` which calls `Cache::add()`.

## Facilitator trust

The default facilitator is Coinbase's hosted endpoint at `https://x402.org/facilitator`. The facilitator can refuse to settle (denial of service) but cannot redirect funds — the EIP-3009 signature binds `to` and `value` cryptographically. Operators with stricter trust requirements should self-host (e.g. `x402-rs`) and pass a custom `baseUrl`.
