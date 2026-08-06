<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Coordination\GovernedFiles;

/**
 * 5. How big, how risky, how confident?
 *
 * Confidence is measured, then capped. Engineer888 derives it from counted
 * unknowns; where a reasoning provider stated its own confidence, the lower of
 * the two wins.
 *
 * Taking the lower is deliberate. A model that says "low" knows something about
 * its answer that no downstream measurement can recover, and letting a
 * well-shaped proposal raise the estimate would be the workflow flattering
 * itself with the model's fluency.
 */
final class EstimateStage extends BaseStage
{
    private const RANK = ['low' => 0, 'unknown' => 0, 'moderate' => 1, 'medium' => 1, 'high' => 2];

    public function name(): string { return 'ESTIMATE'; }
    public function purpose(): string { return 'Derive size, risk and confidence from measurements rather than intuition.'; }
    public function inputs(): array { return ['plan.steps', 'analyze.report', 'reasoning.unknowns']; }
    public function outputs(): array { return ['estimate.complexity', 'estimate.risk', 'estimate.confidence']; }
    public function failureModes(): array { return ['no measurable basis, producing a confident-sounding guess']; }
    public function verification(): string { return 'every figure cites the measurement it came from'; }
    public function recovery(): string { return 'not applicable — an estimate cannot fail, only be wrong; it is recorded for later comparison'; }

    public function run(WorkflowContext $context): StageResult
    {
        $steps = $context->get('plan.steps', []);

        $impact = 0;
        foreach ($steps as $step) { $impact += (int) $step['used_by']; }

        $complexity = count($steps) <= 2 && $impact < 10 ? 'small'
            : (count($steps) <= 8 && $impact < 60 ? 'medium' : 'large');

        $touchesGoverned = false;
        $touchesMigration = false;
        foreach ($steps as $step) {
            if (GovernedFiles::isGoverned($step['file'])) { $touchesGoverned = true; }
            if (str_starts_with($step['file'], 'database/migrations/')) { $touchesMigration = true; }
        }

        $risk = $touchesMigration ? 'elevated' : ($touchesGoverned ? 'elevated' : ($impact > 40 ? 'moderate' : 'low'));

        // Confidence falls when the task, or the proposal, admits unknowns.
        $taskUnknowns = count($context->get('received.unknowns', []));
        $reasonedUnknowns = count($context->get('reasoning.unknowns', []));
        $unknowns = $taskUnknowns + $reasonedUnknowns;

        $confidence = $unknowns === 0 ? 'high' : ($unknowns <= 2 ? 'moderate' : 'low');

        $candidate = $context->get('plan.candidate');
        $stated = $candidate?->confidence();
        if ($stated !== null && (self::RANK[$stated] ?? 0) < (self::RANK[$confidence] ?? 0)) {
            $confidence = $stated === 'medium' ? 'moderate' : $stated;
        }

        return StageResult::ok(
            "complexity {$complexity}, risk {$risk}, confidence {$confidence}",
            compact('complexity', 'risk', 'confidence'),
            [
                'basis' => count($steps) . ' step(s), ' . $impact . ' dependent file(s), '
                         . ($touchesGoverned ? 'touches a governed file, ' : '')
                         . ($touchesMigration ? 'contains a migration, ' : '')
                         . $taskUnknowns . ' declared unknown(s) on the task, '
                         . $reasonedUnknowns . ' raised by reasoning'
                         . ($stated === null ? '' : ', provider stated ' . $stated),
            ]
        );
    }
}
