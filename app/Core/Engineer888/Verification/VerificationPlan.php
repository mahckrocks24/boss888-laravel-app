<?php

namespace App\Core\Engineer888\Verification;

use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Execution\TestSelector;
use App\Core\Engineer888\Repository\DependencyGraph;

/**
 * Which checks would actually catch a mistake in exactly these files.
 *
 * Running the whole suite is not thoroughness, it is an absence of thought: 210
 * of the 215 seconds of the Sprint 8 run were spent proving that files nobody
 * touched still worked. But the opposite failure is worse and easier to reach,
 * so this class refuses to produce a small plan it cannot justify.
 *
 * Every check carries the reason it was selected. Every exclusion carries the
 * reason it was dropped. And the plan carries a verdict on its own adequacy —
 * INSUFFICIENT_EVIDENCE is a valid outcome, and it stops the workflow rather
 * than letting a fast pass stand in for a real one.
 */
final class VerificationPlan
{
    public const ADEQUATE = 'ADEQUATE';
    public const FULL_SUITE_REQUIRED = 'FULL_SUITE_REQUIRED';
    public const INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    public const BLOCKED = 'BLOCKED';

    /**
     * Above this many dependents, a change is no longer local and the argument
     * for a narrow plan disappears.
     */
    public const IMPACT_THRESHOLD = 25;

    /** Touching any of these changes how everything else runs. */
    public const BOOT_PATHS = [
        'bootstrap/', 'composer.json', 'composer.lock', 'artisan',
        'app/Providers/', 'config/app.php',
    ];

    /** Engineer888's own safety surface. Changing it means proving all of it. */
    public const SAFETY_PATHS = [
        'app/Core/Engineer888/Audit/', 'app/Core/Engineer888/Install/',
        'app/Core/Engineer888/Coordination/', 'app/Core/Engineer888/Recovery/',
        'app/Core/Engineer888/Runtime/', 'app/Core/Engineer888/Approval/',
        'tools/exec-safety-audit.php', 'tools/install-integrity-audit.php',
    ];

    public function __construct(
        private readonly string $repoPath,
        private readonly ?DependencyGraph $graph = null,
    ) {}

    /**
     * @param array<int,string> $changedPaths
     * @return array<string,mixed>
     */
    public function build(array $changedPaths, ?string $phpunitConfig): array
    {
        $checks = [];
        $excluded = [];
        $policy = [];
        $fullSuite = false;
        $impact = 0;

        if ($changedPaths === []) {
            return $this->plan([], [], self::BLOCKED, ['nothing was changed, so there is nothing to verify'], 0, false);
        }

        if ($phpunitConfig === null) {
            return $this->plan([], [], self::BLOCKED,
                ['the project declares no isolated phpunit config, and running a suite that could resolve '
                 . 'to production is the defect INC-2026-006 exists to prevent'], 0, false);
        }

        $graph = $this->graph ?? (new DependencyGraph($this->repoPath))->build();

        foreach ($changedPaths as $path) {
            $dependents = count($graph->usedBy($path));
            $impact += $dependents;

            // 1. Syntax, always, for anything PHP. A file that does not parse
            //    breaks autoloading for everything else.
            if (str_ends_with($path, '.php')) {
                $checks[] = ['check' => 'syntax', 'target' => $path,
                             'why' => 'a file that does not parse breaks autoloading platform-wide'];

                // 2. Loading, not merely parsing. `php -l` accepts a private
                //    method that collides with a public parent; the class then
                //    fatals at load and takes every artisan call with it. Twice
                //    in two days, by two engineers.
                $checks[] = ['check' => 'class-load', 'target' => $path,
                             'why' => 'lint proves a file parses, not that its class loads'];
            }

            if (str_starts_with($path, 'database/migrations/')) {
                $checks[] = ['check' => 'migration-contract', 'target' => $path,
                             'why' => 'a migration is only safe if it can be applied and reversed'];
                $policy[] = 'migration: schema change requires the full suite, because any test may read the table';
                $fullSuite = true;
            }

            if (str_starts_with($path, 'config/')) {
                $checks[] = ['check' => 'config-load', 'target' => $path,
                             'why' => 'a config that does not load is discovered at the next request, not here'];
            }

            if (str_starts_with($path, 'routes/')) {
                $checks[] = ['check' => 'route-table', 'target' => $path,
                             'why' => 'a route file must still produce a resolvable route table'];
                $policy[] = 'routes: the route table is global, so related feature tests are widened to the full suite';
                $fullSuite = true;
            }

            if (GovernedFiles::isGoverned($path)) {
                $policy[] = "governed file {$path}: other engineers depend on it, so verification is widened";
                $fullSuite = true;
            }

            foreach (self::BOOT_PATHS as $boot) {
                if (str_starts_with($path, $boot)) {
                    $policy[] = "{$path} affects application boot";
                    $fullSuite = true;
                }
            }

            foreach (self::SAFETY_PATHS as $safety) {
                if (str_starts_with($path, $safety)) {
                    $policy[] = "{$path} is part of Engineer888's own safety surface";
                    $checks[] = ['check' => 'engineer888-safety-suite', 'target' => $path,
                                 'why' => 'a change to a safety control must be proved by the safety suite'];
                }
            }

            if (str_starts_with($path, 'tests/') || str_starts_with($path, 'phpunit')) {
                $policy[] = "{$path} is test infrastructure; a narrowed plan would be graded by the thing that changed";
                $fullSuite = true;
            }
        }

        if ($impact > self::IMPACT_THRESHOLD) {
            $policy[] = "dependency impact {$impact} exceeds the threshold of " . self::IMPACT_THRESHOLD;
            $fullSuite = true;
        }

        // 3. The tests that actually reference these files.
        $selector = new TestSelector($this->repoPath, $graph);
        $covering = [];
        try {
            // The selector speaks in commit groups; this is the same shape
            // the commit engine passes it, so one implementation serves both.
            $covering = $selector->coveringTests(['files' => $changedPaths]);
        } catch (\Throwable $e) {
            $policy[] = 'test selection failed (' . $e->getMessage() . '), so the full suite is used';
            $fullSuite = true;
        }

        if ($covering === [] && ! $fullSuite) {
            // No named coverage and no policy reason to widen. This is the case
            // that must NOT quietly pass: a change with no test that references
            // it has not been verified by running fewer tests faster.
            return $this->plan($checks, $excluded, self::INSUFFICIENT_EVIDENCE, array_merge($policy, [
                'no test file references any changed path, so a change-scoped run would prove nothing '
                . 'about this change. Either add a test, or declare the full suite explicitly.',
            ]), $impact, false);
        }

        foreach ($covering as $test) {
            $checks[] = ['check' => 'tests', 'target' => $test,
                         'why' => 'this test references a changed file'];
        }

        if ($fullSuite) {
            foreach ($covering as $test) {
                $excluded[] = ['target' => $test, 'why' => 'superseded by the full suite'];
            }
            $checks = array_values(array_filter($checks, fn ($c) => $c['check'] !== 'tests'));
            $checks[] = ['check' => 'full-suite', 'target' => $phpunitConfig,
                         'why' => implode('; ', array_unique($policy))];
        } else {
            $excluded[] = ['target' => 'the rest of the suite',
                           'why' => 'no changed file is referenced by it, and dependency impact is '
                                  . $impact . ', under the threshold of ' . self::IMPACT_THRESHOLD];
        }

        return $this->plan($checks, $excluded,
            $fullSuite ? self::FULL_SUITE_REQUIRED : self::ADEQUATE,
            $policy, $impact, $fullSuite);
    }

    private function plan(array $checks, array $excluded, string $verdict, array $policy, int $impact, bool $full): array
    {
        return [
            'checks'     => $checks,
            'excluded'   => $excluded,
            'adequacy'   => $verdict,
            'policy'     => array_values(array_unique($policy)),
            'impact'     => $impact,
            'full_suite' => $full,
        ];
    }

    /** Verdicts that permit the workflow to continue. */
    public static function permitsExecution(string $verdict): bool
    {
        return in_array($verdict, [self::ADEQUATE, self::FULL_SUITE_REQUIRED], true);
    }
}
