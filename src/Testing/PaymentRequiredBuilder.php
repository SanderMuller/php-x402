<?php

declare(strict_types=1);

namespace X402\Testing;

use InvalidArgumentException;
use X402\Protocol\PaymentRequired;
use X402\Schemes\Evm\ExactScheme;

/**
 * Fluent builder for `PaymentRequired` test fixtures. Saves test code
 * from spelling out the asset address, network CAIP-2, and atomic-unit
 * amount conversions every time.
 *
 * ```php
 * $challenge = PaymentRequiredBuilder::usdcOnBase(0.01, '0xPayTo')->build();
 * ```
 *
 * For non-default values, chain setters:
 * ```php
 * PaymentRequiredBuilder::usdcOnBase(0.01, $payTo)
 *     ->withMaxTimeoutSeconds(120)
 *     ->withDescription('Premium API access')
 *     ->build();
 * ```
 */
final class PaymentRequiredBuilder
{
    /**
     * Base mainnet USDC contract address.
     */
    public const USDC_BASE_MAINNET = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';

    /**
     * Base Sepolia USDC contract address.
     */
    public const USDC_BASE_SEPOLIA = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

    private string $scheme = ExactScheme::NAME;

    private int $maxTimeoutSeconds = 60;

    private ?string $resource = null;

    private ?string $description = null;

    private ?string $mimeType = null;

    /**
     * @var array<string, mixed>
     */
    private array $extra = [];

    private function __construct(private readonly string $network, private readonly string $amount, private readonly string $asset, private readonly string $payTo) {}

    /**
     * USDC on Base mainnet (eip155:8453). Amount in USDC units (e.g. 0.01 = 1 cent).
     */
    public static function usdcOnBase(float|string $amount, string $payTo): self
    {
        return new self(
            network: 'eip155:8453',
            amount: self::toAtomicUnits($amount, decimals: 6),
            asset: self::USDC_BASE_MAINNET,
            payTo: $payTo,
        );
    }

    /**
     * USDC on Base Sepolia testnet (eip155:84532). Amount in USDC units.
     */
    public static function usdcOnBaseSepolia(float|string $amount, string $payTo): self
    {
        return new self(
            network: 'eip155:84532',
            amount: self::toAtomicUnits($amount, decimals: 6),
            asset: self::USDC_BASE_SEPOLIA,
            payTo: $payTo,
        );
    }

    /**
     * Generic builder for any network/asset/decimals combination.
     */
    public static function for(string $network, string $asset, float|string $amount, string $payTo, int $decimals = 6): self
    {
        return new self(
            network: $network,
            amount: self::toAtomicUnits($amount, $decimals),
            asset: $asset,
            payTo: $payTo,
        );
    }

    public function withScheme(string $scheme): self
    {
        $this->scheme = $scheme;

        return $this;
    }

    public function withMaxTimeoutSeconds(int $seconds): self
    {
        $this->maxTimeoutSeconds = $seconds;

        return $this;
    }

    public function withResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function withExtra(array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    public function build(): PaymentRequired
    {
        return new PaymentRequired(
            scheme: $this->scheme,
            network: $this->network,
            amount: $this->amount,
            asset: $this->asset,
            payTo: $this->payTo,
            maxTimeoutSeconds: $this->maxTimeoutSeconds,
            resource: $this->resource,
            description: $this->description,
            mimeType: $this->mimeType,
            extra: $this->extra,
        );
    }

    /**
     * Convert a human-readable amount (e.g. 0.01 USDC) to an atomic
     * unit string (e.g. "10000" for 6 decimals). Accepts either a
     * float or a numeric string; strings let callers preserve precision
     * past float-rounding errors.
     *
     * Uses bcmath when available; otherwise falls back to a pure-string
     * decimal shift. The previous float+round() fallback silently
     * overflowed and lost precision for 18-decimal ERC-20 fixtures —
     * the new path is exact at any decimal count.
     */
    private static function toAtomicUnits(float|string $amount, int $decimals): string
    {
        $human = is_string($amount) ? $amount : sprintf('%.' . $decimals . 'F', $amount);

        if (! is_numeric($human)) {
            throw new InvalidArgumentException(sprintf('Amount must be numeric, got "%s".', $human));
        }

        if (function_exists('bcmul')) {
            return bcmul($human, (string) (10 ** $decimals), 0);
        }

        return self::shiftDecimalPoint($human, $decimals);
    }

    /**
     * Pure-string decimal shift — no float math involved, exact at any
     * decimal count. Parses the already-is_numeric'd input as
     * `[-+]?\d*(\.\d*)?[eE][+-]?\d+?` (we exclude the exponent form by
     * having normalized via sprintf upstream for floats), combines int
     * + fractional parts, and pads/truncates to `$decimals` places.
     * Truncation is strict (no rounding) so atomic conversions don't
     * silently bump cents.
     */
    private static function shiftDecimalPoint(string $human, int $decimals): string
    {
        $negative = str_starts_with($human, '-');

        if ($negative || str_starts_with($human, '+')) {
            $human = substr($human, 1);
        }

        [$intPart, $fracPart] = str_contains($human, '.') ? explode('.', $human, 2) : [$human, ''];

        if (strlen($fracPart) > $decimals) {
            $fracPart = substr($fracPart, 0, $decimals);
        } else {
            $fracPart = str_pad($fracPart, $decimals, '0', STR_PAD_RIGHT);
        }

        $combined = ltrim($intPart . $fracPart, '0');
        $result = $combined === '' ? '0' : $combined;

        return $negative && $result !== '0' ? '-' . $result : $result;
    }
}
