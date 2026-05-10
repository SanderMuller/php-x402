<?php

declare(strict_types=1);

use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\EcdsaRecovery;

/**
 * Sign a digest with a known private key via PrivateKeyWallet, then strip
 * the v byte and ask EcdsaRecovery to rediscover it. This proves the loop
 * lands on the right v for both 27 and 28 candidates (we vary the digest
 * until we get a sample of each).
 */
it('rediscovers the correct v byte for a signature whose v is known', function (): void {
    $privateKey = '0x' . str_repeat('11', 32);
    $wallet = new PrivateKeyWallet($privateKey);

    $digest = '0x' . str_repeat('aa', 32);
    $signature = $wallet->signDigest($digest);

    $sig = substr($signature, 2); // strip 0x
    $rHex = substr($sig, 0, 64);
    $sHex = substr($sig, 64, 64);
    $vKnown = (int) hexdec(substr($sig, 128, 2));

    $vRecovered = EcdsaRecovery::deriveV($digest, $rHex, $sHex, $wallet->address());

    expect($vRecovered)->toBe($vKnown);
});

it('throws when the address does not match either recovery candidate', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('22', 32));
    $digest = '0x' . str_repeat('bb', 32);
    $signature = $wallet->signDigest($digest);
    $sig = substr($signature, 2);
    $rHex = substr($sig, 0, 64);
    $sHex = substr($sig, 64, 64);

    $bogus = '0x' . str_repeat('de', 20);

    expect(fn () => EcdsaRecovery::deriveV($digest, $rHex, $sHex, $bogus))
        ->toThrow(InvalidArgumentException::class, 'does not belong to the claimed signer');
});

it('is case-insensitive on the expected-address argument', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('33', 32));
    $digest = '0x' . str_repeat('cc', 32);
    $signature = $wallet->signDigest($digest);
    $sig = substr($signature, 2);
    $rHex = substr($sig, 0, 64);
    $sHex = substr($sig, 64, 64);

    $upper = '0x' . strtoupper(substr($wallet->address(), 2));

    $v = EcdsaRecovery::deriveV($digest, $rHex, $sHex, $upper);

    expect($v)->toBeIn([27, 28]);
});

it('lands on both v=27 and v=28 across varied digests (covers both branches)', function (): void {
    $wallet = new PrivateKeyWallet('0x' . str_repeat('44', 32));

    $seen = [];

    for ($i = 0; $i < 16 && count($seen) < 2; ++$i) {
        $digest = '0x' . str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
        $signature = $wallet->signDigest($digest);
        $sig = substr($signature, 2);
        $rHex = substr($sig, 0, 64);
        $sHex = substr($sig, 64, 64);

        $v = EcdsaRecovery::deriveV($digest, $rHex, $sHex, $wallet->address());

        $seen[$v] = true;
    }

    expect($seen)->toHaveKeys([27, 28]);
});
