<?php

namespace App\Core\Engineer888\Workflow;

/**
 * 13. What did we learn?
 *
 * Findings must cite a stage output. That rule now does more work than it did in
 * Sprint 6, because a reasoning provider will happily narrate a lesson it did
 * not learn — its own "implementation_strategy" reads exactly like a finding.
 *
 * So nothing a provider *said* becomes a finding. Only what the workflow
 * *observed* about the proposal does: whether it installed, whether verification
 * passed, whether its unknowns were ever resolved. Promotion requires
 * implementation evidence, and reasoning is not implementation.
 */
final class LearnStage extends BaseStage
{
    public function name(): string { return 'LEARN'; }
    public function purpose(): string { return 'Extract what this task taught, so the next one does not rediscover it.'; }
    public function inputs(): array { return ['implement.decisions', 'verify.checks', 'implement.origin', 'risks.unknowns']; }
    public function outputs(): array { return ['learn.findings']; }
    public function failureModes(): array {
        return [
            'a lesson is asserted without evidence from this task',
            'a provider claim is recorded as though the workflow had observed it',
        ];
    }
    public function verification(): string { return 'every finding cites a stage output'; }
    public function recovery(): string { return 'not applicable — an empty finding list is a valid outcome'; }

    public function run(WorkflowContext $context): StageResult
    {
        $findings = [];

        foreach ($context->get('implement.decisions', []) as $decision) {
            if ($decision['state'] === 'DIVERGED') {
                $findings[] = ['finding' => 'a destination had been edited after installation',
                               'evidence' => $decision['destination'] . ' — ' . $decision['reason'],
                               'candidate' => 'incident-pattern'];
            }
            if ($decision['state'] === 'OVERRIDDEN') {
                $findings[] = ['finding' => 'an operator override was required',
                               'evidence' => $decision['destination'], 'candidate' => 'playbook'];
            }
        }

        foreach ($context->get('verify.checks', []) as $check) {
            if (! $check['passed']) {
                $findings[] = ['finding' => 'verification caught a defect before deployment',
                               'evidence' => $check['check'], 'candidate' => 'regression_test'];
            }
        }

        if ($context->get('risks.task', []) !== []) {
            $findings[] = [
                'finding'   => 'this change carried a named risk that was accepted before implementation',
                'evidence'  => count($context->get('risks.task')) . ' risk(s) recorded at IDENTIFY_RISKS',
                'candidate' => 'check',
            ];
        }

        // What the workflow observed about reasoning — never what reasoning said
        // about itself.
        $origin = (string) $context->get('implement.origin', 'human');
        if (str_starts_with($origin, 'reasoning:')) {
            // Read from what VERIFY recorded rather than a separate flag: one
            // source of truth, and it is the one the audit trail shows.
            $checks = $context->get('verify.checks', []);
            $verified = $checks !== [] && ! in_array(false, array_column($checks, 'passed'), true);
            $files = array_column($context->get('plan.steps', []), 'file');

            $findings[] = [
                'finding'   => $verified === true
                    ? 'a reasoned candidate was installed and passed verification'
                    : 'a reasoned candidate was installed and did not pass verification',
                'evidence'  => $origin . ' produced ' . count($files) . ' file(s): ' . implode(', ', $files)
                             . ' — VERIFY ' . ($verified === true ? 'passed' : 'did not pass'),
                'candidate' => $verified === true ? 'decision' : 'failure_pattern',
            ];

            $unresolved = $context->get('risks.unknowns', []);
            if ($unresolved !== []) {
                $findings[] = [
                    'finding'   => 'a reasoned candidate was approved with unknowns still unresolved',
                    'evidence'  => count($unresolved) . ' unknown(s) survived to approval: '
                                 . implode('; ', array_slice(array_column($unresolved, 'question'), 0, 3)),
                    'candidate' => 'check',
                ];
            }
        }

        $context->set('learn.findings', $findings);

        return StageResult::ok(count($findings) . ' finding(s)', ['findings' => $findings],
            ['note' => 'findings are candidates; promotion requires evidence and happens in PROMOTE. '
                     . 'Nothing a provider asserted about its own work appears here.']);
    }
}
