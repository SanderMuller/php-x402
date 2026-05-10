<?php

declare(strict_types=1);

use Aws\Kms\KmsClient;
use Aws\Result;
use Elliptic\EC;
use Mockery\MockInterface;
use X402\Client\AwsKmsWallet;
use X402\Client\PrivateKeyWallet;
use X402\Schemes\Evm\SignatureVerifier;

function awsSpkiFor(string $privateKeyHex): string
{
    $ec = new EC('secp256k1');
    $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
    $pubHex = $key->getPublic(false, 'hex');
    $pubBin = hex2bin($pubHex);
    assert(is_string($pubBin) && strlen($pubBin) === 65);

    $alg = "\x30\x10"
        . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        . "\x06\x05\x2b\x81\x04\x00\x0a";
    $bitString = "\x03\x42\x00" . $pubBin;
    $body = $alg . $bitString;

    return "\x30" . chr(strlen($body)) . $body;
}

function awsDerSignature(string $privateKeyHex, string $digestHex): string
{
    $ec = new EC('secp256k1');
    $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
    $sig = $key->sign($digestHex, false, ['canonical' => true]);
    $der = $sig->toDER('hex');
    assert(is_string($der));
    $bin = hex2bin($der);
    assert(is_string($bin));

    return $bin;
}

it('passes the configured KeyId + DIGEST + ECDSA_SHA_256 parameters to kms:Sign', function (): void {
    $keyId = 'arn:aws:kms:eu-west-1:111:key/abcd-ef';
    $pkHex = str_repeat('11', 32);
    $digest = '0x' . str_repeat('aa', 32);
    $digestBytes = hex2bin(substr($digest, 2));

    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->once()
        ->with(['KeyId' => $keyId])
        ->andReturn(new Result(['PublicKey' => awsSpkiFor($pkHex)]));

    $kms->shouldReceive('sign')
        ->once()
        ->with([
            'KeyId' => $keyId,
            'Message' => $digestBytes,
            'MessageType' => 'DIGEST',
            'SigningAlgorithm' => 'ECDSA_SHA_256',
        ])
        ->andReturn(new Result(['Signature' => awsDerSignature($pkHex, substr($digest, 2))]));

    $wallet = new AwsKmsWallet($kms, $keyId);
    $signature = $wallet->signDigest($digest);

    expect(strlen($signature))->toBe(132);
});

it('produces a signature that recovers to the wallet address (round-trip via mocked KMS)', function (): void {
    $keyId = 'test-key';
    $pkHex = str_repeat('22', 32);
    $digest = '0x' . str_repeat('bc', 32);

    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->andReturn(new Result(['PublicKey' => awsSpkiFor($pkHex)]));
    $kms->shouldReceive('sign')
        ->andReturn(new Result(['Signature' => awsDerSignature($pkHex, substr($digest, 2))]));

    $wallet = new AwsKmsWallet($kms, $keyId);
    $signature = $wallet->signDigest($digest);

    $recovered = (new SignatureVerifier())->recover($digest, $signature);

    expect(strtolower($recovered))->toBe(strtolower($wallet->address()));
});

it('derives the same EVM address PrivateKeyWallet would for the configured key', function (): void {
    // We deliberately do NOT compare signatures byte-for-byte against
    // PrivateKeyWallet — the abstract enforces EIP-2 low-s, whereas
    // PrivateKeyWallet's canonical-s flag depends on simplito's option
    // pass-through (which is order-sensitive and lossy). Both paths
    // address-derive identically; signatures agree on recovery but not
    // always on the raw s byte. Test the address invariant directly.
    $pkHex = str_repeat('33', 32);

    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->andReturn(new Result(['PublicKey' => awsSpkiFor($pkHex)]));

    $wallet = new AwsKmsWallet($kms, 'k');
    $reference = new PrivateKeyWallet('0x' . $pkHex);

    expect(strtolower($wallet->address()))->toBe(strtolower($reference->address()));
});

it('caches the derived address — only one kms:GetPublicKey call across many address() invocations', function (): void {
    $pkHex = str_repeat('44', 32);

    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->once()
        ->andReturn(new Result(['PublicKey' => awsSpkiFor($pkHex)]));

    $wallet = new AwsKmsWallet($kms, 'k');

    $wallet->address();
    $wallet->address();
    $wallet->address();
});

it('throws RuntimeException when GetPublicKey returns no PublicKey blob', function (): void {
    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->andReturn(new Result([]));

    $wallet = new AwsKmsWallet($kms, 'missing-key');

    expect(fn () => $wallet->address())
        ->toThrow(RuntimeException::class, 'GetPublicKey returned no PublicKey blob');
});

it('throws RuntimeException when Sign returns no Signature blob', function (): void {
    $pkHex = str_repeat('55', 32);

    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->andReturn(new Result(['PublicKey' => awsSpkiFor($pkHex)]));
    $kms->shouldReceive('sign')
        ->andReturn(new Result([]));

    $wallet = new AwsKmsWallet($kms, 'k');

    expect(fn () => $wallet->signDigest('0x' . str_repeat('ee', 32)))
        ->toThrow(RuntimeException::class, 'Sign returned no Signature blob');
});

it('throws RuntimeException when GetPublicKey returns an empty-string PublicKey', function (): void {
    /** @var KmsClient&MockInterface $kms */
    $kms = Mockery::mock(KmsClient::class);
    $kms->shouldReceive('getPublicKey')
        ->andReturn(new Result(['PublicKey' => '']));

    $wallet = new AwsKmsWallet($kms, 'empty-key');

    expect(fn () => $wallet->address())
        ->toThrow(RuntimeException::class, 'GetPublicKey returned no PublicKey blob');
});
