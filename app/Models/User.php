<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasFactory;

    // RISK-0122 — is_platform_admin is NOT mass-assignable; set it only via forceFill in the
    // platform-admin-gated appoint/revoke paths (AdminGovernanceService / AdminController).
    protected $fillable = ['name', 'email', 'password', 'avatar', 'status', 'account_classification'];

    protected $hidden = ['password', 'mfa_secret_encrypted', 'mfa_recovery_codes_encrypted'];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'is_platform_admin'  => 'boolean',
            // Phase 2B-R1 MFA. The TOTP seed is encrypted at rest; there is
            // no plaintext column. Recovery codes are stored bcrypt-hashed.
            'mfa_enabled'          => 'boolean',
            'mfa_secret_encrypted' => 'encrypted',
            'mfa_confirmed_at'     => 'datetime',
            'mfa_last_verified_at' => 'datetime',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'created_by');
    }
}
