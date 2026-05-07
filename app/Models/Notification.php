<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        // Legacy (Phase 0) — preserved for backward compat
        'workspace_id', 'channel', 'type', 'data_json', 'read_at',
        // V2 (2026-05-07) — added by extend_notifications_table_v2 migration
        'user_id', 'category', 'title', 'body', 'action_url',
        'icon', 'severity', 'emailed_at', 'email_required',
    ];

    protected function casts(): array
    {
        return [
            'data_json'      => 'array',
            'read_at'        => 'datetime',
            'emailed_at'     => 'datetime',
            'email_required' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $q): Builder
    {
        return $q->whereNull('read_at');
    }

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    public function scopeForWorkspace(Builder $q, int $workspaceId): Builder
    {
        return $q->where('workspace_id', $workspaceId);
    }
}
