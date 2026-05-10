<?php

declare(strict_types=1);

use Elliptic\EC;
use X402\Client\KmsWallet;
use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\SignatureVerifier;

/**
 * Test double that fronts simplito/elliptic-php instead of a real KMS,
 * but uses the full abstract pipeline (DER decode + low-s + recovery
 * brute-force + r ‖ s ‖ v packing). Production subclasses substitute
 * Aws\Kms\KmsClient / Google\Cloud\Kms\V1\KeyManagementServiceClient for
 * the simplito calls — the rest of the flow is identical.
 */
final class FakeKmsWallet extends KmsWallet
{
    public int $signCalls = 0;

    public int $publicKeyCalls = 0;

    public ?string $forcedDer = null;

    public function __construct(private readonly string $privateKeyHex) {}

    protected function fetchPublicKeySpki(): string
    {
        ++$this->publicKeyCalls;

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($this->privateKeyHex, 'hex');
        $pubHex = $key->getPublic(false, 'hex');
        $pubBin = hex2bin($pubHex);

        assert(is_string($pubBin) && strlen($pubBin) === 65 && $pubBin[0] === "\x04");

        $alg = "\x30\x10"
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            . "\x06\x05\x2b\x81\x04\x00\x0a";

        $bitString = "\x03\x42\x00" . $pubBin;
        $body = $alg . $bitString;

        return "\x30" . chr(strlen($body)) . $body;
    }

    protected function rawSign(string $digestBytes): string
    {
        ++$this->signCalls;

        if ($this->forcedDer !== null) {
            return $this->forcedDer;
        }

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($this->privateKeyHex, 'hex');
        $sig = $key->sign(bin2hex($digestBytes), false, ['canonical' => true]);

        $derHex = $sig->toDER('hex');
        assert(is_string($derHex));

        $der = hex2bin($derHex);
        assert(is_string($der));

        return $der;
    }
}

it('derives an EVM address that matches PrivateKeyWallet for the same key', function (): void {
    $pk = '0x' . str_repeat('11', 32);
    $reference = new PrivateKeyWallet($pk);
    $kms = new FakeKmsWallet(str_repeat('11', 32));

    expect(strtolower($kms->address()))->toBe(strtolower($reference->address()));
});

it('caches the address — subsequent calls do not hit the KMS again', function (): void {
    $kms = new FakeKmsWallet(str_repeat('22', 32));

    $kms->address();
    $kms->address();
    $kms->address();

    expect($kms->publicKeyCalls)->toBe(1);
});

it('produces a signature that recovers to the wallet address', function (): void {
    $kms = new FakeKmsWallet(str_repeat('33', 32));
    $digest = '0x' . str_repeat('ab', 32);

    $signature = $kms->signDigest($digest);

    $recovered = (new SignatureVerifier())->recover($digest, $signature);

    expect(strtolower($recovered))->toBe(strtolower($kms->address()))
        ->and(strlen($signature))->toBe(132); // 0x + 130 hex chars
});

it('packs r ‖ s ‖ v with v ∈ {27, 28} (EIP-712 typed-data convention)', function (): void {
    $kms = new FakeKmsWallet(str_repeat('44', 32));
    $digest = '0x' . str_repeat('cd', 32);

    $signature = $kms->signDigest($digest);
    $v = (int) hexdec(substr($signature, 130, 2));

    expect($v)->toBeIn([27, 28]);
});

it('produces a signature that resolves to the same address PrivateKeyWallet would for the same key', function (): void {
    // We deliberately do NOT assert byte-identical signatures here. The
    // abstract enforces EIP-2 low-s; PrivateKeyWallet relies on simplito's
    // `canonical: true` flag, which the library quietly clobbers when the
    // wrapper passes options through its KeyPair::sign($enc, $options)
    // signature. KmsWallet's output is always canonical; PrivateKeyWallet's
    // is canonical-when-the-nonce-cooperates. So the two paths agree on
    // recovery but not always on the raw s byte — which is exactly the
    // behaviour you want from a strict KMS wrapper.
    $pkHex = str_repeat('55', 32);
    $kms = new FakeKmsWallet($pkHex);
    $reference = new PrivateKeyWallet('0x' . $pkHex);

    expect(strtolower($kms->address()))->toBe(strtolower($reference->address()));

    $digest = '0x' . str_repeat('ef', 32);
    $sig = $kms->signDigest($digest);
    $recovered = (new SignatureVerifier())->recover($digest, $sig);

    expect(strtolower($recovered))->toBe(strtolower($reference->address()));
});

it('normalises high-s signatures to low-s before recovery', function (): void {
    // Build a signature whose s is in the high half of the curve order,
    // then assert the public path normalises it. We sign normally, flip s
    // to (n - s) to force the high-half value, and re-pack a DER blob with
    // the same r and the flipped s. The abstract must flip it back.
    $pkHex = str_repeat('66', 32);
    $kms = new FakeKmsWallet($pkHex);
    $digest = '0x' . str_repeat('77', 32);

    // Reference signature (already low-s thanks to canonical:true).
    $reference = (new PrivateKeyWallet('0x' . $pkHex))->signDigest($digest);
    $referenceSig = substr($reference, 2);
    $rHex = substr($referenceSig, 0, 64);
    $sLowHex = substr($referenceSig, 64, 64);

    $n = gmp_init('fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141', 16);
    $sHigh = gmp_sub($n, gmp_init($sLowHex, 16));
    $sHighHex = str_pad(gmp_strval($sHigh, 16), 64, '0', STR_PAD_LEFT);

    // Hand-pack DER { r INTEGER, s_high INTEGER } with positivity pads
    // where the high bit is set.
    $packInteger = static function (string $hex): string {
        $bin = hex2bin($hex);
        assert(is_string($bin));
        if ((ord($bin[0]) & 0x80) !== 0) {
            $bin = "\x00" . $bin;
        }

        return "\x02" . chr(strlen($bin)) . $bin;
    };

    $body = $packInteger($rHex) . $packInteger($sHighHex);
    $der = "\x30" . chr(strlen($body)) . $body;

    $kms->forcedDer = $der;

    $signature = $kms->signDigest($digest);
    $producedS = substr($signature, 2 + 64, 64);

    expect($producedS)->toBe($sLowHex);

    // And the produced signature still recovers to the address.
    $recovered = (new SignatureVerifier())->recover($digest, $signature);
    expect(strtolower($recovered))->toBe(strtolower($kms->address()));
});

it('rejects a digest that is not 32-byte hex', function (): void {
    $kms = new FakeKmsWallet(str_repeat('77', 32));

    expect(fn () => $kms->signDigest('0xnothex'))
        ->toThrow(InvalidArgumentException::class, '32-byte hex');
});

it('accepts a digest without the 0x prefix', function (): void {
    $kms = new FakeKmsWallet(str_repeat('88', 32));
    $digest = str_repeat('aa', 32);

    $signature = $kms->signDigest($digest);

    expect($signature)->toStartWith('0x')
        ->and(strlen($signature))->toBe(132);
});

it('rejects a SPKI whose 65-byte point is syntactically valid but not on the secp256k1 curve', function (): void {
    // Build an SPKI with canonical alg + a 65-byte 0x04-prefixed payload of
    // random bytes. The DER parser accepts the syntactic shape; the abstract
    // must catch it via curve-membership validation before deriving an
    // address (Codex review: SPKI parser must not trust a 65-byte 0x04 blob).
    $kms = new class extends KmsWallet {
        protected function fetchPublicKeySpki(): string
        {
            $alg = "\x30\x10"
                . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                . "\x06\x05\x2b\x81\x04\x00\x0a";
            $point = "\x04" . str_repeat("\x01", 64); // off-curve garbage
            $bitString = "\x03\x42\x00" . $point;
            $body = $alg . $bitString;

            return "\x30" . chr(strlen($body)) . $body;
        }

        protected function rawSign(string $digestBytes): string
        {
            throw new RuntimeException('rawSign should not run when the SPKI fails curve membership.');
        }
    };

    expect(fn () => $kms->address())
        ->toThrow(InvalidArgumentException::class, 'secp256k1');
});

it('throws when the rawSign() result does not belong to the configured public key', function (): void {
    $kms = new FakeKmsWallet(str_repeat('99', 32));

    // DER signature from a *different* key for the same digest.
    $ec = new EC('secp256k1');
    $otherKey = $ec->keyFromPrivate(str_repeat('aa', 32), 'hex');
    $digest = '0x' . str_repeat('be', 32);
    $sig = $otherKey->sign(str_repeat('be', 32), false, ['canonical' => true]);
    $derHex = $sig->toDER('hex');
    assert(is_string($derHex));
    $derBin = hex2bin($derHex);
    assert(is_string($derBin));
    $kms->forcedDer = $derBin;

    expect(fn () => $kms->signDigest($digest))
        ->toThrow(InvalidArgumentException::class, 'does not belong to the claimed signer');
});
