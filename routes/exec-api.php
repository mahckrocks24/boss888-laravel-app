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

            $activeAgents = \Illuminate\Support\Facades\DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->whereIn('status', ['queued', 'in_progress'])
                ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(assigned_agents_json, "$[0]")) as agent')
                ->distinct()->pluck('agent')->filter()->values();

            $recentTasks = \App\Models\Task::where('workspace_id', $wsId)
                ->orderByDesc('created_at')->limit(5)
                ->get(['id', 'engine', 'action', 'status', 'created_at', 'completed_at']);

            $activeCampaigns = \Illuminate\Support\Facades\DB::table('campaigns')
                ->where('workspace_id', $wsId)->where('status', 'active')->count();

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
                'active_campaigns'  => $activeCampaigns,
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
            $service = app(\App\Core\Agent\AgentDispatchService::class);
            return response()->json($service->getConversation(
                $r->attributes->get('workspace_id'), (int) $id
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
        Route::get('/tasks/pending-approval', function (Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $approvals = \Illuminate\Support\Facades\DB::table('approvals')
                ->where('workspace_id', $wsId)->where('status', 'pending')
                ->orderByDesc('created_at')->limit(20)->get();
            return response()->json(['approvals' => $approvals, 'count' => $approvals->count()]);
        });

        Route::post('/tasks/{id}/approve', function (Request $r, $id) {
            return response()->json(
                app(\App\Http\Controllers\Api\ApprovalController::class)
                    ->approve($r->merge(['workspace_id' => $r->attributes->get('workspace_id')]), (int) $id)
                    ->getData(true)
            );
        });

        Route::post('/tasks/{id}/reject', function (Request $r, $id) {
            return response()->json(
                app(\App\Http\Controllers\Api\ApprovalController::class)
                    ->reject($r->merge(['workspace_id' => $r->attributes->get('workspace_id')]), (int) $id)
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
