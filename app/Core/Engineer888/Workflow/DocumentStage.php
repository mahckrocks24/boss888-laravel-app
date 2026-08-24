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

/** 12. Write down what happened. */
final class DocumentStage extends BaseStage
{
    public function name(): string { return 'DOCUMENT'; }
    public function purpose(): string { return 'Leave a record another engineer can read without re-deriving the work.'; }
    public function inputs(): array { return ['all prior stage outputs']; }
    public function outputs(): array { return ['document.path']; }
    public function failureModes(): array { return ['the record cannot be written']; }
    public function verification(): string { return 'the file exists and contains the task uuid'; }
    public function recovery(): string { return 'check filesystem permissions; the work itself is unaffected'; }

    public function run(WorkflowContext $context): StageResult
    {
        $directory = $context->repoPath . '/.engineer888/tasks';
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true)) {
            return StageResult::failed('cannot document', "cannot create {$directory}");
        }

        $task = $context->task;
        $lines = [
            '# ' . $task->title,
            '',
            '- task: `' . $task->uuid . '`',
            '- project: ' . $context->project->company . ' / ' . $context->project->name,
            '- kind: ' . $task->kind . ' · priority: ' . $task->priority,
            '- requested by: ' . ($task->requested_by ?? 'unrecorded'),
            '- approved by: ' . ($task->approved_by ?? 'not approved'),
            '',
            '## Description', '', (string) $task->description, '',
            '## Files changed', '',
        ];

        foreach ($context->get('files.owned', []) as $file) { $lines[] = '- `' . $file . '`'; }

        $lines[] = '';
        $lines[] = '## Estimate';
        $lines[] = '';
        foreach (['complexity', 'risk', 'confidence'] as $key) {
            $lines[] = '- ' . $key . ': ' . ($context->get('estimate.' . $key) ?? 'n/a');
        }

        $lines[] = '';
        $lines[] = '## Risks';
        $lines[] = '';
        foreach ($context->get('risks.task', []) as $risk) {
            $lines[] = '- `' . $risk['file'] . '` — ' . $risk['risk'] . ' (breaks: ' . $risk['breaks'] . ')';
        }
        if ($context->get('risks.task', []) === []) { $lines[] = '- none identified'; }

        $lines[] = '';
        $lines[] = '## Verification';
        $lines[] = '';
        foreach ($context->get('verify.checks', []) as $check) {
            $lines[] = '- ' . ($check['passed'] ? 'PASS' : 'FAIL') . ' — ' . $check['check'];
        }

        $lines[] = '';
        $lines[] = '## Deployment verification';
        $lines[] = '';
        $lines[] = '- verdict: ' . ($context->get('deploy.verdict') ?? 'not run');
        $lines[] = '';

        $path = $directory . '/' . substr($task->uuid, 0, 8) . '-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($task->title)) . '.md';
        file_put_contents($path, implode("\n", $lines) . "\n");

        $relative = ltrim(str_replace($context->repoPath, '', $path), '/');
        $context->set('document.path', $relative);

        return StageResult::ok('recorded', ['path' => $relative]);
    }
}
