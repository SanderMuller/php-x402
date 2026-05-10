# php-x402

Framework-agnostic PHP implementation of the [x402 payment protocol](https://www.x402.org/).

HTTP 402 stablecoin settlement. Pay-per-request APIs without subscriptions, API keys, or fiat rails. EIP-3009 `transferWithAuthorization` on EVM chains via the Coinbase facilitator (or any compatible facilitator).

> [!NOTE]
> Pre-1.0 (`0.x`). Public surface is feature-complete for v1 of the spec: HTTP / MCP / A2A transports, `exact` + `upto` schemes on EVM, ERC-7710 shape, SVM pass-through, replay protection, response-cache idempotency, and Bazaar discovery. See [`ROADMAP.md`](https://github.com/SanderMuller/php-x402/blob/main/ROADMAP.md) for what's shipped vs. deferred.

## What it does

The server middleware drops a 402 challenge on protected resources, verifies and settles signed payments via a facilitator, then hands the request to the inner handler. The client decorator auto-pays 402 responses by signing an EIP-3009 authorization with your operator wallet and retrying. Both pieces are pure PSR-7/15/17/18 + PSR-16/3, so they wire into Slim, Mezzio, raw Symfony, or the Laravel adapter.

## Install

```bash
composer require sandermuller/php-x402
```

Requires PHP `^8.2`. Pulls in PSR-7/15/17/18, PSR-16 cache, PSR-3 logger.

Optional extensions for performance:

- `ext-secp256k1`: ~50× faster signature verification.
- `ext-gmp`: required for BigInteger math in signature recovery.

## Quick start

### Server: gate a resource behind 402

```php
use X402\Facilitator\CoinbaseFacilitator;
use X402\Protocol\PaymentRequired;
use X402\Schemes\Evm\ExactScheme;
use X402\Server\PaymentEnforcer;
use X402\Server\StaticPriceTable;

$priceTable = new StaticPriceTable();
$priceTable->set('/premium', new PaymentRequired(
    scheme: 'exact',
    network: 'eip155:8453',                  // Base mainnet
    amount: '10000',                          // 0.01 USDC (6 decimals)
    asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
    payTo: '0xYourReceivingAddress',
));

$middleware = new PaymentEnforcer(
    priceTable:      $priceTable,
    facilitator:     CoinbaseFacilitator::default($psr18Client, $psr17),
    nonceStore:      $atomicNonceStore,                  // see Replay protection
    schemes:         ['exact' => new ExactScheme()],
    responseFactory: $psr17,
    streamFactory:   $psr17,
);
```

Pipe `$middleware` through any PSR-15 dispatcher.

### Client: pay automatically on 402

```php
use X402\Client\PayingClient;
use X402\Client\PrivateKeyWallet;

$client = new PayingClient(
    inner:  $psr18Client,                       // Guzzle, Symfony HttpClient, etc.
    wallet: new PrivateKeyWallet($operatorPk),  // or your KMS-backed Wallet
);

$response = $client->sendRequest($request);     // 402 → sign → retry → 200
```

`PrivateKeyWallet` is fine for tests and CLI tools. **For production, implement `X402\Client\Wallet` against a KMS** (AWS, GCP, HSM) so private keys never sit in process memory. See [`docs/kms.md`](https://github.com/SanderMuller/php-x402/blob/main/docs/kms.md) for the contract details, AWS-KMS reference impl, and the three rules every adapter must follow (low-s normalization, DER → raw conversion, recovery-id derivation).

## Surface

| Layer              | Class                                                              |
|--------------------|--------------------------------------------------------------------|
| Server middleware  | `X402\Server\PaymentEnforcer` (PSR-15)                             |
| Response cache     | `X402\Server\PaymentResponseCache` (PSR-15)                        |
| Price table        | `X402\Server\StaticPriceTable`, `RegexPriceTable`                  |
| Bot detection      | `X402\Server\BotDetector`                                          |
| Idempotency key    | `X402\Server\IdempotencyKeyBuilder` (transport-agnostic)           |
| Client decorator   | `X402\Client\PayingClient` (PSR-18)                                |
| Facilitator        | `X402\Facilitator\CoinbaseFacilitator`                             |
| Event hooks        | `X402\Facilitator\DispatchingFacilitator` (wraps any FacilitatorClient, fires closures on every outcome) |
| Outcome DTO        | `X402\Facilitator\PaymentOutcome`, `PaymentOutcomeKind` (enum)     |
| Payment history    | `X402\PaymentHistory\PaymentRowBuilder` (flat-row helper)          |
| Schemes (opt-in)   | `X402\Schemes\ReplayKeyExtractor` (per-scheme replay extraction)   |
| Signing            | `X402\Schemes\Evm\AuthorizationSigner`, `Eip712Hasher`             |
| Verification       | `X402\Schemes\Evm\SignatureVerifier`                               |
| Replay store       | `X402\Replay\NonceStoreContract`, `CallbackNonceStore`             |
| Decimal helper     | `X402\Support\PriceParser`                                         |
| Testing helpers    | `X402\Testing\PaymentRequiredBuilder`, `FakeFacilitator`           |
| CLI                | `bin/x402 decode <header>`                                         |

## Composing policy

`PaymentEnforcer` accepts an optional `shouldEnforce` predicate that gates the entire pipeline per request. Useful for bot-only payment, IP allowlists, geo policy, or plan-tier exemption:

```php
use Psr\Http\Message\ServerRequestInterface;
use X402\Server\PaymentEnforcer;

$middleware = new PaymentEnforcer(
    // ... priceTable, facilitator, nonceStore, schemes, factories ...
    shouldEnforce: fn (ServerRequestInterface $request): bool
        => str_starts_with($request->getUri()->getPath(), '/api/paid/'),
);
```

Predicate returns `false` → inner handler runs, no challenge / no nonce claim / no facilitator hit. Default (`null`) = always enforce. Compose multiple policies downstream: `fn ($r) => $bot($r) && $geo($r) && $plan($r)`.

`X402\Server\BotDetector` ships a curated list of AI agents / assistants / scrapers / search crawlers (~70 patterns from <https://knownagents.com>) for the bot-only-payment shape:

```php
use X402\Server\BotDetector;

$detector = new BotDetector(extra: ['MyCustomCrawler']);

$middleware = new PaymentEnforcer(
    // ...
    shouldEnforce: static fn (ServerRequestInterface $request): bool
        => $detector->isBot($request->getHeaderLine('User-Agent')),
);
```

Override the default list with `patterns:`, extend it with `extra:`, or pass `patterns: []` to disable detection. Match is case-insensitive substring on the User-Agent.

## Response-cache idempotency

`PaymentResponseCache` is a separate PSR-15 middleware that sits **before** `PaymentEnforcer` in the chain. It caches paid 2xx responses keyed by `(network, from, nonce, signature bytes)` and replays the cached body on duplicates, so a client whose connection drops between facilitator settle and response delivery sees the paid response on retry instead of a 402.

```php
use X402\Server\PaymentResponseCache;

// Pipeline: PaymentResponseCache → PaymentEnforcer → handler
$pipeline = [
    new PaymentResponseCache($psr16Cache, $psr17, $psr17, schemes: ['exact' => new ExactScheme()], ttl: 3600),
    $enforcer,
];
```

Same Redis-backed PSR-16 store as the nonce store. TTL should comfortably exceed the nonce TTL so retries past nonce expiry still hit the cache.

## Replay protection

> [!IMPORTANT]
> Replay protection requires an **atomic** "set-if-absent with TTL" store: Redis `SET key value NX EX ttl` or equivalent. Two concurrent requests carrying the same `(network, from, nonce)` MUST resolve to a single winner; anything else lets a settled signature replay.
>
> - `InMemoryNonceStore`: in-process only, single worker.
> - `Psr16NonceStore`: `has() + set()` on PSR-16. **NOT atomic** (PSR-16 has no add-if-absent primitive). Acceptable for tests and single-worker dev only. A small race window allows two workers to both claim the same nonce and both settle.
> - Production: use `LaravelNonceStore` (in [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402), backed by `Cache::add()`), or implement `NonceStoreContract` against Redis `SETNX EX` directly. Anything else breaks the security contract.

## Event hooks and payment history

`DispatchingFacilitator` wraps any `FacilitatorClient` and fires a closure with a `PaymentOutcome` on every verify / settle outcome — `VerifyRejected`, `VerifyError`, `SettleSucceeded`, `SettleFailed`, `SettleError`. Adopters wire host-specific event dispatch (Symfony EventDispatcher, log channels, metrics) inside the closure:

```php
use X402\Facilitator\DispatchingFacilitator;
use X402\Facilitator\PaymentOutcome;
use X402\Facilitator\PaymentOutcomeKind;
use X402\PaymentHistory\PaymentRowBuilder;

$facilitator = new DispatchingFacilitator(
    inner: CoinbaseFacilitator::default($psr18Client, $psr17),
    onOutcome: function (PaymentOutcome $outcome, array $context) use ($db): void {
        $db->insert('x402_payments', PaymentRowBuilder::fromOutcome($outcome, $context));
    },
    captureContext: fn (): array => ['user_id' => $request->getAttribute('user')?->id],
);
```

`PaymentRowBuilder::fromOutcome()` returns a flat array matching the laravel-x402 `x402_payments` migration shape — adopters using Eloquent feed it directly into `Payment::query()->updateOrCreate(...)`. Listener and context-capture exceptions on `*-error` paths are silently swallowed so the original facilitator throwable always propagates to the caller.

## Framework adapters

- Laravel: [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402)
- Laravel MCP: [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp)

## Testing

```bash
composer test          # vendor/bin/pest
composer qa            # rector + pint + phpstan (auto-fix variants)
composer ci            # all gates in --dry-run mode (suitable for CI / pre-push)
```

For adopter integration tests, the `X402\Testing` namespace ships:

- `PaymentRequiredBuilder`: fluent USDC-on-Base / Base-Sepolia helpers, atomic-unit conversion via `X402\Support\PriceParser` (since 0.4.1).
- `FakeFacilitator`: canonical test double. Settles locally, configures outcomes via `rejectVerify('reason')` / `failSettle('reason')` mid-test, records full signature + challenge payload on every call, and ships PHPUnit assertion helpers (`assertVerified`, `assertSettled`, `assertNothingSettled`).

Conformance vectors in `tests/Fixtures/eip712-vectors.json` mirror the upstream Coinbase Go test suite. A hash deviation here is a deviation from the spec.

## Roadmap

See [`ROADMAP.md`](https://github.com/SanderMuller/php-x402/blob/main/ROADMAP.md) for shipped scope and explicit non-goals (Solana client-side signing, Stellar, ERC-7710 redelegation chain hashing, RFC 9421, SIWX).

## Changelog

See [`CHANGELOG.md`](https://github.com/SanderMuller/php-x402/blob/main/CHANGELOG.md). Updated automatically from GitHub release notes.

## Upgrading

See [`UPGRADING.md`](https://github.com/SanderMuller/php-x402/blob/main/UPGRADING.md) for migration notes between minor / major bumps (`0.2.x` → `0.3.0`, `0.3.x` → `0.4.0`).

## Contributing

See [`CONTRIBUTING.md`](https://github.com/SanderMuller/php-x402/blob/main/CONTRIBUTING.md) for boundary rules, the QA bar, the crypto-stub gotcha, and spec-drift policy.

## Security

See [`SECURITY.md`](https://github.com/SanderMuller/php-x402/blob/main/SECURITY.md) for vulnerability reporting.

## License

MIT.
