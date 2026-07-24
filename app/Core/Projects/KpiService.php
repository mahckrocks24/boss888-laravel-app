<?php

namespace App\Core\Projects;

use Illuminate\Support\Facades\DB;

/**
 * KpiService — CRUD + measurement updates for project_kpis.
 *
 *   Lifecycle: KPIs don't have status transitions. They're measured
 *   continuously. Score reflects current_value vs target_value.
 */
class KpiService
{
    public const DIRECTIONS = ['higher_is_better', 'lower_is_better'];

    public function create(int $wsId, int $projectId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') return ['success' => false, 'error' => 'name is required'];
        if (!isset($data['target_value']) || !is_numeric($data['target_value'])) {
            return ['success' => false, 'error' => 'target_value is required and must be numeric'];
        }
        $direction = (string) ($data['direction'] ?? 'higher_is_better');
        if (!in_array($direction, self::DIRECTIONS, true)) {
            return ['success' => false, 'error' => "invalid direction: $direction"];
        }

        $proj = DB::table('projects')->where('id', $projectId)
            ->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->first(['id']);
        if (!$proj) return ['success' => false, 'error' => 'project not found in workspace'];

        $now = now();
        $id = DB::table('project_kpis')->insertGetId([
            'project_id'                => $projectId,
            'workspace_id'              => $wsId,
            'name'                      => $name,
            'description'               => $data['description'] ?? null,
            'target_value'              => (float) $data['target_value'],
            'current_value'             => (float) ($data['current_value'] ?? 0),
            'unit'                      => $data['unit'] ?? null,
            'direction'                 => $direction,
            'measurement_source_json'   => isset($data['measurement_source'])
                ? json_encode($data['measurement_source']) : json_encode(['type' => 'manual']),
            'order_index'               => (int) ($data['order_index'] ?? 0),
            'metadata_json'             => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at'                => $now,
            'updated_at'                => $now,
        ]);
        return ['success' => true, 'kpi_id' => $id, 'data' => $this->get($wsId, $id)['data'] ?? null];
    }

    public function get(int $wsId, int $id): array
    {
        $row = DB::table('project_kpis')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'KPI not found'];
        return ['success' => true, 'data' => $this->hydrate($row)];
    }

    public function listForProject(int $wsId, int $projectId): array
    {
        $rows = DB::table('project_kpis')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId)
            ->orderBy('order_index')->orderBy('id')
            ->get();
        return [
            'success' => true,
            'data'    => $rows->map(fn($r) => $this->hydrate($r))->toArray(),
            'count'   => $rows->count(),
        ];
    }

    public function update(int $wsId, int $id, array $data): array
    {
        $allowed = ['name', 'description', 'target_value', 'unit', 'direction', 'order_index'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($data['measurement_source'])) {
            $update['measurement_source_json'] = json_encode($data['measurement_source']);
        }
        if (isset($data['metadata'])) {
            $update['metadata_json'] = json_encode($data['metadata']);
        }
        if (isset($update['direction']) && !in_array($update['direction'], self::DIRECTIONS, true)) {
            return ['success' => false, 'error' => "invalid direction"];
        }
        if (empty($update)) return ['success' => false, 'error' => 'no editable fields supplied'];
        $update['updated_at'] = now();
        $affected = DB::table('project_kpis')
            ->where('id', $id)->where('workspace_id', $wsId)->update($update);
        if ($affected === 0) return ['success' => false, 'error' => 'KPI not found'];
        return $this->get($wsId, $id);
    }

    /** Record a measurement: updates current_value + last_measured_at. */
    public function recordValue(int $wsId, int $id, float $value): array
    {
        $affected = DB::table('project_kpis')
            ->where('id', $id)->where('workspace_id', $wsId)
            ->update([
                'current_value'     => $value,
                'last_measured_at'  => now(),
                'updated_at'        => now(),
            ]);
        if ($affected === 0) return ['success' => false, 'error' => 'KPI not found'];
        return $this->get($wsId, $id);
    }

    public function delete(int $wsId, int $id): array
    {
        $affected = DB::table('project_kpis')
            ->where('id', $id)->where('workspace_id', $wsId)->delete();
        return ['success' => $affected > 0];
    }

    /**
     * Compute KPI score (0-100). Average of (current/target) per KPI, capped at 1.0.
     * For lower_is_better KPIs, the formula inverts: target/current (capped at 1.0).
     */
    public function score(int $wsId, int $projectId): array
    {
        $kpis = DB::table('project_kpis')
            ->where('workspace_id', $wsId)->where('project_id', $projectId)
            ->get(['id', 'name', 'target_value', 'current_value', 'direction']);
        if ($kpis->isEmpty()) return ['score' => null, 'count' => 0, 'breakdown' => []];

        $perKpi = [];
        $sum = 0.0;
        foreach ($kpis as $k) {
            $target  = (float) $k->target_value;
            $current = (float) $k->current_value;
            $achievement = 0.0;
            if ($k->direction === 'higher_is_better') {
                $achievement = $target > 0 ? min(1.0, $current / $target) : 0.0;
            } else {
                // lower_is_better: target=2 days, current=1.5 days → exceeded → 1.0
                // current=4 days → 50%
                $achievement = $current > 0 ? min(1.0, $target / $current) : 1.0;
            }
            $achievementPct = round($achievement * 100, 2);
            $perKpi[] = [
                'kpi_id'      => $k->id,
                'name'        => $k->name,
                'achievement' => $achievementPct,
            ];
            $sum += $achievement;
        }
        return [
            'score'     => round(($sum / $kpis->count()) * 100, 2),
            'count'     => $kpis->count(),
            'breakdown' => $perKpi,
        ];
    }

    private function hydrate($row): array
    {
        $arr = (array) $row;
        $arr['measurement_source'] = json_decode($arr['measurement_source_json'] ?? '{}', true) ?: [];
        $arr['metadata']           = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
        unset($arr['measurement_source_json'], $arr['metadata_json']);
        // Cast numerics back from strings
        $arr['target_value']  = (float) $arr['target_value'];
        $arr['current_value'] = (float) $arr['current_value'];
        return $arr;
    }
}