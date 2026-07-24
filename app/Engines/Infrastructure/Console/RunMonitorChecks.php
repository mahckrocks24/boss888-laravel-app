<?php

namespace App\Engines\Infrastructure\Console;

use App\Engines\Infrastructure\Services\InfrastructureMonitoringService;
use Illuminate\Console\Command;

/**
 * Scheduled monitoring runner (Phase 3A).
 *
 * Runs every enabled check across all workspaces, each in its own tenant context
 * so results and incidents stay isolated. Read-only: performs HTTP GETs only, and
 * mutates no customer infrastructure. Safe to run repeatedly; every run appends a
 * fresh observation to each check time series.
 */
class RunMonitorChecks extends Command
{
    protected $signature = 'infra:run-monitor-checks';

    protected $description = 'Run all enabled infrastructure monitoring checks (real HTTP, read-only).';

    public function handle(InfrastructureMonitoringService $monitoring): int
    {
        $counts = $monitoring->runAllDue();

        $this->info(sprintf(
            'Monitor sweep: %d checks (up=%d degraded=%d down=%d).',
            $counts['ran'], $counts['up'] ?? 0, $counts['degraded'] ?? 0, $counts['down'] ?? 0
        ));

        return self::SUCCESS;
    }
}
