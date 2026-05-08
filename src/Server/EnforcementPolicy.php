<?php

declare(strict_types=1);

namespace X402\Server;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-request gate for the `PaymentEnforcer` pipeline. Returning `false`
 * skips the entire enforcement flow (no challenge, no nonce claim, no
 * facilitator call) and passes the request straight to the inner handler.
 *
 * Lets adapters compose policy (bot detection, IP allowlists, geo,
 * plan-tier exemption) inside the enforcer instead of wrapping it in
 * framework-specific middleware. Inline `Closure(ServerRequestInterface): bool`
 * is also accepted.
 */
interface EnforcementPolicy
{
    public function __invoke(ServerRequestInterface $request): bool;
}
