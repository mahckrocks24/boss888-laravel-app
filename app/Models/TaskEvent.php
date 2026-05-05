<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'event', 'status', 'step',
        'connector', 'action', 'message', 'data_json',
    ];

    protected function casts(): array
    {
        return [
            'data_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
