<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;
use Tests\Feature\Chat\Support\ChatTestContext;
use Tests\Feature\Chat\Support\ProbeResponse;

/**
 * S11 — Aria, the in-app AI assistant at POST /api/ai/assistant.
 *
 * This is the surface whose credit refusal the customer saw rendered as
 * "Hi ✦ Assistant <svg…> Not enough credits — chat costs 0.1 credit …".
 * The refusal copy lives here; the markup leak lives in the Builder client that
 * displays it (S7).
 */
class S11AriaAssistantSurface extends ChatSurface
{
    public function key(): string  { return 's11_aria_assistant'; }
    public function name(): string { return 'S11 Aria In-App Assistant (/api/assistant)'; }
    public function channelClass(): string { return 'durable_assistant'; }

    public function sourceFiles(): array
    {
        return ['routes/api.php', ...\Tests\Support\RouteSource::relativeModules()];
    }

    public function sendRoute(): ?string { return '/api/assistant'; }
    public function storeTable(): ?string { return null; }   // no dedicated store
    public function requestContentField(): ?string { return 'message'; }
    public function legacyResponseFields(): array  { return ['response']; }
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
            Cap::ATTACHMENTS        => false,
            Cap::TOOL_CALLS         => false,
            Cap::APPROVALS          => false,
            Cap::HTTP_PROBE         => true,
            Cap::TENANCY_COLUMN     => false,
        ];
    }

    public function send(ChatTestContext $ctx, string $content, array $opts = []): ?ProbeResponse
    {
        $this->beginProbe($ctx);
        $payload = array_merge(['message' => $content, 'context' => [], 'history' => []], $opts);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->postJson($this->sendRoute(), $payload);
        $body = $resp->json();

        return new ProbeResponse($resp->getStatusCode(), is_array($body) ? $body : []);
    }

    public function notes(): array
    {
        return [
            'Declared durable_assistant but persists nothing: a conversation is lost on reload and a refused message is discarded (P-01/P-02 failures, CR-04).',
            'Credit refusal copy at routes/api.php:12748 quotes the internal metering formula to the customer.',
        ];
    }
}
