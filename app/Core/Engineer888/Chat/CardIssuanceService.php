<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cards come from the workflow, never from the conversation.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE: a card may exist only when a live
 * Engineer888 record genuinely requires a human decision. Not because someone
 * typed "approve", not because a message mentions a candidate, not because a
 * card existed a minute ago. Every issuance below starts from a query against
 * engineering_* tables and stops if the record does not say a decision is due.
 *
 * WHY THAT MATTERS. A card is an authority. One issued from chat wording would
 * let the conversation manufacture its own permission — the approver would be
 * pressing a button that exists because they asked for it, not because the work
 * needs it. The whole binding mechanism downstream would be protecting a lie.
 *
 * NOTHING IS EVER MUTATED IN PLACE. When evidence moves, the old card is
 * revoked and a new one issued, so the record of what was offered — and against
 * what — survives. A card whose fingerprint was quietly rewritten would be
 * indistinguishable from one that had always said that.
 */
final class CardIssuanceService
{
    public function __construct(private ActionCardService $cards) {}

    /**
     * Reconcile the conversation's open cards against live workflow state.
     *
     * Idempotent by construction: it computes what SHOULD be open, revokes what
     * should not be, and issues only what is missing. Polling it a hundred times
     * produces the same set — which is the requirement, since both surfaces poll.
     *
     * @return array<int,object> the currently open cards
     */
    public function reconcile(?Request $request, object $conversation): array
    {
        if (! ChatOwner::is($request)) {
            return [];
        }

        // ── RELEVANCE IS A BACKEND CONCERN (2026-08-13) ──────────────────
        //
        // Measured before this line existed: eligible() answered globally and
        // this conversation carried 101 live cards — 52 for the active project
        // and 49 for projects it was not on — while visibleFor() capped at 50
        // by id. Which decisions Boss could see was therefore decided by
        // insertion order.
        //
        // Cards are a conversational construct. A decision that belongs to a
        // repository this conversation is not pointed at is not a decision the
        // conversation should be minting, so eligibility is scoped to the
        // active project. Nothing is deleted and nothing is refused: those
        // decisions remain real, remain in the database, and remain reachable
        // from the Command Center, which reads tasks and candidates directly
        // and never depended on chat cards.
        $eligible = $this->eligible($request, $this->activeProjectId($conversation));
        $wanted = [];

        foreach ($eligible as $e) {
            $wanted[$this->identity($conversation, $e)] = $e;
        }

        $open = ChatOwner::scope(
            DB::table('e888_action_cards')->where('conversation_id', $conversation->id)
        )->whereNull('consumed_at')->whereNull('revoked_at')->get();

        // SUPERSESSION. Any open card whose identity is no longer wanted is
        // revoked: the candidate was superseded, the fingerprint moved, the
        // stage advanced, or the decision was taken elsewhere.
        //
        // AN EXPIRED CARD COUNTS AS STALE, NOT AS PRESENT. This is what made
        // 21 live cards invisible: reconciliation filtered only consumed and
        // revoked, so a lapsed card still satisfied its identity and no
        // replacement was issued — while visibleFor() correctly hid it. The
        // decision was still genuinely due; the offer to make it had simply
        // aged out. Retiring it and issuing a fresh one preserves the history
        // and restores the card.
        foreach ($open as $card) {
            $id = $this->identityOfStored($card);
            $expired = $card->expires_at !== null && now()->greaterThan($card->expires_at);

            if ($expired || ! isset($wanted[$id])) {
                ChatOwner::scope(DB::table('e888_action_cards')->where('id', $card->id))
                    ->whereNull('consumed_at')->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'consumed_result' => $expired ? 'expired' : 'superseded_by_evidence',
                        'updated_at' => now(),
                    ]);
            }
        }

        // Re-read after revocation so the existence check below is accurate.
        $stillOpen = ChatOwner::scope(
            DB::table('e888_action_cards')->where('conversation_id', $conversation->id)
        )->whereNull('consumed_at')->whereNull('revoked_at')
         ->where(function ($q) {
             $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
         })->get();

        $have = [];
        foreach ($stillOpen as $card) {
            $have[$this->identityOfStored($card)] = $card;
        }

        // IDEMPOTENCY. An identical valid card is returned, never re-issued.
        foreach ($wanted as $id => $e) {
            if (isset($have[$id])) {
                continue;
            }

            $card = $this->cards->issue(
                $request,
                $conversation,
                // THE CONVERSATIONAL ANCHOR (2026-08-13). message_id has
                // existed since this table was created and was NULL on all
                // 2117 rows, so every card floated free of the exchange that
                // caused it. The anchor is the message CARRYING THIS TASK, not
                // the newest message: a candidate becomes ready many turns
                // after the request, and attaching its approval to whatever was
                // said last would put it under an unrelated sentence.
                $this->anchorMessageId($conversation, $e['task_uuid']),
                $e['action_type'],
                $e['task_uuid'],
                $e['target_uuid']
            );

            $have[$id] = $card;
        }

        return array_values($have);
    }

    /**
     * Every decision the workflow is currently waiting on.
     *
     * Read-only. Each block states the condition that makes a human decision
     * genuinely due, and returns nothing when it is not.
     *
     * @return array<int,array{action_type:string,task_uuid:?string,target_uuid:?string}>
     */
    /**
     * The project this conversation is pointed at, if any.
     *
     * Re-read rather than trusted from the passed object: a selection made on
     * another surface a moment ago must be honoured, and a stale pointer would
     * scope cards to the wrong repository.
     */
    /**
     * The message this action belongs under, if the conversation has one.
     *
     * Returns null rather than a fallback. A wrong anchor is worse than none:
     * an approval drawn under an unrelated sentence misrepresents what was
     * being discussed when the decision arose.
     */
    private function anchorMessageId(object $conversation, ?string $taskUuid): ?int
    {
        if ($taskUuid === null) { return null; }

        $id = DB::table('e888_messages')
            ->where('conversation_id', $conversation->id)
            ->where('task_uuid', $taskUuid)
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function activeProjectId(object $conversation): ?int
    {
        $row = DB::table('e888_conversations')->where('id', $conversation->id)->first(['active_project_id']);

        return $row?->active_project_id === null ? null : (int) $row->active_project_id;
    }

    public function eligible(?Request $request, ?int $projectId = null): array
    {
        $access = new Engineer888Access();
        $out = [];

        // ── A. Candidate awaiting exact review ───────────────────────────
        // VALIDATED, not superseded, and no decision recorded against it yet.
        //
        // A PENDING row is NOT a decision. RequestApprovalStage writes one when
        // it asks for the decision — ApprovalState::PENDING is "recorded, not
        // yet answered" — so joining it here made the request for approval
        // suppress the card that would answer it, and every task that reached
        // REQUEST_APPROVAL through the workflow became unapprovable from chat.
        // Found in the browser on 2026-08-10; the fixtures in this area had only
        // ever inserted already-decided approvals.
        $awaiting = DB::table('engineering_candidates as c')
            ->leftJoin('engineering_candidate_approvals as a', function ($j) {
                $j->on('a.candidate_id', '=', 'c.id')
                  ->whereNull('a.revoked_at')->whereNull('a.superseded_at')
                  ->where('a.state', '!=', ApprovalState::PENDING);
            })
            ->join('engineering_tasks as t', 't.id', '=', 'c.task_id')
            ->whereNull('c.superseded_at')
            ->where('c.status', 'VALIDATED')
            ->whereNull('a.id')
            ->whereNotIn('t.status', ['completed', 'failed'])
            ->when($projectId !== null, fn ($q) => $q->where('t.project_id', $projectId))
            // NEWEST CANDIDATE PER TASK ONLY.
            //
            // The acceptance loop left 26 tasks with identical titles, each
            // carrying its own VALIDATED candidate and its own PENDING
            // approval. None supersedes another because supersession is
            // per-task and these are separate tasks — so all 26 were
            // legitimately "awaiting a decision" and all 26 were offered at
            // once, indistinguishable from each other.
            //
            // A task has one current proposal. An older candidate on the same
            // task is history, not a second decision.
            ->whereRaw('c.id = (select max(c2.id) from engineering_candidates c2
                                 where c2.task_id = c.task_id and c2.superseded_at is null
                                   and c2.status = ?)', ['VALIDATED'])
            ->orderByDesc('c.id')
            ->select('c.uuid as cuuid', 't.uuid as tuuid')
            ->get();

        foreach ($awaiting as $row) {
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::APPROVE_CANDIDATE)) {
                $out[] = ['action_type' => ActionCardService::APPROVE_CANDIDATE, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::REVIEW_CANDIDATE)) {
                $out[] = ['action_type' => ActionCardService::REJECT_CANDIDATE, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
        }

        // ── B. Approved candidate awaiting implementation ────────────────
        // An approval that is live, and a task that has not yet run it.
        //
        // "LIVE" IS THE LEDGER'S WORD, NOT THIS QUERY'S (2026-08-14). The
        // predicate below reads `approved_at IS NOT NULL AND NOT revoked AND
        // NOT superseded`, and for a long time that was the whole test. It does
        // not catch an approval whose TTL has passed — enforce() writes
        // state=EXPIRED on such a row, but approved_at stays set and neither
        // revoked_at nor superseded_at is ever touched, so the row sailed
        // straight through and this service minted an execute_task card for it.
        //
        // A CARD IS AN AUTHORITY. Issuing one against an approval that the gate
        // will refuse hands Boss a button whose only possible outcome is a
        // refusal — the exact "control that looks available" failure the header
        // comment of this class is about. So the rows are fetched, and
        // ApprovalLedger decides which of them still permit a run.
        $approved = DB::table('engineering_candidate_approvals as a')
            ->join('engineering_candidates as c', 'c.id', '=', 'a.candidate_id')
            ->join('engineering_tasks as t', 't.id', '=', 'a.task_id')
            ->whereNotNull('a.approved_at')
            ->whereNull('a.revoked_at')->whereNull('a.superseded_at')
            ->whereNull('c.superseded_at')
            ->whereNotIn('t.status', ['completed', 'failed'])
            // AND NOT ONE ALREADY IN FLIGHT. ActionCardExecutor claims a task
            // with whereNotIn(status, queued|running|recovering) and refuses a
            // second press with WORKFLOW_ALREADY_RUNNING, so a card issued for
            // a task that is already going is another button that can only
            // fail. Same list, one definition.
            ->whereNotIn('t.status', \App\Core\Engineer888\Decisions\DecisionState::IN_FLIGHT_TASK_STATUSES)
            ->when($projectId !== null, fn ($q) => $q->where('t.project_id', $projectId))
            ->select('c.uuid as cuuid', 't.uuid as tuuid', 'a.state', 'a.expires_at')
            ->get();

        $ledger = new ApprovalLedger();

        foreach ($approved as $row) {
            // Same call the projection makes and the same rule enforce() applies.
            if (! $ledger->permitsExecutionNow($row)) {
                continue;
            }

            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::EXECUTE)) {
                $out[] = ['action_type' => ActionCardService::EXECUTE_TASK, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
            // E. The same live approval is revocable while it remains unspent.
            //
            // Revocation follows execution deliberately. Withdrawing an
            // approval that can no longer authorise anything is not a decision
            // Boss needs offered; the window already did it.
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::APPROVE_CANDIDATE)) {
                $out[] = ['action_type' => ActionCardService::REVOKE_APPROVAL, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
        }

        // ── C. Recovery awaiting a human ─────────────────────────────────
        // BLOCKED is the state that means "a person must look at this".
        // engineering_recoveries carries no uuid of its own: a recovery is
        // identified by the candidate it is recovering. Selecting a `uuid`
        // column here was a defect — the table has task_uuid and candidate_uuid.
        $recoveries = DB::table('engineering_recoveries as rc')
            ->join('engineering_tasks as t', 't.uuid', '=', 'rc.task_uuid')
            ->where('rc.status', 'FAILED_RECOVERY_BLOCKED')
            ->whereNull('rc.approved_at')
            ->when($projectId !== null, fn ($q) => $q->where('t.project_id', $projectId))
            ->get(['rc.task_uuid', 'rc.candidate_uuid']);

        foreach ($recoveries as $row) {
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::APPROVE_RECOVERY)) {
                $out[] = ['action_type' => ActionCardService::APPROVE_RECOVERY, 'task_uuid' => $row->task_uuid, 'target_uuid' => $row->candidate_uuid];
            }
        }

        // ── D. Migration with a CURRENT PASSED rehearsal ─────────────────
        // No rehearsal, a stale one, or one that did not pass means no card.
        // A migration approved on an unrehearsed change is the defect class
        // this platform has already paid for once.
        $migrations = DB::table('engineering_migration_rehearsals as r')
            ->join('engineering_candidates as c', 'c.uuid', '=', 'r.candidate_uuid')
            ->where('r.status', 'PASSED')
            ->whereNull('c.superseded_at')
            ->join('engineering_tasks as mt', 'mt.uuid', '=', 'r.task_uuid')
            ->when($projectId !== null, fn ($q) => $q->where('mt.project_id', $projectId))
            ->select('r.task_uuid', 'r.candidate_uuid')
            ->get();

        foreach ($migrations as $row) {
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::APPROVE_MIGRATION)) {
                $out[] = ['action_type' => ActionCardService::APPROVE_MIGRATION, 'task_uuid' => $row->task_uuid, 'target_uuid' => $row->candidate_uuid];
            }
        }

        return $out;
    }

    /**
     * The identity of an active card.
     *
     * Owner, conversation, action, task, target — plus the fingerprint and
     * workflow state, which are what make it an identity rather than a label.
     * Two cards for the same task and action are the SAME card only while the
     * evidence beneath them is unchanged; the moment it moves, the identity
     * moves with it and the old one is superseded.
     */
    private function identity(object $conversation, array $e): string
    {
        $binding = $this->bindingFor($e);

        return implode('|', [
            ChatOwner::USER_ID,
            $conversation->uuid,
            $e['action_type'],
            $e['task_uuid'] ?? '-',
            $e['target_uuid'] ?? '-',
            $binding['project_id'] ?? '-',
            $binding['content_fingerprint'] ?? '-',
            $binding['workflow_state'] ?? '-',
        ]);
    }

    private function identityOfStored(object $card): string
    {
        return implode('|', [
            (int) $card->owner_user_id,
            $this->conversationUuid((int) $card->conversation_id),
            $card->action_type,
            $card->task_uuid ?? '-',
            ($card->candidate_uuid ?: $card->recovery_uuid) ?? '-',
            $card->project_id ?? '-',
            $card->content_fingerprint ?? '-',
            $card->workflow_state ?? '-',
        ]);
    }

    /** Reuses the card service's own binding resolver so the two cannot disagree. */
    private function bindingFor(array $e): array
    {
        $m = new \ReflectionMethod(ActionCardService::class, 'currentBinding');
        $m->setAccessible(true);

        return $m->invoke($this->cards, $e['action_type'], $e['task_uuid'], $e['target_uuid']);
    }

    private function conversationUuid(int $id): string
    {
        static $cache = [];

        return $cache[$id] ??= (string) DB::table('e888_conversations')->where('id', $id)->value('uuid');
    }
}
