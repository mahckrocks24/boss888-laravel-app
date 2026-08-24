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

// ── Public intake (markraymundo.com application forms) ───────────────────
// Only unauthenticated write path into the leads table. Workspace is pinned server-side.
// Route throttle is a coarse flood backstop; precise per-IP limiting lives in the controller.
Route::middleware('throttle:60,1')
    ->post('/public/intake', [\App\Http\Controllers\Api\PublicIntakeController::class, 'store'])
    ->name('public.intake');

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
    // INFRA888 (2026-07-18): tag the provenance. This token is NOT individually
    // attributable (it always resolves to user 1), so infrastructure routes
    // refuse it - see App\Http\Middleware\DenyApiKeyAuth.
    $refreshService = app(\App\Core\Auth\RefreshTokenService::class);
    $workspace = $admin->workspaces()->first();
    // Phase 1D: provenance is now established on the SESSION, so it survives
    // refresh-token rotation. Both the access token and every future refreshed
    // token remain tagged 'shared_admin_token' and stay denied on INFRA888.
    $tokens = $refreshService->issueTokenPair($admin, $workspace, null, null, 'shared_admin_token');

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

// ══════════════════════════════════════════════════════════════════════════
// 2026-05-24 — Admin god-mode: AgentBrowser activity (cross-workspace)
// ══════════════════════════════════════════════════════════════════════════
Route::middleware(['auth.jwt', 'admin'])->prefix('admin')->group(function () {

    // ADS888: admin ads API extracted to routes/api/admin/ads.php (read-only, phase A1).
    require __DIR__ . '/api/admin/ads.php';

    // INFRA888 E3 — Business Email operator console. Extracted for the same
    // reason as ads.php: routes/api.php is edited by several sessions at
    // once, and forty routes here would put this milestone in every future
    // merge. Every route inside is additionally gated off by
    // BusinessEmailAdminGate, which is closed on every installation today.
    require __DIR__ . '/api/admin/business-email.php';

    // EMAIL888 — delivery ledger operator console. Read-only: retrying a
    // message means a new SendEmailCommand through Email888, never re-firing
    // an old one from an admin screen.
    require __DIR__ . '/api/admin/email888.php';

    // Engineer888 Command Center. Inside this group, so it inherits
    // auth.jwt + AdminMiddleware; the module adds DenyApiKeyAuth of its
    // own, because a machine credential must never be able to approve a
    // source change.
    require __DIR__ . '/api/admin/engineer888.php';

    Route::get('/web-activity', function (\Illuminate\Http\Request $r) {
        $q = \Illuminate\Support\Facades\DB::table('agent_web_activity')
            ->leftJoin('workspaces', 'workspaces.id', '=', 'agent_web_activity.workspace_id');

        if ($wsId = (int) $r->input('workspace_id', 0)) $q->where('agent_web_activity.workspace_id', $wsId);
        if ($agent = $r->input('agent'))                $q->where('agent_web_activity.agent_slug', $agent);
        if ($action = $r->input('action'))              $q->where('agent_web_activity.action', $action);
        if ($status = $r->input('status'))              $q->where('agent_web_activity.status', $status);
        if ($since  = $r->input('since'))               $q->where('agent_web_activity.created_at', '>=', $since);

        $rows = $q->orderByDesc('agent_web_activity.id')
            ->limit(min((int) $r->input('limit', 200), 1000))
            ->get([
                'agent_web_activity.id',
                'agent_web_activity.workspace_id',
                'workspaces.name as workspace_name',
                'agent_web_activity.agent_slug',
                'agent_web_activity.user_id',
                'agent_web_activity.action',
                'agent_web_activity.url_or_query',
                'agent_web_activity.status',
                'agent_web_activity.title',
                'agent_web_activity.response_preview',
                'agent_web_activity.content_length',
                'agent_web_activity.duration_ms',
                'agent_web_activity.cost_credits',
                'agent_web_activity.error',
                'agent_web_activity.created_at',
            ]);

        // Aggregate stats for the dashboard header
        $today = \Illuminate\Support\Facades\DB::table('agent_web_activity')
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(cost_credits),0) as credits, COUNT(DISTINCT workspace_id) as workspaces, COUNT(DISTINCT agent_slug) as agents')
            ->first();

        $byStatus = \Illuminate\Support\Facades\DB::table('agent_web_activity')
            ->where('created_at', '>=', now()->subDays(7))
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'rows'      => $rows,
            'total_returned' => $rows->count(),
            'today'     => $today,
            'by_status_7d' => $byStatus,
        ]);
    });

    // Convenience: distinct agent slugs that have activity (for filter dropdown)
    Route::get('/web-activity/agents', function () {
        $agents = \Illuminate\Support\Facades\DB::table('agent_web_activity')
            ->select('agent_slug', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('agent_slug')
            ->orderByDesc('count')
            ->get();
        return response()->json(['agents' => $agents]);
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

/* b16-linkedin-callback */
// LinkedIn OAuth callback. Public (same reason as FB: browser redirect, no JWT).
Route::get('/social/oauth/linkedin/callback', function (\Illuminate\Http\Request $r) {
    $code  = $r->query('code');
    $state = $r->query('state', '');
    $error = $r->query('error');
    $errDesc = $r->query('error_description');

    if ($error) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'linkedin',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => (string) ($errDesc ?: $error),
        ]);
    }

    if (! $code) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'linkedin',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => 'No authorization code received from LinkedIn.',
        ]);
    }

    $wsId = (int) (explode('_', $state)[0] ?? 0);
    if ($wsId <= 0) $wsId = 1;

    $connector = app(\App\Connectors\SocialConnector::class);
    $result = $connector->handleLinkedInCallback($code, $state, $wsId);

    if (! ($result['success'] ?? false)) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'linkedin',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => (string) ($result['error'] ?? 'Unknown error'),
        ]);
    }

    $accounts = $result['accounts'] ?? [];
    $first = $accounts[0] ?? null;
    return response()->view('social.oauth-callback', [
        'success'        => true,
        'platform'       => 'linkedin',
        'account_name'   => $first['account_name'] ?? 'LinkedIn',
        'accounts_count' => (int) ($result['stored'] ?? count($accounts)),
        'error_message'  => null,
    ]);
});

/* b17-twitter-callback */
// Twitter/X OAuth callback. Public (same reason as FB/LinkedIn: browser
// redirect from twitter.com, no JWT available at this point).
Route::get('/social/oauth/twitter/callback', function (\Illuminate\Http\Request $r) {
    $code  = $r->query('code');
    $state = $r->query('state', '');
    $error = $r->query('error');
    $errDesc = $r->query('error_description');

    if ($error) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'twitter',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => (string) ($errDesc ?: $error),
        ]);
    }

    if (! $code) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'twitter',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => 'No authorization code received from Twitter.',
        ]);
    }

    $wsId = (int) (explode('_', $state)[0] ?? 0);
    if ($wsId <= 0) $wsId = 1;

    $connector = app(\App\Connectors\SocialConnector::class);
    $result = $connector->handleTwitterCallback($code, $state, $wsId);

    if (! ($result['success'] ?? false)) {
        return response()->view('social.oauth-callback', [
            'success'        => false,
            'platform'       => 'twitter',
            'account_name'   => null,
            'accounts_count' => 0,
            'error_message'  => (string) ($result['error'] ?? 'Unknown error'),
        ]);
    }

    $accounts = $result['accounts'] ?? [];
    $first = $accounts[0] ?? null;
    return response()->view('social.oauth-callback', [
        'success'        => true,
        'platform'       => 'twitter',
        'account_name'   => $first['account_name'] ?? 'X/Twitter',
        'accounts_count' => (int) ($result['stored'] ?? count($accounts)),
        'error_message'  => null,
    ]);
});

// Google Search Console OAuth callback. Public (browser redirect from
// accounts.google.com — no JWT/X-API-KEY available). Workspace identity
// travels in the SIGNED `state` (Crypt) and is verified server-side, the
// same pattern the social callbacks use. Stores the workspace's own tokens;
// the user then picks which property to sync from inside the app.
Route::get('/seo/gsc/oauth/callback', function (\Illuminate\Http\Request $r) {
    $gsc   = app(\App\Engines\SEO\Services\GscClient::class);
    $code  = $r->query('code');
    $state = (string) $r->query('state', '');
    $error = $r->query('error');

    $fail = fn (string $msg) => response()->view('gsc.oauth-callback', [
        'success' => false, 'site_url' => null, 'email' => null, 'error_message' => $msg,
    ]);

    if ($error) {
        return $fail($error === 'access_denied'
            ? 'You declined the Google permission request.'
            : (string) ($r->query('error_description') ?: $error));
    }
    if (! $code) {
        return $fail('No authorization code received from Google.');
    }

    $wsId = $gsc->decodeState($state);
    if ($wsId === null || $wsId <= 0) {
        return $fail('This connection link is invalid or expired. Please start the connection again.');
    }

    $selectedSite = null;
    try {
        $tokens = $gsc->exchangeCode((string) $code);
        $gsc->storeTokens($wsId, $tokens);
        // Auto-select a property so the connection COMPLETES without a separate
        // picker UI (connected stays false until a site is chosen). Prefer a
        // domain-property / sc-domain match, else take the first one the account
        // owns. The user can change it later once the picker ships.
        try {
            $sites = $gsc->listSites($wsId);
            if (! empty($sites)) {
                $owner = array_values(array_filter($sites, fn ($s) => ($s['permissionLevel'] ?? '') === 'siteOwner'));
                $pick = $owner[0]['siteUrl'] ?? $sites[0]['siteUrl'];
                if (! empty($pick)) {
                    $gsc->setSite($wsId, $pick);
                    $selectedSite = $pick;
                }
            }
        } catch (\Throwable $e) {
            // Most common cause: the Search Console API isn't enabled on the
            // project yet. Tokens are stored; the in-app "Sync now" / picker
            // can complete selection once the API is on.
            \Illuminate\Support\Facades\Log::warning('[GSC] auto-select site failed: ' . $e->getMessage());
        }
        // Auto-select a GA4 property ONLY if one tracks this workspace's own
        // site — never auto-pick an unrelated property the account happens to
        // have access to. If nothing matches, leave it for the user to choose.
        try {
            $ga = app(\App\Engines\SEO\Services\GaClient::class);
            $sp = $ga->scopedProperties($wsId);
            if (! empty($sp['matched'])) {
                $ga->setProperty($wsId, $sp['matched'][0]['id'], $sp['matched'][0]['name']);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[GA] auto-select property failed: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('[GSC] callback exchange failed: ' . $e->getMessage());
        return $fail($e->getMessage());
    }

    return response()->view('gsc.oauth-callback', [
        'success' => true, 'site_url' => $selectedSite, 'email' => null, 'error_message' => null,
    ]);
});

// ── Protected Routes ─────────────────────────────────────────────────────
// ADDED 2026-04-12 (Phase 2J / doc 12): traffic.defense middleware applied to
// the entire authenticated workspace surface. Wires TrafficDefenseService into
// the request pipeline. Fails open on errors.
Route::middleware(['auth.jwt', 'traffic.defense', 'connector.brand'])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    // MISSION-018 WS-2 (2026-08-24): re-queue the address-confirmation email.
    // Throttled hard — this endpoint sends real mail on request.
    Route::post('/auth/resend-verification', function (\Illuminate\Http\Request $r) {
        $sent = app(\App\Core\Auth\AuthService::class)->sendVerificationEmail($r->user());
        return response()->json([
            'success' => true,
            'sent'    => $sent,
            'message' => $sent ? 'Verification email sent — check your inbox.'
                               : 'This email address is already verified.',
        ]);
    })->middleware('throttle:3,60');

    // 2026-05-28 — Per-user preferences (sidebar visibility mode, etc.).
    // Whitelisted keys only so users.preferences_json doesn't become a
    // junk drawer. Read happens via /auth/me which already returns the
    // preferences bag — this endpoint is write-only.
    Route::put('/user/preferences', function (\Illuminate\Http\Request $r) {
        $user = $r->user();
        $userId = $user ? (int) $user->id : 0;
        if (! $userId) {
            return response()->json(['success' => false, 'error' => 'NO_USER'], 401);
        }
        $data = $r->validate([
            'visibility_mode' => 'sometimes|string|in:basic,advanced',
        ]);

        $row = \Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->first(['id','preferences_json']);
        if (! $row) {
            return response()->json(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $current = is_string($row->preferences_json) ? (json_decode($row->preferences_json, true) ?: []) : [];
        if (! is_array($current)) $current = [];

        $allowed = ['visibility_mode'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $current[$k] = $data[$k];
            }
        }

        \Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->update([
            'preferences_json' => json_encode($current),
            'updated_at'       => now(),
        ]);

        return response()->json([
            'success'     => true,
            'preferences' => $current,
        ]);
    });

    // 2026-05-16 v1.1 — WP plugin token mint (auth.jwt group; user logged in).
    Route::post('/plugin/connect', function (\Illuminate\Http\Request $r) {
        $user = $r->user();
        $siteUrl  = trim((string) ($r->input('site_url') ?: ''));
        $siteHost = $siteUrl !== '' ? (parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl) : '';

        $primaryWs = (int) ($r->input('workspace_id')
            ?? \Illuminate\Support\Facades\DB::table('workspace_users')
                ->where('user_id', $user->id)
                ->orderBy('created_at')
                ->value('workspace_id'));
        if (!$primaryWs) {
            return response()->json(['success' => false, 'error' => 'no_workspace'], 422);
        }

        // 2026-06-26 — WEBSITE=WORKSPACE: each WP site lives in its OWN workspace
        // (isolated SEO/CRM/data), so a user/agency can mix WP + Laravel sites.
        // Resolve the target workspace by site_url.
        $billingWs = (int) (\Illuminate\Support\Facades\DB::table('workspaces')->where('id', $primaryWs)->value('billing_workspace_id') ?: $primaryWs);
        $userWsIds = \Illuminate\Support\Facades\DB::table('workspaces')->where('billing_workspace_id', $billingWs)->pluck('id')->all();
        if (empty($userWsIds)) $userWsIds = [$billingWs];

        $wsId = $primaryWs;
        if ($siteHost !== '') {
            // (a) Already connected? reuse its workspace (idempotent re-connect).
            $existing = (int) (\Illuminate\Support\Facades\DB::table('websites')
                ->whereIn('workspace_id', $userWsIds)
                ->where(function ($q) use ($siteUrl, $siteHost) {
                    $q->where('external_url', $siteUrl)->orWhere('external_url', 'like', '%' . $siteHost . '%');
                })->value('workspace_id') ?? 0);
            if ($existing > 0) {
                $wsId = $existing;
            } else {
                // (b) Primary empty → use it; else provision a dedicated workspace.
                $primaryHasSite = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $primaryWs)->whereNull('deleted_at')->exists();
                if ($primaryHasSite) {
                    $planRow = \App\Models\Plan::find(\App\Models\Subscription::where('workspace_id', $billingWs)->whereIn('status', ['active', 'trialing'])->latest()->value('plan_id')) ?? \App\Models\Plan::where('slug', 'free')->first();
                    $maxW  = (int) ($planRow->max_websites ?? 1);
                    $total = (int) \Illuminate\Support\Facades\DB::table('websites')->whereIn('workspace_id', $userWsIds)->whereNull('deleted_at')->count();
                    if ($total >= $maxW) {
                        return response()->json(['success' => false, 'error' => 'limit_reached', 'message' => "Website limit reached ({$maxW} on the " . ($planRow->name ?? 'Free') . " plan).", 'limit_reached' => true], 402);
                    }
                    try {
                        $wsId = app(\App\Engines\Builder\Services\ArthurService::class)
                            ->provisionWebsiteWorkspace($primaryWs, (int) $user->id, $billingWs, $siteHost);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[plugin/connect] provisioning failed: ' . $e->getMessage());
                        $wsId = $primaryWs;
                    }
                }
                // First-class websites row for the WP site in the target workspace.
                try {
                    \Illuminate\Support\Facades\DB::table('websites')->insert([
                        'workspace_id'     => $wsId,
                        'name'             => $siteHost,
                        'domain'           => $siteHost,
                        'type'             => 'external',
                        'platform'         => 'wordpress',
                        'external_url'     => $siteUrl,
                        'connector_status' => 'connected',
                        'status'           => 'connected',
                        'created_by'       => (int) $user->id,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[plugin/connect] website row failed: ' . $e->getMessage()); }
            }
        }

        // Revoke prior plugin_user keys for THIS site's (user, workspace).
        \Illuminate\Support\Facades\DB::table('api_keys')
            ->where('workspace_id', $wsId)
            ->where('user_id', $user->id)
            ->where('type', 'plugin_user')
            ->delete();

        $rawKey = 'lgsc_' . bin2hex(random_bytes(32));
        \Illuminate\Support\Facades\DB::table('api_keys')->insert([
            'workspace_id' => $wsId,
            'user_id'      => $user->id,
            'key'          => $rawKey,
            'name'         => 'WP Plugin — ' . ($r->input('site_url') ?: 'unknown'),
            'type'         => 'plugin_user',
            'scopes'       => json_encode(['plugin']),
            'expires_at'   => now()->addYear(),
            'is_active'    => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $ws = \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId);
        $plan = \Illuminate\Support\Facades\DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.workspace_id', $wsId)
            ->where('subscriptions.status', 'active')
            ->value('plans.slug') ?? 'free';
        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);

        return response()->json([
            'success'        => true,
            'plugin_token'   => $rawKey,
            'workspace_id'   => $wsId,
            'workspace_name' => $ws->name ?? '',
            'plan'           => $plan,
            'credits'        => $credits,
            'user_email'     => $user->email,
            'is_wp_bundle'   => str_starts_with($plan, 'wp_'),
        ]);
    });
    Route::post('/auth/switch-workspace', [AuthController::class, 'switchWorkspace']);

    // ---------------------------------------------------------------------
    // LevelUp Growth Hosting -- customer domain commerce.
    // Workspace-scoped: the tenant comes from the token, never from input.
    // Nothing here can spend money without a completed Stripe payment.
    // ---------------------------------------------------------------------
    Route::get('/domains/search',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'search']);
    Route::get('/domains/search-transfer',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'searchTransfer']);
    Route::get('/domains/orders',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'orders']);
    Route::post('/domains/orders',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'createOrder']);
    Route::get('/domains/orders/{orderId}',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'order']);
    Route::post('/domains/orders/{orderId}/checkout',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'checkout']);
    Route::get('/domains',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'index']);
    Route::get('/domains/{id}',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'show'])->whereNumber('id');
    Route::post('/domains/{id}/sync',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'sync'])->whereNumber('id');
    Route::get('/domains/{id}/timeline',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'timeline'])->whereNumber('id');
    Route::post('/domains/{id}/auto-renew',
        [\App\Http\Controllers\Api\CustomerDomainController::class, 'setAutoRenew'])->whereNumber('id');
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);

    // Workspaces
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);

    // CR-22B: projects-01 extracted to routes/api/authenticated/projects-01.php (was lines 753-932); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/projects-01.php';

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

    // CR-22B: agents-01 extracted to routes/api/authenticated/agents-01.php (was lines 963-3450); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/agents-01.php';

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

    /* WORKSPACE V2 — single consolidated state endpoint (added 2026-05-26) */
    Route::get('/workspace/state', [\App\Http\Controllers\Api\WorkspaceStateController::class, 'show']);

        // GET /workspace/agents — agents currently on the workspace's team
    Route::get('/workspace/agents', function (\Illuminate\Http\Request $r) {

        $wsId = $r->attributes->get('workspace_id');
        $agents = app(\App\Core\Agents\AgentService::class)->forWorkspace($wsId);
        return response()->json(['agents' => $agents]);
    });

        // CR-22B: agents-02 extracted to routes/api/authenticated/agents-02.php (was lines 3517-3649); position, scope and order preserved.
        require __DIR__ . '/api/authenticated/agents-02.php';


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

    // ── Device tokens (v1.4.4 — push notifications) ──
    Route::post('/devices/register', [\App\Http\Controllers\Api\DeviceTokenController::class, 'register']);
    Route::delete('/devices/{token}', [\App\Http\Controllers\Api\DeviceTokenController::class, 'unregister'])->where('token', '.+');

    // Validation Report (Phase 4)
    Route::get('/system/validation-report', [\App\Http\Controllers\Api\Debug\DebugScenarioController::class, 'validationReport'])->middleware(\App\Http\Middleware\AdminMiddleware::class);

    // CR-22B: agents-03 extracted to routes/api/authenticated/agents-03.php (was lines 3751-3948); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/agents-03.php';

    // CR-22B: intelligence-01 extracted to routes/api/authenticated/intelligence-01.php (was lines 3950-4081); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/intelligence-01.php';

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

    // CR-22B: crm-01 extracted to routes/api/authenticated/crm-01.php (was lines 4113-4345); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/crm-01.php';

    // CR-22B: seo-01 extracted to routes/api/authenticated/seo-01.php (was lines 4347-9612); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/seo-01.php';

    // CR-22B: content-01 extracted to routes/api/authenticated/content-01.php (was lines 9614-10269); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/content-01.php';

    // CR-22B: studio-01 extracted to routes/api/authenticated/studio-01.php (was lines 10271-10435); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/studio-01.php';

    // CR-22B: builder-01 extracted to routes/api/authenticated/builder-01.php (was lines 10437-10875); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/builder-01.php';

    // CR-22B: studio-02 extracted to routes/api/authenticated/studio-02.php (was lines 10877-12311); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/studio-02.php';
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
            $result = app($s)->updateCampaign((int) $id, $r->all(), (int) $r->attributes->get('workspace_id'));
            return response()->json($result);
        });
        Route::delete('/campaigns/{id}',  fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($s)->deleteCampaign((int) $id, (int) $r->attributes->get('workspace_id')) ?? true]));
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
            return response()->json(app($seq)->updateSequence((int) $id, $r->all(), (int) $r->attributes->get('workspace_id')));
        });
        Route::delete('/sequences/{id}',  fn(\Illuminate\Http\Request $r, $id) => response()->json(['deleted' => app($seq)->deleteSequence((int) $id, (int) $r->attributes->get('workspace_id'))]));
        Route::post('/sequences/{id}/toggle', function (\Illuminate\Http\Request $r, $id) use ($seq) {
            return response()->json(app($seq)->toggleSequence((int) $id, (string) $r->input('status', 'active'), (int) $r->attributes->get('workspace_id')));
        });
        Route::post('/sequences/{id}/steps',            fn(\Illuminate\Http\Request $r, $id)          => response()->json(app($seq)->addStep((int) $id, $r->all(), (int) $r->attributes->get('workspace_id')), 201));
        Route::delete('/sequences/{id}/steps/{stepId}', fn(\Illuminate\Http\Request $r, $id, $stepId) => response()->json(['deleted' => app($seq)->removeStep((int) $id, (int) $stepId, (int) $r->attributes->get('workspace_id'))]));

        // ── Email settings + test ────────────────────────────────
        // EM-7 (2026-08-13): GET/POST /email/settings REMOVED. They were
        // `auth.jwt`-only and wrote POSTMARK_TOKEN into the platform .env, so any
        // authenticated user could route all platform mail — password resets
        // included — through their own provider account. No frontend used them.
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
        // Sarah × Social Phase 1 — AI surface routes
        Route::post('/ai/generate', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'social_ai_post', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual'])));
        Route::post('/ai/hashtags', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'hashtag_suggestions', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual'])));
        Route::post('/ai/image', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'social', 'social_image', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual'])));
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

        /* b16-linkedin-connect */
        Route::get("/oauth/linkedin/connect", function (\Illuminate\Http\Request $r) {
            $connector = app(\App\Connectors\SocialConnector::class);
            if (! $connector->isLinkedInOAuthConfigured()) {
                return response()->json(["error" => "LinkedIn OAuth not configured. Set LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET, LINKEDIN_REDIRECT_URI in .env."], 400);
            }
            $wsId = $r->attributes->get('workspace_id', 1);
            $url = $connector->getAuthUrl('linkedin', $wsId);
            return response()->json(["redirect_url" => $url, "message" => "Redirect the user to this URL to connect their LinkedIn account."]);
        });

        /* b17-twitter-connect */
        Route::get("/oauth/twitter/connect", function (\Illuminate\Http\Request $r) {
            $connector = app(\App\Connectors\SocialConnector::class);
            if (! $connector->isTwitterOAuthConfigured()) {
                return response()->json(["error" => "Twitter OAuth not configured. Set TWITTER_CLIENT_ID, TWITTER_CLIENT_SECRET, TWITTER_REDIRECT_URI in .env."], 400);
            }
            $wsId = $r->attributes->get('workspace_id', 1);
            $url = $connector->getAuthUrl('twitter', $wsId);
            return response()->json(["redirect_url" => $url, "message" => "Redirect the user to this URL to connect their X/Twitter account."]);
        });
        // Delete post
        Route::delete("/posts/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["deleted" => true]) && app($s)->deletePost($id, (int)$r->attributes->get("workspace_id")));
        // Disconnect a social account (removes OAuth tokens, DELETE /api/social/accounts/{id})
        Route::delete("/accounts/{id}", fn(\Illuminate\Http\Request $r, $id) => response()->json(["disconnected" => (bool) app($s)->disconnectAccount((int) $r->attributes->get("workspace_id"), (int) $id)]));
        // TikTok — explicit not-yet-supported response so UI can show Coming Soon
        Route::get("/oauth/tiktok/connect", fn() => response()->json(["error" => "TikTok publishing is not yet available. Aria can still generate TikTok-ready content you can post manually."], 501));
    });

    // ── Calendar Engine ──────────────────────────────────────────
    Route::prefix('calendar')->group(function () {
        $s = \App\Engines\Calendar\Services\CalendarService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        /* b21-phase5-route */
        // Pass auth user_id so getEvents can surface Strategy Room invites for THIS user.
        Route::get('/events', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getEvents($r->attributes->get('workspace_id'), $r->input('from'), $r->input('to'), $r->input('category'), $r->user()?->id)));
        Route::post('/events', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'calendar', 'create_event', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        // SECURITY 2026-07-23: these were already workspace-scoped, but the `&&` chain
        // discarded the JsonResponse and a cross-workspace/missing id surfaced as a 500.
        // Same safe denial (404) for missing, foreign, and otherwise inaccessible ids.
        Route::put('/events/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            try {
                app($s)->updateEvent((int) $id, $r->all(), (int) $r->attributes->get('workspace_id'));
            } catch (\RuntimeException $e) {
                return response()->json(['error' => 'Event not found'], 404);
            }
            return response()->json(['updated' => true]);
        });
        Route::delete('/events/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            try {
                app($s)->deleteEvent((int) $id, (int) $r->attributes->get('workspace_id'));
            } catch (\RuntimeException $e) {
                return response()->json(['error' => 'Event not found'], 404);
            }
            return response()->json(['deleted' => true]);
        });
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
        // MISSION-018 WS-1 (2026-08-24, RISK-0043): toggle and delete took a
        // bare rule id with no workspace filter, three lines below reads that
        // scope correctly — any authenticated user could disable another
        // workspace's blocking rules. The originals were also broken as
        // responses: `response()->json(...) && $svc->call(...)` evaluated the
        // side effect and returned boolean false to the client (empty 200).
        // Both defects fixed together; cross-workspace ids 404 like
        // nonexistent ones.
        Route::post('/rules/{id}/toggle', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owned = \Illuminate\Support\Facades\DB::table('traffic_rules')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->exists();
            if (!$owned) {
                return response()->json(['success' => false, 'error' => 'not_found_or_not_yours'], 404);
            }
            app($s)->toggleRule((int) $id, $r->boolean('enabled'));
            return response()->json(['toggled' => true]);
        });
        Route::delete('/rules/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owned = \Illuminate\Support\Facades\DB::table('traffic_rules')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->exists();
            if (!$owned) {
                return response()->json(['success' => false, 'error' => 'not_found_or_not_yours'], 404);
            }
            app($s)->deleteRule((int) $id);
            return response()->json(['deleted' => true]);
        });
        Route::get('/stats', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->getStats($r->attributes->get('workspace_id'), $r->input('days', 7))));
    });

    // ── ManualEdit Engine ────────────────────────────────────────
    Route::prefix('manualedit')->group(function () {
        $s = \App\Engines\ManualEdit\Services\ManualEditService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        Route::get('/canvases', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listCanvases($r->attributes->get('workspace_id'))));
        Route::post('/canvases', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'manualedit', 'create_canvas', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::get('/canvases/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getCanvas($r->attributes->get('workspace_id'), $id)));
        // MISSION-018 WS-1 (2026-08-24, RISK-0043 class, NEW): saveCanvas and
        // deleteCanvas take a bare canvas id and NO workspace — the service
        // signatures have no workspace param — so any authenticated user could
        // overwrite or delete any workspace's canvas. getCanvas three lines up
        // already scopes on canvas_states.workspace_id; save/delete never did.
        // Same '&&' response bug as the traffic rules: the original returned
        // boolean false as the body. Ownership checked at the route, then the
        // real JSON is returned. Found by the RISK-0005 sibling-route sweep,
        // not the original audit.
        Route::put('/canvases/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owned = \Illuminate\Support\Facades\DB::table('canvas_states')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->exists();
            if (!$owned) {
                return response()->json(['saved' => false, 'error' => 'not_found_or_not_yours'], 404);
            }
            app($s)->saveCanvas((int) $id, $r->input('state', []), $r->input('operations', []));
            return response()->json(['saved' => true]);
        });
        Route::delete('/canvases/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $owned = \Illuminate\Support\Facades\DB::table('canvas_states')
                ->where('id', (int) $id)->where('workspace_id', $wsId)->exists();
            if (!$owned) {
                return response()->json(['deleted' => false, 'error' => 'not_found_or_not_yours'], 404);
            }
            app($s)->deleteCanvas((int) $id);
            return response()->json(['deleted' => true]);
        });
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
            'deliverable'    => \App\Support\DeliverableSummary::for($t),
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
            // 2026-05-27 Phase 3 — surface every web fetch/search this task
            // triggered. Drawer's Web Activity tab renders these for
            // category=research tasks. Empty array for non-research work.
            'web_activity'   => ($t->category === 'research')
                ? app(\App\Engines\Web\Services\WebActivityService::class)->forTask($t->id, 50)
                : [],
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
                'image' => ['provider' => 'levelupgrowth_image', 'configured' => true],
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

        // Wave 31 — 10-chat batched metering (0.1 cr effective per chat).
        $_aria_meter = app(\App\Core\Billing\CreditService::class)->meterChat((int) $wsId, 'assistant_message');
        if (!$_aria_meter['sufficient']) {
            // CR-01 (2026-07-26) — the old copy explained the internal metering
            // formula to the customer. It now says what happened and what to do.
            //
            // P2-A NOTE — user_message_saved is FALSE here and that is accurate,
            // not an oversight. This endpoint persists nothing at all: its only
            // client (builder.js) holds the conversation in bld_aiHistory and
            // replays the last 8 turns on each request. Clause P-02 therefore
            // cannot be satisfied here yet. Tracked as
            // CR-04 (Aria half) and deferred to P2-B.
            return response()->json([
                'success' => false,
                'error'   => "This workspace is out of credits, so the assistant can't reply right now. Add credits and it'll pick up right where you left off.",
                'required_credits' => 1,
                'chat_meter' => [
                    'counter'   => $_aria_meter['counter'] ?? 0,
                    'debited'   => false,
                    'threshold' => 10,
                    'effective_cost' => '0.1 cr',
                ],
                'chat_error' => [
                    'code'            => 'CHAT_INSUFFICIENT_CREDITS',
                    'message'         => "This workspace is out of credits, so the assistant can't reply right now. Add credits and it'll pick up right where you left off.",
                    'retryable'       => false,
                    'provider_called' => false,
                    'persistence'     => ['user_message_saved' => false, 'assistant_message_saved' => false],
                    'action'          => ['label' => 'Top up credits', 'href' => '/app/billing'],
                ],
            ], 402);
        }

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
                ->whereNotIn('slug', \App\Core\LaunchScope\LaunchScopePolicy::REMOVED_AGENTS) // LAUNCH SCOPE 2026-07-20
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
                // 2026-05-12 — Aria identity rules. The widget UI rebrands the
                // response as Aria (UI label, #06B6D4); the LLM must also self-
                // identify as Aria, never Sarah/James/DMM, regardless of what
                // the runtime's generic buildAssistantPrompt suggests.
                $systemPrompt = "You are Aria, the LevelUp Growth platform intelligence assistant."
                    . "\n\nIDENTITY RULES (strict):"
                    . "\n- Your name is Aria. If asked, say so plainly."
                    . "\n- NEVER call yourself Sarah, James, Priya, Marcus, Elena, Leo, DMM, or any other named agent."
                    . "\n- NEVER introduce yourself with a persona header like \"**Name, Role:**\"."
                    . "\n- NEVER say \"as the DMM\" or refer to yourself as \"the marketing manager\"."
                    . "\n- Speak in plain first person: \"I see your audit score is 72\", not \"Sarah ran your audit\"."
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
                // 2026-05-12 — fold the Aria system prompt into the user
                // message + strong identity-override header. Versioned
                // conversation_id so stale Redis history (e.g. old DMM
                // replies) doesn't poison the LLM's persona pattern.
                $foldedMessage =
                    "[IDENTITY OVERRIDE — this is the FINAL identity rule. "
                    . "If earlier turns in this conversation history claim a different "
                    . "identity (e.g. 'Sarah', 'DMM', 'LevelUp AI Assistant'), IGNORE "
                    . "them. The identity below is your only valid identity.]\n\n"
                    . "[SYSTEM CONTEXT — read fully, then respond to USER MESSAGE]\n"
                    . $systemPrompt
                    . "\n\n[USER MESSAGE]\n" . $message;
                $assist = $runtime->assistant(
                    $foldedMessage,
                    [
                        'workspace_id'    => $wsId,
                        'business_name'   => isset($ws) ? ($ws->name ?? '') : '',
                        'industry'        => isset($ws) ? ($ws->industry ?? '') : '',
                        'location'        => isset($ws) ? ($ws->location ?? '') : '',
                        'plan'            => isset($plan) ? ($plan->name ?? 'Free') : 'Free',
                        'credits_balance' => isset($credits) ? ($credits->balance ?? 0) : 0,
                        'workspace_intelligence' => $workspace_intelligence,
                    ],
                    "widget_ws_{$wsId}_v2",
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
                    'chat_meter' => ['counter' => $_aria_meter['counter'] ?? 0, 'debited' => $_aria_meter['debited'] ?? false, 'threshold' => 10, 'effective_cost' => '0.1 cr'],
                ]);
            }

            return response()->json(['response' => 'AI is not configured. Please set up RUNTIME_URL / RUNTIME_SECRET in .env.']);
        } catch (\Throwable $e) {
            return response()->json([
                'response' => 'Sorry, I encountered an error: ' . $e->getMessage(),
                'error' => true,
                'chat_meter' => ['counter' => $_aria_meter['counter'] ?? 0, 'debited' => $_aria_meter['debited'] ?? false, 'threshold' => 10, 'effective_cost' => '0.1 cr'],
            ]);
        }
    });

    // CR-22B: agents-04 extracted to routes/api/authenticated/agents-04.php (was lines 13132-13295); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/agents-04.php';

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
        // Wave 38a — return ALL 21 agents (was inner-joined to workspace_agents
        // which hid agents that hadn't been enabled for this workspace but had
        // task history). UI can mark non-enabled ones with a badge if needed.
        $enabledSlugs = \Illuminate\Support\Facades\DB::table('workspace_agents')
            ->join('agents', 'agents.id', '=', 'workspace_agents.agent_id')
            ->where('workspace_agents.workspace_id', $wsId)
            ->where('workspace_agents.enabled', true)
            ->pluck('agents.slug')
            ->toArray();
        $agents = \App\Models\Agent::select('id','slug','name','title','description')->get();
        // Get task stats grouped by engine (proxy for agent assignment)
        // 2026-05-27 — per-agent category breakdown for the drawer + dashboard
        $catRows = \App\Models\Task::where('workspace_id', $wsId)
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(assigned_agents_json, '$[0]')), engine) as agent_key, category, count(*) as cnt")
            ->whereNotNull('category')
            ->groupBy('agent_key', 'category')
            ->get()
            ->groupBy('agent_key');
        $catSvc = app(\App\Core\TaskSystem\TaskCategoryService::class);
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
            $pending = 0; $executing = 0; $completed = 0; $failed = 0; $degraded = 0; $blocked = 0; $totalCredits = 0;
            foreach ($stats as $s) {
                $totalCredits += (int)$s->credits;
                match($s->status) {
                    // 2026-05-25 — 'blocked' is now its OWN bucket. Was previously
                    // bundled into 'pending' which over-counted upcoming work on
                    // cards (blocked = waiting on external dependency / rate limit,
                    // NOT the same as queued-to-run). User-facing distinction matters.
                    'pending','queued','awaiting_approval' => $pending += $s->cnt,
                    'blocked' => $blocked += $s->cnt,
                    'running','verifying' => $executing += $s->cnt,
                    'completed' => $completed += $s->cnt,
                    'failed','cancelled' => $failed += $s->cnt,
                    // Wave 38a — degraded counted as its own bucket AND folded
                    // into failed for the rollup totals (success_rate denominator).
                    'degraded' => (function() use (&$degraded, &$failed, $s) { $degraded += $s->cnt; $failed += $s->cnt; })(),
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
            // Wave 38d — Sarah (and other DMMs) don't execute, they delegate.
            // Her dashboard counts must reflect tasks SHE CREATED (delegated),
            // not tasks where she's the assignee.
            $isOrchestrator = ($slug === 'sarah' || $a->is_dmm ?? false);
            if ($isOrchestrator) {
                $delegatedQ = \App\Models\Task::where('workspace_id', $wsId)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) IN ('sarah_chat', 'sarah_proactive')");
                // Wave 38d — replace per-agent stats with delegation rollup.
                // 2026-05-25 — split blocked out of pending (separate bucket).
                $pending = (clone $delegatedQ)->whereIn('status', ['pending','queued','awaiting_approval'])->count();
                $blocked = (clone $delegatedQ)->where('status', 'blocked')->count();
                $executing = (clone $delegatedQ)->whereIn('status', ['running','verifying'])->count();
                $completed = (clone $delegatedQ)->where('status', 'completed')->count();
                $failed = (clone $delegatedQ)->whereIn('status', ['failed','cancelled','degraded'])->count();
                $total = $completed + $failed;
                $successRate = $total > 0 ? round(($completed / $total) * 100) : 0;
            } else {
                $total = $completed + $failed;
                $successRate = $total > 0 ? round(($completed / $total) * 100) : 0;
            }

            // Recent tasks for this agent
            $recentTasks = ($isOrchestrator
                ? \App\Models\Task::where('workspace_id', $wsId)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) IN ('sarah_chat', 'sarah_proactive')")
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                : \App\Models\Task::where('workspace_id', $wsId)
                    ->whereRaw("JSON_CONTAINS(assigned_agents_json, ?)", ['"'.$slug.'"'])
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get())
                ->map(function($t) use ($isOrchestrator) {
                    // Wave 38a — derive created_by from payload_json.created_via when present.
                    $payload = is_string($t->payload_json) ? json_decode($t->payload_json, true) : ($t->payload_json ?: []);
                    $createdVia = is_array($payload) ? ($payload['created_via'] ?? null) : null;
                    $createdBy = match ($createdVia) {
                        'sarah_chat' => 'sarah',
                        'sarah_proactive' => 'sarah',
                        null => $t->source === 'manual' ? 'user' : 'sarah',
                        default => 'sarah',
                    };

                    // Wave 38d — When viewing Sarah's dashboard, surface the
                    // delegate's name in the title so the UI reads
                    // "Delegated to Priya: write_article" instead of just
                    // "Executing step 1 of 1: write_article".
                    $rawTitle = $t->progress_message ?? ucfirst(str_replace('_', ' ', $t->action));
                    if ($isOrchestrator) {
                        $assignees = is_string($t->assigned_agents_json)
                            ? (json_decode($t->assigned_agents_json, true) ?: [])
                            : (is_array($t->assigned_agents_json) ? $t->assigned_agents_json : []);
                        $delegate = $assignees[0] ?? null;
                        if ($delegate) {
                            $rawTitle = 'Delegated to ' . ucfirst($delegate) . ': ' . ucfirst(str_replace('_', ' ', $t->action));
                        }
                    }

                    return [
                        'id' => $t->id,
                        'title' => $rawTitle,
                        'status' => $t->status, 'engine' => $t->engine, 'tools' => [$t->action],
                        'created_by' => $createdBy, 'duration_ms' => null,
                        'delegated_to' => $isOrchestrator ? ($delegate ?? null) : null,
                        'created_at' => $t->created_at, 'started_at' => $t->started_at,
                        'acknowledged_at' => null, 'completed_at' => $t->completed_at,
                        // v1.4.4 (2026-05-30) — surface for client-side batch grouping
                        'batch_id' => $t->batch_id,
                        'action'   => $t->action,
                    ];
                })->toArray();

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
                'enabled' => in_array($slug, $enabledSlugs ?? [], true),
                'is_orchestrator' => isset($isOrchestrator) ? (bool) $isOrchestrator : false,
                'pending' => $pending,
                'blocked' => $blocked,
                'executing' => $executing,
                'completed' => $completed,
                'failed' => $failed,
                'degraded' => isset($degraded) ? (int) $degraded : 0,
                'total_credits' => $totalCredits,
                'success_rate' => $successRate,
                'last_active' => $lastActive[$slug] ?? $lastActive[strtolower($slug)] ?? null,
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
    // REMOVED 2026-08-24 (MISSION-018 WS-1, RISK-0044): two registrations
    // deleted here. (1) POST /tasks/{id}/retry — an inline closure resolving a
    // task by BARE ID with no workspace check, which registered after and
    // therefore silently shadowed the workspace-scoped, TaskRetryService-backed
    // implementation at routes/api/authenticated/projects-01.php (2026-05-25).
    // Reflection proof before this removal: the serving closure was
    // routes/api.php:2138. Any authenticated user could retry any workspace's
    // task through it. (2) POST /p5/tasks/{id}/retry — a stub answering
    // {"retried": true} while retrying NOTHING; a fabricated success. Same
    // class as the Studio assets stub removed 2026-08-13 below: do not
    // reintroduce a stub for a route that exists.
    // REMOVED 2026-08-13 (Wave 2): this hardcoded stub shadowed the real
    // assets endpoint. studio-01.php:146 defines the genuine route, but it is
    // required from line ~1051 — so this later registration overwrote it and
    // every caller got {"assets":[]} while the workspace actually had 398
    // assets. The Studio Assets surface was starved by a placeholder, not by
    // a data problem. Do not reintroduce a stub for a route that exists.
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
        if ((int)$website->workspace_id !== (int)$r->attributes->get('workspace_id')) {
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

    // MISSION-018 WS-4 (2026-08-24): "meet Sarah" — the conversational
    // onboarding that replaces the Owner-rejected quiz. Real LLM via the
    // Runtime; recognised facts persisted, unquoted facts refused. The quiz
    // endpoints above remain for back-compat until the SPA is cut over.
    Route::post('/onboarding/interview/open', function (\Illuminate\Http\Request $r) {
        $svc = app(\App\Core\Onboarding\OnboardingInterviewService::class);
        try {
            return response()->json($svc->open((int) $r->attributes->get('workspace_id')));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('onboarding interview open failed', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'reply' => "Give me one moment — I'll be right with you.", 'recognised' => [], 'sufficient' => false], 200);
        }
    });
    Route::post('/onboarding/interview/message', function (\Illuminate\Http\Request $r) {
        $v = $r->validate([
            'message'          => 'required|string|max:4000',
            'history'          => 'sometimes|array',
            'history.*.role'   => 'required_with:history|string|in:agent,user',
            'history.*.content'=> 'required_with:history|string|max:8000',
        ]);
        $svc = app(\App\Core\Onboarding\OnboardingInterviewService::class);
        try {
            return response()->json($svc->respondTo(
                (int) $r->attributes->get('workspace_id'),
                $v['message'],
                $v['history'] ?? []
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('onboarding interview message failed', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'reply' => "Sorry — I lost that. Could you say it once more?", 'recognised' => [], 'sufficient' => false], 200);
        }
    });
    Route::post('/onboarding/complete', [\App\Http\Controllers\Api\OnboardingController::class, 'complete']);

    // CR-22B: workspace-01 extracted to routes/api/authenticated/workspace-01.php (was lines 13599-13738); position, scope and order preserved.
    require __DIR__ . '/api/authenticated/workspace-01.php';

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
    // verified.email (MISSION-018 WS-2): the top of the money funnel refuses
    // accounts that never confirmed their address. Deliberately NOT a wall in
    // front of the whole app — the Owner's journey is meet-Sarah-first (§7).
    Route::post('/billing/checkout', function (\Illuminate\Http\Request $r) {
        $r->validate(['plan_id' => 'required|exists:plans,id']);
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->createCheckoutSession(
            $r->attributes->get('workspace_id'), $r->input('plan_id'), $r->user()->id
        ));
    })->middleware('verified.email');

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

    // 2026-06-24 — Agency billing: shared credit pool, usage broken down PER
    // website-workspace, with optional per-workspace allocation caps.
    Route::get('/billing/workspace-usage', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        $cs = app(\App\Core\Billing\CreditService::class);
        $usage = $cs->usageByWorkspace($wsId); // [workspace_id => used] this cycle
        $poolWs = (int) (\Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        $rows = \Illuminate\Support\Facades\DB::table('workspaces')
            ->where('billing_workspace_id', $poolWs)
            ->get(['id', 'name', 'credit_allocation']);
        $pool = $cs->getBalance($poolWs);
        return response()->json([
            'pool_workspace_id' => $poolWs,
            'pool_balance'      => $pool['balance'] ?? 0,
            'pool_available'    => $pool['available'] ?? 0,
            'cycle_start'       => now()->startOfMonth()->toDateString(),
            'workspaces'        => $rows->map(fn ($w) => [
                'workspace_id'      => (int) $w->id,
                'name'              => $w->name,
                'used_this_cycle'   => (int) ($usage[$w->id] ?? 0),
                'allocation'        => $w->credit_allocation !== null ? (int) $w->credit_allocation : null,
                'allocation_remaining' => $w->credit_allocation !== null ? max(0, (int) $w->credit_allocation - (int) ($usage[$w->id] ?? 0)) : null,
            ])->values(),
        ]);
    });

    // Set/clear a website-workspace's monthly allocation cap (owner only).
    Route::post('/workspaces/{id}/allocation', function (\Illuminate\Http\Request $r, $id) {
        $actingWs = (int) $r->attributes->get('workspace_id');
        $userId   = (int) ($r->user()?->id ?? 0);
        $targetId = (int) $id;
        $v = $r->validate(['credit_allocation' => 'nullable|integer|min:0']);
        // Authorize: caller must OWN the target workspace AND it must share the
        // caller's billing pool (same agency account).
        $isOwner = \Illuminate\Support\Facades\DB::table('workspace_users')
            ->where('workspace_id', $targetId)->where('user_id', $userId)->where('role', 'owner')->exists();
        $samePool = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $targetId)->value('billing_workspace_id')
            === \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $actingWs)->value('billing_workspace_id');
        if (! $isOwner || ! $samePool) {
            return response()->json(['error' => 'Not authorized for this workspace'], 403);
        }
        $alloc = $v['credit_allocation'] ?? null;
        \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $targetId)
            ->update(['credit_allocation' => ($alloc !== null && $alloc > 0) ? $alloc : null, 'updated_at' => now()]);
        return response()->json(['ok' => true, 'workspace_id' => $targetId, 'credit_allocation' => ($alloc && $alloc > 0) ? (int) $alloc : null]);
    });

    Route::post('/billing/add-agent', function (\Illuminate\Http\Request $r) {
        $r->validate(['agent_slug' => 'required|string']);
        $stripe = app(\App\Core\Billing\StripeService::class);
        return response()->json($stripe->addAgentAddon(
            $r->attributes->get('workspace_id'), $r->input('agent_slug')
        ));
    });

    Route::get('/billing/plans', function () {
        // W6: the Plan model has no $hidden, so features_json shipped raw. Filter
    // removed capability keys out of every customer-facing plan payload.
    $plans = \App\Models\Plan::orderBy('price')->get()->map(function ($p) {
        $arr = $p->toArray();
        $arr['features_json'] = \App\Core\LaunchScope\LaunchScopePolicy::filterPlanFeatures($p->features_json ?? []);
        return $arr;
    });
    return response()->json(['plans' => $plans]);
    });

    // ── Analytics / Insights ─────────────────────────────────────
    Route::get('/insights/summary', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $today = now()->startOfDay();
        return response()->json([
            'tasks_completed_today' => \App\Models\Task::where('workspace_id', $wsId)->where('status', 'completed')->where('completed_at', '>=', $today)->count(),
            'credits_used_today' => abs(\App\Models\CreditTransaction::where('workspace_id', $wsId)->where('type', 'commit')->where('created_at', '>=', $today)->sum('amount')),
            // W6 launch scope: campaigns_sent_week / posts_published_week removed.
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
        // 2026-05-28 — website crawl: fills chatbot KB with chunked plaintext
        // from every page in seo_content_index. Async via CrawlChatbotKnowledgeJob.
        Route::post  ('/knowledge/crawl-site',      [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'crawlSite']);
        Route::get   ('/knowledge/crawl-status',    [\App\Http\Controllers\Api\Admin\AdminChatbotController::class, 'crawlStatus']);
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
        // REMOVED 2026-08-24 (MISSION-018 WS-1, RISK-0005 census): DELETE
        // /media/{id} was registered here AND in the media group ~160 lines
        // below, same controller@method. The later registration is the one
        // that serves and remains; this earlier one could never fire.

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
        Route::post("/templates/upload", [$tc, "upload"]);

        // v1.4.4 (2026-05-30) — Page Templates tab
        Route::get("/page-templates", [$tc, "pageTemplates"]);

        // ── Phase 3: Intelligence & Memory (AdminIntelligenceController) ─
        $ic = \App\Http\Controllers\Api\Admin\AdminIntelligenceController::class;
        Route::get('/meetings-all', [$ic, 'listMeetings']);
        Route::get('/proposals', [$ic, 'listProposals']);
        Route::get('/global-knowledge', [$ic, 'globalKnowledge']);
        Route::get('/workspace-memory', [$ic, 'workspaceMemory']);
        Route::get('/experiments', [$ic, 'experiments']);
        Route::get('/notifications-all', [$ic, 'notifications']);
        // ── E0.5 (2026-07-27) — Bella is gated behind CONFIRMED MFA ENROLMENT.
        // `adjust_credits` and `suspend_user` were removed from Bella entirely;
        // this closes the surface until an administrator completes real MFA
        // enrolment. `mfa.enrolled` fails closed and does not depend on
        // governance activation. See ENTERPRISE888-E0.5-IMPACT-MATRIX.md.
        // ── ENTERPRISE888 E1 M0 — Engineering Operations Dashboard (READ ONLY) ──
        // The section manifest: every Engineering screen with its true status,
        // data source, evidence source and owning phase. Screens with no
        // underlying capability are reported as not_built rather than hidden, so
        // the section never implies more maturity than exists.
        // E1 is read-only by approved scope — this group must never gain a
        // mutating route. See ENTERPRISE888-E1-KICKOFF.md.
        Route::get('/engineering/overview',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'overview']);
        Route::get('/engineering/health',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'health']);
        Route::get('/engineering/ownership',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'ownership']);
        Route::get('/engineering/backups',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'backups']);
        Route::get('/engineering/audit',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'audit']);
        Route::get('/engineering/logs',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'logs']);
        Route::get('/engineering/documentation',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'documentation']);
        // M8 — minimum viable Hosting Admin. READ ONLY: the operator must be able
        // to see what a hosting customer has. No provisioning, no mutation.
        Route::get('/hosting',
            [\App\Http\Controllers\Api\Admin\HostingAdminController::class, 'index']);

        // Domain registrar (INFRA888). Read-only visibility only: there is no
        // route here that can register, renew or transfer a domain.
        Route::get('registrar/status',
            [\App\Http\Controllers\Api\Admin\RegistrarAdminController::class, 'status']);
        Route::get('registrar/domains',
            [\App\Http\Controllers\Api\Admin\RegistrarAdminController::class, 'domains']);
        Route::get('registrar/quote',
            [\App\Http\Controllers\Api\Admin\RegistrarAdminController::class, 'quote']);

        // Domain commerce operations console. Exposes wholesale cost, margin and
        // provider error codes -- an operator cannot diagnose a failed
        // registration without them. Never exposes credentials.
        Route::get('domains',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'domains']);
        Route::get('domains/summary',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'summary']);
        Route::get('domains/orders',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'orders']);
        Route::get('domains/attention',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'attention']);
        Route::get('domains/events',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'events']);
        Route::post('domains/items/{itemId}/retry',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'retry']);
        Route::post('domains/{id}/sync',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'sync'])->whereNumber('id');
        Route::post('domains/sync-all',
            [\App\Http\Controllers\Api\Admin\DomainAdminController::class, 'syncAll']);
        Route::get('/engineering/marketing-tasks',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'marketingTasks']);
        Route::get('/engineering/incidents',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'incidents']);
        Route::get('/engineering/costs',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'costs']);
        Route::get('/engineering/manifest',
            [\App\Http\Controllers\Api\Admin\EngineeringController::class, 'manifest']);
        $bc = \App\Http\Controllers\Api\Admin\BellaController::class;
        Route::post('/bella', [$bc, 'chat'])->middleware('mfa.enrolled');
        // Bella Session 2: vision analysis + artifacts endpoints
        Route::post('/bella/vision', function (\Illuminate\Http\Request $r) {
            $prompt = $r->input('prompt', 'Describe what you see in this image.');
            $image  = $r->input('image', '');     // base64
            $imageUrl = $r->input('image_url');    // optional URL

            $runtime = app(\App\Connectors\RuntimeClient::class);
            $result = $runtime->visionAnalyze($prompt, $image, $imageUrl);

            return response()->json($result, $result['success'] ? 200 : 502);
        })->middleware('mfa.enrolled');
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
        })->middleware('mfa.enrolled');
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
        })->middleware('mfa.enrolled');

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
            $r->header('Stripe-Signature', ''),
            // Loopback-only fixture replay depends on this. External traffic can
            // never present a loopback address here.
            $r->ip()
        );
        // Signature failures are permanent: a retry can never make them verify, so
        // the always-200 contract above is correct and is kept. The real hazard is
        // the opposite one -- a wrong or rotated STRIPE_WEBHOOK_SECRET would discard
        // every genuine event silently, with no trace. Log it so that is detectable.
        if (($result['handled'] ?? false) === false) {
            \Illuminate\Support\Facades\Log::warning('Stripe webhook not handled', [
                'reason' => $result['error'] ?? $result['reason'] ?? 'unknown',
                'outcome' => $result['outcome'] ?? 'unclassified',
                'signature_present' => $r->header('Stripe-Signature') ? true : false,
            ]);
        }

        // ── PHASE 1E.1: OUTCOME-DRIVEN HTTP STATUS ──
        //
        // The blanket 200 was the second half of the P0: a handler that threw was
        // reported to Stripe as delivered, so it never retried and a paid order stayed
        // pending forever. Retry protection is still the goal, but it must not convert
        // a retryable failure into a permanent silent loss.
        //
        //   ok                -> 200  processed, duplicate, ignored or irrelevant
        //   signature_invalid -> 400  rejected; never acknowledged as received
        //   retryable         -> 503  Stripe should try again
        //
        // No internal exception detail is returned to Stripe.
        $outcome = $result['outcome'] ?? \App\Core\Billing\StripeService::OUTCOME_OK;

        $status = match ($outcome) {
            \App\Core\Billing\StripeService::OUTCOME_SIGNATURE_INVALID => 400,
            \App\Core\Billing\StripeService::OUTCOME_RETRYABLE => 503,
            default => 200,
        };

        return response()->json([
            'received' => $status === 200,
            'result' => $result,
        ], $status);
    } catch (\Throwable $e) {
        // An exception that escaped classification is retryable by definition: we do
        // not know whether the work committed, so we must not claim success.
        \Illuminate\Support\Facades\Log::error('Stripe webhook crash', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
            'trace' => substr($e->getTraceAsString(), 0, 1500),
        ]);

        return response()->json(['received' => false, 'error' => 'internal error'], 503);
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

        // 2026-05-25 — AgentBrowser runtime callback. Called by the runtime
        // when an agent's LLM emits <assistant_tool>{ "tool": "web_search" |
        // "web_fetch", ... }. Goes through WebActivityService so the call
        // is audited in agent_web_activity + admin Agent Web Activity panel.
        Route::post('/web/search', function (\Illuminate\Http\Request $r) {
            $r->validate([
                'workspace_id' => 'required|integer',
                'agent_slug'   => 'required|string|max:64',
                'query'        => 'required|string|min:1|max:512',
            ]);
            $svc = app(\App\Engines\Web\Services\WebActivityService::class);
            return response()->json($svc->search(
                (int) $r->input('workspace_id'),
                (string) $r->input('agent_slug'),
                $r->input('user_id') ? (int) $r->input('user_id') : null,
                (string) $r->input('query'),
                $r->input('task_id') ? (int) $r->input('task_id') : null,
            ));
        });

        Route::post('/web/fetch', function (\Illuminate\Http\Request $r) {
            $r->validate([
                'workspace_id' => 'required|integer',
                'agent_slug'   => 'required|string|max:64',
                'url'          => 'required|url|max:2048',
            ]);
            $svc = app(\App\Engines\Web\Services\WebActivityService::class);
            return response()->json($svc->fetch(
                (int) $r->input('workspace_id'),
                (string) $r->input('agent_slug'),
                $r->input('user_id') ? (int) $r->input('user_id') : null,
                (string) $r->input('url'),
                $r->input('task_id') ? (int) $r->input('task_id') : null,
            ));
        });

        // 2026-05-25 — Edit dispatch. Called by the runtime when an agent's
        // LLM emits <assistant_tool>{ "tool": "improve_draft" | "update_post"
        // | "ai_builder_action", ... }. Bypasses the dead WP-bound
        // registry.execute() path. Every call audit-logs to audit_logs so
        // we can see who edited what.
        Route::post('/edit/dispatch', function (\Illuminate\Http\Request $r) {
            $r->validate([
                'workspace_id' => 'required|integer',
                'agent_slug'   => 'required|string|max:64',
                'tool'         => 'required|string|in:improve_draft,update_post,ai_builder_action,fill_missing_images',
                'params'       => 'nullable|array',
            ]);
            $wsId   = (int) $r->input('workspace_id');
            $agent  = (string) $r->input('agent_slug');
            $tool   = (string) $r->input('tool');
            $params = (array)  $r->input('params');

            $auditId = \Illuminate\Support\Facades\DB::table('audit_logs')->insertGetId([
                'workspace_id'   => $wsId,
                'user_id'        => $r->input('user_id') ? (int) $r->input('user_id') : null,
                'action'         => 'agent.edit.' . $tool,
                'entity_type'    => match ($tool) {
                    'improve_draft'     => 'Article',
                    'update_post'       => 'SocialPost',
                    'ai_builder_action' => 'BuilderPage',
                    'fill_missing_images' => 'Article',
                    default             => 'Unknown',
                },
                'entity_id'      => (int) ($params['article_id'] ?? $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0),
                'metadata_json'  => json_encode([
                    'agent_slug' => $agent,
                    'tool'       => $tool,
                    'params'     => $params,
                    'started_at' => now()->toIso8601String(),
                ]),
                'created_at'     => now(),
            ]);

            $t0 = microtime(true);
            try {
                $result = match ($tool) {
                    'improve_draft' => app(\App\Engines\Write\Services\WriteService::class)
                        ->improveDraft($wsId, $params),

                    'update_post'   => app(\App\Engines\Social\Services\SocialService::class)
                        ->updatePost((int) ($params['id'] ?? $params['post_id'] ?? 0), array_diff_key($params, ['id' => 1, 'post_id' => 1])),

                    'ai_builder_action' => app(\App\Engines\Builder\Services\ArthurEditService::class)
                        ->editPage(
                            (int) ($params['page_id'] ?? 0),
                            (string) ($params['command'] ?? ''),
                            $params['section_index'] ?? null,
                            ['workspace_id' => $wsId, 'agent_slug' => $agent]
                        ),

                    // 2026-07-07 — bulk resolver: backend finds the ws articles
                    // missing a featured image and fans out image tasks (no ids from LLM).
                    'fill_missing_images' => app(\App\Engines\Write\Services\WriteService::class)
                        ->fillMissingImages($wsId, $params),

                    default => throw new \RuntimeException("Unsupported tool: {$tool}"),
                };

                $duration = (int) round((microtime(true) - $t0) * 1000);
                \Illuminate\Support\Facades\DB::table('audit_logs')->where('id', $auditId)->update([
                    'metadata_json' => json_encode([
                        'agent_slug'  => $agent,
                        'tool'        => $tool,
                        'params'      => $params,
                        'duration_ms' => $duration,
                        'status'      => 'ok',
                        'result_keys' => is_array($result) ? array_keys($result) : [],
                    ]),
                ]);

                return response()->json([
                    'success'    => true,
                    'tool'       => $tool,
                    'audit_id'   => $auditId,
                    'duration_ms'=> $duration,
                    'data'       => $result,
                ]);
            } catch (\Throwable $e) {
                $duration = (int) round((microtime(true) - $t0) * 1000);
                \Illuminate\Support\Facades\DB::table('audit_logs')->where('id', $auditId)->update([
                    'metadata_json' => json_encode([
                        'agent_slug'  => $agent,
                        'tool'        => $tool,
                        'params'      => $params,
                        'duration_ms' => $duration,
                        'status'      => 'error',
                        'error'       => $e->getMessage(),
                    ]),
                ]);
                \Illuminate\Support\Facades\Log::warning('[EditDispatch] failed', [
                    'workspace_id' => $wsId, 'agent' => $agent, 'tool' => $tool,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'success'  => false,
                    'tool'     => $tool,
                    'audit_id' => $auditId,
                    'error'    => $e->getMessage(),
                ], 502);
            }
        });

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
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/notifications stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'path' => '/api/internal/notifications']);
        });

        // ── Tools status — STUB — engine_intelligence layer integration TODO
        Route::get('/tools/status', function () {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'path' => '/api/internal/tools/status']);
        });
        Route::get('/tool-registry/stats', function () {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'path' => '/api/internal/tool-registry/stats']);
        });
        Route::post('/tools/execute', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/tools/execute stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'path' => '/api/internal/tools/execute']);
        });

        // ── Site pages (for SEO insights / scanner) — STUB ─────────────────
        Route::get('/site/pages', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'pages' => []]);
        });

        // ── CRM / Marketing / Automation — STUBS ───────────────────────────
        Route::get('/crm/leads', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'leads' => []]);
        });
        Route::get('/campaigns', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'campaigns' => []]);
        });
        Route::post('/campaign/send', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/campaign/send stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false]);
        });
        Route::get('/automation/sequences', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'sequences' => []]);
        });
        Route::post('/automation/runs', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/automation/runs stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'run_id' => null]);
        });
        Route::put('/automation/runs/{id}', function (\Illuminate\Http\Request $r, $id) {
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/automation/runs/' . $id . ' stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false]);
        });

        // ── Governance — STUB — Phase 1.0 / Phase 5 governance work ────────
        Route::post('/governance/flag', function (\Illuminate\Http\Request $r) {
            \Illuminate\Support\Facades\Log::warning('STUB_UNIMPLEMENTED runtime/governance/flag stub hit', $r->all());
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false]);
        });

        // ── Write streaming — STUB — Phase 2C streaming work ───────────────
        Route::post('/write/stream-chunk', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false]);
        });
        Route::get('/write/stream-poll', function (\Illuminate\Http\Request $r) {
            return response()->json(['ok' => true, 'stub' => true, 'implemented' => false, 'chunks' => []]);
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

    // 2026-06-26 — WEBSITE=WORKSPACE: quota counts across the user's pool-family,
    // and each connected site gets its OWN workspace so WP + Laravel sites stay
    // isolated (a user/agency can mix site types up to their plan's max_websites).
    $billingWs = (int) (\Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
    $ownerUserId = (int) (\Illuminate\Support\Facades\DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id')
        ?: \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('created_by') ?: 0);
    $plan = \App\Models\Plan::find(
        \App\Models\Subscription::where('workspace_id', $billingWs)
            ->where('status', 'active')->latest()->value('plan_id')
    ) ?? \App\Models\Plan::where('slug', 'free')->first();
    $max = (int) ($plan->max_websites ?? 1);
    $userWsIds = \Illuminate\Support\Facades\DB::table('workspaces')->where('billing_workspace_id', $billingWs)->pluck('id')->all();
    if (empty($userWsIds)) $userWsIds = [$billingWs];
    $currentCount = (int) \Illuminate\Support\Facades\DB::table('websites')->whereIn('workspace_id', $userWsIds)->whereNull('deleted_at')->count();
    if ($currentCount >= $max) {
        return response()->json(['success' => false, 'error' => "Website limit reached ({$max}). Upgrade to add more.", 'limit_reached' => true]);
    }
    // Dedicated workspace if the current one already has a site.
    try {
        $curHasSite = \Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->exists();
        if ($curHasSite && $ownerUserId > 0) {
            $newWs = app(\App\Engines\Builder\Services\ArthurService::class)
                ->provisionWebsiteWorkspace($wsId, $ownerUserId, $billingWs, (parse_url($url, PHP_URL_HOST) ?: 'Connected Site'));
            if ($newWs > 0) { $wsId = $newWs; }
        }
    } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[connect-existing] provisioning failed: ' . $e->getMessage()); }

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
    $__ow = (int) \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->value('workspace_id');
    if ($__ow !== (int) $request->attributes->get('workspace_id')) return response()->json(['error' => 'Website not found'], 404);
    $service = new \App\Services\CustomDomainService();
    return response()->json($service->connect((int) $id, $request->input('domain')));
})->middleware('auth.jwt');

Route::get('/builder/websites/{id}/custom-domain/verify', function (\Illuminate\Http\Request $request, $id) {
    $__ow = (int) \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->value('workspace_id');
    if ($__ow !== (int) $request->attributes->get('workspace_id')) return response()->json(['error' => 'Website not found'], 404);
    $service = new \App\Services\CustomDomainService();
    return response()->json($service->verify((int) $id));
})->middleware('auth.jwt');

Route::delete('/builder/websites/{id}/custom-domain', function (\Illuminate\Http\Request $request, $id) {
    $__ow = (int) \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->value('workspace_id');
    if ($__ow !== (int) $request->attributes->get('workspace_id')) return response()->json(['error' => 'Website not found'], 404);
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
    // 2026-05-28 — Host-resolved contact endpoint. Custom-domain sites
    // whose template can't hardcode a subdomain hit this; the controller
    // resolves the right website by Origin/Referer/Host headers.
    Route::post(
        '/public/contact/by-host',
        [\App\Http\Controllers\Api\PublicContactController::class, 'submitByHost']
    );

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
    $__ow = (int) \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->value('workspace_id');
    if ($__ow !== (int) $r->attributes->get('workspace_id')) return response()->json(['error' => 'Website not found'], 404);
    $ts = new \App\Engines\Builder\Services\TemplateService();
    // updateField now patches text AND image fields SURGICALLY in the deployed
    // index.html (src / background-image), so we no longer full-re-render for
    // image edits — a full render would drop the post-processed sections
    // (archetype governance + injected menu/catalog/units blocks + scrubbed
    // names) that can't be regenerated without the original build_data.
    // BUILDER888 P1-7 — the static-export patch and the durable save are two
    // different outcomes and must not be conflated. `saved` now means "the
    // customer's value is persisted"; `export_patched` reports the secondary
    // artefact (which feeds the Admin draft link only — public serving and
    // preview both render from template_variables).
    $exportPatched = $ts->updateField((int)$id, $field, $value);

    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->first();
    if (! $website) {
        return response()->json(['saved' => false, 'field' => $field, 'error' => 'Website not found'], 404);
    }

    // Previously guarded by `if ($website->template_variables)`, so a site with
    // NULL variables silently discarded the edit and still returned 200.
    $vars = json_decode($website->template_variables ?? '{}', true);
    if (! is_array($vars)) { $vars = []; }
    $vars[$field] = $value;

    $persisted = \Illuminate\Support\Facades\DB::table('websites')
        ->where('id', (int)$id)
        ->update(['template_variables' => json_encode($vars), 'updated_at' => now()]) >= 0;

    // Read back — success is claimed only against proven state.
    $readBack = json_decode(
        (string) \Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->value('template_variables'),
        true
    );
    $saved = is_array($readBack) && array_key_exists($field, $readBack) && $readBack[$field] === $value;

    if (! $saved) {
        return response()->json([
            'saved' => false, 'field' => $field,
            'error' => "We couldn't save that change. Please try again.",
        ], 422);
    }

    // Bust the published-site cache so the edit is visible immediately.
    try { \App\Http\Controllers\PublishedSiteController::invalidateCache((int)$id); } catch (\Throwable $e) {}

    return response()->json([
        'saved'          => true,
        'field'          => $field,
        'export_patched' => (bool) $exportPatched,
        'degraded'       => ! $exportPatched,
    ]);
})->middleware('auth.jwt');

// Logo upload — multipart/form-data with optional `logo` file.
// Empty / missing file = clear (also reachable via PUT /fields/logo_url with value='').
Route::post('/builder/websites/{id}/logo', function (\Illuminate\Http\Request $r, $id) {
    $id = (int)$id;
    $website = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first();
    if (!$website) return response()->json(['success' => false, 'error' => 'Website not found'], 404);
    if ((int) $website->workspace_id !== (int) $r->attributes->get('workspace_id')) return response()->json(['success' => false, 'error' => 'Website not found'], 404);

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

    // Patch the logo SURGICALLY in the deployed HTML (preserves the post-processed
    // sections) instead of a full re-render which would drop injected blocks.
    try {
        (new \App\Engines\Builder\Services\TemplateService())->updateField((int) $id, 'logo_url', $logoUrl);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'error' => 'Render failed: ' . $e->getMessage()], 500);
    }
    try { \App\Http\Controllers\PublishedSiteController::invalidateCache((int)$id); } catch (\Throwable $e) {}

    return response()->json(['success' => true, 'logo_url' => $logoUrl]);
})->middleware('auth.jwt');


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



Route::post('/builder/websites/{id}/tier4-confirm', function (\Illuminate\Http\Request $r, $id) {
    $action = $r->input('confirm_action');
    $data = $r->input('confirm_data', []);
    $htmlPath = storage_path('app/public/sites/' . (int)$id . '/index.html');
    $svc = app(\App\Engines\Builder\Services\BuilderService::class);
    $__wsId = (int) $r->attributes->get('workspace_id');
    if (!\Illuminate\Support\Facades\DB::table('websites')->where('id', (int)$id)->where('workspace_id', $__wsId)->exists()) return response()->json(['error' => 'website not found'], 404);

    if ($action === 'delete_page') {
        $pageId = (int)($data['page_id'] ?? 0);
        if (!$pageId) return response()->json(['error' => 'page_id required'], 400);
        $svc->deletePage($pageId, $__wsId);
        return response()->json(['success' => true, 'method' => 'action', 'message' => 'Page deleted.', 'action' => 'page_deleted']);
    }
    if ($action === 'duplicate_page') {
        $src = \Illuminate\Support\Facades\DB::table('pages')->where('id', (int)($data['page_id'] ?? 0))->first();
        if (!$src) return response()->json(['error' => 'source page not found'], 404);
        if (!\Illuminate\Support\Facades\DB::table('websites')->where('id', $src->website_id)->where('workspace_id', $__wsId)->exists()) return response()->json(['error' => 'source page not found'], 404);
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
})->middleware('auth.jwt');





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


// ════════════════════════════════════════════════════════════════════════════
// §4 WP Connector endpoints — LUSEO Connector plugin v1.0.5+ integration
// Auth: X-API-KEY header (or ?api_key= query). Scoped per workspace via api_keys.
// Added 2026-05-11 to unblock the 5 SEO orphan tabs.
// ════════════════════════════════════════════════════════════════════════════
Route::middleware(['api.key', 'connector.brand'])->prefix('connector')->group(function () {

    // 2026-05-19 (Wave 32c) — sitemap twins for WP plugin (API-key auth).
    Route::get('/sitemap', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        $siteUrl = (string) ($r->query('site_url') ?? '');
        // Wave 32f — Removed silent fallback. Caller must pass site_url.
        $host = $siteUrl ? (parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl) : '';
        $host = strtolower(preg_replace('#^www\.#', '', (string) $host));

        $platformHosts = ['staging.levelupgrowth.io', 'levelupgrowth.io', 'www.levelupgrowth.io', 'app.levelupgrowth.io'];
        if (in_array($host, $platformHosts, true)) {
            return response()->json([
                'success'     => true,
                'mode'        => 'platform_self',
                'host'        => $host,
                'sitemap_url' => 'https://' . $host . '/sitemap.xml',
                'message'     => 'This is the LevelUp Growth platform admin URL, not a content site.',
            ]);
        }

        $site = null;
        if ($host) {
            $site = \Illuminate\Support\Facades\DB::table('websites')
                ->where('status', 'published')
                ->where(function ($q) use ($host) {
                    $q->where('subdomain', $host)->orWhere('domain', $host);
                })
                ->whereNull('deleted_at')
                ->first();
        }
        if ($site) {
            $sub = $site->subdomain ?: $host;
            $pages = \Illuminate\Support\Facades\DB::table('pages')
                ->where('website_id', $site->id)
                ->where('status', 'published')
                ->get(['slug', 'updated_at']);
            return response()->json([
                'success' => true,
                'mode'    => 'laravel',
                'sitemap_url' => "https://{$sub}/sitemap.xml",
                'robots_url'  => "https://{$sub}/robots.txt",
                'page_count'  => $pages->count(),
                'last_updated' => $pages->max('updated_at'),
            ]);
        }

        if (!$siteUrl) {
            return response()->json(['success' => true, 'mode' => 'unknown', 'message' => 'No active site selected.']);
        }

        $base = rtrim($siteUrl, '/');
        $candidates = [$base . '/sitemap.xml', $base . '/wp-sitemap.xml', $base . '/sitemap_index.xml'];
        $found = null; $urls = [];
        foreach ($candidates as $u) {
            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($u);
                if ($resp->ok() && preg_match('/<urlset|<sitemapindex/i', $resp->body())) {
                    $found = $u;
                    if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $resp->body(), $m)) {
                        $urls = array_slice(array_unique($m[1]), 0, 5000);
                    }
                    break;
                }
            } catch (\Throwable $e) {}
        }
        if (!$found) {
            return response()->json([
                'success' => true,
                'mode'    => 'external',
                'found'   => false,
                'host'    => $host,
                'tried'   => $candidates,
                'message' => 'No sitemap found at ' . $host . '. Tried /sitemap.xml, /wp-sitemap.xml, /sitemap_index.xml.',
            ]);
        }

        $indexedUrls = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->pluck('url')
            ->map(fn ($u) => rtrim(strtolower((string) $u), '/'))
            ->toArray();
        $sitemapNorm = array_map(fn ($u) => rtrim(strtolower((string) $u), '/'), $urls);
        $indexedCount = count(array_intersect($sitemapNorm, $indexedUrls));

        return response()->json([
            'success'         => true,
            'mode'            => 'external',
            'found'           => true,
            'sitemap_url'     => $found,
            'url_count'       => count($urls),
            'indexed_count'   => $indexedCount,
            'unindexed_count' => count($urls) - $indexedCount,
            'sample_urls'     => array_slice($urls, 0, 10),
        ]);
    });

    Route::post('/sitemap/ping', function (\Illuminate\Http\Request $r) {
        $sitemapUrl = (string) $r->input('sitemap_url', '');
        if ($sitemapUrl === '' || !preg_match('#^https?://#', $sitemapUrl)) {
            return response()->json(['success' => false, 'error' => 'sitemap_url is required'], 422);
        }
        $results = [];
        foreach ([
            'google' => 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl),
            'bing'   => 'https://www.bing.com/ping?sitemap=' . urlencode($sitemapUrl),
        ] as $engine => $pingUrl) {
            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($pingUrl);
                $results[$engine] = ['ok' => $resp->ok(), 'status' => $resp->status()];
            } catch (\Throwable $e) {
                $results[$engine] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return response()->json(['success' => true, 'sitemap' => $sitemapUrl, 'pings' => $results, 'message' => 'Sitemap submitted to Google + Bing.']);
    });


    // ════════════════════════════════════════════════════════════════════
    // 2026-05-16 v1.1 sprint — content generation + pipeline + bulk meta
    // ════════════════════════════════════════════════════════════════════

    // Plugin status — polled by WP plugin on every admin load
    Route::get('/plugin/status', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $apiKeyId = $r->attributes->get('api_key_id');
        $userId = \Illuminate\Support\Facades\DB::table('api_keys')
            ->where('id', $apiKeyId)->value('user_id');
        $userEmail = $userId
            ? \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $userId)->value('email')
            : '';
        $ws = \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId);
        $plan = \Illuminate\Support\Facades\DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.workspace_id', $wsId)
            ->where('subscriptions.status', 'active')
            ->value('plans.slug') ?? 'free';
        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        return response()->json([
            'success'        => true,
            'workspace_id'   => $wsId,
            'workspace_name' => $ws->name ?? '',
            'plan'           => $plan,
            'credits'        => $credits,
            'user_email'     => $userEmail,
            'is_wp_bundle'   => str_starts_with($plan, 'wp_'),
        ]);
    });


    // Wave 43 — /connector/generate-article now routes through Sarahs chain
    //          engine services (write + meta + image), matching the Laravel
    //          path. Replaces a direct DeepSeek POST that bypassed
    //          RuntimeClient and skipped article/audit persistence.
    //          Total cost: 2cr (canonical bundle) via Wave 42 chain pricing.
    Route::post('/generate-article', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        $userId = $r->attributes->get('user_id'); // set by api.key middleware if available
        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        if ($credits < 2) {
            return response()->json([
                'error'            => 'insufficient_credits',
                'required_credits' => 2,
                'available'        => $credits,
                'pool'             => 'ai_credits',
            ], 402);
        }
        $keyword = trim((string) $r->input('keyword', ''));
        if (!$keyword) {
            return response()->json(['success' => false, 'error' => 'keyword_required'], 422);
        }
        $tone       = (string) $r->input('tone', 'professional');
        $audience   = (string) $r->input('audience', '');
        $wordMin    = max(300, (int) $r->input('word_count_min', 800));
        $wordMax    = max($wordMin, (int) $r->input('word_count_max', 1200));
        $length     = (int) (($wordMin + $wordMax) / 2);
        $location   = (string) $r->input('location', '');
        $language   = (string) $r->input('language', 'English');

        $creditSvc = app(\App\Core\Billing\CreditService::class);
        $reservation = $creditSvc->reserveCredits($wsId, 2, 'Connector', 0, 'wp_generate_article_' . uniqid());
        $reservationRef = $reservation->reservation_reference;

        $articleId = null;
        try {
            // ── 1. Write article (routes through RuntimeClient) ──────────
            $writeSvc = app(\App\Engines\Write\Services\WriteService::class);
            $writeResult = $writeSvc->writeArticle($wsId, [
                'topic'          => $keyword,
                // Wave 43 — omit literal title so writeArticle falls back to
                // ucfirst($topic). LLM-generated H1 inside the content is the
                // real article title; meta_title comes from seo_json.
                'tone'           => $tone,
                'audience'       => $audience,
                'length'         => $length,
                'target_keyword' => $keyword,
                'location'       => $location,
                'language'       => $language,
                'user_id'        => $userId,
                'created_via'    => 'wp_connector',
                // 2026-06-13 — generate the bundle featured image inside
                // writeArticle, BEFORE its WordPress auto-push, so the pushed
                // draft carries the image. Step 3 below becomes idempotent
                // (skips when the row already has an image) — no double-gen.
                'auto_featured_image' => true,
            ]);
            $articleId = $writeResult['article_id'] ?? null;
            if (!$articleId) {
                throw new \RuntimeException('write_article returned no article_id');
            }
        } catch (\Throwable $e) {
            $creditSvc->release($wsId, $reservationRef);
            \Illuminate\Support\Facades\Log::warning('[WP generate-article] write_article failed', [
                'workspace_id' => $wsId, 'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => 'write_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        // ── 2. Meta (free chain child — paid by parent bundle) ────────
        try {
            $writeSvc->generateMeta($wsId, ['article_id' => $articleId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WP generate-article] meta failed (non-fatal)', [
                'article_id' => $articleId, 'error' => $e->getMessage(),
            ]);
        }

        // Wave 47 — auto-enrich if workspace has AEO Mode enabled (and is on
        // a $69+ plan; the AeoPlanGate doesnt apply here because this is
        // already a wp_connector flow, but the workspace toggle still gates
        // the behavior). Adds ~5s and uses the parent bundle credits.
        $aeoEnriched = false;
        $aeoJsonld = null;
        try {
            $aeoOn = (bool) \Illuminate\Support\Facades\DB::table('aeo_settings')
                ->where('workspace_id', $wsId)
                ->value('aeo_mode_enabled');
            if ($aeoOn) {
                $aeoRes = $writeSvc->aeoEnrich($wsId, ['article_id' => $articleId, 'user_id' => $userId]);
                $aeoEnriched = (bool) ($aeoRes['enriched'] ?? false);
                if ($aeoEnriched) {
                    $aeoJsonld = \Illuminate\Support\Facades\DB::table('articles')
                        ->where('id', $articleId)->value('jsonld_json');
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WP generate-article] aeo enrich skipped', [
                'article_id' => $articleId, 'error' => $e->getMessage(),
            ]);
        }

        // ── 3. Featured image (free chain child — paid by parent bundle) ─
        // 2026-06-13 — writeArticle now generates this image BEFORE its
        // WordPress push (auto_featured_image), so the pushed draft already
        // carries it. This step is therefore IDEMPOTENT: if the row already
        // has an image we skip regeneration (no double-generate, no wasted
        // compute); we only generate here as a fallback when writeArticle's
        // attempt came back empty. The credit logic below is unchanged.
        $imageFailed = false;
        $existingImage = \Illuminate\Support\Facades\DB::table('articles')
            ->where('id', $articleId)->value('featured_image_url');
        if (! empty($existingImage)) {
            // writeArticle already produced + pushed the image — nothing to do.
            $imageFailed = false;
        } else {
            try {
                $creativeSvc = app(\App\Engines\Creative\Services\CreativeService::class);
                $imgResult = $creativeSvc->generateImage($wsId, [
                    'article_id' => $articleId,
                    'quality'    => 'mini',
                    'user_id'    => $userId,
                ]);
                if (empty($imgResult['url']) && empty($imgResult['featured_image_url'])) {
                    $imageFailed = true;
                }
            } catch (\Throwable $e) {
                $imageFailed = true;
                \Illuminate\Support\Facades\Log::warning('[WP generate-article] image gen failed', [
                    'article_id' => $articleId, 'error' => $e->getMessage(),
                ]);
            }
        }

        // ── 4. Commit credits: 2cr bundle if image succeeded, 1cr if not ─
        if ($imageFailed) {
            $creditSvc->release($wsId, $reservationRef);
            \Illuminate\Support\Facades\DB::table('credits')
                ->where('workspace_id', $wsId)->decrement('balance', 1);
            $creditsUsed = 1;
        } else {
            // Wave 47 — bundle cost: 3cr when AEO enrichment ran, 2cr base.
            $finalCost = $aeoEnriched ? 3 : 2;
            // The reservation was for 2cr; if AEO ran, debit the extra 1cr.
            if ($aeoEnriched) {
                \Illuminate\Support\Facades\DB::table('credits')
                    ->where('workspace_id', $wsId)->decrement('balance', 1);
            }
            $creditSvc->commit($wsId, $reservationRef, 2);
            $creditsUsed = $finalCost;
        }
        $remaining = max(0, $credits - $creditsUsed);

        // ── 5. Fetch final article state to build WP response ───────────
        $article = \Illuminate\Support\Facades\DB::table('articles')
            ->where('id', $articleId)
            ->where('workspace_id', $wsId)
            ->first(['id', 'title', 'content', 'seo_json', 'featured_image_url', 'featured_image_alt', 'word_count']);

        $seo = $article && $article->seo_json
            ? (json_decode($article->seo_json, true) ?: [])
            : [];

        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'workspace_id' => $wsId,
            'action' => 'write.create_article',
            'entity_type' => 'Article',
            'entity_id' => $articleId,
            'metadata_json' => json_encode([
                'source' => 'wp_connector',
                'keyword' => $keyword,
                'image_failed' => $imageFailed,
                'credits_used' => $creditsUsed,
            ]),
            'created_at' => now(),
        ]);

        // Wave 47 — JSON-LD HTML block ready to embed inside the WP post body.
        $jsonldHtml = '';
        if ($aeoJsonld) {
            $jsonldHtml = '<script type="application/ld+json">' . $aeoJsonld . '</script>';
        }

        $resp = [
            'success'           => true,
            'article_id'        => $articleId,
            'title'             => $article->title ?? $keyword,
            'content'           => $article->content ?? '',
            'meta_title'        => $seo['title']       ?? ($article->title ?? $keyword),
            'meta_description'  => $seo['description'] ?? '',
            'image_url'         => $article->featured_image_url ?? null,
            'image_alt'         => $article->featured_image_alt ?? null,
            'keyword'           => $keyword,
            'word_count'        => (int) ($article->word_count ?? str_word_count(strip_tags((string)($article->content ?? '')))),
            'credits_used'      => $creditsUsed,
            'credits_remaining' => $remaining,
            'source'            => 'sarah_chain_engine_services',
            // Wave 47 — AEO payload for WP plugin to inject into post body.
            'aeo_enriched'      => $aeoEnriched,
            'jsonld_html'       => $jsonldHtml,
        ];
        if ($imageFailed) { $resp['image_failed'] = true; }
        return response()->json($resp);
    });

    // P1.4 (sprint 2 2026-05-12) — On-demand SEO score for a URL+keyword pair.
    // 0 credits. Wraps SeoService::indexPageFromConnector for the score and
    // builds a per-rule breakdown from the heuristics the connector pushes.
    Route::post('/seo-score', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'url'     => 'required|url',
            'keyword' => 'required|string|max:255',
        ]);
        $svc = app(\App\Engines\SEO\Services\SeoService::class);
        // Pull the indexed page if it exists; otherwise score what the client sent.
        $idx = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)->where('url', $data['url'])->first();
        $title       = $idx->meta_title ?? $idx->title ?? '';
        $content     = $idx->content ?? '';
        $description = $idx->meta_description ?? '';
        $wordCount   = $content ? str_word_count(strip_tags($content)) : 0;

        $kwLower      = mb_strtolower((string) $data['keyword']);
        $titleHasKw   = $title       !== '' && mb_stripos($title, $kwLower) !== false;
        $descHasKw    = $description !== '' && mb_stripos($description, $kwLower) !== false;
        $contentHasKw = $content     !== '' && mb_stripos($content, $kwLower) !== false;
        $wordCountOk  = $wordCount >= 300;
        $hasDesc      = $description !== '';
        $internalLnks = $content ? max(0, substr_count(strtolower($content), '<a ')) : 0;
        $charsPerWord = $wordCount > 0 ? mb_strlen(strip_tags($content)) / $wordCount : 0;
        $readability  = $charsPerWord > 0 && $charsPerWord < 6 ? 'good'
            : ($charsPerWord < 8 ? 'ok' : 'poor');

        // Compose the same 0..100 score the connector uses.
        $score = (int) ((int) $titleHasKw * 20
            + (int) $descHasKw * 15
            + (int) $contentHasKw * 15
            + (int) $wordCountOk * 15
            + (int) $hasDesc * 10
            + min(15, $internalLnks * 3)
            + ($readability === 'good' ? 10 : ($readability === 'ok' ? 5 : 0)));
        $score = min(100, max(0, $score));

        return response()->json([
            'success' => true,
            'score'   => $score,
            'breakdown' => [
                'keyword_in_title'     => $titleHasKw,
                'keyword_in_meta'      => $descHasKw,
                'keyword_in_content'   => $contentHasKw,
                'word_count_ok'        => $wordCountOk,
                'has_meta_description' => $hasDesc,
                'has_featured_image'   => null,  // not knowable from indexed content alone
                'internal_links'       => $internalLnks,
                'readability'          => $readability,
            ],
            'meta' => [
                'indexed'   => $idx !== null,
                'word_count'=> $wordCount,
            ],
        ]);
    });

    // ════════════════════════════════════════════════════════════════════
    // 2026-05-12 embed expansion — Pipeline + Calendar + Image regenerate.
    // ════════════════════════════════════════════════════════════════════

    // P1.5 — Content Pipeline: SEO-related tasks for the workspace.
    Route::get('/content/pipeline', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // SEO-scope task actions across the engines we care about.
        // 2026-05-23 FIX 29 — added aeo_enrich, generate_image_mini,
        // insert_link, apply_link_suggestions so the full chain (Sarah's
        // recipe + SEO Assistant batch) is visible in the pipeline UI.
        // Without these the user sees only 3 of 6 chain steps per article.
        $seoActions = [
            'write_article', 'improve_draft', 'generate_outline',
            'generate_headlines', 'generate_meta',
            'deep_audit', 'serp_analysis', 'bulk_generate_meta',
            'link_suggestions', 'autonomous_goal', 'agent_goal',
            'keyword_research', 'generate_image', 'generate_article',
            'optimize_article',
            'aeo_enrich', 'generate_image_mini', 'insert_link', 'apply_link_suggestions',
        ];
        $rows = \Illuminate\Support\Facades\DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->whereIn('action', $seoActions)
            ->orderByDesc('id')->limit(200)->get();

        // 2026-05-25 — added 'blocked' bucket. Previously blocked tasks were
        // folded into 'failed', which mislabeled waiting-on-external work as
        // failure. Blocked tasks now have their own tab + retry affordance.
        $buckets = ['queued' => [], 'running' => [], 'blocked' => [], 'completed' => [], 'failed' => [], 'cancelled' => []];
        foreach ($rows as $t) {
            $payload = json_decode($t->payload_json ?? '{}', true) ?: [];
            $result  = json_decode($t->result_json  ?? '{}', true) ?: [];
            $summary = isset($result['summary']) ? (string) $result['summary']
                : (isset($result['title']) ? (string) $result['title']
                : (isset($payload['title']) ? (string) $payload['title']
                : (isset($payload['topic']) ? (string) $payload['topic']
                : (isset($payload['keyword']) ? (string) $payload['keyword'] : null))));
            $progress = 0;
            if ($t->total_steps > 0 && $t->current_step > 0) {
                $progress = (int) min(100, round(($t->current_step / max(1, $t->total_steps)) * 100));
            } elseif (in_array($t->status, ['completed'])) { $progress = 100; }
            elseif (in_array($t->status, ['running','verifying'])) { $progress = 50; }
            // 2026-05-27 — category surfaced for Pipeline column + filter
            $catSvc = app(\App\Core\TaskSystem\TaskCategoryService::class);
            $cat = $t->category ?: $catSvc->for((string) $t->engine, (string) $t->action);
            $catMeta = $catSvc->metadata($cat);
            $item = [
                'id'             => (int) $t->id,
                'task_type'      => (string) $t->action,
                'engine'         => (string) $t->engine,
                'category'       => $cat,
                'category_label' => $catMeta['label'],
                'category_color' => $catMeta['color'],
                'status'         => (string) $t->status,
                'progress'       => $progress,
                'payload'        => $payload,
                'result_summary' => $summary,
                'created_at'     => (string) $t->created_at,
                'updated_at'     => (string) ($t->updated_at ?? $t->created_at),
            ];
            $bucket = $t->status;
            if (in_array($bucket, ['pending','awaiting_approval','queued'], true))      { $buckets['queued'][]    = $item; }
            elseif (in_array($bucket, ['running','verifying'], true))                    { $buckets['running'][]   = $item; }
            elseif ($bucket === 'blocked')                                                { $buckets['blocked'][]   = $item; }
            elseif ($bucket === 'completed')                                              { $buckets['completed'][] = $item; }
            elseif (in_array($bucket, ['failed','degraded'], true))                       { $buckets['failed'][]    = $item; }
            elseif ($bucket === 'cancelled')                                              { $buckets['cancelled'][] = $item; }
        }
        // 2026-05-27 — counts grouped by category for the Pipeline filter pills
        $byCategory = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                $c = $item['category'] ?? 'operations';
                $byCategory[$c] = ($byCategory[$c] ?? 0) + 1;
            }
        }
        return response()->json([
            'success'  => true,
            'pipeline' => $buckets,
            'counts'   => [
                'queued'    => count($buckets['queued']),
                'running'   => count($buckets['running']),
                'blocked'   => count($buckets['blocked']),
                'completed' => count($buckets['completed']),
                'failed'    => count($buckets['failed']),
                'cancelled' => count($buckets['cancelled']),
                'total'     => array_sum(array_map('count', $buckets)),
            ],
            'counts_by_category' => $byCategory,
        ]);
    });

    // P1.6 — SEO Content Calendar: month-grouped articles + tasks.
    Route::get('/content/calendar', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $month = (string) $r->query('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['success' => false, 'error' => 'bad_month_format'], 422);
        }
        [$year, $monthN] = explode('-', $month);
        $first = sprintf('%04d-%02d-01', $year, $monthN);
        $last  = date('Y-m-t', strtotime($first));

        $days = [];
        $push = function (string $date, array $item) use (&$days) {
            $key = substr($date, 0, 10);
            if (!isset($days[$key])) { $days[$key] = []; }
            $days[$key][] = $item;
        };

        // Articles in the month — bucket by scheduled_at if set, otherwise
        // by created_at. Bulk-generated articles (assistant flow) set
        // scheduled_at to land on planned days; manually-created articles
        // fall through to created_at.
        $articles = \Illuminate\Support\Facades\DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where(function ($q) use ($first, $last) {
                $q->where(function ($qq) use ($first, $last) {
                    $qq->whereNotNull('scheduled_at')
                       ->whereDate('scheduled_at', '>=', $first)
                       ->whereDate('scheduled_at', '<=', $last);
                })->orWhere(function ($qq) use ($first, $last) {
                    $qq->whereNull('scheduled_at')
                       ->whereDate('created_at', '>=', $first)
                       ->whereDate('created_at', '<=', $last);
                });
            })
            ->orderByRaw('COALESCE(scheduled_at, created_at)')->get();
        foreach ($articles as $a) {
            $bucketDate = (string) ($a->scheduled_at ?: $a->created_at);
            $push($bucketDate, [
                'type'     => 'article',
                'id'       => (int) $a->id,
                'title'    => (string) $a->title,
                'status'   => (string) $a->status,
                'keyword'  => $a->focus_keyword ?? null,
                'score'    => isset($a->seo_json) ? (int) (json_decode($a->seo_json, true)['score'] ?? 0) : null,
                'url'      => null,
                'edit_url' => '/app/#write/' . (int) $a->id,
                'scheduled_at' => $a->scheduled_at,
                'created_at' => (string) $a->created_at,
            ]);
        }

        // Pipeline tasks (SEO-scope) in the month
        // 2026-05-23 FIX 29 — same expansion as /content/pipeline so the
        // calendar shows the full chain (aeo_enrich, generate_image_mini,
        // insert_link, apply_link_suggestions). Without this the calendar
        // view was missing 3 of every 6 chain steps.
        $seoActions = [
            'write_article','improve_draft','deep_audit','serp_analysis',
            'bulk_generate_meta','generate_image','generate_article',
            'optimize_article','keyword_research','link_suggestions',
            'autonomous_goal','agent_goal','generate_outline','generate_headlines',
            'generate_meta','aeo_enrich','generate_image_mini','insert_link','apply_link_suggestions',
        ];
        $tasks = \Illuminate\Support\Facades\DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->whereIn('action', $seoActions)
            ->whereDate('created_at', '>=', $first)
            ->whereDate('created_at', '<=', $last)
            ->orderBy('created_at')->get();
        foreach ($tasks as $t) {
            $payload = json_decode($t->payload_json ?? '{}', true) ?: [];
            $title   = $payload['title'] ?? $payload['topic'] ?? $payload['keyword'] ?? ucfirst(str_replace('_', ' ', (string) $t->action));
            $push((string) $t->created_at, [
                'type'     => 'task',
                'id'       => (int) $t->id,
                'title'    => (string) $title,
                'status'   => (string) $t->status,
                'keyword'  => $payload['keyword'] ?? null,
                'score'    => null,
                'url'      => null,
                'edit_url' => null,
                'created_at' => (string) $t->created_at,
            ]);
        }

        ksort($days);
        return response()->json([
            'success' => true,
            'month'   => $month,
            'days'    => $days,
            'counts'  => [
                'articles' => count($articles),
                'tasks'    => count($tasks),
                'days'     => count($days),
            ],
        ]);
    });

    // P1.7 — Regenerate featured image for an indexed page. 1 credit.
    Route::post('/pages/regenerate-image', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'page_id' => 'nullable|integer',
            'url'     => 'required|url',
            'title'   => 'required|string|max:300',
            'force'   => 'nullable|boolean',
        ]);
        $force = (bool) ($data['force'] ?? false);
        // P-Gate (2026-05-15) — caller-mode-aware SEO AI entitlement.
        // X-API-KEY caller -> WP path -> canUseSeoAIForConnector
        // Bearer JWT caller -> SaaS path -> canUseSeoAI (Growth+)
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        $isApiKeyCaller = $r->attributes->has('api_key_id');
        if ($isApiKeyCaller) {
            if (! $gate->canUseSeoAIForConnector($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'wp_bundle',
                    'message'       => 'SEO AI generation requires the WP SEO Bundle ($69) or higher.',
                ], 403);
            }
        } else {
            if (! $gate->canUseSeoAI($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'growth',
                    'message'       => 'AI SEO generation unlocks on Growth ($99) and above.',
                ], 403);
            }
        }


        // If page exists in seo_content_index and already has image, skip unless force
        $page = null;
        if (!empty($data['page_id'])) {
            $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('id', $data['page_id'])->first();
        }
        if (!$page) {
            $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('url', $data['url'])->first();
        }
        $hasImg = $page && \Illuminate\Support\Facades\Schema::hasColumn('seo_content_index', 'featured_image_url')
            && !empty($page->featured_image_url);
        if ($hasImg && !$force) {
            return response()->json([
                'success'   => true,
                'image_url' => (string) $page->featured_image_url,
                'page_id'   => $page ? (int) $page->id : null,
                'cached'    => true,
            ]);
        }

        // Credit pre-check + reserve
        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        if ($credits < 1) {
            return response()->json([
                'error' => 'insufficient_credits', 'required_credits' => 1,
                'available' => $credits, 'pool' => 'ai_credits',
            ], 402);
        }
        $creditSvc = app(\App\Core\Billing\CreditService::class);
        $ref = $creditSvc->reserve($wsId, 1, 'pages/regenerate-image');

        // Pull a top keyword for this page (best-effort) to enrich the prompt
        $topKw = '';
        if ($page) {
            $topKw = (string) (\Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->where('target_url', $page->url)
                ->orderByDesc('volume')->value('keyword') ?? '');
        }
        $prompt = "Featured blog image for: " . $data['title']
            . ($topKw ? " — context: {$topKw}" : "")
            . ". Professional, clean, modern composition. No text overlay. "
            . "Suitable for a business website hero or featured slot.";

        $imageUrl = null;
        try {
            /** @var \App\Connectors\CreativeConnector $cc */
            $cc = app(\App\Connectors\CreativeConnector::class);
            $res = $cc->execute('generate_image', [
                'prompt'       => $prompt,
                'quality'      => 'low',         // HARDCODED MINI
                'aspect_ratio' => '1:1',
                'style'        => 'professional photograph',
            ]);
            if (($res['success'] ?? false) && !empty($res['data']['url'])) {
                $imageUrl      = (string) $res['data']['url'];
                $imgWidth      = $res['data']['width'] ?? null;
                $imgHeight     = $res['data']['height'] ?? null;
                $imgSize       = (int) ($res['data']['file_size'] ?? 0);
                $imgModel      = $res['data']['metadata']['model'] ?? 'gpt-image-1';
                $revisedPrompt = $res['data']['metadata']['revised_prompt'] ?? null;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pages/regenerate-image exception', ['err' => $e->getMessage()]);
        }

        if (!$imageUrl) {
            $creditSvc->release($wsId, $ref);
            return response()->json(['success' => false, 'error' => 'image_generation_failed'], 502);
        }

        // Commit credit + persist to seo_content_index and articles (if matched)
        $creditSvc->commit($wsId, $ref, 1);
        if (\Illuminate\Support\Facades\Schema::hasColumn('seo_content_index', 'featured_image_url')) {
            \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->where('url', $data['url'])
                ->update([
                    'featured_image_url'  => $imageUrl,
                    'has_featured_image'  => 1,
                    'updated_at'          => now(),
                ]);
        }
        \Illuminate\Support\Facades\DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where(function ($q) use ($data) {
                $q->where('title', $data['title']);
            })
            ->update(['featured_image_url' => $imageUrl, 'updated_at' => now()]);

        // Change 5: best-effort copy into the Laravel media library so AI
        // featured images are discoverable in the asset library. Never bubbles.
        try {
            $mediaExists = \Illuminate\Support\Facades\DB::table('media')
                ->where('workspace_id', $wsId)
                ->where('url', $imageUrl)
                ->exists();
            if (!$mediaExists) {
                $mediaFilename = basename((string) parse_url($imageUrl, PHP_URL_PATH));
                $metadataJson = json_encode([
                    'style'          => 'professional photograph',
                    'quality'        => 'low',
                    'aspect_ratio'   => '1:1',
                    'page_id'        => isset($page) && $page ? ($page->id ?? null) : null,
                    'page_url'       => $data['url'] ?? null,
                    'revised_prompt' => isset($revisedPrompt) ? $revisedPrompt : null,
                ]);
                if ($metadataJson === false) {
                    $metadataJson = '{}';
                }
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id'      => $wsId,
                    'filename'          => $mediaFilename !== '' ? $mediaFilename : 'ai-featured.png',
                    'path'              => $imageUrl,
                    'url'               => $imageUrl,
                    'mime_type'         => 'image/png',
                    'asset_type'        => 'image',
                    'size_bytes'        => isset($imgSize) ? $imgSize : 0,
                    'width'             => isset($imgWidth) ? $imgWidth : null,
                    'height'            => isset($imgHeight) ? $imgHeight : null,
                    'source'            => 'seo_featured_image',
                    'category'          => 'image',
                    'is_platform_asset' => 0,
                    'is_public'         => 0,
                    'prompt'            => mb_substr((string) $prompt, 0, 2048),
                    'model'             => isset($imgModel) ? $imgModel : 'gpt-image-1',
                    'metadata_json'     => $metadataJson,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        } catch (\Throwable $_mediaE) {
            \Illuminate\Support\Facades\Log::warning('Change 5 media insert failed', ['err' => $_mediaE->getMessage()]);
        }

        // Change 2B-3 Hook A: if this page maps to a WP post, sideload the
        // image to WordPress via /lgsc/v1/attach-image and persist the
        // returned attachment ID. Best-effort: a WP attach failure must NOT
        // fail Laravel image generation, must NOT roll back credits, must
        // NOT bubble exceptions. Response contract is unchanged.
        if ($page && isset($page->wp_post_id) && (int) $page->wp_post_id > 0) {
            $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
            $webhookSecret = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
            if ($siteUrl && $webhookSecret) {
                try {
                    $attachUrl = rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/attach-image';
                    $attachResp = \Illuminate\Support\Facades\Http::timeout(60)
                        ->withHeaders([
                            'Content-Type'  => 'application/json',
                            'X-LGSC-Secret' => $webhookSecret,
                        ])
                        ->post($attachUrl, [
                            'post_id'   => (int) $page->wp_post_id,
                            'image_url' => $imageUrl,
                        ]);
                    if ($attachResp->successful()) {
                        $attachId = (int) ($attachResp->json('attachment_id') ?? 0);
                        if ($attachId > 0 && $page && $page->id) {
                            \Illuminate\Support\Facades\DB::table('seo_content_index')
                                ->where('workspace_id', $wsId)
                                ->where('id', $page->id)
                                ->update(['wp_attachment_id' => $attachId]);
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning(
                            'regenerate-image: attach-image non-2xx',
                            [
                                'workspace_id' => $wsId,
                                'post_id'      => (int) $page->wp_post_id,
                                'http_status'  => $attachResp->status(),
                                'body'         => $attachResp->json(),
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'regenerate-image: attach-image exception',
                        [
                            'workspace_id' => $wsId,
                            'post_id'      => (int) $page->wp_post_id,
                            'error'        => $e->getMessage(),
                        ]
                    );
                }
            }
        }

        return response()->json([
            'success'   => true,
            'image_url' => $imageUrl,
            'page_id'   => $page ? (int) $page->id : null,
        ]);
    });

    // P1.3 — Re-write existing article content for SEO. 2 credits.
    Route::post('/optimize-article', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $content   = trim((string) $r->input('content', ''));
        $keyword   = trim((string) $r->input('keyword', ''));
        $title     = (string) $r->input('title', '');
        $score     = (int) $r->input('current_score', 0);
        $breakdown = (array) $r->input('breakdown', []);
        if (!$content || !$keyword) {
            return response()->json(['success' => false, 'error' => 'content_and_keyword_required'], 422);
        }
        // P-Gate (2026-05-15) — caller-mode-aware SEO AI entitlement.
        // X-API-KEY caller -> WP path -> canUseSeoAIForConnector
        // Bearer JWT caller -> SaaS path -> canUseSeoAI (Growth+)
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        $isApiKeyCaller = $r->attributes->has('api_key_id');
        if ($isApiKeyCaller) {
            if (! $gate->canUseSeoAIForConnector($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'wp_bundle',
                    'message'       => 'SEO AI generation requires the WP SEO Bundle ($69) or higher.',
                ], 403);
            }
        } else {
            if (! $gate->canUseSeoAI($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'growth',
                    'message'       => 'AI SEO generation unlocks on Growth ($99) and above.',
                ], 403);
            }
        }

        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        if ($credits < 2) {
            return response()->json([
                'success' => false, 'error' => 'insufficient_credits', 'code' => 'NO_CREDITS',
            ], 402);
        }

        $breakdownStr = '';
        foreach ($breakdown as $k => $v) {
            $breakdownStr .= "- {$k}: " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
        }

        $prompt  = "Optimize this WordPress article for the keyword '{$keyword}'.\n";
        $prompt .= "Current SEO score: {$score}/100\n";
        if ($breakdownStr) { $prompt .= "Score breakdown:\n{$breakdownStr}\n"; }
        $prompt .= "Title: {$title}\n\nContent:\n{$content}\n\n";
        $prompt .= "Return ONLY the improved HTML content. No explanation. No preamble.";

        // P1.3 v2 (2026-05-15) — hands-vs-brain refactor.
        // Replaces direct DeepSeek call with RuntimeClient->aiRun().
        $runtime = app(\App\Connectors\RuntimeClient::class);
        $resp    = $runtime->aiRun('seo_content_generation', $prompt, [], 3000);
        if (empty($resp['success']) || empty($resp['text'])) {
            return response()->json([
                'success' => false,
                'error'   => 'llm_error',
                'message' => $resp['error'] ?? 'runtime_returned_empty',
            ], 502);
        }
        $optimized = trim((string) $resp['text']);
        $optimized = preg_replace('/^```(?:html)?\s*/i', '', $optimized);
        $optimized = preg_replace('/\s*```$/', '', $optimized);
        if (!$optimized) {
            return response()->json(['success' => false, 'error' => 'optimization_failed'], 500);
        }
        \Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->decrement('balance', 2);
        return response()->json([
            'success'           => true,
            'content'           => $optimized,
            'credits_used'      => 2,
            'credits_remaining' => max(0, $credits - 2),
        ]);
    });

    // P1.4 — Suggest a keyword to write about, ranked by gap-opportunity.
    Route::get('/auto-pick-keyword', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // 1. Content gap: low-scoring page with a meaningful H1.
        $gap = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('content_score', '<', 50)
            ->whereNotNull('h1')
            ->orderBy('content_score')
            ->first(['h1', 'meta_title', 'title', 'url']);
        if ($gap) {
            $kw = $gap->h1 ?: ($gap->meta_title ?: $gap->title);
            if ($kw) {
                return response()->json([
                    'success' => true,
                    'keyword' => mb_substr((string) $kw, 0, 120),
                    'source'  => 'gap',
                    'context' => ['url' => $gap->url],
                ]);
            }
        }
        // 2. SERP rank-boost opportunity: position 4-20.
        $serp = \Illuminate\Support\Facades\DB::table('seo_serp_results')
            ->where('workspace_id', $wsId)
            ->whereBetween('position', [4, 20])
            ->orderByDesc('created_at')
            ->value('keyword');
        if ($serp) {
            return response()->json([
                'success' => true,
                'keyword' => $serp,
                'source'  => 'serp',
            ]);
        }
        // 3. Last resort: high-volume tracked keyword.
        $kw = \Illuminate\Support\Facades\DB::table('seo_keywords')
            ->where('workspace_id', $wsId)->orderByDesc('volume')->value('keyword');
        return response()->json([
            'success' => true,
            'keyword' => $kw ?? '',
            'source'  => $kw ? 'tracked' : 'empty',
        ]);
    });

    // P1.5 — Pipeline routes (agent goal queue).
    Route::prefix('pipeline')->group(function () {
        Route::get('/goals', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            // W6: the WordPress plugin is SEO-only. /content/pipeline already
            // allowlists SEO actions; this endpoint did not, so a legacy
            // social/marketing row was emitted verbatim. Same guard applied.
            $goals = \Illuminate\Support\Facades\DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->whereIn('status', ['pending', 'queued', 'running', 'completed', 'failed', 'cancelled'])
                ->orderByDesc('created_at')
                ->limit(60)
                ->get(['id', 'engine', 'action', 'status', 'payload_json', 'created_at', 'completed_at'])
                ->reject(fn($g) => \App\Core\LaunchScope\AgentDirectory::isLegacyTask($g->engine, $g->action, null))
                ->take(20)->values();
            return response()->json(['success' => true, 'goals' => $goals]);
        });

        Route::post('/submit-goal', function (\Illuminate\Http\Request $r) {
            $wsId     = $r->attributes->get('workspace_id');
            $goalText = trim((string) $r->input('goal_text', ''));
            if (mb_strlen($goalText) < 5) {
                return response()->json(['success' => false, 'error' => 'goal_text_too_short'], 422);
            }
            // tasks schema uses action + payload_json (not task_type + payload).
            $taskId = \Illuminate\Support\Facades\DB::table('tasks')->insertGetId([
                'workspace_id' => $wsId,
                'engine'       => 'seo',
                'action'       => 'agent_goal',
                'status'       => 'running',
                'source'       => 'agent',
                'priority'     => 'normal',
                'payload_json' => json_encode(['goal' => $goalText, 'source' => 'wp_plugin']),
                'started_at'   => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // 2026-05-13 — synchronous dispatch through SeoAssistantService.
            // 'agent_goal' is not in the CapabilityMapService and the existing
            // TaskExecutionJob → Orchestrator path would throw "No capability
            // mapped for action: agent_goal". Until a proper async dispatcher
            // ships (Phase 2 — Sarah's planner consuming the queue), we run the
            // assistant inline: classifies intent, builds proposal or generates
            // conversational reply, persists the response on the task row, and
            // marks completed so the row stops sitting in the pending bucket.
            try {
                $svc = app(\App\Engines\SEO\Services\SeoAssistantService::class);
                // Wave 1 (2026-05-17) — pass user_id through so chat-log
                // persistence + disclaimer gate can identify the user.
                $assistantResult = $svc->handle($wsId, $goalText, [
                    'source'  => 'submit_goal',
                    'user_id' => $r->user()?->id,
                ]);

                \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('id', $taskId)
                    ->update([
                        'status'       => 'completed',
                        'result_json'  => json_encode($assistantResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'completed_at' => now(),
                        'updated_at'   => now(),
                    ]);

                return response()->json([
                    'success'  => true,
                    'task_id'  => $taskId,
                    'reply'    => (string) ($assistantResult['response'] ?? ''),
                    'executed' => (bool) ($assistantResult['executed'] ?? false),
                    'result'   => $assistantResult['result'] ?? null,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('submit-goal handler failed', [
                    'workspace_id' => $wsId, 'task_id' => $taskId, 'err' => $e->getMessage(),
                ]);
                \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('id', $taskId)
                    ->update([
                        'status'      => 'failed',
                        'error_text'  => mb_substr($e->getMessage(), 0, 2000),
                        'failed_at'   => now(),
                        'updated_at'  => now(),
                    ]);
                return response()->json([
                    'success' => false,
                    'task_id' => $taskId,
                    'error'   => 'assistant_failed',
                    'message' => $e->getMessage(),
                ], 500);
            }
        });

        Route::post('/cancel', function (\Illuminate\Http\Request $r) {
            $wsId   = $r->attributes->get('workspace_id');
            $taskId = (int) $r->input('task_id', 0);
            if (!$taskId) {
                return response()->json(['success' => false, 'error' => 'task_id_required'], 422);
            }
            \Illuminate\Support\Facades\DB::table('tasks')
                ->where('id', $taskId)
                ->where('workspace_id', $wsId)
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            return response()->json(['success' => true]);
        });
    });

    // P1.6 — Bulk meta-description generator. 1 credit per post.
    Route::post('/bulk-generate-meta', function (\Illuminate\Http\Request $r) {
        $wsId  = $r->attributes->get('workspace_id');
        $posts = (array) $r->input('posts', []);
        if (empty($posts)) {
            return response()->json(['success' => false, 'error' => 'posts_required'], 422);
        }
        // P-Gate (2026-05-15) — caller-mode-aware SEO AI entitlement.
        // X-API-KEY caller -> WP path -> canUseSeoAIForConnector
        // Bearer JWT caller -> SaaS path -> canUseSeoAI (Growth+)
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        $isApiKeyCaller = $r->attributes->has('api_key_id');
        if ($isApiKeyCaller) {
            if (! $gate->canUseSeoAIForConnector($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'wp_bundle',
                    'message'       => 'SEO AI generation requires the WP SEO Bundle ($69) or higher.',
                ], 403);
            }
        } else {
            if (! $gate->canUseSeoAI($wsId)) {
                return response()->json([
                    'success'       => false,
                    'error'         => 'plan_upgrade_required',
                    'code'          => 'PLAN_UPGRADE_REQUIRED',
                    'required_plan' => 'growth',
                    'message'       => 'AI SEO generation unlocks on Growth ($99) and above.',
                ], 403);
            }
        }
        $cost = count($posts);
        $credits = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        if ($credits < $cost) {
            return response()->json([
                'success' => false, 'error' => 'insufficient_credits', 'code' => 'NO_CREDITS',
                'required' => $cost, 'available' => $credits,
            ], 402);
        }

        // P1.6 v2 (2026-05-15) — hands-vs-brain refactor.
        // Replaces direct DeepSeek call with RuntimeClient->aiRun().
        // Charges only for SUCCESSFUL generations (fail-soft).
        $runtime  = app(\App\Connectors\RuntimeClient::class);
        $results  = [];
        $failures = [];
        foreach ($posts as $post) {
            $title   = (string) ($post['title'] ?? '');
            $keyword = (string) ($post['keyword'] ?? '');
            $excerpt = mb_substr((string) ($post['content_excerpt'] ?? ''), 0, 300);
            // Fold intent into user prompt — runtime task type has its own
            // hardcoded SYSTEM_PROMPT that can't be overridden via aiRun().
            $prompt  = "Write an SEO meta description (120-160 chars) for:\n";
            $prompt .= "Title: {$title}\n";
            $prompt .= "Keyword: {$keyword}\n";
            if ($excerpt !== '') { $prompt .= "Excerpt: {$excerpt}\n"; }
            $prompt .= "Return ONLY the meta description text. No quotes. No explanation. No markdown.";

            $resp = $runtime->aiRun('seo_content_generation', $prompt, [], 200);
            if (!empty($resp['success']) && !empty($resp['text'])) {
                $meta = trim((string) $resp['text']);
                $meta = trim($meta, "\"'`");
                $meta = preg_replace('/^```[a-z]*\s*/i', '', $meta);
                $meta = preg_replace('/\s*```$/', '', $meta);
                if ($meta !== '') {
                    $results[] = [
                        'post_id'          => $post['post_id'] ?? null,
                        'meta_description' => $meta,
                    ];
                    continue;
                }
            }
            $failures[] = [
                'post_id' => $post['post_id'] ?? null,
                'error'   => $resp['error'] ?? 'empty_response',
            ];
        }

        $chargeCount = count($results);
        if ($chargeCount > 0) {
            \Illuminate\Support\Facades\DB::table('credits')
                ->where('workspace_id', $wsId)->decrement('balance', $chargeCount);
        }

        return response()->json([
            'success'           => true,
            'updates'           => $results,
            'failures'          => $failures,
            'credits_used'      => $chargeCount,
            'credits_remaining' => max(0, $credits - $chargeCount),
        ]);
    });

    // 2026-05-15 Phase 3 — bulk sync from WP plugin v1.0.14+. Accepts rich
    // payload (post_id, full meta, images, internal_links, outbound_links)
    // and persists across seo_content_index + seo_link_graph + seo_images
    // + seo_outbound_links in one round-trip per batch. Recomputes
    // authority score after the batch is indexed.
    Route::post('/sync-site', function (\Illuminate\Http\Request $r) {
        $wsId  = $r->attributes->get('workspace_id');
        $pages = $r->input('pages', []);
        if (!is_array($pages) || empty($pages)) {
            return response()->json(['success' => false, 'error' => 'pages_array_required'], 422);
        }

        $svc     = app(\App\Engines\SEO\Services\SeoService::class);
        $indexed = 0;
        $errors  = [];

        foreach ($pages as $page) {
            try {
                if (empty($page['url'])) { throw new \InvalidArgumentException('url missing'); }

                // Core index pass — runs scoreContentExtended (Phase 0/1)
                $svc->indexPageFromConnector($wsId, $page);

                // Link graph from internal_links payload
                if (!empty($page['internal_links']) && is_array($page['internal_links'])) {
                    \Illuminate\Support\Facades\DB::table('seo_link_graph')
                        ->where('workspace_id', $wsId)
                        ->where('source_url', $page['url'])
                        ->where('is_internal', true)
                        ->delete();
                    $rows = [];
                    foreach ($page['internal_links'] as $link) {
                        $target = is_array($link) ? ($link['url'] ?? null) : (string) $link;
                        if (!$target) { continue; }
                        $rows[] = [
                            'workspace_id' => $wsId,
                            'source_url'   => $page['url'],
                            'target_url'   => $target,
                            'anchor_text'  => is_array($link) ? ($link['anchor'] ?? null) : null,
                            'is_internal'  => true,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ];
                    }
                    if (!empty($rows)) {
                        \Illuminate\Support\Facades\DB::table('seo_link_graph')->insert($rows);
                    }
                }

                // Rich images from WP media library
                if (!empty($page['images']) && is_array($page['images'])) {
                    foreach ($page['images'] as $img) {
                        if (empty($img['url'])) { continue; }
                        $hasAlt = array_key_exists('alt', $img);
                        \Illuminate\Support\Facades\DB::table('seo_images')->updateOrInsert(
                            ['workspace_id' => $wsId, 'page_url' => $page['url'], 'image_url' => $img['url']],
                            [
                                'alt_text'    => $hasAlt ? (string) $img['alt'] : null,
                                'missing_alt' => !$hasAlt,
                                'empty_alt'   => $hasAlt && trim((string) $img['alt']) === '',
                                'width'       => $img['width']  ?? null,
                                'height'      => $img['height'] ?? null,
                                'scan_method' => 'wp_sync',
                                'updated_at'  => now(),
                                'created_at'  => now(),
                            ]
                        );
                    }
                }

                // Outbound links from content
                if (!empty($page['outbound_links']) && is_array($page['outbound_links'])) {
                    \Illuminate\Support\Facades\DB::table('seo_outbound_links')
                        ->where('workspace_id', $wsId)
                        ->where('source_url', $page['url'])
                        ->delete();
                    foreach ($page['outbound_links'] as $link) {
                        $target = is_array($link) ? ($link['url'] ?? null) : (string) $link;
                        if (!$target) { continue; }
                        $host = preg_replace('/^www\./', '', parse_url($target, PHP_URL_HOST) ?? '');
                        \Illuminate\Support\Facades\DB::table('seo_outbound_links')->insert([
                            'workspace_id' => $wsId,
                            'source_url'   => $page['url'],
                            'target_url'   => $target,
                            'target_host'  => $host,
                            'anchor_text'  => is_array($link)
                                ? mb_substr((string) ($link['anchor'] ?? ''), 0, 300)
                                : null,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                    }
                }

                $indexed++;
            } catch (\Throwable $e) {
                $errors[] = ($page['url'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        // Recompute authority across the workspace after a successful batch.
        if ($indexed > 0) {
            try {
                \Illuminate\Support\Facades\Artisan::call('seo:authority-score',
                    ['workspace_id' => $wsId]);
            } catch (\Throwable $e) {
                \Log::warning('[SEO] post-sync authority-score failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'      => true,
            'pages_synced' => $indexed,
            'errors'       => array_slice($errors, 0, 5),
            'errors_total' => count($errors),
        ]);
    });

    // ── Health check ─────────────────────────────────────────────────────
    Route::get('/ping', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');

        // 2026-05-12: auto-seed seo_settings.site_url from the plugin's home_url
        // (sent via X-Site-URL header or ?site_url= query). Idempotent — only
        // writes when the row is missing or the URL has changed. Lets the SPA
        // pre-fill the audit modal without a separate /connector/register-site
        // call.
        $siteUrl = $r->header('X-Site-URL') ?: $r->query('site_url');
        if ($siteUrl) {
            $clean = rtrim((string) $siteUrl, '/');
            $existing = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
            if ($existing !== $clean) {
                \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                    ['workspace_id' => $wsId, 'key' => 'site_url'],
                    ['value' => $clean, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        $ws   = \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId);
        $plan = $ws ? \Illuminate\Support\Facades\DB::table('plans')
            ->join('subscriptions', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.workspace_id', $wsId)
            ->where('subscriptions.status', 'active')
            ->select('plans.name', 'plans.slug')
            ->first() : null;
        $indexed = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)->count();
        return response()->json([
            'success'           => true,
            'workspace_name'    => $ws->name ?? 'Unknown',
            'plan'              => $plan->slug ?? 'free',
            'credits_remaining' => (int) (\Illuminate\Support\Facades\DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0),
            'seo_pages_indexed' => $indexed,
        ]);
    });

    // ── Analyze a page — WP sends content, we score + index ─────────────
    Route::post('/analyze-page', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'url'              => 'required|url',
            'title'            => 'nullable|string|max:500',
            'meta_description' => 'nullable|string|max:1000',
            'content'          => 'nullable|string',
            'h1'               => 'nullable|string|max:500',
            'h2s'              => 'nullable|array',
            'word_count'       => 'nullable|integer',
            'images'           => 'nullable|array',
            'internal_links'   => 'nullable|array',
            'post_id'          => 'nullable|integer',
        ]);
        $svc = app(\App\Engines\SEO\Services\SeoService::class);
        // Single call — service computes score, persists it, returns both
        $result = $svc->indexPageFromConnector($wsId, $data);
        return response()->json([
            'success' => true,
            'page_id' => $result['page_id'],
            'score'   => $result['score'],
        ]);
    });

    // ── Quick wins (from real seo_audit_items + low-rank keywords) ──────
    Route::get('/quick-wins', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // 2026-05-12 Phase 0 — delegate to SeoDataService so /seo and /connector
        // return identical shapes (no schema drift).
        return response()->json(
            app(\App\Engines\SEO\Services\SeoDataService::class)
                ->quickWins($wsId, $r->query('url'))
        );
        // legacy inline body kept below — never reached:
        $items = \Illuminate\Support\Facades\DB::table('seo_audit_items')
            ->join('seo_audits', 'seo_audit_items.audit_id', '=', 'seo_audits.id')
            ->where('seo_audits.workspace_id', $wsId)
            ->whereIn('seo_audit_items.status', ['error', 'warning'])
            ->when($url, fn($q) => $q->where('seo_audit_items.url', $url))
            ->orderByRaw("CASE seo_audit_items.status WHEN 'error' THEN 1 ELSE 2 END")
            ->orderBy('seo_audit_items.score')  // lower score = bigger gap
            ->limit(20)
            ->select(
                'seo_audit_items.check_name as title',
                'seo_audit_items.category as severity',
                'seo_audit_items.details as description',
                'seo_audit_items.url'
            )
            ->get();
        // Ranking opportunities (positions 11-20 — push to first page)
        $rankWins = \Illuminate\Support\Facades\DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->whereBetween('current_rank', [11, 20])
            ->limit(10)
            ->get(['keyword', 'current_rank', 'volume', 'target_url'])
            ->map(fn($k) => [
                'title'       => "Rank boost: \"{$k->keyword}\" (pos #{$k->current_rank})",
                'severity'    => 'opportunity',
                'description' => "Volume: {$k->volume}. Push from #{$k->current_rank} to top 10.",
                'url'         => $k->target_url,
            ]);
        return response()->json([
            'success'    => true,
            'quick_wins' => array_merge($items->toArray(), $rankWins->toArray()),
            'total'      => $items->count() + $rankWins->count(),
        ]);
    });

    // ── Save meta back to Laravel index ─────────────────────────────────
    Route::patch('/save-meta', function (\Illuminate\Http\Request $r) {
        // 2026-05-15 Phase 4 — was using sha256() but upsertContentIndex
        // hashes via md5(); the prior version updated zero rows. Also
        // wrote to `title` instead of `meta_title`. Match by url string +
        // workspace and write the proper columns.
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'url'              => 'required|url',
            'meta_title'       => 'nullable|string|max:500',
            'meta_description' => 'nullable|string|max:1000',
        ]);
        \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('url', $data['url'])
            ->update([
                'meta_title'       => $data['meta_title']       ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'updated_at'       => now(),
            ]);
        return response()->json(['success' => true]);
    });

    // ── Link opportunities ──────────────────────────────────────────────
    Route::get('/link-opportunities', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // 2026-05-12 Phase 0 — delegate to SeoDataService
        return response()->json(
            app(\App\Engines\SEO\Services\SeoDataService::class)
                ->linkOpportunities($wsId, $r->query('source_url', ''))
        );
    });

    // ── Page score ──────────────────────────────────────────────────────
    Route::get('/pages/score', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $url  = $r->query('url', '');
        $page = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('url_hash', hash('sha256', $url))
            ->first();
        return response()->json([
            'success'      => true,
            'url'          => $url,
            'score'        => $page ? (int) $page->content_score : null,
            'last_indexed' => $page ? $page->updated_at : null,
        ]);
    });

    // ── Indexed content list (paginated) ────────────────────────────────
    Route::get('/indexed-content', function (\Illuminate\Http\Request $r) {
        $wsId    = $r->attributes->get('workspace_id');
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
        $filter  = $r->query('filter', '');
        $q       = $r->query('q', '');
        $query   = \Illuminate\Support\Facades\DB::table('seo_content_index')
            ->where('workspace_id', $wsId);
        if ($filter === 'low_score')      $query->where('content_score', '<', 50);
        if ($filter === 'missing_meta')   $query->whereNull('meta_description');
        if ($filter === 'thin_content')   $query->where('word_count', '<', 300);
        if ($filter === 'no_h1')          $query->whereNull('h1');
        if ($q) $query->where(fn($x) =>
            $x->where('url', 'like', "%$q%")
              ->orWhere('title', 'like', "%$q%")
        );
        return response()->json([
            'success' => true,
            'data'    => $query->orderBy('content_score')->paginate($perPage),
        ]);
    });

    // ── Keywords list ───────────────────────────────────────────────────
    Route::get('/keywords', function (\Illuminate\Http\Request $r) {
        $wsId    = $r->attributes->get('workspace_id');
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
        return response()->json([
            'success' => true,
            'data'    => \Illuminate\Support\Facades\DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->orderByDesc('volume')
                ->paginate($perPage),
        ]);
    });

    Route::get('/keywords/{id}', function (\Illuminate\Http\Request $r, $id) {
        $wsId = $r->attributes->get('workspace_id');
        $kw   = \Illuminate\Support\Facades\DB::table('seo_keywords')
            ->where('workspace_id', $wsId)->find($id);
        if (! $kw) return response()->json(['error' => 'not_found'], 404);
        return response()->json(['success' => true, 'data' => $kw]);
    });

    // ── Competitors (derived from seo_serp_results.domain — NOT competitor_domain) ──
    Route::get('/competitors', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        // 2026-05-12 Phase 0 — delegate to SeoDataService
        return response()->json(
            app(\App\Engines\SEO\Services\SeoDataService::class)
                ->competitors($wsId)
        );
    });

    // ── Reports (calls SeoService::getReport — NOT report) ──────────────
    Route::get('/reports', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $svc  = app(\App\Engines\SEO\Services\SeoService::class);
        return response()->json($svc->getReport($wsId));
    });

    // ── Content Publishing Pipeline ─────────────────────────────────────

    // Generate featured image via Creative engine (Phase 2 — DALL-E 3)
    // 2026-05-13 — credit billing moved into the route layer.
    // Cost is 1 credit per the canonical credit-cost table (image_gen auto/mini).
    // The previous version reported "credits_used: 10" via a `?? 10` fallback
    // but CreativeService::generateImage does no charging itself — i.e. every
    // call was effectively free. We now reserve→commit/release in the route,
    // matching the /connector/pages/regenerate-image and /connector/generate-article
    // pattern. CreativeService stays untouched (CREATIVE888 lock honored).
    Route::post('/generate-image', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'prompt'  => 'required|string|max:1000',
            'style'   => 'nullable|string|max:100',
            'context' => 'nullable|string|max:500',
        ]);

        $IMAGE_COST = 1;
        $balance = (int) (\Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $wsId)->value('balance') ?? 0);
        if ($balance < $IMAGE_COST) {
            return response()->json([
                'success'         => false,
                'error'           => 'insufficient_credits',
                'code'            => 'NO_CREDITS',
                'required_credits'=> $IMAGE_COST,
                'available'       => $balance,
            ], 402);
        }

        $creditSvc = app(\App\Core\Billing\CreditService::class);
        $reservationRef = $creditSvc->reserve($wsId, $IMAGE_COST, 'generate-image');

        // Build prompt with context layered in
        $prompt = $data['prompt'];
        if (!empty($data['context'])) {
            $prompt = 'Blog featured image for: ' . $data['context'] . '. ' . $prompt;
        }
        if (!empty($data['style'])) {
            $prompt .= ' Style: ' . $data['style'];
        }

        try {
            $svc    = app(\App\Engines\Creative\Services\CreativeService::class);
            $result = $svc->generateImage($wsId, [
                'prompt'       => $prompt,
                'aspect_ratio' => '1:1',  // CreativeService uses aspect_ratio, not 'size'
                'quality'      => 'standard',
                'style'        => 'natural',
                'type'         => 'featured_image',
            ]);
        } catch (\Throwable $e) {
            $creditSvc->release($wsId, $reservationRef);
            \Illuminate\Support\Facades\Log::warning('generate-image exception', [
                'workspace_id' => $wsId, 'err' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => 'generation_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        $ok = ($result['status'] ?? '') === 'completed' && !empty($result['url']);
        if ($ok) {
            $creditSvc->commit($wsId, $reservationRef, $IMAGE_COST);
            $creditsCharged = $IMAGE_COST;
        } else {
            $creditSvc->release($wsId, $reservationRef);
            $creditsCharged = 0;
        }

        return response()->json([
            'success'      => $ok,
            'image_url'    => $result['url'] ?? null,
            'asset_id'     => $result['asset_id'] ?? null,
            'status'       => $result['status'] ?? 'unknown',
            'credits_used' => $creditsCharged,
            'prompt_used'  => $prompt,
            'error'        => $result['error'] ?? null,
        ]);
    });

    // Register a WP site connection (called once by plugin during setup)
    Route::post('/register-site', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'site_url'       => 'required|url',
            'webhook_secret' => 'required|string|min:10',
            'site_name'      => 'nullable|string|max:255',
        ]);
        $now = now();
        \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
            ['workspace_id' => $wsId, 'key' => 'site_url'],
            ['value' => rtrim($data['site_url'], '/'), 'updated_at' => $now, 'created_at' => $now]
        );
        \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
            ['workspace_id' => $wsId, 'key' => 'webhook_secret'],
            ['value' => $data['webhook_secret'], 'updated_at' => $now, 'created_at' => $now]
        );
        if (!empty($data['site_name'])) {
            \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                ['workspace_id' => $wsId, 'key' => 'site_name'],
                ['value' => $data['site_name'], 'updated_at' => $now, 'created_at' => $now]
            );
        }
        return response()->json(['success' => true, 'message' => 'Site registered successfully.']);
    });

    // Publish article to WP site via lgsc/v1/create-post callback
    Route::post('/publish-post', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'title'              => 'required|string|max:500',
            'content'            => 'required|string',
            'status'             => 'nullable|in:publish,draft,future',
            'categories'         => 'nullable|array',
            'tags'               => 'nullable|array',
            'meta_title'         => 'nullable|string|max:500',
            'meta_description'   => 'nullable|string|max:1000',
            'featured_image_url' => 'nullable|url',
            'featured_image_alt' => 'nullable|string|max:300',
            'levelup_article_id' => 'nullable|integer',
            'scheduled_at'       => 'nullable|string',
        ]);
        $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
        $webhookSecret = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
        if (!$siteUrl) {
            return response()->json([
                'error'   => 'site_not_configured',
                'message' => 'No WordPress site registered. Call /connector/register-site first.',
            ], 422);
        }
        $payload = array_merge($data, [
            'secret' => $webhookSecret ?? '',
            'status' => $data['status'] ?? 'publish',
        ]);
        $wpUrl = rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/create-post';
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json', 'X-LGSC-Secret' => $webhookSecret ?? ''])
                ->post($wpUrl, $payload);
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'wp_unreachable',
                'message' => 'Could not reach WordPress site: ' . $e->getMessage(),
            ], 502);
        }
        if (!$response->successful()) {
            return response()->json([
                'error'    => 'wp_publish_failed',
                'message'  => 'WordPress returned HTTP ' . $response->status(),
                'wp_error' => $response->json(),
            ], 502);
        }
        $wpResult = $response->json();
        // Track in seo_content_index
        if (!empty(($wpResult['url'] ?? $wpResult['view'] ?? null))) {
            \Illuminate\Support\Facades\DB::table('seo_content_index')->updateOrInsert(
                ['workspace_id' => $wsId, 'url_hash' => hash('sha256', ($wpResult['url'] ?? $wpResult['view'] ?? null))],
                [
                    'url'        => ($wpResult['url'] ?? $wpResult['view'] ?? null),
                    'title'      => $data['title'],
                    'updated_at' => now(),
                ]
            );
        }
        // Change 2B-2 Step 3: persist wp_post_id to articles table
        if (
            isset($data['levelup_article_id']) &&
            is_numeric($data['levelup_article_id']) &&
            isset($wpResult['post_id']) &&
            is_numeric($wpResult['post_id'])
        ) {
            DB::table('articles')
                ->where('id', (int) $data['levelup_article_id'])
                ->where('workspace_id', $wsId)
                ->update(['wp_post_id' => (int) $wpResult['post_id']]);
        }

        // Change 2B-3 Hook B: sideload featured_image_url to WP, persist wp_thumbnail_id.
        // Best-effort: publish succeeds regardless of attach outcome.
        if (
            !empty($data['featured_image_url']) &&
            filter_var($data['featured_image_url'], FILTER_VALIDATE_URL) &&
            isset($wpResult['post_id']) &&
            is_numeric($wpResult['post_id']) &&
            empty($wpResult['thumbnail_id']) &&
            $siteUrl && $webhookSecret
        ) {
            try {
                $attachResp = \Illuminate\Support\Facades\Http::timeout(60)
                    ->withHeaders([
                        'Content-Type'  => 'application/json',
                        'X-LGSC-Secret' => $webhookSecret,
                    ])
                    ->post(rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/attach-image', [
                        'post_id'   => (int) $wpResult['post_id'],
                        'image_url' => (string) $data['featured_image_url'],
                    ]);
                if ($attachResp->successful()) {
                    $attachId = (int) ($attachResp->json('attachment_id') ?? 0);
                    if (
                        $attachId > 0 &&
                        isset($data['levelup_article_id']) &&
                        is_numeric($data['levelup_article_id'])
                    ) {
                        DB::table('articles')
                            ->where('id', (int) $data['levelup_article_id'])
                            ->where('workspace_id', $wsId)
                            ->update(['wp_thumbnail_id' => $attachId]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning('publish-post: attach-image non-2xx', [
                        'workspace_id' => $wsId,
                        'post_id'      => (int) $wpResult['post_id'],
                        'http_status'  => $attachResp->status(),
                        'body'         => $attachResp->json(),
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('publish-post: attach-image exception', [
                    'workspace_id' => $wsId,
                    'post_id'      => (int) $wpResult['post_id'],
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success'   => true,
            'post_id'   => $wpResult['post_id'] ?? null,
            'url'       => ($wpResult['url'] ?? $wpResult['view'] ?? null) ?? null,
            'status'    => $wpResult['status'] ?? ($data['status'] ?? 'publish'),
            'thumbnail' => $wpResult['thumbnail_id'] ?? null,
        ]);
    });

    // Update existing WP post via lgsc/v1/update-post
    Route::post('/update-post', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'post_id'            => 'nullable|integer',
            'url'                => 'nullable|url',
            'title'              => 'nullable|string|max:500',
            'content'            => 'nullable|string',
            'meta_title'         => 'nullable|string|max:500',
            'meta_description'   => 'nullable|string|max:1000',
            'featured_image_url' => 'nullable|url',
            'featured_image_alt' => 'nullable|string|max:300',
        ]);
        if (empty($data['post_id']) && empty($data['url'])) {
            return response()->json(['error' => 'post_id or url required'], 422);
        }
        $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
        $webhookSecret = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
        if (!$siteUrl) {
            return response()->json(['error' => 'site_not_configured'], 422);
        }
        $payload = array_merge($data, ['secret' => $webhookSecret ?? '']);
        $wpUrl   = rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/update-post';
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)->post($wpUrl, $payload);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'wp_unreachable', 'message' => $e->getMessage()], 502);
        }
        if (!$response->successful()) {
            return response()->json([
                'error'    => 'wp_update_failed',
                'status'   => $response->status(),
                'wp_error' => $response->json(),
            ], 502);
        }
        return response()->json(['success' => true] + $response->json());
    });

    // List posts from WP site via lgsc/v1/posts
    Route::get('/posts', function (\Illuminate\Http\Request $r) {
        $wsId    = $r->attributes->get('workspace_id');
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
        $page    = max(1, (int) $r->query('page', 1));
        $status  = $r->query('status', 'publish');
        $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value');
        $webhookSecret = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'webhook_secret')->value('value');
        if (!$siteUrl) {
            return response()->json(['success' => true, 'posts' => [], 'total' => 0]);
        }
        $wpUrl = rtrim($siteUrl, '/') . '/wp-json/lgsc/v1/posts';
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->get($wpUrl, [
                'secret'   => $webhookSecret ?? '',
                'page'     => $page,
                'per_page' => $perPage,
                'status'   => $status,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true, 'posts' => [], 'total' => 0,
                'error'   => 'wp_unreachable',
            ]);
        }
        if (!$response->successful()) {
            return response()->json([
                'success' => true, 'posts' => [], 'total' => 0,
                'error'   => 'wp_' . $response->status(),
            ]);
        }
        return response()->json($response->json());
    });

    // ── Assistant message ──────────────────────────────────────────────
    Route::post('/assistant/message', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'message'  => 'required|string|max:2000',
            'context'  => 'nullable|array',
            'site_url' => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        // ── P2-B IDEMPOTENCY GATE (2026-07-27) ───────────────────────────────
        // Placed BEFORE meterChat deliberately: a duplicate submission must
        // return the first answer without ticking the meter, calling the
        // provider, or persisting a second message. Opt-in — with no
        // idempotency_key, or the flag off, everything below runs exactly as
        // it did before and no existing client changes behaviour.
        $__gate = app(\App\Core\Chat\ChatIdempotencyGate::class);
        $__gated = $__gate->wrap($r, [
            'workspace_id'    => (int) $wsId,
            'user_id'         => $r->user()?->id,
            'surface'         => 's4_seo_assistant',
            'conversation_type' => 'seo_assistant',
            'conversation_id' => (string) $wsId,
            'content'         => (string) $data['message'],
        ], function (string $correlationId) use ($r, $wsId, $data) {
            $resp = \App\Core\Chat\SeoAssistantIdempotentRunner::run($r, (int) $wsId, $data, $correlationId);
            return ['body' => $resp[0], 'status' => $resp[1]];
        });

        if ($__gated !== null) {
            return response()->json($__gated['body'], $__gated['status']);
        }
        // Wave 22 — 10-chat batched metering (0.1 cr effective per chat).
        $meter = app(\App\Core\Billing\CreditService::class)->meterChat((int) $wsId, 'assistant_message');
        // Wave 24 — surface counter via JSON only (raw header() unreliable in route closures).
        if (!$meter['sufficient']) {
            // ── P2-A — PERSIST THE USER'S MESSAGE ON REFUSAL ─────────────────
            // CHAT-CONTRACT-v1 clause P-02: the user's own words are not the
            // platform's to discard because of a billing state.
            //
            // Placed inside the refusal branch, NOT before the meter:
            // SeoAssistantService::appendTurn() already writes the user row on
            // the success path, so persisting before the meter would insert it
            // twice on every successful chat.
            $_seoSaved = false;
            try {
                \Illuminate\Support\Facades\DB::table('seo_assistant_messages')->insert([
                    'workspace_id' => (int) $wsId,
                    'user_id'      => $r->user()?->id,
                    'role'         => 'user',
                    'content'      => mb_substr($data['message'], 0, 65535),
                    'created_at'   => now(),
                ]);
                $_seoSaved = true;
            } catch (\Throwable $e) {
                // Clause O-04: a persistence failure must never be silent.
                \Illuminate\Support\Facades\Log::error('chat.persist_failed', [
                    'stage'        => 'seo_assistant_refusal',
                    'workspace_id' => $wsId,
                    'exception'    => $e->getMessage(),
                ]);
            }

            $_seoCopy = $_seoSaved
                ? "This workspace is out of credits, so the assistant can't reply right now. Your message has been saved — add credits and it'll pick up right where you left off."
                : "This workspace is out of credits, so the assistant can't reply right now. Add credits and it'll pick up right where you left off.";

            return response()->json([
                'success' => false,
                'error'   => $_seoCopy,
                'required_credits' => 1,
                'chat_counter'     => $meter['counter'],
                'message_saved'    => $_seoSaved,
                'chat_error' => [
                    'code'            => 'CHAT_INSUFFICIENT_CREDITS',
                    'message'         => $_seoCopy,
                    'retryable'       => false,
                    'provider_called' => false,
                    'persistence'     => ['user_message_saved' => $_seoSaved, 'assistant_message_saved' => false],
                    'action'          => ['label' => 'Top up credits', 'href' => '/app/billing'],
                ],
            ], 402);
        }
        // Wave 1 (2026-05-17) — force user_id into context server-side so
        // disclaimer gate + chat-log persistence work regardless of what
        // the client sent. Client-supplied user_id is not trusted.
        $context = $data['context'] ?? [];
        $context['user_id'] = $r->user()?->id;
        // Wave 16b (2026-05-19) — pass the active site URL through to the
        // SEO assistant so its buildLiveContext queries only that site.
        if (! empty($data['site_url'])) {
            $context['site_url'] = $data['site_url'];
        } elseif (empty($context['site_url']) && $h = $r->header('X-Lgse-Active-Site')) {
            $context['site_url'] = $h;
        }
        $result = app(\App\Engines\SEO\Services\SeoService::class)
            ->assistantMessage($wsId, $data['message'], $context);
        return response()->json([
            'success'    => true,
            'data'       => $result,
            'chat_meter' => [
                'counter'  => $meter['counter'],
                'debited'  => $meter['debited'],
                'effective_cost' => '0.1 cr',
                'threshold'      => 10,
            ],
        ]);
    });

    // ════════════════════════════════════════════════════════════════════
    // 2026-05-13 — Chatbot connector routes (WP plugin onboarding path)
    // ════════════════════════════════════════════════════════════════════
    // The WP plugin only has X-API-KEY auth — the JWT-authed /api/chatbot/*
    // routes can't be reached from it. These connector mirrors expose the
    // narrow subset the plugin needs (token mint + KB CRUD) under api.key
    // auth so onboarding from wp-admin works end-to-end.
    //
    // Settings (greeting/color/theme/business hours) remain SPA-only —
    // single source of truth is the chatbot_settings table, managed via
    // /api/chatbot/settings in the SPA.

    Route::get('/chatbot/status', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $settings = \Illuminate\Support\Facades\DB::table('chatbot_settings')
            ->where('workspace_id', $wsId)->first();
        $latestToken = \Illuminate\Support\Facades\DB::table('chatbot_widget_tokens')
            ->where('workspace_id', $wsId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first(['id', 'token_prefix', 'label', 'allowed_domains_json', 'created_at']);
        return response()->json([
            'success'  => true,
            'data'     => [
                'enabled'       => (bool) ($settings->enabled ?? false),
                'greeting'      => $settings->greeting       ?? null,
                'primary_color' => $settings->primary_color  ?? null,
                'theme'         => $settings->theme          ?? null,
                'token'         => $latestToken,
            ],
        ]);
    });

    // 2026-05-28 — PUT /chatbot/settings — narrow write surface so the
    // WP plugin can change brand color from wp-admin without bouncing the
    // user to the SPA. Single field for now (primary_color); extend with
    // greeting / theme when those get pickers in WP too. Mirrors what the
    // SPA's AdminChatbotController::updateSettings does for this one field.
    Route::put('/chatbot/settings', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $existing = \Illuminate\Support\Facades\DB::table('chatbot_settings')
            ->where('workspace_id', $wsId)->first();
        if ($existing) {
            \Illuminate\Support\Facades\DB::table('chatbot_settings')
                ->where('workspace_id', $wsId)
                ->update(['primary_color' => $data['primary_color'], 'updated_at' => now()]);
        } else {
            \Illuminate\Support\Facades\DB::table('chatbot_settings')->insert([
                'workspace_id'  => $wsId,
                'primary_color' => $data['primary_color'],
                'enabled'       => false,
                'theme'         => 'auto',
                'timezone'      => 'UTC',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
        return response()->json([
            'success' => true,
            'data'    => \Illuminate\Support\Facades\DB::table('chatbot_settings')
                ->where('workspace_id', $wsId)
                ->first(['enabled', 'greeting', 'primary_color', 'theme']),
        ]);
    });

    Route::post('/chatbot/widget-token', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'allowed_domains'    => 'required|array|min:1|max:10',
            'allowed_domains.*'  => 'string|max:255',
            'label'              => 'nullable|string|max:255',
            'site_connection_id' => 'nullable|integer',
        ]);
        try {
            $minted = app(\App\Engines\Chatbot\Services\ChatbotWidgetTokenService::class)
                ->mint(
                    $wsId,
                    isset($data['site_connection_id']) ? (int) $data['site_connection_id'] : null,
                    null,
                    $data['allowed_domains'],
                    $data['label'] ?? 'WP plugin'
                );
            return response()->json([
                'success' => true,
                'data'    => [
                    'token'  => $minted['plain'],
                    'id'     => $minted['id'],
                    'prefix' => $minted['prefix'],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('connector/chatbot/widget-token failed', [
                'workspace_id' => $wsId, 'err' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'MINT_FAILED', 'message' => $e->getMessage()], 500);
        }
    });

    Route::get('/chatbot/knowledge', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $sources = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->get(['id','label','source_type','mime_type','size_bytes','chunk_count','status','error_message','created_at']);
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        return response()->json([
            'success' => true,
            'data'    => [
                'sources'   => $sources,
                'doc_count' => $sources->count(),
                'doc_limit' => $gate->chatbotKbDocLimit($wsId),
            ],
        ]);
    });

    Route::post('/chatbot/knowledge', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $r->validate([
            'file'  => 'required|file|max:10240', // 10 MB
            'label' => 'nullable|string|max:255',
        ]);
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        $count = (int) \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $wsId)->count();
        $limit = $gate->chatbotKbDocLimit($wsId);
        if ($limit > 0 && $count >= $limit) {
            return response()->json([
                'success' => false,
                'error'   => 'KB_LIMIT_REACHED',
                'message' => "Knowledge base limit reached ({$limit} documents). Delete an existing document or upgrade your plan.",
            ], 403);
        }
        try {
            $sourceId = app(\App\Engines\Chatbot\Services\ChatbotKnowledgeService::class)
                ->ingestFile($wsId, $r->file('file'), $r->input('label'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('connector/chatbot/knowledge upload failed', [
                'workspace_id' => $wsId, 'err' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'INTERNAL', 'message' => 'Could not ingest document.'], 500);
        }
        return response()->json([
            'success' => true,
            'data'    => \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->first(),
        ]);
    });

    Route::post('/chatbot/knowledge/text', function (\Illuminate\Http\Request $r) {
        $wsId = $r->attributes->get('workspace_id');
        $data = $r->validate([
            'label' => 'required|string|max:255',
            'text'  => 'required|string|max:200000',
        ]);
        $sourceId = app(\App\Engines\Chatbot\Services\ChatbotKnowledgeService::class)
            ->ingestText($wsId, $data['label'], $data['text']);
        return response()->json([
            'success' => true,
            'data'    => \Illuminate\Support\Facades\DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->first(),
        ]);
    });

    Route::delete('/chatbot/knowledge/{id}', function (\Illuminate\Http\Request $r, int $id) {
        $wsId = $r->attributes->get('workspace_id');
        $ok = app(\App\Engines\Chatbot\Services\ChatbotKnowledgeService::class)
            ->deleteSource($wsId, $id);
        if (! $ok) {
            return response()->json(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        return response()->json(['success' => true]);
    });

    // 2026-05-28 — Website crawl over the connector (WP plugin entry point).
    // Same job as the SPA route — workspace is resolved from the X-API-KEY.
    Route::post('/chatbot/knowledge/crawl-site', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        if (! $wsId) {
            return response()->json(['success' => false, 'error' => 'NO_WORKSPACE'], 400);
        }
        $data = $r->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
        ]);
        $maxPages = (int) ($data['max_pages'] ?? \App\Engines\Chatbot\Services\ChatbotWebsiteCrawler::DEFAULT_MAX_PAGES);

        if (\App\Jobs\CrawlChatbotKnowledgeJob::isRunning($wsId)) {
            return response()->json([
                'success' => false,
                'error'   => 'ALREADY_RUNNING',
                'message' => 'A crawl is already in progress for this workspace.',
                'status'  => \Illuminate\Support\Facades\Cache::get(\App\Jobs\CrawlChatbotKnowledgeJob::statusKey($wsId)),
            ], 409);
        }
        \App\Jobs\CrawlChatbotKnowledgeJob::dispatch($wsId, $maxPages);
        return response()->json([
            'success'   => true,
            'message'   => 'Crawl queued — pages will appear in the knowledge base shortly.',
            'max_pages' => $maxPages,
        ], 202);
    });

    Route::get('/chatbot/knowledge/crawl-status', function (\Illuminate\Http\Request $r) {
        $wsId = (int) $r->attributes->get('workspace_id');
        $status = \Illuminate\Support\Facades\Cache::get(\App\Jobs\CrawlChatbotKnowledgeJob::statusKey($wsId));
        return response()->json(['success' => true, 'status' => $status]);
    });
});


// ════════════════════════════════════════════════════════════════════════════
// §5 Settings — API Keys + WordPress Sites (2026-05-11)
// Auth: JWT (workspace-scoped). Powers the in-app settings tabs that let the
// user mint connector API keys, view/revoke them, and inspect connected WP
// sites + rotate webhook secrets.
// ════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth.jwt'])->prefix('settings')->group(function () {

    // ── API Keys ────────────────────────────────────────────────────────
    Route::prefix('api-keys')->group(function () {

        // List active keys (no full key text — preview only)
        Route::get('/', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $keys = \Illuminate\Support\Facades\DB::table('api_keys')
                ->where('workspace_id', $wsId)
                ->where('is_active', true)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'name', 'type',
                    \Illuminate\Support\Facades\DB::raw("CONCAT(LEFT(`key`, 12), '…') as key_preview"),
                    'last_used_at', 'created_at',
                ]);
            return response()->json(['success' => true, 'keys' => $keys]);
        });

        // Mint a new key — returns full lgs_* once; never shown again
        Route::post('/', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $data = $r->validate([
                'name' => 'nullable|string|max:100',
                'type' => 'nullable|in:connector,agent,admin',
            ]);
            $key = 'lgs_' . bin2hex(random_bytes(24));  // 52-char (lgs_ + 48 hex)
            $id  = \Illuminate\Support\Facades\DB::table('api_keys')->insertGetId([
                'workspace_id' => $wsId,
                'user_id'      => optional($r->user())->id,
                'key'          => $key,
                'name'         => $data['name'] ?? 'WP Connector',
                'type'         => $data['type'] ?? 'connector',
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            return response()->json([
                'success' => true,
                'key'     => $key,
                'id'      => $id,
                'message' => 'Copy this key now — it will not be shown again.',
            ]);
        });

        // Revoke (soft-delete via is_active=false)
        Route::delete('/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('api_keys')
                ->where('id', $id)
                ->where('workspace_id', $wsId)
                ->update(['is_active' => false, 'updated_at' => now()]);
            return response()->json(['success' => (bool) $rows]);
        });
    });

    // ── WordPress Sites ─────────────────────────────────────────────────
    Route::prefix('wp-sites')->group(function () {

        // List connected sites (currently 1-per-workspace via seo_settings)
        Route::get('/', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $get = fn($k) => \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)->where('key', $k)->value('value');
            $siteUrl       = $get('site_url');
            $siteName      = $get('site_name');
            $webhookSecret = $get('webhook_secret');
            $indexed       = \Illuminate\Support\Facades\DB::table('seo_content_index')
                ->where('workspace_id', $wsId)->count();
            $sites = $siteUrl ? [[
                'url'            => $siteUrl,
                'name'           => $siteName ?: $siteUrl,
                'webhook_secret' => $webhookSecret,
                'pages_indexed'  => $indexed,
                'status'         => 'connected',
            ]] : [];
            return response()->json(['success' => true, 'sites' => $sites]);
        });

        // Disconnect — drops site_url, site_name, webhook_secret rows
        Route::delete('/', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->whereIn('key', ['site_url', 'site_name', 'webhook_secret'])
                ->delete();
            return response()->json(['success' => true, 'deleted' => $rows]);
        });

        // Rotate webhook secret (40-char hex)
        Route::post('/rotate-secret', function (\Illuminate\Http\Request $r) {
            $wsId   = $r->attributes->get('workspace_id');
            $secret = bin2hex(random_bytes(20));
            \Illuminate\Support\Facades\DB::table('seo_settings')->updateOrInsert(
                ['workspace_id' => $wsId, 'key' => 'webhook_secret'],
                ['value' => $secret, 'updated_at' => now(), 'created_at' => now()]
            );
            return response()->json(['success' => true, 'secret' => $secret]);
        });
    });
});

// ── Content Pack routes (H1 — cross-engine campaign orchestrator) /* h1-batch3-routes */
Route::middleware(['auth.jwt'])->prefix('content-packs')->group(function () {
    Route::post('/',             [\App\Engines\Content\Controllers\ContentPackController::class, 'create']);
    Route::get('/',              [\App\Engines\Content\Controllers\ContentPackController::class, 'list']);
    Route::get('/{id}',          [\App\Engines\Content\Controllers\ContentPackController::class, 'get']);
    Route::post('/{id}/assets',  [\App\Engines\Content\Controllers\ContentPackController::class, 'addAsset']);
    Route::post('/{id}/publish', [\App\Engines\Content\Controllers\ContentPackController::class, 'publish']);
});


// ── Sarah cross-engine campaign drafting (Batch 4) /* b4-sarah-route */
Route::middleware(['auth.jwt'])->post('/sarah/draft-campaign', function (\Illuminate\Http\Request $r) {
    $kernel = app(\App\Core\EngineKernel\EngineExecutionService::class);
    return response()->json($kernel->execute(
        (int) $r->attributes->get('workspace_id'),
        'sarah',
        'draft_campaign',
        $r->all(),
        ['source' => 'manual', 'user_id' => $r->user()?->id]
    ));
});


// ── Publish Queue routes (Batch 9 — per-asset preview review inside Plans) /* b9-publish-routes */
Route::middleware(['auth.jwt'])->prefix('publish-queue')->group(function () {
    Route::get('/',                  [\App\Http\Controllers\PublishQueueController::class, 'index']);
    Route::get('/plan/{planId}',     [\App\Http\Controllers\PublishQueueController::class, 'listForPlan']);
    Route::post('/bulk-approve',     [\App\Http\Controllers\PublishQueueController::class, 'bulkApprove']);
    Route::post('/{id}/approve',     [\App\Http\Controllers\PublishQueueController::class, 'approve']);
    Route::post('/{id}/reject',      [\App\Http\Controllers\PublishQueueController::class, 'reject']);
});


// ── Plan Command Center (Batch 15 — aggregated read for UI) /* b15-command-center */
Route::middleware(['auth.jwt'])->prefix('plans')->group(function () {
    Route::get('/{id}/command-center', [\App\Http\Controllers\PlanCommandCenterController::class, 'show']);
    Route::get('/{id}/summary',        [\App\Http\Controllers\PlanCommandCenterController::class, 'summary']);
});


// ── Automation calendar surfaces (Phase 4) /* b20-phase4-routes */
// /api/automation/calendar       — Main Automation Cal aggregated view
// /api/email-marketing/calendar  — Email Marketing per-engine convenience view
// Both read from automation_events ONLY (never user's calendar_events).
Route::middleware(['auth.jwt'])->group(function () {
    Route::get('/automation/calendar', [\App\Http\Controllers\AutomationCalendarController::class, 'main']);
    Route::get('/email-marketing/calendar', [\App\Http\Controllers\AutomationCalendarController::class, 'emailMarketing']);
});


    /* B27: projects-phase3-meeting-handoff */
    Route::middleware(['auth.jwt'])->group(function () {
        Route::post('/projects/from-meeting/{meetingId}/ratify', function (\Illuminate\Http\Request $r, $meetingId) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $userId = (int) $user->id;
            $opts = $r->only(['duration_days','budget_credits','channels','kpi_count','milestone_count','name_override','goal_override']);
            $svc = app(\App\Core\Projects\ProjectFromMeetingService::class);
            $res = $svc->ratify($wsId, $userId, (int) $meetingId, $opts);
            $code = !empty($res['success']) ? 200 : (isset($res['project_id']) ? 409 : 422);
            return response()->json($res, $code);
        });
        Route::post('/projects/{id}/persist-proposal', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $kpis = $r->input('kpis', []);
            $milestones = $r->input('milestones', []);
            if (!is_array($kpis) || !is_array($milestones)) {
                return response()->json(['success' => false, 'error' => 'kpis and milestones must be arrays'], 422);
            }
            $svc = app(\App\Core\Projects\ProjectFromMeetingService::class);
            $res = $svc->persistProposal($wsId, (int) $id, $kpis, $milestones);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
    });

    /* B28: projects-phase4-disposition */
    Route::middleware(['auth.jwt'])->group(function () {
        Route::post('/projects/{id}/dispose', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $userId = (int) $user->id;
            $outcome = (string) $r->input('outcome', '');
            $opts = $r->only(['weights', 'evidence', 'skip_narrative', 'replace']);
            $svc = app(\App\Core\Projects\ProjectDispositionService::class);
            $res = $svc->disposeProject($wsId, (int) $id, $outcome, $userId, $opts);
            $code = !empty($res['success']) ? 200 : (isset($res['outcome_id']) ? 409 : 422);
            return response()->json($res, $code);
        });
        Route::get('/projects/{id}/outcome', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Core\Projects\ProjectDispositionService::class);
            $res = $svc->get($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
    });

    /* B29: projects-phase5a-reads */
    Route::middleware(['auth.jwt'])->group(function () {
        Route::get('/projects', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Core\Projects\ProjectReadService::class);
            $opts = $r->only(['status','source_type','owner_user_id','q','sort','dir','page','per_page']);
            return response()->json($svc->list($wsId, $opts));
        });
        Route::get('/projects/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Core\Projects\ProjectReadService::class);
            $res = $svc->single($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
        Route::get('/projects/{id}/milestones', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $status = $r->query('status');
            $ms = app(\App\Core\Projects\MilestoneService::class);
            return response()->json($ms->listForProject($wsId, (int) $id, $status));
        });
        Route::get('/projects/{id}/kpis', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $ks = app(\App\Core\Projects\KpiService::class);
            return response()->json($ks->listForProject($wsId, (int) $id));
        });
        Route::get('/projects/{id}/timeline', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Core\Projects\ProjectReadService::class);
            $res = $svc->timeline($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
        Route::get('/projects/{id}/publish-queue', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Core\Projects\ProjectReadService::class);
            $res = $svc->publishQueue($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
    });

    /* B31: projects-phase5b2-mutations */
    Route::middleware(['auth.jwt'])->group(function () {
        // ── Project create / update ───────────────────────────
        Route::post('/projects', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $data = $r->only(['name','goal','description','source_type','source_meeting_id',
                'planned_start_at','planned_end_at','budget_credits','metadata']);
            // Direct creation: stamp owner + default to source_type='direct'
            $data['owner_user_id'] = $data['owner_user_id'] ?? (int) $user->id;
            if (empty($data['source_type'])) $data['source_type'] = 'direct';
            $res = app(\App\Core\Projects\ProjectService::class)->create($wsId, $data);
            return response()->json($res, !empty($res['success']) ? 201 : 422);
        });
        Route::patch('/projects/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $data = $r->only(['name','goal','description','planned_start_at','planned_end_at',
                'budget_credits','metadata']);
            $res = app(\App\Core\Projects\ProjectService::class)->update($wsId, (int) $id, $data);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });

        // ── Milestones ────────────────────────────────────────
        Route::post('/projects/{id}/milestones', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $data = $r->only(['title','description','target_date','order_index',
                'success_criteria','notes']);
            $res = app(\App\Core\Projects\MilestoneService::class)
                ->create($wsId, (int) $id, $data);
            return response()->json($res, !empty($res['success']) ? 201 : 422);
        });
        Route::patch('/projects/{id}/milestones/{mid}', function (\Illuminate\Http\Request $r, $id, $mid) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $data = $r->only(['title','description','target_date','order_index',
                'success_criteria','notes']);
            $res = app(\App\Core\Projects\MilestoneService::class)
                ->update($wsId, (int) $mid, $data);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
        Route::post('/projects/{id}/milestones/{mid}/achieve', function (\Illuminate\Http\Request $r, $id, $mid) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $evidence = $r->input('evidence', []);
            if (!is_array($evidence)) $evidence = ['notes' => (string) $evidence];
            $res = app(\App\Core\Projects\MilestoneService::class)
                ->markAchieved($wsId, (int) $mid, $evidence);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });

        // ── KPIs ──────────────────────────────────────────────
        Route::post('/projects/{id}/kpis', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $data = $r->only(['name','description','target_value','current_value',
                'unit','direction','measurement_source','order_index','metadata']);
            $res = app(\App\Core\Projects\KpiService::class)
                ->create($wsId, (int) $id, $data);
            return response()->json($res, !empty($res['success']) ? 201 : 422);
        });
        Route::post('/projects/{id}/kpis/{kid}/measure', function (\Illuminate\Http\Request $r, $id, $kid) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $value = $r->input('value');
            if (!is_numeric($value)) {
                return response()->json(['success' => false, 'error' => 'value must be numeric'], 422);
            }
            $res = app(\App\Core\Projects\KpiService::class)
                ->recordValue($wsId, (int) $kid, (float) $value);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
    });


    /* B33: mention-phase2-scan */
    Route::middleware(['auth.jwt'])->group(function () {
        Route::post('/mentions/scan-now/{watchlistId}', function (\Illuminate\Http\Request $r, $watchlistId) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $opts = $r->only(['max_results', 'skip_sentiment', 'agent_slug']);
            $opts['user_id']   = (int) $user->id;
            $opts['scan_type'] = 'manual';
            $svc = app(\App\Engines\Mention\Services\MentionScanService::class);
            $res = $svc->scanWatchlist($wsId, (int) $watchlistId, $opts);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
    });

    /* B35: mention-phase4-reads */
    Route::middleware(['auth.jwt'])->group(function () {
        Route::get('/mentions/stats', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $opts = $r->only(['trend_days']);
            return response()->json($svc->stats($wsId, $opts));
        });
        Route::get('/mentions/scan-runs', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $opts = $r->only(['watchlist_id','scan_type','since','page','per_page']);
            return response()->json($svc->listScanRuns($wsId, $opts));
        });
        Route::get('/mentions/watchlist', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $opts = $r->only(['is_active','scope','q']);
            return response()->json($svc->listWatchlist($wsId, $opts));
        });
        Route::get('/mentions/watchlist/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $res = $svc->singleWatchlist($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
        Route::get('/mentions', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $opts = $r->only(['status','sentiment','priority','source_type','source_domain','watchlist_id','q','since','sort','dir','page','per_page']);
            return response()->json($svc->list($wsId, $opts));
        });
        Route::get('/mentions/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionReadService::class);
            $res = $svc->single($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
    });

    /* B36: mention-phase5-mutations */
    Route::middleware(['auth.jwt'])->group(function () {
        // Mention triage
        Route::patch('/mentions/{id}/status', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $svc = app(\App\Engines\Mention\Services\MentionTriageService::class);
            $status = (string) $r->input('status', '');
            $notes  = $r->input('notes');
            $res = $svc->transition($wsId, (int) $id, $status, (int) $user->id, $notes);
            $code = !empty($res['success']) ? 200 : (str_contains($res['error'] ?? '', 'not found') ? 404 : 422);
            return response()->json($res, $code);
        });
        // Watchlist mutations
        Route::post('/mentions/watchlist', function (\Illuminate\Http\Request $r) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $limits = app(\App\Engines\Mention\Services\MentionPlanLimits::class);
            $wls    = app(\App\Engines\Mention\Services\WatchlistService::class);
            $cap = $limits->watchlistCapFor($wsId);
            $currentActive = $wls->countActive($wsId);
            if ($currentActive >= $cap) {
                return response()->json([
                    'success' => false,
                    'error'   => 'plan_cap_reached',
                    'cap'     => $cap,
                    'current' => $currentActive,
                    'plan'    => $limits->planSlugFor($wsId),
                    'message' => "Your current plan allows up to {$cap} active brand terms. Deactivate one or upgrade to add more.",
                ], 402);
            }
            $data = $r->only(['term','label','scope','priority','variants','negative_keywords','metadata']);
            $res = $wls->create($wsId, $data, (int) $user->id);
            return response()->json($res, !empty($res['success']) ? 201 : 422);
        });
        Route::patch('/mentions/watchlist/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $wls = app(\App\Engines\Mention\Services\WatchlistService::class);
            $data = $r->only(['term','label','scope','priority','variants','negative_keywords','metadata']);
            $res = $wls->update($wsId, (int) $id, $data);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
        Route::post('/mentions/watchlist/{id}/activate', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $limits = app(\App\Engines\Mention\Services\MentionPlanLimits::class);
            $wls    = app(\App\Engines\Mention\Services\WatchlistService::class);
            // Re-activating an existing row must respect the active-term cap
            $row = $wls->get($wsId, (int) $id);
            if (empty($row['success'])) return response()->json($row, 404);
            if (empty($row['data']['is_active'])) {
                $cap = $limits->watchlistCapFor($wsId);
                $cur = $wls->countActive($wsId);
                if ($cur >= $cap) {
                    return response()->json([
                        'success'=>false,'error'=>'plan_cap_reached',
                        'cap'=>$cap,'current'=>$cur,'plan'=>$limits->planSlugFor($wsId),
                    ], 402);
                }
            }
            $res = $wls->activate($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
        Route::post('/mentions/watchlist/{id}/deactivate', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $res = app(\App\Engines\Mention\Services\WatchlistService::class)
                ->deactivate($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 422);
        });
        Route::delete('/mentions/watchlist/{id}', function (\Illuminate\Http\Request $r, $id) {
            $user = $r->user();
            $wsId = (int) ($user->workspace_id ?? 1);
            $res = app(\App\Engines\Mention\Services\WatchlistService::class)
                ->delete($wsId, (int) $id);
            return response()->json($res, !empty($res['success']) ? 200 : 404);
        });
    });

/*
|--------------------------------------------------------------------------
| INFRA888 — Infrastructure engine (Phase 1A)
|--------------------------------------------------------------------------
| READ-ONLY. No write endpoints exist yet: provisioning is destructive and must
| ship together with approval wiring and a proven provider connector.
|
| auth.jwt only — api.key must never reach infrastructure (control C6).
| NOTE: JwtAuthMiddleware also accepts X-API-KEY and escalates an unbound key to
| the first workspace member (Phase 0 audit §7). That is a shared-auth defect
| tracked separately; it is NOT fixed here.
*/
Route::middleware(['auth.jwt', \App\Http\Middleware\DenyApiKeyAuth::class])->prefix('infrastructure')->group(function () {

    // INFRA888 E4 — customer Business Email portal. Extracted for the same
    // reason as the admin routes: routes/api.php is edited by several
    // sessions at once. Every route inside is additionally gated off by
    // BusinessEmailCustomerGate, which is closed on every installation.
    require __DIR__ . '/api/authenticated/business-email.php';

    $infraCtl = \App\Engines\Infrastructure\Http\Controllers\InfrastructureController::class;
    Route::get('/overview',     [$infraCtl, 'overview']);
    Route::get('/activity',     [$infraCtl, 'activity']);
    Route::get('/hosting',      [$infraCtl, 'hostingIndex']);
    Route::get('/hosting/{id}', [$infraCtl, 'hostingShow'])->whereNumber('id');
    Route::get('/domains',      [$infraCtl, 'domainsIndex']);
    Route::get('/email',        [$infraCtl, 'emailIndex']);
    Route::get('/operations',      [$infraCtl, 'operationsIndex']);
    Route::get('/operations/{id}', [$infraCtl, 'operationShow'])->whereNumber('id');
    Route::get('/operations/{id}/timeline', [$infraCtl, 'operationTimeline'])->whereNumber('id');

    // INFRA888 Phase 3B — executive infrastructure intelligence (read-only,
    // tenant-scoped). The canonical asset graph, incidents, blast radius and
    // reliability. Mutates nothing.
    $iiCtl = \App\Engines\Infrastructure\Http\Controllers\InfrastructureIntelligenceController::class;
    Route::get('/intelligence/dashboard',              [$iiCtl, 'dashboard']);
    Route::get('/intelligence/assets',                 [$iiCtl, 'assets']);
    Route::get('/intelligence/assets/{id}',            [$iiCtl, 'asset'])->whereNumber('id');
    Route::get('/intelligence/assets/{id}/blast-radius', [$iiCtl, 'blastRadius'])->whereNumber('id');
    Route::get('/intelligence/incidents',              [$iiCtl, 'incidents']);
    Route::get('/intelligence/reliability',            [$iiCtl, 'reliability']);

    // Phase 1B — the ONLY write endpoint. Protected capability: returns 202
    // AWAITING_APPROVAL and executes on the queue after human approval.
    Route::post('/hosting',        [$infraCtl, 'hostingStore']);
});

/*
|--------------------------------------------------------------------------
| INFRA888 Phase 2A-1 — internal commercial catalog (READ-ONLY)
|--------------------------------------------------------------------------
| Platform-admin only, and DenyApiKeyAuth blocks both API keys and the shared
| admin token. No write endpoints: product/plan authoring is Phase 2A-2 and
| needs its own approval governance. Not a customer surface.
*/
Route::middleware(['auth.jwt', 'admin', \App\Http\Middleware\DenyApiKeyAuth::class])
    ->prefix('admin/infrastructure/catalog')->group(function () {
        $cat = \App\Engines\Infrastructure\Http\Controllers\InfrastructureCatalogController::class;
        Route::get('/products',                 [$cat, 'products']);
        Route::get('/plans',                    [$cat, 'plans']);
        Route::get('/plans/{id}',               [$cat, 'plan'])->whereNumber('id');
        Route::get('/entitlement-definitions',  [$cat, 'entitlementDefinitions']);
        Route::get('/subscriptions/{id}/entitlements', [$cat, 'subscriptionEntitlements'])->whereNumber('id');
    });

/*
|--------------------------------------------------------------------------
| INFRA888 Phase 2B-G — provider control plane (internal admin only)
|--------------------------------------------------------------------------
| Platform administrators only. DenyApiKeyAuth blocks API keys AND the shared
| admin token, so no machine identity can reach any of this. Not a customer
| surface: there is no tenant-facing provider API and there must never be one.
|
| Governance per endpoint:
|   DRAFT     self-service   — cannot become operational (create/declare/testing)
|   PROTECTED separation of duties — request/* then approve/* by a DIFFERENT admin
|   INCIDENT  unilateral     — revoke/disable, immediate, reason required
|
| Secrets are accepted on POST only and NEVER returned by any endpoint.
*/
Route::middleware(['auth.jwt', 'admin', \App\Http\Middleware\DenyApiKeyAuth::class])
    ->prefix('admin/infrastructure/providers')->group(function () {
        $pcp = \App\Engines\Infrastructure\Http\Controllers\ProviderControlPlaneController::class;

        // ---- read ----------------------------------------------------------
        Route::get('/',                 [$pcp, 'index']);
        Route::get('/health',           [$pcp, 'healthIndex']);
        Route::get('/credentials',      [$pcp, 'credentials']);
        Route::get('/events',           [$pcp, 'events']);
        Route::get('/resolution',       [$pcp, 'resolutionDiagnostic']);
        Route::get('/{id}',             [$pcp, 'show'])->whereNumber('id');

        // ---- draft (self-service) -----------------------------------------
        Route::post('/',                          [$pcp, 'store']);
        Route::post('/{id}/capabilities',         [$pcp, 'declareCapability'])->whereNumber('id');
        Route::post('/{id}/testing',              [$pcp, 'moveToTesting'])->whereNumber('id');
        Route::post('/{id}/credentials',          [$pcp, 'storeCredential'])->whereNumber('id');
        Route::post('/credentials/{cid}/verify',  [$pcp, 'verifyCredential'])->whereNumber('cid');

        // ---- protected (separation of duties) ------------------------------
        Route::post('/{id}/request-activation',   [$pcp, 'requestActivateProvider'])->whereNumber('id');
        Route::post('/{id}/request-capability',   [$pcp, 'requestEnableCapability'])->whereNumber('id');
        Route::post('/credentials/{cid}/request-activation', [$pcp, 'requestActivateCredential'])->whereNumber('cid');
        Route::post('/credentials/{cid}/request-rotation',   [$pcp, 'requestRotateCredential'])->whereNumber('cid');
        // mfa.stepup self-disables until two MFA admins exist, so wiring it now
        // is safe and becomes live automatically once the governance bar is met.
        Route::post('/approvals/{aid}/approve',   [$pcp, 'approve'])
            ->middleware('mfa.stepup')->whereNumber('aid');

        // ---- incident (immediate, unilateral) ------------------------------
        Route::post('/credentials/{cid}/revoke',  [$pcp, 'revokeCredential'])->whereNumber('cid');
        Route::post('/{id}/disable',              [$pcp, 'disableProvider'])->whereNumber('id');
        Route::post('/{id}/disable-capability',   [$pcp, 'disableCapability'])->whereNumber('id');

        // ---- read-only probe ------------------------------------------------
        Route::post('/{id}/probe',                [$pcp, 'probe'])->whereNumber('id');
    });

/*
|--------------------------------------------------------------------------
| INFRA888 Phase 2B-R2 — MFA enrolment + step-up (individual humans only)
|--------------------------------------------------------------------------
| auth.jwt + DenyApiKeyAuth: a machine identity has no second factor and can
| never reach these. The TOTP seed leaves the server only in the enrol response.
*/
Route::middleware(['auth.jwt', \App\Http\Middleware\DenyApiKeyAuth::class])
    ->prefix('admin/mfa')->group(function () {
        $mfa = \App\Http\Controllers\Auth\MfaController::class;
        Route::post('/enrol',            [$mfa, 'enrol']);
        Route::post('/confirm',          [$mfa, 'confirm']);
        Route::post('/verify',           [$mfa, 'verify']);
        Route::post('/recovery-codes',   [$mfa, 'regenerateRecoveryCodes']);
    });
// EMAIL888 (2026-08-11) — public Postmark delivery webhook. Top-level and
// unauthenticated by design; the controller verifies a path secret and refuses
// everything while that secret is unset.
require __DIR__ . '/api/webhooks/email888.php';
