<?php

namespace App\Core\Strategy;

use App\Connectors\RuntimeClient;
use App\Core\Agents\AgentMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 FIX 46 — Sarah's daily orchestrator.
 *
 * Architectural rule: This file does NO synthesis, NO classification,
 * NO LLM reasoning. It is pure orchestration:
 *
 *   1) Gather (via WorkspaceStateGatherer — cheap DB reads)
 *   2) Send raw state to runtime for synthesis
 *   3) Receive structured brief + proposed_actions back
 *   4) Persist proposed_actions to strategy_proposals
 *   5) Post brief to Sarah's chat thread
 *
 * The runtime owns the intelligence per CLAUDE.md "hands-vs-brain" rule
 * and the `feedback_intelligence_belongs_in_runtime` memory.
 *
 * Phase 1 (THIS RELEASE): runtime endpoint /internal/sarah/synthesize-daily
 * may not exist yet — we fall back to runtime->aiRun() with a structured
 * prompt + state payload as context. Same input/output contract; flipping
 * to the dedicated endpoint later is a one-line change.
 */
class SarahDailyOrchestrator
{
    public function __construct(
        private WorkspaceStateGatherer $gatherer,
        private CadenceGuardService $cadence,
        private RuntimeClient $runtime,
        private ProactiveRuleSet $rules,
    ) {}

    /**
     * Full daily cycle for one workspace. Designed to be called by
     * `sarah:morning-brief` artisan command (per-workspace iteration).
     */
    public function runDaily(int $wsId): array
    {
        Log::info('[SarahDaily] starting daily cycle', ['workspace_id' => $wsId]);

        // 1. GATHER
        $state = $this->gatherer->gather($wsId);
        $this->gatherer->cache($wsId, $state);

        // 1b. 2026-05-24 FIX 48 — RULE-SEED. Emit candidate proposals
        // from deterministic rules (orphan detection, opportunity-zone
        // keywords, stale articles, etc.). The LLM in runtime selects
        // + narrates which 3-8 of these candidates make it into today's
        // brief. Without this, the LLM had only raw state and had to
        // invent proposal types from scratch — often missing obvious
        // wins like "fix the 47 orphan pages" or "target the keyword
        // at #14 — opportunity zone".
        $candidates = $this->rules->emit($wsId, $state);
        $state['_rule_candidates'] = $candidates;

        // 1c. Phase 2 (2026-06-30) — RECALL Sarah's episodic workspace memory so
        // she sees what she proposed / learned on prior runs and stops repeating
        // herself. Flows into BOTH synthesis paths via $state (fallback prompt
        // uses it immediately; the dedicated runtime endpoint receives it in the
        // state payload). Best-effort — recall failure never blocks the brief.
        $sarahId = null;
        try {
            $sarahId = \App\Models\Agent::where('slug', 'sarah')->value('id');
            if ($sarahId) {
                $mem = app(\App\Core\Intelligence\AgentExperienceService::class)
                    ->buildMemoryContext($wsId, (int) $sarahId);
                if (is_string($mem) && $mem !== '') {
                    $state['recent_memory'] = $mem;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SarahDaily] memory recall failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        // 2. SYNTHESIZE (call runtime)
        $synthesis = $this->synthesizeViaRuntime($wsId, $state);
        if (!$synthesis) {
            Log::warning('[SarahDaily] synthesis failed, skipping post', ['workspace_id' => $wsId]);
            return ['posted' => false, 'reason' => 'synthesis_failed'];
        }

        // 3. PERSIST proposed actions to strategy_proposals
        $persistedIds = $this->persistProposals($wsId, $synthesis['proposed_actions'] ?? []);

        // 2026-05-24 FIX 55 — compute batch total credits so the FE can
        // render "Approve all (N cr)" without a second round-trip.
        $batchTotalCredits = 0;
        if (!empty($persistedIds)) {
            $batchTotalCredits = (int) DB::table('strategy_proposals')
                ->whereIn('id', $persistedIds)
                ->sum('total_credits');
        }

        // 4. POST brief to chat
        $briefMd = (string) ($synthesis['brief_markdown'] ?? '');
        if ($briefMd !== '') {
            $this->postToChat($wsId, $briefMd, [
                'notification_type'   => 'sarah_daily_brief',
                'proposal_ids'        => $persistedIds,
                'batch_total_credits' => $batchTotalCredits,
                'batch_approve_url'   => '/api/sarah/proposals/batch-approve',
                'batch_decline_url'   => '/api/sarah/proposals/batch-decline',
                'action_link'         => '/app/strategy',
            ]);
        }

        // Phase 2 (2026-06-30) — REMEMBER: persist an episodic memory of this run
        // so the next run can recall it (closes the memory loop). Best-effort.
        try {
            $sarahId = $sarahId ?: \App\Models\Agent::where('slug', 'sarah')->value('id');
            if ($sarahId) {
                $proposedSlugs = collect($synthesis['proposed_actions'] ?? [])
                    ->map(fn ($a) => $a['action'] ?? null)->filter()->implode(', ');
                $summary = 'Daily brief ' . now()->toDateString() . ': proposed ' . count($persistedIds)
                    . ' action(s) [' . ($proposedSlugs !== '' ? $proposedSlugs : 'none') . '].';
                app(\App\Core\Intelligence\AgentExperienceService::class)
                    ->storeMemory($wsId, (int) $sarahId, 'daily_brief', 'daily_brief:' . now()->toDateString(), $summary);
            }
        } catch (\Throwable $e) {
            Log::warning('[SarahDaily] memory store failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        Log::info('[SarahDaily] completed', [
            'workspace_id' => $wsId,
            'proposals_posted' => count($persistedIds),
            'brief_length' => strlen($briefMd),
        ]);

        return [
            'posted' => true,
            'proposals' => count($persistedIds),
            'brief_length' => strlen($briefMd),
        ];
    }

    /**
     * Try the dedicated runtime endpoint first; fall back to aiRun with
     * an embedded prompt if it doesn't exist yet.
     */
    private function synthesizeViaRuntime(int $wsId, array $state): ?array
    {
        // Path A — dedicated endpoint (when Railway ships it)
        $dedicated = $this->tryDedicatedEndpoint($wsId, $state);
        if ($dedicated !== null) return $dedicated;

        // Path B — fallback via aiRun (works today, no runtime PR needed)
        return $this->fallbackViaAiRun($wsId, $state);
    }

    private function tryDedicatedEndpoint(int $wsId, array $state): ?array
    {
        try {
            if (!$this->runtime->isConfigured()) return null;
            $cfg = config('services.runtime') ?? [];
            $baseUrl = rtrim((string) ($cfg['url'] ?? env('RUNTIME_URL') ?? ''), '/');
            $secret  = (string) ($cfg['secret'] ?? env('RUNTIME_SECRET') ?? '');
            if (!$baseUrl || !$secret) return null;

            $resp = Http::timeout(60)
                ->withHeaders(['X-LevelUp-Secret' => $secret])
                ->post($baseUrl . '/internal/sarah/synthesize-daily', [
                    'workspace_id' => $wsId,
                    'state'        => $state,
                ]);

            if (!$resp->successful()) return null;
            $body = $resp->json();
            if (!is_array($body) || empty($body['brief_markdown'])) return null;

            return [
                'source'           => 'dedicated_endpoint',
                'brief_markdown'   => (string) $body['brief_markdown'],
                'proposed_actions' => $body['proposed_actions'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::debug('[SarahDaily] dedicated endpoint unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fallback synthesis via runtime->aiRun with a structured prompt.
     * Works with existing runtime infrastructure; no new endpoint needed.
     */
    private function fallbackViaAiRun(int $wsId, array $state): ?array
    {
        $prompt = $this->buildSynthesisPrompt($state);
        try {
            // 2026-05-24 — max_tokens 3000: brief (300-600 tokens) + 5-8
            // structured proposed_actions (~150 tokens each). Lower values
            // truncate the JSON mid-output and the parser fails.
            $r = $this->runtime->aiRun('seo_content_generation', $prompt, [
                'workspace_id' => $wsId,
                'task'         => 'sarah_daily_synthesis',
            ], 3000);
            if (empty($r['success']) || empty($r['text'])) {
                Log::warning('[SarahDaily] aiRun returned empty', [
                    'workspace_id' => $wsId,
                    'success'      => $r['success'] ?? null,
                    'text_len'     => strlen($r['text'] ?? ''),
                ]);
                return null;
            }

            $parsed = $this->parseSynthesisResponse((string) $r['text']);
            if (!$parsed) {
                Log::warning('[SarahDaily] synthesis JSON parse failed', [
                    'workspace_id' => $wsId,
                    'text_preview' => mb_substr((string) $r['text'], 0, 300),
                ]);
                return null;
            }
            $parsed['source'] = 'aiRun_fallback';
            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('[SarahDaily] aiRun fallback failed: ' . $e->getMessage());
            return null;
        }
    }

    private function buildSynthesisPrompt(array $state): string
    {
        // Compact the state for the LLM — strip empty sections to save tokens
        $compact = array_filter($state, fn ($v) => !empty($v) && $v !== [] && $v !== null);
        $stateJson = json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "You are Sarah, the Digital Marketing Manager (DMM) for this workspace. "
            . "Below is the FULL state across every engine (SEO, content, social, email, CRM, "
            . "chatbot, AEO, traffic, builder, competitor, pipeline, tier, goals).\n\n"
            . "v1.4.4 — DMM THINKING FRAME (apply every time you draft this brief):\n"
            . "  - PERSONA. Anchor every recommendation to the target_audience in workspace memory. "
            . "Don't say 'write more articles' — say 'write articles for {persona} in {context}'.\n"
            . "  - FUNNEL. Tag every proposed content/campaign action with TOFU (awareness), "
            . "MOFU (consideration: comparisons, guides, case studies, calculators), or BOFU "
            . "(decision: pricing, demos, reviews). If the workspace already has 20 TOFU and 0 BOFU, "
            . "your proposed actions MUST fix that imbalance, not extend it.\n"
            . "  - COMPETITIVE. Read the competitor.top_recurring_serp_domains and "
            . "competitor.declared_competitors fields. Frame at least one proposal as "
            . "'competitors X/Y are ranking for {keyword} with {angle}; our gap is {gap}'.\n"
            . "  - PIPELINE. Read crm.pipeline_by_status + pipeline_by_source + stale_leads_30d. "
            . "If leads are stuck or stale, surface a CRM follow-up proposal (a task/reminder — "
            . "NOT an email sequence, which is out of product).\n"
            . "  - ATTRIBUTION. Every proposed action must name (a) the KPI it moves "
            . "(organic impressions, MQLs, demo bookings, revenue), (b) which CRM stage or SEO "
            . "metric will be tracked afterward.\n"
            . "  - DELEGATION. You ORCHESTRATE — you never execute. Assign each action to the "
            . "owning specialist (Priya/Nora for write_*, James for SEO, Alex for technical SEO, "
            . "Elena for CRM leads/follow-ups). Only orchestration actions (strategy_meeting, "
            . "goal_pivot, monthly_strategy, weekly_review, onboarding, estimate_cost) are assigned "
            . "to sarah. LAUNCH SCOPE: social-media posting and email marketing are NOT in this "
            . "product — never propose them or name a social/email specialist.\n\n"
            . "Sarah's checklist (_rule_candidates) is pre-computed for you. Each candidate is "
            . "a real data-driven opportunity (orphan pages found, opportunity-zone keyword, "
            . "stale article, etc.). PREFER picking 3-5 of these over inventing new ones — "
            . "they're grounded in real workspace state.\n\n"
            . "Compose a SHORT morning brief (markdown) for the workspace owner, AND a list of "
            . "proposed actions for TODAY that respect the tier cadence.\n\n"
            . "Brief structure (markdown):\n"
            . "  - Greeting + day-of-month + budget status. If credit utilisation is LOW relative to the plan while goals are behind, frame the unused credits as UNDER-deployment to correct TODAY — NEVER present a low burn / 'plenty of room' as a good thing.\n"
            . "  - AWAITING YOUR OK (2026-07-16 FOLLOW-UP): if state.approvals.count > 0, this section comes FIRST, right after the greeting. You are a DMM chasing a decision that is holding up results — direct, not a nag. Read state.approvals: lead with the OLDEST item (approvals.needs_owner_ok is oldest-first), say how many DAYS it has waited, and name the RESULT it is blocking (e.g. '30 finished articles are written and ready to go live — they've been waiting on your OK for 9 days and won't earn traffic until published. Approve to publish.'). ONE consolidated follow-up covering at most the top 3 by age; if more remain, add 'plus N more waiting'. NEVER re-list an item you already surfaced on a prior day as if it were new, and NEVER frame routine auto-run work as awaiting approval. If approvals.count is 0, OMIT this section entirely.\n"
            . "  - YESTERDAY: cross-engine wins (3-5 bullets max, only real signals from data)\n"
            . "  - GOALS: status of each active goal (progress vs target)\n"
            . "  - TODAY — split into TWO groups: (a) \"I'll handle these myself today\" = the routine content/SEO work you AUTO-RUN under the owner's standing approval (write_article, generate_meta, improve_draft, insert_link, link_suggestions, fix_orphans, generate_image, expand thin pages) — state these in the FUTURE tense as work you are taking care of, NOT as approval requests; (b) \"Needs your OK\" = ONLY external/irreversible actions (publishing content live, emailing leads, social posts) — phrase THESE as 'Approve to …'. 3-8 items total, each with credit cost + reason. "
            . "Each maps to one or more Laravel actions assigned to the right specialist (NEVER Sarah herself for execution):\n"
            . "      write_article, generate_outline, generate_meta, improve_draft  → priya (Content)\n"
            . "      deep_audit, link_suggestions, insert_link, generate_links,\n"
            . "      keyword_research, seo_audit, ai_status                         → james (SEO)\n"
            // LAUNCH SCOPE 2026-07-20 — social/email delegation removed.
            . "      generate_image                                                 → studio (direct-prompt)\n"
            . "      strategy_meeting, goal_pivot, onboarding, monthly_strategy,\n"
            . "      weekly_review                                                  → sarah (Orchestration only)\n\n"
            . "Rules:\n"
            . "  - Reference REAL signals from the state JSON only. Never invent metrics.\n"
            . "  - TRUTH IN FRAMING: NEVER claim anything is already DONE / finished / published / live that has "
            . "not actually completed (no fake 'I've published it', no 'it's done'). BUT the routine content/SEO "
            . "work IS auto-run under the owner's standing approval, so it is CORRECT to say in the FUTURE tense "
            . "that you will take care of those today (e.g. 'I'll write and optimise these two articles today'). "
            . "ONLY external/irreversible actions (publishing live, emailing leads, social posts) require approval "
            . "— phrase those as 'Approve to …'. Never present a low credit burn as fine when goals are behind.\n"
            . "  - PRIORITIZE _rule_candidates (those are real, deterministic opportunities). "
            . "Only add a new proposal if it's clearly higher value than any candidate.\n"
            . "  - Respect the tier cadence (don't propose more than fits the tier).\n"
            . "  - Quote real credit cost using the canonical pricing.\n"
            . "  - Never name competitor brands.\n"
            . "  - No prior-year references (current year = " . date('Y') . ").\n"
            . "  - For every proposed action you MUST set the agent field to the owning specialist. "
            . "Sarah is the orchestrator and never executes — she ONLY appears as agent on the "
            . "orchestration-only actions listed above.\n"
            . "  - Phrase the brief markdown in plain conversational English. Do NOT mention raw "
            . "action codes (write_article, insert_link, etc.) in the markdown body or in any "
            . "labelled \"Action:\" line — describe what will happen, not the slug.\n\n"
            . "Return ONLY this JSON (no preamble, no fences):\n"
            . "{\"brief_markdown\":\"...\",\"proposed_actions\":[{\"agent\":\"priya\",\"action\":\"write_article\",\"title\":\"...\",\"reason\":\"...\",\"credit_cost\":3,\"priority\":\"high\",\"rule\":\"opportunity_zone\"}]}\n\n"
            . "WORKSPACE STATE:\n" . $stateJson;
    }

    private function parseSynthesisResponse(string $text): ?array
    {
        // Strip markdown fences
        $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            // Last-resort: find first { ... } block
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) return null;
        if (empty($parsed['brief_markdown'])) return null;

        return [
            'brief_markdown'   => (string) $parsed['brief_markdown'],
            'proposed_actions' => is_array($parsed['proposed_actions'] ?? null) ? $parsed['proposed_actions'] : [],
        ];
    }

    /**
     * Insert each proposed action into strategy_proposals. Returns the
     * inserted IDs so the chat message can link them for approval.
     */
    private function persistProposals(int $wsId, array $actions): array
    {
        $ids = [];
        foreach ($actions as $a) {
            try {
                $id = DB::table('strategy_proposals')->insertGetId([
                    'workspace_id'         => $wsId,
                    'type'                 => 'daily_action_' . ($a['action'] ?? 'unknown'),
                    'title'                => mb_substr((string) ($a['title'] ?? 'Untitled action'), 0, 255),
                    'description'          => mb_substr((string) ($a['reason'] ?? ''), 0, 65535),
                    'status'               => 'pending_approval',
                    'cost_breakdown_json'  => json_encode([
                        [
                            // v1.4.4 — derive the owning specialist from the action
                            // instead of defaulting to 'sarah'. The prompt now
                            // requires the LLM to set $a['agent'], but if it
                            // forgets we still route to the right agent.
                            'agent' => self::deriveAgentForAction((string) ($a['action'] ?? ''), $a['agent'] ?? null),
                            'action' => $a['action'] ?? 'unknown',
                            'credits' => (int) ($a['credit_cost'] ?? 0),
                            'description' => $a['title'] ?? '',
                        ],
                    ]),
                    'total_credits'        => (int) ($a['credit_cost'] ?? 0),
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
                $ids[] = $id;
            } catch (\Throwable $e) {
                Log::warning('[SarahDaily] proposal persist failed: ' . $e->getMessage(), ['action' => $a]);
            }
        }
        return $ids;
    }

    /**
     * v1.4.4 — Map a proposed action to its owning specialist. Falls back to
     * the LLM-supplied $hint only if it names a real specialist; otherwise
     * routes by the engine that owns the action. Sarah is ONLY assigned for
     * pure-orchestration actions (strategy_meeting, goal_pivot, etc.).
     */
    private static function deriveAgentForAction(string $action, $hint = null): string
    {
        $action = strtolower(trim($action));

        // Orchestration-only — Sarah is the legit assignee here.
        $orchestrationActions = [
            'strategy_meeting', 'goal_pivot', 'onboarding',
            'monthly_strategy', 'weekly_review', 'estimate_cost',
        ];
        if (in_array($action, $orchestrationActions, true)) {
            return 'sarah';
        }

        // Execution actions — assign to the owning specialist.
        $owners = [
            // Content / write
            'write_article'         => 'priya',
            'generate_outline'      => 'priya',
            'improve_draft'         => 'priya',
            'generate_meta'         => 'priya',
            'generate_headlines'    => 'priya',
            'aeo_enrich'            => 'priya',
            'create_article'        => 'priya',
            'write_article_image'   => 'priya',
            // SEO
            'deep_audit'            => 'james',
            'seo_audit'             => 'james',
            'ai_status'             => 'james',
            'link_suggestions'      => 'james',
            'insert_link'           => 'james',
            'generate_links'        => 'james',
            'keyword_research'      => 'james',
            // Social
            // CRM / Email
            // Creative
            'generate_image'        => 'priya',
            'generate_image_mini'   => 'priya',
            'generate_image_high'   => 'priya',
        ];

        if (isset($owners[$action])) {
            return $owners[$action];
        }

        // LLM hint — accept only if it names a non-Sarah specialist.
        if (is_string($hint) && $hint !== '' && strtolower($hint) !== 'sarah' && strtolower($hint) !== 'dmm') {
            return strtolower($hint);
        }

        // Last resort: Priya (Content lead) handles unmapped execution actions
        // safer than Sarah (who can't execute) until the mapping is expanded.
        return 'priya';
    }

    private function postToChat(int $wsId, string $message, array $meta = []): void
    {
        // 2026-06-22 — budget is in CREDITS, never dollars. The runtime LLM
        // sometimes renders credit figures with a $ ("$900"); normalise to "cr".
        $message = preg_replace('/\$(\d[\d,]*)/', '${1}cr', $message);
        try {
            $svc = app(AgentMessageService::class);
            $svc->postAsAgent($wsId, 'sarah', $message, $meta);
        } catch (\Throwable $e) {
            Log::warning('[SarahDaily] chat post failed: ' . $e->getMessage());
        }
    }
}
