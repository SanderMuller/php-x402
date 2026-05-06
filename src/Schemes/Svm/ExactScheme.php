<?php

declare(strict_types=1);

namespace X402\Schemes\Svm;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\SchemeContract;
use X402\Support\JsonReader;

/**
 * Solana `exact` scheme — partial-signed transaction with the facilitator
 * acting as feePayer.
 *
 * Spec: `specs/schemes/exact/scheme_exact_svm.md`.
 *
 * **Scope of this class (v0.1):**
 *   - Validates wire shape: `payload.transaction` is a non-empty,
 *     base64-decodable string.
 *   - Confirms scheme + network match the challenge.
 *   - Hands the opaque transaction blob through to the facilitator
 *     unchanged in `PaymentSignature.payload`. The facilitator
 *     deserializes the versioned transaction, validates the SPL
 *     token-transfer instruction against the challenge, co-signs as
 *     feePayer, and submits.
 *
 * **What this class does NOT do:**
 *   - Solana transaction deserialization. Versioned tx + ALT support
 *     is a substantial subsystem; deferred per ROADMAP.md.
 *   - ed25519 client signing. Hosts produce the partial-signed blob
 *     upstream (Solana web3.js, hosted wallet, paragonie/sodium_compat
 *     in app code) and pass the base64 string in.
 *   - Account-list validation. The facilitator is the source of truth
 *     for whether the tx actually transfers the expected amount of
 *     the expected SPL mint to `payTo`.
 *
 * Use this scheme today by signing your SPL transfer client-side and
 * delivering the result as `{ "transaction": "<base64>" }`.
 */
final class ExactScheme implements SchemeContract
{
    public const NAME = 'exact';

    public const NETWORK_MAINNET = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public const NETWORK_DEVNET = 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1';

    /**
     * @return list<string>
     */
    public function supportedNetworks(): array
    {
        return [self::NETWORK_MAINNET, self::NETWORK_DEVNET];
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

        if (! \in_array($challenge->network, $this->supportedNetworks(), true)) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidNetwork,
                sprintf('SVM exact scheme does not support network "%s".', $challenge->network),
            );
        }

        $transaction = JsonReader::string($signature->payload, 'transaction', 'SVM payload');

        if ($transaction === '') {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                'SVM payload.transaction must be a non-empty base64 string.',
            );
        }

        $decoded = base64_decode($transaction, true);
        if ($decoded === false) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                'SVM payload.transaction is not valid base64.',
            );
        }

        // A signed Solana versioned transaction carries:
        //   - signature count (compact-u16) + signatures (64 bytes each)
        //   - message: 3-byte header + account-keys array (32 bytes each,
        //     compact-u16 length-prefixed) + 32-byte recent blockhash
        //     + instructions array
        // A minimal viable transfer tx (1 sig, ≥3 account keys, 1 instr)
        // is ~200+ bytes. We use 100 as a loose smoke check — high enough
        // to reject a bare-signature blob (65 bytes) but low enough to
        // not over-claim correctness; the facilitator does the real
        // structural validation.
        if (\strlen($decoded) < 100) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                sprintf('SVM payload.transaction is implausibly short (%d bytes).', \strlen($decoded)),
            );
        }
    }
}
