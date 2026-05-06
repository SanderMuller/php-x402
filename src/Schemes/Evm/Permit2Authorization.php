<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

/**
 * Strongly-typed DTO for the Permit2 `permitWitnessTransferFrom` payload
 * carried inside `PaymentSignature.payload` when `extra.assetTransferMethod`
 * is `"permit2"`.
 *
 * Wire shape (decimal-string uint256s):
 *
 *   {
 *     "signature": "0x...65 bytes...",
 *     "permit2Authorization": {
 *       "permitted": { "token": "0x...", "amount": "10000" },
 *       "from":     "0x...",
 *       "spender":  "0x402085c248EeA27D92E8b30b2C58ed07f9E20001",
 *       "nonce":    "12345",
 *       "deadline": "1735689600",
 *       "witness":  { "to": "0x...", "validAfter": "1735689500" }
 *     }
 *   }
 *
 * `spender` MUST be the canonical x402ExactPermit2Proxy
 * (`Constants::X402_EXACT_PERMIT2_PROXY`) — same address on every EVM
 * chain via CREATE2.
 */
final readonly class Permit2Authorization
{
    public function __construct(
        public string $token,
        public string $amount,
        public string $from,
        public string $spender,
        public string $nonce,
        public string $deadline,
        public string $witnessTo,
        public string $validAfter,
    ) {}

    /**
     * @return array{
     *     permitted: array{token: string, amount: string},
     *     from: string,
     *     spender: string,
     *     nonce: string,
     *     deadline: string,
     *     witness: array{to: string, validAfter: string},
     * }
     */
    public function toArray(): array
    {
        return [
            'permitted' => ['token' => $this->token, 'amount' => $this->amount],
            'from' => $this->from,
            'spender' => $this->spender,
            'nonce' => $this->nonce,
            'deadline' => $this->deadline,
            'witness' => ['to' => $this->witnessTo, 'validAfter' => $this->validAfter],
        ];
    }
}
