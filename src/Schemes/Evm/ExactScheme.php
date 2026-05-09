<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\ReplayKeyExtractor;
use X402\Schemes\SchemeContract;
use X402\Support\DecimalCompare;
use X402\Support\JsonReader;

/**
 * EVM "exact" scheme — EIP-3009 transferWithAuthorization signed via EIP-712.
 *
 * Reference: https://github.com/coinbase/x402/blob/main/specs/schemes/exact/scheme_exact_evm.md
 */
final readonly class ExactScheme implements ReplayKeyExtractor, SchemeContract
{
    public const NAME = 'exact';

    /**
     * @param  list<string>|null  $networks  Override the default registry. Pass null to use NetworkRegistry::supportedCaip2().
     */
    public function __construct(private ?array $networks = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function supportedNetworks(): array
    {
        return $this->networks ?? NetworkRegistry::supportedCaip2();
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

        // Spec exact-EVM defines three transfer methods via
        // `extra.assetTransferMethod` — `eip3009` (default), `permit2`, `erc7710`.
        // This class implements EIP-3009 only; reject the others explicitly so
        // clients get a clean `unsupported_scheme` rather than a confusing
        // shape-mismatch chain further down.
        $declaredMethod = $challenge->extra['assetTransferMethod'] ?? Constants::TRANSFER_METHOD_EIP3009;
        if (! is_string($declaredMethod)) {
            $declaredMethod = Constants::TRANSFER_METHOD_EIP3009;
        }

        if ($declaredMethod !== Constants::TRANSFER_METHOD_EIP3009) {
            throw InvalidPaymentException::with(
                ErrorReason::UnsupportedScheme,
                sprintf('Asset transfer method "%s" not implemented (this scheme handles eip3009 only).', $declaredMethod),
            );
        }

        $payload = $signature->payload;

        JsonReader::string($payload, 'signature', 'EVM exact payload');
        $auth = JsonReader::array($payload, 'authorization', 'EVM exact payload');

        $to = JsonReader::string($auth, 'to', 'EIP-3009 authorization');
        $value = JsonReader::string($auth, 'value', 'EIP-3009 authorization');
        JsonReader::string($auth, 'from', 'EIP-3009 authorization');
        JsonReader::string($auth, 'nonce', 'EIP-3009 authorization');
        $validAfter = JsonReader::int($auth, 'validAfter', context: 'EIP-3009 authorization');
        $validBefore = JsonReader::int($auth, 'validBefore', context: 'EIP-3009 authorization');

        if (strcasecmp($to, $challenge->payTo) !== 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmRecipientMismatch,
                'Authorization "to" does not match challenge payTo.',
            );
        }

        if (DecimalCompare::compare($value, $challenge->amount) > 0) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValueMismatch,
                'Authorization "value" exceeds amount.',
            );
        }

        $now = time();

        if ($validAfter > $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidAfter,
                'Authorization is not yet valid (validAfter > now).',
            );
        }

        if ($validBefore <= $now) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidExactEvmAuthValidBefore,
                'Authorization has expired (validBefore <= now).',
            );
        }
    }

    /**
     * @return array{from: string, nonce: string, expiresAt: int}
     */
    public function replayKey(PaymentSignature $signature): array
    {
        // Use JsonReader::string (coerces numeric JSON values to string)
        // to mirror verifyShape exactly — a numeric `nonce: 123` passes
        // verifyShape, so it must also produce a non-null replay key
        // here, otherwise the in-process claim would be silently
        // skipped while settlement still happens.
        $auth = JsonReader::array($signature->payload, 'authorization', 'EVM exact payload');

        return [
            'from' => JsonReader::string($auth, 'from', 'EIP-3009 authorization'),
            'nonce' => JsonReader::string($auth, 'nonce', 'EIP-3009 authorization'),
            'expiresAt' => JsonReader::int($auth, 'validBefore', context: 'EIP-3009 authorization'),
        ];
    }
}
