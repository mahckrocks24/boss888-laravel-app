<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\ReasoningOutcome;
use App\Core\Engineer888\Repository\DependencyGraph;

/**
 * 4. What are the steps?
 *
 * Three ways in, in strict order of precedence.
 *
 *  1. AN APPROVED CANDIDATE EXISTS → use it. Do not reason. This is the Sprint 8
 *     fix at its root: in Sprint 7 a human approved candidate #12 and PLAN
 *     reasoned again inside the same run, so IMPLEMENT installed #13. Both were
 *     valid; neither was what anybody read. Reasoning behind an approval is now
 *     impossible rather than merely discouraged.
 *  2. The task carries a change set → use it. The Sprint 6 path, unchanged, and
 *     still the path a human uses directly.
 *  3. Otherwise → ask the Engineering Reasoning Engine.
 *
 * A new candidate supersedes every open approval on the task the moment it is
 * stored, so route 3 can never leave stale permission standing.
 */
final class PlanStage extends BaseStage
{
    public function name(): string { return 'PLAN'; }
    public function purpose(): string { return 'Produce independently verifiable steps — from an approved candidate, a supplied change set, or reasoning — each naming its files, verification and rollback.'; }
    public function inputs(): array { return ['approval ledger', 'task.change_set', 'project', 'reasoning provider (only when nothing is approved and no change set is supplied)']; }
    public function outputs(): array { return ['plan.steps', 'plan.graph', 'plan.candidate', 'plan.candidate_uuid', 'plan.source']; }
    public function failureModes(): array {
        return [
            'no change set and no reasoning provider available',
            'the provider answered but the answer violated the output contract',
            'the provider proposed no file content, so there is nothing to implement',
            'an approved candidate no longer validates',
            'a step has no verification',
        ];
    }
    public function verification(): string { return 'every step names at least one file and one verification; a reasoned step also records the provider, model, candidate UUID and context fingerprint that produced it'; }
    public function recovery(): string { return 'supply a change set, configure a reasoning provider, or re-run after resolving the recorded contract violations'; }

    public function run(WorkflowContext $context): StageResult
    {
        $engine = ReasoningEngine::make();
        $changeSet = $context->taskJson('change_set');
        $source = 'supplied change set';
        $candidate = null;
        $candidateUuid = null;
        $reasoning = null;

        // 1. Approved work is never re-reasoned.
        $approval = (new ApprovalLedger())->activeFor((int) $context->task->id);
        if ($approval !== null) {
            $candidate = $engine->approvedCandidate((int) $context->task->id);

            if ($candidate === null) {
                return StageResult::blocked('approved candidate no longer validates',
                    'candidate ' . $approval->candidate_uuid . ' is approved but no longer passes the output '
                    . 'contract, so it cannot be planned. Revoke the approval and reason again.');
            }

            $candidateUuid = (string) $approval->candidate_uuid;
            $changeSet = $candidate->asChangeSet();
            $source = 'approved candidate ' . substr($candidateUuid, 0, 8)
                    . ' (' . $candidate->provider . '/' . $candidate->model . ')';

            $context->set('plan.candidate', $candidate);
            $context->set('plan.candidate_uuid', $candidateUuid);
            $context->set('reasoning.unknowns', $candidate->unknowns());
            $context->set('reasoning.risks', $candidate->risks());
        } elseif ($changeSet === []) {
            // 3. Nothing approved, nothing supplied — reason.
            $reasoning = $engine->propose($context->project, $context->task);

            // ONE VALIDATOR-DRIVEN REVISION, AND ONLY ONE.
            //
            // retryForViolations() has existed since Sprint 7, complete with its
            // eligibility list and the shared revision cap, and nothing had ever
            // called it. The measurement that made it worth wiring: twelve
            // consecutive candidates for the same task understood the
            // persistence requirement and checked every save(), and every one of
            // them was refused for the same single unchecked write in the
            // constructor. The provider was not missing the architecture; it was
            // missing one deterministic finding it never got to see.
            //
            // The cap is not enforced here. revise() refuses a second attempt
            // through CandidateStore::revisionCount(), so a third provider call
            // is impossible whatever this stage does. An ineligible violation
            // returns UNAVAILABLE and the original rejection stands, which is
            // how a governance refusal keeps meaning what it meant.
            if ($reasoning->status === ReasoningOutcome::REJECTED) {
                $retry = $engine->retryForViolations(
                    $context->project, $context->task,
                    (string) $reasoning->candidateUuid, $reasoning->violations
                );

                if ($retry->status !== ReasoningOutcome::UNAVAILABLE) {
                    $context->set('reasoning.revised', true);
                    $context->set('reasoning.first_pass_violations', $reasoning->violations);
                    $reasoning = $retry;
                }
            }

            $context->set('reasoning.outcome', $reasoning);

            if (! $reasoning->isValidated()) {
                return StageResult::blocked(
                    'no plan: ' . strtolower($reasoning->status),
                    $this->explain($reasoning),
                    ['reasoning' => $reasoning->toArray()]
                );
            }

            $candidate = $reasoning->candidate;
            $candidateUuid = $reasoning->candidateUuid;

            if ($candidate->isEmpty()) {
                return StageResult::blocked(
                    'candidate proposes no file changes',
                    'the provider produced valid engineering output but no file content. That is a '
                    . 'legitimate answer to an analysis question and it is recorded, but it cannot be '
                    . 'implemented. Unknowns: ' . $this->unknownSummary($candidate),
                    ['reasoning' => $reasoning->toArray()]
                );
            }

            $changeSet = $candidate->asChangeSet();
            $source = 'reasoning:' . $candidate->provider . '/' . $candidate->model;

            $context->set('plan.candidate', $candidate);
            $context->set('plan.candidate_uuid', $candidateUuid);
            $context->set('reasoning.unknowns', $candidate->unknowns());
            $context->set('reasoning.risks', $candidate->risks());
        }

        if ($changeSet === []) {
            return StageResult::blocked('no change set',
                'this task declares no files to change. An analysis-only task is legitimate, but it '
                . 'cannot be IMPLEMENTED, so declare a change set or mark the task analysis-only.');
        }

        $graph = (new DependencyGraph($context->repoPath))->build();
        $context->set('plan.graph', $graph);

        $steps = [];
        foreach ($changeSet as $destination => $spec) {
            $dependents = $graph->usedBy($destination);
            $steps[] = [
                'file'         => $destination,
                'source'       => is_array($spec) ? $source : $spec,
                'origin'       => $candidate === null ? 'human' : 'reasoning',
                'dependencies' => array_slice($graph->dependsOn($destination), 0, 5),
                'used_by'      => count($dependents),
                'expected'     => 'destination content equals the declared source',
                'verification' => 'php -l, then the tests that reference this file',
                'rollback'     => 'SafeInstaller writes a verified backup before any overwrite',
            ];
        }

        $context->set('plan.steps', $steps);
        $context->set('plan.source', $source);

        $outputs = ['steps' => $steps, 'source' => $source];
        if ($candidate !== null) {
            $outputs['candidate'] = [
                'uuid'                => $candidateUuid,
                'provider'            => $candidate->provider,
                'model'               => $candidate->model,
                'confidence'          => $candidate->confidence(),
                'request_fingerprint' => $candidate->requestFingerprint,
                'content_fingerprint' => $candidate->contentFingerprint(),
                'understanding'       => $candidate->get('problem_understanding'),
                'strategy'            => $candidate->get('implementation_strategy'),
                'assumptions'         => $candidate->get('assumptions', []),
                'unknowns'            => $candidate->unknowns(),
                'testing_strategy'    => $candidate->get('testing_strategy'),
                'rollback'            => $candidate->get('rollback'),
                'migrations'          => $candidate->get('migrations'),
                'reused_approved'     => $approval !== null,
            ];
        }

        return StageResult::ok(
            count($steps) . ' step(s) from ' . $source,
            $outputs,
            $reasoning === null ? [] : ['context' => $reasoning->request?->contextManifest(),
                                        'excluded_items' => count($reasoning->request?->excluded ?? [])]
        );
    }

    private function explain(ReasoningOutcome $outcome): string
    {
        return match ($outcome->status) {
            ReasoningOutcome::DISABLED       => 'reasoning is switched off and the task carries no change set. '
                                                . 'Supply one, or enable a provider.',
            ReasoningOutcome::UNAVAILABLE    => $outcome->reason . '. Nothing was invented in its place.',
            ReasoningOutcome::PROVIDER_ERROR => $outcome->reason . '. A failed call is reported, never substituted.',
            ReasoningOutcome::REJECTED       => 'the provider answered, and Engineer888 refused the answer. '
                                                . $outcome->reason,
            default                          => $outcome->reason,
        };
    }

    private function unknownSummary(CandidateImplementation $candidate): string
    {
        $questions = array_map(fn (array $u) => (string) ($u['question'] ?? '?'), $candidate->unknowns());

        return $questions === [] ? 'none declared' : implode('; ', array_slice($questions, 0, 3));
    }
}
