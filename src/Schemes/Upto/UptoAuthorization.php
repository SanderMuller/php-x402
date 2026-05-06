<?php

declare(strict_types=1);

namespace X402\Schemes\Upto;

/**
 * Strongly-typed DTO for the `upto` scheme payload.
 *
 * Differs from `Permit2Authorization` only in the witness — the `upto`
 * witness binds an extra `facilitator` address so a settled attestation
 * can't be replayed against a different facilitator.
 *
 * Wire shape:
 *
 *   {
 *     "signature": "0x...65 bytes...",
 *     "uptoAuthorization": {
 *       "permitted": { "token": "0x...", "amount": "10000" },
 *       "from":     "0x...",
 *       "spender":  "0x4020A4f3b7b90ccA423B9fabCc0CE57C6C240002",
 *       "nonce":    "12345",
 *       "deadline": "1735689600",
 *       "witness":  {
 *         "to": "0x...",
 *         "validAfter": "1735689500",
 *         "facilitator": "0x..."
 *       }
 *     }
 *   }
 */
final readonly class UptoAuthorization
{
    public function __construct(
        public string $token,
        public string $maxAmount,
        public string $from,
        public string $spender,
        public string $nonce,
        public string $deadline,
        public string $witnessTo,
        public string $validAfter,
        public string $facilitator,
    ) {}

    /**
     * @return array{
     *     permitted: array{token: string, amount: string},
     *     from: string,
     *     spender: string,
     *     nonce: string,
     *     deadline: string,
     *     witness: array{to: string, validAfter: string, facilitator: string},
     * }
     */
    public function toArray(): array
    {
        return [
            'permitted' => ['token' => $this->token, 'amount' => $this->maxAmount],
            'from' => $this->from,
            'spender' => $this->spender,
            'nonce' => $this->nonce,
            'deadline' => $this->deadline,
            'witness' => [
                'to' => $this->witnessTo,
                'validAfter' => $this->validAfter,
                'facilitator' => $this->facilitator,
            ],
        ];
    }
}
