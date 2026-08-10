<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovedPreImageGuard;
use App\Core\Engineer888\Approval\DriftVerdict;
use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Workflow\RepositoryExecutionLock;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Approval binds a repository state, not just a set of bytes to write.
 *
 * THE MEASURED DEFECT. In E1-B a candidate was approved, its target rewritten
 * underneath it, and `ApprovalLedger::enforce()` asked again — permitted, no
 * refusals. Correct for what enforce() does, and useless as a safety property:
 * the approved bytes would have installed cleanly over a file that was no longer
 * the file anybody reviewed.
 *
 * WHAT THESE TESTS ARE FOR. Mostly the refusals, and mostly the *timing* of
 * them. A drift check that refuses after RecoveryManifest has taken its backups
 * has already written to the repository, so several tests assert on absence:
 * no backup directory, no `.installing` file, no provenance record, not one
 * changed byte. The scope tests matter just as much in the other direction —
 * this tree carries hundreds of unrelated dirty files at any moment, and a guard
 * that refused on those would make every approval unexecutable.
 */
class PreImageDriftTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        Cache::flush();

        $this->repo = sys_get_temp_dir() . '/e888-drift-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        @mkdir(dirname($this->repo . '/' . $declared), 0775, true);
        file_put_contents($this->repo . '/' . $declared, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'drift-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        // E1-G: coverage is resolved against the PROJECT repository, so a test
        // this fixture cites has to exist in the fixture's own tree.
        @mkdir($this->repo . '/tests/Feature/Engineer888', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/Engineer888/CommandCenterTest.php', "<?php\n");
        file_put_contents($this->repo . '/tests/Feature/Engineer888/ExecutionRecoveryTest.php', "<?php\n");

        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q && git config user.email e888@test && git config user.name e888 2>&1');

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── UPDATE ──────────────────────────────────────────────────────────

    public function test_an_unchanged_target_permits(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// the bytes the model read\n");
        $candidate = $this->reasonUpdate();

        $verdict = $this->guard()->check($candidate);

        $this->assertTrue($verdict->permitted, $verdict->detail());
        $this->assertSame(1, $verdict->checked);
        $this->assertSame([], $verdict->findings);
    }

    public function test_a_changed_target_refuses(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// the bytes the model read\n");
        $candidate = $this->reasonUpdate();

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// a parallel session got here first\n");

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertSame(DriftVerdict::DRIFT, $verdict->summary);
        $this->assertSame([DriftVerdict::TARGET_CHANGED], $verdict->codes());
        $this->assertSame(['app/Owned/A.php'], $verdict->paths());
    }

    public function test_the_comparison_is_exact_to_the_byte(): void
    {
        $original = "<?php\n// one byte matters\n";
        $this->seedOwned('app/Owned/A.php', $original);
        $candidate = $this->reasonUpdate();

        // A single trailing newline. Nothing a reviewer would notice; enough to
        // mean the diff they read is not the diff that would be applied.
        file_put_contents($this->repo . '/app/Owned/A.php', $original . "\n");

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertSame([DriftVerdict::TARGET_CHANGED], $verdict->codes());
    }

    public function test_a_missing_target_refuses(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// here for now\n");
        $candidate = $this->reasonUpdate();

        unlink($this->repo . '/app/Owned/A.php');

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertSame([DriftVerdict::TARGET_MISSING], $verdict->codes());
        $this->assertStringContainsString('somebody deleted', $verdict->detail());
    }

    public function test_an_unreadable_target_refuses_rather_than_assumes(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// about to become unreadable\n");
        $candidate = $this->reasonUpdate();

        @chmod($this->repo . '/app/Owned/A.php', 0000);

        if (is_readable($this->repo . '/app/Owned/A.php')) {
            @chmod($this->repo . '/app/Owned/A.php', 0644);
            $this->markTestSkipped('running as a user for whom nothing is unreadable');
        }

        $verdict = $this->guard()->check($candidate);
        @chmod($this->repo . '/app/Owned/A.php', 0644);

        $this->assertFalse($verdict->permitted);
        $this->assertSame([DriftVerdict::TARGET_UNREADABLE], $verdict->codes());
    }

    // ── CREATE ──────────────────────────────────────────────────────────

    public function test_a_target_that_is_still_absent_permits(): void
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);
        $candidate = $this->reasonCreate();

        $verdict = $this->guard()->check($candidate);

        $this->assertTrue($verdict->permitted, $verdict->detail());
    }

    public function test_a_target_created_after_approval_refuses(): void
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);
        $candidate = $this->reasonCreate();

        file_put_contents($this->repo . '/app/Owned/New.php', "<?php\n// somebody else created this\n");

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertSame([DriftVerdict::TARGET_EXISTS], $verdict->codes());
        $this->assertStringContainsString('overwrite something created after the approval', $verdict->detail());
    }

    /**
     * The limitation E1-A surfaced, held closed rather than widened.
     *
     * SourceGrounding cannot prove absence for a create whose parent directory
     * does not exist — it has no directory to resolve containment against — so
     * such a candidate carries no grounded absence record. E1-D does not create
     * the directory to make the check possible, and does not invent the record.
     * It refuses.
     */
    public function test_a_create_whose_absence_was_never_grounded_refuses(): void
    {
        // No app/Owned directory: grounding refuses the path, so no evidence.
        $candidate = $this->reasonCreate(withDirectory: false);

        $this->assertFalse($candidate->hasBoundPreImage(),
            'the fixture must actually produce an unbound candidate');

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertSame(DriftVerdict::UNBOUND, $verdict->summary);
        $this->assertSame([DriftVerdict::PRE_IMAGE_UNBOUND], $verdict->codes());
    }

    // ── LEGACY ──────────────────────────────────────────────────────────

    public function test_a_legacy_candidate_refuses_and_nothing_is_fabricated(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// present today\n");

        $legacy = new CandidateImplementation(
            ['file_changes' => [['path' => 'app/Owned/A.php', 'action' => 'update', 'content' => "<?php\n"]]],
            'scripted', 'fixture', 'req'   // no pre-image: the shape of every pre-E1-A row
        );

        $verdict = $this->guard()->check($legacy);

        $this->assertFalse($verdict->permitted);
        $this->assertSame(DriftVerdict::UNBOUND, $verdict->summary);

        // The file is sitting right there and its hash would have been trivial
        // to take. Taking it would have produced a pre-image no model ever saw.
        $this->assertSame([], $legacy->preImage);
        $this->assertStringNotContainsString(hash('sha256', "<?php\n// present today\n"),
            json_encode($verdict->toArray()));
    }

    public function test_a_candidate_whose_evidence_misses_a_file_refuses(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// a\n");
        $this->seedOwned('app/Owned/B.php', "<?php\n// b\n");

        // Evidence for A only, while the candidate changes A and B.
        $partial = new CandidateImplementation(
            ['file_changes' => [
                ['path' => 'app/Owned/A.php', 'action' => 'update', 'content' => "<?php\n"],
                ['path' => 'app/Owned/B.php', 'action' => 'update', 'content' => "<?php\n"],
            ]],
            'scripted', 'fixture', 'req',
            [['path' => 'app/Owned/A.php', 'operation' => 'update', 'grounded' => true,
              'existed' => true, 'pre_image_sha256' => hash('sha256', "<?php\n// a\n"),
              'pre_image_bytes' => 11]]
        );

        $verdict = $this->guard()->check($partial);

        $this->assertFalse($verdict->permitted);
        $this->assertSame(DriftVerdict::UNBOUND, $verdict->summary);
    }

    // ── scope ───────────────────────────────────────────────────────────

    public function test_unrelated_repository_dirt_does_not_refuse(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// the approved target\n");
        $candidate = $this->reasonUpdate();

        // The shape of the real tree: other engineers' work, everywhere.
        @mkdir($this->repo . '/app/Studio', 0775, true);
        @mkdir($this->repo . '/app/Sarah', 0775, true);
        file_put_contents($this->repo . '/app/Studio/Editor.php', "<?php\n// another session\n");
        file_put_contents($this->repo . '/app/Sarah/Brain.php', "<?php\n// a third session\n");
        file_put_contents($this->repo . '/composer.json', '{"dirty": true}');

        $verdict = $this->guard()->check($candidate);

        $this->assertTrue($verdict->permitted,
            'the approval binds specific targets, not the cleanliness of the whole tree: ' . $verdict->detail());
    }

    public function test_only_approved_targets_enter_the_evidence(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        $this->seedOwned('app/Owned/Untouched.php', "<?php\n// not part of this change\n");
        $candidate = $this->reasonUpdate();

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// moved\n");
        file_put_contents($this->repo . '/app/Owned/Untouched.php', "<?php\n// also moved\n");

        $verdict = $this->guard()->check($candidate);

        $this->assertSame(['app/Owned/A.php'], $verdict->paths());
        $this->assertStringNotContainsString('Untouched', json_encode($verdict->toArray()));
        $this->assertStringNotContainsString($this->repo, json_encode($verdict->toArray()),
            'refusals carry repository-relative paths, never the server layout');
    }

    /** @dataProvider escapingPaths */
    public function test_a_path_that_escapes_the_repository_fails_safely(string $path): void
    {
        $candidate = new CandidateImplementation(
            ['file_changes' => [['path' => $path, 'action' => 'update', 'content' => "<?php\n"]]],
            'scripted', 'fixture', 'req',
            [['path' => $path, 'operation' => 'update', 'grounded' => true, 'existed' => true,
              'pre_image_sha256' => str_repeat('a', 64), 'pre_image_bytes' => 1]]
        );

        $verdict = $this->guard()->check($candidate);

        $this->assertFalse($verdict->permitted);
        $this->assertContains($verdict->codes()[0],
            [DriftVerdict::PATH_ESCAPES, DriftVerdict::TARGET_MISSING, DriftVerdict::TARGET_CHANGED]);
    }

    public static function escapingPaths(): array
    {
        return [
            'traversal' => ['app/Owned/../../etc/passwd'],
            'absolute'  => ['/etc/passwd'],
            'parent'    => ['../outside.php'],
        ];
    }

    public function test_a_symlink_pointing_outside_the_repository_refuses(): void
    {
        $outside = sys_get_temp_dir() . '/e888-drift-outside-' . getmypid() . '.php';
        file_put_contents($outside, "<?php\n// not in the repository\n");

        @mkdir($this->repo . '/app/Owned', 0775, true);
        @symlink($outside, $this->repo . '/app/Owned/Link.php');

        if (! is_link($this->repo . '/app/Owned/Link.php')) {
            @unlink($outside);
            $this->markTestSkipped('symlinks are not available here');
        }

        $candidate = new CandidateImplementation(
            ['file_changes' => [['path' => 'app/Owned/Link.php', 'action' => 'update', 'content' => "<?php\n"]]],
            'scripted', 'fixture', 'req',
            [['path' => 'app/Owned/Link.php', 'operation' => 'update', 'grounded' => true, 'existed' => true,
              'pre_image_sha256' => hash('sha256', "<?php\n// not in the repository\n"), 'pre_image_bytes' => 33]]
        );

        $verdict = $this->guard()->check($candidate);
        @unlink($outside);

        $this->assertFalse($verdict->permitted,
            'a symlink escape must refuse even when the bytes behind it match');
        $this->assertSame([DriftVerdict::PATH_ESCAPES], $verdict->codes());
    }

    // ── purity ──────────────────────────────────────────────────────────

    public function test_the_guard_writes_nothing_at_all(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        $candidate = $this->reasonUpdate();
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// drifted\n");

        $before = $this->treeDigest();
        $this->guard()->check($candidate);
        $after = $this->treeDigest();

        $this->assertSame($before, $after, 'the guard runs at the last zero-write moment; it must stay one');
    }

    public function test_the_guard_mutates_neither_the_candidate_nor_the_approval(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        $candidate = $this->reasonUpdate();
        $uuid = (string) DB::table('engineering_candidates')->orderByDesc('id')->value('uuid');

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// drifted\n");

        $candidateBefore = (array) DB::table('engineering_candidates')->where('uuid', $uuid)->first();
        $approvalBefore = (array) DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)->first();

        $this->guard()->check($candidate);

        $this->assertEquals($candidateBefore,
            (array) DB::table('engineering_candidates')->where('uuid', $uuid)->first());
        $this->assertEquals($approvalBefore,
            (array) DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)->first());
        $this->assertSame($candidate->preImage,
            ReasoningEngine::make()->store()->candidateByUuid($uuid)->preImage);
    }

    // ── through IMPLEMENT ───────────────────────────────────────────────

    public function test_drift_refuses_before_a_single_byte_is_written(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        [$task] = $this->approvedUpdateTask();

        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// drifted after approval\n");
        $drifted = (string) file_get_contents($this->repo . '/app/Owned/A.php');

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('IMPLEMENT', $result['halted_at']);

        // The three things a write leaves behind, all absent.
        $this->assertDirectoryDoesNotExist($this->repo . '/.engineer888/recovery',
            'RecoveryManifest::capture() must never have run — it writes backups');
        $this->assertFileDoesNotExist($this->repo . '/.engineer888/installed.json',
            'SafeInstaller records provenance on every write');
        $this->assertSame([], glob($this->repo . '/app/Owned/*.installing') ?: [],
            'no atomic-write temp file may exist');

        $this->assertSame($drifted, file_get_contents($this->repo . '/app/Owned/A.php'),
            'not one byte of the target may change');
        $this->assertNull($result['recovery'], 'there was nothing to recover, because nothing was written');
    }

    public function test_the_implement_stage_records_the_drift_and_still_ran_enforcement(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        [$task] = $this->approvedUpdateTask();
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// drifted\n");

        $result = (new WorkflowEngine())->run($task->uuid);

        $implement = null;
        foreach ($result['stages'] as $stage) {
            if ($stage['stage'] === 'IMPLEMENT') { $implement = $stage['result']; }
        }

        $this->assertNotNull($implement);
        $this->assertSame('blocked', $implement->status,
            'drift is a human repair, not a defect: the task waits rather than failing');
        $this->assertStringContainsString(DriftVerdict::DRIFT, $implement->summary);

        $context = $result['context'];
        $this->assertNotNull($context->get('implement.enforcement'),
            'ApprovalLedger::enforce must still have run and been recorded');
        $this->assertTrue($context->get('implement.enforcement')['permitted'],
            'the candidate still matched its approval — only the repository moved');
        $this->assertFalse($context->get('implement.drift')['permitted']);
        $this->assertNull($context->get('implement.recovery_manifest'),
            'the manifest is captured after the drift gate, so it must not exist');
    }

    public function test_an_unchanged_target_still_reaches_the_write(): void
    {
        @mkdir($this->repo . '/app/Owned', 0775, true);
        [$task] = $this->approvedCreateTask();

        $result = (new WorkflowEngine())->run($task->uuid);

        $context = $result['context'];
        $this->assertTrue($context->get('implement.drift')['permitted'], 'the guard must not block a clean tree');
        $this->assertNotNull($context->get('implement.recovery_manifest'),
            'a permitted candidate must reach RecoveryManifest::capture()');
    }

    public function test_a_changed_binding_still_refuses_before_the_drift_gate(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        [$task, $uuid] = $this->approvedUpdateTask();

        // Move the approval's recorded fingerprint: enforce() must catch this
        // first, independently of anything the repository is doing.
        DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)
            ->update(['fingerprint' => str_repeat('b', 64)]);

        $result = (new WorkflowEngine())->run($task->uuid);
        $context = $result['context'];

        $this->assertFalse($context->get('implement.enforcement')['permitted']);
        $this->assertNull($context->get('implement.drift'),
            'the drift gate is downstream of enforcement and must not have been reached');
    }

    // ── lock integration ────────────────────────────────────────────────

    public function test_the_drift_gate_runs_while_the_repository_lock_is_held(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        [$task] = $this->approvedUpdateTask();
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// drifted\n");

        $contendedAtImplement = null;

        DB::listen(function ($query) use (&$contendedAtImplement) {
            if ($contendedAtImplement !== null) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_task_stages`')) { return; }
            if (! in_array('IMPLEMENT', $query->bindings, true)) { return; }

            $probe = RepositoryExecutionLock::forRepository($this->repo, 'probe-at-implement');
            $contendedAtImplement = ! $probe->acquire();
            if (! $contendedAtImplement) { $probe->release(); }
        });

        (new WorkflowEngine())->run($task->uuid);

        $this->assertTrue($contendedAtImplement,
            'the check-then-write window must be closed: no other workflow may touch the tree '
            . 'between the drift decision and the install');
    }

    // ── regression ──────────────────────────────────────────────────────

    public function test_the_bound_fingerprint_is_unchanged_by_the_drift_gate(): void
    {
        $this->seedOwned('app/Owned/A.php', "<?php\n// approved\n");
        $candidate = $this->reasonUpdate();
        $uuid = (string) DB::table('engineering_candidates')->orderByDesc('id')->value('uuid');

        $stored = (string) DB::table('engineering_candidate_approvals')
            ->where('candidate_uuid', $uuid)->value('fingerprint');

        $task = DB::table('engineering_tasks')->orderByDesc('id')->first();
        $project = DB::table('engineering_projects')->orderByDesc('id')->first();

        $rebuilt = \App\Core\Engineer888\Approval\ApprovalBinding::forCandidate(
            $candidate, $uuid, (string) $task->uuid, (string) $project->key
        );

        $this->assertSame($stored, $rebuilt->fingerprint());
        $this->assertNotSame([], $rebuilt->extraParts, 'E1-A pre-image parts must still be bound');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function guard(): ApprovedPreImageGuard
    {
        return new ApprovedPreImageGuard($this->repo);
    }

    private function seedOwned(string $path, string $content): void
    {
        @mkdir(dirname($this->repo . '/' . $path), 0775, true);
        file_put_contents($this->repo . '/' . $path, $content);
    }

    /** Every file in the fixture tree, reduced to one hash. */
    private function treeDigest(): string
    {
        $entries = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $entries[] = $file->getPathname() . ':' . @hash_file('sha256', $file->getPathname());
            }
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    private function reasonUpdate(): CandidateImplementation
    {
        [$task, ] = $this->approvedUpdateTask();

        return ReasoningEngine::make()->approvedCandidate((int) $task->id);
    }

    private function reasonCreate(bool $withDirectory = true): CandidateImplementation
    {
        if ($withDirectory) { @mkdir($this->repo . '/app/Owned', 0775, true); }

        [$task, ] = $this->approvedCreateTask();

        $candidate = ReasoningEngine::make()->approvedCandidate((int) $task->id);
        $this->assertNotNull($candidate, 'the fixture must produce an approved candidate');

        return $candidate;
    }

    /** @return array{0:object,1:string} */
    private function approvedUpdateTask(): array
    {
        return $this->approvedTask('update', 'app/Owned/A.php', "<?php\n// the approved replacement\n");
    }

    /** @return array{0:object,1:string} */
    private function approvedCreateTask(): array
    {
        return $this->approvedTask('create', 'app/Owned/New.php', "<?php\n// a brand new file\n");
    }

    /** @return array{0:object,1:string} */
    private function approvedTask(string $action, string $path, string $content): array
    {
        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'drift-project', 'name' => 'Drift Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask('drift-project', [
            'title' => 'Drift fixture', 'description' => 'Exercises the pre-image drift gate.',
            'change_set' => [],
        ]);

        config([
            'engineer888_reasoning.provider' => 'scripted',
            'engineer888_reasoning.scripted.response' => [
                'problem_understanding' => 'A target under an owned path must change.',
                'assumptions' => [], 'unknowns' => [],
                'implementation_strategy' => 'Write the declared content.',
                'files_affected' => [['path' => $path, 'action' => $action, 'why' => 'the target']],
                'migrations' => ['required' => false, 'detail' => 'none'],
                'risks' => [], 'testing_strategy' => 'php -l', 'rollback' => 'SafeInstaller backup',
                'file_changes' => [['path' => $path, 'action' => $action, 'content' => $content]],
                'test_coverage' => [
                    'behaviour_changed' => 'installs a fixture used to exercise the drift gate',
                    'test_files_proposed' => [],
                    'existing_tests' => ['tests/Feature/Engineer888/ExecutionRecoveryTest.php'],
                    'expected_assertions' => ['an approved target that moved is refused'],
                    'regression_prevented' => 'installing over work nobody approved discarding',
                    'test_database' => 'levelup_e888_test', 'full_suite_required' => false,
                    'gaps' => [], 'classification' => 'TESTED', 'confidence' => 'high',
                ],
                'confidence' => 'high', 'confidence_basis' => 'a single file',
            ],
        ]);

        $outcome = ReasoningEngine::make()->propose($project, $task);
        $this->assertSame('VALIDATED', $outcome->status, json_encode($outcome->violations ?? []));

        $uuid = (string) $outcome->candidateUuid;
        $ledger = new ApprovalLedger();
        $ledger->approve($uuid, $ledger->forCandidateUuid($uuid)->fingerprint, 1, 'Mark (CEO)');

        return [$task, $uuid];
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
