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

/** 6. Exactly which files, and are they mine? */
final class IdentifyFilesStage extends BaseStage
{
    public function name(): string { return 'IDENTIFY_FILES'; }
    public function purpose(): string { return 'Resolve the exact file list and prove ownership of every entry before anything is written.'; }
    public function inputs(): array { return ['plan.steps', 'understand.manifest']; }
    public function outputs(): array { return ['files.owned', 'files.refused']; }
    public function failureModes(): array { return ['a file belongs to another engineer', 'a governed file is undeclared']; }
    public function verification(): string { return 'every file resolves to OWNED or SHARED_DECLARED'; }
    public function recovery(): string { return 'declare the file in the sprint manifest, or hand the task to its owner'; }

    public function run(WorkflowContext $context): StageResult
    {
        /** @var OwnershipManifest $manifest */
        $manifest = $context->get('understand.manifest');
        $owned = [];
        $refused = [];

        foreach ($context->get('plan.steps', []) as $step) {
            $ownership = $manifest->classify($step['file']);
            if (OwnershipManifest::isCommittable($ownership['status'])) {
                $owned[] = $step['file'];
                continue;
            }
            $refused[] = ['file' => $step['file'], 'status' => $ownership['status'], 'evidence' => $ownership['evidence']];
        }

        $context->set('files.owned', $owned);
        $context->set('files.refused', $refused);

        if ($refused !== []) {
            return StageResult::blocked(
                count($refused) . ' file(s) not owned by this sprint',
                'technical coherence is not ownership: ' . implode('; ', array_column($refused, 'file')),
                ['owned' => $owned, 'refused' => $refused]
            );
        }

        return StageResult::ok(count($owned) . ' file(s), all owned', ['owned' => $owned]);
    }
}
