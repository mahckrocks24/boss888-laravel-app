<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraCertificationCheck;
use App\Engines\Infrastructure\Models\InfraCertificationWaiver;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCertification;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Registry\CertificationChecklistRegistry as Checklist;
use App\Engines\Infrastructure\States\CertificationLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provider certification (Phase 2B-CERT).
 *
 * THE ONE RULE EVERYTHING ELSE SERVES: a level is AWARDED FROM EVIDENCE, never
 * asserted. `conclude()` computes the level from the checks actually recorded
 * and will award LESS than the target without complaint. There is no parameter
 * that sets a level directly, because a certification framework whose verdict
 * can be typed in by the assessor certifies nothing.
 */
class ProviderCertificationService
{
    /** Recertification windows by level. Higher trust expires sooner. */
    private const VALIDITY_DAYS = [
        CertificationLevel::REGISTERED          => 3650,
        CertificationLevel::READ_ONLY_VERIFIED  => 180,
        CertificationLevel::SANDBOX_VALIDATED   => 180,
        CertificationLevel::STAGING_CERTIFIED   => 365,
        CertificationLevel::PRODUCTION_APPROVED => 365,
    ];

    public function __construct(
        private readonly ProviderEventRecorder $events,
    ) {
    }

    public function start(
        InfraProvider $provider,
        ?string $capability,
        string $environment,
        int $levelTarget,
        ?int $assessorUserId = null,
        ?string $assessorLabel = null,
        ?InfraProviderCertification $supersedes = null
    ): InfraProviderCertification {
        if (!in_array($levelTarget, CertificationLevel::all(), true)) {
            throw new InvalidArgumentException("Unknown certification level {$levelTarget}.");
        }

        $cert = InfraProviderCertification::create([
            'certification_uid' => (string) Str::uuid(),
            'provider_id'       => $provider->id,
            'provider_key'      => $provider->provider_key,
            'capability'        => $capability,
            'environment'       => $environment,
            'adapter_version'   => $provider->adapter_version,
            'status'            => InfraProviderCertification::STATUS_IN_REVIEW,
            'level'             => CertificationLevel::REGISTERED,
            'level_target'      => $levelTarget,
            'started_at'        => now(),
            'assessor_user_id'  => $assessorUserId,
            'assessor_label'    => $assessorLabel,
            'supersedes_id'     => $supersedes?->id,
        ]);

        // Pre-create every applicable check as `not_tested`. This is deliberate:
        // an assessment that simply omits a check would otherwise look complete.
        // Making the unanswered questions exist forces them to be visible.
        foreach (Checklist::forLevel($levelTarget, $capability) as $key => $meta) {
            InfraCertificationCheck::create([
                'certification_id' => $cert->id,
                'domain'           => $meta['domain'],
                'check_key'        => $key,
                'description'      => $meta['description'],
                'result'           => InfraCertificationCheck::RESULT_NOT_TESTED,
                'is_critical'      => $meta['critical'],
            ]);
        }

        $this->events->record(
            eventType: 'certification_started',
            provider: $provider,
            capability: $capability,
            environment: $environment,
            summary: "Certification started, targeting " . CertificationLevel::label($levelTarget),
            metadata: ['certification_uid' => $cert->certification_uid,
                       'checks' => $cert->checks()->count()],
            actorUserId: $assessorUserId,
            actorType: $assessorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $cert->certification_uid,
        );

        return $cert->fresh();
    }

    /**
     * Record an evidence-backed answer.
     *
     * REFUSES a pass on a critical check that has no runtime evidence. This is
     * the guard against the framework's own worst failure mode: certifying a
     * provider because its documentation says it works.
     */
    public function recordCheck(
        InfraProviderCertification $cert,
        string $checkKey,
        string $result,
        string $evidenceType,
        ?string $evidenceReference = null,
        array $evidence = [],
        ?int $assessorUserId = null,
        ?string $notes = null,
        string $confidence = 'medium'
    ): InfraCertificationCheck {
        if (in_array($cert->status, InfraProviderCertification::terminalStatuses(), true)) {
            throw new RuntimeException(
                "Certification {$cert->id} is concluded; record a new certification instead."
            );
        }
        if (!in_array($result, InfraCertificationCheck::results(), true)) {
            throw new InvalidArgumentException("Unknown result '{$result}'.");
        }

        $check = InfraCertificationCheck::where('certification_id', $cert->id)
            ->where('check_key', $checkKey)->first();

        if (!$check) {
            throw new InvalidArgumentException(
                "Check '{$checkKey}' is not part of certification {$cert->id} "
                . "(target " . CertificationLevel::label($cert->level_target) . ').'
            );
        }

        $isRuntime = in_array($evidenceType, InfraCertificationCheck::runtimeEvidenceTypes(), true);

        if ($check->is_critical
            && $result === InfraCertificationCheck::RESULT_PASS
            && !$isRuntime) {
            throw new RuntimeException(
                "Cannot pass critical check '{$checkKey}' on '{$evidenceType}' evidence. "
                . 'Critical controls require runtime observation (runtime, readonly_api or '
                . 'test_result). Use pass_with_condition and record a waiver if the '
                . 'compromise is accepted deliberately.'
            );
        }

        $check->update([
            'result'              => $result,
            'evidence_type'       => $evidenceType,
            'evidence_reference'  => $evidenceReference,
            'evidence_json'       => $this->events->sanitize($evidence),
            'evidence_is_runtime' => $isRuntime,
            'confidence'          => $confidence,
            'assessor_user_id'    => $assessorUserId,
            'assessed_at'         => now(),
            'notes'               => $notes,
        ]);

        return $check->fresh();
    }

    /**
     * Record a waiver. Requires a DIFFERENT approver than the owner.
     *
     * 🔴 CRITICAL CHECKS CANNOT BE WAIVED. Not "discouraged" — refused. These are
     * ownership and security controls where accepting the risk means accepting
     * the possible loss of customer infrastructure.
     */
    public function applyWaiver(
        InfraProviderCertification $cert,
        string $checkKey,
        string $risk,
        string $justification,
        int $ownerUserId,
        \DateTimeInterface $expiresAt,
        ?string $compensatingControl = null,
        ?int $approverUserId = null
    ): InfraCertificationWaiver {
        if (Checklist::isCritical($checkKey)) {
            throw new RuntimeException(
                "Check '{$checkKey}' is CRITICAL and cannot be waived. A waiver would hide "
                . 'a control whose failure risks loss of, or loss of control over, customer '
                . 'infrastructure. Fix the control or accept a lower certification level.'
            );
        }

        if ($approverUserId !== null && $approverUserId === $ownerUserId) {
            throw new RuntimeException(
                'A waiver may not be approved by the person who requested it. Separation of '
                . 'duties applies to exceptions exactly as it applies to activation.'
            );
        }

        if ($expiresAt <= now()) {
            throw new InvalidArgumentException(
                'A waiver must expire in the future. A permanent waiver is not an exception, '
                . 'it is an undocumented policy change.'
            );
        }

        $waiver = InfraCertificationWaiver::create([
            'waiver_uid'           => (string) Str::uuid(),
            'certification_id'     => $cert->id,
            'check_key'            => $checkKey,
            'risk'                 => $risk,
            'justification'        => $justification,
            'compensating_control' => $compensatingControl,
            'owner_user_id'        => $ownerUserId,
            'approver_user_id'     => $approverUserId,
            'approved_at'          => $approverUserId ? now() : null,
            'expires_at'           => $expiresAt,
            'review_at'            => $expiresAt,
            'status'               => $approverUserId
                ? InfraCertificationWaiver::STATUS_ACTIVE
                : InfraCertificationWaiver::STATUS_PENDING,
        ]);

        // A waiver downgrades a failure to pass_with_condition — never to pass.
        if ($waiver->isEffective()) {
            $check = InfraCertificationCheck::where('certification_id', $cert->id)
                ->where('check_key', $checkKey)->first();

            if ($check && !$check->countsAsSatisfied()) {
                $check->update([
                    'result' => InfraCertificationCheck::RESULT_PASS_CONDITION,
                    'notes'  => trim((string) $check->notes . ' [waived: ' . $waiver->waiver_uid . ']'),
                ]);
            }
        }

        $this->events->record(
            eventType: 'certification_waiver_recorded',
            provider: $cert->provider,
            capability: $cert->capability,
            environment: $cert->environment,
            severity: InfraProviderEvent::SEVERITY_WARNING,
            summary: "Waiver for '{$checkKey}': {$risk}",
            metadata: ['waiver_uid' => $waiver->waiver_uid, 'expires_at' => $expiresAt->format('c')],
            actorUserId: $ownerUserId,
            actorType: InfraProviderEvent::ACTOR_USER,
            correlationId: $cert->certification_uid,
        );

        return $waiver;
    }

    /**
     * Conclude: compute the level FROM THE EVIDENCE and freeze the verdict.
     *
     * Awards the highest level whose required domains are fully satisfied — which
     * may be lower than the target, and frequently should be.
     */
    public function conclude(
        InfraProviderCertification $cert,
        ?int $assessorUserId = null
    ): InfraProviderCertification {
        if (in_array($cert->status, InfraProviderCertification::terminalStatuses(), true)) {
            throw new RuntimeException("Certification {$cert->id} is already concluded.");
        }

        $checks = $cert->checks()->get();

        $counts = [
            'total'      => $checks->count(),
            'passed'     => $checks->filter->countsAsSatisfied()->count(),
            'failed'     => $checks->where('result', InfraCertificationCheck::RESULT_FAIL)->count(),
            'blocked'    => $checks->where('result', InfraCertificationCheck::RESULT_BLOCKED)->count(),
            'not_tested' => $checks->where('result', InfraCertificationCheck::RESULT_NOT_TESTED)->count(),
        ];

        $achieved = $this->computeLevel($cert, $checks);
        $blockers = $this->computeBlockers($cert, $checks, $achieved);

        $unsatisfiedCritical = $checks->filter->isUnsatisfiedCritical();
        $conditional = $checks->where('result', InfraCertificationCheck::RESULT_PASS_CONDITION)->count() > 0;

        $status = match (true) {
            $unsatisfiedCritical->isNotEmpty() && $achieved === CertificationLevel::REGISTERED
                => InfraProviderCertification::STATUS_FAILED,
            $conditional => InfraProviderCertification::STATUS_CONDITIONS,
            default      => InfraProviderCertification::STATUS_PASSED,
        };

        $validity = self::VALIDITY_DAYS[$achieved] ?? 180;

        DB::transaction(function () use ($cert, $status, $achieved, $counts, $blockers, $validity, $assessorUserId) {
            $cert->update([
                'status'            => $status,
                'level'             => $achieved,
                'completed_at'      => now(),
                'expires_at'        => now()->addDays($validity),
                'recertify_after'   => now()->addDays((int) ($validity * 0.8)),
                'checks_total'      => $counts['total'],
                'checks_passed'     => $counts['passed'],
                'checks_failed'     => $counts['failed'],
                'checks_blocked'    => $counts['blocked'],
                'checks_not_tested' => $counts['not_tested'],
                'waivers_active'    => $cert->waivers()->where('status', InfraCertificationWaiver::STATUS_ACTIVE)->count(),
                'blockers_json'     => $blockers,
                'assessor_user_id'  => $cert->assessor_user_id ?? $assessorUserId,
            ]);

            if ($cert->supersedes_id) {
                InfraProviderCertification::where('id', $cert->supersedes_id)
                    ->update(['superseded_by_id' => $cert->id]);
            }
        });

        $this->events->record(
            eventType: 'certification_concluded',
            provider: $cert->provider,
            capability: $cert->capability,
            environment: $cert->environment,
            severity: $status === InfraProviderCertification::STATUS_PASSED
                ? InfraProviderEvent::SEVERITY_SUCCESS
                : InfraProviderEvent::SEVERITY_WARNING,
            toState: $status,
            summary: "Certification concluded: {$status}, " . CertificationLevel::label($achieved)
                . " (target was " . CertificationLevel::label($cert->level_target) . ').',
            metadata: ['counts' => $counts, 'blockers' => $blockers],
            actorUserId: $assessorUserId,
            actorType: $assessorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $cert->certification_uid,
        );

        return $cert->fresh();
    }

    /**
     * Highest level fully supported by the evidence.
     *
     * Walks UP from 0 and stops at the first level with an unmet requirement, so
     * a level is never awarded because a higher one happened to pass.
     */
    private function computeLevel(InfraProviderCertification $cert, $checks): int
    {
        $achieved = CertificationLevel::REGISTERED;

        foreach ([CertificationLevel::READ_ONLY_VERIFIED, CertificationLevel::SANDBOX_VALIDATED,
                  CertificationLevel::STAGING_CERTIFIED, CertificationLevel::PRODUCTION_APPROVED] as $level) {

            if ($level > $cert->level_target) {
                break;
            }

            $required = Checklist::forLevel($level, $cert->capability);
            $ok = true;

            foreach ($required as $key => $meta) {
                $check = $checks->firstWhere('check_key', $key);

                // A required check that was never recorded cannot support a level.
                if (!$check || !$check->countsAsSatisfied()) {
                    $ok = false;
                    break;
                }

                // Critical checks at runtime-evidence levels need observation.
                if ($meta['critical']
                    && CertificationLevel::requiresRuntimeEvidence($level)
                    && !$check->evidence_is_runtime
                    && $check->result === InfraCertificationCheck::RESULT_PASS) {
                    $ok = false;
                    break;
                }
            }

            if (!$ok) {
                break;
            }

            $achieved = $level;
        }

        return $achieved;
    }

    /** Named, actionable reasons a higher level was not reached. */
    private function computeBlockers(InfraProviderCertification $cert, $checks, int $achieved): array
    {
        $next = $achieved + 1;

        if ($next > CertificationLevel::PRODUCTION_APPROVED) {
            return [];
        }

        $blockers = [];

        foreach (Checklist::forLevel($next, $cert->capability) as $key => $meta) {
            $check = $checks->firstWhere('check_key', $key);

            if (!$check || !$check->countsAsSatisfied()) {
                $blockers[] = [
                    'check_key'   => $key,
                    'domain'      => $meta['domain'],
                    'critical'    => $meta['critical'],
                    'result'      => $check?->result ?? 'missing',
                    'blocks_level' => $next,
                    'description' => $meta['description'],
                ];
            }
        }

        return $blockers;
    }

    /** Recertification: a NEW certification superseding the old. Never an edit. */
    public function recertify(
        InfraProviderCertification $previous,
        string $trigger,
        int $levelTarget,
        ?int $assessorUserId = null
    ): InfraProviderCertification {
        $provider = $previous->provider;

        if (!$provider) {
            throw new RuntimeException('Cannot recertify: provider no longer exists.');
        }

        $cert = $this->start(
            $provider, $previous->capability, $previous->environment,
            $levelTarget, $assessorUserId, "recertification: {$trigger}", $previous
        );

        $this->events->record(
            eventType: 'certification_recertification_started',
            provider: $provider,
            capability: $previous->capability,
            environment: $previous->environment,
            summary: "Recertification triggered by: {$trigger}",
            metadata: ['trigger' => $trigger, 'supersedes_uid' => $previous->certification_uid],
            actorUserId: $assessorUserId,
            actorType: $assessorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $cert->certification_uid,
        );

        return $cert;
    }

    /** Triggers requiring recertification (WS5). */
    public static function recertificationTriggers(): array
    {
        return [
            'credential_scope_change', 'credential_rotation', 'provider_account_transfer',
            'adapter_version_change', 'provider_api_version_change', 'material_pricing_change',
            'capability_enablement', 'security_incident', 'repeated_provider_failure',
            'ownership_change', 'billing_account_change', 'new_region',
            'production_promotion', 'certification_expiry',
        ];
    }

    /** The current valid certification, if any. */
    public function current(
        InfraProvider $provider,
        ?string $capability,
        string $environment
    ): ?InfraProviderCertification {
        return InfraProviderCertification::where('provider_id', $provider->id)
            ->where('environment', $environment)
            ->when($capability === null,
                fn ($q) => $q->whereNull('capability'),
                fn ($q) => $q->where('capability', $capability))
            ->whereIn('status', [InfraProviderCertification::STATUS_PASSED,
                                 InfraProviderCertification::STATUS_CONDITIONS])
            ->whereNull('superseded_by_id')
            ->orderByDesc('id')
            ->get()
            ->first(fn ($c) => $c->isCurrent());
    }
}
