<?php

declare(strict_types=1);

namespace X402\Facilitator;

use X402\Schemes\Evm\Caip2;

/**
 * Capability advertisement returned by `GET /supported`.
 *
 * Spec v2 §7: `{ kinds: PaymentKind[], extensions: ExtensionAdvertisement[],
 * signers: { "<caip2-pattern>": string[] } }`. The `signers` map is v2-only
 * and exposes the facilitator's public signing addresses keyed by CAIP-2
 * pattern (e.g. `"eip155:*": ["0x..."]`).
 */
final readonly class SupportedKinds
{
    /**
     * @param  list<array{x402Version: int, scheme: string, network: string, extra?: array<string, mixed>}>  $kinds
     * @param  list<array<string, mixed>>  $extensions
     * @param  array<string, list<string>>  $signers  CAIP-2 wildcard pattern → list of facilitator addresses.
     */
    public function __construct(
        public array $kinds,
        public array $extensions = [],
        public array $signers = [],
    ) {}

    /**
     * Returns true when at least one advertised `kind` matches the given
     * scheme + network. Network matching uses CAIP-2 wildcard patterns
     * (`eip155:*` matches every EVM chain).
     */
    public function supports(string $scheme, string $network): bool
    {
        foreach ($this->kinds as $kind) {
            if ($kind['scheme'] !== $scheme) {
                continue;
            }

            if (Caip2::matches($kind['network'], $network)) {
                return true;
            }
        }

        return false;
    }
}
