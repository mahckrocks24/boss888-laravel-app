<?php

namespace App\Engines\Infrastructure\Console;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Models\InfraMonitorDaily;
use App\Engines\Infrastructure\Models\InfraMonitorResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily monitor rollup (Phase 3B) — the retention/archival foundation.
 *
 * Aggregates the raw time series (infra_monitor_results) into one row per check
 * per day (infra_monitor_daily). Long-term trends then read the compact rollup,
 * so the raw rows can be pruned on a retention window without losing history.
 * This is what lets monitoring scale to millions of events.
 *
 * Idempotent: re-running a day recomputes it (updateOrCreate). Read-only over the
 * raw series; mutates no customer infrastructure.
 */
class RollupMonitorDaily extends Command
{
    protected $signature = 'infra:rollup-monitor-daily {--days=2 : How many trailing days to roll up}';

    protected $description = 'Roll up raw monitor results into daily aggregates for long-term intelligence.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $rolled = 0;

        $checks = InfraMonitorCheck::withoutGlobalScopes()->withTrashed()->get(['id', 'workspace_id']);

        foreach ($checks as $check) {
            WorkspaceContext::run((int) $check->workspace_id, function () use ($check, $days, &$rolled) {
                for ($d = 0; $d < $days; $d++) {
                    $day = now()->subDays($d)->toDateString();

                    $agg = InfraMonitorResult::where('monitor_check_id', $check->id)
                        ->whereDate('checked_at', $day)
                        ->selectRaw('COUNT(*) total')
                        ->selectRaw("SUM(status IN ('up','degraded')) up_ct")
                        ->selectRaw("SUM(status='down') down_ct")
                        ->selectRaw("SUM(status='degraded') deg_ct")
                        ->selectRaw('AVG(response_ms) avg_ms')
                        ->selectRaw('MAX(response_ms) max_ms')
                        ->first();

                    if (!$agg || (int) $agg->total === 0) {
                        continue;
                    }

                    $total = (int) $agg->total;
                    $up    = (int) $agg->up_ct;

                    InfraMonitorDaily::updateOrCreate(
                        ['monitor_check_id' => $check->id, 'day' => $day],
                        [
                            'workspace_id'    => $check->workspace_id,
                            'checks'          => $total,
                            'up'              => $up,
                            'down'            => (int) $agg->down_ct,
                            'degraded'        => (int) $agg->deg_ct,
                            'uptime_pct'      => round($up / $total * 100, 4),
                            'avg_response_ms' => $agg->avg_ms !== null ? (int) round($agg->avg_ms) : null,
                            'max_response_ms' => $agg->max_ms !== null ? (int) $agg->max_ms : null,
                        ]
                    );
                    $rolled++;
                }
            });
        }

        $this->info("Rolled up {$rolled} check-day aggregate(s).");

        return self::SUCCESS;
    }
}
