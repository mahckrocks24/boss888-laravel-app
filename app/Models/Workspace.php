<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    protected $fillable = [
        'name', 'slug', 'settings_json', 'created_by',
        // Onboarding (migration 200003)
        'business_name', 'industry', 'services_json', 'goal', 'location', 'onboarded', 'onboarded_at',
        // Proactive settings (D3 migration)
        'proactive_enabled', 'proactive_frequency',
        // Trial (D5 migration)
        'trial_started_at', 'trial_credits',
        // Onboarding v2 (step 1–2 runbook, 2026-04-25)
        'brand_color', 'logo_url', 'onboarding_step', 'onboarding_data',
    ];

    protected function casts(): array
    {
        return [
            'settings_json'    => 'array',
            'services_json'    => 'array',
            'onboarded'        => 'boolean',
            'onboarded_at'     => 'datetime',
            'proactive_enabled'=> 'boolean',
            'trial_started_at' => 'datetime',
            'trial_credits'    => 'integer',
            'onboarding_step'  => 'integer',
            'onboarding_data'  => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function credits(): HasOne
    {
        return $this->hasOne(Credit::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'workspace_agents')
            ->withPivot('custom_name', 'custom_avatar', 'enabled')
            ->withTimestamps();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function designTokens(): HasOne
    {
        return $this->hasOne(DesignToken::class);
    }

    public function memory(): HasMany
    {
        return $this->hasMany(WorkspaceMemory::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
