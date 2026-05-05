<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskEvent;

class TaskProgressService
{
    /**
     * Record a progress event on a task.
     */
    public function recordEvent(
        int $taskId,
        string $event,
        ?string $status = null,
        int $step = 0,
        ?string $connector = null,
        ?string $action = null,
        ?string $message = null,
        ?array $data = null,
    ): TaskEvent {
        return TaskEvent::create([
            'task_id' => $taskId,
            'event' => $event,
            'status' => $status,
            'step' => $step,
            'connector' => $connector,
            'action' => $action,
            'message' => $message,
            'data_json' => $data,
        ]);
    }

    /**
     * Update task progress fields.
     */
    public function updateProgress(Task $task, int $currentStep, int $totalSteps, string $message): void
    {
        $task->update([
            'current_step' => $currentStep,
            'total_steps' => $totalSteps,
            'progress_message' => $message,
        ]);
    }

    /**
     * Get latest task state for UI/API polling.
     */
    public function getStatus(int $taskId): ?array
    {
        $task = Task::find($taskId);
        if (! $task) {
            return null;
        }

        $totalSteps = max($task->total_steps, 1);
        $progressPercent = $task->status === 'completed' ? 100
            : (int) round(($task->current_step / $totalSteps) * 100);

        return [
            'task_id' => $task->id,
            'status' => $task->status,
            'approval_status' => $task->approval_status,
            'current_step' => $task->current_step,
            'total_steps' => $task->total_steps,
            'progress_percent' => $progressPercent,
            'latest_message' => $task->progress_message,
            'engine' => $task->engine,
            'action' => $task->action,
            'started_at' => $task->execution_started_at?->toIso8601String(),
            'finished_at' => $task->execution_finished_at?->toIso8601String(),
            'error' => $task->error_text,
        ];
    }

    /**
     * Get all events for a task (timeline).
     */
    public function getEvents(int $taskId): array
    {
        return TaskEvent::where('task_id', $taskId)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }
}
