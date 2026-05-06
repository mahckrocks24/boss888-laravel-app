<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'workspace_id', 'task_id', 'status',
        'decision_by', 'decision_note', 'decided_at', 'engine', 'action', 'data_json',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'data_json' => 'array'];
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
