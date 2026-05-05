<?php

declare(strict_types=1);

namespace X402\Client;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * Reference Wallet implementation that signs in-process with a raw private
 * key. Suitable for tests, CLI tooling, and development. For production,
 * prefer a KMS-backed Wallet implementation.
 */
final class PrivateKeyWallet implements Wallet
{
    private string $address;

    private string $privateKeyHex;

    public function __construct(string $privateKey)
    {
        $hex = str_starts_with($privateKey, '0x') ? substr($privateKey, 2) : $privateKey;

        if (\strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new \InvalidArgumentException('Private key must be 32 bytes hex.');
        }

        $this->privateKeyHex = $hex;
        $this->address = $this->deriveAddress($hex);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function signDigest(string $digest): string
    {
        $hex = str_starts_with($digest, '0x') ? substr($digest, 2) : $digest;

        $ec = new EC('secp256k1');
        $signingKey = $ec->keyFromPrivate($this->privateKeyHex, 'hex');
        $sig = $signingKey->sign($hex, ['canonical' => true]);

        $r = str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = str_pad(dechex($sig->recoveryParam + 27), 2, '0', STR_PAD_LEFT);

        return '0x'.$r.$s.$v;
    }

    private function deriveAddress(string $privateKeyHex): string
    {
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKeyHex, 'hex');

        /** @var string $pubHex */
        $pubHex = $key->getPublic(false, 'hex');

        // Drop leading 0x04 marker.
        $stripped = substr($pubHex, 2);
        $hash = Keccak::hash(hex2bin($stripped), 256);

        return '0x'.substr($hash, 24);
    }
}
