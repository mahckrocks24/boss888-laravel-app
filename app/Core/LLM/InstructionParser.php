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

        return [
            'engine' => $parsed['engine'] ?? 'crm',
            'action' => $parsed['action'] ?? 'create_lead',
            'params' => $parsed['params'] ?? ['instruction' => $instruction],
            'requires_agent' => $parsed['requires_agent'] ?? true,
            'agent_id' => $parsed['agent_id'] ?? 'sarah',
            'priority' => $parsed['priority'] ?? 'normal',
            'confidence' => $parsed['confidence'] ?? 50,
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
        $engine = 'crm';
        $action = 'create_lead';
        $agent = 'sarah';

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
        } elseif (preg_match('/\b(campaign|email|newsletter|marketing|automation)\b/', $lower)) {
            $engine = 'marketing'; $agent = 'elena';
            $action = str_contains($lower, 'automat') ? 'create_automation' : 'create_campaign';
        } elseif (preg_match('/\b(social|post|instagram|facebook|twitter|linkedin|tiktok)\b/', $lower)) {
            $engine = 'social'; $agent = 'marcus';
            $action = 'social_create_post';
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
            'requires_agent' => true,
            'agent_id' => $agent,
            'priority' => 'normal',
            'confidence' => 60,
            'clarification_needed' => null,
            'source' => 'keyword_fallback',
        ];
    }
}
