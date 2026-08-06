<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Migration\RehearsalGate;

/**
 * 8. Ask a human, about these exact bytes.
 *
 * When the plan came from a reasoning provider, this stage stops being a
 * formality. Nobody has read the proposed content yet — a model wrote it and a
 * validator checked its shape — so the approver is given the candidate UUID,
 * the fingerprint, the paths, the assumptions and the unresolved unknowns, and
 * the workflow stops.
 *
 * The stage no longer reads a name off the task row. It reads the approval
 * ledger, which can distinguish "approved", "rejected", "superseded by a newer
 * candidate", "revoked" and "expired" — five different reasons to stop that the
 * task row rendered as one blank field.
 */
final class RequestApprovalStage extends BaseStage
{
    public function name(): string { return 'REQUEST_APPROVAL'; }
    public function purpose(): string { return 'Put the exact candidate — its UUID, fingerprint, files and unknowns — in front of a human, and stop until they answer.'; }
    public function inputs(): array { return ['approval ledger', 'plan.candidate_uuid', 'estimate.*', 'risks.unknowns']; }
    public function outputs(): array { return ['approval.granted', 'approval.subject', 'approval.state']; }
    public function failureModes(): array {
        return [
            'nobody has answered yet',
            'the approval was rejected, revoked, superseded or has expired',
            'the approver was shown a summary rather than the content that will be installed',
        ];
    }
    public function verification(): string { return 'the approver is recorded by name against a candidate UUID and a fingerprint covering every proposed byte'; }
    public function recovery(): string { return 'approve the exact candidate in the Command Center, or reject it with an instruction for one bounded revision'; }

    public function run(WorkflowContext $context): StageResult
    {
        $candidate = $context->get('plan.candidate');
        $candidateUuid = $context->get('plan.candidate_uuid');

        $subject = [
            'steps'          => count($context->get('plan.steps', [])),
            'files'          => array_column($context->get('plan.steps', []), 'file'),
            'origin'         => $context->get('plan.source', 'supplied change set'),
            'complexity'     => $context->get('estimate.complexity'),
            'unknowns'       => $context->get('risks.unknowns', []),
            'candidate_uuid' => $candidateUuid,
        ];

        if ($candidate !== null) {
            $subject['candidate'] = [
                'provider'            => $candidate->provider,
                'model'               => $candidate->model,
                'confidence'          => $candidate->confidence(),
                'content_fingerprint' => $candidate->contentFingerprint(),
                'assumptions'         => $candidate->get('assumptions', []),
                'bytes'               => array_map(
                    fn (array $c) => strlen((string) ($c['content'] ?? '')),
                    $candidate->fileChanges()
                ),
            ];
        }

        // A MIGRATION IS NOT APPROVABLE ON TRUST.
        //
        // Sprint 10.1 certified the rehearsal and left it optional, so a
        // candidate could still reach a human with no evidence, or with
        // evidence about different bytes. The gate binds to content first:
        // an hour-old rehearsal of the exact file is good, a fresh rehearsal
        // of the file as it was before the last edit is not.
        $migrationFiles = [];
        if ($candidate !== null) {
            foreach ($candidate->asChangeSet() as $path => $spec) {
                if (str_starts_with($path, 'database/migrations/')) {
                    $migrationFiles[$path] = (string) $spec['content'];
                }
            }
        }

        $gate = (new RehearsalGate())->evaluate(
            $migrationFiles,
            (string) ($candidateUuid ?? ''),
            (string) $context->project->key,
            (string) ($context->project->test_database ?? '')
        );

        $context->set('approval.rehearsal_gate', $gate);
        $subject['rehearsal_gate'] = $gate;

        $context->set('approval.subject', $subject);

        if ($gate['required'] && ! $gate['permitted']) {
            return StageResult::blocked(
                'migration rehearsal gate: ' . implode(', ', array_unique(array_column($gate['refusals'], 'reason'))),
                implode(' | ', array_map(
                    fn ($r) => $r['reason'] . ' — ' . $r['detail'], $gate['refusals'])),
                ['subject' => $subject, 'gate' => $gate]
            );
        }

        // A human-supplied change set has no candidate to bind to; it is the
        // engineer's own work and the task row remains the record.
        if ($candidateUuid === null) {
            $approver = $context->task->approved_by ?? null;
            if ($approver === null || trim((string) $approver) === '') {
                return StageResult::blocked('awaiting approval',
                    'the plan, estimate and risks are recorded. Nothing will be written until a human approves.',
                    ['subject' => $subject]);
            }

            $context->set('approval.granted', true);
            $context->set('approval.state', ApprovalState::APPROVED);

            return StageResult::ok('approved by ' . $approver,
                ['approved_by' => $approver, 'approved_at' => $context->task->approved_at, 'subject' => $subject],
                ['note' => (string) ($context->task->approval_note ?? '')]);
        }

        $approval = (new ApprovalLedger())->forCandidateUuid((string) $candidateUuid);

        if ($approval === null || $approval->state === ApprovalState::PENDING) {
            $detail = 'candidate ' . $candidateUuid . ' was authored by '
                    . $candidate->provider . '/' . $candidate->model
                    . ' and no human has approved these exact bytes yet. Review the diff in the '
                    . 'Command Center and approve by fingerprint.';

            $unknowns = $context->get('risks.unknowns', []);
            if ($unknowns !== []) {
                $detail .= ' ' . count($unknowns) . ' unknown(s) remain unresolved: '
                         . implode('; ', array_slice(array_column($unknowns, 'question'), 0, 3));
            }

            $context->set('approval.state', ApprovalState::PENDING);

            return StageResult::blocked('awaiting approval', $detail, ['subject' => $subject]);
        }

        if (! ApprovalState::permitsExecution((string) $approval->state)) {
            $context->set('approval.state', $approval->state);

            return StageResult::blocked(
                'approval is ' . strtolower((string) $approval->state),
                match ((string) $approval->state) {
                    ApprovalState::REJECTED   => 'a human refused this candidate'
                                                 . ($approval->comment ? ': ' . $approval->comment : '')
                                                 . '. Request the one bounded revision, or supply the work yourself.',
                    ApprovalState::SUPERSEDED => 'a newer candidate exists, so this approval no longer describes '
                                                 . 'what would be written. Approve the current candidate.',
                    ApprovalState::REVOKED    => 'the approval was withdrawn'
                                                 . ($approval->revoked_by ? ' by ' . $approval->revoked_by : ''),
                    ApprovalState::EXPIRED    => 'the approval expired. A diff reviewed against an older tree '
                                                 . 'is not a diff anybody reviewed.',
                    default                   => 'state ' . $approval->state,
                },
                ['subject' => $subject, 'approval_state' => $approval->state]
            );
        }

        $context->set('approval.granted', true);
        $context->set('approval.state', ApprovalState::APPROVED);

        return StageResult::ok('approved by ' . $approval->approver_name,
            ['approved_by' => $approval->approver_name, 'approved_at' => $approval->approved_at,
             'fingerprint' => $approval->fingerprint, 'candidate_uuid' => $candidateUuid,
             'subject' => $subject],
            ['statement' => (string) $approval->statement,
             'comment'   => (string) ($approval->comment ?? '')]);
    }
}
