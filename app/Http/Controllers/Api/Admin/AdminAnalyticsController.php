<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController
{
    /**
     * GET /api/admin/analytics
     * Returns platform-wide analytics overview.
     */
    public function overview(Request $r): JsonResponse
    {
        $days = (int) $r->input('days', 30);
        $since = now()->subDays($days)->startOfDay();

        // 1. User growth — count by created_at grouped by day (last N days)
        $userGrowth = DB::table('users')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // 2. Active users — users with sessions updated in last 7 days
        //    sessions table uses updated_at (timestamp), not last_activity
        $activeUsers = DB::table('sessions')
            ->where('updated_at', '>=', now()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');

        // Total users for context
        $totalUsers = DB::table('users')->count();

        // 3. Task volume — tasks grouped by day (last N days)
        $tasksByDay = DB::table('tasks')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Tasks by engine
        $tasksByEngine = DB::table('tasks')
            ->select('engine', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->groupBy('engine')
            ->orderByDesc('count')
            ->get();

        // 4. Credit consumption — grouped by day (last N days)
        //    credit_transactions uses type enum (debit/commit), not negative amounts
        //    and has no engine column — we join to tasks via reference_type/reference_id
        $creditsByDay = DB::table('credit_transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(ABS(amount)) as total'))
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['debit', 'commit'])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Credits by engine — join to tasks when reference_type is task
        $creditsByEngine = DB::table('credit_transactions')
            ->join('tasks', function ($join) {
                $join->on('credit_transactions.reference_id', '=', 'tasks.id')
                     ->where('credit_transactions.reference_type', '=', 'task');
            })
            ->select('tasks.engine', DB::raw('SUM(ABS(credit_transactions.amount)) as total'))
            ->where('credit_transactions.created_at', '>=', $since)
            ->whereIn('credit_transactions.type', ['debit', 'commit'])
            ->groupBy('tasks.engine')
            ->orderByDesc('total')
            ->get();

        // 5. Engine usage — tasks grouped by engine with counts (all time)
        $engineUsage = DB::table('tasks')
            ->select(
                'engine',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw("SUM(CASE WHEN status IN ('queued','pending','running') THEN 1 ELSE 0 END) as active")
            )
            ->groupBy('engine')
            ->orderByDesc('total')
            ->get();

        // 6. Top workspaces by task count
        $topWorkspaces = DB::table('tasks')
            ->join('workspaces', 'tasks.workspace_id', '=', 'workspaces.id')
            ->select(
                'workspaces.id',
                'workspaces.name',
                DB::raw('COUNT(tasks.id) as task_count')
            )
            ->where('tasks.created_at', '>=', $since)
            ->groupBy('workspaces.id', 'workspaces.name')
            ->orderByDesc('task_count')
            ->limit(10)
            ->get();

        // 7. Revenue — subscriptions by plan (excluding free)
        $revenue = DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->select(
                'plans.name as plan_name',
                'plans.slug as plan_slug',
                'plans.price',
                DB::raw('COUNT(subscriptions.id) as subscriber_count'),
                DB::raw('SUM(plans.price) as mrr')
            )
            ->where('plans.slug', '!=', 'free')
            ->where('subscriptions.status', 'active')
            ->groupBy('plans.id', 'plans.name', 'plans.slug', 'plans.price')
            ->orderByDesc('mrr')
            ->get();

        $totalMrr = $revenue->sum('mrr');

        return response()->json([
            'period_days' => $days,
            'generated_at' => now()->toISOString(),
            'users' => [
                'total' => $totalUsers,
                'active_7d' => $activeUsers,
                'growth' => $userGrowth,
            ],
            'tasks' => [
                'by_day' => $tasksByDay,
                'by_engine' => $tasksByEngine,
            ],
            'credits' => [
                'consumption_by_day' => $creditsByDay,
                'consumption_by_engine' => $creditsByEngine,
            ],
            'engine_usage' => $engineUsage,
            'top_workspaces' => $topWorkspaces,
            'revenue' => [
                'total_mrr' => $totalMrr,
                'by_plan' => $revenue,
            ],
        ]);
    }

    /**
     * GET /api/admin/analytics/workspace/{id}
     * Deep dive into one workspace.
     */
    public function workspace(Request $r, int $id): JsonResponse
    {
        $workspace = DB::table('workspaces')->where('id', $id)->first();

        if (!$workspace) {
            return response()->json(['error' => 'Workspace not found'], 404);
        }

        // Owner (created_by references users.id)
        $owner = DB::table('users')->where('id', $workspace->created_by)->first(['id', 'name', 'email', 'created_at']);

        // Plan
        $subscription = DB::table('subscriptions')
            ->where('workspace_id', $id)
            ->where('status', 'active')
            ->first();
        $plan = $subscription ? DB::table('plans')->where('id', $subscription->plan_id)->first(['name', 'slug', 'price']) : null;

        // Credits
        $credits = DB::table('credits')->where('workspace_id', $id)->first();

        // Agents
        $agents = DB::table('workspace_agents')
            ->join('agents', 'workspace_agents.agent_id', '=', 'agents.id')
            ->where('workspace_agents.workspace_id', $id)
            ->select('agents.id', 'agents.name', 'agents.slug', 'agents.role')
            ->get();

        // Websites
        $websites = DB::table('websites')
            ->where('workspace_id', $id)
            ->select('id', 'domain', 'name', 'status', 'created_at')
            ->get();

        // Tasks summary
        $taskSummary = DB::table('tasks')
            ->where('workspace_id', $id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled"),
                DB::raw("SUM(CASE WHEN status IN ('queued','pending','running') THEN 1 ELSE 0 END) as active")
            )
            ->first();

        // Tasks by engine
        $tasksByEngine = DB::table('tasks')
            ->where('workspace_id', $id)
            ->select('engine', DB::raw('COUNT(*) as count'))
            ->groupBy('engine')
            ->orderByDesc('count')
            ->get();

        // Meetings
        $meetings = DB::table('meetings')
            ->where('workspace_id', $id)
            ->select('id', 'title', 'type', 'status', 'total_credits_used', 'created_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Recent audit logs
        $auditLogs = DB::table('audit_logs')
            ->where('workspace_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Credit transaction history
        $creditHistory = DB::table('credit_transactions')
            ->where('workspace_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'workspace' => $workspace,
            'owner' => $owner,
            'plan' => $plan,
            'subscription' => $subscription,
            'credits' => $credits,
            'agents' => $agents,
            'websites' => $websites,
            'tasks' => [
                'summary' => $taskSummary,
                'by_engine' => $tasksByEngine,
            ],
            'meetings' => $meetings,
            'recent_audit_logs' => $auditLogs,
            'credit_history' => $creditHistory,
        ]);
    }
}
