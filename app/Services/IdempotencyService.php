<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class IdempotencyService
{
    private string $lockPrefix = 'idem_lock:';
    private int $lockTtl;

    public function __construct()
    {
        $this->lockTtl = (int) config('execution.idempotency.lock_ttl', 300);
    }

    /**
     * Generate an idempotency key for a task.
     * Deterministic: same workspace + action + payload = same key.
     */
    public function generateKey(int $workspaceId, string $action, array $payload): string
    {
        $normalized = json_encode($payload, JSON_SORT_KEYS);
        return hash('sha256', "{$workspaceId}:{$action}:{$normalized}");
    }

    /**
     * Generate an execution hash for a specific step within a task.
     */
    public function generateStepHash(int $taskId, string $action, int $step): string
    {
        return hash('sha256', "{$taskId}:{$action}:{$step}");
    }

    /**
     * Check if a task with this idempotency key already completed successfully.
     * Returns the stored result if so, null otherwise.
     */
    public function checkDuplicate(string $idempotencyKey): ?array
    {
        $existing = Task::where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            return [
                'duplicate' => true,
                'task_id' => $existing->id,
                'result' => $existing->result_json,
                'completed_at' => $existing->completed_at?->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Acquire an execution lock. Prevents concurrent execution of the same task.
     * Returns true if lock acquired, false if already locked (execution in progress).
     */
    public function acquireLock(string $idempotencyKey): bool
    {
        $lockKey = $this->lockPrefix . $idempotencyKey;
        return Cache::add($lockKey, now()->toIso8601String(), $this->lockTtl);
    }

    /**
     * Release execution lock.
     */
    public function releaseLock(string $idempotencyKey): void
    {
        Cache::forget($this->lockPrefix . $idempotencyKey);
    }

    /**
     * Check if an execution lock is currently held.
     */
    public function isLocked(string $idempotencyKey): bool
    {
        return Cache::has($this->lockPrefix . $idempotencyKey);
    }

    /**
     * Check if a step within a multi-step task already succeeded.
     * Uses cache for fast lookups during execution.
     */
    public function checkStepCompleted(string $executionHash): ?array
    {
        return Cache::get("step_result:{$executionHash}");
    }

    /**
     * Record a step completion for idempotent replay.
     */
    public function recordStepResult(string $executionHash, array $result): void
    {
        // Keep step results cached for 24h (covers retries)
        Cache::put("step_result:{$executionHash}", $result, 86400);
    }

    /**
     * Ensure a task has an idempotency key. Generate if missing.
     */
    public function ensureKey(Task $task): string
    {
        if ($task->idempotency_key) {
            return $task->idempotency_key;
        }

        $key = $this->generateKey(
            $task->workspace_id,
            $task->action,
            $task->payload_json ?? []
        );

        $task->update(['idempotency_key' => $key]);
        return $key;
    }
}
