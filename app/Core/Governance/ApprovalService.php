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

    public function approve(int $approvalId, int $userId, ?string $note = null): Approval
    {
        $approval = Approval::findOrFail($approvalId);
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

        $task = $approval->task;
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
