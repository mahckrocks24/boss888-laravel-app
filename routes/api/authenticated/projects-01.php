<?php

/**
 * CR-22B — extracted route module: projects-01
 *
 * Source: routes/api.php lines 753-932 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: Projects   ·   Routes: 13   ·   Statements: 13
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // Tasks (user-facing)
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::post('/tasks/create', [TaskController::class, 'store']); // alias for frontend

    // Direct task assignment from workspace canvas — accepts frontend form shape
    Route::post('/tasks/assign', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $title = $r->input('title', 'Untitled task');
        $assignees = $r->input('assignees', []);

        // Infer engine from first assignee's category
        $engineMap = [
            'james'=>'seo','alex'=>'seo','diana'=>'seo','ryan'=>'seo','sofia'=>'seo',
            'priya'=>'write','leo'=>'write','maya'=>'write','chris'=>'write','nora'=>'write',
            'marcus'=>'social','zara'=>'social','tyler'=>'social','zoe'=>'social','jordan'=>'social',
            'elena'=>'crm','sam'=>'marketing','kai'=>'crm','vera'=>'marketing','max'=>'crm',
            'sarah'=>'marketing','dmm'=>'marketing',
        ];
        $firstAgent = $assignees[0] ?? 'sarah';
        $engine = $engineMap[$firstAgent] ?? 'marketing';

        $task = \App\Models\Task::create([
            'workspace_id' => $wsId,
            'engine' => $engine,
            'action' => 'manual_task',
            'source' => 'manual',
            'status' => 'pending',
            'priority' => $r->input('priority', 'normal'),
            'assigned_agents_json' => $assignees,  // raw array — Eloquent casts to JSON
            'credit_cost' => 0,
            'progress_message' => $title,
            'payload_json' => json_encode([
                'title' => $title,
                'description' => $r->input('description', ''),
                'estimated_time' => $r->input('estimated_time', 60),
                'estimated_tokens' => $r->input('estimated_tokens', 4000),
                'success_metric' => $r->input('success_metric', ''),
                'coordinator' => $r->input('coordinator', ''),
            ]),
        ]);

        return response()->json(['task' => $task], 201);
    });

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/stats', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        if (!$wsId) return response()->json(['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0]);
        $counts = \App\Models\Task::where('workspace_id', $wsId)->selectRaw("status, count(*) as cnt")->groupBy('status')->pluck('cnt', 'status')->toArray();
        return response()->json(['pending' => ($counts['pending'] ?? 0) + ($counts['queued'] ?? 0) + ($counts['awaiting_approval'] ?? 0), 'running' => ($counts['running'] ?? 0) + ($counts['verifying'] ?? 0), 'completed' => $counts['completed'] ?? 0, 'failed' => ($counts['failed'] ?? 0) + ($counts['cancelled'] ?? 0)]);
    });
        Route::put('/tasks/{id}/status', function (\Illuminate\Http\Request $r, $id) {
        // MISSION-018 WS-1 (2026-08-24, RISK-0022): this closure resolved a
        // task by bare id with no workspace filter while every sibling route
        // in this file scopes. Confined to the caller's workspace, matching
        // the /tasks/{id}/retry pattern below: cross-workspace ids get the
        // same 404 as nonexistent ones, so existence is not leaked either.
        $task = \App\Models\Task::find((int) $id);
        $callerWs = (int) $r->attributes->get('workspace_id');
        if (!$task || (int) $task->workspace_id !== $callerWs) {
            return response()->json(['success' => false, 'error' => 'not_found_or_not_yours'], 404);
        }
        $newStatus = $r->input('status');
        // Map kanban statuses to task table enum values
        $statusMap = [
            'backlog' => 'pending',
            'planned' => 'queued',
            'in_progress' => 'running',
            'review' => 'verifying',
            'completed' => 'completed',
        ];
        $dbStatus = $statusMap[$newStatus] ?? $newStatus;
        $task->update(['status' => $dbStatus]);
        return response()->json(['success' => true, 'status' => $newStatus]);
    });
        Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::get('/tasks/{id}/status', [TaskController::class, 'status']);
    Route::get('/tasks/{id}/events', [TaskController::class, 'events']);

    // 2026-05-25 — Retry blocked tasks (idempotency-checked). Single-task
    // endpoint hit from Pipeline UI retry button; batch endpoint for
    // "retry all blocked" affordances in drawer + agent chat.
    Route::post('/tasks/{id}/retry', function (\Illuminate\Http\Request $r, $id) {
        $svc = app(\App\Core\TaskSystem\TaskRetryService::class);
        $userId = $r->user()?->id;
        $triggeredBy = $userId ? "user:{$userId}" : 'unknown';
        // Verify the task belongs to the caller's workspace
        $t = \App\Models\Task::find((int) $id);
        $callerWs = (int) $r->attributes->get('workspace_id');
        if (!$t || (int) $t->workspace_id !== $callerWs) {
            return response()->json(['success' => false, 'error' => 'not_found_or_not_yours'], 404);
        }
        $result = $svc->retryOne((int) $id, $triggeredBy);
        return response()->json(['success' => $result['action'] !== 'error', 'result' => $result]);
    });

    Route::post('/tasks/retry-blocked', function (\Illuminate\Http\Request $r) {
        $r->validate(['ids' => 'sometimes|array', 'ids.*' => 'integer']);
        $svc = app(\App\Core\TaskSystem\TaskRetryService::class);
        $userId = $r->user()?->id;
        $triggeredBy = $userId ? "user:{$userId}" : 'unknown';
        $wsId = (int) $r->attributes->get('workspace_id');
        $result = $svc->retryBlockedForWorkspace($wsId, $triggeredBy, $r->input('ids'));
        return response()->json($result);
    });

    // 2026-05-22 URGENT FIX A — POST /api/tasks/approve was deleted in a
    // prior refactor but core.js still calls it from the Strategy Room
    // approval modal (auto-approve safe tasks, manual-approve risky tasks,
    // dismiss). Without this route Laravel matched the URL to GET
    // api/tasks/{id} with id="approve" and the POST returned 405.
    Route::post('/tasks/approve', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        $approved = (array) $r->input('approved', []);
        $tasks = (array) $r->input('tasks', []);
        $meetingId = $r->input('meeting_id');

        if (empty($approved)) {
            if ($meetingId) {
                \Illuminate\Support\Facades\Log::info('[TasksApprove] dismissed', [
                    'workspace_id' => $wsId, 'meeting_id' => $meetingId,
                ]);
            }
            return response()->json(['saved' => 0, 'tasks_imported' => 0, 'previews_created' => 0]);
        }

        $svc = app(\App\Core\TaskSystem\TaskService::class);
        $imported = 0;
        $previews = 0;
        $failedReasons = [];

        $byId = [];
        foreach ($tasks as $t) {
            if (is_array($t) && isset($t['id'])) $byId[(string) $t['id']] = $t;
        }

        foreach ($approved as $tid) {
            $spec = $byId[(string) $tid] ?? null;
            if (!is_array($spec)) continue;
            try {
                $payload = [
                    'agent' => $spec['agent'] ?? 'sarah',
                    'engine' => $spec['engine'] ?? 'marketing',
                    'action' => $spec['action'] ?? 'manual_task',
                    'description' => $spec['description'] ?? 'Approved from Strategy Room',
                    'priority' => $spec['priority'] ?? 'normal',
                    'source' => 'meeting',
                    'created_via' => 'strategy_room_approval',
                    'requires_approval' => false,
                    'payload' => array_filter([
                        'description' => $spec['description'] ?? null,
                        'from_meeting' => $meetingId,
                        'approved_at' => now()->toIso8601String(),
                    ], fn($v) => $v !== null),
                ];
                $newTask = $svc->create($wsId, $payload);
                $imported++;
                $previewActions = ['write_article', 'create_post', 'create_campaign'];
                if (in_array($payload['action'], $previewActions, true)) {
                    $previews++;
                }
            } catch (\Throwable $e) {
                $failedReasons[] = mb_substr($e->getMessage(), 0, 100);
                \Illuminate\Support\Facades\Log::warning('[TasksApprove] task create failed', [
                    'workspace_id' => $wsId,
                    'spec' => $spec,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'saved' => $imported,
            'tasks_imported' => $imported,
            'previews_created' => $previews,
            'failed' => count($failedReasons),
            'failure_reasons' => array_values(array_unique($failedReasons)),
        ]);
    });
    // CRITICAL-03 FIX: user-accessible cancel (admin cancel is separate at /admin/tasks/{id}/cancel)
    Route::post('/tasks/{id}/cancel', function (\Illuminate\Http\Request $r, $id) {
        $wsId = $r->attributes->get('workspace_id');
        $task = \App\Models\Task::where('id', $id)->where('workspace_id', $wsId)->firstOrFail();
        if (in_array($task->status, ['completed', 'failed', 'cancelled'])) {
            return response()->json(['error' => 'Task cannot be cancelled in its current state'], 422);
        }
        $task->update(['status' => 'cancelled', 'updated_at' => now()]);
        return response()->json(['cancelled' => true, 'task_id' => $id]);
    });
