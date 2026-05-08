<?php

namespace App\Core\LLM;

use App\Connectors\DeepSeekConnector;
use App\Models\Agent;
use App\Models\Workspace;
use App\Models\Task;
use App\Models\MeetingMessage;
use App\Core\Memory\WorkspaceMemoryService;
use Illuminate\Support\Facades\Log;

class AgentReasoningService
{
    public function __construct(
        private DeepSeekConnector $llm,
        private WorkspaceMemoryService $memory,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    /**
     * Generate an agent's response to a user message.
     * Used in conversations and strategy meetings.
     *
     * REFACTORED 2026-04-12 (Phase 1.0.3 / doc 14): originally routed through
     * aiRun('competitor_analysis', ...) with the agent persona + workspace +
     * memory + history + user message folded into one user prompt.
     *
     * MIGRATED 2026-04-13 (Phase 0.17b): now uses chat_json with the agent
     * persona as the system prompt and the user message + history as the user
     * prompt. The LLM is instructed to return {"response":"..."} so the public
     * contract (success/content/agent/...) is preserved exactly while we get
     * the benefits of proper system prompt scoping + server-side JSON parsing.
     */
    public function respond(string $agentSlug, int $workspaceId, string $userMessage, ?int $conversationId = null): array
    {
        $agent = Agent::where('slug', $agentSlug)->first();
        if (! $agent) {
            return ['success' => false, 'error' => "Agent not found: {$agentSlug}"];
        }

        if (! $this->runtime->isConfigured()) {
            return $this->templateFallback($agent, $userMessage);
        }

        // Build context
        $workspace = Workspace::find($workspaceId);
        $wsContext = $workspace ? PromptTemplates::workspaceContext($workspace->toArray()) : '';
        $memoryContext = $this->getMemoryContext($workspaceId);
        $conversationHistory = $conversationId ? $this->getConversationHistory($conversationId) : [];

        // Build the agent persona prompt
        $agentPersona = PromptTemplates::agentReasoning(
            $agent->name,
            $agent->title,
            $agent->capabilities_json ?? []
        );

        // Inject intelligence layers
        $experienceContext = '';
        $globalContext = '';
        try {
            $expSvc = app(\App\Core\Intelligence\AgentExperienceService::class);
            $experienceContext = $expSvc->buildExperienceContext($agent->id, $workspace?->industry);
            $agentMemoryCtx = $expSvc->buildMemoryContext($workspaceId, $agent->id);
            if ($agentMemoryCtx) $memoryContext .= "\n" . $agentMemoryCtx;

            $globalSvc = app(\App\Core\Intelligence\GlobalKnowledgeService::class);
            $globalContext = $globalSvc->buildAgentContext($agent->category ?? 'general', $workspace?->industry, $workspace?->location);
        } catch (\Throwable $_) {}

        // Format recent conversation history (last 10 messages) inline
        $historyText = '';
        foreach (array_slice($conversationHistory, -10) as $msg) {
            $role = $msg['role'] === 'assistant' ? $agent->name : 'User';
            $historyText .= "{$role}: {$msg['content']}\n";
        }

        $systemPrompt = implode("\n\n", array_filter([
            $agentPersona,
            "WORKSPACE:\n{$wsContext}",
            $experienceContext ? "YOUR EXPERIENCE:\n{$experienceContext}" : '',
            $globalContext ? "INDUSTRY KNOWLEDGE:\n{$globalContext}" : '',
            "WORKSPACE MEMORY:\n{$memoryContext}",
            "Respond as {$agent->name} in character. Be concise and helpful.",
            'Output ONLY a valid JSON object of the form {"response":"<your reply text>"}. No markdown, no commentary outside the JSON.',
        ]));

        $userPrompt = ($historyText ? "RECENT CONVERSATION:\n{$historyText}\n\n" : '')
                    . "USER MESSAGE:\n{$userMessage}";

        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
            'agent_slug'  => $agent->slug,
            'agent_name'  => $agent->name,
            'agent_title' => $agent->title,
        ], (int) config('llm.agent.max_tokens', 2000));

        if (! ($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
            return $this->templateFallback($agent, $userMessage);
        }

        return [
            'success' => true,
            'content' => $result['parsed']['response'] ?? $result['text'] ?? '',
            'agent' => $agent->slug,
            'usage' => [],
            'source' => 'runtime',
        ];
    }

    // DELETED 2026-04-12 (Phase 1.0.7 / doc 03):
    // planExecution() was a 300-token generic narrative method with marginal UX value.
    // Verified zero callers in the codebase before deletion. C5 LLM bypass site removed.

    // ── Private ──────────────────────────────────────────

    private function getMemoryContext(int $workspaceId): string
    {
        try {
            // PATCH (Intel Fix 1) — was ->getAll() (non-existent method) which
            // threw silently and made every agent prompt receive "Memory
            // unavailable.". WorkspaceMemoryService::all() returns a Collection
            // of WorkspaceMemory rows.
            $memories = $this->memory->all($workspaceId);
            if ($memories->isEmpty()) return 'No workspace memory stored yet.';

            $lines = [];
            foreach ($memories->take(10) as $row) {
                $key = $row->key ?? 'unknown';
                $val = $row->value_json ?? null;
                $lines[] = "- {$key}: " . (is_string($val) ? $val : json_encode($val));
            }
            return implode("\n", $lines);
        } catch (\Throwable) {
            return 'Memory unavailable.';
        }
    }

    private function getConversationHistory(int $conversationId): array
    {
        $messages = MeetingMessage::where('meeting_id', $conversationId)
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        return $messages->map(function ($msg) {
            return [
                'role' => $msg->sender_type === 'user' ? 'user' : 'assistant',
                'content' => $msg->message,
            ];
        })->toArray();
    }

    private function templateFallback(Agent $agent, string $message): array
    {
        // FIX 2026-04-12 (Phase 1.0.9 / doc 13): was a hardcoded 6-agent map
        // (sarah/james/priya/marcus/elena/alex). The agents table has 21 active
        // agents — 15 of them fell through to a generic "I'll work on this" string.
        // Now builds the fallback dynamically from the agent's DB row, which works
        // for all 21 agents (and any future additions) without hardcoded maps.
        $title = $agent->title ?? 'specialist';
        $desc  = $agent->description ?? '';
        $intro = "I'm {$agent->name}, your {$title}.";
        if ($desc !== '') {
            $intro .= ' ' . $desc;
        }

        return [
            'success' => true,
            'content' => $intro . "\n\nRegarding your request: \"{$message}\" — I've created a task for this. You'll see progress updates as I work on it.",
            'agent' => $agent->slug,
            'usage' => [],
            'source' => 'template_fallback',
        ];
    }
}
