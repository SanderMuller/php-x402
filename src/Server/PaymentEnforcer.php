<?php

declare(strict_types=1);

namespace X402\Server;

use Closure;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\FacilitatorClient;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentResponse;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Replay\InMemoryNonceStore;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Schemes\SchemeContract;

/**
 * PSR-15 middleware that enforces the x402 spec on inbound requests.
 *
 * Flow:
 *   0. If a `shouldEnforce` predicate is configured and returns false,
 *      pass through to the inner handler without resolving challenges,
 *      claiming a nonce, or calling the facilitator. Lets adapters
 *      compose policy (bot detection, IP allowlists, geo, plan tier)
 *      around the protocol without wrapping the enforcer.
 *   1. Resolve PaymentRequired challenges via PriceTable. Empty list = free.
 *   2. No PAYMENT-SIGNATURE header → return 402 with PAYMENT-REQUIRED.
 *   3. Has signature → decode, verify shape, claim nonce, call facilitator
 *      verify + settle, attach PAYMENT-RESPONSE, pass to inner handler.
 *
 * The middleware is intentionally framework-agnostic — Laravel / Symfony
 * adapters wrap this and handle the resource → PriceTable lookup their
 * own way.
 *
 * **Idempotency note:** the nonce is claimed BEFORE the facilitator
 * settles. Concurrent attack requests with the same authorization are
 * rejected without hitting the facilitator (DoS protection). The
 * trade-off: if the facilitator's settle fails after the claim, the
 * nonce is burned and the user must regenerate. If the user's
 * connection drops AFTER settle succeeds but before the response
 * reaches them, they cannot retry the same signature — they paid but
 * may not have received content. Hosts that need full idempotency
 * should layer a per-(nonce, from) response cache on top of this
 * middleware.
 */
final readonly class PaymentEnforcer implements MiddlewareInterface
{
    private LoggerInterface $logger;

    /**
     * @param  array<string, SchemeContract>  $schemes  Keyed by scheme name (e.g. ["exact" => new ExactScheme]).
     * @param  Closure(ServerRequestInterface): string|ResourceResolver|null  $resourceResolver  Optional; default uses request URI path. Closure or `ResourceResolver` instance — invoked the same way.
     * @param  Closure(ServerRequestInterface): bool|EnforcementPolicy|null  $shouldEnforce  Optional gate. Returns false → skip enforcement (no challenge / nonce / facilitator). `null` (default) = always enforce.
     */
    public function __construct(
        private PriceTable $priceTable,
        private FacilitatorClient $facilitator,
        private NonceStoreContract $nonceStore,
        private array $schemes,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private Version $version = Version::V1,
        private Closure|ResourceResolver|null $resourceResolver = null,
        private Closure|EnforcementPolicy|null $shouldEnforce = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Test- and dev-only factory. Wires `InMemoryNonceStore` (process-
     * local), the EVM `exact` scheme, and a single PSR-17 factory.
     *
     * **Do NOT use in production.** `InMemoryNonceStore` is per-process
     * only; multi-worker deployments accept the same `(network, from,
     * nonce)` on different workers, breaking replay protection — a
     * paying user can be billed multiple times. Production hosts use
     * the explicit constructor with a Redis-backed `Psr16NonceStore`.
     *
     * @param  ResponseFactoryInterface&StreamFactoryInterface  $factory  PSR-17 impl that fulfils both contracts (e.g. `nyholm/psr7`).
     */
    public static function forTesting(
        PriceTable $priceTable,
        FacilitatorClient $facilitator,
        ResponseFactoryInterface&StreamFactoryInterface $factory,
        Version $version = Version::V1,
    ): self {
        return new self(
            priceTable: $priceTable,
            facilitator: $facilitator,
            nonceStore: new InMemoryNonceStore(),
            schemes: [ExactScheme::NAME => new ExactScheme()],
            responseFactory: $factory,
            streamFactory: $factory,
            version: $version,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldEnforce !== null && ! ($this->shouldEnforce)($request)) {
            $this->logger->debug('x402: shouldEnforce skipped pipeline');

            return $handler->handle($request);
        }

        $resource = $this->resolveResource($request);
        $challenges = $this->priceTable->challengesFor($resource);

        if ($challenges === []) {
            return $handler->handle($request);
        }

        $headerLine = $request->getHeaderLine($this->version->signatureHeader());

        if ($headerLine === '') {
            $this->logger->debug('x402: challenge issued', ['resource' => $resource, 'count' => \count($challenges)]);

            return $this->challenge($challenges, 'Payment required.', fallbackResource: (string) $request->getUri());
        }

        try {
            // Reuse the parse done by PaymentResponseCache (when present
            // in the chain) instead of re-decoding the header. Falls
            // back to a fresh parse so PaymentEnforcer still works
            // standalone.
            $cached = $request->getAttribute(PaymentResponseCache::PARSED_SIGNATURE_ATTR);
            $signature = $cached instanceof PaymentSignature ? $cached : PaymentSignature::fromHeader($headerLine);
            $challenge = $this->matchChallenge($signature, $challenges);
            $scheme = $this->schemeFor($signature->scheme);
            $scheme->verifyShape($signature, $challenge);
            $this->guardReplay($signature);
        } catch (InvalidPaymentException $invalidPaymentException) {
            $this->logger->warning('x402: invalid payment payload', ['reason' => $invalidPaymentException->getMessage()]);

            // Spec transport.md status table: malformed signatures → 400.
            // 402 is reserved for "no payment yet" + "facilitator rejected".
            return $this->challenge(
                $challenges,
                $invalidPaymentException->getMessage(),
                statusCode: 400,
                fallbackResource: (string) $request->getUri(),
            );
        }

        $this->logger->debug('x402: signature decoded', [
            'scheme' => $signature->scheme,
            'network' => $signature->network,
        ]);

        $verify = $this->facilitator->verify($signature, $challenge);

        if (! $verify->isValid) {
            $this->logger->info('x402: facilitator rejected', ['reason' => $verify->invalidReason]);

            return $this->challenge(
                $challenges,
                $verify->invalidReason ?? 'Payment rejected by facilitator.',
                fallbackResource: (string) $request->getUri(),
            );
        }

        $settle = $this->facilitator->settle($signature, $challenge);

        if (! $settle->success) {
            $this->logger->warning('x402: settlement failed', ['reason' => $settle->errorReason]);

            return $this->challenge(
                $challenges,
                $settle->errorReason ?? 'Settlement failed.',
                fallbackResource: (string) $request->getUri(),
            );
        }

        $this->logger->debug('x402: payment settled', [
            'network' => $settle->network,
            'payer' => $settle->payer,
            'tx' => $settle->transaction,
        ]);

        $response = $handler->handle($request->withAttribute('x402.settle', $settle));

        $receipt = new PaymentResponse(
            success: true,
            transaction: $settle->transaction,
            network: $settle->network,
            payer: $settle->payer,
            // v2 settlement may report a different amount than authorized (upto scheme).
            amount: $settle->amount,
            extensions: $settle->extensions,
        );

        return $response->withHeader($this->version->responseHeader(), $receipt->toHeader());
    }

    /**
     * @param  list<PaymentRequired>  $challenges
     */
    private function challenge(array $challenges, string $reason, int $statusCode = 402, ?string $fallbackResource = null): ResponseInterface
    {
        $body = $this->version === Version::V2
            ? $this->buildV2Body($challenges, $reason, $fallbackResource)
            : $this->buildV1Body($challenges, $reason);

        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $response = $this->responseFactory
            ->createResponse($statusCode, $statusCode === 402 ? 'Payment Required' : 'Bad Request');

        // v1 has NO challenge header — body-only. v2 hoists into PAYMENT-REQUIRED.
        $challengeHeader = $this->version->challengeHeader();
        if ($challengeHeader !== null) {
            $response = $response->withHeader($challengeHeader, base64_encode($payload));
        }

        // v1 ships the challenge in the body; v2 puts it in the header
        // and leaves the body as a server implementation concern.
        if ($this->version === Version::V1) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        }

        return $response;
    }

    /**
     * @param  list<PaymentRequired>  $challenges
     * @return array<string, mixed>
     */
    private function buildV1Body(array $challenges, string $reason): array
    {
        return [
            'x402Version' => 1,
            'error' => $reason,
            'accepts' => array_map(static fn (PaymentRequired $c): array => $c->toArrayV1(), $challenges),
        ];
    }

    /**
     * v2 hoists `resource` to a single ResourceInfo at the top level (taken
     * from the first challenge that supplies one). Per-entry `resource` /
     * `description` / `mimeType` move out of `accepts[]`.
     *
     * @param  list<PaymentRequired>  $challenges
     * @return array<string, mixed>
     */
    private function buildV2Body(array $challenges, string $reason, ?string $fallbackResource = null): array
    {
        $body = [
            'x402Version' => 2,
            'error' => $reason,
            'accepts' => array_map(static fn (PaymentRequired $c): array => $c->toArrayV2(), $challenges),
        ];

        // Spec v2 §5.1: `resource: ResourceInfo` is REQUIRED on the challenge
        // envelope. Use the first challenge that carries one; if none do,
        // synthesize from the inbound request URI passed by `process()`.
        foreach ($challenges as $candidate) {
            $resourceInfo = $candidate->resourceInfo();
            if ($resourceInfo !== null) {
                $body['resource'] = $resourceInfo->toArray();

                return $body;
            }
        }

        if ($fallbackResource !== null && $fallbackResource !== '') {
            $body['resource'] = ['url' => $fallbackResource];
        }

        return $body;
    }

    /**
     * @param  list<PaymentRequired>  $challenges
     */
    private function matchChallenge(PaymentSignature $signature, array $challenges): PaymentRequired
    {
        foreach ($challenges as $challenge) {
            if ($challenge->scheme === $signature->scheme && $challenge->network === $signature->network) {
                return $challenge;
            }
        }

        throw InvalidPaymentException::with(
            ErrorReason::InvalidPaymentRequirements,
            sprintf(
                'No matching challenge for scheme="%s" network="%s".',
                $signature->scheme,
                $signature->network,
            ),
        );
    }

    private function schemeFor(string $name): SchemeContract
    {
        if (! isset($this->schemes[$name])) {
            throw InvalidPaymentException::with(
                ErrorReason::UnsupportedScheme,
                sprintf('Unsupported scheme "%s".', $name),
            );
        }

        return $this->schemes[$name];
    }

    private function guardReplay(PaymentSignature $signature): void
    {
        $auth = $signature->authorization() ?? ['from' => '', 'nonce' => '', 'validBefore' => 0];
        $ttl = max(60, $auth['validBefore'] - time() + 30);

        if (! $this->nonceStore->claim($signature->network, $auth['from'], $auth['nonce'], $ttl)) {
            throw InvalidPaymentException::with(
                ErrorReason::ReplayAttempt,
                'Nonce already used (replay attempt).',
            );
        }
    }

    private function resolveResource(ServerRequestInterface $request): string
    {
        if ($this->resourceResolver === null) {
            return $request->getUri()->getPath();
        }

        $resolved = ($this->resourceResolver)($request);

        if (! is_string($resolved)) {
            throw new LogicException('resourceResolver must return a string.');
        }

        return $resolved;
    }
}
