<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Repository\ArchitectureMap;
use App\Core\Engineer888\Repository\CommitPlanner;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Repository\FileClassifier;
use App\Core\Engineer888\Repository\WorkingTree;
use App\Core\Engineer888\RepositoryIntelligence;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Repository Intelligence.
 *
 * Two kinds of test here, deliberately.
 *
 * FIXTURE TESTS build a throwaway git repository on disk and assert exact
 * results. They can prove things the live repository cannot, because they control
 * the input — a merge conflict, a debug call, an orphan class.
 *
 * LIVE TESTS run against this repository. They cannot assert exact numbers, which
 * change hourly, so they assert INVARIANTS: that no file is left unclassified,
 * that the commit plan covers every committable file exactly once, that the order
 * is stable. Those are the properties that make the report trustworthy, and they
 * are the ones a fixture cannot check.
 */
class RepositoryIntelligenceTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('levelup_staging', DB::connection()->getDatabaseName());
        $this->fixture = sys_get_temp_dir() . '/e888-fixture-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixture)) { $this->rmrf($this->fixture); }
        parent::tearDown();
    }

    // ── classification, on controlled input ─────────────────────────────

    public function test_a_merge_conflict_blocks_the_file(): void
    {
        $this->fixtureRepo(['app/Core/X/Broken.php' => "<?php\n<<<<<<< HEAD\n\$a = 1;\n>>>>>>> other\n"]);

        $result = $this->classifyFixture('app/Core/X/Broken.php');

        $this->assertSame(FileClassifier::MERGE, $result['classification']);
    }

    public function test_a_real_debug_call_is_found(): void
    {
        $this->fixtureRepo(['app/Core/X/Leaky.php' => "<?php\nnamespace App\\Core\\X;\nclass Leaky { public function go() { dd(\$this); } }\n"]);

        $result = $this->classifyFixture('app/Core/X/Leaky.php');

        $this->assertSame(FileClassifier::DEBUGGING, $result['classification']);
        $this->assertContains('debug-statements', $result['flags']);
    }

    /**
     * Regression. The first live run reported RiskScanner.php as containing dd()
     * because the words "dd() halts the request" appear in its own description,
     * and reported FileClassifier.php as unfinished because the word TODO appears
     * in the pattern that searches for TODO.
     */
    public function test_a_debug_name_inside_a_string_or_comment_is_not_a_debug_call(): void
    {
        $this->fixtureRepo(['app/Core/X/Describer.php' => <<<'PHP'
<?php
namespace App\Core\X;

/**
 * This class explains that dd() halts the request and dump() leaks state.
 */
class Describer
{
    public function detail(): string
    {
        return 'call dd() or var_dump() to inspect';   // dump() mentioned again
    }

    public function notDebug(): string
    {
        return $this->dump();
    }

    private function dump(): string { return 'a legitimate method named dump'; }
}
PHP,
        ]);

        $result = $this->classifyFixture('app/Core/X/Describer.php');

        $this->assertNotContains('debug-statements', $result['flags'],
            'dd() in a comment, a string, and as a method name are all not debug calls');
        $this->assertNotSame(FileClassifier::DEBUGGING, $result['classification']);
    }

    public function test_a_todo_in_a_string_is_not_an_unfinished_marker(): void
    {
        $this->fixtureRepo([
            'app/Core/X/Matcher.php' => "<?php\nnamespace App\\Core\\X;\nclass Matcher { const P = '/\\b(TODO|FIXME)\\b/'; }\n",
            'app/Core/X/Real.php'    => "<?php\nnamespace App\\Core\\X;\n// TODO: finish this\nclass Real {}\n",
        ]);

        $matcher = $this->classifyFixture('app/Core/X/Matcher.php');
        $real = $this->classifyFixture('app/Core/X/Real.php');

        $this->assertNotContains('unfinished-markers', $matcher['flags'],
            'a pattern that searches for TODO is not itself a TODO');
        $this->assertContains('unfinished-markers', $real['flags']);
        $this->assertSame(FileClassifier::PARTIAL, $real['classification']);
    }

    public function test_shell_redirect_debris_is_identified(): void
    {
        $this->fixtureRepo(['cron|-' => '', 'hourly' => '']);

        $this->assertSame(FileClassifier::TEMPORARY, $this->classifyFixture('cron|-')['classification']);
        $this->assertSame(FileClassifier::TEMPORARY, $this->classifyFixture('hourly')['classification']);
    }

    public function test_a_cookie_jar_is_flagged_as_carrying_authentication_material(): void
    {
        $this->fixtureRepo(['lu-ad' => "# Netscape HTTP Cookie File\nexample.com\tTRUE\t/\tTRUE\t0\tsession\tvalue\n"]);

        $result = $this->classifyFixture('lu-ad');

        $this->assertSame(FileClassifier::TEMPORARY, $result['classification']);
        $this->assertContains('may-contain-session-token', $result['flags']);
    }

    public function test_backups_and_candidates_are_never_committable(): void
    {
        $this->fixtureRepo([
            'bootstrap/app.candidate.php' => "<?php\nreturn 1;\n",
            'app/Core/X/Thing.php.bak-20260101' => "<?php\nreturn 1;\n",
        ]);

        $this->assertSame(FileClassifier::BACKUP, $this->classifyFixture('bootstrap/app.candidate.php')['classification']);
        $this->assertSame(FileClassifier::BACKUP, $this->classifyFixture('app/Core/X/Thing.php.bak-20260101')['classification']);
    }

    public function test_a_migration_is_an_architectural_change(): void
    {
        $this->fixtureRepo(['database/migrations/2026_01_01_000000_create_things_table.php' => "<?php\nreturn 1;\n"]);

        $result = $this->classifyFixture('database/migrations/2026_01_01_000000_create_things_table.php');

        $this->assertSame(FileClassifier::ARCHITECTURAL, $result['classification']);
        $this->assertContains('schema-change', $result['flags']);
    }

    // ── dependency graph ────────────────────────────────────────────────

    public function test_the_graph_resolves_imports_and_reverse_references(): void
    {
        $this->fixtureRepo([
            'app/Core/X/Alpha.php' => "<?php\nnamespace App\\Core\\X;\nclass Alpha {}\n",
            'app/Core/X/Beta.php'  => "<?php\nnamespace App\\Core\\X;\nuse App\\Core\\X\\Alpha;\nclass Beta { public function go(Alpha \$a) {} }\n",
        ]);

        $graph = (new DependencyGraph($this->fixture))->build();

        $this->assertSame(['app/Core/X/Alpha.php'], $graph->dependsOn('app/Core/X/Beta.php'));
        $this->assertSame(['app/Core/X/Beta.php'], $graph->usedBy('app/Core/X/Alpha.php'));
        $this->assertSame(['App\Core\X\Alpha'], $graph->declaredBy('app/Core/X/Alpha.php'));
    }

    public function test_a_class_named_only_inside_a_string_is_not_a_dependency(): void
    {
        $this->fixtureRepo([
            'app/Core/X/Alpha.php' => "<?php\nnamespace App\\Core\\X;\nclass Alpha {}\n",
            'app/Core/X/Gamma.php' => "<?php\nnamespace App\\Core\\X;\nclass Gamma { public function n(): string { return 'Alpha'; } }\n",
        ]);

        $graph = (new DependencyGraph($this->fixture))->build();

        $this->assertSame([], $graph->dependsOn('app/Core/X/Gamma.php'),
            'a class name inside a string literal is data, not an import');
    }

    public function test_a_circular_dependency_is_detected(): void
    {
        $this->fixtureRepo([
            'app/Core/X/Ping.php' => "<?php\nnamespace App\\Core\\X;\nclass Ping { public function go(): Pong { return new Pong(); } }\n",
            'app/Core/X/Pong.php' => "<?php\nnamespace App\\Core\\X;\nclass Pong { public function go(): Ping { return new Ping(); } }\n",
        ]);

        $cycles = (new DependencyGraph($this->fixture))->build()->cycles();

        $this->assertCount(1, $cycles);
        $this->assertSame(['app/Core/X/Ping.php', 'app/Core/X/Pong.php'], $cycles[0]);
    }

    public function test_an_unreferenced_class_is_reported_and_a_referenced_one_is_not(): void
    {
        $this->fixtureRepo([
            'app/Core/X/Used.php'   => "<?php\nnamespace App\\Core\\X;\nclass Used {}\n",
            'app/Core/X/Orphan.php' => "<?php\nnamespace App\\Core\\X;\nclass Orphan {}\n",
            'app/Core/X/Caller.php' => "<?php\nnamespace App\\Core\\X;\nclass Caller { public function go(Used \$u) {} }\n",
        ]);

        $graph = (new DependencyGraph($this->fixture))->build();

        $this->assertTrue($graph->isUnreferenced('app/Core/X/Orphan.php'));
        $this->assertFalse($graph->isUnreferenced('app/Core/X/Used.php'));
    }

    // ── architecture map ────────────────────────────────────────────────

    public function test_layers_are_read_from_position_in_the_tree(): void
    {
        $this->fixtureRepo(['app/Core/X/A.php' => "<?php\n"]);
        $map = new ArchitectureMap($this->fixture);

        $this->assertSame('controller', $map->layer('app/Http/Controllers/Api/ThingController.php'));
        $this->assertSame('middleware', $map->layer('app/Http/Middleware/Thing.php'));
        $this->assertSame('command', $map->layer('app/Console/Commands/Thing.php'));
        $this->assertSame('migration', $map->layer('database/migrations/2026_01_01_x.php'));
        $this->assertSame('routing', $map->layer('routes/api/authenticated/chat.php'));
        $this->assertSame('documentation', $map->layer('SOME-REPORT.md'));
        // A .js file under public/ is code, not a static asset.
        $this->assertSame('frontend', $map->layer('public/app/js/builder.js'));
    }

    /**
     * Regression. routes/api/authenticated/agents-01.php was attributed to a
     * subsystem called "authenticated" — the middleware group it sits behind —
     * which grouped thirteen unrelated route files into one commit.
     */
    public function test_a_surface_directory_does_not_become_a_subsystem(): void
    {
        $this->fixtureRepo([
            'app/Core/Agent/Dispatcher.php' => "<?php\nnamespace App\\Core\\Agent;\nclass Dispatcher {}\n",
            'routes/api/authenticated/agents-01.php' => "<?php\n",
        ]);
        $map = new ArchitectureMap($this->fixture);

        $result = $map->subsystem('routes/api/authenticated/agents-01.php');

        $this->assertNotSame('authenticated', $result['subsystem']);
        $this->assertSame('Agent', $result['subsystem']);
    }

    public function test_subsystem_names_are_discovered_from_the_tree_not_hardcoded(): void
    {
        $this->fixtureRepo(['app/Engines/Quokka/Services/Thing.php' => "<?php\n"]);
        $map = new ArchitectureMap($this->fixture);

        $this->assertContains('Quokka', $map->vocabulary(),
            'a subsystem added to the tree must be understood without editing the map');
        $this->assertSame('Quokka', $map->subsystem('app/Engines/Quokka/Services/Thing.php')['subsystem']);
    }

    // ── working tree ────────────────────────────────────────────────────

    /**
     * Regression. `git status --porcelain` collapses an untracked DIRECTORY into
     * one entry, so nine new files read as one. On the live repository this
     * understated the changed set by 2.2x.
     */
    public function test_untracked_directories_are_expanded_to_files(): void
    {
        $this->fixtureRepo([
            'app/Core/New/A.php' => "<?php\n",
            'app/Core/New/B.php' => "<?php\n",
            'app/Core/New/C.php' => "<?php\n",
        ], commit: false);

        $tree = WorkingTree::read($this->fixture);

        $this->assertTrue($tree['available']);
        $this->assertSame(3, $tree['expanded_files'], 'three files, not one directory entry');
        $this->assertLessThan($tree['expanded_files'], $tree['collapsed_entries']);
        $this->assertSame(['app/Core/New/A.php', 'app/Core/New/B.php', 'app/Core/New/C.php'],
            array_column($tree['files'], 'path'));
    }

    public function test_a_path_with_shell_metacharacters_survives_parsing(): void
    {
        $this->fixtureRepo(['cron|-' => ''], commit: false);

        $tree = WorkingTree::read($this->fixture);
        $paths = array_column($tree['files'], 'path');

        $this->assertContains('cron|-', $paths, 'the odd filenames are the ones worth finding');
    }

    // ── commit planner ──────────────────────────────────────────────────

    public function test_schema_is_ordered_before_the_code_that_reads_it(): void
    {
        $this->fixtureRepo([
            'database/migrations/2026_01_01_000000_create_widgets_table.php' => "<?php\nreturn 1;\n",
            'app/Core/Widgets/WidgetService.php' => "<?php\nnamespace App\\Core\\Widgets;\nclass WidgetService {}\n",
            'tests/Feature/Widgets/WidgetTest.php' => "<?php\nnamespace Tests\\Feature\\Widgets;\nclass WidgetTest {}\n",
        ], commit: false);

        $plan = $this->planFixture();
        $positions = [];
        foreach ($plan['groups'] as $group) {
            foreach ($group['files'] as $path) { $positions[$path] = $group['position']; }
        }

        $this->assertLessThanOrEqual(
            $positions['app/Core/Widgets/WidgetService.php'],
            $positions['database/migrations/2026_01_01_000000_create_widgets_table.php'],
            'a migration must not be committed after the code that expects the table'
        );
    }

    public function test_debris_is_excluded_from_every_commit(): void
    {
        $this->fixtureRepo([
            'app/Core/Widgets/WidgetService.php' => "<?php\nnamespace App\\Core\\Widgets;\nclass WidgetService {}\n",
            'cron|-' => '',
            'bootstrap/app.candidate.php' => "<?php\nreturn 1;\n",
        ], commit: false);

        $plan = $this->planFixture();

        $committed = [];
        foreach ($plan['groups'] as $group) { $committed = array_merge($committed, $group['files']); }

        $this->assertNotContains('cron|-', $committed);
        $this->assertNotContains('bootstrap/app.candidate.php', $committed);
        $this->assertContains('app/Core/Widgets/WidgetService.php', $committed);
        $this->assertCount(2, $plan['excluded']['do_not_commit']);
    }

    /**
     * Regression. The admin conversion added 25 files to one directory; each
     * name-matched a different subsystem, so the plan proposed twenty
     * single-file commits for one piece of work.
     */
    public function test_files_created_together_in_one_directory_form_one_commit(): void
    {
        $this->fixtureRepo([
            'app/Core/Chat/Marker.php'   => "<?php\nnamespace App\\Core\\Chat;\nclass Marker {}\n",
            'app/Core/Studio/Marker.php' => "<?php\nnamespace App\\Core\\Studio;\nclass Marker {}\n",
            'resources/views/admin/pages/chat.blade.php'   => "x\n",
            'resources/views/admin/pages/studio.blade.php' => "x\n",
            'resources/views/admin/pages/memory.blade.php' => "x\n",
        ], commit: false);

        $plan = $this->planFixture();

        $unitGroups = array_values(array_filter($plan['groups'], fn ($g) => $g['kind'] === 'unit'));
        $this->assertCount(1, $unitGroups, 'the three new views are one change to one directory');
        $this->assertSame(3, $unitGroups[0]['file_count']);
        $this->assertStringContainsString('created', $unitGroups[0]['rationale']);
    }

    public function test_a_directory_mixing_old_and_new_files_is_not_merged(): void
    {
        // Committed first, so these are tracked and pre-existing.
        $this->fixtureRepo([
            'public/app/js/builder.js' => "var a = 1;\n",
            'public/app/js/studio.js'  => "var b = 1;\n",
        ], commit: true);

        // Then modify one and add another.
        file_put_contents($this->fixture . '/public/app/js/builder.js', "var a = 2;\n");
        file_put_contents($this->fixture . '/public/app/js/domains.js', "var c = 1;\n");

        $plan = $this->planFixture();

        $unitGroups = array_values(array_filter($plan['groups'], fn ($g) => $g['kind'] === 'unit'));
        $this->assertSame([], $unitGroups,
            'a flat directory of long-standing files is not one unit of work');
    }

    public function test_a_blocker_names_the_file_responsible(): void
    {
        $this->fixtureRepo([
            'app/Core/Widgets/Clean.php' => "<?php\nnamespace App\\Core\\Widgets;\nclass Clean {}\n",
            'app/Core/Widgets/Leaky.php' => "<?php\nnamespace App\\Core\\Widgets;\nclass Leaky { public function go() { dd(1); } }\n",
        ], commit: false);

        $plan = $this->planFixture();

        $blockers = [];
        foreach ($plan['groups'] as $group) { $blockers = array_merge($blockers, $group['blocking']); }

        $this->assertNotEmpty($blockers);
        $debug = array_values(array_filter($blockers, fn ($b) => str_contains($b['reason'], 'Debug output')))[0] ?? null;
        $this->assertNotNull($debug);
        $this->assertSame(['app/Core/Widgets/Leaky.php'], $debug['files'],
            'a blocker the engineer cannot locate is not actionable');
    }

    // ── invariants, against the live repository ─────────────────────────

    public function test_every_changed_file_receives_a_classification(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();

        $this->assertTrue($report['available']);
        $this->assertNotEmpty($report['files']);

        foreach ($report['files'] as $file) {
            $this->assertArrayHasKey('classification', $file['analysis'], $file['path']);
            $this->assertNotSame('', $file['analysis']['classification'], $file['path']);
            $this->assertNotSame('', $file['analysis']['reason'], $file['path']);
            $this->assertContains($file['analysis']['confidence'], ['high', 'medium', 'low'], $file['path']);
        }
    }

    public function test_the_commit_plan_covers_every_committable_file_exactly_once(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();
        $plan = $report['commit_plan'];

        $planned = [];
        foreach ($plan['groups'] as $group) {
            foreach ($group['files'] as $path) { $planned[] = $path; }
        }
        $excluded = array_merge(
            array_column($plan['excluded']['do_not_commit'], 'path'),
            array_column($plan['excluded']['blocked'], 'path'),
        );

        $this->assertSame(count($planned), count(array_unique($planned)),
            'no file may appear in two commits');

        $all = array_column($report['files'], 'path');
        $accounted = array_merge($planned, $excluded);

        $this->assertSame([], array_values(array_diff($all, $accounted)),
            'every changed file is either in a commit or explicitly excluded');
    }

    public function test_the_plan_is_identical_on_two_consecutive_runs(): void
    {
        $first = (new RepositoryIntelligence(base_path()))->analyse();
        $second = (new RepositoryIntelligence(base_path()))->analyse();

        $shape = fn (array $r) => array_map(
            fn ($g) => [$g['position'], $g['key'], $g['files']],
            $r['commit_plan']['groups']
        );

        $this->assertSame($shape($first), $shape($second),
            'an unstable plan cannot be reviewed or diffed');
    }

    public function test_every_risk_states_what_it_prevents(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();

        foreach ($report['risks'] as $risk) {
            $this->assertNotSame('', $risk['prevents'] ?? '', $risk['id']);
            $this->assertContains($risk['severity'], ['critical', 'warn', 'info'], $risk['id']);
            $this->assertNotEmpty($risk['evidence'], $risk['id']);
        }
    }

    public function test_priority_items_are_actions_not_observations(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();

        $this->assertNotEmpty($report['priority']);
        foreach ($report['priority'] as $item) {
            $this->assertMatchesRegularExpression(
                '/^(Resolve|Commit|Unblock|Delete|Decide|Address)\b/', $item['action'],
                'every priority entry must begin with a verb: ' . $item['action']
            );
            $this->assertNotSame('', $item['why']);
        }
    }

    public function test_the_command_runs_and_reports_a_severity_based_exit_code(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();
        $expected = 0;
        foreach ($report['risks'] as $risk) {
            if ($risk['severity'] === 'critical') { $expected = 2; break; }
            if ($risk['severity'] === 'warn') { $expected = 1; }
        }

        $this->artisan('engineering:repo', ['--section' => ['priority']])->assertExitCode($expected);
    }

    public function test_narrowing_to_one_subsystem_returns_only_that_subsystem(): void
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse('Engineer888');

        $this->assertNotEmpty($report['files']);
        foreach ($report['files'] as $file) {
            $this->assertSame('Engineer888', $file['subsystem']['subsystem'], $file['path']);
        }
    }

    // ── fixture helpers ─────────────────────────────────────────────────

    /** @param  array<string,string>  $files */
    private function fixtureRepo(array $files, bool $commit = false): void
    {
        mkdir($this->fixture, 0775, true);
        foreach ($files as $path => $content) {
            $full = $this->fixture . '/' . $path;
            if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
            file_put_contents($full, $content);
        }

        $quoted = escapeshellarg($this->fixture);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");
        if ($commit) {
            exec("cd {$quoted} && git add -A && git commit -q -m base 2>&1");
        }
    }

    private function classifyFixture(string $path): array
    {
        $map = new ArchitectureMap($this->fixture);
        $classifier = new FileClassifier($this->fixture, $map);
        $full = $this->fixture . '/' . $path;

        return $classifier->classify([
            'path'      => $path,
            'exists'    => is_file($full),
            'size'      => is_file($full) ? (int) filesize($full) : 0,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'untracked' => true,
            'deleted'   => false,
            'mtime'     => is_file($full) ? (int) filemtime($full) : null,
        ], (new DependencyGraph($this->fixture))->build());
    }

    private function planFixture(): array
    {
        $tree = WorkingTree::read($this->fixture);
        $map = new ArchitectureMap($this->fixture);
        $graph = (new DependencyGraph($this->fixture))->build();
        $classifier = new FileClassifier($this->fixture, $map);

        $analysed = [];
        foreach ($tree['files'] as $file) {
            $file['layer'] = $map->layer($file['path']);
            $file['subsystem'] = $map->subsystem($file['path']);
            $file['analysis'] = $classifier->classify($file, $graph);
            $analysed[] = $file;
        }

        return (new CommitPlanner($map))->plan($analysed, $graph);
    }

    private function rmrf(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
