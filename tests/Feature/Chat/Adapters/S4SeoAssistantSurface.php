<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;
use Tests\Feature\Chat\Support\ChatTestContext;
use Tests\Feature\Chat\Support\ProbeResponse;

/** S4 — the SEO assistant. Own store, own badge, own error vocabulary. */
class S4SeoAssistantSurface extends ChatSurface
{
    public function key(): string  { return 's4_seo_assistant'; }
    public function name(): string { return 'S4 SEO Assistant (seo.js)'; }
    public function channelClass(): string { return 'durable_assistant'; }

    public function sourceFiles(): array
    {
        return ['public/app/js/seo.js', 'app/Engines/SEO/Services/SeoAssistantService.php', 'routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function sendRoute(): ?string    { return '/api/connector/assistant/message'; }
    public function historyRoute(): ?string { return '/api/seo/assistant/history'; }
    public function storeTable(): ?string   { return 'seo_assistant_messages'; }
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array  { return ['reply']; }
    public function creditService(): ?string { return 'App\\Core\\Billing\\CreditService'; }
    public function readStateScope(): ?string { return null; }

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
            Cap::READ_STATE         => false,  // seo_assistant_notifications is a separate badge
            Cap::READ_ALL           => false,
            Cap::UNREAD_AGGREGATE   => false,
            Cap::CREDITS            => true,
            Cap::CREDIT_RESERVE     => true,
            Cap::STRUCTURED_ERROR   => false,
            // P2-B: proven behaviourally by SeoIdempotencyIntegrationTest (9 tests) —
            // duplicate submissions do not re-meter, do not re-persist, and replay
            // the original outcome. Declared true only after that proof.
            Cap::IDEMPOTENCY        => true,
            Cap::CORRELATION        => false,
            Cap::PROVIDER_ATTR      => false,
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => true,
            Cap::TENANCY_COLUMN     => true,
        ];
    }

    public function send(ChatTestContext $ctx, string $content, array $opts = []): ?ProbeResponse
    {
        // CORRECTION (P2-A): P1 recorded this route as unreachable because it is
        // guarded by ApiKeyAuth. That was wrong — ApiKeyAuth explicitly falls
        // through to JwtAuthMiddleware when no X-API-KEY is present but a Bearer
        // token is, to preserve the SPA's direct-mode contract. The harness's
        // JWT therefore reaches it, so these cases are now genuinely measured
        // instead of being recorded as `blocked`.
        $this->beginProbe($ctx);
        $payload = array_merge(['message' => $content], $opts);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->postJson($this->sendRoute(), $payload);
        $body = $resp->json();

        return new ProbeResponse($resp->getStatusCode(), is_array($body) ? $body : []);
    }

    public function history(ChatTestContext $ctx, array $opts = []): ?array
    {
        $this->beginProbe($ctx);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->getJson($this->historyRoute());
        $body = $resp->json();

        return is_array($body) ? $body : null;
    }

    public function notes(): array
    {
        return [
            'Maintains a third, unrelated unread badge and hardcodes POST /messages/james/read to clear it.',
            'Has no conversation identity: messages are flat per (workspace_id, user_id).',
            'P2-A (2026-07-26): a credit refusal now persists the user message to seo_assistant_messages (clause P-02) and reports it in chat_error.persistence.',
            'P2-B (2026-07-27): idempotency gate live behind CHAT_IDEMPOTENCY_SURFACES. An optional idempotency_key makes a duplicate submission replay the first outcome without re-metering or re-persisting.',
        ];
    }
}
