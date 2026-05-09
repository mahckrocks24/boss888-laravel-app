<?php

namespace App\Core\Orchestration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * OrchestrationHealthService — aggregates orchestration metrics for the
 * admin dashboard. Pulls task histogram + agent perf from the DB, queue
 * depth from Redis (NOT the unused `jobs` table), and orphan count from
 * the state machine.
 */
class OrchestrationHealthService
{
    private const REDIS_PREFIX = 'levelup_database_';

    private const QUEUE_NAMES = ['tasks-high', 'tasks', 'tasks-low', 'default'];

    public function snapshot(?int $workspaceId = null): array
    {
        return [
            'task_stats'   => $this->taskStats($workspaceId),
            'agent_perf'   => $this->agentPerformance($workspaceId),
            'recent_tasks' => $this->recentTasks($workspaceId, 15),
            'queue_depth'  => $this->queueDepth(),
            'orphans'      => app(TaskStateMachine::class)->detectOrphans()->count(),
            'state_machine'=> [
                'transitions' => app(TaskStateMachine::class)->getTransitions(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function taskStats(?int $workspaceId = null): array
    {
        $q = DB::table('tasks')
            ->selectRaw('status, COUNT(*) as count, AVG(duration_ms) as avg_duration_ms, MAX(duration_ms) as max_duration_ms')
            ->groupBy('status')
            ->orderByRaw("FIELD(status, 'running','queued','pending','awaiting_approval','verifying','completed','failed','cancelled','blocked','degraded')");
        if ($workspaceId) $q->where('workspace_id', $workspaceId);
        return $q->get()->map(fn($r) => [
            'status'           => $r->status,
            'count'            => (int)$r->count,
            'avg_duration_ms'  => $r->avg_duration_ms === null ? null : (int)round($r->avg_duration_ms),
            'max_duration_ms'  => $r->max_duration_ms === null ? null : (int)$r->max_duration_ms,
        ])->all();
    }

    public function agentPerformance(?int $workspaceId = null): array
    {
        $q = DB::table('tasks')
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(assigned_agents_json, '$[0]')) as agent_slug,
                COUNT(*) as total,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                AVG(duration_ms) as avg_ms
            ")
            ->whereNotNull('assigned_agents_json')
            ->groupBy('agent_slug')
            ->orderByDesc('total');
        if ($workspaceId) $q->where('workspace_id', $workspaceId);
        $rows = $q->get();

        // Hydrate display name from agents table.
        $names = DB::table('agents')->pluck('name', 'slug')->all();

        return $rows->map(fn($r) => [
            'agent_slug' => $r->agent_slug,
            'agent_name' => $names[$r->agent_slug] ?? ucfirst((string)$r->agent_slug),
            'total'      => (int)$r->total,
            'completed'  => (int)$r->completed,
            'failed'     => (int)$r->failed,
            'avg_ms'     => $r->avg_ms === null ? null : (int)round($r->avg_ms),
        ])->all();
    }

    public function recentTasks(?int $workspaceId = null, int $limit = 15): array
    {
        $q = DB::table('tasks')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->select([
                'id', 'workspace_id', 'engine', 'action', 'status',
                'assigned_agents_json', 'duration_ms', 'retry_count',
                'error_text', 'created_at', 'updated_at', 'completed_at',
            ]);
        if ($workspaceId) $q->where('workspace_id', $workspaceId);
        return $q->get()->map(fn($r) => [
            'id'            => (int)$r->id,
            'workspace_id'  => (int)$r->workspace_id,
            'engine'        => $r->engine,
            'action'        => $r->action,
            'status'        => $r->status,
            'agent'         => $this->firstAgent($r->assigned_agents_json),
            'duration_ms'   => $r->duration_ms === null ? null : (int)$r->duration_ms,
            'retry_count'   => (int)($r->retry_count ?? 0),
            'error_text'    => $r->error_text,
            'created_at'    => $r->created_at,
            'updated_at'    => $r->updated_at,
            'completed_at'  => $r->completed_at,
        ])->all();
    }

    /**
     * Real queue depth from Redis. The `jobs` MySQL table is empty in
     * production (QUEUE_CONNECTION=redis), so the original spec's
     * SELECT COUNT(*) FROM jobs always returned 0.
     *
     * Laravel Redis queues use:
     *   - LIST  queues:{name}            ready jobs
     *   - ZSET  queues:{name}:delayed    delayed jobs
     *   - ZSET  queues:{name}:reserved   in-flight jobs
     */
    public function queueDepth(): array
    {
        $out = [];
        foreach (self::QUEUE_NAMES as $name) {
            $out[$name] = $this->queueSizeFor($name);
        }
        $out['failed_jobs_db'] = (int) DB::table('failed_jobs')->count();
        return $out;
    }

    private function queueSizeFor(string $name): array
    {
        try {
            $base    = self::REDIS_PREFIX . "queues:{$name}";
            $delayed = $base . ':delayed';
            $reserved= $base . ':reserved';
            return [
                'ready'    => $this->intFromRedis('LLEN',  [$base]),
                'delayed'  => $this->intFromRedis('ZCARD', [$delayed]),
                'reserved' => $this->intFromRedis('ZCARD', [$reserved]),
            ];
        } catch (\Throwable $e) {
            Log::warning("OrchestrationHealthService::queueSizeFor({$name}) failed: " . $e->getMessage());
            return ['ready' => null, 'delayed' => null, 'reserved' => null, 'error' => $e->getMessage()];
        }
    }

    private function intFromRedis(string $cmd, array $args): int
    {
        $v = Redis::connection()->command($cmd, $args);
        return is_numeric($v) ? (int)$v : 0;
    }

    private function firstAgent(?string $json): ?string
    {
        if (!$json) return null;
        $arr = json_decode($json, true);
        return is_array($arr) && !empty($arr) ? (string)$arr[0] : null;
    }
}
