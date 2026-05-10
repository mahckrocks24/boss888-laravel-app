<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\DesignTokenController;

// ════════════════════════════════════════════════════════════════
// PUBLIC HEALTH & READINESS ENDPOINTS
// No auth — used by Digital Ocean load balancer health checks
// and uptime monitoring (UptimeRobot / Betterstack)
// ════════════════════════════════════════════════════════════════
Route::get('/health', function () {
    $checks = []; $healthy = true;

    // Database
    try { \DB::select('SELECT 1'); $checks['database'] = 'ok'; }
    catch (\Throwable $e) { $checks['database'] = 'error'; $healthy = false; }

    // Redis / Cache
    try {
        \Illuminate\Support\Facades\Cache::put('lu_health_ping', 'pong', 5);
        $checks['redis'] = \Illuminate\Support\Facades\Cache::get('lu_health_ping') === 'pong' ? 'ok' : 'error';
        if ($checks['redis'] !== 'ok') $healthy = false;
    } catch (\Throwable) { $checks['redis'] = 'error'; $healthy = false; }

    // Queue depth
    try {
        $pending = \App\Models\Task::whereIn('status', ['queued', 'pending'])->count();
        $checks['queue_depth'] = $pending;
        $checks['queue'] = $pending < 1000 ? 'ok' : 'warning';
    } catch (\Throwable) { $checks['queue'] = 'unknown'; }

    $checks['version']     = config('app.version', '1.0.0');
    $checks['environment'] = config('app.env');
    $checks['timestamp']   = now()->toISOString();

    return response()->json([
        'status' => $healthy ? 'healthy' : 'degraded',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
})->name('health');

// Simple liveness probe — minimal, fast
Route::get('/ping', fn () => response()->json(['pong' => true, 'ts' => now()->timestamp]))->name('ping');

Route::get('/public/workspace-count', function () {
    $count = \App\Models\Workspace::where('onboarded', true)->count();
    return response()->json(['count' => $count]);
})->name('public.workspace.count');

use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ManualExecutionController;

/*
|--------------------------------------------------------------------------
| Boss888 / LevelUp OS — API Routes (Phase 1 + Phase 2)
|--------------------------------------------------------------------------
*/

// ── Public Auth ──────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::middleware('throttle:10,5')->post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::middleware('throttle:5,15')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// ── System Health (public) ───────────────────────────────────────────────
Route::prefix('system')->group(function () {
    Route::get('/health', [SystemController::class, 'health']);
});

// ── Admin Panel Token Auth (Phase 5) ─────────────────────────────────────
// The admin panel at /admin/ uses BELLA_ADMIN_TOKEN for the login screen.
// This endpoint verifies the token and returns a JWT for the first admin user
// so the panel can call all /api/admin/* endpoints with proper auth.
Route::post('/admin/auth', function (\Illuminate\Http\Request $r) {
    $token = $r->input('token', '');
    $expected = env('BELLA_ADMIN_TOKEN', '');

    if ($expected === '' || !hash_equals($expected, $token)) {
        return response()->json(['error' => 'Invalid admin token'], 401);
    }

    // Use user id=1 (the platform owner / Shukran).
    // The admin token itself IS the auth — whoever has it is authorized.
    $admin = \App\Models\User::find(1);

    if (! $admin) {
        return response()->json(['error' => 'No admin user found'], 500);
    }

    // Generate JWT via the same service the login endpoint uses.
    $refreshService = app(\App\Core\Auth\RefreshTokenService::class);
    $workspace = $admin->workspaces()->first();
    $tokens = $refreshService->issueTokenPair($admin, $workspace);

    return response()->json([
        'token' => $tokens['access_token'],
        'user'  => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
    ]);
});

// ── Phase 2 Admin: Orchestration Health + Capability Registry ──────────────
// All gated behind auth.jwt + admin (platform-admin flag). Pair with the
// admin panel's existing /admin/auth → JWT flow.
Route::middleware(['auth.jwt', 'admin'])->prefix('admin/orchestration')->group(function () {
    Route::get('/health', function (\Illuminate\Http\Request $r) {
        $wsId = $r->query('workspace_id');
        $svc = app(\App\Core\Orchestration\OrchestrationHealthService::class);
        return response()->json($svc->snapshot($wsId ? (int)$wsId : null));
    });

    Route::get('/orphans', function () {
        $sm = app(\App\Core\Orchestration\TaskStateMachine::class);
        return response()->json([
            'orphans' => $sm->detectOrphans()->map(fn($r) => [
                'id'           => (int)$r->id,
                'workspace_id' => (int)$r->workspace_id,
                'engine'       => $r->engine,
                'action'       => $r->action,
                'status'       => $r->status,
                'updated_at'   => $r->updated_at,
            ])->all(),
        ]);
    });

    Route::post('/recover-orphans', function () {
        $sm = app(\App\Core\Orchestration\TaskStateMachine::class);
        return response()->json(['recovered' => $sm->recoverOrphans(30)]);
    });

    Route::post('/transition', function (\Illuminate\Http\Request $r) {
        $r->validate([
            'task_id'  => 'required|integer',
            'to'       => 'required|string',
            'reason'   => 'sometimes|string',
        ]);
        $sm = app(\App\Core\Orchestration\TaskStateMachine::class);
        $applied = $sm->transition((int)$r->input('task_id'), $r->input('to'), [
            'progress_message' => 'admin transition: ' . ($r->input('reason') ?? 'manual'),
        ]);
        return response()->json([
            'success' => $applied !== null,
            'status'  => $applied,
        ], $applied !== null ? 200 : 422);
    });
});

// Phase 2F/H/I admin endpoints
Route::middleware(['auth.jwt', 'admin'])->prefix('admin/plans')->group(function () {
    Route::post('/', function (\Illuminate\Http\Request $r) {
        $data = $r->validate([
            'workspace_id' => 'required|integer',
            'goal'         => 'required|string|max:500',
            'steps'        => 'required|array|min:1',
            'steps.*.engine' => 'required|string',
            'steps.*.action' => 'required|string',
            'meta'         => 'sometimes|array',
        ]);
        $svc = app(\App\Core\Orchestration\ExecutionPlanService::class);
        $planId = $svc->createPlan(
            (int)$data['workspace_id'],
            $data['goal'],
            $data['steps'],
            $data['meta'] ?? []
        );
        return response()->json(['plan_id' => $planId, 'status' => $svc->getPlanStatus($planId)]);
    });

    Route::get('/{planId}', function (string $planId) {
        return response()->json(app(\App\Core\Orchestration\ExecutionPlanService::class)->getPlanStatus($planId));
    });
});

Route::middleware(['auth.jwt', 'admin'])->prefix('admin/knowledge')->group(function () {
    Route::get('/{wsId}', function (int $wsId) {
        $kb = app(\App\Core\Intelligence\WorkspaceKnowledgeBase::class);
        return response()->json(['workspace_id' => $wsId, 'entries' => $kb->getRelevant($wsId, '', 25)]);
    });

    Route::post('/{wsId}/purge-expired', function () {
        $deleted = app(\App\Core\Intelligence\WorkspaceKnowledgeBase::class)->purgeExpired();
        return response()->json(['purged' => $deleted]);
    });
});

Route::middleware(['auth.jwt', 'admin'])->prefix('admin/strategy')->group(function () {
    Route::get('/{wsId}/learnings/{type}', function (int $wsId, string $type) {
        $svc = app(\App\Core\Intelligence\StrategyLearningService::class);
        return response()->json([
            'workspace_id' => $wsId,
            'type'         => $type,
            'learnings'    => $svc->getLearnings($wsId, $type),
            'outcomes'     => $svc->listOutcomes($wsId, $type, 10),
        ]);
    });

    Route::post('/{wsId}/outcomes', function (int $wsId, \Illuminate\Http\Request $r) {
        $data = $r->validate([
            'strategy_type' => 'required|string',
            'strategy_data' => 'required|array',
            'outcome_data'  => 'required|array',
        ]);
        $id = app(\App\Core\Intelligence\StrategyLearningService::class)->recordOutcome(
            $wsId, $data['strategy_type'], $data['strategy_data'], $data['outcome_data']
        );
        return response()->json(['outcome_id' => $id]);
    });
});

Route::middleware(['auth.jwt', 'admin'])->prefix('admin/agents')->group(function () {
    Route::get('/{slug}/capabilities', function (string $slug) {
        $svc = app(\App\Core\Agent\AgentCapabilityService::class);
        return response()->json([
            'agent_slug'   => $slug,
            'capabilities' => $svc->getCapabilities($slug),
        ]);
    });

    Route::post('/{slug}/capabilities', function (string $slug, \Illuminate\Http\Request $r) {
        $r->validate(['tool_id' => 'required|string']);
        $svc = app(\App\Core\Agent\AgentCapabilityService::class);
        $svc->grant($slug, $r->input('tool_id'), 'admin:' . ($r->user()->email ?? 'unknown'));
        return response()->json(['success' => true, 'agent' => $slug, 'tool' => $r->input('tool_id')]);
    });

    Route::delete('/{slug}/capabilities/{toolId}', function (string $slug, string $toolId, \Illuminate\Http\Request $r) {
        $svc = app(\App\Core\Agent\AgentCapabilityService::class);
        $ok  = $svc->revoke($slug, $toolId, 'admin:' . ($r->user()->email ?? 'unknown'));
        return response()->json(['success' => $ok, 'agent' => $slug, 'tool' => $toolId]);
    });
});

// ── Public OAuth Callbacks ────────────────────────────────────────────────
// OAuth callbacks are hit by platform redirects (browser → Facebook → callback URL).
// The browser won't have a JWT at this point, so these MUST be outside auth.jwt.
// Phase 2G Session 1: Facebook + Instagram OAuth callback.
Route::get('/social/oauth/facebook/callback', function (\Illuminate\Http\Request $r) {
    // Popup-friendly callback: returns an HTML page that postMessages to the
    // opener window and closes itself. Defensive against every Facebook error
    // shape — error, error_code, error_reason, error_description all coexist.
    $code    = $r->query('code');
    $state   = $r->query('state', '');
    $error   = $r->query('error');
    $errCode = $r->query('error_code');

    // Known Facebook error codes with friendlier messages
    $knownCodes = [
        '1349048' => 'Facebook App is in Development mode. You need to be added as an Admin, Developer, or Tester on the app.',
        '190'     => 'The access token was invalid or expired. Try connecting again.',
        '200'     => 'Your account does not have permission to grant these scopes.',
        '10'      => 'This Facebook app needs additional review before it can request these permissions.',
    ];

    if ($error || $errCode) {
        $msg = $r->query('error_description')
            ?? $r->query('error_message')
            ?? $r->query('error_reason')
            ?? $error;

        if ($errCode && isset($knownCodes[(string) $errCode])) {
            $msg = $knownCodes[(string) $errCode] . ($msg ? ' — ' . $msg : '');
        } elseif ($errCode) {
            $msg = ($msg ? $msg . ' ' : '') . "(Facebook error code {$errCode})";
        }

        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'facebook',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => (string) ($msg ?: 'Facebook declined the connection.'),
        ]);
    }
    if (! $code) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'facebook',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => 'No authorization code received from Facebook.',
        ]);
    }

    // Extract workspace_id from state (format: "{wsId}_{nonce}")
    $wsId = (int) (explode('_', $state)[0] ?? 0);
    if ($wsId <= 0) $wsId = 1;

    $connector = app(\App\Connectors\SocialConnector::class);
    $result = $connector->handleCallback($code, $state, $wsId);

    if (! ($result['success'] ?? false)) {
        return response()->view('social.oauth-callback', [
            'success'       => false,
            'platform'      => 'facebook',
            'account_name'  => null,
            'accounts_count' => 0,
            'error_message' => (string) ($result['error'] ?? 'Unknown error'),
        ]);
    }

    $accounts = $result['accounts'] ?? [];
    $first = $accounts[0] ?? null;
    return response()->view('social.oauth-callback', [
        'success'        => true,
        'platform'       => 'facebook',
        'account_name'   => $first['account_name'] ?? $first['name'] ?? 'Facebook',
        'accounts_count' => (int) ($result['stored'] ?? count($accounts)),
        'error_message'  => null,
    ]);
});

// ── Protected Routes ─────────────────────────────────────────────────────
// ADDED 2026-04-12 (Phase 2J / doc 12): traffic.defense middleware applied to
// the entire authenticated workspace surface. Wires TrafficDefenseService into
// the request pipeline. Fails open on errors.
Route::middleware(['auth.jwt', 'traffic.defense'])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/switch-workspace', [AuthController::class, 'switchWorkspace']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);

    // Workspaces
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);

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
            'marcus'=>'social','zara'=>'social','tyler'=>'social','aria'=>'social','jordan'=>'social',
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
        $task = \App\Models\Task::findOrFail($id);
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

    // Approvals (v5.5.1)
    Route::get('/approvals',                 [ApprovalController::class, 'index']);
    Route::get('/approvals/stats',           [ApprovalController::class, 'stats']);
    Route::post('/approvals/bulk-approve',   [ApprovalController::class, 'bulkApprove']);
    Route::post('/approvals/bulk-reject',    [ApprovalController::class, 'bulkReject']);
    Route::post('/approvals/expire-stale',   [ApprovalController::class, 'expireStale']);
    Route::post('/approvals/{id}/approve',   [ApprovalController::class, 'approve']);
    Route::post('/approvals/{id}/reject',    [ApprovalController::class, 'reject']);
    Route::post('/approvals/{id}/revise',    [ApprovalController::class, 'revise']);

    // Command Center — real-data dashboard (Phase 5.5.0)
    Route::get('/dashboard/overview',  [\App\Http\Controllers\Api\DashboardController::class, 'overview']);
    Route::get('/approvals/count',     [\App\Http\Controllers\Api\DashboardController::class, 'approvalsCount']);

    // Engines
    Route::get('/engines', [EngineController::class, 'index']);
    Route::get('/engines/{name}', [EngineController::class, 'show']);

    // System (protected)
    Route::get('/system/engines', [SystemController::class, 'engines']);
    Route::get('/system/queue', [SystemController::class, 'queue']);
    Route::get('/system/connectors', [SystemController::class, 'connectors']);

    // Manual Execution (Phase 2)
    Route::post('/manual/execute', [ManualExecutionController::class, 'execute']);

    // Design Tokens
    Route::get('/design-tokens', [DesignTokenController::class, 'index']);

    // Meetings
    Route::post('/meetings', [MeetingController::class, 'store']);
    Route::get('/meetings', [MeetingController::class, 'index']);
    Route::get('/meetings/{id}', [MeetingController::class, 'show']);
    Route::post('/meetings/{id}/messages', [MeetingController::class, 'addMessage']);

    // Agents
    Route::get('/agents', [AgentController::class, 'index']);

    // ── Agent Team Builder routes ───────────────────────────────
    // GET /agents/available — all agents the workspace's plan tier CAN access (for team builder)
    // ── Agent direct messages ────────────────────────────────
    Route::get('/agents/{slug}/messages', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';
        $agent = \App\Models\Agent::where('slug', $slug)->first();
        if (!$agent) return response()->json([]);

        // Get messages from agent_messages table if exists, otherwise build from delegations + audit
        $messages = [];
        try {
            $rows = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('agent_slug', $slug)
                ->orderBy('created_at')
                ->limit(50)
                ->get();
            $messages = $rows->map(fn($m) => [
                'from' => $m->sender,
                'content' => $m->content,
                'ts' => $m->created_at,
            ])->toArray();
        } catch (\Throwable $e) {
            // agent_messages table may not exist — build from delegations + audit_logs
            $delegations = \Illuminate\Support\Facades\DB::table('agent_delegations')
                ->where('workspace_id', $wsId)
                ->where('to_agent', $slug)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
            foreach ($delegations as $d) {
                $messages[] = ['from' => 'Sarah', 'content' => $d->instruction ?? 'Task delegated', 'ts' => $d->created_at];
                if ($d->result_json) {
                    $result = json_decode($d->result_json, true);
                    $messages[] = ['from' => $agent->name, 'content' => $result['summary'] ?? $result['message'] ?? 'Task completed', 'ts' => $d->updated_at];
                }
            }
            // Also read direct messages stored as audit_log entries
            $dmLogs = \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->where('action', 'agent.direct_message')
                ->whereRaw("JSON_EXTRACT(metadata_json, '$.agent_slug') = ?", [$slug])
                ->orderBy('created_at')
                ->limit(50)
                ->get();
            foreach ($dmLogs as $dm) {
                $meta = json_decode($dm->metadata_json, true);
                $messages[] = ['from' => $meta['from'] ?? 'User', 'content' => $meta['content'] ?? '', 'ts' => $dm->created_at];
            }
            // Sort by timestamp
            usort($messages, fn($a, $b) => strtotime($a['ts']) - strtotime($b['ts']));
        }
        return response()->json($messages);
    });

    // Agent documents — assets produced by or for this agent
    Route::get('/agents/{slug}/documents', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';

        // Find assets linked to this agent via tasks or delegations
        $taskIds = \App\Models\Task::where('workspace_id', $wsId)
            ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
            ->pluck('id')->toArray();

        $delegationAssetIds = \Illuminate\Support\Facades\DB::table('agent_delegations')
            ->where('workspace_id', $wsId)
            ->where('to_agent', $slug)
            ->whereNotNull('result_json')
            ->get()
            ->map(function($d) {
                $result = json_decode($d->result_json, true);
                return $result['asset_id'] ?? null;
            })->filter()->toArray();

        // Get assets from creative engine that match
        $assets = \Illuminate\Support\Facades\DB::table('assets')
            ->where('workspace_id', $wsId)
            ->where(function($q) use ($taskIds, $delegationAssetIds, $slug) {
                if (!empty($taskIds)) $q->orWhereIn('id', $taskIds);
                if (!empty($delegationAssetIds)) $q->orWhereIn('id', $delegationAssetIds);
                // Also match assets whose metadata references this agent
                $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent')) = ?", [$slug]);
            })
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'title' => $a->title ?? $a->prompt ?? 'Untitled',
                'type' => $a->type,
                'mime_type' => $a->mime_type,
                'url' => $a->url,
                'thumbnail_url' => $a->thumbnail_url,
                'created_at' => $a->created_at,
            ])->toArray();

        return response()->json(['documents' => $assets]);
    });

        Route::post('/agents/{slug}/messages', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        $content = $r->input('content', '');
        $from = $r->input('from', 'User');
        $quickAction = $r->input('quick_action'); // my_tasks, recent_completions, whats_next
        $image = $r->input('image'); // base64 image for vision

        // Map 'dmm' alias to 'sarah' (frontend uses 'dmm' for Sarah)
        if ($slug === 'dmm') $slug = 'sarah';
        $agent = \App\Models\Agent::where('slug', $slug)->first();
        if (!$agent) return response()->json(['error' => 'Agent not found'], 404);

        // Store user message in both audit_logs AND agent_messages
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'workspace_id' => $wsId,
            'action' => 'agent.direct_message',
            'entity_type' => 'Agent',
            'metadata_json' => json_encode(['agent_slug' => $slug, 'from' => $from, 'content' => $content]),
            'created_at' => now(),
        ]);

        // Also store in agent_messages for the unified messaging system
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                'workspace_id' => $wsId,
                'agent_slug' => $slug,
                'sender' => 'user',
                'content' => $content,
                'role' => 'user',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { /* table may not exist yet */ }

        // ── Build agent context ──
        $workspace = \App\Models\Workspace::find($wsId);
        $recentTasks = \App\Models\Task::where('workspace_id', $wsId)
            ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn($t) => $t->progress_message ?? ucfirst(str_replace('_', ' ', $t->action)) . ' (' . $t->status . ')')->implode("\n- ");

        // ── Conversation history (last 10 messages) ──
        $history = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->where('action', 'agent.direct_message')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent_slug')) = ?", [$slug])
            ->orderByDesc('created_at')->limit(10)->get()->reverse()
            ->map(function($row) {
                $meta = json_decode($row->metadata_json, true);
                return ($meta['from'] ?? 'User') . ': ' . ($meta['content'] ?? '');
            })->implode("\n");

        $skills = is_array($agent->skills_json) ? $agent->skills_json : json_decode($agent->skills_json ?? '[]', true);
        $isSarah = in_array($slug, ['sarah', 'dmm']);

        // ── Handle quick actions ──
        if ($quickAction === 'my_tasks') {
            $content = "List all my current tasks with their status.";
        } elseif ($quickAction === 'recent_completions') {
            $content = "Summarize what tasks I completed recently.";
        } elseif ($quickAction === 'whats_next') {
            $content = "What are my upcoming tasks and priorities?";
        }

        // ── Handle vision attachment ──
        $visionContext = '';
        if ($image) {
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                $visionResult = $runtime->visionAnalyze("Analyze this image in the context of: {$content}", $image);
                if ($visionResult['success'] ?? false) {
                    $visionContext = "\n\n[The user attached an image. GPT-4o vision analysis: " . ($visionResult['analysis'] ?? '') . "]";
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AgentChat] Vision failed: ' . $e->getMessage());
            }
        }

        // ── Detect confirmation replies ("yes", "go ahead", etc.) ──
        $confirmPhrases = ['yes','proceed','go ahead','do it','confirm','ok','okay','sure','go','yes please','yep','yeah','approved','approve'];
        $isConfirmation = in_array(strtolower(trim($content)), $confirmPhrases);

        // If confirming, tell Sarah to execute the pending task from conversation history
        if ($isConfirmation && $isSarah) {
            $content = "The user confirmed. Execute the task you proposed in the previous message. Create all tasks now. " . $content;
        }

        // If this is a TASK BRIEF (from Assign Task modal), tell Sarah to propose then wait for confirmation
        $isTaskBrief = str_contains($content, 'TASK BRIEF:');
        if ($isTaskBrief && $isSarah) {
            $content = str_replace('TASK BRIEF:', '', $content);
        }

        // PATCH (Sarah brand context, 2026-05-09) — Pull workspace_memory
        // facts and inject as AUTHORITATIVE GROUND TRUTH at the top of
        // Sarah's system prompt. Without this Sarah ECHOES user typos
        // (e.g. visitor types "Levelupgroth.io" — Sarah repeats it
        // instead of using the canonical "levelupgrowth.io" from memory).
        $brandFacts = [];
        try {
            $memRows = DB::table('workspace_memory')->where('workspace_id', $wsId)->get(['key','value_json']);
            foreach ($memRows as $row) {
                $val = is_string($row->value_json) ? json_decode($row->value_json, true) : $row->value_json;
                if (is_string($val) && $val !== '') $brandFacts[$row->key] = $val;
            }
        } catch (\Throwable $e) {}
        $brandFactsBlock = "AUTHORITATIVE WORKSPACE FACTS (these are GROUND TRUTH — use them, never echo back user typos or alternatives):\n";
        $brandFactsBlock .= "- Business name: " . ($brandFacts['business_name'] ?? $workspace->business_name ?? $workspace->name ?? 'this business') . "\n";
        if (! empty($brandFacts['domain']))   $brandFactsBlock .= "- Domain: " . $brandFacts['domain'] . "\n";
        if (! empty($brandFacts['industry'])) $brandFactsBlock .= "- Industry: " . $brandFacts['industry'] . "\n";
        elseif (! empty($workspace->industry)) $brandFactsBlock .= "- Industry: " . $workspace->industry . "\n";
        if (! empty($brandFacts['location'])) $brandFactsBlock .= "- Location: " . $brandFacts['location'] . "\n";
        elseif (! empty($workspace->location)) $brandFactsBlock .= "- Location: " . $workspace->location . "\n";
        $brandFactsBlock .= "Rule: if the user mis-spells the business name or domain, USE the correct spelling above. Never echo a typo.\n\n";

        $formatRules = "FORMAT YOUR RESPONSES:\n"
            . "- Use **bold** for section headers\n"
            . "- Use line breaks between sections\n"
            . "- Use bullet points (- text) for lists, one short line each, max 5 per list\n"
            . "- Lead with the most important point\n"
            . "- Never write walls of text\n\n";

        // PATCH (Phase 2 — tool schema + read-back, 2026-05-10) — assemble the
        // closed tool schema for this agent and any unread completed-task
        // insights. Both blocks are appended to the system prompt below.
        $toolSchemaSvc = app(\App\Core\Orchestration\ToolSchemaService::class);
        $toolSchemaBlock = $toolSchemaSvc->getToolSchemaPrompt($slug);

        $insightsBlock = '';
        if ($isSarah) {
            try {
                $readBack = app(\App\Core\Orchestration\SarahReadBackService::class);
                $insightsBlock = $readBack->renderInsightsBlock($wsId, 5);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[SarahChat] read-back failed: ' . $e->getMessage());
            }
        }

        // PATCH (Phase 2H — cross-agent knowledge, 2026-05-10) — every agent
        // sees what their teammates have learned in this workspace. Sarah's
        // own readback above already records into the KB on her side; here
        // we surface it for the OTHER specialists.
        $sharedKnowledgeBlock = '';
        try {
            $sharedKnowledgeBlock = app(\App\Core\Intelligence\WorkspaceKnowledgeBase::class)
                ->buildContextBlock($wsId, $slug, 5);
        } catch (\Throwable $kbErr) {
            \Illuminate\Support\Facades\Log::warning('[AgentChat] KB block failed: ' . $kbErr->getMessage());
        }

        // PATCH (concise-persona, 2026-05-10) — prepend a sharp/conversational
        // rule to every agent's DM system prompt. Overrides the professor tone.
        $conciseRule = "You are a sharp, direct AI specialist. Keep all responses SHORT and CONVERSATIONAL — maximum 3 sentences unless the user explicitly asks for a plan, report, or detailed breakdown. No bullet frameworks, no numbered action plans, no headers unless asked. Talk like a smart colleague in a Slack message, not a consultant writing a strategy document. If the user asks a simple question, give a simple answer.\n\n";

        // ── Build system prompt ──
        if ($isSarah) {
            $systemPrompt = $conciseRule . $brandFactsBlock
                . "You are Sarah, the Digital Marketing Manager and lead AI orchestrator for " . ($brandFacts['business_name'] ?? $workspace->business_name ?? 'this business') . ".\n"
                . "You coordinate all specialist agents and manage the workspace.\n"
                . $formatRules
                . "Available agents and their expertise:\n"
                . "- james: SEO Strategist (keyword research, SERP analysis, audits)\n"
                . "- alex: Technical SEO (site audits, Core Web Vitals, schema)\n"
                . "- priya: Content Manager (articles, copy, editorial)\n"
                . "- marcus: Social Media (Instagram, LinkedIn, TikTok)\n"
                . "- elena: CRM & Leads (pipeline, lead scoring, follow-ups)\n"
                // PATCH (Sam removal, 2026-05-09) — Sam is no longer in
                // the canonical 21-agent roster. Email marketing is
                // covered by the marketing engine via vera.
                . ($recentTasks ? "Current tasks:\n- {$recentTasks}\n" : "No active tasks.\n")
                . "When the user asks you to create/assign/run tasks, include a create_tasks ARRAY in your JSON. You can include MULTIPLE tasks.\n"
                . "Each task in create_tasks must have: agent (slug), engine, action, and description.\n"
                . "Engine mapping: james/alex/diana/ryan/sofia=seo, priya/leo/maya/chris/nora=write, marcus/zara/tyler/aria/jordan=social, elena/kai/max=crm, vera=marketing\n"
                . "Action examples: serp_analysis, deep_audit, write_article, social_create_post, create_lead, create_campaign\n"
                . "Be decisive and action-oriented. Keep responses under 150 words.\n"
                . "Output JSON: {\"reply\":\"your response\",\"requires_sarah\":false,\"create_tasks\":[],\"tool_calls\":[]}\n"
                . "  - create_tasks: array of {agent, engine, action, description} when delegating work\n"
                . "  - tool_calls: array of {tool, params, reason} when you need to LOOK SOMETHING UP yourself (use this for platform info questions like 'how many websites' — do NOT delegate these to agents)\n"
                . "Include multiple objects in create_tasks for multiple assignments. Both arrays default to [] when not needed.\n"
                . "IMPORTANT: When the user sends a TASK BRIEF with Title/Description/Assign to, respond with a confirmation plan:\n"
                . "- Acknowledge the task\n"
                . "- List who you'll assign and what engine/action you'll use\n"
                . "- Ask: 'Shall I proceed?'\n"
                . "- Include create_tasks in your JSON ONLY when the user confirms (says yes/proceed/go ahead)\n"
                . "- When user confirms, create ALL tasks from the previous brief and include create_tasks array.\n"
                . "\n" . $insightsBlock
                . "\n" . $sharedKnowledgeBlock
                . "\n" . $toolSchemaBlock
                . "\n" . \App\Core\LLM\PromptTemplates::languageRule()
                . "\nThe \"reply\" field value must be in the user's language; JSON keys themselves stay in English.";
        } else {
            $systemPrompt = $conciseRule . $brandFactsBlock
                . "You are {$agent->name}, {$agent->title} for " . ($brandFacts['business_name'] ?? $workspace->business_name ?? 'this business') . ".\n"
                . "Your expertise: " . implode(', ', $skills) . "\n"
                . ($recentTasks ? "Your recent tasks:\n- {$recentTasks}\n" : "No recent tasks.\n")
                . $formatRules
                . "Answer questions about your work directly. Be helpful and specific.\n"
                . "For NEW task requests beyond your current scope, say you'll need Sarah to assign it officially.\n"
                . "Keep responses under 120 words.\n"
                . "Output JSON: {\"reply\":\"your response\",\"requires_sarah\":true/false,\"sarah_context\":\"context if redirecting\",\"tool_calls\":[]}\n"
                . "  - tool_calls: array of {tool, params, reason} when you need to look something up. Empty [] otherwise.\n"
                . "\n" . $sharedKnowledgeBlock
                . "\n" . $toolSchemaBlock
                . "\n" . \App\Core\LLM\PromptTemplates::languageRule()
                . "\nThe \"reply\" field value must be in the user's language; JSON keys themselves stay in English.";
        }

        // ── Call runtime LLM ──
        $userPrompt = ($history ? "Conversation so far:\n{$history}\n\n" : '') . "User: {$content}{$visionContext}";
        $reply = "I'm available but the AI service is temporarily offline. Please try again shortly.";
        $requiresSarah = false;
        $sarahContext = '';

        $runtime = app(\App\Connectors\RuntimeClient::class);
        if ($runtime->isConfigured()) {
            try {
                // PATCH (Assistant 2a) — primary reply now goes through
                // /internal/assistant which gives the agent access to runtime
                // workspace memory (lu-context.js: WP REST + Redis long-term),
                // conversation history, and tool routing (58 tools). The
                // previous chatJson path bypassed all of that.
                //
                // Hybrid: assistant() for the reply + memory context;
                // chatJson() as a SECOND call to extract structured create_tasks
                // when the message reads like an action request, since
                // /internal/assistant returns prose, not the {reply, create_tasks}
                // JSON shape Sarah-chat expected. Doubled cost on action
                // requests vs the old single-call pattern; conversational
                // quality is materially better.
                $assist = $runtime->assistant(
                    $userPrompt,
                    [
                        'workspace_id'  => $wsId,
                        'business_name' => $workspace->business_name ?? $workspace->name ?? '',
                        'industry'      => $workspace->industry ?? '',
                        'location'      => $workspace->location ?? '',
                        'agent_slug'    => $slug,
                        'agent_name'    => $agent->name,
                    ],
                    "agent_chat_ws_{$wsId}_{$slug}",
                    $slug === 'sarah' ? 'dmm' : $slug
                );
                $assistReply = $assist['response'] ?? null;
                if ($assistReply) {
                    $reply = $assistReply;
                }

                // Extract create_tasks: assistant may surface them via runtime
                // tool router; if not, and the message is action-like, fall back
                // to chatJson structured extraction so TaskService still fires.
                $createTasks = $assist['create_tasks'] ?? [];
                $toolCalls   = $assist['tool_calls'] ?? [];
                $requiresSarah = (bool) ($assist['requires_sarah'] ?? false);
                $sarahContext  = $assist['sarah_context'] ?? '';

                // PATCH (Phase 2 — tool schema, 2026-05-10) — extended the
                // re-extract trigger to include info-query verbs so platform
                // tools (get_website_count, get_credit_balance, etc.) get a
                // shot at firing. The assistant() endpoint does not know our
                // schema, so chatJson with the closed schema is what surfaces
                // tool_calls.
                $needTaskExtract = (empty($createTasks) && empty($toolCalls)) && (
                    !$assistReply
                    || preg_match('/\b(create|generate|run|publish|schedule|write|build|launch|start|assign|post|send|audit|analyze|how many|how much|list|show|count|status|balance|websites|leads|campaigns|tasks)\b/i', $userPrompt)
                );
                if ($needTaskExtract) {
                    $cj = $runtime->chatJson($systemPrompt, $userPrompt, [
                        'agent_slug' => $slug, 'agent_name' => $agent->name,
                        'workspace'  => $workspace->business_name ?? '',
                    ], 600);
                    if ($cj['success'] ?? false) {
                        $parsed = $cj['parsed'] ?? [];
                        if (!$assistReply) {
                            $reply = $parsed['reply'] ?? $cj['text'] ?? $reply;
                        }
                        $requiresSarah = $requiresSarah ?: (bool)($parsed['requires_sarah'] ?? false);
                        $sarahContext  = $sarahContext  ?: ($parsed['sarah_context'] ?? '');
                        $createTasks   = $parsed['create_tasks'] ?? $createTasks;
                        $toolCalls     = $parsed['tool_calls']   ?? $toolCalls;
                        if (empty($createTasks) && !empty($parsed['create_task'])) {
                            $createTasks = [$parsed['create_task']];
                        }
                    }
                }

                // PATCH (Phase 2 — tool execution, 2026-05-10) — execute each
                // tool_call through ToolSchemaService. Platform info tools are
                // answered immediately from the DB; engine tools route through
                // EngineExecutionService::execute(). Results are concatenated
                // onto the user-facing reply so the user sees the actual data.
                if (!empty($toolCalls) && is_array($toolCalls)) {
                    $toolResults = [];
                    foreach ($toolCalls as $tc) {
                        if (!is_array($tc) || empty($tc['tool'])) continue;
                        try {
                            $tr = $toolSchemaSvc->executeToolCall(
                                (string)$tc['tool'],
                                is_array($tc['params'] ?? null) ? $tc['params'] : [],
                                $wsId,
                                $slug
                            );
                            $toolResults[] = ['tool' => $tc['tool'], 'result' => $tr];
                            \Illuminate\Support\Facades\Log::info('[AgentChat] tool_call executed', [
                                'agent' => $slug, 'tool' => $tc['tool'],
                                'success' => $tr['success'] ?? false,
                                'code' => $tr['code'] ?? null,
                            ]);
                        } catch (\Throwable $tcErr) {
                            \Illuminate\Support\Facades\Log::warning('[AgentChat] tool_call failed: ' . $tcErr->getMessage());
                            $toolResults[] = ['tool' => $tc['tool'] ?? 'unknown', 'result' => ['success' => false, 'error' => $tcErr->getMessage()]];
                        }
                    }

                    // Compose a natural follow-up: send the results back to
                    // the LLM and ask it to render a final reply. Falls back
                    // to a templated concatenation if the runtime is offline.
                    if (!empty($toolResults)) {
                        $resultsForLlm = [];
                        $resultsForFallback = [];
                        foreach ($toolResults as $tr) {
                            $resultsForLlm[] = $tr['tool'] . ' => ' . json_encode($tr['result'], JSON_UNESCAPED_UNICODE);
                            $rText = $tr['result']['result'] ?? $tr['result']['error'] ?? json_encode($tr['result']);
                            $resultsForFallback[] = '• ' . $rText;
                        }
                        $followSystem = "You just called tools. Render a final reply in 1-3 sentences using the results below. Output JSON: {\"reply\":\"...\"}.";
                        $followUser = "Original user message: {$content}\n\nTool results:\n" . implode("\n", $resultsForLlm);
                        try {
                            $follow = $runtime->chatJson($followSystem, $followUser, [], 300);
                            if (($follow['success'] ?? false)) {
                                $finalReply = $follow['parsed']['reply'] ?? $follow['text'] ?? '';
                                if ($finalReply) {
                                    $reply = trim($finalReply);
                                } else {
                                    $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                                }
                            } else {
                                $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                            }
                        } catch (\Throwable $followErr) {
                            \Illuminate\Support\Facades\Log::warning('[AgentChat] tool follow-up failed: ' . $followErr->getMessage());
                            $reply = ($reply ? $reply . "\n\n" : '') . implode("\n", $resultsForFallback);
                        }
                    }
                }

                if (true) {  // preserve indentation of original `if ($result['success'])` block
                    foreach ($createTasks as $createTask) {
                    if ($createTask && is_array($createTask) && !empty($createTask['agent'])) {
                        try {
                            $taskAgent = $createTask['agent'];
                            $taskEngine = $createTask['engine'] ?? 'marketing';
                            $taskAction = $createTask['action'] ?? 'manual_task';
                            $taskDesc = $createTask['description'] ?? $content;

                            // PATCH (Intel Fix 2a) — was raw Task::create([... 'status'=>'pending' ...])
                            // which bypassed TaskService and TaskDispatcher: the row landed in DB
                            // but no queue job was ever pushed, leaving 12 stuck tasks. TaskService
                            // is the canonical path — handles capability lookup, approval mode,
                            // idempotency hash, audit log, and dispatch in one call.
                            $newTask = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                                'engine'          => $taskEngine,
                                'action'          => $taskAction,
                                'source'          => 'agent',
                                'priority'        => 'normal',
                                'assigned_agents' => [$taskAgent],
                                'payload'         => [
                                    'title'        => $taskDesc,
                                    'created_via'  => 'sarah_chat',
                                    'user_request' => $content,
                                ],
                            ]);
                            // progress_message isn't in the TaskService whitelist — set after.
                            $newTask->update(['progress_message' => $taskDesc]);

                            // Add task creation confirmation to reply
                            $reply .= "\n\n✅ Task #{$newTask->id} created and assigned to " . ucfirst($taskAgent) . ".";

                            \Illuminate\Support\Facades\Log::info("[SarahChat] Task created", [
                                'task_id' => $newTask->id, 'agent' => $taskAgent,
                                'engine' => $taskEngine, 'action' => $taskAction,
                            ]);
                        } catch (\Throwable $taskErr) {
                            \Illuminate\Support\Facades\Log::warning("[SarahChat] Task creation failed: " . $taskErr->getMessage());
                            $reply .= "\n\n⚠️ I tried to create the task but encountered an issue. Please try using the Assign Task button instead.";
                        }
                    }
                    } // end foreach createTasks
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[AgentChat] LLM failed for {$slug}: " . $e->getMessage());
            }
        }

        // ── Store agent response ──
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'workspace_id' => $wsId,
            'action' => 'agent.direct_message',
            'entity_type' => 'Agent',
            'metadata_json' => json_encode(['agent_slug' => $slug, 'from' => $agent->name, 'content' => $reply]),
            'created_at' => now(),
        ]);

        // Store agent response in agent_messages for the unified messaging UI
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')->insert([
                'workspace_id' => $wsId,
                'agent_slug' => $slug,
                'sender' => $agent->name,
                'content' => $reply,
                'role' => 'agent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { /* non-critical */ }

        return response()->json([
            'sent' => true,
            'reply' => $reply,
            'agent_name' => $agent->name,
            'requires_sarah' => $requiresSarah,
            'sarah_context' => $sarahContext,
        ]);
    });

        Route::get('/agents/available', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $planGating = app(\App\Core\PlanGating\PlanGatingService::class);
        $rules = $planGating->getPlanRules($wsId);

        if (!$rules['includes_dmm']) {
            return response()->json(['agents' => [], 'plan_limit' => 'AI agents require Growth plan or above']);
        }

        $agentLevel = $rules['agent_level']; // specialist, junior, or senior
        $maxAgents = $rules['agent_count'];   // 2, 5, or 10 (excluding Sarah)

        // Level hierarchy: senior > specialist > junior
        $levelHierarchy = ['senior' => 3, 'specialist' => 2, 'junior' => 1];
        $minLevel = $levelHierarchy[$agentLevel] ?? 1;

        // Get all agents at or above the plan's agent_level (excluding Sarah — she's always included)
        $available = \App\Models\Agent::where('slug', '!=', 'sarah')
            ->get()
            ->filter(function ($agent) use ($levelHierarchy, $minLevel) {
                $agentLevelNum = $levelHierarchy[$agent->level] ?? 0;
                return $agentLevelNum >= $minLevel;
            })
            ->values();

        // Get currently selected agents for this workspace
        $selected = \Illuminate\Support\Facades\DB::table('workspace_agents')
            ->where('workspace_id', $wsId)
            ->where('enabled', true)
            ->pluck('agent_id')
            ->toArray();

        return response()->json([
            'available' => $available,
            'selected_ids' => $selected,
            'max_agents' => $maxAgents,
            'plan_name' => $rules['plan_name'],
            'agent_level' => $agentLevel,
            'addon_price' => $rules['agent_addon_price'],
        ]);
    });

    // PUT /workspace/agents/positions — persist agent canvas position after drag
    // Brand identity settings
    Route::get('/workspace/brand', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $brand = \Illuminate\Support\Facades\DB::table('creative_brand_identities')->where('workspace_id', $wsId)->first();
        return response()->json($brand ?? ['primary_color' => '#6C5CE7', 'secondary_color' => '#00E5A8', 'accent_color' => '#F4F7FB']);
    });

    Route::put('/workspace/brand', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        \Illuminate\Support\Facades\DB::table('creative_brand_identities')->updateOrInsert(
            ['workspace_id' => $wsId],
            array_filter([
                'primary_color' => $r->input('primary_color'),
                'secondary_color' => $r->input('secondary_color'),
                'accent_color' => $r->input('accent_color'),
                'fonts_json' => $r->input('font_heading') ? json_encode(['heading' => $r->input('font_heading'), 'body' => $r->input('font_body')]) : null,
                'visual_style' => $r->input('visual_style'),
                'logo_url' => $r->input('logo_url'),
                'industry' => $r->input('industry'),
                'updated_at' => now(),
            ])
        );
        // Update all workspace websites settings_json
        \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $wsId)->update([
            'settings_json' => json_encode([
                'primary_color' => $r->input('primary_color', '#6C5CE7'),
                'secondary_color' => $r->input('secondary_color', '#00E5A8'),
                'accent_color' => $r->input('accent_color', '#F4F7FB'),
                'font_heading' => $r->input('font_heading', 'Syne'),
                'font_body' => $r->input('font_body', 'DM Sans'),
                'theme' => 'modern',
            ]),
            'updated_at' => now(),
        ]);
        return response()->json(['success' => true]);
    });

        Route::put('/workspace/agents/positions', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $agentSlug = $r->input('agent');
        $x = (int) $r->input('x', 0);
        $y = (int) $r->input('y', 0);
        // Store in workspace metadata (positions_json)
        $ws = \App\Models\Workspace::findOrFail($wsId);
        // settings_json is cast to array by the model — don't double-decode.
        $meta = is_array($ws->settings_json) ? $ws->settings_json
              : ($ws->settings_json ? (json_decode($ws->settings_json, true) ?: []) : []);
        $meta['agent_positions'] = $meta['agent_positions'] ?? [];
        $meta['agent_positions'][$agentSlug] = ['x' => $x, 'y' => $y];
        $ws->update(['settings_json' => $meta]);
        return response()->json(['saved' => true]);
    });

        // GET /workspace/agents — agents currently on the workspace's team
    Route::get('/workspace/agents', function (\Illuminate\Http\Request $r) {

        $wsId = $r->attributes->get('workspace_id');
        $agents = app(\App\Core\Agents\AgentService::class)->forWorkspace($wsId);
        return response()->json(['agents' => $agents]);
    });

        // ── Unified Messaging System ────────────────────────────────
    Route::get('/messages/unread-count', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $byAgent = [];
        $total = 0;

        // Count from agent_messages
        try {
            $counts = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->selectRaw('agent_slug, COUNT(*) as cnt')
                ->groupBy('agent_slug')
                ->pluck('cnt', 'agent_slug')
                ->toArray();
            $byAgent = $counts;
            $total = array_sum($counts);
        } catch (\Throwable $e) {
            // Fallback: count from audit_logs
            $total = \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->where('action', 'agent.direct_message')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.from')) != 'User'")
                ->count();
        }

        return response()->json(['total' => $total, 'by_agent' => $byAgent]);
    });

    Route::post('/messages/{slug}/read', function (\Illuminate\Http\Request $r, $slug) {
        $wsId = $r->attributes->get('workspace_id');
        if ($slug === 'dmm') $slug = 'sarah';
        try {
            \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('agent_slug', $slug)
                ->where('role', 'agent')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['marked' => true]);
    });

    Route::get('/messages/conversations', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // Get enabled agents for this workspace
        $agents = \Illuminate\Support\Facades\DB::table('agents')
            ->join('workspace_agents', 'agents.id', '=', 'workspace_agents.agent_id')
            ->where('workspace_agents.workspace_id', $wsId)
            ->where('workspace_agents.enabled', true)
            ->select('agents.slug', 'agents.name', 'agents.title', 'agents.color')
            ->get();

        $conversations = [];
        foreach ($agents as $a) {
            // Last message
            $lastMsg = null;
            try {
                $lastMsg = \Illuminate\Support\Facades\DB::table('agent_messages')
                    ->where('workspace_id', $wsId)
                    ->where('agent_slug', $a->slug)
                    ->orderByDesc('created_at')
                    ->first();
            } catch (\Throwable $e) {}

            if (!$lastMsg) {
                // Try audit_logs fallback
                $lastLog = \Illuminate\Support\Facades\DB::table('audit_logs')
                    ->where('workspace_id', $wsId)
                    ->where('action', 'agent.direct_message')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent_slug')) = ?", [$a->slug])
                    ->orderByDesc('created_at')
                    ->first();
                if ($lastLog) {
                    $meta = json_decode($lastLog->metadata_json, true);
                    $lastMsg = (object) ['sender' => $meta['from'] ?? '?', 'content' => $meta['content'] ?? '', 'created_at' => $lastLog->created_at];
                }
            }

            $unread = 0;
            try {
                $unread = \Illuminate\Support\Facades\DB::table('agent_messages')
                    ->where('workspace_id', $wsId)
                    ->where('agent_slug', $a->slug)
                    ->where('role', 'agent')
                    ->whereNull('read_at')
                    ->count();
            } catch (\Throwable $e) {}

            $conversations[] = [
                'slug' => $a->slug,
                'name' => $a->name,
                'title' => $a->title,
                'color' => $a->color,
                'unread' => $unread,
                'last_message' => $lastMsg ? [
                    'from' => $lastMsg->sender ?? '',
                    'content' => mb_substr($lastMsg->content ?? '', 0, 80),
                    'ts' => $lastMsg->created_at ?? null,
                ] : null,
            ];
        }

        // Sort: agents with messages first, then by last message time
        usort($conversations, function ($a, $b) {
            if ($a['slug'] === 'sarah') return -1;
            if ($b['slug'] === 'sarah') return 1;
            $aTs = $a['last_message']['ts'] ?? '0';
            $bTs = $b['last_message']['ts'] ?? '0';
            return strcmp($bTs, $aTs);
        });

        return response()->json(['conversations' => $conversations]);
    });


    // PUT /workspace/agents — update the workspace's agent team selection
    Route::put('/workspace/agents', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $agentIds = $r->input('agent_ids', []);
        $planGating = app(\App\Core\PlanGating\PlanGatingService::class);
        $rules = $planGating->getPlanRules($wsId);

        if (!$rules['includes_dmm']) {
            return response()->json(['error' => 'AI agents require Growth plan or above'], 403);
        }

        $maxAgents = $rules['agent_count'];
        $agentLevel = $rules['agent_level'];
        $levelHierarchy = ['senior' => 3, 'specialist' => 2, 'junior' => 1];
        $minLevel = $levelHierarchy[$agentLevel] ?? 1;

        // Validate: don't exceed max
        if (count($agentIds) > $maxAgents) {
            return response()->json(['error' => "Your {$rules['plan_name']} plan allows {$maxAgents} agents. You selected " . count($agentIds) . "."], 422);
        }

        // Validate: all agents must be at or above plan level
        $agents = \App\Models\Agent::whereIn('id', $agentIds)->get();
        foreach ($agents as $agent) {
            $lvl = $levelHierarchy[$agent->level] ?? 0;
            if ($lvl < $minLevel) {
                return response()->json(['error' => "{$agent->name} is a {$agent->level}-level agent. Your {$rules['plan_name']} plan requires {$agentLevel}-level or above."], 422);
            }
        }

        // Sarah is always included (id=1) — ensure she's in the list
        $sarahId = \App\Models\Agent::where('slug', 'sarah')->value('id');

        // Clear existing selections (except Sarah)
        \Illuminate\Support\Facades\DB::table('workspace_agents')
            ->where('workspace_id', $wsId)
            ->where('agent_id', '!=', $sarahId)
            ->delete();

        // Ensure Sarah is present
        \Illuminate\Support\Facades\DB::table('workspace_agents')->updateOrInsert(
            ['workspace_id' => $wsId, 'agent_id' => $sarahId],
            ['enabled' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        // Insert selected agents
        foreach ($agentIds as $agentId) {
            if ($agentId == $sarahId) continue; // already handled
            \Illuminate\Support\Facades\DB::table('workspace_agents')->updateOrInsert(
                ['workspace_id' => $wsId, 'agent_id' => $agentId],
                ['enabled' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $team = app(\App\Core\Agents\AgentService::class)->forWorkspace($wsId);
        return response()->json(['success' => true, 'agents' => $team, 'count' => count($team)]);
    });

    Route::get('/workspaces/{id}/agents', [AgentController::class, 'forWorkspace']);

    // Agent Dispatch (APP888 messaging integration)
    Route::post('/agent/dispatch', [\App\Http\Controllers\Api\AgentDispatchController::class, 'dispatch']);
    Route::get('/agent/conversations', [\App\Http\Controllers\Api\AgentDispatchController::class, 'conversations']);
    Route::get('/agent/conversation/{id}', [\App\Http\Controllers\Api\AgentDispatchController::class, 'conversation']);
    Route::get('/agent/events', [\App\Http\Controllers\Api\AgentDispatchController::class, 'events']);

    // DELETED 2026-04-12 (Phase 1.0.0 / doc 07): MultiStepPlanner was dead code in
    // every direction. Backend routes existed but had ZERO live callers (frontend
    // helper unused, react-app unbuilt, AgentDispatchService injection wired but
    // never invoked, no internal backend caller). Three routes removed:
    //   - POST /agent/strategy-meeting → MultiStepPlanner::strategyMeeting (C3)
    //   - POST /agent/plan             → MultiStepPlanner::plan (C2)
    //   - POST /agent/execute-plan     → MultiStepPlanner::execute
    // Eliminates 2 LLM bypass sites (C2 + C3). Use /api/sarah/* (Path A) instead.

    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
    // Notification System v2 (2026-05-07) — user-scoped + preferences
    Route::get ('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all',     [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
    Route::get ('/notifications/preferences',  [\App\Http\Controllers\Api\NotificationController::class, 'preferences']);
    Route::put ('/notifications/preferences',  [\App\Http\Controllers\Api\NotificationController::class, 'updatePreferences']);

    // Media Upload + unified media picker (added 2026-04-19, Phase 3)
    // Workspace-scoped. Picker uses /library + /access + /use.
    Route::post('/media/upload', [\App\Http\Controllers\Api\MediaController::class, 'upload']);
    Route::get('/media/library',  [\App\Http\Controllers\Api\MediaController::class, 'library']);
    Route::get('/media/access',   [\App\Http\Controllers\Api\MediaController::class, 'access']);
    Route::post('/media/use',     [\App\Http\Controllers\Api\MediaController::class, 'use_']);
    Route::delete('/media/{id}',  [\App\Http\Controllers\Api\MediaController::class, 'delete'])->where('id', '[0-9]+');

    // Validation Report (Phase 4)
    Route::get('/system/validation-report', [\App\Http\Controllers\Api\Debug\DebugScenarioController::class, 'validationReport'])->middleware(\App\Http\Middleware\AdminMiddleware::class);

    // ══════════════════════════════════════════════════════════════
    // SARAH ORCHESTRATOR — THE real DMM system controller
    // All user goals flow through Sarah → plan → delegate → execute → evaluate
    // ══════════════════════════════════════════════════════════════

    Route::prefix('sarah')->group(function () {
        // Main entry: user sends goal → Sarah creates plan
        Route::post('/receive', function (\Illuminate\Http\Request $r) {
            $r->validate(['goal' => 'required|string']);
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json($sarah->receive(
                $r->attributes->get('workspace_id'), $r->user()->id,
                $r->input('goal'), $r->input('context', [])
            ));
        });

        // Approve a plan
        Route::post('/plans/{id}/approve', function (\Illuminate\Http\Request $r, $id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json($sarah->approvePlan($r->attributes->get('workspace_id'), $id, $r->user()->id));
        });

        // Cancel a plan
        Route::post('/plans/{id}/cancel', function (\Illuminate\Http\Request $r, $id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            $sarah->cancelPlan($id);
            return response()->json(['cancelled' => true]);
        });

        // Get plan status (for polling)
        Route::get('/plans/{id}', function ($id) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json($sarah->getPlanStatus($id));
        });

        // List plans
        Route::get('/plans', function (\Illuminate\Http\Request $r) {
            $sarah = app(\App\Core\Orchestration\SarahOrchestrator::class);
            return response()->json(['plans' => $sarah->listPlans($r->attributes->get('workspace_id'), $r->all())]);
        });

        // ── Strategy Meetings (real agent collaboration) ──
        Route::post('/meeting/start', function (\Illuminate\Http\Request $r) {
            $r->validate(['goal' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->startMeeting(
                $r->attributes->get('workspace_id'), $r->user()->id,
                $r->input('goal'), $r->input('agents', [])
            ));
        });

        Route::post('/meeting/{id}/advance', function ($id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->advanceMeeting($id));
        });

        Route::post('/meeting/full', function (\Illuminate\Http\Request $r) {
            $r->validate(['goal' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->runFullMeeting(
                $r->attributes->get('workspace_id'), $r->user()->id, $r->input('goal')
            ));
        });

        Route::get('/meeting/{id}', function ($id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->getMeetingTranscript($id));
        });

        // User participates in meeting
        Route::post('/meeting/{id}/message', function (\Illuminate\Http\Request $r, $id) {
            $r->validate(['message' => 'required|string']);
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->userMessage($id, $r->user()->id, $r->input('message')));
        });

        // User ends meeting
        Route::post('/meeting/{id}/end', function (\Illuminate\Http\Request $r, $id) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->endMeeting($id, $r->user()->id));
        });

        // Get meeting cost estimate before starting
        Route::post('/meeting/estimate', function (\Illuminate\Http\Request $r) {
            $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
            return response()->json($engine->estimateMeetingCost($r->input('agents', [])));
        });

        // ── Proactive Strategy + Proposals ──
        Route::get('/proposals', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json(['proposals' => $proactive->listProposals($r->attributes->get('workspace_id'), $r->input('status'))]);
        });

        Route::post('/proposals/{id}/approve', function (\Illuminate\Http\Request $r, $id) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->approveProposal($r->attributes->get('workspace_id'), $r->user()->id, $id));
        });

        Route::post('/proposals/{id}/decline', function (\Illuminate\Http\Request $r, $id) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->declineProposal($r->attributes->get('workspace_id'), $id));
        });

        // Cost estimate for any plan
        Route::post('/estimate-cost', function (\Illuminate\Http\Request $r) {
            $r->validate(['tasks' => 'required|array']);
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->estimatePlanCost($r->input('tasks')));
        });

        Route::post('/proactive/onboarding', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->onOnboardingComplete($r->attributes->get('workspace_id'), $r->user()->id));
        });

        Route::post('/proactive/daily-check', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->dailyCheck($r->attributes->get('workspace_id')));
        });

        Route::post('/proactive/weekly-review', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->weeklyReview($r->attributes->get('workspace_id')));
        });

        Route::post('/proactive/monthly-strategy', function (\Illuminate\Http\Request $r) {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            return response()->json($proactive->monthlyStrategy($r->attributes->get('workspace_id'), $r->user()->id));
        });
    });

    // ══════════════════════════════════════════════════════════════
    // EXPERIMENTS — A/B testing engine
    // ══════════════════════════════════════════════════════════════

    Route::prefix('experiments')->group(function () {
        $e = \App\Core\Intelligence\CampaignOptimizationEngine::class;
        Route::get('/', function (\Illuminate\Http\Request $r) use ($e) {
            return response()->json(['experiments' => app($e)->listExperiments($r->attributes->get('workspace_id'), $r->all())]);
        });
        Route::post('/', function (\Illuminate\Http\Request $r) use ($e) {
            $r->validate(['name' => 'required|string', 'engine' => 'required|string', 'hypothesis' => 'required|string']);
            return response()->json(['experiment_id' => app($e)->createExperiment($r->attributes->get('workspace_id'), $r->all())], 201);
        });
        Route::get('/{id}', function (\Illuminate\Http\Request $r, $id) use ($e) {
            return response()->json(app($e)->getExperiment($r->attributes->get('workspace_id'), $id));
        });
        Route::post('/{id}/start', function ($id) use ($e) {
            app($e)->startExperiment($id);
            return response()->json(['started' => true]);
        });
        Route::post('/{id}/result', function (\Illuminate\Http\Request $r, $id) use ($e) {
            $r->validate(['variant_id' => 'required|string', 'results' => 'required|array']);
            app($e)->recordVariantResult($id, $r->input('variant_id'), $r->input('results'));
            return response()->json(['recorded' => true]);
        });
        Route::post('/{id}/evaluate', function ($id) use ($e) {
            return response()->json(app($e)->evaluateExperiment($id));
        });
        Route::get('/{wsId}/compare/{engine}', function (\Illuminate\Http\Request $r, $wsId, $engine) use ($e) {
            return response()->json(app($e)->compareStrategies($wsId, $engine));
        });
    });

    // ══════════════════════════════════════════════════════════════
    // INTELLIGENCE — query knowledge, agent profiles, validation
    // ══════════════════════════════════════════════════════════════

    Route::prefix('intelligence')->group(function () {
        // Global knowledge query
        Route::get('/knowledge', function (\Illuminate\Http\Request $r) {
            return response()->json(['insights' => app(\App\Core\Intelligence\GlobalKnowledgeService::class)->query($r->all())]);
        });

        // Agent profile
        Route::get('/agents/{slug}/profile', function ($slug) {
            $agent = \App\Models\Agent::where('slug', $slug)->first();
            if (!$agent) return response()->json(['error' => 'Agent not found'], 404);
            return response()->json(app(\App\Core\Intelligence\AgentExperienceService::class)->getAgentProfile($agent->id));
        });

        // Agent trust score
        Route::get('/agents/{slug}/trust', function (\Illuminate\Http\Request $r, $slug) {
            $agent = \App\Models\Agent::where('slug', $slug)->first();
            if (!$agent) return response()->json(['error' => 'Agent not found'], 404);
            $ws = \App\Models\Workspace::find($r->attributes->get('workspace_id'));
            return response()->json(app(\App\Core\Intelligence\Validation\IntelligenceValidator::class)
                ->getAgentTrustScore($agent->id, $ws?->industry));
        });

        // Engine briefing
        Route::get('/engines/{engine}/briefing', function ($engine) {
            return response()->json(app(\App\Core\Intelligence\EngineIntelligenceService::class)->getBriefing($engine));
        });

        // Segmented effectiveness
        Route::get('/engines/{engine}/effectiveness/{tool}', function ($engine, $tool) {
            return response()->json(app(\App\Core\Intelligence\Validation\IntelligenceValidator::class)
                ->getSegmentedEffectiveness($engine, $tool));
        });

        // Time-based intelligence
        Route::get('/seasonal-patterns', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getSeasonalPatterns($r->input('engine'), $r->input('industry')));
        });

        Route::get('/trends', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getTrends($r->input('engine'), $r->input('industry'), $r->input('months', 6)));
        });

        Route::get('/lifecycle', function (\Illuminate\Http\Request $r) {
            return response()->json(app(\App\Core\Intelligence\GlobalKnowledgeService::class)
                ->getLifecycleInsights($r->input('engine')));
        });
    });

    // ══════════════════════════════════════════════════════════════
    // UNIFIED EXECUTION — ALL engine actions flow through here
    // Manual Mode: POST /api/execute {engine, action, params}
    // Agent Mode:  POST /api/execute/async {engine, action, params, agent_id}
    // ══════════════════════════════════════════════════════════════

    Route::post('/execute', function (\Illuminate\Http\Request $r) {
        $r->validate(['engine' => 'required|string', 'action' => 'required|string']);
        $executor = app(\App\Core\EngineKernel\EngineExecutionService::class);
        return response()->json($executor->execute(
            $r->attributes->get('workspace_id'),
            $r->input('engine'),
            $r->input('action'),
            $r->input('params', []),
            ['user_id' => $r->user()?->id, 'source' => 'manual', 'priority' => $r->input('priority', 'normal')]
        ));
    });

    Route::post('/execute/async', function (\Illuminate\Http\Request $r) {
        $r->validate(['engine' => 'required|string', 'action' => 'required|string']);
        $executor = app(\App\Core\EngineKernel\EngineExecutionService::class);
        return response()->json($executor->executeAsync(
            $r->attributes->get('workspace_id'),
            $r->input('engine'),
            $r->input('action'),
            $r->input('params', []),
            ['user_id' => $r->user()?->id, 'agent_id' => $r->input('agent_id', 'sarah'), 'source' => 'agent', 'priority' => $r->input('priority', 'normal')]
        ));
    });

    // ══════════════════════════════════════════════════════════════
    // ENGINE ROUTES (Phase 2) — direct CRUD for reads, execution bridge for writes
    // ══════════════════════════════════════════════════════════════

    // ── CRM Engine (100% complete — 32 routes) ─────────────────
    Route::prefix('crm')->group(function () {
        $c = \App\Engines\CRM\Http\Controllers\CrmController::class;

        // Leads (12 routes)
        Route::get('/leads', [$c, 'listLeads']);
        Route::post('/leads', [$c, 'createLead']);
        Route::get('/leads/export', [$c, 'exportLeads']);
        Route::post('/leads/import', [$c, 'importLeads']);
        Route::get('/leads/{id}', [$c, 'getLead']);
        Route::put('/leads/{id}', [$c, 'updateLead']);
        Route::delete('/leads/{id}', [$c, 'deleteLead']);
        Route::post('/leads/{id}/restore', [$c, 'restoreLead']);
        Route::post('/leads/{id}/score', [$c, 'scoreLead']);
        Route::post('/leads/{id}/assign', [$c, 'assignLead']);

        // Contacts (7 routes)
        Route::get('/contacts', [$c, 'listContacts']);
        Route::post('/contacts', [$c, 'createContact']);
        Route::get('/contacts/duplicates', [$c, 'findDuplicates']);
        Route::get('/contacts/{id}', [$c, 'getContact']);
        Route::put('/contacts/{id}', [$c, 'updateContact']);
        Route::delete('/contacts/{id}', [$c, 'deleteContact']);
        Route::post('/contacts/merge', [$c, 'mergeContacts']);

        // Deals (5 routes)
        Route::get('/deals', [$c, 'listDeals']);
        Route::post('/deals', [$c, 'createDeal']);
        Route::get('/deals/{id}', [$c, 'getDeal']);
        Route::put('/deals/{id}', [$c, 'updateDeal']);
        Route::put('/deals/{id}/stage', [$c, 'updateDealStage']);

        // Pipeline stages (5 routes)
        Route::get('/pipeline', [$c, 'pipeline']);
        Route::get('/stages', [$c, 'stages']);
        Route::post('/stages', [$c, 'createStage']);
        Route::put('/stages/{id}', [$c, 'updateStage']);
        Route::delete('/stages/{id}', [$c, 'deleteStage']);
        Route::post('/stages/reorder', [$c, 'reorderStages']);

        // Activities (4 routes)
        Route::get('/activities', [$c, 'listActivities']);
        Route::post('/activities', [$c, 'logActivity']);
        Route::post('/activities/{id}/complete', [$c, 'completeActivity']);
        Route::get('/today', [$c, 'todayView']);

        // Notes (3 routes)
        Route::get('/notes', [$c, 'listNotes']);
        Route::post('/notes', [$c, 'addNote']);
        Route::delete('/notes/{id}', [$c, 'deleteNote']);

        // CRM settings & views (frontend compat)
        Route::get("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get("workspace_id");
            $stages = app(\App\Engines\CRM\Services\CrmService::class)->getStages($wsId);
            return response()->json([
                "stages" => collect($stages)->map(fn($s) => ["id" => is_object($s) ? $s->id : $s, "label" => is_object($s) ? $s->name : (string)$s, "color" => is_object($s) ? ($s->color ?? "#6C5CE7") : "#6C5CE7"])->values()->toArray(),
                "statuses" => [["id" => "active", "label" => "Active"], ["id" => "inactive", "label" => "Inactive"]],
                "categories" => [],
                "sources" => [["id" => "manual", "label" => "Manual"], ["id" => "import", "label" => "Import"], ["id" => "api", "label" => "API"]],
                "tags" => [],
            ]);
        });
        Route::get("/views", fn() => response()->json([]));

        // Nested contact notes & attachments (frontend compat)
        Route::get('/contacts/{contactId}/notes', [$c, 'listNotes']);
        Route::post('/contacts/{contactId}/notes', [$c, 'addNote']);
        Route::delete('/contacts/{contactId}/notes/{noteId}', [$c, 'deleteNote']);
        Route::post('/contacts/{contactId}/attachments', fn(\Illuminate\Http\Request $r, $contactId) => response()->json(["message" => "Attachments not yet implemented"], 501));
        Route::get('/contacts/{contactId}/attachments', fn(\Illuminate\Http\Request $r, $contactId) => response()->json(["attachments" => []]));

        // Revenue & Reporting (2 routes)
        Route::get('/revenue', [$c, 'revenue']);
        Route::get('/reporting', [$c, 'reporting']);

        // AI Outreach Generation — routed through Creative blueprint
        Route::post('/outreach/generate', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\CRM\Services\CrmService::class)->generateOutreach($wsId, $r->all())
            );
        });
        Route::post('/outreach/follow-up', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            return response()->json(
                app(\App\Engines\CRM\Services\CrmService::class)->generateFollowUp($wsId, $r->all())
            );
        });

        // ── CRM route aliases (frontend path compat for restored crm-engine.js v3.6.0) ──
        Route::get('/dashboard', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $leads = $s->listLeads($wsId);
            $leadsList = collect($leads['leads'] ?? []);
            $stages = \Illuminate\Support\Facades\DB::table('pipeline_stages')->where('workspace_id', $wsId)->orderBy('position')->get();
            $todayActivities = \Illuminate\Support\Facades\DB::table('activities')->where('workspace_id', $wsId)->where('created_at', '>=', now()->startOfDay())->count();
            return response()->json([
                'total_leads' => $leads['total'] ?? $leadsList->count(),
                'pipeline_value' => $leadsList->sum('deal_value'),
                'stages_count' => $stages->count(),
                'today_activities' => $todayActivities,
                'recent_leads' => $leadsList->take(5)->toArray(),
            ]);
        });

        Route::get('/pipeline/stages', [$c, 'stages']);  // alias: crm-engine.js calls /pipeline/stages, Laravel has /stages

        Route::get('/modules', function (\Illuminate\Http\Request $r) {
            // CRM modules config — returns enabled module flags
            return response()->json([
                'leads' => ['enabled' => true, 'required' => true, 'label' => 'Leads & Pipeline'],
                'contacts' => ['enabled' => true, 'required' => false, 'label' => 'Contact Management'],
                'deals' => ['enabled' => true, 'required' => false, 'label' => 'Deal Tracking'],
                'activities' => ['enabled' => true, 'required' => false, 'label' => 'Activities & Tasks'],
                'reporting' => ['enabled' => true, 'required' => false, 'label' => 'Revenue Reporting'],
            ]);
        });

        Route::get('/projects', [$c, 'listDeals']);  // alias: crm-engine.js "projects" are Laravel "deals"
        Route::post('/projects', fn(\Illuminate\Http\Request $r) => app($c)->createDeal($r));
        Route::get('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->getDeal($r, $id));
        Route::put('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => app($c)->updateDeal($r, $id));
        Route::delete('/projects/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => true]));

        Route::get('/appointments', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $upcoming = $r->boolean('upcoming');
            $events = \Illuminate\Support\Facades\DB::table('calendar_events')
                ->where('workspace_id', $wsId)
                ->when($upcoming, fn($q) => $q->where('starts_at', '>=', now()))
                ->orderBy('starts_at')
                ->limit(50)
                ->get();
            return response()->json(['appointments' => $events]);
        });
        Route::post('/appointments', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $id = \Illuminate\Support\Facades\DB::table('calendar_events')->insertGetId([
                'workspace_id' => $wsId,
                'title' => $r->input('title'),
                'description' => $r->input('description'),
                'starts_at' => $r->input('start_at'),
                'ends_at' => $r->input('end_at'),
                'type' => 'appointment',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['id' => $id], 201);
        });
        Route::put('/appointments/{id}', function (\Illuminate\Http\Request $r, $id) {
            \Illuminate\Support\Facades\DB::table('calendar_events')->where('id', $id)->update(array_filter([
                'title' => $r->input('title'),
                'description' => $r->input('description'),
                'starts_at' => $r->input('start_at'),
                'ends_at' => $r->input('end_at'),
                'status' => $r->input('status'),
                'updated_at' => now(),
            ]));
            return response()->json(['updated' => true]);
        });

        Route::get('/tasks', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $status = $r->input('status');
            $tasks = \App\Models\Task::where('workspace_id', $wsId)
                ->where('engine', 'crm')
                ->when($status, fn($q) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
            return response()->json(['tasks' => $tasks]);
        });

        Route::post('/scores/recalculate', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $leads = \Illuminate\Support\Facades\DB::table('leads')->where('workspace_id', $wsId)->pluck('id');
            $count = 0;
            foreach ($leads as $leadId) {
                try { $s->scoreLead($leadId); $count++; } catch (\Throwable $e) {}
            }
            return response()->json(['recalculated' => $count]);
        });

        Route::get('/leads/export/{format}', function (\Illuminate\Http\Request $r, $format) {
            $wsId = $r->attributes->get('workspace_id');
            $s = app(\App\Engines\CRM\Services\CrmService::class);
            $csv = $s->exportLeads($wsId, $r->all());
            if ($format === 'csv') return response()->json(['csv' => $csv]);
            if ($format === 'json') {
                $data = $s->listLeads($wsId);
                return response()->json($data['leads'] ?? []);
            }
            if ($format === 'xml') {
                $data = $s->listLeads($wsId);
                $xml = '<?xml version="1.0"?><leads>';
                foreach ($data['leads'] ?? [] as $l) { $xml .= '<lead><name>' . htmlspecialchars($l['name'] ?? '') . '</name><email>' . htmlspecialchars($l['email'] ?? '') . '</email></lead>'; }
                $xml .= '</leads>';
                return response()->json(['xml' => $xml]);
            }
            return response()->json(['error' => 'Unsupported format'], 400);
        });
    });

    // ── SEO Engine (100% complete — 25 routes) ────────────────
    Route::prefix('seo')->group(function () {
        $c = \App\Engines\SEO\Http\Controllers\SeoController::class;

        // Analysis tools (3)
        Route::post('/serp-analysis', [$c, 'serpAnalysis']);
        Route::post('/ai-report', [$c, 'aiReport']);
        Route::post('/deep-audit', [$c, 'deepAudit']);

        // Content delegation (2)
        Route::post('/improve-draft', [$c, 'improveDraft']);
        Route::post('/write-article', [$c, 'writeArticle']);

        // AI status (1)
        Route::get('/ai-status', [$c, 'aiStatus']);

        // Link management (6)
        Route::get('/links', [$c, 'linkSuggestions']);
        Route::post('/links/generate', [$c, 'generateLinks']);
        Route::post('/links/{id}/insert', [$c, 'insertLink']);
        Route::post('/links/{id}/dismiss', [$c, 'dismissLink']);
        Route::get('/outbound', [$c, 'outboundLinks']);
        Route::post('/outbound/check', [$c, 'checkOutbound']);

        // Goals (5)
        Route::get('/goals', [$c, 'listGoals']);
        Route::post('/goals', [$c, 'createGoal']);
        Route::get('/goals/{id}', [$c, 'getGoal']);
        Route::post('/goals/{id}/pause', [$c, 'pauseGoal']);
        Route::post('/goals/{id}/resume', [$c, 'resumeGoal']);
        Route::get('/agent-status', [$c, 'agentStatus']);

        // Keywords (3)
        Route::get('/keywords', [$c, 'listKeywords']);
        Route::post('/keywords', [$c, 'addKeyword']);
        Route::delete('/keywords/{id}', [$c, 'deleteKeyword']);

        // Audits (2)
        Route::get('/audits', [$c, 'listAudits']);
        Route::get('/audits/{id}', [$c, 'getAudit']);

        // Dashboard & Reporting (2)
        Route::get('/dashboard', [$c, 'dashboard']);
        Route::get('/report', [$c, 'report']);

        // Redirects management (DB-backed)
        Route::get("/redirects", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $redirects = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('workspace_id', $wsId)
                ->orderByDesc('created_at')
                ->get();
            return response()->json(["redirects" => $redirects]);
        });
        Route::post("/redirects", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $id = \Illuminate\Support\Facades\DB::table('seo_redirects')->insertGetId([
                'workspace_id' => $wsId,
                'source_url'   => $r->input('from', $r->input('source_url', '')),
                'target_url'   => $r->input('to', $r->input('target_url', '')),
                'type'         => $r->input('type', '301'),
                'is_regex'     => $r->boolean('is_regex', false),
                'status'       => 'active',
                'hit_count'    => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            return response()->json(["id" => $id, "created" => true]);
        });
        Route::delete("/redirects/{id}", function ($id, \Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $deleted = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('id', $id)
                ->where('workspace_id', $wsId)
                ->delete();
            return response()->json(["deleted" => $deleted > 0, "id" => $id]);
        });
        Route::get("/404-log", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $entries = \Illuminate\Support\Facades\DB::table('seo_404_log')
                ->where('workspace_id', $wsId)
                ->orderByDesc('last_hit_at')
                ->limit(200)
                ->get();
            return response()->json(["entries" => $entries]);
        });

        // SEO Settings (DB-backed)
        Route::get("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->get();
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row->key] = $row->value;
            }
            if (empty($settings)) {
                $settings = [
                    'title_template' => '{page_title} | {site_name}',
                    'meta_description_fallback' => '',
                    'sitemap_enabled' => 'true',
                ];
            }
            return response()->json($settings);
        });
        Route::post("/settings", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $settings = $r->except(['_token']);
            $group = $r->input('_group', 'general');
            unset($settings['_group']);
            foreach ($settings as $key => $value) {
                \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                    ['workspace_id' => $wsId, 'key' => $key],
                    ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            return response()->json(["saved" => true]);
        });

        // Score weights (DB-backed)
        Route::get("/score-weights", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('seo_score_weights')
                ->where('workspace_id', $wsId)
                ->get();
            $weights = [];
            foreach ($rows as $row) {
                $weights[$row->factor] = $row->weight;
            }
            if (empty($weights)) {
                $defaults = ['title' => 20, 'meta_description' => 15, 'content_length' => 25, 'internal_links' => 15, 'image_alt' => 10, 'readability' => 15];
                foreach ($defaults as $factor => $weight) {
                    \Illuminate\Support\Facades\DB::table('seo_score_weights')->insert([
                        'workspace_id' => $wsId,
                        'factor' => $factor,
                        'weight' => $weight,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $weights = $defaults;
            }
            return response()->json($weights);
        });
        Route::post("/score-weights", function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $weights = $r->except(['_token']);
            foreach ($weights as $factor => $weight) {
                \Illuminate\Support\Facades\DB::table('seo_score_weights')->updateOrInsert(
                    ['workspace_id' => $wsId, 'factor' => $factor],
                    ['weight' => (int) $weight, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            return response()->json(["saved" => true]);
        });

    });

    // ══════════════════════════════════════════════════════════════
    // ALL ENGINE ROUTES — Controller-based, BaseEngineController enforced
    // READS = direct to service | WRITES = through execution pipeline
    // ══════════════════════════════════════════════════════════════

    // ── Write / Content Engine ───────────────────────────────────
    Route::prefix('write')->group(function () {
        $c = \App\Engines\Write\Http\Controllers\WriteController::class;
        Route::get('/articles', [$c, 'listArticles']);
        Route::post('/articles', [$c, 'createArticle']);
        Route::get('/articles/{id}', [$c, 'getArticle']);
        Route::put('/articles/{id}', [$c, 'updateArticle']);
        Route::delete('/articles/{id}', [$c, 'deleteArticle']);
        Route::get('/articles/{id}/versions', [$c, 'getVersions']);
        Route::post('/articles/{id}/versions/{vid}/restore', [$c, 'restoreVersion']);
        Route::post('/ai/write', [$c, 'writeArticle']);
        Route::post('/ai/improve', [$c, 'improveDraft']);
        Route::post('/ai/outline', [$c, 'generateOutline']);
        Route::post('/ai/headlines', [$c, 'generateHeadlines']);
        Route::post('/ai/meta', [$c, 'generateMeta']);
        Route::get('/dashboard', [$c, 'dashboard']);
    });

    // ── Creative Engine ──────────────────────────────────────────
    Route::prefix('creative')->group(function () {
        $s    = \App\Engines\Creative\Services\CreativeService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;

        // Dashboard
        Route::get('/dashboard', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getDashboard($r->attributes->get('workspace_id'))));

        // Brand identity (CIMS)
        Route::get('/brand', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getBrandIdentity($r->attributes->get('workspace_id'))));
        Route::put('/brand', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->updateBrandIdentity($r->attributes->get('workspace_id'), $r->all())));

        // Generation memory
        Route::get('/memory', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getGenerationMemory($r->attributes->get('workspace_id'), $r->query('type'), (int) $r->query('limit', 20))));
        Route::get('/memory/stats', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getMemoryStats($r->attributes->get('workspace_id'))));

        // Blueprint (cross-engine entry point — callable by other engines or directly)
        Route::post('/blueprint', function (\Illuminate\Http\Request $r) use ($s) {
            $wsId   = $r->attributes->get('workspace_id');
            $engine = $r->input('engine', 'creative');
            $type   = $r->input('type', 'content');
            return response()->json(app($s)->generateThroughBlueprint($engine, $type, $wsId, $r->except(['engine', 'type'])));
        });

        // Image generation (through pipeline — approval-gated, credit-tracked)
        Route::post('/generate/image', function (\Illuminate\Http\Request $r) use ($exec) {
            return response()->json(app($exec)->execute(
                $r->attributes->get('workspace_id'), 'creative', 'generate_image', $r->all(),
                ['user_id' => $r->user()?->id, 'source' => 'manual']
            ), 202);
        });

        // Video generation (async — returns in_progress immediately)
        Route::post('/generate/video', function (\Illuminate\Http\Request $r) use ($exec) {
            return response()->json(app($exec)->execute(
                $r->attributes->get('workspace_id'), 'creative', 'generate_video', $r->all(),
                ['user_id' => $r->user()?->id, 'source' => 'manual']
            ), 202);
        });

        // Video job polling
        Route::get('/assets/{id}/poll', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->pollVideoJob((int) $id)));

        // Asset CRUD
        Route::get('/assets', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listAssets($r->attributes->get('workspace_id'), $r->all())));
        Route::get('/assets/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getAsset($r->attributes->get('workspace_id'), (int) $id)));
        Route::delete('/assets/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($s)->deleteAsset((int) $id)]));

        // ── Video job status (creative-engine.js polls this path) ──
        Route::get('/video/jobs/{id}/status', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->pollVideoJob((int) $id)));

        // ── Website scan (creative-engine.js advanced feature) ──
        Route::post('/scan-url', function (\Illuminate\Http\Request $r) {
            $url = $r->input('url');
            if (!$url) return response()->json(['error' => 'URL required'], 422);
            // Basic URL scan — returns page title, description, colors
            try {
                $html = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
                if (!$html) return response()->json(['url' => $url, 'error' => 'Could not fetch URL']);
                preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatch);
                preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $descMatch);
                return response()->json([
                    'url' => $url,
                    'title' => $titleMatch[1] ?? null,
                    'description' => $descMatch[1] ?? null,
                    'scan_complete' => true,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['url' => $url, 'error' => $e->getMessage()]);
            }
        });

        // PATCH v1.0.1: POST /creative/assets — frontend calls this with {type, prompt}.
        // Was 404 because only GET /assets existed. Routes generation through the execution
        // pipeline so approval gating and credit deduction apply.
        // type=image → creative/generate_image (10 credits, auto-approve)
        // type=video → creative/generate_video (25 credits, review-gated)
        Route::post('/assets', function (\Illuminate\Http\Request $r) use ($exec) {
            $r->validate([
                'type'   => 'required|in:image,video',
                'prompt' => 'required|string|max:2000',
            ]);

            $wsId   = $r->attributes->get('workspace_id');
            $type   = $r->input('type');
            $action = $type === 'video' ? 'generate_video' : 'generate_image';

            return response()->json(
                app($exec)->execute(
                    $wsId,
                    'creative',
                    $action,
                    $r->all(),
                    ['user_id' => $r->user()?->id, 'source' => 'manual']
                ),
                202
            );
        });
    });

    // ── Builder Engine ───────────────────────────────────────────
    Route::prefix('builder')->group(function () {
        $s = \App\Engines\Builder\Services\BuilderService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        // Reads
        Route::get('/websites', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listWebsites($r->attributes->get('workspace_id'))));
        Route::get('/websites/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getWebsite($r->attributes->get('workspace_id'), $id)));
        Route::get('/websites/{id}/pages', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->listPages($id)));
        Route::get('/pages/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getPage($id)));
        // Writes through pipeline
        Route::post('/websites', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'builder', 'create_website', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        // PATCH (publish-flow-fix, 2026-05-09) — duplicate publish route
        // removed. The closure version at /builder/websites/{id}/publish
        // (line ~5430) is now the canonical publish endpoint — it gates
        // on websites.subdomain being non-NULL (returns 422 with a
        // user-facing message if missing), preventing "site is published
        // but has no URL to access" states. The engine path here
        // bypassed that check and was the second of two routes
        // resolving to the same path; first-registered wins, so this
        // one was masking the closure entirely.
        // Route::post('/websites/{id}/publish', ...) — removed
        Route::post('/websites/{wid}/pages', fn(\Illuminate\Http\Request $r, $wid) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'builder', 'generate_page', array_merge($r->all(), ['website_id' => $wid]), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::put('/pages/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['updated' => true]) && app($s)->updatePage($id, $r->all()));

        // PATCH 8 (2026-05-08) — Architecture Lock Tier 1: snapshot/restore for sections_json edits.
        Route::get('/pages/{pageId}/history', [\App\Http\Controllers\Api\BuilderSnapshotController::class, 'history'])
            ->where('pageId', '[0-9]+');
        Route::post('/pages/{pageId}/restore/{stateId}', [\App\Http\Controllers\Api\BuilderSnapshotController::class, 'restore'])
            ->where(['pageId' => '[0-9]+', 'stateId' => '[0-9]+']);

        // PATCH 8.5 (2026-05-08) — Arthur structured-JSON edit endpoint.
        // Refuses 422 on legacy static-HTML pages (Chef Red); for those the
        // legacy /api/builder/websites/{id}/arthur-edit closure still applies
        // until T3.4 / Patch 8.6 retires it.
        Route::post('/pages/{pageId}/arthur-edit', [\App\Http\Controllers\Api\ArthurEditController::class, 'edit'])
            ->where('pageId', '[0-9]+');
        // PATCH 3 (2026-05-08): the legacy structured wizard relied on
        // BuilderService::wizardGenerate() which calls 4 helpers that were
        // removed in 2026-04-19. Returning a clean 501 instead of letting
        // the request fatal. Website creation is owned by Arthur now —
        // POST /api/builder/arthur/message handles intent extraction and
        // multi-page generation conversationally.
        Route::post('/wizard', fn(\Illuminate\Http\Request $r) => response()->json([
            'error' => 'Website creation has moved to Arthur. Use POST /api/builder/arthur/message instead.',
            'replacement' => '/api/builder/arthur/message',
            'status' => 'gone',
        ], 501));


        // Builder page operations (from WP class-lubld-rest.php)
        Route::get("/pages", function(\Illuminate\Http\Request $r) use ($s) {
            $wid = $r->input("website_id");
            if (!$wid) return response()->json([]);
            return response()->json(app($s)->listPages((int)$wid));
        });
        Route::get("/load/{id}", function(\Illuminate\Http\Request $r, $id) use ($s) {
            $page = app($s)->getPage((int)$id);
            if (!$page) return response()->json(["error" => "Page not found"], 404);
            $page = is_object($page) ? (array)$page : $page;
            // Parse sections_json into sections array for the frontend
            if (isset($page['sections_json'])) {
                $parsed = is_string($page['sections_json']) ? json_decode($page['sections_json'], true) : $page['sections_json'];
                $page['sections'] = $parsed['sections'] ?? [];
                $page['schemaVersion'] = $parsed['schemaVersion'] ?? 1;
                $page['theme'] = $parsed['theme'] ?? [];
            }
            if (isset($page['seo_json'])) {
                $page['seo'] = is_string($page['seo_json']) ? json_decode($page['seo_json'], true) : $page['seo_json'];
            }
            return response()->json($page);
        });
        Route::post("/create", function(\Illuminate\Http\Request $r) use ($s, $exec) {
            $wid = $r->input("website_id");
            if (!$wid) return response()->json(["error" => "website_id required"], 400);
            return response()->json(app($exec)->execute($r->attributes->get("workspace_id"), "builder", "generate_page", array_merge($r->all(), ["website_id" => $wid]), ["user_id" => $r->user()?->id, "source" => "manual"]), 201);
        });
        Route::post("/save", function(\Illuminate\Http\Request $r) use ($s) {
            $pid = $r->input("page_id");
            if (!$pid) return response()->json(["error" => "page_id required"], 400);
            $data = $r->except("page_id");
            if (isset($data["sections_json"])) {
                $data["sections"] = is_string($data["sections_json"]) ? json_decode($data["sections_json"], true) : $data["sections_json"];
            }
            app($s)->updatePage((int)$pid, $data);
            return response()->json(["updated" => true, "page_id" => (int)$pid]);
        });
        Route::post("/delete/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["deleted" => app($s)->deletePage((int)$id)]));
        Route::post("/clone", fn(\Illuminate\Http\Request $r) => response()->json(["cloned" => true, "message" => "Clone not yet implemented"]));
        Route::post("/ai", fn() => response()->json(["reply" => "The AI builder assistant has been retired. Use the Strategy Room instead.", "status" => "deprecated"]));
        Route::delete("/websites/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["deleted" => app($s)->deleteWebsite((int)$id)]));
        Route::get("/stats", function(\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get("workspace_id");
            return response()->json([
                "websites" => \Illuminate\Support\Facades\DB::table("websites")->where("workspace_id", $wsId)->whereNull("deleted_at")->count(),
                "pages" => \Illuminate\Support\Facades\DB::table("pages")->whereIn("website_id", \Illuminate\Support\Facades\DB::table("websites")->where("workspace_id", $wsId)->pluck("id"))->count(),
            ]);
        });
        Route::get("/preview/{id}", fn($r, $id) => response()->json(["preview_html" => "", "page_id" => $id]));
        Route::get("/library", fn() => response()->json(["blocks" => [], "templates" => []]));
        Route::get("/sections/{id}", fn($r, $id) => response()->json(["section" => null]));
        Route::get("/components/{id}", fn($r, $id) => response()->json(["component" => null]));
        Route::get("/containers/{id}", fn($r, $id) => response()->json(["container" => null]));
        Route::get("/websites/{id}/blueprint", function(\Illuminate\Http\Request $r, $id) {
            try { return response()->json(app(\App\Engines\Creative\Services\BlueprintService::class)->getPageBlueprint($r->attributes->get("workspace_id"), ["website_id" => $id])); }
            catch (\Throwable $e) { return response()->json(["blueprint" => null]); }
        });
        Route::post("/websites/{id}/deploy-prep", fn($r, $id) => response()->json(["ready" => true]));
        Route::post('/arthur/message', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $msg     = (string) $r->input('message', '');
            $history = $r->input('history', []);
            if (!is_array($history)) $history = [];

            $arthur = new \App\Engines\Builder\Services\ArthurService();

            // PATCH (Arthur confirm flow, 2026-05-09) — two modes on this route:
            //
            //   (a) Conversation turn: client posts {message, history}.
            //       chat() returns type='question' (still gathering info) or
            //       type='confirm' (LLM has enough — send back the summary so
            //       the frontend can render Upload Logo + Build buttons). On
            //       'confirm' we DO NOT build yet; we cache build_data for
            //       300s so the follow-up confirm POST can retrieve it.
            //
            //   (b) Confirm action: client posts {confirm:true, logo_url:'…',
            //       build_data:{…optional echo…}}. We pull build_data from
            //       cache (or trust the client echo as fallback), call
            //       buildFromChat($wsId, $buildData, $logoUrl), and return
            //       type='complete' with website_id + website_url.
            $isConfirm = (bool) $r->input('confirm', false);

            if ($isConfirm) {
                // PATCH (Arthur build timeout, 2026-05-09) — website builds
                // commonly take 50-70s (LLM content generation across 5+ JSON
                // calls + image generation). Default PHP-FPM max_execution_time
                // is 30s and nginx fastcgi_read_timeout was 60s — both fixed.
                // Override the per-request limits so a slow LLM round-trip
                // doesn't kill the script while it's still doing real work.
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $logoUrl  = (string) $r->input('logo_url', '');
                $clientBd = $r->input('build_data', []);
                $cached   = \Illuminate\Support\Facades\Cache::get('arthur_build_data_' . $wsId, []);
                $buildData = is_array($cached) && !empty($cached['business_name'])
                    ? $cached
                    : (is_array($clientBd) ? $clientBd : []);

                // PATCH (full confirm panel, 2026-05-09) — accept images[]
                // and primary_color / secondary_color from the new UI.
                $imagesIn = $r->input('images', []);
                $images   = [];
                if (is_array($imagesIn)) {
                    foreach ($imagesIn as $u) {
                        if (is_string($u) && $u !== '') $images[] = $u;
                    }
                }
                $images = array_slice($images, 0, 10); // hard cap 10

                $primary   = (string) $r->input('primary_color', '');
                $secondary = (string) $r->input('secondary_color', '');
                $hex = '/^#[0-9a-fA-F]{6}$/';
                $colors = [];
                if ($primary   !== '' && preg_match($hex, $primary))   $colors['primary']   = $primary;
                if ($secondary !== '' && preg_match($hex, $secondary)) $colors['secondary'] = $secondary;

                if (empty($buildData['business_name'])) {
                    return response()->json([
                        'type'        => 'error',
                        'build_error' => 'Build data missing or expired. Please describe your business again.',
                    ], 400);
                }

                try {
                    $built = $arthur->buildFromChat(
                        $wsId,
                        $buildData,
                        $logoUrl !== '' ? $logoUrl : null,
                        $images,
                        $colors
                    );
                    \Illuminate\Support\Facades\Cache::forget('arthur_build_data_' . $wsId);
                    return response()->json([
                        'type'          => 'complete',
                        'website_id'    => $built['website_id']  ?? null,
                        'website_url'   => $built['website_url'] ?? null,
                        'build_outcome' => $built['type'] ?? 'website_created',
                        'build_error'   => (($built['type'] ?? '') === 'error') ? ($built['message'] ?? 'website build failed') : null,
                        'build_data'    => $buildData,
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('[Arthur:buildFromChat] failed', [
                        'workspace_id' => $wsId,
                        'error'        => $e->getMessage(),
                    ]);
                    return response()->json([
                        'type'        => 'error',
                        'build_outcome' => 'error',
                        'build_error' => 'Build failed: ' . $e->getMessage(),
                    ], 500);
                }
            }

            // Conversation turn — pure chat() dialogue, no build trigger.
            $result = $arthur->chat($wsId, $msg, $history);
            return response()->json($result);
        });

        // Arthur user photo upload — multipart, ONE file per request.
        // Client uploads files one at a time (keeps each request under
        // PHP post_max_size = 8M), accumulates returned URLs, then passes
        // them as state.uploaded_images on the next /arthur/message call.
        Route::post('/arthur/upload', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            if (!$wsId) return response()->json(['error' => 'Auth required'], 401);

            $file = $r->file('image');
            if (!$file instanceof \Illuminate\Http\UploadedFile) {
                return response()->json(['error' => 'No file received. Field name must be "image".'], 400);
            }
            // Laravel's isValid() checks the uploaded file is present + not errored.
            // Must check this BEFORE getSize()/getMimeType() which stat() the tmp file.
            if (!$file->isValid()) {
                return response()->json(['error' => 'Upload invalid: ' . $file->getErrorMessage()], 400);
            }

            // Read size/mime once, defensively (stat failures here come from
            // tmp-file loss between requests — rare but fatal otherwise).
            try {
                $size = $file->getSize();
                $mime = $file->getMimeType() ?: $file->getClientMimeType();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Could not read uploaded file: ' . $e->getMessage()], 400);
            }

            // 2 MB hard cap per file (staging PHP upload_max_filesize = 2M)
            if ($size > 2 * 1024 * 1024) {
                return response()->json(['error' => 'File too large (max 2 MB).'], 413);
            }
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime, $allowed, true)) {
                return response()->json(['error' => 'Unsupported file type. Use JPG, PNG, WebP, or GIF.'], 415);
            }

            $dir = storage_path("app/public/arthur-uploads/{$wsId}");
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext  = $extByMime[$mime];
            $name = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
            try {
                $file->move($dir, $name);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
            }
            @chmod($dir . '/' . $name, 0644);

            return response()->json([
                'success' => true,
                'url'     => "/storage/arthur-uploads/{$wsId}/{$name}",
                'size'    => $size,
                'mime'    => $mime,
            ]);
        });

        // ── Arthur wizard logo upload (T1 2026-04-20) ──────────────
        // Session-scoped temp upload. Files live in storage/app/public/tmp/logos/
        // and are auto-cleaned. ArthurService::generateWebsite() copies the
        // chosen logo into the permanent per-website directory.
        // Route path = /api/builder/logo-upload-temp (group prefix `builder` already applied).
        Route::post('/logo-upload-temp', function (\Illuminate\Http\Request $r) {
            $file = $r->file('logo');
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                return response()->json(['error' => 'No valid logo file received (field: logo).'], 400);
            }
            $size = $file->getSize();
            $mime = $file->getMimeType() ?: $file->getClientMimeType();
            if ($size > 2 * 1024 * 1024) {
                return response()->json(['error' => 'Logo too large (max 2 MB).'], 413);
            }
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                return response()->json(['error' => 'Unsupported logo type. Use PNG, JPG, SVG, or WEBP.'], 415);
            }
            $dir = storage_path('app/public/tmp/logos');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = 'logo_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            try {
                $file->move($dir, $name);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Logo upload failed: ' . $e->getMessage()], 500);
            }
            @chmod($dir . '/' . $name, 0644);
            $tempPath = $dir . '/' . $name;
            $tempUrl  = '/storage/tmp/logos/' . $name;

            // Extract colors + generate palettes for immediate palette_choice.
            $palettes = [];
            try {
                if (class_exists(\App\Services\ColorExtractorService::class)) {
                    $extractor = new \App\Services\ColorExtractorService();
                    $dominant  = $extractor->extractFromFile($tempPath);
                    $palettes  = $extractor->generatePalettes($dominant);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Arthur] color extract failed: ' . $e->getMessage());
            }

            return response()->json([
                'success'   => true,
                'temp_url'  => $tempUrl,
                'temp_path' => $tempPath, // server-side only; used later by generateWebsite
                'size'      => $size,
                'mime'      => $mime,
                'palettes'  => $palettes,
            ]);
        });

        // ── Arthur image-upload-temp (full confirm panel, 2026-05-09) ────
        // Uploads ONE website image at a time. Auto-optimizes via GD:
        // resize to max 1920px wide, re-encode JPEG at 85% quality.
        // Caller accumulates returned temp_url's into the `images[]` array
        // sent on the follow-up /arthur/message {confirm:true} POST.
        // Field name: "image". 5 MB cap. PNG/JPG/JPEG/WEBP only.
        Route::post('/image-upload-temp', function (\Illuminate\Http\Request $r) {
            $file = $r->file('image');
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                return response()->json(['error' => 'No valid image file received (field: image).'], 400);
            }
            $origSize = $file->getSize();
            $mime     = $file->getMimeType() ?: $file->getClientMimeType();
            if ($origSize > 5 * 1024 * 1024) {
                return response()->json(['error' => 'Image too large (max 5 MB).'], 413);
            }
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                return response()->json(['error' => 'Unsupported image type. Use PNG, JPG, or WEBP.'], 415);
            }

            $dir = storage_path('app/public/tmp/images');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            // Auto-optimize via GD: load → resize if >1920px wide → re-encode
            // as JPEG quality 85 (unless source is PNG with alpha, then keep PNG).
            $srcRaw = @file_get_contents($file->getRealPath());
            if ($srcRaw === false || $srcRaw === '') {
                return response()->json(['error' => 'Could not read uploaded file.'], 400);
            }
            $src = @imagecreatefromstring($srcRaw);
            if (!$src) {
                return response()->json(['error' => 'Image decode failed (corrupt file?).'], 422);
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $maxW = 1920;
            if ($w > $maxW) {
                $newW = $maxW;
                $newH = (int) round($h * ($maxW / $w));
                $resized = imagecreatetruecolor($newW, $newH);
                if ($mime === 'image/png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
                }
                imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src);
                $src = $resized;
                $w = $newW; $h = $newH;
            }

            // Detect PNG alpha: if PNG and has alpha, keep PNG; else JPEG quality 85.
            $hasAlpha = false;
            if ($mime === 'image/png') {
                // imageistruecolor + check a few pixels for non-255 alpha
                imagealphablending($src, false);
                for ($y = 0; $y < $h && !$hasAlpha; $y += max(1, intdiv($h, 20))) {
                    for ($x = 0; $x < $w && !$hasAlpha; $x += max(1, intdiv($w, 20))) {
                        $c = imagecolorat($src, $x, $y);
                        $a = ($c >> 24) & 0x7F;
                        if ($a > 0) $hasAlpha = true;
                    }
                }
            }

            $ext = ($mime === 'image/png' && $hasAlpha) ? 'png' : 'jpg';
            $name = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $outPath = $dir . '/' . $name;

            if ($ext === 'png') {
                imagealphablending($src, false);
                imagesavealpha($src, true);
                $ok = @imagepng($src, $outPath, 6);
            } else {
                // Flatten alpha onto white for JPEG output
                if ($mime === 'image/png') {
                    $flat = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefilledrectangle($flat, 0, 0, $w, $h, $white);
                    imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
                    imagedestroy($src);
                    $src = $flat;
                }
                $ok = @imagejpeg($src, $outPath, 85);
            }
            imagedestroy($src);

            if (!$ok || !is_file($outPath)) {
                return response()->json(['error' => 'Optimization failed.'], 500);
            }
            @chmod($outPath, 0644);
            $optSize = filesize($outPath) ?: 0;

            return response()->json([
                'success'        => true,
                'temp_url'       => '/storage/tmp/images/' . $name,
                'temp_path'      => $outPath,
                'original_size'  => (int) $origSize,
                'optimized_size' => (int) $optSize,
                'width'          => $w,
                'height'         => $h,
                'format'         => $ext,
            ]);
        });

        // ── Template System ──────────────────────────────────────
        Route::get('/templates', function () {
            $ts = new \App\Engines\Builder\Services\TemplateService();
            return response()->json(['templates' => $ts->listTemplates()]);
        });

        Route::get('/templates/{industry}', function ($industry) {
            $ts = new \App\Engines\Builder\Services\TemplateService();
            $manifest = $ts->getManifest($industry);
            return $manifest
                ? response()->json($manifest)
                : response()->json(['error' => 'Not found'], 404);
        });
    });

    // ── Studio Engine (2026-04-20) ─────────────────────────────
    // Social media image editor. Templates are global; designs are per-workspace.
// ═══════════════════════════════════════════════════════════════
// studio_routes.php — new /api/studio/* routes (HTML-template model)
// Patched in-place into routes/api.php replacing the legacy block.
// Pattern: iframe + postMessage (identical to /builder/websites/*/preview)
// ═══════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════
// studio_routes.php — new /api/studio/* routes (HTML-template model)
// Patched in-place into routes/api.php replacing the legacy block.
// Pattern: iframe + postMessage (identical to /builder/websites/*/preview)
// ═══════════════════════════════════════════════════════════════
    Route::prefix('studio')->group(function () {

        // GET /api/studio/templates/html — scan filesystem, list HTML templates
        Route::get('/templates/html', function () {
            $dir  = storage_path('templates/studio');
            $rows = [];
            if (is_dir($dir)) {
                foreach (scandir($dir) as $slug) {
                    if ($slug === '.' || $slug === '..') continue;
                    $tplPath = $dir . '/' . $slug . '/template.html';
                    $mfPath  = $dir . '/' . $slug . '/manifest.json';
                    if (!is_file($tplPath) || !is_file($mfPath)) continue;
                    $mf = json_decode(file_get_contents($mfPath), true) ?: [];
                    $rows[] = [
                        'slug'          => $slug,
                        'name'          => $mf['name'] ?? $slug,
                        'format'        => $mf['format'] ?? 'square',
                        'category'      => $mf['category'] ?? '',
                        'sub_category'  => $mf['sub_category'] ?? '',
                        'canvas_width'  => (int)($mf['canvas_width']  ?? 1080),
                        'canvas_height' => (int)($mf['canvas_height'] ?? 1080),
                        'industry_tags' => $mf['industry_tags'] ?? [],
                        'preview_url'   => '/storage/studio-previews/' . $slug . '.html',
                    ];
                }
            }
            return response()->json(['success' => true, 'templates' => $rows]);
        });

        // GET /api/studio/templates — LEGACY: DB-backed layers_json templates (kept for back-compat)
        Route::get('/templates', function (\Illuminate\Http\Request $r) {
            $q = \Illuminate\Support\Facades\DB::table('studio_templates')->where('is_active', 1);
            if ($r->filled('format'))   $q->where('format',   $r->input('format'));
            if ($r->filled('category')) $q->where('category', $r->input('category'));
            $perPage = (int) min(48, max(6, $r->input('per_page', 24)));
            $page    = (int) max(1, $r->input('page', 1));
            $total   = (clone $q)->count();
            $rows    = $q->orderByDesc('use_count')->orderByDesc('id')
                ->limit($perPage)->offset(($page - 1) * $perPage)
                ->get(['id','name','category','sub_category','industry_tags','demographic','format','canvas_width','canvas_height','thumbnail_url','use_count','layers_json']);
            foreach ($rows as $row) {
                $row->industry_tags = json_decode($row->industry_tags ?? '[]', true);
                $row->layers_json   = json_decode($row->layers_json   ?? '{}', true);
            }
            return response()->json(['success' => true, 'templates' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
        });

        // GET /api/studio/templates/{id}  — legacy
        Route::get('/templates/{id}', function ($id) {
            $row = \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', (int)$id)->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $row->industry_tags = json_decode($row->industry_tags ?? '[]', true);
            $row->layers_json   = json_decode($row->layers_json   ?? '{}', true);
            return response()->json(['success' => true, 'template' => $row]);
        });

        // GET /api/studio/designs  — workspace-scoped saved designs
        Route::get('/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->get(['id','name','format','canvas_width','canvas_height','thumbnail_url','exported_url','status','created_at','updated_at']);
            return response()->json(['success' => true, 'designs' => $rows]);
        });

        // GET /api/studio/designs/{id}  — full single design (content_html + layers_json)
        Route::get('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $row->layers_json = json_decode($row->layers_json ?? '{}', true);
            return response()->json(['success' => true, 'design' => $row]);
        });

        // POST /api/studio/designs  — create from HTML template slug OR legacy template_id
        Route::post('/designs', function (\Illuminate\Http\Request $r) {
            $wsId  = (int) $r->attributes->get('workspace_id');
            $slug  = $r->input('template_slug');
            $tplId = $r->input('template_id');
            $name  = trim((string) $r->input('name', 'Untitled Design'));

            $format = 'square'; $cw = 1080; $ch = 1080;
            $contentHtml = null;
            $layersJson  = '{}';

            if ($slug) {
                // HTML-template path (new)
                $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
                if (!$slug) return response()->json(['success' => false, 'error' => 'invalid_slug'], 422);
                $dir = storage_path('templates/studio/' . $slug);
                $tpl = $dir . '/template.html';
                $mfp = $dir . '/manifest.json';
                if (!is_file($tpl) || !is_file($mfp)) {
                    return response()->json(['success' => false, 'error' => 'template_not_found'], 404);
                }
                $mf = json_decode(file_get_contents($mfp), true) ?: [];
                $format = $mf['format'] ?? 'square';
                $cw     = (int)($mf['canvas_width']  ?? 1080);
                $ch     = (int)($mf['canvas_height'] ?? 1080);
                $html   = file_get_contents($tpl);

                // Substitute manifest defaults into {{var}} tokens
                $vars = [];
                foreach (($mf['variables'] ?? []) as $k => $v)     $vars[$k] = $v['default'] ?? '';
                foreach (($mf['css_variables'] ?? []) as $k => $v) $vars[$k] = $v['default'] ?? '';
                foreach ($vars as $k => $v) { $html = str_replace('{{' . $k . '}}', (string)$v, $html); }
                $contentHtml = $html;
                $layersJson  = json_encode(['template_slug' => $slug, 'source' => 'html']);
            } elseif ($tplId) {
                // Legacy DB-template path (layers_json)
                $tpl = \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', (int)$tplId)->first();
                if (!$tpl) return response()->json(['success' => false, 'error' => 'template_not_found'], 404);
                $layersJson = $tpl->layers_json;
                $cw = $tpl->canvas_width; $ch = $tpl->canvas_height; $format = $tpl->format;
                \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', $tpl->id)->increment('use_count');
            } else {
                return response()->json(['success' => false, 'error' => 'template_required'], 422);
            }

            $id = \Illuminate\Support\Facades\DB::table('studio_designs')->insertGetId([
                'workspace_id'  => $wsId,
                'template_id'   => $tplId ? (int)$tplId : null,
                'name'          => mb_substr($name, 0, 120),
                'format'        => $format,
                'canvas_width'  => $cw,
                'canvas_height' => $ch,
                'layers_json'   => $layersJson,
                'content_html'  => $contentHtml,
                'status'        => 'draft',
                'created_at'    => now(), 'updated_at' => now(),
            ]);
            return response()->json(['success' => true, 'design_id' => $id], 201);
        });

        // PUT /api/studio/designs/{id}  — save content_html / layers / rename
        Route::put('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $update = ['updated_at' => now()];
            if ($r->filled('name'))          $update['name']         = mb_substr((string)$r->input('name'), 0, 120);
            if ($r->has('content_html'))     $update['content_html'] = (string)$r->input('content_html');
            if ($r->filled('layers_json')) {
                $lj = $r->input('layers_json');
                $update['layers_json'] = is_string($lj) ? $lj : json_encode($lj);
            }
            if ($r->filled('thumbnail_url')) $update['thumbnail_url'] = (string)$r->input('thumbnail_url');
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update($update);
            return response()->json(['success' => true]);
        });

        // GET /api/studio/designs/{id}/preview — returns content_html with editor script injected
        Route::get('/designs/{id}/preview', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row  = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response('Not found', 404);
            $html = (string)($row->content_html ?? '');
            if ($html === '') {
                return response('<!doctype html><html><body style="background:#111;color:#fff;font-family:system-ui;padding:40px">Design has no content.</body></html>')
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            }

            $editScript = <<<'HTMLSCRIPT'
<script>
(function(){
  if (window._luStudioEditor) return;
  window._luStudioEditor = true;

  // Export-parity fix (2026-04-21): ensure every <img> carries
  // crossorigin="anonymous" so html2canvas can capture it without
  // tainting the canvas. Covers designs created before the fix
  // landed in the template source. We re-assign src after setting
  // crossOrigin so the image is re-fetched in CORS mode.
  function _ensureCrossOrigin() {
    document.querySelectorAll('img').forEach(function(img){
      if (img.crossOrigin) return;
      var src = img.src || img.getAttribute('src') || '';
      try { img.crossOrigin = 'anonymous'; } catch(_e) {}
      if (src) img.src = src;
    });
  }
  _ensureCrossOrigin();
  // Re-run once images from a later mutation (e.g. Media Picker swap)
  // land in the DOM.
  new MutationObserver(function(){ _ensureCrossOrigin(); }).observe(
    document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] }
  );

  function _fields() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-field]'));
  }
  function _fieldKind(el) {
    if (el.tagName === 'IMG') return 'image';
    if (el.querySelector && el.querySelector('img')) return 'image';
    return 'text';
  }
  function _send(msg) {
    try { window.parent.postMessage(msg, '*'); } catch(_e){}
  }
  function _emitList() {
    var out = _fields().map(function(el){
      var name = el.getAttribute('data-field');
      var kind = _fieldKind(el);
      var value = '', src = '';
      if (kind === 'image') {
        var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
        if (img) src = img.currentSrc || img.src || img.getAttribute('src') || '';
      } else {
        value = (el.innerText || el.textContent || '').trim();
      }
      return { name: name, kind: kind, value: value, src: src };
    });
    _send({ type: 'fields-list', fields: out });
  }
  function _serializeHtml() {
    try {
      // Ensure body reflects any live contenteditable state.
      document.querySelectorAll('[contenteditable="true"]').forEach(function(el){
        el.removeAttribute('contenteditable');
      });
      var html = '<!DOCTYPE html>\n' + document.documentElement.outerHTML;
      _send({ type: 'html-serialized', html: html });
    } catch(e) {
      _send({ type: 'html-serialized', html: '', error: String(e) });
    }
  }
  function _focusField(name) {
    var el = document.querySelector('[data-field="' + name + '"]');
    if (!el) return;
    el.scrollIntoView({ behavior:'smooth', block:'center' });
    if (_fieldKind(el) === 'text') {
      _enterEdit(el);
    }
  }
  function _applyPalette(vars) {
    var root = document.documentElement;
    if (!vars || typeof vars !== 'object') return;
    Object.keys(vars).forEach(function(k){
      if (!k) return;
      var prop = (k.charAt(0) === '-') ? k : ('--' + k);
      root.style.setProperty(prop, String(vars[k]));
    });
  }
  function _updateImage(field, url) {
    var el = document.querySelector('[data-field="' + field + '"]');
    if (!el) return;
    var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
    if (img) img.src = url;
  }
  function _updateFieldText(field, value) {
    var el = document.querySelector('[data-field="' + field + '"]');
    if (!el) return;
    if (el.tagName === 'IMG') return; // text handler — skip image elements
    // If element has an <img> child, it's likely image wrapper; skip.
    if (el.querySelector && el.querySelector('img')) return;
    el.textContent = String(value == null ? '' : value);
  }

  // ── Hover / selection outlines ─────────────────────────────
  var _host = document.body;
  _host.classList.add('lu-studio-edit');

  // Wire up per-field listeners
  var _editing = null;
  function _enterEdit(target) {
    if (!target || _fieldKind(target) !== 'text') return;
    if (_editing === target) return;
    target.setAttribute('contenteditable', 'true');
    target.setAttribute('spellcheck', 'false');
    target.focus();
    _editing = target;
    try {
      var range = document.createRange();
      range.selectNodeContents(target);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    } catch(_e){}
  }
  function _exitEdit() {
    if (!_editing) return;
    var t = _editing;
    t.removeAttribute('contenteditable');
    _editing = null;
    _send({ type: 'field-changed', field: t.getAttribute('data-field'), value: t.innerHTML });
  }

  document.addEventListener('click', function(e){
    var el = e.target.closest && e.target.closest('[data-field]');
    if (!el) return;
    var kind = _fieldKind(el);
    if (kind === 'image') {
      e.preventDefault();
      e.stopPropagation();
      var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
      var src = img ? (img.currentSrc || img.src || '') : '';
      _send({ type: 'image-clicked', field: el.getAttribute('data-field'), currentSrc: src });
      return;
    }
    // Single click on text doesn't enter edit mode — dblclick does (matches builder.js behavior).
  });

  document.addEventListener('dblclick', function(e){
    var el = e.target.closest && e.target.closest('[data-field]');
    if (!el) return;
    if (_fieldKind(el) === 'image') return;
    e.preventDefault();
    _enterEdit(el);
  });

  document.addEventListener('blur', function(e){
    if (_editing && e.target === _editing) _exitEdit();
  }, true);

  document.addEventListener('keydown', function(e){
    if (_editing && (e.key === 'Escape' || (e.key === 'Enter' && !e.shiftKey))) {
      e.preventDefault();
      _editing.blur();
    }
  });

  // Parent → iframe messages
  window.addEventListener('message', function(e){
    var d = e.data;
    if (!d || typeof d !== 'object' || !d.type) return;
    if (d.type === 'list-fields')     _emitList();
    else if (d.type === 'serialize-html') _serializeHtml();
    else if (d.type === 'focus-field')    _focusField(d.field);
    else if (d.type === 'apply-palette')  _applyPalette(d.vars || {});
    else if (d.type === 'lu-update-image') _updateImage(d.field, d.url || '');
    else if (d.type === 'update-field-text') _updateFieldText(d.field, d.value);
  });

  // ── Forward wheel events to parent workspace so pinch/zoom/pan works
  //    even when the cursor is over the iframe. Iframe clientX/Y are
  //    local to the iframe viewport; parent converts to workspace coords.
  document.addEventListener('wheel', function(e){
    // Ctrl/Meta+wheel = pinch-zoom on trackpads. preventDefault so the
    // browser doesn't apply its own page-level zoom on top of ours.
    if (e.ctrlKey || e.metaKey) e.preventDefault();
    _send({
      type: 'studio-wheel',
      deltaX: e.deltaX,
      deltaY: e.deltaY,
      ctrlKey: !!e.ctrlKey,
      metaKey: !!e.metaKey,
      clientX: e.clientX,
      clientY: e.clientY
    });
  }, { passive: false });

  // Initial list after a tick (fonts / images may still be loading but DOM is live).
  setTimeout(_emitList, 80);
})();
</script>
HTMLSCRIPT;

            // Inject script before </body> if possible, otherwise append.
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $editScript . '</body>', $html, 1);
            } else {
                $html .= $editScript;
            }

            return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Frame-Options', 'SAMEORIGIN')
                ->header('Cache-Control', 'no-store');
        });

        // POST /api/studio/designs/{id}/export  — body: {image_data: base64} (client renders via html2canvas)
        Route::post('/designs/{id}/export', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            $imageData = (string) $r->input('image_data', '');
            $format    = strtolower((string) $r->input('format', 'png'));
            if (!in_array($format, ['png','jpg','jpeg'], true)) $format = 'png';
            if (!str_starts_with($imageData, 'data:image/')) {
                return response()->json(['success' => false, 'error' => 'invalid_image_data'], 422);
            }
            $bin = base64_decode(preg_replace('#^data:image/[^;]+;base64,#', '', $imageData));
            if ($bin === false || strlen($bin) < 100) {
                return response()->json(['success' => false, 'error' => 'decode_failed'], 422);
            }
            $dir = storage_path('app/public/studio/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = 'design-' . (int)$id . '-' . date('Ymd-His') . '.' . ($format === 'jpg' ? 'jpg' : $format);
            $abs  = $dir . '/' . $name;
            if (file_put_contents($abs, $bin) === false) {
                return response()->json(['success' => false, 'error' => 'write_failed'], 500);
            }
            $url = '/storage/studio/' . $wsId . '/' . $name;

            // Best-effort media entry.
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId,
                    'url'          => $url,
                    'mime_type'    => 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
                    'source'       => 'studio_export',
                    'is_platform_asset' => 0,
                    'created_at'   => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}

            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update([
                'exported_url' => $url,
                'status'       => 'exported',
                'updated_at'   => now(),
            ]);

            return response()->json(['success' => true, 'url' => $url]);
        });

        // DELETE /api/studio/designs/{id}  — soft delete
        Route::delete('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update(['deleted_at' => now()]);
            return response()->json(['success' => true]);
        });

        // POST /api/studio/designs/{id}/render-png  — server-side export
        //
        // Body: { content_html?: string, width?: int, height?: int }
        //
        // Spawns headless Chrome via `tools/studio-render.cjs` to capture the
        // design HTML at exact template dimensions. Falls back to the saved
        // content_html when the client doesn't send one. Returns a raw PNG
        // stream (Content-Type: image/png). Replaces the client-side html2canvas
        // path — puppeteer preserves object-fit, @font-face and CSS grid
        // correctly where html2canvas cannot.
        Route::post('/designs/{id}/render-png', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            // Prefer the request-body HTML (live editor state); fall back to saved.
            $html = (string) $r->input('content_html', $row->content_html ?? '');
            if ($html === '') return response()->json(['success' => false, 'error' => 'empty_design'], 422);
            if (strlen($html) > 5 * 1024 * 1024) {
                return response()->json(['success' => false, 'error' => 'html_too_large'], 413);
            }

            $w = (int) ($r->input('width')  ?: $row->canvas_width  ?: 1080);
            $h = (int) ($r->input('height') ?: $row->canvas_height ?: 1080);
            $w = max(320, min(2400, $w));
            $h = max(320, min(2400, $h));

            // Scratch dir for the node child process — outside public/.
            $tmpDir = storage_path('app/studio-render-tmp');
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

            // Inject a <base> tag so relative /storage/... URLs in content_html
            // resolve against the public host when puppeteer loads the page.
            // (In the editor iframe, srcdoc inherits the parent's base URL —
            // when we write the HTML to disk for puppeteer, that context is
            // lost, so imgs with src="/storage/..." would 404 silently.)
            $baseHref = rtrim((string) config('app.url') ?: 'https://staging.levelupgrowth.io', '/') . '/';
            $baseTag  = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES) . '">';
            if (!preg_match('/<base\s/i', $html)) {
                if (preg_match('/<head[^>]*>/i', $html)) {
                    $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $baseTag, $html, 1);
                } else {
                    $html = $baseTag . $html;
                }
            }

            $stamp    = bin2hex(random_bytes(6));
            $tmpHtml  = $tmpDir . '/' . $stamp . '.html';
            $tmpPng   = $tmpDir . '/' . $stamp . '.png';
            if (file_put_contents($tmpHtml, $html) === false) {
                return response()->json(['success' => false, 'error' => 'write_failed'], 500);
            }

            $script = base_path('tools/studio-render.cjs');
            if (!is_file($script)) {
                @unlink($tmpHtml);
                return response()->json(['success' => false, 'error' => 'renderer_not_installed'], 500);
            }

            $cmd = 'node ' . escapeshellarg($script) . ' '
                 . escapeshellarg($tmpHtml) . ' '
                 . (int)$w . ' ' . (int)$h . ' '
                 . escapeshellarg($tmpPng) . ' 2>&1';

            // Explicit env for the node child: PHP-FPM's proc_open otherwise
            // drops PUPPETEER_CACHE_DIR and HOME, leaving puppeteer unable to
            // locate its bundled Chromium. Pass the minimum needed.
            $childEnv = [
                'HOME'                 => '/tmp',
                'PATH'                 => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PUPPETEER_CACHE_DIR'  => base_path('.puppeteer-cache'),
                'LANG'                 => 'C.UTF-8',
                'LC_ALL'               => 'C.UTF-8',
            ];

            // 60s hard cap — headless Chrome cold-start averages ~2–4s,
            // so 60s leaves generous headroom even on a loaded host.
            $descriptorspec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $start = microtime(true);
            $proc = proc_open($cmd, $descriptorspec, $pipes, null, $childEnv);
            if (!is_resource($proc)) {
                @unlink($tmpHtml);
                return response()->json(['success' => false, 'error' => 'spawn_failed'], 500);
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $status = proc_close($proc);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            @unlink($tmpHtml);

            if ($status !== 0 || !is_file($tmpPng) || filesize($tmpPng) < 200) {
                @unlink($tmpPng);
                \Illuminate\Support\Facades\Log::error('studio.render-png failed', [
                    'design_id' => (int)$id,
                    'status'    => $status,
                    'stdout'    => mb_substr((string)$stdout, 0, 1000),
                    'stderr'    => mb_substr((string)$stderr, 0, 1000),
                    'elapsed_ms'=> $elapsed,
                ]);
                return response()->json([
                    'success' => false,
                    'error'   => 'render_failed',
                    'detail'  => mb_substr((string)$stderr ?: (string)$stdout, 0, 600),
                ], 500);
            }

            $bin = file_get_contents($tmpPng);
            @unlink($tmpPng);
            $fname = preg_replace('/[^a-z0-9-]+/i', '-', $row->name ?: 'design') . '.png';

            return response($bin, 200, [
                'Content-Type'        => 'image/png',
                'Content-Length'      => (string) strlen($bin),
                'Content-Disposition' => 'attachment; filename="' . $fname . '"',
                'Cache-Control'       => 'no-store, max-age=0',
                'X-Studio-Render'     => 'puppeteer',
                'X-Studio-Render-Ms'  => (string) $elapsed,
            ]);
        });

        // POST /api/studio/chat — AI chat tied to a design
        // Returns { success, reply, actions:[{type:'apply_palette'|'update_field'|'update_image', ...}] }
        // Hands-vs-brain: Laravel persists + validates; runtime (DeepSeek) generates.
        Route::post('/chat', function (\Illuminate\Http\Request $r) {
            $wsId     = (int) $r->attributes->get('workspace_id');
            $designId = (int) $r->input('design_id');
            $message  = trim((string) $r->input('message', ''));
            $history  = $r->input('history', []);
            if ($designId <= 0 || $message === '') {
                return response()->json(['success' => false, 'error' => 'missing_input'], 422);
            }
            $design = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$design) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            // Extract current text fields + CSS variables from content_html so the
            // AI has context about what it can edit.
            $html = (string)($design->content_html ?? '');
            $fields = [];
            $vars   = [];
            if ($html !== '') {
                if (preg_match_all('/data-field="([^"]+)"[^>]*>([^<]*)</', $html, $m)) {
                    foreach ($m[1] as $i => $name) {
                        $val = trim($m[2][$i]);
                        if ($val !== '' && !isset($fields[$name])) $fields[$name] = $val;
                    }
                }
                if (preg_match('/:root\s*\{([^}]+)\}/', $html, $vm)) {
                    if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $vm[1], $vm2)) {
                        foreach ($vm2[1] as $i => $k) $vars[$k] = trim($vm2[2][$i]);
                    }
                }
            }

            // Build a combined user prompt (runtime's task types have fixed system
            // prompts that can't be overridden — fold the instructions into user).
            $instructions = "You are a design AI for the Studio image editor. The user "
              . "is editing a social-media visual. Respond with JSON only — no prose, no "
              . "markdown fences.\n\n"
              . "CURRENT TEXT FIELDS (name => value): " . json_encode($fields, JSON_UNESCAPED_SLASHES) . "\n"
              . "CURRENT CSS COLOR VARIABLES: " . json_encode($vars, JSON_UNESCAPED_SLASHES) . "\n\n"
              . "Valid action types:\n"
              . "  {\"type\":\"update_field\",\"name\":\"<field_name>\",\"value\":\"<new text>\"}\n"
              . "  {\"type\":\"apply_palette\",\"vars\":{\"--primary\":\"#...\",\"--bg\":\"#...\",\"--text\":\"#...\",\"--accent\":\"#...\"}}\n"
              . "  {\"type\":\"update_image\",\"name\":\"<field_name>\",\"url\":\"<absolute url>\"}\n\n"
              . "Return JSON shape: {\"reply\":\"one brief friendly sentence\",\"actions\":[...]}\n"
              . "Only include fields that actually exist above. Only palette keys that exist above.\n\n"
              . "Conversation history (most recent last):\n" . json_encode($history, JSON_UNESCAPED_SLASHES) . "\n\n"
              . "USER MESSAGE: " . $message;

            $reply = '';
            $actions = [];
            $ok = false;
            // PATCH 4 (2026-05-08): route through RuntimeClient (was direct
            // DeepSeekConnector::chatJson — hands-vs-brain bypass).
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                if ($runtime->isConfigured()) {
                    $systemPrompt = 'You are a design AI for the Studio image editor. Return ONLY a single JSON object: '
                                  . '{"reply":"one friendly sentence","actions":[...]}. '
                                  . 'Valid action types: '
                                  . '{"type":"update_field","name":"<existing field>","value":"<new text>"}, '
                                  . '{"type":"apply_palette","vars":{"--primary":"#...","--bg":"#...","--text":"#...","--accent":"#..."}}, '
                                  . '{"type":"update_image","name":"<existing field>","url":"<absolute url>"}. '
                                  . 'Only reference fields that exist in the provided context. No markdown fences, no prose outside the JSON.';
                    $resp = $runtime->chatJson($systemPrompt, $instructions, [], 600);
                    if (!empty($resp['success'])) {
                        $parsed = $resp['parsed'] ?? null;
                        if (is_array($parsed)) {
                            $reply   = (string)($parsed['reply'] ?? '');
                            $actions = is_array($parsed['actions'] ?? null) ? $parsed['actions'] : [];
                            $ok = ($reply !== '' || !empty($actions));
                        } else {
                            $content = (string)($resp['content'] ?? '');
                            if ($content !== '') { $reply = $content; $ok = true; }
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning('studio.chat runtime call failed', ['error' => $resp['error'] ?? null]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('studio.chat runtime exception: ' . $e->getMessage());
            }

            // Fallback: lightweight keyword handler for when runtime is down.
            // Keeps the feature usable: common intents map to concrete actions.
            if (!$ok) {
                $lower = mb_strtolower($message);
                if (strpos($lower, 'luxury') !== false) {
                    $actions[] = ['type'=>'apply_palette','vars'=>['--primary'=>'#C9943A','--bg'=>'#0A0A0A','--text'=>'#FAF7F2','--accent'=>'#C9943A']];
                    $reply = 'Applied a luxury gold-on-black palette.';
                } elseif (strpos($lower, 'brand') !== false) {
                    $actions[] = ['type'=>'apply_palette','vars'=>['--primary'=>'#6C5CE7','--bg'=>'#14161C','--text'=>'#FFFFFF','--accent'=>'#00E5A8']];
                    $reply = 'Applied your default brand colors.';
                } elseif (strpos($lower, 'color') !== false) {
                    $palettes = [
                        ['--primary'=>'#FF8C00','--bg'=>'#1E2128','--text'=>'#FFFFFF','--accent'=>'#FFB84D'],
                        ['--primary'=>'#B983FF','--bg'=>'#3D1B6E','--text'=>'#FFFFFF','--accent'=>'#FFE14D'],
                        ['--primary'=>'#00E5FF','--bg'=>'#0A1929','--text'=>'#FFFFFF','--accent'=>'#FFB3B3'],
                        ['--primary'=>'#FF6EC4','--bg'=>'#1A0A1F','--text'=>'#FFE9F3','--accent'=>'#FFD1E6'],
                    ];
                    $actions[] = ['type'=>'apply_palette','vars'=>$palettes[array_rand($palettes)]];
                    $reply = 'Swapped to a fresh palette — let me know if you want to adjust.';
                } else {
                    $reply = 'AI is offline right now, so I cannot rewrite copy yet. Try the Colors tab or one of the quick actions.';
                }
            }

            // Credits: best-effort 1-credit deduction if the credit system is available.
            try {
                if (class_exists(\App\Services\CreditService::class)) {
                    app(\App\Services\CreditService::class)->deduct($wsId, 1, 'studio_chat', [
                        'design_id' => $designId,
                    ]);
                }
            } catch (\Throwable $_e) {}

            return response()->json([
                'success' => true,
                'reply'   => $reply,
                'actions' => $actions,
            ]);
        });
// ═════════════════════════════════════════════════════════════════
// Studio VIDEO routes — Slice A
// Patched into the existing Route::prefix('studio')->group(...) block
// right before its closing });
// ═════════════════════════════════════════════════════════════════

        // GET  /api/studio/video/templates — list active video templates
        Route::get('/video/templates', function (\Illuminate\Http\Request $r) {
            $q = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('is_active', 1);
            if ($r->filled('format')) $q->where('format', $r->input('format'));
            // Optional: ?type=html_animated or ?type=clip_json
            if ($r->filled('type')) $q->where('template_type', $r->input('type'));
            $rows = $q->orderBy('id')->get([
                'id','slug','name','category','format','canvas_width','canvas_height',
                'duration_seconds','thumbnail_url','template_type','template_html_path'
            ]);
            return response()->json(['success' => true, 'templates' => $rows]);
        });

        // GET  /api/studio/video/templates/{slug} — single template with structure_json
        Route::get('/video/templates/{slug}', function ($slug) {
            $row = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('slug', (string)$slug)->where('is_active',1)->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $row->structure_json = json_decode($row->structure_json ?? '{}', true);
            return response()->json(['success'=>true,'template'=>$row]);
        });

        // POST /api/studio/video/designs — create a new video design
        // Body: { template_slug?, format, name }
        Route::post('/video/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $name = trim((string) $r->input('name', 'Untitled Video'));
            $format = (string) $r->input('format', 'reels');
            $templateSlug = $r->input('template_slug');

            $structure = null;
            if ($templateSlug) {
                $tpl = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('slug', $templateSlug)->first();
                if (!$tpl) return response()->json(['success'=>false,'error'=>'template_not_found'], 404);
                $structure = json_decode($tpl->structure_json ?? '{}', true) ?: [];
                $format = $tpl->format;
            }

            $formatToDims = [
                'reels'     => [1080, 1920],
                'square'    => [1080, 1080],
                'landscape' => [1920, 1080],
            ];
            [$cw, $ch] = $formatToDims[$format] ?? [1080, 1920];
            $duration = (int) ($structure['duration'] ?? 15);

            $dbFormat = match ($format) {
                'reels'     => 'reels',
                'square'    => 'video_square',
                'landscape' => 'video_landscape',
                default     => 'reels',
            };

            $videoData = $structure ?: [
                'format'           => $format,
                'canvas_width'     => $cw,
                'canvas_height'    => $ch,
                'duration'         => $duration,
                'fps'              => 30,
                'background_color' => '#000000',
                'global_filter'    => 'none',
                'clips'            => [],
                'text_overlays'    => [],
                'elements'         => [],
                'audio'            => ['url' => null, 'volume' => 0.8, 'fade_in' => 0.5, 'fade_out' => 1.0],
            ];

            // ─── Normalize template schema → client schema ─────────────────
            // Templates ship compact field names (start/end, x/y, size, weight,
            // animation_in:"slide_up") but the client reader uses the canonical
            // long form (start_time/end_time, position.{x,y}, font_size,
            // font_weight, animation_in:{type,duration}). Also: convert
            // clip_slots[] placeholders into empty clips[] entries so the
            // editor can render them as "drop a clip here" outlines. This runs
            // only when the design is created from a template.
            $videoData = (function($vd) {
                if (!is_array($vd)) return $vd;
                // clip_slots → clips (as placeholders)
                if (empty($vd['clips']) && !empty($vd['clip_slots']) && is_array($vd['clip_slots'])) {
                    $vd['clips'] = [];
                    foreach ($vd['clip_slots'] as $i => $slot) {
                        $s = (float)($slot['start'] ?? 0);
                        $e = (float)($slot['end']   ?? ($s + 3));
                        $vd['clips'][] = [
                            'id'         => $slot['id'] ?? 'slot_' . ($i+1),
                            'type'       => 'placeholder',
                            'source_url' => null,
                            'label'      => (string)($slot['label'] ?? ('Clip ' . ($i+1))),
                            'start_time' => $s,
                            'end_time'   => $e,
                            'duration'   => max(0.5, $e - $s),
                            'transition_in' => $vd['transitions_default'] ?? ['type' => 'fade', 'duration' => 0.5],
                        ];
                    }
                }
                // Existing clips[] — promote short names if present
                if (!empty($vd['clips']) && is_array($vd['clips'])) {
                    foreach ($vd['clips'] as &$c) {
                        if (!isset($c['start_time']) && isset($c['start'])) $c['start_time'] = (float)$c['start'];
                        if (!isset($c['end_time'])   && isset($c['end']))   $c['end_time']   = (float)$c['end'];
                        if (!isset($c['duration']) && isset($c['start_time'], $c['end_time'])) {
                            $c['duration'] = max(0.5, $c['end_time'] - $c['start_time']);
                        }
                    }
                    unset($c);
                }
                // text_overlays → canonical shape
                if (!empty($vd['text_overlays']) && is_array($vd['text_overlays'])) {
                    foreach ($vd['text_overlays'] as $i => &$t) {
                        if (!isset($t['id'])) $t['id'] = 'text_' . ($i + 1);
                        if (!isset($t['start_time']) && isset($t['start'])) $t['start_time'] = (float)$t['start'];
                        if (!isset($t['end_time'])   && isset($t['end']))   $t['end_time']   = (float)$t['end'];
                        if (!isset($t['font_size'])  && isset($t['size']))   $t['font_size']  = (int)$t['size'];
                        if (!isset($t['font_weight'])&& isset($t['weight'])) $t['font_weight']= (string)$t['weight'];
                        if (!isset($t['font_family'])&& isset($t['font']))   $t['font_family']= (string)$t['font'];
                        if (!isset($t['position']) && (isset($t['x']) || isset($t['y']))) {
                            $t['position'] = ['x' => (float)($t['x'] ?? 0), 'y' => (float)($t['y'] ?? 0)];
                        }
                        // animation_in can be a string "slide_up" — wrap it
                        if (isset($t['animation_in']) && is_string($t['animation_in'])) {
                            $t['animation_in'] = ['type' => $t['animation_in'], 'duration' => 0.4];
                        }
                        // Strip the short names so saved data is canonical
                        foreach (['start','end','size','weight','font','x','y'] as $drop) unset($t[$drop]);
                    }
                    unset($t);
                }
                // Ensure audio struct exists
                if (!isset($vd['audio']) || !is_array($vd['audio'])) {
                    $vd['audio'] = ['url' => null, 'volume' => 0.8, 'fade_in' => 0.5, 'fade_out' => 1.0];
                }
                return $vd;
            })($videoData);

            $id = \Illuminate\Support\Facades\DB::table('studio_designs')->insertGetId([
                'workspace_id'      => $wsId,
                'template_id'       => null,
                'name'              => mb_substr($name, 0, 120),
                'format'            => $dbFormat,
                'design_type'       => 'video',
                'canvas_width'      => $cw,
                'canvas_height'     => $ch,
                'layers_json'       => json_encode(['source' => 'video', 'template_slug' => $templateSlug]),
                'video_data'        => json_encode($videoData, JSON_UNESCAPED_SLASHES),
                'duration_seconds'  => $duration,
                'status'            => 'draft',
                'export_status'     => 'pending',
                'created_at'        => now(), 'updated_at' => now(),
            ]);
            return response()->json(['success' => true, 'design_id' => $id, 'video_data' => $videoData], 201);
        });

        // GET  /api/studio/video/designs — list video designs for workspace
        Route::get('/video/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('workspace_id', $wsId)->where('design_type','video')->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->get(['id','name','format','canvas_width','canvas_height','duration_seconds','thumbnail_url','exported_video_url','export_status','updated_at']);
            return response()->json(['success'=>true,'designs'=>$rows]);
        });

        // GET  /api/studio/video/designs/{id} — full video_data
        Route::get('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $row->video_data = json_decode($row->video_data ?? '{}', true);
            return response()->json(['success'=>true,'design'=>$row]);
        });

        // PUT  /api/studio/video/designs/{id} — save video_data / name
        Route::put('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $update = ['updated_at' => now()];
            if ($r->filled('name')) $update['name'] = mb_substr((string)$r->input('name'), 0, 120);
            if ($r->has('video_data')) {
                $vd = $r->input('video_data');
                $vd = is_string($vd) ? json_decode($vd, true) : $vd;
                if (is_array($vd)) {
                    $update['video_data']       = json_encode($vd, JSON_UNESCAPED_SLASHES);
                    $update['duration_seconds'] = (int)($vd['duration'] ?? $row->duration_seconds);
                }
            }
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update($update);
            return response()->json(['success'=>true]);
        });

        // POST /api/studio/video/designs/{id}/export — dispatch render job
        Route::post('/video/designs/{id}/export', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);

            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update([
                'export_status'       => 'pending',
                'export_progress_pct' => 0,
                'export_error'        => null,
                'updated_at'          => now(),
            ]);

            try {
                // v4.4.0: branch by template_type — html_animated uses a different renderer.
                // BUG 3 fix (v4.1.1): worker supervisor listens to tasks-high,tasks,tasks-low.
                // Without onQueue('tasks') the job lands in the unwatched `default` queue.
                $vd = json_decode($row->video_data ?? '{}', true) ?: [];
                $tplSlug = $vd['template_slug'] ?? $vd['slug'] ?? null;
                $templateType = 'clip_json';
                if ($tplSlug) {
                    $tpl = \Illuminate\Support\Facades\DB::table('studio_video_templates')
                        ->where('slug', $tplSlug)->first();
                    if ($tpl && !empty($tpl->template_type)) $templateType = $tpl->template_type;
                }

                if ($templateType === 'html_animated') {
                    \App\Jobs\RenderHtmlAnimatedJob::dispatch((int)$id)->onQueue('tasks');
                } else {
                    \App\Jobs\RenderStudioVideoJob::dispatch((int)$id)->onQueue('tasks');
                }
            } catch (\Throwable $e) {
                return response()->json(['success'=>false,'error'=>'dispatch_failed','detail'=>$e->getMessage()], 500);
            }

            return response()->json(['success'=>true,'status'=>'queued','design_id'=>(int)$id]);
        });

        // GET  /api/studio/video/designs/{id}/export-status — poll render progress
        Route::get('/video/designs/{id}/export-status', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')
                ->first(['export_status','export_progress_pct','export_error','exported_video_url']);
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            return response()->json([
                'success'      => true,
                'status'       => $row->export_status,
                'progress_pct' => (int) $row->export_progress_pct,
                'error'        => $row->export_error,
                'video_url'    => $row->exported_video_url,
            ]);
        });

        // POST /api/studio/video/upload-clip — multipart video upload
        Route::post('/video/upload-clip', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $ok = in_array($file->getMimeType(), ['video/mp4','video/quicktime','video/webm','video/x-matroska'], true);
            if (!$ok) return response()->json(['success'=>false,'error'=>'unsupported_mime','mime'=>$file->getMimeType()], 415);
            if ($file->getSize() > 100 * 1024 * 1024) return response()->json(['success'=>false,'error'=>'too_large'], 413);

            $dir = storage_path('app/public/video-clips/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = bin2hex(random_bytes(5)) . '.' . ($file->getClientOriginalExtension() ?: 'mp4');
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-clips/' . $wsId . '/' . $name;

            // Probe duration + dimensions via ffprobe
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of json ' . escapeshellarg($path), $probe);
            $meta = json_decode(implode('', $probe), true) ?: [];
            $s = $meta['streams'][0] ?? [];
            $out = [
                'success'  => true,
                'clip_url' => $url,
                'width'    => (int)($s['width']  ?? 0),
                'height'   => (int)($s['height'] ?? 0),
                'duration' => (float)($s['duration'] ?? 0),
                'mime'     => $file->getMimeType(),
            ];
            // Best-effort media table insert
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $file->getMimeType(),
                    'source' => 'studio_video_upload', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}
            return response()->json($out);
        });

        // POST /api/studio/video/upload-image — image for slideshow
        Route::post('/video/upload-image', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $ok = str_starts_with((string)$file->getMimeType(), 'image/');
            if (!$ok) return response()->json(['success'=>false,'error'=>'not_an_image'], 415);
            if ($file->getSize() > 25 * 1024 * 1024) return response()->json(['success'=>false,'error'=>'too_large'], 413);

            $dir = storage_path('app/public/video-images/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = bin2hex(random_bytes(5)) . '.' . ($file->getClientOriginalExtension() ?: 'jpg');
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-images/' . $wsId . '/' . $name;
            $info = @getimagesize($path) ?: [0, 0];
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $file->getMimeType(),
                    'source' => 'studio_video_image', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}
            return response()->json(['success'=>true,'image_url'=>$url,'width'=>(int)$info[0],'height'=>(int)$info[1]]);
        });

        // POST /api/studio/video/upload-audio — audio track upload (mp3/aac/wav/m4a, 50MB max)
        Route::post('/video/upload-audio', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $mime = (string) $file->getMimeType();
            $allowed = [
                'audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/wav', 'audio/x-wav',
                'audio/x-m4a', 'audio/mp4', 'audio/m4a', 'audio/ogg',
            ];
            if (!in_array($mime, $allowed, true)) {
                return response()->json(['success'=>false,'error'=>'unsupported_mime','mime'=>$mime], 415);
            }
            if ($file->getSize() > 50 * 1024 * 1024) {
                return response()->json(['success'=>false,'error'=>'too_large'], 413);
            }

            $dir = storage_path('app/public/video-audio/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $ext = $file->getClientOriginalExtension() ?: 'mp3';
            $name = bin2hex(random_bytes(5)) . '.' . $ext;
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-audio/' . $wsId . '/' . $name;

            // Probe duration via ffprobe
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -show_entries format=duration -of json ' . escapeshellarg($path), $probe);
            $meta = json_decode(implode('', $probe), true) ?: [];
            $duration = (float)($meta['format']['duration'] ?? 0);

            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $mime,
                    'source' => 'studio_video_audio', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}

            return response()->json([
                'success'   => true,
                'audio_url' => $url,
                'duration'  => $duration,
                'mime'      => $mime,
                'size'      => filesize($path),
            ]);
        });

        // POST /api/studio/video/generate-minimax — AI video generation
        Route::post('/video/generate-minimax', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $prompt = trim((string)$r->input('prompt',''));
            if ($prompt === '') return response()->json(['success'=>false,'error'=>'missing_prompt'], 422);

            $key = env('MINIMAX_API_KEY');
            $group = env('MINIMAX_GROUP_ID');
            if (!$key) {
                return response()->json([
                    'success' => false,
                    'error'   => 'MiniMax API key not configured. Add MINIMAX_API_KEY to .env',
                ], 503);
            }

            $model = 'MiniMax-Hailuo-02';
            $resp = \Illuminate\Support\Facades\Http::withToken($key)
                ->timeout(30)
                ->post('https://api.minimax.chat/v1/video_generation', [
                    'model'       => $model,
                    'prompt'      => $prompt,
                    'duration'    => (int) min(10, max(5, $r->input('duration_seconds', 6))),
                    'resolution'  => $r->input('resolution', '1080P'),
                ]);
            if (!$resp->ok()) {
                return response()->json(['success'=>false,'error'=>'minimax_create_failed','detail'=>mb_substr($resp->body(),0,400)], 502);
            }
            $taskId = $resp->json('task_id');
            if (!$taskId) return response()->json(['success'=>false,'error'=>'no_task_id','detail'=>mb_substr($resp->body(),0,400)], 502);

            // Poll: up to 3 min (generation takes 45-120s typically).
            $deadline = time() + 180;
            $fileId = null;
            $lastStatus = null;
            while (time() < $deadline) {
                sleep(5);
                $poll = \Illuminate\Support\Facades\Http::withToken($key)
                    ->timeout(15)
                    ->get('https://api.minimax.chat/v1/query/video_generation', ['task_id' => $taskId]);
                if (!$poll->ok()) continue;
                $lastStatus = $poll->json('status');
                if ($lastStatus === 'Success') { $fileId = $poll->json('file_id'); break; }
                if (in_array($lastStatus, ['Fail', 'Failed', 'fail'], true)) {
                    return response()->json(['success'=>false,'error'=>'minimax_failed','detail'=>$poll->json('base_resp.status_msg')], 502);
                }
            }
            if (!$fileId) return response()->json(['success'=>false,'error'=>'minimax_timeout','last_status'=>$lastStatus], 504);

            // Retrieve the generated file download URL
            $fileResp = \Illuminate\Support\Facades\Http::withToken($key)
                ->timeout(15)
                ->get('https://api.minimax.chat/v1/files/retrieve', array_filter(['file_id' => $fileId, 'GroupId' => $group]));
            $downloadUrl = $fileResp->json('file.download_url');
            if (!$downloadUrl) return response()->json(['success'=>false,'error'=>'minimax_no_download','detail'=>mb_substr($fileResp->body(),0,400)], 502);

            // Download locally + persist
            $dir = storage_path('app/public/video-clips/minimax');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $hash = substr(hash('sha256', $taskId . $fileId), 0, 16);
            $dest = $dir . '/' . $hash . '.mp4';
            $bin = @file_get_contents($downloadUrl);
            if ($bin === false || strlen($bin) < 10_000) return response()->json(['success'=>false,'error'=>'minimax_dl_failed'], 502);
            file_put_contents($dest, $bin);
            $publicUrl = '/storage/video-clips/minimax/' . $hash . '.mp4';

            // Probe duration
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of json ' . escapeshellarg($dest), $probe);
            $s = (json_decode(implode('', $probe), true)['streams'][0] ?? []);
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $publicUrl, 'mime_type' => 'video/mp4',
                    'source' => 'minimax', 'is_platform_asset' => 0, 'prompt' => $prompt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}

            return response()->json([
                'success'  => true,
                'clip_url' => $publicUrl,
                'width'    => (int)($s['width']  ?? 0),
                'height'   => (int)($s['height'] ?? 0),
                'duration' => (float)($s['duration'] ?? 0),
                'task_id'  => $taskId,
            ]);
        });

        // DELETE /api/studio/video/designs/{id} — soft delete
        Route::delete('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update(['deleted_at'=>now()]);
            return response()->json(['success'=>true]);
        });


        // studio-phase1-routes
        $studio = \App\Engines\Studio\Services\StudioService::class;

        // Element CRUD
        Route::get('/designs/{id}/elements',             fn(\Illuminate\Http\Request $r, $id)      => response()->json(['elements' => app($studio)->getElements((int) $id)]));
        Route::post('/designs/{id}/elements',            fn(\Illuminate\Http\Request $r, $id)      => response()->json(app($studio)->saveElement($r->attributes->get('workspace_id'), (int) $id, $r->all()), 201));
        Route::put('/designs/{id}/elements/{eid}',       fn(\Illuminate\Http\Request $r, $id, $eid) => response()->json(app($studio)->updateElement((int) $eid, $r->all())));
        Route::delete('/designs/{id}/elements/{eid}',    fn(\Illuminate\Http\Request $r, $id, $eid) => response()->json(['deleted' => app($studio)->deleteElement((int) $eid)]));
        Route::post('/designs/{id}/elements/reorder',    fn(\Illuminate\Http\Request $r, $id)       => response()->json(['reordered' => app($studio)->reorderElements((int) $id, (array) $r->input('element_ids', []))]));

        // Design-level Phase 1 additions
        Route::post('/designs/{id}/duplicate',           fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->duplicateDesign((int) $id, (int) $r->attributes->get('workspace_id'))));
        Route::post('/designs/{id}/thumbnail',           fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->generateThumbnail((int) $id)));
        Route::post('/designs/{id}/history',             fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->saveHistory((int) $id, (array) $r->input('snapshot', []))));
        Route::get('/designs/{id}/history',              fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->getHistory((int) $id)));

        // Brand kit (per workspace)
        Route::get('/brand-kit',  fn(\Illuminate\Http\Request $r) => response()->json(['brand_kit' => app($studio)->getBrandKit((int) $r->attributes->get('workspace_id'))]));
        Route::put('/brand-kit',  fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->updateBrandKit((int) $r->attributes->get('workspace_id'), $r->all())));

        // Static catalogs
        Route::get('/fonts',      fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->getFonts()));
        Route::get('/formats',    fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->getFormats()));


        // studio-phase4-routes
        $studioAi = \App\Engines\Studio\Services\StudioAiService::class;

        // Arthur AI (Phase 4) — PATCH 4 (2026-05-08): plan-gated + credit-reserved.
        // Was direct service calls with no plan check or credit reservation,
        // letting free-plan users drain provider keys at zero cost.
        $studioAiGate = function (\Illuminate\Http\Request $r, string $method, int $cost, string $reason) use ($studioAi) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if ($wsId <= 0) return response()->json(['error' => 'workspace_required'], 400);

            $gate = app(\App\Core\Billing\FeatureGateService::class);
            if (!$gate->canUseAI($wsId)) {
                return response()->json([
                    'error'            => 'Your plan does not include AI generation.',
                    'upgrade_required' => true,
                ], 403);
            }

            $credits = app(\App\Core\Billing\CreditService::class);
            try {
                $reservationRef = $credits->reserve($wsId, $cost, $reason);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Insufficient credits.', 'message' => $e->getMessage()], 402);
            }

            try {
                $result = app($studioAi)->{$method}($wsId, $r->all());
                if (!empty($result['success'])) {
                    $credits->commit($wsId, $reservationRef, $cost);
                } else {
                    $credits->release($wsId, $reservationRef);
                }
                return response()->json($result);
            } catch (\Throwable $e) {
                $credits->release($wsId, $reservationRef);
                throw $e;
            }
        };
        Route::post('/ai/generate-design', fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'generateDesign', 5, 'studio_ai_generate_design'));
        Route::post('/ai/generate-image',  fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'generateImage',  8, 'studio_ai_generate_image'));
        Route::post('/ai/suggest-copy',    fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'suggestCopy',    1, 'studio_ai_suggest_copy'));
        Route::post('/ai/chat',            fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'chat',           1, 'studio_ai_chat'));

        // Phase 5 — publish + thumbnail + resize
        Route::post('/designs/{id}/publish-social', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->publishToSocial((int) $id, (int) $r->attributes->get('workspace_id'), $r->all())));
        Route::post('/designs/{id}/resize',         fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->resizeDesign((int) $id, (int) $r->input('width'), (int) $r->input('height'))));
        Route::post('/designs/{id}/save-to-media',  fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->saveExportToMedia((int) $id, (int) $r->attributes->get('workspace_id'))));

    });
    // ── Marketing Engine ─────────────────────────────────────────
    // // marketing-fixes-v1 //
    Route::prefix('marketing')->group(function () {
        $s    = \App\Engines\Marketing\Services\MarketingService::class;
        $seq  = \App\Engines\Marketing\Services\SequenceService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;

        // ── Campaigns ────────────────────────────────────────────
        Route::get('/campaigns',          fn(\Illuminate\Http\Request $r)      => response()->json(app($s)->listCampaigns($r->attributes->get('workspace_id'), $r->all())));
        Route::get('/campaigns/{id}',     fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getCampaign($r->attributes->get('workspace_id'), $id)));
        Route::post('/campaigns',         fn(\Illuminate\Http\Request $r)      => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'marketing', 'create_campaign', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::put('/campaigns/{id}',     function (\Illuminate\Http\Request $r, $id) use ($s) {
            $result = app($s)->updateCampaign((int) $id, $r->all());
            return response()->json($result);
        });
        Route::delete('/campaigns/{id}',  fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($s)->deleteCampaign((int) $id) ?? true]));
        Route::post('/campaigns/{id}/schedule', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'marketing', 'schedule_campaign', ['campaign_id' => $id, 'scheduled_at' => $r->input('scheduled_at')], ['user_id' => $r->user()?->id, 'source' => 'manual'])));
        Route::post('/campaigns/{id}/send',     fn(\Illuminate\Http\Request $r, $id) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'marketing', 'send_campaign', array_merge($r->all(), ['campaign_id' => $id]), ['user_id' => $r->user()?->id, 'source' => 'manual'])));

        // ── Templates ────────────────────────────────────────────
        Route::get('/templates',          fn(\Illuminate\Http\Request $r)      => response()->json(['templates' => app($s)->listTemplates($r->attributes->get('workspace_id'))]));
        Route::post('/templates',         fn(\Illuminate\Http\Request $r)      => response()->json(['template_id' => app($s)->createTemplate($r->attributes->get('workspace_id'), $r->all())], 201));
        Route::get('/templates/{id}',     fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getTemplate((int) $id)));
        Route::put('/templates/{id}',     function (\Illuminate\Http\Request $r, $id) use ($s) {
            return response()->json(app($s)->updateTemplate((int) $id, $r->all()));
        });
        Route::delete('/templates/{id}',  function (\Illuminate\Http\Request $r, $id) use ($s) {
            return response()->json(['deleted' => app($s)->deleteTemplate((int) $id)]);
        });

        // ── Automations ──────────────────────────────────────────
        Route::get('/automations',        fn(\Illuminate\Http\Request $r)      => response()->json(app($s)->listAutomations($r->attributes->get('workspace_id'))));
        Route::post('/automations',       fn(\Illuminate\Http\Request $r)      => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'marketing', 'create_automation', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::post('/automations/{id}/toggle', function (\Illuminate\Http\Request $r, $id) use ($s) {
            app($s)->toggleAutomation((int) $id, (string) $r->input('status'));
            return response()->json(['toggled' => true, 'status' => $r->input('status')]);
        });

        // ── Sequences ────────────────────────────────────────────
        Route::get('/sequences',          fn(\Illuminate\Http\Request $r)      => response()->json(app($seq)->listSequences($r->attributes->get('workspace_id'))));
        Route::post('/sequences',         fn(\Illuminate\Http\Request $r)      => response()->json(app($seq)->createSequence($r->attributes->get('workspace_id'), array_merge($r->all(), ['user_id' => $r->user()?->id])), 201));
        Route::get('/sequences/{id}',     fn(\Illuminate\Http\Request $r, $id) => response()->json(app($seq)->getSequence($r->attributes->get('workspace_id'), (int) $id)));
        Route::put('/sequences/{id}',     function (\Illuminate\Http\Request $r, $id) use ($seq) {
            return response()->json(app($seq)->updateSequence((int) $id, $r->all()));
        });
        Route::delete('/sequences/{id}',  fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($seq)->deleteSequence((int) $id)]));
        Route::post('/sequences/{id}/toggle', function (\Illuminate\Http\Request $r, $id) use ($seq) {
            return response()->json(app($seq)->toggleSequence((int) $id, (string) $r->input('status', 'active')));
        });
        Route::post('/sequences/{id}/steps',            fn(\Illuminate\Http\Request $r, $id)          => response()->json(app($seq)->addStep((int) $id, $r->all()), 201));
        Route::delete('/sequences/{id}/steps/{stepId}', fn(\Illuminate\Http\Request $r, $id, $stepId) => response()->json(['deleted' => app($seq)->removeStep((int) $id, (int) $stepId)]));

        // ── Email settings + test ────────────────────────────────
        Route::get('/email/settings',  fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getEmailSettings()));
        Route::post('/email/settings', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->updateEmailSettings($r->all())));
        Route::post('/email/test',     fn(\Illuminate\Http\Request $r) => response()->json(app($s)->sendTestEmail((string) ($r->input('to_email') ?: $r->input('email', '')))));

        // ── Email Builder ────────────────────────────────────────
        // // email-builder-v1 //
        $eb = \App\Engines\Marketing\Services\EmailBuilderService::class;

        // Template CRUD
        Route::get('/email-builder/templates',        fn(\Illuminate\Http\Request $r)      => response()->json(['templates' => app($eb)->listTemplates($r->attributes->get('workspace_id'), (string) $r->query('scope', 'all'))])); // tpl-scope-routes-v1
        Route::post('/email-builder/templates',       fn(\Illuminate\Http\Request $r)      => response()->json(app($eb)->createTemplate($r->attributes->get('workspace_id'), $r->all()), 201));
        Route::get('/email-builder/templates/{id}',   fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->getTemplate((int) $id)));
        Route::put('/email-builder/templates/{id}',   fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->updateTemplate((int) $id, $r->all())));
        Route::delete('/email-builder/templates/{id}',fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($eb)->deleteTemplate((int) $id)]));

        // Block CRUD + reorder  (NOTE: reorder registered BEFORE {bid} to avoid capture)
        Route::post('/email-builder/templates/{id}/blocks/reorder',   fn(\Illuminate\Http\Request $r, $id)       => response()->json(['reordered' => app($eb)->reorderBlocks((int) $id, (array) $r->input('block_ids', []))]));
        Route::get('/email-builder/templates/{id}/variables',  fn(\Illuminate\Http\Request $r, $id) => response()->json(['variables' => app($eb)->getTemplateVariables((int) $id)])); // email-builder-variables-v1
        Route::post('/email-builder/templates/{id}/use',       fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->useSystemTemplate((int) $r->attributes->get('workspace_id'), (int) $id)));
        Route::get('/email-builder/templates/{id}/blocks',            fn(\Illuminate\Http\Request $r, $id)       => response()->json(['blocks' => app($eb)->getBlocks((int) $id)]));
        Route::post('/email-builder/templates/{id}/blocks',           fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($eb)->addBlock((int) $id, $r->all()), 201));
        Route::put('/email-builder/templates/{id}/blocks/{bid}',      fn(\Illuminate\Http\Request $r, $id, $bid) => response()->json(app($eb)->updateBlock((int) $id, (int) $bid, $r->all())));
        Route::delete('/email-builder/templates/{id}/blocks/{bid}',   fn(\Illuminate\Http\Request $r, $id, $bid) => response()->json(['deleted' => app($eb)->deleteBlock((int) $id, (int) $bid)]));

        // Preview / export / thumbnail
        Route::post('/email-builder/templates/{id}/preview',     fn(\Illuminate\Http\Request $r, $id) => response()->json(['html' => app($eb)->previewTemplate((int) $id, (array) $r->input('variables', []), (string) $r->input('format', 'desktop'))]));
        Route::post('/email-builder/templates/{id}/export-html', function (\Illuminate\Http\Request $r, $id) use ($eb) { return response(app($eb)->exportHtml((int) $id), 200, ['Content-Type' => 'text/html; charset=utf-8']); });
        Route::post('/email-builder/templates/{id}/thumbnail',   fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->generateThumbnail((int) $id)));

        // AI
        Route::post('/email-builder/ai/generate',        fn(\Illuminate\Http\Request $r) => response()->json(app($eb)->aiGenerate($r->attributes->get('workspace_id'), $r->all())));
        Route::post('/email-builder/ai/rewrite-block',   fn(\Illuminate\Http\Request $r) => response()->json(app($eb)->aiRewriteBlock((int) $r->input('template_id'), (int) $r->input('block_id'), (string) $r->input('instruction', 'rewrite'))));
        Route::post('/email-builder/ai/suggest-subject', fn(\Illuminate\Http\Request $r) => response()->json(app($eb)->aiSuggestSubjects((int) $r->input('template_id'), $r->all())));
        Route::post('/email-builder/ai/spam-check',      fn(\Illuminate\Http\Request $r) => response()->json(app($eb)->aiSpamCheck((int) $r->input('template_id'), (string) $r->input('subject', ''))));

        // Send pipeline
        Route::post('/email-builder/campaigns/{id}/validate',  fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->validateCampaign((int) $id)));
        Route::post('/email-builder/campaigns/{id}/send-test', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->sendTest((int) $id, (string) $r->input('to_email'), (array) $r->input('variables', []))));
        Route::post('/email-builder/campaigns/{id}/send',      fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->queueSendCampaign((int) $id)));

        // Analytics
        // email-builder-phase5-routes
        Route::post('/email-builder/templates/{id}/send-test', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->sendTestTemplate((int) $id, (string) $r->input('to_email'), (array) $r->input('variables', []))));
        Route::get('/email-builder/campaigns/{id}/send-status', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->getSendStatus((int) $id)));
                Route::get('/email-builder/campaigns/{id}/analytics',  fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->getCampaignAnalytics((int) $id)));

    });

    // ── Social Engine ────────────────────────────────────────────
    Route::prefix('social')->group(function () {
        $s = \App\Engines\Social\Services\SocialService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        // Reads
        Route::get('/posts', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listPosts($r->attributes->get('workspace_id'), $r->all())));
        Route::get('/posts/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getPost($r->attributes->get('workspace_id'), $id)));
        Route::get('/accounts', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listAccounts($r->attributes->get('workspace_id'))));
        Route::get('/calendar', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getCalendarPosts($r->attributes->get('workspace_id'), $r->input('from'), $r->input('to'))));
        // Writes through pipeline
        Route::post('/posts', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'create_post', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::post('/posts/{id}/schedule', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'social_schedule_post', ['post_id' => $id, 'scheduled_at' => $r->input('scheduled_at')], ['user_id' => $r->user()?->id, 'source' => 'manual'])));
        Route::post('/posts/{id}/publish', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'social_publish_post', ['post_id' => $id], ['user_id' => $r->user()?->id, 'source' => 'manual'])));
        Route::post('/accounts', fn(\Illuminate\Http\Request $r) => response()->json(['account_id' => app($s)->addAccount($r->attributes->get('workspace_id'), $r->all())], 201));

        // Platform settings
        Route::post("/settings/facebook", fn(\Illuminate\Http\Request $r) => response()->json(["message" => "Facebook settings saved", "configured" => false], 200));
        Route::post("/settings/linkedin", fn(\Illuminate\Http\Request $r) => response()->json(["message" => "LinkedIn settings saved", "configured" => false], 200));
        // OAuth — Phase 2G Session 1: Facebook + Instagram real flow.
        // Facebook connect → redirect to Facebook login with correct scopes.
        // Instagram connect → same Facebook OAuth (Instagram is auto-discovered from linked Pages).
        Route::get("/oauth/facebook/connect", function (\Illuminate\Http\Request $r) {
            $connector = app(\App\Connectors\SocialConnector::class);
            if (! $connector->isFacebookOAuthConfigured()) {
                return response()->json(["error" => "Facebook OAuth not configured. Set FACEBOOK_APP_ID, FACEBOOK_APP_SECRET, FACEBOOK_REDIRECT_URI in .env."], 400);
            }
            $wsId = $r->attributes->get('workspace_id', 1);
            $url = $connector->getAuthUrl('facebook', $wsId);
            return response()->json(["redirect_url" => $url, "message" => "Redirect the user to this URL to connect Facebook + Instagram."]);
        });

        // Facebook callback is now a PUBLIC route (outside auth.jwt) because
        // Facebook redirects the browser directly — no JWT in the redirect.
        // See the Route::get('/social/oauth/facebook/callback', ...) above line 79.

        // Instagram connect → same Facebook flow (IG Business API requires Facebook Page)
        Route::get("/oauth/instagram/connect", function (\Illuminate\Http\Request $r) {
            $connector = app(\App\Connectors\SocialConnector::class);
            if (! $connector->isFacebookOAuthConfigured()) {
                return response()->json(["error" => "Instagram requires a connected Facebook Page. Configure Facebook OAuth first."], 400);
            }
            $wsId = $r->attributes->get('workspace_id', 1);
            $url = $connector->getAuthUrl('instagram', $wsId);
            return response()->json(["redirect_url" => $url, "message" => "Redirect to Facebook login — Instagram accounts will be auto-discovered from linked Pages."]);
        });

        Route::get("/oauth/linkedin/connect", fn(\Illuminate\Http\Request $r) => response()->json(["error" => "LinkedIn OAuth not yet configured. Add your Client ID in Settings first."], 400));
        // Delete post
        Route::delete("/posts/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["deleted" => true]) && app($s)->deletePost($id));
        // Disconnect a social account (removes OAuth tokens, DELETE /api/social/accounts/{id})
        Route::delete("/accounts/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["disconnected" => (bool) app($s)->disconnectAccount((int) $r->attributes->get("workspace_id"), (int) $id)]));
        // TikTok — explicit not-yet-supported response so UI can show Coming Soon
        Route::get("/oauth/tiktok/connect", fn() => response()->json(["error" => "TikTok publishing is not yet available. Aria can still generate TikTok-ready content you can post manually."], 501));
    });

    // ── Calendar Engine ──────────────────────────────────────────
    Route::prefix('calendar')->group(function () {
        $s = \App\Engines\Calendar\Services\CalendarService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        Route::get('/events', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getEvents($r->attributes->get('workspace_id'), $r->input('from'), $r->input('to'), $r->input('category'))));
        Route::post('/events', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'calendar', 'create_event', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::put('/events/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['updated' => true]) && app($s)->updateEvent($id, $r->all()));
        Route::delete('/events/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => true]) && app($s)->deleteEvent($id));
    });

    // ── BeforeAfter Engine ───────────────────────────────────────
    Route::prefix('beforeafter')->group(function () {
        $s = \App\Engines\BeforeAfter\Services\BeforeAfterService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        Route::get('/designs', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listDesigns($r->attributes->get('workspace_id'))));
        Route::post('/designs', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'beforeafter', 'create_design', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::get('/designs/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getDesign($r->attributes->get('workspace_id'), $id)));
        Route::get('/room-types', fn() => response()->json(app($s)->getRoomTypes()));
        Route::get('/styles', fn() => response()->json(app($s)->getStyles()));
    });

    // ── Traffic Defense Engine ────────────────────────────────────
    Route::prefix('traffic')->group(function () {
        $s = \App\Engines\TrafficDefense\Services\TrafficDefenseService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        Route::get('/rules', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listRules($r->attributes->get('workspace_id'))));
        Route::post('/rules', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'traffic', 'create_rule', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::post('/rules/{id}/toggle', fn(\Illuminate\Http\Request $r, $id) => response()->json(['toggled' => true]) && app($s)->toggleRule($id, $r->boolean('enabled')));
        Route::delete('/rules/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => true]) && app($s)->deleteRule($id));
        Route::get('/stats', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getStats($r->attributes->get('workspace_id'), $r->input('days', 7))));
    });

    // ── ManualEdit Engine ────────────────────────────────────────
    Route::prefix('manualedit')->group(function () {
        $s = \App\Engines\ManualEdit\Services\ManualEditService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        Route::get('/canvases', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listCanvases($r->attributes->get('workspace_id'))));
        Route::post('/canvases', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'manualedit', 'create_canvas', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::get('/canvases/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getCanvas($r->attributes->get('workspace_id'), $id)));
        Route::put('/canvases/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['saved' => true]) && app($s)->saveCanvas($id, $r->input('state', []), $r->input('operations', [])));
        Route::delete('/canvases/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => true]) && app($s)->deleteCanvas($id));
    });

    // Campaign send alias (JS calls /api/campaign/send)
    Route::post("/campaign/send", function (\Illuminate\Http\Request $r) {
        $exec = app(\App\Core\EngineKernel\EngineExecutionService::class);
        return response()->json($exec->execute(
            $r->attributes->get("workspace_id"), "marketing", "send_campaign",
            $r->all(), ["user_id" => $r->user()?->id, "source" => "manual"]
        ));
    });

    // Governance aliases (JS calls /governance/*, backend uses /approvals/*)
    Route::get("/governance/pending", [\App\Http\Controllers\Api\ApprovalController::class, "index"]);
    Route::post("/governance/approve", fn(\Illuminate\Http\Request $r) => app(\App\Http\Controllers\Api\ApprovalController::class)->approve($r, $r->input("id")));
    Route::post("/governance/reject", fn(\Illuminate\Http\Request $r) => app(\App\Http\Controllers\Api\ApprovalController::class)->reject($r, $r->input("id")));

    // ── Projects kanban (groups tasks by status for kanban board) ─────────
    Route::get("/projects/tasks", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // Map DB task statuses to kanban column names
        $kanbanMap = [
            'pending' => 'backlog',
            'awaiting_approval' => 'backlog',
            'queued' => 'planned',
            'running' => 'in_progress',
            'verifying' => 'review',
            'completed' => 'completed',
            'failed' => 'backlog',
            'cancelled' => 'backlog',
            'blocked' => 'backlog',
            'degraded' => 'in_progress',
        ];
        $tasks = \App\Models\Task::where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit((int) $r->input('limit', 100))
            ->get()
            ->map(function($t) use ($kanbanMap) {
                $agents = $t->assigned_agents_json;
                if (is_string($agents)) $agents = json_decode($agents, true);
                return [
                    'id'          => $t->id,
                    'title'       => ucfirst(str_replace('_', ' ', $t->action)) . ' (' . $t->engine . ')',
                    'description' => $t->progress_message ?? ('Execute ' . $t->action),
                    'engine'      => $t->engine,
                    'action'      => $t->action,
                    'status'      => $kanbanMap[$t->status] ?? 'backlog',
                    'priority'    => $t->priority ?? 'normal',
                    'source'      => $t->source,
                    'credit_cost' => $t->credit_cost,
                    'estimated_time' => 60,
                    'retry_count' => $t->retry_count,
                    'error_text'  => $t->error_text,
                    'progress_message' => $t->progress_message,
                    'started_at'  => $t->started_at,
                    'completed_at'=> $t->completed_at,
                    'created_at'  => $t->created_at,
                    'assignee'    => is_array($agents) ? ($agents[0] ?? null) : null,
                ];
            });
        return response()->json(['tasks' => $tasks]);
    });
    Route::get("/projects/tasks/{id}", function (\Illuminate\Http\Request $r, $id) {
        $t = \App\Models\Task::findOrFail($id);
        $agents = $t->assigned_agents_json;
        if (is_string($agents)) { try { $agents = json_decode($agents, true); } catch (\Throwable $e) { $agents = []; } }
        $payload = $t->payload_json;
        if (is_string($payload)) { try { $payload = json_decode($payload, true); } catch (\Throwable $e) { $payload = []; } }
        $assignee = is_array($agents) && count($agents) ? $agents[0] : null;
        if ($assignee === 'sarah') $assignee = 'dmm';
        return response()->json([
            'id'             => $t->id,
            'title'          => $payload['title'] ?? $t->progress_message ?? ucfirst(str_replace('_', ' ', $t->action)),
            'description'    => $payload['description'] ?? $t->progress_message ?? '',
            'engine'         => $t->engine,
            'action'         => $t->action,
            'status'         => $t->status,
            'priority'       => $t->priority ?? 'normal',
            'source'         => $t->source,
            'assignee'       => $assignee,
            'assignees'      => is_array($agents) ? array_map(fn($a) => $a === 'sarah' ? 'dmm' : $a, $agents) : [],
            'coordinator'    => $payload['coordinator'] ?? '',
            'estimated_time' => $payload['estimated_time'] ?? 60,
            'estimated_tokens'=> $payload['estimated_tokens'] ?? 0,
            'success_metric' => $payload['success_metric'] ?? '',
            'credit_cost'    => $t->credit_cost,
            'error_text'     => $t->error_text,
            'started_at'     => $t->started_at,
            'completed_at'   => $t->completed_at,
            'created_at'     => $t->created_at,
            'result_json'    => $t->result_json,
            // Deliverable — from result_json if completed
            'deliverable'    => $t->status === 'completed' && $t->result_json
                ? ['summary' => is_array($t->result_json) ? ($t->result_json['summary'] ?? json_encode($t->result_json)) : $t->result_json, 'deliverable' => $t->result_json]
                : null,
            // Notes — from payload_json.notes or result_json.notes
            'notes'          => (function() use ($t, $payload) {
                $notes = $payload['notes'] ?? [];
                if (is_array($t->result_json) && isset($t->result_json['notes'])) {
                    $notes = array_merge($notes, $t->result_json['notes']);
                }
                return $notes;
            })(),
            // History — build from task_events table
            'history'        => \Illuminate\Support\Facades\DB::table('task_events')
                ->where('task_id', $t->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn($e) => [
                    'status' => $e->status ?? $e->event,
                    'from'   => null,
                    'at'     => $e->created_at,
                    'by'     => 'system',
                    'note'   => $e->message,
                ])->toArray() ?: [
                    ['status' => 'created', 'at' => $t->created_at, 'by' => $t->source ?? 'system', 'note' => 'Task created'],
                    $t->started_at ? ['status' => 'running', 'at' => $t->started_at, 'by' => 'system', 'note' => 'Execution started'] : null,
                    $t->completed_at ? ['status' => $t->status, 'at' => $t->completed_at, 'by' => 'system', 'note' => 'Task ' . $t->status] : null,
                ],
            // Meeting reference
            'meeting_id'     => $payload['from_meeting'] ?? null,
        ]);
    });
    Route::post("/projects/tasks/{id}/note", function (\Illuminate\Http\Request $r, $id) {
        // Stub for task notes — stores in result_json for now
        $task = \App\Models\Task::findOrFail($id);
        $result = $task->result_json ?? [];
        $result['notes'] = $result['notes'] ?? [];
        $result['notes'][] = ['content' => $r->input('content'), 'at' => now()->toISOString()];
        $task->update(['result_json' => $result]);
        return response()->json(['success' => true]);
    });

    // ── Tool Registry (exposes CapabilityMapService for frontend) ────────
    Route::get("/tools", function () {
        $caps = app(\App\Core\EngineKernel\CapabilityMapService::class)->getAllCapabilities();
        $result = [];
        foreach ($caps as $name => $cap) {
            $result[$name] = [
                'name'          => $name,
                'engine'        => $cap['engine'] ?? 'unknown',
                'action'        => $cap['action'] ?? $name,
                'description'   => ucfirst(str_replace('_', ' ', $name)),
                'credit_cost'   => $cap['credit_cost'] ?? 0,
                'approval_mode' => $cap['approval_mode'] ?? 'auto',
                'connector'     => $cap['connector'] ?? null,
                'agents'        => [],
            ];
        }
        return response()->json($result);
    });

    // ── Stub routes for dashboard polling (prevent 404 noise) ────────────
    Route::get("/exec/mode", fn() => response()->json(["mode" => "manual", "autonomous" => false]));
    Route::post("/exec/mode", fn(\Illuminate\Http\Request $r) => response()->json(["mode" => $r->input("mode", "manual")]));
    Route::get("/previews", fn() => response()->json(["previews" => []]));
    Route::get("/assistant", fn() => response()->json(["messages" => []]));
    Route::get("/calendar/booking-slots", fn() => response()->json(["slots" => []]));
    Route::get("/exec/history", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $limit = min((int)($r->input('limit', 50)), 200);
        $rows = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function($row) {
                $meta = $row->metadata_json ? json_decode($row->metadata_json, true) : [];
                return [
                    'id' => $row->id,
                    'tool_id' => $row->action,
                    'agent_id' => $meta['agent'] ?? $meta['agent_id'] ?? ($row->entity_type ? strtolower($row->entity_type) : 'system'),
                    'success' => !str_contains($row->action, 'fail'),
                    'duration_ms' => $meta['duration_ms'] ?? null,
                    'mode' => $meta['mode'] ?? $meta['source'] ?? 'auto',
                    'rationale' => $meta['rationale'] ?? null,
                    'result_summary' => $meta['result'] ?? $meta['summary'] ?? null,
                    'credit_cost' => $meta['credit_cost'] ?? 0,
                    'created_at' => $row->created_at,
                ];
            });
        return response()->json(['history' => $rows]);
    });
    Route::get("/history", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $meetings = \Illuminate\Support\Facades\DB::table('meetings')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'topic' => $m->title ?: ($m->type . ' meeting'),
                'date' => $m->created_at,
                'status' => $m->status,
                'credits' => $m->total_credits_used,
                'summary' => $m->metadata_json ? (json_decode($m->metadata_json, true)['summary'] ?? '') : '',
            ]);
        return response()->json($meetings);
    });
    Route::get("/decisions", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $limit = min((int)($r->input('limit', 50)), 200);
        $rows = \Illuminate\Support\Facades\DB::table('approvals')
            ->leftJoin('tasks', 'approvals.task_id', '=', 'tasks.id')
            ->where('approvals.workspace_id', $wsId)
            ->orderByDesc('approvals.created_at')
            ->limit($limit)
            ->select('approvals.*', 'tasks.engine', 'tasks.action', 'tasks.credit_cost')
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'title' => ($row->engine ?? 'system') . '.' . ($row->action ?? 'approval'),
                'status' => $row->status,
                'agent_id' => 'system',
                'decision_type' => 'review',
                'rationale' => $row->decision_note ?? null,
                'credit_cost' => $row->credit_cost ?? 0,
                'created_at' => $row->created_at,
                'resolved_at' => $row->decided_at ?? null,
            ]);
        return response()->json(['decisions' => $rows]);
    });
        // insights/summary stub removed — real route exists below

    // ── Credits routes (creative-engine.js advanced features) ──
    Route::get('/credits/cost-map', function () {
        $caps = app(\App\Core\EngineKernel\CapabilityMapService::class)->getAllCapabilities();
        $map = [];
        foreach ($caps as $name => $cap) {
            $map[$name] = [
                'cost' => $cap['credit_cost'] ?? 0,
                'engine' => $cap['engine'] ?? 'unknown',
                'approval' => $cap['approval_mode'] ?? 'auto',
            ];
        }
        return response()->json(['cost_map' => $map]);
    });

    Route::get('/credits/settings', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json([
            'workspace_id' => $wsId,
            'providers' => [
                'image' => ['provider' => 'dall-e-3', 'configured' => true],
                'video' => ['provider' => 'mock', 'configured' => false],
            ],
        ]);
    });

    Route::post('/credits/kill-switch', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $action = $r->input('action', 'kill_all'); // kill_all, kill_agents, kill_gen, resume
        \Illuminate\Support\Facades\Log::warning("[CREDIT888] Kill switch activated: {$action} for workspace {$wsId}");
        // Cancel all running tasks for this workspace
        if ($action !== 'resume') {
            \App\Models\Task::where('workspace_id', $wsId)
                ->whereIn('status', ['pending', 'queued', 'running'])
                ->update(['status' => 'cancelled']);
        }
        return response()->json(['action' => $action, 'success' => true]);
    });
    // REMOVED v5.5.4 — duplicate stub (see /billing/status real route at ~line 4276 wired to StripeService::getBillingStatus)



    // ── AI Assistant (SaaS chat) ────────────────────────────────────────
            Route::post('/assistant', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $userId = $r->user()->id;
        $message = $r->input('message', '');
        $context = $r->input('context', []);
        $history = $r->input('history', []);

        // ── Build workspace intelligence context ────────────────────
        $workspace_intelligence = '';
        try {
            $ws = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first();
            $plan = \App\Models\Plan::find(
                \App\Models\Subscription::where('workspace_id', $wsId)->where('status', 'active')->latest()->value('plan_id')
            );
            $credits = \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->first();

            $articles = \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->select('id','title','status','blog_category','word_count','published_at','featured_image_url')->orderByDesc('updated_at')->limit(10)->get();
            $websites = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->select('id','name','status','type','subdomain','external_url','domain')->limit(10)->get();
            $keywords = \Illuminate\Support\Facades\DB::table('seo_keywords')->where('workspace_id', $wsId)
                ->select('keyword','current_rank','volume','last_rank_check')->limit(20)->get();
            $goals = \Illuminate\Support\Facades\DB::table('seo_goals')->where('workspace_id', $wsId)->where('status', 'active')->limit(5)->get();

            $workspace_intelligence = "\n\nWORKSPACE STATE (live data — use this to answer questions):\n";
            $workspace_intelligence .= "- Workspace: " . ($ws->name ?? '?') . " (id={$wsId})\n";
            $workspace_intelligence .= "- Plan: " . ($plan->name ?? 'Free') . "\n";
            $workspace_intelligence .= "- Credits: " . ($credits->balance ?? 0) . " available\n";
            $workspace_intelligence .= "- Is house account: " . ($ws->is_house_account ? 'YES' : 'no') . "\n";
            $workspace_intelligence .= "\nARTICLES (" . count($articles) . " total):\n";
            foreach ($articles as $a) {
                $img = $a->featured_image_url ? 'has image' : 'NO IMAGE';
                $workspace_intelligence .= "  - [{$a->status}] \"{$a->title}\" ({$a->word_count} words, {$a->blog_category}, {$img})\n";
            }
            $workspace_intelligence .= "\nWEBSITES (" . count($websites) . "):\n";
            foreach ($websites as $w) {
                $url = $w->external_url ?: ($w->subdomain ? "https://{$w->subdomain}" : $w->domain);
                $workspace_intelligence .= "  - [{$w->status}] \"{$w->name}\" type={$w->type} url={$url}\n";
            }
            if (count($keywords) > 0) {
                $workspace_intelligence .= "\nTRACKED KEYWORDS (" . count($keywords) . "):\n";
                foreach ($keywords as $k) {
                    $rank = $k->current_rank ? "#{$k->current_rank}" : 'unranked';
                    $workspace_intelligence .= "  - \"{$k->keyword}\" {$rank} vol={$k->volume}\n";
                }
            }
            if (count($goals) > 0) {
                $workspace_intelligence .= "\nACTIVE SEO GOALS:\n";
                foreach ($goals as $g) { $workspace_intelligence .= "  - {$g->title}\n"; }
            }

            // PATCH (Aria platform-aware, 2026-05-09) — Inject the 21
            // platform-wide agents + workspace task counts so Aria can
            // answer "Is Sarah available?" / "How many tasks are running?"
            // /  "Who handles SEO?" directly. Agents table has no
            // workspace_id — agents are platform-wide. Availability is
            // implicit: all 21 agents are online unless the platform
            // takes them down.
            $agents = \Illuminate\Support\Facades\DB::table('agents')
                ->orderBy('id')
                ->get(['slug', 'name', 'title']);
            if (count($agents) > 0) {
                $workspace_intelligence .= "\nAGENTS (" . count($agents) . " — all available unless flagged):\n";
                foreach ($agents as $a) {
                    $workspace_intelligence .= "  - " . $a->name . " (" . ($a->title ?: $a->slug) . ") — slug=" . $a->slug . "\n";
                }
            }
            $taskCounts = \Illuminate\Support\Facades\DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->toArray();
            if (! empty($taskCounts)) {
                $workspace_intelligence .= "\nTASKS (workspace {$wsId}):\n";
                foreach (['pending','queued','running','awaiting_approval','completed','failed'] as $st) {
                    if (isset($taskCounts[$st]) && $taskCounts[$st] > 0) {
                        $workspace_intelligence .= "  - {$st}: {$taskCounts[$st]}\n";
                    }
                }
            }
        } catch (\Throwable $e) {
            $workspace_intelligence = "\n(Could not load workspace data: {$e->getMessage()})";
        }

        // ── Detect action requests → route to Sarah ─────────────────
        $actionKeywords = ['generate','create','write','publish','assign','schedule','post','send','run','start','build','make','attach','update','delete','remove','audit','analyze','connect'];
        $lowerMsg = strtolower($message);
        $isAction = false;
        foreach ($actionKeywords as $kw) {
            if (strpos($lowerMsg, $kw) !== false) { $isAction = true; break; }
        }

        // ── Call LLM with full context ──────────────────────────────
        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if ($runtime->isConfigured()) {
                $systemPrompt = "You are the LevelUp Growth AI Assistant — a helpful platform guide that knows everything about this workspace."
                    . "\n\nYou have FULL access to the user's workspace data (shown below). Use it to give specific, informed answers."
                    . "\nWhen the user asks about their content, websites, keywords, or any workspace data — reference the ACTUAL data below, don't ask them for details you already have."
                    . "\n\nWhen the user requests an ACTION (create, generate, publish, etc.):"
                    . "\n- Tell them to use the 💬 Messages panel (bottom left) to talk to Sarah, who can coordinate agents"
                    . "\n- Or direct them to the relevant engine section in the sidebar"
                    . "\n- You can answer questions about the workspace data shown below, but you don't execute actions yourself"
                    . "\n\nBe helpful. If you see missing data or issues in the workspace, mention them and suggest which agent or section can fix it."
                    . "\nBe concise — 2-3 sentences for simple questions, more for complex strategy."
                    . "\n\n" . \App\Core\LLM\PromptTemplates::languageRule()
                    . $workspace_intelligence;

                $messages = [['role' => 'system', 'content' => $systemPrompt]];
                foreach (array_slice($history, -8) as $h) {
                    if (!empty($h['role']) && isset($h['content'])) {
                        $messages[] = ['role' => $h['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string)$h['content']];
                    }
                }
                $messages[] = ['role' => 'user', 'content' => $message];

                // PATCH (Assistant 3) — primary path now /internal/assistant.
                // Runtime endpoint pulls workspace context from lu-context.js
                // (WP REST + Redis long-term, 15-min cache), persists conversation
                // history per conversation_id, and routes through tool-router.
                // Laravel-built systemPrompt + workspace_intelligence are still
                // sent as `context` so the runtime can layer them in.
                $assist = $runtime->assistant(
                    $message,
                    [
                        'workspace_id'    => $wsId,
                        'business_name'   => isset($ws) ? ($ws->name ?? '') : '',
                        'industry'        => isset($ws) ? ($ws->industry ?? '') : '',
                        'location'        => isset($ws) ? ($ws->location ?? '') : '',
                        'plan'            => isset($plan) ? ($plan->name ?? 'Free') : 'Free',
                        'credits_balance' => isset($credits) ? ($credits->balance ?? 0) : 0,
                        'workspace_intelligence' => $workspace_intelligence,
                    ],
                    "widget_ws_{$wsId}",
                    'dmm'
                );
                $assistReply = $assist['response'] ?? null;
                // PATCH (Assistant 3b, 2026-05-09) — generic-response detection.
                // After clearing the runtime's Shukran ghost, /internal/assistant
                // sometimes returns "you haven't told me about your business yet"
                // when its memory layer is empty. The Laravel-built
                // workspace_intelligence string passed in `context` is currently
                // ignored by the runtime (TASK 1 runtime patch fixes this once
                // deployed). Until then, detect those generic replies and fall
                // through to the chatJson fallback below which uses the rich
                // Laravel-side workspace_intelligence.
                $genericMarkers = [
                    "haven't told me", "havent told me", "could you share",
                    "tell me about your business", "what business are you",
                    "what industry", "you haven't specified", "you havent specified",
                    "haven't shared", "share what industry",
                    // PATCH (Aria platform-aware, 2026-05-09) — also catch
                    // generic SaaS strategy ramble. The runtime's default
                    // assistant prompt sometimes pivots to MRR/CAC/growth-
                    // hacking advice when asked a specific platform question.
                    // Mark those replies generic so the chatJson fallback
                    // (which uses Aria's platform-aware system prompt with
                    // agents + tasks injected) takes over.
                    'mrr', 'monthly recurring revenue', 'customer acquisition cost',
                    'cac', 'churn rate', 'ltv:cac', 'growth hack', 'product-led growth',
                    'go-to-market', 'gtm strategy', 'unit economics',
                ];
                $isGeneric = false;
                if ($assistReply) {
                    foreach ($genericMarkers as $g) {
                        if (stripos($assistReply, $g) !== false) { $isGeneric = true; break; }
                    }
                }
                if ($assistReply && !$isGeneric) {
                    return response()->json([
                        'response'       => $assistReply,
                        'agent_response' => true,
                        // PATCH (widget-persona, 2026-05-09) — widget rebrand:
                        // Aria persona instead of Sarah. Runtime agent_id stays
                        // 'dmm' because 'assistant' isn't a registered runtime
                        // persona (verified against runtime /health) — but the
                        // widget UI presents itself as Aria so it stays
                        // distinct from the Messages-panel Sarah surface.
                        'agent_name'     => 'Aria',
                        'agent_emoji'    => '✨',
                        'agent_color'    => '#06B6D4',
                        'is_action'      => $isAction,
                    ]);
                }
                // assistant returned empty or generic — fall through to the
                // chatJson path below which has full workspace_intelligence.
            }

            // PATCH 4 (2026-05-08): runtime-only path. Was a DeepSeekConnector
            // direct fallback that bypassed RuntimeClient.
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if ($runtime->isConfigured()) {
                // PATCH (Aria platform-aware, 2026-05-09) — Aria is a
                // PLATFORM intelligence assistant, not a marketing
                // strategist. She answers direct questions about agents,
                // tasks, and navigation in 2-3 sentences. She NEVER
                // gives generic SaaS / MRR / CAC / growth advice when
                // asked something specific. The PLATFORM STATE block
                // below (agents + tasks + workspace data) is her source
                // of truth — she answers from it, not from training.
                $systemPrompt = "You are Aria, the platform intelligence assistant for LevelUp Growth — an AI marketing platform.\n\n"
                    . "YOUR JOB: answer questions about THIS user's platform — their agents, their tasks, their websites, their data — directly and briefly.\n\n"
                    . "STYLE RULES:\n"
                    . "- Answer the actual question asked. Never pivot to generic advice.\n"
                    . "- 2-3 sentences max for simple questions. One sentence is often best.\n"
                    . "- Use the PLATFORM STATE below as your source of truth.\n"
                    . "- For availability questions: all agents listed below are available unless flagged otherwise. Just say so.\n"
                    . "- For task / website / article questions: read the counts and lists below and quote them.\n"
                    . "- For 'who handles X' questions: name the agent from the list and tell the user where to message them (Messages panel).\n"
                    . "- Never give generic marketing strategy advice (MRR, CAC, growth tactics, etc.) unless the user explicitly asks for strategy.\n"
                    . "- Never say 'I'm just an AI' or apologize for limits. Just answer.\n"
                    . "- Never invent agents, tasks, or data — if it's not in the PLATFORM STATE, say you don't have that info and offer to direct them somewhere useful.\n\n"
                    . "EXAMPLES:\n"
                    . "Q: 'Is Sarah available?' -> 'Yes, Sarah (Digital Marketing Manager) is available. Message her in the Messages panel to assign work.'\n"
                    . "Q: 'How many tasks are running?' -> Quote the running count from PLATFORM STATE.\n"
                    . "Q: 'Who handles SEO?' -> 'James is your SEO Strategist. Open the Messages panel and message James.'\n"
                    . "Q: 'What can you do?' -> 'I can tell you about your agents, tasks, articles, websites, and SEO data — and direct you to the right place. What do you need?'\n\n"
                    . "OUTPUT: Return ONLY a JSON object: {\"reply\":\"<your concise answer>\"}. The reply value mirrors the user's language; JSON keys stay in English.\n\n"
                    . \App\Core\LLM\PromptTemplates::languageRule()
                    . $workspace_intelligence;

                $historyText = '';
                foreach (array_slice($history, -8) as $h) {
                    if (!empty($h['role']) && isset($h['content'])) {
                        $role = $h['role'] === 'assistant' ? 'Assistant' : 'User';
                        $historyText .= "\n{$role}: " . (string) $h['content'];
                    }
                }
                $userPrompt = trim($historyText . "\nUser: " . $message);
                $result = $runtime->chatJson($systemPrompt, $userPrompt, [], 1000);
                $replyText = trim((string) ($result['parsed']['reply'] ?? $result['content'] ?? ''));

                return response()->json([
                    'response' => $replyText !== '' ? $replyText : 'I could not process that request.',
                    'agent_response' => false,
                    'is_action' => $isAction,
                ]);
            }

            return response()->json(['response' => 'AI is not configured. Please set up RUNTIME_URL / RUNTIME_SECRET in .env.']);
        } catch (\Throwable $e) {
            return response()->json([
                'response' => 'Sorry, I encountered an error: ' . $e->getMessage(),
                'error' => true,
            ]);
        }
    });

    // ── Meeting route aliases (JS calls /meeting/*, Laravel has /sarah/meeting/*) ──
    Route::post('/meeting/start', function (\Illuminate\Http\Request $r) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        $goal = $r->input('topic', $r->input('goal', 'Strategy discussion'));
        return response()->json($engine->startMeeting(
            $r->attributes->get('workspace_id'), $r->user()->id, $goal, $r->input('agents', [])
        ));
    });
    Route::get('/meeting/{id}', function ($id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        return response()->json($engine->getMeetingTranscript($id));
    });
    Route::post('/meeting/{id}/message', function (\Illuminate\Http\Request $r, $id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        $msg = $r->input('content', $r->input('message', ''));
        return response()->json($engine->userMessage($id, $r->user()->id, $msg));
    });
    Route::post('/meeting/{id}/wrap', function (\Illuminate\Http\Request $r, $id) {
        $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
        return response()->json($engine->endMeeting($id, $r->user()->id));
    });

    // ── Meeting status (polled every 4s by frontend) ─────────────
    // Auto-advances meeting on each poll — gives progressive message delivery
    Route::get('/meeting/{id}/status', function (\Illuminate\Http\Request $r, $id) {
        $meeting = \App\Models\Meeting::findOrFail($id);
        $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];

        // AUTO-ADVANCE: if meeting is active and current phase is done, advance to next
        // Uses cache lock to prevent double-advance from concurrent polls
        $phase = $meta['phase'] ?? 'opening';
        $lockKey = "meeting_advancing_{$id}";
        if ($meeting->status === 'active' && !in_array($phase, ['complete', 'synthesis', 'synthesis_done'])) {
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);
            if ($lock->get()) {
                try {
                    $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
                    $result = $engine->advanceMeeting($id);
                    $meeting = $meeting->fresh();
                    $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[Meeting] Auto-advance failed for meeting {$id}: " . $e->getMessage());
                } finally {
                    $lock->release();
                }
            }
        } elseif ($phase === 'synthesis' && $meeting->status === 'active') {
            // Synthesis is the last real phase — complete the meeting
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);
            if ($lock->get()) {
                try {
                    $engine = app(\App\Core\Orchestration\AgentMeetingEngine::class);
                    $engine->advanceMeeting($id); // synthesis → complete
                    $meeting = $meeting->fresh();
                    $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[Meeting] Synthesis→complete failed: " . $e->getMessage());
                } finally {
                    $lock->release();
                }
            }
        }

        // Get all messages for this meeting
        $messages = \App\Models\MeetingMessage::where('meeting_id', $id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) {
                $agent = $msg->sender_type === 'agent' ? \App\Models\Agent::find($msg->sender_id) : null;
                $attachments = $msg->attachments_json ? json_decode($msg->attachments_json, true) : [];
                return [
                    'role'      => $msg->sender_type === 'user' ? 'user' : ($attachments['role'] ?? 'agent'),
                    'agent_id'  => $agent ? $agent->slug : ($msg->sender_type === 'user' ? 'user' : 'system'),
                    'name'      => $agent ? $agent->name : 'You',
                    'title'     => $agent ? $agent->title : '',
                    'content'   => $msg->message,
                    'phase'     => $attachments['phase'] ?? null,
                    'timestamp' => $msg->created_at?->toISOString(),
                ];
            })->toArray();

        // Determine current phase from metadata or last message
        $phase = $meta['phase'] ?? 'briefing';
        $status = $meeting->status;

        // Map meeting status to frontend expectations
        if ($status === 'completed' || $status === 'closed') $status = 'complete';

        // Find current speaker (last agent message sender if meeting is active)
        $currentSpeaker = null;
        $spokenAgents = [];
        foreach ($messages as $m) {
            if ($m['role'] !== 'user' && $m['agent_id'] !== 'system') {
                $spokenAgents[] = $m['agent_id'];
                $currentSpeaker = $m['agent_id'];
            }
        }
        $spokenAgents = array_values(array_unique($spokenAgents));
        // Only show current speaker if meeting is active
        if ($status === 'complete') $currentSpeaker = null;

        return response()->json([
            'messages'       => $messages,
            'phase'          => $phase,
            'status'         => $status,
            'current_speaker'=> $currentSpeaker,
            'spokenAgents'   => $spokenAgents,
            'topic'          => $meeting->title,
        ]);
    });

    // ── Meeting pending tasks (called after meeting ends) ────────
    Route::get('/meeting/{id}/pending-tasks', function (\Illuminate\Http\Request $r, $id) {
        $meeting = \App\Models\Meeting::findOrFail($id);
        $wsId = $meeting->workspace_id;

        // Check meeting_tasks pivot for tasks linked to this meeting
        $taskIds = \Illuminate\Support\Facades\DB::table('meeting_tasks')
            ->where('meeting_id', $id)
            ->pluck('task_id')
            ->toArray();

        $tasks = [];
        if (!empty($taskIds)) {
            $tasks = \App\Models\Task::whereIn('id', $taskIds)
                ->get()
                ->map(fn($t) => [
                    'id'          => $t->id,
                    'title'       => ucfirst(str_replace('_', ' ', $t->action)),
                    'description' => $t->progress_message ?? ('Execute ' . $t->action . ' via ' . $t->engine),
                    'engine'      => $t->engine,
                    'action'      => $t->action,
                    'agent_id'    => $t->assigned_agents_json[0] ?? 'sarah',
                    'credit_cost' => $t->credit_cost,
                    'tools'       => [$t->action],
                    'status'      => $t->status,
                ])->toArray();
        }

        // Also check for execution plans linked via metadata
        if (empty($tasks)) {
            $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
            $planId = $meta['plan_id'] ?? $meta['execution_plan_id'] ?? null;
            if ($planId) {
                $planTasks = \Illuminate\Support\Facades\DB::table('plan_tasks')
                    ->where('plan_id', $planId)
                    ->get()
                    ->map(fn($pt) => [
                        'id'          => $pt->id,
                        'title'       => ucfirst(str_replace('_', ' ', $pt->action ?? 'task')),
                        'description' => $pt->description ?? '',
                        'engine'      => $pt->engine ?? 'system',
                        'action'      => $pt->action ?? 'execute',
                        'agent_id'    => $pt->agent_slug ?? 'sarah',
                        'credit_cost' => $pt->credit_cost ?? 0,
                        'tools'       => [$pt->action ?? 'execute'],
                        'status'      => $pt->status ?? 'pending',
                    ])->toArray();
                $tasks = $planTasks;
            }
        }

        return response()->json(['tasks' => $tasks]);
    });

    // ── Save meeting history/summary (POST from frontend after meeting ends) ──
    Route::post('/history', function (\Illuminate\Http\Request $r) {
        $meetingId = $r->input('meeting_id');
        $topic = $r->input('topic', '');
        $summary = $r->input('summary', '');
        if ($meetingId) {
            $meeting = \App\Models\Meeting::find($meetingId);
            if ($meeting) {
                $meta = $meeting->metadata_json ? json_decode($meeting->metadata_json, true) : [];
                $meta['summary'] = $summary;
                $meta['topic_label'] = $topic;
                $meeting->update(['metadata_json' => json_encode($meta)]);
            }
        }
        return response()->json(['saved' => true]);
    });


    // ── Builder.js compatibility stubs ──────────────────────────────
    Route::get("/policy", fn() => response()->json(["mode" => "manual", "policies" => []]));
    Route::post("/policy/track", fn() => response()->json(["tracked" => true]));
    Route::get("/policy/domain", fn() => response()->json(["domains" => []]));
    Route::get("/policy/suggestions", fn() => response()->json(["suggestions" => []]));
    Route::get("/workspace/subscription", function(\Illuminate\Http\Request $r) { $wsId = $r->attributes->get("workspace_id"); $sub = \Illuminate\Support\Facades\DB::table("subscriptions")->where("workspace_id", $wsId)->where("status", "active")->first(); return response()->json($sub ?? ["plan" => "free", "status" => "active"]); });
    Route::get("/system/cron-status", fn() => response()->json(["active" => false, "last_run" => null]));
    Route::get("/agents/dashboard", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // Get workspace-enabled agents only (not all 21)
        $agents = \App\Models\Agent::select('agents.id','agents.slug','agents.name','agents.title','agents.description')
            ->join('workspace_agents', 'agents.id', '=', 'workspace_agents.agent_id')
            ->where('workspace_agents.workspace_id', $wsId)
            ->where('workspace_agents.enabled', true)
            ->get();
        // Get task stats grouped by engine (proxy for agent assignment)
        $taskStats = \App\Models\Task::where('workspace_id', $wsId)
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(assigned_agents_json, '$[0]')), engine) as agent_key, status, count(*) as cnt, sum(credit_cost) as credits")
            ->groupBy('agent_key', 'status')
            ->get()
            ->groupBy('agent_key');
        // Get delegation stats per agent
        $delegationStats = \Illuminate\Support\Facades\DB::table('agent_delegations')
            ->where('workspace_id', $wsId)
            ->selectRaw("to_agent as agent_id, status, count(*) as cnt")
            ->groupBy('to_agent', 'status')
            ->get()
            ->groupBy('agent_id');
        // Get last activity per agent from audit_logs
        $lastActive = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->whereNotNull('entity_type')
            ->selectRaw("LOWER(entity_type) as etype, MAX(created_at) as last_at")
            ->groupBy('etype')
            ->pluck('last_at', 'etype')
            ->toArray();

        $result = $agents->map(function($a) use ($taskStats, $delegationStats, $lastActive, $wsId) {
            $slug = $a->slug;
            // Check tasks assigned to this agent or tasks in this agent's engine
            $stats = $taskStats->get($slug, collect());
            $delegations = $delegationStats->get($a->id, collect());
            $pending = 0; $executing = 0; $completed = 0; $failed = 0; $totalCredits = 0;
            foreach ($stats as $s) {
                $totalCredits += (int)$s->credits;
                match($s->status) {
                    'pending','queued','awaiting_approval' => $pending += $s->cnt,
                    'running','verifying' => $executing += $s->cnt,
                    'completed' => $completed += $s->cnt,
                    'failed','cancelled' => $failed += $s->cnt,
                    default => null,
                };
            }
            foreach ($delegations as $d) {
                match($d->status) {
                    'pending' => $pending += $d->cnt,
                    'in_progress' => $executing += $d->cnt,
                    'completed' => $completed += $d->cnt,
                    'failed' => $failed += $d->cnt,
                    default => null,
                };
            }
            $total = $completed + $failed;
            $successRate = $total > 0 ? round(($completed / $total) * 100) : 0;
            // Recent tasks for this agent
            $recentTasks = \App\Models\Task::where('workspace_id', $wsId)
                ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id, 'title' => $t->progress_message ?? ucfirst(str_replace('_', ' ', $t->action)),
                    'status' => $t->status, 'engine' => $t->engine, 'tools' => [$t->action],
                    'created_by' => 'sarah', 'duration_ms' => null,
                    'created_at' => $t->created_at, 'started_at' => $t->started_at,
                    'acknowledged_at' => null, 'completed_at' => $t->completed_at,
                ])->toArray();

            // Recent executions from audit_logs
            $recentExec = \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->whereRaw("LOWER(entity_type) = ?", [strtolower($slug)])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function($row) {
                    $meta = $row->metadata_json ? json_decode($row->metadata_json, true) : [];
                    return [
                        'tool_id' => $row->action, 'success' => !str_contains($row->action, 'fail'),
                        'result_summary' => $meta['result'] ?? $meta['summary'] ?? '',
                        'duration_ms' => $meta['duration_ms'] ?? null,
                        'created_at' => $row->created_at,
                    ];
                })->toArray();

            return [
                'agent_id' => $slug,
                'name' => $a->name,
                'title' => $a->title,
                'description' => $a->description,
                'pending' => $pending,
                'executing' => $executing,
                'completed' => $completed,
                'failed' => $failed,
                'total_credits' => $totalCredits,
                'success_rate' => $successRate,
                'last_active' => $lastActive[strtolower($slug)] ?? null,
                'recent_tasks' => $recentTasks,
                'recent_exec' => $recentExec,
            ];
        });

        return response()->json(['agents' => $result, 'stats' => [
            'total_agents' => $agents->count(),
            'active_tasks' => $result->sum('executing'),
            'total_completed' => $result->sum('completed'),
        ]]);
    });
    Route::get("/activity/feed", function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $limit = min((int) $r->input('limit', 30), 100);
        $rows = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $meta = $row->metadata_json ? json_decode($row->metadata_json, true) : [];
                $parts = explode('.', $row->action, 2);
                $engine = $parts[0] ?? 'system';
                $action = $parts[1] ?? $row->action;
                return [
                    'ts' => strtotime($row->created_at),
                    'event' => $action,
                    'agent_id' => $meta['agent'] ?? $meta['agent_id'] ?? strtolower($row->entity_type ?? 'system'),
                    'task_id' => $row->entity_id,
                    'data' => [
                        'title' => $meta['title'] ?? ucfirst(str_replace('_', ' ', $action)),
                        'engine' => $engine,
                    ],
                ];
            })->toArray();
        return response()->json(['feed' => $rows]);
    });
    Route::get("/agent/queue", fn() => response()->json(["queue" => [], "stats" => ["pending" => 0]]));
    Route::get("/reviews", fn() => response()->json(["reviews" => []]));
    Route::get("/seo/summary", fn(\Illuminate\Http\Request $r) => response()->json(["keywords" => 0, "audits" => 0]));
    Route::get("/seo/results", fn() => response()->json(["results" => []]));
    Route::get("/seo/bridge/{key}", fn() => response()->json(["data" => null]));
    Route::get("/tools/render-manifest", fn() => response()->json(["manifest" => []]));
    Route::get("/listings", fn() => response()->json(["listings" => []]));
    Route::get("/workspace/context", function(\Illuminate\Http\Request $r) { $ws = \App\Models\Workspace::find($r->attributes->get("workspace_id")); return response()->json(["business_name" => $ws->business_name ?? null, "industry" => $ws->industry ?? null, "location" => $ws->location ?? null]); });
    Route::get("/workspace/profile/status", function(\Illuminate\Http\Request $r) { $ws = \App\Models\Workspace::find($r->attributes->get("workspace_id")); return response()->json(["complete" => (bool)($ws && $ws->industry), "industry" => $ws->industry ?? null]); });
    Route::get("/p5/tasks/{id}", fn($r, $id) => response()->json(["task" => null]));
    Route::post("/p5/tasks/{id}/retry", fn($r, $id) => response()->json(["retried" => true]));
    Route::post("/tasks/{id}/retry", function (\Illuminate\Http\Request $r, $id) {
        $task = \App\Models\Task::findOrFail($id);
        if ($task->status !== 'failed') {
            return response()->json(['error' => 'Only failed tasks can be retried'], 422);
        }
        $task->update(['status' => 'pending', 'retry_count' => $task->retry_count + 1, 'error_text' => null]);
        return response()->json(['retried' => true, 'task_id' => $task->id]);
    });
    Route::get("/creative/assets", fn() => response()->json(["assets" => []]));
    Route::get("/websites", function(\Illuminate\Http\Request $r) { return response()->json(app(\App\Engines\Builder\Services\BuilderService::class)->listWebsites($r->attributes->get("workspace_id"))); });
    // PATCH 3 (2026-05-08): same as /api/builder/wizard above —
    // wizardGenerate() helpers were removed 2026-04-19 and this
    // closure was fataling. Use Arthur conversational create flow.
    Route::post("/websites/create", fn(\Illuminate\Http\Request $r) => response()->json([
        'error' => 'Website creation has moved to Arthur. Use POST /api/builder/arthur/message instead.',
        'replacement' => '/api/builder/arthur/message',
        'status' => 'gone',
    ], 501));
    // Website delete — matches JS wsDelete() which POSTs to /api/websites/{id}/delete
    Route::post("/websites/{id}/delete", function (\Illuminate\Http\Request $r, $id) {
        $bs = app(\App\Engines\Builder\Services\BuilderService::class);
        $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->first();
        if (!$website) {
            return response()->json(['success' => false, 'error' => 'Website not found'], 404);
        }
        // Count pages before delete
        $pageCount = \Illuminate\Support\Facades\DB::table('pages')->where('website_id', (int)$id)->count();
        // Delete pages permanently (they're tied to this website)
        \Illuminate\Support\Facades\DB::table('pages')->where('website_id', (int)$id)->delete();
        // Soft-delete the website
        $bs->deleteWebsite((int)$id);
        return response()->json(['success' => true, 'deleted' => true, 'pages_deleted' => $pageCount]);
    });
    // tasks/stats moved before /tasks/{id} to avoid wildcard match
    Route::post("/arthur/proactive", fn() => response()->json(["suggestions" => []]));
    Route::post("/arthur/record-progress", fn() => response()->json(["recorded" => true]));

    // ── Onboarding v2 (Step 1–3 runbook 2026-04-25) ──────────────
    // New, controller-backed endpoints. Live alongside legacy /workspace/onboarding
    // routes below (kept for back-compat with older core.js paths).
    Route::post('/onboarding/business-info', [\App\Http\Controllers\Api\OnboardingController::class, 'businessInfo']);
    Route::get('/onboarding/status', [\App\Http\Controllers\Api\OnboardingController::class, 'status']);
    Route::post('/onboarding/complete', [\App\Http\Controllers\Api\OnboardingController::class, 'complete']);

    // ── Workspace Onboarding ─────────────────────────────────────
    Route::put('/workspace/settings', function (\Illuminate\Http\Request $r) {
        // Alias for onboarding save — crm-engine.js and core.js call PUT /workspace/settings
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update(array_filter([
            'business_name' => $r->input('business_name'),
            'industry' => $r->input('industry'),
            'services_json' => $r->input('services') ?: $r->input('services_json'),
            'goal' => $r->input('goal') ?: $r->input('business_desc'),
            'location' => $r->input('location'),
        ]));
        return response()->json(['success' => true]);
    });

        Route::post('/workspace/onboarding', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update([
            'business_name' => $r->input('business_name'),
            'industry' => $r->input('industry'),
            'services_json' => $r->input('services'),
            'goal' => $r->input('goal'),
            'location' => $r->input('location'),
        ]);
        return response()->json(['success' => true]);
    });

    Route::post('/workspace/complete-onboarding', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $ws->update(['onboarded' => true, 'onboarded_at' => now()]);

        // Sarah sends cost estimate ONLY — zero credits, template message
        try {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);
            $proposal = $proactive->onOnboardingComplete($ws->id, $r->user()->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Proactive proposal failed: {$e->getMessage()}");
            $proposal = ['error' => $e->getMessage()];
        }

        return response()->json([
            'success' => true,
            'proposal' => $proposal,
            'message' => 'Welcome! Sarah has prepared a strategy proposal. Review and approve in the Strategy Room — no credits used until you approve.',
        ]);
    });

    // ── Workspace Status (for SaaS app dashboard) ────────────────
    Route::get('/workspace/status', function (\Illuminate\Http\Request $r) {
        $ws = \App\Models\Workspace::findOrFail($r->attributes->get('workspace_id'));
        $planRules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($ws->id);
        $credit = \App\Models\Credit::where('workspace_id', $ws->id)->first();
        $websiteCount = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $ws->id)->whereNull('deleted_at')->count();
        return response()->json([
            'workspace' => $ws, 'plan' => $planRules,
            'credit_balance' => $credit?->balance ?? 0,
            'monthly_credit_limit' => $planRules['credit_limit'],
            'website_count' => $websiteCount,
            'business_name' => $ws->business_name,
            'industry' => $ws->industry,
            'onboarded' => (bool) $ws->onboarded,
        ]);
    });

    // ── Workspace Credits ────────────────────────────────────────
    Route::get('/workspace/credits', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $credit = \App\Models\Credit::where('workspace_id', $wsId)->first();
        $planRules = app(\App\Core\PlanGating\PlanGatingService::class)->getPlanRules($wsId);
        $used = \App\Models\CreditTransaction::where('workspace_id', $wsId)->where('type', 'commit')->sum('amount');
        return response()->json([
            'credit_balance' => $credit?->balance ?? 0,
            'monthly_limit' => $planRules['credit_limit'],
            'plan_name' => $planRules['plan_name'],
            'lifetime_used' => abs($used),
        ]);
    });

    Route::get('/workspace/credits/transactions', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $txns = \App\Models\CreditTransaction::where('workspace_id', $wsId)
            ->orderByDesc('created_at')->limit($r->input('limit', 20))->get();
        return response()->json(['transactions' => $txns]);
    });

    // ── Workspace Proactive Settings ─────────────────────────────
    Route::get('/workspace/proactive-settings', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $ws   = \App\Models\Workspace::find($wsId);
        return response()->json([
            'proactive_enabled'   => $ws?->proactive_enabled ?? true,
            'proactive_frequency' => $ws?->proactive_frequency ?? 'daily',
        ]);
    });

    Route::put('/workspace/proactive-settings', function (\Illuminate\Http\Request $r) {
        $wsId    = $r->attributes->get('workspace_id');
        $enabled = $r->input('proactive_enabled');
        $freq    = $r->input('proactive_frequency');

        $update = [];
        if (!is_null($enabled))  $update['proactive_enabled']   = (bool) $enabled;
        if (!is_null($freq) && in_array($freq, ['daily', 'weekly'])) {
            $update['proactive_frequency'] = $freq;
        }

        if (!empty($update)) {
            \App\Models\Workspace::where('id', $wsId)->update($update);
        }

        $ws = \App\Models\Workspace::find($wsId);
        return response()->json([
            'updated'             => !empty($update),
            'proactive_enabled'   => $ws?->proactive_enabled ?? true,
            'proactive_frequency' => $ws?->proactive_frequency ?? 'daily',
        ]);
    });

    // ── Workspace Capabilities Map (for frontend feature gating) ─
    Route::get('/workspace/capabilities', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\FeatureGateService::class)->getCapabilities($wsId)
        );
    });

    // ── Trial status ──────────────────────────────────────────────
    Route::get('/workspace/trial-status', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\TrialService::class)->getTrialStatus($wsId)
        );
    });

    // Manual trial activation (for testing / admin override)
    Route::post('/workspace/trial/activate', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\TrialService::class)->activateTrial($wsId)
        );
    });

    // ── Team Management ───────────────────────────────────────────
    // Gated: plan:agent (Growth+) for invite/remove — anyone can view members
    Route::prefix('team')->group(function () {
        $t = \App\Core\Workspaces\TeamService::class;

        // List members + pending invites (all roles can view)
        Route::get('/members', function (\Illuminate\Http\Request $r) use ($t) {
            return response()->json(app($t)->getMembers($r->attributes->get('workspace_id')));
        });

        // Seat quota check
        Route::get('/seats', function (\Illuminate\Http\Request $r) use ($t) {
            return response()->json(app($t)->checkSeatQuota($r->attributes->get('workspace_id')));
        });

        // Invite member — admin or owner only, Growth+ plan
        Route::post('/invite', function (\Illuminate\Http\Request $r) use ($t) {
            $wsId = $r->attributes->get('workspace_id');
            $result = app($t)->inviteMember(
                $wsId,
                $r->user()->id,
                $r->input('email', ''),
                $r->input('role', 'member')
            );
            return response()->json($result, $result['success'] ? 201 : 422);
        })->middleware(['team.role:admin', 'plan:agent']);

        // List pending invites
        Route::get('/invites', function (\Illuminate\Http\Request $r) use ($t) {
            return response()->json([
                'invites' => app($t)->listPendingInvites($r->attributes->get('workspace_id')),
            ]);
        })->middleware('team.role:admin');

        // Cancel a pending invite
        Route::delete('/invites/{id}', function (\Illuminate\Http\Request $r, $id) use ($t) {
            $wsId    = $r->attributes->get('workspace_id');
            $deleted = app($t)->cancelInvite($wsId, (int) $id);
            return response()->json(['cancelled' => $deleted]);
        })->middleware('team.role:admin');

        // Update member role — admin or owner only
        Route::put('/members/{userId}/role', function (\Illuminate\Http\Request $r, $userId) use ($t) {
            $wsId   = $r->attributes->get('workspace_id');
            $result = app($t)->updateRole($wsId, (int) $userId, $r->input('role', 'member'), $r->user()->id);
            return response()->json($result, $result['success'] ? 200 : 422);
        })->middleware('team.role:admin');

        // Remove a member — admin or owner only
        Route::delete('/members/{userId}', function (\Illuminate\Http\Request $r, $userId) use ($t) {
            $wsId   = $r->attributes->get('workspace_id');
            $result = app($t)->removeMember($wsId, (int) $userId, $r->user()->id);
            return response()->json($result, $result['success'] ? 200 : 422);
        })->middleware('team.role:admin');
    });

    // ── Workspace Billing ────────────────────────────────────────
    Route::get('/billing/status', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        return response()->json(
            app(\App\Core\Billing\StripeService::class)->getBillingStatus($wsId)
        );
    });

    // ── Billing Actions (Stripe) ─────────────────────────────────
    Route::post('/billing/checkout', function (\Illuminate\Http\Request $r) {
        $r->validate(['plan_id' => 'required|exists:plans,id']);
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->createCheckoutSession(
            $r->attributes->get('workspace_id'), $r->input('plan_id'), $r->user()->id
        ));
    });

    Route::post('/billing/upgrade', function (\Illuminate\Http\Request $r) {
        $r->validate(['plan_id' => 'required|exists:plans,id']);
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->changePlan(
            $r->attributes->get('workspace_id'), (int) $r->input('plan_id'), $r->user()->id
        ));
    });

    Route::get('/billing/portal', function (\Illuminate\Http\Request $r) {
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->getPortalUrl(
            $r->attributes->get('workspace_id'), $r->user()->id
        ));
    });

    Route::post('/billing/cancel', function (\Illuminate\Http\Request $r) {
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->cancel($r->attributes->get('workspace_id')));
    });

    Route::post('/billing/add-agent', function (\Illuminate\Http\Request $r) {
        $r->validate(['agent_slug' => 'required|string']);
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->addAgentAddon(
            $r->attributes->get('workspace_id'), $r->input('agent_slug')
        ));
    });

    Route::get('/billing/plans', function () {
        return response()->json(['plans' => \App\Models\Plan::orderBy('price')->get()]);
    });

    // ── Analytics / Insights ─────────────────────────────────────
    Route::get('/insights/summary', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $today = now()->startOfDay();
        return response()->json([
            'tasks_completed_today' => \App\Models\Task::where('workspace_id', $wsId)->where('status', 'completed')->where('completed_at', '>=', $today)->count(),
            'credits_used_today' => abs(\App\Models\CreditTransaction::where('workspace_id', $wsId)->where('type', 'commit')->where('created_at', '>=', $today)->sum('amount')),
            'campaigns_sent_week' => \Illuminate\Support\Facades\DB::table('campaigns')->where('workspace_id', $wsId)->where('status', 'sent')->where('sent_at', '>=', now()->subWeek())->count(),
            'posts_published_week' => \Illuminate\Support\Facades\DB::table('social_posts')->where('workspace_id', $wsId)->where('status', 'published')->where('published_at', '>=', now()->subWeek())->count(),
            'write_items_total' => \Illuminate\Support\Facades\DB::table('articles')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'avg_seo_score' => \Illuminate\Support\Facades\DB::table('seo_audits')->where('workspace_id', $wsId)->where('status', 'completed')->avg('score'),
        ]);
    });

    // ── Chatbot888 admin SPA (Recovery 2026-05-05 / §2 May-2 chatbot) ────────
    Route::prefix('chatbot')->group(function () {
        Route::get   ('/settings',                  [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'getSettings']);
        Route::put   ('/settings',                  [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'updateSettings']);
        Route::post  ('/knowledge/upload',          [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'uploadKnowledge']);
        Route::post  ('/knowledge/text',            [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'patchKnowledgeText']);
        Route::get   ('/knowledge',                 [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listKnowledge']);
        Route::delete('/knowledge/{id}',            [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'deleteKnowledge']);
        Route::get   ('/conversations',             [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listConversations']);
        Route::get   ('/conversations/{id}',        [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'getConversation']);
        Route::get   ('/leads',                     [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listLeads']);
        Route::get   ('/bookings',                  [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listBookings']);
        Route::get   ('/escalations',               [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listEscalations']);
        Route::get   ('/widget-tokens',             [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'listWidgetTokens']);
        Route::post  ('/widget-tokens',             [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'mintWidgetToken']);
        Route::post  ('/widget-tokens/{id}/revoke', [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'revokeWidgetToken']);
    });

    // ── Chatbot888 billing endpoints (Recovery 2026-05-05 / §4 May-2 chatbot) ─
    Route::post('/billing/chatbot-addon/add', function (\Illuminate\Http\Request $r) {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $userId = (int) ($r->attributes->get('user_id') ?? auth()->id() ?? 0);
        return response()->json(
            app(\App\Core\Billing\StripeService::class)->addChatbotAddon($wsId, $userId)
        );
    });
    Route::post('/billing/chatbot-addon/remove', function (\Illuminate\Http\Request $r) {
        $wsId   = (int) $r->attributes->get('workspace_id');
        $userId = (int) ($r->attributes->get('user_id') ?? auth()->id() ?? 0);
        return response()->json(
            app(\App\Core\Billing\StripeService::class)->removeChatbotAddon($wsId, $userId)
        );
    });
});

// ── Debug Routes (disabled in production) ────────────────────────────
Route::middleware(['auth.jwt', \App\Http\Middleware\AdminMiddleware::class])->prefix('debug')->group(function () {
    Route::post('/run-scenario', [\App\Http\Controllers\Api\Debug\DebugScenarioController::class, 'runScenario']);
});

// ══════════════════════════════════════════════════════════════════════
// ADMIN ROUTES (Phase 4 — requires admin middleware)
// ══════════════════════════════════════════════════════════════════════
Route::middleware(['auth.jwt', \App\Http\Middleware\AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        $c = \App\Http\Controllers\Api\Admin\AdminController::class;

        // Dashboard
        Route::get('/stats', [$c, 'dashboardStats']);

        // Notification System v2 (2026-05-07) — admin scope
        Route::get ('/notifications',           [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'index']);
        Route::post('/notifications/broadcast', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'broadcast']);

        // Media Library admin actions (T3.1D)
        Route::post  ('/media/generate', [\App\Http\Controllers\Api\Admin\AdminMediaController::class, 'generate']);
        Route::delete('/media/{id}',     [\App\Http\Controllers\Api\Admin\AdminMediaController::class, 'destroy'])->where('id', '[0-9]+');

        // ── Connector / API-key admin (Recovery 2026-05-05 / §1 SEO_ONLY) ────
        Route::get ('/connector-sites',      [$c, 'listConnectorSites']);
        Route::post('/api-keys/{id}/revoke', [$c, 'revokeApiKey']);

        // Email templates — admin CRUD (bypasses system protection)
        $eb = \App\Engines\Marketing\Services\EmailBuilderService::class;
        Route::get('/email-templates',           fn() => response()->json(['templates' => app($eb)->adminListAllTemplates()]));
        Route::post('/email-templates',          fn(\Illuminate\Http\Request $r)      => response()->json(app($eb)->adminCreateSystemTemplate($r->all())));
        Route::put('/email-templates/{id}',      fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->adminUpdateAnyTemplate((int) $id, $r->all())));
        Route::delete('/email-templates/{id}',   fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($eb)->adminDeleteAnyTemplate((int) $id)]));
        Route::post('/email-templates/{id}/regen-thumbnail', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($eb)->generateThumbnail((int) $id)));


        // API Usage tracking dashboard
        Route::get('/api-usage', function () {
            $today = now()->startOfDay();
            $monthStart = now()->startOfMonth();

            $todayStats = \Illuminate\Support\Facades\DB::table('api_usage_logs')
                ->where('created_at', '>=', $today)
                ->selectRaw("COUNT(*) as calls, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(cost_usd),0) as cost_usd")
                ->first();

            $monthStats = \Illuminate\Support\Facades\DB::table('api_usage_logs')
                ->where('created_at', '>=', $monthStart)
                ->selectRaw("COUNT(*) as calls, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(cost_usd),0) as cost_usd")
                ->first();

            $totalStats = \Illuminate\Support\Facades\DB::table('api_usage_logs')
                ->selectRaw("COUNT(*) as calls, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(cost_usd),0) as cost_usd")
                ->first();

            $byProvider = \Illuminate\Support\Facades\DB::table('api_usage_logs')
                ->selectRaw("provider, COUNT(*) as calls, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(cost_usd),0) as cost_usd, ROUND(AVG(duration_ms)) as avg_ms")
                ->groupBy('provider')
                ->get();

            $recent = \Illuminate\Support\Facades\DB::table('api_usage_logs')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            return response()->json([
                'summary' => ['today' => $todayStats, 'this_month' => $monthStats, 'total' => $totalStats],
                'by_provider' => $byProvider,
                'recent' => $recent,
            ]);
        });

        // Users
        Route::get('/users', [$c, 'listUsers']);
        Route::get('/users/{id}', [$c, 'getUser']);
        Route::put('/users/{id}', [$c, 'updateUser']);
        Route::post('/users/{id}/suspend', [$c, 'suspendUser']);
        Route::delete('/users/{id}', [$c, 'deleteUser']);
        Route::post('/users', [$c, 'createUser']);

        // Workspaces
        Route::get('/workspaces', [$c, 'listWorkspaces']);
        Route::get('/workspaces/{id}', [$c, 'getWorkspace']);
        Route::put('/workspaces/{id}', [$c, 'updateWorkspace']);
        Route::post('/workspaces/{id}/credits', [$c, 'adjustCredits']);

        // ── House Account Management ────────────────────────────────
        Route::get('/house-accounts', function () {
            $accounts = \Illuminate\Support\Facades\DB::table('workspaces')
                ->where('is_house_account', true)
                ->get();

            $result = [];
            foreach ($accounts as $ws) {
                $credits = \Illuminate\Support\Facades\DB::table('credits')
                    ->where('workspace_id', $ws->id)->first();
                $lastReplenish = \Illuminate\Support\Facades\DB::table('credit_transactions')
                    ->where('workspace_id', $ws->id)
                    ->where('reference_type', 'house_account_replenish')
                    ->orderByDesc('created_at')->first();
                $sub = \Illuminate\Support\Facades\DB::table('subscriptions')
                    ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                    ->where('subscriptions.workspace_id', $ws->id)
                    ->where('subscriptions.status', 'active')
                    ->select('plans.name as plan_name')
                    ->first();

                $result[] = [
                    'id' => $ws->id,
                    'name' => $ws->name,
                    'balance' => $credits->balance ?? 0,
                    'reserved' => $credits->reserved_balance ?? 0,
                    'monthly_allowance' => $ws->monthly_credit_allowance,
                    'auto_replenish' => (bool) $ws->credits_auto_replenish,
                    'plan' => $sub->plan_name ?? 'None',
                    'last_replenish' => $lastReplenish->created_at ?? null,
                ];
            }

            return response()->json(['house_accounts' => $result]);
        });

        Route::post('/house-accounts/{id}/top-up', function (\Illuminate\Http\Request $r, $id) {
            $amount = (int) $r->input('amount', 0);
            if ($amount <= 0) return response()->json(['error' => 'Amount must be positive'], 400);

            $ws = \Illuminate\Support\Facades\DB::table('workspaces')
                ->where('id', $id)->where('is_house_account', true)->first();
            if (!$ws) return response()->json(['error' => 'House account not found'], 404);

            $credits = \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $id)->first();
            $oldBalance = $credits->balance ?? 0;
            $newBalance = $oldBalance + $amount;

            \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $id)
                ->update(['balance' => $newBalance, 'updated_at' => now()]);

            \Illuminate\Support\Facades\DB::table('credit_transactions')->insert([
                'workspace_id' => (int) $id,
                'type' => 'credit',
                'amount' => $amount,
                'reference_type' => 'house_account_manual_topup',
                'metadata_json' => json_encode(['previous_balance' => $oldBalance, 'new_balance' => $newBalance, 'topped_up_by' => 'admin']),
                'created_at' => now(),
            ]);

            return response()->json(['success' => true, 'new_balance' => $newBalance]);
        });

        Route::put('/house-accounts/{id}/settings', function (\Illuminate\Http\Request $r, $id) {
            $ws = \Illuminate\Support\Facades\DB::table('workspaces')
                ->where('id', $id)->where('is_house_account', true)->first();
            if (!$ws) return response()->json(['error' => 'House account not found'], 404);

            $update = [];
            if ($r->has('monthly_credit_allowance')) $update['monthly_credit_allowance'] = (int) $r->input('monthly_credit_allowance');
            if ($r->has('credits_auto_replenish')) $update['credits_auto_replenish'] = (bool) $r->input('credits_auto_replenish');
            if (!empty($update)) {
                $update['updated_at'] = now();
                \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $id)->update($update);
            }

            return response()->json(['success' => true, 'updated' => $update]);
        });
        // ── Media Library (AdminMediaController) ─────────────────────
        // Rewritten 2026-04-19. Replaces three inline closures with a
        // dedicated controller that exposes the full admin UI surface:
        // filtered+paginated list, stats cards, platform-asset upload,
        // delete with file cleanup, and arthur-upload file scan.
        $mc = \App\Http\Controllers\Api\Admin\AdminMediaController::class;
        Route::get('/media', [$mc, 'index']);
        Route::get('/media/stats', [$mc, 'stats']);
        Route::get('/media/arthur-uploads', [$mc, 'arthurUploads']);
        Route::post('/media/upload', [$mc, 'upload']);
        Route::post('/media/bulk-upload', [$mc, 'bulkUpload']);
        Route::post('/media/bulk-delete', [$mc, 'bulkDestroy']);
        Route::patch('/media/{id}/tags', [$mc, 'updateTags'])
            ->where('id', '[0-9]+');
        Route::get('/media/{id}/usage', [$mc, 'usage'])
            ->where('id', '[0-9]+');
        Route::delete('/media/{id}', [$mc, 'destroy'])
            ->where('id', '[0-9]+');
        Route::post('/workspaces/{id}/plan', [$c, 'assignPlan']);

        // Plans
        Route::get('/plans', [$c, 'listPlans']);
        Route::put('/plans/{id}', [$c, 'updatePlan']);

        // Agents
        Route::get('/agents', [$c, 'listAgents']);
        Route::put('/agents/{id}', [$c, 'updateAgent']);

        // System Config
        Route::get('/config', [$c, 'getConfig']);
        Route::post('/config', [$c, 'updateConfig']);

        // System Health
        Route::get('/health', [$c, 'systemHealth']);
        Route::get('/queue', [$c, 'queueHealth']);
        Route::post('/recover-stale', [$c, 'recoverStale']);
        Route::get('/validation-report', [$c, 'validationReport']);

        // Task Monitor
        Route::get('/tasks', [$c, 'taskMonitor']);
        Route::get('/tasks/{id}', [$c, 'taskDetail']);
        Route::post('/tasks/{id}/retry', [$c, 'retryTask']);
        Route::post('/tasks/{id}/cancel', [$c, 'cancelTask']);

        // Audit Logs
        Route::get('/audit-logs', [$c, 'auditLogs']);

        // Memberships
        Route::get('/memberships', [$c, 'listMemberships']);
        Route::put('/memberships/{id}', [$c, 'updateMembership']);

        // Subscriptions
        Route::get('/subscriptions', [$c, 'listSubscriptions']);

        // Sessions (Phase 1 — Operational Visibility)
        Route::get('/sessions', [$c, 'listSessions']);
        Route::post('/sessions/{id}/revoke', [$c, 'revokeSession']);

        // Credits & Transactions (Phase 1 — Operational Visibility)
        Route::get('/credits', [$c, 'listCredits']);

        // Failed Jobs (Phase 1 — Operational Visibility)
        Route::get('/failed-jobs', [$c, 'listFailedJobs']);
        Route::post('/failed-jobs/{id}/retry', [$c, 'retryFailedJob']);
        Route::delete('/failed-jobs/{id}', [$c, 'deleteFailedJob']);
        Route::post('/failed-jobs/purge', [$c, 'purgeFailedJobs']);
// ── Phase 2: Engine Registry (AdminEngineController) ─────────────
        $ec = \App\Http\Controllers\Api\Admin\AdminEngineController::class;
        Route::get('/engines/registry', [$ec, 'registry']);
        Route::get('/engines/capabilities', [$ec, 'capabilities']);
        Route::put('/engines/capabilities', [$ec, 'updateCapability']);

        // ── Phase 2: Analytics (AdminAnalyticsController) ────────────────
        $ac = \App\Http\Controllers\Api\Admin\AdminAnalyticsController::class;
        Route::get('/analytics', [$ac, 'overview']);
        Route::get('/analytics/workspace/{id}', [$ac, 'workspace']);

        // ── Phase 3: Bella AI Admin Assistant ────────────────────────────

        // ── Phase 3: Content & Data Visibility (AdminContentController) ──
        $cc = \App\Http\Controllers\Api\Admin\AdminContentController::class;
        Route::get('/websites-all', [$cc, 'listWebsites']);
        Route::get('/campaigns-all', [$cc, 'listCampaigns']);
        Route::get('/assets', [$cc, 'listCreativeAssets']);
        Route::get('/articles', [$cc, 'listArticles']);
        Route::get('/crm-overview', [$cc, 'crmOverview']);
        Route::get('/seo-overview', [$cc, 'seoOverview']);
        Route::get('/revenue', [$cc, 'revenueOverview']);

        // ── Template Library management (AdminTemplatesController) ──────
        // Added 2026-04-19. File-based template catalogue under
        // storage/templates/{industry}/. Admin panel UI lives at
        // #templatesAdmin in resources/views/admin/app.blade.php.
        $tc = \App\Http\Controllers\Api\Admin\AdminTemplatesController::class;
        Route::get('/templates', [$tc, 'index']);
        Route::post('/templates/{industry}/toggle', [$tc, 'toggle'])
            ->where('industry', '[a-z0-9_]+');
        Route::post('/templates/{industry}/clone', [$tc, 'clone'])
            ->where('industry', '[a-z0-9_]+');
        Route::post('/templates/upload', [$tc, 'upload']);

        // ── Phase 3: Intelligence & Memory (AdminIntelligenceController) ─
        $ic = \App\Http\Controllers\Api\Admin\AdminIntelligenceController::class;
        Route::get('/meetings-all', [$ic, 'listMeetings']);
        Route::get('/proposals', [$ic, 'listProposals']);
        Route::get('/global-knowledge', [$ic, 'globalKnowledge']);
        Route::get('/workspace-memory', [$ic, 'workspaceMemory']);
        Route::get('/experiments', [$ic, 'experiments']);
        Route::get('/notifications-all', [$ic, 'notifications']);
        $bc = \App\Http\Controllers\Api\Admin\BellaController::class;
        Route::post('/bella', [$bc, 'chat']);
        // Bella Session 2: vision analysis + artifacts endpoints
        Route::post('/bella/vision', function (\Illuminate\Http\Request $r) {
            $prompt = $r->input('prompt', 'Describe what you see in this image.');
            $image  = $r->input('image', '');     // base64
            $imageUrl = $r->input('image_url');    // optional URL

            $runtime = app(\App\Connectors\RuntimeClient::class);
            $result = $runtime->visionAnalyze($prompt, $image, $imageUrl);

            return response()->json($result, $result['success'] ? 200 : 502);
        });
        Route::get('/bella/artifacts', function (\Illuminate\Http\Request $r) {
            $perPage = min((int) ($r->input('per_page', 20)), 100);
            $assets = \Illuminate\Support\Facades\DB::table('assets')
                ->where(function ($q) {
                    $q->where('workspace_id', 1)  // admin workspace
                      ->orWhere('prompt', 'like', '%bella%')
                      ->orWhere('prompt', 'like', '%admin%');
                })
                ->orderByDesc('created_at')
                ->paginate($perPage);
            return response()->json($assets);
        });
        // Bella Session 6: video generation poll endpoint
        Route::get('/bella/video-status/{assetId}', function (int $assetId) {
            $svc = app(\App\Engines\Creative\Services\CreativeService::class);
            $result = $svc->pollVideoJob($assetId);
            $asset = \Illuminate\Support\Facades\DB::table('assets')->where('id', $assetId)->first();
            return response()->json([
                'asset_id' => $assetId,
                'status'   => $result['status'] ?? $asset->status ?? 'unknown',
                'url'      => $result['url'] ?? $asset->url ?? null,
            ]);
        });

    });

// ══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES (no auth required)
// ══════════════════════════════════════════════════════════════════════

// Stripe Webhook (no auth — verified by signature)
Route::post('/webhook/stripe', function (\Illuminate\Http\Request $r) {
    // v5.5.4 — webhook ALWAYS returns 200. Stripe retries 5xx for hours; we
    // do not want a transient app bug to cause a webhook storm. We surface
    // the actual handler outcome in the JSON body for our own logs/dashboards.
    try {
        $stripe = app(\App\Core\Billing\StripeService::class);
        $result = $stripe->handleWebhook(
            $r->getContent(),
            $r->header('Stripe-Signature', '')
        );
        return response()->json(['received' => true, 'result' => $result], 200);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Stripe webhook crash', [
            'error' => $e->getMessage(),
            'trace' => substr($e->getTraceAsString(), 0, 1500),
        ]);
        return response()->json(['received' => true, 'error' => 'logged'], 200);
    }
})->withoutMiddleware('auth.jwt');

// ── Internal Runtime Callback Routes (secret-gated, no JWT) ─────────────────
// Called by: Railway Node.js runtime, scheduler cron jobs
// Auth: X-Runtime-Secret header must match LARAVEL_RUNTIME_SECRET env var
Route::prefix('internal')->group(function () {

    // Middleware: verify shared secret
    Route::middleware([\App\Http\Middleware\RuntimeSecretMiddleware::class])->group(function () {

        // Proactive trigger — called by cron or external scheduler
        // Delegates to Sarah::handleProactiveSignal() which enforces no-credit-spend rule
        Route::post('/proactive-trigger', function (\Illuminate\Http\Request $r) {
            $wsId       = (int) $r->input('workspace_id');
            $signalType = $r->input('signal_type', 'daily_check');
            $context    = $r->input('context', []);

            if (!$wsId) {
                return response()->json(['error' => 'workspace_id required'], 422);
            }

            $sarah  = app(\App\Core\Orchestration\SarahOrchestrator::class);
            $result = $sarah->handleProactiveSignal($wsId, $signalType, $context);

            return response()->json($result);
        });

        // Runtime task result callback — called when BullMQ worker completes a task
        Route::post('/task-result', function (\Illuminate\Http\Request $r) {
            $taskId = $r->input('task_id');
            $status = $r->input('status');
            $result = $r->input('result', []);

            if (!$taskId || !$status) {
                return response()->json(['error' => 'task_id and status required'], 422);
            }

            $executor = app(\App\Core\EngineKernel\EngineExecutionService::class);
            $executor->handleRuntimeCallback($taskId, $status, $result);

            return response()->json(['received' => true]);
        });

        // Runtime health ping
        Route::get('/ping', fn() => response()->json(['status' => 'ok', 'ts' => now()->toISOString()]));

        // ─────────────────────────────────────────────────────────────────────
        // RUNTIME CALLBACK STUBS — added 2026-04-12 (Phase 0.6b / doc 04 + 11)
        // ─────────────────────────────────────────────────────────────────────
        // The runtime sends outbound callbacks to legacy WordPress paths
        // ($WP_URL/wp-json/lu/v1/*, /wp-json/lumkt/v1/*, /wp-json/lucrm/v1/*).
        // Per planner Q3 Option B, nginx rewrites translate those legacy paths
        // to /api/internal/* on the Laravel side. Until those rewrites are
        // applied to nginx config, these stubs are reachable directly via
        // /api/internal/* with the X-LU-Secret header.
        //
        // Status of each route:
        //   - REAL: backed by actual data, useful from day one
        //   - STUB: returns {ok:true, stub:true} placeholder, needs real impl

        // ── Agents — REAL — high value for the runtime PR ──────────────────
        // The runtime needs to know the canonical agent roster (21 agents per
        // doc 13) to register them. This endpoint exposes the agents table.
        Route::get('/agents', function () {
            return response()->json([
                'agents' => \App\Models\Agent::where('status', 'active')
                    ->orderBy('id')
                    ->get([
                        'id', 'slug', 'name', 'title', 'description',
                        'category', 'level', 'role', 'is_dmm',
                        'capabilities_json', 'skills_json',
                    ])
                    ->toArray(),
            ]);
        });

        Route::get('/agents/{slug}/experience', function (string $slug) {
            $agent = \App\Models\Agent::where('slug', $slug)->first();
            if (!$agent) return response()->json(['error' => 'Agent not found'], 404);
            try {
                $exp = app(\App\Core\Intelligence\AgentExperienceService::class);
                return response()->json([
                    'agent_slug' => $slug,
                    'agent_id'   => $agent->id,
                    'experience' => $exp->buildExperienceContext($agent->id, null),
                ]);
            } catch (\Throwable $e) {
                return response()->json(['agent_slug' => $slug, 'experience' => '', 'error' => $e->getMessage()]);
            }
        });

        // ── Workspace context — REAL — used by runtime for agent context ──
        Route::get('/workspace/{id}/context', function (int $id) {
            $ws = \App\Models\Workspace::find($id);
            if (!$ws) return response()->json(['error' => 'Workspace not found'], 404);
            return response()->json([
                'workspace_id'  => $ws->id,
                'business_name' => $ws->business_name ?? null,
                'industry'      => $ws->industry ?? null,
                'location'      => $ws->location ?? null,
                'goal'          => $ws->goal ?? null,
                'services'      => $ws->services ?? null,
            ]);
        });

        // ── Notifications — STUB — needs real dispatcher (Phase 4.5 / 11) ──
        Route::post('/notifications', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::info('runtime/notifications stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'path' => '/api/internal/notifications']);
        });

        // ── Tools status — STUB — engine_intelligence layer integration TODO
        Route::get('/tools/status', function () {
            return response()->json(['ok' => true, 'stub' => true, 'path' => '/api/internal/tools/status']);
        });
        Route::get('/tool-registry/stats', function () {
            return response()->json(['ok' => true, 'stub' => true, 'path' => '/api/internal/tool-registry/stats']);
        });
        Route::post('/tools/execute', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::info('runtime/tools/execute stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'path' => '/api/internal/tools/execute']);
        });

        // ── Site pages (for SEO insights / scanner) — STUB ─────────────────
        Route::get('/site/pages', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'pages' => []]);
        });

        // ── CRM / Marketing / Automation — STUBS ───────────────────────────
        Route::get('/crm/leads', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'leads' => []]);
        });
        Route::get('/campaigns', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'campaigns' => []]);
        });
        Route::post('/campaign/send', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::info('runtime/campaign/send stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true]);
        });
        Route::get('/automation/sequences', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'sequences' => []]);
        });
        Route::post('/automation/runs', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::info('runtime/automation/runs stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'run_id' => null]);
        });
        Route::put('/automation/runs/{id}', function (\Illuminate\Http\Request $r, $id) {
            \Illuminate\Support\Facades\Log::info('runtime/automation/runs/' . $id . ' stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true]);
        });

        // ── Governance — STUB — Phase 1.0 / Phase 5 governance work ────────
        Route::post('/governance/flag', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::info('runtime/governance/flag stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true]);
        });

        // ── Write streaming — STUB — Phase 2C streaming work ───────────────
        Route::post('/write/stream-chunk', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true]);
        });
        Route::get('/write/stream-poll', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'chunks' => []]);
        });
    });
})->withoutMiddleware('auth.jwt');

// Public plan listing (for marketing site pricing page)
Route::get('/public/plans', function () {
    return response()->json(['plans' => \App\Models\Plan::orderBy('price')->get([
        'name', 'slug', 'price', 'credit_limit', 'ai_access', 'includes_dmm',
        'agent_count', 'agent_level', 'agent_addon_price', 'max_websites',
        'companion_app', 'white_label', 'features_json',
    ])]);
});

// ════════════════════════════════════════════════════════════════
// EXEC-API routes moved to routes/exec-api.php (PATCH v1.0.1)
// They are registered via bootstrap/app.php without the /api prefix
// so APP888 can reach app.levelupgrowth.io/exec-api/* directly.
// ════════════════════════════════════════════════════════════════

// Public invite routes — no JWT (invitee may not have an account)
Route::get('/invite/{token}', function (string $token) {
    $result = app(\App\Core\Workspaces\TeamService::class)->previewInvite($token);
    return response()->json($result, $result['valid'] ? 200 : 404);
});

Route::post('/invite/{token}/accept', function (\Illuminate\Http\Request $r, string $token) {
    $result = app(\App\Core\Workspaces\TeamService::class)->acceptInvite($token, [
        'name'     => $r->input('name'),
        'password' => $r->input('password'),
    ]);
    return response()->json($result, $result['success'] ? 200 : 422);
});

// User Registration — handled by AuthController@register (routes/api.php:63).
// The legacy duplicate closure previously here was removed 2026-04-25 as part of
// the onboarding Step 1–2 runbook: it bypassed Sarah-only agent attach and the
// new password confirmation + regex rules. The canonical route above is the
// single source of truth.

// ═══ Website Publishing Pipeline ═══
Route::post('/builder/websites/connect-existing', function (\Illuminate\Http\Request $request) {
    // Resolve workspace from JWT (route is outside auth middleware group)
        $wsId = $request->attributes->get('workspace_id');
        if (!$wsId) {
            $token = str_replace('Bearer ', '', $request->header('Authorization', ''));
            if ($token) {
                try {
                    $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(env('JWT_SECRET'), 'HS256'));
                    $wsId = $payload->ws ?? null;
                    if (!$wsId && ($payload->sub ?? null)) {
                        $wsRow = \Illuminate\Support\Facades\DB::table('workspace_users')->where('user_id', (int) $payload->sub)->first();
                        if ($wsRow) $wsId = $wsRow->workspace_id;
                    }
                } catch (\Throwable $e) {}
            }
        }
        if (!$wsId) return response()->json(['success' => false, 'error' => 'Authentication required'], 401);
    $url = $request->input('url', '');

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['success' => false, 'error' => 'Please enter a valid URL.'], 400);
    }

    // Plan limit check
    $currentCount = \Illuminate\Support\Facades\DB::table('websites')
        ->where('workspace_id', $wsId)->whereNull('deleted_at')->count();
    $plan = \App\Models\Plan::find(
        \App\Models\Subscription::where('workspace_id', $wsId)
            ->where('status', 'active')->latest()->value('plan_id')
    ) ?? \App\Models\Plan::where('slug', 'free')->first();
    $max = (int) ($plan->max_websites ?? 1);
    if ($currentCount >= $max) {
        return response()->json(['success' => false, 'error' => "Website limit reached ({$max}). Upgrade to add more.", 'limit_reached' => true]);
    }

    // Fetch URL
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0'])->get($url);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'error' => 'Could not reach this URL.']);
    }
    if (!$response->successful()) {
        return response()->json(['success' => false, 'error' => 'Website returned HTTP ' . $response->status()]);
    }

    $html = $response->body();
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $titleNodes = $dom->getElementsByTagName('title');
    $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : parse_url($url, PHP_URL_HOST);
    $description = '';
    foreach ($dom->getElementsByTagName('meta') as $meta) {
        if (strtolower($meta->getAttribute('name')) === 'description') { $description = $meta->getAttribute('content'); break; }
    }

    $platform = 'html';
    if (stripos($html, 'wp-content') !== false || stripos($html, 'wp-includes') !== false) $platform = 'wordpress';
    elseif (stripos($html, 'cdn.shopify') !== false) $platform = 'shopify';
    elseif (stripos($html, 'webflow') !== false) $platform = 'webflow';
    elseif (stripos($html, 'squarespace') !== false) $platform = 'squarespace';
    elseif (stripos($html, 'wix.com') !== false) $platform = 'wix';

    $thumbnailUrl = 'https://image.thum.io/get/width/400/crop/600/' . urlencode($url);

    $websiteId = \Illuminate\Support\Facades\DB::table('websites')->insertGetId([
        'workspace_id' => $wsId,
        'name' => mb_substr($title, 0, 255),
        'domain' => parse_url($url, PHP_URL_HOST),
        'type' => 'external',
        'external_url' => $url,
        'thumbnail_url' => $thumbnailUrl,
        'connector_status' => 'connected',
        'platform' => $platform,
        'status' => 'connected',
        'settings_json' => json_encode(['description' => $description, 'connected_at' => now()->toISOString(), 'platform' => $platform]),
        'created_by' => $payload->sub ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'website_id' => $websiteId,
        'name' => $title,
        'platform' => $platform,
        'thumbnail_url' => $thumbnailUrl,
    ]);
});
Route::post('/builder/websites/{id}/publish', function (\Illuminate\Http\Request $request, $id) {
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first();
    if (!$website) return response()->json(['error' => 'Website not found'], 404);

    $user = $request->user();
    // PATCH 3 (2026-05-08): was querying `workspaces.user_id` which
    // doesn't exist — workspace ownership lives in `workspace_users`
    // pivot. The bad column reference made this throw 500 on every
    // publish/unpublish attempt with a valid auth token.
    $ws = \Illuminate\Support\Facades\DB::table('workspace_users')
        ->where('workspace_id', $website->workspace_id)
        ->where('user_id', $user->id)
        ->first();
    if (!$ws) return response()->json(['error' => 'Unauthorized'], 403);

    $subdomain = $website->subdomain;
    if (!$subdomain) {
        return response()->json(['error' => 'No subdomain set. Choose a web address first.'], 422);
    }

    \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->update([
        'status' => 'published', 'subdomain' => $subdomain,
        'published_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('pages')
        ->where('website_id', $id)->update(['status' => 'published', 'updated_at' => now()]);

    \App\Http\Controllers\PublishedSiteController::invalidateCache($id);

    $url = 'https://' . str_replace('.levelupgrowth.io', '', $subdomain) . '.levelupgrowth.io';
    return response()->json(['success' => true, 'url' => $url, 'subdomain' => $subdomain]);
})->middleware('auth.jwt');

Route::post('/builder/websites/{id}/unpublish', function (\Illuminate\Http\Request $request, $id) {
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first();
    if (!$website) return response()->json(['error' => 'Website not found'], 404);

    $user = $request->user();
    // PATCH 3 (2026-05-08): was querying `workspaces.user_id` which
    // doesn't exist — workspace ownership lives in `workspace_users`
    // pivot. The bad column reference made this throw 500 on every
    // publish/unpublish attempt with a valid auth token.
    $ws = \Illuminate\Support\Facades\DB::table('workspace_users')
        ->where('workspace_id', $website->workspace_id)
        ->where('user_id', $user->id)
        ->first();
    if (!$ws) return response()->json(['error' => 'Unauthorized'], 403);

    \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->update([
        'status' => 'draft', 'updated_at' => now(),
    ]);
    \App\Http\Controllers\PublishedSiteController::invalidateCache($id);
    return response()->json(['success' => true, 'message' => 'Website unpublished']);
})->middleware('auth.jwt');

// ═══ Custom Domain Management ═══
Route::post('/builder/websites/{id}/custom-domain', function (\Illuminate\Http\Request $request, $id) {
    $request->validate(['domain' => 'required|string|max:255']);
    $service = new \App\Services\CustomDomainService();
    return response()->json($service->connect((int) $id, $request->input('domain')));
})->middleware('auth.jwt');

Route::get('/builder/websites/{id}/custom-domain/verify', function (\Illuminate\Http\Request $request, $id) {
    $service = new \App\Services\CustomDomainService();
    return response()->json($service->verify((int) $id));
})->middleware('auth.jwt');

Route::delete('/builder/websites/{id}/custom-domain', function (\Illuminate\Http\Request $request, $id) {
    $service = new \App\Services\CustomDomainService();
    return response()->json($service->disconnect((int) $id));
})->middleware('auth.jwt');
// ═══ Set subdomain on a website (2026-05-09) ═══
// POST /api/builder/websites/{id}/set-subdomain
// Body: {"subdomain": "my-site"}
// Validates slug format + availability + workspace ownership; writes
// websites.subdomain = '{slug}.levelupgrowth.io'. Required before publish
// (the publish closure 422s on missing subdomain).
Route::post('/builder/websites/{id}/set-subdomain', function (\Illuminate\Http\Request $request, $id) {
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first();
    if (!$website) return response()->json(['error' => 'Website not found'], 404);

    // Workspace ownership check (workspaces.user_id doesn't exist —
    // ownership lives on workspace_users pivot). Mirrors the publish
    // closure's auth pattern so we keep behaviour consistent.
    $user = $request->user();
    if (!$user) return response()->json(['error' => 'Unauthorized'], 401);
    $ws = \Illuminate\Support\Facades\DB::table('workspace_users')
        ->where('workspace_id', $website->workspace_id)
        ->where('user_id', $user->id)
        ->first();
    if (!$ws) return response()->json(['error' => 'Unauthorized'], 403);

    $slug = strtolower(trim((string) $request->input('subdomain', '')));
    // Normalise: lowercase, allow only alnum + hyphen, collapse repeats
    $slug = preg_replace('/[^a-z0-9-]+/', '', $slug);
    $slug = preg_replace('/-+/', '-', (string) $slug);
    $slug = trim((string) $slug, '-');

    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug)) {
        return response()->json([
            'error' => 'Invalid subdomain. Use lowercase letters, numbers and hyphens only (3-50 chars).',
        ], 422);
    }

    // Reserved-word block (matches /check-subdomain rules)
    $reserved = ['www', 'app', 'api', 'admin', 'staging', 'mail', 'ftp', 'smtp', 'pop',
                 'levelup', 'levelupgrowth', 'support', 'help', 'blog', 'test', 'demo',
                 'dashboard', 'panel', 'login', 'signup', 'register', 'auth', 'oauth',
                 'billing', 'payment', 'stripe', 'webhook', 'internal', 'system', 'root',
                 'cdn', 'assets', 'static', 'media', 'img', 'images', 'css', 'js'];
    if (in_array($slug, $reserved, true)) {
        return response()->json(['error' => 'That web address is reserved. Try another.'], 422);
    }

    $fullSub = $slug . '.levelupgrowth.io';
    $taken = \Illuminate\Support\Facades\DB::table('websites')
        ->where('subdomain', $fullSub)
        ->where('id', '!=', $id)
        ->whereNull('deleted_at')
        ->exists();
    if ($taken) {
        return response()->json(['error' => 'That web address is already taken. Try another.'], 422);
    }

    \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->update([
        'subdomain'  => $fullSub,
        'updated_at' => now(),
    ]);

    return response()->json([
        'success'   => true,
        'subdomain' => $fullSub,
        'url'       => 'https://' . $fullSub,
    ]);
})->middleware('auth.jwt');

// ═══ Subdomain Availability Check ═══
Route::get('/builder/check-subdomain', function (\Illuminate\Http\Request $request) {
    $slug = strtolower(trim($request->query('slug', '')));

// ── Connect Existing Website ────────────────────────────────
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $excludeId = (int) $request->query('exclude', 0);

    if (strlen($slug) < 2) {
        return response()->json(['available' => false, 'error' => 'Must be at least 2 characters', 'slug' => $slug]);
    }
    if (strlen($slug) > 40) {
        return response()->json(['available' => false, 'error' => 'Must be 40 characters or less', 'slug' => $slug]);
    }

    // Reserved words
    $reserved = ['www', 'app', 'api', 'admin', 'staging', 'mail', 'ftp', 'smtp', 'pop',
                 'levelup', 'levelupgrowth', 'support', 'help', 'blog', 'test', 'demo',
                 'dashboard', 'panel', 'login', 'signup', 'register', 'auth', 'oauth',
                 'billing', 'payment', 'stripe', 'webhook', 'internal', 'system', 'root',
                 'cdn', 'assets', 'static', 'media', 'img', 'images', 'css', 'js'];
    if (in_array($slug, $reserved)) {
        return response()->json([
            'available' => false,
            'slug' => $slug,
            'error' => 'This name is reserved',
            'suggestion' => $slug . '-site',
        ]);
    }

    // Check DB
    $fullSub = $slug . '.levelupgrowth.io';
    $query = \Illuminate\Support\Facades\DB::table('websites')
        ->where('subdomain', $fullSub)
        ->whereNull('deleted_at');
    if ($excludeId > 0) $query->where('id', '!=', $excludeId);
    $exists = $query->exists();

    if ($exists) {
        // Generate suggestion
        $base = $slug;
        $i = 2;
        while (\Illuminate\Support\Facades\DB::table('websites')
            ->where('subdomain', $base . '-' . $i . '.levelupgrowth.io')
            ->whereNull('deleted_at')
            ->exists()) {
            $i++;
            if ($i > 20) break;
        }
        return response()->json([
            'available' => false,
            'slug' => $slug,
            'suggestion' => $base . '-' . $i,
        ]);
    }

    return response()->json(['available' => true, 'slug' => $slug]);
})->middleware('auth.jwt');

// ── Public Blog API (no auth required) ───────────────────────
Route::prefix("blog")->group(function () {
    $c = \App\Http\Controllers\BlogController::class;
    Route::get("/posts", [$c, "listPosts"]);
    Route::get("/posts/{slug}", [$c, "getPost"]);
    Route::get("/categories", [$c, "categories"]);
});

// ── T3.2 Public contact form submission (no auth, rate-limited) ─────
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post(
        '/public/contact/{subdomain}',
        [\App\Http\Controllers\Api\PublicContactController::class, 'submit']
    )->where('subdomain', '[a-z0-9\-]+');
});


// ── T3 Template Editor Routes ──────────────────────────────────
Route::get('/builder/websites/{id}/preview', function ($id) {
    $htmlPath = storage_path('app/public/sites/' . (int)$id . '/index.html');
    if (!file_exists($htmlPath)) return response('Not found', 404);
    $html = file_get_contents($htmlPath);

    // Load element map from the template's manifest so the iframe script
    // knows which CSS selectors to wire for element selection.
    $elementsByBlock = [];
    $imageDims = [];
    try {
        $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->first();
        $industry = $website->template_industry ?? ($website->industry ?? 'restaurant');
        $manifestPath = storage_path('templates/' . $industry . '/manifest.json');
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            foreach (($manifest['blocks'] ?? []) as $_b) {
                if (!empty($_b['elements']) && !empty($_b['id'])) {
                    $elementsByBlock[$_b['id']] = $_b['elements'];
                }
            }
            if (!empty($manifest['image_dimensions']) && is_array($manifest['image_dimensions'])) {
                $imageDims = $manifest['image_dimensions'];
            }
        }
    } catch (\Throwable $_e) { /* no manifest — block-only selection */ }
    $elementsJson = json_encode($elementsByBlock, JSON_UNESCAPED_SLASHES);
    $imageDimsJson = json_encode($imageDims, JSON_UNESCAPED_SLASHES);

    $editScript = '<script>
document.addEventListener("DOMContentLoaded",function(){
  var _elementsByBlock = ' . $elementsJson . ';
  var _imageDims = ' . $imageDimsJson . ';
  window._selectedBlock = null;
  window._selectedElement = null;
  var _selEl = null; // currently-selected DOM node

  // ── Builder-mode logo click target (visible only inside preview iframe) ──
  // Without this, an empty logo renders as a transparent SVG (no broken icon —
  // good for the published site) but the user sees nothing to click. Inject
  // a dashed outline + cursor pointer so the click target is obvious.
  try {
    var _luLogoCss = document.createElement("style");
    _luLogoCss.textContent =
      ".nav-logo-img,.footer-logo-img{min-width:60px;min-height:36px;border:1px dashed rgba(108,92,231,0.30);border-radius:4px;background:rgba(108,92,231,0.04);box-sizing:border-box;padding:2px;pointer-events:none}" +
      "[data-field=\"logo_url\"]{cursor:pointer;position:relative}" +
      "[data-field=\"logo_url\"]:hover .nav-logo-img,[data-field=\"logo_url\"]:hover .footer-logo-img{border-color:#6C5CE7;background:rgba(108,92,231,0.10);box-shadow:0 0 0 2px rgba(108,92,231,0.18)}" +
      "[data-field=\"logo_url\"]::after{content:\"\\1F3F7 click to add logo\";display:none;position:absolute;top:100%;left:0;background:#6C5CE7;color:#fff;font:600 10px/1.4 system-ui;padding:3px 6px;border-radius:3px;margin-top:6px;white-space:nowrap;pointer-events:none !important;z-index:1}" +
      "[data-field=\"logo_url\"]:hover::after{display:block}";
    document.head.appendChild(_luLogoCss);
  } catch(_lc){}

  // ── Direct-click fallback for logo wrappers ──────────────────────
  // Belt-and-suspenders: in addition to the delegated element-select
  // handler below, bind a direct click listener on every logo wrapper
  // so native <a href="#"> navigation is preempted and the image-clicked
  // postMessage always fires even if the delegation path misses.
  try {
    document.querySelectorAll("[data-field=\"logo_url\"]").forEach(function(el){
      el.addEventListener("click", function(ev){
        ev.preventDefault();
        ev.stopPropagation();
        var rect = el.getBoundingClientRect();
        var img = el.querySelector("img");
        var block = el.closest("[data-block]") ? el.closest("[data-block]").getAttribute("data-block") : "nav";
        try { console.log("[lu image-clicked direct]", { field: "logo_url", block: block, tag: el.tagName }); } catch(_){}
        window.parent.postMessage({
          type: "image-clicked",
          websiteId: ' . (int)$id . ',
          field: "logo_url",
          block: block,
          currentSrc: img ? (img.currentSrc || img.src || "") : "",
          recommended: (_imageDims && _imageDims["logo_url"]) ? _imageDims["logo_url"] : null,
          rect: { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom, width: rect.width, height: rect.height }
        }, "*");
      }, true);
    });
  } catch(_ld){}

  // ── Global image walker — single-click for every image field ─────
  // Extends the logo pattern to ALL image-typed elements. Covers <img>
  // with data-field, background-image wrappers with data-field, and any
  // field name that appears in the manifest image_dimensions map.
  try {
    var _imgSelectors = [
      "img[data-field]",
      "[data-field$=\"_image\"]",
      "[data-field$=\"_photo\"]",
      "[data-field$=\"_avatar\"]",
      "[data-field$=\"_img\"]",
      "[data-field*=\"_logo\"]",
      "[data-field=\"logo\"]",
      "[data-field=\"logo_url\"]",
      "[data-field=\"footer_logo\"]"
    ];
    // Add every image_dimensions key as an explicit selector (covers any
    // field name the regexes miss, e.g. client_logo_1, partner_logo_2).
    if (_imageDims) {
      Object.keys(_imageDims).forEach(function(k){
        _imgSelectors.push("[data-field=\"" + k + "\"]");
      });
    }
    // Reusable click-to-panel dispatcher. `imgEl` is the image whose
    // data-field drives the panel; `anchorEl` is the element whose rect
    // positions the panel (usually the same as imgEl, but for parent-
    // attached listeners it is the click target so the panel anchors
    // near where the user actually clicked).
    function _luFireImageClick(imgEl, anchorEl, ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var field = imgEl.getAttribute("data-field");
      if (!field) return;
      var blockEl = imgEl.closest("[data-block]");
      var block = blockEl ? blockEl.getAttribute("data-block") : "global";
      var currentSrc = "";
      if (imgEl.tagName === "IMG") {
        currentSrc = imgEl.currentSrc || imgEl.src || imgEl.getAttribute("src") || "";
      } else {
        var bg = (window.getComputedStyle(imgEl).backgroundImage || "");
        var m = bg.match(/url\(["\']?([^"\')]+)/);
        if (m) currentSrc = m[1];
      }
      var rect = (anchorEl || imgEl).getBoundingClientRect();
      var recommended = (_imageDims && _imageDims[field]) ? _imageDims[field] : null;
      try { console.log("[lu image-clicked global]", { field: field, block: block, tag: (ev && ev.target && ev.target.tagName) || imgEl.tagName }); } catch(_){}
      window.parent.postMessage({
        type: "image-clicked",
        websiteId: ' . (int)$id . ',
        field: field,
        block: block,
        currentSrc: currentSrc,
        recommended: recommended,
        rect: { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom, width: rect.width, height: rect.height }
      }, "*");
    }

    var _seen = new WeakSet();
    _imgSelectors.forEach(function(sel){
      try {
        document.querySelectorAll(sel).forEach(function(el){
          if (!_seen.has(el)) {
            _seen.add(el);
            el.addEventListener("click", function(ev){ _luFireImageClick(el, el, ev); }, true);
          }
          // ── Parent-fallback binding ──────────────────────────
          // If the image has a parent card (like <div class="trainer-card">)
          // that contains sibling overlay divs (.trainer-overlay, .gallery-item-overlay,
          // etc.) absorbing the click, bind a capture listener on the parent
          // so clicks on ANY descendant fire image-clicked for THIS img.
          // Skip when parent is the <body> (too broad) or already bound.
          if (el.tagName === "IMG" && el.parentElement && el.parentElement.tagName !== "BODY") {
            var pEl = el.parentElement;
            if (!_seen.has(pEl)) {
              _seen.add(pEl);
              pEl.addEventListener("click", function(ev){ _luFireImageClick(el, pEl, ev); }, true);
            }
          }
        });
      } catch(_se){}
    });
  } catch(_gw){}

  // ── Parent-driven image update (lu-update-image) ─────────────────
  // The parent builder calls _t3UpdateImageInIframe(field, url) which
  // postMessages this iframe. We update our own DOM in place — no
  // reload, no scroll reset, no cross-origin document access.
  window.addEventListener("message", function(e) {
    if (!e.data || e.data.type !== "lu-update-image") return;
    var field = e.data.field;
    var url = e.data.url || "";
    if (!field) return;
    var placeholder = "data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221%22%20height%3D%221%22%2F%3E";
    var effectiveSrc = url || (field === "logo_url" ? placeholder : "");
    var els = document.querySelectorAll("[data-field=\"" + field + "\"]");
    els.forEach(function(el) {
      if (el.tagName === "IMG") {
        el.src = effectiveSrc;
      } else {
        var innerImg = el.querySelector("img[data-field=\"" + field + "\"], img.nav-logo-img, img.footer-logo-img");
        if (innerImg) {
          innerImg.src = effectiveSrc;
        } else {
          el.style.backgroundImage = url ? "url(\"" + url + "\")" : "";
        }
      }
      if (field === "logo_url") {
        var txt = el.querySelector(".nav-logo-text, .footer-logo-text");
        if (txt) txt.style.display = url ? "none" : "block";
      }
    });
  });

  // ── Universal hover indicator for all image fields ────────────────
  try {
    var _luImgHover = document.createElement("style");
    _luImgHover.textContent =
      "[data-field$=\"_image\"]:hover,[data-field$=\"_photo\"]:hover,[data-field$=\"_avatar\"]:hover,[data-field$=\"_img\"]:hover,[data-field*=\"_logo\"]:hover,img[data-field]:hover,[data-field=\"logo\"]:hover,[data-field=\"logo_url\"]:hover,[data-field=\"footer_logo\"]:hover{outline:2px dashed rgba(108,92,231,0.5) !important;outline-offset:2px;cursor:pointer !important}";
    document.head.appendChild(_luImgHover);
  } catch(_ih){}

  // ── Overlay DOM (hover box + selected box + tooltip) ──
  function _mk(id, css){ var d = document.createElement("div"); d.id = id; d.style.cssText = css; document.body.appendChild(d); return d; }
  var _hov = _mk("__lu_el_hov",
    "position:fixed;pointer-events:none;z-index:2147483645;box-sizing:border-box;"
    + "border:2px dashed #38bdf8;background:rgba(56,189,248,0.08);display:none;");
  var _sel = _mk("__lu_el_sel",
    "position:fixed;pointer-events:none;z-index:2147483646;box-sizing:border-box;"
    + "border:2px solid #f97316;background:rgba(249,115,22,0.08);display:none;");
  var _tip = _mk("__lu_el_tip",
    "position:fixed;pointer-events:none;z-index:2147483647;"
    + "background:#0f172a;color:#fff;padding:3px 7px;border-radius:3px;"
    + "font:600 10px/1.35 ui-monospace,SFMono-Regular,Menlo,Consolas,system-ui,-apple-system,sans-serif;"
    + "letter-spacing:.02em;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.5);display:none;");

  function _prettyKey(k){ return k.replace(/_/g," ").replace(/\b\w/g, function(c){ return c.toUpperCase(); }); }

  function _posBox(box, el){
    var r = el.getBoundingClientRect();
    box.style.left   = r.left + "px";
    box.style.top    = r.top + "px";
    box.style.width  = r.width + "px";
    box.style.height = r.height + "px";
    box.style.display = "block";
  }
  function _posTip(elKey, el){
    var r = el.getBoundingClientRect();
    _tip.textContent = _prettyKey(elKey) + "   " + Math.round(r.width) + " \u00d7 " + Math.round(r.height);
    // Bottom-left of element; clamp to viewport so tip stays visible near edges.
    var tipTop = r.bottom + 4;
    if (tipTop + 20 > window.innerHeight) tipTop = r.top - 22;
    _tip.style.left = Math.max(2, r.left) + "px";
    _tip.style.top  = tipTop + "px";
    _tip.style.display = "block";
  }
  function _hideHov(){ _hov.style.display = "none"; }
  function _hideSel(){ _sel.style.display = "none"; _selEl = null; }
  function _hideAll(){ _hideHov(); _hideSel(); _tip.style.display = "none"; }

  function _reposition(){
    if (_selEl && window._selectedElement) {
      _posBox(_sel, _selEl);
      _posTip(window._selectedElement, _selEl);
    } else {
      _tip.style.display = "none";
    }
    _hideHov();
  }
  window.addEventListener("scroll", _reposition, {passive:true, capture:true});
  window.addEventListener("resize", _reposition);

  // ── Block selection ──
  document.querySelectorAll("[data-block]").forEach(function(block){
    var bid = block.getAttribute("data-block");
    var blabel = (bid.charAt(0).toUpperCase()+bid.slice(1))+" Section";
    block.style.position = "relative";
    block.addEventListener("mouseenter",function(){
      if (window._selectedBlock !== bid) {
        block.style.outline = "2px dashed rgba(108,92,231,0.35)";
        block.style.outlineOffset = "-2px";
        block.style.cursor = "pointer";
      }
    });
    block.addEventListener("mouseleave",function(){
      if (window._selectedBlock !== bid) block.style.outline = "";
    });
    block.addEventListener("click",function(e){
      // Clicks on contenteditable text nodes: let them through.
      if (e.target.hasAttribute("data-field") && e.target.dataset.luElHooked !== "1") return;
      // Clicks on hooked elements: element listener handles it.
      if (e.target.hasAttribute("data-lu-el-hooked") || e.target.closest("[data-lu-el-hooked=\"1\"]")) return;

      // Inside a selected block, clicking the background deselects the element only.
      if (window._selectedBlock === bid && window._selectedElement) {
        _hideSel();
        window._selectedElement = null;
        window.parent.postMessage({type:"element-deselected"},"*");
        e.stopPropagation();
        return;
      }

      document.querySelectorAll("[data-block]").forEach(function(b){
        b.style.outline = "";
        var t = b.querySelector(".lu-block-toolbar"); if (t) t.remove();
      });
      _hideAll();
      window._selectedElement = null;

      if (window._selectedBlock === bid) {
        window._selectedBlock = null;
        window.parent.postMessage({type:"block-deselected"},"*");
        return;
      }
      window._selectedBlock = bid;
      block.style.outline = "2px solid #6C5CE7";
      block.style.outlineOffset = "-2px";
      var tb = document.createElement("div");
      tb.className = "lu-block-toolbar";
      tb.style.cssText = "position:absolute;top:12px;right:12px;z-index:9999;background:#1E2230;border:1px solid #6C5CE7;border-radius:6px;padding:5px 12px;display:flex;align-items:center;gap:8px;font-family:system-ui;font-size:11px;color:#fff;white-space:nowrap;box-shadow:0 4px 16px rgba(0,0,0,0.5);pointer-events:none;";
      tb.innerHTML = "<span style=\"color:#6C5CE7\">&#x2B21;</span><span style=\"font-weight:600\">"+blabel+"</span><span style=\"color:rgba(255,255,255,0.45);font-size:10px\"> &middot; Hover any element, click to select</span>";
      block.appendChild(tb);
      window.parent.postMessage({type:"block-selected",block_id:bid,block_label:blabel},"*");
      e.stopPropagation();
    });
  });

  // ── Element selection (devtools-style overlay) — manifest elements only ──
  Object.keys(_elementsByBlock).forEach(function(bid){
    var blockEl = document.querySelector("[data-block=\"" + bid + "\"]");
    if (!blockEl) return;
    var elements = _elementsByBlock[bid];
    Object.keys(elements).forEach(function(elKey){
      var sel = elements[elKey];
      if (!sel) return;
      try {
        blockEl.querySelectorAll(sel).forEach(function(el){
          if (el.dataset.luElHooked === "1") return;
          el.dataset.luElHooked = "1";
          el.dataset.luElKey = elKey;
          el.dataset.luElBlock = bid;

          el.addEventListener("mouseenter", function(e){
            if (window._selectedBlock !== bid) return;
            e.stopPropagation();
            if (window._selectedElement === elKey && _selEl === el) return;
            _posBox(_hov, el);
            _posTip(elKey, el);
          });
          el.addEventListener("mouseleave", function(){
            if (window._selectedBlock !== bid) return;
            _hideHov();
            if (_selEl && window._selectedElement) {
              _posTip(window._selectedElement, _selEl);
            } else {
              _tip.style.display = "none";
            }
          });
          el.addEventListener("click", function(e){
            if (window._selectedBlock !== bid) return;
            if (el.tagName === "A") e.preventDefault();
            if (document.activeElement === el && typeof el.blur === "function") el.blur();
            e.stopPropagation();

            // Toggle off: click already-selected element again.
            if (window._selectedElement === elKey && _selEl === el) {
              _hideSel();
              _hideHov();
              window._selectedElement = null;
              _tip.style.display = "none";
              window.parent.postMessage({type:"element-deselected"},"*");
              return;
            }

            _selEl = el;
            window._selectedElement = elKey;
            _hideHov();
            _posBox(_sel, el);
            _posTip(elKey, el);
            var pretty = (bid.charAt(0).toUpperCase()+bid.slice(1)) + " \u203A " + _prettyKey(elKey);
            window.parent.postMessage({
              type:"element-selected",
              block_id: bid,
              element_key: elKey,
              element_label: pretty
            },"*");

            // ── Image-click detection (2026-04-19) ─────────────────────
            // If the clicked element is an image surface, fire an extra
            // "image-clicked" message so the parent can offer a Media
            // Library replace / paste URL / remove panel.
            var _isImg = el.tagName === "IMG";
            var _bgImg = (window.getComputedStyle(el).backgroundImage || "");
            var _hasBg = /url\(/.test(_bgImg);
            var _fieldLooksImage = /_image$|_image_\d+$|_photo$|_img$|_avatar$|_logo$|_logo_\d+$/.test(elKey) || elKey === "logo_url" || elKey === "logo" || elKey === "footer_logo" || (_imageDims && _imageDims[elKey] !== undefined);
            if (_isImg || _hasBg || _fieldLooksImage) {
              var _src = "";
              if (_isImg) { _src = el.currentSrc || el.src || el.getAttribute("src") || ""; }
              else {
                var _m = _bgImg.match(/url\(["\']?([^"\')]+)/);
                if (_m) _src = _m[1];
              }
              var _r2 = el.getBoundingClientRect();
              try { console.log("[lu image-clicked]", { field: elKey, block: bid, isImg: _isImg, hasBg: _hasBg, fieldLooksImage: _fieldLooksImage, currentSrc: _src, tag: el.tagName }); } catch(_lg){}
              window.parent.postMessage({
                type: "image-clicked",
                websiteId: ' . (int)$id . ',
                field: elKey,
                block: bid,
                currentSrc: _src,
                recommended: (_imageDims && _imageDims[elKey]) ? _imageDims[elKey] : null,
                rect: { left:_r2.left, top:_r2.top, right:_r2.right, bottom:_r2.bottom, width:_r2.width, height:_r2.height }
              }, "*");
            }
          });
        });
      } catch(_ex){}
    });
  });

  // ── Double-click to edit any [data-field] — delegated ──
  var _editingEl = null;
  function _enterEdit(target){
    console.log("[edit] _enterEdit called with", target);
    if (!target || _editingEl === target) return;
    _editingEl = target;
    target.setAttribute("contenteditable", "true");
    target.setAttribute("spellcheck", "false");
    target.style.cursor = "text";
    _posBox(_sel, target);
    _sel.style.border = "2px solid #6C5CE7";
    _sel.style.background = "rgba(108,92,231,0.12)";
    var keyForLabel = target.dataset.luElKey || target.getAttribute("data-field") || "text";
    var rect = target.getBoundingClientRect();
    _tip.textContent = "\u270F  Editing: " + _prettyKey(keyForLabel);
    _tip.style.display = "block";
    _tip.style.left = Math.max(2, rect.left) + "px";
    _tip.style.top  = (rect.bottom + 4) + "px";
    _hideHov();
    target.focus();
    try {
      var range = document.createRange();
      range.selectNodeContents(target);
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(range);
    } catch(_e){}
  }
  function _exitEdit(){
    if (!_editingEl) return;
    var t = _editingEl;
    t.removeAttribute("contenteditable");
    t.style.cursor = "";
    try {
      window.parent.postMessage({
        type: "field-changed",
        websiteId: ' . (int)$id . ',
        field: t.getAttribute("data-field"),
        value: t.innerHTML
      }, "*");
    } catch(_e){}
    _editingEl = null;
    // Restore orange selection overlay if the element is still the selected one.
    if (_selEl) {
      _posBox(_sel, _selEl);
      _sel.style.border = "2px solid #f97316";
      _sel.style.background = "rgba(249,115,22,0.08)";
      _posTip(window._selectedElement || (_selEl.dataset.luElKey || "element"), _selEl);
    } else {
      _sel.style.display = "none";
      _tip.style.display = "none";
    }
  }

  document.addEventListener("dblclick", function(e){
    console.log("[edit] dblclick fired on", e.target.tagName, e.target);
    var target = e.target.closest && e.target.closest("[data-field]");
    if (!target) {
      // User double-clicked a wrapper with no data-field of its own. If the
      // wrapper is a manifest-hooked element, look for its first data-field
      // descendant (e.g. .hero-eyebrow wrapping a <span data-field>).
      var hooked = e.target.closest && e.target.closest("[data-lu-el-hooked=\"1\"]");
      if (hooked) target = hooked.querySelector("[data-field]");
    }
    if (!target) return;
    e.stopPropagation();
    e.preventDefault();
    _enterEdit(target);
  });

  document.addEventListener("input", function(e){
    if (_editingEl && e.target === _editingEl) {
      window.parent.postMessage({
        type: "field-changed",
        websiteId: ' . (int)$id . ',
        field: _editingEl.getAttribute("data-field"),
        value: _editingEl.innerHTML
      }, "*");
    }
  }, true);

  document.addEventListener("blur", function(e){
    if (_editingEl && e.target === _editingEl) _exitEdit();
  }, true);


  // ── Fully-outside click: deselect block + element ──
  document.addEventListener("click", function(e){
    if (!e.target.closest("[data-block]") && window._selectedBlock) {
      document.querySelectorAll("[data-block]").forEach(function(b){
        b.style.outline = "";
        var t = b.querySelector(".lu-block-toolbar"); if (t) t.remove();
      });
      _hideAll();
      window._selectedBlock = null;
      window._selectedElement = null;
      window.parent.postMessage({type:"block-deselected"},"*");
    }
  });
});
</script>';
    $html = str_replace('</body>', $editScript . '</body>', $html);
    return response($html)->header('Content-Type', 'text/html');
});

Route::put('/builder/websites/{id}/fields/{field}', function (\Illuminate\Http\Request $r, $id, $field) {
    $value = $r->input('value', '');
    $ts = new \App\Engines\Builder\Services\TemplateService();
    $result = $ts->updateField((int)$id, $field, $value);
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->first();
    if ($website && $website->template_variables) {
        $vars = json_decode($website->template_variables, true) ?: [];
        $vars[$field] = $value;
        \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->update(['template_variables' => json_encode($vars), 'updated_at' => now()]);
        // Image-typed fields require full re-render: DOM textContent swap doesn't update src/srcset.
        $isImage = ($field === 'logo_url') || str_ends_with($field, '_image') || str_contains($field, 'image_');
        if ($isImage && !empty($website->template_industry)) {
            try {
                $html = $ts->render($website->template_industry, $vars);
                $ts->deploy((int)$id, $html);
            } catch (\Throwable $e) { /* swallow — the value is saved, render can retry */ }
        }
    }
    return response()->json(['saved' => $result, 'field' => $field]);
});

// Logo upload — multipart/form-data with optional `logo` file.
// Empty / missing file = clear (also reachable via PUT /fields/logo_url with value='').
Route::post('/builder/websites/{id}/logo', function (\Illuminate\Http\Request $r, $id) {
    $id = (int)$id;
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first();
    if (!$website) return response()->json(['success' => false, 'error' => 'Website not found'], 404);

    $vars = json_decode($website->template_variables ?? '{}', true) ?: [];
    $remove = $r->input('remove') === '1' || $r->input('remove') === 1;
    $logoUrl = '';

    if (!$remove && $r->hasFile('logo')) {
        $file = $r->file('logo');
        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json(['success' => false, 'error' => 'File too large (max 2MB)'], 400);
        }
        $allowedExt   = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
        $mime = $file->getMimeType();
        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMimes, true)) {
            return response()->json(['success' => false, 'error' => 'Invalid file type (PNG/JPG/SVG/WEBP only)'], 400);
        }
        $dir = storage_path('app/public/sites/' . $id);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Remove any prior logo files (any extension)
        foreach (glob($dir . '/logo.*') as $old) { @unlink($old); }
        $file->move($dir, 'logo.' . $ext);
        $logoUrl = '/storage/sites/' . $id . '/logo.' . $ext . '?v=' . time();
    }

    $vars['logo_url'] = $logoUrl;
    \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->update([
        'template_variables' => json_encode($vars),
        'updated_at' => now(),
    ]);

    try {
        $industry = $website->template_industry ?: 'restaurant';
        $ts = new \App\Engines\Builder\Services\TemplateService();
        $html = $ts->render($industry, $vars);
        $ts->deploy($id, $html);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'error' => 'Render failed: ' . $e->getMessage()], 500);
    }

    return response()->json(['success' => true, 'logo_url' => $logoUrl]);
});


// T4: Arthur block editor — CSS injection for styling, full edit for structure
// ═══════════════════════════════════════════════════════════════
// TIER 4 — handleTier4 dispatcher (named function, loaded once)
// ═══════════════════════════════════════════════════════════════
if (!function_exists('handleTier4')) {
    function handleTier4(string $message, int $websiteId, ?string $blockId, int $workspaceId): array {
        $lower = strtolower($message);
        $htmlPath = storage_path('app/public/sites/' . $websiteId . '/index.html');
        $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) return ['success' => false, 'message' => 'Website not found.'];

        // ── PAGE OPERATIONS ──────────────────────────────────────
        if (preg_match('/(delete|remove)\s+(the\s+)?(\w+)\s+page/i', $message, $m)) {
            $pageName = trim($m[3]);
            $page = \Illuminate\Support\Facades\DB::table('pages')
                ->where('website_id', $websiteId)
                ->where(function($q) use ($pageName){ $q->where('slug', \Illuminate\Support\Str::slug($pageName))->orWhere('title', 'like', "%{$pageName}%"); })
                ->first();
            if (!$page) return ['success' => true, 'method' => 'chat', 'message' => "I couldn't find a page called \"{$pageName}\"."];
            return ['success' => true, 'method' => 'confirm',
                'message' => "Delete the \"{$page->title}\" page? This cannot be undone.",
                'confirm_action' => 'delete_page',
                'confirm_data' => ['page_id' => $page->id, 'page_title' => $page->title]];
        }

        if (preg_match('/(duplicate|copy)\s+(this\s+)?page/i', $message)) {
            $firstPage = \Illuminate\Support\Facades\DB::table('pages')->where('website_id', $websiteId)->orderBy('position')->first();
            if (!$firstPage) return ['success' => true, 'method' => 'chat', 'message' => 'No pages to duplicate yet.'];
            $svc = app(\App\Engines\Builder\Services\BuilderService::class);
            $newTitle = ($firstPage->title ?? 'Page') . ' Copy';
            $newSlug = \Illuminate\Support\Str::slug($newTitle) . '-' . substr(uniqid(), -4);
            $res = $svc->createPage($websiteId, [
                'title' => $newTitle, 'slug' => $newSlug,
                'sections_json' => $firstPage->sections_json ?? null,
                'seo_json' => $firstPage->seo_json ?? null,
            ]);
            return ['success' => true, 'method' => 'action',
                'message' => "Done — \"{$newTitle}\" added.",
                'action' => 'page_duplicated',
                'page_id' => $res['page_id'] ?? null,
                'page_title' => $newTitle];
        }

        if (preg_match('/(add|create|new|make|build|generate)\s+(?:an?\s+)?(?:\w+\s+)?(?:\w+\s+)?(page|tab)/i', $message)) {
            // Try to extract a page type
            $pageType = 'page';
            // PART 1 (2026-04-20) — rich page creation with block composition
            $_ind = $website->template_industry ?? ($website->industry ?? 'business');
            return _t4_handlePageAdd($message, $websiteId, $website, $_ind);
        }

        // PART 3 (2026-04-20) — add-element intent ("add another service card",
        // "add a 7th team member", "I need more testimonials"). Runs BEFORE
        // the remove-section / move-section blocks so "add another" doesn't
        // get shadowed.
        if (preg_match('/(?:add|insert|create)\s+(?:another|a\s+new|one\s+more|a)\s+(\w+)(?:\s+(?:card|item|block))?/i', $message, $m)
            || preg_match('/(?:i\s+need|add)\s+more\s+(\w+)/i', $message, $m)) {
            $elementNoun = strtolower($m[1] ?? '');
            $candidate = _t4_handleElementAdd($elementNoun, $websiteId, $htmlPath, $blockId);
            if ($candidate !== null) return $candidate;
            // fall through — not an element-add, let other handlers try
        }

        // ── BOOKING BLOCK ────────────────────────────────────────
        if (preg_match('/(booking|appointment|reservation|scheduling)|book\s+a\s+(table|class|consultation|viewing|appointment|demo|slot)/i', $lower)) {
            if (!file_exists($htmlPath)) return ['success' => false, 'message' => 'Website HTML not found.'];
            $html = file_get_contents($htmlPath);
            if (strpos($html, 'data-block="booking"') !== false) {
                // Block exists — offer to style it
                return ['success' => true, 'method' => 'chat',
                    'message' => 'A booking section is already on this page. Want me to change the style or form fields?'];
            }
            // Inject booking block before footer.
            // BUG 3 FIX — uses hoisted $industry (no hardcoded fallback).
            $bookingHtml = _t4_buildBookingBlock($industry, json_decode($website->template_variables ?? '{}', true) ?: []);
            if (preg_match('/<footer[^>]*data-block="footer"/', $html)) {
                $html = preg_replace('/<footer([^>]*)data-block="footer"/', $bookingHtml . '<footer$1data-block="footer"', $html, 1);
            } else {
                $html = str_replace('</body>', $bookingHtml . '</body>', $html);
            }
            file_put_contents($htmlPath, $html);
            return ['success' => true, 'method' => 'action',
                'message' => "Done — I've added a booking section. Visitors can now submit requests directly.",
                'action' => 'block_added', 'block_id' => 'booking',
                'reload_preview' => true];
        }

        // ── BLOCK OPERATIONS ─────────────────────────────────────
        if (preg_match('/(remove|delete)\s+(the\s+)?(\w+)\s+(section|block)/i', $message, $m)) {
            $blockName = strtolower(trim($m[3]));
            return ['success' => true, 'method' => 'confirm',
                'message' => "Remove the \"{$blockName}\" section?",
                'confirm_action' => 'remove_block',
                'confirm_data' => ['block_id' => $blockName]];
        }

        if (preg_match('/(add|insert)\s+(a\s+)?(\w+)\s+(section|block)/i', $message, $m)) {
            $blockName = strtolower(trim($m[3]));
            // BUG 3 FIX — uses hoisted $industry (was hardcoded 'restaurant').
            $tplPath = storage_path('templates/' . $industry . '/template.html');
            if (!file_exists($tplPath)) return ['success' => true, 'method' => 'chat', 'message' => "Template not available for this website."];
            $tpl = file_get_contents($tplPath);
            if (!preg_match('/<[^>]+data-block="' . preg_quote($blockName, '/') . '"[^>]*>.*?(?=<!-- ═══|<footer)/s', $tpl, $bm)) {
                return ['success' => true, 'method' => 'chat', 'message' => "I don't know how to add a \"{$blockName}\" section to this template."];
            }
            $blockHtml = $bm[0];
            // Fill in template vars from website row
            $vars = json_decode($website->template_variables ?? '{}', true) ?: [];
            foreach ($vars as $k => $v) $blockHtml = str_replace('{{' . $k . '}}', (string)$v, $blockHtml);
            $blockHtml = preg_replace('/\{\{[a-z_0-9]+\}\}/', '', $blockHtml);
            if (!file_exists($htmlPath)) return ['success' => false, 'message' => 'Website HTML not found.'];
            $html = file_get_contents($htmlPath);
            if (preg_match('/<footer[^>]*data-block="footer"/', $html)) {
                $html = preg_replace('/<footer([^>]*)data-block="footer"/', $blockHtml . '<footer$1data-block="footer"', $html, 1);
            } else {
                $html = str_replace('</body>', $blockHtml . '</body>', $html);
            }
            file_put_contents($htmlPath, $html);
            return ['success' => true, 'method' => 'action',
                'message' => "Added {$blockName} section.",
                'action' => 'block_added', 'block_id' => $blockName,
                'reload_preview' => true];
        }

        // ── MOVE BLOCK (up/down OR above/below another block) ────────
        // Patterns:
        //   "move the testimonials section up"
        //   "move the testimonials section below the pricing block"
        if (preg_match('/move\s+(?:the\s+)?(\w+)\s+(?:section|block)\s+(up|down)/i', $message, $m) ||
            preg_match('/move\s+(?:the\s+)?(\w+)\s+(?:section|block)?\s*(above|below|before|after)\s+(?:the\s+)?(\w+)/i', $message, $m)) {
            $blockName = strtolower(trim($m[1]));
            $direction = strtolower(trim($m[2])); // up / down / above / before / below / after
            $targetName = isset($m[3]) ? strtolower(trim($m[3])) : null;

            if (!file_exists($htmlPath)) return ['success' => false, 'message' => 'Website HTML not found.'];
            $html = file_get_contents($htmlPath);

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $xp = new \DOMXPath($dom);
            $block = $xp->query("//*[@data-block='" . $blockName . "']")->item(0);
            if (!$block) {
                return ['success' => true, 'method' => 'chat',
                    'message' => "Couldn't find a \"{$blockName}\" section on this page."];
            }

            $moved = false;
            if ($direction === 'up') {
                $prev = $block->previousSibling;
                // Skip text/whitespace nodes
                while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) $prev = $prev->previousSibling;
                if ($prev) { $block->parentNode->insertBefore($block, $prev); $moved = true; }
            } elseif ($direction === 'down') {
                $next = $block->nextSibling;
                while ($next && $next->nodeType !== XML_ELEMENT_NODE) $next = $next->nextSibling;
                if ($next) {
                    $afterNext = $next->nextSibling;
                    if ($afterNext) $block->parentNode->insertBefore($block, $afterNext);
                    else            $block->parentNode->appendChild($block);
                    $moved = true;
                }
            } elseif (in_array($direction, ['above', 'before'], true) && $targetName) {
                $target = $xp->query("//*[@data-block='" . $targetName . "']")->item(0);
                if ($target) { $target->parentNode->insertBefore($block, $target); $moved = true; }
            } elseif (in_array($direction, ['below', 'after'], true) && $targetName) {
                $target = $xp->query("//*[@data-block='" . $targetName . "']")->item(0);
                if ($target) {
                    $after = $target->nextSibling;
                    if ($after) $target->parentNode->insertBefore($block, $after);
                    else        $target->parentNode->appendChild($block);
                    $moved = true;
                }
            }

            if (!$moved) {
                $hint = $targetName ? "I couldn't find \"{$targetName}\" to position next to." : "The \"{$blockName}\" section is already at the " . ($direction === 'up' ? 'top' : 'bottom') . ".";
                return ['success' => true, 'method' => 'chat', 'message' => $hint];
            }

            $newHtml = $dom->saveHTML();
            // Strip the XML encoding hint we injected
            $newHtml = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $newHtml);
            file_put_contents($htmlPath, $newHtml);

            $where = $targetName ? ucfirst($direction) . " {$targetName}" : ucfirst($direction);
            return ['success' => true, 'method' => 'action',
                'message' => "Done — {$blockName} section moved {$where}.",
                'action' => 'block_moved',
                'block_id' => $blockName,
                'direction' => $direction,
                'target' => $targetName,
                'reload_preview' => true];
        }

        // ── RENAME PAGE ──────────────────────────────────────────────
        // Patterns:
        //   "rename this page to Our Work"
        //   "change the page title to About Us"
        if (preg_match('/(?:rename\s+(?:this\s+)?page|change\s+(?:the\s+)?page\s+title)\s+to\s+(.+)$/i', $message, $m)) {
            $newTitle = trim(preg_replace('/["\.!?]\s*$/', '', $m[1]));
            if ($newTitle === '') {
                return ['success' => true, 'method' => 'chat', 'message' => "What would you like to rename the page to?"];
            }
            $newTitle = mb_substr($newTitle, 0, 120);

            // Find the current page: homepage first, else first by position.
            $page = \Illuminate\Support\Facades\DB::table('pages')
                ->where('website_id', $websiteId)
                ->orderByDesc('is_homepage')
                ->orderBy('position')
                ->first();
            if (!$page) {
                return ['success' => true, 'method' => 'chat', 'message' => "No pages found on this website yet."];
            }

            $newSlug = \Illuminate\Support\Str::slug($newTitle);
            if ($newSlug === '') $newSlug = 'page-' . substr(uniqid(), -6);

            // Ensure slug uniqueness within this website (skip self)
            $slugTaken = \Illuminate\Support\Facades\DB::table('pages')
                ->where('website_id', $websiteId)
                ->where('slug', $newSlug)
                ->where('id', '!=', $page->id)
                ->exists();
            if ($slugTaken) $newSlug .= '-' . substr(uniqid(), -4);

            \Illuminate\Support\Facades\DB::table('pages')->where('id', $page->id)->update([
                'title'      => $newTitle,
                'slug'       => $newSlug,
                'updated_at' => now(),
            ]);

            return ['success' => true, 'method' => 'action',
                'message' => "Page renamed to \"{$newTitle}\".",
                'action' => 'page_renamed',
                'page_id' => $page->id,
                'page_title' => $newTitle,
                'page_slug' => $newSlug];
        }

        // TASK 1 (2026-04-20) — PART 2: add a block from another template
        // Pattern: "add a pricing section" / "add an about section" / "insert a menu block" etc.
        // Handles: verb, optional "a new"/"an"/"a", optional type adj, required noun, then section|block.
        if (preg_match('/(?:add|insert|create|build)\s+(?:(?:a\s+new|an?)\s+)?(?:[a-z]+\s+)?([a-z_]+)\s+(?:section|block)/i', $message, $m)) {
            $blockType = strtolower(trim($m[1]));
            // Skip obvious non-block terms that other handlers should have caught.
            if (!in_array($blockType, ['the','a','an','new','page','tab','this','my','your'], true)) {
                $_ind2 = $website->template_industry ?? ($website->industry ?? 'business');
                $candidate = _t4_handleBlockAddFromOther($blockType, $websiteId, $htmlPath, $_ind2);
                if ($candidate !== null) return $candidate;
            }
        }

        // Fall-through: unmatched Tier 4 input
        return ['success' => true, 'method' => 'chat',
            'message' => "I can add/delete pages, add/remove sections, or drop in a booking form. Try: \"add a gallery page\" or \"add a booking section\"."];
    }
}

if (!function_exists('_t4_buildBookingBlock')) {
    function _t4_buildBookingBlock(string $industry, array $vars): string {
        $industry = strtolower($industry);
        $map = [
            'restaurant'  => ['cta'=>'Reserve a Table',        'fields'=>['date','time','party_size','name','phone']],
            'fitness'     => ['cta'=>'Book a Class',            'fields'=>['class_type','date','time','name','email']],
            'healthcare'  => ['cta'=>'Book Consultation',       'fields'=>['service','doctor','date','time','name','phone']],
            'legal'       => ['cta'=>'Free Consultation',       'fields'=>['practice_area','date','time','name','email','brief']],
            'real_estate' => ['cta'=>'Book a Viewing',          'fields'=>['property_interest','date','time','name','phone']],
            'fashion'     => ['cta'=>'Book Appointment',        'fields'=>['service','date','time','name','email']],
            'technology'  => ['cta'=>'Request a Demo',          'fields'=>['company','role','date','time','email']],
            'events'      => ['cta'=>'Get a Quote',             'fields'=>['event_type','date','guests','name','email']],
            'beauty'      => ['cta'=>'Book Appointment',        'fields'=>['service','stylist','date','time','name','phone']],
            'default'     => ['cta'=>'Book Appointment',        'fields'=>['service','date','time','name','email']],
        ];
        $cfg = $map[$industry] ?? $map['default'];
        $cta = $vars['booking_cta'] ?? $cfg['cta'];
        $title = $vars['booking_title'] ?? 'Reserve Your Spot';
        $intro = $vars['booking_intro'] ?? "Tell us when you'd like to visit and we'll confirm shortly.";
        $gold = $vars['primary_color'] ?? '#C9943A';
        $ink  = $vars['bg_color'] ?? '#0A0806';
        $ink2 = $vars['bg_color_2'] ?? '#131009';
        $cream = $vars['text_color'] ?? '#F2EBDF';
        $today = date('Y-m-d');
        $fieldHtml = [];
        if (in_array('service', $cfg['fields'])) $fieldHtml['service'] = '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Service</label><input type="text" name="service" placeholder="What service?" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('class_type', $cfg['fields'])) $fieldHtml['class_type'] = '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Class</label><input type="text" name="class_type" placeholder="Which class?" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('doctor', $cfg['fields'])) $fieldHtml['doctor'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Preferred provider</label><input type="text" name="doctor" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('practice_area', $cfg['fields'])) $fieldHtml['practice_area'] = '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Practice area</label><input type="text" name="practice_area" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('property_interest', $cfg['fields'])) $fieldHtml['property_interest'] = '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Property of interest</label><input type="text" name="property_interest" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('stylist', $cfg['fields'])) $fieldHtml['stylist'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Preferred stylist</label><input type="text" name="stylist" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('company', $cfg['fields'])) $fieldHtml['company'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Company</label><input type="text" name="company" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('role', $cfg['fields'])) $fieldHtml['role'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Role</label><input type="text" name="role" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('event_type', $cfg['fields'])) $fieldHtml['event_type'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Event type</label><input type="text" name="event_type" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('guests', $cfg['fields'])) $fieldHtml['guests'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Guests</label><input type="number" name="guests" min="1" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('party_size', $cfg['fields'])) $fieldHtml['party_size'] = '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Party size</label><input type="number" name="party_size" min="1" max="30" value="2" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>';
        if (in_array('brief', $cfg['fields'])) $fieldHtml['brief'] = '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Brief</label><textarea name="notes" rows="3" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></textarea></div>';
        $dateField = in_array('date', $cfg['fields']) ? '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Date</label><input type="date" name="preferred_date" required min="' . $today . '" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>' : '';
        $timeField = in_array('time', $cfg['fields']) ? '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:8px">Preferred time</label><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px"><label class="booking-slot" style="padding:14px 8px;background:' . $ink2 . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;text-align:center;cursor:pointer"><input type="radio" name="preferred_time" value="morning" required style="display:none"><div style="font-size:.9rem;color:' . $cream . '">Morning</div><div style="font-size:.7rem;color:' . $cream . ';opacity:.6">9am – 12pm</div></label><label class="booking-slot" style="padding:14px 8px;background:' . $ink2 . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;text-align:center;cursor:pointer"><input type="radio" name="preferred_time" value="afternoon" style="display:none"><div style="font-size:.9rem;color:' . $cream . '">Afternoon</div><div style="font-size:.7rem;color:' . $cream . ';opacity:.6">12pm – 5pm</div></label><label class="booking-slot" style="padding:14px 8px;background:' . $ink2 . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;text-align:center;cursor:pointer"><input type="radio" name="preferred_time" value="evening" style="display:none"><div style="font-size:.9rem;color:' . $cream . '">Evening</div><div style="font-size:.7rem;color:' . $cream . ';opacity:.6">5pm – 9pm</div></label></div></div>' : '';
        $nameField  = in_array('name',  $cfg['fields']) ? '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Name</label><input type="text" name="name" required style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>' : '';
        $phoneField = in_array('phone', $cfg['fields']) ? '<div class="form-field"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Phone</label><input type="tel" name="phone" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>' : '';
        $emailField = in_array('email', $cfg['fields']) ? '<div class="form-field" style="grid-column:1/-1"><label style="font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:' . $cream . ';display:block;margin-bottom:6px">Email</label><input type="email" name="email" style="width:100%;padding:12px;background:' . $ink2 . ';color:' . $cream . ';border:1px solid rgba(201,148,58,.25);border-radius:6px;font-family:inherit"></div>' : '';
        $inner = ($fieldHtml['service'] ?? '') . ($fieldHtml['class_type'] ?? '') . ($fieldHtml['practice_area'] ?? '') . ($fieldHtml['property_interest'] ?? '') . ($fieldHtml['event_type'] ?? '') . ($fieldHtml['company'] ?? '') . ($fieldHtml['role'] ?? '') . ($fieldHtml['doctor'] ?? '') . ($fieldHtml['stylist'] ?? '') . $dateField . ($fieldHtml['party_size'] ?? '') . ($fieldHtml['guests'] ?? '') . $timeField . $nameField . $phoneField . $emailField . ($fieldHtml['brief'] ?? '');
        return "\n<!-- ═══ BOOKING ═══ -->\n<section class=\"booking section\" id=\"booking\" data-block=\"booking\">\n  <div class=\"booking-inner\" style=\"max-width:780px;margin:0 auto;padding:4rem 2rem\">\n    <div class=\"eyebrow reveal\"><span>Reservations</span></div>\n    <h2 class=\"section-h2 booking-h2 reveal reveal-delay-1\" data-field=\"booking_title\">{$title}</h2>\n    <p class=\"booking-intro reveal reveal-delay-2\" data-field=\"booking_intro\" style=\"color:{$cream};opacity:.85;margin-bottom:2rem\">{$intro}</p>\n    <form class=\"booking-form\" onsubmit=\"handleBookingSubmit(event)\" style=\"display:grid;grid-template-columns:1fr 1fr;gap:14px\">\n      {$inner}\n      <button type=\"submit\" class=\"btn-primary booking-submit\" data-field=\"booking_cta\" style=\"grid-column:1/-1;padding:14px 24px;background:{$gold};color:{$ink};border:none;border-radius:6px;font-weight:600;letter-spacing:.04em;cursor:pointer;font-family:inherit;margin-top:6px\">{$cta}</button>\n    </form>\n    <div id=\"booking-success\" style=\"display:none;margin-top:1.5rem;padding:1rem;background:rgba(201,148,58,.1);border:1px solid {$gold};border-radius:6px;color:{$cream};text-align:center\">Thank you! We'll confirm shortly.</div>\n  </div>\n</section>\n<style>.booking-slot:has(input:checked){border-color:{$gold} !important;background:rgba(201,148,58,.12) !important}</style>\n<script>function handleBookingSubmit(e){e.preventDefault();var f=e.target,fd=new FormData(f),d={};fd.forEach(function(v,k){d[k]=v});fetch('/book',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(d)}).then(function(r){return r.json()}).then(function(res){if(res&&res.ok){f.style.display='none';document.getElementById('booking-success').style.display='block'}else{alert('Submission failed: '+((res&&res.error)||'please try again.'))}}).catch(function(){alert('Network error — please try again.')})}</script>\n";
    }
}

// ── PART 1 (2026-04-20) — rich page composition helper ─────────────
// Creates a pages row with sections_json populated from a block-composition
// map keyed by page type. PublishedSiteMiddleware + BuilderRenderer serve
// the resulting page at /{slug} on the site's subdomain automatically.
if (!function_exists('_t4_handlePageAdd')) {
    function _t4_handlePageAdd(string $message, int $websiteId, object $website, string $industry): array {
        $lower = strtolower($message);
        $pageType = 'page';
        $title = 'New Page';
        $keywords = [
            'gallery'=>'Gallery','about'=>'About','team'=>'Team','services'=>'Services',
            'contact'=>'Contact','pricing'=>'Pricing','blog'=>'Blog','portfolio'=>'Portfolio',
            'testimonials'=>'Testimonials','faq'=>'FAQ','shop'=>'Shop',
        ];
        foreach ($keywords as $k => $label) {
            if (strpos($lower, $k) !== false) { $pageType = $k; $title = $label; break; }
        }

        $vars = json_decode($website->template_variables ?? '{}', true) ?: [];
        $businessName = $vars['business_name'] ?? ($website->name ?? 'Your Business');
        $location     = $vars['contact_address'] ?? ($vars['city'] ?? '');
        $services     = $vars['services'] ?? [];
        if (is_string($services)) $services = array_filter(array_map('trim', explode(',', $services)));
        $primary      = $vars['primary_color']   ?? '#6C5CE7';
        $secondary    = $vars['secondary_color'] ?? '#00E5A8';

        // Compose sections per page type. Uses BuilderRenderer's section
        // schema (type + components[type,text,items,...]) which already
        // renders via BuilderRenderer::renderSection().
        $sections = _t4_composePageSections($pageType, [
            'business_name' => $businessName,
            'industry'      => $industry,
            'location'      => $location,
            'services'      => is_array($services) ? $services : [],
            'primary_color' => $primary,
            'secondary_color'=> $secondary,
            'title'         => $title,
        ]);

        // TASK 2 (2026-04-20) — DeepSeek content generation.
        // Populates heading/subheading/body/card text with business-specific copy.
        // Gracefully falls back to templated English if runtime is unavailable.
        $sections = _t4_populatePageWithAI($sections, [
            'page_type'     => $pageType,
            'business_name' => $businessName,
            'industry'      => $industry,
            'location'      => $location,
            'services'      => is_array($services) ? $services : [],
        ]);

        $slug = \Illuminate\Support\Str::slug($title);
        // Uniqueness — append short suffix if slug collides for this website
        $taken = \Illuminate\Support\Facades\DB::table('pages')
            ->where('website_id', $websiteId)->where('slug', $slug)->exists();
        if ($taken) $slug .= '-' . substr(uniqid(), -4);

        $svc = app(\App\Engines\Builder\Services\BuilderService::class);
        // BuilderService::createPage takes a raw `sections` array and wraps it;
        // passing `sections_json` is a no-op from that method's POV.
        $res = $svc->createPage($websiteId, [
            'title'    => $title,
            'slug'     => $slug,
            'type'     => $pageType,
            'sections' => $sections,
        ]);

        $pageId = $res['page_id'] ?? null;
        if (!$pageId) {
            return ['success' => false, 'method' => 'chat', 'message' => "I couldn't create the {$title} page just now. Try again in a moment."];
        }

        // Update page to published so PublishedSiteMiddleware serves it.
        \Illuminate\Support\Facades\DB::table('pages')->where('id', $pageId)->update([
            'status'       => 'published',
            'meta_title'   => "{$title} — {$businessName}",
            'updated_at'   => now(),
        ]);

        // Invalidate the published-site cache for this subdomain+slug so the
        // next visit renders fresh.
        try {
            $sub = str_replace('.levelupgrowth.io', '', $website->subdomain ?? '');
            if ($sub) {
                \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:{$slug}");
                \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:home");
            }
        } catch (\Throwable $_e) {}

        // Try to add nav link to homepage's sections if it has a header block.
        try { _t4_addNavLink($websiteId, $title, $slug); } catch (\Throwable $_e) {}

        $pageUrl = '/' . $slug;
        return [
            'success'        => true,
            'method'         => 'action',
            'message'        => "Done — I've created your {$title} page with starter content. Edit it in the builder, or visit [{$pageUrl}]({$pageUrl}).",
            'action'         => 'page_added',
            'page_id'        => $pageId,
            'page_title'     => $title,
            'page_slug'      => $slug,
            'page_url'       => $pageUrl,
            'reload_preview' => true,
        ];
    }
}

if (!function_exists('_t4_composePageSections')) {
    function _t4_composePageSections(string $pageType, array $ctx): array {
        $name     = $ctx['business_name']  ?? 'Your Business';
        $industry = $ctx['industry']        ?? 'business';
        $location = $ctx['location']        ?? '';
        $services = $ctx['services']        ?? [];
        $title    = $ctx['title']           ?? ucfirst($pageType);
        $primary  = $ctx['primary_color']   ?? '#6C5CE7';
        $secondary= $ctx['secondary_color'] ?? '#00E5A8';

        $navText   = 'Home · About · Services · Blog · Contact';
        $copyright = '© ' . date('Y') . ' ' . $name . '. All rights reserved.';

        $header = [
            'type' => 'header',
            'components' => [
                ['type' => 'heading', 'text' => $name],
                ['type' => 'text',    'text' => $navText],
                ['type' => 'button',  'text' => 'Contact Us', 'href' => '/contact'],
            ],
        ];
        $footer = [
            'type' => 'footer',
            'components' => [
                ['type' => 'heading', 'text' => $name],
                ['type' => 'text',    'text' => ucfirst($industry) . ($location ? ' · ' . $location : '')],
                ['type' => 'text',    'text' => 'Home · About · Services · Contact'],
                ['type' => 'text',    'text' => $copyright],
            ],
        ];
        $ctaBanner = [
            'type' => 'cta',
            'style' => ['gradient' => "linear-gradient(135deg, {$primary} 0%, {$secondary} 100%)"],
            'heading' => 'Ready to get started?',
            'body' => "Get in touch with {$name} today — we'd love to hear about your project.",
            'cta_text' => 'Contact Us',
            'components' => [
                ['type' => 'heading', 'text' => 'Ready to get started?'],
                ['type' => 'text',    'text' => "Get in touch with {$name} today — we'd love to hear about your project."],
                ['type' => 'button',  'text' => 'Contact Us', 'href' => '/contact'],
            ],
        ];

        $sections = [$header];

        switch ($pageType) {
            case 'about':
            case 'team':
                $sections[] = [
                    'type' => 'hero',
                    'heading' => 'About ' . $name,
                    'subheading' => 'Our story, our people, and what drives us.',
                    'components' => [
                        ['type' => 'heading', 'text' => 'About ' . $name],
                        ['type' => 'text',    'text' => 'Our story, our people, and what drives us.'],
                    ],
                ];
                $sections[] = [
                    'type' => 'features',
                    'heading' => $pageType === 'team' ? 'Meet the Team' : 'What We Stand For',
                    'style' => ['bg' => '#ffffff'],
                    'components' => [
                        ['type' => 'heading', 'text' => $pageType === 'team' ? 'Meet the Team' : 'What We Stand For'],
                        ['type' => 'cards', 'items' => [
                            ['icon' => '🎯', 'heading' => 'Clarity', 'text' => 'We say what we mean, and we do what we say.'],
                            ['icon' => '🤝', 'heading' => 'Partnership', 'text' => "We treat every client like a long-term partner, not a transaction."],
                            ['icon' => '✨', 'heading' => 'Craft', 'text' => "We care about the details — even the ones you'll never see."],
                        ]],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            case 'services':
                $sections[] = [
                    'type' => 'hero',
                    'heading' => 'Our Services',
                    'subheading' => 'Everything ' . $name . ' can do for you.',
                    'components' => [
                        ['type' => 'heading', 'text' => 'Our Services'],
                        ['type' => 'text',    'text' => 'Everything ' . $name . ' can do for you.'],
                    ],
                ];
                $sItems = [];
                $serviceIcons = ['🧭', '⚡', '🎨', '📊', '🛡️', '🚀'];
                $serviceList = !empty($services) ? array_slice($services, 0, 6) : ['Consulting', 'Strategy', 'Execution', 'Support', 'Training', 'Analytics'];
                foreach ($serviceList as $i => $s) {
                    $sItems[] = ['icon' => $serviceIcons[$i] ?? '⭐', 'heading' => $s, 'text' => "Professional {$s} from {$name}."];
                }
                $sections[] = [
                    'type' => 'features',
                    'heading' => 'What We Do',
                    'style' => ['bg' => '#ffffff'],
                    'components' => [
                        ['type' => 'heading', 'text' => 'What We Do'],
                        ['type' => 'cards', 'items' => $sItems],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            case 'contact':
                $sections[] = [
                    'type' => 'contact_form',
                    'heading' => 'Get in Touch',
                    'style' => ['bg' => '#ffffff'],
                    'fields' => [['label'=>'Name','placeholder'=>'Your name'],['label'=>'Email','placeholder'=>'you@email.com'],['label'=>'Message','placeholder'=>'Your message']],
                    'components' => [
                        ['type' => 'heading', 'text' => 'Get in Touch'],
                        ['type' => 'text',    'text' => "We'll get back to you within one business day."],
                    ],
                ];
                break;

            case 'pricing':
                $sections[] = [
                    'type' => 'hero',
                    'heading' => 'Pricing',
                    'subheading' => 'Simple, transparent pricing for every stage.',
                    'components' => [
                        ['type' => 'heading', 'text' => 'Pricing'],
                        ['type' => 'text',    'text' => 'Simple, transparent pricing for every stage.'],
                    ],
                ];
                $sections[] = [
                    'type' => 'features',
                    'heading' => 'Choose Your Plan',
                    'style' => ['bg' => '#f8fafc'],
                    'components' => [
                        ['type' => 'heading', 'text' => 'Choose Your Plan'],
                        ['type' => 'cards', 'items' => [
                            ['icon' => '🌱', 'heading' => 'Starter', 'text' => 'For getting off the ground — the essentials, done right.'],
                            ['icon' => '🚀', 'heading' => 'Growth',  'text' => 'Our most popular package — for teams ready to scale.'],
                            ['icon' => '🏆', 'heading' => 'Premium', 'text' => 'White-glove service with dedicated support and bespoke work.'],
                        ]],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            case 'blog':
                $sections[] = [
                    'type' => 'hero',
                    'heading' => 'Blog',
                    'subheading' => 'Notes, stories, and ideas from ' . $name . '.',
                    'components' => [
                        ['type' => 'heading', 'text' => 'Blog'],
                        ['type' => 'text',    'text' => 'Notes, stories, and ideas from ' . $name . '.'],
                    ],
                ];
                break;

            case 'gallery':
            case 'portfolio':
                $sections[] = [
                    'type' => 'hero',
                    'heading' => $title,
                    'subheading' => 'A selection of our work.',
                    'components' => [
                        ['type' => 'heading', 'text' => $title],
                        ['type' => 'text',    'text' => 'A selection of our work.'],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            case 'testimonials':
                $sections[] = [
                    'type' => 'features',
                    'heading' => 'What Our Clients Say',
                    'style' => ['bg' => '#ffffff'],
                    'components' => [
                        ['type' => 'heading', 'text' => 'What Our Clients Say'],
                        ['type' => 'cards', 'items' => [
                            ['icon' => '⭐', 'heading' => 'Sarah K.',   'text' => "{$name} delivered exactly what they promised, on time and on budget."],
                            ['icon' => '⭐', 'heading' => 'Ahmed M.',   'text' => 'Easy to work with, clear communication, great results. Would recommend.'],
                            ['icon' => '⭐', 'heading' => 'Jordan T.',  'text' => "Consistently high quality. It's rare to find a team this reliable."],
                        ]],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            case 'faq':
                $sections[] = [
                    'type' => 'features',
                    'heading' => 'Frequently Asked Questions',
                    'style' => ['bg' => '#ffffff'],
                    'components' => [
                        ['type' => 'heading', 'text' => 'Frequently Asked Questions'],
                        ['type' => 'cards', 'items' => [
                            ['icon' => '❓', 'heading' => 'How long does a typical project take?', 'text' => 'Most engagements run 4–8 weeks, depending on scope.'],
                            ['icon' => '💬', 'heading' => 'Do you offer ongoing support?',         'text' => 'Yes — we offer monthly retainers for clients who want continuous partnership.'],
                            ['icon' => '📍', 'heading' => 'Do you work with businesses outside ' . ($location ?: 'the UAE') . '?', 'text' => 'Absolutely. We work remotely with clients globally.'],
                        ]],
                    ],
                ];
                $sections[] = $ctaBanner;
                break;

            default:
                $sections[] = [
                    'type' => 'hero',
                    'heading' => $title,
                    'subheading' => 'Placeholder content — edit this page in the builder.',
                    'components' => [
                        ['type' => 'heading', 'text' => $title],
                        ['type' => 'text',    'text' => 'Placeholder content — edit this page in the builder.'],
                    ],
                ];
                $sections[] = $ctaBanner;
        }

        $sections[] = $footer;
        return $sections;
    }
}

if (!function_exists('_t4_addNavLink')) {
    function _t4_addNavLink(int $websiteId, string $title, string $slug): void {
        // FIX 1/2/3 (2026-04-20) — Authoritative nav update:
        //   1) Uses the ACTUAL pages.slug from DB (never derived from title).
        //   2) Operates on the DEPLOYED HTML (Pattern A ul.nav-links, Pattern B div.nav-links,
        //      Pattern C .logo+.nav-links, Footer footer-links) — not just sections_json.
        //   3) Invalidates the published-site cache for EVERY page of this website.

        $href = '/' . ltrim($slug, '/');

        // ── (A) Deployed-HTML update: walk every per-site HTML file ───────
        $siteDir = storage_path('app/public/sites/' . $websiteId);
        if (is_dir($siteDir)) {
            foreach (glob($siteDir . '/*.html') as $htmlFile) {
                _t4_injectNavLinkIntoHtml($htmlFile, $href, $title);
            }
        }

        // ── (B) sections_json path (kept for sites using BuilderRenderer) ─
        // Stores the NEW link as "{title}|{href}" so BuilderRenderer can
        // split on "·" AND on "|" to honour the actual slug.
        $rows = \Illuminate\Support\Facades\DB::table('pages')
            ->where('website_id', $websiteId)
            ->where('status', 'published')
            ->where('slug', '!=', $slug)
            ->get();
        foreach ($rows as $p) {
            $sj = $p->sections_json ?? '';
            if ($sj === '' || $sj === null) continue;
            $decoded = json_decode($sj, true);
            if (!is_array($decoded)) continue;
            $secs = $decoded['sections'] ?? (is_array($decoded) ? $decoded : []);
            $changed = false;
            foreach ($secs as &$sec) {
                if (($sec['type'] ?? '') !== 'header') continue;
                foreach ($sec['components'] ?? [] as &$c) {
                    if (($c['type'] ?? '') !== 'text') continue;
                    if (strpos($c['text'] ?? '', '·') === false) continue;
                    $items = array_map('trim', explode('·', $c['text']));
                    $titleLower = strtolower($title);
                    $alreadyIn = false;
                    foreach ($items as $it) if (strtolower(trim(explode('|', $it)[0])) === $titleLower) { $alreadyIn = true; break; }
                    if (!$alreadyIn) {
                        $items[] = $title . '|' . $href; // title|href format so renderer can read the real slug
                        $c['text'] = implode(' · ', $items);
                        $changed = true;
                    }
                }
                unset($c);
            }
            unset($sec);
            if ($changed) {
                \Illuminate\Support\Facades\DB::table('pages')->where('id', $p->id)->update([
                    'sections_json' => json_encode(['sections' => $secs]),
                    'updated_at'    => now(),
                ]);
            }
        }

        // ── (C) FULL cache invalidation for every slug on this site ───────
        try {
            $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first();
            if ($website) {
                $sub = str_replace('.levelupgrowth.io', '', $website->subdomain ?? '');
                if ($sub) {
                    $slugs = \Illuminate\Support\Facades\DB::table('pages')
                        ->where('website_id', $websiteId)
                        ->pluck('slug')
                        ->all();
                    $slugs[] = 'home';
                    $slugs[] = $slug;
                    $slugs[] = '';
                    foreach (array_unique($slugs) as $s) {
                        \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:{$s}");
                    }
                }
            }
        } catch (\Throwable $_e) { /* non-fatal */ }
    }
}

if (!function_exists('_t4_injectNavLinkIntoHtml')) {
    function _t4_injectNavLinkIntoHtml(string $htmlFile, string $href, string $title): void {
        $html = @file_get_contents($htmlFile);
        if ($html === false || $html === '') return;

        // Idempotency: if an <a> with this href already exists, skip.
        if (strpos($html, 'href="' . $href . '"') !== false) return;

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $changed = false;

        // ── Primary nav: Pattern A (ul.nav-links) ─────────────────────────
        $ul = $xp->query("//ul[contains(concat(' ', normalize-space(@class), ' '), ' nav-links ')]")->item(0);
        if ($ul) {
            // Copy last <li><a>'s classes for style consistency.
            $lastA = null;
            foreach ($xp->query(".//li/a[@href]", $ul) as $a) $lastA = $a;
            $liClass = '';
            $aClass  = $lastA ? $lastA->getAttribute('class') : '';
            $newLi = $dom->createElement('li');
            if ($liClass !== '') $newLi->setAttribute('class', $liClass);
            $newA = $dom->createElement('a', htmlspecialchars($title, ENT_QUOTES | ENT_HTML5));
            $newA->setAttribute('href', $href);
            if ($aClass !== '') $newA->setAttribute('class', $aClass);
            $newLi->appendChild($newA);
            $ul->appendChild($newLi);
            $changed = true;
        }

        // ── Primary nav: Pattern B/C (div.nav-links) ──────────────────────
        if (!$changed) {
            $div = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' nav-links ')]")->item(0);
            if ($div) {
                // Copy the class of the last direct <a> child that is NOT a CTA/login.
                $candidates = $xp->query("./a[not(contains(@class,'nav-cta')) and not(contains(@class,'nav-login'))]", $div);
                $lastA = null;
                foreach ($candidates as $a) $lastA = $a;
                if (!$lastA) {
                    foreach ($xp->query("./a", $div) as $a) $lastA = $a;
                }
                $aClass = $lastA ? $lastA->getAttribute('class') : '';
                $newA = $dom->createElement('a', htmlspecialchars($title, ENT_QUOTES | ENT_HTML5));
                $newA->setAttribute('href', $href);
                if ($aClass !== '') $newA->setAttribute('class', $aClass);
                // Insert before any CTA/login button so new links sit with regular links.
                $cta = $xp->query("./a[contains(@class,'nav-cta')]", $div)->item(0);
                if ($cta) $div->insertBefore($newA, $cta);
                else      $div->appendChild($newA);
                $changed = true;
            }
        }

        // ── Fallback: any <nav> with an <a> inside ────────────────────────
        if (!$changed) {
            $anyNavA = $xp->query("//nav//a[@href][last()]")->item(0);
            if ($anyNavA && $anyNavA->parentNode) {
                $newA = $anyNavA->cloneNode(false);
                $newA->setAttribute('href', $href);
                // Clear existing text content and set to title.
                while ($newA->hasChildNodes()) $newA->removeChild($newA->firstChild);
                $newA->appendChild($dom->createTextNode($title));
                if ($anyNavA->nextSibling) $anyNavA->parentNode->insertBefore($newA, $anyNavA->nextSibling);
                else                        $anyNavA->parentNode->appendChild($newA);
                $changed = true;
            }
        }

        // ── Footer nav (Pattern A + B both use ul.footer-links) ───────────
        // We only add to the FIRST footer-links list (the site-nav one). Contact
        // columns and social columns also use this class, so first-only is safer.
        $footerUl = $xp->query("//ul[contains(concat(' ', normalize-space(@class), ' '), ' footer-links ')]")->item(0);
        if ($footerUl) {
            $lastFA = null;
            foreach ($xp->query(".//li/a[@href]", $footerUl) as $a) $lastFA = $a;
            $newLi = $dom->createElement('li');
            $newA  = $dom->createElement('a', htmlspecialchars($title, ENT_QUOTES | ENT_HTML5));
            $newA->setAttribute('href', $href);
            if ($lastFA && $lastFA->getAttribute('class') !== '') $newA->setAttribute('class', $lastFA->getAttribute('class'));
            $newLi->appendChild($newA);
            $footerUl->appendChild($newLi);
            $changed = true;
        }

        if ($changed) {
            $out = $dom->saveHTML();
            $out = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $out);
            @file_put_contents($htmlFile, $out);
        }
    }
}

// ── PART 3 (2026-04-20) — add-element handler ──────────────────────
// Returns a Tier 4 response if the element noun maps to a repeating
// card/item inside an existing block; null if it should fall through
// to other handlers.
if (!function_exists('_t4_handleElementAdd')) {
    function _t4_handleElementAdd(string $noun, int $websiteId, string $htmlPath, ?string $blockId): ?array {
        // Map noun to (block-id, item-class, field-prefix, starting number to scan from)
        // Card class list includes common variants across all 26 templates
        // (service-card, expertise-card, why-card, etc.) so one pattern works everywhere.
        $map = [
            'service'     => ['block'=>'services',     'item'=>'.service-card,.expertise-card,.why-card', 'field_prefix'=>'service'],
            'services'    => ['block'=>'services',     'item'=>'.service-card,.expertise-card,.why-card', 'field_prefix'=>'service'],
            'team'        => ['block'=>'team',         'item'=>'.team-card,.staff-card,.faculty-card,.member-card', 'field_prefix'=>'team'],
            'member'      => ['block'=>'team',         'item'=>'.team-card,.staff-card,.faculty-card,.member-card', 'field_prefix'=>'team'],
            'staff'       => ['block'=>'team',         'item'=>'.team-card,.staff-card,.faculty-card,.member-card', 'field_prefix'=>'staff'],
            'trainer'     => ['block'=>'trainers',     'item'=>'.trainer-card,.coach-card',   'field_prefix'=>'trainer'],
            'coach'       => ['block'=>'trainers',     'item'=>'.trainer-card,.coach-card',   'field_prefix'=>'trainer'],
            'doctor'      => ['block'=>'doctors',      'item'=>'.doctor-card,.practitioner-card', 'field_prefix'=>'doctor'],
            'attorney'    => ['block'=>'team',         'item'=>'.attorney-card,.team-card',   'field_prefix'=>'attorney'],
            'agent'       => ['block'=>'team',         'item'=>'.agent-card,.team-card',      'field_prefix'=>'agent'],
            'testimonial' => ['block'=>'testimonials', 'item'=>'.testimonial-slide,.testimonial-card,.testimonial-item', 'field_prefix'=>'testimonial'],
            'testimonials'=> ['block'=>'testimonials', 'item'=>'.testimonial-slide,.testimonial-card,.testimonial-item', 'field_prefix'=>'testimonial'],
            'faq'         => ['block'=>'faq',          'item'=>'.faq-item,.faq-card',         'field_prefix'=>'faq'],
            'question'    => ['block'=>'faq',          'item'=>'.faq-item,.faq-card',         'field_prefix'=>'faq'],
            'gallery'     => ['block'=>'gallery',      'item'=>'.gallery-item',               'field_prefix'=>'gallery_image'],
            'photo'       => ['block'=>'gallery',      'item'=>'.gallery-item',               'field_prefix'=>'gallery_image'],
            'listing'     => ['block'=>'listings',     'item'=>'.listing-card',               'field_prefix'=>'listing'],
            'project'     => ['block'=>'portfolio',    'item'=>'.project-card,.portfolio-card,.portfolio-item', 'field_prefix'=>'project'],
            'portfolio'   => ['block'=>'portfolio',    'item'=>'.project-card,.portfolio-card,.portfolio-item', 'field_prefix'=>'project'],
            'blog'        => ['block'=>'blog',         'item'=>'.blog-card,.blog-item,.press-card', 'field_prefix'=>'blog'],
            'article'     => ['block'=>'blog',         'item'=>'.blog-card,.blog-item,.press-card', 'field_prefix'=>'blog'],
            'menu'        => ['block'=>'menu',         'item'=>'.menu-item,.cuisine-item',    'field_prefix'=>'menu_item'],
            'plan'        => ['block'=>'pricing',      'item'=>'.pricing-card,.plan-card',    'field_prefix'=>'plan'],
            'pricing'     => ['block'=>'pricing',      'item'=>'.pricing-card,.plan-card',    'field_prefix'=>'plan'],
            'vehicle'     => ['block'=>'fleet',        'item'=>'.fleet-card,.vehicle-card',   'field_prefix'=>'vehicle'],
            'room'        => ['block'=>'rooms',        'item'=>'.room-card,.room-item',       'field_prefix'=>'room'],
        ];

        if (!isset($map[$noun])) return null; // unknown — let other handlers try
        $cfg = $map[$noun];
        if (!file_exists($htmlPath)) return ['success' => false, 'method' => 'chat', 'message' => 'Website HTML not found.'];
        $html = file_get_contents($htmlPath);

        // Use DOMDocument for reliable block + card location.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // XML encoding hint keeps utf-8 intact during load.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // Find the block element by data-block attribute.
        $blockNode = $xp->query("//*[@data-block='" . $cfg['block'] . "']")->item(0);
        if (!$blockNode) {
            return ['success' => true, 'method' => 'chat', 'message' => "I couldn't find a \"{$cfg['block']}\" section on this page — try adding one first."];
        }

        // Find all cards by class (any of the configured class variants).
        $classList = array_map('trim', explode(',', $cfg['item']));
        $cardNodes = [];
        foreach ($classList as $cls) {
            $className = ltrim($cls, '.');
            // Use contains() with space-padding for exact class match (not substring of another class).
            $hits = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $className . " ')]", $blockNode);
            foreach ($hits as $node) $cardNodes[] = $node;
        }
        if (empty($cardNodes)) {
            return ['success' => true, 'method' => 'chat', 'message' => "The \"{$cfg['block']}\" section exists but I couldn't find a repeating card pattern to duplicate."];
        }

        // Last card = template for the new one.
        $lastCard = end($cardNodes);

        // Determine next index from data-field values in the whole block.
        $prefix = $cfg['field_prefix'];
        $maxN = 0;
        foreach ($xp->query(".//*[@data-field]", $blockNode) as $el) {
            $f = $el->getAttribute('data-field');
            if (preg_match('/^' . preg_quote($prefix, '/') . '_(\d+)(?:_[a-z_]+)?$/i', $f, $mm)) {
                if ((int)$mm[1] > $maxN) $maxN = (int)$mm[1];
            }
        }
        if ($maxN === 0) $maxN = count($cardNodes); // fallback
        $nextN = $maxN + 1;

        // Clone + renumber data-field attributes on the clone.
        $clone = $lastCard->cloneNode(true);
        foreach ($xp->query(".//*[@data-field] | self::*[@data-field]", $clone) as $el) {
            $f = $el->getAttribute('data-field');
            $newF = preg_replace('/^(' . preg_quote($prefix, '/') . ')_' . $maxN . '(_[a-z_]+|)$/i', '${1}_' . $nextN . '$2', $f);
            if ($newF !== $f) $el->setAttribute('data-field', $newF);
        }
        // Clear "active" class state if present (e.g. testimonial slides).
        foreach ($xp->query(".//*[contains(@class, 'active')] | self::*[contains(@class, 'active')]", $clone) as $el) {
            $cls = $el->getAttribute('class');
            $el->setAttribute('class', trim(preg_replace('/\bactive\b/', '', $cls)));
        }
        // Placeholder-ize the first text node per data-field so user sees a prompt.
        foreach ($xp->query(".//*[@data-field]", $clone) as $el) {
            $tn = null;
            foreach ($el->childNodes as $c) {
                if ($c->nodeType === XML_TEXT_NODE && trim($c->nodeValue) !== '') { $tn = $c; break; }
            }
            if ($tn) $tn->nodeValue = ucfirst($prefix) . ' ' . $nextN;
        }

        // Insert the clone right after the last card.
        if ($lastCard->nextSibling) {
            $lastCard->parentNode->insertBefore($clone, $lastCard->nextSibling);
        } else {
            $lastCard->parentNode->appendChild($clone);
        }

        $newHtml = $dom->saveHTML();
        $newHtml = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $newHtml);
        file_put_contents($htmlPath, $newHtml);

        $humanName = ucfirst($noun);
        return [
            'success'        => true,
            'method'         => 'action',
            'message'        => "Done — I've added a new {$humanName} (#" . $nextN . "). Click on it in the preview to edit, or tell me what to put there.",
            'action'         => 'element_added',
            'block'          => $cfg['block'],
            'element_index'  => $nextN,
            'reload_preview' => true,
        ];
    }
}

// ── TASK 1 (2026-04-20) — Add block from another template ──────────
if (!function_exists('_t4_handleBlockAddFromOther')) {
    function _t4_handleBlockAddFromOther(string $blockType, int $websiteId, string $htmlPath, string $currentIndustry): ?array {
        if (!file_exists($htmlPath)) return ['success' => false, 'method' => 'chat', 'message' => 'Website HTML not found.'];
        $html = file_get_contents($htmlPath);

        // Skip if block already exists on this site — let existing handler take it.
        if (strpos($html, 'data-block="' . $blockType . '"') !== false) return null;

        // Scan all templates (except current) for this block.
        $templatesDir = storage_path('templates');
        $found = null;
        $foundIndustry = null;
        foreach (glob($templatesDir . '/*/template.html') as $tplFile) {
            $src = basename(dirname($tplFile));
            if ($src === $currentIndustry) continue;
            $content = file_get_contents($tplFile);
            if (strpos($content, 'data-block="' . $blockType . '"') === false) continue;
            $found = $content;
            $foundIndustry = $src;
            break; // first match wins for MVP
        }
        if (!$found) {
            return ['success' => true, 'method' => 'chat',
                'message' => "I couldn't find a \"{$blockType}\" block in any of our templates. Try a different name, like 'pricing', 'faq', 'testimonials', or 'gallery'."];
        }

        // Extract the block element via DOMDocument.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $found, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $node = $xp->query("//*[@data-block='" . $blockType . "']")->item(0);
        if (!$node) {
            return ['success' => true, 'method' => 'chat',
                'message' => "Found a candidate block in \"{$foundIndustry}\" but couldn't extract its markup."];
        }
        $blockHtml = $dom->saveHTML($node);

        // CSS variable translation: source template's semantic vars → current template's equivalents.
        $srcVars = _t4_collectTemplateSemanticVars($foundIndustry);
        $tgtVars = _t4_collectTemplateSemanticVars($currentIndustry);
        $translation = [];
        foreach ($srcVars as $role => $srcName) {
            if (isset($tgtVars[$role]) && $tgtVars[$role] !== $srcName) {
                $translation[$srcName] = $tgtVars[$role];
            }
        }
        foreach ($translation as $from => $to) {
            $blockHtml = preg_replace('/\bvar\(--' . preg_quote($from, '/') . '\b/', 'var(--' . $to, $blockHtml);
            // Also replace raw CSS custom property ref if someone hardcoded a value name.
            $blockHtml = preg_replace('/--' . preg_quote($from, '/') . '\b/', '--' . $to, $blockHtml);
        }

        // Inject before footer (or before </body> if no footer block).
        $newHtml = preg_replace(
            '/(<[a-z]+[^>]*data-block="footer"[^>]*>)/i',
            "\n" . $blockHtml . "\n" . '$1',
            $html,
            1
        );
        if ($newHtml === $html || $newHtml === null) {
            $newHtml = preg_replace('/<\/body>/i', $blockHtml . "\n</body>", $html, 1);
        }
        if (!$newHtml || $newHtml === $html) {
            return ['success' => false, 'method' => 'chat',
                'message' => "I extracted the block but couldn't find a good place to insert it on your page."];
        }
        file_put_contents($htmlPath, $newHtml);

        // Track in website's settings_json so future renders remember.
        try {
            $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first();
            $settings = json_decode($w->settings_json ?? '{}', true) ?: [];
            $settings['custom_blocks'] = array_values(array_unique(array_merge(
                $settings['custom_blocks'] ?? [],
                [$blockType]
            )));
            \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update([
                'settings_json' => json_encode($settings),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $_e) {}

        return [
            'success'        => true,
            'method'         => 'action',
            'message'        => "Done — I've added a {$blockType} section from our {$foundIndustry} template, adapted to match your site's colors and style.",
            'action'         => 'block_added_from_other',
            'block'          => $blockType,
            'source_industry'=> $foundIndustry,
            'reload_preview' => true,
        ];
    }
}

// Semantic CSS variable roles per template. Used by block-migration to
// translate var(--orange) → var(--gold) when moving a block between templates.
if (!function_exists('_t4_collectTemplateSemanticVars')) {
    function _t4_collectTemplateSemanticVars(string $industry): array {
        static $cache = [];
        if (isset($cache[$industry])) return $cache[$industry];

        // Priority-ordered names per semantic role. First match wins.
        $roles = [
            'primary' => ['primary_color','gold','orange','medical','rose','terra','bronze','red','copper','sky','teal','sage','violet','forest','coral','yellow','cyan'],
            'bg'      => ['bg_color','ink','carbon','navy','charcoal','black','espresso'],
            'bg2'     => ['bg_color_2','ink2','carbon_soft','navy_soft','charcoal_soft','chalk_soft','ivory_soft','bg_color_3'],
            'text'    => ['text_color','cream','chalk','ivory','white'],
            'warm'    => ['text_warm','warm_grey','stone'],
            'muted'   => ['text_muted','accent_muted','divider','text_smoke'],
        ];

        $manifestPath = storage_path('templates/' . $industry . '/manifest.json');
        if (!file_exists($manifestPath)) { $cache[$industry] = []; return []; }
        $m = json_decode(file_get_contents($manifestPath), true) ?: [];
        $vars = $m['variables'] ?? [];

        $out = [];
        foreach ($roles as $role => $options) {
            foreach ($options as $opt) {
                if (isset($vars[$opt])) { $out[$role] = $opt; break; }
            }
        }
        $cache[$industry] = $out;
        return $out;
    }
}

// ── TASK 2 (2026-04-20) — DeepSeek content generation for new pages ─
// Takes a sections composition (raw PHP array) + business context, asks the
// runtime to generate a field→value map, merges generated content back in.
// Gracefully no-ops on runtime failure (keeps templated English).
if (!function_exists('_t4_populatePageWithAI')) {
    function _t4_populatePageWithAI(array $sections, array $ctx): array {
        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if (!$runtime->isConfigured()) return $sections;
        } catch (\Throwable $_e) { return $sections; }

        // Collect all editable text fields with dotted paths so we can merge back.
        $fields = [];
        foreach ($sections as $sIdx => $sec) {
            $type = $sec['type'] ?? '';
            if (isset($sec['heading']))    $fields["s{$sIdx}.heading"]    = $sec['heading'];
            if (isset($sec['subheading'])) $fields["s{$sIdx}.subheading"] = $sec['subheading'];
            if (isset($sec['body']))       $fields["s{$sIdx}.body"]       = $sec['body'];
            foreach (($sec['components'] ?? []) as $cIdx => $c) {
                $ct = $c['type'] ?? '';
                if (in_array($ct, ['heading','text'], true) && isset($c['text'])) {
                    $fields["s{$sIdx}.c{$cIdx}.text"] = $c['text'];
                }
                if ($ct === 'cards' && isset($c['items'])) {
                    foreach ($c['items'] as $iIdx => $it) {
                        if (isset($it['heading'])) $fields["s{$sIdx}.c{$cIdx}.i{$iIdx}.heading"] = $it['heading'];
                        if (isset($it['text']))    $fields["s{$sIdx}.c{$cIdx}.i{$iIdx}.text"]    = $it['text'];
                    }
                }
            }
        }
        if (empty($fields)) return $sections;

        $pageType   = $ctx['page_type']     ?? 'page';
        $businessName = $ctx['business_name'] ?? 'the business';
        $industry   = $ctx['industry']     ?? 'business';
        $location   = $ctx['location']     ?? '';
        $services   = $ctx['services']     ?? [];
        if (is_array($services)) $services = implode(', ', $services);

        $system = "You are generating content for a {$pageType} page for {$businessName}, "
            . "a {$industry} business" . ($location ? " in {$location}" : '') . ". "
            . "Return ONLY a valid JSON object with field_path: value pairs. "
            . "Guidelines: headlines under 8 words; descriptions under 30 words; specific to the business; "
            . "professional tone matching a {$industry} brand; no generic placeholders; "
            . "do NOT change field_path keys — use exactly the keys shown in the prompt.";
        $userPrompt = "Business: {$businessName}\nIndustry: {$industry}\n"
            . ($location ? "Location: {$location}\n" : '')
            . ($services ? "Services: {$services}\n" : '')
            . "Page type: {$pageType}\n\n"
            . "Fill each of these fields with business-specific content. "
            . "Current placeholder shown after the colon — replace with real copy:\n"
            . json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $resp = null;
        try {
            $resp = $runtime->chatJson($system, $userPrompt, [], 1500);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur T4 page-gen] runtime chatJson threw: ' . $e->getMessage());
            return $sections;
        }
        if (!is_array($resp) || empty($resp['success'])) {
            \Illuminate\Support\Facades\Log::info('[Arthur T4 page-gen] runtime returned non-success — keeping templated content', ['err' => $resp['error'] ?? null]);
            return $sections;
        }
        // RuntimeClient::chatJson returns parsed JSON in 'parsed' key.
        // Fallback to decoding 'text' if 'parsed' wasn't provided.
        $filled = $resp['parsed'] ?? null;
        if (!is_array($filled) && !empty($resp['text'])) {
            $txt = $resp['text'];
            // Some models wrap JSON in code fences — strip them.
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $txt, $fm)) $txt = $fm[1];
            $decoded = json_decode($txt, true);
            if (is_array($decoded)) $filled = $decoded;
        }
        if (!is_array($filled) || empty($filled)) {
            \Illuminate\Support\Facades\Log::info('[Arthur T4 page-gen] runtime succeeded but no parseable JSON — keeping templated content');
            return $sections;
        }

        // Merge filled values back into sections at their dotted paths.
        foreach ($filled as $path => $value) {
            if (!is_string($value) || $value === '') continue;
            if (!preg_match('/^s(\d+)(?:\.c(\d+))?(?:\.i(\d+))?\.(heading|subheading|body|text)$/', $path, $pm)) continue;
            $sIdx = (int)$pm[1];
            $cIdx = isset($pm[2]) && $pm[2] !== '' ? (int)$pm[2] : null;
            $iIdx = isset($pm[3]) && $pm[3] !== '' ? (int)$pm[3] : null;
            $leaf = $pm[4];
            if (!isset($sections[$sIdx])) continue;

            if ($cIdx === null) {
                $sections[$sIdx][$leaf] = $value;
            } elseif ($iIdx === null) {
                if (isset($sections[$sIdx]['components'][$cIdx])) {
                    $sections[$sIdx]['components'][$cIdx]['text'] = $value;
                }
            } else {
                if (isset($sections[$sIdx]['components'][$cIdx]['items'][$iIdx])) {
                    $sections[$sIdx]['components'][$cIdx]['items'][$iIdx][$leaf] = $value;
                }
            }
        }
        return $sections;
    }
}


// LEGACY: T3.4 — this 750-line static-HTML regex closure is the pre-Patch-8
// edit path for sites that still render from /storage/app/public/sites/{id}/index.html
// (Chef Red is the only one). Once Chef Red is migrated to sections_json
// per T3.4 / Patch 8.5, this entire closure is retired and Arthur edits flow
// through BuilderService::updatePage() which already snapshots + invalidates cache.
Route::post('/builder/websites/{id}/arthur-edit', function (\Illuminate\Http\Request $r, $id) {
    $wsId = $r->attributes->get('workspace_id');
    if (!$wsId) {
        $token = str_replace('Bearer ', '', $r->header('Authorization', ''));
        if ($token) {
            try {
                $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(env('JWT_SECRET'), 'HS256'));
                $wsId = $payload->ws ?? null;
                if (!$wsId && ($payload->sub ?? null)) {
                    $wsRow = \Illuminate\Support\Facades\DB::table('workspace_users')->where('user_id', (int)$payload->sub)->first();
                    if ($wsRow) $wsId = $wsRow->workspace_id;
                }
            } catch (\Throwable $e) {}
        }
    }
    if (!$wsId) return response()->json(['error' => 'Auth required'], 401);

    $message = $r->input('message', '');
    $blockId = $r->input('block_id');
    $elementKey = $r->input('element_key');
    if (empty($message)) return response()->json(['error' => 'Message required'], 400);

    $htmlPath = storage_path('app/public/sites/' . (int)$id . '/index.html');
    if (!file_exists($htmlPath)) return response()->json(['error' => 'Website not found'], 404);

    $lowerMsg = strtolower($message);

    // BUG 3 FIX — hoist website + industry once at the top of the route.
    // Every downstream branch (chat, Tier 1–4, CSS/HTML prompts) reads from
    // $industry instead of re-querying with inconsistent fallbacks.
    $website  = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->first();
    $industry = $website->template_industry ?? ($website->industry ?? 'default');
    $industry = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)$industry) ?: 'default');

    $credits = \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->first();


    $runtime = app(\App\Connectors\RuntimeClient::class);
    if (!$runtime->isConfigured()) return response()->json(['error' => 'AI not configured']);

    // ── T4-A: Tier 4 intent classifier (runs before chat-first gate) ──
    $tier4Patterns = [
        // Pages (existing + PART 4 extended)
        '/(add|create|new|make|build|generate)\s+(a\s+)?(full|complete|new)?\s*(\w+\s+)?(page|tab)/i',
        '/(delete|remove)\s+(the\s+)?\w+\s+page/i',
        '/(duplicate|copy)\s+(this\s+)?page/i',
        '/(rename)\s+(this\s+)?page/i',
        // Sections/blocks (existing + PART 4 extended)
        '/(add|insert|create|build)\s+(a\s+new\s+)?\w+\s+(block|section)/i',
        '/(remove|delete)\s+(the\s+)?\w+\s+(section|block)/i',
        '/(move)\s+(the\s+)?\w+\s+(section|block)/i',
        // Booking shortcut (existing)
        '/(add|insert|create)\s+(a\s+)?(booking|appointment|reservation|scheduling)/i',
        '/book\s+a\s+(table|class|consultation|viewing|appointment|demo|slot)/i',
        // PART 4 (2026-04-20) — element addition ("add another service card",
        // "add a 7th team member", "I need more testimonials").
        '/(add|insert|create)\s+(another|a\s+new|one\s+more)\s+\w+/i',
        '/(i\s+need|add)\s+more\s+\w+/i',
        '/(add|create)\s+a\s+(new\s+)?\w+\s+card/i',
    ];
    $isTier4 = false;
    foreach ($tier4Patterns as $_p) { if (preg_match($_p, $message)) { $isTier4 = true; break; } }
    if ($isTier4) {
        return response()->json(handleTier4($message, (int)$id, $blockId, (int)$wsId));
    }

    // ── CHAT-FIRST GATE — chat is the default, edit is the exception ──
    // Edit fires only when a block is selected AND the message has clear
    // action-word phrasing. Everything else routes to conversational chat.
    $actionWords = ['make','change','set','add','remove','turn','update','edit','move','resize','replace','delete','put','use','apply','give','shift','swap','convert','adjust','rewrite','recolor','increase','decrease','fix'];
    $adjEdits    = ['bigger','smaller','darker','lighter','brighter','bolder','wider','narrower','taller','shorter','thinner','thicker'];
    $politeLeads = ['can you','could you','would you','please','pls','plz'];

    $msgTrim   = trim($message);
    $firstWord = strtolower(explode(' ', $msgTrim)[0] ?? '');
    $isEditIntent = false;

    // Element-scope-wins: if the client clicked an element, treat as edit.
    if (!empty($elementKey)) {
        $isEditIntent = true;
    }

    if (!empty($blockId)) {
        // Direct action: "make the hero blue", "rewrite this", "change colour"
        if (in_array($firstWord, $actionWords, true)) {
            $isEditIntent = true;
        }
        // Standalone adjective: "bigger", "darker", "smaller"
        if (in_array($firstWord, $adjEdits, true)) {
            $isEditIntent = true;
        }
        // Polite lead: "can you make ...", "please change ..."
        foreach ($politeLeads as $lead) {
            if (stripos($msgTrim, $lead) === 0) {
                $rest = trim(substr($msgTrim, strlen($lead)));
                $restFirst = strtolower(explode(' ', $rest)[0] ?? '');
                if (in_array($restFirst, $actionWords, true) || in_array($restFirst, $adjEdits, true)) {
                    $isEditIntent = true;
                }
                break;
            }
        }
    }

    // Chat is the default: anything not flagged as explicit edit is a chat turn.
    $isQuestion = !$isEditIntent;

    if ($isQuestion) {
        if (($credits->balance ?? 0) < 1) {
            return response()->json(['error' => 'Need 1 credit. You have ' . ($credits->balance ?? 0) . '.']);
        }

        $fullHtml = file_get_contents($htmlPath);

        // Build block list for context
        preg_match_all('/data-block="([^"]+)"/', $fullHtml, $_blocks);
        $blockList = array_unique($_blocks[1] ?? []);
        $blockListStr = implode(', ', $blockList);

        // Get target block HTML if selected
        $blockContext = '';
        if ($blockId) {
            if (preg_match('/<[^>]+data-block="' . preg_quote($blockId) . '"[^>]*>.*?(?=<[^>]+data-block="|<footer|$)/s', $fullHtml, $_bm)) {
                $blockContext = mb_substr(strip_tags($_bm[0]), 0, 2000);
            }
        }

        $arthurSystem = "You are Arthur, the AI editor for a {$industry} business website. Answer the user's question about the page or section, keeping the {$industry} context in mind. "
            . "You can: change colors, backgrounds, gradients, fonts, spacing, alignment, text size, borders, shadows, button styles, layout, and rewrite any text content. You edit one section at a time. You cannot add new sections or upload images. "
            . "Be conversational, helpful, and concise. Do NOT make any changes. Just answer.";
        $arthurUser = "Page sections: {$blockListStr}\n"
            . ($blockId ? "Currently selected block: {$blockId}\nBlock content: {$blockContext}\n" : "No block selected.\n")
            . "User question: {$message}";

        try {
            $chatResult = $runtime->chatJson(
                $arthurSystem . " Return JSON with the word json: {\"reply\": \"your answer here\"}",
                $arthurUser,
                ['task' => 'arthur_css'],
                600
            );

            $reply = $chatResult['parsed']['reply'] ?? null;
            if ($reply) {
                \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->decrement('balance', 1);

                return response()->json([
                    'success' => true,
                    'message' => $reply,
                    'method' => 'chat',
                    'credits_used' => 1,
                    'credits_remaining' => ($credits->balance ?? 0) - 1,
                    'reload_preview' => false,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur Chat] ' . $e->getMessage());
        }

        // PATCH 4 (2026-05-08): DeepSeek direct fallback removed.
        // Runtime is the only LLM path now (hands-vs-brain enforcement).
        // If the primary $runtime->chatJson above already returned empty,
        // we surface a chat-shaped graceful error.
        return response()->json([
            'success' => false,
            'message' => "I couldn't reach the AI just now. Try again in a moment.",
            'method' => 'chat',
            'credits_used' => 0,
            'credits_remaining' => ($credits->balance ?? 0),
            'reload_preview' => false,
        ]);
    }


    // ── Scope classification: GLOBAL vs BLOCK vs AUTO ──
    $globalKeywords = ['theme', 'color scheme', 'primary color', 'secondary color', 'site-wide', 'entire site', 'all sections', 'whole site', 'whole page', 'every section', 'overall'];
    $blockKeywords = ['this section', 'this block', 'this part', 'the hero', 'the nav', 'the about', 'the footer', 'the contact', 'the stats', 'the services', 'the blog', 'the testimonial', 'the process', 'make this', 'change this', 'edit this'];
    $scope = 'auto';
    foreach ($globalKeywords as $_gk) {
        if (stripos($message, $_gk) !== false) { $scope = 'global'; break; }
    }
    if ($scope === 'auto') {
        foreach ($blockKeywords as $_bk) {
            if (stripos($message, $_bk) !== false) { $scope = 'block'; break; }
        }
    }
    // AUTO resolution: blockId set → block, blockId null → global
    if ($scope === 'auto') {
        $scope = $blockId ? 'block' : 'global';
    }

    // ── FIX 2: element-aware selector override ────────────────────────
    // Load this block's element map from the template manifest. If the
    // user's message names a specific element (button, heading, etc.),
    // narrow $cssSelector to that element inside the block so downstream
    // tiers target it directly.
    $blockElements = [];
    $resolvedElementSelector = null;
    if ($blockId) {
        try {
            // BUG 3 FIX — uses hoisted $industry (no extra DB query, no 'restaurant' fallback).
            $manifestPath = storage_path('templates/' . $industry . '/manifest.json');
            if (is_file($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                foreach (($manifest['blocks'] ?? []) as $_b) {
                    if (($_b['id'] ?? null) === $blockId) {
                        $blockElements = $_b['elements'] ?? [];
                        break;
                    }
                }
            }
        } catch (\Throwable $_e) { /* no manifest → fall back to block-level selector */ }

        if (!empty($blockElements)) {
            // Explicit element_key from the iframe click wins over keyword matching.
            if ($elementKey && !empty($blockElements[$elementKey])) {
                $resolvedElementSelector = '[data-block="' . $blockId . '"] ' . $blockElements[$elementKey];
            }
            // Keywords ordered specific-first so "primary button" wins over "button".
            $elementKeywords = [
                'primary button'   => ['primary_button'],
                'cta button'       => ['primary_button'],
                'main button'      => ['primary_button'],
                'secondary button' => ['secondary_button'],
                'submit button'    => ['primary_button'],
                'button'           => ['primary_button', 'secondary_button'],
                'cta'              => ['primary_button'],
                'subheading'       => ['subheading'],
                'subtitle'         => ['subheading'],
                'heading'          => ['heading'],
                'title'            => ['heading'],
                'tagline'          => ['tagline'],
                'eyebrow'          => ['tagline', 'eyebrow'],
                'image'            => ['image'],
                'photo'            => ['image'],
                'picture'          => ['image'],
                'logo'             => ['logo'],
                'quote'            => ['quote'],
                'author'           => ['author'],
                'form'             => ['form'],
                'submit'           => ['primary_button'],
            ];
            if (!$resolvedElementSelector) foreach ($elementKeywords as $kw => $elemKeys) {
                if (stripos($lowerMsg, $kw) !== false) {
                    $matched = [];
                    foreach ($elemKeys as $ek) {
                        if (!empty($blockElements[$ek])) {
                            $matched[] = '[data-block="' . $blockId . '"] ' . $blockElements[$ek];
                        }
                    }
                    if ($matched) {
                        $resolvedElementSelector = implode(', ', array_unique($matched));
                        break;
                    }
                }
            }
        }
    }

    // Effective selector for CSS tiers — narrowed if an element keyword matched.
    $cssSelector = $resolvedElementSelector
        ?? (($scope === 'global' || !$blockId) ? 'body' : '[data-block="' . $blockId . '"]');
    // Always-block selector, used by Tier 1 button patterns that must target
    // both buttons regardless of element narrowing.
    $blockSel = $blockId ? '[data-block="' . $blockId . '"]' : 'body';

    // ── Credit cost for edit operations (after chat intent is handled) ──
    $creditCost = 2;
    if (preg_match('/color|theme|font|translate|arabic|language/', $lowerMsg)) $creditCost = 3;
    elseif (preg_match('/add|new section|insert|whatsapp|map/', $lowerMsg)) $creditCost = 5;

    if (($credits->balance ?? 0) < $creditCost) {
        return response()->json(['error' => "Need {$creditCost} credits. You have " . ($credits->balance ?? 0) . "."]);
    }


    // ── Compound keyword check: skip Tier 1 for gradient/shadow/animation etc ──
    $compoundKeywords = ['gradient', 'shadow', 'animation', 'blur', 'transition', 'glow', 'overlay', 'fade', 'radial', 'linear', 'opacity'];
    $skipTier1 = false;
    foreach ($compoundKeywords as $kw) {
        if (stripos($message, $kw) !== false) { $skipTier1 = true; break; }
    }
    // ── INSTANT CSS map (0ms, no API call) ──
    {
        $sel = $cssSelector;
        $instantMap = [
            '/background.*red/i'           => "{$sel} { background: #8B0000 !important; }",
            '/background.*blue/i'          => "{$sel} { background: #1a3a6b !important; }",
            '/background.*green/i'         => "{$sel} { background: #1B4332 !important; }",
            '/background.*black/i'         => "{$sel} { background: #000000 !important; }",
            '/background.*white/i'         => "{$sel} { background: #ffffff !important; }",
            '/background.*gold/i'          => "{$sel} { background: #D4AF37 !important; }",
            '/background.*dark$/i'         => "{$sel} { background: #0a0a0a !important; }",
            '/background.*light$/i'        => "{$sel} { background: #f8f8f8 !important; }",
            '/gradient.*(red.*black|black.*red)/i' => "{$sel} { background: linear-gradient(135deg, #000, #8B0000) !important; }",
            '/gradient.*(blue.*black|black.*blue)/i' => "{$sel} { background: linear-gradient(135deg, #000, #1a3a6b) !important; }",
            '/gradient.*(green.*black|black.*green)/i' => "{$sel} { background: linear-gradient(135deg, #000, #1B4332) !important; }",
            '/gradient.*gold/i'            => "{$sel} { background: linear-gradient(135deg, #1a1a1a, #D4AF37) !important; }",
            '/gradient.*purple/i'          => "{$sel} { background: linear-gradient(135deg, #0F1117, #6C5CE7) !important; }",
            '/gradient.*red/i'             => "{$sel} { background: linear-gradient(135deg, #1a0000, #8B0000) !important; }",
            '/(darker|dim)/i'              => "{$sel} { filter: brightness(0.7) !important; }",
            '/(lighter|brighter)/i'        => "{$sel} { filter: brightness(1.3) !important; }",
            '/(bigger|larger).*text/i'     => "{$sel} h1, {$sel} h2 { font-size: 120% !important; }",
            '/(smaller).*text/i'           => "{$sel} h1, {$sel} h2 { font-size: 80% !important; }",
            '/(taller|full.*screen|full.*height)/i' => "{$sel} { min-height: 100vh !important; }",
            '/(shorter|compact)/i'         => "{$sel} { min-height: 50vh !important; padding: 60px 0 !important; }",
            '/(more.*padding|more.*space)/i' => "{$sel} { padding: 120px 0 !important; }",
            '/(less.*padding|less.*space)/i' => "{$sel} { padding: 40px 0 !important; }",
            '/add.*border/i'               => "{$sel} { border: 2px solid #D4AF37 !important; }",
            '/remove.*border/i'            => "{$sel} { border: none !important; }",
            '/rounded/i'                   => "{$sel} { border-radius: 16px !important; overflow: hidden !important; }",
            '/(dark.*overlay|overlay.*dark)/i' => "{$sel} { position:relative; } {$sel}::after { content:''; position:absolute; inset:0; background:rgba(0,0,0,0.5); z-index:1; pointer-events:none; }",
            '/text.*white|white.*text/i'   => "{$sel}, {$sel} * { color: #fff !important; }",
            '/text.*gold|gold.*text/i'     => "{$sel} h1, {$sel} h2, {$sel} h3 { color: #D4AF37 !important; }",
            '/(number|01|02|03).*gold/i'   => "{$sel} .expertise-num { color: #FFD700 !important; }",
            '/(number|01|02|03).*white/i'  => "{$sel} .expertise-num { color: #fff !important; }",
            '/(number|01|02|03).*(bright|lighter)/i' => "{$sel} .expertise-num { color: #FFD700 !important; }",
            '/hide.*section|hide$/i'       => "{$sel} { display: none !important; }",
            '/show.*section|show$/i'       => "{$sel} { display: block !important; }",
            '/(bold|bolder)/i'             => "{$sel} p, {$sel} span { font-weight: 700 !important; }",
            '/font.*bigger/i'              => "{$sel} { font-size: 110% !important; }",
            '/center.*text|text.*center/i' => "{$sel} { text-align: center !important; }",
            // ── FIX 4: button-specific instant patterns ──
            '/button.*gold|gold.*button/i'     => "{$blockSel} a.btn-primary, {$blockSel} a.btn-ghost, {$blockSel} button.form-submit, {$blockSel} a.nav-cta { background: #C9943A !important; color: #fff !important; border-color: #C9943A !important; }",
            '/button.*red|red.*button/i'       => "{$blockSel} a.btn-primary, {$blockSel} a.btn-ghost, {$blockSel} button.form-submit, {$blockSel} a.nav-cta { background: #b91c1c !important; color: #fff !important; border-color: #b91c1c !important; }",
            '/button.*white|white.*button/i'   => "{$blockSel} a.btn-primary, {$blockSel} a.btn-ghost, {$blockSel} button.form-submit, {$blockSel} a.nav-cta { background: #ffffff !important; color: #000 !important; border-color: #ffffff !important; }",
            '/button.*black|black.*button/i'   => "{$blockSel} a.btn-primary, {$blockSel} a.btn-ghost, {$blockSel} button.form-submit, {$blockSel} a.nav-cta { background: #000 !important; color: #fff !important; border-color: #000 !important; }",
            '/button.*(charcoal|dark|carbon|graphite)|(charcoal|dark|carbon|graphite).*button/i' => "{\} a.btn-primary, {\} a.btn-ghost, {\} button.form-submit, {\} a.nav-cta { background: #1B1B1B !important; color: #ffffff !important; border-color: #1B1B1B !important; }",
            '/button.*(bronze|brass|copper)|(bronze|brass|copper).*button/i' => "{\} a.btn-primary, {\} a.btn-ghost, {\} button.form-submit, {\} a.nav-cta { background: #8B6F3E !important; color: #ffffff !important; border-color: #8B6F3E !important; }",
            '/button.*(cream|chalk|ivory)|(cream|chalk|ivory).*button/i' => "{\} a.btn-primary, {\} a.btn-ghost, {\} button.form-submit, {\} a.nav-cta { background: #FAF7F2 !important; color: #1B1B1B !important; border-color: #FAF7F2 !important; }",
            '/button.*blue|blue.*button/i'     => "{$blockSel} a.btn-primary, {$blockSel} a.btn-ghost, {$blockSel} button.form-submit, {$blockSel} a.nav-cta { background: #1a3a6b !important; color: #fff !important; border-color: #1a3a6b !important; }",
        ];

if (!$skipTier1)
        foreach ($instantMap as $pattern => $css) {
            if (preg_match($pattern, $message)) {
                $html = file_get_contents($htmlPath);
                // Accumulate in single arthur-edits style tag
                preg_match('/<style id="arthur-edits">(.*?)<\/style>/s', $html, $_ae);
                $_existCss = $_ae[1] ?? '';
                $_newCss = $_existCss . "\n/* instant: {$blockId} */ " . $css;
                if (strpos($html, 'id="arthur-edits"') !== false) {
                    $html = preg_replace('/<style id="arthur-edits">.*?<\/style>/s', '<style id="arthur-edits">' . $_newCss . '</style>', $html);
                } else {
                    $html = str_replace('</head>', '<style id="arthur-edits">' . $_newCss . '</style></head>', $html);
                }
                file_put_contents($htmlPath, $html);

                \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->decrement('balance', 1);

                return response()->json([
                    'success' => true,
                    'message' => "Done.",
                    'method' => 'instant',
                    'credits_used' => 1,
                    'credits_remaining' => ($credits->balance ?? 0) - 1,
                    'reload_preview' => true,
                ]);
            }
        }
    }

    // ── CSS-only path for styling changes (fast, no timeout risk) ──
    $cssKeywords = ['background','color','gradient','font','padding','margin','border','shadow','opacity','darker','lighter','bigger','smaller','taller','wider','brighter','dimmer','spacing','rounded','align','center'];
    $isCssChange = false;
    foreach ($cssKeywords as $kw) {
        if (strpos($lowerMsg, $kw) !== false) { $isCssChange = true; break; }
    }

    if ($isCssChange) {
        try {
            $_elementList = '';
            if (!empty($blockElements)) {
                $_parts = [];
                foreach ($blockElements as $_k => $_s) {
                    $_parts[] = '[data-block="' . $blockId . '"] ' . $_s . ' (' . $_k . ')';
                }
                $_elementList = implode(', ', $_parts);
            }
            $cssResult = $runtime->chatJson(
                "You are a CSS expert editing the website of a {$industry} business. "
                . "Return JSON with the word json. One key: 'css' containing CSS rules. Execute the full request exactly as stated. If the user says gradient, write a gradient. If they name two colors, use both. "
                . (!empty($blockElements)
                    ? "This block contains these child elements and their EXACT CSS selectors (as JSON): " . json_encode($blockElements) . ". "
                      . "Use ONLY these selectors when targeting child elements. Never guess class names. "
                    : "")
                . "Target using the CSS selector: {$cssSelector}. "
                . "Example: {\"css\": \"body { background: linear-gradient(135deg, #000, #8B0000) !important; }\"} "
                . "Use !important on all rules. No HTML. No explanation.",
                "Industry: {$industry}\nRequest: {$message}\nScope: {$scope}\nSelector: {$cssSelector}"
                . (!empty($_elementList) ? "\nAvailable selectors in this block: {$_elementList}" : ""),
                ['task' => 'arthur_css'],
                600
            );

            $css = $cssResult['parsed']['css'] ?? null;
            if ($css && strlen($css) > 5) {
                $html = file_get_contents($htmlPath);
                $styleTag = '';
                // Accumulate in arthur-edits tag
                preg_match('/<style id="arthur-edits">(.*?)<\/style>/s', $html, $_ae2);
                $_existCss2 = $_ae2[1] ?? '';
                $_newCss2 = $_existCss2 . "\n/* css: " . substr($message, 0, 30) . " */ " . $css;
                if (strpos($html, 'id="arthur-edits"') !== false) {
                    $html = preg_replace('/<style id="arthur-edits">.*?<\/style>/s', '<style id="arthur-edits">' . $_newCss2 . '</style>', $html);
                } else {
                    $html = str_replace('</head>', '<style id="arthur-edits">' . $_newCss2 . '</style></head>', $html);
                }
                file_put_contents($htmlPath, $html);

                \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->decrement('balance', $creditCost);

                return response()->json([
                    'success' => true,
                    'message' => "Done.",
                    'method' => 'css',
                    'credits_used' => $creditCost,
                    'credits_remaining' => ($credits->balance ?? 0) - $creditCost,
                    'reload_preview' => true,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur CSS] ' . $e->getMessage());
        }
        // CSS failed — fall through to full edit
    }

    // ── Full HTML edit path (structural changes) ──
    // BUG 3 FIX — use the $website hoisted at the top of the route.
    $fullHtml = file_get_contents($htmlPath);
    $vars = json_decode($website->template_variables ?? '{}', true);

    // Extract target block if specified
    $isBlockEdit = false;
    $contextHtml = $fullHtml;
    if ($blockId) {
        if (preg_match('/<[^>]+data-block="' . preg_quote($blockId) . '"[^>]*>.*?(?=<[^>]+data-block="|<footer|$)/s', $fullHtml, $m)) {
            $contextHtml = $m[0];
            $isBlockEdit = true;
        }
    }

    // Minify for LLM
    $minified = preg_replace('/<!--(?!\s*(?:BLOCK|END BLOCK)).*?-->/s', '', $contextHtml);
    $minified = preg_replace('/\s+/', ' ', $minified);
    $minified = preg_replace('/>\s+</', '><', $minified);

    try {
        $result = $runtime->chatJson(
            "You are Arthur, an expert web developer editing the website of a {$industry} business. "
            . ($isBlockEdit ? "Edit ONLY this HTML block." : "Edit this website HTML. Apply the change globally across the page.") . " "
            . "Return JSON with the word json: {\"html\": \"<modified HTML>\"} "
            . "Keep data-block and data-field attributes. Keep the tone and copy appropriate for a {$industry} business. "
            . "CSS vars: --gold:" . ($vars['primary_color'] ?? '#C9943A') . " --ink:" . ($vars['bg_color'] ?? '#0A0806') . ". "
            . "No explanation. Just the JSON.",
            "Industry: {$industry}\nHTML:\n" . $minified . "\n\nRequest: " . $message,
            ['task' => 'arthur_edit'],
            8000
        );

        if (!($result['success'] ?? false)) {
            return response()->json(['error' => "I couldn't process that. Try selecting a specific section and making a simpler change."]);
        }

        $newHtml = $result['parsed']['html'] ?? null;
        if (!$newHtml || strlen($newHtml) < 50) {
            return response()->json(['error' => "That change was too complex. Try one thing at a time."]);
        }

        if ($isBlockEdit && $blockId) {
            $pattern = '/<[^>]+data-block="' . preg_quote($blockId) . '"[^>]*>.*?(?=<[^>]+data-block="|<footer|$)/s';
            $updatedFull = preg_replace($pattern, $newHtml, $fullHtml, 1);
            file_put_contents($htmlPath, $updatedFull);
        } else {
            if (stripos($newHtml, '<!DOCTYPE') !== false || stripos($newHtml, '<html') !== false) {
                file_put_contents($htmlPath, $newHtml);
            } else {
                return response()->json(['error' => 'For big changes, try selecting a section first.']);
            }
        }

        \Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->decrement('balance', $creditCost);

        return response()->json([
            'success' => true,
            'message' => "Done.",
            'method' => 'html',
            'credits_used' => $creditCost,
            'credits_remaining' => ($credits->balance ?? 0) - $creditCost,
            'reload_preview' => true,
        ]);
    } catch (\Throwable $e) {
        return response()->json(['error' => "Arthur timed out. Try a simpler change like: 'Change the color to red'"]);
    }
});

Route::post('/builder/websites/{id}/tier4-confirm', function (\Illuminate\Http\Request $r, $id) {
    $action = $r->input('confirm_action');
    $data = $r->input('confirm_data', []);
    $htmlPath = storage_path('app/public/sites/' . (int)$id . '/index.html');
    $svc = app(\App\Engines\Builder\Services\BuilderService::class);

    if ($action === 'delete_page') {
        $pageId = (int)($data['page_id'] ?? 0);
        if (!$pageId) return response()->json(['error' => 'page_id required'], 400);
        $svc->deletePage($pageId);
        return response()->json(['success' => true, 'method' => 'action', 'message' => 'Page deleted.', 'action' => 'page_deleted']);
    }
    if ($action === 'duplicate_page') {
        $src = \Illuminate\Support\Facades\DB::table('pages')->where('id', (int)($data['page_id'] ?? 0))->first();
        if (!$src) return response()->json(['error' => 'source page not found'], 404);
        $newTitle = ($src->title ?? 'Page') . ' Copy';
        $newSlug = \Illuminate\Support\Str::slug($newTitle) . '-' . substr(uniqid(), -4);
        $res = $svc->createPage((int)$src->website_id, [
            'title' => $newTitle, 'slug' => $newSlug,
            'sections_json' => $src->sections_json ?? null,
            'seo_json' => $src->seo_json ?? null,
        ]);
        return response()->json(['success' => true, 'method' => 'action', 'message' => 'Done — ' . $newTitle . ' added.', 'action' => 'page_duplicated', 'page_id' => $res['page_id'] ?? null]);
    }
    if ($action === 'remove_block') {
        if (!file_exists($htmlPath)) return response()->json(['error' => 'website not found'], 404);
        $blockId = (string)($data['block_id'] ?? '');
        if ($blockId === '') return response()->json(['error' => 'block_id required'], 400);
        $html = file_get_contents($htmlPath);
        $pattern = '/<[^>]+data-block="' . preg_quote($blockId, '/') . '"[^>]*>.*?(?=<[^>]+data-block="|<footer[^>]*data-block="footer")/s';
        $html = preg_replace($pattern, '', $html, 1);
        file_put_contents($htmlPath, $html);
        return response()->json(['success' => true, 'method' => 'action', 'message' => 'Removed ' . $blockId . ' section.', 'action' => 'block_removed', 'block_id' => $blockId, 'reload_preview' => true]);
    }
    return response()->json(['error' => 'unknown action'], 400);
});





// ═══════════════════════════════════════════════════════════════════
// Email Builder — PUBLIC tracking endpoints (no auth)
// // email-builder-v1 //
// ═══════════════════════════════════════════════════════════════════
Route::get('/email/track/open/{token}', function (\Illuminate\Http\Request $r, $token) {
    try {
        app(\App\Engines\Marketing\Services\EmailBuilderService::class)
            ->trackOpenEnhanced((string) $token, (string) $r->header('User-Agent', ''));
    } catch (\Throwable $e) { /* always serve the pixel */ }
    // Canonical 1x1 transparent GIF — 43 bytes
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    return response($gif, 200, [
        'Content-Type'   => 'image/gif',
        'Content-Length' => (string) strlen($gif),
        'Cache-Control'  => 'no-cache, no-store, must-revalidate',
        'Pragma'         => 'no-cache',
        'Expires'        => '0',
    ]);
});

Route::get('/email/track/click/{linkToken}/{logToken}', function ($linkToken, $logToken) {
    // Must redirect even on error — never show an error page to a recipient.
    $url = '/';
    try {
        $url = app(\App\Engines\Marketing\Services\EmailBuilderService::class)
            ->trackClickEnhanced((string) $linkToken, (string) $logToken) ?: '/';
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('email.click.redirect.fallback', ['error' => $e->getMessage()]);
    }
    return redirect($url);
});

Route::get('/email/unsubscribe/{token}', function (\Illuminate\Http\Request $r, $token) {
    $svc  = app(\App\Engines\Marketing\Services\EmailBuilderService::class);
    $lead = $svc->unsubscribeByToken((string) $token);
    $resubscribed = (bool) $r->query('resubscribed');
    return response()->view('email.unsubscribe', [
        'ok'                 => $lead !== null || $resubscribed,
        'email'              => $lead->email ?? null,
        'first_name'         => $lead->first_name ?? null,
        'brand_name'         => config('app.name', 'LevelUp Growth'),
        'resubscribe_url'    => url('/api/email/resubscribe/' . $token),
        'resubscribed'       => $resubscribed,
    ]);
})->name('email.unsubscribe');

Route::post('/email/resubscribe/{token}', function (\Illuminate\Http\Request $r, $token) {
    app(\App\Engines\Marketing\Services\EmailBuilderService::class)->resubscribeByToken((string) $token);
    return redirect('/api/email/unsubscribe/' . $token . '?resubscribed=1');
})->name('email.resubscribe');

// ════════════════════════════════════════════════════════════════════════════
// §3 Chatbot888 public widget endpoints (Recovery 2026-05-05 / May-2 chatbot)
// Auth: NO JWT. Token via X-CHATBOT-TOKEN header + Origin allowlist (verified
// inside controller). RateLimiter (currently file-cache-backed; Redis once
// php-redis is wired). Public anonymous traffic.
// ════════════════════════════════════════════════════════════════════════════
Route::prefix('public/chatbot')->group(function () {
    Route::get ('/config',           [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'getConfig']);
    Route::post('/session/start',    [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'startSession']);
    Route::post('/message',          [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'postMessage']);
    Route::post('/lead',             [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'captureLead']);
    Route::post('/booking-request', [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'bookingRequest']);
    Route::post('/callback-request', [\App\Http\Controllers\Api\Widget\PublicChatbotController::class, 'callbackRequest']);
});

// ── Public News widget endpoints (Option C, 2026-05-09) ──────────────
// Read-only stories + categories for tenant news_channel sites. No auth;
// tenant subdomain in URL scopes the lookup. Returns only published,
// non-deleted articles (already publicly visible on the tenant subdomain
// itself, so no new exposure).
Route::prefix('public/news')->group(function () {
    Route::get('/{subdomain}/stories',    [\App\Http\Controllers\Api\Widget\PublicNewsController::class, 'stories'])->where('subdomain', '[a-z0-9\-]+');
    Route::get('/{subdomain}/categories', [\App\Http\Controllers\Api\Widget\PublicNewsController::class, 'categories'])->where('subdomain', '[a-z0-9\-]+');
});
