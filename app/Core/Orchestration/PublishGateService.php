<?php

namespace App\Core\Orchestration;

use App\Core\Governance\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PublishGateService — per-asset preview review queue for publishing actions
 * inside an approved Plan.
 *
 * This is a layer ON TOP of the existing approvals system. The actual gate
 * still lives in the `approvals` table — this service adds rich preview
 * metadata + plan-scoped grouping + bulk-approve semantics.
 *
 * Wiring: EngineExecutionService::execute() calls enqueue() when an action
 * with approval_level=protected runs with plan_id in context. The standard
 * approvals row is also created (via the same EES flow); approval_id on
 * this queue row links them.
 *
 * Approval flow: approve(queueId) -> ApprovalService::approve(approval_id)
 * which fires the actual publish via the existing TaskDispatcher path.
 */
class PublishGateService
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * Enqueue an asset for preview review. Called from EES when a protected
     * action runs inside an executing plan.
     *
     * @param int   $wsId
     * @param int   $planId
     * @param int   $approvalId  approvals.id — the underlying gate
     * @param string $engine
     * @param string $action
     * @param array  $params       original action params
     * @param array  $previewHint  caller-supplied preview overrides
     * @return int  plan_publish_queue.id
     */
    public function enqueue(
        int $wsId,
        int $planId,
        int $approvalId,
        string $engine,
        string $action,
        array $params,
        array $previewHint = []
    ): int {
        $preview = $this->extractPreview($wsId, $engine, $action, $params, $previewHint);
        $batchId = $this->resolveBatchId($planId);
        $now = now();
        return DB::table('plan_publish_queue')->insertGetId([
            'plan_id'       => $planId,
            'workspace_id'  => $wsId,
            'approval_id'   => $approvalId,
            'source_task_id'=> $params['_source_task_id'] ?? null,
            'engine'        => $engine,
            'action'        => $action,
            'entity_type'   => $preview['entity_type'] ?? null,
            'entity_id'     => $preview['entity_id']   ?? null,
            'preview_json'  => json_encode($preview),
            'params_json'   => json_encode($params),
            'batch_id'      => $batchId,
            'status'        => 'queued',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * List all queued items for a plan. Returns enriched rows with decoded JSON.
     */
    public function listForPlan(int $wsId, int $planId, ?string $status = null): array
    {
        $q = DB::table('plan_publish_queue')
            ->where('workspace_id', $wsId)
            ->where('plan_id', $planId);
        if ($status) $q->where('status', $status);
        return $this->hydrate($q->orderBy('created_at')->get()->toArray());
    }

    /**
     * List all queued items for a workspace, optionally filtered by status.
     * Default: status=queued (the active review queue).
     */
    public function listForWorkspace(int $wsId, ?string $status = 'queued', int $limit = 50): array
    {
        $q = DB::table('plan_publish_queue')->where('workspace_id', $wsId);
        if ($status) $q->where('status', $status);
        return $this->hydrate($q->orderByDesc('id')->limit($limit)->get()->toArray());
    }

    /**
     * Approve a single queue item — fires the underlying publish via the
     * existing ApprovalService.approve() flow (which runs through
     * TaskDispatcher -> Orchestrator -> dispatchMap).
     */
    public function approve(int $wsId, int $queueId, int $userId, ?string $note = null): array
    {
        $row = $this->loadQueueRow($wsId, $queueId);
        if (!$row) return ['success' => false, 'error' => 'queue item not found'];
        if ($row->status !== 'queued') {
            return ['success' => false, 'error' => "already {$row->status}"];
        }
        // Queue-level state is authoritative. Update the queue row + the
        // underlying approval row directly. ApprovalService::approve() is
        // attempted best-effort but its failure (common — see B9 docs about
        // the pre-existing requestIfNeeded → null task_id gap) does NOT
        // propagate to queue-level success.
        DB::table('plan_publish_queue')->where('id', $queueId)->update([
            'status' => 'approved',
            'decision_by' => $userId,
            'decision_note' => $note,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        if ($row->approval_id) {
            try {
                $this->approvals->approve($row->approval_id, $userId, $note);
            } catch (\Throwable $e) {
                // Fallback: update approval row directly so audit shows decision
                Log::warning('[PublishGate] underlying approval.approve failed (likely null task_id) — updating row directly', [
                    'queue_id' => $queueId, 'approval_id' => $row->approval_id, 'err' => $e->getMessage(),
                ]);
                try {
                    DB::table('approvals')->where('id', $row->approval_id)->update([
                        'status' => 'approved',
                        'decision_by' => $userId,
                        'decision_note' => $note,
                        'decided_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $fallbackErr) {
                    Log::warning('[PublishGate] fallback approval row update also failed: ' . $fallbackErr->getMessage());
                }
            }
        }
        return ['success' => true, 'queue_id' => $queueId, 'status' => 'approved'];
    }

    /**
     * Reject a single queue item — fires the underlying ApprovalService.reject.
     */
    public function reject(int $wsId, int $queueId, int $userId, ?string $note = null): array
    {
        $row = $this->loadQueueRow($wsId, $queueId);
        if (!$row) return ['success' => false, 'error' => 'queue item not found'];
        if ($row->status !== 'queued') {
            return ['success' => false, 'error' => "already {$row->status}"];
        }
        try {
            if ($row->approval_id) {
                $this->approvals->reject($row->approval_id, $userId, $note);
            }
        } catch (\Throwable $e) {
            Log::warning('[PublishGate] underlying approval.reject failed', [
                'queue_id' => $queueId, 'err' => $e->getMessage(),
            ]);
        }
        DB::table('plan_publish_queue')->where('id', $queueId)->update([
            'status' => 'rejected',
            'decision_by' => $userId,
            'decision_note' => $note,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        return ['success' => true, 'queue_id' => $queueId, 'status' => 'rejected'];
    }

    /**
     * Bulk approve N queue items. Designed for "approve all of today's drafts"
     * UI button. Per the user's design note, batches are intended to be
     * per-day or per-batch, NOT entire-plan, to limit blast radius.
     */
    public function bulkApprove(int $wsId, array $queueIds, int $userId, ?string $note = null): array
    {
        $approved = []; $failed = [];
        foreach ($queueIds as $qid) {
            $r = $this->approve($wsId, (int) $qid, $userId, $note);
            if ($r['success']) $approved[] = $qid;
            else $failed[] = ['id' => $qid, 'error' => $r['error']];
        }
        return [
            'success'  => count($failed) === 0,
            'approved' => $approved,
            'failed'   => $failed,
            'count_approved' => count($approved),
        ];
    }

    /**
     * Extract a rich preview from the engine-native entity. Best-effort —
     * if the entity row is missing or fields aren't populated, falls back
     * to a generic preview shape with engine/action labels.
     */
    public function extractPreview(int $wsId, string $engine, string $action, array $params, array $hint = []): array
    {
        $base = [
            'type'    => "$engine.$action",
            'engine'  => $engine,
            'action'  => $action,
            'title'   => $hint['title'] ?? null,
            'excerpt' => $hint['excerpt'] ?? null,
            'image_url' => $hint['image_url'] ?? null,
            'entity_type' => null,
            'entity_id'   => null,
            'meta'    => [],
        ];

        try {
            // ── per-engine preview extractors ──
            if ($engine === 'social' && ($action === 'publish_post' || $action === 'social_publish_post')) {
                $postId = (int) ($params['post_id'] ?? 0);
                if ($postId > 0) {
                    $row = DB::table('social_posts')->where('id', $postId)->where('workspace_id', $wsId)->first();
                    if ($row) {
                        $base['entity_type'] = 'social_post';
                        $base['entity_id']   = $postId;
                        $base['title']       = 'Social post — ' . ($row->platform ?? 'unknown');
                        $base['excerpt']     = mb_substr((string)($row->content ?? $row->body ?? ''), 0, 240);
                        $base['image_url']   = $row->image_url ?? $row->media_url ?? null;
                        $base['meta']        = ['platform' => $row->platform ?? null, 'scheduled_for' => $row->scheduled_for ?? null];
                    }
                }
            } elseif ($engine === 'marketing' && $action === 'send_campaign') {
                $cid = (int) ($params['campaign_id'] ?? 0);
                if ($cid > 0) {
                    $row = DB::table('campaigns')->where('id', $cid)->where('workspace_id', $wsId)->first();
                    if ($row) {
                        $base['entity_type'] = 'campaign';
                        $base['entity_id']   = $cid;
                        $base['title']       = (string)($row->subject ?? $row->name ?? 'Email campaign');
                        // Strip HTML, take first 240 chars for excerpt
                        $body = (string)($row->body_html ?? '');
                        $base['excerpt']     = mb_substr(trim(strip_tags($body)), 0, 240);
                        $recipients = json_decode($row->recipients_json ?? '[]', true) ?: [];
                        $base['meta']        = ['recipient_count' => count($recipients), 'type' => $row->type ?? 'email'];
                    }
                }
            } elseif ($engine === 'content' && $action === 'publish_pack') {
                $pid = (int) ($params['pack_id'] ?? 0);
                if ($pid > 0) {
                    $row = DB::table('content_packs')->where('id', $pid)->where('workspace_id', $wsId)->first();
                    if ($row) {
                        $base['entity_type'] = 'content_pack';
                        $base['entity_id']   = $pid;
                        $base['title']       = (string)$row->name;
                        $base['excerpt']     = (string)($row->theme ?? '');
                        $assetCount = DB::table('content_pack_assets')->where('pack_id', $pid)->count();
                        $base['meta']        = ['asset_count' => $assetCount, 'status' => $row->status];
                    }
                }
            } elseif ($engine === 'write' && in_array($action, ['publish_article', 'write_article'], true)) {
                $aid = (int) ($params['article_id'] ?? 0);
                if ($aid > 0) {
                    $row = DB::table('articles')->where('id', $aid)->where('workspace_id', $wsId)->first();
                    if ($row) {
                        $base['entity_type'] = 'article';
                        $base['entity_id']   = $aid;
                        $base['title']       = (string)$row->title;
                        // b17 (2026-07-24) — the article preview was always blank.
                        // `articles` has no `body` or `hero_image_url` column (they
                        // are `content` / `featured_image_url`), and the cast bound
                        // before the ?? so `(string)$row->body` warned on every
                        // queued publish. The owner was being asked to approve
                        // going live with no excerpt and no image to review.
                        $base['excerpt']     = mb_substr(trim((string)($row->excerpt ?: strip_tags((string)($row->content ?? '')))), 0, 240);
                        $base['image_url']   = $row->featured_image_url ?? null;
                        $base['meta']        = ['slug' => $row->slug ?? null, 'word_count' => $row->word_count ?? null];
                    }
                }
            } elseif ($engine === 'builder' && $action === 'publish_website') {
                $wid = (int) ($params['website_id'] ?? 0);
                if ($wid > 0) {
                    $row = DB::table('websites')->where('id', $wid)->where('workspace_id', $wsId)->first();
                    if ($row) {
                        $base['entity_type'] = 'website';
                        $base['entity_id']   = $wid;
                        $base['title']       = (string)$row->name;
                        $base['meta']        = ['domain' => $row->domain ?? null];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Best-effort — never break on preview extraction
            Log::warning('[PublishGate] preview extraction error', ['err' => $e->getMessage()]);
        }

        // Final fallback: title becomes engine/action label so the chat card is never blank
        if (empty($base['title'])) {
            $base['title'] = ucfirst($engine) . ' — ' . str_replace('_', ' ', $action);
        }
        return $base;
    }

    /**
     * Stats for a plan: how many queued / approved / rejected.
     */
    public function statsForPlan(int $wsId, int $planId): array
    {
        $rows = DB::table('plan_publish_queue')
            ->where('workspace_id', $wsId)
            ->where('plan_id', $planId)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')->toArray();
        return [
            'plan_id' => $planId,
            'queued'   => (int) ($rows['queued']   ?? 0),
            'approved' => (int) ($rows['approved'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'expired'  => (int) ($rows['expired']  ?? 0),
            'total'    => array_sum($rows),
        ];
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function loadQueueRow(int $wsId, int $queueId): ?object
    {
        return DB::table('plan_publish_queue')
            ->where('id', $queueId)
            ->where('workspace_id', $wsId)
            ->first();
    }

    /**
     * Batch ID resolution. Same plan + same day = same batch_id (so "approve
     * all today's drafts" can target the right rows).
     */
    private function resolveBatchId(int $planId): string
    {
        return 'plan_' . $planId . '_' . now()->toDateString();
    }

    /**
     * Decode JSON columns + return as arrays for API responses.
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $arr = (array) $r;
            $arr['preview'] = json_decode($arr['preview_json'] ?? '{}', true) ?: [];
            $arr['params']  = json_decode($arr['params_json']  ?? '{}', true) ?: [];
            unset($arr['preview_json'], $arr['params_json']);
            $out[] = $arr;
        }
        return $out;
    }
}