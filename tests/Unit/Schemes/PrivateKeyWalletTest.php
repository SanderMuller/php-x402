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

it('rejects EIP-155 chainId-offset v bytes (not used in EIP-712 typed-data)', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('a', 64));
    $digest = '0x' . str_repeat('1', 64);

    $signed = $wallet->signDigest($digest);
    // Replace the trailing v=1b/1c with v=29 (a transaction-style chainId-offset value).
    $forged = substr($signed, 0, -2) . '1d';

    (new SignatureVerifier())->recover($digest, $forged);
})->throws(InvalidArgumentException::class, 'Invalid signature v byte "29"');

it('rejects signatures with non-hex characters (hexdec would silently coerce to 0)', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('a', 64));
    $digest = '0x' . str_repeat('1', 64);

    $signed = $wallet->signDigest($digest);
    // Replace v with non-hex "gg". hexdec('gg') === 0 — without an
    // explicit hex check this would coerce to recovery id 0 and
    // proceed through pubkey recovery against the wrong v byte.
    $forged = substr($signed, 0, -2) . 'gg';

    (new SignatureVerifier())->recover($digest, $forged);
})->throws(InvalidArgumentException::class, 'non-hex characters');
