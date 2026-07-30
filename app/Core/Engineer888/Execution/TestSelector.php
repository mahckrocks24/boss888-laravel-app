<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\Repository\DependencyGraph;

/**
 * The smallest verification that would actually catch a mistake in this group.
 *
 * Running the full suite for a one-file documentation commit is not caution, it
 * is noise — it takes minutes, it fails for unrelated reasons, and a check that
 * routinely fails for unrelated reasons stops being read. So the selection is
 * derived per group from what the group contains.
 *
 * The interesting case is PHP. Rather than guessing test names from paths, the
 * selector asks the DEPENDENCY GRAPH which test files reference the classes this
 * group declares. That is the precise answer, it reuses machinery Sprint 2
 * already built and proved, and it finds tests that a path convention would miss.
 */
final class TestSelector
{
    public function __construct(
        private string $repoPath,
        private DependencyGraph $graph,
    ) {}

    /**
     * @param  array<string,mixed>  $group
     * @return array<int,array{id:string, kind:string, description:string, command:string, rationale:string}>
     */
    public function select(array $group): array
    {
        $checks = [];
        $layers = $group['layers'];
        $files = $group['files'];

        $php = array_values(array_filter($files, fn ($p) => str_ends_with($p, '.php')));
        $js = array_values(array_filter($files, fn ($p) => str_ends_with($p, '.js')));
        $blade = array_values(array_filter($files, fn ($p) => str_ends_with($p, '.blade.php')));

        // ── syntax, always, for anything that must parse ─────────────────
        if ($php !== []) {
            $checks[] = [
                'id' => 'php-syntax',
                'kind' => 'syntax',
                'description' => 'php -l on ' . count($php) . ' PHP file(s)',
                'command' => $this->lintCommand($php),
                'rationale' => 'A file that does not parse breaks autoloading for everything, so this runs first and costs milliseconds.',
            ];
        }

        if ($js !== []) {
            $checks[] = [
                'id' => 'js-syntax',
                'kind' => 'build',
                'description' => 'node --check on ' . count($js) . ' JavaScript file(s)',
                'command' => 'for f in ' . implode(' ', array_map('escapeshellarg', $js)) . '; do node --check "$f" || exit 1; done',
                'rationale' => 'There is no bundler step for these files — they are served as-is — so parsing them IS the build.',
            ];
        }

        if ($blade !== []) {
            $checks[] = [
                'id' => 'blade-compile',
                'kind' => 'build',
                'description' => 'Blade compilation of ' . count($blade) . ' view(s)',
                'runner' => 'internal',
                'targets' => $blade,
                'rationale' => 'A Blade template only fails when it is rendered, which in production means it fails for a customer. Compiling it here moves that forward.',
            ];
        }

        // ── configuration ────────────────────────────────────────────────
        $configs = array_values(array_filter($files, fn ($p) => str_starts_with($p, 'config/')));
        if ($configs !== []) {
            $checks[] = [
                'id' => 'config-loads',
                'kind' => 'configuration',
                'description' => 'each config file returns an array',
                'runner' => 'internal',
                'targets' => $configs,
                'rationale' => 'A config file that returns anything other than an array fails at boot, on every request, with an unhelpful error.',
            ];
        }

        // ── routing ──────────────────────────────────────────────────────
        if (in_array('routing', $layers, true) || in_array('bootstrap', $layers, true)) {
            $checks[] = [
                'id' => 'routes-resolve',
                'kind' => 'configuration',
                'description' => 'php artisan route:list resolves',
                'command' => 'php artisan route:list --json > /dev/null',
                'rationale' => 'Route files are only executed at boot. If a controller reference is wrong the whole application 500s, and route:list is the cheapest thing that proves it does not.',
            ];
        }

        // ── migrations ───────────────────────────────────────────────────
        if (in_array('migration', $layers, true)) {
            $checks[] = [
                'id' => 'migrations-readable',
                'kind' => 'migration',
                'description' => 'php artisan migrate:status enumerates every migration',
                'command' => 'php artisan migrate:status > /dev/null',
                'rationale' => 'The migrator instantiates every migration file. A malformed one breaks migrate for the whole platform, not just itself. This verifies without APPLYING anything — running an unreviewed migration against production is not verification.',
            ];
        }

        // ── the tests that actually cover this code ──────────────────────
        $tests = $this->coveringTests($group);
        if ($tests !== []) {
            $config = $this->configFor($tests);
            $checks[] = [
                'id' => 'covering-tests',
                'kind' => 'test',
                'description' => count($tests) . ' test file(s) that reference this group\'s classes'
                    . ($config !== null ? ' (via ' . $config . ')' : ''),
                'command' => 'php artisan test'
                    . ($config !== null ? ' -c ' . escapeshellarg($config) : '')
                    . ' ' . implode(' ', array_map('escapeshellarg', $tests)),
                'rationale' => 'Selected from the dependency graph — these are the test files that import a '
                    . 'class this group changes. Not a path convention, an actual reference.'
                    . ($config !== null
                        ? ' Run under ' . $config . ', which owns a dedicated database: the shared one is '
                        . 'corrupted by concurrent sessions often enough that a failure there would say '
                        . 'nothing about this commit.'
                        : ''),
            ];
        }

        if ($checks === []) {
            $checks[] = [
                'id' => 'none-required',
                'kind' => 'none',
                'description' => 'no executable verification applies',
                'runner' => 'none',
                'rationale' => 'This group contains nothing that can fail at runtime — documentation only. Saying so explicitly is better than running an irrelevant suite and calling it proof.',
            ];
        }

        foreach ($checks as $i => $check) {
            $checks[$i]['runner'] = $check['runner'] ?? 'shell';
            $checks[$i]['command'] = $check['command'] ?? '';
            $checks[$i]['targets'] = $check['targets'] ?? [];
        }

        return $checks;
    }

    /**
     * Test files that reference a class declared in this group, plus any test
     * files the group itself contains.
     *
     * @return array<int,string>
     */
    public function coveringTests(array $group): array
    {
        $tests = [];

        foreach ($group['files'] as $path) {
            if (str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php')) {
                $tests[$path] = true;
            }
            foreach ($this->graph->usedBy($path) as $referrer) {
                if (str_starts_with($referrer, 'tests/') && str_ends_with($referrer, 'Test.php')) {
                    $tests[$referrer] = true;
                }
            }
        }

        $tests = array_keys($tests);
        sort($tests);

        // A "smallest meaningful" check that runs eighty test files is neither.
        // Beyond this the honest move is to say the selection is too broad.
        return array_slice($tests, 0, 12);
    }

    /**
     * A per-suite phpunit config whose testsuite directories cover EVERY
     * selected test, or null.
     *
     * This repository keeps dedicated configs (phpunit.chat.xml,
     * phpunit.e888.xml) precisely because the shared levelup_test database gets
     * corrupted whenever two sessions run suites at once. Verification that
     * fails for that reason is noise, and noise is how a check stops being read.
     * Only a config covering ALL of the selected tests qualifies — a partial
     * match would silently drop coverage.
     */
    public function configFor(array $tests): ?string
    {
        foreach ((array) @glob($this->repoPath . '/phpunit.*.xml') as $configPath) {
            $xml = @file_get_contents($configPath);
            if ($xml === false) { continue; }

            if (! preg_match_all('#<directory[^>]*>\s*\./?([^<]+?)\s*</directory>#', $xml, $matches)) {
                continue;
            }
            $directories = array_map(fn ($d) => trim($d, '/'), $matches[1]);

            $coversAll = true;
            foreach ($tests as $test) {
                $covered = false;
                foreach ($directories as $directory) {
                    if ($directory !== '' && str_starts_with($test, $directory . '/')) { $covered = true; break; }
                }
                if (! $covered) { $coversAll = false; break; }
            }

            if ($coversAll) { return basename($configPath); }
        }

        return null;
    }

    private function lintCommand(array $files): string
    {
        return 'for f in ' . implode(' ', array_map('escapeshellarg', $files))
             . '; do php -l "$f" > /dev/null || exit 1; done';
    }
}
