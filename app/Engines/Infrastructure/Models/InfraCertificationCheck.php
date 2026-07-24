<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\Registry\CertificationChecklistRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One evidence-backed answer to one checklist item (Phase 2B-CERT, WS3).
 *
 * SIX RESULTS, NOT TWO. The three non-obvious ones carry the integrity of the
 * whole framework:
 *
 *   not_tested — we did not assess this. NOT a pass, NOT a failure.
 *   blocked    — we could not assess it (missing scope, missing account).
 *                Distinct from not_tested: someone tried and was stopped.
 *   not_applicable — genuinely does not apply to this capability.
 *
 * Collapsing any of these into `pass` is how a certification framework becomes
 * theatre. `countsAsSatisfied()` therefore treats only pass / pass_with_condition
 * / not_applicable as satisfied — unknown never equals pass.
 *
 * `evidence_is_runtime` separates observation from inference. Documentation that
 * a provider *claims* a capability is not evidence the capability works, and a
 * framework that cannot tell the two apart will eventually certify a provider on
 * the strength of its marketing page.
 */
class InfraCertificationCheck extends Model
{
    protected $table = 'infra_certification_checks';

    protected $fillable = [
        'certification_id', 'domain', 'check_key', 'description', 'result',
        'is_critical', 'evidence_type', 'evidence_reference', 'evidence_json',
        'evidence_is_runtime', 'confidence', 'assessor_user_id', 'assessed_at', 'notes',
    ];

    protected $casts = [
        'is_critical'         => 'boolean',
        'evidence_is_runtime' => 'boolean',
        'evidence_json'       => 'array',
        'assessed_at'         => 'datetime',
    ];

    public const RESULT_PASS           = 'pass';
    public const RESULT_PASS_CONDITION = 'pass_with_condition';
    public const RESULT_FAIL           = 'fail';
    public const RESULT_NOT_APPLICABLE = 'not_applicable';
    public const RESULT_NOT_TESTED     = 'not_tested';
    public const RESULT_BLOCKED        = 'blocked';

    public const EVIDENCE_RUNTIME      = 'runtime';
    public const EVIDENCE_READONLY_API = 'readonly_api';
    public const EVIDENCE_CONFIG       = 'config_snapshot';
    public const EVIDENCE_DATABASE     = 'database';
    public const EVIDENCE_DOCS         = 'documentation';
    public const EVIDENCE_TEST         = 'test_result';
    public const EVIDENCE_AUDIT        = 'audit_event';
    public const EVIDENCE_ATTESTATION  = 'attestation';
    public const EVIDENCE_CONTRACT     = 'contract';
    public const EVIDENCE_PRICING      = 'pricing_source';

    public static function results(): array
    {
        return [
            self::RESULT_PASS, self::RESULT_PASS_CONDITION, self::RESULT_FAIL,
            self::RESULT_NOT_APPLICABLE, self::RESULT_NOT_TESTED, self::RESULT_BLOCKED,
        ];
    }

    /** Evidence types that constitute direct observation of behaviour. */
    public static function runtimeEvidenceTypes(): array
    {
        return [self::EVIDENCE_RUNTIME, self::EVIDENCE_READONLY_API, self::EVIDENCE_TEST];
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(InfraProviderCertification::class, 'certification_id');
    }

    /** Unknown never equals pass. */
    public function countsAsSatisfied(): bool
    {
        return in_array($this->result, [
            self::RESULT_PASS, self::RESULT_PASS_CONDITION, self::RESULT_NOT_APPLICABLE,
        ], true);
    }

    public function isUnsatisfiedCritical(): bool
    {
        return $this->is_critical && !$this->countsAsSatisfied();
    }

    /** A critical check resting on inference rather than observation. */
    public function isWeaklyEvidenced(): bool
    {
        return $this->is_critical
            && $this->countsAsSatisfied()
            && !$this->evidence_is_runtime;
    }

    public function meta(): ?array
    {
        return CertificationChecklistRegistry::get($this->check_key);
    }
}
