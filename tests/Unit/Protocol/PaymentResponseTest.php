<?php

declare(strict_types=1);

use X402\Protocol\PaymentResponse;

it('round-trips through header encoding', function (): void {
    $original = new PaymentResponse(
        success: true,
        transaction: '0xabc123',
        network: 'eip155:8453',
        payer: '0x000000000000000000000000000000000000beef',
    );

    $bin = base64_decode($original->toHeader(), true);
    $decoded = json_decode($bin === false ? '' : $bin, true);

    expect($decoded)->toBe([
        'success' => true,
        'transaction' => '0xabc123',
        'network' => 'eip155:8453',
        'payer' => '0x000000000000000000000000000000000000beef',
    ]);
});

it('serialises failures with success=false', function (): void {
    $response = new PaymentResponse(
        success: false,
        transaction: '',
        network: 'eip155:8453',
        payer: '0xabc',
    );

    expect($response->toArray()['success'])->toBeFalse();
});
