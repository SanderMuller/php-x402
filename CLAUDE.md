# CLAUDE.md — php-x402

Framework-agnostic PHP implementation of the [x402 payment protocol](https://www.x402.org/). HTTP 402 stablecoin settlement over EVM chains via EIP-3009.

## Foundational context

- **Plain PHP library**, NOT a Laravel application — no `artisan`, no `app/`, no `.env`.
- Public API surface: PSR-15 server middleware (`X402\Server\PaymentEnforcer`), PSR-18 client decorator (`X402\Client\PayingClient`), DTOs (`X402\Protocol\*`), facilitator interface (`X402\Facilitator\FacilitatorClient`).
- Downstream packages: `sandermuller/laravel-x402` (Laravel adapter), `sandermuller/laravel-x402-mcp` (laravel/mcp bridge). Anything ANY upstream Laravel/Symfony package depends on must NEVER appear in `require:`.
- `composer.json` is the source of truth for supported PHP versions. Currently `^8.2`.

## Boundary discipline

The package's job is to be framework-agnostic. CI guards this via the arch test in `tests/Arch/StructureTest.php`:

```
arch('php-x402 stays framework-agnostic')
    ->expect('X402')
    ->not->toUse(['Illuminate', 'Symfony\Component\HttpFoundation', 'Laravel\Mcp']);
```

If you find yourself reaching for an `Illuminate\*` class from `src/`, you are in the wrong package — it belongs in `laravel-x402`.

## Testing

- Test runner: `vendor/bin/pest` (Pest 3+).
- Test suites: `Unit`, `Feature`, `Arch` — wired in `phpunit.xml.dist`.
- Conformance vectors live in `tests/Fixtures/eip712-vectors.json`. Inputs match the official Coinbase Go test suite (`go/test/unit/evm_eip712_test.go`) so a hash deviation here is a deviation from the upstream spec.

## Quality bar

- PHPStan max + bleeding edge + strict rules + cognitive_complexity + type_coverage 100%.
- Rector with `phpunitCodeQuality` prepared set.
- Pint Laravel preset (style only — Pint runs on plain PHP just fine).
- Zero baselines. Adding code that introduces a new error is the same as introducing a regression.

Run the full QA chain: `composer qa` (rector + format + phpstan).

## Crypto interop

The simplito/elliptic-php and bn-php libraries ship without PHPDoc types. PHPStan stub overrides live in `.phpstan/stubs/elliptic-php.stub.php`. Method return types in stubs are honored by PHPStan; **property types are not** — that's why `SignatureExporter::toHex65()` does runtime `instanceof BN` narrowing instead of relying on `Signature::$r: BN`.

If you upgrade either library, re-verify the stub method signatures match real signatures byte-for-byte. PHPStan silently drops stub types that don't match real param shapes.

## Replay protection — required

Server enforcement guards `(network, from, nonce)` via `NonceStoreContract`. The default `InMemoryNonceStore` is in-process only — production hosts MUST inject a Redis-backed PSR-16 store (or use `LaravelNonceStore` from the Laravel adapter, which is `Cache::add()` atomic).

A misconfigured / shared-nothing nonce store breaks replay protection. Treat this as a security-critical contract.

## Verification before completion

- Run `composer qa` (rector + pint + phpstan) before claiming a change is done.
- `vendor/bin/pest` must pass — including the arch tests.
- Don't add `@phpstan-ignore` comments. If PHPStan flags real code, fix the type narrowing instead.

## What NOT to do

- Don't add `Illuminate\*` or `Symfony\Component\*` to `require:`. Adapters live downstream.
- Don't change the public DTO shape (`PaymentRequired`, `PaymentSignature`, `PaymentResponse`) without a major version bump — it's wire-format.
- Don't replace `JsonReader` with inline `(string) $array['key']` casts — PHPStan rejects mixed-cast at level max strict.
- Don't silently swallow facilitator failures in `CoinbaseFacilitator` — throw `FacilitatorException` so callers can decide.
