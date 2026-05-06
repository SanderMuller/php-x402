<?php

declare(strict_types=1);

use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\SignatureVerifier;

it('derives a 20-byte EVM address from a private key', function (): void {
    // Vitalik's well-known dev key; address is determined by the spec.
    $wallet = new PrivateKeyWallet('0x' . str_repeat('1', 64));

    expect($wallet->address())->toMatch('/^0x[0-9a-f]{40}$/');
});

it('rejects malformed private keys', function (): void {
    new PrivateKeyWallet('0xshort');
})->throws(InvalidArgumentException::class);

it('signs digests in a recoverable way', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('a', 64));
    $digest = '0x' . str_repeat('1', 64);

    $signature = $wallet->signDigest($digest);
    $recovered = (new SignatureVerifier())->recover($digest, $signature);

    expect(strtolower($recovered))->toBe(strtolower($wallet->address()));
});

it('produces consistent addresses across instantiations', function (): void {
    $a = new PrivateKeyWallet('0x' . str_repeat('b', 64));
    $b = new PrivateKeyWallet(str_repeat('b', 64)); // No 0x prefix

    expect($a->address())->toBe($b->address());
});
