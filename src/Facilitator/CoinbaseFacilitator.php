<?php

declare(strict_types=1);

namespace X402\Facilitator;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use X402\Exceptions\FacilitatorException;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Support\JsonReader;

/**
 * Default facilitator client. Targets the Coinbase-hosted facilitator
 * (https://x402.org/facilitator) by default; pass a custom $baseUrl to
 * point at the CDP-authenticated endpoint or a self-hosted facilitator
 * (e.g. x402-rs).
 */
final readonly class CoinbaseFacilitator implements FacilitatorClient
{
    public const DEFAULT_BASE_URL = 'https://x402.org/facilitator';

    /**
     * @param  array<string, string>  $defaultHeaders  E.g. authentication headers for CDP.
     */
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private array $defaultHeaders = [],
        private Version $version = Version::V1,
    ) {}

    /**
     * Convenience constructor for the common case where the same
     * PSR-17 implementation fulfils both factory contracts (e.g.
     * `nyholm/psr7`, `guzzle/psr7`, `slim/psr7`). Saves callers from
     * passing the same factory instance twice.
     *
     * @param  array<string, string>  $defaultHeaders
     */
    public static function default(
        ClientInterface $http,
        RequestFactoryInterface&StreamFactoryInterface $factory,
        string $baseUrl = self::DEFAULT_BASE_URL,
        array $defaultHeaders = [],
        Version $version = Version::V1,
    ): self {
        return new self(
            http: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: $baseUrl,
            defaultHeaders: $defaultHeaders,
            version: $version,
        );
    }

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        $body = $this->call('/verify', $this->buildBody($signature, $challenge));

        return new VerifyResult(
            isValid: filter_var($body['isValid'] ?? false, FILTER_VALIDATE_BOOLEAN),
            invalidReason: JsonReader::stringOrNull($body, 'invalidReason'),
            payer: JsonReader::stringOrNull($body, 'payer'),
            extensions: $this->readExtensions($body),
        );
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        $body = $this->call('/settle', $this->buildBody($signature, $challenge));

        return new SettleResult(
            success: filter_var($body['success'] ?? false, FILTER_VALIDATE_BOOLEAN),
            transaction: JsonReader::stringOrNull($body, 'transaction') ?? '',
            network: JsonReader::stringOrNull($body, 'network') ?? $challenge->network,
            payer: JsonReader::stringOrNull($body, 'payer') ?? '',
            errorReason: JsonReader::stringOrNull($body, 'errorReason'),
            // v2 settlement receipt may include `amount` — actual
            // atomic-unit amount settled (used by the `upto` scheme).
            amount: JsonReader::stringOrNull($body, 'amount'),
            extensions: $this->readExtensions($body),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function readExtensions(array $body): ?array
    {
        $raw = $body['extensions'] ?? null;
        if (! is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        try {
            $url = rtrim($this->baseUrl, '/') . '/discovery/resources?' . http_build_query($query->toQueryParams());
            $request = $this->requestFactory
                ->createRequest('GET', $url)
                ->withHeader('Accept', 'application/json');

            foreach ($this->defaultHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $clientException) {
            throw new FacilitatorException('Facilitator /discovery/resources transport failure: ' . $clientException->getMessage(), $clientException->getCode(), previous: $clientException);
        }

        $body = $this->decode($response, '/discovery/resources');

        $itemsRaw = is_array($body['items'] ?? null) ? $body['items'] : [];
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];

        $items = [];
        foreach ($itemsRaw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $accepts = [];
            if (is_array($entry['accepts'] ?? null)) {
                foreach ($entry['accepts'] as $accept) {
                    if (is_array($accept)) {
                        /** @var array<string, mixed> $accept */
                        $accepts[] = $accept;
                    }
                }
            }

            $discoveryInfo = null;
            if (is_array($entry['discoveryInfo'] ?? null)) {
                /** @var array<string, mixed> $infoRaw */
                $infoRaw = $entry['discoveryInfo'];
                $discoveryInfo = $infoRaw;
            }

            $items[] = new DiscoveryResource(
                resource: JsonReader::stringOrNull($entry, 'resource') ?? '',
                type: JsonReader::stringOrNull($entry, 'type') ?? '',
                x402Version: JsonReader::int($entry, 'x402Version', default: 2),
                accepts: $accepts,
                lastUpdated: JsonReader::stringOrNull($entry, 'lastUpdated'),
                metadata: JsonReader::arrayOrEmpty($entry, 'metadata'),
                discoveryInfo: $discoveryInfo,
            );
        }

        /** @var array<string, mixed> $pagination */
        return new DiscoveryPage(
            items: $items,
            limit: JsonReader::int($pagination, 'limit', default: $query->limit),
            offset: JsonReader::int($pagination, 'offset', default: $query->offset),
            total: JsonReader::int($pagination, 'total', default: count($items)),
        );
    }

    public function supported(): SupportedKinds
    {
        try {
            $request = $this->requestFactory
                ->createRequest('GET', rtrim($this->baseUrl, '/') . '/supported')
                ->withHeader('Accept', 'application/json');

            foreach ($this->defaultHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $clientException) {
            throw new FacilitatorException('Facilitator /supported transport failure: ' . $clientException->getMessage(), $clientException->getCode(), previous: $clientException);
        }

        $body = $this->decode($response, '/supported');

        /** @var list<array{x402Version: int, scheme: string, network: string, extra?: array<string, mixed>}> $kinds */
        $kinds = is_array($body['kinds'] ?? null) ? array_values($body['kinds']) : [];
        /** @var list<array<string, mixed>> $extensions */
        $extensions = is_array($body['extensions'] ?? null) ? array_values($body['extensions']) : [];
        /** @var array<string, list<string>> $signers */
        $signers = is_array($body['signers'] ?? null) ? $body['signers'] : [];

        return new SupportedKinds(
            kinds: $kinds,
            extensions: $extensions,
            signers: $signers,
        );
    }

    /**
     * Spec §7 — `/verify` and `/settle` request body is
     * `{ x402Version, paymentPayload, paymentRequirements }`.
     *
     * @return array<string, mixed>
     */
    private function buildBody(PaymentSignature $signature, PaymentRequired $challenge): array
    {
        return [
            'x402Version' => $this->version->toInt(),
            'paymentPayload' => $this->version === Version::V2 ? $signature->toArrayV2() : $signature->toArrayV1(),
            'paymentRequirements' => $this->version === Version::V2 ? $challenge->toArrayV2() : $challenge->toArrayV1(),
        ];
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
                ->createRequest('POST', rtrim($this->baseUrl, '/') . $path)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

            foreach ($this->defaultHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface|JsonException $e) {
            throw new FacilitatorException('Facilitator transport failure: ' . $e->getMessage(), $e->getCode(), previous: $e);
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

        // Facilitator endpoints reply 200 on success. Any 3xx (redirect) or
        // 4xx/5xx is a transport failure — we do NOT follow redirects implicitly
        // because PSR-18 clients vary in their redirect behaviour and a
        // facilitator misconfigured to redirect could leak request payloads.
        if ($status < 200 || $status >= 300) {
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
        } catch (JsonException $jsonException) {
            throw new FacilitatorException('Facilitator returned non-JSON body: ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }

        return $decoded;
    }
}
