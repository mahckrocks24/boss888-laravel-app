<?php

namespace App\Engines\Infrastructure\Email\States;

use App\Engines\Infrastructure\States\StateMachine;

/**
 * INFRA888 · E1 — MAILBOX LIFECYCLE.
 *
 * A mailbox is the billable, quota-consuming unit and the one entity whose
 * deletion destroys customer data that no rollback restores. Its machine is
 * therefore stricter than the domain's in exactly one respect: there is no edge
 * that reaches `deleted` without passing through `deleting`, so every deletion
 * has a governed, auditable intermediate state and a point at which it can be
 * abandoned.
 *
 * WHY `suspended` IS A LIFECYCLE STATE AND ALSO A SEPARATE REASON FIELD
 * The state says mail is stopped. It does not say WHY, and why determines who
 * may restore it: a customer-requested suspension is customer-reversible, a
 * billing hold is not. Encoding the reason as extra states would multiply the
 * machine; encoding it as a boolean would lose it. It lives in
 * EmailMailbox::SUSPENSION_* alongside this state.
 *
 * `reconciling` carries the same meaning as everywhere else in INFRA888: the
 * provider's answer was ambiguous and the truth must be READ BACK. Creating a
 * mailbox twice because a timeout was read as failure is the email equivalent
 * of the double-renewal S5 exists to prevent.
 */
final class EmailMailboxState extends StateMachine
{
    /** Recorded locally. No provider mutation attempted. Consumes no quota yet. */
    public const REQUESTED = 'requested';

    /** A governed create operation is in flight. */
    public const PROVISIONING = 'provisioning';

    /** Exists at the provider and was read back. Billable. */
    /**
     * Provisioned at the provider, and waiting for a human.
     *
     * INFRA888 · E7. Under invitation mode the provider creates the mailbox and
     * emails its owner to set a password; until they do, the mailbox exists but
     * nobody can log into it and no mail can be read. Calling that ACTIVE would
     * tell a customer their email works when it does not, and calling it
     * PROVISIONING would say WE are still working when the next move is theirs.
     */
    public const AWAITING_ACTIVATION = 'awaiting_activation';

    public const ACTIVE = 'active';

    /** Mail stopped, data retained, reversible. */
    public const SUSPENDED = 'suspended';

    /** Ambiguous provider outcome. Resolved by read-back only. */
    public const RECONCILING = 'reconciling';

    /** Provider refused terminally. No mailbox exists. */
    public const PROVISIONING_FAILED = 'provisioning_failed';

    /** Deletion authorised and in flight. Still recoverable until it completes. */
    public const DELETING = 'deleting';

    /** Gone provider-side. Terminal. Mail is unrecoverable after provider retention lapses. */
    public const DELETED = 'deleted';

    public static function initial(): string
    {
        return self::REQUESTED;
    }

    public static function states(): array
    {
        return [
            self::REQUESTED,
            self::PROVISIONING,
            self::AWAITING_ACTIVATION,
            self::ACTIVE,
            self::SUSPENDED,
            self::RECONCILING,
            self::PROVISIONING_FAILED,
            self::DELETING,
            self::DELETED,
        ];
    }

    public static function transitions(): array
    {
        return [
            // Abandoning a never-provisioned mailbox still goes through DELETING
            // so that one code path, one audit record and one governance check
            // cover every route to DELETED.
            self::REQUESTED => [self::PROVISIONING, self::DELETING],

            self::PROVISIONING => [
                self::ACTIVE,
                // Invitation mode lands here rather than ACTIVE.
                self::AWAITING_ACTIVATION,
                self::PROVISIONING_FAILED,
                self::RECONCILING,
            ],

            // The owner accepted the invitation, or the mailbox is torn down
            // before they ever did. Never back to PROVISIONING: the mailbox
            // already exists, and re-provisioning it would be a duplicate.
            self::AWAITING_ACTIVATION => [self::ACTIVE, self::DELETING, self::RECONCILING],

            // Never back to PROVISIONING: that is the duplicate-create path.
            self::RECONCILING => [self::ACTIVE, self::AWAITING_ACTIVATION, self::PROVISIONING_FAILED, self::DELETING],

            self::PROVISIONING_FAILED => [self::PROVISIONING, self::DELETING],

            self::ACTIVE => [self::SUSPENDED, self::RECONCILING, self::DELETING],

            self::SUSPENDED => [self::ACTIVE, self::DELETING],

            // A delete that times out is ambiguous too — the mailbox may or may
            // not still exist, and only a read-back can say.
            self::DELETING => [self::DELETED, self::RECONCILING],

            self::DELETED => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::DELETED];
    }

    public static function operational(): array
    {
        return [self::ACTIVE];
    }

    public static function failureStates(): array
    {
        return [self::PROVISIONING_FAILED, self::SUSPENDED];
    }

    public static function needsHuman(): array
    {
        return [self::RECONCILING, self::PROVISIONING_FAILED];
    }

    /** States in which the mailbox consumes plan quota and is billable. */
    public static function billable(): array
    {
        return [self::ACTIVE, self::SUSPENDED];
    }

    /**
     * Destructive transitions. Every one requires explicit governance and an
     * approving actor; none may be taken by an automatic system decision.
     */
    public static function governedTransitions(): array
    {
        return [
            self::REQUESTED . '->' . self::DELETING,
            self::ACTIVE . '->' . self::DELETING,
            self::SUSPENDED . '->' . self::DELETING,
            self::RECONCILING . '->' . self::DELETING,
            self::PROVISIONING_FAILED . '->' . self::DELETING,
            self::DELETING . '->' . self::DELETED,
            self::ACTIVE . '->' . self::SUSPENDED,
        ];
    }

    public static function evidenceRequired(): array
    {
        return [
            self::PROVISIONING . '->' . self::ACTIVE,
            self::RECONCILING . '->' . self::ACTIVE,
            self::DELETING . '->' . self::DELETED,
            // A restore is a provider mutation. "The provider accepted the
            // unsuspend" is not the same as "this mailbox receives mail again".
            self::SUSPENDED . '->' . self::ACTIVE,

            // INFRA888 · E7. The owner accepting an invitation happens entirely
            // outside this platform — a person opens an email and chooses a
            // password. We cannot know it has happened; we can only read it
            // back from the provider. Marking a mailbox ACTIVE because enough
            // time passed would be the purest form of the fabricated-success
            // failure this whole engine exists to prevent.
            self::AWAITING_ACTIVATION . '->' . self::ACTIVE,
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
