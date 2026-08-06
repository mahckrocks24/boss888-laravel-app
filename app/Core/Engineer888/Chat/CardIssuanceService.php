<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Access;
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

        $eligible = $this->eligible($request);
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
                null,
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
    public function eligible(?Request $request): array
    {
        $access = new Engineer888Access();
        $out = [];

        // ── A. Candidate awaiting exact review ───────────────────────────
        // VALIDATED, not superseded, and no decision recorded against it yet.
        $awaiting = DB::table('engineering_candidates as c')
            ->leftJoin('engineering_candidate_approvals as a', function ($j) {
                $j->on('a.candidate_id', '=', 'c.id')
                  ->whereNull('a.revoked_at')->whereNull('a.superseded_at');
            })
            ->join('engineering_tasks as t', 't.id', '=', 'c.task_id')
            ->whereNull('c.superseded_at')
            ->where('c.status', 'VALIDATED')
            ->whereNull('a.id')
            ->whereNotIn('t.status', ['completed', 'failed'])
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
        $approved = DB::table('engineering_candidate_approvals as a')
            ->join('engineering_candidates as c', 'c.id', '=', 'a.candidate_id')
            ->join('engineering_tasks as t', 't.id', '=', 'a.task_id')
            ->whereNotNull('a.approved_at')
            ->whereNull('a.revoked_at')->whereNull('a.superseded_at')
            ->whereNull('c.superseded_at')
            ->whereNotIn('t.status', ['completed', 'failed'])
            ->select('c.uuid as cuuid', 't.uuid as tuuid')
            ->get();

        foreach ($approved as $row) {
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::EXECUTE)) {
                $out[] = ['action_type' => ActionCardService::EXECUTE_TASK, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
            // E. The same live approval is revocable while it remains unspent.
            if ($access->allows($request, \App\Core\Engineer888\Access\Engineer888Capability::APPROVE_CANDIDATE)) {
                $out[] = ['action_type' => ActionCardService::REVOKE_APPROVAL, 'task_uuid' => $row->tuuid, 'target_uuid' => $row->cuuid];
            }
        }

        // ── C. Recovery awaiting a human ─────────────────────────────────
        // BLOCKED is the state that means "a person must look at this".
        // engineering_recoveries carries no uuid of its own: a recovery is
        // identified by the candidate it is recovering. Selecting a `uuid`
        // column here was a defect — the table has task_uuid and candidate_uuid.
        $recoveries = DB::table('engineering_recoveries')
            ->where('status', 'FAILED_RECOVERY_BLOCKED')
            ->whereNull('approved_at')
            ->get(['task_uuid', 'candidate_uuid']);

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
