<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Schemes\SchemeContract;

/**
 * EVM "exact" scheme — EIP-3009 transferWithAuthorization signed via EIP-712.
 *
 * Reference: https://github.com/coinbase/x402/blob/main/specs/schemes/exact/scheme_exact_evm.md
 */
final class ExactScheme implements SchemeContract
{
    public const NAME = 'exact';

    /**
     * Common EVM networks. Hosts may extend by passing additional CAIP-2 ids
     * to the constructor.
     *
     * @var list<string>
     */
    private const DEFAULT_NETWORKS = [
        'eip155:8453',  // Base mainnet
        'eip155:84532', // Base Sepolia
        'eip155:1',     // Ethereum mainnet
        'eip155:137',   // Polygon
        'eip155:42161', // Arbitrum One
    ];

    /**
     * @param  list<string>|null  $networks
     */
    public function __construct(private readonly ?array $networks = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function supportedNetworks(): array
    {
        return $this->networks ?? self::DEFAULT_NETWORKS;
    }

    public function verifyShape(PaymentSignature $signature, PaymentRequired $challenge): void
    {
        if ($signature->scheme !== self::NAME) {
            throw new InvalidPaymentException(sprintf(
                'Expected scheme "%s", got "%s".',
                self::NAME,
                $signature->scheme,
            ));
        }

        if ($signature->network !== $challenge->network) {
            throw new InvalidPaymentException('Signature network does not match challenge network.');
        }

        $payload = $signature->payload;

        foreach (['signature', 'authorization'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidPaymentException(sprintf('EVM exact payload missing "%s".', $required));
            }
        }

        $auth = (array) $payload['authorization'];

        foreach (['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'] as $required) {
            if (! array_key_exists($required, $auth)) {
                throw new InvalidPaymentException(sprintf('EIP-3009 authorization missing "%s".', $required));
            }
        }

        if (strcasecmp((string) $auth['to'], $challenge->payTo) !== 0) {
            throw new InvalidPaymentException('Authorization "to" does not match challenge payTo.');
        }

        if ($this->compareAmount((string) $auth['value'], $challenge->maxAmountRequired) > 0) {
            throw new InvalidPaymentException('Authorization "value" exceeds maxAmountRequired.');
        }

        $now = time();
        $validAfter = (int) $auth['validAfter'];
        $validBefore = (int) $auth['validBefore'];

        if ($validAfter > $now) {
            throw new InvalidPaymentException('Authorization is not yet valid (validAfter > now).');
        }

        if ($validBefore <= $now) {
            throw new InvalidPaymentException('Authorization has expired (validBefore <= now).');
        }
    }

    /**
     * Compare two stringified non-negative integers without overflowing PHP int.
     */
    private function compareAmount(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';

        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return strcmp($a, $b);
    }
}
