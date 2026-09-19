<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Agent;
use App\Models\Task;
use App\Models\Workspace;
use App\Core\Agents\AgentService;
use App\Core\TaskSystem\TaskCategoryService;

/**
 * Workspace v2 — single consolidated endpoint backing the rebuilt workspace tab.
 *
 * Returns: { agents:[{slug,name,title,category,stats:{ongoing,upcoming,completed,blocked,failed,total}}],
 *            tasks:[...recent 200...],
 *            positions:{slug:{x,y}}, generated_at }
 *
 * Mirrors the bucket math used by /api/agents/dashboard so v1 and v2 stay in sync.
 */
class WorkspaceStateController
{
    public function show(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if (!$wsId) {
            return response()->json(['error' => 'no_workspace'], 422);
        }

        // v1.0.1 — only show agents enabled for THIS workspace.
        // Matches AgentService::forWorkspace() pattern used by /api/agents,
        // so v2 stays in sync with the team-builder activation state.
        $agents = app(AgentService::class)->forWorkspace($wsId);
        if ($agents->isEmpty()) {
            // Fallback: if no agents have been explicitly enabled yet (fresh
            // workspace), surface Sarah at least so the workspace isn't empty.
            $agents = Agent::where('slug', 'sarah')->get();
        }

        // A2 (2026-09-19): the buckets come from App\Core\Metrics\WorkspaceMetrics — the same definitions the
        // Command Center and the Agents page read, so the three surfaces agree by construction.
        $buckets = app(\App\Core\Metrics\WorkspaceMetrics::class)->agentBuckets($wsId);

        $agentList = $agents->map(function ($a) use ($buckets) {
            $slug = $a->slug;
            $b = $buckets[$slug] ?? ['ongoing' => 0, 'upcoming' => 0, 'blocked' => 0, 'completed' => 0, 'failed' => 0, 'declined' => 0, 'qa_rejected' => 0, 'success_rate' => 0, 'total' => 0, 'credits' => 0];
            $pending = $b['upcoming']; $blocked = $b['blocked']; $executing = $b['ongoing']; $completed = $b['completed']; $failed = $b['failed'];
            $isOrchestrator = ($slug === 'sarah');

            return [
                'slug'  => $slug,
                'name'  => $a->name,
                'title' => $a->title,
                'category' => $this->inferCategory($slug, $a->title),
                'is_orchestrator' => $isOrchestrator,
                'stats' => [
                    'ongoing'   => $executing,
                    'upcoming'  => $pending,
                    'completed' => $completed,
                    'blocked'   => $blocked,
                    'failed'    => $failed,
                    'total'     => $executing + $pending + $completed + $blocked + $failed,
                ],
            ];
        })->values();

        $catSvc = app(TaskCategoryService::class);
        $tasks = Task::where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'engine', 'action', 'category', 'status', 'priority', 'assigned_agents_json', 'created_at', 'updated_at', 'completed_at', 'payload_json'])
            ->map(function ($t) use ($catSvc) {
                // Surface orchestration context so the canvas can render an
                // implicit Sarah↔specialist pair for chat-delegated and
                // meeting-extracted tasks (which have only the specialist in
                // assigned_agents_json but were orchestrated by Sarah).
                $payload = is_array($t->payload_json) ? $t->payload_json
                         : (is_string($t->payload_json) ? (json_decode($t->payload_json, true) ?: []) : []);
                // Category — derived live if the row column is null (covers
                // pre-Phase-1 rows the backfill might have missed). Frontend
                // shouldn't have to do this.
                $cat = $t->category ?: $catSvc->for($t->engine, $t->action);
                $catMeta = $catSvc->metadata($cat);
                return [
                    'id'       => $t->id,
                    'engine'   => $t->engine,
                    'action'   => $t->action,
                    'category'       => $cat,
                    'category_label' => $catMeta['label'],
                    'category_color' => $catMeta['color'],
                    'status'   => $t->status,
                    'priority' => $t->priority,
                    'assigned_agents' => $this->decodeAssigned($t->assigned_agents_json),
                    'created_at'   => $t->created_at,
                    'updated_at'   => $t->updated_at,
                    'completed_at' => $t->completed_at,
                    'created_via'  => $payload['created_via'] ?? null,
                    'from_meeting' => $payload['from_meeting'] ?? null,
                ];
            });

        $ws = Workspace::find($wsId);
        $meta = is_array($ws?->settings_json) ? $ws->settings_json : [];
        $positions = $meta['agent_positions'] ?? new \stdClass();

        return response()->json([
            'agents'    => $agentList,
            'tasks'     => $tasks,
            'positions' => $positions,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function decodeAssigned($json): array
    {
        if (is_array($json)) return $json;
        if (!$json) return [];
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Heuristic category inference for layout zones.
     * Mirrors the IIFE CATS map in legacy view-workspace.
     */
    private function inferCategory(string $slug, ?string $title): string
    {
        // LAUNCH SCOPE 2026-07-20 — removed social/email agents dropped from the
        // category map (they are no longer rostered or surfaced). Retained agents only.
        $map = [
            'sarah' => 'dmm', 'james' => 'seo', 'alex' => 'seo', 'diana' => 'seo',
            'ryan' => 'seo', 'sofia' => 'design', 'priya' => 'content',
            'nora' => 'content', 'elena' => 'crm', 'max' => 'crm',
        ];
        if (isset($map[$slug])) return $map[$slug];
        $t = strtolower($title ?? '');
        if (str_contains($t, 'seo')) return 'seo';
        if (str_contains($t, 'social')) return 'social';
        if (str_contains($t, 'content') || str_contains($t, 'writ')) return 'content';
        if (str_contains($t, 'design')) return 'design';
        if (str_contains($t, 'email')) return 'email';
        return 'technical';
    }
}
