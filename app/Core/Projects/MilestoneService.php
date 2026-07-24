<?php

namespace App\Core\Projects;

use Illuminate\Support\Facades\DB;

/**
 * MilestoneService — CRUD + lifecycle for project_milestones.
 *
 *   Lifecycle: pending → achieved (with evidence) | missed | cancelled
 */
class MilestoneService
{
    public const STATUSES = ['pending', 'achieved', 'missed', 'cancelled'];

    public function create(int $wsId, int $projectId, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') return ['success' => false, 'error' => 'title is required'];

        // Verify project belongs to workspace
        $proj = DB::table('projects')->where('id', $projectId)
            ->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->first(['id']);
        if (!$proj) return ['success' => false, 'error' => 'project not found in workspace'];

        $now = now();
        $id = DB::table('project_milestones')->insertGetId([
            'project_id'             => $projectId,
            'workspace_id'           => $wsId,
            'title'                  => $title,
            'description'            => $data['description'] ?? null,
            'target_date'            => $data['target_date'] ?? null,
            'status'                 => 'pending',
            'order_index'            => (int) ($data['order_index'] ?? 0),
            'success_criteria_json'  => isset($data['success_criteria'])
                ? json_encode($data['success_criteria']) : null,
            'notes'                  => $data['notes'] ?? null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        return ['success' => true, 'milestone_id' => $id, 'data' => $this->get($wsId, $id)['data'] ?? null];
    }

    public function get(int $wsId, int $id): array
    {
        $row = DB::table('project_milestones')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'milestone not found'];
        return ['success' => true, 'data' => $this->hydrate($row)];
    }

    public function listForProject(int $wsId, int $projectId, ?string $status = null): array
    {
        $q = DB::table('project_milestones')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId);
        if ($status) $q->where('status', $status);
        $rows = $q->orderBy('order_index')->orderBy('id')->get();
        return [
            'success' => true,
            'data'    => $rows->map(fn($r) => $this->hydrate($r))->toArray(),
            'count'   => $rows->count(),
        ];
    }

    public function update(int $wsId, int $id, array $data): array
    {
        $allowed = ['title', 'description', 'target_date', 'order_index', 'notes'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($data['success_criteria'])) {
            $update['success_criteria_json'] = json_encode($data['success_criteria']);
        }
        if (empty($update)) return ['success' => false, 'error' => 'no editable fields supplied'];
        $update['updated_at'] = now();
        $affected = DB::table('project_milestones')
            ->where('id', $id)->where('workspace_id', $wsId)
            ->update($update);
        if ($affected === 0) return ['success' => false, 'error' => 'milestone not found'];
        return $this->get($wsId, $id);
    }

    /** Lifecycle: mark as achieved with evidence. */
    public function markAchieved(int $wsId, int $id, array $evidence = []): array
    {
        return $this->transition($wsId, $id, 'achieved', [
            'completed_at' => now(),
            'evidence_json' => json_encode($evidence),
        ]);
    }

    /** Lifecycle: mark as missed (target_date passed without achievement). */
    public function markMissed(int $wsId, int $id, ?string $reason = null): array
    {
        return $this->transition($wsId, $id, 'missed', [
            'notes' => $reason,
        ]);
    }

    /** Lifecycle: cancel (no longer needed). */
    public function cancel(int $wsId, int $id, ?string $reason = null): array
    {
        return $this->transition($wsId, $id, 'cancelled', [
            'notes' => $reason,
        ]);
    }

    public function delete(int $wsId, int $id): array
    {
        $affected = DB::table('project_milestones')
            ->where('id', $id)->where('workspace_id', $wsId)->delete();
        return ['success' => $affected > 0];
    }

    /** Compute milestone score (0-100). achieved=1, others=0. */
    public function score(int $wsId, int $projectId): array
    {
        $total = DB::table('project_milestones')
            ->where('workspace_id', $wsId)->where('project_id', $projectId)
            ->whereNotIn('status', ['cancelled'])
            ->count();
        if ($total === 0) return ['score' => null, 'achieved' => 0, 'total' => 0];

        $achieved = DB::table('project_milestones')
            ->where('workspace_id', $wsId)->where('project_id', $projectId)
            ->where('status', 'achieved')
            ->count();
        return [
            'score'    => round(($achieved / $total) * 100, 2),
            'achieved' => $achieved,
            'total'    => $total,
        ];
    }

    private function transition(int $wsId, int $id, string $newStatus, array $extra = []): array
    {
        $row = DB::table('project_milestones')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'milestone not found'];
        if ($row->status !== 'pending') {
            return ['success' => false, 'error' => "milestone already {$row->status}"];
        }
        $update = array_merge(['status' => $newStatus, 'updated_at' => now()], $extra);
        DB::table('project_milestones')->where('id', $id)->update($update);
        return $this->get($wsId, $id);
    }

    private function hydrate($row): array
    {
        $arr = (array) $row;
        $arr['success_criteria'] = json_decode($arr['success_criteria_json'] ?? '{}', true) ?: [];
        $arr['evidence']         = json_decode($arr['evidence_json'] ?? '{}', true) ?: [];
        unset($arr['success_criteria_json'], $arr['evidence_json']);
        return $arr;
    }
}