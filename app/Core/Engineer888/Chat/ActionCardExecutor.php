<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Approval\ApprovalBinding;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The layer that makes a card press mean something.
 *
 * WHY THIS EXISTS. On 2026-08-05 a real card was pressed in the browser, the
 * server accepted it, the card was consumed and the UI reported success — and
 * nothing happened. The candidate stayed VALIDATED, no decision was recorded,
 * and reconciliation correctly re-issued an equivalent card two seconds later.
 * The card layer validated every binding and then simply returned.
 * (ACTION_CARD_CONSUMED_WITHOUT_DOMAIN_EFFECT.)
 *
 * So this class does exactly one thing: take a claimed card, invoke the EXISTING
 * governed domain service, and then PROVE the mutation happened by re-reading
 * the record. A card may only be marked succeeded on evidence.
 *
 * IT OWNS NO BUSINESS RULES. Approval semantics live in ApprovalLedger and
 * nowhere else. This maps an action type to a call and checks the result. It
 * does not call controllers — controllers are transport — and it never decides
 * whether something may be approved; the card's own validation and the policy
 * did that before it was claimed.
 *
 * UNIMPLEMENTED ACTIONS FAIL CLOSED. REJECT_CANDIDATE and APPROVE_CANDIDATE are
 * wired today. Every other type raises NotImplemented, so a press cannot
 * silently report success for an action that has no effect. That is the whole
 * lesson of the incident.
 */
final class ActionCardExecutor
{
    /** The domain effect could not be produced. The card must NOT succeed. */
    public const FAILED = 'FAILED';

    /** The action needs input the card does not carry. Nothing is fabricated. */
    public const INSTRUCTION_REQUIRED = 'REJECTION_INSTRUCTION_REQUIRED';

    /** The action type exists but has no domain wiring yet. */
    public const NOT_IMPLEMENTED = 'ACTION_NOT_IMPLEMENTED';

    /** Approval needs the sentence typed out. A click is not a statement. */
    public const STATEMENT_REQUIRED = 'APPROVAL_STATEMENT_REQUIRED';

    /** What was typed is not, character for character, what was required. */
    public const STATEMENT_MISMATCH = 'APPROVAL_STATEMENT_MISMATCH';

    /** The candidate is gone, superseded, or no longer the one on screen. */
    public const CANDIDATE_NOT_CURRENT = 'CANDIDATE_NOT_CURRENT';

    /** A decided approval is never re-opened, so there is nothing to approve. */
    public const LEDGER_NOT_PENDING = 'LEDGER_NOT_PENDING';

    /** The bytes moved between the screen and the press. */
    public const FINGERPRINT_MISMATCH = 'FINGERPRINT_MISMATCH';

    public function __construct(private ApprovalLedger $ledger) {}

    /**
     * The exact sentence an approver must type for THIS card.
     *
     * Generated server-side, from the live candidate and the fingerprint the
     * LEDGER binds — never from anything a browser sent.
     *
     * WHICH FINGERPRINT, AND WHY IT IS NOT THE CARD'S. A card carries
     * `content_fingerprint`, the candidate's own 40-character content hash. The
     * ledger binds something else: ApprovalBinding's sha256 over candidate,
     * task, project, provider, model and every per-file hash. They are different
     * schemes over different inputs and they never coincide. ApprovalLedger::
     * approve() compares against ITS fingerprint, so a statement built from the
     * card's would fail that comparison and throw — inside the consume
     * transaction, which is exactly where nothing may throw.
     *
     * The binding fingerprint is also the honest one to show: it is what the
     * approval is recorded against, and it is what the two approvals already in
     * the ledger were stated with.
     *
     * The card's own fingerprint is not weakened by this. ActionCardService::
     * validate() revalidates it against the live candidate before consume() will
     * claim the card at all, and approveCandidate() checks it again below.
     */
    public function requiredStatementFor(object $card): ?string
    {
        if (($card->action_type ?? null) !== ActionCardService::APPROVE_CANDIDATE) {
            return null;
        }

        $candidateUuid = (string) ($card->candidate_uuid ?? '');

        if ($candidateUuid === '') {
            return null;
        }

        $fingerprint = $this->approvalFingerprintFor($candidateUuid);

        return $fingerprint === null
            ? null
            : $this->ledger->statement($candidateUuid, $fingerprint);
    }

    /**
     * Execute the domain action a claimed card authorises.
     *
     * @return array{ok:bool, reason:?string, evidence:array}
     */
    public function execute(?Request $request, object $card, array $input = []): array
    {
        return match ($card->action_type) {
            ActionCardService::APPROVE_CANDIDATE => $this->approveCandidate($request, $card, $input),
            ActionCardService::REJECT_CANDIDATE => $this->rejectCandidate($request, $card, $input),
            default => $this->refuse(self::NOT_IMPLEMENTED,
                "no domain wiring exists for {$card->action_type}; the card must not report success"),
        };
    }

    /**
     * Reject a candidate through the existing approval ledger.
     *
     * THE INSTRUCTION IS NOT INVENTED. ApprovalLedger::reject() accepts a null
     * instruction, but the governed HTTP path validates it as required, and a
     * rejection with no stated reason is an engineering decision with no record
     * of why. If the caller did not supply one, this refuses and the UI asks.
     */
    private function rejectCandidate(?Request $request, object $card, array $input): array
    {
        $instruction = trim((string) ($input['instruction'] ?? ''));

        if ($instruction === '') {
            return $this->refuse(self::INSTRUCTION_REQUIRED,
                'a rejection must say why; the card carries no instruction');
        }

        $candidateUuid = (string) $card->candidate_uuid;

        $candidate = DB::table('engineering_candidates')->where('uuid', $candidateUuid)->first();

        if ($candidate === null) {
            return $this->refuse(self::FAILED, 'the candidate no longer exists');
        }

        // The ledger transitions an EXISTING row and throws without one. A
        // candidate awaiting first review legitimately has none — record()
        // opens the PENDING row it will transition. record() is idempotent, so
        // a concurrent opener does not produce a second row.
        if ($this->ledger->forCandidateUuid($candidateUuid) === null) {
            $this->openLedgerRow($candidate);
        }

        $user = $request?->user();

        $this->ledger->reject(
            $candidateUuid,
            (int) ($user->id ?? ChatOwner::USER_ID),
            (string) ($user->name ?? 'Engineer888 chat'),
            $instruction,
        );

        // PROOF, NOT ASSUMPTION. Re-read the record and confirm the transition.
        // Without this the card would report success on a call that returned
        // quietly — which is precisely how the original defect behaved.
        $after = $this->ledger->forCandidateUuid($candidateUuid);

        if ($after === null || $after->state !== ApprovalState::REJECTED) {
            return $this->refuse(self::FAILED,
                'the ledger did not record a rejection; refusing to mark the card succeeded');
        }

        return [
            'ok' => true,
            'reason' => null,
            'evidence' => [
                'domain' => 'approval_ledger',
                'candidate_uuid' => $candidateUuid,
                'approval_id' => (int) $after->id,
                'state' => $after->state,
                'decided_at' => (string) $after->decided_at,
                'approver_user_id' => (int) $after->approver_user_id,
            ],
        ];
    }

    /**
     * Approve a candidate through the existing approval ledger.
     *
     * THE TYPED SENTENCE IS THE POINT. A press is a click; a click cannot say
     * "these bytes". The approver retypes a statement naming the candidate and
     * the fingerprint, and it must match character for character. Only the outer
     * whitespace a text box adds is forgiven — trim, and nothing else. No case
     * folding, no punctuation tolerance, no collapsing of internal spaces, and
     * no generic "I approve": each of those would let a sentence that is not the
     * required sentence stand in for it.
     *
     * The browser supplies the typed statement and an optional comment. It does
     * not supply — and is not believed about — the candidate, the fingerprint,
     * the action, the project or the workflow state. All of those are read from
     * the stored card and the live engineering records.
     */
    private function approveCandidate(?Request $request, object $card, array $input): array
    {
        $submitted = (string) ($input['statement'] ?? '');

        if (trim($submitted) === '') {
            return $this->refuse(self::STATEMENT_REQUIRED,
                'approval requires the exact statement to be typed; the card carries none');
        }

        $candidateUuid = (string) $card->candidate_uuid;

        $candidate = DB::table('engineering_candidates')->where('uuid', $candidateUuid)->first();

        if ($candidate === null) {
            return $this->refuse(self::CANDIDATE_NOT_CURRENT, 'the candidate no longer exists');
        }

        if ($candidate->superseded_at !== null) {
            return $this->refuse(self::CANDIDATE_NOT_CURRENT,
                'the candidate was superseded; approving it would authorise bytes nobody reviewed');
        }

        // The card's own binding, checked again here. validate() already proved
        // it before the claim; this is the second, independent check, because an
        // approval is the one action where being wrong is unrecoverable.
        if (! hash_equals((string) $candidate->content_fingerprint, (string) $card->content_fingerprint)) {
            return $this->refuse(self::FINGERPRINT_MISMATCH,
                'the candidate changed after this card was issued');
        }

        // The fingerprint the LEDGER binds, and the statement built from it.
        $fingerprint = $this->approvalFingerprintFor($candidateUuid);

        if ($fingerprint === null) {
            return $this->refuse(self::FAILED, 'no approval fingerprint could be resolved for this candidate');
        }

        $expected = $this->ledger->statement($candidateUuid, $fingerprint);

        // Constant time, exact bytes, trim only.
        if (! hash_equals($expected, trim($submitted))) {
            return $this->refuse(self::STATEMENT_MISMATCH,
                'the statement typed is not the statement required for this candidate');
        }

        // The ledger transitions an EXISTING row and throws without one. A
        // candidate awaiting first review legitimately has none — record()
        // opens the PENDING row it will transition, and is idempotent.
        if ($this->ledger->forCandidateUuid($candidateUuid) === null) {
            $this->openLedgerRow($candidate);
        }

        $before = $this->ledger->forCandidateUuid($candidateUuid);

        if ($before === null) {
            return $this->refuse(self::FAILED, 'the ledger row could not be opened');
        }

        if ($before->state !== ApprovalState::PENDING) {
            // Already decided. approve() would throw, and throwing inside the
            // consume transaction is what discards the refusal reason.
            return $this->refuse(self::LEDGER_NOT_PENDING,
                "this candidate is already {$before->state}; a decided approval is never re-opened");
        }

        if (! hash_equals((string) $before->fingerprint, $fingerprint)) {
            return $this->refuse(self::FINGERPRINT_MISMATCH,
                'the ledger binds a different fingerprint than the one approved');
        }

        $user = $request?->user();
        $comment = trim((string) ($input['comment'] ?? ''));
        $comment = $comment === '' ? null : $comment;

        $this->ledger->approve(
            $candidateUuid,
            $fingerprint,
            ChatOwner::USER_ID,
            (string) ($user->name ?? 'Engineer888 chat'),
            $comment,
        );

        // PROOF, NOT ASSUMPTION. Everything below re-reads the row and refuses
        // if the mutation is not exactly what was asked for. A card may not be
        // marked succeeded on the strength of a call that returned quietly.
        $after = $this->ledger->forCandidateUuid($candidateUuid);

        if ($after === null || $after->state !== ApprovalState::APPROVED) {
            return $this->refuse(self::FAILED,
                'the ledger did not record an approval; refusing to mark the card succeeded');
        }

        if (! hash_equals((string) $after->fingerprint, $fingerprint)) {
            return $this->refuse(self::FINGERPRINT_MISMATCH,
                'the approved row binds a different fingerprint than the one approved');
        }

        if ((int) $after->approver_user_id !== ChatOwner::USER_ID) {
            return $this->refuse(self::FAILED, 'the approval was not attributed to the canonical approver');
        }

        // ApprovalLedger stamps the approval instant in approved_at. decided_at
        // is the rejection path's field and approve() does not write it, so the
        // decision timestamp asserted here is approved_at. Stated rather than
        // quietly substituted, and not repaired from here: ApprovalLedger owns
        // approval and this class owns none of its rules.
        if ($after->approved_at === null) {
            return $this->refuse(self::FAILED, 'the approval carries no decision timestamp');
        }

        if ($comment !== null && (string) $after->comment !== $comment) {
            return $this->refuse(self::FAILED, 'the approval comment was not persisted as submitted');
        }

        if (! hash_equals($expected, (string) $after->statement)) {
            return $this->refuse(self::FAILED, 'the ledger stored a different statement than the one approved');
        }

        return [
            'ok' => true,
            'reason' => null,
            'evidence' => [
                'domain' => 'approval_ledger',
                'candidate_uuid' => $candidateUuid,
                'approval_id' => (int) $after->id,
                'state' => $after->state,
                'fingerprint' => (string) $after->fingerprint,
                'approved_at' => (string) $after->approved_at,
                'expires_at' => (string) $after->expires_at,
                'approver_user_id' => (int) $after->approver_user_id,
                'approver_name' => (string) $after->approver_name,
                'statement_recorded' => (string) $after->statement,
                'comment' => $after->comment,
            ],
        ];
    }

    /**
     * The fingerprint the ledger binds for this candidate.
     *
     * An existing row is authoritative. Without one — the normal case for a
     * candidate awaiting first review — the same ApprovalBinding record() would
     * open is built and hashed, so the statement shown before the row exists is
     * the statement the row will carry.
     */
    private function approvalFingerprintFor(string $candidateUuid): ?string
    {
        $existing = $this->ledger->forCandidateUuid($candidateUuid);

        if ($existing !== null) {
            return (string) $existing->fingerprint;
        }

        $candidate = DB::table('engineering_candidates')->where('uuid', $candidateUuid)->first();

        return $candidate === null ? null : $this->bindingFor($candidate)->fingerprint();
    }

    /** Open the PENDING row a decision will transition. Idempotent via record(). */
    private function openLedgerRow(object $candidate): void
    {
        $this->ledger->record(
            (int) $candidate->id,
            $this->bindingFor($candidate),
            (int) $candidate->task_id,
            (int) $candidate->project_id,
        );
    }

    /**
     * The approval binding for a candidate.
     *
     * Built from the candidate's own stored payload, with the same per-file
     * hashing the card binding uses, so the ledger row and the card describe the
     * same bytes. Shared by approve and reject so the two can never disagree
     * about what a candidate's fingerprint is.
     */
    private function bindingFor(object $candidate): ApprovalBinding
    {
        $task = DB::table('engineering_tasks')->where('id', $candidate->task_id)->first(['uuid']);
        $projectKey = (string) DB::table('engineering_projects')
            ->where('id', $candidate->project_id)->value('key');

        return new ApprovalBinding(
            (string) $candidate->uuid,
            (string) ($task->uuid ?? ''),
            $projectKey,
            (string) ($candidate->provider ?? ''),
            (string) ($candidate->model ?? ''),
            $this->fileHashesOf($candidate),
        );
    }

    /**
     * Per-file hashes from the candidate payload.
     *
     * Same shape and ordering the card binding uses, so a ledger row opened
     * here describes exactly the bytes the card was issued against.
     *
     * @return array<string,string>
     */
    private function fileHashesOf(object $candidate): array
    {
        $payload = json_decode((string) ($candidate->payload ?? ''), true);
        $files = is_array($payload) ? ($payload['files'] ?? $payload['changes'] ?? []) : [];
        $out = [];

        foreach ((array) $files as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            if ($path === null) { continue; }
            $out[$path] = hash('sha256', (string) (is_array($file) ? ($file['content'] ?? $file['contents'] ?? '') : ''));
        }

        ksort($out);

        return $out;
    }

    private function refuse(string $reason, string $detail): array
    {
        return ['ok' => false, 'reason' => $reason, 'evidence' => ['detail' => $detail]];
    }
}
