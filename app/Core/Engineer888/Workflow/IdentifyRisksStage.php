<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Coordination\GovernedFiles;

/**
 * 7. What could this break?
 *
 * Two sources of risk, kept apart. Engineer888 derives its own from measurable
 * facts — governed files, migrations, fan-in — and those are the ones it stands
 * behind. A reasoned candidate may also declare risks, and those are carried
 * forward attributed to the provider that stated them.
 *
 * The attribution is the point. A model's risk assessment is a claim, not a
 * measurement, and merging the two lists would launder the claim into a finding.
 */
final class IdentifyRisksStage extends BaseStage
{
    public function name(): string { return 'IDENTIFY_RISKS'; }
    public function purpose(): string { return 'State what this change could break, before approval rather than after failure.'; }
    public function inputs(): array { return ['analyze.report', 'files.owned', 'reasoning.risks', 'reasoning.unknowns']; }
    public function outputs(): array { return ['risks.task', 'risks.declared', 'risks.unknowns']; }
    public function failureModes(): array {
        return [
            'a critical repository risk is already present and unrelated to this task',
            'a reasoned proposal declares an unknown that nobody resolves before approval',
        ];
    }
    public function verification(): string { return 'each risk names what it would break, and each carries its source'; }
    public function recovery(): string { return 'resolve the risk or record it as accepted on the task'; }

    public function run(WorkflowContext $context): StageResult
    {
        $risks = [];

        foreach ($context->get('plan.steps', []) as $step) {
            if (GovernedFiles::isGoverned($step['file'])) {
                $policy = GovernedFiles::policyFor($step['file']);
                $risks[] = ['file' => $step['file'], 'risk' => 'governed shared file',
                            'breaks' => $policy['affects'] ?? 'other engineers', 'source' => 'measured'];
            }
            if (str_starts_with($step['file'], 'database/migrations/')) {
                $risks[] = ['file' => $step['file'], 'risk' => 'schema change',
                            'breaks' => 'any environment whose database has not applied it', 'source' => 'measured'];
            }
            if ($step['used_by'] >= 20) {
                $risks[] = ['file' => $step['file'], 'risk' => 'high fan-in (' . $step['used_by'] . ' dependents)',
                            'breaks' => 'a wide blast radius if the contract changes', 'source' => 'measured'];
            }
            if (($step['origin'] ?? 'human') === 'reasoning') {
                $risks[] = ['file' => $step['file'], 'risk' => 'content authored by a reasoning provider',
                            'breaks' => 'nothing by itself — but this file has been read by no human until '
                                      . 'the approval gate, so approval is a review and not a formality',
                            'source' => 'measured'];
            }
        }

        // Risks the provider stated about its own proposal. Carried, attributed,
        // and never counted as Engineer888's own analysis.
        $declared = [];
        foreach ($context->get('reasoning.risks', []) as $risk) {
            $declared[] = [
                'risk'       => (string) ($risk['risk'] ?? 'unstated'),
                'breaks'     => (string) ($risk['breaks'] ?? 'UNKNOWN'),
                'mitigation' => (string) ($risk['mitigation'] ?? 'UNKNOWN'),
                'source'     => 'declared by ' . ($context->get('plan.candidate')?->provider ?? 'the provider'),
            ];
        }

        // Unknowns must survive to the approver. An unresolved question that
        // quietly stops being mentioned is the failure this stage exists to stop.
        $unknowns = [];
        foreach ($context->get('reasoning.unknowns', []) as $unknown) {
            $unknowns[] = [
                'question'       => (string) ($unknown['question'] ?? '?'),
                'why_it_matters' => (string) ($unknown['why_it_matters'] ?? 'UNKNOWN'),
                'how_to_resolve' => (string) ($unknown['how_to_resolve'] ?? 'UNKNOWN'),
            ];
        }

        $report = $context->get('analyze.report', []);
        $preExisting = array_values(array_filter($report['risks'] ?? [], fn ($r) => $r['severity'] === 'critical'));

        $context->set('risks.task', $risks);
        $context->set('risks.declared', $declared);
        $context->set('risks.unknowns', $unknowns);

        return StageResult::ok(
            count($risks) . ' task risk(s), ' . count($declared) . ' declared by the provider, '
                . count($unknowns) . ' unresolved unknown(s), '
                . count($preExisting) . ' pre-existing critical repository risk(s)',
            ['task_risks' => $risks, 'declared_risks' => $declared, 'unknowns' => $unknowns,
             'pre_existing' => array_column($preExisting, 'id')],
            ['note' => 'pre-existing risks are reported, not attributed to this task; provider-declared '
                     . 'risks are claims, not measurements']
        );
    }
}
