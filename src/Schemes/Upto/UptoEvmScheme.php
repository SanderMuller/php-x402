<?php

declare(strict_types=1);

namespace X402\Schemes\Upto;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\Constants;
use X402\Schemes\Evm\NetworkRegistry;
use X402\Schemes\SchemeContract;
use X402\Support\JsonReader;

/**
 * `upto` scheme on EVM — usage-based pricing.
 *
 * Spec: `specs/schemes/upto/scheme_upto_evm.md`.
 *
 * Semantics:
 *   - At verify time, `paymentRequirements.amount` is the MAXIMUM the
 *     server is allowed to charge.
 *   - Buyer signs an upto Permit2 authorization where
 *     `permitted.amount == paymentRequirements.amount` (full ceiling).
 *   - Facilitator settles for the actual usage amount (≤ ceiling) and
 *     reports it back via `SettleResult.amount`.
 *
 * What this class does NOT do:
 *   - Allowance check on the Permit2 contract — facilitator owns it.
 *   - Signature recovery — facilitator's `/verify` does it via the
 *     uptoPermit2Proxy on-chain typed-data hash.
 *
 * The signing path lives in `UptoHasher` + `UptoSigner`.
 */
final class UptoEvmScheme implements SchemeContract
{
    public const NAME = 'upto';

    /**
     * @return list<string>
     */
    public function supportedNetworks(): array
    {
        return NetworkRegistry::supportedCaip2();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void
    {
        if ($signature->scheme !== self::NAME) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidScheme,
                sprintf('Expected scheme "%s", got "%s".', self::NAME, $signature->scheme),
            );
        }

        if ($signature->network !== $challenge->network) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidNetwork,
                'Signature network does not match challenge network.',
            );
        }

        $payload = $signature->payload;

        JsonReader::string($payload, 'signature', 'upto payload');
        $auth = JsonReader::array($payload, 'uptoAuthorization', 'upto payload');

        $permitted = JsonReader::array($auth, 'permitted', 'upto authorization');
        $witness = JsonReader::array($auth, 'witness', 'upto authorization');

        $token = JsonReader::string($permitted, 'token', 'upto permitted');
        $amount = JsonReader::string($permitted, 'amount', 'upto permitted');
        $spender = JsonReader::string($auth, 'spender', 'upto authorization');
        JsonReader::string($auth, 'from', 'upto authorization');
        JsonReader::string($auth, 'nonce', 'upto authorization');
        $deadline = JsonReader::int($auth, 'deadline', context: 'upto authorization');
        $witnessTo = JsonReader::string($witness, 'to', 'upto witness');
        $validAfter = JsonReader::int($witness, 'validAfter', context: 'upto witness');
        JsonReader::string($witness, 'facilitator', 'upto witness');

        if (strcasecmp($spender, Constants::X402_UPTO_PERMIT2_PROXY) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                sprintf(
                    'upto spender must be the canonical x402UptoPermit2Proxy (%s), got "%s".',
                    Constants::X402_UPTO_PERMIT2_PROXY,
                    $spender,
                ),
            );
        }

        if (strcasecmp($token, $challenge->asset) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                'upto permitted.token does not match challenge asset.',
            );
        }

        if (strcasecmp($witnessTo, $challenge->payTo) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                'upto witness.to does not match challenge payTo.',
            );
        }

        // The buyer authorises up to the ceiling — `permitted.amount`
        // MUST equal `challenge.amount`. A smaller permitted amount
        // would break the "settle up to max" guarantee; a larger one
        // would allow over-collection.
        if ($this->compareAmount($amount, $challenge->amount) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValueMismatch,
                'upto permitted.amount must equal challenge amount (the ceiling).',
            );
        }

        $now = time();
        if ($validAfter > $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidAfter,
                'upto witness.validAfter is in the future.',
            );
        }

        if ($deadline <= $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidBefore,
                'upto deadline has expired.',
            );
        }
    }

    private function compareAmount(string $a, string $b): int
    {
        $a = ltrim($a, '0') === '' ? '0' : ltrim($a, '0');
        $b = ltrim($b, '0') === '' ? '0' : ltrim($b, '0');

        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return strcmp($a, $b);
    }
}
