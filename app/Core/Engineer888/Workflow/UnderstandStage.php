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

/** 2. What environment is this task about? */
final class UnderstandStage extends BaseStage
{
    public function name(): string { return 'UNDERSTAND'; }
    public function purpose(): string { return 'Resolve the project, repository, ownership manifest and test target the task will operate on.'; }
    public function inputs(): array { return ['project.repository_path', 'project.ownership_manifest']; }
    public function outputs(): array { return ['understand.repo', 'understand.manifest', 'understand.head']; }
    public function failureModes(): array {
        return ['the repository path does not exist', 'no ownership manifest, so nothing can be proved owned'];
    }
    public function verification(): string { return 'the repository resolves and the manifest loads'; }
    public function recovery(): string { return 'register the project correctly, or create the sprint manifest'; }

    public function run(WorkflowContext $context): StageResult
    {
        if (! is_dir($context->repoPath)) {
            return StageResult::failed('repository unreachable', $context->repoPath . ' is not a directory');
        }

        $manifest = OwnershipManifest::active($context->repoPath);
        if ($manifest === null) {
            return StageResult::blocked('no ownership manifest',
                'without a manifest nothing can be proved owned, so no file may be changed');
        }

        $head = trim(Shell::run('git', ['rev-parse', 'HEAD'], $context->repoPath)['out']);
        $branch = trim(Shell::run('git', ['rev-parse', '--abbrev-ref', 'HEAD'], $context->repoPath)['out']);

        $context->set('understand.manifest', $manifest);
        $context->set('understand.head', $head);

        return StageResult::ok(
            "project {$context->project->key} on {$branch}",
            ['head' => $head, 'branch' => $branch, 'sprint' => $manifest->sprint()],
            ['company' => $context->project->company, 'test_database' => $context->project->test_database]
        );
    }
}
