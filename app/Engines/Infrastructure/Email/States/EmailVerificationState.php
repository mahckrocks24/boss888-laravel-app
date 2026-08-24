<?php

namespace App\Engines\Infrastructure\Email\States;

use App\Engines\Infrastructure\States\StateMachine;

/**
 * INFRA888 · E1 — DNS VERIFICATION EVIDENCE STATE.
 *
 * WHY THIS IS SEPARATE FROM EmailDomainState
 * The domain lifecycle answers "where is this domain in its commercial life?".
 * This answers "what do we currently KNOW about its DNS?". They move at
 * different rates and for different reasons: a live, `active` domain whose DKIM
 * record was deleted this morning is commercially active and evidentially
 * `drifted` at the same time. One column cannot hold both without lying about
 * one of them.
 *
 * ACCEPTED IS NOT VERIFIED (S8.4 discipline, masterplan section 3).
 * `pending_records` means we have TOLD the customer what to add. It is not a
 * claim that they added it. Only `checking` -> `verified` is an evidence
 * transition, and it may be entered only from an observation recorded in
 * infra_observation_facts. There is deliberately no edge from `pending_records`
 * straight to `verified`: nothing may skip the observation.
 *
 * ELAPSED TIME NEVER PROMOTES. There is no "assume propagated after 48h" edge.
 * The only thing that moves this machine forward is a fact.
 */
final class EmailVerificationState extends StateMachine
{
    /** We have not told the customer anything and have observed nothing. */
    public const UNVERIFIED = 'unverified';

    /** Required records issued to the customer. A claim about US, not about them. */
    public const PENDING_RECORDS = 'pending_records';

    /** An observation is in flight against public DNS. */
    public const CHECKING = 'checking';

    /** Every required record observed. The only state that may gate provisioning. */
    public const VERIFIED = 'verified';

    /** Observation ran and records are missing or wrong. Customer-actionable. */
    public const FAILED = 'failed';

    /** Was verified; a later observation found the records changed or gone. */
    public const DRIFTED = 'drifted';

    public static function initial(): string
    {
        return self::UNVERIFIED;
    }

    public static function states(): array
    {
        return [
            self::UNVERIFIED,
            self::PENDING_RECORDS,
            self::CHECKING,
            self::VERIFIED,
            self::FAILED,
            self::DRIFTED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::UNVERIFIED => [self::PENDING_RECORDS],

            // No edge to VERIFIED. Issuing records is not evidence.
            self::PENDING_RECORDS => [self::CHECKING],

            self::CHECKING => [self::VERIFIED, self::FAILED],

            self::FAILED => [self::CHECKING, self::PENDING_RECORDS],

            // A verified domain can be re-checked, and can be found drifted.
            self::VERIFIED => [self::CHECKING, self::DRIFTED],

            // Drift is resolved only by observing again.
            self::DRIFTED => [self::CHECKING],
        ];
    }

    /**
     * Nothing here is terminal. DNS is externally mutable for the life of the
     * domain, so every evidential conclusion must remain revisable.
     */
    public static function terminal(): array
    {
        return [];
    }

    public static function operational(): array
    {
        return [self::VERIFIED];
    }

    public static function failureStates(): array
    {
        return [self::FAILED, self::DRIFTED];
    }

    /** Only these transitions may be driven by a recorded observation. */
    public static function evidenceRequired(): array
    {
        return [
            self::CHECKING . '->' . self::VERIFIED,
            self::CHECKING . '->' . self::FAILED,
            self::VERIFIED . '->' . self::DRIFTED,
        ];
    }

    public static function requiresEvidence(string $from, string $to): bool
    {
        return in_array($from . '->' . $to, self::evidenceRequired(), true);
    }

    /** The record types the engine must observe before claiming VERIFIED. */
    public static function requiredRecordTypes(): array
    {
        return ['mx', 'spf', 'dkim'];
    }

    /**
     * DMARC is observed and reported but is NOT required for verification.
     * Mail flows without it; requiring it would block onboarding on a policy
     * record many customers legitimately choose to set later.
     */
    public static function advisoryRecordTypes(): array
    {
        return ['dmarc'];
    }
}
