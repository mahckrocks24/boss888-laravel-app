<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\Registry\CertificationChecklistRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A governed exception to a checklist item (Phase 2B-CERT, WS4).
 *
 * THE RULE THAT MAKES WAIVERS SAFE: a waiver NEVER converts a failed check into
 * an unconditional pass. The best it can achieve is `pass_with_condition`, which
 * caps the whole certification at `passed_with_conditions`. The compromise stays
 * visible in the verdict forever rather than disappearing into a green tick.
 *
 * And a waiver can never be applied to a CRITICAL ownership or security check at
 * all — see CertificationService::applyWaiver(). Those are the controls where
 * being wrong means losing customer infrastructure; "we accepted the risk" is
 * not an available answer.
 *
 * Expiry is mandatory. A waiver without an end date is not an exception, it is
 * an undocumented policy change.
 */
class InfraCertificationWaiver extends Model
{
    protected $table = 'infra_certification_waivers';

    protected $fillable = [
        'waiver_uid', 'certification_id', 'check_key', 'risk', 'justification',
        'compensating_control', 'owner_user_id', 'approver_user_id', 'approved_at',
        'expires_at', 'review_at', 'affected_capabilities_json',
        'affected_environments_json', 'status', 'revoked_at', 'revoked_reason',
    ];

    protected $casts = [
        'approved_at'                => 'datetime',
        'expires_at'                 => 'datetime',
        'review_at'                  => 'datetime',
        'revoked_at'                 => 'datetime',
        'affected_capabilities_json' => 'array',
        'affected_environments_json' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public function certification(): BelongsTo
    {
        return $this->belongsTo(InfraProviderCertification::class, 'certification_id');
    }

    public function isEffective(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->approver_user_id !== null
            && $this->expires_at->isFuture();
    }

    /** Critical checks can never be waived — enforced again at the service layer. */
    public function targetsCriticalCheck(): bool
    {
        return CertificationChecklistRegistry::isCritical($this->check_key);
    }
}
