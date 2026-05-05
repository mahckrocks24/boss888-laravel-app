<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    protected $fillable = [
        'workspace_id', 'engine', 'action', 'payload_json', 'status',
        'requires_approval', 'approval_status', 'source',
        'assigned_agents_json', 'priority', 'retry_count',
        'result_json', 'error_text', 'started_at', 'completed_at',
        'parent_task_id', 'plan_task_id', 'credit_cost',
        // Phase 3
        'idempotency_key', 'execution_hash',
        'execution_started_at', 'execution_finished_at',
        'current_step', 'total_steps', 'progress_message',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'assigned_agents_json' => 'array',
            'result_json' => 'array',
            'requires_approval' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'execution_finished_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(Approval::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderBy('created_at');
    }
}
