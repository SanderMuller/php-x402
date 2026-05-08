<?php

declare(strict_types=1);

namespace X402\Server;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Maps an inbound request to the resource string used as a `PriceTable`
 * lookup key. Default behavior (when the enforcer is constructed without
 * a resolver) is `$request->getUri()->getPath()`.
 *
 * Adapters that need richer routing (route attributes, controller
 * metadata, query-string-aware identifiers) ship a class implementing
 * this interface and pass the instance to `PaymentEnforcer`. Inline
 * `Closure(ServerRequestInterface): string` is also accepted.
 */
interface ResourceResolver
{
    public function __invoke(ServerRequestInterface $request): string;
}
