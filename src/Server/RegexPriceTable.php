<?php

declare(strict_types=1);

namespace X402\Server;

use InvalidArgumentException;
use RuntimeException;
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
        // Validate eagerly so a malformed PCRE fails at registration time
        // (loud, easy to debug) rather than silently never matching at
        // runtime. preg_match returns false on a malformed pattern; the
        // error_handler swap suppresses the PHP warning so it doesn't
        // leak to stderr in callers' test runs.
        set_error_handler(static fn (): bool => true);

        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $valid) {
            throw new InvalidArgumentException(sprintf('Invalid PCRE pattern: %s', $pattern));
        }

        $this->entries[] = [
            'pattern' => $pattern,
            'challenges' => array_values($challenges),
        ];
    }

    public function challengesFor(string $resource): array
    {
        foreach ($this->entries as $entry) {
            $result = preg_match($entry['pattern'], $resource);

            // Fail closed on PCRE runtime errors (backtrack-limit
            // exhaustion, recursion-limit, JIT stack overflow, …).
            // Returning `[]` would silently de-protect a paid route under
            // attacker-controlled input — the route becomes free instead
            // of erroring out. Throw so the host gets a 5xx and the
            // operator sees the failure.
            if ($result === false) {
                throw new RuntimeException(sprintf(
                    'PCRE runtime error matching pattern %s against resource: %s',
                    $entry['pattern'],
                    preg_last_error_msg(),
                ));
            }

            if ($result === 1) {
                return $entry['challenges'];
            }
        }

        return [];
    }
}
