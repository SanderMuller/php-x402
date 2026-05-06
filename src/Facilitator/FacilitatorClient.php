<?php

declare(strict_types=1);

namespace X402\Facilitator;

use X402\Exceptions\FacilitatorException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Pluggable facilitator. The default implementation calls Coinbase's hosted
 * facilitator at https://x402.org/facilitator (or the CDP-managed variant
 * for authenticated/higher-volume use).
 */
interface FacilitatorClient
{
    /**
     * Verify the signed payload's correctness off-chain (signature, balance,
     * nonce uniqueness, simulation). Does NOT settle.
     *
     * @throws FacilitatorException On transport failure.
     */
    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult;

    /**
     * Broadcast settlement on-chain. Idempotent on (nonce, from) per the
     * EIP-3009 spec — duplicate submissions return the original transaction.
     *
     * @throws FacilitatorException On transport failure.
     */
    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult;

    /**
     * Capability advertisement (`GET /supported`). Lets hosts pre-check at
     * boot whether the facilitator covers their `(scheme, network)` pair
     * before going live, and discover available extensions + signer
     * addresses.
     *
     * Spec v2 §7. The Coinbase-hosted facilitator implements this; some
     * self-hosted setups may not — implementations may throw
     * `FacilitatorException` to indicate the endpoint is unavailable.
     *
     * @throws FacilitatorException On transport failure or unsupported endpoint.
     */
    public function supported(): SupportedKinds;

    /**
     * Bazaar marketplace listing — `GET /discovery/resources`. Returns a
     * paginated catalogue of paid endpoints the facilitator has indexed.
     *
     * Spec: `specs/extensions/bazaar.md` + facilitator-protocol.txt.
     * Filterable by protocol type (`"http"` or `"mcp"`) via `$query->type`.
     *
     * @throws FacilitatorException On transport failure or unsupported endpoint.
     */
    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage;
}
