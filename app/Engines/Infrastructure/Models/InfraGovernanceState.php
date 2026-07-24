<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform governance state machine (Phase 2B-R3, WS4).
 *
 * A single logical row (id=1). Three modes:
 *
 *   bootstrap   no governance yet. Step-up self-disables so the second admin can
 *               be enrolled. The ONLY mode in which self-disable is legitimate.
 *   activated   two MFA admins have existed. Step-up is enforced PERMANENTLY.
 *               Dropping below the bar now FAILS CLOSED — privileged ops are
 *               blocked, not silently allowed.
 *   recovery    an explicit, audited, time-boxed break-glass state entered by a
 *               human to restore governance after it degraded. Never automatic.
 *
 * `activated_at`, once set, is never cleared. Its presence is the durable fact
 * that governance was activated — the anti-fail-open anchor.
 */
class InfraGovernanceState extends Model
{
    protected $table = 'infra_governance_state';

    protected $fillable = [
        'mode', 'activated_at', 'activated_by_user_id', 'recovery_entered_at',
        'recovery_by_user_id', 'recovery_reason', 'recovery_expires_at', 'metadata_json',
    ];

    protected $casts = [
        'activated_at'        => 'datetime',
        'recovery_entered_at' => 'datetime',
        'recovery_expires_at' => 'datetime',
        'metadata_json'       => 'array',
    ];

    public const MODE_BOOTSTRAP = 'bootstrap';
    public const MODE_ACTIVATED = 'activated';
    public const MODE_RECOVERY  = 'recovery';
    // Phase 3A — Stage 1 single-operator production governance.
    public const MODE_FOUNDER   = 'founder';

    /** The one row. Created by migration; this is a safety net if absent. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['mode' => self::MODE_BOOTSTRAP]);
    }

    /** Governance has been activated at some point and never un-activated. */
    public function wasEverActivated(): bool
    {
        return $this->activated_at !== null;
    }

    public function inRecovery(): bool
    {
        return $this->mode === self::MODE_RECOVERY
            && $this->recovery_expires_at !== null
            && $this->recovery_expires_at->isFuture();
    }

    public function isFounderMode(): bool
    {
        return $this->mode === self::MODE_FOUNDER;
    }
}
