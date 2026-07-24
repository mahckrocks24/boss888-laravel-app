<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Agent extends Model
{
    /**
     * LAUNCH SCOPE (2026-07-20) — removed social/email agents are excluded
     * from EVERY Agent query by default (rosters, meeting candidates, admin,
     * workspace-state, mobile). This is the authoritative roster gate; it
     * cannot be bypassed by a forgotten endpoint. Use Agent::withRemovedAgents()
     * only for historical attribution lookups that must resolve a legacy slug.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('launch_scope', function (\Illuminate\Database\Eloquent\Builder $q) {
            $q->whereNotIn('slug', \App\Core\LaunchScope\LaunchScopePolicy::REMOVED_AGENTS);
        });
    }

    /** Escape hatch for historical attribution — includes removed agents. */
    public static function withRemovedAgents(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->withoutGlobalScope('launch_scope');
    }

    protected $fillable = [
        'slug', 'name', 'title', 'description', 'personality', 'avatar_url',
        'role', 'category', 'level', 'orb_type', 'color',
        'capabilities_json', 'skills_json', 'is_dmm', 'status',
    ];

    protected function casts(): array
    {
        return [
            'capabilities_json' => 'array',
            'skills_json' => 'array',
            'is_dmm' => 'boolean',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_agents')
            ->withPivot('custom_name', 'custom_avatar', 'enabled')
            ->withTimestamps();
    }

    public function isDMM(): bool
    {
        return $this->is_dmm || $this->slug === 'sarah';
    }
}
