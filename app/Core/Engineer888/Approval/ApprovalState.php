<?php

namespace App\Core\Engineer888\Approval;

/**
 * The life of an approval.
 *
 * Six states, and the five that are not APPROVED all mean the same thing to
 * IMPLEMENT: do not write. They are kept distinct anyway, because "the human
 * said no" and "the human said yes to something else" and "the human said yes
 * three days ago" are different facts, and collapsing them would make the audit
 * trail unable to answer why a task stopped.
 */
final class ApprovalState
{
    /** Recorded, not yet answered. */
    public const PENDING = 'PENDING';

    /** A named human approved these exact bytes. */
    public const APPROVED = 'APPROVED';

    /** A named human refused them. */
    public const REJECTED = 'REJECTED';

    /** A newer candidate exists for this task, so this approval is stale. */
    public const SUPERSEDED = 'SUPERSEDED';

    /** Approved too long ago to still describe the repository. */
    public const EXPIRED = 'EXPIRED';

    /** Withdrawn after the fact. */
    public const REVOKED = 'REVOKED';

    public const ALL = [
        self::PENDING, self::APPROVED, self::REJECTED,
        self::SUPERSEDED, self::EXPIRED, self::REVOKED,
    ];

    /**
     * Only one state permits a write, and it is not enough on its own — the
     * binding must also still match. See ApprovalLedger::enforce().
     */
    public static function permitsExecution(string $state): bool
    {
        return $state === self::APPROVED;
    }

    /** States that can still become something else. */
    public static function isOpen(string $state): bool
    {
        return in_array($state, [self::PENDING, self::APPROVED], true);
    }
}
