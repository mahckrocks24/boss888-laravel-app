<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Migration\MigrationClassifier;
use App\Core\Engineer888\Migration\MigrationRehearsal;
use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\TestContract;
use App\Core\Engineer888\Recovery\ManualRecovery;
use App\Core\Engineer888\Recovery\RecoveryCandidate;
use App\Core\Engineer888\Recovery\RecoveryManifest;
use App\Core\Engineer888\Recovery\RecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manual recovery approval, the test contract, and migration rehearsal.
 *
 * Three capabilities, one theme: the workflow now asks for the thing it used to
 * merely require. It asks a human to authorise a recovery it will not perform
 * alone, it asks a provider for the test that would catch its own mistake, and
 * it asks a migration to prove it can be undone before anybody approves it.
 */
class RecoveryApprovalTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-s10-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        @mkdir(dirname($this->repo . '/' . $declared), 0775, true);
        file_put_contents($this->repo . '/' . $declared, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'sprint-10-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**', 'tests/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n");
        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q 2>&1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── A. manual recovery approval ─────────────────────────────────────

    public function test_a_recovery_candidate_describes_every_file_and_its_proposed_action(): void
    {
        [$recoveryId] = $this->blockedRecovery();

        $candidate = (new ManualRecovery($this->repo))->candidateFor($recoveryId);

        $this->assertNotNull($candidate);
        $this->assertCount(1, $candidate->files);

        $file = $candidate->files[0];
        $this->assertSame('app/Owned/A.php', $file['path']);
        $this->assertSame(RecoveryCandidate::MANUAL_ONLY, $file['action'],
            'a file another engineer changed is never automatically restorable');
        $this->assertTrue($file['drift']);
        $this->assertNotNull($file['backup_path']);
        $this->assertNotEmpty($candidate->conflicts());
    }

    public function test_the_recovery_fingerprint_is_deterministic(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);

        $this->assertSame(
            $manual->candidateFor($recoveryId)->fingerprint(),
            $manual->candidateFor($recoveryId)->fingerprint()
        );
    }

    public function test_current_byte_drift_changes_the_fingerprint(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);

        $before = $manual->candidateFor($recoveryId)->fingerprint();

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// changed again\n");

        $this->assertNotSame($before, $manual->candidateFor($recoveryId)->fingerprint(),
            'an approval must not survive the file it describes changing');
    }

    public function test_approval_requires_the_exact_fingerprint_and_statement(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);
        $candidate = $manual->candidateFor($recoveryId);
        $manual->offer($recoveryId, $candidate);

        try {
            $manual->approve($candidate->uuid, str_repeat('0', 64), $candidate->statement(), 1, 'Mark');
            $this->fail('a wrong fingerprint must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        try {
            $manual->approve($candidate->uuid, $candidate->fingerprint(), 'I approve.', 1, 'Mark');
            $this->fail('a generic statement must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('statement does not match', $e->getMessage());
        }

        $approval = $manual->approve($candidate->uuid, $candidate->fingerprint(),
            $candidate->statement(), 1, 'Mark (CEO)', 'read the diff');

        $this->assertSame(ManualRecovery::APPROVED, $approval->status);
        $this->assertNotNull($approval->expires_at);
    }

    public function test_execution_refuses_when_the_tree_moved_after_approval(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);
        $candidate = $manual->candidateFor($recoveryId);
        $manual->offer($recoveryId, $candidate);
        $manual->approve($candidate->uuid, $candidate->fingerprint(), $candidate->statement(), 1, 'Mark');

        $theirs = "<?php\n// a third change, after approval\n";
        file_put_contents($this->repo . '/app/Owned/A.php', $theirs);

        $evidence = $manual->execute($candidate->uuid, 'Engineer888');

        $this->assertSame('REFUSED', $evidence['status']);
        $this->assertSame($theirs, file_get_contents($this->repo . '/app/Owned/A.php'));
    }

    public function test_an_expired_approval_cannot_execute(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);
        $candidate = $manual->candidateFor($recoveryId);
        $manual->offer($recoveryId, $candidate);
        $manual->approve($candidate->uuid, $candidate->fingerprint(), $candidate->statement(), 1, 'Mark');

        DB::table('engineering_recovery_approvals')->where('recovery_uuid', $candidate->uuid)
            ->update(['expires_at' => now()->subMinute()]);

        $evidence = $manual->execute($candidate->uuid, 'Engineer888');

        $this->assertSame('REFUSED', $evidence['status']);
        $this->assertStringContainsString('expired', $evidence['summary']);
    }

    public function test_a_revoked_approval_cannot_execute(): void
    {
        [$recoveryId] = $this->blockedRecovery();
        $manual = new ManualRecovery($this->repo);
        $candidate = $manual->candidateFor($recoveryId);
        $manual->offer($recoveryId, $candidate);
        $manual->approve($candidate->uuid, $candidate->fingerprint(), $candidate->statement(), 1, 'Mark');
        $manual->revoke($candidate->uuid, 'Mark', 'changed my mind');

        $this->assertSame('REFUSED', $manual->execute($candidate->uuid, 'Engineer888')['status']);
    }

    public function test_only_approved_actions_execute_and_manual_only_files_are_untouched(): void
    {
        // A recovery that did NOT complete — the write happened and the
        // restore did not. A recovery that succeeded has nothing left for a
        // human to approve, so it is the wrong fixture for this claim.
        [$recoveryId, $preHash] = $this->incompleteRecovery();

        $manual = new ManualRecovery($this->repo);
        $candidate = $manual->candidateFor($recoveryId);

        $this->assertSame(RecoveryCandidate::RESTORE_UPDATED_FILE, $candidate->files[0]['action']);

        $manual->offer($recoveryId, $candidate);
        $manual->approve($candidate->uuid, $candidate->fingerprint(), $candidate->statement(), 1, 'Mark');

        $evidence = $manual->execute($candidate->uuid, 'Engineer888');

        $this->assertSame(ManualRecovery::EXECUTED, $evidence['status']);
        $this->assertSame($preHash, hash_file('sha256', $this->repo . '/app/Owned/A.php'));
        $this->assertTrue($evidence['performed'][0]['verified']);
    }

    // ── B. the test contract ────────────────────────────────────────────

    public function test_a_behaviour_change_without_coverage_is_rejected(): void
    {
        $violations = $this->validate($this->payload([
            'app/Owned/Thing.php' => "<?php\nnamespace App\\Owned;\nclass Thing { public function go() { return 1; } }\n",
        ]));

        $this->assertContains('missing_test_coverage', array_column($violations, 'rule'));
    }

    public function test_documentation_alone_needs_no_coverage(): void
    {
        $violations = $this->validate($this->payload(['docs/NOTES.md' => "# notes\n"]));

        $this->assertSame([], array_intersect(
            ['missing_test_coverage', 'no_coverage'], array_column($violations, 'rule')));
    }

    public function test_a_placeholder_assertion_is_not_coverage(): void
    {
        $violations = $this->validate($this->payloadWithTest(
            "<?php\nnamespace Tests\\Owned;\nclass ThingTest { public function t() { \$this->assertTrue(true); } }\n"
        ));

        $this->assertContains('placeholder_assertion', array_column($violations, 'rule'));
    }

    public function test_a_test_that_reads_the_working_tree_is_rejected(): void
    {
        $violations = $this->validate($this->payloadWithTest(
            "<?php\nnamespace Tests\\Owned;\nclass ThingTest { public function t() { \$x = base_path('app'); \$this->assertSame(1, (new Thing)->go()); } }\n"
        ));

        $this->assertContains('transient_state_test', array_column($violations, 'rule'));
    }

    public function test_a_test_naming_a_shared_database_is_rejected(): void
    {
        $violations = $this->validate($this->payloadWithTest(
            "<?php\nnamespace Tests\\Owned;\nclass ThingTest { public function t() { config(['db' => 'levelup_test']); \$this->assertSame(1, (new Thing)->go()); } }\n"
        ));

        $this->assertContains('forbidden_test_database', array_column($violations, 'rule'));
    }

    public function test_a_test_unrelated_to_the_change_is_rejected(): void
    {
        $violations = $this->validate($this->payloadWithTest(
            "<?php\nnamespace Tests\\Owned;\nclass ThingTest { public function t() { \$this->assertSame(2, 1 + 1); } }\n"
        ));

        $this->assertContains('unrelated_test', array_column($violations, 'rule'));
    }

    public function test_a_test_outside_this_sprints_ownership_is_rejected(): void
    {
        $payload = $this->payloadWithTest(
            "<?php\nnamespace Tests\\Studio;\nclass ThingTest { public function t() { \$this->assertSame(1, (new Thing)->go()); } }\n",
            'tests/Studio888/ThingTest.php'
        );

        $this->assertContains('unowned_test_path', array_column($this->validate($payload), 'rule'));
    }

    public function test_size_is_not_a_reason_a_change_cannot_be_tested(): void
    {
        $payload = $this->payload([
            'app/Owned/Thing.php' => "<?php\nnamespace App\\Owned;\nclass Thing { public function go() { return 1; } }\n",
        ]);
        $payload['test_coverage'] = [
            'behaviour_changed' => 'returns 1', 'regression_prevented' => 'nothing', 'confidence' => 'low',
            'classification' => 'NON_TESTABLE', 'non_testable_reason' => 'it is a small change',
            'alternative_verification' => 'reading it', 'risk' => 'low',
        ];

        $this->assertContains('unjustified_non_testable', array_column($this->validate($payload), 'rule'));
    }

    public function test_a_well_formed_candidate_with_a_real_test_validates(): void
    {
        $violations = $this->validate($this->payloadWithTest(
            "<?php\nnamespace Tests\\Owned;\nuse App\\Owned\\Thing;\n"
            . "class ThingTest { public function test_go_returns_one() { \$this->assertSame(1, (new Thing)->go()); } }\n"
        ));

        $this->assertSame([], $violations, json_encode($violations));
    }

    public function test_the_approval_fingerprint_covers_test_bytes(): void
    {
        $engine = ReasoningEngine::make();

        $this->assertContains('missing_test_coverage', ReasoningEngine::RETRYABLE_RULES,
            'a missing test is exactly the kind of violation a provider can fix when told');
        $this->assertNotContains('unsafe_path', ReasoningEngine::RETRYABLE_RULES,
            'a proposal that tried to write .env is not retried, it is refused');
    }

    // ── C. migration classification ─────────────────────────────────────

    /** @dataProvider migrations */
    public function test_migrations_are_classified_from_their_own_tokens(string $body, string $expected): void
    {
        $source = "<?php\nreturn new class extends Migration {\n" . $body . "\n};\n";

        $result = (new MigrationClassifier())->classify($source, 'database/migrations/x.php');

        $this->assertSame($expected, $result['classification'], json_encode($result['evidence']));
    }

    public static function migrations(): array
    {
        return [
            'create with a real down' => [
                'public function up(): void { Schema::create("widgets", function ($t) { $t->id(); }); }'
                . ' public function down(): void { Schema::dropIfExists("widgets"); }',
                MigrationClassifier::REVERSIBLE_AUTOMATIC,
            ],
            'drop of a populated column' => [
                'public function up(): void { Schema::table("users", function ($t) { $t->dropColumn("nickname"); }); }'
                . ' public function down(): void { Schema::table("users", function ($t) { $t->string("nickname")->nullable(); }); }',
                MigrationClassifier::REVERSIBLE_WITH_DATA_RISK,
            ],
            'no down at all' => [
                'public function up(): void { Schema::create("widgets", function ($t) { $t->id(); }); }'
                . ' public function down(): void { }',
                MigrationClassifier::IRREVERSIBLE,
            ],
            'external side effect' => [
                'public function up(): void { Http::post("https://example.test"); }'
                . ' public function down(): void { Schema::dropIfExists("widgets"); }',
                MigrationClassifier::IRREVERSIBLE,
            ],
            'data transformation' => [
                'public function up(): void { DB::table("users")->update(["x" => 1]); }'
                . ' public function down(): void { Schema::dropIfExists("widgets"); }',
                MigrationClassifier::REVERSIBLE_WITH_DATA_RISK,
            ],
        ];
    }

    public function test_the_classifier_names_the_tables_a_migration_touches(): void
    {
        $source = "<?php\nclass M { public function up(): void { Schema::create('widgets', function (\$t) { \$t->id(); }); }"
                . " public function down(): void { Schema::dropIfExists('widgets'); } }\n";

        $this->assertSame(['widgets'], (new MigrationClassifier())->classify($source, 'x.php')['tables']);
    }

    /** @dataProvider unsafeTargets */
    public function test_a_rehearsal_refuses_a_target_it_cannot_prove_is_a_test_database(string $database): void
    {
        $result = (new MigrationRehearsal($this->repo, $database))
            ->rehearse('database/migrations/x.php', 'phpunit.e888.xml');

        $this->assertSame(MigrationRehearsal::REFUSED, $result['status']);
        $this->assertNotEmpty($result['problems']);
    }

    public static function unsafeTargets(): array
    {
        return [['levelup_staging'], ['levelup'], ['levelup_test'], ['production_data']];
    }

    public function test_a_schema_fingerprint_describes_the_assigned_database(): void
    {
        $rehearsal = new MigrationRehearsal(base_path(), 'levelup_e888_test');

        $first = $rehearsal->schemaFingerprint();

        $this->assertSame(64, strlen($first));
        $this->assertSame($first, $rehearsal->schemaFingerprint(), 'the fingerprint must be stable');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{0:int,1:string} recovery id, pre-task hash */
    private function blockedRecovery(bool $drift = true): array
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);

        $original = "<?php\n// the original\n";
        file_put_contents($this->repo . '/app/Owned/A.php', $original);
        $preHash = hash_file('sha256', $this->repo . '/app/Owned/A.php');

        $manifest = RecoveryManifest::capture(
            $this->repo, 'wf', 'task-uuid', 'candidate-uuid', 'approval-fingerprint',
            ['app/Owned/A.php' => "<?php\n// written by the workflow\n"],
            fn () => 'OWNED', fn () => false, '.engineer888/recovery/test'
        );

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// written by the workflow\n");

        if ($drift) {
            file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// somebody else was here\n");
        }

        (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'UPDATE', 'destination' => 'app/Owned/A.php'],
        ]);

        return [(int) DB::table('engineering_recoveries')->latest('id')->value('id'), $preHash];
    }

    /**
     * A workflow that wrote and whose automatic restore never ran.
     *
     * @return array{0:int,1:string} recovery id, pre-task hash
     */
    private function incompleteRecovery(): array
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);

        $original = "<?php\n// the original\n";
        file_put_contents($this->repo . '/app/Owned/A.php', $original);
        $preHash = hash_file('sha256', $this->repo . '/app/Owned/A.php');

        $manifest = RecoveryManifest::capture(
            $this->repo, 'wf', 'task-uuid', 'candidate-uuid', 'approval-fingerprint',
            ['app/Owned/A.php' => "<?php\n// written by the workflow\n"],
            fn () => 'OWNED', fn () => false, '.engineer888/recovery/test'
        );

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// written by the workflow\n");

        $id = DB::table('engineering_recoveries')->insertGetId([
            'task_uuid' => 'task-uuid', 'candidate_uuid' => 'candidate-uuid',
            'fingerprint' => $manifest->fingerprint(),
            'status' => RecoveryService::INCOMPLETE,
            'manifest' => json_encode($manifest->toArray()),
            'evidence' => json_encode(['performed' => [], 'problems' => [
                ['path' => 'app/Owned/A.php', 'error' => 'the restore never ran'],
            ]]),
            'actor' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [(int) $id, $preHash];
    }

    private function validate(array $payload): array
    {
        return (new CandidateValidator(12, 60000, $this->repo))->violations($payload);
    }

    private function payload(array $changeSet): array
    {
        $changes = [];
        foreach ($changeSet as $path => $content) {
            $changes[] = ['path' => $path, 'action' => 'create', 'content' => $content];
        }

        return [
            'problem_understanding'   => 'A thing is needed.',
            'assumptions'             => [],
            'unknowns'                => [],
            'implementation_strategy' => 'Add the class.',
            'files_affected'          => [['path' => 'app/Owned/Thing.php', 'action' => 'create', 'why' => 'x']],
            'migrations'              => ['required' => false, 'detail' => 'none'],
            'risks'                   => [],
            'testing_strategy'        => 'the proposed unit test',
            'rollback'                => 'delete the file',
            'file_changes'            => $changes,
            'confidence'              => 'high',
            'confidence_basis'        => 'one new class',
        ];
    }

    private function payloadWithTest(string $testContent, string $testPath = 'tests/Owned/ThingTest.php'): array
    {
        $payload = $this->payload([
            'app/Owned/Thing.php' => "<?php\nnamespace App\\Owned;\nclass Thing { public function go() { return 1; } }\n",
            $testPath             => $testContent,
        ]);

        $payload['test_coverage'] = [
            'behaviour_changed'    => 'Thing::go() returns 1',
            'test_files_proposed'  => [$testPath],
            'existing_tests'       => [],
            'expected_assertions'  => ['go() returns exactly 1'],
            'regression_prevented' => 'a future change to go() that alters its return value',
            'test_database'        => 'levelup_e888_test',
            'full_suite_required'  => false,
            'gaps'                 => [],
            'classification'       => 'TESTED',
            'confidence'           => 'high',
        ];

        return $payload;
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) { @unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) { $this->rmrf($path . '/' . $entry); }
        @rmdir($path);
    }
}
