<?php

declare(strict_types=1);

namespace X402\Webhook;

/**
 * Outcome status of an inbound settlement webhook delivery.
 *
 * Mappers parse the facilitator-specific status field via:
 *
 *     WebhookStatus::tryFrom($payload['status']) ?? WebhookStatus::Unknown
 *
 * so unrecognised values degrade to `Unknown` instead of throwing.
 * This preserves static-analysis exhaustiveness on the four canonical
 * cases while leaving forward compat for facilitator-specific extras
 * (e.g. progress events, partial-fill states).
 */
enum WebhookStatus: string
{
    case Settled = 'settled';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    /**
     * Some facilitators emit interim progress webhooks before the
     * terminal state. Adapters typically ignore these — the row stays
     * pending until a terminal status arrives.
     */
    case Pending = 'pending';

    /**
     * Forward-compat fallback for facilitator-specific values not in
     * the canonical set. Mappers SHOULD route to this case via
     * `tryFrom() ?? Unknown` so static analysis on the canonical four
     * stays exhaustive.
     */
    case Unknown = 'unknown';
}
