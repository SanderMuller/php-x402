# Public API

The semver-protected surface of `sandermuller/php-x402`. Anything documented here is covered by semver; anything outside is internal and may change without notice (including in patch releases).

## Versioning

This package follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html). Pre-`1.0.0` releases may break API in MINOR bumps; we surface those in `CHANGELOG.md` and `UPGRADING.md`.

## Stable surface

### Namespaces

- `X402\Client\*` — wallet, KMS, HTTP client (`AwsKmsWallet`, etc.)
- `X402\Server\*` — PSR-15 middleware for serving paid resources
- `X402\Facilitator\*` — facilitator HTTP client + responses
- `X402\Protocol\*` — protocol value objects (PaymentRequirements, PaymentPayload, etc.)
- `X402\Schemes\*` — payment scheme implementations (EIP-3009 exact)
- `X402\Webhook\*` — webhook verification + dispatch
- `X402\Replay\*` — replay protection stores
- `X402\PaymentHistory\*` — settlement history stores
- `X402\Transport\*` — PSR-18 transport abstractions
- `X402\Errors\*` / `X402\Exceptions\*` — typed error and exception surface
- `X402\Cli\*` — `bin/x402` command surface

### Binary

- `bin/x402` — CLI entrypoint (subcommand list is documented in `README.md`)

### Internal (not covered by semver)

- `X402\Support\*` — internal helpers; do not import from outside the package
- `X402\Extensions\*` — extension-author SPI, may evolve before 1.0
- `X402\Testing\*` — test doubles intended only for downstream test code; signatures may change
- Anything marked `@internal` in PHPDoc

## Removed APIs

<!-- Track removed APIs here so consumers know what was removed when. Example:
- `0.5.0` — Removed `OldClass::oldMethod()`. Migrate to `NewClass::newMethod()`.
-->
