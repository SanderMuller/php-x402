<?php

declare(strict_types=1);

/**
 * Demo client that hits the example server, signs the 402 challenge,
 * and prints the protected resource body + transaction receipt.
 *
 * Prerequisite: `php -S localhost:8080 examples/server.php` running.
 *
 * Run:
 *   php examples/client.php
 *
 * The wallet uses a hard-coded throwaway key — fine for this demo
 * because the server's StubFacilitator doesn't verify signatures
 * against a real payer. Production clients use a KMS-backed wallet;
 * see docs/kms.md.
 */
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use X402\Client\PayingClient;
use X402\Client\PrivateKeyWallet;

const DEMO_KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
const SERVER_URL = 'http://localhost:8080/premium';

$factory = new Psr17Factory();

$inner = new GuzzleClient([
    'http_errors' => false,                 // 402 isn't an exception
    'allow_redirects' => false,
]);

$client = new PayingClient(
    inner: $inner,
    wallet: new PrivateKeyWallet(DEMO_KEY),
);

$request = $factory->createRequest('GET', SERVER_URL);

try {
    $response = $client->sendRequest($request);
} catch (Throwable $e) {
    fwrite(STDERR, "request failed: {$e->getMessage()}\n");
    exit(1);
}

echo "Status: {$response->getStatusCode()} {$response->getReasonPhrase()}\n";

$receipt = $response->getHeaderLine('PAYMENT-RESPONSE') ?: $response->getHeaderLine('X-PAYMENT-RESPONSE');
if ($receipt !== '') {
    $decoded = base64_decode($receipt, strict: true);
    echo "Receipt:  {$decoded}\n";
}

echo 'Body:     ' . $response->getBody() . "\n";
