<?php

declare(strict_types=1);

namespace X402\Schemes\Evm;

/**
 * Per-network EIP-712 domain + default settlement asset registry.
 *
 * The mapping mirrors the Coinbase Go reference (`go/mechanisms/evm/constants.go`).
 *
 * Critical quirk: Base Sepolia USDC's EIP-712 domain `name` is the literal
 * string `"USDC"`, NOT `"USD Coin"` like Base mainnet. Hosts that hardcode
 * `"USD Coin"` for both networks will silently produce wrong-domain
 * signatures on testnet — facilitator rejects every payment with no
 * obvious cause.
 *
 * @phpstan-type NetworkConfig array{
 *     name: string,
 *     asset: string,
 *     decimals: int,
 *     eip712Name: string,
 *     eip712Version: string,
 * }
 */
final class NetworkRegistry
{
    /**
     * @var array<string, NetworkConfig>
     */
    private const NETWORKS = [
        'eip155:8453' => [
            'name' => 'Base mainnet',
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:84532' => [
            'name' => 'Base Sepolia',
            'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'decimals' => 6,
            // ⚠️ Base Sepolia USDC uses literal "USDC", NOT "USD Coin".
            'eip712Name' => 'USDC',
            'eip712Version' => '2',
        ],
        'eip155:1' => [
            'name' => 'Ethereum mainnet',
            'asset' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:11155111' => [
            'name' => 'Ethereum Sepolia',
            'asset' => '0x1c7D4B196Cb0C7B01d743Fbc6116a902379C7238',
            'decimals' => 6,
            'eip712Name' => 'USDC',
            'eip712Version' => '2',
        ],
        'eip155:137' => [
            'name' => 'Polygon mainnet',
            'asset' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:80002' => [
            'name' => 'Polygon Amoy',
            'asset' => '0x41E94Eb019C0762f9Bfcf9Fb1E58725BfB0e7582',
            'decimals' => 6,
            'eip712Name' => 'USDC',
            'eip712Version' => '2',
        ],
        'eip155:42161' => [
            'name' => 'Arbitrum One',
            'asset' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:421614' => [
            'name' => 'Arbitrum Sepolia',
            'asset' => '0x75faf114eafb1BDbe2F0316DF893fd58CE46AA4d',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:10' => [
            'name' => 'Optimism mainnet',
            'asset' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:43114' => [
            'name' => 'Avalanche mainnet',
            'asset' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
        'eip155:43113' => [
            'name' => 'Avalanche Fuji',
            'asset' => '0x5425890298aed601595a70AB815c96711a31Bc65',
            'decimals' => 6,
            'eip712Name' => 'USD Coin',
            'eip712Version' => '2',
        ],
    ];

    /**
     * Friendly slugs accepted on the v1 wire and as middleware shorthand
     * (`x402:0.01,USDC,base`). Maps to the canonical CAIP-2 identifier.
     *
     * @var array<string, string>
     */
    private const SLUG_TO_CAIP2 = [
        'base' => 'eip155:8453',
        'base-sepolia' => 'eip155:84532',
        'ethereum' => 'eip155:1',
        'sepolia' => 'eip155:11155111',
        'polygon' => 'eip155:137',
        'polygon-amoy' => 'eip155:80002',
        'arbitrum' => 'eip155:42161',
        'arbitrum-sepolia' => 'eip155:421614',
        'optimism' => 'eip155:10',
        'avalanche' => 'eip155:43114',
        'avalanche-fuji' => 'eip155:43113',
    ];

    /**
     * @return list<string>
     */
    public static function supportedCaip2(): array
    {
        return array_keys(self::NETWORKS);
    }

    public static function isSupported(string $caip2): bool
    {
        return array_key_exists($caip2, self::NETWORKS);
    }

    /**
     * Resolve a friendly slug or raw CAIP-2 to canonical CAIP-2.
     */
    public static function toCaip2(string $networkOrSlug): string
    {
        return self::SLUG_TO_CAIP2[$networkOrSlug] ?? $networkOrSlug;
    }

    /**
     * @return NetworkConfig|null
     */
    public static function get(string $caip2): ?array
    {
        return self::NETWORKS[$caip2] ?? null;
    }

    /**
     * Default EIP-712 domain `name` for the network's primary stablecoin.
     */
    public static function eip712Name(string $caip2): ?string
    {
        return self::NETWORKS[$caip2]['eip712Name'] ?? null;
    }
}
