<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Execution\CommitExecutor;
use App\Core\Engineer888\Execution\CommitMessageBuilder;
use App\Core\Engineer888\Execution\GitGate;
use App\Core\Engineer888\Execution\PlanStore;
use App\Core\Engineer888\Execution\PreflightCheck;
use App\Core\Engineer888\Execution\TestSelector;
use App\Core\Engineer888\Repository\ArchitectureMap;
use App\Core\Engineer888\Repository\CommitPlanner;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Repository\FileClassifier;
use App\Core\Engineer888\Repository\WorkingTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Commit execution.
 *
 * Every test that involves committing runs against a THROWAWAY repository built
 * in a temp directory. Nothing here touches the real working tree — a test suite
 * that commits to the repository it is testing is not a test suite.
 *
 * The most important tests in this file are the negative ones. An execution
 * engine is judged by what it refuses, so the refusals get more coverage than
 * the happy path: forbidden git verbs, a dirty index, a file that changed after
 * planning, artifacts, conflict markers, and an index that does not match what
 * was approved.
 */
class CommitExecutionTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('levelup_staging', DB::connection()->getDatabaseName());
        $this->repo = sys_get_temp_dir() . '/e888-exec-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the git gate ────────────────────────────────────────────────────

    public function test_history_rewriting_and_publishing_verbs_are_refused(): void
    {
        $gate = new GitGate($this->repo);

        foreach (['push', 'reset', 'rebase', 'merge', 'cherry-pick', 'checkout', 'branch', 'clean', 'stash', 'filter-branch'] as $verb) {
            $this->assertNotNull($gate->refuse($verb, []), "git {$verb} must be refused");
        }
    }

    public function test_dangerous_flags_are_refused_on_allowed_subcommands(): void
    {
        $gate = new GitGate($this->repo);

        $this->assertNotNull($gate->refuse('commit', ['--amend']));
        $this->assertNotNull($gate->refuse('commit', ['--no-verify']));
        $this->assertNotNull($gate->refuse('add', ['--force', 'x.php']));
    }

    public function test_git_add_must_name_its_files(): void
    {
        $gate = new GitGate($this->repo);

        $this->assertNotNull($gate->refuse('add', ['-A']), 'git add -A is how another session\'s work gets committed');
        $this->assertNotNull($gate->refuse('add', ['.']));
        $this->assertNotNull($gate->refuse('add', ['-u']));
        $this->assertNull($gate->refuse('add', ['--', 'app/Thing.php']));
    }

    public function test_restore_may_unstage_but_never_discard_the_working_tree(): void
    {
        $gate = new GitGate($this->repo);

        $this->assertNull($gate->refuse('restore', ['--staged', '--', 'a.php']));
        $this->assertNotNull($gate->refuse('restore', ['--', 'a.php']), 'restoring the worktree destroys edits');
        $this->assertNotNull($gate->refuse('restore', ['--staged', '--worktree', '--', 'a.php']));
    }

    public function test_git_config_may_be_read_but_not_written(): void
    {
        $gate = new GitGate($this->repo);

        $this->assertNull($gate->refuse('config', ['user.name']));
        $this->assertNotNull($gate->refuse('config', ['user.name', 'someone else']));
        $this->assertNotNull($gate->refuse('config', ['--unset', 'user.name']));
    }

    public function test_a_refused_command_never_reaches_the_shell(): void
    {
        $this->makeRepo(['a.php' => "<?php\n"], commit: true);
        $before = $this->headSha();

        $result = (new GitGate($this->repo))->run('reset', ['--hard', 'HEAD~1']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('refused', $result);
        $this->assertSame($before, $this->headSha(), 'the repository must be untouched');
    }

    // ── preflight refusals ──────────────────────────────────────────────

    public function test_execution_refuses_when_something_is_already_staged(): void
    {
        $this->makeRepo(['app/Core/W/A.php' => "<?php\nnamespace App\\Core\\W;\nclass A {}\n"], commit: true);
        $this->write('app/Core/W/B.php', "<?php\nnamespace App\\Core\\W;\nclass B {}\n");
        $this->write('other.php', "<?php\n");
        $this->git('add -- other.php');

        $result = $this->executeFirstGroup();

        $this->assertSame('preflight_failed', $result['status']);
        $this->assertStringContainsString('already staged', (string) $result['outcome']);
    }

    public function test_execution_refuses_when_a_planned_file_changed_after_planning(): void
    {
        $this->makeRepo(['app/Core/W/A.php' => "<?php\nnamespace App\\Core\\W;\nclass A {}\n"], commit: true);
        $this->write('app/Core/W/B.php', "<?php\nnamespace App\\Core\\W;\nclass B {}\n");

        $context = $this->plan();

        // A concurrent session edits the file between planning and execution.
        $this->write('app/Core/W/B.php', "<?php\nnamespace App\\Core\\W;\nclass B { /* half-written */ }\n");

        $result = $this->runGroup($context, 0);

        $this->assertSame('preflight_failed', $result['status']);
        $this->assertStringContainsString('changed since planning', (string) $result['outcome']);
        $this->assertSame(0, $this->commitCount() - 1, 'nothing may have been committed');
    }

    public function test_execution_refuses_a_group_containing_a_conflict_marker(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/Bad.php', "<?php\n<<<<<<< HEAD\n\$a=1;\n>>>>>>> b\n");

        $context = $this->plan();
        $result = $this->runGroupContaining($context, 'app/Core/W/Bad.php');

        // A conflicted file is excluded from every group by the planner, so the
        // group either does not exist or refuses. Both are correct; what must
        // never happen is committing it.
        $this->assertNotSame('completed', $result['status'] ?? 'absent');
    }

    public function test_a_group_never_contains_debris_because_the_planner_excludes_it(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('lu-ad', "# Netscape HTTP Cookie File\nsession\tvalue\n");
        $this->write('app/Core/W/Good.php', "<?php\nnamespace App\\Core\\W;\nclass Good {}\n");

        $context = $this->plan();

        $planned = [];
        foreach ($context['plan']['commit_plan']['groups'] as $group) {
            $planned = array_merge($planned, $group['files']);
        }

        $this->assertNotContains('lu-ad', $planned);
        $this->assertContains('app/Core/W/Good.php', $planned);
    }

    // ── the happy path, on a throwaway repository ───────────────────────

    public function test_an_approved_group_is_staged_committed_and_verified(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/Service.php', "<?php\nnamespace App\\Core\\W;\nclass Service {}\n");

        $before = $this->commitCount();
        $context = $this->plan();
        $result = $this->runGroupContaining($context, 'app/Core/W/Service.php', approve: true);

        $this->assertSame('completed', $result['status'], (string) ($result['outcome'] ?? ''));
        $this->assertSame($before + 1, $this->commitCount());
        $this->assertNotEmpty($result['commit_sha']);

        // The commit must contain exactly the planned files and nothing else.
        $inCommit = array_values(array_filter(explode("\n",
            $this->git('show --name-only --format= HEAD'))));
        sort($inCommit);
        $this->assertSame(['app/Core/W/Service.php'], $inCommit);

        // And the index must be clean afterwards.
        $this->assertSame('', trim($this->git('diff --cached --name-only')));
    }

    public function test_the_execution_is_logged_whether_or_not_it_committed(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/Service.php', "<?php\nnamespace App\\Core\\W;\nclass Service {}\n");

        $context = $this->plan();
        $this->runGroupContaining($context, 'app/Core/W/Service.php', approve: true);

        $row = DB::table('engineering_executions')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->commit_sha);
        $this->assertNotNull($row->commit_message);
        $this->assertGreaterThan(0, $row->duration_ms);
        $this->assertContains('app/Core/W/Service.php', json_decode($row->files, true));
        $this->assertNotEmpty(json_decode($row->checks, true));
    }

    public function test_declining_approval_commits_nothing_and_is_still_logged(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/Service.php', "<?php\nnamespace App\\Core\\W;\nclass Service {}\n");

        $before = $this->commitCount();
        $context = $this->plan();
        $result = $this->runGroupContaining($context, 'app/Core/W/Service.php', approve: false);

        $this->assertSame('aborted', $result['status']);
        $this->assertSame($before, $this->commitCount());
        $this->assertSame(1, DB::table('engineering_executions')->where('status', 'aborted')->count(),
            'a refusal is exactly the thing the log exists to explain');
    }

    public function test_a_dry_run_commits_nothing_and_writes_no_log_row(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/Service.php', "<?php\nnamespace App\\Core\\W;\nclass Service {}\n");

        $before = $this->commitCount();
        $context = $this->plan();
        $result = $this->runGroupContaining($context, 'app/Core/W/Service.php', dryRun: true);

        $this->assertSame($before, $this->commitCount());
        $this->assertSame(0, DB::table('engineering_executions')->count(),
            'a rehearsal is not an execution');
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('checks', $result);
    }

    // ── verification ────────────────────────────────────────────────────

    public function test_a_failing_check_stops_execution_and_does_not_repair(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $executor = new CommitExecutor($this->repo, new PlanStore($this->repo));

        $verification = $executor->verify([
            ['id' => 'passes', 'kind' => 'syntax', 'runner' => 'shell', 'command' => 'true', 'targets' => []],
            ['id' => 'fails', 'kind' => 'test', 'runner' => 'shell', 'command' => 'echo "boom" && exit 1', 'targets' => []],
            ['id' => 'never-runs', 'kind' => 'test', 'runner' => 'shell', 'command' => 'true', 'targets' => []],
        ]);

        $this->assertSame('failed', $verification['result']);
        $this->assertSame('fails', $verification['failed_check']);
        $this->assertCount(2, $verification['checks'], 'checks after a failure must not run');
        $this->assertStringContainsString('boom', $verification['output']);
    }

    public function test_a_corrupted_shared_test_database_is_inconclusive_not_a_failure(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $executor = new CommitExecutor($this->repo, new PlanStore($this->repo));

        $verification = $executor->verify([[
            'id' => 'covering-tests', 'kind' => 'test', 'runner' => 'shell', 'targets' => [],
            'command' => 'echo "SQLSTATE[42S01]: Base table or view already exists: 1050 Table users" && exit 1',
        ]]);

        $this->assertSame('inconclusive', $verification['result'],
            'another session corrupting the shared test database is not evidence this commit broke anything');
        $this->assertStringContainsString('concurrent', $verification['reason']);
    }

    public function test_verification_selection_is_derived_from_group_contents(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $graph = (new DependencyGraph($this->repo))->build();
        $selector = new TestSelector($this->repo, $graph);

        $docs = $selector->select($this->fakeGroup(['README.md'], ['documentation']));
        $this->assertSame(['none-required'], array_column($docs, 'id'),
            'a documentation commit does not need the test suite');

        $migration = $selector->select($this->fakeGroup(
            ['database/migrations/2026_01_01_x.php'], ['migration']));
        $this->assertContains('migrations-readable', array_column($migration, 'id'));

        $js = $selector->select($this->fakeGroup(['public/app/js/x.js'], ['frontend']));
        $this->assertContains('js-syntax', array_column($js, 'id'));

        $routes = $selector->select($this->fakeGroup(['routes/api/x.php'], ['routing']));
        $this->assertContains('routes-resolve', array_column($routes, 'id'));
    }

    public function test_covering_tests_come_from_the_dependency_graph(): void
    {
        $this->makeRepo([
            'app/Core/W/Service.php'            => "<?php\nnamespace App\\Core\\W;\nclass Service {}\n",
            'tests/Feature/W/ServiceTest.php'   => "<?php\nnamespace Tests\\Feature\\W;\nuse App\\Core\\W\\Service;\nclass ServiceTest { public function t(Service \$s) {} }\n",
            'tests/Feature/W/UnrelatedTest.php' => "<?php\nnamespace Tests\\Feature\\W;\nclass UnrelatedTest {}\n",
        ], commit: true);

        $graph = (new DependencyGraph($this->repo))->build();
        $selector = new TestSelector($this->repo, $graph);

        $tests = $selector->coveringTests($this->fakeGroup(['app/Core/W/Service.php'], ['core']));

        $this->assertSame(['tests/Feature/W/ServiceTest.php'], $tests,
            'only the test that actually imports the class, not everything in the directory');
    }

    // ── commit messages ─────────────────────────────────────────────────

    public function test_the_message_follows_this_repositorys_convention(): void
    {
        $builder = new CommitMessageBuilder();
        $group = $this->fakeGroup(['app/Core/Ads/X.php'], ['core'], subsystem: 'Ads');

        $message = $builder->build($group, [], 7, 46);
        $header = strtok($message, "\n");

        $this->assertMatchesRegularExpression('/^[a-z]+\([a-z0-9-]+\): .+/', $header,
            'type(scope): subject, lowercase scope — the convention already in this history');
        $this->assertLessThanOrEqual(72, mb_strlen($header));
        $this->assertStringContainsString('Risk:', $message);
        $this->assertStringContainsString('Breaking changes:', $message);
        $this->assertStringContainsString('Ref: Engineer888 commit plan #7, group', $message);
    }

    public function test_the_type_is_derived_from_what_the_group_contains(): void
    {
        $builder = new CommitMessageBuilder();

        $this->assertSame('docs', $builder->type($this->fakeGroup(['A.md'], ['documentation'])));
        $this->assertSame('test', $builder->type($this->fakeGroup(['tests/A.php'], ['test'])));
        $this->assertSame('chore', $builder->type($this->fakeGroup(['config/a.php'], ['config'])));
        $this->assertSame('feat', $builder->type($this->fakeGroup(['database/migrations/a.php'], ['migration'])));
        $this->assertSame('wip', $builder->type(
            $this->fakeGroup(['app/Core/W/A.php'], ['core'], classifications: [FileClassifier::PARTIAL])));
    }

    public function test_a_migration_commit_does_not_claim_there_are_no_breaking_changes(): void
    {
        $builder = new CommitMessageBuilder();
        $group = $this->fakeGroup(['database/migrations/a.php'], ['migration'], hasMigration: true);

        $message = $builder->build($group, [], 1, 1);

        $this->assertStringContainsString('not determined automatically', $message,
            'whether a schema change breaks existing data is not something this analysis reads');
        $this->assertStringNotContainsString('Breaking changes: none identified', $message);
    }

    /**
     * Regression. This engine typed its own first commit `wip` because the
     * comment explaining TODO detection contains the word TODO.
     */
    public function test_prose_mentioning_todo_is_not_an_unfinished_marker(): void
    {
        $this->makeRepo([
            'app/Core/W/Prose.php'  => "<?php\nnamespace App\\Core\\W;\n// reported it unfinished because the word TODO appears in the pattern\nclass Prose {}\n",
            'app/Core/W/Marked.php' => "<?php\nnamespace App\\Core\\W;\n// TODO: wire this up\nclass Marked {}\n",
            'app/Core/W/Doc.php'    => "<?php\nnamespace App\\Core\\W;\n/** @todo finish */\nclass Doc {}\n",
        ], commit: false);

        $map = new ArchitectureMap($this->repo);
        $classifier = new FileClassifier($this->repo, $map);

        $prose = $classifier->classify($this->fileRecord('app/Core/W/Prose.php'));
        $marked = $classifier->classify($this->fileRecord('app/Core/W/Marked.php'));
        $doc = $classifier->classify($this->fileRecord('app/Core/W/Doc.php'));

        $this->assertNotContains('unfinished-markers', $prose['flags'],
            'a sentence about TODO markers is not a TODO marker');
        $this->assertContains('unfinished-markers', $marked['flags']);
        $this->assertContains('unfinished-markers', $doc['flags'], '@todo is the docblock form');
    }

    // ── plan storage ────────────────────────────────────────────────────

    public function test_a_stored_plan_is_replayed_not_regenerated(): void
    {
        $this->makeRepo(['seed.txt' => "x\n"], commit: true);
        $this->write('app/Core/W/A.php', "<?php\nnamespace App\\Core\\W;\nclass A {}\n");

        $store = new PlanStore($this->repo);
        $created = $store->create();

        // Add a file AFTER planning. The stored plan must not know about it.
        $this->write('app/Core/W/Later.php', "<?php\nnamespace App\\Core\\W;\nclass Later {}\n");

        $loaded = $store->load($created['plan_id']);
        $paths = array_column($loaded['plan']['files'], 'path');

        $this->assertContains('app/Core/W/A.php', $paths);
        $this->assertNotContains('app/Core/W/Later.php', $paths,
            'execution consumes the plan as approved, not the tree as it is now');
    }

    public function test_drift_detection_distinguishes_modification_from_deletion(): void
    {
        $this->makeRepo([
            'a.php' => "<?php\n// one\n",
            'b.php' => "<?php\n// two\n",
            'c.php' => "<?php\n// three\n",
        ], commit: false);

        $store = new PlanStore($this->repo);
        $fingerprints = [
            'a.php' => $store->fingerprint('a.php'),
            'b.php' => $store->fingerprint('b.php'),
            'c.php' => $store->fingerprint('c.php'),
        ];

        $this->write('a.php', "<?php\n// changed\n");
        unlink($this->repo . '/b.php');

        $drift = $store->drift(['a.php', 'b.php', 'c.php'], $fingerprints);
        $byPath = array_column($drift, 'change', 'path');

        $this->assertSame('modified since planning', $byPath['a.php']);
        $this->assertSame('deleted since planning', $byPath['b.php']);
        $this->assertArrayNotHasKey('c.php', $byPath);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function fakeGroup(array $files, array $layers, string $subsystem = 'W',
                               array $classifications = [], bool $hasMigration = false): array
    {
        return [
            'key' => 'feature:' . $subsystem, 'kind' => 'feature', 'subsystem' => $subsystem,
            'title' => $subsystem . ' — feature implementation',
            'rationale' => 'All of these belong to the ' . $subsystem . ' subsystem.',
            'files' => $files, 'file_count' => count($files), 'layers' => $layers,
            'classifications' => $classifications ?: [FileClassifier::COMPLETE],
            'flags' => [], 'has_migration' => $hasMigration || in_array('migration', $layers, true),
            'has_test' => in_array('test', $layers, true), 'blocking' => [],
            'insertions' => 0, 'position' => 1, 'must_follow' => [],
        ];
    }

    private function fileRecord(string $path): array
    {
        $full = $this->repo . '/' . $path;

        return [
            'path' => $path, 'exists' => is_file($full),
            'size' => is_file($full) ? (int) filesize($full) : 0,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'untracked' => true, 'deleted' => false,
            'mtime' => is_file($full) ? (int) filemtime($full) : null,
        ];
    }

    /** @return array<string,mixed> plan context for the throwaway repo */
    private function plan(): array
    {
        $store = new PlanStore($this->repo);
        $created = $store->create();
        $loaded = $store->load($created['plan_id']);

        return [
            'store'        => $store,
            'plan_id'      => $created['plan_id'],
            'plan'         => $loaded['plan'],
            'fingerprints' => $loaded['fingerprints'],
            'graph'        => (new DependencyGraph($this->repo))->build(),
        ];
    }

    private function runGroup(array $context, int $index, bool $approve = true, bool $dryRun = false): array
    {
        $group = $context['plan']['commit_plan']['groups'][$index];

        return (new CommitExecutor($this->repo, $context['store']))
            ->execute($group, $context, $dryRun, $dryRun ? null : fn () => $approve);
    }

    private function runGroupContaining(array $context, string $path, bool $approve = true, bool $dryRun = false): array
    {
        foreach ($context['plan']['commit_plan']['groups'] as $i => $group) {
            if (in_array($path, $group['files'], true)) {
                return $this->runGroup($context, $i, $approve, $dryRun);
            }
        }

        return ['status' => 'absent', 'outcome' => $path . ' is in no group'];
    }

    private function executeFirstGroup(): array
    {
        $context = $this->plan();

        return $this->runGroup($context, 0);
    }

    private function makeRepo(array $files, bool $commit): void
    {
        mkdir($this->repo, 0775, true);
        foreach ($files as $path => $content) {
            $full = $this->repo . '/' . $path;
            if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
            file_put_contents($full, $content);
        }
        $this->git('init -q');
        $this->git('config user.email e888@test');
        $this->git('config user.name e888');
        if ($commit) {
            $this->git('add -A');
            $this->git('commit -q -m base');
        }
    }

    /** Write a file into the throwaway repo, creating its directory. */
    private function write(string $path, string $content): void
    {
        $full = $this->repo . '/' . $path;
        if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
        file_put_contents($full, $content);
    }

    private function git(string $args): string
    {
        $out = [];
        exec('cd ' . escapeshellarg($this->repo) . ' && git ' . $args . ' 2>&1', $out);

        return implode("\n", $out);
    }

    private function headSha(): string
    {
        return trim($this->git('rev-parse HEAD'));
    }

    private function commitCount(): int
    {
        return (int) trim($this->git('rev-list --count HEAD'));
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
