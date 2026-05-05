<?php

namespace App\Http\Controllers\Api\Debug;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\TaskSystem\TaskService;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\ValidationReportService;
use Illuminate\Support\Facades\Cache;

class DebugScenarioController
{
    public function __construct(
        private TaskService $taskService,
        private ConnectorCircuitBreakerService $circuitBreaker,
        private ValidationReportService $reportService,
    ) {}

    /**
     * POST /api/debug/run-scenario
     *
     * Available scenarios:
     *   duplicate_execution, failure_retry, circuit_breaker, rate_limit
     */
    public function runScenario(Request $request): JsonResponse
    {
        if (app()->environment('production') && ! config('execution.debug_enabled', false)) {
            return response()->json(['error' => 'Debug routes disabled in production'], 403);
        }

        $scenario = $request->input('scenario');
        $workspaceId = $request->attributes->get('workspace_id');

        return match ($scenario) {
            'duplicate_execution' => $this->scenarioDuplicate($workspaceId),
            'failure_retry' => $this->scenarioFailureRetry($workspaceId),
            'circuit_breaker' => $this->scenarioCircuitBreaker(),
            'rate_limit' => $this->scenarioRateLimit($workspaceId),
            default => response()->json(['error' => 'Unknown scenario', 'available' => [
                'duplicate_execution', 'failure_retry', 'circuit_breaker', 'rate_limit',
            ]], 400),
        };
    }

    /**
     * GET /api/debug/validation-report
     */
    public function validationReport(): JsonResponse
    {
        if (app()->environment('production') && ! config('execution.debug_enabled', false)) {
            return response()->json(['error' => 'Debug routes disabled in production'], 403);
        }

        return response()->json($this->reportService->generate());
    }

    private function scenarioDuplicate(int $workspaceId): JsonResponse
    {
        $payload = ['title' => 'Debug Duplicate Test', 'content' => '<p>Test</p>'];

        $task1 = $this->taskService->create($workspaceId, [
            'engine' => 'content',
            'action' => 'create_post',
            'payload' => $payload,
            'source' => 'manual',
        ]);

        $task2 = $this->taskService->create($workspaceId, [
            'engine' => 'content',
            'action' => 'create_post',
            'payload' => $payload,
            'source' => 'manual',
        ]);

        return response()->json([
            'scenario' => 'duplicate_execution',
            'task_1_id' => $task1->id,
            'task_1_idempotency' => $task1->idempotency_key,
            'task_2_id' => $task2->id,
            'task_2_idempotency' => $task2->idempotency_key,
            'same_key' => $task1->idempotency_key === $task2->idempotency_key,
            'note' => 'Both tasks have same idempotency key — second execution will skip via duplicate check',
        ]);
    }

    private function scenarioFailureRetry(int $workspaceId): JsonResponse
    {
        $task = $this->taskService->create($workspaceId, [
            'engine' => 'content',
            'action' => 'create_post',
            'payload' => ['title' => 'Retry Test', 'content' => '<p>Retry</p>'],
            'source' => 'manual',
        ]);

        return response()->json([
            'scenario' => 'failure_retry',
            'task_id' => $task->id,
            'status' => $task->status,
            'note' => 'Task dispatched. Retry logic activates if connector fails (up to 4 retries with backoff).',
        ]);
    }

    private function scenarioCircuitBreaker(): JsonResponse
    {
        $connector = 'creative';
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        for ($i = 0; $i < $threshold; $i++) {
            $this->circuitBreaker->recordFailure($connector);
        }

        return response()->json([
            'scenario' => 'circuit_breaker',
            'connector' => $connector,
            'status' => $this->circuitBreaker->getStatus($connector),
            'note' => "Recorded {$threshold} failures — circuit now open. New tasks to {$connector} will be blocked.",
        ]);
    }

    private function scenarioRateLimit(int $workspaceId): JsonResponse
    {
        $limit = config('execution.rate_limits.workspace.per_minute', 30);
        $key = "ratelimit:ws:{$workspaceId}:per_minute";
        Cache::put($key, $limit, 60);

        return response()->json([
            'scenario' => 'rate_limit',
            'workspace_id' => $workspaceId,
            'limit_per_minute' => $limit,
            'current_count' => $limit,
            'note' => 'Workspace per-minute limit exhausted. New tasks will be blocked until counter resets.',
        ]);
    }
}
