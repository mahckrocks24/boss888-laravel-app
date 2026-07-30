<?php

namespace App\Core\Engineer888\Signals;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform workload — tasks and their outcomes.
 *
 * Counts failures over a 24h window rather than all-time, because an all-time
 * failure count only ever rises and therefore carries no signal. What matters
 * is "did anything fail since yesterday".
 */
final class WorkloadSignal implements Signal
{
    public function key(): string { return 'workload'; }
    public function label(): string { return 'Workload'; }

    public function collect(): array
    {
        if (! Schema::hasTable('tasks')) {
            return ['available' => false, 'reason' => 'tasks table absent'];
        }

        $since = now()->subDay();

        $byStatus = DB::table('tasks')
            ->where('created_at', '>=', $since)
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        $out = [
            'available'       => true,
            'window_hours'    => 24,
            'tasks_by_status' => $byStatus,
            'tasks_total'     => DB::table('tasks')->count(),
        ];

        // Stale tasks: running but untouched. The shape of INC-2026-001, where
        // failures accumulated in task records while failed_jobs stayed empty.
        if (Schema::hasColumn('tasks', 'updated_at')) {
            $out['tasks_stale_running'] = DB::table('tasks')
                ->whereIn('status', ['running', 'in_progress', 'processing'])
                ->where('updated_at', '<', now()->subHours(2))
                ->count();
        }

        foreach (['task_events', 'audit_logs', 'api_usage_logs'] as $t) {
            if (Schema::hasTable($t)) {
                $out[$t . '_total'] = DB::table($t)->count();
            }
        }

        return $out;
    }
}
