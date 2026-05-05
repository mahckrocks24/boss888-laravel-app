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
                $engines = $this->enginesForCategory($a->category, $a->slug, (bool) $a->is_dmm);
                $last = null;
                $weeklyCount = 0;
                if (!empty($engines)) {
                    $where = function ($q) use ($engines) {
                        foreach ($engines as $eng) {
                            $q->orWhere('action', 'LIKE', $eng . '.%');
                        }
                    };
                    $last = DB::table('audit_logs')
                        ->where('workspace_id', $wsId)
                        ->where($where)
                        ->orderByDesc('created_at')
                        ->value('created_at');
                    $weeklyCount = DB::table('audit_logs')
                        ->where('workspace_id', $wsId)
                        ->where($where)
                        ->where('created_at', '>=', $weekAgo)
                        ->count();
                }
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
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['action', 'entity_type', 'entity_id', 'metadata_json', 'created_at']);

        $feed = $feedRows->map(function ($log) {
            [$engine, $action] = $this->splitAction($log->action);
            $meta = json_decode($log->metadata_json ?? 'null', true);
            return [
                'engine'    => $engine,
                'action'    => $action,
                'label'     => $this->labelFor($engine, $action, $meta),
                'agent'     => $this->agentForEngine($engine),
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
        $auditTotal = DB::table('audit_logs')->where('workspace_id', $wsId)->count();
        $auditWeek  = DB::table('audit_logs')->where('workspace_id', $wsId)->where('created_at', '>=', $weekAgo)->count();
        $auditToday = DB::table('audit_logs')->where('workspace_id', $wsId)->whereDate('created_at', today())->count();

        $stats = [
            'tasks_completed'        => $auditTotal,
            'tasks_this_week'        => $auditWeek,
            'tasks_today'            => $auditToday,
            'articles_published'     => DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->count(),
            'articles_total'         => DB::table('articles')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'leads_captured'         => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'leads_this_week'        => DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('created_at', '>=', $weekAgo)->count(),
            'social_posts_scheduled' => DB::table('social_posts')->where('workspace_id', $wsId)->whereIn('status', ['scheduled', 'draft'])->whereNull('deleted_at')->count(),
            'social_posts_published' => DB::table('social_posts')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->count(),
            'keywords_tracked'       => DB::table('seo_keywords')->where('workspace_id', $wsId)->count(),
            'designs_created'        => DB::table('studio_designs')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'emails_sent'            => DB::table('email_campaigns_log')->where('workspace_id', $wsId)->count(),
            'campaigns_total'        => DB::table('campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'active_agents'          => DB::table('workspace_agents')->where('workspace_id', $wsId)->where('enabled', true)->count(),
            'websites_total'         => DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->count(),
            'websites_published'     => DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')->count(),
        ];

        // ── PENDING APPROVALS (join tasks for engine/action context) ───────
        $approvals = DB::table('approvals as ap')
            ->leftJoin('tasks as t', 't.id', '=', 'ap.task_id')
            ->where('ap.workspace_id', $wsId)
            ->where('ap.status', 'pending')
            ->orderByDesc('ap.created_at')
            ->limit(5)
            ->get([
                'ap.id', 'ap.task_id', 'ap.status', 'ap.created_at',
                't.engine', 't.action', 't.payload_json', 't.credit_cost',
            ])
            ->map(function ($row) {
                $engine = $row->engine ?: 'system';
                $action = $row->action ?: 'review';
                $meta = $row->payload_json ? json_decode($row->payload_json, true) : null;
                return [
                    'id'         => $row->id,
                    'task_id'    => $row->task_id,
                    'engine'     => $engine,
                    'action'     => $action,
                    'label'      => $this->labelFor($engine, $action, $meta),
                    'agent'      => $this->agentForEngine($engine),
                    'credit_cost' => $row->credit_cost ?? 0,
                    'created_at' => $row->created_at,
                    'age_hours'  => (int) Carbon::parse($row->created_at)->diffInHours(now()),
                    'time_ago'   => Carbon::parse($row->created_at)->diffForHumans(),
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
    private function labelFor(string $engine, string $action, ?array $meta): string
    {
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
            'social.create_post'       => 'Marcus created a social post',
            'social.update_post'       => 'Marcus edited a social post',
            'social.schedule_post'     => 'Marcus scheduled a post',
            'social.publish_post'      => 'Marcus published a post',
            'crm.create_lead'          => 'Elena captured a new lead',
            'crm.create_contact'       => 'Elena added a contact',
            'crm.update_lead'          => 'Elena updated a lead',
            'crm.score_lead'           => 'Elena scored a lead',
            'crm.generate_outreach'    => 'Elena drafted an outreach email',
            'studio.export_design'     => 'Marcus exported a design',
            'studio.create_design'     => 'Marcus started a new design',
            'studio.publish_social'    => 'Marcus published a design to social',
            'marketing.send_campaign'  => 'Priya sent an email campaign',
            'marketing.schedule_campaign' => 'Priya scheduled a campaign',
            'marketing.update_campaign'=> 'Priya edited a campaign',
            'builder.wizard_generate'  => 'Arthur generated a new website',
            'builder.create_website'   => 'Arthur created a website',
            'builder.generate_page'    => 'Arthur generated a page',
            'builder.publish_website'  => 'Arthur published your website',
            'creative.generate_image'  => 'The creative engine generated an image',
            'creative.generate_video'  => 'The creative engine rendered a video',
            'manualedit.create_canvas' => 'Marcus started a canvas edit',
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
        if (isset($map[$key])) return $map[$key];
        return ucfirst(str_replace('_', ' ', $action)) . ' · ' . $engine;
    }

    /** Map an engine key to a primary agent (name + slug + color). */
    private function agentForEngine(string $engine): array
    {
        $map = [
            'seo'        => ['name' => 'James',  'slug' => 'james',  'color' => '#3B82F6'],
            'write'      => ['name' => 'Priya',  'slug' => 'priya',  'color' => '#7C3AED'],
            'social'     => ['name' => 'Marcus', 'slug' => 'marcus', 'color' => '#EC4899'],
            'crm'        => ['name' => 'Elena',  'slug' => 'elena',  'color' => '#00E5A8'],
            'studio'     => ['name' => 'Marcus', 'slug' => 'marcus', 'color' => '#EC4899'],
            'marketing'  => ['name' => 'Priya',  'slug' => 'priya',  'color' => '#7C3AED'],
            'builder'    => ['name' => 'Arthur', 'slug' => 'arthur', 'color' => '#00E5A8'],
            'creative'   => ['name' => 'Marcus', 'slug' => 'marcus', 'color' => '#EC4899'],
            'manualedit' => ['name' => 'Marcus', 'slug' => 'marcus', 'color' => '#EC4899'],
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
