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
        'business_id', 'lead_id', // CRM-DATA-1
    ];

    /** CRM-DATA-1: every timeline row on a client carries the lead and its business, whatever wrote it. */
    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (! $a->lead_id && in_array($a->activitable_type, ['Lead', 'App\\Models\\Lead'], true)) $a->lead_id = $a->activitable_id;
            if ($a->lead_id && ! $a->business_id) $a->business_id = \Illuminate\Support\Facades\DB::table('leads')->where('id', $a->lead_id)->value('business_id');
        });
    }

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
