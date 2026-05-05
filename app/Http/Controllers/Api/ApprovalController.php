<?php

namespace App\Http\Controllers\Api;

use App\Core\Governance\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ApprovalController — Review Queue surface (v5.5.1).
 *
 * Scope: every method is scoped to the caller's workspace_id.
 * Resume/cancel task logic delegated to ApprovalService.
 */
class ApprovalController
{
    public function __construct(private ApprovalService $service) {}

    /**
     * GET /api/approvals
     * Query: status=pending|approved|rejected|revised|expired|all (default pending)
     *        page=1, per_page=20 (max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $wsId   = (int) $request->attributes->get('workspace_id');
        $status = (string) $request->query('status', 'pending');
        $page   = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $q = DB::table('approvals as a')
            ->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
            ->where('a.workspace_id', $wsId);

        if ($status !== 'all') $q->where('a.status', $status);

        $total = (clone $q)->count();

        $rows = $q->orderByRaw("CASE WHEN a.status='pending' THEN 0 ELSE 1 END")
            ->orderByDesc('a.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'a.id', 'a.task_id', 'a.status', 'a.created_at',
                'a.decided_at', 'a.decision_by', 'a.decision_note',
                't.engine', 't.action', 't.payload_json',
                't.credit_cost', 't.priority', 't.status as task_status',
                't.assigned_agents_json',
            ]);

        $items = $rows->map(function ($r) {
            return $this->shapeApproval($r);
        })->values();

        return response()->json([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
            'status'   => $status,
        ]);
    }

    /**
     * GET /api/approvals/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $today    = today();
        $weekAgo  = now()->subDays(7);

        $base = fn() => DB::table('approvals')->where('workspace_id', $wsId);

        $pending         = $base()->where('status', 'pending')->count();
        $approvedToday   = $base()->where('status', 'approved')->whereDate('decided_at', $today)->count();
        $rejectedToday   = $base()->where('status', 'rejected')->whereDate('decided_at', $today)->count();
        $approvedWeek    = $base()->where('status', 'approved')->where('decided_at', '>=', $weekAgo)->count();

        // Avg response time (hours) on decided approvals in the last 30 days
        $avgRow = $base()
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, decided_at)) as m')
            ->first();
        $avgHours = $avgRow && $avgRow->m ? round(((float) $avgRow->m) / 60, 1) : null;

        // Oldest pending age in hours
        $oldest = $base()->where('status', 'pending')->min('created_at');
        $oldestHours = $oldest ? (int) Carbon::parse($oldest)->diffInHours(now()) : 0;

        // By engine (join tasks)
        $byEngineRows = DB::table('approvals as a')
            ->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
            ->where('a.workspace_id', $wsId)
            ->selectRaw("COALESCE(t.engine, 'system') as engine, a.status, COUNT(*) as n")
            ->groupBy('engine', 'a.status')
            ->get();

        $byEngine = [];
        foreach ($byEngineRows as $r) {
            $byEngine[$r->engine] ??= ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0, 'revised' => 0];
            if (isset($byEngine[$r->engine][$r->status])) {
                $byEngine[$r->engine][$r->status] = (int) $r->n;
            }
        }

        return response()->json([
            'pending'             => $pending,
            'approved_today'      => $approvedToday,
            'rejected_today'      => $rejectedToday,
            'approved_this_week'  => $approvedWeek,
            'avg_response_hours'  => $avgHours,
            'oldest_pending_hours' => $oldestHours,
            'is_overdue'          => $oldestHours > 24,
            'by_engine'           => $byEngine,
        ]);
    }

    /**
     * POST /api/approvals/{id}/approve
     * Body: {note?: string}
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $row = DB::table('approvals')->where('id', $id)->first(['id', 'workspace_id', 'task_id', 'status']);

        if (!$row) return response()->json(['error' => 'approval_not_found'], 404);
        if ((int) $row->workspace_id !== $wsId) return response()->json(['error' => 'forbidden'], 403);

        // Idempotent: already approved
        if ($row->status === 'approved') {
            return response()->json(['success' => true, 'message' => 'already approved', 'approval_id' => $id]);
        }
        if ($row->status !== 'pending') {
            return response()->json(['error' => 'not_pending', 'status' => $row->status], 409);
        }
        if (!$row->task_id) {
            return response()->json(['error' => 'orphan_approval', 'hint' => 'This approval has no attached task. Expire it instead.'], 422);
        }

        try {
            $approval = $this->service->approve($id, (int) $request->user()->id, $request->input('note'));
            return response()->json(['success' => true, 'message' => 'Approved — the task will proceed.', 'approval' => $approval]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'approve_failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/approvals/{id}/reject
     * Body: {reason: string (required)}
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $reason = trim((string) $request->input('reason', $request->input('note', '')));
        if ($reason === '') {
            return response()->json(['error' => 'reason_required', 'message' => 'A rejection reason is required.'], 422);
        }

        $row = DB::table('approvals')->where('id', $id)->first(['id', 'workspace_id', 'task_id', 'status']);
        if (!$row) return response()->json(['error' => 'approval_not_found'], 404);
        if ((int) $row->workspace_id !== $wsId) return response()->json(['error' => 'forbidden'], 403);

        if ($row->status === 'rejected') {
            return response()->json(['success' => true, 'message' => 'already rejected', 'approval_id' => $id]);
        }
        if ($row->status !== 'pending') {
            return response()->json(['error' => 'not_pending', 'status' => $row->status], 409);
        }

        // Orphan — no task to cancel; just mark rejected directly.
        if (!$row->task_id) {
            DB::table('approvals')->where('id', $id)->update([
                'status'        => 'rejected',
                'decision_by'   => $request->user()->id,
                'decision_note' => $reason,
                'decided_at'    => now(),
                'updated_at'    => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Rejected.']);
        }

        try {
            $approval = $this->service->reject($id, (int) $request->user()->id, $reason);
            return response()->json(['success' => true, 'message' => 'Rejected — the task was cancelled.', 'approval' => $approval]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'reject_failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/approvals/{id}/revise — preserved from existing impl.
     */
    public function revise(Request $request, int $id): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $row = DB::table('approvals')->where('id', $id)->first(['id', 'workspace_id', 'status']);
        if (!$row || (int) $row->workspace_id !== $wsId) return response()->json(['error' => 'forbidden'], 403);
        try {
            $approval = $this->service->revise($id, (int) $request->user()->id, $request->input('note'));
            return response()->json(['success' => true, 'approval' => $approval]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'revise_failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/approvals/bulk-approve
     * Body: {ids: int[]}
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $ids  = array_slice(array_values(array_unique(array_map('intval', (array) $request->input('ids', [])))), 0, 50);
        if (empty($ids)) return response()->json(['error' => 'no_ids'], 422);

        $scoped = DB::table('approvals')->whereIn('id', $ids)->where('workspace_id', $wsId)
            ->where('status', 'pending')->whereNotNull('task_id')->pluck('id')->toArray();

        $approved = 0; $failed = 0; $errors = [];
        foreach ($scoped as $id) {
            try {
                $this->service->approve($id, (int) $request->user()->id, $request->input('note'));
                $approved++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        $skipped = count($ids) - count($scoped);

        return response()->json([
            'approved' => $approved,
            'failed'   => $failed,
            'skipped'  => $skipped,  // not-pending, not-in-workspace, or orphan (task_id null)
            'errors'   => $errors,
        ]);
    }

    /**
     * POST /api/approvals/bulk-reject
     * Body: {ids: int[], reason: string}
     */
    public function bulkReject(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return response()->json(['error' => 'reason_required'], 422);
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', (array) $request->input('ids', [])))), 0, 50);
        if (empty($ids)) return response()->json(['error' => 'no_ids'], 422);

        $scoped = DB::table('approvals')->whereIn('id', $ids)->where('workspace_id', $wsId)
            ->where('status', 'pending')->pluck('id', 'task_id')->toArray();
        // pluck(id, task_id) — $scoped keys are task_ids (possibly NULL), values are approval ids.

        // Simpler: re-query with task_id info.
        $rows = DB::table('approvals')->whereIn('id', $ids)->where('workspace_id', $wsId)
            ->where('status', 'pending')->get(['id', 'task_id']);

        $rejected = 0; $failed = 0; $errors = [];
        foreach ($rows as $row) {
            try {
                if ($row->task_id) {
                    $this->service->reject($row->id, (int) $request->user()->id, $reason);
                } else {
                    DB::table('approvals')->where('id', $row->id)->update([
                        'status' => 'rejected', 'decision_by' => $request->user()->id,
                        'decision_note' => $reason, 'decided_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $rejected++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['id' => $row->id, 'error' => $e->getMessage()];
            }
        }
        $skipped = count($ids) - count($rows);

        return response()->json([
            'rejected' => $rejected,
            'failed'   => $failed,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    /**
     * POST /api/approvals/expire-stale
     * Body: {older_than_days?: int = 30}
     * Marks pending approvals older than N days as status=expired.
     */
    public function expireStale(Request $request): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $days = max(1, (int) $request->input('older_than_days', 30));
        $cutoff = now()->subDays($days);
        $note   = "Auto-expired after {$days} days pending";

        $n = DB::table('approvals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->update([
                'status'        => 'expired',
                'decision_note' => $note,
                'decided_at'    => now(),
                'updated_at'    => now(),
            ]);

        return response()->json(['expired' => $n, 'older_than_days' => $days]);
    }

    /** Build the API response shape for a single approval + joined task. */
    private function shapeApproval(object $r): array
    {
        $engine = $r->engine ?: 'system';
        $action = $r->action ?: 'review';
        $payload = $r->payload_json ? json_decode($r->payload_json, true) : null;
        $agents  = $r->assigned_agents_json ? (json_decode($r->assigned_agents_json, true) ?: []) : [];
        $primaryAgentSlug = $agents[0] ?? null;

        $ageHours = (int) Carbon::parse($r->created_at)->diffInHours(now());

        return [
            'id'           => (int) $r->id,
            'status'       => $r->status,
            'created_at'   => $r->created_at,
            'decided_at'   => $r->decided_at,
            'decision_note' => $r->decision_note,
            'age_hours'    => $ageHours,
            'time_ago'     => Carbon::parse($r->created_at)->diffForHumans(),
            'is_overdue'   => $ageHours > 24 && $r->status === 'pending',
            'is_orphan'    => $r->task_id === null,
            'task' => $r->task_id ? [
                'id'            => (int) $r->task_id,
                'engine'        => $engine,
                'action'        => $action,
                'label'         => $this->labelFor($engine, $action, $payload),
                'description'   => $this->descriptionFor($engine, $action, $payload),
                'payload'       => $payload,
                'payload_keys'  => is_array($payload) ? array_slice(array_keys($payload), 0, 8) : [],
                'credit_cost'   => (int) ($r->credit_cost ?? 0),
                'priority'      => $r->priority ?: 'normal',
                'status'        => $r->task_status,
                'assigned_agents' => $agents,
                'primary_agent' => $primaryAgentSlug,
                'agent'         => $this->agentBadge($primaryAgentSlug ?: $this->agentForEngine($engine)['slug']),
                'engine_badge'  => $this->engineBadge($engine),
            ] : null,
        ];
    }

    private function labelFor(string $engine, string $action, ?array $payload): string
    {
        $map = [
            'seo.run_audit'           => 'Run a full SEO audit',
            'seo.deep_audit'          => 'Run technical SEO audit',
            'seo.publish_content'     => 'Publish SEO article',
            'seo.update_keywords'     => 'Update keyword targets',
            'seo.generate_article'    => 'Generate an SEO article',
            'seo.generate_links'      => 'Generate internal-link suggestions',
            'write.publish_article'   => 'Publish article to blog',
            'write.ai_write'          => 'Write article with AI',
            'write.improve_draft'     => 'Improve article draft with AI',
            'social.publish_post'     => 'Publish post to social',
            'social.schedule_post'    => 'Schedule social post',
            'social.create_post'      => 'Create social post',
            'crm.send_outreach'       => 'Send outreach email',
            'crm.create_sequence'     => 'Start email sequence',
            'crm.create_lead'         => 'Create lead',
            'crm.generate_outreach'   => 'Generate outreach email',
            'studio.export_design'    => 'Export design as PNG',
            'studio.publish_social'   => 'Publish design to social',
            'marketing.send_campaign' => 'Send email campaign',
            'marketing.schedule_campaign' => 'Schedule email campaign',
            'builder.publish_website' => 'Publish website live',
            'builder.custom_domain'   => 'Connect custom domain',
            'builder.wizard_generate' => 'Generate a new website',
            'meeting.create_plan'     => 'Execute strategic plan',
            'meeting.end_meeting'     => 'End strategy meeting',
            'creative.generate_image' => 'Generate an AI image',
            'creative.generate_video' => 'Generate an AI video',
        ];
        $k = $engine . '.' . $action;
        return $map[$k] ?? (ucfirst(str_replace('_', ' ', $action)) . ' · ' . $engine);
    }

    private function descriptionFor(string $engine, string $action, ?array $payload): ?string
    {
        if (!is_array($payload)) return null;
        // Pick the most descriptive field if present.
        foreach (['prompt', 'subject', 'title', 'name', 'message', 'content', 'description'] as $k) {
            if (!empty($payload[$k]) && is_string($payload[$k])) {
                $v = trim($payload[$k]);
                return strlen($v) > 140 ? substr($v, 0, 137) . '…' : $v;
            }
        }
        return null;
    }

    private function agentBadge(?string $slug): array
    {
        if (!$slug) return ['name' => 'Sarah', 'slug' => 'sarah', 'color' => '#F59E0B'];
        $row = DB::table('agents')->where('slug', $slug)->first(['slug', 'name', 'color']);
        if ($row) return ['name' => $row->name, 'slug' => $row->slug, 'color' => $row->color ?: '#6C5CE7'];
        return ['name' => ucfirst($slug), 'slug' => $slug, 'color' => '#6C5CE7'];
    }

    private function agentForEngine(string $engine): array
    {
        $map = [
            'seo' => ['slug' => 'james'], 'write' => ['slug' => 'priya'],
            'social' => ['slug' => 'marcus'], 'crm' => ['slug' => 'elena'],
            'studio' => ['slug' => 'marcus'], 'marketing' => ['slug' => 'priya'],
            'builder' => ['slug' => 'sarah'], 'creative' => ['slug' => 'marcus'],
            'meeting' => ['slug' => 'sarah'], 'calendar' => ['slug' => 'elena'],
        ];
        return $map[$engine] ?? ['slug' => 'sarah'];
    }

    private function engineBadge(string $engine): array
    {
        $colors = [
            'seo'       => '#3B82F6',
            'write'     => '#7C3AED',
            'social'    => '#EC4899',
            'crm'       => '#00E5A8',
            'studio'    => '#EC4899',
            'marketing' => '#7C3AED',
            'builder'   => '#00E5A8',
            'creative'  => '#F97316',
            'meeting'   => '#F59E0B',
            'calendar'  => '#00E5A8',
            'system'    => '#8B97B0',
        ];
        return ['name' => $engine, 'color' => $colors[$engine] ?? '#8B97B0'];
    }
}
