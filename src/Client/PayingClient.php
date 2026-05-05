<?php

declare(strict_types=1);

namespace X402\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Exceptions\X402Exception;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\Evm\AuthorizationSigner;
use X402\Schemes\Evm\Eip712Hasher;

/**
 * PSR-18 decorator that pays automatically when a 402 is returned.
 *
 * Wraps any PSR-18 ClientInterface — works with Guzzle, Symfony HttpClient,
 * Laravel Http facade (via the underlying Guzzle client), Buzz, etc.
 *
 * Flow on each request:
 *   1. Send the inner client.
 *   2. If response is not 402, return as-is.
 *   3. Decode PAYMENT-REQUIRED, pick the first acceptable challenge.
 *   4. Sign EIP-3009 authorization with the operator wallet.
 *   5. Resend with PAYMENT-SIGNATURE header. Return that response.
 */
final class PayingClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Wallet $wallet,
        private readonly Eip712Hasher $hasher = new Eip712Hasher,
        private readonly Version $version = Version::V1,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        if ($response->getStatusCode() !== 402) {
            return $response;
        }

        $challenge = $this->pickChallenge($response);
        $signed = $this->signChallenge($challenge);

        $paid = $request->withHeader($this->version->signatureHeader(), $signed->toHeader());

        return $this->inner->sendRequest($paid);
    }

    private function pickChallenge(ResponseInterface $response): PaymentRequired
    {
        $headerLine = $response->getHeaderLine($this->version->challengeHeader());

        $body = $headerLine !== ''
            ? (base64_decode($headerLine, true) ?: '')
            : (string) $response->getBody();

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new X402Exception('Could not decode 402 challenge body: '.$e->getMessage(), previous: $e);
        }

        $accepts = $decoded['accepts'] ?? [];

        if (! is_array($accepts) || $accepts === []) {
            throw new X402Exception('402 response contained no challenges.');
        }

        /** @var array<string, mixed> $first */
        $first = $accepts[0];

        return new PaymentRequired(
            scheme: (string) $first['scheme'],
            network: (string) $first['network'],
            maxAmountRequired: (string) $first['maxAmountRequired'],
            asset: (string) $first['asset'],
            payTo: (string) $first['payTo'],
            maxTimeoutSeconds: isset($first['maxTimeoutSeconds']) ? (int) $first['maxTimeoutSeconds'] : 60,
            resource: isset($first['resource']) ? (string) $first['resource'] : null,
            description: isset($first['description']) ? (string) $first['description'] : null,
            mimeType: isset($first['mimeType']) ? (string) $first['mimeType'] : null,
            extra: isset($first['extra']) && is_array($first['extra']) ? $first['extra'] : [],
        );
    }

    private function signChallenge(PaymentRequired $challenge): PaymentSignature
    {
        $now = time();
        $validBefore = $now + $challenge->maxTimeoutSeconds;

        // Domain MUST come from the challenge's `extra.eip712Domain` per the spec.
        $domain = $this->resolveDomain($challenge);

        $message = [
            'from' => $this->wallet->address(),
            'to' => $challenge->payTo,
            'value' => $challenge->maxAmountRequired,
            'validAfter' => $now - 5,
            'validBefore' => $validBefore,
            'nonce' => AuthorizationSigner::randomNonce(),
        ];

        $digest = $this->hasher->digest($domain, $message);
        $signature = $this->wallet->signDigest($digest);

        return new PaymentSignature(
            scheme: $challenge->scheme,
            network: $challenge->network,
            payload: [
                'signature' => $signature,
                'authorization' => $message,
            ],
            x402Version: $this->version === Version::V2 ? '2' : '1',
        );
    }

    /**
     * @return array{name: string, version: string, chainId: int, verifyingContract: string}
     */
    private function resolveDomain(PaymentRequired $challenge): array
    {
        $extra = $challenge->extra;

        if (! isset($extra['name'], $extra['version'])) {
            throw new X402Exception('Challenge "extra" must include EIP-712 domain "name" and "version".');
        }

        // CAIP-2 "eip155:8453" → chainId 8453
        $chainId = 0;
        if (str_starts_with($challenge->network, 'eip155:')) {
            $chainId = (int) substr($challenge->network, 7);
        }

        return [
            'name' => (string) $extra['name'],
            'version' => (string) $extra['version'],
            'chainId' => $chainId,
            'verifyingContract' => $challenge->asset,
        ];
    }
}
