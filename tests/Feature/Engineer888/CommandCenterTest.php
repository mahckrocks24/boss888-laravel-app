<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalBinding;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Support\UnifiedDiff;
use App\Core\Engineer888\Workflow\ImplementStage;
use App\Core\Engineer888\Workflow\StageResult;
use App\Core\Engineer888\Workflow\WorkflowContext;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use App\Http\Controllers\Api\Admin\Engineer888Controller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Engineer888 Command Center.
 *
 * The claim under test is narrow and it is the whole sprint: an approval names
 * exact bytes, and IMPLEMENT will not write anything else. Sprint 7 approved
 * candidate #12 and installed candidate #13 — both valid, neither the same
 * thing — because approval was a name on a task row.
 *
 * Everything else here guards the perimeter around that claim: who may reach
 * the surface, what a rejection does, and that one revision is one.
 */
class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-cc-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'command-center-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        $quoted = escapeshellarg($this->repo);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── authorization ───────────────────────────────────────────────────

    public function test_every_engineer888_route_requires_an_authenticated_admin(): void
    {
        $routes = $this->engineerRoutes();

        $this->assertNotEmpty($routes, 'the Engineer888 route module is not registered');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth.jwt', $middleware, "{$route->uri()} is missing auth.jwt");
            $this->assertContains('admin', $middleware,
                "{$route->uri()} is missing the admin gate — hidden in the UI is not a permission");
        }
    }

    public function test_no_engineer888_route_can_be_driven_by_an_api_key(): void
    {
        foreach ($this->engineerRoutes() as $route) {
            $this->assertContains(\App\Http\Middleware\DenyApiKeyAuth::class, $route->gatherMiddleware(),
                "{$route->uri()} is reachable by a machine credential. Engineer888 writes source files "
                . 'into the running platform and an API key has no name to put on an approval.');
        }
    }

    public function test_engineer888_is_not_exposed_outside_the_admin_prefix(): void
    {
        $leaked = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'engineer888') && ! str_starts_with($r->uri(), 'api/admin/'))
            ->map(fn ($r) => $r->uri())
            ->all();

        $this->assertSame([], $leaked, 'Engineer888 must exist only under api/admin');
    }

    public function test_the_admin_page_is_registered_and_separate_from_platform_engineering(): void
    {
        $pages = config('admin_pages', []);

        $this->assertArrayHasKey('e888Tasks', $pages);
        $this->assertSame('engineer888', $pages['e888Tasks']['slug']);
        $this->assertSame('Engineer888', $pages['e888Tasks']['group']);

        // The observability surface must not still be called Engineering, or the
        // two domains are a coin toss every time somebody opens the sidebar.
        $groups = array_column($pages, 'group');
        $this->assertNotContains('Engineering', $groups);
        $this->assertContains('Platform Engineering', $groups);
    }

    // ── the fingerprint contract ────────────────────────────────────────

    public function test_a_fingerprint_is_deterministic_for_the_same_bytes(): void
    {
        $a = $this->binding([['app/Owned/A.php', "<?php\n// one\n"], ['app/Owned/B.php', "<?php\n// two\n"]]);
        $b = $this->binding([['app/Owned/A.php', "<?php\n// one\n"], ['app/Owned/B.php', "<?php\n// two\n"]]);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertSame(64, strlen($a->fingerprint()), 'sha256, hex');
    }

    public function test_file_order_does_not_change_the_fingerprint_but_content_does(): void
    {
        $forward = $this->binding([['app/Owned/A.php', "<?php\n// a\n"], ['app/Owned/B.php', "<?php\n// b\n"]]);
        $reverse = $this->binding([['app/Owned/B.php', "<?php\n// b\n"], ['app/Owned/A.php', "<?php\n// a\n"]]);

        $this->assertSame($forward->fingerprint(), $reverse->fingerprint(),
            'the same two files in the other order are the same change');

        $changed = $this->binding([['app/Owned/A.php', "<?php\n// a\n"], ['app/Owned/B.php', "<?php\n// B!\n"]]);
        $this->assertNotSame($forward->fingerprint(), $changed->fingerprint());
    }

    /** @dataProvider bindingFields */
    public function test_changing_any_bound_field_changes_the_fingerprint(string $field): void
    {
        $base = $this->binding([['app/Owned/A.php', "<?php\n// a\n"]]);

        $altered = new ApprovalBinding(
            $field === 'candidate' ? 'other-uuid' : $base->candidateUuid,
            $field === 'task' ? 'other-task' : $base->taskUuid,
            $field === 'project' ? 'other-project' : $base->projectKey,
            $field === 'provider' ? 'other-provider' : $base->provider,
            $field === 'model' ? 'other-model' : $base->model,
            $field === 'path' ? ['app/Owned/Z.php' => array_values($base->fileHashes)[0]] : $base->fileHashes,
        );

        $this->assertNotSame($base->fingerprint(), $altered->fingerprint(),
            "changing the {$field} must change what was approved");
        $this->assertNotEmpty($altered->differencesFrom($base));
    }

    public static function bindingFields(): array
    {
        return [['candidate'], ['task'], ['project'], ['provider'], ['model'], ['path']];
    }

    // ── the approval binds to exact bytes ───────────────────────────────

    public function test_implement_installs_the_approved_bytes_without_recalling_the_provider(): void
    {
        // E1-D: SourceGrounding can only prove a target absent when its parent
        // directory exists, and a create with no grounded absence is refused
        // before it can install. The directory is what a real tree looks like.
        @mkdir($this->repo . '/app/Owned', 0775, true);

        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n\nnamespace App\\Owned;\n\nfinal class A {}\n");
        $this->approve($uuid);

        // Point the provider at completely different content. If IMPLEMENT ever
        // reasons again, the file on disk will show it.
        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// NEVER APPROVED\n"],
        ]));

        $context = $this->context($task, $project, ['app/Owned/A.php']);
        $result = (new ImplementStage())->run($context);

        $this->assertSame(StageResult::OK, $result->status, (string) $result->failure);
        $this->assertStringContainsString('final class A',
            (string) file_get_contents($this->repo . '/app/Owned/A.php'));
        $this->assertStringNotContainsString('NEVER APPROVED',
            (string) file_get_contents($this->repo . '/app/Owned/A.php'));
    }

    public function test_changed_bytes_invalidate_the_approval(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// approved\n");
        $this->approve($uuid);

        // Edit the stored candidate after approval, as a tampering database
        // write or a bad migration would.
        $tampered = $this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// tampered\n"],
        ]);
        DB::table('engineering_candidates')->where('uuid', $uuid)->update(['payload' => json_encode($tampered)]);

        $result = (new ImplementStage())->run($this->context($task, $project, ['app/Owned/A.php']));

        $this->assertSame(StageResult::BLOCKED, $result->status);
        $this->assertStringContainsString('changed content', (string) $result->failure);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
    }

    public function test_reasoning_again_supersedes_the_approval_rather_than_carrying_it_forward(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// first\n");
        $this->approve($uuid);

        $this->assertSame(ApprovalState::APPROVED,
            (new ApprovalLedger())->forCandidateUuid($uuid)->state);

        // A second proposal arrives.
        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// second\n"],
        ]));
        ReasoningEngine::make()->propose($project, $task);

        $this->assertSame(ApprovalState::SUPERSEDED,
            (new ApprovalLedger())->forCandidateUuid($uuid)->state,
            'the older approval must stop being permission the moment a newer candidate exists');

        $result = (new ImplementStage())->run($this->context($task, $project, ['app/Owned/A.php']));

        $this->assertSame(StageResult::BLOCKED, $result->status);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
    }

    public function test_plan_does_not_re_reason_behind_an_approval(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// approved\n");
        $this->approve($uuid);

        $before = DB::table('engineering_candidates')->where('task_id', $task->id)->count();

        // If PLAN reasons, this content would become the plan.
        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// would be new\n"],
        ]));

        $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);
        $stages = $this->byStage($result);

        $this->assertSame($before, DB::table('engineering_candidates')->where('task_id', $task->id)->count(),
            'PLAN produced a new candidate behind an approval — the Sprint 7 defect');
        $this->assertStringContainsString('approved candidate', $stages['PLAN']->summary);
        $this->assertSame(StageResult::OK, $stages['REQUEST_APPROVAL']->status);
    }

    /** @dataProvider terminalStates */
    public function test_a_candidate_in_a_terminal_approval_state_cannot_execute(string $state): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// x\n");
        $this->approve($uuid);

        DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)
            ->update(['state' => $state]);

        $result = (new ImplementStage())->run($this->context($task, $project, ['app/Owned/A.php']));

        $this->assertSame(StageResult::BLOCKED, $result->status, "state {$state} must not permit a write");
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
    }

    public static function terminalStates(): array
    {
        return [['REJECTED'], ['REVOKED'], ['SUPERSEDED'], ['EXPIRED'], ['PENDING']];
    }

    public function test_an_expired_approval_cannot_execute_and_is_recorded_as_expired(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// x\n");
        $this->approve($uuid);

        DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)
            ->update(['expires_at' => now()->subMinute()]);

        $result = (new ImplementStage())->run($this->context($task, $project, ['app/Owned/A.php']));

        $this->assertSame(StageResult::BLOCKED, $result->status);
        $this->assertStringContainsString('expired', strtolower((string) $result->failure));
        $this->assertSame(ApprovalState::EXPIRED,
            (new ApprovalLedger())->forCandidateUuid($uuid)->state);
    }

    public function test_ownership_drift_stops_the_write_even_with_a_valid_approval(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// x\n");
        $this->approve($uuid);

        // IDENTIFY_FILES proved a different file. The approved path never passed
        // the ownership gate in this run.
        $result = (new ImplementStage())->run($this->context($task, $project, ['app/Owned/Something else.php']));

        $this->assertSame(StageResult::FAILED, $result->status);
        $this->assertStringContainsString('ownership gate', (string) $result->failure);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/A.php');
    }

    public function test_the_fingerprint_submitted_with_an_approval_is_compared_not_trusted(): void
    {
        [, , $uuid] = $this->reasonedCandidate("<?php\n// x\n");

        $this->expectExceptionMessageMatches('/does not match this candidate/');

        (new ApprovalLedger())->approve($uuid, str_repeat('0', 64), 1, 'Mark');
    }

    public function test_an_approval_is_never_re_opened(): void
    {
        [, , $uuid] = $this->reasonedCandidate("<?php\n// x\n");
        $ledger = new ApprovalLedger();
        $fingerprint = $ledger->forCandidateUuid($uuid)->fingerprint;

        $ledger->approve($uuid, $fingerprint, 1, 'Mark');

        $this->expectExceptionMessageMatches('/not pending/');
        $ledger->approve($uuid, $fingerprint, 2, 'Somebody else');
    }

    // ── bounded revision ────────────────────────────────────────────────

    public function test_one_bounded_revision_is_allowed(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// first\n");

        (new ApprovalLedger())->reject($uuid, 1, 'Mark', 'Use the existing service instead');

        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// revised\n"],
        ]));

        $outcome = ReasoningEngine::make()->revise($project, $task, $uuid, 'Use the existing service instead');

        $this->assertTrue($outcome->isValidated());

        $revision = DB::table('engineering_candidates')->where('uuid', $outcome->candidateUuid)->first();
        $this->assertNotNull($revision->revision_of, 'a revision must record what it revises');
        $this->assertSame('Use the existing service instead', $revision->revision_instruction);
    }

    public function test_a_second_revision_is_refused_and_the_task_needs_a_human(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// first\n");

        $first = ReasoningEngine::make()->revise($project, $task, $uuid, 'try again');
        $this->assertTrue($first->isValidated());

        $second = ReasoningEngine::make()->revise($project, $task, (string) $first->candidateUuid, 'and again');

        $this->assertFalse($second->isValidated());
        $this->assertStringContainsString('already had its one revision', $second->reason);
    }

    public function test_a_revision_is_told_why_it_was_rejected(): void
    {
        [$task, $project, $uuid] = $this->reasonedCandidate("<?php\n// first\n");

        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// revised\n"],
        ]));

        $request = ReasoningEngine::make()->buildRequest($project, $task, [
            'of' => (int) DB::table('engineering_candidates')->where('uuid', $uuid)->value('id'),
            'instruction' => 'Do not modify the public API',
            'prior' => DB::table('engineering_candidates')->where('uuid', $uuid)->first(),
        ]);

        $this->assertArrayHasKey('revision_instruction', $request->sections);
        $this->assertStringContainsString('Do not modify the public API', $request->renderText());
    }

    // ── the API surface ─────────────────────────────────────────────────

    public function test_a_task_can_be_created_through_the_api(): void
    {
        $project = $this->project();

        $response = (new Engineer888Controller())->createTask($this->adminRequest([
            'project'     => $project->key,
            'title'       => 'Created from the Command Center',
            'description' => 'A task created through the admin API rather than the terminal.',
            'priority'    => 'high',
            'acceptance_criteria' => ['it exists'],
        ]));

        $this->assertSame(201, $response->getStatusCode());

        $uuid = json_decode($response->getContent(), true)['uuid'];
        $task = DB::table('engineering_tasks')->where('uuid', $uuid)->first();

        $this->assertSame('high', $task->priority);
        $this->assertStringContainsString('mark@', strtolower((string) $task->requested_by),
            'the human actor must be recorded, not the system');
    }

    public function test_the_task_list_filters_and_reports_approval_state(): void
    {
        [$task, , $uuid] = $this->reasonedCandidate("<?php\n// x\n");

        $controller = new Engineer888Controller();

        $all = json_decode($controller->tasks($this->adminRequest([], 'GET'))->getContent(), true);
        $this->assertGreaterThan(0, $all['count']);
        $this->assertSame(ApprovalState::PENDING, $all['tasks'][0]['approval_state']);

        $awaiting = json_decode($controller->tasks($this->adminRequest(['awaiting' => '1'], 'GET'))->getContent(), true);
        $this->assertSame(1, $awaiting['count']);

        $none = json_decode($controller->tasks($this->adminRequest(['status' => 'completed'], 'GET'))->getContent(), true);
        $this->assertSame(0, $none['count']);
    }

    public function test_the_timeline_lists_all_fourteen_stages_even_before_a_run(): void
    {
        [$task] = $this->reasonedCandidate("<?php\n// x\n");

        $d = json_decode((new Engineer888Controller())
            ->task($this->adminRequest([], 'GET'), $task->uuid)->getContent(), true);

        $this->assertCount(14, $d['stages']);
        $this->assertSame('RECEIVE', $d['stages'][0]['stage']);
        $this->assertSame('PROMOTE', $d['stages'][13]['stage']);
        foreach ($d['stages'] as $stage) {
            $this->assertSame('PENDING', $stage['state'], 'an unrun stage is PENDING, never a success label');
        }
    }

    public function test_the_candidate_screen_shows_the_exact_diff_and_the_fingerprint_it_binds(): void
    {
        $content = "<?php\n\nnamespace App\\Owned;\n\nfinal class A\n{\n    public function b(): int { return 1; }\n}\n";
        [, , $uuid] = $this->reasonedCandidate($content);

        $d = json_decode((new Engineer888Controller())
            ->candidate($this->adminRequest([], 'GET'), $uuid)->getContent(), true);

        $this->assertCount(1, $d['files']);
        $this->assertSame('app/Owned/A.php', $d['files'][0]['path']);
        $this->assertSame('CREATE', $d['files'][0]['action']);
        $this->assertSame($content, $d['files'][0]['proposed'], 'the reviewer must see the exact bytes');
        $this->assertSame('OWNED', $d['files'][0]['ownership']);
        $this->assertGreaterThan(0, $d['totals']['additions']);

        // The fingerprint shown is the one the ledger holds.
        $this->assertSame(
            (new ApprovalLedger())->forCandidateUuid($uuid)->fingerprint,
            $d['binding']['fingerprint']
        );
    }

    public function test_approval_through_the_api_requires_the_exact_statement(): void
    {
        [, , $uuid] = $this->reasonedCandidate("<?php\n// x\n");
        $fingerprint = (new ApprovalLedger())->forCandidateUuid($uuid)->fingerprint;

        $controller = new Engineer888Controller();

        $wrong = $controller->approve($this->adminRequest([
            'fingerprint' => $fingerprint,
            'statement'   => 'I approve.',
        ]), $uuid);
        $this->assertSame(422, $wrong->getStatusCode(),
            'a generic statement would let a click mean anything');

        $right = $controller->approve($this->adminRequest([
            'fingerprint' => $fingerprint,
            'statement'   => (new ApprovalLedger())->statement($uuid, $fingerprint),
            'comment'     => 'read the diff line by line',
        ]), $uuid);

        $this->assertSame(200, $right->getStatusCode());

        $approval = (new ApprovalLedger())->forCandidateUuid($uuid);
        $this->assertSame(ApprovalState::APPROVED, $approval->state);
        $this->assertStringContainsString('mark@', strtolower((string) $approval->approver_name));
        $this->assertNotNull($approval->expires_at);
    }

    // ── diff correctness ────────────────────────────────────────────────

    public function test_the_diff_reports_true_counts(): void
    {
        $diff = UnifiedDiff::between("a\nb\nc\n", "a\nB\nc\nd\n");

        $this->assertSame(2, $diff['additions']);
        $this->assertSame(1, $diff['deletions']);
        $this->assertFalse($diff['binary']);
    }

    public function test_a_new_file_is_all_additions(): void
    {
        $diff = UnifiedDiff::between('', "one\ntwo\n");

        $this->assertSame(2, $diff['additions']);
        $this->assertSame(0, $diff['deletions']);
    }

    // ── project isolation, still ────────────────────────────────────────

    public function test_the_project_view_reports_per_project_counts_and_scoping(): void
    {
        $mine = $this->project();
        $theirs = $this->project('second-project', 'Second Project');

        $this->reasonedCandidate("<?php\n// x\n", $mine);

        $d = json_decode((new Engineer888Controller())
            ->projects($this->adminRequest([], 'GET'))->getContent(), true);

        $byKey = collect($d['projects'])->keyBy('key');

        $this->assertSame(1, $byKey['fixture-project']['tasks']['total']);
        $this->assertSame(0, $byKey['second-project']['tasks']['total']);
        $this->assertSame($this->repo, $byKey['fixture-project']['repository_path']);
    }

    public function test_other_engineers_directories_are_outside_engineer888_ownership(): void
    {
        $manifest = \App\Core\Engineer888\Coordination\OwnershipManifest::active(base_path());
        $this->assertNotNull($manifest, 'the live sprint manifest must load');

        foreach ([
            'app/Engines/Studio/Services/StudioService.php',
            'app/Engines/Infrastructure/Services/DomainRenewalService.php',
            'app/Core/ImageIntelligence/ImageReasoningService.php',
        ] as $path) {
            $this->assertFalse(
                \App\Core\Engineer888\Coordination\OwnershipManifest::isCommittable($manifest->classify($path)['status']),
                "{$path} belongs to another engineer and Engineer888 must not be able to write it"
            );
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function engineerRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/engineer888'))
            ->all();
    }

    private function project(string $key = 'fixture-project', string $name = 'Fixture Project'): object
    {
        return (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => $key, 'name' => $name,
            'repository_path' => $this->repo, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);
    }

    /** @return array{0:object,1:object,2:string} task, project, candidate uuid */
    private function reasonedCandidate(string $content, ?object $project = null): array
    {
        $project ??= $this->project();

        $task = (new WorkflowEngine())->createTask($project->key, [
            'title' => 'Command Center fixture',
            'description' => 'A fixture task for the exact-content approval tests.',
            'acceptance_criteria' => ['the approval binds to exact bytes'],
            'change_set' => [],
        ]);

        $this->useScriptedProvider($this->payload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => $content],
        ]));

        $outcome = ReasoningEngine::make()->propose($project, $task);
        $this->assertTrue($outcome->isValidated(), $outcome->reason);

        return [$task, $project, (string) $outcome->candidateUuid];
    }

    private function approve(string $uuid): void
    {
        $ledger = new ApprovalLedger();
        $ledger->approve($uuid, $ledger->forCandidateUuid($uuid)->fingerprint, 1, 'Mark (CEO)');
    }

    private function binding(array $files): ApprovalBinding
    {
        $changes = [];
        foreach ($files as [$path, $content]) {
            $changes[] = ['path' => $path, 'action' => 'create', 'content' => $content];
        }

        $candidate = new CandidateImplementation($this->payload($changes), 'scripted', 'fixture', 'req');

        return ApprovalBinding::forCandidate($candidate, 'candidate-uuid', 'task-uuid', 'project-key');
    }

    private function context(object $task, object $project, array $owned): WorkflowContext
    {
        $context = new WorkflowContext(
            DB::table('engineering_tasks')->find($task->id),
            $project,
            $this->repo
        );
        $context->set('files.owned', $owned);

        return $context;
    }

    private function adminRequest(array $data = [], string $method = 'POST'): Request
    {
        $request = Request::create('/api/admin/engineer888', $method,
            $method === 'GET' ? $data : []);

        if ($method !== 'GET') { $request->merge($data); }

        $user = (object) ['id' => 1, 'name' => 'Mark', 'email' => 'mark@levelupgrowth.io',
                          'is_platform_admin' => true];
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function useScriptedProvider(array $payload): void
    {
        config([
            'engineer888_reasoning.provider'          => 'scripted',
            'engineer888_reasoning.scripted.response' => $payload,
            'engineer888_reasoning.scripted.label'    => 'scripted-fixture',
        ]);
    }

    private function payload(array $fileChanges): array
    {
        return [
            'problem_understanding'   => 'A fixture file is required under an owned path.',
            'assumptions'             => ['the destination directory may not exist yet'],
            'unknowns'                => [],
            'implementation_strategy' => 'Create the file with the declared content.',
            'files_affected'          => [['path' => 'app/Owned/A.php', 'action' => 'create', 'why' => 'the deliverable']],
            'migrations'              => ['required' => false, 'detail' => 'no schema change'],
            'risks'                   => [['risk' => 'none material', 'breaks' => 'nothing', 'mitigation' => 'n/a']],
            'testing_strategy'        => 'php -l, then the project suite.',
            'rollback'                => 'SafeInstaller backs up before any overwrite.',
            'file_changes'            => $fileChanges,
            // Added in Sprint 10: a candidate that changes executable behaviour
            // must account for its own coverage. These fixtures exercise the
            // approval and enforcement path, which this suite already covers, so
            // they name it rather than proposing a test they do not need.
            'test_coverage' => [
                'behaviour_changed'    => 'installs a fixture class used to exercise the approval gate',
                'test_files_proposed'  => [],
                'existing_tests'       => ['tests/Feature/Engineer888/CommandCenterTest.php'],
                'expected_assertions'  => ['the approved bytes are the bytes installed'],
                'regression_prevented' => 'an approval that does not bind to exact content',
                'test_database'        => 'levelup_e888_test',
                'full_suite_required'  => false,
                'gaps'                 => [],
                'classification'       => 'TESTED',
                'confidence'           => 'high',
            ],
            'confidence'              => 'high',
            'confidence_basis'        => 'the change is a single new file',
        ];
    }

    /** @return array<string,StageResult> */
    private function byStage(array $result): array
    {
        $stages = [];
        foreach ($result['stages'] as $entry) { $stages[$entry['stage']] = $entry['result']; }

        return $stages;
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
