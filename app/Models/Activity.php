<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    protected $fillable = [
        'workspace_id', 'activitable_type', 'activitable_id',
        'type', 'description', 'metadata_json', 'performed_by',
        'subject', 'scheduled_at', 'completed', 'completed_at', // CRM-FIX-0: were silently dropped
    ];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
