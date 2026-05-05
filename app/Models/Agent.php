<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Agent extends Model
{
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
