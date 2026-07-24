<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first-class incident (Phase 3B) — the one incident model the whole platform
 * uses. Elevates 3A's lightweight monitor_incidents; future provider integrations
 * reuse this, not a per-provider variant.
 *
 * Full lifecycle: detected → acknowledged → investigating → mitigated → resolved
 * → closed. Every transition is recorded in infra_incident_transitions
 * (append-only, actor-attributed, workspace-isolated).
 */
class InfraIncident extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_incidents';

    protected $fillable = [
        'incident_uid', 'workspace_id', 'asset_id', 'title', 'severity',
        'lifecycle_state', 'source', 'source_ref', 'cause', 'impact_summary',
        'detected_at', 'acknowledged_at', 'mitigated_at', 'resolved_at', 'closed_at',
        'acknowledged_by', 'resolved_by', 'time_to_resolve_seconds', 'metadata_json',
    ];

    protected $casts = [
        'detected_at'             => 'datetime',
        'acknowledged_at'         => 'datetime',
        'mitigated_at'            => 'datetime',
        'resolved_at'             => 'datetime',
        'closed_at'               => 'datetime',
        'time_to_resolve_seconds' => 'integer',
        'metadata_json'           => 'array',
    ];

    public const STATE_DETECTED      = 'detected';
    public const STATE_ACKNOWLEDGED  = 'acknowledged';
    public const STATE_INVESTIGATING = 'investigating';
    public const STATE_MITIGATED     = 'mitigated';
    public const STATE_RESOLVED      = 'resolved';
    public const STATE_CLOSED        = 'closed';

    public const SEV_CRITICAL = 'critical';
    public const SEV_MAJOR    = 'major';
    public const SEV_MINOR    = 'minor';
    public const SEV_WARNING  = 'warning';

    /** Legal forward transitions. Enforced by IncidentService. */
    public static function transitions(): array
    {
        return [
            self::STATE_DETECTED      => [self::STATE_ACKNOWLEDGED, self::STATE_INVESTIGATING, self::STATE_RESOLVED],
            self::STATE_ACKNOWLEDGED  => [self::STATE_INVESTIGATING, self::STATE_MITIGATED, self::STATE_RESOLVED],
            self::STATE_INVESTIGATING => [self::STATE_MITIGATED, self::STATE_RESOLVED],
            self::STATE_MITIGATED     => [self::STATE_RESOLVED, self::STATE_INVESTIGATING],
            self::STATE_RESOLVED      => [self::STATE_CLOSED, self::STATE_INVESTIGATING],
            self::STATE_CLOSED        => [],
        ];
    }

    public static function openStates(): array
    {
        return [self::STATE_DETECTED, self::STATE_ACKNOWLEDGED, self::STATE_INVESTIGATING, self::STATE_MITIGATED];
    }

    public function isOpen(): bool
    {
        return in_array($this->lifecycle_state, self::openStates(), true);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InfraAsset::class, 'asset_id');
    }

    public function transitionsHistory(): HasMany
    {
        return $this->hasMany(InfraIncidentTransition::class, 'incident_id');
    }
}
