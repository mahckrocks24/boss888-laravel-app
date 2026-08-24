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

/** 11. Is production still honest about itself? */
final class DeployVerifyStage extends BaseStage
{
    public function name(): string { return 'DEPLOY_VERIFY'; }
    public function purpose(): string { return 'Check the platform after the change, and report release identity truthfully.'; }
    public function inputs(): array { return ['verify.checks']; }
    public function outputs(): array { return ['deploy.verdict']; }
    public function failureModes(): array { return ['a critical check fails', 'evidence is unavailable']; }
    public function verification(): string { return 'read-only deployment verification against the environment'; }
    public function recovery(): string { return 'the guidance section of the verification names the failing check and its evidence'; }

    public function run(WorkflowContext $context): StageResult
    {
        $result = (new DeploymentVerifier($context->repoPath))->verify('production', null, true);
        $context->set('deploy.verdict', $result['verdict']);

        $halting = in_array($result['verdict'], [DeploymentVerifier::FAILED, DeploymentVerifier::BLOCKED], true);

        if ($halting) {
            return StageResult::failed('deployment verification ' . $result['verdict'], $result['reason'],
                ['verdict' => $result['verdict']]);
        }

        return StageResult::ok($result['verdict'],
            ['verdict' => $result['verdict'], 'checks' => count($result['checks'])],
            ['reason' => $result['reason']]);
    }
}
