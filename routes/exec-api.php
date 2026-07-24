<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| APP888 Exec-API Routes  —  /exec-api/* (no /api prefix)
|--------------------------------------------------------------------------
| Served at: app.levelupgrowth.io/exec-api/*
| Auth:      JWT bearer token (same token as web app — no separate login)
| Plan gate: Pro+ only (companion_app flag on plan)
|
| PATCH v1.0.1:
|   These routes were previously registered inside routes/api.php under
|   Route::prefix('exec-api'), which Laravel served as /api/exec-api/*.
|   APP888 expects /exec-api/* (no /api prefix).
|   Moved here and registered in bootstrap/app.php without a prefix.
|--------------------------------------------------------------------------
*/

Route::prefix('exec-api')
    ->middleware(['throttle:60,1', 'auth.jwt', 'plan:app888'])
    ->group(function () {

        // ── Workspace Executive Summary ───────────────────────────
        Route::get('/workspace/summary', function (Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $ws   = \App\Models\Workspace::find($wsId);

            $activePlan = \App\Models\Task::where('workspace_id', $wsId)
                ->whereIn('status', ['queued', 'in_progress'])->count();

            $pendingApprovals = \Illuminate\Support\Facades\DB::table('approvals')
                ->where('workspace_id', $wsId)->where('status', 'pending')->count();

            $credit    = \App\Models\Credit::where('workspace_id', $wsId)->first();
            $available = max(0, (int)($credit?->balance ?? 0) - (int)($credit?->reserved_balance ?? 0));

            // W6: a legacy task row can still name a removed agent. The mobile
            // home card must never present one as currently active.
            $activeAgents = \Illuminate\Support\Facades\DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->whereIn('status', ['queued', 'in_progress'])
                ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(assigned_agents_json, "$[0]")) as agent')
                ->distinct()->pluck('agent')->filter()
                ->reject(fn($s) => \App\Core\LaunchScope\AgentDirectory::isRemoved((string) $s))
                ->values();

            // W6: legacy social/marketing rows are historical, not recent work.
            $recentTasks = \App\Models\Task::where('workspace_id', $wsId)
                ->orderByDesc('created_at')->limit(25)
                ->get(['id', 'engine', 'action', 'status', 'created_at', 'completed_at'])
                ->reject(fn($t) => \App\Core\LaunchScope\AgentDirectory::isLegacyTask($t->engine, $t->action, null))
                ->take(5)->values();

            return response()->json([
                'workspace' => [
                    'id'            => $wsId,
                    'name'          => $ws?->name,
                    'business_name' => $ws?->business_name,
                    'industry'      => $ws?->industry,
                ],
                'credits' => [
                    'available' => $available,
                    'balance'   => (int)($credit?->balance ?? 0),
                    'reserved'  => (int)($credit?->reserved_balance ?? 0),
                ],
                'pending_approvals' => $pendingApprovals,
                'active_tasks'      => $activePlan,
                'active_agents'     => $activeAgents,
                'recent_tasks'      => $recentTasks,
                'generated_at'      => now()->toISOString(),
            ]);
        });

        // ── Agent Conversations ───────────────────────────────────
        Route::get('/agent/conversations', function (Request $r) {
            $service = app(\App\Core\Agent\AgentDispatchService::class);
            return response()->json($service->listConversations(
                $r->attributes->get('workspace_id'), $r->user()->id
            ));
        });

        Route::get('/agent/conversations/{id}', function (Request $r, $id) {
            // v1.4.4 — $id is now the agent slug (string), not an int meeting id.
            // listConversations + getConversation read from agent_messages so
            // mobile + web SPA share the same per-agent thread.
            $service = app(\App\Core\Agent\AgentDispatchService::class);
            return response()->json($service->getConversation(
                $r->attributes->get('workspace_id'), (string) $id
            ));
        });

        Route::post('/agent/message', function (Request $r) {
            $r->validate([
                'agent_id'        => 'required|string',
                'content'         => 'required|string|max:5000',
                'conversation_id' => 'nullable',
            ]);
            $service = app(\App\Core\Agent\AgentDispatchService::class);
            return response()->json($service->dispatch(
                $r->attributes->get('workspace_id'),
                $r->user()->id,
                array_merge($r->all(), ['source' => 'app888'])
            ), 201);
        });

        Route::get('/agent/events', function (Request $r) {
            $service = app(\App\Core\Agent\AgentDispatchService::class);
            return response()->json($service->getEvents(
                $r->attributes->get('workspace_id'),
                $r->user()->id,
                $r->query('cursor'),
                $r->query('conversation_id')
            ));
        });

        // ── Approvals ─────────────────────────────────────────────
        // v1.4.4 (2026-05-30) — Mobile approvals overhaul.
        // - /tasks/pending-approval now returns the same batched shape as
        //   the web Command Center (count + sample_titles + total credits +
        //   friendly label + agent name) so the mobile ApprovalsScreen can
        //   render "Approve all 10 articles" cards instead of raw rows.
        // - The {id} path param on approve/reject is the TASK id (what the
        //   mobile sends) — we resolve it to the corresponding approval row
        //   before delegating to ApprovalController. Older releases passed
        //   the task id directly to ApprovalController::approve which
        //   expected the approval id → every approval hit 404.
        Route::get('/tasks/pending-approval', function (Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('approvals as ap')
                ->leftJoin('tasks as t', 't.id', '=', 'ap.task_id')
                ->where('ap.workspace_id', $wsId)
                ->where('ap.status', 'pending')
                ->orderByDesc('ap.created_at')
                ->limit(50)
                ->get([
                    'ap.id', 'ap.task_id', 'ap.created_at', 'ap.batch_id',
                    'ap.engine as ap_engine', 'ap.action as ap_action', 'ap.data_json as ap_data_json',
                    't.engine as t_engine', 't.action as t_action', 't.payload_json as t_payload_json',
                    't.credit_cost', 't.assigned_agents_json',
                ]);

            // W6: historical rows for removed engines/actions/agents are NOT
            // current work. They stay in the database but must never appear in
            // a live approval queue as something the customer can action.
            $rows = $rows->reject(function ($row) {
                return \App\Core\LaunchScope\AgentDirectory::isLegacyTask(
                    $row->t_engine ?: $row->ap_engine,
                    $row->t_action ?: $row->ap_action,
                    $row->assigned_agents_json
                );
            })->values();

            $approvals = $rows->map(function ($row) use ($wsId) {
                $engine = $row->t_engine ?: ($row->ap_engine ?: 'system');
                $action = $row->t_action ?: ($row->ap_action ?: 'review');

                $batchCount        = 1;
                $batchTotalCredits = (int) ($row->credit_cost ?? 0);
                $sampleTitles      = [];
                $siblingTaskIds    = [];
                if ($row->batch_id) {
                    $siblings = \Illuminate\Support\Facades\DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->where('batch_id', $row->batch_id)
                        ->where('action', $action)
                        ->where('approval_status', 'pending')
                        ->get(['id', 'credit_cost', 'payload_json']);
                    $batchCount        = $siblings->count() ?: 1;
                    $batchTotalCredits = (int) $siblings->sum('credit_cost') ?: $batchTotalCredits;
                    foreach ($siblings->take(6) as $s) {
                        $p = $s->payload_json ? json_decode($s->payload_json, true) : null;
                        $t = is_array($p) ? ($p['title'] ?? $p['topic'] ?? $p['keyword'] ?? null) : null;
                        if ($t) $sampleTitles[] = mb_substr((string) $t, 0, 80);
                        $siblingTaskIds[] = (int) $s->id;
                    }
                }

                // Resolve a friendly label from the same map ApprovalController uses.
                $unit = [
                    'write_article'           => 'article',
                    'generate_meta'           => 'meta block',
                    'insert_link'             => 'internal link',
                    'generate_image_mini'     => 'image',
                    'generate_image'          => 'image',
                    // W6: removed-scope units deleted. Rows carrying these actions
                    // are rejected above; the labels go too, so a crafted or
                    // legacy row can never render removed-product wording.
                    'create_lead'             => 'lead',
                    'delete_lead'             => 'lead delete',
                    'publish_article'         => 'article publish',
                    'delete_article'          => 'article delete',
                    'delete_post'             => 'post delete',
                    'add_page_from_template'  => 'page',
                    'publish_website'         => 'site publish',
                    'publish_builder_page'    => 'page publish',
                    'update_page'             => 'page edit',
                    'ai_builder_action'       => 'page edit',
                ][$action] ?? str_replace('_', ' ', $action);
                $label = $batchCount > 1
                    ? ($batchCount . ' ' . $unit . 's pending approval')
                    : ('1 ' . $unit . ' pending approval');

                // Resolve agent (first assignee) so mobile can show the orb.
                $assignedAgent = null;
                if ($row->assigned_agents_json) {
                    $aa = is_string($row->assigned_agents_json) ? json_decode($row->assigned_agents_json, true) : $row->assigned_agents_json;
                    if (is_array($aa) && !empty($aa)) {
                        $cand = (string) $aa[0];
                        // W6: never hand the mobile client a removed agent slug.
                        $assignedAgent = \App\Core\LaunchScope\AgentDirectory::isRemoved($cand) ? null : $cand;
                    }
                }

                return [
                    'id'                  => (int) $row->id,
                    'approval_id'         => (int) $row->id,
                    'task_id'             => $row->task_id ? (int) $row->task_id : null,
                    'batch_id'            => $row->batch_id,
                    'batch_count'         => $batchCount,
                    'batch_total_credits' => $batchTotalCredits,
                    'sample_titles'       => array_values(array_unique($sampleTitles)),
                    'sibling_task_ids'    => $siblingTaskIds,
                    'engine'              => $engine,
                    'action'              => $action,
                    'label'               => $label,
                    'agent_slug'          => $assignedAgent,
                    'credit_cost'         => (int) ($row->credit_cost ?? 0),
                    'created_at'          => $row->created_at,
                ];
            })->values();

            // Stats: one card per batch, plus the underlying task count.
            $taskTotal = 0;
            foreach ($approvals as $a) $taskTotal += $a['batch_count'];

            return response()->json([
                'approvals'        => $approvals,
                'count'            => count($approvals),
                'underlying_tasks' => $taskTotal,
            ]);
        });

        // v1.4.4 (2026-05-30) — Resolver wrapper. Mobile sends task_id in
        // the URL path; we resolve to the linked approval_id and delegate.
        // Backwards-compat: if the path id MATCHES an approval row directly
        // (e.g. a future mobile release that sends approval_id), we use it.
        Route::post('/tasks/{id}/approve', function (Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $req = $r->merge(['workspace_id' => $wsId]);

            $approval = \App\Models\Approval::where('workspace_id', $wsId)
                ->where('status', 'pending')
                ->where(function ($q) use ($id) {
                    $q->where('task_id', (int) $id)->orWhere('id', (int) $id);
                })
                ->orderByDesc('id')
                ->first();

            if (!$approval) {
                return response()->json([
                    'error' => 'approval_not_found',
                    'hint'  => 'No pending approval found for that task/approval id.',
                ], 404);
            }

            return response()->json(
                app(\App\Http\Controllers\Api\ApprovalController::class)
                    ->approve($req, $approval->id)
                    ->getData(true)
            );
        });

        Route::post('/tasks/{id}/reject', function (Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            // Accept both `note` (legacy mobile) and `reason` (Laravel
            // ApprovalController). Promote to `reason` so the controller's
            // required-reason validation passes.
            $reason = trim((string) ($r->input('reason') ?: $r->input('note') ?: 'Rejected from mobile'));
            $req = $r->merge(['workspace_id' => $wsId, 'reason' => $reason]);

            $approval = \App\Models\Approval::where('workspace_id', $wsId)
                ->where('status', 'pending')
                ->where(function ($q) use ($id) {
                    $q->where('task_id', (int) $id)->orWhere('id', (int) $id);
                })
                ->orderByDesc('id')
                ->first();

            if (!$approval) {
                return response()->json([
                    'error' => 'approval_not_found',
                    'hint'  => 'No pending approval found for that task/approval id.',
                ], 404);
            }

            return response()->json(
                app(\App\Http\Controllers\Api\ApprovalController::class)
                    ->reject($req, $approval->id)
                    ->getData(true)
            );
        });

        // ── Campaigns ─────────────────────────────────────────────
        Route::get('/campaigns/active', function (Request $r) {
            $campaigns = \Illuminate\Support\Facades\DB::table('campaigns')
                ->where('workspace_id', $r->attributes->get('workspace_id'))
                ->whereIn('status', ['active', 'scheduled', 'sending'])
                ->orderByDesc('updated_at')->limit(20)->get();
            return response()->json(['campaigns' => $campaigns]);
        });

        // ── Media Upload ───────────────────────────────────────────
        Route::post('/media/upload', [\App\Http\Controllers\Api\MediaController::class, 'upload']);

        // ── Arthur Executive Briefing ─────────────────────────────
        Route::get('/arthur/brief', function (Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $ws   = \App\Models\Workspace::find($wsId);

            $latestPlan = \Illuminate\Support\Facades\DB::table('execution_plans')
                ->where('workspace_id', $wsId)
                ->whereIn('status', ['draft', 'approved', 'executing'])
                ->orderByDesc('created_at')->first();

            $weeklyStats = [
                'completed' => \App\Models\Task::where('workspace_id', $wsId)
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', now()->subWeek())->count(),
                'pending' => \App\Models\Task::where('workspace_id', $wsId)
                    ->whereIn('status', ['pending', 'queued'])->count(),
                'failed' => \App\Models\Task::where('workspace_id', $wsId)
                    ->where('status', 'failed')
                    ->where('updated_at', '>=', now()->subWeek())->count(),
            ];

            $credit = \App\Models\Credit::where('workspace_id', $wsId)->first();

            return response()->json([
                'workspace'   => $ws?->business_name ?? $ws?->name,
                'latest_plan' => $latestPlan ? [
                    'goal'   => $latestPlan->goal ?? 'Active strategy',
                    'status' => $latestPlan->status,
                    'tasks'  => $latestPlan->task_count ?? 0,
                ] : null,
                'weekly_stats'      => $weeklyStats,
                'credit_balance'    => max(0, (int)($credit?->balance ?? 0) - (int)($credit?->reserved_balance ?? 0)),
                'pending_approvals' => \Illuminate\Support\Facades\DB::table('approvals')
                    ->where('workspace_id', $wsId)->where('status', 'pending')->count(),
                'generated_at' => now()->toISOString(),
            ]);
        });
    });
