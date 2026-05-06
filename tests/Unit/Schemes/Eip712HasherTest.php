<?php

declare(strict_types=1);

use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\AuthorizationSigner;
use X402\Schemes\Evm\Eip712Hasher;
use X402\Schemes\Evm\SignatureVerifier;

/**
 * Conformance tests modelled on the official Coinbase Go test suite
 * (go/test/unit/evm_eip712_test.go). The Go and TypeScript suites assert
 * structural invariants (length, determinism, sensitivity) rather than
 * pinning specific digest hex — pinned hex would diverge any time the
 * reference impl tweaked padding or normalisation, and the spec doesn't
 * mandate one canonical hex form.
 *
 * Inputs come from tests/Fixtures/eip712-vectors.json so they match the
 * Go reference suite byte-for-byte.
 */
/**
 * @return list<array{name: string, domain: array{name: string, version: string, chainId: int, verifyingContract: string}, message: array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}, expectedDigest: string}>
 */
function loadVectors(): array
{
    /** @var array{vectors: list<array{name: string, domain: array{name: string, version: string, chainId: int, verifyingContract: string}, message: array{from: string, to: string, value: string, validAfter: int, validBefore: int, nonce: string}, expectedDigest: string}>} $data */
    $data = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/eip712-vectors.json'), true);

    return $data['vectors'];
}

it('matches the pinned digest for every fixture vector (regression guard)', function (): void {
    $hasher = new Eip712Hasher();

    foreach (loadVectors() as $vector) {
        expect($hasher->digest($vector['domain'], $vector['message']))
            ->toBe($vector['expectedDigest'], "vector {$vector['name']} drift");
    }
});

it('produces a 32-byte digest for every fixture vector', function (): void {
    $hasher = new Eip712Hasher();

    foreach (loadVectors() as $vector) {
        $digest = $hasher->digest($vector['domain'], $vector['message']);

        expect($digest)
            ->toMatch('/^0x[0-9a-f]{64}$/', "vector {$vector['name']} should be 32 bytes hex");
    }
});

it('is deterministic — same inputs produce same digest', function (): void {
    $hasher = new Eip712Hasher();

    foreach (loadVectors() as $vector) {
        $a = $hasher->digest($vector['domain'], $vector['message']);
        $b = $hasher->digest($vector['domain'], $vector['message']);

        expect($a)->toBe($b);
    }
});

it('changes the digest when chainId differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altDomain = $vector['domain'];
    $altDomain['chainId'] = 1;
    $alt = $hasher->digest($altDomain, $vector['message']);

    expect($alt)->not->toBe($base);
});

it('changes the digest when value differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altMessage = $vector['message'];
    $altMessage['value'] = '2000000';
    $alt = $hasher->digest($vector['domain'], $altMessage);

    expect($alt)->not->toBe($base);
});

it('changes the digest when nonce differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altMessage = $vector['message'];
    $altMessage['nonce'] = '0x' . str_repeat('f', 64);
    $alt = $hasher->digest($vector['domain'], $altMessage);

    expect($alt)->not->toBe($base);
});

it('changes the digest when verifyingContract differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altDomain = $vector['domain'];
    $altDomain['verifyingContract'] = '0x0000000000000000000000000000000000000001';
    $alt = $hasher->digest($altDomain, $vector['message']);

    expect($alt)->not->toBe($base);
});

it('changes the digest when domain name differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altDomain = $vector['domain'];
    $altDomain['name'] = 'Different Coin';
    $alt = $hasher->digest($altDomain, $vector['message']);

    expect($alt)->not->toBe($base);
});

it('changes the digest when validBefore differs', function (): void {
    $vector = loadVectors()[0];
    $hasher = new Eip712Hasher();

    $base = $hasher->digest($vector['domain'], $vector['message']);

    $altMessage = $vector['message'];
    $altMessage['validBefore'] = 9999999998;
    $alt = $hasher->digest($vector['domain'], $altMessage);

    expect($alt)->not->toBe($base);
});

it('signs every fixture vector and recovers the signing address', function (): void {
    $hasher = new Eip712Hasher();
    $verifier = new SignatureVerifier();
    $wallet = new PrivateKeyWallet('0x' . str_repeat('a', 64));

    foreach (loadVectors() as $vector) {
        $digest = $hasher->digest($vector['domain'], $vector['message']);
        $signature = $wallet->signDigest($digest);
        $recovered = $verifier->recover($digest, $signature);

        expect(strtolower($recovered))
            ->toBe(strtolower($wallet->address()), "vector {$vector['name']} recovery mismatch");
    }
});

it('AuthorizationSigner produces a payload with signature + authorization', function (): void {
    $vector = loadVectors()[0];

    $signed = (new AuthorizationSigner())->sign(
        $vector['domain'],
        $vector['message'],
        '0x' . str_repeat('b', 64),
    );

    expect($signed)->toHaveKey('signature')->toHaveKey('authorization');
    expect($signed['signature'])->toMatch('/^0x[0-9a-f]{130}$/');
    expect($signed['authorization'])->toBe($vector['message']);
});

it('produces 32-byte random nonces', function (): void {
    $a = AuthorizationSigner::randomNonce();
    $b = AuthorizationSigner::randomNonce();

    expect($a)->toMatch('/^0x[0-9a-f]{64}$/')
        ->and($a)->not->toBe($b);
});
