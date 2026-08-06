<?php

namespace App\Core\Engineer888\Chat;

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
 * UNIMPLEMENTED ACTIONS FAIL CLOSED. Only REJECT_CANDIDATE is wired today. Every
 * other type raises NotImplemented, so a press cannot silently report success
 * for an action that has no effect. That is the whole lesson of the incident.
 */
final class ActionCardExecutor
{
    /** The domain effect could not be produced. The card must NOT succeed. */
    public const FAILED = 'FAILED';

    /** The action needs input the card does not carry. Nothing is fabricated. */
    public const INSTRUCTION_REQUIRED = 'REJECTION_INSTRUCTION_REQUIRED';

    /** The action type exists but has no domain wiring yet. */
    public const NOT_IMPLEMENTED = 'ACTION_NOT_IMPLEMENTED';

    public function __construct(private ApprovalLedger $ledger) {}

    /**
     * Execute the domain action a claimed card authorises.
     *
     * @return array{ok:bool, reason:?string, evidence:array}
     */
    public function execute(?Request $request, object $card, array $input = []): array
    {
        return match ($card->action_type) {
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
            $task = DB::table('engineering_tasks')->where('id', $candidate->task_id)->first(['uuid']);
            $projectKey = (string) DB::table('engineering_projects')
                ->where('id', $candidate->project_id)->value('key');

            // Built from the candidate's own stored payload, with the same
            // per-file hashing the card binding uses, so the ledger row and the
            // card describe the same bytes.
            $binding = new \App\Core\Engineer888\Approval\ApprovalBinding(
                $candidateUuid,
                (string) ($task->uuid ?? ''),
                $projectKey,
                (string) ($candidate->provider ?? ''),
                (string) ($candidate->model ?? ''),
                $this->fileHashesOf($candidate),
            );

            $this->ledger->record(
                (int) $candidate->id,
                $binding,
                (int) $candidate->task_id,
                (int) $candidate->project_id,
            );
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
