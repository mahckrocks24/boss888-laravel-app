<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DashboardController — Command Center data, real numbers only.
 * Every query scoped to the caller's workspace_id.
 */
class DashboardController
{
    public function overview(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if (!$wsId) {
            return response()->json(['error' => 'workspace_required'], 400);
        }

        $weekAgo = now()->subDays(7);

        // ── AGENT TEAM (workspace-enabled only) ────────────────────────────
        // Wave 40 — only show agents enabled for this workspace. Plan-tier
        // gate (Growth = Sarah + 2, Pro = Sarah + 5, Agency = Sarah + 10).
        $agents = DB::table('workspace_agents as wa')
            ->join('agents as a', 'a.id', '=', 'wa.agent_id')
            ->where('wa.workspace_id', $wsId)
            ->where('wa.enabled', true)
            ->orderByDesc('a.is_dmm')
            ->orderBy('a.name')
            ->get([
                'a.slug', 'a.name', 'a.title', 'a.category',
                'a.color', 'a.avatar_url', 'a.status', 'a.is_dmm',
                'wa.created_at as assigned_at',
            ])
            ->map(function ($a) use ($wsId, $weekAgo) {
                // Wave 40b — count tasks, not audit_logs.
                // audit_logs use task.* prefix so any engine-prefix filter
                // mis-credits Sarah for all task work. Read tasks table
                // directly by who actually owns the work.
                // A2 (2026-09-19): the same crediting rule as WorkspaceMetrics — every assignee, plus everything
                // Sarah originated (created_via sarah*), plus builder work for Arthur.
                $isOrchestrator = ((bool) $a->is_dmm) || $a->slug === 'sarah';
                $tq = DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where(function ($q) use ($a, $isOrchestrator) {
                        $q->whereRaw('JSON_CONTAINS(assigned_agents_json, ?)', ['"' . $a->slug . '"']);
                        if ($isOrchestrator) $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) LIKE 'sarah%'");
                        if ($a->slug === 'arthur') $q->orWhere('engine', 'builder');
                    });
                $weeklyCount = (clone $tq)->where('created_at', '>=', $weekAgo)->count();
                $last = (clone $tq)
                    ->orderByDesc(DB::raw('COALESCE(completed_at, started_at, created_at)'))
                    ->value(DB::raw('COALESCE(completed_at, started_at, created_at)'));
                return [
                    'slug'            => $a->slug,
                    'name'            => $a->name,
                    'title'           => $a->title,
                    'category'        => $a->category,
                    'color'           => $a->color ?: '#6C5CE7',
                    'avatar_url'      => $a->avatar_url,
                    'status'          => $a->status,
                    'is_dmm'          => (bool) $a->is_dmm,
                    'assigned_at'     => $a->assigned_at,
                    'last_action_at'  => $last,
                    'last_action_ago' => $last ? Carbon::parse($last)->diffForHumans() : null,
                    'tasks_this_week' => $weeklyCount,
                ];
            })
            ->values();

        // ── ACTIVITY FEED ─────────────────────────────────────────────────
        $feedRows = DB::table('audit_logs')
            ->where('workspace_id', $wsId)
            ->where('action', 'NOT LIKE', 'user.%')
            ->where('action', 'NOT LIKE', 'agent.direct_message')
            ->where('action', 'NOT LIKE', 'approval.%')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['action', 'entity_type', 'entity_id', 'metadata_json', 'created_at']);

        $feed = $feedRows->map(function ($log) {
            [$engine, $action] = $this->splitAction($log->action);
            $meta = json_decode($log->metadata_json ?? 'null', true);
            // Wave 40 — derive the actual agent from metadata where possible,
            // not just the engine prefix. Most audit_logs start with task. /
            // agent. / approval. and all defaulted to Sarah before.
            $agentSlug = null;
            if (is_array($meta)) {
                $agentSlug = $meta['agent_slug'] ?? $meta['agent'] ?? $meta['assigned_to'] ?? null;
                // task.* entries don't carry agent_slug in metadata. Look up
                // the task's assigned_agents_json[0] when entity_id is set.
                if (!$agentSlug && $log->entity_type === 'Task' && $log->entity_id) {
                    $assigned = \Illuminate\Support\Facades\DB::table('tasks')
                        ->where('id', $log->entity_id)
                        ->value('assigned_agents_json');
                    if ($assigned) {
                        $arr = is_string($assigned) ? (json_decode($assigned, true) ?: []) : ($assigned ?: []);
                        $agentSlug = is_array($arr) && !empty($arr) ? $arr[0] : null;
                    }
                }
            }
            $agent = $agentSlug
                ? $this->agentForSlug($agentSlug)
                : $this->agentForEngine($engine);
            return [
                'engine'    => $engine,
                'action'    => $action,
                'label'     => $this->labelFor($engine, $action, $meta, $agent['name'] ?? null),
                'agent'     => $agent,
                'timestamp' => $log->created_at,
                'time_ago'  => Carbon::parse($log->created_at)->diffForHumans(),
            ];
        })->values();

        // ── STRATEGY ──────────────────────────────────────────────────────
        $latestProposal = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->first(['id', 'title', 'description', 'status', 'total_credits', 'meeting_id', 'created_at']);

        if ($latestProposal) {
            $latestProposal->time_ago = Carbon::parse($latestProposal->created_at)->diffForHumans();
        }

        $proposalsTotal  = DB::table('strategy_proposals')->where('workspace_id', $wsId)->count();
        $proposalsGlobal = DB::table('strategy_proposals')->count();

        // ── STATS (all scoped to workspace, all from real DB) ──────────────
        // RES-1 (2026-08-30): "Tasks done" must count COMPLETED TASKS, not audit-log rows. audit_logs holds
        // every execution/audit event (11,347 rows vs 1,968 completed tasks on ws 2) — presenting it as
        // "tasks done" turned execution records into a performance figure.
        // A2 (2026-09-19): every task/agent count comes from App\Core\Metrics\WorkspaceMetrics — the one place the
        // definitions live (tasks_done excludes QA-rejected work; declined work is its own bucket). The Workspace
        // canvas, the Agents page and this dashboard read the same numbers.
        $metrics = app(\App\Core\Metrics\WorkspaceMetrics::class);
        $taskCounts  = $metrics->taskCounts($wsId);
        $agentCounts = $metrics->agentCounts($wsId);

        $stats = [
            'tasks_completed'        => $taskCounts['tasks_done'],
            'tasks_this_week'        => $taskCounts['tasks_done_week'],
            'tasks_today'            => $taskCounts['tasks_done_today'],
            'tasks_running'          => $taskCounts['tasks_running'],
            'tasks_pending'          => $taskCounts['tasks_pending'],
            'tasks_blocked'          => $taskCounts['tasks_blocked'],
            'tasks_declined'         => $taskCounts['tasks_declined'],
            'tasks_failed'           => $taskCounts['tasks_failed'],
            'tasks_qa_rejected'      => $taskCounts['tasks_qa_rejected'],
            'agents_enabled'         => $agentCounts['agents_enabled'],
            'agents_roster'          => $agentCounts['agents_roster'],
            'agents_active_30d'      => $agentCounts['agents_active_30d'],
            'activity_events'        => DB::table('audit_logs')->where('workspace_id', $wsId)->count(),
            'articles_published'     => DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->count(),
            'articles_total'         => DB::table('articles')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'leads_captured'         => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'leads_this_week'        => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('created_at', '>=', $weekAgo)->count(),
            // W6 launch scope: social_posts_scheduled / social_posts_published removed.
            'keywords_tracked'       => DB::table('seo_keywords')->where('workspace_id', $wsId)->count(),
            'designs_created'        => DB::table('studio_designs')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            // W6 launch scope: emails_sent / campaigns_total removed.
            'active_agents'          => $agentCounts['agents_enabled'],   // kept for old clients: = agents_enabled
            'websites_total'         => DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'websites_published'     => DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->count(),
        ];

        // ── PENDING APPROVALS (join tasks for engine/action context) ───────
        // 2026-05-30 fix: ApprovalService::requestIfNeeded() creates approvals
        // BEFORE a task exists (the task is created post-approval). Those rows
        // have NULL task_id but DO carry engine/action/data_json on the
        // approvals row itself. The previous query only read engine/action
        // from the joined task and fell back to "system"/"review" — which
        // surfaced as "Review · system" labels with greyed-out buttons. We
        // now coalesce between the two tables so the real engine/action shows.
        //
        // v1.4.4 (2026-05-30) — batched approvals. Each row now exposes
        // batch_id + batch_count + batch_total_credits + sample_titles so the
        // SPA can render "Approve all 10 articles" instead of 10 separate
        // rows. When batch_id is null the row behaves identically to before.
        $approvals = DB::table('approvals as ap')
            ->leftJoin('tasks as t', 't.id', '=', 'ap.task_id')
            ->where('ap.workspace_id', $wsId)
            ->where('ap.status', 'pending')
            ->orderByDesc('ap.created_at')
            ->limit(5)
            ->get([
                'ap.id', 'ap.task_id', 'ap.status', 'ap.created_at', 'ap.batch_id',
                'ap.engine as ap_engine', 'ap.action as ap_action', 'ap.data_json as ap_data_json',
                't.engine as t_engine', 't.action as t_action', 't.payload_json as t_payload_json',
                't.credit_cost',
            ])
            ->map(function ($row) use ($wsId) {
                $engine = $row->t_engine ?: ($row->ap_engine ?: 'system');
                $action = $row->t_action ?: ($row->ap_action ?: 'review');
                $rawMeta = $row->t_payload_json ?: $row->ap_data_json;
                $meta = $rawMeta ? json_decode($rawMeta, true) : null;

                // Batch context: if this approval has a batch_id, count and
                // sample the sibling tasks so the Command Center card can
                // surface "10 articles" with a few title previews.
                $batchCount        = 1;
                $batchTotalCredits = (int) ($row->credit_cost ?? 0);
                $sampleTitles      = [];
                if ($row->batch_id) {
                    $siblings = DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->where('batch_id', $row->batch_id)
                        ->where('action', $action)
                        ->whereIn('approval_status', ['pending'])
                        ->get(['id', 'credit_cost', 'payload_json']);
                    $batchCount = $siblings->count() ?: 1;
                    $batchTotalCredits = (int) $siblings->sum('credit_cost') ?: $batchTotalCredits;
                    foreach ($siblings->take(6) as $s) {
                        $p = $s->payload_json ? json_decode($s->payload_json, true) : null;
                        $t = is_array($p) ? ($p['title'] ?? $p['topic'] ?? $p['keyword'] ?? null) : null;
                        if ($t) $sampleTitles[] = mb_substr((string) $t, 0, 80);
                    }
                }

                // P0-B (2026-08-30, REPORT-0023 UX-020): the card names the agent the task is assigned to,
                // falling back to the engine's default only when there is no task.
                $__cardAgent = $this->agentForEngine($engine);
                if (!empty($row->task_id)) {
                    $__assigned = DB::table('tasks')->where('id', $row->task_id)->value('assigned_agents_json');
                    $__arr = is_string($__assigned) ? (json_decode($__assigned, true) ?: []) : ($__assigned ?: []);
                    if (is_array($__arr) && !empty($__arr[0])) { $__cardAgent = $this->agentForSlug((string) $__arr[0]); }
                }
                return [
                    'id'                  => $row->id,
                    'task_id'             => $row->task_id,
                    'batch_id'            => $row->batch_id,
                    'batch_count'         => $batchCount,
                    'batch_total_credits' => $batchTotalCredits,
                    'sample_titles'       => array_values(array_unique($sampleTitles)),
                    'engine'              => $engine,
                    'action'              => $action,
                    'label'               => $this->labelFor($engine, $action, $meta, $__cardAgent['name'] ?? null),
                    'agent'               => $__cardAgent,
                    'credit_cost'         => $row->credit_cost ?? 0,
                    'created_at'          => $row->created_at,
                    'age_hours'           => (int) Carbon::parse($row->created_at)->diffInHours(now()),
                    'time_ago'            => Carbon::parse($row->created_at)->diffForHumans(),
                ];
            })->values();

        $approvalsPending = DB::table('approvals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();

        // ── WEBSITES (most recent 6) ───────────────────────────────────────
        $websites = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get(['id', 'name', 'status', 'subdomain', 'custom_domain', 'published_at', 'updated_at'])
            ->map(function ($w) {
                $host = $w->custom_domain ?: ($w->subdomain ? $w->subdomain . '.levelupgrowth.io' : null);
                return [
                    'id'            => $w->id,
                    'name'          => $w->name,
                    'status'        => $w->status,
                    'host'          => $host,
                    'subdomain'     => $w->subdomain,
                    'custom_domain' => $w->custom_domain,
                    'published_at'  => $w->published_at,
                    'updated_at'    => $w->updated_at,
                    'time_ago'      => $w->updated_at ? Carbon::parse($w->updated_at)->diffForHumans() : null,
                ];
            })
            ->values();

        // ── RECENT MEETINGS ────────────────────────────────────────────────
        $meetings = DB::table('meetings')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'title', 'type', 'status', 'total_credits_used', 'created_at'])
            ->map(function ($m) {
                return [
                    'id'           => $m->id,
                    'title'        => $m->title,
                    'type'         => $m->type,
                    'status'       => $m->status,
                    'credits_used' => $m->total_credits_used,
                    'created_at'   => $m->created_at,
                    'time_ago'     => Carbon::parse($m->created_at)->diffForHumans(),
                ];
            })
            ->values();

        // ── Greeting ───────────────────────────────────────────────────────
        $user = $request->user();
        $firstName = $user ? (explode(' ', (string) ($user->name ?? ''))[0] ?: null) : null;

        return response()->json([
            'workspace_id'              => $wsId,
            'first_name'                => $firstName,
            'server_time'               => now()->toIso8601String(),
            'agents'                    => $agents,
            'activity_feed'             => $feed,
            'latest_strategy'           => $latestProposal,
            'strategy_proposals_total'  => $proposalsTotal,
            'strategy_proposals_global' => $proposalsGlobal,
            'stats'                     => $stats,
            'pending_approvals'         => $approvals,
            'approvals_pending_total'   => $approvalsPending,
            'websites'                  => $websites,
            'recent_meetings'           => $meetings,
        ]);
    }

    /** Lightweight endpoint for nav badge polling. */
    public function approvalsCount(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if (!$wsId) return response()->json(['pending' => 0]);
        $n = DB::table('approvals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();
        return response()->json(['pending' => $n]);
    }

    /** Split dotted audit-log action "engine.action" into [engine, action]. */
    private function splitAction(string $s): array
    {
        if (str_contains($s, '.')) {
            [$e, $a] = explode('.', $s, 2);
            return [$e, $a];
        }
        return ['system', $s];
    }

    /** Plain-English label for an engine.action event. */
    private function labelFor(string $engine, string $action, ?array $meta, ?string $agentName = null): string
    {
        // Wave 41c — task.* events used to label as "Sarah created/executed a task"
        // because the audit_log action is always task.created / task.executed
        // regardless of which agent did the work. Use the real agent name +
        // the underlying action stored in metadata_json.action.
        if ($engine === 'task') {
            $worker = $agentName ?: 'A specialist';
            $innerAction = is_array($meta) && !empty($meta['action'])
                ? ucfirst(str_replace('_', ' ', (string) $meta['action']))
                : 'a task';
            return match ($action) {
                'created'           => "Sarah delegated " . lcfirst($innerAction) . " to {$worker}",
                'executed'          => "{$worker} completed " . lcfirst($innerAction),
                'execution_failed'  => "{$worker} failed at " . lcfirst($innerAction),
                'cancelled'         => "{$worker} cancelled " . lcfirst($innerAction),
                default             => "{$worker} {$action} " . lcfirst($innerAction),
            };
        }

        $map = [
            'seo.run_audit'            => 'James ran an SEO audit',
            'seo.deep_audit'           => 'Alex ran a technical SEO audit',
            'seo.track_keywords'       => 'James tracked keyword rankings',
            'seo.add_keyword'          => 'James added a tracked keyword',
            'seo.resume_goal'          => 'James resumed an SEO goal',
            'seo.generate_article'     => 'James queued an article for Priya',
            'seo.serp_analysis'        => 'James ran SERP analysis',
            'seo.generate_links'       => 'Ryan generated internal-link suggestions',
            'write.create_article'     => 'Priya created an article',
            'write.publish_article'    => 'Priya published an article',
            'write.ai_write'           => 'Priya drafted content with AI',
            'write.improve_draft'      => 'Priya improved a draft',
            'write.generate_outline'   => 'Priya outlined an article',
            'social.create_post'       => 'An article was shared to social',
            'social.update_post'       => 'A social share was edited',
            'social.schedule_post'     => 'A social share was scheduled',
            'social.publish_post'      => 'An article was published to social',
            'crm.create_lead'          => 'Elena captured a new lead',
            'crm.create_contact'       => 'Elena added a contact',
            'crm.update_lead'          => 'Elena updated a lead',
            'crm.score_lead'           => 'Elena scored a lead',
            'crm.generate_outreach'    => 'Elena drafted an outreach email',
            'studio.export_design'     => 'Studio exported a design',
            'studio.create_design'     => 'Studio started a new design',
            'studio.publish_social'    => 'A Studio design was shared to social',
            'marketing.send_campaign'  => 'Priya sent an email campaign',
            'marketing.schedule_campaign' => 'Priya scheduled a campaign',
            'marketing.update_campaign'=> 'Priya edited a campaign',
            'builder.wizard_generate'  => 'Arthur generated a new website',
            'builder.create_website'   => 'Arthur created a website',
            'builder.generate_page'    => 'Arthur generated a page',
            'builder.publish_website'  => 'Arthur published your website',
            'creative.generate_image'  => 'The creative engine generated an image',
            'creative.generate_video'  => 'The creative engine rendered a video',
            'manualedit.create_canvas' => 'A canvas edit was started',
            'meeting.end_meeting'      => 'Sarah closed a strategy meeting',
            'meeting.start_meeting'    => 'Sarah opened a strategy meeting',
            'meeting.create_plan'      => 'Sarah drafted a strategic plan',
            'calendar.create_event'    => 'Elena added a calendar event',
            'agent.direct_message'     => 'Sarah sent an agent message',
            'agent.dispatch'           => 'Sarah dispatched an agent',
            'task.created'             => 'Sarah created a task',
            'task.executed'            => 'Sarah executed a task',
            'bella_chat'               => 'Bella assisted with admin',
        ];
        $key = $engine . '.' . $action;
        if (isset($map[$key])) {
            $label = $map[$key];
            // RES-3 (2026-08-30): the map carries a default first name per action; the feed already
            // resolved the REAL agent (task assignment / metadata). Never name the wrong specialist.
            if ($agentName && preg_match('/^(James|Alex|Priya|Elena|Ryan|Arthur|Marcus|Sarah)\b/', $label, $m)
                && strcasecmp($m[1], $agentName) !== 0) {
                $label = $agentName . substr($label, strlen($m[1]));
            }
            return $label;
        }
        return ucfirst(str_replace('_', ' ', $action)) . ' · ' . $engine;
    }

    /** Map an engine key to a primary agent (name + slug + color). */
    /** Wave 40 — Resolve agent by slug from the agents table. Caches per request. */
    private function agentForSlug(string $slug): array
    {
        static $cache = [];
        $slug = strtolower($slug);
        if (isset($cache[$slug])) return $cache[$slug];
        // W6: raw DB reads bypass the Agent model global scope. Resolve through
        // the one authority so a removed agent can only render as historical.
        return $cache[$slug] = \App\Core\LaunchScope\AgentDirectory::resolve($slug);
    }

    private function agentForEngine(string $engine): array
    {
        $map = [
            'seo'        => ['name' => 'James',  'slug' => 'james',  'color' => '#3B82F6'],
            'write'      => ['name' => 'Priya',  'slug' => 'priya',  'color' => '#7C3AED'],
            // LAUNCH SCOPE 2026-07-20 — removed-agent (marcus) badges replaced with
            // honest non-person tool labels. Studio/creative/manualedit are direct
            // user tools; social is the retained article-share service.
            'social'     => ['name' => 'Article share', 'slug' => 'system', 'color' => '#EC4899'],
            'crm'        => ['name' => 'Elena',  'slug' => 'elena',  'color' => '#00E5A8'],
            'studio'     => ['name' => 'Studio', 'slug' => 'studio', 'color' => '#EC4899'],
            'marketing'  => ['name' => 'Sarah',  'slug' => 'sarah',  'color' => '#F59E0B'],
            'builder'    => ['name' => 'Arthur', 'slug' => 'arthur', 'color' => '#00E5A8'],
            'creative'   => ['name' => 'Studio', 'slug' => 'studio', 'color' => '#F97316'],
            'manualedit' => ['name' => 'Editor', 'slug' => 'system', 'color' => '#EC4899'],
            'meeting'    => ['name' => 'Sarah',  'slug' => 'sarah',  'color' => '#F59E0B'],
            'calendar'   => ['name' => 'Elena',  'slug' => 'elena',  'color' => '#00E5A8'],
            'agent'      => ['name' => 'Sarah',  'slug' => 'sarah',  'color' => '#F59E0B'],
            'task'       => ['name' => 'Sarah',  'slug' => 'sarah',  'color' => '#F59E0B'],
            'bella_chat' => ['name' => 'Bella',  'slug' => 'bella',  'color' => '#A78BFA'],
        ];
        return $map[$engine] ?? ['name' => 'Sarah', 'slug' => 'sarah', 'color' => '#F59E0B'];
    }

    /** Map an agent's category → which engines' audit-logs count as their work. */
    private function enginesForCategory(?string $category, string $slug, bool $isDmm): array
    {
        if ($isDmm || $slug === 'sarah') {
            return ['meeting', 'task', 'agent'];
        }
        return match ($category) {
            'seo'     => ['seo'],
            'content' => ['write', 'marketing'],
            'social'  => ['social', 'studio', 'creative'],
            'crm'     => ['crm', 'calendar'],
            default   => [],
        };
    }
}
