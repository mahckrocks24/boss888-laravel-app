<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\QueueControlService;
use App\Services\ConnectorCircuitBreakerService;
use App\Models\Task;
use App\Models\TaskEvent;

class QueueHealthReportCommand extends Command
{
    protected $signature = 'boss888:queue-health';

    protected $description = 'Output queue health report: stuck tasks, circuits, pressure, throttling';

    public function handle(
        QueueControlService $queueControl,
        ConnectorCircuitBreakerService $circuitBreaker,
    ): int {
        $this->info('═══════════════════════════════════════════');
        $this->info('  Boss888 Queue Health Report');
        $this->info('  ' . now()->toIso8601String());
        $this->info('═══════════════════════════════════════════');

        // Queue metrics
        $metrics = $queueControl->getMetrics();
        $this->newLine();
        $this->info('── Queue Pressure ──');
        $this->table(
            ['Metric', 'Count'],
            collect($metrics)->map(fn ($v, $k) => [$k, $v])->toArray()
        );

        // Stale tasks
        $stale = $queueControl->findStaleTasks();
        $this->newLine();
        $this->info("── Stale Tasks: {$stale->count()} ──");
        if ($stale->count() > 0) {
            $this->table(
                ['ID', 'Action', 'Started', 'Age (s)'],
                $stale->map(fn ($t) => [
                    $t->id, $t->action,
                    $t->started_at?->toDateTimeString(),
                    now()->diffInSeconds($t->started_at),
                ])->toArray()
            );
        }

        // Circuit breakers
        $circuits = $circuitBreaker->getAllStatuses();
        $this->newLine();
        $this->info('── Circuit Breakers ──');
        $this->table(
            ['Connector', 'State', 'Failures', 'Threshold'],
            collect($circuits)->map(fn ($s) => [
                $s['connector'], $s['state'], $s['failure_count'], $s['threshold'],
            ])->toArray()
        );

        // Throttled workspaces
        $throttled = $queueControl->getThrottledWorkspaces();
        $this->newLine();
        $this->info("── Throttled Workspaces: " . count($throttled) . ' ──');
        if (count($throttled) > 0) {
            $this->table(
                ['Workspace ID', 'Running Tasks'],
                collect($throttled)->map(fn ($count, $wsId) => [$wsId, $count])->toArray()
            );
        }

        // Verification failures (last 24h)
        $verifyFails = TaskEvent::where('event', 'step_verified')
            ->where('created_at', '>=', now()->subDay())
            ->whereRaw("JSON_EXTRACT(data_json, '$.verified') = false OR message LIKE '%FAILED%'")
            ->count();

        $this->newLine();
        $this->info("── Verification Failures (24h): {$verifyFails} ──");

        $this->newLine();
        $this->info('Report complete.');

        return 0;
    }
}
