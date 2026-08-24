<?php

namespace App\Engines\Infrastructure\Email\States;

use App\Engines\Infrastructure\States\StateMachine;

/**
 * INFRA888 · E1 — CATCH-ALL LIFECYCLE.
 *
 * WHY A STATE MACHINE AND NOT A BOOLEAN
 * "Catch-all is on" has at least four distinct meanings that a boolean cannot
 * tell apart: we have not configured it, we asked the provider and are waiting,
 * the provider confirmed it, and the provider rejected it. StateMachine.php's
 * own docblock records what happened the last time this project modelled a
 * multi-step externally-dependent flow as booleans — a half-provisioned domain
 * became indistinguishable from a working one. The same trap applies here, so
 * there is no `enabled` column; the state IS the answer.
 *
 * `disabled` is the initial state AND a legitimate destination. Nothing here is
 * terminal: a catch-all is switched on and off for the life of the domain, and
 * the row is deleted only when its domain is.
 *
 * OPERATIONAL NOTE, not enforced here: a catch-all accepts mail for every
 * unassigned local part, which is why it is a well-known spam magnet. That is a
 * product warning for the customer surface (E4), not a lifecycle constraint.
 */
final class EmailCatchAllState extends StateMachine
{
    /** No catch-all in effect. The safe default. */
    public const DISABLED = 'disabled';

    /** A governed configure operation is in flight. */
    public const CONFIGURING = 'configuring';

    /** Provider confirmed and read back. Unassigned mail is being delivered. */
    public const ENABLED = 'enabled';

    /** A governed disable operation is in flight. */
    public const DISABLING = 'disabling';

    /** Provider refused terminally. Nothing is catching mail. */
    public const CONFIGURATION_FAILED = 'configuration_failed';

    /** Ambiguous provider outcome. Resolved by read-back only. */
    public const RECONCILING = 'reconciling';

    public static function initial(): string
    {
        return self::DISABLED;
    }

    public static function states(): array
    {
        return [
            self::DISABLED,
            self::CONFIGURING,
            self::ENABLED,
            self::DISABLING,
            self::CONFIGURATION_FAILED,
            self::RECONCILING,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::DISABLED => [self::CONFIGURING],

            self::CONFIGURING => [self::ENABLED, self::CONFIGURATION_FAILED, self::RECONCILING],

            // Reconciliation may land on either real answer. It may not return
            // to CONFIGURING, which would re-issue the mutation blind.
            self::RECONCILING => [self::ENABLED, self::DISABLED, self::CONFIGURATION_FAILED],

            self::CONFIGURATION_FAILED => [self::CONFIGURING, self::DISABLED],

            // Changing the target of a live catch-all is a re-configure.
            self::ENABLED => [self::CONFIGURING, self::DISABLING, self::RECONCILING],

            self::DISABLING => [self::DISABLED, self::RECONCILING],
        ];
    }

    /** Nothing is terminal: a catch-all is toggled for the life of the domain. */
    public static function terminal(): array
    {
        return [];
    }

    public static function operational(): array
    {
        return [self::ENABLED];
    }

    public static function failureStates(): array
    {
        return [self::CONFIGURATION_FAILED];
    }

    public static function needsHuman(): array
    {
        return [self::RECONCILING, self::CONFIGURATION_FAILED];
    }

    /** States in which a delivery target must be present. */
    public static function requiresTarget(): array
    {
        return [self::CONFIGURING, self::ENABLED, self::DISABLING, self::RECONCILING];
    }

    public static function governedTransitions(): array
    {
        return [
            self::DISABLED . '->' . self::CONFIGURING,
            self::ENABLED . '->' . self::CONFIGURING,
            self::ENABLED . '->' . self::DISABLING,
        ];
    }

    public static function evidenceRequired(): array
    {
        return [
            self::CONFIGURING . '->' . self::ENABLED,
            self::RECONCILING . '->' . self::ENABLED,
            self::RECONCILING . '->' . self::DISABLED,
            self::DISABLING . '->' . self::DISABLED,
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
}
