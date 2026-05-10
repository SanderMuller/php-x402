<?php

declare(strict_types=1);

use X402\Client\HdWallet;
use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\Eip712Hasher;
use X402\Schemes\Evm\SignatureVerifier;

/**
 * @return list<array{name: string, seed: string, derivations: list<array{path: string, privateKey: string, chainCode: string}>}>
 */
function loadBip32Vectors(): array
{
    /** @var list<array{name: string, seed: string, derivations: list<array{path: string, privateKey: string, chainCode: string}>}> $data */
    $data = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/bip32-vectors.json'), true);

    return $data;
}

it('matches BIP-32 published test vectors byte-for-byte', function (): void {
    foreach (loadBip32Vectors() as $vector) {
        foreach ($vector['derivations'] as $row) {
            $wallet = HdWallet::fromSeed($vector['seed'], $row['path']);

            expect($wallet->privateKeyHex())
                ->toBe($row['privateKey'], sprintf('%s @ %s', $vector['name'], $row['path']));
        }
    }
});

it("handles the standard EVM derivation path m/44'/60'/0'/0/0", function (): void {
    // 64-byte seed (BIP-32 maximum).
    $seed = str_repeat('00', 64);
    $wallet = HdWallet::fromSeed($seed, "m/44'/60'/0'/0/0");

    expect($wallet->address())->toMatch('/^0x[0-9a-f]{40}$/')
        ->and($wallet->privateKeyHex())->toMatch('/^[0-9a-f]{64}$/');
});

it('accepts the BIP-32 minimum 16-byte seed', function (): void {
    $wallet = HdWallet::fromSeed(str_repeat('00', 16), "m/0'");

    expect($wallet->privateKeyHex())->toMatch('/^[0-9a-f]{64}$/');
});

it('rejects seeds shorter than 128 bits', function (): void {
    HdWallet::fromSeed(str_repeat('00', 15), 'm');
})->throws(InvalidArgumentException::class, 'Seed must be 16..64 bytes');

it('rejects seeds longer than 512 bits', function (): void {
    HdWallet::fromSeed(str_repeat('00', 65), 'm');
})->throws(InvalidArgumentException::class, 'Seed must be 16..64 bytes');

it('produces 1000 distinct addresses across non-hardened tenant indices', function (): void {
    $seed = '000102030405060708090a0b0c0d0e0f';
    $addresses = [];

    for ($i = 0; $i < 1000; ++$i) {
        $wallet = HdWallet::fromSeed($seed, sprintf("m/44'/60'/0'/0/%d", $i));
        $addresses[$wallet->address()] = true;
    }

    expect($addresses)->toHaveCount(1000);
});

it('accepts paths with the leading "m/" omitted', function (): void {
    $seed = '000102030405060708090a0b0c0d0e0f';

    $withM = HdWallet::fromSeed($seed, "m/0'/1");
    $withoutM = HdWallet::fromSeed($seed, "0'/1");

    expect($withM->privateKeyHex())->toBe($withoutM->privateKeyHex());
});

it('strips a 0x prefix from the seed', function (): void {
    $seed = '000102030405060708090a0b0c0d0e0f';

    $bare = HdWallet::fromSeed($seed, 'm');
    $prefixed = HdWallet::fromSeed('0x' . $seed, 'm');

    expect($bare->privateKeyHex())->toBe($prefixed->privateKeyHex());
});

it('produces byte-identical signatures to PrivateKeyWallet for the derived key', function (): void {
    $seed = '000102030405060708090a0b0c0d0e0f';
    $hd = HdWallet::fromSeed($seed, "m/44'/60'/0'/0/7");
    $raw = new PrivateKeyWallet($hd->privateKeyHex());

    $digest = '0x' . str_repeat('1', 64);

    expect($hd->signDigest($digest))->toBe($raw->signDigest($digest))
        ->and($hd->address())->toBe($raw->address());
});

it('signatures recover to the HD-derived address', function (): void {
    $seed = '000102030405060708090a0b0c0d0e0f';
    $wallet = HdWallet::fromSeed($seed, "m/44'/60'/0'/0/42");
    $digest = '0x' . str_repeat('a', 64);

    $signature = $wallet->signDigest($digest);
    $recovered = (new SignatureVerifier())->recover($digest, $signature);

    expect(strtolower($recovered))->toBe(strtolower($wallet->address()));
});

it('rejects an empty derivation path', function (): void {
    HdWallet::fromSeed('000102030405060708090a0b0c0d0e0f', '');
})->throws(InvalidArgumentException::class, 'Derivation path is empty');

it('rejects double-slash in the path', function (): void {
    HdWallet::fromSeed('000102030405060708090a0b0c0d0e0f', 'm//0');
})->throws(InvalidArgumentException::class);

it('rejects non-numeric path components', function (): void {
    HdWallet::fromSeed('000102030405060708090a0b0c0d0e0f', "m/foo'");
})->throws(InvalidArgumentException::class);

it('rejects mixed apostrophe placement', function (): void {
    HdWallet::fromSeed('000102030405060708090a0b0c0d0e0f', "m/0'x");
})->throws(InvalidArgumentException::class);

it('rejects child index components ≥ 2^31', function (): void {
    HdWallet::fromSeed('000102030405060708090a0b0c0d0e0f', 'm/2147483648');
})->throws(InvalidArgumentException::class, 'out of range');

it('rejects an empty seed', function (): void {
    HdWallet::fromSeed('', 'm');
})->throws(InvalidArgumentException::class, 'Seed must be');

it('rejects an odd-length seed', function (): void {
    HdWallet::fromSeed('abc', 'm');
})->throws(InvalidArgumentException::class, 'Seed must be');

it('rejects non-hex seed characters', function (): void {
    HdWallet::fromSeed('zz', 'm');
})->throws(InvalidArgumentException::class, 'Seed must be');

it('signs an EIP-3009 authorization with an HD-derived key that recovers to the same address', function (): void {
    $hasher = new Eip712Hasher();
    $verifier = new SignatureVerifier();
    $wallet = HdWallet::fromSeed(
        '000102030405060708090a0b0c0d0e0f',
        "m/44'/60'/0'/0/0",
    );

    $domain = [
        'name' => 'USD Coin',
        'version' => '2',
        'chainId' => 8453,
        'verifyingContract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
    ];
    $message = [
        'from' => $wallet->address(),
        'to' => '0x00000000000000000000000000000000000000ff',
        'value' => '1000000',
        'validAfter' => 0,
        'validBefore' => 9_999_999_999,
        'nonce' => '0x' . str_repeat('11', 32),
    ];

    $digest = $hasher->digest($domain, $message);
    $signature = $wallet->signDigest($digest);
    $recovered = $verifier->recover($digest, $signature);

    expect(strtolower($recovered))->toBe(strtolower($wallet->address()))
        ->and($signature)->toMatch('/^0x[0-9a-f]{130}$/');
});
