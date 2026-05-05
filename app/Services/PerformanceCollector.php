<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PerformanceCollector
{
    private string $prefix = 'perf:';

    /**
     * Collect comprehensive performance metrics.
     */
    public function collect(?\DateTimeInterface $since = null): array
    {
        $since = $since ?? now()->subHour();

        return [
            'period' => [
                'since' => $since->format('Y-m-d H:i:s'),
                'until' => now()->format('Y-m-d H:i:s'),
            ],
            'task_metrics' => $this->taskMetrics($since),
            'queue_metrics' => $this->queueMetrics(),
            'credit_metrics' => $this->creditMetrics($since),
            'connector_metrics' => $this->connectorMetrics($since),
            'idempotency_metrics' => $this->idempotencyMetrics($since),
            'infrastructure' => $this->infraMetrics(),
        ];
    }

    private function taskMetrics(\DateTimeInterface $since): array
    {
        $tasks = Task::where('created_at', '>=', $since);

        $total = (clone $tasks)->count();
        $completed = (clone $tasks)->where('status', 'completed')->count();
        $failed = (clone $tasks)->where('status', 'failed')->count();
        $blocked = (clone $tasks)->where('status', 'blocked')->count();
        $degraded = (clone $tasks)->where('status', 'degraded')->count();

        // Average execution time (completed tasks only)
        $avgExecTime = Task::where('created_at', '>=', $since)
            ->where('status', 'completed')
            ->whereNotNull('execution_started_at')
            ->whereNotNull('execution_finished_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, execution_started_at, execution_finished_at)) as avg_time')
            ->value('avg_time');

        // Average queue wait time
        $avgQueueWait = Task::where('created_at', '>=', $since)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, started_at)) as avg_wait')
            ->value('avg_wait');

        // Retry frequency
        $totalRetries = Task::where('created_at', '>=', $since)
            ->sum('retry_count');

        $failureRate = $total > 0 ? round(($failed / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'blocked' => $blocked,
            'degraded' => $degraded,
            'failure_rate_percent' => $failureRate,
            'avg_execution_time_seconds' => round((float) ($avgExecTime ?? 0), 2),
            'avg_queue_wait_seconds' => round((float) ($avgQueueWait ?? 0), 2),
            'total_retries' => (int) $totalRetries,
        ];
    }

    private function queueMetrics(): array
    {
        $pending = Task::where('status', 'queued')->count();
        $running = Task::where('status', 'running')->count();

        $staleTimeout = config('queue_control.stale_task_timeout', 600);
        $stale = Task::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($staleTimeout))
            ->count();

        // Redis queue lengths
        $queueLengths = [];
        foreach (['tasks-high', 'tasks', 'tasks-low', 'default'] as $queue) {
            try {
                $queueLengths[$queue] = (int) Redis::connection()->llen("queues:{$queue}");
            } catch (\Throwable) {
                $queueLengths[$queue] = -1; // Redis unavailable
            }
        }

        return [
            'pending_tasks' => $pending,
            'running_tasks' => $running,
            'stale_tasks' => $stale,
            'redis_queue_lengths' => $queueLengths,
        ];
    }

    private function creditMetrics(\DateTimeInterface $since): array
    {
        $reserved = CreditTransaction::where('created_at', '>=', $since)
            ->where('type', 'reserve')->where('reservation_status', 'pending')->count();

        $committed = CreditTransaction::where('created_at', '>=', $since)
            ->where('type', 'commit')->count();

        $released = CreditTransaction::where('created_at', '>=', $since)
            ->where('type', 'release')->count();

        $orphaned = CreditTransaction::where('type', 'reserve')
            ->where('reservation_status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))
            ->count();

        return [
            'pending_reservations' => $reserved,
            'committed' => $committed,
            'released' => $released,
            'orphaned_reservations' => $orphaned,
            'integrity' => $orphaned === 0 ? 'clean' : 'orphans_detected',
        ];
    }

    private function connectorMetrics(\DateTimeInterface $since): array
    {
        $connectors = ['creative', 'email', 'social'];
        $metrics = [];

        foreach ($connectors as $c) {
            $executed = TaskEvent::where('created_at', '>=', $since)
                ->where('connector', $c)
                ->where('event', 'step_executed')
                ->count();

            $verified = TaskEvent::where('created_at', '>=', $since)
                ->where('connector', $c)
                ->where('event', 'step_verified')
                ->count();

            $verifyFailed = TaskEvent::where('created_at', '>=', $since)
                ->where('connector', $c)
                ->where('event', 'step_verified')
                ->where('message', 'LIKE', 'Verification FAILED%')
                ->count();

            $circuitStatus = Cache::get("circuit:{$c}:state", 'closed');
            $failures = (int) Cache::get("circuit:{$c}:failures", 0);

            $metrics[$c] = [
                'executions' => $executed,
                'verifications' => $verified,
                'verification_failures' => $verifyFailed,
                'verification_failure_rate' => $verified > 0 ? round(($verifyFailed / $verified) * 100, 2) : 0,
                'circuit_state' => $circuitStatus,
                'circuit_failures' => $failures,
            ];
        }

        return $metrics;
    }

    private function idempotencyMetrics(\DateTimeInterface $since): array
    {
        $skips = TaskEvent::where('created_at', '>=', $since)
            ->where('event', 'idempotent_skip')
            ->count();

        $lockBlocks = TaskEvent::where('created_at', '>=', $since)
            ->where('event', 'lock_blocked')
            ->count();

        return [
            'duplicate_skips' => $skips,
            'lock_blocks' => $lockBlocks,
            'collision_rate' => $skips + $lockBlocks,
        ];
    }

    private function infraMetrics(): array
    {
        // DB check
        $dbOk = false;
        $dbLatency = 0;
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $dbLatency = round((microtime(true) - $start) * 1000, 2);
            $dbOk = true;
        } catch (\Throwable) {}

        // Redis check
        $redisOk = false;
        $redisLatency = 0;
        try {
            $start = microtime(true);
            Cache::put('infra_ping', 1, 5);
            Cache::get('infra_ping');
            $redisLatency = round((microtime(true) - $start) * 1000, 2);
            $redisOk = true;
        } catch (\Throwable) {}

        return [
            'database' => ['connected' => $dbOk, 'latency_ms' => $dbLatency],
            'redis' => ['connected' => $redisOk, 'latency_ms' => $redisLatency],
            'queue_driver' => config('queue.default'),
            'cache_driver' => config('cache.default'),
            'workers_detected' => $this->detectWorkers(),
        ];
    }

    private function detectWorkers(): int
    {
        // Approximate worker count by checking recent job processing
        try {
            $recentProcessed = Task::where('status', 'completed')
                ->where('completed_at', '>=', now()->subMinutes(5))
                ->count();
            // If tasks are completing, workers are alive
            return $recentProcessed > 0 ? max(1, (int) ceil($recentProcessed / 10)) : 0;
        } catch (\Throwable) {
            return -1;
        }
    }
}
