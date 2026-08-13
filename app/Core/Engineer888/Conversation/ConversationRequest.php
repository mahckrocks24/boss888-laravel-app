<?php

namespace App\Core\Engineer888\Conversation;

/**
 * One conversational turn, as the provider will see it.
 *
 * IMMUTABLE AND PROVIDER-SHAPED-BY-NOBODY. The request carries a system prompt,
 * the assembled engineering frame, recent turns and the thing the human just
 * said. It contains no vendor concepts — no model name, no temperature, no
 * message-role vocabulary — because the moment a request knows how OpenAI
 * structures a call, swapping the provider stops being a config change.
 */
final class ConversationRequest
{
    /**
     * @param array<int,array{role:string,body:string}> $history oldest first
     */
    public function __construct(
        public readonly string $persona,
        public readonly string $frame,
        public readonly array $history,
        public readonly string $turn,
    ) {}

    /** The system side of the call: who Engineer888 is, plus what it knows. */
    public function systemText(): string
    {
        return $this->frame === ''
            ? $this->persona
            : $this->persona . "\n\n===== CURRENT ENGINEERING STATE =====\n" . $this->frame;
    }

    /**
     * Recent turns, normalised to two roles.
     *
     * `system` messages (project changes) are folded into the assistant voice
     * rather than dropped: "active project changed" is context the model needs
     * to understand a later "and what about that one".
     */
    public function normalisedHistory(): array
    {
        $out = [];

        foreach ($this->history as $m) {
            $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
            $body = trim((string) ($m['body'] ?? ''));

            if ($body === '') { continue; }

            $out[] = ['role' => $role, 'body' => $body];
        }

        return $out;
    }
}
