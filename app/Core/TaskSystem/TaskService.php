<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Models\Approval;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;
use App\Core\EngineKernel\CapabilityMapService;

class TaskService
{
    public function __construct(
        private TaskDispatcher $dispatcher,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private CapabilityMapService $capabilityMap,
        private TaskCategoryService $categoryService,
    ) {}

    /**
     * Create a task. Approval enforcement is MANDATORY.
     *
     * Approval modes (from CapabilityMap):
     *   auto      → dispatch immediately
     *   review    → block until human approves
     *   protected → ALWAYS require approval, no bypass
     */
    public function create(int $workspaceId, array $data): Task
    {
        $action = $data['action'];
        $engine = $data['engine'] ?? $this->resolveEngineSlug($action);

        // LAUNCH SCOPE (2026-07-20) — creation-time choke point (memory-compat +
        // routing safety net). NO source — a promoted/remembered behavior, a saved
        // goal or strategy replay, a task template, a runtime proposal, or a direct
        // caller — may CREATE a task for a launch-removed engine/action, nor route a
        // task to a removed agent. Removed work is refused at creation, so it is
        // never queued, assigned, or shown (not merely refused at execution). The
        // retained blog-article-share passes because its payload/source carry the
        // article-share context marker that LaunchScopePolicy explicitly allows.
        $lsParams  = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if (\App\Core\LaunchScope\LaunchScopePolicy::isRemoved($engine, $action, $lsParams, $data)) {
            \Illuminate\Support\Facades\Log::warning('[LaunchScope] task creation refused', [
                'workspace_id' => $workspaceId, 'engine' => $engine, 'action' => $action,
                'source' => $data['source'] ?? null,
            ]);
            throw new \RuntimeException("LAUNCH_SCOPE: task creation refused for removed capability {$engine}/{$action}");
        }
        // Never route a task to a removed agent (strip; the action itself is allowed).
        if (!empty($data['assigned_agents']) && is_array($data['assigned_agents'])) {
            $data['assigned_agents'] = array_values(array_filter(
                $data['assigned_agents'],
                fn($a) => !(is_string($a) && \App\Core\LaunchScope\LaunchScopePolicy::isRemovedAgent($a))
            ));
        }

        // 2026-05-27 — derive task category from (engine, action). Single
        // source of truth for the taxonomy. Manual override still possible
        // via $data['category']. Validation: invalid categories fall back to
        // the derived value so the column never holds garbage.
        $category = $data['category'] ?? null;
        if (!$category || !$this->categoryService->isValid($category)) {
            $category = $this->categoryService->for($engine, $action);
        }

        // Resolve approval mode from capability map (kept as a safety net /
        // narrower override; category is now the primary authority).
        $approvalMode = $this->capabilityMap->getApprovalMode($action);
        $creditCost = $data['credit_cost'] ?? $this->capabilityMap->getCreditCost($action);

        // 2026-05-27 Phase 4 — category is the primary authority for default
        // approval routing. Per the masterplan's 7-category taxonomy:
        //   - publish   → HARD require approval (going-live safety rail)
        //   - create    → require approval (generative output for the brand)
        //   - crm       → require approval (touches relationships)
        //   - campaign  → require approval (multi-step marketing)
        //   - research  → auto-run (read-only, low risk)
        //   - optimize  → auto-run (incremental tweaks)
        //   - operations → auto-run (system housekeeping)
        //
        // CapabilityMap remains a safety net: if an action is explicitly
        // marked 'protected' (the strictest setting), that still wins so
        // category=research with cap=protected still requires approval.
        // Explicit requires_approval on the caller side also wins.
        $categoryDefault = match ($category) {
            'publish'                              => 'protected',
            'create', 'crm', 'campaign'            => 'review',
            'research', 'optimize', 'operations'   => 'auto',
            default                                => 'review',
        };
        // Pick the MORE restrictive of category-default and cap-map-mode.
        // Strictness: protected > review > auto.
        $strictness = ['auto' => 0, 'review' => 1, 'protected' => 2];
        $effectiveMode = ($strictness[$approvalMode] ?? 1) > ($strictness[$categoryDefault] ?? 0)
            ? $approvalMode
            : $categoryDefault;

        $requiresApproval = match ($effectiveMode) {
            'auto'      => array_key_exists('requires_approval', $data) ? (bool) $data['requires_approval'] : false,
            'review'    => empty($data['auto_approve']) ? ($data['requires_approval'] ?? true) : false,
            'protected' => true,
            default     => true,
        };
        // Publish stays a HARD override regardless of any caller hint — EXCEPT
        // when the caller has already captured an explicit user confirmation for
        // an ARTICLE publish (2026-07-23, Boss decision). Sarah's chat path runs a
        // two-turn confirm and the affirmative is matched server-side from the
        // user's own message, so requiring a second Review-Queue click asked for
        // the same consent twice and left confirmed articles sitting unpublished.
        // Scope is deliberately narrow: `publish_article` only. Website and page
        // publishes, and every other publish-category action, stay hard-gated.
        if ($category === 'publish') {
            $requiresApproval = ! (! empty($data['user_confirmed']) && $action === 'publish_article');
        }

        // Generate idempotency key
        // FIX 2026-04-11: JSON_SORT_KEYS is not a real PHP constant. Use ksort
        // for deterministic hashing (top-level keys only, sufficient for the
        // shallow payloads that engine actions produce).
        $payload = $data['payload'] ?? [];
        $payloadForHash = $payload;
        if (is_array($payloadForHash)) ksort($payloadForHash);
        $idemKey = $data['idempotency_key'] ?? hash('sha256',
            "{$workspaceId}:{$action}:" . json_encode($payloadForHash));

        // v1.4.4 (2026-05-30) — batched approvals. Caller (Sarah's chat loop
        // in routes/api.php) generates one batch_id per (action) group when
        // create_tasks contains multiple entries of the same action. We
        // persist it on the task and use it to fold approvals: only the
        // FIRST task of a (workspace_id, batch_id, action) trio creates an
        // approval row; subsequent tasks just inherit by virtue of sharing
        // the batch_id. The approval+reject endpoints cascade across all
        // sibling tasks via batch_id.
        $batchId = isset($data['batch_id']) ? (string) $data['batch_id'] : null;

        // 2026-07-15 — idempotent guard (dup-as-failure fix). idempotency_key
        // is globally UNIQUE; a repeat (e.g. Sarah emitting two identical tasks
        // in one turn) previously threw a 1062 duplicate-key error that the
        // chat loop logged as "Task creation failed" and reported to the user
        // as a failure. Return the EXISTING task instead — that is the whole
        // point of an idempotency key (repeat request -> same result).
        $existingIdemTask = Task::where('idempotency_key', $idemKey)->first();
        if ($existingIdemTask) {
            return $existingIdemTask;
        }

        $task = Task::create([
            'workspace_id' => $workspaceId,
            'parent_task_id' => $data['parent_task_id'] ?? null,
            'batch_id' => $batchId,
            'engine' => $engine,
            'action' => $action,
            'category' => $category,
            'payload_json' => $payload ?: null,
            'source' => $data['source'] ?? 'manual',
            'assigned_agents_json' => $data['assigned_agents'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'requires_approval' => $requiresApproval,
            'credit_cost' => $creditCost,
            'idempotency_key' => $idemKey,
        ]);

        if ($requiresApproval) {
            $task->update(['approval_status' => 'pending']);

            // Batch-fold: if another pending approval already exists for
            // this workspace + batch_id + action, do NOT create a new one.
            // The user will see ONE row in Command Center for the whole
            // batch; on Approve/Reject the cascade in ApprovalController
            // updates every sibling task by batch_id.
            $existingBatchApproval = null;
            if ($batchId) {
                $existingBatchApproval = Approval::where('workspace_id', $workspaceId)
                    ->where('batch_id', $batchId)
                    ->where('action', $action)
                    ->where('status', 'pending')
                    ->first();
            }

            if (!$existingBatchApproval) {
                // INFRA888 Phase 1D — persist the REQUESTER so the approval
                // layer can enforce separation of duties. Without this the
                // requester is unknown and protected capabilities fail closed.
                $requestedBy = $data['user_id'] ?? ($data['created_by'] ?? null);
                $requesterType = !empty($data['agent_id']) ? 'agent' : ($requestedBy ? 'user' : 'system');

                Approval::create([
                    'workspace_id'         => $workspaceId,
                    'task_id'              => $task->id,
                    'batch_id'             => $batchId,
                    'engine'               => $engine,
                    'action'               => $action,
                    'status'               => 'pending',
                    'requested_by'         => $requestedBy,
                    'requester_actor_type' => $requesterType,
                    'capability_key'       => $engine . '.' . $action,
                    'approval_policy_json' => \App\Core\Governance\ApprovalPolicyRegistry::forCapability(
                        $engine, $action, $approvalMode ?? 'review'
                    ),
                ]);

                $this->notifications->send($workspaceId, 'task', 'task.approval_required', [
                    'task_id'        => $task->id,
                    'engine'         => $task->engine,
                    'action'         => $task->action,
                    'approval_mode'  => $approvalMode,
                    'batch_id'       => $batchId,
                ]);
            }
            // else: batch already has a pending approval — this task just
            // inherits, no new notification (would spam the user with N).
        } else {
            // Auto mode — dispatch immediately
            $this->dispatcher->dispatch($task);
        }

        // 2026-05-27 Phase 5 — include category in audit metadata so post-hoc
        // analytics can filter the audit trail by category without parsing
        // payload JSON. Also surfaces the effective approval mode (which may
        // differ from raw cap-map mode when category overrides applied).
        $this->auditLog->log($workspaceId, null, 'task.created', 'Task', $task->id, [
            'engine'            => $task->engine,
            'action'            => $task->action,
            'category'          => $task->category,
            'source'            => $task->source,
            'approval_mode'     => $approvalMode,
            'effective_mode'    => $effectiveMode,
            'requires_approval' => $requiresApproval,
        ]);

        return $task;
    }

    public function find(int $taskId): ?Task
    {
        return Task::find($taskId);
    }

    public function listForWorkspace(int $workspaceId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Task::where('workspace_id', $workspaceId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        return $query->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get();
    }

    public function markRunning(Task $task): void
    {
        // ENFORCEMENT: verify approval before running
        if ($task->requires_approval && $task->approval_status !== 'approved') {
            throw new \RuntimeException(
                "Task {$task->id} requires approval (current: {$task->approval_status}). Cannot run."
            );
        }

        $task->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(Task $task, ?array $result = null): void
    {
        $task->update([
            'status' => 'completed',
            'result_json' => $result,
            'completed_at' => now(),
            // 2026-06-08 — finalize progress_message on completion. It was set to
            // "Executing step N of M" at dispatch and never cleared, so terminal
            // tasks kept reading as in-progress (root of the phantom "pending
            // tasks" issue where Sarah narrated finished work as current).
            'progress_message' => 'Completed: ' . str_replace('_', ' ', (string) $task->action),
        ]);

        $this->notifications->send($task->workspace_id, 'task', 'task.completed', [
            'task_id' => $task->id,
            'action' => $task->action,
        ]);
    }

    public function markFailed(Task $task, string $error): void
    {
        $task->increment('retry_count');
        $maxRetries = 4;

        if ($task->retry_count < $maxRetries) {
            $task->update(['status' => 'queued']);
            $this->dispatcher->dispatch($task);
        } else {
            $task->update([
                'status' => 'failed',
                'error_text' => $error,
                'completed_at' => now(),
                // 2026-06-08 — finalize progress_message (see markCompleted).
                'progress_message' => 'Failed: ' . str_replace('_', ' ', (string) $task->action),
            ]);
            $this->notifications->send($task->workspace_id, 'task', 'task.failed', [
                'task_id' => $task->id,
                'error' => $error,
            ]);
        }
    }

    private function resolveEngineSlug(string $action): string
    {
        $cap = $this->capabilityMap->resolve($action);
        return $cap['engine'] ?? 'unknown';
    }

    /**
     * Requeue a task with optional delay (for throttling/retry).
     */
    public function requeue(Task $task, int $delaySeconds = 5): void
    {
        $task->update(['status' => 'queued']);
        $this->dispatcher->dispatchWithDelay($task, $delaySeconds);
    }
}
