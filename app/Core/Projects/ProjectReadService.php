<?php

namespace App\Core\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ProjectReadService — assembles read-only views over the project graph.
 *
 *   list()           — paginated list with filters/sort
 *   single()         — single project + scores + counts + has_outcome
 *   timeline()       — merged event feed (automation + milestones + plan + outcome)
 *   publishQueue()   — content_packs linked to project
 *
 * KPI + Milestone *content* still flows through KpiService / MilestoneService
 * — this service does NOT duplicate hydration logic, only orchestrates
 * fetches that span multiple tables.
 */
class ProjectReadService
{
    public function __construct(
        private readonly MilestoneService $milestones,
        private readonly KpiService $kpis,
    ) {}

    public const ALLOWED_SORTS = ['created_at', 'planned_end_at', 'planned_start_at',
        'updated_at', 'name', 'status'];

    public function list(int $wsId, array $opts = []): array
    {
        $q = DB::table('projects')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at');

        if (!empty($opts['status']))         $q->where('status', $opts['status']);
        if (!empty($opts['source_type']))    $q->where('source_type', $opts['source_type']);
        if (!empty($opts['owner_user_id']))  $q->where('owner_user_id', (int) $opts['owner_user_id']);

        // Search by name
        if (!empty($opts['q'])) {
            $needle = '%' . str_replace(['%','_'], ['\%','\_'], (string) $opts['q']) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('name', 'like', $needle)
                  ->orWhere('goal', 'like', $needle);
            });
        }

        $sort = in_array($opts['sort'] ?? '', self::ALLOWED_SORTS, true)
            ? $opts['sort'] : 'created_at';
        $dir  = (($opts['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

        $total   = (clone $q)->count();
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 20)));
        $page    = max(1, (int) ($opts['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $rows = $q->orderBy($sort, $dir)
            ->offset($offset)->limit($perPage)
            ->get([
                'id','name','goal','description','status','source_type','source_meeting_id',
                'owner_user_id','budget_credits','budget_spent','planned_start_at',
                'planned_end_at','started_at','completed_at','archived_at',
                'metadata_json','created_at','updated_at',
            ]);

        // Attach counts in a single batched query to avoid N+1
        $projectIds = $rows->pluck('id')->toArray();
        $counts = $this->batchCounts($wsId, $projectIds);
        $outcomes = DB::table('project_outcomes')
            ->where('workspace_id', $wsId)
            ->whereIn('project_id', $projectIds)
            ->get(['project_id','outcome','composite_score','disposed_at'])
            ->keyBy('project_id');

        $hydrated = $rows->map(function ($r) use ($counts, $outcomes) {
            $arr = (array) $r;
            $arr['metadata'] = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
            unset($arr['metadata_json']);
            $arr['counts']   = $counts[$r->id] ?? [
                'milestones' => 0, 'kpis' => 0, 'plan_tasks' => 0, 'events' => 0,
            ];
            $o = $outcomes->get($r->id);
            $arr['outcome'] = $o ? [
                'outcome'         => $o->outcome,
                'composite_score' => $o->composite_score !== null ? (float) $o->composite_score : null,
                'disposed_at'     => $o->disposed_at,
            ] : null;
            return $arr;
        });

        return [
            'success' => true,
            'data'    => $hydrated->toArray(),
            'paging'  => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters_applied' => array_filter([
                'status'        => $opts['status']        ?? null,
                'source_type'   => $opts['source_type']   ?? null,
                'owner_user_id' => $opts['owner_user_id'] ?? null,
                'q'             => $opts['q']             ?? null,
            ], fn($v) => $v !== null && $v !== ''),
            'sort'    => $sort,
            'dir'     => $dir,
        ];
    }

    public function single(int $wsId, int $projectId): array
    {
        $row = DB::table('projects')
            ->where('id', $projectId)->where('workspace_id', $wsId)
            ->whereNull('deleted_at')->first();
        if (!$row) return ['success' => false, 'error' => 'project not found'];

        $arr = (array) $row;
        $arr['metadata'] = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
        unset($arr['metadata_json'], $arr['deleted_at']);

        // Scores
        $msScore  = $this->milestones->score($wsId, $projectId);
        $kpiScore = $this->kpis->score($wsId, $projectId);
        $arr['scores'] = [
            'milestone'    => $msScore,
            'kpi'          => $kpiScore,
            'composite'    => $this->composite($msScore['score'] ?? null, $kpiScore['score'] ?? null),
        ];

        // Counts
        $counts = $this->batchCounts($wsId, [$projectId])[$projectId] ?? [
            'milestones' => 0, 'kpis' => 0, 'plan_tasks' => 0, 'events' => 0,
        ];
        $arr['counts'] = $counts;

        // Outcome summary if any
        $outcome = DB::table('project_outcomes')
            ->where('workspace_id', $wsId)->where('project_id', $projectId)->first();
        $arr['outcome'] = $outcome ? [
            'outcome'         => $outcome->outcome,
            'composite_score' => $outcome->composite_score !== null ? (float) $outcome->composite_score : null,
            'milestone_score' => $outcome->milestone_score !== null ? (float) $outcome->milestone_score : null,
            'kpi_score'       => $outcome->kpi_score       !== null ? (float) $outcome->kpi_score       : null,
            'narrative'       => $outcome->narrative,
            'disposed_at'     => $outcome->disposed_at,
            'disposed_by'     => $outcome->disposed_by,
        ] : null;
        $arr['has_outcome'] = $outcome !== null;

        return ['success' => true, 'data' => $arr];
    }

    /**
     * Timeline: merged feed of project events, oldest → newest.
     * Sources:
     *   - automation_events (scheduled work)
     *   - milestones (target_date entries + completed_at)
     *   - execution_plans (started_at, completed_at)
     *   - project_outcomes (disposed_at)
     */
    public function timeline(int $wsId, int $projectId, array $opts = []): array
    {
        // Verify project exists
        $proj = DB::table('projects')
            ->where('id', $projectId)->where('workspace_id', $wsId)
            ->whereNull('deleted_at')->first(['id','planned_start_at','created_at']);
        if (!$proj) return ['success' => false, 'error' => 'project not found'];

        $events = [];

        // automation_events
        $autoRows = DB::table('automation_events')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId)
            ->get(['id','title','description','category','engine','starts_at','ends_at','status']);
        foreach ($autoRows as $a) {
            $events[] = [
                'kind'        => 'automation_event',
                'reference'   => 'automation_events.' . $a->id,
                'at'          => $a->starts_at,
                'title'       => $a->title,
                'engine'      => $a->engine,
                'category'    => $a->category,
                'status'      => $a->status,
                'description' => $a->description,
            ];
        }

        // milestones (target_date for pending, completed_at for achieved)
        $msRows = DB::table('project_milestones')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId)
            ->get(['id','title','target_date','completed_at','status']);
        foreach ($msRows as $m) {
            // Use completed_at if achieved/missed/cancelled (terminal), else target_date
            $at = in_array($m->status, ['achieved','missed','cancelled'], true)
                ? ($m->completed_at ?? $m->target_date)
                : $m->target_date;
            if (!$at) continue;
            $events[] = [
                'kind'      => 'milestone',
                'reference' => 'project_milestones.' . $m->id,
                'at'        => $at,
                'title'     => $m->title,
                'status'    => $m->status,
            ];
        }

        // execution_plans
        $planRows = DB::table('execution_plans')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId)
            ->get(['id','title','status','started_at','approved_at']);
        foreach ($planRows as $p) {
            $at = $p->started_at ?? $p->approved_at;
            if (!$at) continue;
            $events[] = [
                'kind'      => 'execution_plan',
                'reference' => 'execution_plans.' . $p->id,
                'at'        => $at,
                'title'     => $p->title,
                'status'    => $p->status,
            ];
        }

        // outcome
        $outcome = DB::table('project_outcomes')
            ->where('workspace_id', $wsId)->where('project_id', $projectId)->first();
        if ($outcome) {
            $events[] = [
                'kind'      => 'outcome',
                'reference' => 'project_outcomes.' . $outcome->id,
                'at'        => $outcome->disposed_at,
                'title'     => 'Project closed as ' . $outcome->outcome,
                'status'    => $outcome->outcome,
                'composite' => $outcome->composite_score !== null ? (float) $outcome->composite_score : null,
            ];
        }

        // Project creation as the timeline origin
        $events[] = [
            'kind'      => 'created',
            'reference' => 'projects.' . $projectId,
            'at'        => $proj->created_at,
            'title'     => 'Project created',
            'status'    => 'created',
        ];

        // Sort ascending by timestamp
        usort($events, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));

        return [
            'success' => true,
            'count'   => count($events),
            'data'    => $events,
        ];
    }

    public function publishQueue(int $wsId, int $projectId): array
    {
        // Verify project belongs to workspace before exposing linked content
        $exists = DB::table('projects')
            ->where('id', $projectId)->where('workspace_id', $wsId)
            ->whereNull('deleted_at')->exists();
        if (!$exists) return ['success' => false, 'error' => 'project not found'];

        $packs = DB::table('content_packs')
            ->where('workspace_id', $wsId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id','name','theme','status','created_at','updated_at']);

        return [
            'success' => true,
            'count'   => $packs->count(),
            'data'    => $packs->toArray(),
        ];
    }

    /**
     * Batch counts for a set of project IDs.
     *
     * Returns: [projectId => ['milestones'=>N,'kpis'=>N,'plan_tasks'=>N,'events'=>N]]
     */
    private function batchCounts(int $wsId, array $projectIds): array
    {
        if (empty($projectIds)) return [];

        $out = [];
        foreach ($projectIds as $pid) {
            $out[$pid] = [
                'milestones' => 0,
                'kpis'       => 0,
                'plan_tasks' => 0,
                'events'     => 0,
            ];
        }

        $ms = DB::table('project_milestones')
            ->where('workspace_id', $wsId)
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('COUNT(*) as c'))
            ->groupBy('project_id')->get();
        foreach ($ms as $row) $out[$row->project_id]['milestones'] = (int) $row->c;

        $k = DB::table('project_kpis')
            ->where('workspace_id', $wsId)
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('COUNT(*) as c'))
            ->groupBy('project_id')->get();
        foreach ($k as $row) $out[$row->project_id]['kpis'] = (int) $row->c;

        $p = DB::table('execution_plans')
            ->where('workspace_id', $wsId)
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('COUNT(*) as c'))
            ->groupBy('project_id')->get();
        foreach ($p as $row) $out[$row->project_id]['plan_tasks'] = (int) $row->c;

        $e = DB::table('automation_events')
            ->where('workspace_id', $wsId)
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('COUNT(*) as c'))
            ->groupBy('project_id')->get();
        foreach ($e as $row) $out[$row->project_id]['events'] = (int) $row->c;

        return $out;
    }

    private function composite(?float $ms, ?float $kpi): ?float
    {
        if ($ms === null && $kpi === null) return null;
        if ($ms === null)  return round((float) $kpi, 2);
        if ($kpi === null) return round((float) $ms,  2);
        return round(((float) $ms + (float) $kpi) / 2, 2);
    }
}