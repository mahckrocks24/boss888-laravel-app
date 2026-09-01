<?php

namespace App\Core\Strategy;

use App\Core\Strategy\StrategyTierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 FIX 46 — Sarah's daily state gatherer.
 *
 * Reads CHEAP DB state from every engine and packages it into a single
 * payload that the runtime synthesises into Sarah's morning brief.
 *
 * Architectural rule (from CLAUDE.md + project memory):
 *   - This class does NO intelligence, NO scoring, NO ranking, NO
 *     classification. Pure data reads. The runtime does synthesis.
 *   - Where a "computed field" makes sense (e.g. "orphan count"), it's
 *     a SQL COUNT or simple arithmetic — never an LLM call or a
 *     ranking heuristic.
 *
 * For each engine: read what's in its existing tables. No new schema
 * required beyond workspace_goals (FIX 46 migration).
 */
class WorkspaceStateGatherer
{
    /**
     * Master entry point — returns the full state blob to send to runtime.
     */
    public function gather(int $wsId): array
    {
        return [
            'workspace_id'  => $wsId,
            'captured_at'   => now()->toIso8601String(),
            'tier_state'    => $this->readTierState($wsId),
            'goals'         => $this->readGoals($wsId),
            'seo'           => $this->readSeoState($wsId),
            'write'         => $this->readWriteState($wsId),
            'creative'      => $this->readCreativeState($wsId),
            // LAUNCH SCOPE 2026-07-20 — social + email removed from launch;
            // their state blocks no longer feed Sarah's briefs.
            // 'social'     => $this->readSocialState($wsId),
            // 'email'      => $this->readEmailState($wsId),
            'crm'           => $this->readCrmState($wsId),
            'chatbot'       => $this->readChatbotState($wsId),
            'aeo'           => $this->readAeoState($wsId),
            'traffic'       => $this->readTrafficState($wsId),
            'builder'       => $this->readBuilderState($wsId),
            'competitor'    => $this->readCompetitorState($wsId),
            'pipeline'      => $this->readPipelineState($wsId),
            'approvals'     => $this->readApprovalsState($wsId),
        ];
    }

    // ── PENDING APPROVALS (owner action required) ─────────────────────
    //
    // 2026-07-16 — Sarah was blind to her own approval queue. Pending
    // proposals were never surfaced in state, so she proposed an external
    // action ONCE and never followed up — leaving real, finished work
    // stranded behind an un-clicked approval (e.g. 30 completed draft
    // articles waiting on a single publish OK). A DMM chases the decisions
    // that gate results; this makes the aging queue VISIBLE to her so the
    // brief can lead with a follow-up.
    //
    // Only EXTERNAL / irreversible asks are surfaced here (publish, send
    // email, social, sequence launch, goal pivot). The routine content/SEO
    // work in $autoSafe is auto-run under standing approval by
    // sarah:auto-execute and must NEVER be framed as an approval request —
    // asking for it daily is the exact nag the owner rejected.
    private function readApprovalsState(int $wsId): array
    {
        $empty = ['needs_owner_ok' => [], 'count' => 0, 'oldest_age_days' => 0, 'total_credits' => 0];

        // Keep in lockstep with SarahAutoExecuteCommand::BOUNDED_AUTO /
        // SyncProposalApprovalsCommand::AUTO_SAFE.
        $autoSafe = [
            'write_article', 'insert_link', 'fix_orphans', 'generate_meta',
            'expand_thin_pages', 'apply_link_suggestions', 'link_suggestions',
            'improve_draft', 'generate_image',
        ];

        try {
            $rows = DB::table('strategy_proposals')
                ->where('workspace_id', $wsId)
                ->where('status', 'pending_approval')
                ->orderBy('created_at')
                ->get(['id', 'type', 'title', 'total_credits', 'created_at']);
        } catch (\Throwable $e) {
            Log::warning('[Sarah state] approvals read failed: ' . $e->getMessage());
            return $empty;
        }

        $items = [];
        $totalCredits = 0;
        $oldestAge = 0;
        foreach ($rows as $r) {
            $slug = preg_replace('/^(daily_action_|weekly_pivot_)/', '', (string) $r->type);
            // publish_ready always needs the owner; otherwise skip auto-safe work.
            if ($r->type !== 'publish_ready' && in_array($slug, $autoSafe, true)) {
                continue;
            }
            $ageDays = (int) abs(now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($r->created_at)->startOfDay()));
            $oldestAge = max($oldestAge, $ageDays);
            $totalCredits += (int) $r->total_credits;
            $items[] = [
                'id'       => (int) $r->id,
                'title'    => (string) $r->title,
                'kind'     => $r->type === 'publish_ready' ? 'publish' : $slug,
                'age_days' => $ageDays,
                'credits'  => (int) $r->total_credits,
            ];
        }

        if (empty($items)) {
            return $empty;
        }

        // Oldest-first: the most-aged decision leads the follow-up.
        usort($items, fn ($a, $b) => $b['age_days'] <=> $a['age_days']);

        return [
            'needs_owner_ok'  => array_slice($items, 0, 8),
            'count'           => count($items),
            'oldest_age_days' => $oldestAge,
            'total_credits'   => $totalCredits,
        ];
    }

    // ── TIER + BUDGET ─────────────────────────────────────────────────

    private function readTierState(int $wsId): array
    {
        $active = StrategyTierService::getActiveStrategy($wsId);
        $balance = (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0);
        $planLimit = (int) (DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.workspace_id', $wsId)
            ->whereIn('subscriptions.status', ['active', 'trialing'])
            ->orderByDesc('subscriptions.id')
            ->value('plans.credit_limit') ?? 0);
        $dayOfMonth = (int) now()->day;
        $daysInMonth = (int) now()->daysInMonth;
        $daysLeft = max(0, $daysInMonth - $dayOfMonth);
        $expectedConsumed = $planLimit > 0 ? (int) round($planLimit * ($dayOfMonth / $daysInMonth)) : 0;
        $actualConsumed = max(0, $planLimit - $balance);
        $consumedPct = $planLimit > 0 ? round(($actualConsumed / $planLimit) * 100, 1) : 0.0;
        $paceOver = ($expectedConsumed > 0) && ($actualConsumed > $expectedConsumed * 1.15);

        // 2026-05-24 FIX 54 — combined absolute + pace classification.
        // Absolute thresholds take precedence so the user sees the most
        // informative state ("90% consumed" beats "pace over") whenever
        // both apply.
        //   over          → 100%+ of monthly budget consumed OR (under
        //                   80% absolute AND pace is 15%+ ahead of
        //                   pro-rata expected consumption)
        //   critical_90   → 90-99% consumed (absolute heads-up)
        //   warning_80    → 80-89% consumed (early heads-up)
        //   under         → less than 60% of expected pro-rata pace
        //   on_track      → otherwise
        if ($consumedPct >= 100) {
            $burnStatus = 'over';
        } elseif ($consumedPct >= 90) {
            $burnStatus = 'critical_90';
        } elseif ($consumedPct >= 80) {
            $burnStatus = 'warning_80';
        } elseif ($paceOver) {
            $burnStatus = 'over';
        } elseif ($expectedConsumed > 0 && $actualConsumed < $expectedConsumed * 0.6) {
            $burnStatus = 'under';
        } else {
            $burnStatus = 'on_track';
        }

        return [
            'active_tier'           => $active['tier'],
            'tier_name'             => $active['tier_name'],
            'plan_credit_limit'     => $planLimit,
            'credit_balance'        => $balance,
            'credits_consumed'      => $actualConsumed,
            'expected_consumed'     => $expectedConsumed,
            'consumed_pct'          => $consumedPct,
            'burn_rate_status'      => $burnStatus,
            'estimated_monthly_cost' => $active['estimated_monthly_cost'],
            'day_of_month'          => $dayOfMonth,
            'days_in_month'         => $daysInMonth,
            'days_left_in_month'    => $daysLeft,
            'connected_platforms'   => $active['connected_platforms'],
        ];
    }

    // ── GOALS ─────────────────────────────────────────────────────────

    private function readGoals(int $wsId): array
    {
        try {
            // 2026-05-24 FIX 52 — include all non-terminal statuses so
            // GoalLifecycleService's classifications (on_track/at_risk/
            // off_track/achieved) still surface in the brief and
            // ProactiveRuleSet. Only 'abandoned' is filtered out.
            return DB::table('workspace_goals')
                ->where('workspace_id', $wsId)
                ->whereNotIn('status', ['abandoned'])
                ->whereNull('deleted_at')
                ->orderBy('priority')
                ->get(['id', 'goal_type', 'title', 'status', 'target_json', 'current_state_json', 'target_deadline', 'started_at'])
                ->map(fn ($g) => [
                    'id'           => $g->id,
                    'type'         => $g->goal_type,
                    'title'        => $g->title,
                    'status'       => $g->status,
                    'target'       => json_decode($g->target_json ?? '{}', true),
                    'current'      => json_decode($g->current_state_json ?? '{}', true),
                    'deadline'     => $g->target_deadline,
                    'started_at'   => $g->started_at,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── SEO ───────────────────────────────────────────────────────────

    private function readSeoState(int $wsId): array
    {
        $stats = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) AS pages_indexed,
                         SUM(CASE WHEN inbound_links = 0 THEN 1 ELSE 0 END) AS orphans,
                         SUM(CASE WHEN word_count < 300 THEN 1 ELSE 0 END) AS thin_pages,
                         SUM(CASE WHEN meta_description IS NULL OR meta_description = "" THEN 1 ELSE 0 END) AS missing_meta,
                         ROUND(AVG(content_score), 1) AS avg_content_score')
            ->first();

        $keywords = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->where('status', 'tracking')
            ->select('id', 'keyword', 'current_rank', 'volume', 'target_url')
            ->get();

        // Categorize by rank bucket (cheap arithmetic, not "intelligence")
        $rankBuckets = ['top_3' => 0, 'top_10' => 0, 'page_2' => 0, 'opportunity_zone_11_30' => 0, 'low_31_100' => 0, 'unranked' => 0];
        $opportunityKeywords = [];
        foreach ($keywords as $kw) {
            $r = (int) ($kw->current_rank ?? 0);
            if ($r === 0) { $rankBuckets['unranked']++; continue; }
            if ($r <= 3)       $rankBuckets['top_3']++;
            elseif ($r <= 10)  $rankBuckets['top_10']++;
            elseif ($r <= 20)  $rankBuckets['page_2']++;
            elseif ($r <= 30)  $rankBuckets['opportunity_zone_11_30']++;
            else               $rankBuckets['low_31_100']++;
            // Opportunity zone candidates — keywords one push from page 1
            if ($r >= 11 && $r <= 30) {
                $opportunityKeywords[] = ['keyword' => $kw->keyword, 'rank' => $r, 'volume' => $kw->volume];
            }
        }

        // Rank deltas from 7 days ago
        $rankDeltas = [];
        try {
            if (Schema::hasTable('keyword_rank_history')) {
                $weekAgo = DB::table('keyword_rank_history')
                    ->where('workspace_id', $wsId)
                    ->where('captured_at', '<=', now()->subDays(7))
                    ->where('captured_at', '>=', now()->subDays(10))
                    ->get(['keyword_id', 'rank']);
                $weekAgoMap = $weekAgo->keyBy('keyword_id');
                foreach ($keywords as $kw) {
                    $past = $weekAgoMap[$kw->id]->rank ?? null;
                    if ($past !== null && $kw->current_rank !== null && $past !== $kw->current_rank) {
                        $rankDeltas[] = [
                            'keyword'    => $kw->keyword,
                            'from_rank'  => (int) $past,
                            'to_rank'    => (int) $kw->current_rank,
                            'delta'      => (int) $past - (int) $kw->current_rank,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}

        $staleArticles = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->where('updated_at', '<', now()->subDays(60))
            ->whereNull('deleted_at')
            ->select('id', 'title', 'updated_at', 'published_at')
            ->limit(10)
            ->get();

        $latestAudit = DB::table('seo_audits')
            ->where('workspace_id', $wsId)
            ->orderByDesc('id')
            ->first(['id', 'score', 'created_at']);

        $pendingLinkSugs = (int) DB::table('seo_links')
            ->where('workspace_id', $wsId)
            ->where('status', 'suggested')
            ->count();

        return [
            'pages_indexed'         => (int) ($stats->pages_indexed ?? 0),
            'orphan_pages'          => (int) ($stats->orphans ?? 0),
            'thin_pages'            => (int) ($stats->thin_pages ?? 0),
            'missing_meta'          => (int) ($stats->missing_meta ?? 0),
            'avg_content_score'     => (float) ($stats->avg_content_score ?? 0),
            'tracked_keywords'      => count($keywords),
            'rank_buckets'          => $rankBuckets,
            'opportunity_keywords'  => array_slice($opportunityKeywords, 0, 10),
            'rank_deltas_7d'        => array_slice($rankDeltas, 0, 15),
            'stale_articles'        => $staleArticles->toArray(),
            'last_audit_score'      => $latestAudit->score ?? null,
            'last_audit_at'         => $latestAudit->created_at ?? null,
            'pending_link_suggestions' => $pendingLinkSugs,
            // 2026-06-12 — real Google performance so Sarah's strategy is
            // driven by actual rankings/traffic, not just on-site signals.
            'search_console'        => app(\App\Engines\SEO\Services\GscClient::class)->quickTotals($wsId, 28),
            'analytics'             => app(\App\Engines\SEO\Services\GaClient::class)->quickTotals($wsId, 28),
        ];
    }

    // ── WRITE / CONTENT ──────────────────────────────────────────────

    private function readWriteState(int $wsId): array
    {
        $articles = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->select(DB::raw('status, COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        $weekAgo = now()->subDays(7);
        $publishedLastWeek = (int) DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->where('published_at', '>=', $weekAgo)
            ->count();

        // Refresh-priority candidates: 60d+ old + low rank for their keyword
        $refreshCandidates = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->where('updated_at', '<', now()->subDays(60))
            ->whereNull('deleted_at')
            ->orderBy('updated_at')
            ->limit(5)
            ->get(['id', 'title', 'updated_at', 'focus_keyword']);

        return [
            'articles_by_status'   => $articles,
            'published_last_7d'    => $publishedLastWeek,
            'refresh_candidates'   => $refreshCandidates->toArray(),
        ];
    }

    // ── CREATIVE ──────────────────────────────────────────────────────

    private function readCreativeState(int $wsId): array
    {
        $imagesGenerated30d = 0;
        try {
            if (Schema::hasTable('seo_images')) {
                $imagesGenerated30d = (int) DB::table('seo_images')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count();
            }
        } catch (\Throwable $e) {}
        return [
            'images_generated_30d' => $imagesGenerated30d,
        ];
    }

    // ── SOCIAL ────────────────────────────────────────────────────────

    private function readSocialState(int $wsId): array
    {
        $stats = ['posts_last_7d' => 0, 'posts_last_30d' => 0, 'connected_platforms' => 0];
        try {
            if (Schema::hasTable('social_posts')) {
                $stats['posts_last_7d'] = (int) DB::table('social_posts')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(7))->count();
                $stats['posts_last_30d'] = (int) DB::table('social_posts')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(30))->count();
            }
            if (Schema::hasTable('social_accounts')) {
                $stats['connected_platforms'] = (int) DB::table('social_accounts')
                    ->where('workspace_id', $wsId)->where('status', 'connected')->count();
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── EMAIL ─────────────────────────────────────────────────────────

    private function readEmailState(int $wsId): array
    {
        $stats = ['campaigns_last_30d' => 0, 'active_sequences' => 0, 'subscribers' => 0, 'dormant_subscribers' => 0];
        try {
            if (Schema::hasTable('email_campaigns')) {
                $stats['campaigns_last_30d'] = (int) DB::table('email_campaigns')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(30))->count();
            }
            if (Schema::hasTable('email_sequences')) {
                $stats['active_sequences'] = (int) DB::table('email_sequences')
                    ->where('workspace_id', $wsId)->where('status', 'active')->count();
            }
            if (Schema::hasTable('email_subscribers')) {
                $stats['subscribers'] = (int) DB::table('email_subscribers')
                    ->where('workspace_id', $wsId)->where('status', 'active')->count();
                $stats['dormant_subscribers'] = (int) DB::table('email_subscribers')
                    ->where('workspace_id', $wsId)
                    ->where('last_engagement_at', '<', now()->subDays(60))
                    ->count();
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── CRM ───────────────────────────────────────────────────────────

    private function readCrmState(int $wsId): array
    {
        $stats = [
            'leads_total' => 0, 'leads_last_7d' => 0, 'leads_last_30d' => 0,
            // v1.4.4 — DMM-grade additions: pipeline distribution + conversion.
            'pipeline_by_status' => [], 'pipeline_by_source' => [],
            'avg_deal_value' => 0, 'total_pipeline_value' => 0,
            'sequences_active' => 0, 'leads_unassigned' => 0,
            'stale_leads_30d' => 0, // leads with no activity in 30d
        ];
        try {
            if (Schema::hasTable('leads')) {
                $stats['leads_total'] = (int) DB::table('leads')->where('workspace_id', $wsId)->count();
                $stats['leads_last_7d'] = (int) DB::table('leads')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(7))->count();
                $stats['leads_last_30d'] = (int) DB::table('leads')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(30))->count();
                // Pipeline distribution by status (new / contacted / qualified / won / lost).
                $stats['pipeline_by_status'] = DB::table('leads')
                    ->where('workspace_id', $wsId)
                    ->select(DB::raw('status, COUNT(*) AS n'))
                    ->groupBy('status')->pluck('n', 'status')->toArray();
                // Channel attribution — which sources are producing leads.
                $stats['pipeline_by_source'] = DB::table('leads')
                    ->where('workspace_id', $wsId)
                    ->whereNotNull('source')
                    ->select(DB::raw('source, COUNT(*) AS n'))
                    ->groupBy('source')->orderByDesc('n')->limit(10)
                    ->pluck('n', 'source')->toArray();
                // Deal value if column exists.
                if (Schema::hasColumn('leads', 'deal_value')) {
                    $stats['total_pipeline_value'] = (float) DB::table('leads')
                        ->where('workspace_id', $wsId)
                        ->whereNotIn('status', ['lost', 'unqualified'])
                        ->sum('deal_value');
                    $stats['avg_deal_value'] = (float) DB::table('leads')
                        ->where('workspace_id', $wsId)
                        ->whereNotIn('status', ['lost', 'unqualified'])
                        ->avg('deal_value');
                }
                if (Schema::hasColumn('leads', 'assigned_to')) {
                    $stats['leads_unassigned'] = (int) DB::table('leads')
                        ->where('workspace_id', $wsId)
                        ->whereNull('assigned_to')
                        ->whereNotIn('status', ['lost', 'unqualified'])
                        ->count();
                }
                if (Schema::hasColumn('leads', 'last_contacted_at')) {
                    $stats['stale_leads_30d'] = (int) DB::table('leads')
                        ->where('workspace_id', $wsId)
                        ->whereNotIn('status', ['lost', 'unqualified', 'won'])
                        ->where(function ($q) {
                            $q->whereNull('last_contacted_at')->orWhere('last_contacted_at', '<', now()->subDays(30));
                        })->count();
                }
            }
            if (Schema::hasTable('lead_sequences')) {
                $stats['sequences_active'] = (int) DB::table('lead_sequences')
                    ->where('workspace_id', $wsId)->where('status', 'active')->count();
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── CHATBOT ───────────────────────────────────────────────────────

    private function readChatbotState(int $wsId): array
    {
        $stats = ['active' => false, 'sessions_last_7d' => 0, 'unanswered_questions' => []];
        try {
            if (Schema::hasTable('chatbot_settings')) {
                $stats['active'] = (bool) DB::table('chatbot_settings')
                    ->where('workspace_id', $wsId)->where('enabled', 1)->exists();
            }
            if (Schema::hasTable('chatbot_sessions')) {
                $stats['sessions_last_7d'] = (int) DB::table('chatbot_sessions')
                    ->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(7))->count();
            }
            if (Schema::hasTable('chatbot_escalations')) {
                $stats['unanswered_questions'] = DB::table('chatbot_escalations')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->limit(5)
                    ->pluck('user_message')
                    ->toArray();
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── AEO ───────────────────────────────────────────────────────────

    private function readAeoState(int $wsId): array
    {
        $stats = ['aeo_mode_enabled' => false, 'ai_crawler_hits_30d' => 0, 'aeo_audit_score' => null];
        try {
            $stats['aeo_mode_enabled'] = (bool) DB::table('aeo_settings')
                ->where('workspace_id', $wsId)->value('aeo_mode_enabled');
            if (Schema::hasTable('aeo_traffic')) {
                $stats['ai_crawler_hits_30d'] = (int) DB::table('aeo_traffic')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count();
            }
            if (Schema::hasTable('aeo_score_snapshots')) {
                $latest = DB::table('aeo_score_snapshots')
                    ->where('workspace_id', $wsId)
                    ->orderByDesc('id')
                    ->first(['score', 'created_at']);
                $stats['aeo_audit_score'] = $latest->score ?? null;
                $stats['aeo_audit_at'] = $latest->created_at ?? null;
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── TRAFFIC ───────────────────────────────────────────────────────

    private function readTrafficState(int $wsId): array
    {
        $stats = ['real_visits_last_7d' => 0, 'bot_blocks_last_7d' => 0, 'firewall_hits_last_7d' => 0];

        // 2026-06-22 — REAL visits come from Google Analytics (sessions), NOT the
        // TrafficDefense firewall log. traffic_logs counts every *allowed*
        // request — dominated by internal /app dashboard + connector-embed hits
        // (e.g. ws2: 5,676 "visits" from 128 IPs, 94% referred by /app/seo) — so
        // it massively over-states visitors vs reality (~0). Use GA; keep
        // traffic_logs only as a firewall-hit / bot-block metric.
        try {
            $ga = app(\App\Engines\SEO\Services\GaClient::class)->quickTotals($wsId, 7);
            if (is_array($ga)) {
                $stats['real_visits_last_7d'] = (int) ($ga['sessions'] ?? $ga['users'] ?? 0);
            }
        } catch (\Throwable $e) {}

        try {
            if (Schema::hasTable('traffic_logs')) {
                $stats['firewall_hits_last_7d'] = (int) DB::table('traffic_logs')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->where('action', 'allowed')
                    ->count();
                $stats['bot_blocks_last_7d'] = (int) DB::table('traffic_logs')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->where('action', 'blocked')
                    ->count();
            }
        } catch (\Throwable $e) {}
        return $stats;
    }

    // ── BUILDER ───────────────────────────────────────────────────────

    /**
     * The business's websites, not just a tally of them.
     *
     * Chef Red, 2026-09-01: a second website was built — a graphic design business in Dubai — and Sarah never
     * mentioned it. This method was the reason. It returned two integers, so the most she could know was that
     * "2 published websites" existed. She could not name the site, did not know its industry, and had no way
     * to see it had been created that morning with nothing on it.
     *
     * A workspace holds many websites (INC-0006), so a count is not a portfolio. She gets the sites
     * themselves: what they are, how old they are, and whether anything has been written for them — which is
     * what makes a launch worth proposing rather than a number worth reciting.
     */
    private function readBuilderState(int $wsId): array
    {
        try {
            $sites = DB::table('websites')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'name', 'status', 'template_industry', 'subdomain', 'custom_domain', 'created_at']);

            // One grouped query rather than one per site: a portfolio read runs on every turn.
            $articles = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->whereNotNull('website_id')
                ->selectRaw('website_id, COUNT(*) c')
                ->groupBy('website_id')
                ->pluck('c', 'website_id');

            $pages = DB::table('pages')
                ->whereIn('website_id', $sites->pluck('id'))
                ->selectRaw('website_id, COUNT(*) c')
                ->groupBy('website_id')
                ->pluck('c', 'website_id');

            $list = $sites->map(function ($s) use ($articles, $pages) {
                $articleCount = (int) ($articles[$s->id] ?? 0);

                return [
                    'website_id'   => (int) $s->id,
                    'name'         => (string) ($s->name ?? ''),
                    'status'       => (string) ($s->status ?? ''),
                    'industry'     => (string) ($s->template_industry ?? ''),
                    'host'         => (string) ($s->custom_domain ?: $s->subdomain ?: ''),
                    'days_old'     => $s->created_at ? (int) \Carbon\Carbon::parse($s->created_at)->diffInDays(now()) : null,
                    'pages'        => (int) ($pages[$s->id] ?? 0),
                    'articles'     => $articleCount,
                    // The fact that makes a launch plan worth proposing.
                    'has_content'  => $articleCount > 0,
                ];
            })->values()->all();

            return [
                'published_websites' => $sites->where('status', 'published')->count(),
                'draft_websites'     => $sites->where('status', 'draft')->count(),
                'websites'           => $list,
                'websites_without_content' => array_values(array_filter($list, fn ($w) => ! $w['has_content'])),
            ];
        } catch (\Throwable $e) {
            return ['published_websites' => 0, 'draft_websites' => 0, 'websites' => [],
                    'websites_without_content' => []];
        }
    }

    // ── COMPETITOR ────────────────────────────────────────────────────

    private function readCompetitorState(int $wsId): array
    {
        // Cheap read from existing SERP cache (when it exists per keyword)
        $sample = [];
        $topDomains = [];
        try {
            $kws = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->where('status', 'tracking')
                ->whereNotNull('serp_top_results_json')
                ->limit(20)
                ->get(['keyword', 'serp_top_results_json']);
            foreach ($kws as $kw) {
                $serp = json_decode($kw->serp_top_results_json ?? '[]', true);
                $top3 = array_slice($serp ?: [], 0, 3);
                $domains = array_column($top3, 'domain');
                $sample[] = ['keyword' => $kw->keyword, 'top_3_domains' => $domains];
                foreach ($domains as $d) {
                    if (!$d) continue;
                    $topDomains[$d] = ($topDomains[$d] ?? 0) + 1;
                }
            }
            // v1.4.4 — surface the most-recurring competitor domains so Sarah
            // has a real "who are the competitors" answer instead of guessing.
            arsort($topDomains);
            $topDomains = array_slice($topDomains, 0, 8, true);
        } catch (\Throwable $e) {}

        // v1.4.4 — also surface explicitly-declared competitors if the user
        // populated workspace_memory.competitors (recommended Phase B keys).
        $declared = [];
        try {
            $row = DB::table('workspace_memory')
                ->where('workspace_id', $wsId)->where('key', 'competitors')->value('value_json');
            $decoded = is_string($row) ? json_decode($row, true) : null;
            if (is_array($decoded)) $declared = $decoded;
            elseif (is_string($row) && $row !== '') $declared = [trim($row, '"')];
        } catch (\Throwable $e) {}

        return [
            'competitor_signals' => array_slice($sample, 0, 5),
            'top_recurring_serp_domains' => $topDomains,
            'declared_competitors' => $declared,
        ];
    }

    // ── PIPELINE / TASKS ──────────────────────────────────────────────

    private function readPipelineState(int $wsId): array
    {
        $stats = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', now()->subDays(7))
            ->select(DB::raw('status, COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();
        $stuck = (int) DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('status', 'blocked')
            ->where('created_at', '<', now()->subDays(2))
            ->count();
        return [
            'tasks_last_7d_by_status' => $stats,
            'stuck_tasks'             => $stuck,
        ];
    }

    /**
     * Cache the gathered blob for fast read by the morning brief command.
     * 6h TTL — refreshed by the 07:45 cron gather pass.
     */
    public function cache(int $wsId, array $blob): void
    {
        try {
            Redis::setex("sarah_state_blob_{$wsId}", 6 * 3600, json_encode($blob));
        } catch (\Throwable $e) {
            Log::warning('[Sarah state cache] write failed: ' . $e->getMessage());
        }
    }

    public function readCache(int $wsId): ?array
    {
        try {
            $raw = Redis::get("sarah_state_blob_{$wsId}");
            return $raw ? (json_decode($raw, true) ?: null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
