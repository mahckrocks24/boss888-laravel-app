<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 Phase 1M — request-scoped correlation context.
 *
 * Traces to S1J-N01, found while proving the Phase 1J self-report guard on the
 * live path. Every agent_messages row has carried an execution_id since Phase
 * 1A, and no task ever has: 565 of 565 tasks created on Chef Red in 24 hours
 * carried created_via and not one carried execution_id.
 *
 * Two things were quietly broken by that. ActionLedger::forConversation could
 * never match a task to the conversation that caused it, so it reported zero
 * however much work had been done — which made the self-report guard read every
 * denial as truthful and stay silent. And ActionLedger's `this_turn` flag,
 * written in Phase 1D, has never once been true.
 *
 * WHY A CONTEXT RATHER THAN A PARAMETER
 * created_via is set at roughly eighteen separate call sites, each building its
 * own payload array. Threading a correlation id through all of them would mean
 * every future caller has to remember it, and the ones that forget are exactly
 * the ones whose work then looks like it came from nowhere. TaskService::create
 * is the single funnel they all pass through — the same reason the Phase 1E
 * cost gate lives there — so the envelope travels with the request and is
 * stamped once, centrally.
 *
 * This deliberately mirrors SpendContext, including the binding trick: an
 * unbound concrete class resolves to a NEW instance on every app() call, so
 * without app()->instance() the envelope set by the route would be invisible to
 * TaskService. That precise mistake cost slice 1E.2 a debugging cycle.
 *
 * Unset is safe: a queue worker or scheduled job simply stamps nothing, which
 * is the current behaviour and remains correct — that work genuinely has no
 * conversation behind it.
 */
class CorrelationContext
{
    private ?string $executionId = null;
    private ?string $conversationId = null;
    private ?int $userMessageId = null;
    private ?int $workspaceId = null;

    public function set(array $corr, ?int $workspaceId = null): void
    {
        $this->executionId    = isset($corr['execution_id']) ? (string) $corr['execution_id'] : null;
        $this->conversationId = isset($corr['conversation_id']) ? (string) $corr['conversation_id'] : null;
        $this->userMessageId  = isset($corr['user_message_id']) ? (int) $corr['user_message_id'] : null;
        $this->workspaceId    = $workspaceId;

        try { app()->instance(self::class, $this); } catch (\Throwable $e) { /* non-fatal */ }
    }

    public function isSet(): bool
    {
        return $this->executionId !== null;
    }

    public function executionId(): ?string
    {
        return $this->executionId;
    }

    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    public function workspaceId(): ?int
    {
        return $this->workspaceId;
    }

    /**
     * The fields to merge into a task payload.
     *
     * Returns an empty array when nothing is set, so a caller can merge
     * unconditionally without inventing null correlation keys that would then
     * have to be filtered out again downstream.
     */
    public function payloadStamp(): array
    {
        if (!$this->isSet()) return [];

        $stamp = ['execution_id' => $this->executionId];
        if ($this->conversationId !== null) $stamp['conversation_id'] = $this->conversationId;
        if ($this->userMessageId !== null)  $stamp['source_message_id'] = $this->userMessageId;
        return $stamp;
    }

    /** Used by tests and by long-lived workers that must not inherit a stale envelope. */
    public function clear(): void
    {
        $this->executionId = null;
        $this->conversationId = null;
        $this->userMessageId = null;
        $this->workspaceId = null;
    }
}
