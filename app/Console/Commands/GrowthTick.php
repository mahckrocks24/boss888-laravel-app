<?php

namespace App\Console\Commands;

use App\Core\Growth\CheckinService;
use App\Core\Growth\SignalService;
use App\Core\Growth\WatchService;
use App\Jobs\GrowthReactJob;
use App\Jobs\WatchRunJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WATCH-1 (RFC-0019):
 *   growth:tick --signals   every 10 min: collect what happened inside each business; Sarah reacts to anything new
 *   growth:tick --watch     hourly: run the watches the owners approved that are due; ask (once) where not asked yet
 *   growth:tick --checkins  hourly: Sarah's afternoon / night check-ins and the Friday feedback, in local time
 */
class GrowthTick extends Command
{
    protected $signature = 'growth:tick {--signals} {--watch} {--checkins} {--workspace= : one workspace only} {--force-checkin= : afternoon|night|weekly_feedback (testing)}';
    protected $description = 'Sarah watches and adapts: signals, market watch, check-ins';

    public function handle(SignalService $signals, WatchService $watch, CheckinService $checkins): int
    {
        $one = $this->option('workspace') ? (int) $this->option('workspace') : null;
        if ($this->option('signals')) {
            $q = DB::table('workspaces')->where('onboarded', 1);
            if ($one) $q->where('id', $one);
            $n = 0;
            foreach ($q->pluck('id') as $wsId) {
                try { $made = $signals->collect((int) $wsId); } catch (\Throwable $e) { $this->warn("ws {$wsId}: " . $e->getMessage()); continue; }
                // react to anything new (fresh or left from a quiet hour) — per business
                $pending = DB::table('growth_signals')->where('workspace_id', $wsId)->where('status', 'new')->select('business_id')->distinct()->pluck('business_id');
                foreach ($pending as $biz) { GrowthReactJob::dispatch((int) $wsId, $biz ? (int) $biz : null)->delay(now()->addSeconds(70)); $n++; }
            }
            $this->info("signals: queued {$n} reactions");
        }
        if ($this->option('watch')) {
            $due = $watch->due();
            foreach ($due as $i => $id) { if ($one && (int) DB::table('business_watch')->where('id', $id)->value('workspace_id') !== $one) continue; WatchRunJob::dispatch($id)->delay(now()->addSeconds(30 * $i)); }
            $asked = 0;
            $q = DB::table('workspaces')->where('onboarded', 1)->where('proactive_enabled', 1);
            if ($one) $q->where('id', $one);
            foreach ($q->get(['id', 'timezone']) as $ws) {
                try { $h = \Carbon\Carbon::now($ws->timezone ?: 'UTC')->hour; } catch (\Throwable $e) { $h = (int) now()->hour; }
                if ($h < 10 || $h > 17) continue;   // ask in working hours
                try { if ($watch->ask((int) $ws->id)) $asked++; } catch (\Throwable $e) { $this->warn("ask ws {$ws->id}: " . $e->getMessage()); }
            }
            $this->info('watch: ' . count($due) . " due, asked {$asked}");
        }
        if ($this->option('checkins')) {
            $sent = $checkins->tick($one, $this->option('force-checkin') ?: null);
            $this->info('check-ins: ' . json_encode($sent));
        }
        return self::SUCCESS;
    }
}
