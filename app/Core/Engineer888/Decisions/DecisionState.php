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

    /**
     * Task statuses that mean the run has already been triggered.
     *
     * ── THE SAME MISTAKE, A SECOND TIME (2026-08-14) ────────────────────
     *
     * ActionCardExecutor claims a task with
     * `whereNotIn('status', ['queued','running','recovering'])` and refuses a
     * second press with WORKFLOW_ALREADY_RUNNING. The projection did not know
     * that, so a task Boss had already started still read READY_TO_EXECUTE —
     * a run button whose only outcome was a refusal, which is exactly the
     * expiry defect wearing different clothes.
     *
     * Found by the Decisions page's own test, before the page shipped.
     * The list lives here so the projection, the card issuer and the executor
     * cannot drift apart on what "already running" means.
     */
    public const IN_FLIGHT_TASK_STATUSES = ['queued', 'running', 'recovering'];

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

    // ── WHAT A SURFACE MAY OFFER ────────────────────────────────────────
    //
    // Presentation vocabulary, not authority. Naming an action here does not
    // create permission to take it: approve and execute still go through a card
    // the server issued against exact evidence, and the two view actions change
    // nothing at all. What this list decides is what a human is SHOWN, and that
    // is the thing that was wrong.

    /** Open the candidate and read its bytes. Changes nothing. */
    public const ACTION_VIEW_CANDIDATE = 'view_candidate';

    /** Read the earlier attempts and the evidence behind them. Changes nothing. */
    public const ACTION_VIEW_HISTORY = 'view_history';

    /** Approve these exact bytes. Needs a card and a typed statement. */
    public const ACTION_APPROVE = 'approve';

    /** Refuse these exact bytes. Needs a card. */
    public const ACTION_REJECT = 'reject';

    /** Run the approved bytes. Needs a card AND a live approval. */
    public const ACTION_EXECUTE = 'execute';

    /**
     * What a surface may put in front of Boss for a decision in this state.
     *
     * ── APPROVAL_EXPIRED GETS NO ACTION AT ALL ──────────────────────────
     *
     * Not execute — the ledger would refuse it. Not approve, renew or extend —
     * none of those exist. approve() throws on any row that is not PENDING and
     * record() will not open a second row for the same candidate, so a lapsed
     * approval is TERMINAL for its candidate. The only forward path is a new
     * candidate, and that is a task re-run, which is ordinary governed work
     * that Boss asks for in his own words. It is not a button on a decision
     * card, because a button here would spend provider budget on a press.
     *
     * So an expired decision is explained, not actioned. See explanation().
     *
     * @return array<int,string>
     */
    public static function offerableActions(string $state): array
    {
        return match ($state) {
            self::REVIEW_REQUIRED => [
                self::ACTION_VIEW_CANDIDATE, self::ACTION_VIEW_HISTORY,
                self::ACTION_APPROVE, self::ACTION_REJECT,
            ],
            self::READY_TO_EXECUTE => [
                self::ACTION_VIEW_CANDIDATE, self::ACTION_VIEW_HISTORY,
                self::ACTION_EXECUTE,
            ],
            // APPROVAL_EXPIRED and every terminal state: read-only.
            default => [self::ACTION_VIEW_CANDIDATE, self::ACTION_VIEW_HISTORY],
        };
    }

    /**
     * Why this decision is in the state it is in, in Boss's language.
     *
     * Returned for the states where "what am I looking at" is not obvious from
     * the label. Null where the label already says it.
     */
    public static function explanation(string $state): ?string
    {
        return match ($state) {
            self::APPROVAL_EXPIRED =>
                'Your approval expired before this candidate was executed. Nothing was changed. '
                . 'The candidate is preserved for history, but it can no longer be executed. '
                . 'If you still want to proceed, ask me to run the task again and I will prepare '
                . 'a fresh candidate for review.',
            self::REVOKED    => 'This approval was withdrawn after it was given, so it authorises nothing.',
            self::SUPERSEDED => 'A newer candidate replaced this one, so the approval no longer describes what would be written.',
            default          => null,
        };
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
