<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Credit;
use App\Models\AuditLog;
use App\Models\Agent;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController
{
    // ═══════════════════════════════════════════════════════
    // USER MANAGEMENT
    // ═══════════════════════════════════════════════════════

    public function listUsers(Request $r): JsonResponse
    {
        // SEO-only product mode 2026-05-01: enrich with plan/mode/source +
        // wp_sites_count so admin can classify users without per-row drilldown.
        $q = User::query();
        if ($r->input('search')) {
            $s = $r->input('search');
            $q->where(fn($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        if ($r->input('status')) $q->where('status', $r->input('status'));
        if ($r->input('signup_source')) $q->where('signup_source', $r->input('signup_source'));

        $users = $q->orderByDesc('created_at')->paginate($r->input('per_page', 25));

        // Enrich each user row with plan/mode/wp_sites_count.
        // One user can belong to many workspaces — surface the *primary*
        // workspace's plan/mode (first owned). Cheap N+1 scoped to the
        // 25-row paginated window.
        $items = $users->items();
        foreach ($items as $u) {
            $ws = $u->workspaces()->with('subscription.plan')->first();
            $plan = $ws?->subscription?->plan;
            $features = $plan?->features_json ?? [];
            $mode = is_array($features) && isset($features['mode']) && in_array($features['mode'], ['seo','full'], true)
                ? $features['mode'] : 'full';
            $u->primary_plan       = $plan?->slug;
            $u->primary_mode       = $mode;
            $u->primary_workspace  = $ws ? ['id' => $ws->id, 'name' => $ws->name] : null;
            $u->wp_sites_count     = $ws
                ? DB::table('wp_site_connections')->where('workspace_id', $ws->id)->count()
                : 0;
        }
        return response()->json($users);
    }

    public function getUser(int $id): JsonResponse
    {
        $user = User::with('workspaces')->findOrFail($id);
        $taskCount = Task::whereIn('workspace_id', $user->workspaces->pluck('id'))->count();
        return response()->json(['user' => $user, 'task_count' => $taskCount]);
    }

    public function updateUser(Request $r, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $r->only(['name', 'email', 'status', 'is_admin']);
        if ($r->filled('password')) $data['password'] = Hash::make($r->input('password'));
        $user->update($data);
        return response()->json(['user' => $user->fresh()]);
    }

    public function suspendUser(int $id): JsonResponse
    {
        User::findOrFail($id)->update(['status' => 'suspended']);
        return response()->json(['success' => true]);
    }

    public function deleteUser(int $id): JsonResponse
    {
        User::findOrFail($id)->update(['status' => 'deleted']);
        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════
    // WORKSPACE MANAGEMENT
    // ═══════════════════════════════════════════════════════

    public function listWorkspaces(Request $r): JsonResponse
    {
        // SEO-only product mode 2026-05-01: surface plan/mode + WP-connection
        // status on each row + filter dimensions: mode, source, plan, wp_connected.
        $q = Workspace::withCount('users');
        if ($r->input('search')) $q->where('name', 'like', '%' . $r->input('search') . '%');

        $workspaces = $q->orderByDesc('created_at')->paginate($r->input('per_page', 25));

        $items = $workspaces->items();
        foreach ($items as $ws) {
            $sub = Subscription::where('workspace_id', $ws->id)
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan')->latest()->first();
            $plan = $sub?->plan;
            $features = $plan?->features_json ?? [];
            $mode = is_array($features) && isset($features['mode']) && in_array($features['mode'], ['seo','full'], true)
                ? $features['mode'] : 'full';
            $ws->plan_slug         = $plan?->slug;
            $ws->plan_name         = $plan?->name;
            $ws->mode              = $mode;
            $ws->subscription_status= $sub?->status;
            $ws->wp_sites_count    = DB::table('wp_site_connections')->where('workspace_id', $ws->id)->count();
            $ws->wp_active_count   = DB::table('wp_site_connections')
                ->where('workspace_id', $ws->id)->where('status', 'active')->count();
            $ws->wp_suspended_count= DB::table('wp_site_connections')
                ->where('workspace_id', $ws->id)->where('status', 'billing_suspended')->count();
            // Owner signup_source — the user with role='owner' on workspace_users.
            $ownerId = DB::table('workspace_users')
                ->where('workspace_id', $ws->id)->where('role', 'owner')
                ->value('user_id');
            $ws->owner_source = $ownerId
                ? DB::table('users')->where('id', $ownerId)->value('signup_source')
                : null;
        }

        // Optional post-filters (applied after enrichment because mode/source are derived).
        if ($r->filled('mode')) {
            $items = array_values(array_filter($items, fn($w) => ($w->mode ?? 'full') === $r->input('mode')));
            $workspaces->setCollection(collect($items));
        }
        if ($r->filled('source')) {
            $items = array_values(array_filter($items, fn($w) => ($w->owner_source ?? null) === $r->input('source')));
            $workspaces->setCollection(collect($items));
        }
        if ($r->boolean('wp_connected')) {
            $items = array_values(array_filter($items, fn($w) => ($w->wp_sites_count ?? 0) > 0));
            $workspaces->setCollection(collect($items));
        }
        return response()->json($workspaces);
    }

    /**
     * SEO-only product mode 2026-05-01.
     *
     * Returns paginated wp_site_connections with workspace + owner + plan +
     * api_keys count. NEVER exposes raw or hashed key material — only counts.
     * Filters: status (active|disconnected|failed|billing_suspended), search
     * (site_url substring), workspace_id.
     */
    public function listConnectorSites(Request $r): JsonResponse
    {
        $q = DB::table('wp_site_connections as c')
            ->leftJoin('workspaces as w', 'c.workspace_id', '=', 'w.id')
            ->leftJoin('subscriptions as s', function ($j) {
                $j->on('s.workspace_id', '=', 'c.workspace_id')
                  ->whereIn('s.status', ['active', 'trialing']);
            })
            ->leftJoin('plans as p', 's.plan_id', '=', 'p.id')
            ->select(
                'c.id', 'c.workspace_id', 'c.site_url', 'c.status',
                'c.last_push_at', 'c.last_push_status', 'c.created_at', 'c.updated_at',
                'w.name as workspace_name', 'w.slug as workspace_slug',
                'p.slug as plan_slug', 'p.name as plan_name',
                's.status as subscription_status'
            );
        if ($r->filled('status'))     $q->where('c.status', $r->input('status'));
        if ($r->filled('workspace_id')) $q->where('c.workspace_id', (int) $r->input('workspace_id'));
        if ($r->filled('search')) {
            $s = $r->input('search');
            $q->where('c.site_url', 'like', "%{$s}%");
        }

        $rows = $q->orderByDesc('c.created_at')->paginate($r->input('per_page', 25));

        // Enrich with api_keys count + features_json mode (no key material).
        foreach ($rows->items() as $row) {
            $row->api_keys_active = DB::table('api_keys')
                ->where('site_connection_id', $row->id)
                ->whereNull('revoked_at')
                ->count();
            $row->api_keys_total = DB::table('api_keys')
                ->where('site_connection_id', $row->id)
                ->count();
            // Look up plan->features_json.mode without re-joining
            if ($row->plan_slug) {
                $features = DB::table('plans')->where('slug', $row->plan_slug)->value('features_json');
                $arr = $features ? (json_decode($features, true) ?: []) : [];
                $row->mode = (isset($arr['mode']) && in_array($arr['mode'], ['seo','full'], true))
                    ? $arr['mode'] : 'full';
            } else {
                $row->mode = 'full';
            }
        }
        return response()->json($rows);
    }

    /**
     * SEO-only product mode 2026-05-01.
     *
     * Revoke a single api_key by id. Soft-revoke (sets revoked_at). The key
     * row stays so audit trails remain intact. Use this to terminate a leaked
     * or compromised connector key without wiping the whole site_connection.
     */
    public function revokeApiKey(Request $r, int $id): JsonResponse
    {
        $key = DB::table('api_keys')->where('id', $id)->first();
        if (! $key) {
            return response()->json(['error' => 'not found'], 404);
        }
        if ($key->revoked_at) {
            return response()->json(['ok' => true, 'already_revoked' => true]);
        }
        DB::table('api_keys')->where('id', $id)->update(['revoked_at' => now()]);
        return response()->json([
            'ok' => true,
            'revoked_at' => now()->toIso8601String(),
            'key_id' => $id,
        ]);
    }

    public function getWorkspace(int $id): JsonResponse
    {
        $ws = Workspace::with(['users', 'agents'])->findOrFail($id);
        $credit = Credit::where('workspace_id', $id)->first();
        // SEO-only product mode 2026-05-01: include 'trialing' so the detail
        // panel doesn't show "no subscription" during the 3-day Stripe trial.
        $sub = Subscription::where('workspace_id', $id)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')->latest()->first();
        $taskCount = Task::where('workspace_id', $id)->count();
        $taskCompleted = Task::where('workspace_id', $id)->where('status', 'completed')->count();

        // SEO-only product mode 2026-05-01: surface mode + connector visibility.
        $features = $sub?->plan?->features_json ?? [];
        $mode = is_array($features) && isset($features['mode']) && in_array($features['mode'], ['seo','full'], true)
            ? $features['mode'] : 'full';
        $wpSites = DB::table('wp_site_connections')->where('workspace_id', $id)
            ->select('id', 'site_url', 'status', 'last_push_at', 'last_push_status', 'created_at')
            ->get();
        // api_keys per connection — only counts and metadata, never the hash/raw.
        $apiKeyCounts = DB::table('api_keys')
            ->where('workspace_id', $id)
            ->selectRaw('site_connection_id, COUNT(*) as total, SUM(revoked_at IS NULL) as active')
            ->groupBy('site_connection_id')->get()->keyBy('site_connection_id');
        foreach ($wpSites as $s) {
            $s->api_keys_total  = (int) ($apiKeyCounts[$s->id]->total ?? 0);
            $s->api_keys_active = (int) ($apiKeyCounts[$s->id]->active ?? 0);
        }

        return response()->json([
            'workspace' => $ws,
            'credit' => $credit,
            'subscription' => $sub,
            'mode' => $mode,
            'wp_sites' => $wpSites,
            'task_count' => $taskCount, 'task_completed' => $taskCompleted,
        ]);
    }

    public function updateWorkspace(Request $r, int $id): JsonResponse
    {
        $ws = Workspace::findOrFail($id);
        $ws->update($r->only(['name', 'business_name', 'industry', 'settings_json']));
        return response()->json(['workspace' => $ws->fresh()]);
    }

    public function adjustCredits(Request $r, int $id): JsonResponse
    {
        $r->validate(['amount' => 'required|integer', 'reason' => 'required|string']);
        $credit = Credit::firstOrCreate(['workspace_id' => $id], ['balance' => 0, 'reserved_balance' => 0]);
        $credit->increment('balance', $r->input('amount'));

        DB::table('credit_transactions')->insert([
            'workspace_id' => $id, 'type' => 'admin_adjustment', 'amount' => $r->input('amount'),
            'reference_type' => 'admin', 'reference_id' => $r->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['balance' => $credit->fresh()->balance]);
    }

    public function assignPlan(Request $r, int $id): JsonResponse
    {
        $r->validate(['plan_id' => 'required|exists:plans,id']);
        Subscription::updateOrCreate(
            ['workspace_id' => $id, 'status' => 'active'],
            ['plan_id' => $r->input('plan_id'), 'started_at' => now()]
        );
        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════
    // PLAN MANAGEMENT
    // ═══════════════════════════════════════════════════════

    public function listPlans(): JsonResponse
    {
        return response()->json(['plans' => Plan::orderBy('price')->get()]);
    }

    public function updatePlan(Request $r, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update($r->only([
            'name', 'price', 'credit_limit', 'ai_access', 'includes_dmm',
            'agent_count', 'agent_level', 'agent_addon_price',
            'max_websites', 'max_team_members', 'companion_app', 'white_label',
            'priority_processing', 'features_json',
        ]));
        return response()->json(['plan' => $plan->fresh()]);
    }

    // ═══════════════════════════════════════════════════════
    // API KEY / SYSTEM CONFIG
    // ═══════════════════════════════════════════════════════

    public function getConfig(): JsonResponse
    {
        $settings = app(\App\Core\Admin\SettingsService::class)->all();
        return response()->json([
            'settings' => $settings,
            'env' => [
                'queue_driver' => config('queue.default'),
                'cache_driver' => config('cache.default'),
                'db_driver'    => config('database.default'),
                'app_env'      => config('app.env'),
            ],
        ]);
    }

    public function updateConfig(Request $r): JsonResponse
    {
        $settings = app(\App\Core\Admin\SettingsService::class);
        $updated  = 0;

        $sensitiveKeys = [
            // Short names (from SaaS settings)
            'openai_key'   => ['provider' => 'openai',   'group' => 'llm'],
            'deepseek_key' => ['provider' => 'deepseek', 'group' => 'llm'],
            'minimax_key'  => ['provider' => 'minimax',  'group' => 'creative'],
            'runway_key'   => ['provider' => 'runway',   'group' => 'creative'],
            'postmark_key' => ['provider' => 'postmark', 'group' => 'email'],
            'stripe_key'   => ['provider' => 'stripe',   'group' => 'payment'],
            'stripe_secret'=> ['provider' => 'stripe_secret', 'group' => 'payment'],
            'stability_key'=> ['provider' => 'stability', 'group' => 'creative'],
            // DB key names (from admin settings form — input IDs match DB keys)
            'api_key_deepseek'  => ['provider' => 'deepseek',  'group' => 'llm'],
            'api_key_openai'    => ['provider' => 'openai',    'group' => 'llm'],
            'api_key_minimax'   => ['provider' => 'minimax',   'group' => 'creative'],
            'api_key_runway'    => ['provider' => 'runway',    'group' => 'creative'],
            'api_key_stability' => ['provider' => 'stability', 'group' => 'creative'],
            'api_key_postmark'  => ['provider' => 'postmark',  'group' => 'email'],
            'api_key_sendgrid'  => ['provider' => 'sendgrid',  'group' => 'email'],
            'api_key_dataforseo'=> ['provider' => 'dataforseo','group' => 'seo'],
            'stripe_publishable_key' => ['provider' => 'stripe_publishable', 'group' => 'payment'],
            'stripe_secret_key'      => ['provider' => 'stripe_secret',      'group' => 'payment'],
            'stripe_webhook_secret'  => ['provider' => 'stripe_webhook',     'group' => 'payment'],
            'facebook_app_secret'    => ['provider' => 'facebook_secret',    'group' => 'social'],
            'linkedin_client_secret' => ['provider' => 'linkedin_secret',    'group' => 'social'],
            'gsc_client_secret'      => ['provider' => 'gsc_secret',         'group' => 'seo'],
            'runtime_secret'         => ['provider' => 'runtime_secret',     'group' => 'runtime'],
        ];

        // Also handle non-sensitive keys that come from the form
        $nonSensitiveKeys = [
            'deepseek_model', 'dataforseo_login', 'gsc_client_id',
            'facebook_app_id', 'linkedin_client_id', 'runtime_url',
            'stripe_publishable_key',
        ];
        foreach ($nonSensitiveKeys as $nsKey) {
            if ($r->has($nsKey) && $r->input($nsKey) !== '') {
                $settings->set($nsKey, $r->input($nsKey), false, 'general', $nsKey);
                $updated++;
            }
        }

        foreach ($sensitiveKeys as $inputKey => $meta) {
            if ($r->filled($inputKey)) {
                $settings->setApiKey($meta['provider'], $r->input($inputKey), $meta['group']);
                $updated++;
            }
        }

        // Non-sensitive settings
        foreach (['app_name', 'support_email', 'maintenance_mode'] as $key) {
            if ($r->has($key)) {
                $settings->set($key, $r->input($key), false, 'general');
                $updated++;
            }
        }

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    // ═══════════════════════════════════════════════════════
    // AGENT MANAGEMENT
    // ═══════════════════════════════════════════════════════

    public function listAgents(): JsonResponse
    {
        return response()->json(['agents' => Agent::orderBy('category')->orderBy('name')->get()]);
    }

    public function updateAgent(Request $r, int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        $agent->update($r->only(['name', 'title', 'description', 'status', 'capabilities_json', 'skills_json']));
        return response()->json(['agent' => $agent->fresh()]);
    }

    // ═══════════════════════════════════════════════════════
    // AUDIT LOGS
    // ═══════════════════════════════════════════════════════

    public function auditLogs(Request $r): JsonResponse
    {
        $q = AuditLog::query();
        if ($r->input('workspace_id')) $q->where('workspace_id', $r->input('workspace_id'));
        if ($r->input('action')) $q->where('action', 'like', '%' . $r->input('action') . '%');
        if ($r->input('user_id')) $q->where('user_id', $r->input('user_id'));

        return response()->json($q->orderByDesc('created_at')->paginate($r->input('per_page', 50)));
    }

    // ═══════════════════════════════════════════════════════
    // TASK MONITOR
    // ═══════════════════════════════════════════════════════

    public function taskMonitor(Request $r): JsonResponse
    {
        $q = Task::query();
        if ($r->input('status')) $q->where('status', $r->input('status'));
        if ($r->input('workspace_id')) $q->where('workspace_id', $r->input('workspace_id'));
        if ($r->input('engine')) $q->where('engine', $r->input('engine'));

        return response()->json($q->orderByDesc('created_at')->paginate($r->input('per_page', 50)));
    }

    public function taskDetail(int $id): JsonResponse
    {
        $task = Task::findOrFail($id);
        $events = DB::table('task_events')->where('task_id', $id)->orderBy('created_at')->get();
        return response()->json(['task' => $task, 'events' => $events]);
    }

    public function retryTask(int $id): JsonResponse
    {
        $task = Task::findOrFail($id);
        $task->update(['status' => 'queued', 'retry_count' => 0]);
        return response()->json(['success' => true]);
    }

    public function cancelTask(int $id): JsonResponse
    {
        Task::findOrFail($id)->update(['status' => 'cancelled']);
        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════
    // SYSTEM HEALTH + QUEUE
    // ═══════════════════════════════════════════════════════

    public function systemHealth(): JsonResponse
    {
        $health = app(\App\Core\SystemHealth\SystemHealthService::class)->health();
        return response()->json($health);
    }

    public function queueHealth(): JsonResponse
    {
        $stale = Task::where('status', 'running')->where('started_at', '<', now()->subMinutes(10))->count();
        $pending = Task::where('status', 'queued')->count();
        $running = Task::where('status', 'running')->count();
        $failedToday = Task::where('status', 'failed')->where('updated_at', '>=', now()->startOfDay())->count();

        return response()->json([
            'pending' => $pending, 'running' => $running,
            'stale' => $stale, 'failed_today' => $failedToday,
            'queue_driver' => config('queue.default'),
        ]);
    }

    public function recoverStale(): JsonResponse
    {
        \Artisan::call('boss888:recover-stale', ['--timeout' => 600]);
        return response()->json(['success' => true, 'output' => \Artisan::output()]);
    }

    public function validationReport(): JsonResponse
    {
        $report = app(\App\Services\ValidationReportService::class)->generate();
        return response()->json($report);
    }

    // ═══════════════════════════════════════════════════════
    // DASHBOARD STATS
    // ═══════════════════════════════════════════════════════

    public function dashboardStats(): JsonResponse
    {
        return response()->json([
            'total_users' => User::where('status', 'active')->count(),
            'total_workspaces' => Workspace::count(),
            'total_tasks' => Task::count(),
            'tasks_today' => Task::where('created_at', '>=', now()->startOfDay())->count(),
            'tasks_completed_today' => Task::where('status', 'completed')->where('completed_at', '>=', now()->startOfDay())->count(),
            'tasks_failed_today' => Task::where('status', 'failed')->where('updated_at', '>=', now()->startOfDay())->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
            'total_revenue' => Subscription::where('status', 'active')->join('plans', 'subscriptions.plan_id', '=', 'plans.id')->sum('plans.price'),
        ]);
    }

    // ── Private ──────────────────────────────────────────


    // ═══════════════════════════════════════════════════════
    // MEMBERSHIP MANAGEMENT
    // ═══════════════════════════════════════════════════════

    public function listMemberships(Request $r): JsonResponse
    {
        $memberships = DB::table('workspace_users as wu')
            ->join('users as u', 'u.id', '=', 'wu.user_id')
            ->join('workspaces as w', 'w.id', '=', 'wu.workspace_id')
            ->select('wu.*', 'u.name as user_name', 'u.email as user_email', 'w.name as workspace_name')
            ->orderByDesc('wu.created_at')
            ->paginate($r->input('per_page', 50));

        return response()->json($memberships);
    }

    public function updateMembership(Request $r, int $id): JsonResponse
    {
        $r->validate(['role' => 'required|in:owner,admin,member,viewer']);

        DB::table('workspace_users')->where('id', $id)->update([
            'role' => $r->input('role'),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════
    // SUBSCRIPTION MANAGEMENT (read-only list)
    // ═══════════════════════════════════════════════════════

    public function listSubscriptions(Request $r): JsonResponse
    {
        $subscriptions = DB::table('subscriptions as s')
            ->join('workspaces as w', 'w.id', '=', 's.workspace_id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->select(
                's.*',
                'w.name as workspace_name',
                'p.name as plan_name'
            )
            ->orderByDesc('s.created_at')
            ->paginate($r->input('per_page', 50));

        return response()->json($subscriptions);
    }

    // ═══════════════════════════════════════════════════════
    // SESSION MANAGEMENT (Phase 1 — Operational Visibility)
    // ═══════════════════════════════════════════════════════

    public function listSessions(Request $r): JsonResponse
    {
        $sessions = DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select(
                's.id as session_id',
                'u.name as user_name',
                'u.email as user_email',
                's.ip_address',
                's.user_agent',
                's.created_at',
                's.expires_at',
                's.revoked_at',
                's.updated_at as last_used_at'
            )
            ->orderByDesc('s.updated_at')
            ->paginate($r->input('per_page', 50));

        $totalActive = DB::table('sessions')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();
        $totalRevoked = DB::table('sessions')
            ->whereNotNull('revoked_at')
            ->count();

        return response()->json([
            'sessions' => $sessions,
            'summary' => [
                'total_active' => $totalActive,
                'total_revoked' => $totalRevoked,
            ],
        ]);
    }

    public function revokeSession(int $id): JsonResponse
    {
        $affected = DB::table('sessions')->where('id', $id)->update(['revoked_at' => now()]);
        if (!$affected) {
            return response()->json(['error' => 'Session not found'], 404);
        }
        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════
    // CREDITS & TRANSACTIONS (Phase 1 — Operational Visibility)
    // ═══════════════════════════════════════════════════════

    public function listCredits(Request $r): JsonResponse
    {
        $credits = DB::table('credits as c')
            ->join('workspaces as w', 'w.id', '=', 'c.workspace_id')
            ->select('c.id', 'c.workspace_id', 'w.name as workspace_name', 'c.balance', 'c.reserved_balance')
            ->orderByDesc('c.balance')
            ->get();

        $transactions = DB::table('credit_transactions as ct')
            ->join('workspaces as w', 'w.id', '=', 'ct.workspace_id')
            ->select('ct.id', 'ct.workspace_id', 'w.name as workspace_name', 'ct.type', 'ct.amount', 'ct.reference_type', 'ct.created_at')
            ->orderByDesc('ct.created_at')
            ->limit(100)
            ->get();

        $totalBalance = DB::table('credits')->sum('balance');
        $totalReserved = DB::table('credits')->sum('reserved_balance');
        $transactionsToday = DB::table('credit_transactions')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return response()->json([
            'credits' => $credits,
            'transactions' => $transactions,
            'summary' => [
                'total_balance' => (int) $totalBalance,
                'total_reserved' => (int) $totalReserved,
                'transactions_today' => $transactionsToday,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // FAILED JOBS (Phase 1 — Operational Visibility)
    // ═══════════════════════════════════════════════════════

    public function listFailedJobs(Request $r): JsonResponse
    {
        $jobs = DB::table('failed_jobs')
            ->select('id', 'uuid', 'connection', 'queue',
                DB::raw('LEFT(payload, 200) as payload_preview'),
                DB::raw('LEFT(exception, 300) as exception_preview'),
                'failed_at')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get();

        return response()->json(['jobs' => $jobs]);
    }

    public function retryFailedJob(int $id): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('id', $id)->first();
        if (!$job) {
            return response()->json(['error' => 'Failed job not found'], 404);
        }
        DB::table('failed_jobs')->where('id', $id)->delete();
        return response()->json(['success' => true, 'message' => 'Failed job removed — re-dispatch manually if needed']);
    }

    public function deleteFailedJob(int $id): JsonResponse
    {
        $affected = DB::table('failed_jobs')->where('id', $id)->delete();
        if (!$affected) {
            return response()->json(['error' => 'Failed job not found'], 404);
        }
        return response()->json(['success' => true]);
    }

    public function purgeFailedJobs(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();
        return response()->json(['success' => true, 'purged' => $count]);
    }
    private function maskKey(?string $key): string
    {
        if (! $key || strlen($key) < 8) return '(not set)';
        return substr($key, 0, 4) . '****' . substr($key, -4);
    }

    // ═══════════════════════════════════════════════════════
    // ADMIN USER CREATION
    // ═══════════════════════════════════════════════════════

    public function createUser(Request $r): JsonResponse
    {
        $r->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'is_platform_admin' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name' => $r->input('name'),
            'email' => $r->input('email'),
            'password' => Hash::make($r->input('password')),
            'is_platform_admin' => $r->boolean('is_platform_admin', false),
        ]);

        // Create a default workspace for the new user
        $workspace = Workspace::create([
            'name' => $r->input('name') . "'s Workspace",
            'slug' => \Illuminate\Support\Str::slug($r->input('name') . '-' . \Illuminate\Support\Str::random(4)),
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner']);

        Credit::firstOrCreate(
            ['workspace_id' => $workspace->id],
            ['balance' => 0, 'reserved_balance' => 0]
        );

        $freePlan = Plan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
        }

        return response()->json([
            'user' => $user->fresh(),
            'workspace' => $workspace,
        ], 201);
    }
}
