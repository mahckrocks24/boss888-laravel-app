<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * STUDIO888 Phase I — canonical, OBSERVATIONAL Studio domain record.
 * Never an execution authority; see CreativeJobService.
 */
class CreativeJob extends Model
{
    protected $fillable = [
        'uuid', 'workspace_id', 'user_id', 'task_id', 'asset_id', 'parent_job_id',
        'type', 'capability', 'provider', 'provider_model', 'status',
        'original_prompt', 'compiled_prompt', 'generation_spec', 'edit_spec',
        'provider_request', 'provider_response', 'metadata',
        'started_at', 'completed_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'generation_spec'   => 'array',
            'edit_spec'         => 'array',
            'provider_request'  => 'array',
            'provider_response' => 'array',
            'metadata'          => 'array',
            'started_at'        => 'datetime',
            'completed_at'      => 'datetime',
            'failed_at'         => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
    public function task(): BelongsTo      { return $this->belongsTo(Task::class); }
    public function asset(): BelongsTo     { return $this->belongsTo(Asset::class); }
    public function parent(): BelongsTo    { return $this->belongsTo(self::class, 'parent_job_id'); }
    public function children(): HasMany    { return $this->hasMany(self::class, 'parent_job_id'); }
}
