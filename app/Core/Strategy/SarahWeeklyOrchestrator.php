<?php

namespace App\Core\Strategy;

use App\Connectors\RuntimeClient;
use App\Core\Agents\AgentMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 FIX 47 — Sarah's weekly retrospective.
 *
 * Monday 09:00 — reads outcomes from the past 7 days across all engines,
 * sends to runtime for synthesis, posts retrospective + revised plan to
 * the user's chat thread.
 *
 * Architecture: same orchestration pattern as SarahDailyOrchestrator:
 *   1. Gather (cheap DB reads — 7-day outcomes)
 *   2. Send to runtime (dedicated endpoint when available; aiRun fallback)
 *   3. Persist pivot proposals
 *   4. Post retrospective to chat
 *
 * Runtime endpoint when shipped: /internal/sarah/synthesize-weekly
 * (same contract as daily — see RUNTIME-ENDPOINT-SPEC.md). Fallback
 * via aiRun works today.
 */
class SarahWeeklyOrchestrator
{
    public function __construct(
        private WorkspaceStateGatherer $gatherer,
        private RuntimeClient $runtime,
    ) {}

    public function runWeekly(int $wsId): array
    {
        Log::info('[SarahWeekly] starting weekly review', ['workspace_id' => $wsId]);

        // 1. Gather: pull current state + 7-day-ago snapshot for deltas
        $current = $this->gatherer->gather($wsId);
        $outcomes = $this->gatherWeeklyOutcomes($wsId);

        $payload = [
            'workspace_id'   => $wsId,
            'period'         => 'last_7_days',
            'captured_at'    => now()->toIso8601String(),
            'current_state'  => $current,
            'week_outcomes'  => $outcomes,
        ];

        // 2. Synthesize
        $synthesis = $this->synthesizeViaRuntime($wsId, $payload);
        if (!$synthesis) {
            Log::warning('[SarahWeekly] synthesis failed', ['workspace_id' => $wsId]);
            return ['posted' => false, 'reason' => 'synthesis_failed'];
        }

        // 3. Persist pivot proposals
        $persistedIds = $this->persistPivotProposals($wsId, $synthesis['proposed_pivots'] ?? []);

        // 4. Post retrospective
        $retro = (string) ($synthesis['retrospective_markdown'] ?? '');
        if ($retro !== '') {
            $this->postToChat($wsId, $retro, [
                'notification_type' => 'sarah_weekly_review',
                'pivot_proposal_ids' => $persistedIds,
                'action_link'        => '/app/strategy',
            ]);
        }

        Log::info('[SarahWeekly] completed', [
            'workspace_id' => $wsId,
            'pivots_proposed' => count($persistedIds),
        ]);
        return ['posted' => true, 'pivots' => count($persistedIds)];
    }

    /**
     * Gather 7-day outcome signals — what changed, what was published,
     * what completed/failed. Pure DB reads.
     */
    private function gatherWeeklyOutcomes(int $wsId): array
    {
        $weekAgo = now()->subDays(7);

        $articlesPublished = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('published_at', '>=', $weekAgo)
            ->get(['id', 'title', 'published_at', 'focus_keyword', 'wp_post_id'])
            ->map(fn ($a) => [
                'id'             => $a->id,
                'title'          => $a->title,
                'published_at'   => $a->published_at,
                'focus_keyword'  => $a->focus_keyword,
                'has_wp_post'    => !empty($a->wp_post_id),
            ])->toArray();

        $tasksLast7d = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', $weekAgo)
            ->select(DB::raw('status, COUNT(*) AS n, SUM(credit_cost) AS total_credits'))
            ->groupBy('status')
            ->get()
            ->toArray();

        $proposalsLast7d = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', $weekAgo)
            ->select(DB::raw('status, COUNT(*) AS n'))
            ->groupBy('status')
            ->get()
            ->toArray();

        return [
            'articles_published'    => $articlesPublished,
            'tasks_summary'         => $tasksLast7d,
            'proposals_summary'     => $proposalsLast7d,
            'articles_published_n'  => count($articlesPublished),
        ];
    }

    private function synthesizeViaRuntime(int $wsId, array $payload): ?array
    {
        // Dedicated endpoint when Railway ships it
        $dedicated = $this->tryDedicatedEndpoint($wsId, $payload, 'synthesize-weekly');
        if ($dedicated !== null) return $dedicated;
        // Fallback via aiRun
        return $this->fallbackViaAiRun($wsId, $payload);
    }

    private function tryDedicatedEndpoint(int $wsId, array $payload, string $endpoint): ?array
    {
        try {
            if (!$this->runtime->isConfigured()) return null;
            $cfg = config('services.runtime') ?? [];
            $baseUrl = rtrim((string) ($cfg['url'] ?? env('RUNTIME_URL') ?? ''), '/');
            $secret  = (string) ($cfg['secret'] ?? env('RUNTIME_SECRET') ?? '');
            if (!$baseUrl || !$secret) return null;
            $resp = Http::timeout(90)
                ->withHeaders(['X-LevelUp-Secret' => $secret])
                ->post($baseUrl . '/internal/sarah/' . $endpoint, $payload);
            if (!$resp->successful()) return null;
            $body = $resp->json();
            if (!is_array($body) || empty($body['retrospective_markdown'])) return null;
            return [
                'source'                  => 'dedicated_endpoint',
                'retrospective_markdown'  => (string) $body['retrospective_markdown'],
                'proposed_pivots'         => $body['proposed_pivots'] ?? [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fallbackViaAiRun(int $wsId, array $payload): ?array
    {
        $prompt = $this->buildWeeklyPrompt($payload);
        try {
            $r = $this->runtime->aiRun('seo_content_generation', $prompt, [
                'workspace_id' => $wsId,
                'task'         => 'sarah_weekly_review',
            ], 3000);
            if (empty($r['success']) || empty($r['text'])) {
                Log::warning('[SarahWeekly] aiRun empty', ['workspace_id' => $wsId]);
                return null;
            }
            $parsed = $this->parseWeeklyResponse((string) $r['text']);
            if (!$parsed) {
                Log::warning('[SarahWeekly] parse failed', [
                    'workspace_id' => $wsId,
                    'preview'      => mb_substr((string) $r['text'], 0, 300),
                ]);
                return null;
            }
            $parsed['source'] = 'aiRun_fallback';
            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('[SarahWeekly] aiRun exception: ' . $e->getMessage());
            return null;
        }
    }

    private function buildWeeklyPrompt(array $payload): string
    {
        $stateJson = json_encode(array_filter($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "You are Sarah, the workspace's DMM. It is Monday morning. "
            . "Below is the last 7 days of outcomes + current state across all engines.\n\n"
            . "Compose a WEEKLY RETROSPECTIVE markdown for the user that:\n"
            . "  - Names 2-4 specific WINS from the week with the real metric (rank +N, article shipped, audit score change, etc.)\n"
            . "  - Names 1-3 specific LOSSES or things that didn't work, with what the data shows\n"
            . "  - Proposes 2-4 PIVOTS for the coming week: amplification of wins, course-correction on losses\n\n"
            . "Then list each pivot as a structured proposed_pivots[] item with action, title, reason, credit_cost.\n\n"
            . "Rules:\n"
            . "  - Reference REAL signals from the JSON only. Never invent numbers.\n"
            . "  - Never name competitor brands.\n"
            . "  - Current year is " . date('Y') . " — never reference prior years.\n"
            . "  - Quote real credit costs (write_article=3cr, social_create_post=1cr, etc.)\n\n"
            . "Return ONLY this JSON (no preamble, no fences):\n"
            . "{\"retrospective_markdown\":\"...\",\"proposed_pivots\":[{\"action\":\"write_article\",\"title\":\"...\",\"reason\":\"...\",\"credit_cost\":3}]}\n\n"
            . "WORKSPACE WEEKLY DATA:\n" . $stateJson;
    }

    private function parseWeeklyResponse(string $text): ?array
    {
        $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
        $text = trim($text);
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            if (preg_match('/\{.*\}/s', $text, $m)) $parsed = json_decode($m[0], true);
        }
        if (!is_array($parsed) || empty($parsed['retrospective_markdown'])) return null;
        return [
            'retrospective_markdown' => (string) $parsed['retrospective_markdown'],
            'proposed_pivots'        => is_array($parsed['proposed_pivots'] ?? null) ? $parsed['proposed_pivots'] : [],
        ];
    }

    private function persistPivotProposals(int $wsId, array $pivots): array
    {
        $ids = [];
        foreach ($pivots as $p) {
            try {
                $id = DB::table('strategy_proposals')->insertGetId([
                    'workspace_id'        => $wsId,
                    'type'                => 'weekly_pivot_' . ($p['action'] ?? 'unknown'),
                    'title'               => mb_substr((string) ($p['title'] ?? 'Pivot'), 0, 255),
                    'description'         => mb_substr((string) ($p['reason'] ?? ''), 0, 65535),
                    'status'              => 'pending_approval',
                    'cost_breakdown_json' => json_encode([
                        ['agent' => 'sarah', 'action' => $p['action'] ?? 'unknown', 'credits' => (int) ($p['credit_cost'] ?? 0)],
                    ]),
                    'total_credits'       => (int) ($p['credit_cost'] ?? 0),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
                $ids[] = $id;
            } catch (\Throwable $e) {
                Log::warning('[SarahWeekly] pivot persist failed: ' . $e->getMessage());
            }
        }
        return $ids;
    }

    private function postToChat(int $wsId, string $message, array $meta = []): void
    {
        // 2026-06-22 — budget is in CREDITS, never dollars ($900 → 900cr).
        $message = preg_replace('/\$(\d[\d,]*)/', '${1}cr', $message);
        try {
            app(AgentMessageService::class)->postAsAgent($wsId, 'sarah', $message, $meta);
        } catch (\Throwable $e) {
            Log::warning('[SarahWeekly] chat post failed: ' . $e->getMessage());
        }
    }
}
