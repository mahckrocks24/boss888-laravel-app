<?php

namespace App\Core\Engineer888\Access;

/**
 * What may be done with Engineer888, enumerated.
 *
 * Ten capabilities rather than one "access" flag, because the questions are
 * genuinely different: seeing that Engineer888 exists is not reading a
 * candidate, and reading a candidate is not approving one. A single flag would
 * make every future widening of access an all-or-nothing decision.
 *
 * There is no capability for granting capabilities that Engineer888 itself can
 * hold. `manage_access` exists so the question can be asked and answered NO;
 * the grant table is written by a human through a migration, never by this
 * application.
 */
final class Engineer888Capability
{
    public const DISCOVER = 'engineer888.discover';
    public const VIEW = 'engineer888.view';
    public const MESSAGE = 'engineer888.message';
    public const CREATE_TASK = 'engineer888.create_task';
    public const REVIEW_CANDIDATE = 'engineer888.review_candidate';
    public const APPROVE_CANDIDATE = 'engineer888.approve_candidate';
    public const EXECUTE = 'engineer888.execute';
    public const APPROVE_RECOVERY = 'engineer888.approve_recovery';
    public const APPROVE_MIGRATION = 'engineer888.approve_migration';
    public const MANAGE_ACCESS = 'engineer888.manage_access';

    public const ALL = [
        self::DISCOVER, self::VIEW, self::MESSAGE, self::CREATE_TASK,
        self::REVIEW_CANDIDATE, self::APPROVE_CANDIDATE, self::EXECUTE,
        self::APPROVE_RECOVERY, self::APPROVE_MIGRATION, self::MANAGE_ACCESS,
    ];

    /**
     * Capabilities that change the repository or authorise a change.
     *
     * These are the ones that need a named human, a fresh session and — once it
     * exists — an MFA step-up. Reading is not in this list; refusing to let Mark
     * look at a blocked task because he has not enrolled MFA would be security
     * theatre that costs availability and buys nothing.
     */
    public const HIGH_RISK = [
        self::APPROVE_CANDIDATE, self::EXECUTE,
        self::APPROVE_RECOVERY, self::APPROVE_MIGRATION, self::MANAGE_ACCESS,
    ];

    public static function isHighRisk(string $capability): bool
    {
        return in_array($capability, self::HIGH_RISK, true);
    }
}
