<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Execution\TestSelector;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Runtime\Workspace;
use App\Core\Engineer888\Verification\VerificationPlan;

/**
 * 10. Prove it works — with the smallest checks that could actually fail.
 *
 * Sprint 8 ran the entire project suite for a one-file change: 210 of 215
 * seconds spent proving that untouched code still worked. That is not caution,
 * it is the absence of a decision.
 *
 * The decision now lives in VerificationPlan, and this stage runs what it
 * selects and records why. The important half is the refusal: when no test
 * references a changed file and no policy widens the run, the verdict is
 * INSUFFICIENT_EVIDENCE and the workflow stops. A fast pass that proves nothing
 * is worse than a slow one, because it looks the same as a real result.
 */
final class VerifyStage extends BaseStage
{
    public function name(): string { return 'VERIFY'; }
    public function purpose(): string { return 'Run the smallest verification that would catch a mistake in exactly these files, and refuse when that would prove nothing.'; }
    public function inputs(): array { return ['files.owned', 'plan.graph', 'project.phpunit_config']; }
    public function outputs(): array { return ['verify.checks', 'verify.plan', 'verify.adequacy']; }
    public function failureModes(): array {
        return [
            'a check fails',
            'no isolated test configuration exists, so tests cannot be run safely',
            'no test references the change, so a narrow run would prove nothing',
            'the workspace for temporary verification files cannot be created',
        ];
    }
    public function verification(): string { return 'syntax, class loading, then the checks VerificationPlan selected — each recorded with the reason it was chosen and what was excluded'; }
    public function recovery(): string { return 'a failure here triggers recovery: every file this workflow wrote is restored to its pre-task state'; }

    public function run(WorkflowContext $context): StageResult
    {
        $files = $context->get('files.owned', []);
        $checks = [];

        $plan = (new VerificationPlan($context->repoPath, $context->get('plan.graph')))
            ->build($files, $context->project->phpunit_config);

        $context->set('verify.plan', $plan);
        $context->set('verify.adequacy', $plan['adequacy']);

        try {
            $workspace = Workspace::open($context->repoPath, 'wf-' . substr((string) $context->task->uuid, 0, 8));
        } catch (\Throwable $e) {
            return StageResult::failed('no verification workspace', $e->getMessage(), ['plan' => $plan]);
        }

        // STRUCTURE FIRST, ALWAYS.
        //
        // Syntax and class loading are cheap, and a file that will not parse is
        // complete evidence of a defect whatever the test selection concluded.
        // Gating them behind adequacy reported "insufficient evidence" for a
        // file that plainly did not compile — true of the selection, false of
        // the change, and it sends an engineer to the wrong place.
        $structural = ['syntax', 'class-load', 'config-load', 'route-table', 'migration-contract'];

        try {
            foreach ([true, false] as $structuralPass) {
                foreach ($plan['checks'] as $check) {
                    if (in_array($check['check'], $structural, true) !== $structuralPass) { continue; }

                    // Only the test-running half is governed by adequacy.
                    if (! $structuralPass && ! VerificationPlan::permitsExecution($plan['adequacy'])) {
                        return StageResult::blocked(
                            'verification is ' . strtolower(str_replace('_', ' ', $plan['adequacy'])),
                            implode('; ', $plan['policy']),
                            ['checks' => $checks, 'plan' => $plan]
                        );
                    }

                    $result = $this->runCheck($check, $context, $workspace);
                    $checks[] = $result;
                    $context->set('verify.checks', $checks);

                    if (! $result['passed']) {
                        return StageResult::failed(
                            $result['check'] . ' failed',
                            $result['target'] . ': ' . $result['detail'],
                            ['checks' => $checks, 'plan' => $plan]
                        );
                    }
                }
            }

            // A plan with no test checks at all still has to answer for itself.
            if (! VerificationPlan::permitsExecution($plan['adequacy'])) {
                return StageResult::blocked(
                    'verification is ' . strtolower(str_replace('_', ' ', $plan['adequacy'])),
                    implode('; ', $plan['policy']),
                    ['checks' => $checks, 'plan' => $plan]
                );
            }
        } finally {
            $workspace->close();
        }

        $context->set('verify.checks', $checks);

        return StageResult::ok(
            count($checks) . ' check(s) pass · ' . $plan['adequacy'],
            ['checks' => $checks, 'plan' => $plan],
            ['selected' => array_map(fn ($c) => $c['check'] . ':' . $c['target'], $plan['checks']),
             'excluded' => $plan['excluded'],
             'policy'   => $plan['policy']]
        );
    }

    /** @return array<string,mixed> */
    private function runCheck(array $check, WorkflowContext $context, Workspace $workspace): array
    {
        $target = (string) $check['target'];
        $repo = $context->repoPath;

        return match ($check['check']) {
            'syntax' => $this->shell('syntax', $target, $check['why'],
                'php -l ' . escapeshellarg($repo . '/' . $target)),

            // Lint accepts a class that cannot load. Reflection does not.
            'class-load' => $this->reflect($target, $check['why'], $repo),

            'config-load' => $this->shell('config-load', $target, $check['why'],
                'cd ' . escapeshellarg($repo) . ' && php -r ' . escapeshellarg(
                    '$c = require ' . var_export($repo . '/' . $target, true) . ';'
                    . 'if (! is_array($c)) { fwrite(STDERR, "config did not return an array"); exit(1); }'
                    . 'echo count($c), " key(s)";'
                )),

            'route-table' => $this->shell('route-table', $target, $check['why'],
                'cd ' . escapeshellarg($repo) . ' && php artisan route:list --json'),

            'migration-contract' => $this->shell('migration-contract', $target, $check['why'],
                'cd ' . escapeshellarg($repo) . ' && php -l ' . escapeshellarg($repo . '/' . $target)),

            'engineer888-safety-suite' => $this->suite('engineer888-safety-suite', $target, $check['why'],
                $context, $workspace, ['tests/Feature/Engineer888/ExecutionSafetyTest.php',
                                       'tests/Feature/Engineer888/InstallIntegrityTest.php']),

            'tests' => $this->suite('tests', $target, $check['why'], $context, $workspace, [$target]),

            'full-suite' => $this->suite('full-suite', $target, $check['why'], $context, $workspace, []),

            default => ['check' => $check['check'], 'target' => $target, 'why' => $check['why'],
                        'passed' => false, 'detail' => 'no runner for this check type'],
        };
    }

    private function shell(string $name, string $target, string $why, string $command): array
    {
        $out = [];
        $code = 0;
        @exec($command . ' 2>&1', $out, $code);

        return ['check' => $name, 'target' => $target, 'why' => $why, 'passed' => $code === 0,
                'detail' => $code === 0 ? 'ok' : implode(' ', array_slice($out, 0, 6))];
    }

    private function reflect(string $target, string $why, string $repo): array
    {
        $class = $this->classFor($target);

        if ($class === null) {
            return ['check' => 'class-load', 'target' => $target, 'why' => $why, 'passed' => true,
                    'detail' => 'not a PSR-4 class file; syntax check stands alone'];
        }

        return $this->shell('class-load', $target, $why,
            'php -r ' . escapeshellarg(
                'require ' . var_export($repo . '/vendor/autoload.php', true) . ';'
                . 'new ReflectionClass(' . var_export($class, true) . ');'
                . 'echo "loads";'
            ));
    }

    /** app/Core/Foo/Bar.php => App\Core\Foo\Bar, or null when not PSR-4. */
    private function classFor(string $path): ?string
    {
        if (! str_starts_with($path, 'app/') || ! str_ends_with($path, '.php')) { return null; }

        $relative = substr($path, strlen('app/'), -strlen('.php'));

        return 'App\\' . str_replace('/', '\\', $relative);
    }

    /**
     * Run tests under a config that cannot resolve to production.
     *
     * The config is REWRITTEN INTO THE WORKSPACE rather than used in place, so
     * nothing here needs the repository to be writable — the assumption that
     * broke the safety suite under the queue user.
     *
     * @param array<int,string> $paths empty means the whole suite
     */
    private function suite(string $name, string $target, string $why, WorkflowContext $context, Workspace $workspace, array $paths): array
    {
        $config = (string) $context->project->phpunit_config;

        // The selector was built around the dependency graph; PLAN already
        // produced one for this run, so it is reused rather than rebuilt.
        $graph = $context->get('plan.graph') ?? (new DependencyGraph($context->repoPath))->build();
        $selector = new TestSelector($context->repoPath, $graph);

        $existing = array_values(array_filter($paths,
            fn ($p) => is_file($context->repoPath . '/' . $p)));

        if ($paths !== [] && $existing === []) {
            return ['check' => $name, 'target' => $target, 'why' => $why, 'passed' => false,
                    'detail' => 'the selected test file does not exist: ' . implode(', ', $paths)];
        }

        $command = 'cd ' . escapeshellarg($context->repoPath) . ' && '
            . $selector->sanitisedTestCommand($config, $existing) . ' 2>&1';

        $out = [];
        $code = 0;
        @exec($command, $out, $code);

        $summary = preg_grep('/Tests:/', $out) ?: [];

        return [
            'check'   => $name,
            'target'  => $target,
            'why'     => $why,
            'passed'  => $code === 0,
            'detail'  => $code === 0
                ? trim((string) reset($summary))
                : implode("\n", array_slice($out, -14)),
            'command' => $selector->sanitisedTestCommand($config, $existing),
        ];
    }
}
