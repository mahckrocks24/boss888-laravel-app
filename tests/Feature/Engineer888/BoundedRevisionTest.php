<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SequencedReasoningProvider;
use Tests\TestCase;

/**
 * One validator-driven revision. Not a loop.
 *
 * WHY THIS WAS WIRED. retryForViolations() was written in Sprint 7, complete
 * with its eligibility list and the shared cap, and nothing ever called it. On
 * 2026-08-11 twelve consecutive candidates for the same unchanged task all
 * understood the persistence requirement, all checked every save(), and all were
 * refused for the same single unchecked write in the constructor. The provider
 * was not missing the architecture; it was missing one deterministic finding it
 * never got to see.
 *
 * The danger of wiring it is the one the original author named in the docblock
 * they left on MAX_REVISIONS: a loop that keeps asking until something passes
 * the validator optimises for getting past the gate rather than for being right.
 * So the cap matters more than the feature, and most of this file is about the
 * cap.
 */
class BoundedRevisionTest extends TestCase
{
    use RefreshDatabase;

    private object $project;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-revision-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
        @mkdir($this->repo . '/app', 0775, true);
        @mkdir($this->repo . '/tests', 0775, true);

        $declared = (string) (getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json');
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'revision',
            'sprint' => 'revision-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/**', 'tests/**'],
        ]));

        // UNDERSTAND reads the branch and HEAD, so a directory that is not a
        // repository halts the workflow before PLAN is ever reached — which is
        // how the first version of the wiring test failed for a reason that had
        // nothing to do with revisions.
        exec('cd ' . escapeshellarg($this->repo) . ' && git init -q && '
           . 'git -c user.email=t@t -c user.name=t commit -q --allow-empty -m base 2>&1');

        $this->project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'revision-fixture',
            'name' => 'Revision fixture', 'repository_path' => $this->repo,
        ]);

        config([
            'engineer888_reasoning.enabled' => true,
            'engineer888_reasoning.provider' => 'sequenced',
            'engineer888_reasoning.providers.sequenced' => SequencedReasoningProvider::class,
        ]);
    }

    protected function tearDown(): void
    {
        SequencedReasoningProvider::reset();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function payload(array $changes, array $overrides = []): array
    {
        $tests = array_values(array_filter(array_column($changes, 'path'),
            static fn (string $p): bool => str_ends_with($p, 'Test.php')));

        return array_merge([
            'problem_understanding'   => 'Store a record on disk.',
            'assumptions'             => [],
            'unknowns'                => [],
            'implementation_strategy' => 'A small class with a checked write.',
            'files_affected'          => array_map(
                static fn (array $c): array => ['path' => $c['path'], 'action' => $c['action'], 'why' => 'the target'],
                $changes),
            'migrations'              => ['required' => false, 'detail' => 'none'],
            'risks'                   => [],
            'testing_strategy'        => 'PHPUnit around the write.',
            'rollback'                => 'delete the created files',
            'file_changes'            => $changes,
            'confidence'              => 'high',
            'test_coverage'           => [
                'behaviour_changed'    => 'records are written to disk',
                'test_files_proposed'  => $tests,
                'existing_tests'       => [],
                'expected_assertions'  => ['a failed write raises'],
                'regression_prevented' => 'a silent write failure',
                'test_database'        => 'levelup_e888_test',
                'full_suite_required'  => false,
                'gaps'                 => [],
                'classification'       => 'TESTED',
                'confidence'           => 'high',
            ],
        ], $overrides);
    }

    private function uncheckedWrite(): array
    {
        return $this->payload([
            ['path' => 'app/Store.php', 'action' => 'create', 'content' => <<<'CODE'
<?php

namespace App;

final class Store
{
    public function __construct(private readonly string $path) {}

    public function save(array $rows): void
    {
        file_put_contents($this->path, json_encode($rows));
    }
}
CODE],
            ['path' => 'tests/StoreTest.php', 'action' => 'create', 'content' => <<<'CODE'
<?php

use App\Store;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    public function testItWritesTheRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'store');
        (new Store($path))->save(['a']);

        $this->assertSame(['a'], json_decode((string) file_get_contents($path), true));
    }
}
CODE],
        ]);
    }
    private function checkedWrite(): array
    {
        return $this->payload([
            ['path' => 'app/Store.php', 'action' => 'create', 'content' => <<<'CODE'
<?php

namespace App;

final class Store
{
    public function __construct(private readonly string $path) {}

    public function save(array $rows): void
    {
        if (file_put_contents($this->path, json_encode($rows)) === false) {
            throw new \RuntimeException('could not write ' . $this->path);
        }
    }
}
CODE],
            ['path' => 'tests/StoreTest.php', 'action' => 'create', 'content' => <<<'CODE'
<?php

use App\Store;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    public function testItWritesTheRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'store');
        (new Store($path))->save(['a']);

        $this->assertSame(['a'], json_decode((string) file_get_contents($path), true));
    }

    public function testItRaisesWhenTheWriteFails(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Store('/proc/nowhere/store.json'))->save(['a']);
    }
}
CODE],
        ]);
    }
    /** A traversal attempt: never something to ask a provider to try again. */
    private function ineligible(): array
    {
        return $this->payload([
            ['path' => '../outside/Escape.php', 'action' => 'create', 'content' => "<?php\n\nreturn 1;\n"],
        ]);
    }

    /**
     * One "turn" is one propose(), and propose() asks the provider TWICE.
     *
     * Pass 1 names the files; pass 2 re-asks with the grounded source so the
     * contract's "return the complete file" is answerable. That is deliberate
     * design, and a queue that ignores it feeds pass 2 the answer meant for the
     * next attempt — which is exactly how the first version of this file
     * appeared to prove a revision that never happened.
     *
     * @param array<int,array<string,mixed>> $turns
     * @return array<int,array<string,mixed>>
     */
    private function turns(array $turns): array
    {
        $queue = [];
        foreach ($turns as $payload) { $queue[] = $payload; $queue[] = $payload; }

        return $queue;
    }

    /** Provider calls a completed propose() costs. */
    private const CALLS_PER_TURN = 2;
    private function firstAnswer(array $queue): array
    {
        SequencedReasoningProvider::reset($this->turns($queue));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $outcome = ReasoningEngine::make()->propose($this->project, $task);

        return ['task' => $task, 'outcome' => $outcome];
    }

    // ── 1, 5, 11 ────────────────────────────────────────────────────────

    public function test_a_valid_first_answer_is_never_revised(): void
    {
        $r = $this->firstAnswer([$this->checkedWrite()]);

        $this->assertTrue($r['outcome']->isValidated());
        $this->assertSame(self::CALLS_PER_TURN, SequencedReasoningProvider::$calls,
            'a candidate that passes costs one propose(), which is two provider calls by design');
        $this->assertSame(0, DB::table('engineering_candidates')
            ->where('task_id', $r['task']->id)->whereNotNull('revision_of')->count());
    }

    // ── 2, 3, 10, 11, 12 ────────────────────────────────────────────────

    public function test_an_eligible_refusal_buys_exactly_one_revision_that_can_succeed(): void
    {
        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $this->checkedWrite()]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);

        $this->assertFalse($first->isValidated(), 'the unchecked write must be refused');
        $this->assertContains('unchecked_write', array_column($first->violations, 'rule'));

        $revised = $engine->retryForViolations($this->project, $task,
            (string) $first->candidateUuid, $first->violations);

        $this->assertTrue($revised->isValidated(), 'being told exactly what broke must be enough to fix it');
        $this->assertSame(2 * self::CALLS_PER_TURN, SequencedReasoningProvider::$calls,
            'one refusal buys one more propose(), never two');

        // 10 — the two attempts stay distinguishable in the record.
        $rows = DB::table('engineering_candidates')->where('task_id', $task->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, '11 — both provider calls are recorded, not just the one that worked');
        $this->assertSame('REJECTED', $rows[0]->status);
        $this->assertSame('VALIDATED', $rows[1]->status);
        $this->assertNotSame($rows[0]->uuid, $rows[1]->uuid);
        $this->assertNotNull($rows[1]->revision_of, 'a revision must say what it revised');

        // 12 — the second call was given the same project and task, and the
        // repository map and requirement checklist travelled with it.
        $second = SequencedReasoningProvider::$requests[self::CALLS_PER_TURN];
        $this->assertSame($this->project->repository_path, $second->project['repository_path']);
        $this->assertStringContainsString('ESTABLISHED BY DISCOVERY', $second->renderText(),
            'a revision reasoned without the repository is a fresh guess wearing a correction');
        $this->assertStringContainsString('unchecked_write', $second->renderText(),
            'the revision must be told what was actually wrong');
    }

    // ── 4, 5 ────────────────────────────────────────────────────────────

    public function test_a_revision_that_is_still_wrong_stops_and_does_not_get_a_third_call(): void
    {
        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $this->uncheckedWrite()]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);
        $revised = $engine->retryForViolations($this->project, $task,
            (string) $first->candidateUuid, $first->violations);

        $this->assertFalse($revised->isValidated());
        $this->assertSame(2 * self::CALLS_PER_TURN, SequencedReasoningProvider::$calls);

        // A third attempt is refused by the cap, not by this stage's manners.
        $third = $engine->retryForViolations($this->project, $task,
            (string) $revised->candidateUuid, $revised->violations);

        $this->assertSame(ReasoningEngine::MAX_REVISIONS, 1);
        $this->assertFalse($third->isValidated());
        $this->assertSame(2 * self::CALLS_PER_TURN, SequencedReasoningProvider::$calls,
            'a third propose() is the retry loop this cap exists to prevent');
        $this->assertStringContainsString('already had its one revision', $third->reason);
    }

    // ── 6 ───────────────────────────────────────────────────────────────

    public function test_an_ineligible_refusal_buys_no_revision_at_all(): void
    {
        SequencedReasoningProvider::reset($this->turns([$this->ineligible(), $this->checkedWrite()]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);
        $this->assertFalse($first->isValidated());
        $this->assertContains('unsafe_path', array_column($first->violations, 'rule'));

        $callsAfterFirst = SequencedReasoningProvider::$calls;

        $retry = $engine->retryForViolations($this->project, $task,
            (string) $first->candidateUuid, $first->violations);

        $this->assertSame('UNAVAILABLE', $retry->status,
            'a path escaping the repository is not a contract slip to be explained away');
        $this->assertSame($callsAfterFirst, SequencedReasoningProvider::$calls,
            'and it must not cost another provider call. A refused path never reaches pass 2, so
             the first turn costs one call rather than two — the claim is that nothing follows it');
    }

    // ── 7, 8, 9 ─────────────────────────────────────────────────────────

    public function test_a_revision_is_judged_from_zero_by_every_rule(): void
    {
        // The revision fixes the write and breaks something else. Nothing about
        // having been a revision earns it a lighter check.
        $brokenDifferently = $this->payload([
            ['path' => 'app/Store.php', 'action' => 'create',
             'content' => "<?php\n\nnamespace App;\n\nfinal class Store\n{\n    public function save(array \$rows): void\n    {\n        // TODO\n    }\n}\n"],
            ['path' => 'tests/StoreTest.php', 'action' => 'create',
             'content' => "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass StoreTest extends TestCase\n{\n    public function testItWrites(): void\n    {\n        \$this->assertTrue(true);\n    }\n}\n"],
        ]);

        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $brokenDifferently]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);
        $revised = $engine->retryForViolations($this->project, $task,
            (string) $first->candidateUuid, $first->violations);

        $rules = array_column($revised->violations, 'rule');
        $this->assertFalse($revised->isValidated());
        $this->assertContains('placeholder_body', $rules,
            'the full validator runs again; a revision is not partially trusted');
        $this->assertNotContains('unchecked_write', $rules, 'and the original finding really was fixed');
    }

    public function test_a_revision_cannot_silence_a_finding_by_dropping_the_work(): void
    {
        // The cheapest way past unchecked_write is to delete the write. The
        // revision is still held to the coverage contract, so an empty answer
        // is refused rather than rewarded.
        $gutted = $this->payload([
            ['path' => 'app/Store.php', 'action' => 'create',
             'content' => "<?php\n\nnamespace App;\n\nfinal class Store\n{\n    public function save(array \$rows): void\n    {\n        // no-op\n    }\n}\n"],
        ], ['test_coverage' => [
            'behaviour_changed' => '', 'test_files_proposed' => [], 'existing_tests' => [],
            'expected_assertions' => [], 'regression_prevented' => '', 'test_database' => 'levelup_e888_test',
            'full_suite_required' => false, 'gaps' => [], 'classification' => 'TESTED', 'confidence' => '',
        ]]);

        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $gutted]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);
        $revised = $engine->retryForViolations($this->project, $task,
            (string) $first->candidateUuid, $first->violations);

        $this->assertFalse($revised->isValidated(),
            'deleting the feature is not a repair, and the coverage contract is what says so');
    }

    public function test_the_workflow_itself_asks_for_the_revision(): void
    {
        // MUTATION A HAS TO BITE SOMEWHERE. Every other test here calls
        // retryForViolations() directly, which proves the capability and says
        // nothing about whether anything USES it — and for the whole of Sprint 7
        // to 2026-08-11 nothing did. This drives PLAN the way a real task does,
        // so removing the call from PlanStage turns it red.
        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $this->checkedWrite()]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk', 'description' => 'Write records to a JSON store.',
            'change_set' => [],
        ]);

        $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);

        $stages = [];
        foreach ($result['stages'] as $s) { $stages[$s['stage']] = $s['result']; }

        $this->assertArrayHasKey('PLAN', $stages);
        $this->assertSame('ok', $stages['PLAN']->status,
            'the first answer was refused for an eligible finding; PLAN must ask for the one revision '
            . 'rather than blocking the task on a defect the provider can fix by being told');

        $rows = DB::table('engineering_candidates')->where('task_id', $task->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'both attempts are recorded, and only two');
        $this->assertSame('REJECTED', $rows[0]->status);
        $this->assertSame('VALIDATED', $rows[1]->status);
    }
    public function test_the_requirement_checklist_survives_into_the_revision(): void
    {
        SequencedReasoningProvider::reset($this->turns([$this->uncheckedWrite(), $this->checkedWrite()]));

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Store records on disk',
            'description' => "Write records to a JSON store.\n\nRequirements:\n\n- persistence\n- tests\n- README\n",
            'change_set' => [],
        ]);

        $engine = ReasoningEngine::make();
        $first = $engine->propose($this->project, $task);
        $engine->retryForViolations($this->project, $task, (string) $first->candidateUuid, $first->violations);

        $second = SequencedReasoningProvider::$requests[self::CALLS_PER_TURN];
        foreach (['persistence', 'tests', 'README'] as $requirement) {
            $this->assertMatchesRegularExpression('/^\d+\. ' . $requirement . '$/m', $second->renderText(),
                'a revision that forgets the requirements will fix one finding and drop a feature');
        }
    }
}
