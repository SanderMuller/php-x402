# php-x402 Roadmap

What's shipped, what's deferred, and why. Tracks deviations from the
upstream x402 spec. Update this file when scope changes — don't hide
gaps in commit messages.

---

## Shipped (v1)

| Area | Status |
|---|---|
| HTTP transport (v1 + v2 negotiation) | ✅ |
| MCP transport (`_meta["x402/payment"]`) | ✅ |
| A2A transport metadata helpers | ✅ |
| `exact` scheme on EVM (EIP-3009) | ✅ |
| `exact` scheme + Permit2 transfer method (EVM) | ✅ |
| `upto` scheme on EVM (Permit2 + facilitator-bound witness) | ✅ |
| `exact` scheme + ERC-7710 transfer method (delegation chains, shape-only) | ✅ |
| `exact` scheme on Solana (pass-through with shape validation) | ✅ |
| ERC-6492 wrapped-signature decoder + signer wrapper | ✅ |
| ERC-1271 magic-value awareness (verification delegated to facilitator) | ✅ |
| Bazaar `/discovery/resources` client + DTO | ✅ |
| `payment-identifier` extension | ✅ |
| Replay protection (in-memory + interface for distributed stores) | ✅ |
| PSR-15 server middleware + PSR-18 client decorator | ✅ |
| Coinbase facilitator + self-hosted (`x402-rs`) compatibility | ✅ |

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

### `upto` scheme — *(now shipped, see above)*

Originally deferred; full impl landed in this round.
`UptoEvmScheme`, `UptoHasher`, `UptoSigner`, `UptoAuthorization`
ship with the Permit2-based EIP-712 typed data and the canonical
`X402_UPTO_PERMIT2_PROXY` spender. `SettleResult.amount` carries
the actual settled cost back from the facilitator.

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
