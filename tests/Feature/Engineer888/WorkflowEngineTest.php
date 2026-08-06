<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Workflow\ImplementStage;
use App\Core\Engineer888\Workflow\RequestApprovalStage;
use App\Core\Engineer888\Workflow\Stage;
use App\Core\Engineer888\Workflow\StageResult;
use App\Core\Engineer888\Workflow\WorkflowContext;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The engineering workflow.
 *
 * The claims worth testing are the gates: that nothing is implemented without
 * approval, that a halt stops everything downstream, that ownership is proved
 * before a write, and that an asset cannot be promoted without evidence.
 *
 * Fixtures only — no test here writes to the real repository.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-wf-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'workflow-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        $quoted = escapeshellarg($this->repo);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the process itself ──────────────────────────────────────────────

    public function test_the_lifecycle_has_all_fourteen_stages_in_order(): void
    {
        $names = array_map(fn (Stage $s) => $s->name(), WorkflowEngine::stages());

        $this->assertSame([
            'RECEIVE', 'UNDERSTAND', 'ANALYZE', 'PLAN', 'ESTIMATE', 'IDENTIFY_FILES',
            'IDENTIFY_RISKS', 'REQUEST_APPROVAL', 'IMPLEMENT', 'VERIFY', 'DEPLOY_VERIFY',
            'DOCUMENT', 'LEARN', 'PROMOTE',
        ], $names);
    }

    public function test_every_stage_declares_its_contract(): void
    {
        foreach (WorkflowEngine::stages() as $stage) {
            $this->assertNotSame('', $stage->purpose(), $stage->name() . ' must state its purpose');
            $this->assertNotEmpty($stage->inputs(), $stage->name() . ' must declare inputs');
            $this->assertNotEmpty($stage->outputs(), $stage->name() . ' must declare outputs');
            $this->assertNotEmpty($stage->failureModes(),
                $stage->name() . ' must say how it fails — a stage that cannot has not been thought through');
            $this->assertNotSame('', $stage->verification(), $stage->name() . ' must state verification');
            $this->assertNotSame('', $stage->recovery(), $stage->name() . ' must state recovery');
        }
    }

    // ── gates ───────────────────────────────────────────────────────────

    public function test_implement_never_runs_without_approval(): void
    {
        $context = $this->context(['change_set' => ['app/Owned/A.php' => 'src.php']]);

        $approval = (new RequestApprovalStage())->run($context);
        $this->assertSame(StageResult::BLOCKED, $approval->status);
        $this->assertTrue($approval->halts());

        // Even if IMPLEMENT is called directly, it refuses.
        $implement = (new ImplementStage())->run($context);
        $this->assertSame(StageResult::BLOCKED, $implement->status);
        $this->assertStringContainsString('never runs without approval', (string) $implement->failure);
    }

    public function test_a_blocked_stage_halts_everything_downstream(): void
    {
        $task = $this->createTask(['change_set' => []]);   // no change set -> PLAN blocks

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('PLAN', $result['halted_at']);

        $ran = array_column($result['stages'], 'stage');
        $this->assertContains('ANALYZE', $ran);
        $this->assertNotContains('IMPLEMENT', $ran, 'nothing downstream of a halt may run');
        $this->assertNotContains('DEPLOY_VERIFY', $ran);
    }

    public function test_a_file_this_sprint_does_not_own_blocks_before_any_write(): void
    {
        $this->write('app/Foreign/Theirs.php', "<?php\n// another engineer\n");
        $task = $this->createTask(['change_set' => ['app/Foreign/Theirs.php' => 'src.php']]);

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('IDENTIFY_FILES', $result['halted_at']);
        $this->assertStringContainsString('// another engineer',
            file_get_contents($this->repo . '/app/Foreign/Theirs.php'),
            'their file must be untouched');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->write('src/new.php', "<?php\n// incoming\n");
        $task = $this->createTask(['change_set' => ['app/Owned/New.php' => 'src/new.php']]);
        (new WorkflowEngine())->approve($task->uuid, 'tester');

        $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);

        $this->assertFileDoesNotExist($this->repo . '/app/Owned/New.php');
        $implement = $this->stage($result, 'IMPLEMENT');
        $this->assertSame(StageResult::SKIPPED, $implement->status);
    }

    // ── audit trail ─────────────────────────────────────────────────────

    public function test_every_stage_that_ran_is_recorded_with_its_contract(): void
    {
        $task = $this->createTask(['change_set' => []]);
        (new WorkflowEngine())->run($task->uuid);

        $rows = DB::table('engineering_task_stages')->where('task_id', $task->id)->orderBy('position')->get();

        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $evidence = json_decode((string) $row->evidence, true);
            $this->assertNotEmpty($evidence['failure_modes'], $row->stage . ' must record how it can fail');
            $this->assertNotSame('', $evidence['verification']);
            $this->assertNotSame('', $evidence['recovery']);

            $inputs = json_decode((string) $row->inputs, true);
            $this->assertNotSame('', $inputs['purpose'] ?? '');
        }
    }

    public function test_re_running_replaces_the_trail_rather_than_appending(): void
    {
        $task = $this->createTask(['change_set' => []]);
        $engine = new WorkflowEngine();

        $engine->run($task->uuid);
        $first = DB::table('engineering_task_stages')->where('task_id', $task->id)->count();

        $engine->run($task->uuid);
        $second = DB::table('engineering_task_stages')->where('task_id', $task->id)->count();

        $this->assertSame($first, $second, 'the trail must describe the latest run, not every run concatenated');
    }

    // ── project abstraction ─────────────────────────────────────────────

    public function test_projects_are_rows_so_engineer888_is_not_tied_to_one_codebase(): void
    {
        $engine = new WorkflowEngine();

        $first = $engine->registerProject([
            'company' => 'LevelUp Growth', 'key' => 'project-a',
            'name' => 'Project A', 'repository_path' => $this->repo,
        ]);
        $second = $engine->registerProject([
            'company' => 'LevelUp Growth', 'key' => 'project-b',
            'name' => 'Project B', 'repository_path' => $this->repo . '-other',
            'test_database' => 'levelup_b_test',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('Project B', $engine->project('project-b')->name);
        $this->assertSame('levelup_b_test', $engine->project('project-b')->test_database);

        // Registering the same key twice returns the existing row.
        $this->assertSame($first->id, $engine->registerProject([
            'key' => 'project-a', 'name' => 'Project A', 'repository_path' => $this->repo,
        ])->id);
    }

    // ── asset promotion ─────────────────────────────────────────────────

    public function test_an_asset_cannot_be_promoted_without_evidence(): void
    {
        $task = $this->createTask(['change_set' => []]);
        (new WorkflowEngine())->run($task->uuid);   // halts at PLAN, so LEARN never runs

        $this->assertSame(0, DB::table('engineering_assets')->count(),
            'a workflow that never reached LEARN must promote nothing');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function createTask(array $attributes): object
    {
        $engine = new WorkflowEngine();
        $engine->registerProject([
            'company' => 'LevelUp Growth', 'key' => 'fixture-project',
            'name' => 'Fixture', 'repository_path' => $this->repo,
            'test_database' => 'levelup_e888_test',
        ]);

        return $engine->createTask('fixture-project', array_merge([
            'title' => 'Fixture task', 'description' => 'A task used by the workflow tests.',
        ], $attributes));
    }

    private function context(array $attributes): WorkflowContext
    {
        $task = $this->createTask($attributes);
        $project = DB::table('engineering_projects')->find($task->project_id);

        return new WorkflowContext($task, $project, $this->repo);
    }

    private function stage(array $result, string $name): StageResult
    {
        foreach ($result['stages'] as $entry) {
            if ($entry['stage'] === $name) { return $entry['result']; }
        }

        $this->fail("stage {$name} did not run");
    }

    private function write(string $path, string $content): void
    {
        $full = $this->repo . '/' . $path;
        if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
        file_put_contents($full, $content);
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
