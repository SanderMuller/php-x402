<?php

declare(strict_types=1);

namespace X402\Testing;

use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Test-only `FacilitatorClient` that counts verify/settle calls. Useful
 * for asserting "the facilitator was (not) hit" — e.g. when a
 * `shouldEnforce` predicate skips the pipeline.
 *
 * Always returns success. For rejection paths, use `StubFacilitator`
 * with the toggles flipped.
 *
 * @deprecated since 0.5.0; use `X402\Testing\FakeFacilitator` instead.
 *             FakeFacilitator records full call payloads (signature +
 *             challenge), exposes the count via `verifyCalls()` /
 *             `settleCalls()`, AND lets tests assert via
 *             `assertVerified()` / `assertSettled()` /
 *             `assertNothingSettled()` PHPUnit helpers. This class
 *             stays unchanged through 0.5.x and is removed in 0.6.0.
 *             Migrate by swapping the import; `verifyCalls` /
 *             `settleCalls` properties → `verifyCalls()` /
 *             `settleCalls()` methods returning the recorded payloads.
 */
final class RecordingFacilitator implements FacilitatorClient
{
    public int $verifyCalls = 0;

    public int $settleCalls = 0;

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        ++$this->verifyCalls;

        return new VerifyResult(isValid: true, invalidReason: null, payer: '0xpayer');
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        ++$this->settleCalls;

        return new SettleResult(success: true, transaction: '0xtxhash', network: $challenge->network, payer: '0xpayer');
    }

    public function supported(): SupportedKinds
    {
        return new SupportedKinds(kinds: []);
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
    }
}
