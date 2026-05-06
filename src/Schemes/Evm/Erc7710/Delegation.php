<?php

declare(strict_types=1);

namespace X402\Schemes\Evm\Erc7710;

/**
 * One ERC-7710 delegation envelope.
 *
 * For x402, the buyer signs a delegation that authorises a delegate
 * (typically the facilitator's settlement proxy or a paymaster smart
 * account) to pay on their behalf, subject to caveats that bound
 * what the delegate can do.
 *
 * Field semantics:
 *
 *   - `delegate`: the address that gains the authority.
 *   - `delegator`: the EOA / smart wallet granting the authority.
 *   - `authority`: 32-byte hash naming the delegation that this one
 *     redelegates from. For a root delegation (no parent), this is
 *     `ROOT_AUTHORITY` = `0xffff…ffff` (32 bytes of `0xff`).
 *   - `caveats`: array of `Caveat` constraints.
 *   - `salt`: 32-byte uniqueness — same `(delegator, terms)` shape can
 *     be issued more than once with different salts.
 *   - `signature`: 65-byte EIP-712 signature over the delegation hash.
 *
 * The on-chain hash structure follows MetaMask's Delegation Framework
 * `Delegation` type. Computing it requires the framework's verifying
 * contract address as the EIP-712 verifyingContract — out of scope
 * for v0.x; the facilitator's `/verify` resolves that on-chain.
 */
final readonly class Delegation
{
    public const ROOT_AUTHORITY = '0xffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /**
     * @param  list<Caveat>  $caveats
     */
    public function __construct(
        public string $delegate,
        public string $delegator,
        public string $authority,
        public array $caveats,
        public string $salt,
        public string $signature,
    ) {}

    /**
     * @return array{
     *     delegate: string,
     *     delegator: string,
     *     authority: string,
     *     caveats: list<array{enforcer: string, terms: string, args: string}>,
     *     salt: string,
     *     signature: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'delegate' => $this->delegate,
            'delegator' => $this->delegator,
            'authority' => $this->authority,
            'caveats' => array_map(static fn (Caveat $c): array => $c->toArray(), $this->caveats),
            'salt' => $this->salt,
            'signature' => $this->signature,
        ];
    }
}
