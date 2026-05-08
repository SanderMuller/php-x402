<?php

declare(strict_types=1);

namespace X402\Server;

use X402\Protocol\PaymentRequired;

/**
 * Pattern-matched `PriceTable` for hosts that price by URL shape rather
 * than by exact resource string.
 *
 * Patterns are PCRE — fully bracketed expressions including delimiters
 * (e.g. `'#^/api/v\d+/users/\d+$#'`). First match wins; ordering follows
 * insertion order. Falls through to an empty challenge list if nothing
 * matches.
 *
 * Use `StaticPriceTable` when keys are exact resource strings — pattern
 * matching has a per-request cost. For mixed setups, layer them: query
 * the static table first, fall back to the regex table.
 */
final class RegexPriceTable implements PriceTable
{
    /**
     * @var list<array{pattern: string, challenges: list<PaymentRequired>}>
     */
    private array $entries = [];

    public function add(string $pattern, PaymentRequired ...$challenges): void
    {
        $this->entries[] = [
            'pattern' => $pattern,
            'challenges' => array_values($challenges),
        ];
    }

    public function challengesFor(string $resource): array
    {
        foreach ($this->entries as $entry) {
            if (preg_match($entry['pattern'], $resource) === 1) {
                return $entry['challenges'];
            }
        }

        return [];
    }
}
