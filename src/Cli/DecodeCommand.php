<?php

declare(strict_types=1);

namespace X402\Cli;

use Throwable;
use X402\Exceptions\InvalidPaymentException;
use X402\Protocol\PaymentSignature;

/**
 * Decode a base64-encoded `PAYMENT-SIGNATURE` (v2) or `X-PAYMENT` (v1)
 * header value and dump the parsed envelope as pretty-printed JSON.
 *
 * Used by the `bin/x402` CLI for protocol debugging — when a 402
 * response confuses someone, paste the header into `bin/x402 decode`
 * and see exactly what the client sent.
 */
final class DecodeCommand
{
    /**
     * @param  list<string>  $args  Argv slice after the subcommand name.
     */
    public static function run(array $args, Output $output): int
    {
        if ($args === [] || in_array($args[0], ['-h', '--help'], strict: true)) {
            $output->stdout(<<<'TXT'
Usage: x402 decode <header-value>
       echo <header-value> | x402 decode -

Decodes an x402 PAYMENT-SIGNATURE / X-PAYMENT header into pretty-printed JSON.
The header value is the base64 string the client sends — version, scheme,
network, and the per-scheme payload (authorization + signature for `exact`).

Examples:
  x402 decode "eyJ4NDAyVmVyc2lvbiI6MS4uLn0="
  curl -sI https://api.example.com/paid | grep -i x-payment | cut -d: -f2 | x402 decode -

TXT);

            return 0;
        }

        $headerValue = $args[0] === '-' ? trim((string) fgets(STDIN)) : $args[0];

        if ($headerValue === '') {
            $output->stderr("error: empty header value\n");

            return 1;
        }

        try {
            $signature = PaymentSignature::fromHeader($headerValue);
        } catch (InvalidPaymentException $e) {
            $output->stderr(sprintf("error: invalid payment header — %s\n", $e->getMessage()));

            return 2;
        } catch (Throwable $e) {
            $output->stderr(sprintf("error: decode failed — %s\n", $e->getMessage()));

            return 2;
        }

        $output->stdout(json_encode(
            $signature->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n");

        return 0;
    }
}
