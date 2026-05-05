<?php

namespace App\Core\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * ToolCostCalculatorService — replaces flat per-engine cost estimates.
 *
 * Reads real per-tool credit costs from engine_intelligence.metadata_json.credit_cost,
 * walks a planned task sequence, and returns a detailed breakdown:
 *
 *   [
 *     'total' => 14,
 *     'per_task' => [ { task_index, engine, action, cost, source }, ... ],
 *     'per_engine' => [ 'seo' => 4, 'write' => 3, ... ],
 *     'confidence' => 'high' | 'medium' | 'low',
 *     'unknown_tools' => [ ... ],
 *   ]
 *
 * Fallback: if a tool has no blueprint, uses a conservative default from
 * the legacy flat map (preserves behavior during transition).
 */
class ToolCostCalculatorService
{
    /** Legacy per-engine fallback costs — used when blueprint is missing. */
    private const FALLBACK_COSTS = [
        'seo' => 2,
        'write' => 3,
        'creative' => 4,
        'social' => 0,
        'marketing' => 1,
        'crm' => 0,
        'builder' => 3,
        'beforeafter' => 3,
        'traffic' => 0,
        'calendar' => 0,
        'manualedit' => 0,
    ];

    /**
     * Estimate cost for a planned task sequence.
     * Each task is expected to have at least ['engine' => ..., 'action' => ...].
     */
    public function estimate(array $taskSequence): array
    {
        $total = 0;
        $perTask = [];
        $perEngine = [];
        $unknownTools = [];
        $confidentSources = 0;

        foreach ($taskSequence as $index => $task) {
            $engine = $task['engine'] ?? null;
            $action = $task['action'] ?? null;

            if (!$engine || !$action) continue;

            $cost = $this->costForTool($engine, $action);
            $source = $this->sourceForTool($engine, $action);

            if ($source === 'blueprint') {
                $confidentSources++;
            } elseif ($source === 'unknown') {
                $unknownTools[] = "{$engine}.{$action}";
            }

            $total += $cost;
            $perTask[] = [
                'task_index' => $index,
                'engine' => $engine,
                'action' => $action,
                'cost' => $cost,
                'source' => $source,
            ];
            $perEngine[$engine] = ($perEngine[$engine] ?? 0) + $cost;
        }

        $taskCount = count($perTask);
        $confidence = 'low';
        if ($taskCount > 0) {
            $ratio = $confidentSources / $taskCount;
            if ($ratio >= 0.9) $confidence = 'high';
            elseif ($ratio >= 0.6) $confidence = 'medium';
        }

        return [
            'total' => $total,
            'per_task' => $perTask,
            'per_engine' => $perEngine,
            'confidence' => $confidence,
            'unknown_tools' => $unknownTools,
        ];
    }

    /**
     * Get the cost for a single tool. Reads blueprint metadata first,
     * falls back to legacy flat map only if blueprint is missing.
     */
    public function costForTool(string $engine, string $action): int
    {
        $row = DB::table('engine_intelligence')
            ->where('engine', $engine)
            ->where('knowledge_type', 'tool_blueprint')
            ->where('key', $action)
            ->first();

        if ($row && $row->metadata_json) {
            $meta = json_decode($row->metadata_json, true) ?: [];
            if (isset($meta['credit_cost'])) {
                return (int) $meta['credit_cost'];
            }
        }

        // Fallback to legacy flat cost
        return (int) (self::FALLBACK_COSTS[$engine] ?? 1);
    }

    /**
     * Where did the cost come from? Blueprint, fallback, or unknown.
     * Used to compute overall estimate confidence.
     */
    public function sourceForTool(string $engine, string $action): string
    {
        $row = DB::table('engine_intelligence')
            ->where('engine', $engine)
            ->where('knowledge_type', 'tool_blueprint')
            ->where('key', $action)
            ->first();

        if ($row && $row->metadata_json) {
            $meta = json_decode($row->metadata_json, true) ?: [];
            if (isset($meta['credit_cost'])) {
                return 'blueprint';
            }
            return 'blueprint_partial';
        }

        if (isset(self::FALLBACK_COSTS[$engine])) {
            return 'fallback';
        }

        return 'unknown';
    }
}
