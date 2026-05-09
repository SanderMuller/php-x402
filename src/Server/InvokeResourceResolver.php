<?php

declare(strict_types=1);

namespace X402\Server;

use Closure;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared invoker for the `Closure|ResourceResolver|null` shape both
 * `PaymentEnforcer` and `PaymentResponseCache` accept. Centralises the
 * null-default + return-type-guard so the two middlewares can't drift.
 */
final class InvokeResourceResolver
{
    /**
     * @param  Closure(ServerRequestInterface): string|ResourceResolver|null  $resolver
     * @param  string  $callerLabel  Class name used in the exception message — `PaymentEnforcer` / `PaymentResponseCache` so failures point at the offending wiring.
     */
    public static function resolve(
        Closure|ResourceResolver|null $resolver,
        ServerRequestInterface $request,
        string $callerLabel,
    ): string {
        if ($resolver === null) {
            return $request->getUri()->getPath();
        }

        $resolved = $resolver($request);

        if (! is_string($resolved)) {
            throw new LogicException(sprintf('%s::resourceResolver must return a string.', $callerLabel));
        }

        return $resolved;
    }
}
