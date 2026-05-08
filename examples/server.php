<?php

declare(strict_types=1);

/**
 * Single-file PSR-15 demo server.
 *
 * Run from the package root:
 *   php -S localhost:8080 examples/server.php
 *
 * Then in another terminal:
 *   php examples/client.php
 *
 * The server gates `/premium` behind a 0.01 USDC payment on Base.
 * Uses `StubFacilitator` so no real network calls fire — the demo
 * shows the full handshake (challenge issued → client signs → 402
 * loop completes → 200 with PAYMENT-RESPONSE) without spending real
 * funds or hitting a live facilitator.
 */
require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use X402\Server\PaymentEnforcer;
use X402\Server\StaticPriceTable;
use X402\Testing\PaymentRequiredBuilder;
use X402\Testing\StubFacilitator;

// ---------------------------------------------------------------------
// Wiring: price table → enforcer → inner handler.
// ---------------------------------------------------------------------

$factory = new Psr17Factory();
$priceTable = new StaticPriceTable();
$priceTable->set(
    '/premium',
    PaymentRequiredBuilder::usdcOnBase(0.01, '0x000000000000000000000000000000000000bEEf')
        ->withDescription('Demo paid endpoint')
        ->build(),
);

// `forTesting()` wires InMemoryNonceStore + ExactScheme. Demo only —
// production hosts use the full constructor with a Redis-backed
// Psr16NonceStore so replay protection holds across workers.
$enforcer = PaymentEnforcer::forTesting(
    priceTable: $priceTable,
    facilitator: new StubFacilitator(),    // always-success, no network
    factory: $factory,
);

// ---------------------------------------------------------------------
// Inner handler — what the user sees AFTER paying.
// ---------------------------------------------------------------------

$inner = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['secret' => 'the answer is 42'], JSON_THROW_ON_ERROR),
        );
    }
};

// ---------------------------------------------------------------------
// Tiny PSR-15 dispatcher. Real apps use Slim, Mezzio, etc.
// ---------------------------------------------------------------------

$dispatch = new class ($enforcer, $inner) implements RequestHandlerInterface {
    public function __construct(
        private MiddlewareInterface $middleware,
        private RequestHandlerInterface $inner,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->inner);
    }
};

// ---------------------------------------------------------------------
// Build a PSR-7 request from PHP's built-in server globals.
// ---------------------------------------------------------------------

$request = $factory->createServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    $_SERVER,
);

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_') && is_string($value)) {
        $name = str_replace('_', '-', substr($key, 5));
        $request = $request->withHeader($name, $value);
    }
}

$response = $dispatch->handle($request);

// ---------------------------------------------------------------------
// Emit. PHP's built-in server reads status from header(), body from echo.
// ---------------------------------------------------------------------

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
echo $response->getBody();
