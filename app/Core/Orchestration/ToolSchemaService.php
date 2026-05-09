<?php

namespace App\Core\Orchestration;

use App\Core\Agent\AgentCapabilityService;
use App\Core\Billing\CreditService;
use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ToolSchemaService — closed function-calling schema for Sarah and agents.
 *
 * Eliminates LLM tool hallucination by advertising a finite, named list of
 * tools (with parameters and descriptions) in the system prompt. Each tool
 * ID maps to either:
 *   - a `platform.*` info tool — answered directly from the DB / services
 *     (no engine pipeline, no approval gate, no credit cost)
 *   - an engine action — routed through EngineExecutionService::execute()
 *
 * Action keys mirror CapabilityMapService keys exactly; an unknown tool ID
 * returns INVALID_TOOL rather than triggering an engine round-trip.
 *
 * Patched 2026-05-10 (Phase 2 — tool schema enforcement).
 */
class ToolSchemaService
{
    /**
     * Tool definitions. Engine actions reuse exact CapabilityMapService keys.
     * Platform info tools are handled directly in executeToolCall().
     */
    private const TOOL_DEFINITIONS = [
        // ─── PLATFORM INFO (direct, no engine pipeline) ──────────────
        'platform.get_website_count' => [
            'description' => 'Return the total number of websites in the workspace.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_website_count',
            'approval'    => 'auto',
        ],
        'platform.get_published_websites' => [
            'description' => 'Return the list of published websites with names + subdomains.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_published_websites',
            'approval'    => 'auto',
        ],
        'platform.get_credit_balance' => [
            'description' => 'Return the current credit balance for the workspace.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_credit_balance',
            'approval'    => 'auto',
        ],
        'platform.get_task_status' => [
            'description' => 'Return a histogram of task statuses (pending / running / completed / failed).',
            'parameters'  => ['limit' => 'int? (recent N tasks, default 50)'],
            'engine'      => 'platform',
            'action'      => 'get_task_status',
            'approval'    => 'auto',
        ],

        // ─── SEO ─────────────────────────────────────────────────────
        'seo.serp_analysis' => [
            'description' => 'Run SERP analysis for a keyword (rankings, competition, volume).',
            'parameters'  => ['keyword' => 'string', 'location' => 'string?'],
            'engine'      => 'seo', 'action' => 'serp_analysis', 'approval' => 'auto',
        ],
        'seo.deep_audit' => [
            'description' => 'Run a full technical SEO audit on a workspace URL.',
            'parameters'  => ['url' => 'string'],
            'engine'      => 'seo', 'action' => 'deep_audit', 'approval' => 'auto',
        ],
        'seo.ai_report' => [
            'description' => 'Generate an AI-written SEO report for the workspace.',
            'parameters'  => ['url' => 'string?'],
            'engine'      => 'seo', 'action' => 'ai_report', 'approval' => 'auto',
        ],
        'seo.link_suggestions' => [
            'description' => 'Suggest internal links for an article or page.',
            'parameters'  => ['article_id' => 'int?'],
            'engine'      => 'seo', 'action' => 'link_suggestions', 'approval' => 'auto',
        ],

        // ─── WRITE / CONTENT ─────────────────────────────────────────
        'write.write_article' => [
            'description' => 'Write a full blog article on a topic.',
            'parameters'  => ['topic' => 'string', 'keywords' => 'array?', 'word_count' => 'int?'],
            'engine'      => 'write', 'action' => 'write_article', 'approval' => 'review',
        ],
        'write.improve_draft' => [
            'description' => 'Improve an existing article draft.',
            'parameters'  => ['article_id' => 'int', 'instructions' => 'string?'],
            'engine'      => 'write', 'action' => 'improve_draft', 'approval' => 'review',
        ],
        'write.generate_headlines' => [
            'description' => 'Generate headline options for a topic.',
            'parameters'  => ['topic' => 'string', 'count' => 'int?'],
            'engine'      => 'write', 'action' => 'generate_headlines', 'approval' => 'auto',
        ],
        'write.generate_outline' => [
            'description' => 'Generate an article outline for a topic.',
            'parameters'  => ['topic' => 'string'],
            'engine'      => 'write', 'action' => 'generate_outline', 'approval' => 'auto',
        ],

        // ─── SOCIAL ──────────────────────────────────────────────────
        'social.create_post' => [
            'description' => 'Create a social media post draft.',
            'parameters'  => ['platform' => 'string (linkedin|instagram|tiktok|x)', 'content' => 'string'],
            'engine'      => 'social', 'action' => 'social_create_post', 'approval' => 'review',
        ],
        'social.list_posts' => [
            'description' => 'List recent social posts.',
            'parameters'  => ['limit' => 'int?', 'platform' => 'string?'],
            'engine'      => 'social', 'action' => 'list_posts', 'approval' => 'auto',
        ],

        // ─── CRM ─────────────────────────────────────────────────────
        'crm.create_lead' => [
            'description' => 'Create a new lead in the CRM.',
            'parameters'  => ['first_name' => 'string', 'last_name' => 'string?', 'email' => 'string?', 'source' => 'string?'],
            'engine'      => 'crm', 'action' => 'create_lead', 'approval' => 'review',
        ],
        'crm.list_leads' => [
            'description' => 'List leads from the CRM.',
            'parameters'  => ['limit' => 'int?', 'status' => 'string?'],
            'engine'      => 'crm', 'action' => 'list_leads', 'approval' => 'auto',
        ],
        'crm.update_lead' => [
            'description' => 'Update a lead.',
            'parameters'  => ['lead_id' => 'int', 'status' => 'string?', 'notes' => 'string?'],
            'engine'      => 'crm', 'action' => 'update_lead', 'approval' => 'auto',
        ],

        // ─── MARKETING ───────────────────────────────────────────────
        'marketing.create_campaign' => [
            'description' => 'Create an email marketing campaign.',
            'parameters'  => ['name' => 'string', 'subject' => 'string', 'audience' => 'string?'],
            'engine'      => 'marketing', 'action' => 'create_campaign', 'approval' => 'review',
        ],
        'marketing.list_campaigns' => [
            'description' => 'List existing email campaigns.',
            'parameters'  => ['limit' => 'int?'],
            'engine'      => 'marketing', 'action' => 'list_campaigns', 'approval' => 'auto',
        ],

        // ─── CREATIVE ────────────────────────────────────────────────
        'creative.generate_image' => [
            'description' => 'Generate an image with the creative engine.',
            'parameters'  => ['prompt' => 'string', 'style' => 'string?'],
            'engine'      => 'creative', 'action' => 'generate_image', 'approval' => 'auto',
        ],
    ];

    /**
     * Tools an agent is allowed to invoke. Platform info tools are always
     * allowed; engine tools are gated by AgentCapabilityService.
     */
    public function getAgentTools(string $agentSlug): array
    {
        $cap = app(AgentCapabilityService::class);
        $out = [];
        foreach (self::TOOL_DEFINITIONS as $toolId => $def) {
            if ($def['engine'] === 'platform' || $cap->canUse($agentSlug, $def['action'])) {
                $out[$toolId] = $def;
            }
        }
        return $out;
    }

    /**
     * Render the schema as a system-prompt block. Sarah / agents see this
     * INSTEAD of the loose prose roster they had before.
     */
    public function getToolSchemaPrompt(string $agentSlug): string
    {
        $tools = $this->getAgentTools($agentSlug);
        $lines = ['AVAILABLE TOOLS — you may ONLY call these exact tool IDs:'];
        foreach ($tools as $toolId => $def) {
            $params = empty($def['parameters'])
                ? '(no parameters)'
                : implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($def['parameters']), $def['parameters']));
            $lines[] = "- {$toolId}: {$def['description']} | params: {$params} | approval: {$def['approval']}";
        }
        $lines[] = '';
        $lines[] = 'To CALL a tool, include this in your JSON output:';
        $lines[] = '"tool_calls": [{"tool": "<exact tool id from list above>", "params": {...}, "reason": "why"}]';
        $lines[] = 'Rules: NEVER invent tool names. ONLY use IDs from the list above. If no tool fits, leave tool_calls as [] and answer in prose.';
        return implode("\n", $lines);
    }

    /**
     * Execute a single tool call. Platform info → direct DB read.
     * Engine tools → EngineExecutionService::execute() with correct argument order.
     */
    public function executeToolCall(string $toolId, array $params, int $wsId, string $agentSlug): array
    {
        if (!isset(self::TOOL_DEFINITIONS[$toolId])) {
            return [
                'success' => false,
                'error'   => "Unknown tool id: {$toolId}",
                'code'    => 'INVALID_TOOL',
            ];
        }

        $def = self::TOOL_DEFINITIONS[$toolId];

        if ($def['engine'] === 'platform') {
            return $this->executePlatformTool($toolId, $params, $wsId);
        }

        // Authorization check before routing to engine.
        if (!app(AgentCapabilityService::class)->canUse($agentSlug, $def['action'])) {
            return [
                'success' => false,
                'error'   => "{$agentSlug} is not authorised to call {$toolId}",
                'code'    => 'AGENT_NOT_AUTHORIZED',
            ];
        }

        $exec = app(EngineExecutionService::class);
        return $exec->execute($wsId, $def['engine'], $def['action'], $params, [
            'agent_id' => $agentSlug,
            'source'   => 'agent',
        ]);
    }

    private function executePlatformTool(string $toolId, array $params, int $wsId): array
    {
        try {
            switch ($toolId) {
                case 'platform.get_website_count':
                    $count = DB::table('websites')->where('workspace_id', $wsId)->count();
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "{$count} websites total in this workspace.",
                        'data'    => ['count' => $count],
                    ];

                case 'platform.get_published_websites':
                    $rows = DB::table('websites')
                        ->where('workspace_id', $wsId)
                        ->where('status', 'published')
                        ->select('name', 'subdomain', 'custom_domain', 'published_at')
                        ->orderByDesc('published_at')
                        ->limit(50)
                        ->get();
                    if ($rows->isEmpty()) {
                        return ['success' => true, 'tool' => $toolId, 'result' => 'No websites are currently published.', 'data' => ['count' => 0]];
                    }
                    $list = $rows->map(fn($r) => trim($r->name ?? 'untitled') . ($r->subdomain ? " ({$r->subdomain})" : ''))->implode(', ');
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "{$rows->count()} published: {$list}.",
                        'data'    => ['count' => $rows->count(), 'items' => $rows],
                    ];

                case 'platform.get_credit_balance':
                    $balance = app(CreditService::class)->getBalance($wsId);
                    $value = is_array($balance)
                        ? ($balance['balance'] ?? $balance['available'] ?? $balance['total'] ?? 0)
                        : $balance;
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "Credit balance: {$value}.",
                        'data'    => is_array($balance) ? $balance : ['balance' => $value],
                    ];

                case 'platform.get_task_status':
                    $limit = (int)($params['limit'] ?? 50);
                    $rows = DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->selectRaw('status, COUNT(*) as n')
                        ->groupBy('status')
                        ->pluck('n', 'status')
                        ->all();
                    $parts = [];
                    foreach (['pending', 'queued', 'running', 'completed', 'failed'] as $s) {
                        $parts[] = "{$s}=" . ($rows[$s] ?? 0);
                    }
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => 'Tasks: ' . implode(', ', $parts) . '.',
                        'data'    => $rows,
                    ];
            }
        } catch (\Throwable $e) {
            Log::warning("ToolSchemaService::executePlatformTool {$toolId} failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Tool execution failed: ' . $e->getMessage(), 'code' => 'TOOL_EXEC_ERROR'];
        }

        return ['success' => false, 'error' => "Platform tool not implemented: {$toolId}", 'code' => 'NOT_IMPLEMENTED'];
    }

    /**
     * For diagnostics / UI surfaces.
     */
    public function getAllToolIds(): array
    {
        return array_keys(self::TOOL_DEFINITIONS);
    }
}
