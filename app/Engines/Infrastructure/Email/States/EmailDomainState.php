<?php

namespace App\Engines\Infrastructure\Email\States;

use App\Engines\Infrastructure\States\StateMachine;

/**
 * INFRA888 · E1 — BUSINESS EMAIL DOMAIN LIFECYCLE.
 *
 * The domain is the root object. A mailbox cannot exist before the domain it
 * belongs to is provisioned, so this machine gates everything downstream.
 *
 * THE BASELINE FROM THE MASTERPLAN
 *   connected -> verifying_dns -> dns_verified -> provisioning -> active
 *                                                          -> suspended -> terminated
 *
 * WHAT THIS ADDS, AND WHY EACH ADDITION IS LOAD-BEARING
 *
 *   verification_failed / provisioning_failed are DISTINCT.
 *   A domain whose MX records are wrong is a customer-fixable problem with a
 *   customer-facing instruction. A domain the provider refused to accept is an
 *   operator problem. Collapsing them into one `failed` state means the portal
 *   cannot tell a customer what to do, and S3's NEEDS_MANUAL lesson applies:
 *   any state requiring a human decision must be distinguishable.
 *
 *   reconciling is NOT an error state.
 *   Carried forward from S5 and restated in the masterplan section 4: a timeout
 *   or ambiguous response on a mutating call is neither success nor failure. It
 *   enters `reconciling` and is resolved ONLY by reading provider truth back.
 *   Without this state the two available answers are "assume it worked" (a lie)
 *   and "retry" (duplicate provisioning). Neither is acceptable for email, where
 *   a duplicated domain onboarding can split inbound mail.
 *
 *   active -> verifying_dns is legal.
 *   DNS is not a one-time gate. A customer who edits their DNS after go-live
 *   breaks mail flow, and drift detection must be able to pull a live domain
 *   back into verification rather than silently reporting `active`. Elapsed
 *   time never promotes a state here; only observation does.
 *
 * TERMINAL. Only `terminated` is terminal. Termination stops mail flow and
 * releases the provider-side domain, and nothing may follow it — a re-onboard
 * is a NEW record, so the audit trail of the terminated one stays intact.
 */
final class EmailDomainState extends StateMachine
{
    /** The workspace has declared this domain for Business Email. Nothing provider-side exists yet. */
    public const CONNECTED = 'connected';

    /** DNS records have been issued and are being checked against public DNS. */
    public const VERIFYING_DNS = 'verifying_dns';

    /** Required records observed. Evidence-backed, never time-backed. */
    public const DNS_VERIFIED = 'dns_verified';

    /** A governed provisioning operation is in flight. */
    public const PROVISIONING = 'provisioning';

    /** Mail flows. The only customer-visible "working" state. */
    public const ACTIVE = 'active';

    /** Mail suspended by us or by the customer. Reversible; data retained. */
    public const SUSPENDED = 'suspended';

    /** DNS check ran and the records are absent or wrong. Customer-actionable. */
    public const VERIFICATION_FAILED = 'verification_failed';

    /** The provider refused or errored terminally. Operator-actionable. */
    public const PROVISIONING_FAILED = 'provisioning_failed';

    /** Outcome unknown. Resolved by reading provider truth back, never by retrying blind. */
    public const RECONCILING = 'reconciling';

    /** Released provider-side. Terminal. */
    public const TERMINATED = 'terminated';

    public static function initial(): string
    {
        return self::CONNECTED;
    }

    public static function states(): array
    {
        return [
            self::CONNECTED,
            self::VERIFYING_DNS,
            self::DNS_VERIFIED,
            self::PROVISIONING,
            self::ACTIVE,
            self::SUSPENDED,
            self::VERIFICATION_FAILED,
            self::PROVISIONING_FAILED,
            self::RECONCILING,
            self::TERMINATED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::CONNECTED => [self::VERIFYING_DNS, self::TERMINATED],

            self::VERIFYING_DNS => [self::DNS_VERIFIED, self::VERIFICATION_FAILED, self::TERMINATED],

            // Re-checking a verified domain is legal: DNS can change under us.
            self::DNS_VERIFIED => [self::PROVISIONING, self::VERIFYING_DNS, self::TERMINATED],

            // No direct edge to ACTIVE from a failed check. Re-verification is
            // the only way back, so a fixed DNS record must be OBSERVED again.
            self::VERIFICATION_FAILED => [self::VERIFYING_DNS, self::TERMINATED],

            self::PROVISIONING => [
                self::ACTIVE,
                self::PROVISIONING_FAILED,
                self::RECONCILING,
                self::TERMINATED,
            ],

            // Reconciliation reads provider truth and lands on a real answer.
            // It may NOT return to PROVISIONING: re-issuing the mutation is
            // exactly the double-provisioning this state exists to prevent.
            self::RECONCILING => [self::ACTIVE, self::PROVISIONING_FAILED, self::TERMINATED],

            self::PROVISIONING_FAILED => [self::PROVISIONING, self::TERMINATED],

            self::ACTIVE => [self::SUSPENDED, self::VERIFYING_DNS, self::RECONCILING, self::TERMINATED],

            self::SUSPENDED => [self::ACTIVE, self::TERMINATED],

            self::TERMINATED => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::TERMINATED];
    }

    /**
     * States a customer may be told mail is working in. Exactly one, deliberately.
     */
    public static function operational(): array
    {
        return [self::ACTIVE];
    }

    public static function failureStates(): array
    {
        return [self::VERIFICATION_FAILED, self::PROVISIONING_FAILED, self::SUSPENDED];
    }

    /**
     * States that need a human decision and must therefore have an admin screen
     * before this engine is allowed to execute anything (S3's NEEDS_MANUAL lesson).
     */
    public static function needsHuman(): array
    {
        return [self::RECONCILING, self::PROVISIONING_FAILED];
    }

    /** States in which no provider mutation has been attempted for this domain. */
    public static function preProvision(): array
    {
        return [self::CONNECTED, self::VERIFYING_DNS, self::DNS_VERIFIED, self::VERIFICATION_FAILED];
    }

    /**
     * Transitions that destroy or stop service and therefore require explicit
     * governance rather than an automatic system decision.
     */
    public static function governedTransitions(): array
    {
        return [
            self::ACTIVE . '->' . self::SUSPENDED,
            self::ACTIVE . '->' . self::TERMINATED,
            self::SUSPENDED . '->' . self::TERMINATED,
            self::PROVISIONING . '->' . self::TERMINATED,
            self::RECONCILING . '->' . self::TERMINATED,
            self::PROVISIONING_FAILED . '->' . self::TERMINATED,
            self::VERIFICATION_FAILED . '->' . self::TERMINATED,
            self::VERIFYING_DNS . '->' . self::TERMINATED,
            self::DNS_VERIFIED . '->' . self::TERMINATED,
            self::CONNECTED . '->' . self::TERMINATED,
        ];
    }

    /**
     * Transitions that may only be entered on observed evidence — never on a
     * provider's claim of success and never on elapsed time.
     */
    public static function evidenceRequired(): array
    {
        return [
            self::VERIFYING_DNS . '->' . self::DNS_VERIFIED,
            self::PROVISIONING . '->' . self::ACTIVE,
            self::RECONCILING . '->' . self::ACTIVE,
            self::ACTIVE . '->' . self::VERIFYING_DNS,
            // Lifting a suspension is a provider mutation like any other, and
            // "the provider accepted the unsuspend" is not the same as "mail is
            // flowing again". It must be read back.
            self::SUSPENDED . '->' . self::ACTIVE,
        ];
    }

    public static function requiresEvidence(string $from, string $to): bool
    {
        return in_array($from . '->' . $to, self::evidenceRequired(), true);
    }

    public static function requiresGovernance(string $from, string $to): bool
    {
        return in_array($from . '->' . $to, self::governedTransitions(), true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::terminal(), true);
    }
}
