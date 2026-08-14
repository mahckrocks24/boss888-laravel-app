<?php

namespace App\Core\Engineer888\Approval;

use App\Core\Engineer888\Reasoning\CandidateImplementation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who approved exactly what, and whether it still holds.
 *
 * Append-only in spirit: an approval is never edited into a different approval.
 * Rejecting, revoking or superseding writes a terminal state onto the existing
 * row, and a new decision needs a new row against a new candidate.
 *
 * The ledger is the only thing IMPLEMENT will accept as permission. It does not
 * read `engineering_tasks.approved_by`, which is what Sprint 7 relied on and
 * which cannot express "these bytes".
 */
final class ApprovalLedger
{
    /**
     * How long an approval describes the repository it was made against.
     *
     * Not a security boundary — the binding is. This is an honesty boundary: a
     * diff read on Monday against a tree that has since moved 400 files is not
     * a diff anybody reviewed, even if the candidate's own bytes are unchanged.
     */
    public const DEFAULT_TTL_MINUTES = 720;

    public function record(
        int $candidateId,
        ApprovalBinding $binding,
        int $taskId,
        int $projectId,
    ): object {
        $existing = DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $candidateId)->first();

        if ($existing !== null) { return $existing; }

        $id = DB::table('engineering_candidate_approvals')->insertGetId([
            'candidate_id'    => $candidateId,
            'candidate_uuid'  => $binding->candidateUuid,
            'task_id'         => $taskId,
            'project_id'      => $projectId,
            'state'           => ApprovalState::PENDING,
            'fingerprint'     => $binding->fingerprint(),
            'binding'         => json_encode($binding->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR),
            'approved_paths'  => json_encode($binding->paths()),
            'approved_hashes' => json_encode($binding->fileHashes),
            'provider'        => $binding->provider,
            'model'           => $binding->model,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('engineering_candidate_approvals')->find($id);
    }

    /**
     * Approve exactly these bytes.
     *
     * The caller must supply the fingerprint it is approving. That is not
     * ceremony: the UI computes nothing, it echoes back the value it displayed,
     * so an approval submitted against a screen that has gone stale fails here
     * rather than authorising something the human never saw.
     */
    public function approve(
        string $candidateUuid,
        string $fingerprint,
        int $approverUserId,
        string $approverName,
        ?string $comment = null,
        ?int $ttlMinutes = null,
    ): object {
        $row = $this->forCandidateUuid($candidateUuid);
        if ($row === null) { throw new RuntimeException("no approval record for candidate {$candidateUuid}"); }

        if ($row->state !== ApprovalState::PENDING) {
            throw new RuntimeException(
                "this candidate is {$row->state}, not pending; a decided approval is never re-opened"
            );
        }

        if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
            throw new RuntimeException(
                'the fingerprint submitted does not match this candidate. The screen you approved from is '
                . 'out of date, or the candidate changed after it was displayed.'
            );
        }

        DB::table('engineering_candidate_approvals')->where('id', $row->id)->update([
            'state'            => ApprovalState::APPROVED,
            'approver_user_id' => $approverUserId,
            'approver_name'    => $approverName,
            'statement'        => $this->statement($candidateUuid, $fingerprint),
            'comment'          => $comment,
            'approved_at'      => now(),
            'expires_at'       => now()->addMinutes($ttlMinutes ?? self::DEFAULT_TTL_MINUTES),
            'updated_at'       => now(),
        ]);

        return DB::table('engineering_candidate_approvals')->find($row->id);
    }

    /** The sentence the approver is agreeing to, stored verbatim. */
    public function statement(string $candidateUuid, string $fingerprint): string
    {
        return "I approve candidate {$candidateUuid} with fingerprint {$fingerprint} "
             . 'and the exact file changes shown.';
    }

    public function reject(string $candidateUuid, int $userId, string $name, ?string $instruction = null): object
    {
        $row = $this->forCandidateUuid($candidateUuid);
        if ($row === null) { throw new RuntimeException("no approval record for candidate {$candidateUuid}"); }

        DB::table('engineering_candidate_approvals')->where('id', $row->id)->update([
            'state'            => ApprovalState::REJECTED,
            'approver_user_id' => $userId,
            'approver_name'    => $name,
            'comment'          => $instruction,
            'decided_at'       => now(),
            'updated_at'       => now(),
        ]);

        return DB::table('engineering_candidate_approvals')->find($row->id);
    }

    public function revoke(string $candidateUuid, int $userId, string $name, ?string $why = null): object
    {
        $row = $this->forCandidateUuid($candidateUuid);
        if ($row === null) { throw new RuntimeException("no approval record for candidate {$candidateUuid}"); }

        DB::table('engineering_candidate_approvals')->where('id', $row->id)->update([
            'state'      => ApprovalState::REVOKED,
            'revoked_by' => $name,
            'revoked_at' => now(),
            'comment'    => $why ?? $row->comment,
            'updated_at' => now(),
        ]);

        return DB::table('engineering_candidate_approvals')->find($row->id);
    }

    /**
     * A newer candidate exists, so every open approval on this task is stale.
     *
     * Called whenever a candidate is stored. It is what makes "no approval may
     * silently carry forward" true by construction rather than by discipline.
     */
    public function supersedeOpenApprovals(int $taskId, ?int $exceptCandidateId = null): int
    {
        $query = DB::table('engineering_candidate_approvals')
            ->where('task_id', $taskId)
            ->whereIn('state', [ApprovalState::PENDING, ApprovalState::APPROVED]);

        if ($exceptCandidateId !== null) { $query->where('candidate_id', '!=', $exceptCandidateId); }

        return $query->update([
            'state'         => ApprovalState::SUPERSEDED,
            'superseded_at' => now(),
            'updated_at'    => now(),
        ]);
    }

    public function forCandidateUuid(string $candidateUuid): ?object
    {
        return DB::table('engineering_candidate_approvals')
            ->where('candidate_uuid', $candidateUuid)->first();
    }

    /**
     * Has this approval outlived the window it was granted for?
     *
     * THE ONE IMPLEMENTATION OF THE TTL RULE. enforce() asks it before writing
     * EXPIRED, and authorityState() asks it to answer the same question without
     * writing anything. Two copies of this comparison is how a projection ends
     * up disagreeing with the gate — which is exactly what happened on
     * 2026-08-14 and what this method exists to make impossible.
     */
    public function hasLapsed(object $approval): bool
    {
        return $approval->expires_at !== null && now()->greaterThan($approval->expires_at);
    }

    /**
     * The state this approval EFFECTIVELY has right now. Reads nothing, writes nothing.
     *
     * An approval whose stored state is APPROVED but whose TTL has passed is
     * EXPIRED as far as authority is concerned — enforce() will refuse it and
     * record that state on the next execution attempt. This method returns the
     * answer enforce() would give, so a surface can ask "may this run?" without
     * mutating a governance row to find out.
     *
     * ── WHAT THIS IS NOT ────────────────────────────────────────────────
     *
     * These are the LEDGER gates only: state and expiry. The binding gate —
     * candidate, task, project, provider, model, every file hash and the
     * pre-image — still lives in enforce() and still has to pass. A caller that
     * treats a permissive answer here as permission to write would be skipping
     * half the gate. Nothing does; presentation surfaces call this to decide
     * what to SHOW, and execution goes through enforce() as it always has.
     */
    public function authorityState(object $approval): string
    {
        $state = (string) $approval->state;

        if ($state === ApprovalState::APPROVED && $this->hasLapsed($approval)) {
            return ApprovalState::EXPIRED;
        }

        return $state;
    }

    /**
     * Could this approval still permit execution, as of now?
     *
     * For presentation. See authorityState() for what this deliberately does
     * not check.
     */
    public function permitsExecutionNow(object $approval): bool
    {
        return ApprovalState::permitsExecution($this->authorityState($approval));
    }

    /**
     * The approval that could permit a write on this task, if any.
     *
     * DELIBERATELY DOES NOT FILTER ON EXPIRY. Its callers hand the row straight
     * to enforce(), which refuses a lapsed approval AND records EXPIRED onto
     * the row. Excluding lapsed rows here would make enforce() unreachable for
     * them, so the state would never be written and the audit trail would stop
     * saying why a task stopped. Callers that only want to know whether
     * execution is currently possible must ask permitsExecutionNow().
     */
    public function activeFor(int $taskId): ?object
    {
        return DB::table('engineering_candidate_approvals')
            ->where('task_id', $taskId)
            ->where('state', ApprovalState::APPROVED)
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<int,object> */
    public function historyFor(int $taskId): array
    {
        return DB::table('engineering_candidate_approvals')
            ->where('task_id', $taskId)->orderBy('id')->get()->all();
    }

    /**
     * The gate. May THIS candidate be written for THIS task?
     *
     * Every refusal path returns a reason. Nothing here consults the provider,
     * re-reads the candidate from the model, or repairs a mismatch.
     */
    public function enforce(
        CandidateImplementation $candidate,
        string $candidateUuid,
        object $task,
        object $project,
    ): EnforcementResult {
        $approval = DB::table('engineering_candidate_approvals')
            ->where('candidate_uuid', $candidateUuid)->first();

        if ($approval === null) {
            return EnforcementResult::refuse(
                'no approval exists for this candidate',
                ['candidate ' . $candidateUuid . ' was never submitted for approval']
            );
        }

        if ((int) $approval->task_id !== (int) $task->id) {
            return EnforcementResult::refuse('the approval belongs to a different task',
                ['approval task_id ' . $approval->task_id . ', executing task ' . $task->id], $approval);
        }

        if (! ApprovalState::permitsExecution((string) $approval->state)) {
            return EnforcementResult::refuse(
                'the approval is ' . strtolower((string) $approval->state),
                [match ((string) $approval->state) {
                    ApprovalState::PENDING    => 'nobody has approved this candidate yet',
                    ApprovalState::REJECTED   => 'a human refused this candidate' . ($approval->comment ? ': ' . $approval->comment : ''),
                    ApprovalState::SUPERSEDED => 'a newer candidate replaced this one, so the approval no longer describes what would be written',
                    ApprovalState::REVOKED    => 'the approval was withdrawn' . ($approval->revoked_by ? ' by ' . $approval->revoked_by : ''),
                    ApprovalState::EXPIRED    => 'the approval has expired',
                    default                   => 'state ' . $approval->state,
                }],
                $approval
            );
        }

        if ($this->hasLapsed($approval)) {
            // Recorded as expired so the next read does not have to re-derive it.
            DB::table('engineering_candidate_approvals')->where('id', $approval->id)
                ->update(['state' => ApprovalState::EXPIRED, 'updated_at' => now()]);

            return EnforcementResult::refuse('the approval has expired',
                ['approved at ' . $approval->approved_at . ', expired at ' . $approval->expires_at
                 . '. A diff reviewed against an older tree is not a diff anybody reviewed.'], $approval);
        }

        $held = ApprovalBinding::forCandidate($candidate, $candidateUuid,
            (string) $task->uuid, (string) $project->key);

        $approvedBinding = ApprovalBinding::fromArray(
            json_decode((string) $approval->binding, true) ?: []
        );

        $differences = $held->differencesFrom($approvedBinding);

        if ($differences !== []) {
            return EnforcementResult::refuse('the candidate no longer matches what was approved',
                $differences, $approval);
        }

        if (! hash_equals((string) $approval->fingerprint, $held->fingerprint())) {
            // Belt and braces: the field comparison above should already have
            // caught anything that moves the hash. If it did not, the binding
            // definition has drifted and nothing may be written on that basis.
            return EnforcementResult::refuse('fingerprint mismatch',
                ['the recorded fingerprint and the recomputed one differ despite matching fields; '
                 . 'the binding definition itself has changed'], $approval);
        }

        return EnforcementResult::permit($approval);
    }
}
