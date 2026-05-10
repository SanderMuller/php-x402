# php-x402 Roadmap

What's shipped, what's deferred, and why. Tracks deviations from the
upstream x402 spec. Update this file when scope changes — don't hide
gaps in commit messages.

---

## Shipped (v0.1)

| Area                                                                                | Status |
|-------------------------------------------------------------------------------------|--------|
| HTTP transport (v1 + v2 negotiation)                                                | ✅      |
| MCP transport (`_meta["x402/payment"]`)                                             | ✅      |
| A2A transport metadata helpers                                                      | ✅      |
| `exact` scheme on EVM (EIP-3009)                                                    | ✅      |
| `exact` scheme + Permit2 transfer method (EVM)                                      | ✅      |
| `upto` scheme on EVM (Permit2 + facilitator-bound witness)                          | ✅      |
| `exact` scheme + ERC-7710 transfer method (delegation chains, shape-only)           | ✅      |
| `exact` scheme on Solana (pass-through with shape validation)                       | ✅      |
| ERC-6492 wrapped-signature decoder + signer wrapper                                 | ✅      |
| ERC-1271 magic-value awareness (verification delegated to facilitator)              | ✅      |
| Bazaar `/discovery/resources` client + DTO                                          | ✅      |
| `payment-identifier` extension                                                      | ✅      |
| Replay protection (in-memory + interface for distributed stores)                    | ✅      |
| PSR-15 server middleware + PSR-18 client decorator                                  | ✅      |
| `shouldEnforce` predicate — composable enforcement-policy hook on `PaymentEnforcer` | ✅      |
| Coinbase facilitator + self-hosted (`x402-rs`) compatibility                        | ✅      |

---

## Shipped (post-v0.1)

| Version | Change                                                                                                 |
|---------|--------------------------------------------------------------------------------------------------------|
| 0.2.0   | `PaymentResponseCache` (idempotent retry on dropped responses), `RegexPriceTable`, `bin/x402` CLI      |
| 0.2.1   | Replay-gate harden (Permit2-injection bypass closed), `v`-byte validation, `Psr16NonceStore` doc fix   |
| 0.3.0   | `X402\Schemes\ReplayKeyExtractor` opt-in interface; `CallbackNonceStore`; response-header allow-list    |
| 0.3.1   | Deprecation `warning` log when `PaymentEnforcer` BC fallback for non-RKE EIP-3009 schemes fires        |
| 0.4.0   | **Mandatory** `ReplayKeyExtractor` for in-process replay protection — BC fallback removed              |
| 0.5.0   | `X402\Testing\FakeFacilitator` (canonical test double), `X402\Server\BotDetector`, `PaymentSignature::fromArray`, public `PriceParser` |
| 0.5.1   | `PriceParser::toAtomic()` strict-by-default — reject negative / decimal-overflow / non-strict-shape    |
| 0.6.0   | `X402\Facilitator\DispatchingFacilitator` (outcome closures), `PaymentOutcome` / `PaymentOutcomeKind`, `PaymentRowBuilder::fromOutcome()` |
| 0.7.0   | Async settlement (`SettleResult::pending`, 202 path, `SettlePending` outcome), `X402\Webhook\*` primitives, `X402\Client\HdWallet` (BIP-32), `PaymentResponseCacheOptions` DTO |
| 0.8.0   | `X402\Client\KmsWallet` (abstract) + `AwsKmsWallet`, `X402\Support\Asn1DerDecoder` (strict canonical), `X402\Schemes\Evm\EcdsaRecovery`, EIP-2 low-s enforcement in `SignatureExporter`, Wallet conformance test suite |

---

## Deferred — explicit non-goals for v1

These are **known gaps** from the upstream spec. Each entry lists the
trigger that would make us reconsider, plus the rough scope.

### `exact` on Solana / SVM — client-side signing

- **Status**: Wire shape supported (`SvmExactScheme` validates
  base64 transaction blob); client-side signing not implemented.
- **Why deferred**: Needs ed25519 signing (`paragonie/sodium_compat`
  or ext-sodium) plus Solana versioned-transaction serialization
  with address-lookup tables. ~1.5 KLOC with its own test matrix;
  rarely the first ask from PHP-shop hosts.
- **Re-trigger**: First user issue requesting client-side SVM
  signing, OR a host needs a self-contained PHP signer (no Node
  bridge).
- **Workaround today**: Build the partial-signed transaction
  upstream (Node SDK, hosted wallet, Solana web3.js), base64-
  encode it, and pass it into `PaymentSignature.payload.transaction`.
  `SvmExactScheme::verifyShape` validates wire shape; facilitator
  handles deserialisation, instruction validation, fee-payer
  co-signing, and submission.

### `exact` on Stellar / Soroban

- **Status**: Not implemented.
- **Why deferred**: Stellar uses ed25519 + its own `sep-10` auth
  shape. Smaller community of PHP+Stellar users than EVM/SVM.
- **Re-trigger**: Same pattern as SVM — wait for first ask.

### ERC-7710 delegation — client-side signing

- **Status**: Shape-only. `Erc7710Scheme` validates the wire
  format (`delegations[]` with `delegate`, `delegator`, `authority`,
  `caveats[]`, `salt`, `signature`); `Delegation` + `Caveat` DTOs
  ship for callers to build payloads. Signing not implemented.
- **Why deferred**: The Delegation Framework's EIP-712 hash chain
  uses a per-chain verifying contract address (MetaMask Delegation
  Manager). Computing the redelegation chain hash requires walking
  parent delegations and a contract registry we don't ship. Spec
  is also still iterating upstream.
- **Re-trigger**: MetaMask publishes stable per-chain Delegation
  Manager addresses AND a host needs PHP-side signing. Today,
  hosts sign with the MetaMask Snap / Permissionless.js / Biconomy
  client SDK and pass the wrapped delegation in.

### HTTP Message Signatures (RFC 9421)

- **Status**: Not implemented as a transport layer.
- **Why deferred**: Optional in the x402 spec — current transports
  (HTTP header / MCP `_meta` / A2A metadata) cover the documented
  use cases. Adding signed messages adds canonicalisation logic
  and a key-rotation surface that 99% of integrations don't touch.
- **Re-trigger**: Facilitator vendors require it for compliance
  reasons.

### Per-request challenge augmentation (`ChallengeFilter` hook)

- **Status**: Not implemented. `shouldEnforce` (shipped) gates the
  pipeline yes/no; there's no hook to *mutate* the challenge list
  per request.
- **Why deferred**: Two slots is one too many until the use case
  is concrete. `shouldEnforce` already covers gating; a separate
  filter only earns its place when adapters need to re-price per
  request (e.g. "bots pay $0.01, paid users pay $0.001 on the same
  resource") and `PriceTable::challengesFor()` keyed on resource
  alone isn't enough.
- **Re-trigger**: Downstream adapter (laravel-x402 or other) hits a
  pricing tier that can't be expressed by routing the request to a
  different `resource` string. At that point: add
  `?Closure(ServerRequestInterface, PaymentRequired[]): PaymentRequired[]`
  after `shouldEnforce` in the ctor; do not generalize speculatively.
- **Workaround today**: Resolve to a different `resource` string in
  the adapter's resource resolver based on request context, and let
  the existing `PriceTable` lookup pick the right challenge list.

### Sign-in-with-X (SIWX / SIWE)

- **Status**: Not implemented.
- **Why deferred**: Out-of-band auth concern, not a payment
  concern. Hosts can wire SIWE separately via Laravel Socialite or
  any OIDC provider; mixing it into x402 conflates payment
  authentication with identity.
- **Re-trigger**: Spec moves SIWX from "may" to "must" for any
  transport we support.

---

## Spec drift policy

When the upstream x402 spec ships a breaking change:

1. Open a ROADMAP entry under "Deferred" if we're not adopting
   immediately.
2. If we ARE adopting, bump the package major version. Keep the
   old wire format reachable behind a `Version` enum case for
   one minor cycle.
3. Update `tests/Arch` to enforce the new shape on new code paths.

The `Version` enum is the migration seam — every transport,
facilitator call, and DTO either checks it or accepts both shapes.
Don't sneak v2-only fields into v1 paths.
