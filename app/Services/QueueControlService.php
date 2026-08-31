<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Cache;

class QueueControlService
{
    /**
     * Determine queue name based on priority.
     */
    public function resolveQueue(Task $task): string
    {
        return match ($task->priority) {
            'urgent' => 'tasks-high',
            'high' => 'tasks-high',
            'normal' => 'tasks',
            'low' => 'tasks-low',
            default => 'tasks',
        };
    }

    /**
     * Check if workspace has capacity (concurrency cap).
     */
    public function canWorkspaceExecute(int $workspaceId): bool
    {
        $cap = (int) config('queue_control.workspace_concurrency', 10);
        $running = Task::where('workspace_id', $workspaceId)
            ->where('status', 'running')
            ->count();

        return $running < $cap;
    }

    /**
     * Check if an agent has capacity (concurrency cap).
     */
    public function canAgentExecute(string $agentSlug): bool
    {
        $cap = (int) config('queue_control.agent_concurrency', 5);
        $running = Task::where('source', 'agent')
            ->whereJsonContains('assigned_agents_json', $agentSlug)
            ->where('status', 'running')
            ->count();

        return $running < $cap;
    }

    /**
     * Find stale tasks (stuck in running beyond threshold).
     */
    public function findStaleTasks(?int $wsId = null): \Illuminate\Database\Eloquent\Collection
    {
        $timeout = (int) config('queue_control.stale_task_timeout', 600); // 10 min

        return Task::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($timeout))
            ->when($wsId !== null, fn ($q) => $q->where('workspace_id', $wsId))   // SEC-2
            ->get();
    }

    /**
     * Get queue pressure metrics.
     */
    /**
     * SEC-2: $wsId scopes the metrics to one workspace. Operator surfaces (the CLI report, system health,
     * the validation report) pass nothing and keep the platform view; anything a CUSTOMER can reach must pass
     * their workspace, or it reports other tenants' volume as if it were theirs.
     */
    public function getMetrics(?int $wsId = null): array
    {
        $scope = fn ($q) => $wsId === null ? $q : $q->where('workspace_id', $wsId);

        return [
            'pending' => $scope(Task::where('status', 'pending'))->count(),
            'awaiting_approval' => $scope(Task::where('status', 'awaiting_approval'))->count(),
            'queued' => $scope(Task::where('status', 'queued'))->count(),
            'running' => $scope(Task::where('status', 'running'))->count(),
            'stale' => $this->findStaleTasks($wsId)->count(),
            'failed_24h' => $scope(Task::where('status', 'failed'))
                ->where('created_at', '>=', now()->subDay())->count(),
            'completed_24h' => $scope(Task::where('status', 'completed'))
                ->where('completed_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Get throttled workspaces (at or over concurrency cap).
     */
    public function getThrottledWorkspaces(): array
    {
        $cap = (int) config('queue_control.workspace_concurrency', 10);

        return Task::where('status', 'running')
            ->selectRaw('workspace_id, COUNT(*) as running_count')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) >= ?', [$cap])
            ->pluck('running_count', 'workspace_id')
            ->toArray();
    }
}
