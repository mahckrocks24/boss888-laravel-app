<?php

namespace App\Core\Strategy;

use App\Connectors\RuntimeClient;
use App\Core\Agents\AgentMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 FIX 47 — Sarah's monthly strategy refresh.
 *
 * 1st of month at 10:00 — runs the full multi-agent strategy meeting
 * (Sarah + James + Alex + Priya + Nora + Elena) to produce a
 * refreshed 30-day plan based on the past month's outcomes.
 * LAUNCH SCOPE 2026-07-20 — removed social/email specialists (Marcus, Vera)
 * dropped from the attendee roster; retained launch agents only.
 *
 * Uses the existing runtime multi-agent meeting infrastructure
 * (/internal/meeting/* — already in use for ad-hoc meetings). Just
 * triggers it on a calendar cadence with the right participant list
 * and a monthly-strategy prompt.
 *
 * Cost: 8 credits per meeting (canonical from FIX 45 tier framework).
 * Auto-approved as part of monthly Sarah operating budget.
 */
class SarahMonthlyOrchestrator
{
    public function __construct(
        private WorkspaceStateGatherer $gatherer,
        private RuntimeClient $runtime,
    ) {}

    public function runMonthly(int $wsId): array
    {
        Log::info('[SarahMonthly] starting monthly strategy', ['workspace_id' => $wsId]);

        // 1. Gather: current state + 30-day outcomes
        $current = $this->gatherer->gather($wsId);
        $outcomes = $this->gatherMonthlyOutcomes($wsId);

        // 2. Trigger multi-agent meeting via runtime (or fallback to aiRun)
        $meeting = $this->runStrategyMeeting($wsId, $current, $outcomes);
        if (!$meeting) {
            Log::warning('[SarahMonthly] meeting failed', ['workspace_id' => $wsId]);
            return ['posted' => false, 'reason' => 'meeting_failed'];
        }

        // 3. Persist 30-day plan as strategy_proposal
        $planId = $this->persistMonthlyPlan($wsId, $meeting);

        // 4. Post executive summary to chat
        $summary = (string) ($meeting['executive_summary'] ?? '');
        if ($summary !== '') {
            $this->postToChat($wsId, $summary, [
                'notification_type' => 'sarah_monthly_strategy',
                'plan_proposal_id'  => $planId,
                'action_link'       => '/app/strategy/' . $planId,
            ]);
        }

        Log::info('[SarahMonthly] completed', [
            'workspace_id' => $wsId,
            'plan_id'      => $planId,
            'summary_length' => strlen($summary),
        ]);
        return ['posted' => true, 'plan_id' => $planId];
    }

    private function gatherMonthlyOutcomes(int $wsId): array
    {
        $monthAgo = now()->subDays(30);
        return [
            'articles_published_30d' => (int) DB::table('articles')
                ->where('workspace_id', $wsId)->where('published_at', '>=', $monthAgo)->count(),
            'tasks_completed_30d' => (int) DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->where('status', 'completed')
                ->where('created_at', '>=', $monthAgo)
                ->count(),
            'credits_consumed_30d' => (int) DB::table('credit_transactions')
                ->where('workspace_id', $wsId)
                ->where('type', 'debit')
                ->where('created_at', '>=', $monthAgo)
                ->sum('amount') ?: 0,
            'approved_proposals_30d' => (int) DB::table('strategy_proposals')
                ->where('workspace_id', $wsId)
                ->where('status', 'approved')
                ->where('created_at', '>=', $monthAgo)
                ->count(),
        ];
    }

    /**
     * Try dedicated /internal/sarah/synthesize-monthly first, then fall
     * back to multi-agent meeting via aiRun.
     */
    private function runStrategyMeeting(int $wsId, array $current, array $outcomes): ?array
    {
        $payload = [
            'workspace_id'  => $wsId,
            'period'        => 'last_30_days',
            'captured_at'   => now()->toIso8601String(),
            'current_state' => $current,
            'month_outcomes' => $outcomes,
        ];

        $dedicated = $this->tryDedicatedEndpoint($wsId, $payload);
        if ($dedicated !== null) return $dedicated;

        return $this->fallbackViaAiRun($wsId, $payload);
    }

    private function tryDedicatedEndpoint(int $wsId, array $payload): ?array
    {
        try {
            if (!$this->runtime->isConfigured()) return null;
            $cfg = config('services.runtime') ?? [];
            $baseUrl = rtrim((string) ($cfg['url'] ?? env('RUNTIME_URL') ?? ''), '/');
            $secret  = (string) ($cfg['secret'] ?? env('RUNTIME_SECRET') ?? '');
            if (!$baseUrl || !$secret) return null;
            $resp = Http::timeout(120)
                ->withHeaders(['X-LevelUp-Secret' => $secret])
                ->post($baseUrl . '/internal/sarah/synthesize-monthly', $payload);
            if (!$resp->successful()) return null;
            $body = $resp->json();
            if (!is_array($body) || empty($body['executive_summary'])) return null;
            return [
                'source'             => 'dedicated_endpoint',
                'executive_summary'  => (string) $body['executive_summary'],
                'thirty_day_plan'    => $body['thirty_day_plan'] ?? [],
                'agent_contributions' => $body['agent_contributions'] ?? [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fallbackViaAiRun(int $wsId, array $payload): ?array
    {
        $stateJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $prompt = "You are Sarah, the DMM facilitating a monthly strategy meeting. "
            . "Attendees: Sarah (lead), James (SEO), Alex (technical SEO), Priya (content), Nora (content strategy), Elena (CRM), Max (conversion).\n\n"
            . "Below is the workspace's last 30 days + current state. Synthesize a refreshed "
            . "30-day plan as if you ran a real team meeting and captured the consensus.\n\n"
            . "Output structure:\n"
            . "  - executive_summary (markdown, 200-400 words): what the team agreed on. Lead with the 1-2 biggest opportunities for the next 30 days.\n"
            . "  - thirty_day_plan (array): week-by-week tactical breakdown. Each week has objective + actions + expected outcome.\n"
            . "  - agent_contributions: short note from each specialist agent on what they will own next 30 days.\n\n"
            . "Rules: real data only, no invented metrics, no competitor names, current year " . date('Y') . ".\n\n"
            . "Return ONLY this JSON (no preamble, no fences):\n"
            . "{\"executive_summary\":\"...\",\"thirty_day_plan\":[{\"week\":1,\"objective\":\"...\",\"actions\":[\"...\"],\"expected_outcome\":\"...\"}],\"agent_contributions\":{\"james\":\"...\",\"priya\":\"...\"}}\n\n"
            . "WORKSPACE STATE:\n" . $stateJson;

        try {
            $r = $this->runtime->aiRun('seo_content_generation', $prompt, [
                'workspace_id' => $wsId,
                'task'         => 'sarah_monthly_strategy',
            ], 4000);
            if (empty($r['success']) || empty($r['text'])) return null;
            $text = (string) $r['text'];
            $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```\s*$/', '', $text);
            $parsed = json_decode(trim($text), true);
            if (!is_array($parsed)) {
                if (preg_match('/\{.*\}/s', $text, $m)) $parsed = json_decode($m[0], true);
            }
            if (!is_array($parsed) || empty($parsed['executive_summary'])) return null;
            return [
                'source'              => 'aiRun_fallback',
                'executive_summary'   => (string) $parsed['executive_summary'],
                'thirty_day_plan'     => $parsed['thirty_day_plan'] ?? [],
                'agent_contributions' => $parsed['agent_contributions'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('[SarahMonthly] aiRun exception: ' . $e->getMessage());
            return null;
        }
    }

    private function persistMonthlyPlan(int $wsId, array $meeting): ?int
    {
        try {
            return DB::table('strategy_proposals')->insertGetId([
                'workspace_id'        => $wsId,
                'type'                => 'monthly_30_day_plan',
                'title'               => '30-day strategy plan — ' . now()->format('M Y'),
                'description'         => mb_substr((string) ($meeting['executive_summary'] ?? ''), 0, 65535),
                'status'              => 'pending_approval',
                'cost_breakdown_json' => json_encode([
                    ['agent' => 'sarah', 'action' => 'strategy_meeting', 'credits' => 8, 'description' => 'Multi-agent monthly meeting'],
                ]),
                'total_credits'       => 8,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SarahMonthly] plan persist failed: ' . $e->getMessage());
            return null;
        }
    }

    private function postToChat(int $wsId, string $message, array $meta = []): void
    {
        // 2026-06-22 — budget is in CREDITS, never dollars ($900 → 900cr).
        $message = preg_replace('/\$(\d[\d,]*)/', '${1}cr', $message);
        try {
            app(AgentMessageService::class)->postAsAgent($wsId, 'sarah', $message, $meta);
        } catch (\Throwable $e) {
            Log::warning('[SarahMonthly] chat post failed: ' . $e->getMessage());
        }
    }
}
