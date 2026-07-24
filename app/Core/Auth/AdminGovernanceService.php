<?php

namespace App\Core\Auth;

use App\Engines\Infrastructure\Models\AdminAppointment;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Platform-administrator appointment and offboarding (Phase 2B-R1, WS1).
 *
 * THE POINT: is_platform_admin can never move without an audit row. Both writes
 * happen in one transaction. Today an admin is minted by a raw UPDATE with no
 * trail; this closes that.
 *
 * DOES NOT appoint anyone by itself. A real human must be named and authorized
 * by the user. This service is the mechanism, invoked with that authorization —
 * it refuses to appoint the staging validation identity, and refuses
 * self-appointment.
 */
class AdminGovernanceService
{
    /**
     * Account classifications that may NEVER hold platform governance.
     * This is the primary, structural control (WS14) — independent of email.
     */
    public const CLASS_STANDARD        = 'standard';
    public const CLASS_VALIDATION_ONLY = 'validation_only';
    public const CLASS_SERVICE         = 'service';

    private const NON_GOVERNANCE_CLASSES = [
        self::CLASS_VALIDATION_ONLY,
        self::CLASS_SERVICE,
    ];

    /**
     * Kept ONLY as belt-and-braces alongside the classification check. A single
     * guard is one edit away from failing open; two independent guards are not.
     */
    private const VALIDATION_IDENTITY_EMAIL = 'infra-approver-staging@levelupgrowth.io';

    /**
     * Appoint a human as platform administrator.
     *
     * @throws RuntimeException when the appointment would violate governance.
     */
    public function appoint(
        User $appointee,
        User $appointedBy,
        string $reason,
        string $environment = 'production',
        bool $productionEligible = false
    ): AdminAppointment {
        if ($appointee->id === $appointedBy->id) {
            throw new RuntimeException(
                'An administrator may not appoint themselves. A second, already-'
                . 'authorized administrator must perform the appointment.'
            );
        }

        // PRIMARY control: structural classification, independent of email.
        if (in_array($appointee->account_classification, self::NON_GOVERNANCE_CLASSES, true)) {
            throw new RuntimeException(
                "Account classification '{$appointee->account_classification}' may never hold "
                . 'platform governance. Validation and service accounts prove mechanisms and '
                . 'operate machinery; they are not decision-makers.'
            );
        }

        // BELT-AND-BRACES: the original email guard, retained deliberately so a
        // misclassified validation identity is still refused.
        if ($appointee->email === self::VALIDATION_IDENTITY_EMAIL) {
            throw new RuntimeException(
                'The staging validation identity may never be appointed a real '
                . 'administrator. It proves the mechanism; it is not a decision-maker.'
            );
        }

        if (!$appointedBy->is_platform_admin) {
            throw new RuntimeException(
                'Only an existing platform administrator may appoint another.'
            );
        }

        // Production eligibility additionally requires the appointee to have MFA,
        // because a production admin without a second factor is the exact gap D3
        // exists to close. Eligibility is downgraded rather than refused, so an
        // admin can be appointed for staging now and become production-eligible
        // once enrolled.
        $mfaReady = (bool) $appointee->mfa_enabled && $appointee->mfa_confirmed_at !== null;
        $prodEligible = $productionEligible && $mfaReady;

        return DB::transaction(function () use ($appointee, $appointedBy, $reason, $environment, $prodEligible, $mfaReady) {
            $appointment = AdminAppointment::create([
                'appointment_uid'                  => (string) Str::uuid(),
                'user_id'                          => $appointee->id,
                'appointed_by_user_id'             => $appointedBy->id,
                'action'                           => AdminAppointment::ACTION_GRANT,
                'role'                             => 'platform_admin',
                'environment'                      => $environment,
                'reason'                           => $reason,
                'mfa_enabled_at_appointment'       => $mfaReady,
                'recovery_verified_at_appointment' => $appointee->mfa_confirmed_at !== null,
                'production_eligible'              => $prodEligible,
                'effective_at'                     => now(),
            ]);

            // The flag and the audit row move together, or neither moves.
            $appointee->forceFill(['is_platform_admin' => 1, 'is_admin' => 1])->save();

            return $appointment;
        });
    }

    /**
     * Offboard: remove admin authority immediately, with a permanent record.
     *
     * Historical appointments and approvals keep their original attribution — the
     * person WAS an admin then, and rewriting that would corrupt the audit trail.
     */
    public function offboard(User $admin, User $offboardedBy, string $reason): AdminAppointment
    {
        return DB::transaction(function () use ($admin, $offboardedBy, $reason) {
            $record = AdminAppointment::create([
                'appointment_uid'      => (string) Str::uuid(),
                'user_id'              => $admin->id,
                'appointed_by_user_id' => $offboardedBy->id,
                'action'               => AdminAppointment::ACTION_REVOKE,
                'role'                 => 'platform_admin',
                'reason'               => $reason,
                'revoked_at'           => now(),
                'effective_at'         => now(),
            ]);

            $admin->forceFill([
                'is_platform_admin'    => 0,
                'is_admin'             => 0,
                // Kill the MFA freshness anchor so any live privileged session
                // fails its next step-up check immediately.
                'mfa_last_verified_at' => null,
            ])->save();

            return $record;
        });
    }

    /** Count of genuine human production-eligible admins (excludes validation identity). */
    public function productionEligibleAdminCount(): int
    {
        return User::where('is_platform_admin', 1)
            ->whereNotIn('account_classification', self::NON_GOVERNANCE_CLASSES)
            ->where('email', '!=', self::VALIDATION_IDENTITY_EMAIL)
            ->where('mfa_enabled', 1)
            ->whereNotNull('mfa_confirmed_at')
            ->count();
    }

    /** Whether the platform meets the two-MFA-admin bar for production activation. */
    public function meetsProductionGovernanceBar(): bool
    {
        return $this->productionEligibleAdminCount() >= 2;
    }

    // ══════════════════════════════════════════════════════════════════════
    // GOVERNANCE STATE MACHINE (Phase 2B-R3, WS4)
    // bootstrap -> activated -> (recovery) -> activated
    // ══════════════════════════════════════════════════════════════════════

    public function governanceState(): InfraGovernanceState
    {
        return InfraGovernanceState::current();
    }

    public function currentMode(): string
    {
        return $this->governanceState()->mode;
    }

    /**
     * Activate governance the first time the two-MFA-admin bar is met.
     *
     * Idempotent and one-way: once activated, `activated_at` is set forever.
     * Called opportunistically (e.g. after an appointment/enrolment). Returns
     * true only on the transition, so callers can event it.
     */
    public function maybeActivateGovernance(?int $actorUserId = null): bool
    {
        $state = $this->governanceState();

        if ($state->wasEverActivated()) {
            // Already activated — never regress to bootstrap, even if the count
            // has since dropped. That is exactly the fail-open this prevents.
            return false;
        }

        if (!$this->meetsProductionGovernanceBar()) {
            return false;
        }

        $state->update([
            'mode'                 => InfraGovernanceState::MODE_ACTIVATED,
            'activated_at'         => now(),
            'activated_by_user_id' => $actorUserId,
        ]);

        return true;
    }

    /**
     * Is MFA step-up ENFORCED right now (as opposed to standing down in bootstrap)?
     *
     * The whole point of WS4: enforcement is a function of whether governance was
     * EVER activated, NOT of the current admin count. Once activated, always
     * enforced.
     */
    public function stepUpEnforced(): bool
    {
        $state = $this->governanceState();
        return $state->wasEverActivated() || $state->isFounderMode();
    }

    public function isFounderMode(): bool
    {
        return $this->governanceState()->isFounderMode();
    }

    /**
     * Enter Founder Mode (Phase 3A, Stage 1).
     *
     * FAILS CLOSED: the founder must be a platform admin, of standard
     * classification, and MFA-enrolled+confirmed. A founder without a second
     * factor is exactly the gap MFA exists to close, so Founder Mode cannot be
     * entered until the founder has enrolled.
     *
     * Self-authorization is permitted in this mode BECAUSE the operating model is
     * genuinely single-operator — there is no second party to separate duties
     * with. That is an explicit, recorded governance decision, not a silent
     * bypass, and every action remains MFA-gated and audited.
     */
    public function enterFounderMode(\App\Models\User $founder): InfraGovernanceState
    {
        if (!$founder->is_platform_admin) {
            throw new \RuntimeException('Founder Mode requires a platform administrator.');
        }
        if (in_array($founder->account_classification, self::NON_GOVERNANCE_CLASSES, true)) {
            throw new \RuntimeException(
                "Account classification '{$founder->account_classification}' may not hold Founder Mode."
            );
        }
        if (!$founder->mfa_enabled || $founder->mfa_confirmed_at === null) {
            throw new \RuntimeException(
                'Founder Mode requires the founder to be MFA-enrolled and confirmed. '
                . 'Enrol via /api/admin/mfa/enrol first — a founder without a second factor '
                . 'is precisely the risk this mode must not carry.'
            );
        }

        $state = $this->governanceState();
        $state->update([
            'mode'                 => InfraGovernanceState::MODE_FOUNDER,
            // Founder Mode IS governance activation — set the durable anchor so
            // the platform can never silently regress to bootstrap.
            'activated_at'         => $state->activated_at ?? now(),
            'activated_by_user_id' => $state->activated_by_user_id ?? $founder->id,
            'metadata_json'        => array_merge($state->metadata_json ?? [], [
                'founder_user_id' => $founder->id,
                'stage'           => 'founder_mode',
            ]),
        ]);

        return $state->fresh();
    }

    /**
     * Whether this user may self-authorize a protected action right now.
     * True only for the founder while in Founder Mode.
     */
    public function founderSelfApprovalPermitted(\App\Models\User $user): bool
    {
        $state = $this->governanceState();
        if (!$state->isFounderMode()) {
            return false;
        }
        return (int) (($state->metadata_json['founder_user_id'] ?? 0)) === (int) $user->id;
    }

    /**
     * Governance is degraded when it was activated but the bar is no longer met
     * (e.g. an admin was offboarded). In this state privileged ops FAIL CLOSED
     * until an explicit recovery restores a second admin.
     */
    public function isDegraded(): bool
    {
        $state = $this->governanceState();

        return $state->wasEverActivated()
            && $state->mode !== InfraGovernanceState::MODE_RECOVERY
            && $state->mode !== InfraGovernanceState::MODE_FOUNDER
            && !$this->meetsProductionGovernanceBar();
    }

    /**
     * Explicit, audited, time-boxed break-glass. The ONLY way to perform
     * privileged operations (like appointing a replacement admin) while
     * governance is degraded — never automatic, always logged, always expiring.
     */
    public function enterRecoveryMode(int $actorUserId, string $reason, int $minutes = 60): InfraGovernanceState
    {
        $state = $this->governanceState();

        $state->update([
            'mode'                => InfraGovernanceState::MODE_RECOVERY,
            'recovery_entered_at' => now(),
            'recovery_by_user_id' => $actorUserId,
            'recovery_reason'     => $reason,
            'recovery_expires_at' => now()->addMinutes(max(1, $minutes)),
        ]);

        return $state->fresh();
    }

    /** Leave recovery, returning to activated. Governance stays activated forever. */
    public function exitRecoveryMode(?int $actorUserId = null): InfraGovernanceState
    {
        $state = $this->governanceState();

        $state->update([
            'mode'                => InfraGovernanceState::MODE_ACTIVATED,
            'recovery_entered_at' => null,
            'recovery_by_user_id' => null,
            'recovery_reason'     => null,
            'recovery_expires_at' => null,
        ]);

        return $state->fresh();
    }
}
