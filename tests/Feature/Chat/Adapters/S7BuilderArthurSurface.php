<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;

/**
 * S7 — the Builder / Arthur chat.
 *
 * This is the surface that produced the "Hi ✦ Assistant <svg…> Not enough
 * credits" output: a credit refusal rendered through the assistant message path
 * with an icon helper's SVG concatenated onto the front of it.
 */
class S7BuilderArthurSurface extends ChatSurface
{
    public function key(): string  { return 's7_builder_arthur'; }
    public function name(): string { return 'S7 Builder / Arthur (builder.js + arthur-chat.js)'; }
    public function channelClass(): string { return 'ephemeral_tool'; }

    public function sourceFiles(): array
    {
        return ['public/app/js/builder.js', 'public/app/js/arthur-chat.js'];
    }

    public function sendRoute(): ?string { return '/api/builder/arthur/message'; }
    public function storeTable(): ?string { return null; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array { return ['message', 'text']; }
    public function creditService(): ?string { return 'App\\Core\\Billing\\CreditService'; }

    public function capabilities(): array
    {
        return [
            Cap::SEND               => true,
            Cap::PERSIST_USER       => false,
            Cap::PERSIST_ASSISTANT  => false,
            Cap::HISTORY            => false,
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
            Cap::IDEMPOTENCY        => false,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => true,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => true,   // measured: answers under a plain JWT
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function notes(): array
    {
        return [
            'Ephemeral: the conversation is lost on reload.',
            'CR-01 origin: builder.js renders caught errors through bld_aiAddMsg("assistant", …) with window.icon() prefixed.',
            'HTTP-probed: POST /api/builder/arthur/message answers under a plain JWT and returns a bare {reply}.',
        ];
    }
}
