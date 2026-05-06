<?php

declare(strict_types=1);

namespace X402\Schemes\Evm\Erc7710;

use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\Evm\Constants;
use X402\Schemes\Evm\NetworkRegistry;
use X402\Schemes\SchemeContract;
use X402\Support\JsonReader;

/**
 * `exact` scheme + `erc7710` transfer method.
 *
 * The buyer signs an ERC-7710 delegation chain that authorises a
 * delegate (typically a paymaster smart account) to execute a token
 * transfer on their behalf. Caveats bound *what* the delegate may do
 * — the facilitator submits the delegation and pays gas.
 *
 * **Scope of this class (v0.x):**
 *   - Validates wire shape: scheme/network match, `delegations` is a
 *     non-empty list, each entry has the required ERC-7710 fields.
 *   - Asserts at least one caveat is present (an unbounded delegation
 *     would let the delegate transfer arbitrarily — fail-closed).
 *
 * **What this class does NOT do:**
 *   - EIP-712 hashing of the delegation. The Delegation Framework's
 *     `Delegation` type hash + redelegation chain hashing is non-trivial
 *     and the verifying-contract address varies per chain. Facilitator
 *     resolves this on-chain.
 *   - Caveat semantics validation. Each enforcer is a contract — only
 *     the chain knows whether `terms`/`args` will pass.
 *   - Signing. Hosts sign with a smart-account SDK (MetaMask Snap,
 *     Permissionless.js, Biconomy) and pass the wrapped delegation
 *     into `PaymentSignature.payload`.
 *
 * Spec: ERC-7710 + `specs/schemes/exact/scheme_exact_evm.md` §ERC-7710.
 */
final class Erc7710Scheme implements SchemeContract
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

        $declaredMethod = $challenge->extra['assetTransferMethod'] ?? null;
        if ($declaredMethod !== Constants::TRANSFER_METHOD_ERC7710) {
            throw InvalidPaymentException::with(
                ErrorReason::UnsupportedScheme,
                sprintf('Erc7710Scheme requires extra.assetTransferMethod="erc7710", got "%s".', is_string($declaredMethod) ? $declaredMethod : '(unset)'),
            );
        }

        $delegations = $signature->payload['delegations'] ?? null;
        if (! is_array($delegations) || $delegations === []) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                'ERC-7710 payload.delegations must be a non-empty list.',
            );
        }

        foreach ($delegations as $idx => $entry) {
            $this->validateDelegation($entry, is_int($idx) ? (string) $idx : '?');
        }
    }

    private function validateDelegation(mixed $entry, string $idxLabel): void
    {
        if (! is_array($entry)) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                sprintf('ERC-7710 delegations[%s] is not an object.', $idxLabel),
            );
        }

        /** @var array<string, mixed> $entry */
        $context = sprintf('ERC-7710 delegations[%s]', $idxLabel);

        JsonReader::string($entry, 'delegate', $context);
        JsonReader::string($entry, 'delegator', $context);
        JsonReader::string($entry, 'authority', $context);
        JsonReader::string($entry, 'salt', $context);
        JsonReader::string($entry, 'signature', $context);

        $caveats = $entry['caveats'] ?? null;
        if (! is_array($caveats) || $caveats === []) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                $context . ' must declare at least one caveat (unbounded delegations are rejected).',
            );
        }

        foreach ($caveats as $caveatIdx => $caveat) {
            $this->validateCaveat($caveat, $context, is_int($caveatIdx) ? (string) $caveatIdx : '?');
        }
    }

    private function validateCaveat(mixed $caveat, string $delegationContext, string $idxLabel): void
    {
        if (! is_array($caveat)) {
            throw InvalidPaymentException::with(
                ErrorReason::InvalidPayload,
                sprintf('%s.caveats[%s] is not an object.', $delegationContext, $idxLabel),
            );
        }

        /** @var array<string, mixed> $caveat */
        $caveatContext = sprintf('%s.caveats[%s]', $delegationContext, $idxLabel);
        JsonReader::string($caveat, 'enforcer', $caveatContext);
        JsonReader::string($caveat, 'terms', $caveatContext);
        JsonReader::string($caveat, 'args', $caveatContext);
    }
}
