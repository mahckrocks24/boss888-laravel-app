<?php

namespace App\Console\Commands;

use App\Core\Strategy\SarahMonthlyOrchestrator;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * 2026-05-24 FIX 47 — Sarah's monthly strategy meeting.
 * 2026-05-24 FIX 53B — timezone awareness. Cron fires HOURLY; this
 * command gates each workspace to the 1st-of-month 10:00 in workspace
 * local time, with workspace-local-month idempotency.
 */
class SarahMonthlyStrategyCommand extends Command
{
    protected $signature = 'sarah:monthly-strategy {--workspace= : single workspace_id} {--dry-run : log only} {--force : bypass timezone + idempotency gates}';
    protected $description = 'Sarah runs the monthly multi-agent strategy meeting + posts 30-day plan (TZ-gated to 1st 10:00 workspace-local)';

    public function handle(SarahMonthlyOrchestrator $orchestrator): int
    {
        $oneWs = $this->option('workspace');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Workspace::query()
            ->where('onboarded', true)
            ->where('proactive_enabled', true);
        if ($oneWs) $query->where('id', (int) $oneWs);

        $workspaces = $query->get();
        $this->info('Running monthly strategy for ' . $workspaces->count() . ' workspace(s)' . ($dryRun ? ' [DRY RUN]' : '') . ($force ? ' [FORCE]' : ''));

        $ok = 0; $fail = 0; $skipped = 0;
        foreach ($workspaces as $w) {
            if (!$force && !$dryRun) {
                $tz = $w->timezone ?: 'UTC';
                try { $localNow = \Carbon\Carbon::now($tz); }
                catch (\Throwable $e) { $localNow = \Carbon\Carbon::now('UTC'); }
                if ($localNow->day !== 1 || $localNow->hour !== 10) { $skipped++; continue; }

                $monthKey = $localNow->format('Y-m');
                $memKey = "ws:{$w->id}:last_monthly_strategy_local_month";
                try { $last = \Illuminate\Support\Facades\Cache::get($memKey); }
                catch (\Throwable $e) { $last = null; }
                if ($last === $monthKey) { $skipped++; continue; }
            }

            try {
                if ($dryRun) { $this->line("  ws={$w->id}: dry-run"); continue; }
                $r = $orchestrator->runMonthly((int) $w->id);
                if ($r['posted'] ?? false) {
                    $this->line("  ws={$w->id} {$w->name}: 30-day plan #{$r['plan_id']} posted");
                    if (!$force) {
                        try {
                            $tz = $w->timezone ?: 'UTC';
                            $monthKey = \Carbon\Carbon::now($tz)->format('Y-m');
                            \Illuminate\Support\Facades\Cache::put("ws:{$w->id}:last_monthly_strategy_local_month", $monthKey, now()->addDays(370));
                        } catch (\Throwable $e) {}
                    }
                    $ok++;
                } else {
                    $this->warn("  ws={$w->id}: SKIPPED — " . ($r['reason'] ?? 'unknown'));
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
