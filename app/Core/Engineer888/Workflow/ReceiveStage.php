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

/** 1. Is this a task at all? */
final class ReceiveStage extends BaseStage
{
    public function name(): string { return 'RECEIVE'; }
    public function purpose(): string { return 'Confirm the request is a workable engineering task before any effort is spent on it.'; }
    public function inputs(): array { return ['task.title', 'task.description', 'task.project']; }
    public function outputs(): array { return ['received.completeness', 'received.unknowns']; }
    public function failureModes(): array {
        return ['no description, so scope cannot be judged', 'no acceptance criteria, so completion cannot be judged'];
    }
    public function verification(): string { return 'every required field is present and non-empty'; }
    public function recovery(): string { return 'the requester supplies the missing field; nothing has been changed'; }

    public function run(WorkflowContext $context): StageResult
    {
        $missing = [];
        foreach (['title', 'description'] as $field) {
            if (trim((string) ($context->task->{$field} ?? '')) === '') { $missing[] = $field; }
        }

        $criteria = $context->taskJson('acceptance_criteria');
        $unknowns = $context->taskJson('unknowns');

        if ($missing !== []) {
            return StageResult::failed('incomplete task', 'missing: ' . implode(', ', $missing));
        }

        $context->set('received.unknowns', $unknowns);

        return StageResult::ok(
            'task accepted' . ($criteria === [] ? ' (no acceptance criteria — completion will be judged by verification only)' : ''),
            ['acceptance_criteria' => $criteria, 'unknowns' => $unknowns],
            ['kind' => $context->task->kind, 'priority' => $context->task->priority]
        );
    }
}
