<?php

declare(strict_types=1);

namespace X402\PaymentHistory;

use DateTimeImmutable;
use X402\Facilitator\PaymentOutcome;
use X402\Facilitator\PaymentOutcomeKind;
use X402\Facilitator\SettleResult;
use X402\Protocol\PaymentRequired;

/**
 * Build a flat payment-row array from a `PaymentOutcome` for direct
 * insert into a payment-history table.
 *
 * Returned array shape (matches the laravel-x402-shipped migration's
 * `x402_payments` columns byte-for-byte):
 *
 *     status         string   'settled' | 'rejected'
 *     resource       string   $outcome->resource verbatim
 *     payer          ?string
 *     pay_to         string   $challenge->payTo
 *     amount         string   atomic units; settled-rows prefer SettleResult->amount, fall back to challenge
 *     asset          string   $challenge->asset
 *     network        string   settled-rows prefer SettleResult->network, fall back to challenge
 *     transaction    ?string  null on rejected; null on settled when SettleResult->transaction === ''
 *     nonce          ?string  EIP-3009 nonce or scheme-specific equivalent
 *     reason         ?string  null on settled; truncated to $reasonMaxLength on rejected
 *     extensions     array    settled = SettleResult->extensions ?? []; rejected = signature->extensions ?? []
 *     meta           array    $context verbatim
 *     settled_at     ?DateTimeImmutable  null on rejected; $now on settled
 *
 * Adopters using Eloquent feed the array straight into
 * `Payment::query()->updateOrCreate(['transaction' => ...], $row)`.
 * Doctrine / PDO callers map the keys to their column names.
 *
 * **Resource source.** The row's `resource` column is `$outcome->resource`
 * verbatim — `DispatchingFacilitator` already formatted it via the
 * optional `resourceFormatter` closure at outcome-construction time.
 *
 * **Scheme-aware nonce / payer extraction.** Pass a pre-extracted
 * `replayKey` from the matched `ReplayKeyExtractor` so non-EIP-3009
 * schemes (Permit2, Upto, Erc7710) get correct idempotency keying.
 * When `replayKey` is null the builder falls back to
 * `PaymentSignature::authorization()` — fine for the EIP-3009 exact
 * path, known-incomplete for newer schemes (their authorization()
 * returns null because the payload uses different keys).
 *
 * **Reason length.** Truncated to `$reasonMaxLength` via `mb_substr`
 * before insertion. Default `255` matches the laravel-x402-shipped
 * migration's `string(255)` column. Without truncation a long
 * exception message (HTTP transport failure, JSON parse error)
 * exceeds the column → DB write fails → audit row never persists,
 * after the payment path has already failed once.
 */
final readonly class PaymentRowBuilder
{
    public const STATUS_SETTLED = 'settled';

    public const STATUS_REJECTED = 'rejected';

    public const DEFAULT_REASON_MAX_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $context
     * @param  array{from: string, nonce: string, expiresAt: int}|null  $replayKey
     * @return array<string, mixed>
     */
    public static function fromOutcome(
        PaymentOutcome $outcome,
        array $context = [],
        ?array $replayKey = null,
        int $reasonMaxLength = self::DEFAULT_REASON_MAX_LENGTH,
        ?DateTimeImmutable $now = null,
    ): array {
        [$from, $nonce] = self::resolveFromAndNonce($outcome, $replayKey);

        if ($outcome->kind === PaymentOutcomeKind::SettleSucceeded && $outcome->settle instanceof SettleResult) {
            return self::settledRow($outcome->challenge, $outcome->resource, $outcome->settle, $context, $from, $nonce, $now ?? new DateTimeImmutable());
        }

        return self::rejectedRow($outcome, $context, $from, $nonce, $reasonMaxLength);
    }

    /**
     * @param  array{from: string, nonce: string, expiresAt: int}|null  $replayKey
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolveFromAndNonce(PaymentOutcome $outcome, ?array $replayKey): array
    {
        if ($replayKey !== null) {
            return [$replayKey['from'], $replayKey['nonce']];
        }

        $auth = $outcome->signature->authorization();

        return [$auth['from'] ?? null, $auth['nonce'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function settledRow(
        PaymentRequired $challenge,
        string $resource,
        SettleResult $settle,
        array $context,
        ?string $from,
        ?string $nonce,
        DateTimeImmutable $now,
    ): array {
        return [
            'status' => self::STATUS_SETTLED,
            'resource' => $resource,
            'payer' => $settle->payer !== '' ? $settle->payer : $from,
            'pay_to' => $challenge->payTo,
            'amount' => $settle->amount ?? $challenge->amount,
            'asset' => $challenge->asset,
            'network' => $settle->network !== '' ? $settle->network : $challenge->network,
            'transaction' => $settle->transaction !== '' ? $settle->transaction : null,
            'nonce' => $nonce,
            'reason' => null,
            'extensions' => $settle->extensions ?? [],
            'meta' => $context,
            'settled_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function rejectedRow(
        PaymentOutcome $outcome,
        array $context,
        ?string $from,
        ?string $nonce,
        int $reasonMaxLength,
    ): array {
        $challenge = $outcome->challenge;

        $reason = $outcome->reason ?? '';
        if ($reason !== '' && $reasonMaxLength > 0) {
            $reason = mb_substr($reason, 0, $reasonMaxLength);
        }

        return [
            'status' => self::STATUS_REJECTED,
            'resource' => $outcome->resource,
            'payer' => $from,
            'pay_to' => $challenge->payTo,
            'amount' => $challenge->amount,
            'asset' => $challenge->asset,
            'network' => $challenge->network,
            'transaction' => null,
            'nonce' => $nonce,
            'reason' => $reason !== '' ? $reason : null,
            'extensions' => $outcome->signature->extensions ?? [],
            'meta' => $context,
            'settled_at' => null,
        ];
    }
}
