<?php

declare(strict_types=1);

namespace X402\Server;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\FacilitatorClient;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentResponse;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Schemes\SchemeContract;

/**
 * PSR-15 middleware that enforces the x402 spec on inbound requests.
 *
 * Flow:
 *   1. Resolve PaymentRequired challenges via PriceTable. Empty list = free.
 *   2. No PAYMENT-SIGNATURE header → return 402 with PAYMENT-REQUIRED.
 *   3. Has signature → decode, verify shape, claim nonce, call facilitator
 *      verify + settle, attach PAYMENT-RESPONSE, pass to inner handler.
 *
 * The middleware is intentionally framework-agnostic — Laravel / Symfony
 * adapters wrap this and handle the resource → PriceTable lookup their
 * own way.
 */
final class PaymentEnforcer implements MiddlewareInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param  array<string, SchemeContract>  $schemes  Keyed by scheme name (e.g. ["exact" => new ExactScheme]).
     */
    public function __construct(
        private readonly PriceTable $priceTable,
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly array $schemes,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Version $version = Version::V1,
        private readonly ?\Closure $resourceResolver = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resource = $this->resolveResource($request);
        $challenges = $this->priceTable->challengesFor($resource);

        if ($challenges === []) {
            return $handler->handle($request);
        }

        $headerLine = $request->getHeaderLine($this->version->signatureHeader());

        if ($headerLine === '') {
            return $this->challenge($challenges, 'Payment required.');
        }

        try {
            $signature = PaymentSignature::fromHeader($headerLine);
            $challenge = $this->matchChallenge($signature, $challenges);
            $scheme = $this->schemeFor($signature->scheme);
            $scheme->verifyShape($signature, $challenge);
            $this->guardReplay($signature);
        } catch (InvalidPaymentException $e) {
            $this->logger->warning('x402: invalid payment payload', ['reason' => $e->getMessage()]);

            return $this->challenge($challenges, $e->getMessage());
        }

        $verify = $this->facilitator->verify($signature, $challenge);

        if (! $verify->isValid) {
            $this->logger->info('x402: facilitator rejected', ['reason' => $verify->invalidReason]);

            return $this->challenge($challenges, $verify->invalidReason ?? 'Payment rejected by facilitator.');
        }

        $settle = $this->facilitator->settle($signature, $challenge);

        if (! $settle->success) {
            $this->logger->warning('x402: settlement failed', ['reason' => $settle->errorReason]);

            return $this->challenge($challenges, $settle->errorReason ?? 'Settlement failed.');
        }

        $response = $handler->handle($request->withAttribute('x402.settle', $settle));

        $receipt = new PaymentResponse(
            success: true,
            transaction: $settle->transaction,
            network: $settle->network,
            payer: $settle->payer,
        );

        return $response->withHeader($this->version->responseHeader(), $receipt->toHeader());
    }

    /**
     * @param  list<PaymentRequired>  $challenges
     */
    private function challenge(array $challenges, string $reason): ResponseInterface
    {
        $body = [
            'x402Version' => $this->version === Version::V2 ? '2' : '1',
            'error' => $reason,
            'accepts' => array_map(static fn (PaymentRequired $c) => $c->toArray(), $challenges),
        ];

        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse(402, 'Payment Required')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader($this->version->challengeHeader(), base64_encode($payload))
            ->withBody($this->streamFactory->createStream($payload));
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

        throw new InvalidPaymentException(sprintf(
            'No matching challenge for scheme="%s" network="%s".',
            $signature->scheme,
            $signature->network,
        ));
    }

    private function schemeFor(string $name): SchemeContract
    {
        if (! isset($this->schemes[$name])) {
            throw new InvalidPaymentException(sprintf('Unsupported scheme "%s".', $name));
        }

        return $this->schemes[$name];
    }

    private function guardReplay(PaymentSignature $signature): void
    {
        $auth = (array) ($signature->payload['authorization'] ?? []);
        $from = (string) ($auth['from'] ?? '');
        $nonce = (string) ($auth['nonce'] ?? '');
        $validBefore = (int) ($auth['validBefore'] ?? 0);
        $ttl = max(60, $validBefore - time() + 30);

        if (! $this->nonceStore->claim($signature->network, $from, $nonce, $ttl)) {
            throw new InvalidPaymentException('Nonce already used (replay attempt).');
        }
    }

    private function resolveResource(ServerRequestInterface $request): string
    {
        if ($this->resourceResolver !== null) {
            return ($this->resourceResolver)($request);
        }

        return $request->getUri()->getPath();
    }
}
