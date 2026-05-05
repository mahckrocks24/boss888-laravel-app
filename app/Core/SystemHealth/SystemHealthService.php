<?php

namespace App\Core\SystemHealth;

use App\Models\EngineRegistry;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Connectors\ConnectorResolver;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\QueueControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SystemHealthService
{
    public function __construct(
        private ConnectorResolver $connectorResolver,
        private ConnectorCircuitBreakerService $circuitBreaker,
        private QueueControlService $queueControl,
    ) {}

    /**
     * Full system health summary for admin dashboard.
     */
    public function health(): array
    {
        $db = $this->checkDatabase();
        $cache = $this->checkCache();
        $connectorPings = $this->checkConnectors();
        $circuits = $this->circuitBreaker->getAllStatuses();
        $openCircuits = $this->circuitBreaker->getOpenCircuits();
        $queue = $this->queueControl->getMetrics();
        $throttled = $this->queueControl->getThrottledWorkspaces();
        $verifyFailRate = $this->verificationFailureRate();

        $hasOpenCircuits = count($openCircuits) > 0;
        $hasStale = ($queue['stale'] ?? 0) > 0;
        $dbOk = $db === 'connected';
        $cacheOk = $cache === 'connected';

        $overallStatus = match (true) {
            ! $dbOk => 'error',
            ! $cacheOk => 'error',
            $hasOpenCircuits && $hasStale => 'error',
            $hasOpenCircuits || $hasStale => 'degraded',
            default => 'ok',
        };

        return [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $db,
                'cache' => $cache,
                'connectors' => $connectorPings,
                'circuits' => $circuits,
                'open_circuits' => array_keys($openCircuits),
                'queue' => $queue,
                'throttled_workspaces' => $throttled,
                'stale_task_count' => $queue['stale'] ?? 0,
                'verification_failure_rate_24h' => $verifyFailRate,
                'degraded_flags' => array_filter([
                    'open_circuits' => $hasOpenCircuits,
                    'stale_tasks' => $hasStale,
                    'high_verify_failures' => $verifyFailRate > 20,
                    'throttling_active' => count($throttled) > 0,
                ]),
            ],
        ];
    }

    public function engines(): array
    {
        return EngineRegistry::all()->map(fn ($e) => [
            'slug' => $e->slug,
            'name' => $e->name,
            'version' => $e->version,
            'status' => $e->status,
        ])->toArray();
    }

    public function queueStatus(): array
    {
        return $this->queueControl->getMetrics();
    }

    public function checkConnectors(): array
    {
        return $this->connectorResolver->healthCheckAll();
    }

    /**
     * Verification failure rate in past 24h (percentage).
     */
    private function verificationFailureRate(): float
    {
        $total = TaskEvent::where('event', 'step_verified')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($total === 0) return 0.0;

        $failures = TaskEvent::where('event', 'step_verified')
            ->where('created_at', '>=', now()->subDay())
            ->where('message', 'LIKE', 'Verification FAILED%')
            ->count();

        return round(($failures / $total) * 100, 1);
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('SELECT 1');
            return 'connected';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkCache(): string
    {
        try {
            Cache::put('health_check', true, 5);
            return Cache::get('health_check') ? 'connected' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
