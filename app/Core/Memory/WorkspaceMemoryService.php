<?php

namespace App\Core\Memory;

use App\Models\WorkspaceMemory;
use App\Models\Task;
use App\Models\AuditLog;

class WorkspaceMemoryService
{
    public function get(int $workspaceId, string $key): mixed
    {
        $mem = WorkspaceMemory::where('workspace_id', $workspaceId)
            ->where('key', $key)
            ->first();

        if (! $mem) {
            return null;
        }

        if ($mem->ttl && $mem->updated_at->addSeconds($mem->ttl)->isPast()) {
            $mem->delete();
            return null;
        }

        return $mem->value_json;
    }

    public function set(int $workspaceId, string $key, mixed $value, ?int $ttl = null): WorkspaceMemory
    {
        return WorkspaceMemory::updateOrCreate(
            ['workspace_id' => $workspaceId, 'key' => $key],
            ['value_json' => is_array($value) ? $value : ['value' => $value], 'ttl' => $ttl]
        );
    }

    public function forget(int $workspaceId, string $key): void
    {
        WorkspaceMemory::where('workspace_id', $workspaceId)
            ->where('key', $key)
            ->delete();
    }

    public function all(int $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkspaceMemory::where('workspace_id', $workspaceId)->get();
    }

    /**
     * Build execution context for the Orchestrator / ParameterResolver.
     *
     * Returns:
     *  - relevant memory keys (brand, defaults, preferences)
     *  - recent activity summary
     *  - agent context (last 5 agent tasks)
     */
    public function getContextForTask(int $workspaceId): array
    {
        // Relevant memory keys
        $memoryKeys = [
            'brand_voice', 'brand_name', 'brand_colors',
            'target_audience', 'default_language', 'default_social_platform',
            'seo_focus_keywords', 'email_signature',
        ];

        $memory = [];
        foreach ($memoryKeys as $key) {
            $val = $this->get($workspaceId, $key);
            if ($val !== null) {
                $memory[$key] = $val;
            }
        }

        // Recent activity (last 10 completed tasks)
        $recentTasks = Task::where('workspace_id', $workspaceId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get(['id', 'engine', 'action', 'completed_at'])
            ->toArray();

        // Recent audit entries (last 10)
        $recentAudit = AuditLog::where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['action', 'entity_type', 'entity_id', 'created_at'])
            ->toArray();

        // Agent context: last 5 agent-sourced tasks
        $agentTasks = Task::where('workspace_id', $workspaceId)
            ->where('source', 'agent')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'engine', 'action', 'assigned_agents_json', 'status', 'created_at'])
            ->toArray();

        return [
            'memory' => $memory,
            'recent_activity' => $recentTasks,
            'recent_audit' => $recentAudit,
            'agent_context' => $agentTasks,
        ];
    }
}
