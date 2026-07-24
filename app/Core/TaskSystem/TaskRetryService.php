<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-25 — Idempotency-checked retry of blocked tasks.
 *
 * Why this exists: a previous bulk-revive operation re-queued 112 blocked
 * tasks without checking whether the underlying work was already done.
 * ~15 of them were duplicates — wasted LLM tokens and risked overwriting
 * good content on already-completed articles.
 *
 * Every retry through this service runs a target-by-target check FIRST.
 * If the engine action's end state already exists on the target entity
 * (article has meta_title, featured_image_url, aeo_enriched_at, content
 * with internal links, etc.), the task is marked cancelled and SKIPPED.
 * Only tasks whose work is genuinely incomplete get requeued.
 *
 * Used by:
 *   - POST /api/tasks/{id}/retry (single, user-triggered from Pipeline UI)
 *   - POST /api/tasks/retry-blocked (batch, user-triggered)
 *   - Orchestrator dispatch tasks/retry_blocked (agent-triggered)
 */
class TaskRetryService
{
    public function __construct(private TaskService $taskService) {}

    /**
     * Retry a single blocked task. Returns one of:
     *   ['action' => 'requeued',         'task_id' => N, 'delay_sec' => N]
     *   ['action' => 'skipped_duplicate','task_id' => N, 'reason' => '...']
     *   ['action' => 'not_blocked',      'task_id' => N, 'status' => '<current>']
     *   ['action' => 'error',            'task_id' => N, 'error' => '...']
     */
    public function retryOne(int $taskId, ?string $triggeredBy = null): array
    {
        $task = Task::find($taskId);
        if (!$task) {
            return ['action' => 'error', 'task_id' => $taskId, 'error' => 'not_found'];
        }
        if ($task->status !== 'blocked') {
            return [
                'action'   => 'not_blocked',
                'task_id'  => $taskId,
                'status'   => $task->status,
                'message'  => "Task is {$task->status}, retry only applies to 'blocked'",
            ];
        }

        // Idempotency check — if the work is already done on the target,
        // mark task cancelled and skip.
        $dup = $this->detectDuplicate($task);
        if ($dup !== null) {
            DB::table('tasks')->where('id', $taskId)->update([
                'status'           => 'cancelled',
                'progress_message' => 'Skipped duplicate retry: ' . $dup,
                'updated_at'       => now(),
            ]);
            DB::table('audit_logs')->insert([
                'workspace_id' => $task->workspace_id,
                'action'       => 'task.retry.skipped_duplicate',
                'entity_type'  => 'Task',
                'entity_id'    => $taskId,
                'metadata_json'=> json_encode([
                    'action_slug'   => $task->action,
                    'reason'        => $dup,
                    'triggered_by'  => $triggeredBy,
                ]),
                'created_at'   => now(),
            ]);
            return ['action' => 'skipped_duplicate', 'task_id' => $taskId, 'reason' => $dup];
        }

        // Genuine retry — requeue with short delay.
        try {
            $this->taskService->requeue($task, mt_rand(2, 8));
            DB::table('audit_logs')->insert([
                'workspace_id' => $task->workspace_id,
                'action'       => 'task.retry.requeued',
                'entity_type'  => 'Task',
                'entity_id'    => $taskId,
                'metadata_json'=> json_encode([
                    'action_slug'  => $task->action,
                    'prior_msg'    => $task->progress_message,
                    'triggered_by' => $triggeredBy,
                ]),
                'created_at'   => now(),
            ]);
            return ['action' => 'requeued', 'task_id' => $taskId];
        } catch (\Throwable $e) {
            Log::warning('[TaskRetryService] requeue failed', [
                'task_id' => $taskId, 'error' => $e->getMessage(),
            ]);
            return ['action' => 'error', 'task_id' => $taskId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Batch retry — all blocked tasks for a workspace (or scoped subset).
     * Returns aggregate counts + per-task verdict array.
     */
    public function retryBlockedForWorkspace(int $wsId, ?string $triggeredBy = null, ?array $taskIds = null): array
    {
        $q = Task::where('workspace_id', $wsId)->where('status', 'blocked');
        if (is_array($taskIds) && !empty($taskIds)) {
            $q->whereIn('id', $taskIds);
        }
        $tasks = $q->get();

        $requeued = 0; $skipped = 0; $errors = 0;
        $results = [];
        foreach ($tasks as $t) {
            $r = $this->retryOne($t->id, $triggeredBy);
            $results[] = $r;
            match ($r['action']) {
                'requeued'           => $requeued++,
                'skipped_duplicate'  => $skipped++,
                default              => $errors++,
            };
        }

        return [
            'success'   => true,
            'summary'   => [
                'total_inspected'  => count($tasks),
                'requeued'         => $requeued,
                'skipped_duplicate'=> $skipped,
                'errors'           => $errors,
            ],
            'results'   => $results,
        ];
    }

    /**
     * Detect whether the task's intended work is already done on its
     * target entity. Returns a reason string if duplicate, null if not.
     *
     * Action-by-action checks. Conservative — when uncertain, returns
     * null (= allow retry). Better to waste a few cycles than overwrite
     * good content.
     */
    private function detectDuplicate(Task $task): ?string
    {
        $action = (string) $task->action;
        $wsId   = (int)    $task->workspace_id;

        // Resolve article_id — for chain children, look up parent's result.
        $articleId = null;
        $payload = is_array($task->payload_json)
            ? $task->payload_json
            : (json_decode($task->payload_json ?? '{}', true) ?: []);
        if (!empty($payload['article_id'])) {
            $articleId = (int) $payload['article_id'];
        } elseif ($task->parent_task_id) {
            $parent = DB::table('tasks')->where('id', $task->parent_task_id)->first(['result_json']);
            if ($parent) {
                $pr = is_string($parent->result_json) ? json_decode($parent->result_json, true) : ($parent->result_json ?: []);
                $articleId = $pr['data']['article_id'] ?? $pr['article_id'] ?? null;
            }
        }

        $article = $articleId
            ? DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->first()
            : null;

        switch ($action) {
            case 'generate_meta':
                if ($article && !empty($article->meta_title)) {
                    return "article #{$article->id} already has meta_title";
                }
                break;

            case 'generate_image':
            case 'generate_image_mini':
            case 'generate_image_high':
                if ($article && !empty($article->featured_image_url)) {
                    return "article #{$article->id} already has featured_image_url";
                }
                break;

            case 'aeo_enrich':
                if ($article && !empty($article->aeo_enriched_at)) {
                    return "article #{$article->id} already aeo_enriched_at {$article->aeo_enriched_at}";
                }
                break;

            case 'insert_link':
                if ($article && is_string($article->content) && strpos($article->content, '</a>') !== false) {
                    return "article #{$article->id} content already contains internal links";
                }
                break;

            case 'add_keyword':
                // Only safe to dedup if the exact keyword is already tracked
                $kw = $payload['keyword'] ?? null;
                if ($kw) {
                    $exists = DB::table('seo_keywords')
                        ->where('workspace_id', $wsId)
                        ->whereRaw('LOWER(keyword) = LOWER(?)', [$kw])
                        ->exists();
                    if ($exists) return "keyword \"{$kw}\" already tracked";
                }
                break;

            // write_article, link_suggestions, deep_audit, etc.:
            // their outputs are new artifacts; safe to re-run if blocked.
            // (link_suggestions = generates suggestions, doesn't mutate page)
        }
        return null;
    }
}
