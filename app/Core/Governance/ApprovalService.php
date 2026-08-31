<?php

namespace App\Core\Governance;

use App\Models\Approval;
use App\Models\Task;
use App\Core\TaskSystem\TaskDispatcher;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;

class ApprovalService
{
    public function __construct(
        private TaskDispatcher $dispatcher,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
    ) {}

    public function listPending(int $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return Approval::where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->with('task')
            ->orderByDesc('created_at')
            ->get();
    }

    /** Decisions already made are never silently re-made. P1R-5 (2026-08-31). */
    private const TERMINAL = ['approved', 'rejected', 'expired', 'cancelled'];

    public function approve(int $approvalId, int $userId, ?string $note = null): Approval
    {
        $approval = Approval::findOrFail($approvalId);
        if (in_array((string) $approval->status, self::TERMINAL, true)) {
            if ((string) $approval->status === 'approved') {
                return $approval;   // idempotent: no re-dispatch, original decision kept
            }
            throw new \DomainException("This request was already {$approval->status} and cannot be approved. Ask for it again to run it.");
        }
        $approval->update([
            'status' => 'approved',
            'decision_by' => $userId,
            'decision_note' => $note,
            'decided_at' => now(),
        ]);

        $task = $approval->task;
        $task->update(['approval_status' => 'approved']);
        $this->dispatcher->dispatch($task);

        $this->auditLog->log($approval->workspace_id, $userId, 'approval.approved', 'Approval', $approvalId);
        $this->notifications->send($approval->workspace_id, 'task', 'approval.approved', ['task_id' => $task->id]);

        return $approval;
    }

    public function reject(int $approvalId, int $userId, ?string $note = null): Approval
    {
        $approval = Approval::findOrFail($approvalId);
        if (in_array((string) $approval->status, self::TERMINAL, true)) {
            if ((string) $approval->status === 'rejected') {
                return $approval;   // idempotent: the first reason and timestamp stand
            }
            throw new \DomainException("This request was already {$approval->status} and cannot be rejected now.");
        }
        $approval->update([
            'status' => 'rejected',
            'decision_by' => $userId,
            'decision_note' => $note,
            'decided_at' => now(),
        ]);

        $approval->task->update(['approval_status' => 'rejected', 'status' => 'failed', 'error_text' => 'Rejected: ' . ($note ?? 'No reason given')]);

        $this->auditLog->log($approval->workspace_id, $userId, 'approval.rejected', 'Approval', $approvalId);

        return $approval;
    }

    public function revise(int $approvalId, int $userId, ?string $note = null): Approval
    {
        $approval = Approval::findOrFail($approvalId);
        $approval->update([
            'status' => 'revised',
            'decision_by' => $userId,
            'decision_note' => $note,
            'decided_at' => now(),
        ]);

        // b21 (2026-07-24) — approve()/reject() and both bulk paths guard for a
        // null task; revise() was the one that did not, so revising one of the
        // task-less approval rows dereferenced null. Fail with a clear message
        // instead of a 500 the caller has to decode.
        $task = $approval->task;
        if (! $task) {
            throw new \RuntimeException(
                'This approval has no attached task and cannot be revised — reject or expire it instead.'
            );
        }
        $task->update(['approval_status' => 'revised', 'status' => 'pending']);

        // Create new pending approval for revised task
        Approval::create([
            'workspace_id' => $approval->workspace_id,
            'task_id' => $task->id,
            'status' => 'pending',
        ]);

        $this->auditLog->log($approval->workspace_id, $userId, 'approval.revised', 'Approval', $approvalId);

        return $approval;
    }



    /**
     * Check if approval is needed for an action.
     * Returns null if auto-approved, or the Approval record if pending.
     */
    public function requestIfNeeded(int $workspaceId, string $engine, string $action, string $approvalMode, array $data = []): ?Approval
    {
        if ($approvalMode === 'auto') {
            return null;
        }

        return Approval::create([
            'workspace_id' => $workspaceId,
            'engine' => $engine,
            'action' => $action,
            'status' => 'pending',
            'data_json' => json_encode($data),
        ]);
    }

}
