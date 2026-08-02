<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Baseline\BaselineSnapshot;
use App\Core\Engineer888\Baseline\DirtyFileClassifier;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Repository\FileClassifier;
use Tests\TestCase;

/**
 * Sprint 5 — production baseline.
 *
 * The claims under test are all negative: that a file this sprint cannot prove
 * it owns never reaches a commit, that a file which moved under our feet is
 * dropped, and that a snapshot can prove nothing changed silently.
 *
 * Throwaway fixtures only. Nothing here touches the real tree or a shared
 * database.
 */
class BaselineTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/e888-baseline-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $this->manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($this->manifestPath), 0775, true);
        $this->writeManifest(['owned_paths' => ['app/Core/Engineer888/**'], 'shared_files' => []]);
    }

    private string $manifestPath;

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── classification ──────────────────────────────────────────────────

    public function test_a_file_this_sprint_owns_is_committable(): void
    {
        $verdict = $this->classify('app/Core/Engineer888/Thing.php', FileClassifier::COMPLETE);

        $this->assertSame(DirtyFileClassifier::OWNED_COMPLETE, $verdict['category']);
        $this->assertTrue($verdict['committable']);
    }

    public function test_unknown_ownership_is_excluded(): void
    {
        $verdict = $this->classify('app/Core/PlatformEvents/Outbox.php', FileClassifier::COMPLETE);

        $this->assertSame(DirtyFileClassifier::OWNERSHIP_UNKNOWN, $verdict['category']);
        $this->assertFalse($verdict['committable'],
            'a complete, coherent file is still not this sprint\'s to commit');
    }

    public function test_an_externally_owned_file_is_named_not_guessed(): void
    {
        $verdict = $this->classify('tests/Feature/Chat/ThingTest.php', FileClassifier::COMPLETE,
            ['tests/Feature/Chat' => 'session chat']);

        $this->assertSame(DirtyFileClassifier::EXTERNALLY_OWNED, $verdict['category']);
        $this->assertStringContainsString('session chat', $verdict['basis']);
        $this->assertFalse($verdict['committable']);
    }

    public function test_a_sensitive_file_can_never_be_committed(): void
    {
        $flagged = $this->classify('lu-ad', FileClassifier::TEMPORARY, [], ['may-contain-session-token']);
        $this->assertSame(DirtyFileClassifier::SENSITIVE, $flagged['category']);
        $this->assertFalse($flagged['committable']);

        // Even inside an owned path, and even if it looks complete.
        $byName = $this->classify('app/Core/Engineer888/.env.backup', FileClassifier::COMPLETE);
        $this->assertSame(DirtyFileClassifier::SENSITIVE, $byName['category']);
    }

    public function test_runtime_data_and_artifacts_are_excluded(): void
    {
        $this->assertSame(DirtyFileClassifier::RUNTIME_DATA,
            $this->classify('storage/app/thing.json', FileClassifier::COMPLETE)['category']);
        $this->assertSame(DirtyFileClassifier::BACKUP,
            $this->classify('app/Core/Engineer888/X.php.bak', FileClassifier::BACKUP)['category']);
        $this->assertSame(DirtyFileClassifier::TEMPORARY,
            $this->classify('cron|-', FileClassifier::TEMPORARY)['category']);

        // public/build is BOTH generated and runtime-derived. The path check runs
        // first by design, so it reports RUNTIME_DATA. Either way it is excluded;
        // this asserts the documented precedence rather than a coin toss.
        $this->assertSame(DirtyFileClassifier::RUNTIME_DATA,
            $this->classify('public/build/app.js', FileClassifier::GENERATED)['category']);

        // Generated output outside a runtime path keeps its own category.
        $this->assertSame(DirtyFileClassifier::GENERATED,
            $this->classify('composer.lock', FileClassifier::GENERATED)['category']);

        foreach (['storage/app/thing.json', 'app/Core/Engineer888/X.php.bak', 'cron|-',
                  'public/build/app.js', 'composer.lock'] as $path) {
            $this->assertFalse($this->classify($path, FileClassifier::GENERATED)['committable'],
                $path . ' must never enter the baseline');
        }
    }

    public function test_unsafe_content_is_excluded_even_when_owned(): void
    {
        $merge = $this->classify('app/Core/Engineer888/Broken.php', FileClassifier::MERGE);
        $this->assertSame(DirtyFileClassifier::UNSAFE_TO_COMMIT, $merge['category']);

        $debug = $this->classify('app/Core/Engineer888/Leaky.php', FileClassifier::COMPLETE, [], ['debug-statements']);
        $this->assertSame(DirtyFileClassifier::UNSAFE_TO_COMMIT, $debug['category']);
    }

    public function test_a_governed_shared_file_needs_an_explicit_declaration(): void
    {
        $undeclared = $this->classify('tests/TestCase.php', FileClassifier::COMPLETE);
        $this->assertSame(DirtyFileClassifier::OWNERSHIP_UNKNOWN, $undeclared['category']);

        $this->writeManifest([
            'owned_paths'  => ['app/Core/Engineer888/**'],
            'shared_files' => [['path' => 'tests/TestCase.php', 'reason' => 'declared for this test']],
        ]);

        $declared = $this->classify('tests/TestCase.php', FileClassifier::COMPLETE);
        $this->assertSame(DirtyFileClassifier::GOVERNED_SHARED, $declared['category']);
        $this->assertTrue($declared['committable']);
    }

    public function test_documentation_still_requires_an_owner(): void
    {
        // The c42ea4b incident was documentation.
        $theirs = $this->classify('BOSS888-PLATFORM-EVENTS-ADR.md', FileClassifier::DOCUMENTATION);
        $this->assertFalse($theirs['committable']);

        $mine = $this->classify('app/Core/Engineer888/README.md', FileClassifier::DOCUMENTATION);
        $this->assertSame(DirtyFileClassifier::DOCUMENTATION, $mine['category']);
        $this->assertTrue($mine['committable']);
    }

    // ── snapshot and drift ──────────────────────────────────────────────

    public function test_a_snapshot_detects_a_file_changing_underneath_it(): void
    {
        $this->makeRepo(['app/Core/Engineer888/A.php' => "<?php\n// v1\n"]);
        $snapshots = new BaselineSnapshot($this->repo);

        $snapshot = $snapshots->capture('test');
        $this->assertFalse($snapshots->hasDrifted($snapshot, 'app/Core/Engineer888/A.php'));

        // Another engineer edits it.
        file_put_contents($this->repo . '/app/Core/Engineer888/A.php', "<?php\n// they changed it\n");

        $this->assertTrue($snapshots->hasDrifted($snapshot, 'app/Core/Engineer888/A.php'),
            'a file that moved after classification must be dropped from its commit group');

        $verify = $snapshots->verify($snapshot['path']);
        $this->assertTrue($verify['drift_detected']);
        $this->assertSame('app/Core/Engineer888/A.php', $verify['changed'][0]['path']);
    }

    public function test_a_snapshot_proves_it_was_not_itself_edited(): void
    {
        $this->makeRepo(['app/Core/Engineer888/A.php' => "<?php\n"]);
        $snapshots = new BaselineSnapshot($this->repo);
        $snapshot = $snapshots->capture('test');

        $this->assertTrue($snapshots->verify($snapshot['path'])['integrity_intact']);

        // Tamper with the record itself.
        $full = $this->repo . '/' . $snapshot['path'];
        $data = json_decode((string) file_get_contents($full), true);
        $data['files']['app/Core/Engineer888/A.php']['hash'] = str_repeat('0', 40);
        file_put_contents($full, json_encode($data));

        $this->assertFalse($snapshots->verify($snapshot['path'])['integrity_intact'],
            'an edited snapshot must not be able to vouch for anything');
    }

    public function test_a_file_appearing_after_the_snapshot_is_reported(): void
    {
        $this->makeRepo(['app/Core/Engineer888/A.php' => "<?php\n"]);
        $snapshots = new BaselineSnapshot($this->repo);
        $snapshot = $snapshots->capture('test');

        file_put_contents($this->repo . '/app/Core/Engineer888/B.php', "<?php\n// arrived later\n");

        $verify = $snapshots->verify($snapshot['path']);
        $this->assertContains('app/Core/Engineer888/B.php', $verify['appeared']);
        $this->assertTrue($verify['drift_detected']);
    }

    public function test_an_unknown_file_counts_as_drift(): void
    {
        $this->makeRepo(['app/Core/Engineer888/A.php' => "<?php\n"]);
        $snapshots = new BaselineSnapshot($this->repo);
        $snapshot = $snapshots->capture('test');

        $this->assertTrue($snapshots->hasDrifted($snapshot, 'app/Core/Engineer888/NeverSeen.php'),
            'a file the snapshot never saw cannot be vouched for');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{category:string, basis:string, committable:bool} */
    private function classify(string $path, string $classification, array $external = [], array $flags = []): array
    {
        $manifest = OwnershipManifest::load($this->manifestPath);

        return (new DirtyFileClassifier($manifest, $external))->classify([
            'path'     => $path,
            'analysis' => ['classification' => $classification, 'flags' => $flags, 'reason' => 'fixture'],
        ]);
    }

    private function writeManifest(array $overrides): void
    {
        file_put_contents($this->manifestPath, json_encode(array_merge([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'baseline-test', 'test_database' => 'levelup_e888_test',
        ], $overrides), JSON_PRETTY_PRINT));
    }

    private function makeRepo(array $files): void
    {
        foreach ($files as $path => $content) {
            $full = $this->repo . '/' . $path;
            if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
            file_put_contents($full, $content);
        }
        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
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
