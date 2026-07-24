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

        // v1.0.9 — count multi-assignee tasks for EVERY assignee.
        // Previous SQL pulled only assigned_agents_json[0], so a task assigned
        // to [sarah,james,priya] only credited sarah. Now we iterate in PHP
        // and increment each agent's bucket once per task, deduped by task id.
        $allTasks = Task::where('workspace_id', $wsId)
            ->get(['id', 'status', 'assigned_agents_json', 'engine']);

        $bucketIds = []; // slug => bucket => [task_id, ...]
        foreach ($allTasks as $t) {
            $assignees = $this->decodeAssigned($t->assigned_agents_json);
            if (empty($assignees)) {
                // Legacy fallback for older tasks with no assigned_agents_json:
                // use engine name as the "agent_key" (matches v1 dashboard behavior).
                $assignees = [$t->engine];
            }
            $bucket = match ($t->status) {
                'pending', 'queued', 'awaiting_approval' => 'pending',
                'blocked'                                => 'blocked',
                'running', 'verifying'                   => 'executing',
                'completed'                              => 'completed',
                'failed', 'cancelled', 'degraded'        => 'failed',
                default                                  => null,
            };
            if (!$bucket) continue;
            foreach ($assignees as $slug) {
                if (!isset($bucketIds[$slug])) {
                    $bucketIds[$slug] = ['pending'=>[],'blocked'=>[],'executing'=>[],'completed'=>[],'failed'=>[]];
                }
                $bucketIds[$slug][$bucket][] = $t->id;
            }
        }

        // Orchestrator (Sarah) ALSO counts tasks she delegated via her chat —
        // those may have her in assignees OR not. We union by task id to avoid
        // double-counting when she's explicitly assigned to a task she created.
        $orchestratorSlugs = ['sarah'];
        foreach ($orchestratorSlugs as $orchSlug) {
            $delegated = Task::where('workspace_id', $wsId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) IN ('sarah_chat', 'sarah_proactive')")
                ->get(['id', 'status']);
            if (!isset($bucketIds[$orchSlug])) {
                $bucketIds[$orchSlug] = ['pending'=>[],'blocked'=>[],'executing'=>[],'completed'=>[],'failed'=>[]];
            }
            foreach ($delegated as $t) {
                $bucket = match ($t->status) {
                    'pending', 'queued', 'awaiting_approval' => 'pending',
                    'blocked'                                => 'blocked',
                    'running', 'verifying'                   => 'executing',
                    'completed'                              => 'completed',
                    'failed', 'cancelled', 'degraded'        => 'failed',
                    default                                  => null,
                };
                if ($bucket) $bucketIds[$orchSlug][$bucket][] = $t->id;
            }
        }

        $delegationStats = DB::table('agent_delegations')
            ->where('workspace_id', $wsId)
            ->selectRaw('to_agent as agent_id, status, count(*) as cnt')
            ->groupBy('to_agent', 'status')
            ->get()
            ->groupBy('agent_id');

        $agentList = $agents->map(function ($a) use ($bucketIds, $delegationStats) {
            $slug = $a->slug;
            $b = $bucketIds[$slug] ?? ['pending'=>[],'blocked'=>[],'executing'=>[],'completed'=>[],'failed'=>[]];
            // Dedup by task id (a multi-assignee task counts once per agent
            // but the orchestrator union above could insert duplicates).
            $pending   = count(array_unique($b['pending']));
            $blocked   = count(array_unique($b['blocked']));
            $executing = count(array_unique($b['executing']));
            $completed = count(array_unique($b['completed']));
            $failed    = count(array_unique($b['failed']));

            // Add agent_delegations rows (separate stream, no task-id overlap).
            $delegations = $delegationStats->get($a->id, collect());
            foreach ($delegations as $d) {
                match ($d->status) {
                    'pending'     => $pending += $d->cnt,
                    'in_progress' => $executing += $d->cnt,
                    'completed'   => $completed += $d->cnt,
                    'failed'      => $failed += $d->cnt,
                    default       => null,
                };
            }

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
