<?php

declare(strict_types=1);

use X402\Schemes\Evm\Erc6492Decoder;
use X402\Schemes\Evm\SmartWalletSigner;

it('appends the ERC-6492 magic suffix', function (): void {
    $signer = new SmartWalletSigner();

    $wrapped = $signer->wrap(
        factory: '0x' . str_repeat('11', 20),
        factoryCalldata: '0xdeadbeef',
        innerSignature: '0x' . str_repeat('aa', 65),
    );

    expect($signer->isWrapped($wrapped))->toBeTrue();
    expect(Erc6492Decoder::isWrapped($wrapped))->toBeTrue();
});

it('round-trips through Erc6492Decoder', function (): void {
    $factory = '0x' . str_repeat('11', 20);
    $calldata = '0xdeadbeefcafe';
    $inner = '0x' . str_repeat('aa', 65);

    $wrapped = (new SmartWalletSigner())->wrap($factory, $calldata, $inner);
    $decoded = Erc6492Decoder::decode($wrapped);

    expect(strtolower($decoded['factory']))->toBe(strtolower($factory));
    expect($decoded['factoryCalldata'])->toBe($calldata);
    expect($decoded['innerSignature'])->toBe($inner);
});

it('rejects malformed factory address', function (): void {
    (new SmartWalletSigner())->wrap('0x1234', '0x', '0x' . str_repeat('aa', 65));
})->throws(InvalidArgumentException::class, '20-byte address');

it('derives a counterfactual CREATE2 address', function (): void {
    $signer = new SmartWalletSigner();

    $addr = $signer->counterfactualAddress(
        deployer: '0x' . str_repeat('22', 20),
        salt: '0x' . str_repeat('00', 32),
        initCode: '0x6080',
    );

    expect($addr)->toStartWith('0x')->and(\strlen($addr))->toBe(42);
});
