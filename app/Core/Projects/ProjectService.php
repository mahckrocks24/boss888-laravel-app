<?php

namespace App\Core\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProjectService — CRUD + lifecycle for Projects.
 *
 *   Lifecycle: proposed → active → (paused?) → completed → archived
 *              cancelled is a terminal state from any non-archived state
 *
 * Pure data plane in Phase 1. KPI auto-gen by Sarah lands in Phase 2.
 * Disposition + scoring lands in Phase 4.
 */
class ProjectService
{
    /** Allowed status values. */
    public const STATUSES = ['proposed', 'active', 'paused', 'completed', 'archived', 'cancelled'];

    /** Allowed source types. */
    public const SOURCE_TYPES = ['direct', 'strategy_room', 'sarah_campaign', 'content_pack'];

    /**
     * Create a new project. Defaults to 'proposed' status until first
     * transition to 'active' (typically when its execution_plan starts).
     *
     * Required: name, goal
     * Optional: description, source_type, source_meeting_id, owner_user_id,
     *           budget_credits, planned_start_at, planned_end_at, metadata
     */
    public function create(int $wsId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') return ['success' => false, 'error' => 'name is required'];
        $goal = trim((string) ($data['goal'] ?? ''));
        if ($goal === '') return ['success' => false, 'error' => 'goal is required'];

        $sourceType = (string) ($data['source_type'] ?? 'direct');
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            return ['success' => false, 'error' => "invalid source_type: $sourceType"];
        }

        $now = now();
        $id = DB::table('projects')->insertGetId([
            'workspace_id'           => $wsId,
            'name'                   => $name,
            'goal'                   => $goal,
            'description'            => $data['description'] ?? null,
            'status'                 => 'proposed',
            'source_type'            => $sourceType,
            'source_meeting_id'      => $data['source_meeting_id'] ?? null,
            'source_chat_message_id' => $data['source_chat_message_id'] ?? null,
            'owner_user_id'          => $data['owner_user_id'] ?? null,
            'budget_credits'         => (int) ($data['budget_credits'] ?? 0),
            'budget_spent'           => 0,
            'planned_start_at'       => $data['planned_start_at'] ?? null,
            'planned_end_at'         => $data['planned_end_at'] ?? null,
            'metadata_json'          => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        return ['success' => true, 'project_id' => $id, 'data' => $this->get($wsId, $id)['data'] ?? null];
    }

    /** Return a single project (workspace-scoped). */
    public function get(int $wsId, int $id): array
    {
        $row = DB::table('projects')
            ->where('id', $id)
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->first();
        if (!$row) return ['success' => false, 'error' => 'project not found'];
        return ['success' => true, 'data' => $this->hydrate($row)];
    }

    /**
     * List projects. Filters: status, source_type, owner_user_id.
     * Returns most recently updated first.
     */
    public function list(int $wsId, array $filters = []): array
    {
        $q = DB::table('projects')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at');

        if (!empty($filters['status']))        $q->where('status', $filters['status']);
        if (!empty($filters['source_type']))   $q->where('source_type', $filters['source_type']);
        if (!empty($filters['owner_user_id'])) $q->where('owner_user_id', (int) $filters['owner_user_id']);

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $rows = $q->orderByDesc('updated_at')->limit($limit)->get();
        return [
            'success' => true,
            'data'    => $rows->map(fn($r) => $this->hydrate($r))->toArray(),
            'count'   => $rows->count(),
        ];
    }

    /**
     * Update editable fields. Cannot change workspace_id (multi-tenancy).
     * Cannot change status via update — use start/pause/complete/archive/cancel.
     */
    public function update(int $wsId, int $id, array $data): array
    {
        $allowed = [
            'name', 'goal', 'description', 'owner_user_id',
            'budget_credits', 'planned_start_at', 'planned_end_at',
            'source_meeting_id', 'source_chat_message_id',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($data['metadata'])) {
            $update['metadata_json'] = json_encode($data['metadata']);
        }
        if (empty($update)) return ['success' => false, 'error' => 'no editable fields supplied'];
        $update['updated_at'] = now();

        $affected = DB::table('projects')
            ->where('id', $id)
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->update($update);

        if ($affected === 0) return ['success' => false, 'error' => 'project not found'];
        return $this->get($wsId, $id);
    }

    /** Lifecycle: → active (first transition; sets started_at). */
    public function start(int $wsId, int $id): array
    {
        return $this->transition($wsId, $id, 'active', ['started_at' => now()]);
    }

    /** Lifecycle: → paused (preserves work in flight). */
    public function pause(int $wsId, int $id): array
    {
        return $this->transition($wsId, $id, 'paused');
    }

    /** Lifecycle: resume from paused → active. */
    public function resume(int $wsId, int $id): array
    {
        $row = DB::table('projects')->where('id', $id)->where('workspace_id', $wsId)->first(['status']);
        if (!$row) return ['success' => false, 'error' => 'project not found'];
        if ($row->status !== 'paused') {
            return ['success' => false, 'error' => 'project not paused (status=' . $row->status . ')'];
        }
        return $this->transition($wsId, $id, 'active');
    }

    /** Lifecycle: → completed (sets completed_at; Phase 4 will add disposition). */
    public function complete(int $wsId, int $id): array
    {
        return $this->transition($wsId, $id, 'completed', ['completed_at' => now()]);
    }

    /** Lifecycle: → archived (terminal). */
    public function archive(int $wsId, int $id): array
    {
        return $this->transition($wsId, $id, 'archived', ['archived_at' => now()]);
    }

    /** Lifecycle: → cancelled (terminal, can come from any non-archived state). */
    public function cancel(int $wsId, int $id): array
    {
        return $this->transition($wsId, $id, 'cancelled');
    }

    /**
     * Link an execution_plan to this project. Also tags any child plan_tasks'
     * downstream automation_events with the project_id for traceability.
     */
    public function linkExecutionPlan(int $wsId, int $projectId, int $executionPlanId): array
    {
        $project = $this->get($wsId, $projectId);
        if (empty($project['success'])) return $project;

        $plan = DB::table('execution_plans')
            ->where('id', $executionPlanId)
            ->where('workspace_id', $wsId)
            ->first(['id']);
        if (!$plan) return ['success' => false, 'error' => 'execution_plan not found'];

        DB::table('execution_plans')
            ->where('id', $executionPlanId)
            ->update(['project_id' => $projectId, 'updated_at' => now()]);

        // Cascade to automation_events linked via plan_tasks of this plan
        $planTaskIds = DB::table('plan_tasks')->where('plan_id', $executionPlanId)->pluck('id');
        if ($planTaskIds->isNotEmpty()) {
            DB::table('automation_events')
                ->where('reference_type', 'plan_task')
                ->whereIn('reference_id', $planTaskIds)
                ->update(['project_id' => $projectId, 'updated_at' => now()]);
        }

        return ['success' => true, 'project_id' => $projectId, 'execution_plan_id' => $executionPlanId];
    }

    /** Soft-delete (use sparingly; archive is the canonical retire path). */
    public function softDelete(int $wsId, int $id): array
    {
        $affected = DB::table('projects')
            ->where('id', $id)
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        if ($affected === 0) return ['success' => false, 'error' => 'project not found'];
        return ['success' => true];
    }

    // ─── Private ──────────────────────────────────────────────────────

    /**
     * State machine transition with validation. Returns success/error.
     */
    private function transition(int $wsId, int $id, string $newStatus, array $extra = []): array
    {
        $row = DB::table('projects')
            ->where('id', $id)
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->first();
        if (!$row) return ['success' => false, 'error' => 'project not found'];

        if (!$this->isValidTransition($row->status, $newStatus)) {
            return ['success' => false, 'error' => "cannot move from {$row->status} to {$newStatus}"];
        }

        $update = array_merge(['status' => $newStatus, 'updated_at' => now()], $extra);
        DB::table('projects')->where('id', $id)->update($update);

        return $this->get($wsId, $id);
    }

    /**
     * State machine — what transitions are legal:
     *   proposed  → active, cancelled
     *   active    → paused, completed, cancelled
     *   paused    → active, cancelled, completed
     *   completed → archived
     *   cancelled → archived
     *   archived  → (terminal, no further transitions)
     */
    private function isValidTransition(string $from, string $to): bool
    {
        static $graph = [
            'proposed'  => ['active', 'cancelled'],
            'active'    => ['paused', 'completed', 'cancelled'],
            'paused'    => ['active', 'completed', 'cancelled'],
            'completed' => ['archived'],
            'cancelled' => ['archived'],
            'archived'  => [],
        ];
        return in_array($to, $graph[$from] ?? [], true);
    }

    /** Convert DB row + decode JSON columns for API consumers. */
    private function hydrate($row): array
    {
        $arr = (array) $row;
        $arr['metadata'] = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
        unset($arr['metadata_json']);
        return $arr;
    }
}