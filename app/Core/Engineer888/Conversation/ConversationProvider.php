<?php

namespace App\Core\Engineer888\Conversation;

/**
 * Anything that can hold an engineering conversation.
 *
 * Narrow on purpose, and shaped exactly like ReasoningProvider so the two read
 * as one house style. A provider answers, reports whether it can answer at all,
 * and says why not when it cannot. It is handed no repository, no candidate, no
 * approval and no write path — see the Conversation namespace README note in
 * ConversationEngine for why that boundary is mechanical rather than trusted.
 */
interface ConversationProvider
{
    /** Stable key, matching the config map. */
    public function name(): string;

    /** Model and endpoint, for evidence and metering. */
    public function describe(): array;

    /** Can this provider answer right now? Missing credentials is a fact, not an error. */
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;

    public function converse(ConversationRequest $request): ConversationReply;
}
