<?php

declare(strict_types=1);

namespace X402\Schemes\Evm\Erc7710;

/**
 * One condition attached to an ERC-7710 delegation.
 *
 * `enforcer` is a deployed contract that implements `ICaveatEnforcer`
 * — at execution time, the delegation framework calls
 * `enforcer.beforeHook(terms, args, ...)`. If the call reverts, the
 * delegation execution is aborted.
 *
 * Common enforcer patterns (MetaMask Delegation Toolkit):
 *   - `AllowedTargetsEnforcer` — restrict callable contracts
 *   - `LimitedCallsEnforcer` — cap invocation count
 *   - `BlockNumberEnforcer` — time-bound expiry
 *   - `ERC20BalanceGteEnforcer` — require balance state at execution
 *
 * Spec: ERC-7710 (Smart Contract Delegation),
 * `specs/schemes/exact/scheme_exact_evm.md` §ERC-7710 transfer method.
 */
final readonly class Caveat
{
    public function __construct(
        public string $enforcer,
        public string $terms,
        public string $args,
    ) {}

    /**
     * @return array{enforcer: string, terms: string, args: string}
     */
    public function toArray(): array
    {
        return ['enforcer' => $this->enforcer, 'terms' => $this->terms, 'args' => $this->args];
    }
}
