<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An auditable platform-administrator appointment (Phase 2B-R1, WS1).
 *
 * APPEND-ONLY. A grant and a later revoke are two rows, never one row edited.
 * "Who was an administrator, and when" must survive offboarding — an editable
 * record cannot answer that after the fact.
 *
 * This does NOT itself grant authority. AdminGovernanceService writes both this
 * record AND the users.is_platform_admin flag inside one transaction, so the
 * flag can never move without a matching audit row.
 */
class AdminAppointment extends Model
{
    protected $table = 'admin_appointments';

    protected $fillable = [
        'appointment_uid', 'user_id', 'appointed_by_user_id', 'action', 'role',
        'environment', 'reason', 'acknowledged_at', 'mfa_enabled_at_appointment',
        'recovery_verified_at_appointment', 'production_eligible', 'effective_at',
        'revoked_at', 'metadata_json',
    ];

    protected $casts = [
        'mfa_enabled_at_appointment'        => 'boolean',
        'recovery_verified_at_appointment'  => 'boolean',
        'production_eligible'               => 'boolean',
        'acknowledged_at'                   => 'datetime',
        'effective_at'                      => 'datetime',
        'revoked_at'                        => 'datetime',
        'metadata_json'                     => 'array',
    ];

    public const ACTION_GRANT  = 'grant';
    public const ACTION_REVOKE = 'revoke';

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            // Acknowledgement is the one legitimate later write: the appointee
            // accepting the role after it was granted.
            $allowed = ['acknowledged_at', 'updated_at'];
            foreach (array_keys($a->getDirty()) as $field) {
                if (!in_array($field, $allowed, true)) {
                    throw new RuntimeException(
                        "AdminAppointment is append-only; '{$field}' may not be edited. "
                        . 'Record a new appointment (grant/revoke) instead.'
                    );
                }
            }
        });

        static::deleting(fn () => throw new RuntimeException(
            'AdminAppointment is permanent governance history and may not be deleted.'
        ));
    }
}
