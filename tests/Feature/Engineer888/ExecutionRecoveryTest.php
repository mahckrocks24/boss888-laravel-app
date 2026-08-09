<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Audit\TreeScan;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Recovery\RecoveryManifest;
use App\Core\Engineer888\Recovery\RecoveryService;
use App\Core\Engineer888\Runtime\Workspace;
use App\Core\Engineer888\Verification\VerificationPlan;
use App\Core\Engineer888\Workflow\ImplementStage;
use App\Core\Engineer888\Workflow\StageResult;
use App\Core\Engineer888\Workflow\WorkflowContext;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Execution safety and recovery.
 *
 * Sprint 8 approved a file, installed it, failed verification and stopped —
 * leaving unverified code running. Stopping was right. Leaving it was not.
 *
 * What is proved here: that a write is never made without a provable undo, that
 * the undo happens automatically when verification fails, and — the part that
 * matters most in a repository three engineers are working in at once — that it
 * refuses rather than overwrite somebody else's change.
 */
class ExecutionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-rec-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'recovery-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q && git config user.email e888@test && git config user.name e888 2>&1');

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── repository traversal ────────────────────────────────────────────

    public function test_traversal_filters_before_descending_into_excluded_directories(): void
    {
        @mkdir($this->repo . '/storage/framework/locked', 0775, true);
        file_put_contents($this->repo . '/storage/framework/locked/X.php', "<?php\n");
        @chmod($this->repo . '/storage/framework/locked', 0000);

        @mkdir($this->repo . '/app/Owned', 0775, true);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n");

        $scan = new TreeScan($this->repo);
        $files = $scan->files();

        @chmod($this->repo . '/storage/framework/locked', 0775);

        $this->assertContains('app/Owned/A.php', $files);
        $this->assertTrue($scan->isComplete(),
            'an unreadable EXCLUDED directory must never fail a scan — it was not going to be read');
        $this->assertNotEmpty(
            array_filter($scan->excludedDirectories(), fn ($d) => str_starts_with($d, 'storage')),
            'the exclusion must be recorded, not silent'
        );
    }

    public function test_an_unreadable_required_path_makes_the_scan_incomplete(): void
    {
        @mkdir($this->repo . '/app/Locked', 0775, true);
        file_put_contents($this->repo . '/app/Locked/Y.php', "<?php\n");
        @chmod($this->repo . '/app/Locked', 0000);

        $scan = new TreeScan($this->repo);
        $scan->files();
        $unreadable = $scan->requiredUnreadable();

        @chmod($this->repo . '/app/Locked', 0775);

        if ($unreadable === []) {
            // Running as root, where nothing is unreadable. The rule is still
            // asserted below against the classifier, which is what callers use.
            $this->assertSame(TreeScan::REQUIRED, $scan->classify('app/Locked/Y.php'));
            $this->markTestSkipped('running as a user for whom nothing is unreadable');
        }

        $this->assertFalse($scan->isComplete(),
            'a scan that could not read app/ must not be able to report a verdict');
        $this->assertSame('app/Locked', $unreadable[0]['path']);
    }

    /** @dataProvider pathClasses */
    public function test_paths_are_classified_by_what_a_verdict_depends_on(string $path, string $class): void
    {
        $this->assertSame($class, (new TreeScan($this->repo))->classify($path));
    }

    public static function pathClasses(): array
    {
        return [
            ['app/Core/X.php', TreeScan::REQUIRED],
            ['tests/Feature/X.php', TreeScan::REQUIRED],
            ['routes/api.php', TreeScan::REQUIRED],
            ['storage/framework/x', TreeScan::EXCLUDED],
            ['vendor/foo/bar.php', TreeScan::EXCLUDED],
            ['resources/views/x.blade.php', TreeScan::OPTIONAL],
        ];
    }

    // ── temporary workspace ─────────────────────────────────────────────

    public function test_the_workspace_never_needs_the_repository_root_to_be_writable(): void
    {
        $workspace = Workspace::open($this->repo, 'run-a');
        $path = $workspace->write('note.txt', 'hello');

        $this->assertStringContainsString('storage/app/engineer888/run/run-a', $path);
        $this->assertSame('hello', file_get_contents($path));
        $this->assertStringNotContainsString('/app/Owned', $path);

        $workspace->close();
        $this->assertFileDoesNotExist($path, 'a workspace must clean up after itself');
    }

    public function test_workspaces_are_isolated_per_execution(): void
    {
        $a = Workspace::open($this->repo, 'run-a');
        $b = Workspace::open($this->repo, 'run-b');

        $a->write('x', 'from a');
        $b->write('x', 'from b');

        $this->assertSame('from a', file_get_contents($a->path('x')));
        $this->assertSame('from b', file_get_contents($b->path('x')));

        $a->close();
        $this->assertFileExists($b->path('x'), 'closing one run must not touch another');
        $b->close();
    }

    /** @dataProvider traversalAttempts */
    public function test_the_workspace_refuses_path_traversal(string $name): void
    {
        $workspace = Workspace::open($this->repo, 'run-a');

        $this->expectExceptionMessageMatches('/unsafe workspace segment/');

        try {
            $workspace->path($name);
        } finally {
            $workspace->close();
        }
    }

    public static function traversalAttempts(): array
    {
        return [['../escape'], ['a/b'], ['..'], ['']];
    }

    public function test_a_rewritten_phpunit_config_keeps_its_paths_resolvable(): void
    {
        $template = $this->repo . '/phpunit.fixture.xml';
        file_put_contents($template, '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php">'
            . '<testsuites><testsuite name="x"><directory suffix="Test.php">./tests/Feature</directory>'
            . '</testsuite></testsuites></phpunit>');

        $workspace = Workspace::open($this->repo, 'run-c');
        $config = $workspace->phpunitConfigFrom($template, $this->repo, ['x' => 'y']);
        $xml = (string) file_get_contents($config);

        // PHPUnit resolves these relative to the CONFIG file. Copying without
        // rewriting produces a config that finds no tests and reads as a pass.
        $this->assertStringContainsString($this->repo . '/vendor/autoload.php', $xml);
        $this->assertStringContainsString($this->repo . '/tests/Feature', $xml);
        $this->assertStringContainsString('name="y"', $xml);

        $workspace->close();
    }

    // ── change-scoped verification ──────────────────────────────────────

    public function test_a_governed_file_widens_verification_to_the_full_suite(): void
    {
        $plan = $this->plan(['tests/TestCase.php']);

        $this->assertSame(VerificationPlan::FULL_SUITE_REQUIRED, $plan['adequacy']);
        $this->assertTrue($plan['full_suite']);
        $this->assertNotEmpty($plan['policy']);
    }

    public function test_a_migration_widens_verification_to_the_full_suite(): void
    {
        $plan = $this->plan(['database/migrations/2026_01_01_000000_x.php']);

        $this->assertSame(VerificationPlan::FULL_SUITE_REQUIRED, $plan['adequacy']);
        $this->assertContains('migration-contract', array_column($plan['checks'], 'check'));
    }

    public function test_test_infrastructure_changes_are_never_graded_by_themselves(): void
    {
        $plan = $this->plan(['phpunit.e888.xml']);

        $this->assertTrue($plan['full_suite']);
    }

    public function test_a_change_no_test_references_is_insufficient_evidence_not_a_fast_pass(): void
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);
        file_put_contents($this->repo . '/app/Owned/Lonely.php', "<?php\nnamespace App\\Owned;\nclass Lonely {}\n");

        // A real test suite that simply does not reference the changed class.
        // Without it, selection cannot run at all, which correctly widens to the
        // full suite — a different verdict from the one under test here.
        @mkdir($this->repo . '/tests/Feature', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/UnrelatedTest.php',
            "<?php\nnamespace Tests\\Feature;\nuse App\\Owned\\Elsewhere;\nclass UnrelatedTest {}\n");
        file_put_contents($this->repo . '/app/Owned/Elsewhere.php',
            "<?php\nnamespace App\\Owned;\nclass Elsewhere {}\n");

        $plan = $this->plan(['app/Owned/Lonely.php']);

        $this->assertSame(VerificationPlan::INSUFFICIENT_EVIDENCE, $plan['adequacy'],
            'selection ran and found no test referencing this change: ' . json_encode($plan['policy']));
        $this->assertFalse(VerificationPlan::permitsExecution($plan['adequacy']),
            'a change with no covering test must stop the workflow, not pass quickly');
    }

    public function test_every_check_and_every_exclusion_carries_a_reason(): void
    {
        $plan = $this->plan(['tests/TestCase.php']);

        foreach ($plan['checks'] as $check) {
            $this->assertNotSame('', (string) ($check['why'] ?? ''), 'a check with no reason cannot be reviewed');
        }
        foreach ($plan['excluded'] as $excluded) {
            $this->assertNotSame('', (string) ($excluded['why'] ?? ''));
        }
    }

    public function test_verification_is_blocked_without_an_isolated_test_config(): void
    {
        $plan = (new VerificationPlan($this->repo))->build(['app/Owned/A.php'], null);

        $this->assertSame(VerificationPlan::BLOCKED, $plan['adequacy']);
        $this->assertFalse(VerificationPlan::permitsExecution($plan['adequacy']));
    }

    // ── the recovery manifest ───────────────────────────────────────────

    public function test_the_manifest_records_the_pre_state_and_backs_it_up_before_any_write(): void
    {
        $this->write('app/Owned/A.php', "<?php\n// original\n");
        $preHash = hash_file('sha256', $this->repo . '/app/Owned/A.php');

        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// new\n",
                                     'app/Owned/B.php' => "<?php\n// created\n"]);

        $entries = collect($manifest->entries)->keyBy('path');

        $this->assertSame(RecoveryManifest::UPDATE, $entries['app/Owned/A.php']['action']);
        $this->assertSame($preHash, $entries['app/Owned/A.php']['pre_hash']);
        $this->assertNotNull($entries['app/Owned/A.php']['backup_path']);
        $this->assertSame($preHash, $entries['app/Owned/A.php']['backup_hash'],
            'a backup that does not match what it copied is not a backup');

        $this->assertSame(RecoveryManifest::CREATE, $entries['app/Owned/B.php']['action']);
        $this->assertNull($entries['app/Owned/B.php']['pre_hash']);

        $this->assertSame([], $manifest->deficiencies());
        $this->assertSame("<?php\n// original\n", file_get_contents($this->repo . '/app/Owned/A.php'),
            'capturing the manifest must not write anything');
    }

    public function test_an_update_without_a_verified_backup_is_a_deficiency(): void
    {
        $manifest = new RecoveryManifest('w', 't', 'c', 'f', [[
            'path' => 'app/Owned/A.php', 'action' => RecoveryManifest::UPDATE,
            'existed_before' => true, 'pre_hash' => str_repeat('a', 64), 'pre_bytes' => 1,
            'intended_hash' => str_repeat('b', 64), 'intended_bytes' => 1,
            'backup_path' => null, 'backup_hash' => null, 'ownership' => 'OWNED', 'governed' => false,
        ]], 'now');

        $this->assertNotEmpty($manifest->deficiencies());
    }

    public function test_implement_writes_nothing_when_the_undo_cannot_be_promised(): void
    {
        // E1-D: SourceGrounding can only prove a target absent when its parent
        // directory exists, and a create with no grounded absence is refused
        // before it can install. The directory is what a real tree looks like.
        @mkdir($this->repo . '/app/Owned', 0775, true);

        [$task, $project, $uuid] = $this->approvedCandidate("<?php\n// x\n");

        // A destination directory that cannot be backed up into.
        $context = $this->context($task, $project, ['app/Owned/A.php']);

        // Make the recovery backup location impossible.
        @mkdir($this->repo . '/.engineer888', 0775, true);
        file_put_contents($this->repo . '/.engineer888/recovery', 'not a directory');

        $result = (new ImplementStage())->run($context);

        @unlink($this->repo . '/.engineer888/recovery');

        $this->assertSame(StageResult::FAILED, $result->status);
        $this->assertStringContainsString('recovery manifest incomplete', $result->summary);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
    }

    // ── automatic recovery ──────────────────────────────────────────────

    public function test_a_created_file_is_removed_when_verification_fails(): void
    {
        $this->write('app/Owned/A.php', null);   // ensure directory exists
        @unlink($this->repo . '/app/Owned/A.php');

        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// installed\n"]);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// installed\n");

        $evidence = (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'INSTALL', 'destination' => 'app/Owned/A.php'],
        ]);

        $this->assertSame(RecoveryService::RECOVERED, $evidence['status']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
        $this->assertSame('REMOVED', $evidence['performed'][0]['action']);
    }

    public function test_an_updated_file_is_restored_to_exactly_its_prior_bytes(): void
    {
        $original = "<?php\n// the original, byte for byte\n";
        $this->write('app/Owned/A.php', $original);
        $preHash = hash_file('sha256', $this->repo . '/app/Owned/A.php');

        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// replacement\n"]);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// replacement\n");

        $evidence = (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'UPDATE', 'destination' => 'app/Owned/A.php'],
        ]);

        $this->assertSame(RecoveryService::RECOVERED, $evidence['status']);
        $this->assertSame($original, file_get_contents($this->repo . '/app/Owned/A.php'));
        $this->assertSame($preHash, hash_file('sha256', $this->repo . '/app/Owned/A.php'));
        $this->assertTrue($evidence['performed'][0]['verified']);
    }

    public function test_a_file_this_workflow_did_not_write_is_left_alone(): void
    {
        $this->write('app/Owned/A.php', "<?php\n// mine\n");
        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// planned\n"]);

        // No decision says it was written — the run halted before it.
        $evidence = (new RecoveryService($this->repo))->restore($manifest, []);

        $this->assertSame(RecoveryService::RECOVERED, $evidence['status']);
        $this->assertSame("<?php\n// mine\n", file_get_contents($this->repo . '/app/Owned/A.php'));
        $this->assertSame('SKIP', $evidence['performed'] === [] ? 'SKIP' : $evidence['performed'][0]['action']);
    }

    // ── recovery refuses rather than overwrite ──────────────────────────

    public function test_recovery_refuses_when_another_engineer_changed_the_file(): void
    {
        $original = "<?php\n// original\n";
        $this->write('app/Owned/A.php', $original);

        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// mine\n"]);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// mine\n");

        // Somebody else edits the same file between the write and the recovery.
        $theirs = "<?php\n// somebody else was here\n";
        file_put_contents($this->repo . '/app/Owned/A.php', $theirs);

        $evidence = (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'UPDATE', 'destination' => 'app/Owned/A.php'],
        ]);

        $this->assertSame(RecoveryService::BLOCKED, $evidence['status']);
        $this->assertSame($theirs, file_get_contents($this->repo . '/app/Owned/A.php'),
            "another engineer's work must never be overwritten in the name of rollback");
        $this->assertNotEmpty($evidence['manual'], 'a blocked recovery must say what a human should do');
        $this->assertStringContainsString('another engineer', $evidence['problems'][0]['reason']);
    }

    public function test_recovery_refuses_when_the_backup_itself_changed(): void
    {
        $this->write('app/Owned/A.php', "<?php\n// original\n");
        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// mine\n"]);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// mine\n");

        $backup = $this->repo . '/' . $manifest->entries[0]['backup_path'];
        file_put_contents($backup, "<?php\n// tampered backup\n");

        $evidence = (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'UPDATE', 'destination' => 'app/Owned/A.php'],
        ]);

        $this->assertSame(RecoveryService::BLOCKED, $evidence['status']);
        $this->assertSame("<?php\n// mine\n", file_get_contents($this->repo . '/app/Owned/A.php'));
    }

    public function test_every_recovery_is_recorded_with_its_fingerprint(): void
    {
        $this->write('app/Owned/A.php', "<?php\n// original\n");
        $manifest = $this->manifest(['app/Owned/A.php' => "<?php\n// mine\n"]);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// mine\n");

        (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'UPDATE', 'destination' => 'app/Owned/A.php'],
        ]);

        $row = DB::table('engineering_recoveries')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame($manifest->fingerprint(), $row->fingerprint);
        $this->assertSame(RecoveryService::RECOVERED, $row->status);
    }

    public function test_the_recovery_fingerprint_changes_with_the_files_it_covers(): void
    {
        $this->write('app/Owned/A.php', "<?php\n// a\n");
        $one = $this->manifest(['app/Owned/A.php' => "<?php\n// x\n"]);
        $two = $this->manifest(['app/Owned/A.php' => "<?php\n// y\n"]);

        $this->assertNotSame($one->fingerprint(), $two->fingerprint(),
            'a human approving a manual recovery must be approving one exact set of restorations');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function plan(array $paths): array
    {
        return (new VerificationPlan($this->repo))->build($paths, 'phpunit.e888.xml');
    }

    private function manifest(array $changeSet): RecoveryManifest
    {
        return RecoveryManifest::capture(
            $this->repo, 'wf', 'task-uuid', 'candidate-uuid', 'fingerprint',
            $changeSet,
            fn () => 'OWNED',
            fn () => false,
            '.engineer888/recovery/test'
        );
    }

    private function write(string $path, ?string $content): void
    {
        @mkdir(dirname($this->repo . '/' . $path), 0775, true);
        if ($content !== null) { file_put_contents($this->repo . '/' . $path, $content); }
    }

    /** @return array{0:object,1:object,2:string} */
    private function approvedCandidate(string $content): array
    {
        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'fixture-project', 'name' => 'Fixture Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask($project->key, [
            'title' => 'Recovery fixture', 'description' => 'A fixture for the recovery tests.',
            'change_set' => [],
        ]);

        config([
            'engineer888_reasoning.provider' => 'scripted',
            'engineer888_reasoning.scripted.response' => [
                'problem_understanding' => 'x', 'assumptions' => [], 'unknowns' => [],
                'implementation_strategy' => 'x',
                'files_affected' => [['path' => 'app/Owned/A.php', 'action' => 'create', 'why' => 'x']],
                'migrations' => ['required' => false, 'detail' => 'none'],
                'risks' => [], 'testing_strategy' => 'x', 'rollback' => 'x',
                'file_changes' => [['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => $content]],
                // Sprint 10: an executable change accounts for its coverage.
                // This fixture's subject is the recovery machinery, which this
                // suite already covers, so it names that rather than inventing
                // a test for a two-line fixture class.
                'test_coverage' => [
                    'behaviour_changed'    => 'installs a fixture used to exercise recovery',
                    'test_files_proposed'  => [],
                    'existing_tests'       => ['tests/Feature/Engineer888/ExecutionRecoveryTest.php'],
                    'expected_assertions'  => ['a failed verification leaves nothing behind'],
                    'regression_prevented' => 'unverified code surviving a halted workflow',
                    'test_database'        => 'levelup_e888_test',
                    'full_suite_required'  => false,
                    'gaps'                 => [],
                    'classification'       => 'TESTED',
                    'confidence'           => 'high',
                ],
                'confidence' => 'high', 'confidence_basis' => 'x',
            ],
        ]);

        $outcome = ReasoningEngine::make()->propose($project, $task);
        $uuid = (string) $outcome->candidateUuid;

        $ledger = new ApprovalLedger();
        $ledger->approve($uuid, $ledger->forCandidateUuid($uuid)->fingerprint, 1, 'Mark (CEO)');

        return [$task, $project, $uuid];
    }

    private function context(object $task, object $project, array $owned): WorkflowContext
    {
        $context = new WorkflowContext(
            DB::table('engineering_tasks')->find($task->id), $project, $this->repo
        );
        $context->set('files.owned', $owned);

        return $context;
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) { @unlink($path); return; }
        @chmod($path, 0775);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
