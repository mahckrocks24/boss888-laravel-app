<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\States\CertificationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A provider certification verdict (Phase 2B-CERT).
 *
 * IMMUTABLE ONCE CONCLUDED. A certification in a terminal status may not be
 * edited — recertification creates a new row via `supersedes_id`. "Was this
 * provider certified when we activated it?" is asked after an incident, and an
 * editable answer is not an answer.
 */
class InfraProviderCertification extends Model
{
    protected $table = 'infra_provider_certifications';

    protected $fillable = [
        'certification_uid', 'provider_id', 'provider_key', 'capability', 'environment',
        'adapter_version', 'provider_api_version', 'status', 'level', 'level_target',
        'started_at', 'completed_at', 'expires_at', 'recertify_after',
        'assessor_user_id', 'assessor_label', 'supersedes_id', 'superseded_by_id',
        'checks_total', 'checks_passed', 'checks_failed', 'checks_blocked',
        'checks_not_tested', 'waivers_active', 'blockers_json', 'summary_json', 'notes',
    ];

    protected $casts = [
        'level'             => 'integer',
        'level_target'      => 'integer',
        'checks_total'      => 'integer',
        'checks_passed'     => 'integer',
        'checks_failed'     => 'integer',
        'checks_blocked'    => 'integer',
        'checks_not_tested' => 'integer',
        'waivers_active'    => 'integer',
        'blockers_json'     => 'array',
        'summary_json'      => 'array',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'expires_at'        => 'datetime',
        'recertify_after'   => 'datetime',
    ];

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_IN_REVIEW  = 'in_review';
    public const STATUS_PASSED     = 'passed';
    public const STATUS_CONDITIONS = 'passed_with_conditions';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_EXPIRED    = 'expired';
    public const STATUS_REVOKED    = 'revoked';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_IN_REVIEW, self::STATUS_PASSED,
            self::STATUS_CONDITIONS, self::STATUS_FAILED, self::STATUS_EXPIRED,
            self::STATUS_REVOKED,
        ];
    }

    /** Statuses after which the verdict is history and must not change. */
    public static function terminalStatuses(): array
    {
        return [self::STATUS_PASSED, self::STATUS_CONDITIONS, self::STATUS_FAILED,
                self::STATUS_EXPIRED, self::STATUS_REVOKED];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cert) {
            $original = $cert->getOriginal('status');

            if (!in_array($original, self::terminalStatuses(), true)) {
                return; // still being assessed
            }

            // Expiry/revocation are legitimate later transitions; a concluded
            // certification may lapse. Everything else is frozen.
            $allowed = ['status', 'superseded_by_id', 'updated_at', 'expires_at'];

            foreach (array_keys($cert->getDirty()) as $field) {
                if (!in_array($field, $allowed, true)) {
                    throw new RuntimeException(
                        "Certification {$cert->id} is concluded ('{$original}'). Field "
                        . "'{$field}' may not be edited — recertify instead, so the "
                        . 'original verdict survives.'
                    );
                }
            }
        });

        static::deleting(fn () => throw new RuntimeException(
            'Certifications are permanent history and may not be deleted.'
        ));
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(InfraCertificationCheck::class, 'certification_id');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(InfraCertificationWaiver::class, 'certification_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** Currently valid — concluded favourably, not expired, not superseded. */
    public function isCurrent(): bool
    {
        if (!in_array($this->status, [self::STATUS_PASSED, self::STATUS_CONDITIONS], true)) {
            return false;
        }
        if ($this->superseded_by_id !== null) {
            return false;
        }

        return !$this->expires_at || $this->expires_at->isFuture();
    }

    /** May this certification make a provider production-selectable? */
    public function permitsProduction(): bool
    {
        return $this->isCurrent()
            && CertificationLevel::permitsProductionSelection($this->level);
    }

    public function levelLabel(): string
    {
        return CertificationLevel::label($this->level);
    }
}
