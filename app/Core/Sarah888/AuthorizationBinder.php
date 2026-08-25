<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — what a later "yes" is actually allowed to authorise.
 *
 * THE GAP THIS CLOSES.
 * ChatActionProposal::resolveAuthorization() has existed since Phase 1Y and had
 * zero production callers — only tests. So even where a pending authorization
 * existed, a "yes" typed into chat had no code path to it: the planner simply
 * ran again on the next turn, and whatever it chose became the authorised
 * thing. Approval reached proposals only through the approvals UI
 * (ApprovalController) or an explicit proposal id (agents-03).
 *
 * This is the missing half. Given the owner's turn, it decides — before any
 * planning happens — whether that turn authorises exactly one pending offer,
 * and refuses in every case where it does not.
 *
 * WHY IT DECIDES RATHER THAN EXECUTES.
 * Approval already has one correct implementation:
 * ProactiveStrategyEngine::approveProposal() reserves credits, builds the task
 * from the stored payload and marks the proposal approved, and it is the path
 * the approvals UI has used all along. A second executor would be a second
 * source of truth for what "approved" means. So this returns a decision and the
 * caller runs the existing engine.
 *
 * WHY A BARE "YES" IS NOT ALLOWED TO GUESS.
 * With two offers outstanding, picking one has a fifty per cent chance of
 * spending the owner's credits on the thing they did not mean. Ambiguity is
 * reported back, never resolved by preference, recency or cost.
 */
class AuthorizationBinder
{
    public const NOT_AUTHORIZATION = 'not_authorization';
    public const NONE_PENDING      = 'none_pending';
    public const AMBIGUOUS         = 'ambiguous';
    public const AUTHORIZED        = 'authorized';
    public const REFUSED           = 'refused';
    public const CANCELLED         = 'cancelled';

    /** The owner withdrawing an offer before approving it. */
    private const CANCELLATION = '/\b(?:no,? (?:don\'?t|do not)|don\'?t\b|do not\b|cancel|forget it|leave it|'
                               . 'never ?mind|stop|scrap it|not now|hold off|abort)\b/i';

    /**
     * Impatience with the PROTOCOL, not withdrawal of the WORK.
     *
     * T033: "You know I always approve these. Just do it and stop asking me."
     * came back as "Cancelled - nothing was created or charged", and every
     * pending proposal was rejected in the database. CANCELLATION carries a
     * bare `stop`, and it is tested before authorisation, so the owner's
     * `do it` was never reached. `don't` has the same hole: "don't ask me
     * again, just publish it" reverses the same way.
     *
     * The object is what separates them. "Stop" applied to asking, checking
     * or confirming is about how Sarah behaves; "stop" applied to anything
     * else is about the work. These spans are removed before the withdrawal
     * test, so a genuine cancellation elsewhere in the same message still
     * wins - this narrows what counts as withdrawal, it does not reorder the
     * checks, and withdrawal is still decided before consent.
     */
    private const PROTOCOL_COMPLAINT = '/\b(?:stop|don\'?t|do not|quit|no more|cut out|without)\s+'
                                     . '(?:the\s+)?(?:asking|ask|checking|check(?:ing)?\s+with|confirming|'
                                     . 'confirm|bugging|pestering|nagging|prompting|questioning|'
                                     . 'second[- ]guessing|hesitating|double[- ]checking)\b/i';

    /**
     * The message with protocol complaints removed. What remains is what the
     * owner said about the WORK.
     */
    private function withdrawalText(string $msg): string
    {
        return (string) preg_replace(self::PROTOCOL_COMPLAINT, ' ', $msg);
    }

    /**
     * An affirmative opener, used ONLY while something is pending.
     *
     * SpendPolicy::assessTurn recognises bare consent — "yes, go ahead", "do
     * it", "proceed" — but not the most natural way anyone approves a specific
     * offer: "Yes, publish the stockist directory." That reads as a directive
     * to its DIRECTIVE regex, so before this the specific yes fell through to
     * fresh planning and the pending offer was left standing. Measured as a
     * failure in 2ftest before it was fixed.
     *
     * Anchored at the start, so "You approved that already" cannot match — that
     * is the owner challenging an approval, and assessTurn already refuses to
     * read it as consent. Gated on something being pending, so the same words
     * with no outstanding offer stay ordinary instructions and go to the
     * planner as they always did.
     */
    private const AFFIRMATIVE = '/^\s*(?:yes|yeah|yep|yup|ok|okay|sure|confirmed?|approved?|'
                              . 'agreed|please\s+do|go\s+ahead|do\s+it|proceed|run\s+it)\b/i';

    public function __construct(
        private ChatActionProposal $proposals,
        private SpendPolicy $policy,
    ) {}

    /**
     * @return array{outcome:string, proposal:object|null, reason:string,
     *               message:string, pending:int}
     */
    public function bind(int $wsId, ?string $conversationId, string $userMessage, ?int $userId = null): array
    {
        $out = fn(string $o, string $msg, ?object $p = null, string $reason = '', int $pending = 0) =>
            ['outcome' => $o, 'proposal' => $p, 'reason' => $reason,
             'message' => $msg, 'pending' => $pending];

        $msg = trim($userMessage);
        if ($msg === '') return $out(self::NOT_AUTHORIZATION, '');

        $pending = $this->proposals->pending($wsId, $conversationId);

        // Withdrawal is checked BEFORE authorisation. "No, don't publish it"
        // contains "publish" and would otherwise read as a directive, and a
        // withdrawal misread as consent is the worst failure this class can
        // have.
        if (preg_match(self::CANCELLATION, $this->withdrawalText($msg))) {
            if (!$pending) return $out(self::NOT_AUTHORIZATION, '');
            $ids = array_map(fn($p) => (int) $p->id, $pending);
            $this->withdraw($wsId, $ids, $userId);
            $n = count($ids);
            return $out(self::CANCELLED,
                $n === 1 ? "Cancelled — nothing was created or charged."
                         : "Cancelled all {$n} — nothing was created or charged.",
                null, 'owner withdrew', $n);
        }

        $turn   = $this->policy->assessTurn($msg);
        $isAuth = ($turn['classification'] ?? '') === 'authorisation'
               || ($pending && preg_match(self::AFFIRMATIVE, $msg));

        if (!$isAuth) {
            return $out(self::NOT_AUTHORIZATION, '', null, '', count($pending));
        }

        if (!$pending) {
            return $out(self::NONE_PENDING,
                "There's nothing waiting for your approval, so there's nothing for me to run. "
              . "Tell me what you'd like done and I'll put it in front of you with the cost first.",
                null, 'nothing pending');
        }

        if (count($pending) === 1) {
            // One offer, one yes: unambiguous whether or not it was named —
            // UNLESS the turn names a different item, in which case the owner
            // is approving something that is not on the table and the safe
            // reading is to run nothing.
            $target = $pending[0];
            if ($this->namesADifferentItem($target, $msg)) {
                return $out(self::REFUSED,
                    $this->refusalFor(ChatActionProposal::AUTH_ENTITY_CHANGED, $target),
                    $target, ChatActionProposal::AUTH_ENTITY_CHANGED, 1);
            }
        } else {
            $target = $this->matchNamed($pending, $msg);
        }

        if ($target === null) {
            return $out(self::AMBIGUOUS,
                trim($this->proposals->disclosure($wsId, $conversationId)),
                null, 'more than one pending and the turn named none of them', count($pending));
        }

        // Re-validate WHAT THE OWNER WAS TOLD against WHAT WOULD ACTUALLY RUN.
        //
        // The first version of this passed the row's own total_credits and
        // scope_hash back into authorize(), which compared each column with
        // itself and could therefore never fail — a check that always passes is
        // worse than no check, because it reads like protection. 2ftest caught
        // it: a proposal whose scope_hash had been altered still authorised.
        //
        // The summary columns record the offer as it was made; the stored
        // payload is what approval would execute. Consent covers the action
        // only while those two still agree, so the expectation is derived from
        // the payload and tested against the columns.
        $stored = $this->proposals->payloadFor($target) ?? [];
        $check  = $this->proposals->authorize($wsId, $conversationId, (int) $target->id, [
            'credit_cost' => (int) ($stored['credit_cost'] ?? 0),
            'scope_hash'  => $this->proposals->scopeHash($stored),
        ]);

        if (!$check['ok']) {
            return $out(self::REFUSED, $this->refusalFor($check['reason'], $target),
                        $target, $check['reason'], count($pending));
        }

        return $out(self::AUTHORIZED, '', $target, ChatActionProposal::AUTH_OK, count($pending));
    }

    /**
     * The single pending offer this turn names, or null.
     *
     * Matching is on the entity id and on distinctive words from the offer's
     * description. Null on a tie AND null on no match: both mean the owner has
     * not said which one, and the correct response to that is to ask, not to
     * choose.
     */
    private function matchNamed(array $pending, string $msg): ?object
    {
        $norm = mb_strtolower($msg);
        $scores = [];

        foreach ($pending as $p) {
            $score = 0;

            if (!empty($p->entity_id) && preg_match('/\b' . preg_quote((string) $p->entity_id, '/') . '\b/', $norm)) {
                $score += 10;
            }

            foreach ($this->distinctiveWords((string) $p->description) as $w) {
                if (str_contains($norm, $w)) $score++;
            }

            $scores[(int) $p->id] = $score;
        }

        arsort($scores);
        $ids  = array_keys($scores);
        $best = $scores[$ids[0]] ?? 0;

        if ($best === 0) return null;                                  // named nothing
        if (count($ids) > 1 && ($scores[$ids[1]] ?? 0) === $best) return null;  // tie

        foreach ($pending as $p) if ((int) $p->id === $ids[0]) return $p;
        return null;
    }

    /**
     * The turn explicitly names an item that is not the one on offer.
     *
     * Only identifiers are considered, and only three digits or more: a "yes,
     * in 30 minutes" must not be read as naming article 30. Where the turn
     * carries identifiers and none of them is the pending item's, consent has
     * been given for something that was never offered, and the safe direction
     * is to refuse — the owner can restate it and lose nothing but a turn.
     */
    private function namesADifferentItem(object $target, string $msg): bool
    {
        if (empty($target->entity_id)) return false;
        if (!preg_match_all('/\b\d{3,}\b/', $msg, $m)) return false;

        return !in_array((string) $target->entity_id, $m[0], true);
    }

    /**
     * Words worth matching on. Common verbs are stripped because every publish
     * proposal contains "publish" — matching on it would make two offers score
     * identically and defeat the tie check that exists to protect the owner.
     */
    private function distinctiveWords(string $description): array
    {
        $stop = ['the','a','an','and','or','to','for','of','it','this','that','publish',
                 'send','delete','remove','page','article','post','now','please'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($description), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($words,
            fn($w) => mb_strlen($w) > 2 && !in_array($w, $stop, true)));
    }

    /** Withdraw offers the owner has declined, so a later "yes" cannot revive them. */
    private function withdraw(int $wsId, array $proposalIds, ?int $userId = null): void
    {
        if (!$proposalIds) return;

        DB::table('strategy_proposals')->where('workspace_id', $wsId)
            ->whereIn('id', $proposalIds)
            ->update(['status' => 'rejected', 'superseded_reason' => 'owner_cancelled',
                      'updated_at' => now()]);

        // RISK-0056 (2026-08-25): the owner declining in chat is a REAL human decision.
        // Capture WHO decided + actor_type + an audit row, not just a Log::info that
        // dropped the user — so a decline is attributable in the governance record.
        $apprIds = DB::table('approvals')->where('workspace_id', $wsId)
            ->whereIn('proposal_id', $proposalIds)->where('status', 'pending')->pluck('id')->all();

        DB::table('approvals')->where('workspace_id', $wsId)
            ->whereIn('proposal_id', $proposalIds)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_at' => now(),
                      'decision_by' => $userId, 'decision_actor_type' => $userId ? 'user' : 'system',
                      'decision_note' => 'Owner declined in Sarah chat', 'updated_at' => now()]);

        if ($apprIds) {
            $audit = app(\App\Core\Audit\AuditLogService::class);
            foreach ($apprIds as $aid) {
                $audit->log($wsId, $userId, 'approval.rejected', 'Approval', (int) $aid,
                    ['source' => 'AuthorizationBinder::withdraw', 'reason' => 'owner_cancelled',
                     'actor_type' => $userId ? 'user' : 'system']);
            }
        }

        Log::info('[Sarah888] pending authorizations withdrawn by the owner', [
            'workspace_id' => $wsId, 'proposal_ids' => $proposalIds, 'user_id' => $userId,
        ]);
    }

    /** Say WHY, in the owner's terms. A refusal they cannot act on is a dead end. */
    private function refusalFor(string $reason, object $p): string
    {
        return match ($reason) {
            ChatActionProposal::AUTH_EXPIRED =>
                "That request has expired, so I haven't run it — nothing was created or charged. "
              . "Ask me again and I'll put it back in front of you.",
            ChatActionProposal::AUTH_COST_CHANGED =>
                "The cost has changed since I offered it, so I haven't run it on the old price. "
              . "Tell me to go ahead and I'll requote it first.",
            ChatActionProposal::AUTH_SCOPE_CHANGED =>
                "What that would do has changed since I offered it, so your approval no longer "
              . "covers it. Nothing has run. Ask me again and I'll re-state the scope.",
            ChatActionProposal::AUTH_ENTITY_CHANGED =>
                "That approval doesn't match the item I offered, so I haven't run anything.",
            ChatActionProposal::AUTH_NOT_PENDING =>
                "That one has already been dealt with, so there was nothing left to approve.",
            ChatActionProposal::AUTH_WRONG_CONVERSATION,
            ChatActionProposal::AUTH_WRONG_WORKSPACE =>
                "That approval doesn't belong to this conversation, so I haven't acted on it.",
            default =>
                "I couldn't safely match that approval to what I offered, so nothing has run.",
        };
    }
}
