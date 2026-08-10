<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Chat\ActionCardExecutor;
use App\Core\Engineer888\Chat\ActionCardService;
use App\Core\Engineer888\Chat\ConversationService;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use App\Jobs\Engineer888WorkflowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * EXECUTE_TASK — a trigger, and only a trigger.
 *
 * WHAT IS WORTH PROVING. Not that a press queues a job; that a press CANNOT do
 * anything else. The card must not install, must not write, must not decide
 * whether an approval is still good — the ledger and the workflow own all of
 * that and were proved through E1-A to E1-G. So most of these tests assert
 * absence: no repository byte, no second dispatch, no success reported for an
 * action that did not happen.
 *
 * AND IT MUST NOT SAY "EXECUTED". On 2026-08-05 a card reported success for an
 * action that never occurred. A press hands the task to a queue; the install is
 * still ahead of it. QUEUED is the only true thing to say at that moment, and
 * the card records exactly that.
 */
class ExecuteTaskCardTest extends TestCase
{
    use RefreshDatabase;

    private object $conversation;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->seedCanonical();
        $this->conversation = app(ConversationService::class)->forOwner($this->req());

        $this->repo = sys_get_temp_dir() . '/e888-exec-card-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        @mkdir(dirname($this->repo . '/' . $declared), 0775, true);
        file_put_contents($this->repo . '/' . $declared, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'execute-card', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/**', 'tests/**'],
        ]));
        @mkdir($this->repo . '/app/Owned', 0775, true);
        @mkdir($this->repo . '/tests/Feature/Engineer888', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/Engineer888/ExecutionRecoveryTest.php', "<?php\n");
        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n");
        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q && git config user.email e@t && git config user.name e 2>&1');

        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the happy path ──────────────────────────────────────────────────

    public function test_a_press_queues_the_existing_workflow(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        $consumed = $this->press($card);

        Queue::assertPushed(Engineer888WorkflowJob::class, function ($job) use ($task) {
            return $job->taskUuid === $task->uuid && $job->dryRun === false;
        });

        $this->assertSame(ActionCardExecutor::QUEUED, $consumed->consumed_result,
            'a press queues; it does not execute');
        $this->assertNotNull($consumed->consumed_at, 'the card is spent');
        $this->assertSame('queued',
            DB::table('engineering_tasks')->where('id', $task->id)->value('status'));
    }

    public function test_the_card_never_reports_executed(): void
    {
        [, $card] = $this->approvedTaskWithCard();

        // The transport passes 'executed' for every card type; the domain action
        // overrides it, because nothing has been executed yet.
        $consumed = $this->press($card);

        $this->assertNotSame('executed', $consumed->consumed_result);
        $this->assertSame('QUEUED', $consumed->consumed_result);
    }

    public function test_a_press_writes_no_repository_byte(): void
    {
        [, $card] = $this->approvedTaskWithCard();

        $before = $this->treeDigest();
        $this->press($card);

        $this->assertSame($before, $this->treeDigest(),
            'the trigger performs no engineering work of its own');
        $this->assertFileDoesNotExist($this->repo . '/.engineer888/installed.json');
        $this->assertDirectoryDoesNotExist($this->repo . '/.engineer888/recovery');
        $this->assertSame(0, DB::table('engineering_execution_attempts')->count(),
            'the attempt is opened by the workflow, not by the card');
    }

    // ── refusals ────────────────────────────────────────────────────────

    public function test_a_second_press_of_the_same_card_is_refused(): void
    {
        [, $card] = $this->approvedTaskWithCard();

        $this->press($card);

        $this->expectException(HttpException::class);
        $this->press($card);
    }

    /**
     * A card issued BEFORE the run started is refused by the card layer, not the
     * executor: pressing it would act in a workflow state it was never issued
     * for. That check is older than this phase and it fires first, which is the
     * right order — the cheapest refusal wins.
     */
    public function test_a_card_issued_before_the_run_is_refused_once_the_task_moves_on(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();
        $stale = $this->issueCard($task);

        $this->press($card);

        try {
            $this->press($stale);
            $this->fail('a card issued in an earlier workflow state must not act');
        } catch (HttpException) {
            // WORKFLOW_STATE_CHANGED, refused before the executor is reached.
        }

        Queue::assertPushed(Engineer888WorkflowJob::class, 1);
    }

    /**
     * And a card issued while the run is ALREADY IN FLIGHT reaches the executor —
     * its workflow state matches — where the atomic task claim refuses it. This
     * is the guard that stops two different cards, or a card and the Command
     * Center, from queueing the same task twice.
     *
     * The task is moved to `running` deliberately, not left at `queued`. Against
     * a queued task the claim's UPDATE sets `status` to the value it already
     * holds, and MySQL reports zero affected rows — so the guard would appear to
     * work even with its condition deleted. Mutation testing caught exactly that:
     * this scenario is the one where a missing condition really would queue a
     * second run.
     */
    public function test_a_card_pressed_while_a_run_is_in_flight_is_refused_by_the_claim(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        $this->press($card);

        // The worker has picked the job up.
        DB::table('engineering_tasks')->where('id', $task->id)->update(['status' => 'running']);

        $second = $this->issueCard($task);

        $this->assertRefused($second, ActionCardExecutor::WORKFLOW_ALREADY_RUNNING);
        Queue::assertPushed(Engineer888WorkflowJob::class, 1);
        $this->assertSame('running',
            DB::table('engineering_tasks')->where('id', $task->id)->value('status'),
            'a refused press must not drag a running task back to queued');
    }

    public function test_an_unapproved_task_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['state' => ApprovalState::PENDING]);

        $this->assertRefused($card, ActionCardExecutor::NOT_APPROVED);
        Queue::assertNothingPushed();
    }

    public function test_an_expired_approval_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertRefused($card, ActionCardExecutor::APPROVAL_NOT_EXECUTABLE);
        Queue::assertNothingPushed();
        $this->assertSame(ApprovalState::EXPIRED,
            DB::table('engineering_candidate_approvals')->where('task_id', $task->id)->value('state'),
            'the ledger records the expiry it just discovered');
    }

    public function test_a_revoked_approval_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['state' => ApprovalState::REVOKED]);

        $this->assertRefused($card, ActionCardExecutor::NOT_APPROVED);
        Queue::assertNothingPushed();
    }

    public function test_a_superseded_candidate_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['state' => ApprovalState::SUPERSEDED]);

        $this->assertRefused($card, ActionCardExecutor::NOT_APPROVED);
        Queue::assertNothingPushed();
    }

    public function test_a_candidate_whose_bytes_moved_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        // The approval still says APPROVED; the candidate no longer matches it.
        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['fingerprint' => str_repeat('c', 64)]);

        $this->assertRefused($card, ActionCardExecutor::APPROVAL_NOT_EXECUTABLE);
        Queue::assertNothingPushed();
    }

    public function test_a_card_naming_a_different_candidate_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('e888_action_cards')->where('id', $card->id)
            ->update(['candidate_uuid' => (string) \Illuminate\Support\Str::uuid()]);

        // The card no longer describes the live candidate, so its own binding
        // check refuses it before the executor is ever reached.
        $this->expectException(HttpException::class);
        $this->press(DB::table('e888_action_cards')->where('id', $card->id)->first());
    }

    public function test_a_task_that_has_vanished_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('e888_action_cards')->where('id', $card->id)
            ->update(['task_uuid' => (string) \Illuminate\Support\Str::uuid()]);

        $this->expectException(HttpException::class);
        $this->press(DB::table('e888_action_cards')->where('id', $card->id)->first());
    }

    /**
     * Both of these states are prevented by foreign keys, which is the first
     * line of defence and not a reason to leave the second one untested. The
     * constraint is dropped for exactly one statement so the guard can be
     * exercised against the corrupted row a restore or a manual edit could
     * still produce.
     */
    public function test_a_project_that_has_vanished_is_refused(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        $this->withoutForeignKeys(fn () => DB::table('engineering_tasks')
            ->where('id', $task->id)->update(['project_id' => 999999]));

        $this->assertRefused($card, ActionCardExecutor::PROJECT_NOT_FOUND);
        Queue::assertNothingPushed();
    }

    public function test_a_card_from_a_conversation_that_is_gone_is_refused(): void
    {
        [, $card] = $this->approvedTaskWithCard();

        $this->withoutForeignKeys(fn () => DB::table('e888_action_cards')
            ->where('id', $card->id)->update(['conversation_id' => 999999]));

        $this->assertRefused(DB::table('e888_action_cards')->where('id', $card->id)->first(),
            ActionCardExecutor::CONVERSATION_NOT_FOUND);
        Queue::assertNothingPushed();
    }

    private function withoutForeignKeys(callable $work): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $work();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function test_pressing_an_execute_card_at_the_wrong_endpoint_is_refused(): void
    {
        [, $card] = $this->approvedTaskWithCard();

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume(
            $this->req(), (string) $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed', []
        );
    }

    // ── the refusal leaves nothing behind ───────────────────────────────

    public function test_a_refusal_releases_the_card_and_queues_nothing(): void
    {
        [$task, $card] = $this->approvedTaskWithCard();

        DB::table('engineering_candidate_approvals')->where('task_id', $task->id)
            ->update(['state' => ApprovalState::PENDING]);

        $this->assertRefused($card, ActionCardExecutor::NOT_APPROVED);

        $after = DB::table('e888_action_cards')->where('id', $card->id)->first();

        $this->assertNull($after->consumed_at, 'a refused card is released, not spent');
        $this->assertSame(ActionCardExecutor::NOT_APPROVED, $after->consumed_result);
        $this->assertSame('received',
            DB::table('engineering_tasks')->where('id', $task->id)->value('status'),
            'the task must not be left claimed by a run that never started');
        Queue::assertNothingPushed();
    }

    public function test_the_executor_owns_no_execution_logic(): void
    {
        $body = (string) file_get_contents(
            base_path('app/Core/Engineer888/Chat/ActionCardExecutor.php')
        );

        foreach (['SafeInstaller', 'ImplementStage', 'RecoveryManifest', 'RepositoryExecutionLock',
                  'ApprovedPreImageGuard', 'ExecutionAttempt', 'file_put_contents', 'DurableRecovery'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "the card layer must not reach for {$forbidden}; the workflow owns it");
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function treeDigest(): string
    {
        $entries = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) { $entries[] = $f->getPathname() . ':' . @hash_file('sha256', $f->getPathname()); }
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    private function assertRefused(object $card, string $reason): void
    {
        try {
            $this->press($card);
            $this->fail("expected a refusal: {$reason}");
        } catch (HttpException) {
            // The refusal reason is persisted after the transaction rolls back.
        }

        $this->assertSame($reason,
            DB::table('e888_action_cards')->where('id', $card->id)->value('consumed_result'));
    }

    private function press(object $card): object
    {
        return app(ActionCardService::class)->consume(
            $this->req(), (string) $card->uuid, ActionCardService::EXECUTE_TASK, 'executed', []
        );
    }

    /** @return array{0:object,1:object} */
    private function approvedTaskWithCard(): array
    {
        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'execute-card-project', 'name' => 'Execute Card Project',
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask('execute-card-project', [
            'title' => 'Install a fixture class', 'description' => 'Exercises the execute card.',
            'change_set' => [],
        ]);

        config([
            'engineer888_reasoning.enabled' => true,
            'engineer888_reasoning.provider' => 'scripted',
            'engineer888_reasoning.scripted.label' => 'execute-card',
            'engineer888_reasoning.scripted.response' => [
                'problem_understanding' => 'A fixture class is required.',
                'assumptions' => [], 'unknowns' => [],
                'implementation_strategy' => 'Create the file.',
                'files_affected' => [['path' => 'app/Owned/A.php', 'action' => 'create', 'why' => 'the deliverable']],
                'migrations' => ['required' => false, 'detail' => 'none'],
                'risks' => [], 'testing_strategy' => 'php -l', 'rollback' => 'SafeInstaller backup',
                'file_changes' => [['path' => 'app/Owned/A.php', 'action' => 'create',
                                    'content' => "<?php\n// installed by the execute-card fixture\n"]],
                'test_coverage' => [
                    'behaviour_changed' => 'installs a fixture used to exercise the execute card',
                    'test_files_proposed' => [],
                    'existing_tests' => ['tests/Feature/Engineer888/ExecutionRecoveryTest.php'],
                    'expected_assertions' => ['a press queues the governed workflow'],
                    'regression_prevented' => 'a card reporting an effect that never happened',
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

        return [$task, $this->issueCard($task)];
    }

    private function issueCard(object $task): object
    {
        return app(ActionCardService::class)->issue(
            $this->req(), $this->conversation, null,
            ActionCardService::EXECUTE_TASK, (string) $task->uuid
        );
    }

    private function req(): Request
    {
        $request = Request::create('/api/admin/engineer888/chat', 'POST');
        $request->setUserResolver(fn () => User::find(1));

        return $request;
    }

    /**
     * The canonical admin, seeded exactly as the proven card suites do it.
     *
     * `is_platform_admin` is the part that is easy to miss: without it the
     * access policy answers NOT_PLATFORM_ADMIN and every card refuses at
     * validate(), long before the executor is reached.
     */
    private function seedCanonical(): void
    {
        $user = new User();
        $user->id = 1;
        $user->name = 'Mark';
        $user->email = \App\Core\Engineer888\Access\Engineer888Access::CANONICAL_EMAIL;
        $user->password = 'irrelevant';
        $user->is_platform_admin = true;
        $user->status = 'active';
        $user->save();

        DB::table('engineering_access_grants')->where('user_id', 1)->delete();
        DB::table('engineering_access_grants')->insert([
            'user_id' => 1,
            'email' => \App\Core\Engineer888\Access\Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(
                \App\Core\Engineer888\Access\Engineer888Access::capabilitiesForCanonicalAdmin()
            ),
            'policy_version' => \App\Core\Engineer888\Access\Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) { @unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
