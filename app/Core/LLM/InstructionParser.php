<?php

namespace App\Core\LLM;

use App\Connectors\DeepSeekConnector;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

class InstructionParser
{
    public function __construct(
        private DeepSeekConnector $llm,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    /**
     * Parse a natural language instruction into a structured task.
     * Returns: [engine, action, params, agent_id, priority, confidence, clarification_needed]
     *
     * REFACTORED 2026-04-12 (Phase 1.0.1 / doc 14): originally routed through
     * aiRun('competitor_analysis', ...) with the system prompt folded into the
     * user prompt as a workaround.
     *
     * MIGRATED 2026-04-13 (Phase 0.17b): runtime now exposes a generic
     * `chat_json` task type with proper system prompt support + server-side
     * JSON parsing. Cleaner end-to-end: caller passes the instruction-parser
     * system prompt directly, runtime parses the JSON, we just read $result['parsed'].
     */
    public function parse(string $instruction, int $workspaceId): array
    {
        // If runtime is not configured, fall back to keyword matching
        if (! $this->runtime->isConfigured()) {
            return $this->keywordFallback($instruction);
        }

        $workspace = Workspace::find($workspaceId);
        $context = $workspace ? PromptTemplates::workspaceContext($workspace->toArray()) : '';

        $systemPrompt = PromptTemplates::instructionParser()
                      . "\n\nOutput ONLY a valid JSON object — no markdown, no commentary.";

        $userPrompt = "WORKSPACE CONTEXT:\n{$context}\n\n"
                    . "USER INSTRUCTION:\n{$instruction}";

        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
            'task'         => 'instruction_parsing',
            'workspace_id' => (string) $workspaceId,
        ], 500);

        if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
            Log::warning('InstructionParser: runtime failed, falling back to keyword match', [
                'error'       => $result['error'] ?? null,
                'parse_error' => $result['parse_error'] ?? null,
            ]);
            return $this->keywordFallback($instruction);
        }

        $parsed = $result['parsed'];

        // v1.4.4 — when the LLM can't pin an intent, do NOT default to
        // create_lead. A chat with no recognised action must NOT spawn a
        // task. Otherwise greetings like "Hi Sarah" silently become CRM
        // leads named "Sarah" (real bug observed in workspace 2,
        // 2026-05-29). Callers must treat action===null as "just chat,
        // no task" (AgentDispatchService::dispatch already guards on
        // !empty($parsed['action']) but we strengthen the contract here).
        $resolvedAction = $parsed['action'] ?? null;
        $resolvedEngine = $parsed['engine'] ?? null;
        return [
            'engine' => $resolvedEngine,
            'action' => $resolvedAction,
            'params' => $parsed['params'] ?? ['instruction' => $instruction],
            'requires_agent' => $parsed['requires_agent'] ?? false,
            'agent_id' => $parsed['agent_id'] ?? null,
            'priority' => $parsed['priority'] ?? 'normal',
            // If no action was parsed, force confidence to 0 so any
            // downstream "confidence >= 40" check fails closed.
            'confidence' => $resolvedAction ? ($parsed['confidence'] ?? 50) : 0,
            'clarification_needed' => $parsed['clarification_needed'] ?? null,
            'source' => 'runtime',
        ];
    }

    /**
     * Keyword-based fallback when LLM is unavailable.
     */
    private function keywordFallback(string $instruction): array
    {
        $lower = strtolower($instruction);
        // v1.4.4 — start with NO action. The keyword cascade below sets
        // engine/action/agent ONLY when a clear keyword matches. If
        // nothing matches we return action=null and confidence=0 so the
        // dispatcher treats the message as a plain chat (no task spawned).
        // Previous default ($action = 'create_lead') turned every
        // unrecognised greeting into a CRM lead.
        $engine = null;
        $action = null;
        $agent = null;

        // Engine detection
        if (preg_match('/\b(seo|keyword|serp|audit|ranking|backlink)\b/', $lower)) {
            $engine = 'seo'; $agent = 'james';
            $action = str_contains($lower, 'audit') ? 'deep_audit' : 'serp_analysis';
        } elseif (preg_match('/\b(write|article|blog|content|copy|draft|headline)\b/', $lower)) {
            $engine = 'write'; $agent = 'priya';
            $action = str_contains($lower, 'improve') ? 'improve_draft' : 'write_article';
        } elseif (preg_match('/\b(image|video|creative|design|generate|photo)\b/', $lower)) {
            $engine = 'creative'; $agent = 'sarah';
            $action = str_contains($lower, 'video') ? 'generate_video' : 'generate_image';
        } elseif (preg_match('/\b(website|page|builder|landing|site)\b/', $lower)) {
            $engine = 'builder'; $agent = 'sarah';
            $action = str_contains($lower, 'page') ? 'generate_page' : 'create_website';
        // LAUNCH SCOPE 2026-07-20 — email-marketing and standalone-social NL
        // branches REMOVED. Instructions like "create a campaign", "send a
        // newsletter", "make a facebook post" no longer resolve to an executable
        // engine/action here; they fall through unmatched → action=null →
        // confidence 0 → the dispatcher's >=40 gate fails closed and NO task is
        // queued. This is intentional: these capabilities are not in the launch
        // product (see LaunchScopePolicy). The kernel would refuse them anyway;
        // failing to match here means we never even propose a removed action.
        } elseif (preg_match('/\b(lead|crm|contact|deal|pipeline|follow.?up)\b/', $lower)) {
            $engine = 'crm'; $agent = 'elena';
            if (str_contains($lower, 'deal')) $action = 'create_deal';
            elseif (str_contains($lower, 'contact')) $action = 'create_contact';
            else $action = 'create_lead';
        } elseif (preg_match('/\b(interior|room|before.?after|transform)\b/', $lower)) {
            $engine = 'beforeafter'; $agent = 'sarah';
            $action = 'ba_transform';
        }

        return [
            'engine' => $engine,
            'action' => $action,
            'params' => ['instruction' => $instruction],
            // requires_agent only when we matched something concrete.
            'requires_agent' => $action !== null,
            'agent_id' => $agent,
            'priority' => 'normal',
            // No match → confidence 0 so the dispatcher's >= 40 gate
            // fails closed and no task is queued.
            'confidence' => $action !== null ? 60 : 0,
            'clarification_needed' => null,
            'source' => 'keyword_fallback',
        ];
    }
}
