<?php

namespace App\Console\Commands;

use App\Core\Billing\FeatureGateService;
use App\Engines\Mention\Services\MentionScanService;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MENTION888 — Daily scheduled scanner.
 *
 * Fires hourly via Laravel scheduler. For each onboarded workspace:
 *   1. Resolve active plan → look up daily scan cap
 *   2. Count today's scan_runs of type='scheduled'
 *   3. If cap reached → skip workspace
 *   4. Else pick (cap - count) active watchlist rows whose
 *      last_scanned_at is null OR > 24h ago, ordered priority DESC,
 *      last_scanned_at ASC (oldest first)
 *   5. Call MentionScanService.scanWatchlist for each
 *
 * Idempotency: re-running within the same workspace-local day is a
 * no-op once the cap is hit OR all watchlist rows are within their
 * 24h cooldown. Safe to run hourly.
 *
 * Flags:
 *   --workspace=ID    only this workspace
 *   --watchlist=ID    only this watchlist row (still cap-gated)
 *   --dry-run         compute eligibility, log decisions, don't scan
 *   --force           bypass cap + 24h cooldown (admin debugging)
 *   --sentiment=full|rule_only|skip
 *                     override the sentiment mode (default: full)
 */
class MentionScanDailyCommand extends Command
{
    protected $signature = 'mention:scan-daily
        {--workspace= : single workspace_id}
        {--watchlist= : single watchlist_id}
        {--dry-run : log decisions only}
        {--force : bypass cap + 24h cooldown}
        {--sentiment=full : sentiment_mode override}';

    protected $description = 'Scan brand watchlist for new mentions; per-plan daily cap, 24h cooldown';

    /** Daily scan caps per plan slug. Plans not listed default to 0. */
    public const PLAN_DAILY_CAPS = [
        'free'        => 0,
        'starter'     => 0,
        'ai-lite'     => 0,
        'wp_bundle'   => 0,
        'growth'      => 1,
        'wp_growth'   => 1,
        'pro'         => 1,
        'wp_pro'      => 1,
        'agency'      => 3,
        'wp_agency'   => 3,
    ];

    public function handle(FeatureGateService $gates, MentionScanService $scanner): int
    {
        $force        = (bool) $this->option('force');
        $dryRun       = (bool) $this->option('dry-run');
        $wsFilter     = $this->option('workspace');
        $wlFilter     = $this->option('watchlist');
        $sentMode     = (string) $this->option('sentiment') ?: 'full';

        $startedAt    = now();
        $totalScans   = 0;
        $totalSkipped = 0;
        $totalErrors  = 0;

        $q = Workspace::query()
            ->where('onboarded', true)
            ->orderBy('id');
        if ($wsFilter) $q->where('id', (int) $wsFilter);

        foreach ($q->lazy() as $workspace) {
            $wsId = $workspace->id;

            // Resolve plan + cap
            $plan = $gates->getActivePlanFor($wsId);
            $planSlug = $plan?->slug ?? 'free';
            $cap = self::PLAN_DAILY_CAPS[$planSlug] ?? 0;

            if ($cap === 0 && !$force) {
                $this->line(" ws=$wsId plan=$planSlug → cap=0, skip");
                continue;
            }

            // Count today's scheduled scan_runs in workspace-local "today"
            $today = $this->workspaceLocalToday($workspace);
            $todayCount = DB::table('brand_mention_scan_runs')
                ->where('workspace_id', $wsId)
                ->where('scan_type', 'scheduled')
                ->whereDate('started_at', $today)
                ->count();

            if (!$force && $todayCount >= $cap) {
                $this->line(" ws=$wsId plan=$planSlug → cap=$cap reached ($todayCount/$cap), skip");
                continue;
            }

            $remainingSlots = $force ? PHP_INT_MAX : max(0, $cap - $todayCount);

            // Pick watchlist rows: active, cooldown elapsed (or null), priority desc, oldest scan first
            $wlQ = DB::table('brand_watchlist')
                ->where('workspace_id', $wsId)
                ->where('is_active', true);
            if ($wlFilter) $wlQ->where('id', (int) $wlFilter);
            if (!$force) {
                $wlQ->where(function ($w) {
                    $w->whereNull('last_scanned_at')
                      ->orWhere('last_scanned_at', '<', now()->subHours(24));
                });
            }
            $rows = $wlQ->orderByRaw("FIELD(priority,'high','normal','low')")
                ->orderByRaw('last_scanned_at IS NULL DESC') // null = first scan ever, prioritize
                ->orderBy('last_scanned_at')
                ->limit($remainingSlots)
                ->get(['id', 'label', 'term', 'priority', 'last_scanned_at']);

            if ($rows->isEmpty()) {
                $this->line(" ws=$wsId plan=$planSlug → no eligible watchlist rows");
                continue;
            }

            foreach ($rows as $wl) {
                if ($dryRun) {
                    $this->line(" ws=$wsId wl={$wl->id} '{$wl->label}' priority={$wl->priority} → would scan");
                    continue;
                }

                $res = $scanner->scanWatchlist($wsId, $wl->id, [
                    'scan_type'       => 'scheduled',
                    'sentiment_mode'  => $sentMode,
                ]);
                if (!empty($res['success'])) {
                    $totalScans++;
                    $this->info(" ws=$wsId wl={$wl->id} → found={$res['results_found']} new={$res['results_new']} credits={$res['credits_charged']}");
                } else {
                    $totalErrors++;
                    $this->warn(" ws=$wsId wl={$wl->id} → ERR: " . ($res['error'] ?? 'unknown'));
                }
            }
        }

        $duration = $startedAt->diffInSeconds(now());
        $this->info("Scan cycle complete: scans={$totalScans} skipped={$totalSkipped} errors={$totalErrors} duration={$duration}s");
        return self::SUCCESS;
    }

    /**
     * Today's date in workspace-local timezone. Falls back to UTC when
     * workspaces.timezone is malformed.
     */
    private function workspaceLocalToday(Workspace $workspace): string
    {
        $tz = $workspace->timezone ?: 'UTC';
        try {
            return Carbon::now($tz)->toDateString();
        } catch (\Throwable $e) {
            return Carbon::now('UTC')->toDateString();
        }
    }
}