<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Deployment\DeploymentVerifier;
use App\Core\Engineer888\Install\SafeInstaller;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\RepositoryIntelligence;
use App\Core\Engineer888\Signals\Shell;
use Illuminate\Support\Facades\DB;

/**
 * The fourteen lifecycle stages.
 *
 * Every stage ORCHESTRATES capabilities that already exist and were already
 * proved: Repository Intelligence, the dependency graph, the ownership manifest,
 * SafeInstaller, the commit engine's test selection, deployment verification.
 * Nothing here re-implements analysis. Where a stage looks thin, that is the
 * point — the work was done in earlier sprints and this sprint is the sequence.
 *
 * They live in one file because they are one process. Fourteen files of twenty
 * lines each would obscure the order, which is the thing worth reading.
 */

/** 3. What is the state of the codebase this task lands in? */
final class AnalyzeStage extends BaseStage
{
    public function name(): string { return 'ANALYZE'; }
    public function purpose(): string { return 'Measure the repository the task will change, so scope and risk rest on facts rather than impression.'; }
    public function inputs(): array { return ['understand.repo']; }
    public function outputs(): array { return ['analyze.report', 'analyze.risks']; }
    public function failureModes(): array { return ['the working tree is unreadable', 'analysis contradicts the task description']; }
    public function verification(): string { return 'Repository Intelligence returns an available report'; }
    public function recovery(): string { return 'resolve the repository problem; no change has been made'; }

    public function run(WorkflowContext $context): StageResult
    {
        $report = (new RepositoryIntelligence($context->repoPath))->analyse();
        if (! ($report['available'] ?? false)) {
            return StageResult::failed('analysis unavailable', (string) ($report['reason'] ?? 'unknown'));
        }

        $context->set('analyze.report', $report);

        $critical = array_values(array_filter($report['risks'], fn ($r) => $r['severity'] === 'critical'));

        return StageResult::ok(
            count($report['files']) . ' changed files, ' . count($report['risks']) . ' repository risks',
            [
                'changed_files'   => count($report['files']),
                'critical_risks'  => array_column($critical, 'id'),
                'subsystems'      => array_slice(array_keys($report['by_subsystem']), 0, 8),
            ],
            ['duration_ms' => $report['duration_ms'], 'graph_files' => $report['graph_files']]
        );
    }
}
