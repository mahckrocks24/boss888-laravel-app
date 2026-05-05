<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Models\Approval;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;
use App\Core\EngineKernel\CapabilityMapService;

class TaskService
{
    public function __construct(
        private TaskDispatcher $dispatcher,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private CapabilityMapService $capabilityMap,
    ) {}

    /**
     * Create a task. Approval enforcement is MANDATORY.
     *
     * Approval modes (from CapabilityMap):
     *   auto      → dispatch immediately
     *   review    → block until human approves
     *   protected → ALWAYS require approval, no bypass
     */
    public function create(int $workspaceId, array $data): Task
    {
        $action = $data['action'];

        // Resolve approval mode from capability map
        $approvalMode = $this->capabilityMap->getApprovalMode($action);
        $creditCost = $data['credit_cost'] ?? $this->capabilityMap->getCreditCost($action);

        // Determine if approval is required — NO BYPASS for protected
        $requiresApproval = match ($approvalMode) {
            'auto' => false,
            'review' => $data['requires_approval'] ?? true,   // default to requiring
            'protected' => true,                                // ALWAYS
            default => true,
        };

        // Generate idempotency key
        // FIX 2026-04-11: JSON_SORT_KEYS is not a real PHP constant. Use ksort
        // for deterministic hashing (top-level keys only, sufficient for the
        // shallow payloads that engine actions produce).
        $payload = $data['payload'] ?? [];
        $payloadForHash = $payload;
        if (is_array($payloadForHash)) ksort($payloadForHash);
        $idemKey = $data['idempotency_key'] ?? hash('sha256',
            "{$workspaceId}:{$action}:" . json_encode($payloadForHash));

        $task = Task::create([
            'workspace_id' => $workspaceId,
            'engine' => $data['engine'] ?? $this->resolveEngineSlug($action),
            'action' => $action,
            'payload_json' => $payload ?: null,
            'source' => $data['source'] ?? 'manual',
            'assigned_agents_json' => $data['assigned_agents'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'requires_approval' => $requiresApproval,
            'credit_cost' => $creditCost,
            'idempotency_key' => $idemKey,
        ]);

        if ($requiresApproval) {
            $task->update(['approval_status' => 'pending']);

            Approval::create([
                'workspace_id' => $workspaceId,
                'task_id' => $task->id,
                'status' => 'pending',
            ]);

            $this->notifications->send($workspaceId, 'task', 'task.approval_required', [
                'task_id' => $task->id,
                'engine' => $task->engine,
                'action' => $task->action,
                'approval_mode' => $approvalMode,
            ]);
        } else {
            // Auto mode — dispatch immediately
            $this->dispatcher->dispatch($task);
        }

        $this->auditLog->log($workspaceId, null, 'task.created', 'Task', $task->id, [
            'engine' => $task->engine,
            'action' => $task->action,
            'source' => $task->source,
            'approval_mode' => $approvalMode,
            'requires_approval' => $requiresApproval,
        ]);

        return $task;
    }

    public function find(int $taskId): ?Task
    {
        return Task::find($taskId);
    }

    public function listForWorkspace(int $workspaceId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Task::where('workspace_id', $workspaceId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        return $query->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get();
    }

    public function markRunning(Task $task): void
    {
        // ENFORCEMENT: verify approval before running
        if ($task->requires_approval && $task->approval_status !== 'approved') {
            throw new \RuntimeException(
                "Task {$task->id} requires approval (current: {$task->approval_status}). Cannot run."
            );
        }

        $task->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(Task $task, ?array $result = null): void
    {
        $task->update([
            'status' => 'completed',
            'result_json' => $result,
            'completed_at' => now(),
        ]);

        $this->notifications->send($task->workspace_id, 'task', 'task.completed', [
            'task_id' => $task->id,
            'action' => $task->action,
        ]);
    }

    public function markFailed(Task $task, string $error): void
    {
        $task->increment('retry_count');
        $maxRetries = 4;

        if ($task->retry_count < $maxRetries) {
            $task->update(['status' => 'queued']);
            $this->dispatcher->dispatch($task);
        } else {
            $task->update([
                'status' => 'failed',
                'error_text' => $error,
                'completed_at' => now(),
            ]);
            $this->notifications->send($task->workspace_id, 'task', 'task.failed', [
                'task_id' => $task->id,
                'error' => $error,
            ]);
        }
    }

    private function resolveEngineSlug(string $action): string
    {
        $cap = $this->capabilityMap->resolve($action);
        return $cap['engine'] ?? 'unknown';
    }

    /**
     * Requeue a task with optional delay (for throttling/retry).
     */
    public function requeue(Task $task, int $delaySeconds = 5): void
    {
        $task->update(['status' => 'queued']);
        $this->dispatcher->dispatchWithDelay($task, $delaySeconds);
    }
}
