<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Secure action cards — the only way a high-risk action leaves this chat.
 *
 * A card is a statement by a named human about EXACT BYTES, made at a known
 * moment, in a known workflow state, from a known session. Free text cannot make
 * that statement: "approve" is a word, not a binding.
 *
 * NOTHING IN THE BROWSER IS TRUSTED. The card UUID is the only thing that
 * travels; every other binding is re-read from the database and recomputed at
 * press time. A card whose candidate has since been revised no longer matches
 * its own fingerprint and is refused — approval cannot drift onto content the
 * approver never saw. That is the entire point of the mechanism.
 *
 * THIS CLASS DOES NOT PERFORM ENGINEERING WORK. It validates, records, and then
 * hands off to the existing Engineer888 controller path, which owns approval,
 * execution and recovery. Reimplementing any of that here would create the
 * second source of truth the whole module exists to avoid.
 */
final class ActionCardService
{
    public const APPROVE_CANDIDATE = 'approve_candidate';
    public const REJECT_CANDIDATE = 'reject_candidate';
    public const REQUEST_REVISION = 'request_revision';
    public const EXECUTE_TASK = 'execute_task';
    public const APPROVE_RECOVERY = 'approve_recovery';
    public const REVOKE_APPROVAL = 'revoke_approval';
    public const APPROVE_MIGRATION = 'approve_migration';

    /** Long enough to read a diff properly, short enough that a stale tab cannot act. */
    private const TTL_MINUTES = 30;

    /** Which capability each action requires. The card never widens a grant. */
    private const CAPABILITY = [
        self::APPROVE_CANDIDATE => Cap::APPROVE_CANDIDATE,
        self::REJECT_CANDIDATE => Cap::REVIEW_CANDIDATE,
        self::REQUEST_REVISION => Cap::REVIEW_CANDIDATE,
        self::EXECUTE_TASK => Cap::EXECUTE,
        self::APPROVE_RECOVERY => Cap::APPROVE_RECOVERY,
        self::REVOKE_APPROVAL => Cap::APPROVE_CANDIDATE,
        self::APPROVE_MIGRATION => Cap::APPROVE_MIGRATION,
    ];

    /**
     * The capability a card of this type needs, for callers that must decide
     * whether to SHOW it. A card the reader may not act on is not listed:
     * review_candidate must not become a universal card-read permission.
     */
    public static function capabilityFor(string $actionType): ?string
    {
        return self::CAPABILITY[$actionType] ?? null;
    }

    /**
     * Withdraw an approval that has not yet been acted on.
     *
     * PATTERN B. One endpoint, gated on the weakest Engineer888 capability that
     * still means "may act on a card at all", then the AUTHORITY IS RESOLVED
     * FROM THE STORED CARD. Revoking a recovery approval requires the recovery
     * capability; revoking an execution authority requires execute. A holder of
     * approve_candidate cannot undo a recovery approval merely because both are
     * "a revoke".
     *
     * The client never names the type. It is read from the row, and a card whose
     * type is unknown fails closed.
     */
    public function revoke(?Request $request, string $cardUuid): object
    {
        return DB::transaction(function () use ($request, $cardUuid) {
            $owner = ChatOwner::resolve($request);

            if ($owner === null) {
                ChatAbsent::throw('revoke by a non-canonical identity');
            }

            $card = ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->first();

            if ($card === null) {
                ChatAbsent::throw('revoke: card not found for owner');
            }

            // The authority that GAVE the approval is the authority that may
            // take it back — resolved from the stored action, never the request.
            $needed = self::capabilityFor((string) $card->action_type);

            if ($needed === null || ! (new Engineer888Access())->allows($request, $needed)) {
                ChatAbsent::throw('revoke: capability for the stored card action is not granted');
            }

            if ($card->consumed_at !== null) {
                // Already acted on. Revoking is no longer the operation; undoing
                // is, and that is the recovery path, not this one.
                ChatAbsent::throw('revoke: card already consumed');
            }

            $claimed = ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->whereNull('revoked_at')->whereNull('consumed_at')
             ->update(['revoked_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                ChatAbsent::throw('revoke: card changed concurrently');
            }

            return ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->first();
        });
    }

    /**
     * The cards this actor may SEE on a conversation.
     *
     * Filtered one at a time by the capability each card's own action requires.
     * A card the actor may not act on is not returned in any form — no disabled
     * entry, no hidden flag, no placeholder, no action-type hint, no fingerprint
     * and no route. It is simply absent, because anything else tells the reader
     * that something exists which they cannot have.
     */
    public function visibleFor(?Request $request, object $conversation): array
    {
        if (! ChatOwner::is($request)) {
            return [];
        }

        $access = new Engineer888Access();
        $out = [];

        $rows = ChatOwner::scope(
            DB::table('e888_action_cards')->where('conversation_id', $conversation->id)
        )->whereNull('consumed_at')->whereNull('revoked_at')
         ->orderByDesc('id')->limit(50)->get();

        foreach ($rows as $card) {
            $needed = self::capabilityFor((string) $card->action_type);

            // Unknown action type fails closed.
            if ($needed === null || ! $access->allows($request, $needed)) {
                continue;
            }

            if ($card->expires_at !== null && now()->greaterThan($card->expires_at)) {
                continue;
            }

            // PRESENTATION DATA ONLY. Enough to decide, nothing more.
            $task = $card->task_uuid
                ? DB::table('engineering_tasks')->where('uuid', $card->task_uuid)
                    ->first(['title', 'status', 'current_stage', 'project_id'])
                : null;

            $candidate = $card->candidate_uuid
                ? DB::table('engineering_candidates')->where('uuid', $card->candidate_uuid)
                    ->first(['file_count', 'status', 'provider', 'model', 'violations'])
                : null;

            $project = $task
                ? DB::table('engineering_projects')->where('id', $task->project_id)->value('name')
                : null;

            $out[] = [
                'uuid' => $card->uuid,
                'action_type' => $card->action_type,
                'task_uuid' => $card->task_uuid,
                'candidate_uuid' => $card->candidate_uuid,
                'recovery_uuid' => $card->recovery_uuid,
                'workflow_state' => $card->workflow_state,
                'expires_at' => $card->expires_at,
                'project' => $project,
                'task_title' => $task->title ?? null,
                'task_status' => $task->status ?? null,
                'file_count' => $candidate->file_count ?? null,
                'candidate_status' => $candidate->status ?? null,
                'provider' => $candidate->provider ?? null,
                // A count, never the contents. "Two things are unresolved" is
                // what a decision needs; the unknowns themselves live in the
                // Command Center behind their own capability.
                'unknown_count' => $candidate && $candidate->violations
                    ? count((array) json_decode((string) $candidate->violations, true))
                    : 0,
                'command_center_url' => '/admin/engineer888',
                'mfa' => [
                    'required' => (bool) $card->mfa_required,
                    'satisfied' => (bool) $card->mfa_satisfied,
                ],
                // The fingerprint is NOT returned. It is the binding the server
                // revalidates; a client has no use for it and echoing it invites
                // a client to believe it can compare or supply one.
            ];
        }

        return $out;
    }

    /**
     * Issue a card bound to the state that exists RIGHT NOW.
     *
     * The fingerprint and workflow state are read from the engineering records,
     * never accepted from the caller — a card must describe reality, not what
     * the chat layer believed a moment ago.
     */
    public function issue(
        ?Request $request,
        object $conversation,
        ?int $messageId,
        string $actionType,
        ?string $taskUuid,
        ?string $targetUuid = null
    ): object {
        $owner = ChatOwner::resolve($request);

        if ($owner === null || ! isset(self::CAPABILITY[$actionType])) {
            ChatAbsent::throw('card issuance refused: owner or action type');
        }

        $binding = $this->currentBinding($actionType, $taskUuid, $targetUuid);
        $mfa = (new Engineer888Access())->check($request, self::CAPABILITY[$actionType])['mfa'] ?? [];

        $uuid = (string) Str::uuid();

        DB::table('e888_action_cards')->insert([
            'uuid' => $uuid,
            'conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'owner_user_id' => ChatOwner::USER_ID,
            'action_type' => $actionType,
            'task_uuid' => $taskUuid,
            'candidate_uuid' => $binding['candidate_uuid'],
            'recovery_uuid' => $binding['recovery_uuid'],
            'project_id' => $binding['project_id'],
            'content_fingerprint' => $binding['content_fingerprint'],
            'file_hashes_json' => PayloadCanonical::encode($binding['file_hashes']),
            'workflow_state' => $binding['workflow_state'],
            'issued_to_user_id' => ChatOwner::USER_ID,
            'issued_session_id' => $this->sessionId($request),
            'issued_device_id' => $request?->attributes->get('e888_device_id'),
            'mfa_required' => (bool) ($mfa['required'] ?? false),
            'mfa_satisfied' => (bool) ($mfa['satisfied'] ?? false),
            'expires_at' => now()->addMinutes((int) config('engineer888_chat.action_card_ttl_minutes', self::TTL_MINUTES)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('e888_action_cards')->where('uuid', $uuid)->first();
    }

    /**
     * Validate a card without consuming it. Every binding, recomputed.
     *
     * @return array{ok:bool, reason:?string, card:?object, binding:?array}
     */
    public function validate(?Request $request, string $cardUuid): array
    {
        $owner = ChatOwner::resolve($request);

        if ($owner === null) {
            ChatAbsent::throw('card validate by non-canonical identity');
        }

        $card = ChatOwner::scope(
            DB::table('e888_action_cards')->where('uuid', $cardUuid)
        )->first();

        // Not found, not yours, never existed — one answer.
        if ($card === null) {
            ChatAbsent::throw('card uuid not found for owner');
        }

        // The capability is re-asked now, not trusted from issue time. A grant
        // revoked in between must close the card.
        $decision = (new Engineer888Access())->check($request, self::CAPABILITY[$card->action_type] ?? Cap::VIEW);

        if (! $decision['allowed']) {
            return $this->refuse($card, 'CAPABILITY_' . ($decision['reason'] ?? 'DENIED'));
        }

        if ($card->consumed_at !== null) {
            return $this->refuse($card, 'ALREADY_CONSUMED');
        }

        if ($card->revoked_at !== null) {
            return $this->refuse($card, 'REVOKED');
        }

        if ($card->expires_at !== null && now()->greaterThan($card->expires_at)) {
            return $this->refuse($card, 'EXPIRED');
        }

        // The session that pressed must be the session that was issued. A card
        // copied into another sign-in is not the card that was issued.
        $session = $this->sessionId($request);
        if ($card->issued_session_id !== null && $session !== null
            && (int) $card->issued_session_id !== (int) $session) {
            return $this->refuse($card, 'SESSION_MISMATCH');
        }

        if ((bool) $card->mfa_required && ! (bool) $card->mfa_satisfied) {
            return $this->refuse($card, Engineer888Access::DENY_MFA);
        }

        // THE BINDING CHECK. Read the engineering records as they are now and
        // compare with what the card was issued against.
        $now = $this->currentBinding($card->action_type, $card->task_uuid, $card->candidate_uuid ?: $card->recovery_uuid);

        if (($now['content_fingerprint'] ?? null) !== ($card->content_fingerprint ?: null)) {
            // The candidate was revised, superseded, or replaced.
            return $this->refuse($card, 'FINGERPRINT_MISMATCH');
        }

        if (($now['workflow_state'] ?? null) !== ($card->workflow_state ?: null)) {
            // The task moved on. A card issued in one stage cannot act in another.
            return $this->refuse($card, 'WORKFLOW_STATE_CHANGED');
        }

        // Canonical form on both sides. Comparing the stored TEXT against
        // freshly encoded PHP compared MySQL's json formatting with PHP's:
        // the column is native json and returns `{"a": "b"}` where PHP emits
        // `{"a":"b"}`. Identical data, different bytes, every card refused.
        if (! PayloadCanonical::matches($now['file_hashes'], $card->file_hashes_json)) {
            return $this->refuse($card, 'PAYLOAD_HASH_MISMATCH');
        }

        return ['ok' => true, 'reason' => null, 'card' => $card, 'binding' => $now];
    }

    /**
     * Consume a card, atomically and exactly once.
     *
     * The claim is a conditional UPDATE inside a transaction: two presses of the
     * same card race on the database, not in PHP, and only one can win.
     */
    public function consume(?Request $request, string $cardUuid, string $expectedActionType, string $result, array $input = []): object
    {
        $outcome = DB::transaction(function () use ($request, $cardUuid, $expectedActionType, $result, $input) {
            $check = $this->validate($request, $cardUuid);

            if (! $check['ok']) {
                ChatAbsent::throw('card consume refused: ' . $check['reason']);
            }

            // THE ENDPOINT MUST MATCH THE CARD. A candidate-approval card
            // submitted to the recovery endpoint is answered exactly like a card
            // that does not exist — the caller learns neither that the card is
            // real nor that they used the wrong door. The type is read from the
            // stored row; nothing the client sent is consulted.
            if ($check['card']->action_type !== $expectedActionType) {
                ChatAbsent::throw('card action_type does not match the endpoint');
            }

            // The capability for THIS card's action, re-asked now. The route's
            // gate is the floor; this is the exact requirement.
            $needed = self::capabilityFor($check['card']->action_type);

            if ($needed === null
                || ! (new Engineer888Access())->allows($request, $needed)) {
                ChatAbsent::throw('capability for this card action is not granted');
            }

            // CLAIM FIRST, SUCCEED LAST.
            //
            // The claim is a conditional UPDATE, so exactly one caller can take
            // the card and only one domain action can follow. It is marked
            // PROCESSING rather than succeeded: on 2026-08-05 this step wrote
            // "executed" and returned, and the card reported success for an
            // action that never happened.
            $claimed = ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->whereNull('consumed_at')->update([
                'consumed_at' => now(),
                'consumed_result' => 'PROCESSING',
                'updated_at' => now(),
            ]);

            if ($claimed !== 1) {
                ChatAbsent::throw('card was consumed concurrently');
            }

            // THE DOMAIN ACTION. Delegated to the governed service, then proven.
            $card = ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->first();

            $outcome = app(ActionCardExecutor::class)->execute($request, $card, $input);

            if (! $outcome['ok']) {
                // REFUSED. Roll the whole claim back — including any partial
                // domain work — by RETURNING rather than throwing. Throwing here
                // aborts the transaction and discards anything written inside
                // it, which is exactly how the refusal reason was lost: the
                // action was safely refused and the caller learned nothing.
                //
                // The reason is persisted after the transaction has rolled back.
                return ['refused' => $outcome['reason']];
            }

            // Only now, on evidence the mutation exists.
            ChatOwner::scope(DB::table('e888_action_cards')->where('uuid', $cardUuid))
                ->update(['consumed_result' => $result, 'updated_at' => now()]);

            return ['card' => ChatOwner::scope(
                DB::table('e888_action_cards')->where('uuid', $cardUuid)
            )->first()];
        });

        // ── AFTER THE TRANSACTION ────────────────────────────────────────
        // Everything above either committed or rolled back. A refusal reason
        // written here is durable precisely because it is outside that
        // boundary — that is the whole point of the fix.
        if (isset($outcome['refused'])) {
            ChatOwner::scope(DB::table('e888_action_cards')->where('uuid', $cardUuid))
                ->update([
                    // The claim is released. The decision is still due, and a
                    // card left PROCESSING would be invisible to reconciliation
                    // forever. Retry is valid because nothing was mutated.
                    'consumed_at' => null,
                    'consumed_result' => $outcome['refused'],
                    'updated_at' => now(),
                ]);

            // Auditable, carrying no payload, stack trace or secret.
            \Illuminate\Support\Facades\Log::warning('engineer888.card.refused', [
                'card' => $cardUuid,
                'reason' => $outcome['refused'],
            ]);

            ChatAbsent::throw('domain action refused: ' . $outcome['refused']);
        }

        return $outcome['card'];
    }

    /**
     * The engineering records as they are NOW.
     *
     * Chat state is never workflow truth. Everything here is read from
     * engineering_tasks / engineering_candidates / engineering_recoveries at the
     * moment it is asked for.
     */
    private function currentBinding(string $actionType, ?string $taskUuid, ?string $targetUuid): array
    {
        $out = [
            'candidate_uuid' => null,
            'recovery_uuid' => null,
            'project_id' => null,
            'content_fingerprint' => null,
            'file_hashes' => [],
            'workflow_state' => null,
        ];

        $task = $taskUuid ? DB::table('engineering_tasks')->where('uuid', $taskUuid)->first() : null;

        if ($task !== null) {
            $out['project_id'] = $task->project_id;
            $out['workflow_state'] = trim(($task->current_stage ?? '') . ':' . ($task->status ?? ''), ':');
        }

        if ($actionType === self::APPROVE_RECOVERY) {
            // Keyed on candidate_uuid: engineering_recoveries has no uuid column
            // of its own, and a recovery is about the candidate that failed.
            $recovery = $targetUuid
                ? DB::table('engineering_recoveries')->where('candidate_uuid', $targetUuid)->first()
                : null;

            if ($recovery !== null) {
                $out['recovery_uuid'] = $recovery->candidate_uuid;
                $out['content_fingerprint'] = $recovery->fingerprint ?? null;
            }

            return $out;
        }

        // Candidate-bound actions. The LIVE candidate for this task, not the one
        // the chat message happened to name: if it was superseded, the
        // fingerprint moves and the card stops matching, which is correct.
        $candidate = null;

        if ($targetUuid) {
            $candidate = DB::table('engineering_candidates')->where('uuid', $targetUuid)->first();
        } elseif ($task !== null) {
            $candidate = DB::table('engineering_candidates')
                ->where('task_id', $task->id)
                ->whereNull('superseded_at')
                ->orderByDesc('id')->first();
        }

        if ($candidate !== null) {
            $out['candidate_uuid'] = $candidate->uuid;
            $out['content_fingerprint'] = $candidate->content_fingerprint;
            $out['project_id'] = $candidate->project_id ?? $out['project_id'];
            $out['file_hashes'] = $this->fileHashes($candidate);
        }

        return $out;
    }

    /**
     * Per-file hashes from the candidate payload.
     *
     * A content fingerprint covers the whole proposal; these cover each file, so
     * a payload rewritten to the same overall fingerprint — or a fingerprint
     * scheme that ever weakens — still cannot slip a changed file past.
     */
    private function fileHashes(object $candidate): array
    {
        $payload = json_decode((string) ($candidate->payload ?? ''), true);

        if (! is_array($payload)) {
            return [];
        }

        $files = $payload['files'] ?? $payload['changes'] ?? [];
        $out = [];

        foreach ((array) $files as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;

            if ($path === null) {
                continue;
            }

            $content = is_array($file) ? (string) ($file['content'] ?? $file['contents'] ?? '') : '';
            $out[$path] = hash('sha256', $content);
        }

        ksort($out);

        return $out;
    }

    private function sessionId(?Request $request): ?int
    {
        $sid = $request?->attributes->get('session_id');

        return $sid === null ? null : (int) $sid;
    }

    private function refuse(object $card, string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'card' => $card, 'binding' => null];
    }
}
