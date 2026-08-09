<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Recovery\RecoveryService;
use App\Core\Engineer888\Workflow\RepositoryExecutionLock;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use App\Jobs\Engineer888WorkflowJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * One repository, one execution.
 *
 * WHAT IS WORTH PROVING HERE. Not that a lock can be taken — that a refused run
 * is genuinely inert. The dangerous failure is not a second workflow finishing
 * badly, it is a second workflow getting far enough to delete the first one's
 * stage history, or reaching SafeInstaller while the first is mid-install and
 * quietly discarding its provenance records. So most of these tests assert
 * absence: no stage rows deleted, no file touched, no installer reached.
 *
 * The other half is the release. A lock that is not released on every exit is a
 * repository nobody can execute against again, so completion, blocking, failure
 * and an exception thrown mid-stage each get their own test — and recovery is
 * proved to run while the lock is still held, because recovery decides what to
 * restore by asking whether a file is still exactly what this workflow wrote.
 *
 * Locks here run on the array cache store that phpunit.e888.xml pins. It is the
 * same `Illuminate\Cache\Lock` contract staging exercises on Redis; what differs
 * is only where the key lives.
 */
class ExecutionLockTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    /** Locks taken directly by a test, released in tearDown whatever happens. */
    private array $taken = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        // The array store outlives a single test inside one process; a lock left
        // held would silently contend the next test rather than fail it.
        Cache::flush();

        $this->repo = sys_get_temp_dir() . '/e888-lock-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
        $this->seedRepository($this->repo, 'lock-test');

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        foreach ($this->taken as $lock) { $lock->release(); }
        $this->taken = [];

        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the key ─────────────────────────────────────────────────────────

    public function test_the_key_is_deterministic_for_one_repository(): void
    {
        $a = RepositoryExecutionLock::forRepository($this->repo, 'task-a');
        $b = RepositoryExecutionLock::forRepository($this->repo, 'task-b');

        $this->assertSame($a->key, $b->key,
            'the resource is the tree, so the task must not enter the key');
        $this->assertNotSame($a->owner, $b->owner,
            'each attempt still needs its own owner token');
    }

    public function test_the_same_repository_spelled_differently_yields_one_key(): void
    {
        $canonical = RepositoryExecutionLock::forRepository($this->repo)->key;

        foreach ([$this->repo . '/', $this->repo . '/.', $this->repo . '//'] as $variant) {
            $this->assertSame($canonical, RepositoryExecutionLock::forRepository($variant)->key,
                "{$variant} must resolve onto the same lock");
        }
    }

    public function test_different_repositories_yield_different_keys(): void
    {
        $other = $this->repo . '-other';
        $this->seedRepository($other, 'other');

        $this->assertNotSame(
            RepositoryExecutionLock::forRepository($this->repo)->key,
            RepositoryExecutionLock::forRepository($other)->key
        );

        $this->rmrf($other);
    }

    public function test_an_unresolvable_path_still_produces_a_stable_key(): void
    {
        $missing = '/var/www/a-tree-that-is-not-there';

        $this->assertSame(
            RepositoryExecutionLock::forRepository($missing)->key,
            RepositoryExecutionLock::forRepository($missing . '/')->key,
            'refusing to produce a key would mean refusing to lock'
        );
    }

    // ── the primitive ───────────────────────────────────────────────────

    public function test_a_second_holder_is_refused_while_the_first_holds_it(): void
    {
        $a = $this->take($this->repo, 'task-a');
        $this->assertTrue($a->isHeld());

        $b = RepositoryExecutionLock::forRepository($this->repo, 'task-b');
        $this->assertFalse($b->acquire(), 'two processes must never both own one tree');

        $a->release();
        $this->assertTrue($b->acquire(), 'and the tree must be usable again afterwards');
        $b->release();
    }

    public function test_a_contender_cannot_free_the_holders_lock(): void
    {
        $holder = $this->take($this->repo, 'holder');

        $contender = RepositoryExecutionLock::forRepository($this->repo, 'contender');
        $this->assertFalse($contender->acquire());
        $contender->release();

        $third = RepositoryExecutionLock::forRepository($this->repo, 'third');
        $this->assertFalse($third->acquire(),
            'a refused run calling release() must not hand the repository to someone else');
    }

    public function test_the_holder_is_identifiable(): void
    {
        $lock = $this->take($this->repo, 'task-abc');
        $holder = $lock->heldBy();

        $this->assertIsArray($holder);
        $this->assertSame('task-abc', $holder['task_uuid']);
        $this->assertSame(getmypid(), $holder['pid']);
        $this->assertNotEmpty($holder['acquired_at']);
    }

    // ── TTL ─────────────────────────────────────────────────────────────

    public function test_the_ttl_is_finite_and_outlives_the_job_timeout(): void
    {
        $jobTimeout = (new Engineer888WorkflowJob('x'))->timeout;

        $this->assertGreaterThan($jobTimeout, RepositoryExecutionLock::TTL_SECONDS,
            'a TTL that expired under a live run would hand the tree to a second workflow mid-install');
        $this->assertLessThan(86400, RepositoryExecutionLock::TTL_SECONDS,
            'and it must be finite, or a hard-killed worker deadlocks the repository for ever');
    }

    public function test_a_lock_nobody_released_eventually_expires(): void
    {
        // The release lives in a `finally`, which a SIGKILL does not run. The
        // TTL is the only thing that frees the tree in that case, so it has to
        // actually expire. Proved at one second; production runs the same
        // contract at 2700.
        $key = 'engineer888:ttl-probe:' . getmypid();

        $this->assertTrue(Cache::lock($key, 1, 'first')->get());
        $this->assertFalse(Cache::lock($key, 1, 'second')->get());

        usleep(1_200_000);

        $this->assertTrue(Cache::lock($key, 1, 'third')->get(),
            'an abandoned lock must not outlive its TTL');
        Cache::lock($key, 1, 'third')->forceRelease();
    }

    // ── contention through the engine ───────────────────────────────────

    public function test_a_contended_run_never_enters_the_stage_loop(): void
    {
        [$task, , ] = $this->approvedCreate();
        $history = $this->seedStageHistory((int) $task->id);

        $this->take($this->repo, 'someone-else');

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(RepositoryExecutionLock::CONTENDED, $result['halted_at']);
        $this->assertSame([], $result['stages'], 'not one stage may have run');
        $this->assertTrue($result['lock']['contended']);
    }

    public function test_a_contended_run_preserves_the_existing_stage_history(): void
    {
        [$task, , ] = $this->approvedCreate();
        $history = $this->seedStageHistory((int) $task->id);

        $this->take($this->repo, 'someone-else');

        (new WorkflowEngine())->run($task->uuid);

        $after = DB::table('engineering_task_stages')->where('task_id', $task->id)
            ->orderBy('id')->pluck('stage', 'id')->all();

        $this->assertSame($history, $after,
            'the delete happens inside run(); a refused run reaching it would erase '
            . 'the trail of the run it was refused in favour of');
    }

    public function test_a_contended_run_reaches_neither_implement_nor_the_installer(): void
    {
        [$task, , ] = $this->approvedCreate();
        $target = $this->repo . '/app/Owned/A.php';

        $this->take($this->repo, 'someone-else');

        (new WorkflowEngine())->run($task->uuid);

        $this->assertFileDoesNotExist($target, 'IMPLEMENT must never have run');
        $this->assertFileDoesNotExist($this->repo . '/.engineer888/installed.json',
            'SafeInstaller records provenance on every write; there must be no record');
        $this->assertSame(0, DB::table('engineering_recoveries')->count());
    }

    public function test_a_contended_run_names_the_holder_and_the_reason(): void
    {
        [$task, , ] = $this->approvedCreate();
        $this->take($this->repo, 'the-other-task');

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame(RepositoryExecutionLock::CONTENDED, $result['lock']['reason']);
        $this->assertSame('the-other-task', $result['lock']['holder']['task_uuid']);
        $this->assertStringContainsString($this->repo, $result['lock']['detail']);
        $this->assertStringContainsString('Nothing was read, written or deleted', $result['lock']['detail']);
    }

    // ── duplicate dispatch of ONE task ──────────────────────────────────

    public function test_a_duplicate_dispatch_of_the_same_task_runs_once(): void
    {
        [$task, , ] = $this->approvedCreate();

        // The first dispatch is already inside run(), holding the tree.
        $this->take($this->repo, (string) $task->uuid);
        DB::table('engineering_tasks')->where('id', $task->id)->update(['status' => 'running']);

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame(RepositoryExecutionLock::CONTENDED, $result['halted_at']);
        $this->assertTrue($result['lock']['same_task']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php',
            'only one of two dispatches may reach the installer');

        $this->assertSame('running',
            DB::table('engineering_tasks')->where('id', $task->id)->value('status'),
            'the live run owns this row; reporting it blocked would be a lie about a running execution');
    }

    // ── two tasks, one repository ───────────────────────────────────────

    public function test_two_tasks_against_one_repository_contend(): void
    {
        [$taskA, $project, ] = $this->approvedCreate();

        $taskB = (new WorkflowEngine())->createTask($project->key, [
            'title' => 'A second task in the same tree', 'description' => 'x', 'change_set' => [],
        ]);

        $this->take($this->repo, (string) $taskA->uuid);

        $result = (new WorkflowEngine())->run($taskB->uuid);

        $this->assertSame(RepositoryExecutionLock::CONTENDED, $result['halted_at']);
        $this->assertFalse($result['lock']['same_task']);
        $this->assertSame('blocked',
            DB::table('engineering_tasks')->where('id', $taskB->id)->value('status'),
            'a different task genuinely cannot proceed, and the row should say so');
    }

    public function test_two_repositories_do_not_contend(): void
    {
        $other = $this->repo . '-second';
        $this->seedRepository($other, 'second');

        $held = $this->take($this->repo, 'task-in-tree-one');
        $free = RepositoryExecutionLock::forRepository($other, 'task-in-tree-two');

        $this->assertTrue($held->isHeld());
        $this->assertTrue($free->acquire(), 'separate trees are separate resources');
        $free->release();

        $this->rmrf($other);
    }

    // ── release ─────────────────────────────────────────────────────────

    public function test_the_lock_is_released_after_a_blocked_run(): void
    {
        $task = $this->unapprovedTask();

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotSame(RepositoryExecutionLock::CONTENDED, $result['halted_at']);
        $this->assertLockIsFree();
    }

    public function test_the_lock_is_released_after_a_failed_run(): void
    {
        [$task, , ] = $this->approvedCreate();

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertContains($result['status'], ['failed', RecoveryService::RECOVERED]);
        $this->assertLockIsFree();
    }

    /**
     * The release lives in `finally`, so prove it with something that actually
     * throws while the lock is held.
     *
     * The stage-history delete is the first thing the lifecycle does once it
     * owns the tree, which makes it the earliest point at which a failure can
     * strand the lock. The listener disarms itself on the way out so it cannot
     * leak into the rest of the run — or the rest of the suite.
     */
    public function test_the_lock_is_released_when_a_run_throws(): void
    {
        [$task, , ] = $this->approvedCreate();

        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if (! $armed) { return; }
            if (! str_contains(strtolower($query->sql), 'delete from `engineering_task_stages`')) { return; }

            $armed = false;
            throw new \RuntimeException('forced failure inside the locked lifecycle');
        });

        try {
            (new WorkflowEngine())->run($task->uuid);
            $this->fail('expected the forced failure to escape run()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('forced failure', $e->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertLockIsFree('a throw inside the locked lifecycle must still release the tree');
    }

    /**
     * THE INVARIANT THIS PHASE EXISTS FOR.
     *
     * Recovery asks, per file, "is this still exactly the bytes I wrote?" and
     * refuses if not. Another workflow writing during that question turns a
     * clean rollback into a refusal — or into a rollback of somebody else's
     * work. So the lock must still be held when recovery runs, and only then
     * released.
     *
     * Observed rather than asserted structurally: a query listener fires on the
     * RECOVERY stage insert, which happens inside recover(), and at that instant
     * a competing acquisition must fail.
     */
    public function test_the_lock_is_still_held_while_recovery_runs_and_free_afterwards(): void
    {
        [$task, , ] = $this->approvedCreate();

        $contendedDuringRecovery = null;

        DB::listen(function ($query) use (&$contendedDuringRecovery) {
            if ($contendedDuringRecovery !== null) { return; }
            if (! str_contains(strtolower($query->sql), 'insert into `engineering_task_stages`')) { return; }
            if (! in_array('RECOVERY', $query->bindings, true)) { return; }

            $probe = RepositoryExecutionLock::forRepository($this->repo, 'probe-during-recovery');
            $contendedDuringRecovery = ! $probe->acquire();
            if (! $contendedDuringRecovery) { $probe->release(); }
        });

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertNotNull($result['recovery'],
            'this fixture must actually write and then halt, or the test proves nothing');
        $this->assertSame(RecoveryService::RECOVERED, $result['recovery']['status']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php',
            'recovery should have removed the file it created');

        $this->assertTrue($contendedDuringRecovery,
            'the repository lock must still be held while recovery inspects and restores');

        $this->assertLockIsFree('and released once recovery has completed');
    }

    // ── regression ──────────────────────────────────────────────────────

    public function test_the_job_still_refuses_to_retry(): void
    {
        $this->assertSame(1, (new Engineer888WorkflowJob('x'))->tries,
            'a retried workflow would re-enter IMPLEMENT');
        $this->assertFalse(
            (new ReflectionClass(Engineer888WorkflowJob::class))->implementsInterface(
                \Illuminate\Contracts\Queue\ShouldBeUnique::class
            ),
            'exclusivity belongs to the repository lock, not to job uniqueness'
        );
    }

    public function test_an_uncontended_run_behaves_exactly_as_before(): void
    {
        [$task, , ] = $this->approvedCreate();

        $result = (new WorkflowEngine())->run($task->uuid);

        $stages = array_column(array_map(
            fn ($s) => ['stage' => $s['stage'], 'status' => $s['result']->status], $result['stages']
        ), 'status', 'stage');

        foreach (['RECEIVE', 'UNDERSTAND', 'ANALYZE', 'PLAN', 'ESTIMATE',
                  'IDENTIFY_FILES', 'IDENTIFY_RISKS', 'REQUEST_APPROVAL'] as $stage) {
            $this->assertSame('ok', $stages[$stage] ?? null, "{$stage} must still pass uncontended");
        }

        $this->assertArrayHasKey('IMPLEMENT', $stages, 'the lifecycle must still reach the write');
        $this->assertNotEmpty(DB::table('engineering_task_stages')->where('task_id', $task->id)->get());
    }

    public function test_a_dry_run_still_mutates_no_source_file(): void
    {
        [$task, , ] = $this->approvedCreate();

        $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);

        $byStage = [];
        foreach ($result['stages'] as $s) { $byStage[$s['stage']] = $s['result']->status; }

        $this->assertSame('skipped', $byStage['IMPLEMENT'] ?? null);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
        $this->assertFileDoesNotExist($this->repo . '/.engineer888/installed.json');
        $this->assertLockIsFree();
    }

    public function test_the_bound_pre_image_is_unaffected_by_the_lock(): void
    {
        [$task, , $uuid] = $this->approvedCreate();

        $candidate = ReasoningEngine::make()->approvedCandidate((int) $task->id);

        $this->assertNotNull($candidate);
        $this->assertTrue($candidate->hasBoundPreImage(),
            'E1-A evidence must survive the E1-C refactor untouched');
        $this->assertFalse($candidate->preImage[0]['existed']);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function take(string $repo, string $taskUuid): RepositoryExecutionLock
    {
        $lock = RepositoryExecutionLock::forRepository($repo, $taskUuid);
        $this->assertTrue($lock->acquire(), 'the fixture must actually hold the lock');
        $this->taken[] = $lock;

        return $lock;
    }

    private function assertLockIsFree(string $why = 'the run must not leave the repository locked'): void
    {
        $probe = RepositoryExecutionLock::forRepository($this->repo, 'free-probe');
        $this->assertTrue($probe->acquire(), $why);
        $probe->release();
    }

    /** @return array<int,string> id => stage, as it stood before the refused run */
    private function seedStageHistory(int $taskId): array
    {
        foreach (['RECEIVE', 'UNDERSTAND'] as $position => $stage) {
            DB::table('engineering_task_stages')->insert([
                'task_id' => $taskId, 'stage' => $stage, 'position' => $position + 1,
                'status' => 'ok', 'summary' => 'from an earlier run', 'duration_ms' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::table('engineering_task_stages')->where('task_id', $taskId)
            ->orderBy('id')->pluck('stage', 'id')->all();
    }

    private function seedRepository(string $path, string $sprint): void
    {
        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        @mkdir(dirname($path . '/' . $declared), 0775, true);
        file_put_contents($path . '/' . $declared, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => $sprint, 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($path . '/.gitignore', ".engineer888/\n.gitignore\n");
        exec('cd ' . escapeshellarg($path) . ' && git init -q && git config user.email e888@test && git config user.name e888 2>&1');
    }

    private function unapprovedTask(): object
    {
        (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'lock-project', 'name' => 'Lock Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        return (new WorkflowEngine())->createTask('lock-project', [
            'title' => 'Nothing approved', 'description' => 'x', 'change_set' => [],
        ]);
    }

    /**
     * A task with an approved candidate that CREATES a file.
     *
     * Create rather than update on purpose: an update to a file the installer
     * has no record of is refused as UNKNOWN_PROVENANCE and never writes, so it
     * could not exercise the write-then-recover path these tests need.
     *
     * @return array{0:object,1:object,2:string}
     */
    private function approvedCreate(): array
    {
        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'lock-project', 'name' => 'Lock Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask('lock-project', [
            'title' => 'Install a fixture class', 'description' => 'Exercises the execution lock.',
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
                                    'content' => "<?php\n// installed by the lock fixture\n"]],
                'test_coverage' => [
                    'behaviour_changed' => 'installs a fixture used to exercise the execution lock',
                    'test_files_proposed' => [],
                    'existing_tests' => ['tests/Feature/Engineer888/ExecutionRecoveryTest.php'],
                    'expected_assertions' => ['one workflow at a time reaches the installer'],
                    'regression_prevented' => 'two workflows writing the same tree at once',
                    'test_database' => 'levelup_e888_test', 'full_suite_required' => false,
                    'gaps' => [], 'classification' => 'TESTED', 'confidence' => 'high',
                ],
                'confidence' => 'high', 'confidence_basis' => 'a single new file',
            ],
        ]);

        $outcome = ReasoningEngine::make()->propose($project, $task);
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
