# Contributing

Thanks for considering a contribution to `php-x402`. This package is the
framework-agnostic core of the x402 payment protocol on PHP — everything
Laravel/Symfony-shaped lives in downstream adapters, not here.

## Boundary discipline

The package's job is to stay framework-agnostic. CI enforces this via
`tests/Arch/StructureTest.php`:

```
arch('php-x402 stays framework-agnostic')
    ->expect('X402')
    ->not->toUse(['Illuminate', 'Symfony\Component\HttpFoundation', 'Laravel\Mcp']);
```

If your change reaches for `Illuminate\*` or `Symfony\Component\*` from
`src/`, it belongs in [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402)
or a new Symfony adapter — not in this repo.

Do **not** add framework dependencies to `composer.json` `require:`.
Adapters live downstream and inherit our constraints; we don't inherit
theirs.

## Local setup

```bash
composer install
```

Requires PHP `^8.2` (the source of truth is `composer.json`).

## Quality gate

Run before pushing:

```bash
composer qa            # rector + pint + phpstan
composer test          # vendor/bin/pest (Unit + Feature + Arch)
```

The bar:

- **PHPStan** max + bleeding edge + strict rules + cognitive complexity + 100% type coverage. Zero baselines.
- **Rector** with `phpunitCodeQuality` prepared set.
- **Pint** Laravel preset (style only).
- **Pest 3+**, suites: `Unit`, `Feature`, `Arch`.

Adding code that introduces a new error is the same as introducing a
regression. Don't add `@phpstan-ignore` — fix the type narrowing instead.

## Crypto interop

`simplito/elliptic-php` and `bn-php` ship without PHPDoc types. PHPStan
stubs live in `.phpstan/stubs/elliptic-php.stub.php`. Method return types
in stubs are honored; **property types are not** — that's why
`SignatureExporter::toHex65()` does runtime `instanceof BN` narrowing
instead of relying on `Signature::$r: BN`.

If you upgrade either library, re-verify the stub method signatures match
real signatures byte-for-byte. PHPStan silently drops stub types that
don't match real param shapes.

## Conformance

`tests/Fixtures/eip712-vectors.json` mirrors the upstream Coinbase Go
test suite (`go/test/unit/evm_eip712_test.go`). A hash deviation in those
tests is a deviation from the spec — fix the code, not the vectors.

## Public API

`PaymentRequired`, `PaymentSignature`, `PaymentResponse` are wire-format.
Don't change their public shape without a major version bump. The
`Version` enum is the migration seam — every transport, facilitator
call, and DTO either checks it or accepts both shapes. Don't sneak
v2-only fields into v1 paths.

## Replay protection

`NonceStoreContract` is security-critical. The default
`InMemoryNonceStore` is in-process only by design — production hosts
inject Redis-backed PSR-16 or the Laravel adapter's atomic store. If
you touch the store contract, document the atomicity guarantees you're
relying on; a shared-nothing store breaks replay protection.

## Pull requests

1. Branch off `main`.
2. Add tests alongside the change. Tests are how behaviour is pinned
   down — don't ship a verification script when a test can prove the
   same thing.
3. Run `composer qa` and `composer test` clean.
4. If your change touches deferred scope (Solana client-side signing,
   Stellar, ERC-7710 redelegation, RFC 9421, SIWX), update
   [`ROADMAP.md`](ROADMAP.md) — don't hide scope changes in commit
   messages.
5. Open the PR with a clear description of what changed and why.
   Don't include a `CHANGELOG.md` diff — release notes are
   auto-prepended after publish.

## Spec drift

When upstream x402 ships a breaking change:

1. Open a `ROADMAP.md` entry under "Deferred" if we're not adopting
   immediately.
2. If we **are** adopting, bump the package major. Keep the old wire
   format reachable behind a `Version` enum case for one minor cycle.
3. Update `tests/Arch` to enforce the new shape on new code paths.

## Security

Don't open public issues for vulnerabilities. See [`SECURITY.md`](SECURITY.md)
for the disclosure channel.

## License

By contributing you agree your contributions are MIT-licensed, matching
the project license.
