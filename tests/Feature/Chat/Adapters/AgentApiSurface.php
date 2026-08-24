<?php

namespace Tests\Feature\Chat\Adapters;

use Tests\Feature\Chat\Support\Cap;
use Tests\Feature\Chat\Support\ChatSurface;
use Tests\Feature\Chat\Support\ChatTestContext;
use Tests\Feature\Chat\Support\ProbeResponse;

/**
 * Shared behaviour for the three surfaces that sit on the agent chat API.
 *
 * S1 (drawer), S2 (widget modal) and S3 (messages page) all POST to
 * /api/agents/{slug}/messages and read the same agent_messages store. They
 * differ ONLY in their frontend bundle and the client-side capabilities that
 * bundle implements — which is precisely why a single normative suite can
 * distinguish them.
 */
abstract class AgentApiSurface extends ChatSurface
{
    protected string $agentSlug = 'sarah';

    public function channelClass(): string { return 'durable_agent'; }
    public function sendRoute(): ?string { return '/api/agents/{slug}/messages'; }
    public function historyRoute(): ?string { return '/api/agents/{slug}/messages'; }
    public function storeTable(): ?string { return 'agent_messages'; }
    public function storeContentColumn(): string { return 'content'; }
    public function requestContentField(): ?string { return 'content'; }
    public function legacyResponseFields(): array { return ['ack']; }
    public function readStateScope(): ?string { return 'workspace'; }
    public function readStateLimitation(): ?string
    {
        return 'agent_messages has no user_id column, so read state is genuinely workspace-wide: '
             . 'one member opening a conversation clears the badge for every member.';
    }
    public function creditService(): ?string { return 'App\\Core\\Billing\\CreditService'; }
    public function historyCap(): ?int { return 100; }
    public function declaresHistoryCap(): bool { return false; }

    public function resolvedSendRoute(): ?string { return $this->endpoint(); }

    protected function endpoint(): string
    {
        return '/api/agents/' . $this->agentSlug . '/messages';
    }

    public function send(ChatTestContext $ctx, string $content, array $opts = []): ?ProbeResponse
    {
        $this->beginProbe($ctx);
        $payload = array_merge(['content' => $content, 'from' => 'User'], $opts);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->postJson($this->endpoint(), $payload);
        $body = $resp->json();

        return new ProbeResponse(
            $resp->getStatusCode(),
            is_array($body) ? $body : [],
            $resp->headers->all(),
        );
    }

    public function history(ChatTestContext $ctx, array $opts = []): ?array
    {
        $this->beginProbe($ctx);
        $resp = $ctx->test->withHeaders($ctx->authHeaders())->getJson($this->endpoint());
        $body = $resp->json();

        return is_array($body) ? $body : null;
    }
}
