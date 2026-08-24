<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;

/** S8 — the embeddable public chatbot. Unauthenticated by design (clause Z-06 still applies). */
class S8PublicChatbotSurface extends ChatSurface
{
    public function key(): string  { return 's8_public_chatbot'; }
    public function name(): string { return 'S8 Public Chatbot Widget (chatbot-widget.js)'; }
    public function channelClass(): string { return 'durable_visitor'; }

    public function sourceFiles(): array
    {
        return ['public/chatbot-widget.js', 'app/Engines/Chatbot/Services/ChatbotResponseService.php'];
    }

    public function sendRoute(): ?string  { return '/api/public/chatbot/message'; }
    public function storeTable(): ?string { return 'chatbot_messages'; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array  { return ['reply']; }
    public function creditService(): ?string { return null; }  // flat CREDIT_COST_PER_MESSAGE constant

    public function capabilities(): array
    {
        return [
            Cap::SEND               => true,
            Cap::PERSIST_USER       => true,
            Cap::PERSIST_ASSISTANT  => true,
            Cap::HISTORY            => true,
            Cap::PAGINATION         => false,
            Cap::ACK                => false,
            Cap::TWO_PHASE          => false,
            Cap::BACKGROUND_REFRESH => false,
            Cap::READ_STATE         => false,
            Cap::READ_ALL           => false,
            Cap::UNREAD_AGGREGATE   => false,
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => false,
            Cap::STRUCTURED_ERROR   => false,
            // P2-C: proven behaviourally by ChatbotIdempotencyTest (13 tests) and
            // ProcessParallelTest (10 tests, real OS processes). Declared true
            // only after that proof — never in anticipation.
            Cap::IDEMPOTENCY        => true,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => false,  // needs a provisioned widget token fixture
            Cap::TENANCY_COLUMN     => true,
        ];
    }

    public function notes(): array
    {
        return [
            'Unauthenticated BY DESIGN — visitor-facing. Authorization is a chatbot_widget_tokens check, not a user session.',
            'Charges a flat 1 credit via a class constant: a third credit model.',
            'Not HTTP-probed: requires a provisioned widget token the harness does not mint in P1.',
            'P2-C (2026-07-27): ChatbotResponseService adopts the shared P2-B primitive. A duplicate submission replays the first answer without re-executing, re-persisting or re-charging. When the widget sends no key, a canonical server-side key is derived from (session, normalised message, 60s bucket).',
            'Charging is DELEGATED: the pipeline keeps its own correct reserve/commit/release cycle and the coordinator records linkage only — otherwise one request would be billed twice.',
        ];
    }
}
