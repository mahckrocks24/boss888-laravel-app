<?php

namespace App\Core\Engineer888\Conversation\Providers;

use App\Core\Engineer888\Conversation\ConversationProvider;
use App\Core\Engineer888\Conversation\ConversationReply;
use App\Core\Engineer888\Conversation\ConversationRequest;

/**
 * The provider a fresh environment gets.
 *
 * It never answers, and it never pretends to. That is the whole point: a new
 * environment that has chosen no provider must not start making paid calls, and
 * must not emit a plausible sentence about engineering state either. Chat
 * degrades to what it can read from the database, and says so plainly.
 */
final class NullConversationProvider implements ConversationProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'null';
    }

    public function describe(): array
    {
        return ['model' => 'none', 'endpoint' => 'none'];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'no conversation provider is configured (E888_CONVERSATION_PROVIDER)';
    }

    public function converse(ConversationRequest $request): ConversationReply
    {
        return ConversationReply::failure($this->name(), 'none', (string) $this->unavailableReason());
    }
}
