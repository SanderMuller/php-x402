<?php

declare(strict_types=1);

namespace X402\Client;

use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use X402\Exceptions\X402Exception;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Schemes\Evm\AuthorizationSigner;
use X402\Schemes\Evm\Eip712Hasher;
use X402\Schemes\Evm\NetworkRegistry;
use X402\Support\JsonReader;

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
final readonly class PayingClient implements ClientInterface
{
    /**
     * @param  Version  $version  Preferred wire version. Used as the fallback
     *                            when the 402 response doesn't expose
     *                            `PAYMENT-REQUIRED` (i.e. v1-only servers).
     *                            Per-response negotiation in `sendRequest`
     *                            takes precedence — a v2 server overrides
     *                            a v1-configured client and vice versa.
     */
    public function __construct(
        private ClientInterface $inner,
        private Wallet $wallet,
        private Eip712Hasher $hasher = new Eip712Hasher(),
        private Version $version = Version::V1,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        if ($response->getStatusCode() !== 402) {
            return $response;
        }

        // Version negotiation: trust the server's wire over our config.
        // Presence of `PAYMENT-REQUIRED` header → v2 envelope. Absence →
        // fall back to the configured preferred version.
        $v2Header = Version::V2->challengeHeader();
        $negotiated = $v2Header !== null && $response->hasHeader($v2Header)
            ? Version::V2
            : $this->version;

        $challenge = $this->pickChallenge($response, $negotiated);
        $signed = $this->signChallenge($challenge, $negotiated);

        $paid = $request->withHeader($negotiated->signatureHeader(), $signed->toHeader());

        return $this->inner->sendRequest($paid);
    }

    private function pickChallenge(ResponseInterface $response, Version $negotiated): PaymentRequired
    {
        // v2 puts the challenge in PAYMENT-REQUIRED header (base64'd JSON).
        // v1 puts it in the body. Read header first if v2 declares one;
        // fall back to body otherwise.
        $challengeHeader = $negotiated->challengeHeader();
        $headerLine = $challengeHeader !== null ? $response->getHeaderLine($challengeHeader) : '';

        if ($headerLine !== '') {
            $decoded = base64_decode($headerLine, true);
            $body = $decoded === false ? '' : $decoded;
        } else {
            $body = (string) $response->getBody();
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new X402Exception('Could not decode 402 challenge body: ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }

        $accepts = $decoded['accepts'] ?? [];

        if (! is_array($accepts) || $accepts === []) {
            throw new X402Exception('402 response contained no challenges.');
        }

        $firstRaw = $accepts[0] ?? null;
        if (! is_array($firstRaw)) {
            throw new X402Exception('402 challenge entry is not an object.');
        }

        /** @var array<string, mixed> $first */
        $first = $firstRaw;

        // v2 wire uses `amount`; v1 wire uses `maxAmountRequired`. Accept either.
        $amount = JsonReader::stringOrNull($first, 'amount')
            ?? JsonReader::string($first, 'maxAmountRequired', '402 challenge');

        return new PaymentRequired(
            scheme: JsonReader::string($first, 'scheme', '402 challenge'),
            network: JsonReader::string($first, 'network', '402 challenge'),
            amount: $amount,
            asset: JsonReader::string($first, 'asset', '402 challenge'),
            payTo: JsonReader::string($first, 'payTo', '402 challenge'),
            maxTimeoutSeconds: JsonReader::int($first, 'maxTimeoutSeconds', default: 60),
            resource: JsonReader::stringOrNull($first, 'resource'),
            description: JsonReader::stringOrNull($first, 'description'),
            mimeType: JsonReader::stringOrNull($first, 'mimeType'),
            extra: JsonReader::arrayOrEmpty($first, 'extra'),
        );
    }

    private function signChallenge(PaymentRequired $challenge, Version $negotiated): PaymentSignature
    {
        $now = time();
        $validBefore = $now + $challenge->maxTimeoutSeconds;

        // EIP-712 domain fields ride flat under the challenge's `extra` —
        // `extra.name` + `extra.version`. Spec v2 §6.1.1 (no `extra.eip712Domain`
        // wrapper). Fall back to the network registry when the server omits.
        $domain = $this->resolveDomain($challenge);

        $message = [
            'from' => $this->wallet->address(),
            'to' => $challenge->payTo,
            'value' => $challenge->amount,
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
            x402Version: $negotiated->toInt(),
            // Spec v2 §5.2: clients MUST echo the chosen PaymentRequirements
            // back so the server can disambiguate when multiple `accepts[]`
            // entries were offered. Harmless on v1 (the v1 wire shape's
            // `toArrayV1()` doesn't include `accepted`).
            accepted: $negotiated === Version::V2 ? $challenge : null,
            extensions: $challenge->extensions,
        );
    }

    /**
     * Resolve the EIP-712 domain for the challenge.
     *
     * The challenge's `extra.name` + `extra.version` are the source of
     * truth when set. When the server omits them (minimal v2 challenges
     * sometimes do — they assume well-known token defaults), we fall
     * back to the per-network registry so the canonical USDC quirks
     * (`"USDC"` on Base Sepolia vs `"USD Coin"` on mainnet) are
     * applied automatically.
     *
     * @return array{name: string, version: string, chainId: int, verifyingContract: string}
     */
    private function resolveDomain(PaymentRequired $challenge): array
    {
        $extra = $challenge->extra;

        $name = JsonReader::stringOrNull($extra, 'name')
            ?? NetworkRegistry::get($challenge->network)['eip712Name']
            ?? null;
        $version = JsonReader::stringOrNull($extra, 'version')
            ?? NetworkRegistry::get($challenge->network)['eip712Version']
            ?? null;

        if ($name === null || $version === null) {
            throw new X402Exception(sprintf(
                'EIP-712 domain unresolved for network "%s" — challenge `extra` lacks "name"/"version" and the network is not in NetworkRegistry.',
                $challenge->network,
            ));
        }

        // CAIP-2 "eip155:8453" → chainId 8453
        $chainId = 0;
        if (str_starts_with($challenge->network, 'eip155:')) {
            $chainId = (int) substr($challenge->network, 7);
        }

        return [
            'name' => $name,
            'version' => $version,
            'chainId' => $chainId,
            'verifyingContract' => $challenge->asset,
        ];
    }
}
