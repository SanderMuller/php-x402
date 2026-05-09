<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\ReplayKeyExtractor;
use X402\Schemes\SchemeContract;
use X402\Support\JsonReader;

/**
 * `exact` scheme + `permit2` transfer method (universal ERC-20 fallback
 * when the token doesn't implement EIP-3009).
 *
 * Spec: `specs/schemes/exact/scheme_exact_evm.md` §Permit2 transfer method.
 *
 * What this class does:
 *   - Validates Permit2Authorization payload shape
 *   - Confirms `spender == X402_EXACT_PERMIT2_PROXY` (CREATE2 same on every EVM chain)
 *   - Confirms `permitted.token` matches challenge `asset`
 *   - Confirms `permitted.amount` ≤ challenge `amount`
 *   - Confirms `witness.to` matches challenge `payTo`
 *   - Time-window enforcement via `witness.validAfter` + `permit.deadline`
 *
 * What this class does NOT do:
 *   - On-chain allowance check. Facilitator handles it; if missing,
 *     responds HTTP 412 with `errorReason = "PERMIT2_ALLOWANCE_REQUIRED"`.
 *   - Signature recovery. Facilitator's `/verify` does the EIP-712
 *     signature recovery via the Permit2 contract's typed-data hash.
 *
 * The signing path lives in `Permit2Hasher`; client wallets sign the
 * resulting digest with their EOA / smart-wallet signer.
 */
final class Permit2Scheme implements ReplayKeyExtractor, SchemeContract
{
    public const NAME = 'exact';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function supportedNetworks(): array
    {
        return NetworkRegistry::supportedCaip2();
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

        // Routing — only handle when the challenge declares Permit2.
        $declaredMethod = $challenge->extra['assetTransferMethod'] ?? null;
        if ($declaredMethod !== Constants::TRANSFER_METHOD_PERMIT2) {
            throw InvalidPaymentException::with(
                ErrorReason::UnsupportedScheme,
                sprintf('Permit2Scheme requires extra.assetTransferMethod="permit2", got "%s".', is_string($declaredMethod) ? $declaredMethod : '(unset)'),
            );
        }

        $payload = $signature->payload;

        JsonReader::string($payload, 'signature', 'Permit2 payload');
        $auth = JsonReader::array($payload, 'permit2Authorization', 'Permit2 payload');

        $permitted = JsonReader::array($auth, 'permitted', 'Permit2 authorization');
        $witness = JsonReader::array($auth, 'witness', 'Permit2 authorization');

        $token = JsonReader::string($permitted, 'token', 'Permit2 permitted');
        $amount = JsonReader::string($permitted, 'amount', 'Permit2 permitted');
        $spender = JsonReader::string($auth, 'spender', 'Permit2 authorization');
        JsonReader::string($auth, 'from', 'Permit2 authorization');
        JsonReader::string($auth, 'nonce', 'Permit2 authorization');
        $deadline = JsonReader::int($auth, 'deadline', context: 'Permit2 authorization');
        $witnessTo = JsonReader::string($witness, 'to', 'Permit2 witness');
        $validAfter = JsonReader::int($witness, 'validAfter', context: 'Permit2 witness');

        if (strcasecmp($spender, Constants::X402_EXACT_PERMIT2_PROXY) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                sprintf(
                    'Permit2 spender must be the canonical x402ExactPermit2Proxy (%s), got "%s".',
                    Constants::X402_EXACT_PERMIT2_PROXY,
                    $spender,
                ),
            );
        }

        if (strcasecmp($token, $challenge->asset) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                'Permit2 permitted.token does not match challenge asset.',
            );
        }

        if (strcasecmp($witnessTo, $challenge->payTo) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                'Permit2 witness.to does not match challenge payTo.',
            );
        }

        if ($this->compareAmount($amount, $challenge->amount) > 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValueMismatch,
                'Permit2 permitted.amount exceeds challenge amount.',
            );
        }

        $now = time();
        if ($validAfter > $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidAfter,
                'Permit2 witness.validAfter is in the future.',
            );
        }

        if ($deadline <= $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidBefore,
                'Permit2 deadline has expired.',
            );
        }
    }

    /**
     * @return array{from: string, nonce: string, expiresAt: int}
     */
    public function replayKey(PaymentSignature $signature): array
    {
        // Mirror verifyShape exactly: JsonReader::string coerces numeric
        // JSON values to string. Using stringOrNull instead would let
        // a numeric `nonce: 123` pass verifyShape but produce a null
        // replay key, silently skipping the in-process claim.
        $auth = JsonReader::array($signature->payload, 'permit2Authorization', 'Permit2 payload');

        return [
            'from' => JsonReader::string($auth, 'from', 'Permit2 authorization'),
            'nonce' => JsonReader::string($auth, 'nonce', 'Permit2 authorization'),
            'expiresAt' => JsonReader::int($auth, 'deadline', context: 'Permit2 authorization'),
        ];
    }

    /**
     * Compare two stringified non-negative integers without overflowing PHP int.
     */
    private function compareAmount(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if ($a === '') {
            $a = '0';
        }

        if ($b === '') {
            $b = '0';
        }

        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return strcmp($a, $b);
    }
}
