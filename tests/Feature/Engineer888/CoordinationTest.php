<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Coordination\DatabaseAssignment;
use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Execution\CommitExecutor;
use App\Core\Engineer888\Execution\PlanStore;
use App\Core\Engineer888\Repository\ArchitectureMap;
use App\Core\Engineer888\Repository\CommitPlanner;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Repository\FileClassifier;
use App\Core\Engineer888\Repository\WorkingTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Multi-engineer coordination controls.
 *
 * Everything here runs against throwaway resources: temporary git repositories
 * and temporary manifests. No test in this file touches a shared database, and
 * none creates one — the test user deliberately has no CREATE DATABASE grant,
 * and weakening that to make a test easier would invert the point of the sprint.
 *
 * The controls exist because of two specific events:
 *   2026-07-30  levelup_test was dropped while another engineer was migrating it
 *   2026-07-31  commit c42ea4b captured 9 documents written by another engineer
 * Each test below traces to one of those.
 */
class CoordinationTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/e888-coord-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── Capability 1: database assignment ───────────────────────────────

    public function test_the_assigned_isolated_database_is_accepted(): void
    {
        $manifest = $this->manifest(['test_database' => 'levelup_e888_test']);

        DatabaseAssignment::assertAssigned('levelup_e888_test', 'test', $manifest);

        $this->assertTrue(DatabaseAssignment::check('levelup_e888_test', $manifest)['assigned']);
    }

    public function test_a_shared_test_database_is_refused_even_though_it_looks_like_a_test_database(): void
    {
        $manifest = $this->manifest(['test_database' => 'levelup_e888_test']);

        foreach (['levelup_test', 'levelup_clean_test', 'boss888_test'] as $shared) {
            try {
                DatabaseAssignment::assertAssigned($shared, 'test', $manifest);
                $this->fail("{$shared} must be refused — it is shared, and dropping it destroyed a concurrent run");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('SHARED', $e->getMessage());
            }
        }
    }

    public function test_another_engineers_isolated_database_is_refused(): void
    {
        $manifest = $this->manifest(['test_database' => 'levelup_e888_test']);

        // levelup_p1e1_test is a perfectly valid test database — just not mine.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not assigned to this session/');

        DatabaseAssignment::assertAssigned('levelup_p1e1_test', 'test', $manifest);
    }

    public function test_production_like_names_are_always_refused(): void
    {
        $manifest = $this->manifest(['test_database' => 'levelup_staging']);   // even if assigned!

        foreach (['levelup_staging', 'levelup', 'levelup_production', 'levelup_prod'] as $production) {
            try {
                DatabaseAssignment::assertAssigned($production, 'test', $manifest);
                $this->fail("{$production} must be refused regardless of what any manifest claims");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('production', $e->getMessage());
            }
        }
    }

    public function test_a_missing_manifest_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no sprint manifest is active|fails closed/');

        // Point the resolver at a manifest that does not exist, so active()
        // genuinely finds nothing. Without this it falls back to the repository's
        // real manifest and the test proves the wrong thing.
        putenv('E888_SPRINT_MANIFEST=/nonexistent/manifest.json');
        try {
            DatabaseAssignment::assertAssigned('levelup_anything_test', 'test');
        } finally {
            putenv('E888_SPRINT_MANIFEST');
        }
    }

    public function test_ambiguous_assignment_fails_closed(): void
    {
        // Two manifests, no explicit selection: active() must decline to choose.
        @mkdir($this->repo . '/' . OwnershipManifest::DIRECTORY, 0775, true);
        file_put_contents($this->repo . '/' . OwnershipManifest::DIRECTORY . '/a.json',
            json_encode(['session' => 'a', 'test_database' => 'levelup_a_test']));
        file_put_contents($this->repo . '/' . OwnershipManifest::DIRECTORY . '/b.json',
            json_encode(['session' => 'b', 'test_database' => 'levelup_b_test']));

        $this->assertNull(OwnershipManifest::active($this->repo),
            'two manifests with no explicit selection is ambiguous, and ambiguity must not resolve to a guess');
    }

    // ── Capability 3: ownership ─────────────────────────────────────────

    public function test_owned_paths_and_files_are_recognised(): void
    {
        $manifest = $this->manifest([
            'owned_paths' => ['app/Core/Engineer888/**', 'tests/Feature/Engineer888/**'],
            'owned_files' => ['tools/exec-safety-audit.php'],
        ]);

        $this->assertSame(OwnershipManifest::OWNED,
            $manifest->classify('app/Core/Engineer888/Signals/GitSignal.php')['status'],
            '** must match at any depth');
        $this->assertSame(OwnershipManifest::OWNED,
            $manifest->classify('tools/exec-safety-audit.php')['status']);
    }

    /** The c42ea4b failure, as a test. */
    public function test_another_engineers_document_is_not_owned(): void
    {
        $manifest = $this->manifest(['owned_paths' => ['app/Core/Engineer888/**']]);

        $result = $manifest->classify('BOSS888-PLATFORM-EVENTS-ADR.md');

        $this->assertSame(OwnershipManifest::UNKNOWN, $result['status']);
        $this->assertFalse(OwnershipManifest::isCommittable($result['status']),
            'this is the exact file class that commit c42ea4b captured');
    }

    public function test_exclusions_beat_ownership_globs(): void
    {
        $manifest = $this->manifest([
            'owned_paths' => ['app/**'],
            'exclusions'  => ['app/Core/PlatformEvents/**'],
        ]);

        $this->assertSame(OwnershipManifest::OWNED, $manifest->classify('app/Core/Chat/Thing.php')['status']);
        $this->assertSame(OwnershipManifest::EXCLUDED,
            $manifest->classify('app/Core/PlatformEvents/Outbox.php')['status'],
            'an explicit exclusion is the only way to carve out a directory otherwise owned');
    }

    public function test_a_declared_shared_file_is_committable_and_an_undeclared_one_is_not(): void
    {
        $manifest = $this->manifest([
            'owned_paths'  => ['app/Core/Engineer888/**'],
            'shared_files' => [['path' => 'tests/TestCase.php', 'reason' => 'INC-2026-006 guard order']],
        ]);

        $declared = $manifest->classify('tests/TestCase.php');
        $this->assertSame(OwnershipManifest::SHARED_DECLARED, $declared['status']);
        $this->assertTrue(OwnershipManifest::isCommittable($declared['status']));
        $this->assertStringContainsString('INC-2026-006', $declared['evidence']);

        $this->assertSame(OwnershipManifest::UNKNOWN, $manifest->classify('composer.json')['status']);
    }

    // ── Capability 2: governed files ────────────────────────────────────

    public function test_high_blast_radius_files_are_governed(): void
    {
        $governed = GovernedFiles::all();

        $this->assertArrayHasKey('tests/TestCase.php', $governed,
            'the base class for every test in the platform must be governed');
        $this->assertSame('HIGH', $governed['tests/TestCase.php']['blast_radius']);
        $this->assertNotEmpty($governed['tests/TestCase.php']['why_governed']);

        foreach (GovernedFiles::all() as $path => $policy) {
            foreach (['classification', 'blast_radius', 'ownership', 'affects', 'why_governed'] as $key) {
                $this->assertArrayHasKey($key, $policy, "{$path} must state {$key}");
            }
        }

        $this->assertNotEmpty(GovernedFiles::procedure());
    }

    // ── the controls, end to end on a throwaway repository ──────────────

    public function test_an_externally_owned_file_is_excluded_from_the_commit_plan(): void
    {
        $this->makeRepo([
            'app/Core/Engineer888/Mine.php'   => "<?php\nnamespace App\\Core\\Engineer888;\nclass Mine {}\n",
            'app/Core/PlatformEvents/Theirs.php' => "<?php\nnamespace App\\Core\\PlatformEvents;\nclass Theirs {}\n",
            'BOSS888-PLATFORM-EVENTS-ADR.md'  => "# Their document\n",
        ]);
        $this->writeManifest(['owned_paths' => ['app/Core/Engineer888/**']]);

        $plan = $this->planFixture();

        $staged = [];
        foreach ($plan['groups'] as $group) { $staged = array_merge($staged, $group['files']); }

        $this->assertContains('app/Core/Engineer888/Mine.php', $staged);
        $this->assertNotContains('app/Core/PlatformEvents/Theirs.php', $staged,
            'technically coherent, but not this sprint\'s to commit');
        $this->assertNotContains('BOSS888-PLATFORM-EVENTS-ADR.md', $staged);

        $notOwned = array_column($plan['excluded']['not_owned'], 'path');
        $this->assertContains('app/Core/PlatformEvents/Theirs.php', $notOwned);
        $this->assertContains('BOSS888-PLATFORM-EVENTS-ADR.md', $notOwned);
    }

    public function test_a_governed_file_is_excluded_unless_the_manifest_declares_it(): void
    {
        $this->makeRepo([
            'app/Core/Engineer888/Mine.php' => "<?php\nnamespace App\\Core\\Engineer888;\nclass Mine {}\n",
            'tests/TestCase.php'            => "<?php\nnamespace Tests;\nclass TestCase {}\n",
        ]);

        // Undeclared: excluded even though app/** would own it.
        $this->writeManifest(['owned_paths' => ['app/**', 'tests/**']]);
        $plan = $this->planFixture();
        $staged = [];
        foreach ($plan['groups'] as $group) { $staged = array_merge($staged, $group['files']); }

        $this->assertNotContains('tests/TestCase.php', $staged,
            'a governed file needs an explicit declaration, not a broad ownership glob');
        $this->assertContains('tests/TestCase.php',
            array_column($plan['excluded']['governed_undeclared'], 'path'));

        // Declared: permitted.
        $this->writeManifest([
            'owned_paths'  => ['app/**'],
            'shared_files' => [['path' => 'tests/TestCase.php', 'reason' => 'declared for this test']],
        ]);
        $plan = $this->planFixture();
        $staged = [];
        foreach ($plan['groups'] as $group) { $staged = array_merge($staged, $group['files']); }

        $this->assertContains('tests/TestCase.php', $staged);
    }

    public function test_drift_after_the_plan_is_stored_causes_refusal(): void
    {
        $this->makeRepo(['app/Core/Engineer888/Drifty.php' => "<?php\nnamespace App\\Core\\Engineer888;\nclass Drifty {}\n"]);
        $this->writeManifest(['owned_paths' => ['app/Core/Engineer888/**']]);

        $store = new PlanStore($this->repo);
        $created = $store->create();
        $loaded = $store->load($created['plan_id']);

        // Another engineer edits the file between planning and execution.
        file_put_contents($this->repo . '/app/Core/Engineer888/Drifty.php',
            "<?php\nnamespace App\\Core\\Engineer888;\nclass Drifty { /* someone else was here */ }\n");

        $group = $loaded['plan']['commit_plan']['groups'][0];
        $result = (new CommitExecutor($this->repo, $store))->execute($group, [
            'plan_id' => $created['plan_id'], 'plan' => $loaded['plan'],
            'fingerprints' => $loaded['fingerprints'], 'graph' => (new DependencyGraph($this->repo))->build(),
        ], false, fn () => true);

        $this->assertSame('preflight_failed', $result['status']);
        $this->assertStringContainsString('changed since planning', (string) $result['outcome']);
    }

    // ── migrations ──────────────────────────────────────────────────────

    public function test_engineer888_operations_leave_unrelated_pending_migrations_untouched(): void
    {
        $pending = fn () => array_values(array_filter(
            array_map('trim', explode("\n", (string) shell_exec(
                'cd ' . escapeshellarg(base_path()) . ' && php artisan migrate:status 2>/dev/null | grep -i pending'
            ))),
            fn ($line) => $line !== ''
        ));

        $before = $pending();

        // A representative Engineer888 operation.
        shell_exec('cd ' . escapeshellarg(base_path()) . ' && php artisan engineering:brief --no-store >/dev/null 2>&1');

        $this->assertSame($before, $pending(),
            'no Engineer888 operation may run a migration it does not own — '
            . 'every migrate call must be --path scoped');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function manifest(array $overrides): OwnershipManifest
    {
        @mkdir($this->repo, 0775, true);
        $path = $this->repo . '/manifest.json';
        file_put_contents($path, json_encode(array_merge([
            'manifest_version' => 1,
            'engineer' => 'Engineer888',
            'session'  => 'e888-test',
            'sprint'   => 'coordination-test',
        ], $overrides), JSON_PRETTY_PRINT));

        return OwnershipManifest::load($path);
    }

    private function writeManifest(array $overrides): void
    {
        $dir = $this->repo . '/' . OwnershipManifest::DIRECTORY;
        @mkdir($dir, 0775, true);
        foreach ((array) glob($dir . '/*.json') as $old) { unlink($old); }
        file_put_contents($dir . '/sprint.json', json_encode(array_merge([
            'manifest_version' => 1,
            'engineer' => 'Engineer888',
            'session'  => 'e888-test',
            'sprint'   => 'coordination-test',
            'test_database' => 'levelup_e888_test',
        ], $overrides), JSON_PRETTY_PRINT));
    }

    private function planFixture(): array
    {
        $tree = WorkingTree::read($this->repo);
        $map = new ArchitectureMap($this->repo);
        $graph = (new DependencyGraph($this->repo))->build();
        $classifier = new FileClassifier($this->repo, $map);
        $manifest = OwnershipManifest::active($this->repo);

        $analysed = [];
        foreach ($tree['files'] as $file) {
            $file['layer'] = $map->layer($file['path']);
            $file['subsystem'] = $map->subsystem($file['path']);
            $file['analysis'] = $classifier->classify($file, $graph);
            $file['ownership'] = $manifest !== null
                ? $manifest->classify($file['path'])
                : ['status' => OwnershipManifest::UNKNOWN, 'evidence' => 'no manifest'];
            $analysed[] = $file;
        }

        return (new CommitPlanner($map))->plan($analysed, $graph);
    }

    private function makeRepo(array $files): void
    {
        @mkdir($this->repo, 0775, true);
        foreach ($files as $path => $content) {
            $full = $this->repo . '/' . $path;
            if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
            file_put_contents($full, $content);
        }
        $quoted = escapeshellarg($this->repo);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) { return; }
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
