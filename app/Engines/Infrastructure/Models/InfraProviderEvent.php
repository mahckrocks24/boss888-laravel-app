<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only provider control-plane history (Phase 2B-9).
 *
 * NOT workspace-scoped, unlike InfraEvent — see the migration docblock for why
 * platform operations must not be filed under a tenant.
 *
 * IMMUTABILITY IS ENFORCED, NOT DOCUMENTED. Update and delete both throw. A
 * correction is a NEW event referencing the original by correlation_id, because
 * a history that can be rewritten is not evidence of anything.
 */
class InfraProviderEvent extends Model
{
    protected $table = 'infra_provider_events';

    public $timestamps = false;

    protected $fillable = [
        'event_uid', 'provider_id', 'provider_key', 'capability', 'environment',
        'event_type', 'severity', 'actor_type', 'actor_user_id', 'actor_label',
        'from_state', 'to_state', 'correlation_id', 'operation_id',
        'credential_id', 'summary', 'metadata_json', 'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at'    => 'datetime',
    ];

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_SUCCESS  = 'success';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_ERROR    = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    public const ACTOR_USER      = 'user';
    public const ACTOR_SYSTEM    = 'system';
    public const ACTOR_SCHEDULER = 'scheduler';
    public const ACTOR_WORKER    = 'worker';
    public const ACTOR_API       = 'api';

    // ── Registry ──────────────────────────────────────────────────────────
    public const PROVIDER_REGISTERED   = 'provider_registered';
    public const PROVIDER_UPDATED      = 'provider_updated';
    public const PROVIDER_ENABLED      = 'provider_enabled';
    public const PROVIDER_DISABLED     = 'provider_disabled';
    public const PROVIDER_STATE_CHANGED = 'provider_state_changed';
    public const CAPABILITY_DECLARED   = 'capability_declared';
    public const CAPABILITY_ENABLED    = 'capability_enabled';
    public const CAPABILITY_DISABLED   = 'capability_disabled';

    // ── Credentials ───────────────────────────────────────────────────────
    public const CREDENTIAL_CREATED           = 'credential_created';
    public const CREDENTIAL_VERIFIED          = 'credential_verified';
    public const CREDENTIAL_VERIFICATION_FAILED = 'credential_verification_failed';
    public const CREDENTIAL_ACTIVATED         = 'credential_activated';
    public const CREDENTIAL_ROTATION_STARTED  = 'credential_rotation_started';
    public const CREDENTIAL_ROTATED           = 'credential_rotated';
    public const CREDENTIAL_REVOKED           = 'credential_revoked';
    public const CREDENTIAL_EXPIRING          = 'credential_expiring';
    public const CREDENTIAL_EXPIRED           = 'credential_expired';

    // ── Health ────────────────────────────────────────────────────────────
    public const HEALTH_CHANGED         = 'provider_health_changed';
    public const PROVIDER_DEGRADED      = 'provider_degraded';
    public const PROVIDER_RECOVERED     = 'provider_recovered';
    public const AUTHENTICATION_FAILED  = 'provider_authentication_failed';
    public const QUOTA_WARNING          = 'provider_quota_warning';
    public const QUOTA_EXHAUSTED        = 'provider_quota_exhausted';
    public const RATE_LIMITED           = 'provider_rate_limited';
    public const MAINTENANCE_STARTED    = 'provider_maintenance_started';
    public const MAINTENANCE_ENDED      = 'provider_maintenance_ended';

    // ── Governance ────────────────────────────────────────────────────────
    public const PERMISSION_DENIED = 'provider_permission_denied';

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException(
            'InfraProviderEvent is append-only. Record a correcting event instead.'
        ));
        static::deleting(fn () => throw new RuntimeException(
            'InfraProviderEvent is append-only and may not be deleted.'
        ));
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }
}
