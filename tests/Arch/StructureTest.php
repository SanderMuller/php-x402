<?php

declare(strict_types=1);

use X402\Client\KmsWallet;
use X402\Client\Wallet;
use X402\Exceptions\X402Exception;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\Replay\NonceStoreContract;
use X402\Schemes\SchemeContract;
use X402\Server\PriceTable;

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| Locks structural invariants that the rest of the suite can't reach.
|
| Run on every PR: drift here means the package's contract has slipped.
*/

arch('php-x402 stays framework-agnostic')
    ->expect('X402')
    ->not->toUse([
        'Illuminate',
        'Symfony\Component\HttpFoundation',
        'Laravel\Mcp',
    ]);

arch('every src class declares strict types')
    ->expect('X402')
    ->toUseStrictTypes();

arch('no debug helpers ship in production')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'xdebug_break'])
    ->not->toBeUsed();

arch('protocol DTOs are readonly')
    ->expect('X402\Protocol')
    ->classes()
    ->toBeReadonly();

arch('facilitator results are readonly')
    ->expect([
        VerifyResult::class,
        SettleResult::class,
    ])
    ->toBeReadonly();

arch('exceptions extend X402Exception')
    ->expect('X402\Exceptions')
    ->classes()
    ->toExtend(X402Exception::class);

arch('contracts are interfaces')
    ->expect([
        SchemeContract::class,
        NonceStoreContract::class,
        PriceTable::class,
        FacilitatorClient::class,
        Wallet::class,
    ])
    ->toBeInterfaces();

arch('concrete classes are final')
    ->expect('X402')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        X402Exception::class, // base class for InvalidPaymentException etc.
        KmsWallet::class, // abstract — subclasses (AwsKmsWallet, GcpKmsWallet, …) are final.
    ]);
