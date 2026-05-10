<?php

declare(strict_types=1);

use X402\Client\HdWallet;
use X402\Client\PrivateKeyWallet;
use X402\Client\Wallet;
use X402\Schemes\Evm\SignatureVerifier;

require_once __DIR__ . '/KmsWalletTest.php'; // FakeKmsWallet test double

/**
 * Conformance dataset — every Wallet implementation must satisfy the
 * invariants below. New wallets get a row here, not a bespoke test file
 * of their own. A bug that escapes implementation-specific tests (high-s
 * leakage past simplito's busted `canonical: true` option dispatch is a
 * recent example) has to slip through *every* wallet's row of this
 * table — which is much harder than slipping past one wallet's tests.
 */
dataset('wallet implementations', [
    'PrivateKeyWallet' => fn (): Wallet => new PrivateKeyWallet('0x' . str_repeat('11', 32)),
    'HdWallet' => fn (): Wallet => HdWallet::fromSeed(str_repeat('22', 32), "m/44'/60'/0'/0/0"),
    'FakeKmsWallet' => fn (): Wallet => new FakeKmsWallet(str_repeat('33', 32)),
]);

/** secp256k1 curve order / 2 — the EIP-2 low-s threshold. */
const SECP256K1_HALF_N_HEX = '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0';

it('produces an EIP-2 canonical (low-s) signature for every digest', function (Wallet $wallet): void {
    $halfN = gmp_init(SECP256K1_HALF_N_HEX, 16);

    // 16 varied digests — enough to exercise both v ∈ {0, 1} recoveryParam
    // branches against the deterministic RFC 6979 nonce. Without the
    // low-s fix in SignatureExporter (and the matching s ≤ n/2 check in
    // KmsWallet), simplito's option-dispatch quirk lets ~half of these
    // through with s in the upper half.
    foreach (range(0, 15) as $i) {
        $digest = '0x' . str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
        $sig = $wallet->signDigest($digest);
        $sHex = substr($sig, 2 + 64, 64);
        $s = gmp_init($sHex, 16);

        expect(gmp_cmp($s, $halfN))->toBeLessThanOrEqual(
            0,
            sprintf('Wallet produced a high-s signature for digest %s — violates EIP-2.', $digest),
        );
    }
})->with('wallet implementations');

it('produces a deterministic signature for the same digest (RFC 6979)', function (Wallet $wallet): void {
    $digest = '0x' . str_repeat('5a', 32);

    expect($wallet->signDigest($digest))->toBe($wallet->signDigest($digest));
})->with('wallet implementations');

it('signs a digest that SignatureVerifier::recover resolves to the wallet address', function (Wallet $wallet): void {
    $digest = '0x' . str_repeat('a5', 32);
    $recovered = (new SignatureVerifier())->recover($digest, $wallet->signDigest($digest));

    expect(strtolower($recovered))->toBe(strtolower($wallet->address()));
})->with('wallet implementations');

it('packs v into the {27, 28} byte range', function (Wallet $wallet): void {
    $digest = '0x' . str_repeat('cd', 32);
    $sig = $wallet->signDigest($digest);
    $v = (int) hexdec(substr($sig, 130, 2));

    expect($v)->toBeIn([27, 28]);
})->with('wallet implementations');

it('produces a 0x-prefixed 65-byte hex signature', function (Wallet $wallet): void {
    $sig = $wallet->signDigest('0x' . str_repeat('77', 32));

    expect($sig)->toStartWith('0x')
        ->and(strlen($sig))->toBe(132)
        ->and(ctype_xdigit(substr($sig, 2)))->toBeTrue();
})->with('wallet implementations');

it('produces a 0x-prefixed 20-byte hex address', function (Wallet $wallet): void {
    $address = $wallet->address();

    expect($address)->toStartWith('0x')
        ->and(strlen($address))->toBe(42)
        ->and(ctype_xdigit(substr($address, 2)))->toBeTrue();
})->with('wallet implementations');
