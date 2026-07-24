<?php

namespace App\Console\Commands;

use App\Core\Strategy\SarahDailyOrchestrator;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * 2026-05-24 FIX 46 — Sarah's morning brief command.
 *
 * Runs the daily orchestration cycle for every onboarded workspace
 * that has proactive_enabled = true.
 *
 * 2026-05-24 FIX 53 — timezone awareness. The cron now fires HOURLY and
 * this command gates each workspace to its local 08:00 hour, with an
 * idempotency check so the hourly cron doesn't double-post if a brief
 * already ran today (in the workspace's local date).
 *
 * Usage:
 *   php artisan sarah:morning-brief                  # all eligible workspaces (TZ-gated)
 *   php artisan sarah:morning-brief --workspace=7    # single ws (still TZ-gated)
 *   php artisan sarah:morning-brief --force          # bypass TZ + idempotency gates
 *   php artisan sarah:morning-brief --dry-run        # gather + log, no post
 */
class SarahMorningBriefCommand extends Command
{
    protected $signature = 'sarah:morning-brief {--workspace= : single workspace_id} {--dry-run : log only, no chat post} {--force : bypass timezone + idempotency gates}';
    protected $description = 'Sarah generates and posts the daily morning brief with proposed actions (TZ-gated to 08:00 workspace-local)';

    public function handle(SarahDailyOrchestrator $orchestrator): int
    {
        $oneWs = $this->option('workspace');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Workspace::query()
            ->where('onboarded', true)
            ->where('proactive_enabled', true);
        if ($oneWs) $query->where('id', (int) $oneWs);

        $workspaces = $query->get();
        $this->info('Running morning brief for ' . $workspaces->count() . ' workspace(s)' . ($dryRun ? ' [DRY RUN]' : '') . ($force ? ' [FORCE]' : ''));

        $discovery = app(\App\Core\Strategy\DiscoveryRunOrchestrator::class);
        $goalLifecycle = app(\App\Core\Strategy\GoalLifecycleService::class);

        $ok = 0; $fail = 0; $skipped = 0;
        foreach ($workspaces as $w) {
            // 2026-05-24 FIX 53 — TZ gate + idempotency. Hourly cron
            // calls this command; only fire when it's 08:00 in the
            // workspace's local time and we haven't already posted
            // today's brief in that local date.
            if (!$force && !$dryRun) {
                $tz = $w->timezone ?: 'UTC';
                try {
                    $localNow = \Carbon\Carbon::now($tz);
                } catch (\Throwable $e) {
                    $localNow = \Carbon\Carbon::now('UTC');
                }
                if ($localNow->hour !== 8) {
                    $skipped++;
                    continue;
                }
                $todayLocal = $localNow->toDateString();
                $memKey = "ws:{$w->id}:last_morning_brief_local_date";
                try {
                    $last = \Illuminate\Support\Facades\Cache::get($memKey);
                } catch (\Throwable $e) {
                    $last = null;
                }
                if ($last === $todayLocal) {
                    $skipped++;
                    continue;
                }
            }
            try {
                if ($dryRun) {
                    $gatherer = app(\App\Core\Strategy\WorkspaceStateGatherer::class);
                    $state = $gatherer->gather((int) $w->id);
                    $this->line("  ws={$w->id} {$w->name}: gathered " . count($state) . " sections");
                    $ok++;
                    continue;
                }

                // 2026-05-24 FIX 51 — discovery run. Idempotent — only
                // runs once per workspace, then the timestamp lives in
                // workspace_memory. Back-fills any existing workspace
                // that was onboarded before this fix shipped. Runs
                // BEFORE the daily brief so the brief can reference
                // discovery findings on day-1 morning.
                $discResult = $discovery->runIfNew((int) $w->id);
                if (!empty($discResult['skipped'])) {
                    // Already run before — silent skip
                } else {
                    $stepsOk = count(array_filter($discResult['steps'] ?? [], fn ($s) => ($s['ok'] ?? false)));
                    $stepsTotal = count($discResult['steps'] ?? []);
                    $this->line("  ws={$w->id} {$w->name}: DISCOVERY ran ({$stepsOk}/{$stepsTotal} steps ok)");
                }

                // 2026-05-24 FIX 52 — goal lifecycle. Updates each active
                // goal's current_state_json + status (achieved/at_risk/etc)
                // BEFORE the gather pass so the morning brief sees fresh
                // progress numbers. Pure rules — no LLM here; the LLM
                // narrates the results in the brief.
                try {
                    $goalUpdates = $goalLifecycle->updateAllGoalsForWorkspace((int) $w->id);
                    if (!empty($goalUpdates)) {
                        $atRisk = count(array_filter($goalUpdates, fn ($g) => in_array($g['status'] ?? '', ['at_risk', 'off_track'], true)));
                        $achieved = count(array_filter($goalUpdates, fn ($g) => ($g['status'] ?? '') === 'achieved'));
                        $this->line("  ws={$w->id} {$w->name}: " . count($goalUpdates) . " goal(s) updated ({$atRisk} at-risk, {$achieved} achieved)");
                    }
                } catch (\Throwable $e) {
                    $this->warn("  ws={$w->id}: goal lifecycle EXCEPTION — " . $e->getMessage());
                }

                $r = $orchestrator->runDaily((int) $w->id);
                if (($r['posted'] ?? false)) {
                    $this->line("  ws={$w->id} {$w->name}: posted brief, {$r['proposals']} proposals queued");
                    // 2026-05-24 FIX 53 — stamp idempotency key in
                    // workspace-local date. 90-day TTL is overkill but
                    // matches the existing workspace_memory pattern.
                    if (!$force && !$dryRun) {
                        try {
                            $tz = $w->timezone ?: 'UTC';
                            $todayLocal = \Carbon\Carbon::now($tz)->toDateString();
                            \Illuminate\Support\Facades\Cache::put("ws:{$w->id}:last_morning_brief_local_date", $todayLocal, now()->addDays(90));
                        } catch (\Throwable $e) {}
                    }
                    $ok++;
                } else {
                    $this->warn("  ws={$w->id} {$w->name}: SKIPPED — " . ($r['reason'] ?? 'unknown'));
                    $fail++;
                }
            } catch (\Throwable $e) {
                $this->error("  ws={$w->id}: EXCEPTION — " . $e->getMessage());
                $fail++;
            }
        }
        $tzSkipMsg = $skipped > 0 ? " | TZ-gated: {$skipped}" : '';
        $this->info("Done. Success: {$ok} | Failed: {$fail}{$tzSkipMsg}");
        return Command::SUCCESS;
    }
}
