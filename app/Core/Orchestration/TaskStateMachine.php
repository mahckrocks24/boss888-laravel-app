<?php

namespace App\Core\Orchestration;

use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * TaskStateMachine — validates `tasks.status` transitions and stamps the
 * supporting timestamp / duration columns. Runs ALONGSIDE the existing
 * Orchestrator (which already drives most state changes); this class adds
 * a guarded entrypoint plus orphan recovery.
 *
 * Spec deviations:
 *   - Operates on the existing 10-value `status` enum, not a new `state`
 *     column. Every state in the original spec maps to an existing enum
 *     value (created -> pending; dispatched -> queued post-dispatch;
 *     approved -> queued; retrying -> running; orphaned -> failed).
 *   - Uses `retry_count` (already in schema) for the attempt counter.
 *
 * Patched 2026-05-10 (Phase 2C — task state machine).
 */
class TaskStateMachine
{
    /**
     * Allowed transitions on the existing tasks.status enum.
     * Empty array = terminal state.
     */
    private const TRANSITIONS = [
        'pending'           => ['queued', 'awaiting_approval', 'cancelled', 'failed'],
        'awaiting_approval' => ['queued', 'cancelled', 'failed'],
        'queued'            => ['running', 'cancelled', 'failed', 'blocked', 'degraded'],
        'running'           => ['verifying', 'completed', 'failed', 'queued', 'blocked', 'degraded'],
        'verifying'         => ['completed', 'failed'],
        'blocked'           => ['queued', 'cancelled', 'failed'],
        'degraded'          => ['queued', 'failed', 'cancelled'],
        'completed'         => [],
        'failed'            => ['queued'],   // retry path
        'cancelled'         => [],
    ];

    /**
     * Validate + apply a transition. Returns the new status string on
     * success, or null if rejected. Stamps the matching timestamp column
     * and computes duration_ms on completion.
     */
    public function transition(int $taskId, string $toStatus, array $meta = []): ?string
    {
        $task = DB::table('tasks')->where('id', $taskId)->first();
        if (!$task) {
            Log::warning("TaskStateMachine: task {$taskId} not found");
            return null;
        }

        $from = (string)($task->status ?? 'pending');
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (!in_array($toStatus, $allowed, true)) {
            Log::warning("TaskStateMachine: invalid transition {$from} -> {$toStatus} for task {$taskId}");
            return null;
        }

        $update = [
            'status'     => $toStatus,
            'updated_at' => now(),
        ];

        switch ($toStatus) {
            case 'queued':
                if (empty($task->queued_at)) $update['queued_at'] = now();
                break;
            case 'running':
                if (empty($task->started_at))    $update['started_at'] = now();
                if (empty($task->dispatched_at)) $update['dispatched_at'] = now();
                break;
            case 'completed':
                $update['completed_at'] = now();
                if (!empty($task->started_at)) {
                    $started = Carbon::parse($task->started_at);
                    $update['duration_ms'] = max(0, (int) (now()->getPreciseTimestamp(3) - $started->getPreciseTimestamp(3)));
                }
                break;
            case 'failed':
                $update['failed_at'] = now();
                break;
            case 'cancelled':
                $update['cancelled_at'] = now();
                break;
        }

        if (array_key_exists('error', $meta) && $meta['error'] !== null) {
            $update['error_text'] = mb_substr((string)$meta['error'], 0, 60000);
        }
        if (array_key_exists('error_trace', $meta) && $meta['error_trace'] !== null) {
            $update['error_trace'] = (string)$meta['error_trace'];
        }
        if (array_key_exists('result', $meta) && is_array($meta['result'])) {
            $update['result_json'] = json_encode($meta['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('progress_message', $meta)) {
            $update['progress_message'] = mb_substr((string)$meta['progress_message'], 0, 500);
        }

        // retry_count bump on the explicit retry path: failed -> queued.
        if ($from === 'failed' && $toStatus === 'queued') {
            $update['retry_count'] = (int)($task->retry_count ?? 0) + 1;
        }

        DB::table('tasks')->where('id', $taskId)->update($update);

        Log::info("TaskStateMachine: task {$taskId} {$from} -> {$toStatus}", [
            'task_id'  => $taskId,
            'duration' => $update['duration_ms'] ?? null,
        ]);

        return $toStatus;
    }

    /**
     * Tasks stuck in `running` / `queued` longer than $minutes are likely
     * orphaned (worker crashed, redis flushed, machine bounced).
     */
    public function detectOrphans(int $minutes = 30): Collection
    {
        return DB::table('tasks')
            ->whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();
    }

    /**
     * Mark stale running/queued tasks as failed with a clear reason. The
     * scheduler calls this every 15 minutes.
     */
    public function recoverOrphans(int $minutes = 30): int
    {
        $orphans = $this->detectOrphans($minutes);
        $recovered = 0;
        foreach ($orphans as $row) {
            // Run through the normal validated path so timestamps + log line stay consistent.
            $next = $row->status === 'queued' ? 'failed' : 'failed';
            $applied = $this->transition((int)$row->id, $next, [
                'error' => "Auto-recovered: stuck in {$row->status} > {$minutes} min",
                'progress_message' => "orphan reaper @ " . now()->toDateTimeString(),
            ]);
            if ($applied !== null) $recovered++;
        }
        if ($recovered > 0) {
            Log::info("TaskStateMachine::recoverOrphans recovered {$recovered} task(s)");
        }
        return $recovered;
    }

    /**
     * For tooling / observability surfaces.
     */
    public function getTransitions(): array
    {
        return self::TRANSITIONS;
    }
}
