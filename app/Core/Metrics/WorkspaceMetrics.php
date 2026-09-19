<?php

namespace App\Core\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A2 (REPORT-0061, Owner Decision 2, 2026-09-19) — the ONE place a task or agent count is defined.
 *
 * Before this class the Command Center, the Workspace canvas, the Agents page and the Projects board each counted
 * the same tasks with their own rules (three per-agent bucket implementations, two "Sarah" rules, "done" that
 * included QA-rejected work, a board that never loaded). Every surface now reads these definitions; where two
 * numbers differ on screen they are different METRICS and the label says which one it is.
 *
 * METRIC DEFINITIONS (workspace-scoped; every query is bounded by workspace_id)
 * ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
 *  tasks_done         tasks with status = completed whose output QA did not reject (qa_status <> 'rejected').
 *                     A completed task the QA gate rejected is NOT "done" for the customer.
 *  tasks_done_today   tasks_done finished today            (COALESCE(completed_at, updated_at))
 *  tasks_done_week    tasks_done finished in the last 7 d  (same timestamp)
 *  tasks_running      status in (running, verifying)                                   — "ongoing"
 *  tasks_pending      status in (pending, queued, awaiting_approval)                    — "upcoming"
 *  tasks_blocked      status = blocked (waiting on a dependency or a limit; not upcoming, not failed)
 *  tasks_declined     approval_status = rejected (the customer said no) — its own bucket, never a failure
 *  tasks_failed       status in (failed, degraded, cancelled) that were NOT declined
 *  tasks_qa_rejected  qa_status = rejected (completed by the agent, refused by QA) — shown separately
 *
 *  agents_enabled     workspace_agents rows with enabled = 1 for the workspace ("on your workspace")
 *  agents_roster      the whole customer-facing workforce: every row of `agents` plus Arthur (the builder, who has
 *                     no `agents` row but is a face on every roster surface)
 *  agents_active_30d  distinct agents credited with a task created in the last 30 days (by the crediting rule below)
 *
 * PER-AGENT CREDITING RULE (one task can be credited to more than one agent; never twice to the same agent)
 *  – every slug in tasks.assigned_agents_json is credited;
 *  – a task with no assignee is credited to the agent whose engine it belongs to (engine → slug: builder → arthur,
 *    otherwise the engine name itself when it is a roster slug);
 *  – Arthur is additionally credited with every builder-engine task (he is the builder even when Sarah is the
 *    assignee — "Sarah asked Arthur");
 *  – Sarah is additionally credited with every task she ORIGINATED: payload_json.created_via starting with
 *    "sarah" (sarah_chat, sarah_proposal, sarah_proactive, sarah_router, sarah_image_request …). The old rule
 *    counted only sarah_chat / sarah_proactive and showed "0 done" beside "Tasks done 1".
 *  – agent_delegations rows (a separate stream, no task id) are added to the delegate's buckets as before.
 *  Buckets per agent: ongoing (= tasks_running), upcoming (= tasks_pending), blocked, completed (= tasks_done),
 *  failed, declined, qa_rejected, success_rate = completed / (completed + failed) — declined work is not a failure.
 */
class WorkspaceMetrics
{
    public const RUNNING  = ['running', 'verifying'];
    public const PENDING  = ['pending', 'queued', 'awaiting_approval'];
    public const FAILED   = ['failed', 'degraded', 'cancelled'];
    public const ENGINE_AGENT = ['builder' => 'arthur'];

    /** @return array<string,int> the workspace's task counts (see the definitions above) */
    public function taskCounts(int $wsId): array
    {
        $base = fn () => DB::table('tasks')->where('workspace_id', $wsId);
        $done = fn () => $base()->where('status', 'completed')->where(fn ($q) => $q->whereNull('qa_status')->orWhere('qa_status', '<>', 'rejected'));
        $finishedAt = DB::raw('COALESCE(completed_at, updated_at)');
        return [
            'tasks_done'        => $done()->count(),
            'tasks_done_today'  => $done()->whereDate($finishedAt, Carbon::today())->count(),
            'tasks_done_week'   => $done()->where($finishedAt, '>=', Carbon::now()->subDays(7))->count(),
            'tasks_running'     => $base()->whereIn('status', self::RUNNING)->count(),
            'tasks_pending'     => $base()->whereIn('status', self::PENDING)->count(),
            'tasks_blocked'     => $base()->where('status', 'blocked')->count(),
            'tasks_declined'    => $base()->where('approval_status', 'rejected')->count(),
            'tasks_failed'      => $base()->whereIn('status', self::FAILED)->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', '<>', 'rejected'))->count(),
            'tasks_qa_rejected' => $base()->where('status', 'completed')->where('qa_status', 'rejected')->count(),
            'tasks_total'       => $base()->count(),
        ];
    }

    /** @return array{agents_enabled:int, agents_roster:int, agents_active_30d:int, enabled_slugs:string[]} */
    public function agentCounts(int $wsId): array
    {
        $enabled = DB::table('workspace_agents')->join('agents', 'agents.id', '=', 'workspace_agents.agent_id')
            ->where('workspace_agents.workspace_id', $wsId)->where('workspace_agents.enabled', true)->pluck('agents.slug')->all();
        $roster = $this->rosterSlugs();
        $since = Carbon::now()->subDays(30);
        $active = [];
        foreach ($this->creditedTasks($wsId, $since) as $slug => $ids) { if (in_array($slug, $roster, true) && $ids) $active[$slug] = true; }
        return [
            'agents_enabled'    => count($enabled),
            'agents_roster'     => count($roster),
            'agents_active_30d' => count($active),
            'enabled_slugs'     => $enabled,
        ];
    }

    /**
     * Per-agent buckets for the whole roster (slug => buckets). Slugs with no work still get zeros so a card never
     * shows "—" because a query skipped it.
     * @return array<string, array{ongoing:int,upcoming:int,blocked:int,completed:int,failed:int,declined:int,qa_rejected:int,success_rate:int,total:int,credits:int}>
     */
    public function agentBuckets(int $wsId): array
    {
        $tasks = DB::table('tasks')->where('workspace_id', $wsId)
            ->get(['id', 'status', 'approval_status', 'qa_status', 'engine', 'assigned_agents_json', 'credit_cost', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) as created_via")]);
        $byId = [];
        foreach ($tasks as $t) $byId[$t->id] = $t;

        $credit = [];   // slug => [task_id => true]
        foreach ($tasks as $t) { foreach ($this->creditedSlugs($t) as $slug) $credit[$slug][$t->id] = true; }

        $delegations = DB::table('agent_delegations as d')->join('agents as a', 'a.id', '=', 'd.to_agent')
            ->where('d.workspace_id', $wsId)->selectRaw('a.slug, d.status, count(*) as cnt')->groupBy('a.slug', 'd.status')->get();

        $out = [];
        foreach ($this->rosterSlugs() as $slug) $out[$slug] = $this->emptyBuckets();
        foreach ($credit as $slug => $ids) {
            if (!isset($out[$slug])) $out[$slug] = $this->emptyBuckets();
            foreach (array_keys($ids) as $id) {
                $t = $byId[$id];
                $b = $this->bucketOf($t);
                if ($b) $out[$slug][$b]++;
                $out[$slug]['credits'] += (int) $t->credit_cost;
            }
        }
        foreach ($delegations as $d) {
            if (!isset($out[$d->slug])) $out[$d->slug] = $this->emptyBuckets();
            $b = match ($d->status) { 'pending' => 'upcoming', 'in_progress' => 'ongoing', 'completed' => 'completed', 'failed' => 'failed', default => null };
            if ($b) $out[$d->slug][$b] += (int) $d->cnt;
        }
        foreach ($out as $slug => &$b) {
            $den = $b['completed'] + $b['failed'];
            $b['success_rate'] = $den > 0 ? (int) round($b['completed'] / $den * 100) : 0;
            $b['total'] = $b['ongoing'] + $b['upcoming'] + $b['blocked'] + $b['completed'] + $b['failed'] + $b['declined'] + $b['qa_rejected'];
        }
        unset($b);
        return $out;
    }

    /** The bucket a single task falls into under the definitions above (null = a status we do not count). */
    public function bucketOf(object $t): ?string
    {
        if (($t->approval_status ?? null) === 'rejected') return 'declined';
        if ($t->status === 'completed') return ($t->qa_status ?? null) === 'rejected' ? 'qa_rejected' : 'completed';
        if (in_array($t->status, self::RUNNING, true)) return 'ongoing';
        if (in_array($t->status, self::PENDING, true)) return 'upcoming';
        if ($t->status === 'blocked') return 'blocked';
        if (in_array($t->status, self::FAILED, true)) return 'failed';
        return null;
    }

    /** @return string[] the slugs a task is credited to (deduplicated) */
    public function creditedSlugs(object $t): array
    {
        $assigned = $t->assigned_agents_json;
        if (is_string($assigned)) $assigned = json_decode($assigned, true);
        $slugs = [];
        foreach ((array) ($assigned ?: []) as $s) { if (is_string($s) && $s !== '') $slugs[] = strtolower($s); }
        if (!$slugs) {
            $engineSlug = self::ENGINE_AGENT[$t->engine] ?? $t->engine;
            if ($engineSlug) $slugs[] = strtolower((string) $engineSlug);
        }
        if (($t->engine ?? null) === 'builder') $slugs[] = 'arthur';
        $via = (string) ($t->created_via ?? '');
        if (str_starts_with($via, 'sarah')) $slugs[] = 'sarah';
        return array_values(array_unique($slugs));
    }

    /** @return string[] every customer-facing agent: the `agents` table plus Arthur */
    public function rosterSlugs(): array
    {
        $slugs = DB::table('agents')->orderBy('id')->pluck('slug')->map(fn ($s) => strtolower($s))->all();
        if (!in_array('arthur', $slugs, true)) $slugs[] = 'arthur';
        return $slugs;
    }

    /** @return array<string, int[]> slug => task ids created since $since, by the crediting rule */
    private function creditedTasks(int $wsId, Carbon $since): array
    {
        $rows = DB::table('tasks')->where('workspace_id', $wsId)->where('created_at', '>=', $since)
            ->get(['id', 'engine', 'assigned_agents_json', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.created_via')) as created_via")]);
        $out = [];
        foreach ($rows as $t) { foreach ($this->creditedSlugs($t) as $slug) $out[$slug][] = $t->id; }
        return $out;
    }

    private function emptyBuckets(): array
    {
        return ['ongoing' => 0, 'upcoming' => 0, 'blocked' => 0, 'completed' => 0, 'failed' => 0, 'declined' => 0, 'qa_rejected' => 0, 'success_rate' => 0, 'total' => 0, 'credits' => 0];
    }
}
