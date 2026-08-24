<?php

namespace App\Core\Email888\Contracts;

use App\Core\Email888\States\DeliveryState;
use Illuminate\Support\Carbon;

/**
 * EMAIL888 EM-8 — one inbound provider event, normalised.
 *
 * Everything above the adapter boundary consumes THIS, never a vendor payload.
 * Before it existed, the Postmark webhook controller read `RecordType`,
 * `MessageID`, `DeliveredAt` and `BouncedAt` directly and mapped them inline —
 * which worked, and would have kept working, right up until a second provider
 * arrived with different field names and a different event vocabulary. At that
 * point the mapping would have been duplicated rather than reused, and the
 * duplicate would have drifted.
 *
 * $state is NULL for an event we recognise but do not act on (an open, a click,
 * a subscription change that suppresses nothing). That is deliberately distinct
 * from an event we could not parse at all: one is "understood, no effect", the
 * other is "not understood", and an operator needs to tell them apart.
 */
final class ProviderEvent
{
    /** @param array<string,scalar|null> $evidence operator-safe, never secrets */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerMessageId,
        public readonly ?DeliveryState $state,
        /** The provider's own word for it, retained as evidence only. */
        public readonly string $rawEventType,
        public readonly ?Carbon $occurredAt = null,
        public readonly ?string $recipient = null,
        public readonly array $evidence = [],
    ) {
    }

    /** Understood, but nothing in the ledger changes. */
    public function isActionable(): bool
    {
        return $this->state !== null;
    }

    /**
     * Stable across providers: the same logical event from two vendors produces
     * the same digest, so a redelivery is recognised as a duplicate whoever
     * sent it.
     */
    public function digest(): string
    {
        return hash('sha256', implode('|', [
            $this->provider,
            (string) $this->providerMessageId,
            $this->rawEventType,
            (string) $this->state?->value,
            (string) $this->occurredAt?->toIso8601String(),
        ]));
    }
}
