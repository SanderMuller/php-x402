# php-x402

Framework-agnostic PHP implementation of the [x402 payment protocol](https://www.x402.org/).

HTTP 402 stablecoin settlement — pay-per-request APIs without subscriptions, API keys, or fiat rails. EIP-3009 `transferWithAuthorization` on EVM chains via the Coinbase facilitator (or any compatible facilitator).

> **Status:** scaffolding. Not yet usable. See `internal/roadmap.md`.

## Install

```bash
composer require sandermuller/php-x402
```

PHP 8.2+. PSR-7/15/17/18 + PSR-16 cache + PSR-3 logger.

## Surface

| Layer | Class |
|---|---|
| Server middleware | `X402\Server\PaymentEnforcer` (PSR-15) |
| Client decorator | `X402\Client\PayingClient` (PSR-18) |
| Facilitator | `X402\Facilitator\CoinbaseFacilitator` |
| Signing | `X402\Schemes\Evm\AuthorizationSigner` |
| Verification | `X402\Schemes\Evm\SignatureVerifier` |

## Framework adapters

- Laravel: [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402)
- Laravel MCP: [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp)

## License

MIT.
