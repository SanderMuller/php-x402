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
 * Drop-in `FacilitatorClient` for tests. Returns success or rejection
 * based on construction-time toggles; never hits the network.
 *
 * Use `RecordingFacilitator` instead when a test needs to assert
 * call counts.
 */
final readonly class StubFacilitator implements FacilitatorClient
{
    public function __construct(
        public bool $verifyOk = true,
        public bool $settleOk = true,
        public string $payer = '0xpayer',
        public string $transaction = '0xtxhash',
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        return new VerifyResult(
            isValid: $this->verifyOk,
            invalidReason: $this->verifyOk ? null : 'rejected',
            payer: $this->payer,
        );
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        return new SettleResult(
            success: $this->settleOk,
            transaction: $this->settleOk ? $this->transaction : '',
            network: $challenge->network,
            payer: $this->payer,
            errorReason: $this->settleOk ? null : 'settlement-failed',
        );
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
