<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;
use Tests\Feature\Chat\Support\ChatTestContext;
use Tests\Feature\Chat\Support\ProbeResponse;

/**
 * S5 — Studio design chat.
 *
 * Declared ephemeral because it genuinely persists nothing. That declaration is
 * NOT an excuse: clause P-11 requires the UI not to imply durability, and clause
 * F-06 still prohibits the keyword fallback this surface uses when the runtime
 * is unreachable.
 */
class S5StudioChatSurface extends ChatSurface
{
    public function key(): string  { return 's5_studio_chat'; }
    public function name(): string { return 'S5 Studio Chat (studio.js)'; }
    public function channelClass(): string { return 'ephemeral_tool'; }

    public function sourceFiles(): array
    {
        return ['public/app/js/studio.js', 'routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function sendRoute(): ?string { return '/api/studio/chat'; }
    public function storeTable(): ?string { return null; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array { return ['reply']; }
    // P2-A finding F-1: App\Services\CreditService DOES NOT EXIST. The route
    // guards its charge with class_exists() on that name, which is always
    // false — so this surface has never charged anything.
    public function creditService(): ?string { return null; }

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
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => false,  // measured: 422 missing_input without a studio_designs fixture
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function send(ChatTestContext $ctx, string $content, array $opts = []): ?ProbeResponse
    {
        $this->beginProbe($ctx);
        $payload = array_merge(['message' => $content, 'history' => []], $opts);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->postJson($this->sendRoute(), $payload);
        $body = $resp->json();

        return new ProbeResponse($resp->getStatusCode(), is_array($body) ? $body : []);
    }

    public function notes(): array
    {
        return [
            'EPHEMERAL BY IMPLEMENTATION, NOT BY DESIGN DECISION: nothing is stored and history lives only in a client-side slice(-6).',
            'P2-A finding F-1: App\Services\CreditService DOES NOT EXIST, so the class_exists() guard is always false and this surface has NEVER charged anything. The P1 audit claim of a "flat 1 credit via a second CreditService class" was wrong — there is only one CreditService.',
            'CR-03 CLOSED (P2-A 2026-07-26): the keyword fallback is removed; an unreachable runtime now returns 503 CHAT_PROVIDER_UNAVAILABLE.',
            'Not HTTP-probed: POST /api/studio/chat returns 422 missing_input without a design_id, and the harness does not create design fixtures in P1. Evidence for this surface is source-level.',
        ];
    }
}
