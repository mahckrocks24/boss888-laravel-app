<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 FIX 52 — Goal lifecycle.
 *
 * For every active goal in workspace_goals, this service:
 *   1. Measures current state vs target (cheap DB reads — pure rules)
 *   2. Computes time-elapsed-vs-time-remaining
 *   3. Classifies status: achieved / on_track / at_risk / off_track
 *   4. Updates workspace_goals.current_state_json + status
 *
 * Architecture rule: this is pure rules + arithmetic, no LLM/synthesis.
 * It feeds the morning brief's GOALS section with REAL progress numbers
 * that the LLM in runtime then narrates ("You're 60% there with 30 days
 * left").
 *
 * Goal types currently supported (extensible):
 *   - keyword_rank_target — count keywords at or above target_rank
 *   - traffic_growth      — last-30-day visit count vs target
 *   - lead_volume         — leads this month vs target
 *   - email_subscribers   — active subscriber count vs target
 *   - social_followers    — per-platform follower count vs target
 *   - revenue_attribution — currently not trackable; marks as "pending_data"
 */
class GoalLifecycleService
{
    /**
     * Update all active goals for a workspace. Returns the updated goals.
     * Called by SarahMorningBriefCommand before the daily orchestrator
     * runs so the brief sees fresh progress numbers.
     */
    public function updateAllGoalsForWorkspace(int $wsId): array
    {
        $goals = DB::table('workspace_goals')
            ->where('workspace_id', $wsId)
            ->where('status', '!=', 'achieved')
            ->where('status', '!=', 'abandoned')
            ->whereNull('deleted_at')
            ->get();

        $updated = [];
        foreach ($goals as $g) {
            try {
                $result = $this->updateGoal($g);
                $updated[] = $result;
            } catch (\Throwable $e) {
                Log::warning('[GoalLifecycle] update failed for goal #' . $g->id . ': ' . $e->getMessage());
            }
        }
        return $updated;
    }

    /**
     * Measure + classify a single goal. Persists current_state_json +
     * status back to DB. Returns the updated state.
     */
    public function updateGoal(object $g): array
    {
        $wsId = (int) $g->workspace_id;
        $target = json_decode($g->target_json ?? '{}', true) ?: [];

        // Measure current
        $current = $this->measureCurrent($wsId, (string) $g->goal_type, $target);

        // Classify status using progress vs time-elapsed
        $status = $this->classify($g, $current, $target);

        DB::table('workspace_goals')->where('id', $g->id)->update([
            'current_state_json'      => json_encode($current),
            'status'                  => $status,
            'last_progress_check_at'  => now(),
            'updated_at'              => now(),
        ]);

        return [
            'goal_id'       => $g->id,
            'goal_type'     => $g->goal_type,
            'title'         => $g->title,
            'current'       => $current,
            'target'        => $target,
            'status'        => $status,
            'deadline'      => $g->target_deadline,
        ];
    }

    /**
     * Measure the current state of a goal. Pure DB reads.
     */
    private function measureCurrent(int $wsId, string $type, array $target): array
    {
        switch ($type) {
            case 'keyword_rank_target':
                return $this->measureKeywordRankTarget($wsId, $target);
            case 'traffic_growth':
                return $this->measureTrafficGrowth($wsId, $target);
            case 'lead_volume':
                return $this->measureLeadVolume($wsId, $target);
            case 'email_subscribers':
                return $this->measureEmailSubscribers($wsId, $target);
            case 'social_followers':
                return $this->measureSocialFollowers($wsId, $target);
            case 'revenue_attribution':
                // Not yet trackable — needs payments/analytics integration
                return ['status' => 'pending_data', 'note' => 'Revenue attribution requires payments/analytics integration'];
            default:
                return ['error' => 'unsupported_goal_type'];
        }
    }

    private function measureKeywordRankTarget(int $wsId, array $target): array
    {
        $keywords = $target['keywords'] ?? [];
        $targetRank = (int) ($target['target_rank'] ?? 10);
        $total = count($keywords);
        if ($total === 0) return ['error' => 'no_keywords_in_target'];

        // Match against seo_keywords by string equality (case insensitive)
        $rows = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->whereIn(DB::raw('LOWER(keyword)'), array_map('strtolower', $keywords))
            ->get(['keyword', 'current_rank']);

        $atOrBelow = 0;
        $sumRank = 0;
        $rankedCount = 0;
        $unranked = [];
        $belowTarget = [];

        foreach ($rows as $r) {
            $rank = $r->current_rank;
            if ($rank === null || $rank === 0) {
                $unranked[] = $r->keyword;
                continue;
            }
            $rankedCount++;
            $sumRank += $rank;
            if ($rank <= $targetRank) {
                $atOrBelow++;
            } else {
                $belowTarget[] = ['keyword' => $r->keyword, 'rank' => $rank];
            }
        }

        // Keywords listed but not in seo_keywords table — track separately
        $foundLower = array_map(fn ($r) => strtolower((string) $r->keyword), $rows->toArray());
        $missing = array_filter($keywords, fn ($k) => !in_array(strtolower($k), $foundLower, true));

        return [
            'total_target_keywords'  => $total,
            'keywords_tracked'       => $rankedCount + count($unranked),
            'keywords_missing'       => array_values($missing),
            'keywords_unranked'      => $unranked,
            'keywords_in_target'     => $atOrBelow,
            'keywords_below_target'  => array_slice($belowTarget, 0, 10),
            'progress_pct'           => $total > 0 ? round(($atOrBelow / $total) * 100, 1) : 0,
            'average_rank'           => $rankedCount > 0 ? round($sumRank / $rankedCount, 1) : null,
        ];
    }

    private function measureTrafficGrowth(int $wsId, array $target): array
    {
        $targetPerMonth = (int) ($target['target_visits_per_month'] ?? 0);
        $current = 0;
        try {
            if (Schema::hasTable('traffic_logs')) {
                $current = (int) DB::table('traffic_logs')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('action', 'allowed')
                    ->count();
            }
        } catch (\Throwable $e) {}
        return [
            'current_visits_30d'      => $current,
            'target_visits_per_month' => $targetPerMonth,
            'progress_pct'            => $targetPerMonth > 0 ? round(min(100, ($current / $targetPerMonth) * 100), 1) : 0,
        ];
    }

    private function measureLeadVolume(int $wsId, array $target): array
    {
        $targetPerMonth = (int) ($target['target_per_month'] ?? 0);
        $current = 0;
        try {
            if (Schema::hasTable('leads')) {
                $current = (int) DB::table('leads')
                    ->where('workspace_id', $wsId)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count();
            }
        } catch (\Throwable $e) {}
        return [
            'leads_this_month'        => $current,
            'target_per_month'        => $targetPerMonth,
            'progress_pct'            => $targetPerMonth > 0 ? round(min(100, ($current / $targetPerMonth) * 100), 1) : 0,
        ];
    }

    private function measureEmailSubscribers(int $wsId, array $target): array
    {
        $targetCount = (int) ($target['target'] ?? $target['target_count'] ?? 0);
        $current = 0;
        try {
            if (Schema::hasTable('email_subscribers')) {
                $current = (int) DB::table('email_subscribers')
                    ->where('workspace_id', $wsId)
                    ->where('status', 'active')
                    ->count();
            }
        } catch (\Throwable $e) {}
        return [
            'current_subscribers' => $current,
            'target_subscribers'  => $targetCount,
            'progress_pct'        => $targetCount > 0 ? round(min(100, ($current / $targetCount) * 100), 1) : 0,
        ];
    }

    private function measureSocialFollowers(int $wsId, array $target): array
    {
        $platform = (string) ($target['platform'] ?? 'all');
        $targetCount = (int) ($target['target'] ?? 0);
        $current = 0;
        try {
            if (Schema::hasTable('social_accounts') && Schema::hasColumn('social_accounts', 'follower_count')) {
                $q = DB::table('social_accounts')->where('workspace_id', $wsId);
                if ($platform !== 'all') $q->where('platform', $platform);
                $current = (int) $q->sum('follower_count');
            }
        } catch (\Throwable $e) {}
        return [
            'platform'           => $platform,
            'current_followers'  => $current,
            'target_followers'   => $targetCount,
            'progress_pct'       => $targetCount > 0 ? round(min(100, ($current / $targetCount) * 100), 1) : 0,
        ];
    }

    /**
     * Classify a goal's status based on progress vs time elapsed.
     *
     * Rule:
     *   - achieved: progress_pct >= 100 (or domain-specific completion)
     *   - on_track: progress_pct >= time_elapsed_pct * 0.85
     *   - at_risk: progress_pct >= time_elapsed_pct * 0.5
     *   - off_track: progress_pct < time_elapsed_pct * 0.5
     *
     * If no deadline or progress_pct unmeasured, default to 'active'.
     */
    private function classify(object $goal, array $current, array $target): string
    {
        $progressPct = (float) ($current['progress_pct'] ?? -1);
        if ($progressPct < 0) return 'active';  // unmeasurable yet
        if ($progressPct >= 100) return 'achieved';

        // Compute time-elapsed percentage if deadline known
        $startedAt = $goal->started_at ?? null;
        $deadline = $goal->target_deadline ?? null;
        if (!$startedAt || !$deadline) return 'active';

        try {
            $startTs = strtotime((string) $startedAt);
            $endTs   = strtotime((string) $deadline);
            $nowTs   = time();
            $totalDays   = max(1, ($endTs - $startTs) / 86400);
            $elapsedDays = max(0, ($nowTs - $startTs) / 86400);
            $timeElapsedPct = min(100, ($elapsedDays / $totalDays) * 100);
        } catch (\Throwable $e) {
            return 'active';
        }

        // Past deadline + not achieved
        if ($nowTs > $endTs) return 'off_track';

        // Don't classify early — first 14 days of any goal, just say active
        if ($timeElapsedPct < 15) return 'active';

        $expectedProgress = $timeElapsedPct * 0.85;
        if ($progressPct >= $expectedProgress) return 'on_track';
        if ($progressPct >= $timeElapsedPct * 0.5) return 'at_risk';
        return 'off_track';
    }
}
