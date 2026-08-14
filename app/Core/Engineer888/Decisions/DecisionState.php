<?php

namespace App\Core\Engineer888\Decisions;

use App\Core\Engineer888\Approval\ApprovalState;

/**
 * What a decision currently IS, in the words a human would use.
 *
 * ── WHY THIS IS NOT ApprovalState ───────────────────────────────────
 *
 * ApprovalState describes one ledger row. A decision is a logical work item
 * that may have no approval row at all (nobody has been asked yet), and the
 * question a surface needs answered is not "what does the row say" but "what
 * does Boss have to do about this". REVIEW_REQUIRED has no ApprovalState —
 * it is precisely the ABSENCE of a decided one.
 *
 * ── THE DISTINCTION THIS EXISTS TO KEEP ─────────────────────────────
 *
 * APPROVED and READY_TO_EXECUTE are not the same fact and must never be
 * rendered as if they were. On 2026-08-14 two approvals sat in the database
 * reading APPROVED, approver "Mark", with a timestamp — all true, all history.
 * Their 12-hour window had passed, so ApprovalLedger::enforce() would refuse
 * them. The projection offered both as ready to run.
 *
 * Nothing was unsafe: the gate held, and would have refused on the press. What
 * was wrong was the sentence the interface was saying. A control that looks
 * available implies an authority that is not there, and the moment a human
 * trusts that implication the governance layer is protecting a lie.
 *
 * So: the historical fact (Mark approved candidate X at time Y) is never
 * rewritten. The current authority (that approval no longer permits execution)
 * is stated separately, and it is the one the interface acts on.
 */
final class DecisionState
{
    /** A candidate exists and nobody has judged its bytes yet. */
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /** Approved, inside its window, and nothing has run it. Pressable. */
    public const READY_TO_EXECUTE = 'READY_TO_EXECUTE';

    /**
     * Approved by a named human, but the window closed before anything ran it.
     *
     * Still needs Boss. NOT executable, and not silently dropped either — the
     * work is real and the approval genuinely happened.
     */
    public const APPROVAL_EXPIRED = 'APPROVAL_EXPIRED';

    /** The work ran and finished. Terminal. */
    public const COMPLETED = 'COMPLETED';

    /** A human refused these bytes. Terminal. */
    public const REJECTED = 'REJECTED';

    /** An approval was withdrawn after the fact. Terminal. */
    public const REVOKED = 'REVOKED';

    /** A newer candidate replaced this one. Terminal. */
    public const SUPERSEDED = 'SUPERSEDED';

    /** The workflow stopped and cannot proceed without something else first. */
    public const BLOCKED = 'BLOCKED';

    public const ALL = [
        self::REVIEW_REQUIRED, self::READY_TO_EXECUTE, self::APPROVAL_EXPIRED,
        self::COMPLETED, self::REJECTED, self::REVOKED, self::SUPERSEDED, self::BLOCKED,
    ];

    /**
     * States that still want something from Boss.
     *
     * This is what the header counts and what the Decisions page files under
     * NEEDS ATTENTION. An expired approval is in here deliberately: the work
     * did not go away when the window closed.
     */
    public const ATTENTION_REQUIRED = [
        self::REVIEW_REQUIRED, self::READY_TO_EXECUTE, self::APPROVAL_EXPIRED,
    ];

    /** Is this a state a human still has to do something about? */
    public static function needsAttention(string $state): bool
    {
        return in_array($state, self::ATTENTION_REQUIRED, true);
    }

    /** Only one state means "this can run right now". */
    public static function isExecutable(string $state): bool
    {
        return $state === self::READY_TO_EXECUTE;
    }

    /**
     * The decision state an approval row in this ledger state produces.
     *
     * Takes the EFFECTIVE state from ApprovalLedger::authorityState(), never a
     * raw column read — that is the whole point of the seam.
     */
    public static function fromAuthorityState(string $authorityState): string
    {
        return match ($authorityState) {
            ApprovalState::APPROVED   => self::READY_TO_EXECUTE,
            ApprovalState::EXPIRED    => self::APPROVAL_EXPIRED,
            ApprovalState::REJECTED   => self::REJECTED,
            ApprovalState::REVOKED    => self::REVOKED,
            ApprovalState::SUPERSEDED => self::SUPERSEDED,
            ApprovalState::PENDING    => self::REVIEW_REQUIRED,
            default                   => self::BLOCKED,
        };
    }
}
