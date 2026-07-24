<?php

namespace App\Console\Commands;

use App\Core\Strategy\SarahWeeklyOrchestrator;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * 2026-05-24 FIX 47 — Sarah's weekly retrospective command.
 * 2026-05-24 FIX 53B — timezone awareness. Cron now fires HOURLY and
 * this command gates each workspace to Monday 09:00 in that workspace's
 * local time, with workspace-local-week idempotency.
 */
class SarahWeeklyReviewCommand extends Command
{
    protected $signature = 'sarah:weekly-review {--workspace= : single workspace_id} {--dry-run : log only} {--force : bypass timezone + idempotency gates}';
    protected $description = 'Sarah generates and posts the weekly retrospective + pivot proposals (TZ-gated to Mon 09:00 workspace-local)';

    public function handle(SarahWeeklyOrchestrator $orchestrator): int
    {
        $oneWs = $this->option('workspace');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Workspace::query()
            ->where('onboarded', true)
            ->where('proactive_enabled', true);
        if ($oneWs) $query->where('id', (int) $oneWs);
        $query->whereIn('proactive_frequency', ['daily', 'weekly']);

        $workspaces = $query->get();
        $this->info('Running weekly review for ' . $workspaces->count() . ' workspace(s)' . ($dryRun ? ' [DRY RUN]' : '') . ($force ? ' [FORCE]' : ''));

        $ok = 0; $fail = 0; $skipped = 0;
        foreach ($workspaces as $w) {
            if (!$force && !$dryRun) {
                $tz = $w->timezone ?: 'UTC';
                try { $localNow = \Carbon\Carbon::now($tz); }
                catch (\Throwable $e) { $localNow = \Carbon\Carbon::now('UTC'); }
                if ($localNow->dayOfWeekIso !== 1 || $localNow->hour !== 9) { $skipped++; continue; }

                $weekKey = $localNow->format('o-\WW');
                $memKey = "ws:{$w->id}:last_weekly_review_local_week";
                try { $last = \Illuminate\Support\Facades\Cache::get($memKey); }
                catch (\Throwable $e) { $last = null; }
                if ($last === $weekKey) { $skipped++; continue; }
            }

            try {
                if ($dryRun) { $this->line("  ws={$w->id} {$w->name}: dry-run skipped"); continue; }
                $r = $orchestrator->runWeekly((int) $w->id);
                if ($r['posted'] ?? false) {
                    $this->line("  ws={$w->id} {$w->name}: posted retro, {$r['pivots']} pivots queued");
                    if (!$force) {
                        try {
                            $tz = $w->timezone ?: 'UTC';
                            $weekKey = \Carbon\Carbon::now($tz)->format('o-\WW');
                            \Illuminate\Support\Facades\Cache::put("ws:{$w->id}:last_weekly_review_local_week", $weekKey, now()->addDays(120));
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
