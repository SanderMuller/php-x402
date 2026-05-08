<?php

declare(strict_types=1);

use X402\Cli\DecodeCommand;
use X402\Cli\Output;
use X402\Protocol\PaymentSignature;

final class RecordingOutput
{
    public string $stdout = '';

    public string $stderr = '';

    public Output $output;

    public function __construct()
    {
        $this->output = new Output(
            stdoutWriter: function (string $s): void {
                $this->stdout .= $s;
            },
            stderrWriter: function (string $s): void {
                $this->stderr .= $s;
            },
        );
    }
}

it('prints help when called without args', function (): void {
    $rec = new RecordingOutput();

    expect(DecodeCommand::run([], $rec->output))->toBe(0)
        ->and($rec->stdout)->toContain('Usage: x402 decode')
        ->and($rec->stderr)->toBe('');
});

it('decodes a real PaymentSignature header', function (): void {
    $signature = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: ['authorization' => ['from' => '0xfrom', 'nonce' => 'abc'], 'signature' => '0xsig'],
    );

    $rec = new RecordingOutput();
    $exit = DecodeCommand::run([$signature->toHeader()], $rec->output);

    expect($exit)->toBe(0)
        ->and($rec->stdout)->toContain('"scheme": "exact"')
        ->and($rec->stdout)->toContain('"network": "eip155:8453"')
        ->and($rec->stdout)->toContain('"from": "0xfrom"');
});

it('exits 2 with stderr on a malformed header', function (): void {
    $rec = new RecordingOutput();
    $exit = DecodeCommand::run(['not-base64-or-anything-valid!!!'], $rec->output);

    expect($exit)->toBe(2)
        ->and($rec->stderr)->toContain('error');
});

it('exits 1 with stderr on empty header value', function (): void {
    $rec = new RecordingOutput();
    $exit = DecodeCommand::run([''], $rec->output);

    expect($exit)->toBe(1)
        ->and($rec->stderr)->toContain('empty');
});
