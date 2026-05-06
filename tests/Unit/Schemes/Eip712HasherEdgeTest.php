<?php

declare(strict_types=1);

use X402\Schemes\Evm\Eip712Hasher;

it('rejects an invalid EVM address (wrong length)', function (): void {
    (new Eip712Hasher())->digest(
        ['name' => 'USD Coin', 'version' => '2', 'chainId' => 8453, 'verifyingContract' => '0xtoo_short'],
        ['from' => '0x' . str_repeat('a', 40), 'to' => '0x' . str_repeat('b', 40), 'value' => '1', 'validAfter' => 0, 'validBefore' => 9999999999, 'nonce' => '0x' . str_repeat('0', 64)],
    );
})->throws(InvalidArgumentException::class, 'Invalid EVM address');

it('rejects a malformed nonce (wrong length)', function (): void {
    (new Eip712Hasher())->digest(
        ['name' => 'USD Coin', 'version' => '2', 'chainId' => 8453, 'verifyingContract' => '0x' . str_repeat('a', 40)],
        ['from' => '0x' . str_repeat('a', 40), 'to' => '0x' . str_repeat('b', 40), 'value' => '1', 'validAfter' => 0, 'validBefore' => 9999999999, 'nonce' => '0xshort'],
    );
})->throws(InvalidArgumentException::class, 'bytes32');

it('rejects a negative chainId', function (): void {
    (new Eip712Hasher())->digest(
        ['name' => 'USD Coin', 'version' => '2', 'chainId' => -1, 'verifyingContract' => '0x' . str_repeat('a', 40)],
        ['from' => '0x' . str_repeat('a', 40), 'to' => '0x' . str_repeat('b', 40), 'value' => '1', 'validAfter' => 0, 'validBefore' => 9999999999, 'nonce' => '0x' . str_repeat('0', 64)],
    );
})->throws(InvalidArgumentException::class, 'non-negative');

it('encodes value=0 without crashing on empty trim', function (): void {
    $digest = (new Eip712Hasher())->digest(
        ['name' => 'USD Coin', 'version' => '2', 'chainId' => 8453, 'verifyingContract' => '0x' . str_repeat('a', 40)],
        ['from' => '0x' . str_repeat('a', 40), 'to' => '0x' . str_repeat('b', 40), 'value' => '0', 'validAfter' => 0, 'validBefore' => 9999999999, 'nonce' => '0x' . str_repeat('0', 64)],
    );

    expect($digest)->toMatch('/^0x[0-9a-f]{64}$/');
});
