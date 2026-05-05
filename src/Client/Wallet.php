<?php

declare(strict_types=1);

namespace X402\Client;

/**
 * Pluggable wallet — abstracts how the buyer's signing key is fetched.
 *
 * Production deployments should NOT implement this with a hard-coded private
 * key in source. Use env-injected secrets, a KMS adapter, or a remote signer.
 */
interface Wallet
{
    /**
     * EVM address of the wallet (0x-prefixed, 20 bytes hex).
     */
    public function address(): string;

    /**
     * Sign an EIP-712 digest produced by Eip712Hasher::digest().
     *
     * @param  string  $digest  0x-prefixed 32-byte hex.
     * @return string  0x-prefixed 65-byte hex (r || s || v).
     */
    public function signDigest(string $digest): string;
}
