<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Audit\ExecutionAttempt;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Recovery\DurableRecovery;
use App\Core\Engineer888\Recovery\RecoveryManifest;
use App\Core\Engineer888\Recovery\RecoveryService;
use App\Core\Engineer888\Workflow\RepositoryExecutionLock;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * An execution that can be reconstructed after the process that ran it is gone.
 *
 * THE CASE EVERYTHING ELSE MISSED. Every earlier control assumed the process
 * survives to handle its own failure. The recovery manifest lived in a
 * `WorkflowContext` — an in-memory array — and the stage trail is deleted at the
 * start of the next run, so a SIGKILL between the install and the halt handler
 * left files changed and no record anywhere of what they had been. The run that
 * would investigate it was the run that erased the evidence.
 *
 * So the tests that matter here are the ones about absence and survival: that
 * the manifest is on disk in the database BEFORE the first byte, that a refusal
 * to store it stops the write entirely, and that killing the process mid-flight
 * leaves something an operator can act on.
 *
 * IMMUTABILITY IS ENFORCED IN CODE, AND THESE TESTS SAY SO. There is no database
 * trigger; a hand-written UPDATE would still succeed. What is proved is that the
 * writers refuse.
 */
class ExecutionAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        Cache::flush();

        $this->repo = sys_get_temp_dir() . '/e888-audit-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        @mkdir(dirname($this->repo . '/' . $declared), 0775, true);
        file_put_contents($this->repo . '/' . $declared, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'audit-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        file_put_contents($this->repo . '/README.md', "fixture repository\n");
        // E1-G: coverage is resolved against the PROJECT repository, so a test
        // this fixture cites has to exist in the fixture's own tree.
        @mkdir($this->repo . '/tests/Feature/Engineer888', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/Engineer888/CommandCenterTest.php', "<?php\n");
        file_put_contents($this->repo . '/tests/Feature/Engineer888/ExecutionRecoveryTest.php', "<?php\n");


        // Committed, unlike the other fixtures: a repository with no commits has
        // no HEAD at all, and this suite asserts on the commit an execution ran
        // against. The unborn case is pinned separately below.
        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q'
            . ' && git config user.email e888@test && git config user.name e888'
            . ' && git add README.md && git commit -q -m "fixture baseline" 2>&1');

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the attempt row ─────────────────────────────────────────────────

    public function test_a_run_opens_exactly_one_attempt(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $attempts = DB::table('engineering_execution_attempts')->get();

        $this->assertCount(1, $attempts);
        $this->assertSame((string) $task->uuid, $attempts[0]->task_uuid);
        $this->assertSame($this->repo, $attempts[0]->repository_path);
        $this->assertNotNull($attempts[0]->started_at);
        $this->assertNotNull($attempts[0]->worker);
    }

    public function test_the_attempt_records_the_lock_it_ran_under(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_execution_attempts')->first();
        $expected = RepositoryExecutionLock::forRepository($this->repo, 'probe');

        $this->assertSame($expected->key, $row->lock_key,
            'the audit must name the lock the execution held, not a lock of its own');
        $this->assertNotNull($row->lock_owner);
        $this->assertStringContainsString((string) $task->uuid, (string) $row->lock_owner);
    }

    public function test_the_attempt_binds_the_candidate_approval_and_pre_image(): void
    {
        [$task, , $uuid] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_execution_attempts')->first();
        $approval = (new ApprovalLedger())->forCandidateUuid($uuid);

        $this->assertSame($uuid, $row->candidate_uuid);
        $this->assertSame((int) $approval->id, (int) $row->approval_id);
        $this->assertSame($approval->fingerprint, $row->approval_fingerprint);
        $this->assertNotNull($row->pre_image_fingerprint,
            'the E1-A pre-image identity belongs in the execution record');
        $this->assertSame(['app/Owned/A.php'], json_decode((string) $row->targets, true));
    }

    public function test_before_and_after_hashes_are_recorded(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_execution_attempts')->first();

        $this->assertNotNull($row->repository_head_before, 'the commit the tree was on');
        $this->assertNotNull($row->targets_hash_before);
        $this->assertNotNull($row->targets_hash_after);

        // The file was created and then removed by recovery, so the footprint
        // ends where it started — and the point is that both were measured.
        $this->assertSame(
            ExecutionAttempt::targetsHash($this->repo, ['app/Owned/A.php']),
            $row->targets_hash_after
        );
    }

    public function test_the_head_is_read_without_a_shell_and_unknown_stays_unknown(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/',
            (string) ExecutionAttempt::head($this->repo),
            'the commit must be resolved from .git directly, with no git process');

        // A repository that has been initialised but never committed to has no
        // HEAD. Recording "unknown" is the truth; inventing a commit would not be.
        $unborn = $this->repo . '-unborn';
        @mkdir($unborn, 0775, true);
        exec('cd ' . escapeshellarg($unborn) . ' && git init -q 2>&1');

        $this->assertNull(ExecutionAttempt::head($unborn));
        $this->assertNull(ExecutionAttempt::head($this->repo . '-does-not-exist'));

        $this->rmrf($unborn);
    }

    public function test_the_after_hash_differs_when_the_footprint_changed(): void
    {
        $absent = ExecutionAttempt::targetsHash($this->repo, ['app/Owned/A.php']);
        @mkdir($this->repo . '/app/Owned', 0775, true);
        file_put_contents($this->repo . '/app/Owned/A.php', "<?php\n// now present\n");
        $present = ExecutionAttempt::targetsHash($this->repo, ['app/Owned/A.php']);

        $this->assertNotSame($absent, $present,
            'a hash that could not tell absent from present would prove nothing');
    }

    public function test_a_successful_close_records_the_outcome(): void
    {
        [$task] = $this->approvedCreate();

        $result = (new WorkflowEngine())->run($task->uuid);
        $row = DB::table('engineering_execution_attempts')->first();

        $this->assertNotNull($row->finished_at);
        $this->assertNotSame(ExecutionAttempt::OPEN, $row->result);
        $this->assertSame($result['halted_at'], $row->workflow_state);
        $this->assertNotNull($row->failure, 'a halted run must say what stopped it');
    }

    public function test_a_recovered_run_is_recorded_as_recovered(): void
    {
        [$task] = $this->approvedCreate();

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame(RecoveryService::RECOVERED, $result['recovery']['status'] ?? null,
            'this fixture must write and then roll back, or the test proves nothing');
        $this->assertSame(ExecutionAttempt::RECOVERED,
            DB::table('engineering_execution_attempts')->value('result'));
    }

    public function test_a_blocked_run_is_recorded_as_blocked(): void
    {
        $task = $this->unapprovedTask();

        (new WorkflowEngine())->run($task->uuid);

        $this->assertSame(ExecutionAttempt::BLOCKED,
            DB::table('engineering_execution_attempts')->value('result'));
    }

    // ── immutability ────────────────────────────────────────────────────

    public function test_an_attempt_refuses_to_name_a_second_candidate(): void
    {
        [$task, $project, $uuid] = $this->approvedCreate();
        $candidate = ReasoningEngine::make()->approvedCandidate((int) $task->id);
        $approval = (new ApprovalLedger())->forCandidateUuid($uuid);

        $attempt = ExecutionAttempt::open(
            DB::table('engineering_tasks')->find($task->id),
            DB::table('engineering_projects')->find($project->id)
        );

        $attempt->bind($candidate, $uuid, $approval, ['app/Owned/A.php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/appended, never rewritten/');

        $attempt->bind($candidate, 'a-completely-different-candidate-uuid', $approval, ['app/Owned/A.php']);
    }

    public function test_binding_the_same_values_twice_is_not_a_rewrite(): void
    {
        [$task, $project, $uuid] = $this->approvedCreate();
        $candidate = ReasoningEngine::make()->approvedCandidate((int) $task->id);
        $approval = (new ApprovalLedger())->forCandidateUuid($uuid);

        $attempt = ExecutionAttempt::open(
            DB::table('engineering_tasks')->find($task->id),
            DB::table('engineering_projects')->find($project->id)
        );

        $attempt->bind($candidate, $uuid, $approval, ['app/Owned/A.php']);
        $attempt->bind($candidate, $uuid, $approval, ['app/Owned/A.php']);

        $this->assertSame($uuid, ExecutionAttempt::find($attempt->uuid)->candidate_uuid);
    }

    public function test_a_closed_attempt_is_never_reopened(): void
    {
        [$task, $project] = $this->approvedCreate();

        $attempt = ExecutionAttempt::open(
            DB::table('engineering_tasks')->find($task->id),
            DB::table('engineering_projects')->find($project->id)
        );

        $attempt->close(ExecutionAttempt::COMPLETED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already closed/');

        $attempt->close(ExecutionAttempt::FAILED);
    }

    public function test_history_is_added_to_not_replaced(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);
        $first = (array) DB::table('engineering_execution_attempts')->first();

        // A second run of the same task — the case that deletes the stage trail.
        (new WorkflowEngine())->run($task->uuid);

        $rows = DB::table('engineering_execution_attempts')->orderBy('id')->get();

        $this->assertCount(2, $rows, 'a re-run appends an attempt, it does not replace one');
        $this->assertEquals($first, (array) $rows[0],
            'the earlier attempt must be byte-identical afterwards');
        $this->assertSame(1, DB::table('engineering_task_stages')->where('task_id', $task->id)
            ->where('stage', 'RECEIVE')->count(),
            'meanwhile the stage trail kept only the latest run, which is why it cannot be the audit');
    }

    // ── durable recovery ────────────────────────────────────────────────

    public function test_the_recovery_manifest_is_persisted_before_any_write(): void
    {
        [$task] = $this->approvedCreate();

        $capturedBeforeWrite = null;

        DB::listen(function ($query) use (&$capturedBeforeWrite) {
            if ($capturedBeforeWrite !== null) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_recovery_manifests`')) { return; }

            // At this instant the installer has not run.
            $capturedBeforeWrite = ! is_file($this->repo . '/app/Owned/A.php')
                && ! is_file($this->repo . '/.engineer888/installed.json');
        });

        (new WorkflowEngine())->run($task->uuid);

        $this->assertTrue($capturedBeforeWrite,
            'evidence written after the fact describes a repository that has already moved');
    }

    public function test_the_stored_manifest_reconstructs_to_the_one_that_was_captured(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_recovery_manifests')->first();
        $this->assertNotNull($row);

        $rebuilt = DurableRecovery::manifestFor((string) $row->uuid);

        $this->assertInstanceOf(RecoveryManifest::class, $rebuilt);
        $this->assertSame($row->manifest_fingerprint, $rebuilt->fingerprint());
        $this->assertSame(['app/Owned/A.php'], $rebuilt->paths());
        $this->assertSame((string) $task->uuid, $rebuilt->taskUuid);
    }

    public function test_the_installer_decisions_are_attached_to_the_evidence(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_recovery_manifests')->first();
        $decisions = json_decode((string) $row->decisions, true);

        $this->assertIsArray($decisions);
        $this->assertSame('app/Owned/A.php', $decisions[0]['destination']);
        $this->assertSame('INSTALL', $decisions[0]['state']);
    }

    public function test_evidence_is_settled_once_its_question_is_answered(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);

        $row = DB::table('engineering_recovery_manifests')->first();

        $this->assertSame(DurableRecovery::RECOVERED, $row->state);
        $this->assertNotNull($row->completed_at);
        $this->assertSame([], DurableRecovery::outstanding($this->repo),
            'a settled rollback is history, not an outstanding question');
    }

    /**
     * THE CASE THIS PHASE EXISTS FOR.
     *
     * The process ends between the install and the halt handler. Nothing gets to
     * clean up, nothing marks the evidence settled — and an operator arriving
     * afterwards must still be able to see that this repository has an unfinished
     * execution against it, and to rebuild the manifest that says what to undo.
     */
    public function test_a_process_that_dies_mid_execution_leaves_recoverable_evidence(): void
    {
        [$task] = $this->approvedCreate();

        // Die the instant IMPLEMENT has finished writing and its stage row is
        // being recorded. That insert happens in the lifecycle, outside the
        // per-stage try/catch, so the throw escapes the run exactly as a real
        // process death would — rather than being converted into a tidy failed
        // stage that the halt handler would then clean up after.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if (! $armed) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_task_stages`')) { return; }
            if (! in_array('IMPLEMENT', $query->bindings, true)) { return; }

            $armed = false;
            throw new RuntimeException('simulated worker death after the write');
        });

        try {
            (new WorkflowEngine())->run($task->uuid);
            $this->fail('the simulated death should have escaped the run');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated worker death', $e->getMessage());
        } finally {
            $armed = false;
        }

        // The file really is on disk, unverified, with nobody left to undo it.
        $this->assertFileExists($this->repo . '/app/Owned/A.php',
            'the simulation must actually leave a mutated repository');

        // And this is what the operator finds.
        $outstanding = DurableRecovery::outstanding($this->repo);
        $this->assertCount(1, $outstanding, 'the crashed execution must still be visible');

        $manifest = DurableRecovery::manifestFor((string) $outstanding[0]->uuid);
        $this->assertInstanceOf(RecoveryManifest::class, $manifest);
        $this->assertSame(['app/Owned/A.php'], $manifest->paths());
        $this->assertSame([], $manifest->deficiencies(),
            'the rebuilt manifest must be good enough to promise an undo');

        // Enough to actually perform the rollback, from the database alone.
        $evidence = (new RecoveryService($this->repo))->restore($manifest, [
            ['state' => 'INSTALL', 'destination' => 'app/Owned/A.php'],
        ], 'operator');

        $this->assertSame(RecoveryService::RECOVERED, $evidence['status']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');

        // The attempt is still open, which is exactly how an abandoned run reads.
        $attempt = DB::table('engineering_execution_attempts')->first();
        $this->assertNull($attempt->finished_at);
        $this->assertSame(ExecutionAttempt::OPEN, $attempt->result);
        $this->assertCount(1, ExecutionAttempt::unfinished($this->repo));
    }

    /**
     * Storage refuses. Nothing may be written.
     *
     * The failure is injected at the insert rather than by dropping the table:
     * DDL commits the surrounding transaction in MySQL and would leak this
     * test's rows into every test after it. What is exercised is the same code
     * path — persist() raises, IMPLEMENT returns before constructing the
     * installer, and the repository is untouched.
     */
    public function test_persistence_failure_refuses_the_execution_and_writes_nothing(): void
    {
        [$task] = $this->approvedCreate();

        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if (! $armed) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_recovery_manifests`')) { return; }

            $armed = false;
            throw new RuntimeException('the evidence store is unavailable');
        });

        $result = (new WorkflowEngine())->run($task->uuid);
        $armed = false;

        $this->assertSame('IMPLEMENT', $result['halted_at']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php',
            'a write whose undo could not be stored must not happen at all');
        $this->assertFileDoesNotExist($this->repo . '/.engineer888/installed.json',
            'the installer must never have been reached');
        $this->assertNull($result['recovery'], 'there was nothing to recover, because nothing was written');
    }

    // ── lock relationship ───────────────────────────────────────────────

    public function test_the_attempt_is_opened_and_closed_inside_the_lock(): void
    {
        [$task] = $this->approvedCreate();

        $contendedAtOpen = null;

        DB::listen(function ($query) use (&$contendedAtOpen) {
            if ($contendedAtOpen !== null) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_execution_attempts`')) { return; }

            $probe = RepositoryExecutionLock::forRepository($this->repo, 'probe-at-open');
            $contendedAtOpen = ! $probe->acquire();
            if (! $contendedAtOpen) { $probe->release(); }
        });

        (new WorkflowEngine())->run($task->uuid);

        $this->assertTrue($contendedAtOpen, 'the attempt must be opened while the tree is held');

        $free = RepositoryExecutionLock::forRepository($this->repo, 'after');
        $this->assertTrue($free->acquire(), 'and the lock released once it is closed');
        $free->release();
    }

    public function test_a_contended_run_leaves_no_attempt_and_disturbs_none(): void
    {
        [$task] = $this->approvedCreate();

        (new WorkflowEngine())->run($task->uuid);
        $before = DB::table('engineering_execution_attempts')->get()->toArray();

        $held = RepositoryExecutionLock::forRepository($this->repo, 'someone-else');
        $held->acquire();

        (new WorkflowEngine())->run($task->uuid);

        $held->release();

        $this->assertEquals($before, DB::table('engineering_execution_attempts')->get()->toArray(),
            'a run refused before it held the tree never began, so it records no attempt '
            . 'and must not touch anybody else\'s');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function unapprovedTask(): object
    {
        (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'audit-project', 'name' => 'Audit Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        return (new WorkflowEngine())->createTask('audit-project', [
            'title' => 'Nothing approved', 'description' => 'x', 'change_set' => [],
        ]);
    }

    /** @return array{0:object,1:object,2:string} */
    private function approvedCreate(): array
    {
        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'audit-project', 'name' => 'Audit Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask('audit-project', [
            'title' => 'Install a fixture class', 'description' => 'Exercises the execution audit.',
            'change_set' => [],
        ]);

        @mkdir($this->repo . '/app/Owned', 0775, true);

        config([
            'engineer888_reasoning.provider' => 'scripted',
            'engineer888_reasoning.scripted.response' => [
                'problem_understanding' => 'A fixture class is required.',
                'assumptions' => [], 'unknowns' => [],
                'implementation_strategy' => 'Create the file.',
                'files_affected' => [['path' => 'app/Owned/A.php', 'action' => 'create', 'why' => 'the deliverable']],
                'migrations' => ['required' => false, 'detail' => 'none'],
                'risks' => [], 'testing_strategy' => 'php -l', 'rollback' => 'SafeInstaller backup',
                'file_changes' => [['path' => 'app/Owned/A.php', 'action' => 'create',
                                    'content' => "<?php\n// installed by the audit fixture\n"]],
                'test_coverage' => [
                    'behaviour_changed' => 'installs a fixture used to exercise the execution audit',
                    'test_files_proposed' => [],
                    'existing_tests' => ['tests/Feature/Engineer888/ExecutionRecoveryTest.php'],
                    'expected_assertions' => ['an execution survives the process that ran it'],
                    'regression_prevented' => 'a mutated repository with no record of what to undo',
                    'test_database' => 'levelup_e888_test', 'full_suite_required' => false,
                    'gaps' => [], 'classification' => 'TESTED', 'confidence' => 'high',
                ],
                'confidence' => 'high', 'confidence_basis' => 'a single new file',
            ],
        ]);

        $outcome = ReasoningEngine::make()->propose($project, $task);
        $this->assertSame('VALIDATED', $outcome->status, json_encode($outcome->violations ?? []));

        $uuid = (string) $outcome->candidateUuid;
        $ledger = new ApprovalLedger();
        $ledger->approve($uuid, $ledger->forCandidateUuid($uuid)->fingerprint, 1, 'Mark (CEO)');

        return [$task, $project, $uuid];
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
