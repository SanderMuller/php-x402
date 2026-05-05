<?php

declare(strict_types=1);

namespace X402\Facilitator;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use X402\Exceptions\FacilitatorException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Default facilitator client. Targets the Coinbase-hosted facilitator
 * (https://x402.org/facilitator) by default; pass a custom $baseUrl to
 * point at the CDP-authenticated endpoint or a self-hosted facilitator
 * (e.g. x402-rs).
 */
final class CoinbaseFacilitator implements FacilitatorClient
{
    public const DEFAULT_BASE_URL = 'https://x402.org/facilitator';

    /**
     * @param  array<string, string>  $defaultHeaders  E.g. authentication headers for CDP.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly array $defaultHeaders = [],
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        $body = $this->call('/verify', [
            'paymentPayload' => $signature->toArray(),
            'paymentRequirements' => $challenge->toArray(),
        ]);

        return new VerifyResult(
            isValid: (bool) ($body['isValid'] ?? false),
            invalidReason: isset($body['invalidReason']) ? (string) $body['invalidReason'] : null,
            payer: isset($body['payer']) ? (string) $body['payer'] : null,
        );
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        $body = $this->call('/settle', [
            'paymentPayload' => $signature->toArray(),
            'paymentRequirements' => $challenge->toArray(),
        ]);

        return new SettleResult(
            success: (bool) ($body['success'] ?? false),
            transaction: (string) ($body['transaction'] ?? ''),
            network: (string) ($body['network'] ?? $challenge->network),
            payer: (string) ($body['payer'] ?? ''),
            errorReason: isset($body['errorReason']) ? (string) $body['errorReason'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws FacilitatorException
     */
    private function call(string $path, array $payload): array
    {
        try {
            $request = $this->requestFactory
                ->createRequest('POST', rtrim($this->baseUrl, '/').$path)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

            foreach ($this->defaultHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface | \JsonException $e) {
            throw new FacilitatorException('Facilitator transport failure: '.$e->getMessage(), previous: $e);
        }

        return $this->decode($response, $path);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FacilitatorException
     */
    private function decode(ResponseInterface $response, string $path): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new FacilitatorException(sprintf(
                'Facilitator %s returned HTTP %d: %s',
                $path,
                $status,
                substr($body, 0, 256),
            ));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FacilitatorException('Facilitator returned non-JSON body: '.$e->getMessage(), previous: $e);
        }

        return $decoded;
    }
}
