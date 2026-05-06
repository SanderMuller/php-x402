<?php

declare(strict_types=1);

use X402\Replay\InMemoryNonceStore;

it('treats different "from" addresses as independent claims', function (): void {
    $store = new InMemoryNonceStore();

    $store->claim('eip155:8453', '0xabc', '0xdead', 60);

    expect($store->claim('eip155:8453', '0xdef', '0xdead', 60))->toBeTrue();
});
