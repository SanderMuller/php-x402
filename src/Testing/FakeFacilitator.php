<?php

declare(strict_types=1);

namespace X402\Testing;

use PHPUnit\Framework\Assert;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Test double facilitator. Records every verify/settle call and lets
 * tests configure outcomes — pass/reject without hitting Coinbase or
 * any network.
 *
 * Removed in 0.6.0: `StubFacilitator` (configurable outcomes) and
 * `RecordingFacilitator` (call capture). Adopters migrating from
 * either pre-0.5.0 import: this class is a functional superset of
 * both — swap the import.
 *
 * Usage:
 *
 *     $fake = new FakeFacilitator();
 *
 *     // Tweak outcomes
 *     $fake->rejectVerify('insufficient-funds');
 *     $fake->failSettle('on-chain-revert');
 *
 *     // Run the system under test, then assert
 *     $fake->assertVerified();
 *     $fake->assertSettled('https://example.test/premium');
 *     $fake->assertNothingSettled();
 *
 * Sourced from real-world adapter dogfood (originally lived in the
 * `laravel-x402` adapter as `X402\Laravel\Testing\FakeFacilitator`;
 * lifted upstream so non-Laravel adapters share one canonical test
 * double).
 *
 * **Note on dependencies:** this class uses
 * `PHPUnit\Framework\Assert` for the `assert*()` helpers. php-x402
 * requires `pestphp/pest` in dev, which transitively pulls phpunit;
 * test suites that don't depend on phpunit at all should keep using a
 * non-asserting stub. This class is mutable by design (ctor-default
 * state plus `rejectVerify()` / `failSettle()` mutators) — `final` but
 * not `readonly`.
 */
final class FakeFacilitator implements FacilitatorClient
{
    public bool $verifyOk = true;

    public ?string $verifyReason = null;

    public bool $settleOk = true;

    public ?string $settleReason = null;

    public string $payer = '0xpayer';

    public string $transaction = '0xtxhash';

    /**
     * @var list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    private array $verifyCalls = [];

    /**
     * @var list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    private array $settleCalls = [];

    /**
     * Indexed in lockstep with $verifyCalls.
     *
     * @var list<VerifyResult>
     */
    private array $verifyResults = [];

    /**
     * Indexed in lockstep with $settleCalls.
     *
     * @var list<SettleResult>
     */
    private array $settleResults = [];

    /**
     * @return list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    public function verifyCalls(): array
    {
        return $this->verifyCalls;
    }

    /**
     * @return list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    public function settleCalls(): array
    {
        return $this->settleCalls;
    }

    /**
     * @return list<VerifyResult>
     */
    public function verifyResults(): array
    {
        return $this->verifyResults;
    }

    /**
     * @return list<SettleResult>
     */
    public function settleResults(): array
    {
        return $this->settleResults;
    }

    public function rejectVerify(string $reason = 'rejected'): self
    {
        $this->verifyOk = false;
        $this->verifyReason = $reason;

        return $this;
    }

    public function failSettle(string $reason = 'settlement-failed'): self
    {
        $this->settleOk = false;
        $this->settleReason = $reason;

        return $this;
    }

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        $this->verifyCalls[] = ['signature' => $signature, 'challenge' => $challenge];

        $result = new VerifyResult(
            isValid: $this->verifyOk,
            invalidReason: $this->verifyOk ? null : ($this->verifyReason ?? 'rejected'),
            payer: $this->payer,
        );

        $this->verifyResults[] = $result;

        return $result;
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        $this->settleCalls[] = ['signature' => $signature, 'challenge' => $challenge];

        $result = new SettleResult(
            success: $this->settleOk,
            transaction: $this->settleOk ? $this->transaction : '',
            network: $challenge->network,
            payer: $this->payer,
            errorReason: $this->settleOk ? null : ($this->settleReason ?? 'settlement-failed'),
        );

        $this->settleResults[] = $result;

        return $result;
    }

    public function supported(): SupportedKinds
    {
        return new SupportedKinds(kinds: []);
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
    }

    public function assertVerified(?string $resource = null): void
    {
        Assert::assertNotEmpty($this->verifyCalls, 'Expected facilitator->verify to be called.');

        if ($resource !== null) {
            $hit = array_filter(
                $this->verifyCalls,
                static fn (array $call): bool => $call['challenge']->resource === $resource,
            );
            Assert::assertNotEmpty($hit, sprintf('Expected verify for resource "%s".', $resource));
        }
    }

    public function assertSettled(?string $resource = null): void
    {
        Assert::assertNotEmpty($this->settleCalls, 'Expected facilitator->settle to be called.');

        if ($resource !== null) {
            $hit = array_filter(
                $this->settleCalls,
                static fn (array $call): bool => $call['challenge']->resource === $resource,
            );
            Assert::assertNotEmpty($hit, sprintf('Expected settle for resource "%s".', $resource));
        }
    }

    public function assertNothingSettled(): void
    {
        Assert::assertEmpty($this->settleCalls, 'Expected no settlement calls.');
    }
}
