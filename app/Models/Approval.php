<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'workspace_id', 'task_id', 'status',
        'decision_by', 'decision_note', 'decided_at', 'engine', 'action', 'data_json',
        // v1.4.4 (2026-05-30) — batched approvals
        'batch_id',
        // INFRA888 Phase 1D — separation of duties. Without these in $fillable,
        // mass assignment silently DROPS them and every protected approval fails
        // closed with 'requester unverifiable'.
        'requested_by', 'requester_actor_type', 'capability_key',
        'approval_policy_json', 'decision_actor_type',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'data_json' => 'array',
            'approval_policy_json' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }
}
