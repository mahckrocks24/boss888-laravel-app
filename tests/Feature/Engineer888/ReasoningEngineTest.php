<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Audit\WriteBoundary;
use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Core\Engineer888\Reasoning\ProviderRegistry;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\ReasoningOutcome;
use App\Core\Engineer888\Reasoning\ReasoningProvider;
use App\Core\Engineer888\Workflow\ImplementStage;
use App\Core\Engineer888\Workflow\StageResult;
use App\Core\Engineer888\Workflow\VerifyStage;
use App\Core\Engineer888\Workflow\WorkflowContext;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Engineering Reasoning Engine.
 *
 * What is worth proving here is not that a model can write code. It is that a
 * model cannot escape the workflow: that its proposals are validated rather than
 * trusted, refused when they name another engineer's file, useless without a
 * human approval, and incapable of touching the filesystem at all.
 *
 * Every test uses the scripted or null provider. phpunit.e888.xml blanks every
 * API key on purpose, so a test that reached a real model would be a test that
 * silently depended on somebody's billing account.
 */
class ReasoningEngineTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-ere-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'reasoning-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        // E1-G: coverage is resolved against the PROJECT repository, so a test
        // this fixture cites has to exist in the fixture's own tree.
        @mkdir($this->repo . '/tests/Feature/Engineer888', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/Engineer888/CommandCenterTest.php', "<?php\n");
        file_put_contents($this->repo . '/tests/Feature/Engineer888/ExecutionRecoveryTest.php', "<?php\n");

        $quoted = escapeshellarg($this->repo);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── provider abstraction ────────────────────────────────────────────

    public function test_every_registered_provider_satisfies_the_interface(): void
    {
        $registry = ProviderRegistry::fromConfig();
        $this->assertNotEmpty($registry->names());

        foreach ($registry->names() as $name) {
            $provider = $registry->make($name);

            $this->assertInstanceOf(ReasoningProvider::class, $provider);
            $this->assertSame($name, $provider->name());

            $described = $provider->describe();
            foreach (['model', 'endpoint', 'deterministic', 'notes'] as $key) {
                $this->assertArrayHasKey($key, $described, "{$name} must describe its {$key}");
            }

            // An unavailable provider must say why. "It didn't work" is not a
            // reportable engineering state.
            if (! $provider->isAvailable()) {
                $this->assertNotNull($provider->unavailableReason(), "{$name} must explain its unavailability");
            }
        }
    }

    public function test_an_unregistered_provider_is_refused_rather_than_guessed(): void
    {
        $this->expectExceptionMessageMatches('/unknown reasoning provider/');

        ProviderRegistry::fromConfig()->make('a-model-that-does-not-exist');
    }

    /**
     * THE MODEL REPLACEMENT TEST.
     *
     * The same task, run twice, with two different providers. Nothing about the
     * workflow may differ — same stages, same order, same statuses, same gates —
     * and the only difference in the record is which provider produced which
     * bytes. If this test ever requires an architecture change to keep passing,
     * the provider abstraction has failed.
     */
    public function test_replacing_the_provider_changes_configuration_not_architecture(): void
    {
        $project = $this->project();
        $runs = [];

        foreach ([
            ['label' => 'provider-a', 'content' => "<?php\n// proposed by provider A\n"],
            ['label' => 'provider-b', 'content' => "<?php\n// proposed by provider B, differently\n"],
        ] as $variant) {
            $this->useScriptedProvider($this->wellFormedPayload([
                ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => $variant['content']],
            ]), $variant['label']);

            $task = $this->task($project, 'Add the reasoned file');
            (new WorkflowEngine())->approve($task->uuid, 'Mark (CEO)');

            $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);

            $runs[$variant['label']] = [
                'stages'   => array_column($result['stages'], 'stage'),
                'statuses' => array_map(fn ($s) => $s['result']->status, $result['stages']),
                'status'   => $result['status'],
                'halted'   => $result['halted_at'],
            ];

            $candidate = DB::table('engineering_candidates')->where('task_id', $task->id)->first();
            $runs[$variant['label']]['model'] = $candidate->model;
            $runs[$variant['label']]['content_fingerprint'] = $candidate->content_fingerprint;
        }

        $a = $runs['provider-a'];
        $b = $runs['provider-b'];

        $this->assertSame($a['stages'], $b['stages'], 'the lifecycle must not depend on the provider');
        $this->assertSame($a['statuses'], $b['statuses'], 'the gates must not depend on the provider');
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame($a['halted'], $b['halted']);

        $this->assertNotSame($a['model'], $b['model'], 'the record must show which provider reasoned');
        $this->assertNotSame($a['content_fingerprint'], $b['content_fingerprint'],
            'only the reasoning should differ between the two runs');
    }

    // ── the write boundary ──────────────────────────────────────────────

    public function test_the_write_boundary_detector_passes_its_own_selftest(): void
    {
        foreach (WriteBoundary::selfTest() as $case) {
            $this->assertTrue($case['passed'], 'WriteBoundary self-test: ' . $case['case'] . ' — ' . $case['detail']);
        }
    }

    public function test_nothing_in_the_reasoning_namespace_can_write_a_file(): void
    {
        $boundary = new WriteBoundary(base_path('app/Core/Engineer888/Reasoning'));

        $this->assertNotEmpty($boundary->phpFiles(), 'the scan must actually find the namespace');

        $violations = $boundary->violations();
        $this->assertSame([], $violations, 'reasoning must never reach the filesystem or a shell: '
            . json_encode(array_map(fn ($v) => basename($v['file']) . ':' . $v['line'] . ' ' . $v['symbol'], $violations)));
    }

    // ── the structured output contract ──────────────────────────────────

    public function test_a_well_formed_proposal_validates(): void
    {
        $violations = (new CandidateValidator())->violations($this->wellFormedPayload());

        $this->assertSame([], $violations);
    }

    public function test_a_proposal_missing_a_required_section_is_refused(): void
    {
        $payload = $this->wellFormedPayload();
        unset($payload['rollback']);

        $violations = (new CandidateValidator())->violations($payload);

        $this->assertNotSame([], $violations);
        $this->assertSame('missing_section', $violations[0]['rule']);
    }

    public function test_an_empty_prose_section_is_refused_because_it_reads_as_an_answer(): void
    {
        $payload = $this->wellFormedPayload();
        $payload['implementation_strategy'] = '   ';

        $rules = array_column((new CandidateValidator())->violations($payload), 'rule');

        $this->assertContains('unjustified_blank', $rules);
    }

    public function test_unknown_is_an_acceptable_answer_and_a_blank_is_not(): void
    {
        $payload = $this->wellFormedPayload();
        $payload['implementation_strategy'] = CandidateImplementation::UNKNOWN;

        $this->assertSame([], (new CandidateValidator())->violations($payload));
    }

    /** @dataProvider unsafePaths */
    public function test_a_proposal_that_targets_a_protected_path_is_refused(string $path): void
    {
        $payload = $this->wellFormedPayload([
            ['path' => $path, 'action' => 'create', 'content' => "<?php\n// no\n"],
        ]);

        $rules = array_column((new CandidateValidator())->violations($payload), 'rule');

        $this->assertContains('unsafe_path', $rules, "{$path} must never be writable by a proposal");
    }

    public static function unsafePaths(): array
    {
        return [
            'credentials'        => ['.env'],
            'git internals'      => ['.git/config'],
            'dependencies'       => ['vendor/laravel/framework/src/X.php'],
            'the test config'    => ['phpunit.e888.xml'],
            'backups'            => ['storage/app/ads-backups/x.php'],
            'the ownership data' => ['.engineer888/sprints/fixture.json'],
            'absolute'           => ['/etc/passwd'],
            'traversal'          => ['app/Owned/../../etc/passwd'],
        ];
    }

    /**
     * Regression: observed in production on 2026-08-02.
     *
     * A provider that could not determine two values wrote `'model' => UNKNOWN`
     * into the file. Honest in the reasoning, fatal in the code — an undefined
     * constant that `php -l` accepts and that throws when the line runs.
     */
    public function test_unknown_written_into_executable_code_is_refused(): void
    {
        $payload = $this->wellFormedPayload([
            ['path' => 'app/Owned/Leak.php', 'action' => 'create',
             'content' => "<?php\n\nclass Leak { public function f() { return ['model' => UNKNOWN]; } }\n"],
        ]);

        $rules = array_column((new CandidateValidator())->violations($payload), 'rule');

        $this->assertContains('unknown_leaked_into_code', $rules);
    }

    /** @dataProvider legitimateUnknowns */
    public function test_unknown_is_still_allowed_where_it_is_not_an_undefined_constant(string $body): void
    {
        $payload = $this->wellFormedPayload([
            ['path' => 'app/Owned/Fine.php', 'action' => 'create',
             'content' => "<?php\n\nclass Fine { public function f() { {$body} } }\n"],
        ]);

        $this->assertSame([], (new CandidateValidator())->violations($payload));
    }

    public static function legitimateUnknowns(): array
    {
        return [
            'a class constant' => ['return self::UNKNOWN;'],
            'a string'         => ['return "UNKNOWN";'],
            'a property'       => ['return $this->UNKNOWN;'],
            'a comment'        => ['// UNKNOWN, for now' . "\n" . 'return 1;'],
        ];
    }

    public function test_a_truncated_file_is_refused_rather_than_installed(): void
    {
        $payload = $this->wellFormedPayload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => ''],
        ]);

        $rules = array_column((new CandidateValidator())->violations($payload), 'rule');

        $this->assertContains('empty_content', $rules);
    }

    // ── unknown propagation ─────────────────────────────────────────────

    public function test_unknowns_travel_from_the_provider_to_the_approver(): void
    {
        $project = $this->project();

        $payload = $this->wellFormedPayload([
            ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => "<?php\n// x\n"],
        ]);
        $payload['unknowns'] = [[
            'question'       => 'does the caller expect a nullable return?',
            'why_it_matters' => 'a non-null contract would break three call sites',
            'how_to_resolve' => 'read the callers, or ask the requester',
        ]];
        $payload['confidence'] = 'low';

        $this->useScriptedProvider($payload);

        $task = $this->task($project, 'Something with an open question');
        $result = (new WorkflowEngine())->run($task->uuid, dryRun: true);

        $stages = $this->byStage($result);

        $this->assertStringContainsString('unresolved unknown', $stages['IDENTIFY_RISKS']->summary);
        $this->assertSame('low', $stages['ESTIMATE']->outputs['confidence'],
            'a provider that admits low confidence must not be flattered by the estimate');

        // The approver is told, in the blocking message, what is not known.
        $this->assertSame(StageResult::BLOCKED, $stages['REQUEST_APPROVAL']->status);
        $this->assertStringContainsString('nullable return', (string) $stages['REQUEST_APPROVAL']->failure);
    }

    // ── governance still applies ────────────────────────────────────────

    public function test_a_proposal_naming_another_engineers_file_is_refused_before_any_write(): void
    {
        $project = $this->project();
        $this->useScriptedProvider($this->wellFormedPayload([
            // create, not update: app/Foreign/Theirs.php does not exist in the
            // fixture repository, and update is a claim that it does. With the
            // false claim in place the candidate is now refused at PLAN, which
            // is still before any write but is not the refusal this test is
            // about — it is about OWNERSHIP being caught at IDENTIFY_FILES.
            ['path' => 'app/Foreign/Theirs.php', 'action' => 'create', 'content' => "<?php\n// not mine\n"],
        ]));

        $task = $this->task($project, 'Touch a file owned by somebody else');
        (new WorkflowEngine())->approve($task->uuid, 'Mark (CEO)');

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('IDENTIFY_FILES', $result['halted_at']);
        $this->assertFileDoesNotExist($this->repo . '/app/Foreign/Theirs.php',
            'a refused proposal must leave nothing behind');
    }

    public function test_a_reasoned_candidate_is_not_implemented_without_approval(): void
    {
        $project = $this->project();
        $this->useScriptedProvider($this->wellFormedPayload([
            ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => "<?php\n// x\n"],
        ]));

        $task = $this->task($project, 'Unapproved reasoned work');
        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('REQUEST_APPROVAL', $result['halted_at']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/Reasoned.php');
    }

    public function test_an_approved_candidate_is_installed_through_the_same_governed_path(): void
    {
        // E1-D: SourceGrounding can only prove a target absent when its parent
        // directory exists, and a create with no grounded absence is refused
        // before it can install. The directory is what a real tree looks like.
        @mkdir($this->repo . '/app/Owned', 0775, true);

        $project = $this->project();
        $content = "<?php\n\nnamespace App\\Owned;\n\nfinal class Reasoned {}\n";

        $this->useScriptedProvider($this->wellFormedPayload([
            ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => $content],
        ]));

        $task = $this->task($project, 'Install a reasoned file');
        $this->reasonAndApprove($project, $task);

        $result = (new WorkflowEngine())->run($task->uuid);
        $stages = $this->byStage($result);

        $this->assertSame(StageResult::OK, $stages['IMPLEMENT']->status, (string) $stages['IMPLEMENT']->failure);

        // SafeInstaller recorded provenance, exactly as it does for a human change.
        $this->assertStringContainsString('reasoning:', $stages['IMPLEMENT']->outputs['origin']);
        $this->assertFileExists($this->repo . '/.engineer888/installed.json');

        // The fixture repository has no artisan, so VERIFY cannot pass — and
        // from Sprint 9 a halt after a write is undone. The file being GONE is
        // the contract now; asserting it survived was asserting the defect.
        $this->assertSame('FAILED_RECOVERED', $result['status']);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/Reasoned.php',
            'a write that failed verification must not remain in the working tree');
        $this->assertSame('REMOVED', $result['recovery']['performed'][0]['action']);
    }

    /**
     * A validated candidate is not permission.
     *
     * Sprint 7 let a name on the task row stand in for approval, and the
     * consequence was that the newest candidate got installed. Nothing but an
     * approval naming this candidate will do — see CommandCenterTest for what
     * happens when the approved bytes then change.
     */
    public function test_a_candidate_nobody_approved_is_not_implemented(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Validated but unapproved');

        $this->useScriptedProvider($this->wellFormedPayload([
            ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => "<?php\n// unapproved\n"],
        ]));
        ReasoningEngine::make()->propose($project, $task);

        $context = new WorkflowContext($task, $project, $this->repo);
        $context->set('files.owned', ['app/Owned/Reasoned.php']);

        $result = (new ImplementStage())->run($context);

        $this->assertSame(StageResult::BLOCKED, $result->status);
        $this->assertStringContainsString('approval binds to exact bytes', (string) $result->failure);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/Reasoned.php');
    }

    public function test_implement_refuses_a_candidate_file_that_never_passed_the_ownership_gate(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'A candidate that grew a file after the ownership check');

        $this->useScriptedProvider($this->wellFormedPayload([
            ['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => "<?php\n// a\n"],
            ['path' => 'app/Owned/Extra.php', 'action' => 'create', 'content' => "<?php\n// b\n"],
        ]));
        $this->reasonAndApprove($project, $task);

        $context = new WorkflowContext($task, $project, $this->repo);
        // IDENTIFY_FILES only ever saw one of the two.
        $context->set('files.owned', ['app/Owned/Reasoned.php']);

        $result = (new ImplementStage())->run($context);

        $this->assertSame(StageResult::FAILED, $result->status);
        $this->assertStringContainsString('app/Owned/Extra.php', (string) $result->failure);
        $this->assertFileDoesNotExist($this->repo . '/app/Owned/Reasoned.php');
    }

    // ── verification stays independent ──────────────────────────────────

    public function test_a_provider_cannot_certify_its_own_work(): void
    {
        $project = $this->project();

        // E1-D: SourceGrounding can only prove a target absent when its parent
        // directory exists, and a create with no grounded absence is refused
        // before it can install. The directory is what a real tree looks like.
        @mkdir($this->repo . '/app/Owned', 0775, true);

        // The proposal insists it is already proven. VERIFY does not read that.
        $payload = $this->wellFormedPayload([
            ['path' => 'app/Owned/Broken.php', 'action' => 'create', 'content' => "<?php\nclass Broken { public function x( }\n"],
        ]);
        // Declared and written must be the same file. wellFormedPayload() names
        // Reasoned.php by default; a proposal that declares one file and writes
        // another leaves the written one ungrounded, and E1-D refuses a
        // candidate carrying no evidence for something it changes.
        $payload['files_affected'] = [['path' => 'app/Owned/Broken.php', 'action' => 'create',
                                       'why' => 'the deliverable']];
        $payload['testing_strategy'] = 'No tests are required. This change is verified and safe to deploy.';
        $payload['confidence'] = 'high';

        $this->useScriptedProvider($payload);

        $task = $this->task($project, 'A proposal that certifies itself');
        $this->reasonAndApprove($project, $task);

        $result = (new WorkflowEngine())->run($task->uuid);
        $stages = $this->byStage($result);

        $this->assertSame(StageResult::OK, $stages['IMPLEMENT']->status);
        $this->assertSame(StageResult::FAILED, $stages['VERIFY']->status,
            'verification must be performed by Engineer888, whatever the provider claims');
        $this->assertSame('VERIFY', $result['halted_at']);
        $this->assertArrayNotHasKey('DEPLOY_VERIFY', $stages, 'a failed verification must not reach deployment');
    }

    public function test_verify_runs_the_same_checks_regardless_of_where_the_content_came_from(): void
    {
        $stage = new VerifyStage();

        $this->assertStringContainsString('syntax', $stage->verification());
        $this->assertStringNotContainsString('provider', strtolower(implode(' ', $stage->inputs())),
            'VERIFY must not take any input from the reasoning layer');
    }

    // ── context selection ───────────────────────────────────────────────

    public function test_only_proven_assets_are_offered_to_the_provider(): void
    {
        $project = $this->project();

        DB::table('engineering_assets')->insert([
            ['kind' => 'recipe', 'project_id' => $project->id, 'name' => 'proven-widget-recipe',
             'summary' => 'how to build a widget subsystem', 'evidence_task_id' => null,
             'evidence' => 'proved by task X', 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'recipe', 'project_id' => $project->id, 'name' => 'unproven-widget-recipe',
             'summary' => 'how to build a widget subsystem', 'evidence_task_id' => null,
             'evidence' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $task = $this->task($project, 'Build a widget subsystem');
        $request = ReasoningEngine::make()->buildRequest($project, $task);

        $labels = $this->allLabels($request->sections);
        $this->assertContains('proven-widget-recipe', $labels);
        $this->assertNotContains('unproven-widget-recipe', $labels);

        $this->assertContains('no implementation evidence; only proven assets may be suggested',
            array_column($request->excluded, 'reason'),
            'an excluded asset must say why it was excluded');
    }

    public function test_knowledge_proved_in_another_project_is_never_offered(): void
    {
        $mine = $this->project();
        $theirs = $this->project('other-project', 'Other Project');

        DB::table('engineering_assets')->insert([
            'kind' => 'recipe', 'project_id' => $theirs->id, 'name' => 'their-widget-recipe',
            'summary' => 'how they build a widget subsystem', 'evidence_task_id' => null,
            'evidence' => 'proved in their codebase', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $task = $this->task($mine, 'Build a widget subsystem');
        $request = ReasoningEngine::make()->buildRequest($mine, $task);

        $this->assertNotContains('their-widget-recipe', $this->allLabels($request->sections));
        $this->assertContains('belongs to another project', array_column($request->excluded, 'reason'));
    }

    public function test_a_company_wide_asset_is_offered_to_every_project(): void
    {
        $project = $this->project();

        DB::table('engineering_assets')->insert([
            'kind' => 'coding_standard', 'project_id' => null, 'name' => 'widget-naming-standard',
            'summary' => 'widget classes are named for what they render', 'evidence_task_id' => null,
            'evidence' => 'INC-2026-006', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $task = $this->task($project, 'Build a widget subsystem');
        $request = ReasoningEngine::make()->buildRequest($project, $task);

        $this->assertContains('widget-naming-standard', $this->allLabels($request->sections));
    }

    public function test_irrelevant_knowledge_is_excluded_and_the_exclusion_is_recorded(): void
    {
        $project = $this->project();

        DB::table('engineering_assets')->insert([
            ['kind' => 'recipe', 'project_id' => $project->id, 'name' => 'widget-rendering-recipe',
             'summary' => 'widget rendering, widget lifecycle, widget state', 'evidence_task_id' => null,
             'evidence' => 'proved', 'created_at' => now(), 'updated_at' => now()],
            ['kind' => 'recipe', 'project_id' => $project->id, 'name' => 'payroll-tax-recipe',
             'summary' => 'quarterly withholding schedules for overseas contractors', 'evidence_task_id' => null,
             'evidence' => 'proved', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $task = $this->task($project, 'Build a widget subsystem');
        $request = ReasoningEngine::make()->buildRequest($project, $task);

        $labels = $this->allLabels($request->sections);
        $this->assertContains('widget-rendering-recipe', $labels);
        $this->assertNotContains('payroll-tax-recipe', $labels,
            'the company knowing something is not a reason to send it');

        $excluded = array_column($request->excluded, 'item');
        $this->assertContains('payroll-tax-recipe', $excluded);
    }

    /**
     * Regression: observed across three live attempts on 2026-08-02.
     *
     * Two providers, given everything the company knows and nothing the code
     * says, each produced a structurally valid proposal that invented a method
     * signature — and each declared that invention as its first unknown. The
     * context builder now sends the public surface of classes the task names,
     * and one hop onward, because the methods a proposal actually calls live on
     * the object the named class hands back.
     */
    public function test_the_context_carries_the_public_surface_of_classes_the_task_names(): void
    {
        $project = $this->realRepoProject();
        $task = $this->task($project, 'Extend ProviderRegistry with a readiness report');

        $sections = ReasoningEngine::make()->buildRequest($project, $task)->sections;

        $this->assertArrayHasKey('code_surface', $sections);

        $labels = $this->allLabels($sections);
        $this->assertContains(ProviderRegistry::class, $labels,
            'a task that names a class must be told what that class exposes');

        $surface = '';
        foreach ($sections['code_surface'] as $item) { $surface .= $item['body']; }

        $this->assertStringContainsString('fromConfig', $surface);
        $this->assertStringNotContainsString('$this->config', $surface,
            'signatures only — sending bodies would spend the budget answering a question nobody asked');
    }

    public function test_the_context_follows_one_hop_to_the_interface_a_named_class_returns(): void
    {
        $project = $this->realRepoProject();
        $task = $this->task($project, 'Extend ProviderRegistry with a readiness report');

        $sections = ReasoningEngine::make()->buildRequest($project, $task)->sections;
        $labels = $this->allLabels($sections);

        // ProviderRegistry::make() returns a ReasoningProvider, and that is
        // where isAvailable() and describe() live.
        $this->assertContains(ReasoningProvider::class, $labels);

        $surface = '';
        foreach ($sections['code_surface'] as $item) { $surface .= $item['body']; }
        $this->assertStringContainsString('unavailableReason', $surface);
    }

    public function test_the_code_surface_stops_at_one_hop_rather_than_crawling_the_framework(): void
    {
        $project = $this->realRepoProject();
        $task = $this->task($project, 'Extend ProviderRegistry with a readiness report');

        $sections = ReasoningEngine::make()->buildRequest($project, $task)->sections;

        $this->assertLessThanOrEqual(6, count($sections['code_surface'] ?? []),
            'transitive closure over a Laravel application reaches Illuminate within three steps');
    }

    public function test_ownership_boundaries_are_always_sent_however_the_task_is_worded(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'zzzz qqqq');   // no vocabulary overlap with anything

        $request = ReasoningEngine::make()->buildRequest($project, $task);

        $this->assertArrayHasKey('ownership_boundaries', $request->sections,
            'a proposal written without knowing the ownership boundary cannot be executed');
        $this->assertArrayHasKey('test_constraints', $request->sections);
        $this->assertArrayHasKey('deployment_constraints', $request->sections);
    }

    public function test_the_provider_receives_engineering_context_and_not_just_a_prompt(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Build a widget subsystem');

        $rendered = ReasoningEngine::make()->buildRequest($project, $task)->renderText();

        $this->assertStringContainsString('# PROJECT', $rendered);
        $this->assertStringContainsString($project->key, $rendered);
        $this->assertStringContainsString('# TASK', $rendered);
        $this->assertStringContainsString('# CONTEXT: OWNERSHIP BOUNDARIES', $rendered);
        $this->assertStringContainsString('# REQUIRED OUTPUT', $rendered);
        $this->assertStringContainsString('UNKNOWN', $rendered);
    }

    public function test_the_same_context_fingerprints_identically_and_a_changed_one_does_not(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Build a widget subsystem');

        $engine = ReasoningEngine::make();
        $first = $engine->buildRequest($project, $task);
        $second = $engine->buildRequest($project, $task);

        $this->assertSame($first->fingerprint(), $second->fingerprint());

        $other = $engine->buildRequest($project, $this->task($project, 'Build something else entirely'));
        $this->assertNotSame($first->fingerprint(), $other->fingerprint());
    }

    // ── multi-project routing ───────────────────────────────────────────

    public function test_reasoning_is_routed_by_the_project_the_task_belongs_to(): void
    {
        $first = $this->project('project-one', 'Project One');

        $secondRepo = $this->repo . '-two';
        @mkdir($secondRepo . '/app/Owned', 0775, true);
        $second = (new WorkflowEngine())->registerProject([
            'company' => 'Another Company', 'key' => 'project-two', 'name' => 'Project Two',
            'repository_path' => $secondRepo, 'phpunit_config' => 'phpunit.two.xml',
            'test_database' => 'two_test',
        ]);

        $engine = ReasoningEngine::make();
        $a = $engine->buildRequest($first, $this->task($first, 'Build a widget subsystem'));
        $b = $engine->buildRequest($second, $this->task($second, 'Build a widget subsystem'));

        $this->assertSame('project-one', $a->project['key']);
        $this->assertSame('project-two', $b->project['key']);
        $this->assertSame('Another Company', $b->project['company']);
        $this->assertNotSame($a->project['repository_path'], $b->project['repository_path']);

        // Nothing about the engine is LevelUp-specific: the same code answered
        // for a project belonging to a different company.
        $this->assertNotSame($a->fingerprint(), $b->fingerprint());

        $this->rmrf($secondRepo);
    }

    // ── degradation ─────────────────────────────────────────────────────

    public function test_with_no_provider_configured_the_workflow_blocks_rather_than_inventing_a_plan(): void
    {
        $project = $this->project();
        config(['engineer888_reasoning.provider' => 'null']);

        $task = $this->task($project, 'Anything at all');
        (new WorkflowEngine())->approve($task->uuid, 'Mark (CEO)');

        $result = (new WorkflowEngine())->run($task->uuid);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('PLAN', $result['halted_at']);
        $this->assertStringContainsString('no file changes', $this->byStage($result)['PLAN']->summary);
    }

    public function test_reasoning_can_be_switched_off_and_the_sprint_six_behaviour_returns(): void
    {
        $project = $this->project();
        config(['engineer888_reasoning.enabled' => false]);

        $task = $this->task($project, 'Anything at all');
        $result = (new WorkflowEngine())->run($task->uuid);

        $plan = $this->byStage($result)['PLAN'];
        $this->assertSame(StageResult::BLOCKED, $plan->status);
        $this->assertStringContainsString('switched off', (string) $plan->failure);
    }

    public function test_a_rejected_proposal_is_recorded_with_its_violations(): void
    {
        $project = $this->project();

        $broken = $this->wellFormedPayload();
        unset($broken['testing_strategy']);
        $this->useScriptedProvider($broken);

        $task = $this->task($project, 'A proposal that will be refused');
        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame(ReasoningOutcome::REJECTED, $outcome->status);

        $row = DB::table('engineering_candidates')->where('task_id', $task->id)->first();
        $this->assertNotNull($row, 'a refused proposal is still evidence and must be kept');
        $this->assertSame('REJECTED', $row->status);
        $this->assertStringContainsString('testing_strategy', (string) $row->violations);
    }

    public function test_a_stored_candidate_edited_in_the_database_does_not_become_installable(): void
    {
        $project = $this->project();
        $this->useScriptedProvider($this->wellFormedPayload([
            ['path' => 'app/Owned/A.php', 'action' => 'create', 'content' => "<?php\n// ok\n"],
        ]));

        $task = $this->task($project, 'Tamper with a stored candidate');
        $this->reasonAndApprove($project, $task);

        $tampered = $this->wellFormedPayload([
            ['path' => '.env', 'action' => 'update', 'content' => "APP_KEY=stolen\n"],
        ]);
        DB::table('engineering_candidates')->where('task_id', $task->id)
            ->update(['payload' => json_encode($tampered)]);

        $this->assertNull(ReasoningEngine::make()->approvedCandidate((int) $task->id),
            'stored rows are re-validated on the way out, not trusted because they once passed');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function project(string $key = 'fixture-project', string $name = 'Fixture Project'): object
    {
        return (new WorkflowEngine())->registerProject([
            'company'         => 'Fixture Co',
            'key'             => $key,
            'name'            => $name,
            'repository_path' => $this->repo,
            'phpunit_config'  => 'phpunit.e888.xml',
            'test_database'   => 'levelup_e888_test',
        ]);
    }

    /**
     * A project pointed at the real repository.
     *
     * The code-surface tests need real classes to read. Nothing they do writes:
     * buildRequest() only parses, so this stays a read of the working tree.
     */
    private function realRepoProject(): object
    {
        return (new WorkflowEngine())->registerProject([
            'company'         => 'Fixture Co',
            'key'             => 'real-repo-project',
            'name'            => 'Real Repository',
            'repository_path' => base_path(),
            'phpunit_config'  => 'phpunit.e888.xml',
            'test_database'   => 'levelup_e888_test',
        ]);
    }

    private function task(object $project, string $title): object
    {
        return (new WorkflowEngine())->createTask($project->key, [
            'title'               => $title,
            'description'         => $title . ' — fixture task for the reasoning engine.',
            'acceptance_criteria' => ['the workflow governs the result'],
            'change_set'          => [],
        ]);
    }

    /**
     * Approve a reasoned candidate the way Sprint 8 requires.
     *
     * The task row no longer grants permission. An approval names one candidate
     * and the sha256 of every byte it proposes, because in Sprint 7 a name on
     * the task row let candidate #13 be installed against an approval of #12.
     */
    private function approveLatestCandidate(object $task): string
    {
        $uuid = (string) DB::table('engineering_candidates')
            ->where('task_id', $task->id)->where('status', 'VALIDATED')
            ->orderByDesc('id')->value('uuid');

        $ledger = new ApprovalLedger();
        $ledger->approve($uuid, $ledger->forCandidateUuid($uuid)->fingerprint, 1, 'Mark (CEO)');

        return $uuid;
    }

    /** Reason once, then approve exactly what came back. */
    private function reasonAndApprove(object $project, object $task): string
    {
        ReasoningEngine::make()->propose($project, $task);

        return $this->approveLatestCandidate($task);
    }

    private function useScriptedProvider(array $payload, string $label = 'scripted-fixture'): void
    {
        config([
            'engineer888_reasoning.provider'          => 'scripted',
            'engineer888_reasoning.scripted.response' => $payload,
            'engineer888_reasoning.scripted.label'    => $label,
        ]);
    }

    private function wellFormedPayload(array $fileChanges = []): array
    {
        return [
            'problem_understanding'   => 'A fixture file is required under an owned path.',
            'assumptions'             => ['the destination directory may not exist yet'],
            'unknowns'                => [],
            'implementation_strategy' => 'Create the file with the declared content.',
            'files_affected'          => [['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'why' => 'the deliverable']],
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

    /** @return array<int,string> */
    private function allLabels(array $sections): array
    {
        $labels = [];
        foreach ($sections as $items) {
            foreach ($items as $item) { $labels[] = (string) ($item['label'] ?? ''); }
        }

        return $labels;
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
