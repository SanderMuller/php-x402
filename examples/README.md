# Examples

Two-process demo of the full x402 handshake. No real network calls,
no real funds — uses `X402\Testing\StubFacilitator` so the server
"settles" payments locally.

## Run

```bash
# Terminal 1 — server (listens on :8080, gates /premium at 0.01 USDC)
php -S localhost:8080 examples/server.php

# Terminal 2 — client (signs the 402, retries, prints the protected body)
php examples/client.php
```

Expected client output:

```
Status: 200 OK
Receipt:  {"success":true,"transaction":"0xtxhash","network":"eip155:8453","payer":"0xpayer"}
Body:     {"secret":"the answer is 42"}
```

## What the demo shows

1. Client GETs `/premium` with no payment header.
2. Server's `PaymentEnforcer` looks up the resource in `StaticPriceTable`,
   finds a 0.01 USDC challenge, returns `402 Payment Required` with
   the `PAYMENT-REQUIRED` body.
3. `PayingClient` decodes the 402, signs an EIP-3009 authorization
   with the demo wallet, retries with `PAYMENT-SIGNATURE` set.
4. Server verifies the signature shape, claims the nonce in the
   in-memory store, calls the (stub) facilitator, attaches a
   `PAYMENT-RESPONSE` receipt, hands off to the inner handler.
5. Inner handler returns the protected body. Client prints it.

## What it doesn't show

- **Real settlement.** `StubFacilitator` always returns success and
  does no on-chain work. To hit a live testnet, swap in
  `CoinbaseFacilitator(baseUrl: 'https://x402.org/facilitator')` and
  fund the demo wallet's address with Base Sepolia USDC.
- **Replay protection across workers.** The example uses
  `InMemoryNonceStore` (PaymentEnforcer's default). Production hosts
  inject `Psr16NonceStore` against Redis — see the README's
  Replay-protection section.
- **`PaymentResponseCache`.** Production hosts also install this
  before the enforcer to cover the "paid but didn't receive" gap.
  See `src/Server/PaymentResponseCache.php`.
- **KMS-backed wallets.** `PrivateKeyWallet` with a hard-coded key
  is fine here. Production uses
  [docs/kms.md](https://github.com/SanderMuller/php-x402/blob/main/docs/kms.md).

## CLI debugging

If a PAYMENT-SIGNATURE header looks wrong, decode it:

```bash
echo "<header-value>" | bin/x402 decode -
```
