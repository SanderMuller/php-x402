<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

/**
 * CAIP-2 chain-id parsing + wildcard matching.
 *
 * The x402 v2 spec advertises facilitator-supported networks via CAIP-2
 * patterns where the reference part may be `*` to mean "every chain
 * within this namespace". For example, `"eip155:*"` matches every EVM
 * chain; `"solana:*"` matches mainnet-beta + devnet.
 *
 * Reference: `go/types.go:Match`.
 */
final class Caip2
{
    public static function matches(string $pattern, string $caip2): bool
    {
        if ($pattern === $caip2) {
            return true;
        }

        $parts = explode(':', $pattern, 2);
        if (count($parts) !== 2 || $parts[1] !== '*') {
            return false;
        }

        $namespace = $parts[0];
        $candidateNamespace = strstr($caip2, ':', true);

        return $candidateNamespace === $namespace;
    }

    public static function namespaceOf(string $caip2): ?string
    {
        $namespace = strstr($caip2, ':', true);

        return $namespace === false ? null : $namespace;
    }

    public static function referenceOf(string $caip2): ?string
    {
        $ref = strstr($caip2, ':');

        return $ref === false ? null : substr($ref, 1);
    }
}
