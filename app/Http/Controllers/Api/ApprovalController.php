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
                'a.id', 'a.task_id', 'a.proposal_id', 'a.status', 'a.created_at',
                'a.decided_at', 'a.decision_by', 'a.decision_note',
                'a.data_json as a_data_json',
                't.engine', 't.action', 't.category as task_category', 't.payload_json',
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
        // P0-B (2026-08-30, REPORT-0023 UX-022): only the customer's own decisions — system expiries
        // carry a decided_at too and made "your average response" a fiction.
        $avgRow = $base()
            ->whereNotNull('decided_at')
            ->whereIn('status', ['approved', 'rejected'])
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
        $row = DB::table('approvals')->where('id', $id)->first(['id', 'workspace_id', 'task_id', 'proposal_id', 'status', 'batch_id', 'action', 'engine', 'requested_by']);

        if (!$row) return response()->json(['error' => 'approval_not_found'], 404);
        if ((int) $row->workspace_id !== $wsId) return response()->json(['error' => 'approval_not_found'], 404);

        // INFRA888 Phase 1D — central approval authorization.
        // Previously this checked ONLY workspace + pending state: no role check
        // and no self-approval prevention, so a viewer could approve a protected
        // action and a requester could approve their own request.
        $authz = app(\App\Core\Governance\ApprovalAuthorizationService::class)->authorize($row, $request);

        if (!$authz['allowed']) {
            try {
                app(\App\Core\Audit\AuditLogService::class)->log(
                    $wsId, $request->user()?->id, 'approval.denied', 'approval', (int) $row->id,
                    ['code' => $authz['code'], 'capability' => $authz['policy']['capability_key'] ?? null,
                     'role' => $authz['role'], 'requested_by' => $row->requested_by ?? null]
                );
            } catch (\Throwable $e) { /* audit must never block a denial */ }

            return response()->json(['error' => $authz['message'], 'code' => $authz['code']], 403);
        }

        // Idempotent: already approved
        if ($row->status === 'approved') {
            return response()->json(['success' => true, 'message' => 'already approved', 'approval_id' => $id]);
        }
        if ($row->status !== 'pending') {
            return response()->json(['error' => 'not_pending', 'status' => $row->status], 409);
        }

        // 2026-06-30 — proposal-linked approval: delegate to the proactive engine,
        // which reserves credits + creates and runs the task(s), then mark the
        // mirrored approval row approved.
        if (!empty($row->proposal_id)) {
            try {
                $res = app(\App\Core\Orchestration\ProactiveStrategyEngine::class)
                    ->approveProposal($wsId, (int) $request->user()->id, (int) $row->proposal_id);
                if (($res['success'] ?? false) === false) {
                    return response()->json($res, ($res['code'] ?? '') === 'NO_CREDITS' ? 402 : 422);
                }
                DB::table('approvals')->where('id', $id)->update([
                    'status'      => 'approved',
                    'decision_by' => $request->user()->id,
                    'decided_at'  => now(),
                    'updated_at'  => now(),
                ]);
                return response()->json(array_merge(['success' => true, 'approval_id' => $id], $res));
            } catch (\Throwable $e) {
                return response()->json(['error' => 'proposal_approve_failed', 'message' => $e->getMessage()], 500);
            }
        }

        if (!$row->task_id) {
            return response()->json(['error' => 'orphan_approval', 'hint' => 'This approval has no attached task. Expire it instead.'], 422);
        }

        try {
            $approval = $this->service->approve($id, (int) $request->user()->id, $request->input('note'));

            // v1.4.4 (2026-05-30) — batched approvals cascade.
            // If this approval represents a batch, mark every sibling
            // task in the same batch as approved + push them to the queue.
            // The single approval row covered all of them.
            $cascaded = 0;
            if ($row->batch_id) {
                $taskAction = $row->action ?? null;
                $siblings = \App\Models\Task::where('workspace_id', $wsId)
                    ->where('batch_id', $row->batch_id)
                    ->when($taskAction, fn ($q) => $q->where('action', $taskAction))
                    ->where('approval_status', 'pending')
                    // MISSION-018 WS-1 (2026-08-24, RISK-0020): the cascade
                    // matched on batch_id + action + approval_status only, with
                    // NO task-status filter, then set status='pending' and
                    // dispatched. A task that ran and FAILED keeps
                    // approval_status='pending' (handleTaskFailure never clears
                    // it), so approving an unrelated sibling later could
                    // re-queue and RE-RUN failed work. Only genuinely-waiting
                    // tasks may be revived — never a terminal or in-flight one.
                    ->whereNotIn('status', ['completed', 'failed', 'cancelled', 'degraded', 'running', 'verifying'])
                    ->where('id', '!=', $row->task_id)  // first task already handled by service
                    ->get();
                foreach ($siblings as $sib) {
                    try {
                        $sib->update(['approval_status' => 'approved', 'status' => 'pending']);
                        $cascaded++;
                        // Dispatch — service already dispatched the first sibling
                        // via approve(); for the rest we replicate that step.
                        try {
                            app(\App\Core\TaskSystem\TaskDispatcher::class)->dispatch($sib);
                        } catch (\Throwable $dispErr) {
                            \Illuminate\Support\Facades\Log::warning('[ApprovalController] batch sibling dispatch failed', [
                                'task_id' => $sib->id, 'err' => $dispErr->getMessage(),
                            ]);
                        }
                    } catch (\Throwable $sibErr) {
                        \Illuminate\Support\Facades\Log::warning('[ApprovalController] batch sibling approve failed', [
                            'task_id' => $sib->id, 'err' => $sibErr->getMessage(),
                        ]);
                    }
                }
            }
            return response()->json([
                'success'  => true,
                'message'  => $cascaded > 0
                    ? "Approved — {$cascaded} sibling task" . ($cascaded === 1 ? '' : 's') . " in this batch will also proceed."
                    : 'Approved — the task will proceed.',
                'approval' => $approval,
                'cascaded' => $cascaded,
            ]);
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

        $row = DB::table('approvals')->where('id', $id)->first(['id', 'workspace_id', 'task_id', 'proposal_id', 'status', 'batch_id', 'action', 'engine', 'requested_by']);
        if (!$row) return response()->json(['error' => 'approval_not_found'], 404);
        if ((int) $row->workspace_id !== $wsId) return response()->json(['error' => 'approval_not_found'], 404);

        // INFRA888 Phase 1D — central approval authorization.
        // Previously this checked ONLY workspace + pending state: no role check
        // and no self-approval prevention, so a viewer could approve a protected
        // action and a requester could approve their own request.
        $authz = app(\App\Core\Governance\ApprovalAuthorizationService::class)->authorize($row, $request);

        if (!$authz['allowed']) {
            try {
                app(\App\Core\Audit\AuditLogService::class)->log(
                    $wsId, $request->user()?->id, 'approval.denied', 'approval', (int) $row->id,
                    ['code' => $authz['code'], 'capability' => $authz['policy']['capability_key'] ?? null,
                     'role' => $authz['role'], 'requested_by' => $row->requested_by ?? null]
                );
            } catch (\Throwable $e) { /* audit must never block a denial */ }

            return response()->json(['error' => $authz['message'], 'code' => $authz['code']], 403);
        }

        if ($row->status === 'rejected') {
            return response()->json(['success' => true, 'message' => 'already rejected', 'approval_id' => $id]);
        }
        if ($row->status !== 'pending') {
            return response()->json(['error' => 'not_pending', 'status' => $row->status], 409);
        }

        // 2026-06-30 — proposal-linked approval: decline the proposal (no credits)
        // and mark the mirrored approval row rejected.
        if (!empty($row->proposal_id)) {
            try {
                app(\App\Core\Orchestration\ProactiveStrategyEngine::class)
                    ->declineProposal($wsId, (int) $row->proposal_id);
            } catch (\Throwable $e) { /* still mark the approval rejected below */ }
            DB::table('approvals')->where('id', $id)->update([
                'status'        => 'rejected',
                'decision_by'   => $request->user()->id,
                'decision_note' => $reason,
                'decided_at'    => now(),
                'updated_at'    => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Declined.']);
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

            // v1.4.4 (2026-05-30) — batched approvals cascade.
            // Cancel every sibling task that shared this batch_id.
            $cascaded = 0;
            if ($row->batch_id) {
                $taskAction = $row->action ?? null;
                $siblings = \App\Models\Task::where('workspace_id', $wsId)
                    ->where('batch_id', $row->batch_id)
                    ->when($taskAction, fn ($q) => $q->where('action', $taskAction))
                    ->where('approval_status', 'pending')
                    // MISSION-018 WS-1 (2026-08-24, RISK-0020): same guard as the
                    // approve cascade — the reject cascade also filtered only on
                    // batch_id + action + approval_status, so it would overwrite
                    // a task that had already COMPLETED or FAILED to
                    // status='cancelled', corrupting a terminal state. Touch
                    // only tasks still genuinely awaiting a decision.
                    ->whereNotIn('status', ['completed', 'failed', 'cancelled', 'degraded', 'running', 'verifying'])
                    ->where('id', '!=', $row->task_id)
                    ->get();
                foreach ($siblings as $sib) {
                    try {
                        $sib->update([
                            'approval_status' => 'rejected',
                            'status'          => 'cancelled',
                            'error_text'      => 'Rejected via batch approval: ' . $reason,
                            'cancelled_at'    => now(),
                        ]);
                        $cascaded++;
                    } catch (\Throwable $sibErr) {
                        \Illuminate\Support\Facades\Log::warning('[ApprovalController] batch sibling reject failed', [
                            'task_id' => $sib->id, 'err' => $sibErr->getMessage(),
                        ]);
                    }
                }
            }
            return response()->json([
                'success' => true,
                'message' => $cascaded > 0
                    ? "Rejected — {$cascaded} sibling task" . ($cascaded === 1 ? '' : 's') . " in this batch were also cancelled."
                    : 'Rejected — the task was cancelled.',
                'approval' => $approval,
                'cascaded' => $cascaded,
            ]);
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
        // 2026-06-30 — proposal-linked approval (Sarah's daily/weekly/monthly
        // recommendation mirrored into the queue). No task yet; render from the
        // proposal's display data so it shows as a normal, approvable card.
        if (!empty($r->proposal_id ?? null)) {
            return $this->shapeProposalApproval($r);
        }

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
                // 2026-05-27 — surface meeting context when present so the Review Queue
                // can highlight tasks that originated from a Strategy Room synthesis.
                'from_meeting'  => $this->meetingContext($payload),
                // 2026-05-27 — surface category for the chip + filter in the Review Queue
                'category'        => $this->categoryFor($r, $engine, $action),
                'category_label'  => $this->categoryMeta($r, $engine, $action)['label'],
                'category_color'  => $this->categoryMeta($r, $engine, $action)['color'],
            ] : null,
        ];
    }

    /**
     * 2026-06-30 — shape a proposal-linked approval for the Command Center.
     * Display data comes from approvals.data_json (mirrored at sync time); the
     * card renders like a normal approval with Sarah + credit cost, approve
     * enabled (is_orphan=false). Approve/reject route to the proposal engine.
     */
    private function shapeProposalApproval(object $r): array
    {
        $d = [];
        if (!empty($r->a_data_json)) {
            $d = is_string($r->a_data_json) ? (json_decode($r->a_data_json, true) ?: []) : (array) $r->a_data_json;
        }
        $agentSlug = $d['agent'] ?? 'sarah';
        $ageHours  = (int) Carbon::parse($r->created_at)->diffInHours(now());
        // P2-U1b (2026-08-30): a chat proposal carries the exact creation payload it will run. Surface the real
        // engine/action/payload so the customer reads "Publish an article" (not "Recommended action") and can
        // open the very article/post/lead the approval is about.
        $cp = is_array($d['create_payload'] ?? null) ? $d['create_payload'] : [];
        $cpEngine  = (string) ($cp['engine'] ?? '');
        $cpAction  = (string) ($cp['action'] ?? '');
        $cpPayload = is_array($cp['payload'] ?? null) ? $cp['payload'] : null;
        if ($cpEngine !== '' && $cpAction !== '') {
            $agents = is_array($cp['assigned_agents'] ?? null) && !empty($cp['assigned_agents']) ? $cp['assigned_agents'] : [$agentSlug];
            $primary = $agents[0] ?? $agentSlug;
            return [
                'id'            => (int) $r->id,
                'kind'          => 'proposal',
                'status'        => $r->status,
                'created_at'    => $r->created_at,
                'decided_at'    => $r->decided_at,
                'decision_note' => $r->decision_note,
                'age_hours'     => $ageHours,
                'time_ago'      => Carbon::parse($r->created_at)->diffForHumans(),
                'is_overdue'    => $ageHours > 24 && $r->status === 'pending',
                'is_orphan'     => false,
                'task' => [
                    'id'              => 0,
                    'engine'          => $cpEngine,
                    'action'          => $cpAction,
                    'label'           => $this->labelFor($cpEngine, $cpAction, $cpPayload),
                    'description'     => $this->descriptionFor($cpEngine, $cpAction, $cpPayload),
                    'payload'         => $cpPayload,
                    'payload_keys'    => is_array($cpPayload) ? array_slice(array_keys($cpPayload), 0, 8) : [],
                    'credit_cost'     => (int) ($d['total_credits'] ?? ($cp['credit_cost'] ?? 0)),
                    'priority'        => (string) ($cp['priority'] ?? 'normal'),
                    'status'          => 'pending',
                    'assigned_agents' => $agents,
                    'primary_agent'   => $primary,
                    'agent'           => $this->agentBadge($primary),
                    'engine_badge'    => $this->engineBadge($cpEngine),
                    'from_meeting'    => null,
                    'category'        => 'proposal',
                    'category_label'  => 'Sarah proposed',
                    'category_color'  => '#F59E0B',
                ],
            ];
        }

        return [
            'id'            => (int) $r->id,
            'kind'          => 'proposal',
            'status'        => $r->status,
            'created_at'    => $r->created_at,
            'decided_at'    => $r->decided_at,
            'decision_note' => $r->decision_note,
            'age_hours'     => $ageHours,
            'time_ago'      => Carbon::parse($r->created_at)->diffForHumans(),
            'is_overdue'    => $ageHours > 24 && $r->status === 'pending',
            'is_orphan'     => false,
            'task' => [
                'id'              => 0,
                'engine'          => 'strategy',
                'action'          => $d['type'] ?? 'proposal',
                'label'           => $d['title'] ?? 'Recommended action',
                'description'     => $d['description'] ?? '',
                'payload'         => null,
                'payload_keys'    => [],
                'credit_cost'     => (int) ($d['total_credits'] ?? 0),
                'priority'        => 'normal',
                'status'          => 'pending',
                'assigned_agents' => [$agentSlug],
                'primary_agent'   => $agentSlug,
                'agent'           => $this->agentBadge($agentSlug),
                'engine_badge'    => $this->engineBadge('strategy'),
                'from_meeting'    => null,
                'category'        => 'strategy',
                'category_label'  => 'Strategy',
                'category_color'  => '#F59E0B',
            ],
        ];
    }

    /**
     * Resolve the task's category — prefers the persisted column value (set
     * by TaskService::create) then falls back to live derivation for any
     * legacy row whose backfill missed.
     */
    private function categoryFor(object $r, string $engine, string $action): string
    {
        if (!empty($r->task_category)) return $r->task_category;
        return app(\App\Core\TaskSystem\TaskCategoryService::class)->for($engine, $action);
    }

    private function categoryMeta(object $r, string $engine, string $action): array
    {
        return app(\App\Core\TaskSystem\TaskCategoryService::class)
            ->metadata($this->categoryFor($r, $engine, $action));
    }

    /**
     * If a task's payload carries a from_meeting ID, look up minimal context
     * (id + title) so the Review Queue can show a "From meeting #N — title" chip
     * without an extra round trip. Returns null when not meeting-sourced.
     */
    private function meetingContext(?array $payload): ?array
    {
        if (!is_array($payload) || empty($payload['from_meeting'])) return null;
        $meetingId = (int) $payload['from_meeting'];
        if ($meetingId <= 0) return null;
        static $cache = [];
        if (isset($cache[$meetingId])) return $cache[$meetingId];
        $row = \Illuminate\Support\Facades\DB::table('meetings')
            ->where('id', $meetingId)
            ->first(['id', 'title', 'status']);
        if (!$row) return $cache[$meetingId] = null;
        return $cache[$meetingId] = [
            'id'     => (int) $row->id,
            'title'  => $row->title,
            'status' => $row->status,
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
        // W6: see DashboardController::agentForSlug - raw reads bypass the scope.
        return \App\Core\LaunchScope\AgentDirectory::resolve($slug);
    }

    private function agentForEngine(string $engine): array
    {
        // LAUNCH SCOPE 2026-07-20 — removed-agent badges (marcus) replaced with
        // honest non-person tool labels. Studio/creative are direct user tools;
        // social is the retained article-share service. Nothing here re-attributes
        // removed work to a retained specialist.
        $map = [
            'seo' => ['slug' => 'james'], 'write' => ['slug' => 'priya'],
            'social' => ['slug' => 'system', 'name' => 'Article share'], 'crm' => ['slug' => 'elena'],
            'studio' => ['slug' => 'studio', 'name' => 'Studio'], 'marketing' => ['slug' => 'sarah'],
            'builder' => ['slug' => 'sarah'], 'creative' => ['slug' => 'studio', 'name' => 'Studio'],
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
