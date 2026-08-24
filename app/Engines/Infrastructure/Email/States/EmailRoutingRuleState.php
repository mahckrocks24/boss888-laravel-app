<?php

namespace App\Engines\Infrastructure\Email\States;

use App\Engines\Infrastructure\States\StateMachine;

/**
 * INFRA888 · E1 — shared lifecycle for address-routing rules (aliases and
 * forwarders).
 *
 * WHY ONE MACHINE FOR TWO ENTITIES
 * An alias and a forwarder differ in what they DO — an alias delivers into a
 * mailbox we operate, a forwarder relays to an address we do not control — and
 * that difference is real enough to justify separate tables, separate
 * capabilities and separate risk classes. It is NOT a difference in lifecycle:
 * both are created at a provider, both can fail, both can be removed, and both
 * can return an ambiguous outcome that must be read back.
 *
 * Duplicating the machine would mean two definitions drifting apart for no
 * behavioural reason. The subclasses exist so each entity has its own type and
 * its own label in audit records, which is what actually needs to differ.
 *
 * Removal is not destructive in the way mailbox deletion is: no stored mail is
 * lost. It is still governed, because a removed forwarder silently stops
 * delivering mail someone depends on.
 */
abstract class EmailRoutingRuleState extends StateMachine
{
    /** Recorded locally. Nothing exists at the provider. */
    public const REQUESTED = 'requested';

    /** A governed create operation is in flight. */
    public const PROVISIONING = 'provisioning';

    /** Exists at the provider and was read back. Routing mail. */
    public const ACTIVE = 'active';

    /** Ambiguous provider outcome. Resolved by read-back only. */
    public const RECONCILING = 'reconciling';

    /** Provider refused terminally. The rule does not exist. */
    public const PROVISIONING_FAILED = 'provisioning_failed';

    /** Removal authorised and in flight. */
    public const REMOVING = 'removing';

    /** Gone provider-side. Terminal. */
    public const REMOVED = 'removed';

    public static function initial(): string
    {
        return self::REQUESTED;
    }

    public static function states(): array
    {
        return [
            self::REQUESTED,
            self::PROVISIONING,
            self::ACTIVE,
            self::RECONCILING,
            self::PROVISIONING_FAILED,
            self::REMOVING,
            self::REMOVED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::REQUESTED => [self::PROVISIONING, self::REMOVING],

            self::PROVISIONING => [self::ACTIVE, self::PROVISIONING_FAILED, self::RECONCILING],

            // Never back to PROVISIONING — that is the duplicate-create path.
            self::RECONCILING => [self::ACTIVE, self::PROVISIONING_FAILED, self::REMOVING],

            self::PROVISIONING_FAILED => [self::PROVISIONING, self::REMOVING],

            self::ACTIVE => [self::REMOVING, self::RECONCILING],

            self::REMOVING => [self::REMOVED, self::RECONCILING],

            self::REMOVED => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::REMOVED];
    }

    public static function operational(): array
    {
        return [self::ACTIVE];
    }

    public static function failureStates(): array
    {
        return [self::PROVISIONING_FAILED];
    }

    public static function needsHuman(): array
    {
        return [self::RECONCILING, self::PROVISIONING_FAILED];
    }

    public static function governedTransitions(): array
    {
        return [
            self::ACTIVE . '->' . self::REMOVING,
            self::REMOVING . '->' . self::REMOVED,
        ];
    }

    public static function evidenceRequired(): array
    {
        return [
            self::PROVISIONING . '->' . self::ACTIVE,
            self::RECONCILING . '->' . self::ACTIVE,
            self::REMOVING . '->' . self::REMOVED,
        ];
    }

    public static function requiresEvidence(string $from, string $to): bool
    {
        return in_array($from . '->' . $to, static::evidenceRequired(), true);
    }

    public static function requiresGovernance(string $from, string $to): bool
    {
        return in_array($from . '->' . $to, static::governedTransitions(), true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, static::terminal(), true);
    }
}
