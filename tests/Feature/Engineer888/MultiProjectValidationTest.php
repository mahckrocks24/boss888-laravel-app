<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Engineer888 governs projects. It is not one of them.
 *
 * THE DEFECT, AS MEASURED IN E1-F. The validator was constructed once against
 * base_path() and every candidate for every project was judged against
 * Engineer888's own tree. With one project that is invisible. With two it is
 * incoherent: SourceGrounding told the sandbox's model that tests/GreeterTest.php
 * existed — it does, in the sandbox — and the validator then refused the
 * candidate for citing it, because it does not exist here. Two real DeepSeek
 * runs and the engine's own governed retry all died on that contradiction, and
 * the model was right every time.
 *
 * So these tests are mostly about WHICH repository answers a question. The same
 * citation must validate for a project that has the file and refuse for one that
 * does not, and neither answer may depend on where Engineer888 happens to live.
 */
class MultiProjectValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-multi-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
        $this->seedRepository($this->repo, ['app/**', 'tests/**']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the citation is resolved against the project ────────────────────

    public function test_a_test_that_exists_only_in_the_project_is_accepted(): void
    {
        // The exact shape E1-F could not get past: a project's own test, which
        // Engineer888's repository has never heard of.
        $this->write('tests/GreeterTest.php', "<?php\n// the project's own test\n");

        $violations = $this->validator()->violations(
            $this->payload(['tests/GreeterTest.php'])
        );

        $this->assertSame([], $violations,
            'a project may cite its own tests: ' . json_encode($violations));
    }

    public function test_a_test_that_exists_only_in_engineer888_is_refused(): void
    {
        $engineerOwnTest = 'tests/Feature/Engineer888/CommandCenterTest.php';

        $this->assertFileExists(base_path() . '/' . $engineerOwnTest,
            'the premise of this test is that this file exists HERE');
        $this->assertFileDoesNotExist($this->repo . '/' . $engineerOwnTest,
            'and not in the project');

        $rules = array_column($this->validator()->violations(
            $this->payload([$engineerOwnTest])
        ), 'rule');

        $this->assertContains('invented_coverage', $rules,
            'Engineer888 owning a file is not evidence that the project under change does');
    }

    public function test_the_same_citation_is_answered_differently_by_two_projects(): void
    {
        $other = $this->repo . '-second';
        $this->seedRepository($other, ['app/**', 'tests/**']);

        $this->write('tests/GreeterTest.php', "<?php\n");           // present in one
        // deliberately absent from the other

        $payload = $this->payload(['tests/GreeterTest.php']);

        $this->assertSame([], (new CandidateValidator(12, 60000, $this->repo))->violations($payload));
        $this->assertContains('invented_coverage',
            array_column((new CandidateValidator(12, 60000, $other))->violations($payload), 'rule'));

        $this->rmrf($other);
    }

    // ── ownership is the project's manifest ─────────────────────────────

    public function test_a_proposed_test_is_owned_by_the_projects_manifest(): void
    {
        $proposed = 'tests/Feature/Sandbox/GreeterTest.php';

        $violations = $this->validator()->violations(
            $this->payload([], [$proposed => "<?php\n// asserts Greeter\nclass X { function t() { new Greeter(); } }\n"])
        );

        $this->assertSame([], $violations,
            'the project manifest owns tests/**, so this is the project\'s own test: '
            . json_encode($violations));
    }

    public function test_a_proposed_test_the_project_does_not_own_is_refused(): void
    {
        // A project whose sprint owns only app/. Its tests belong to somebody else.
        $narrow = $this->repo . '-narrow';
        $this->seedRepository($narrow, ['app/**']);

        $rules = array_column((new CandidateValidator(12, 60000, $narrow))->violations(
            $this->payload([], ['tests/Feature/Sandbox/GreeterTest.php' => "<?php\n// Greeter\n"])
        ), 'rule');

        $this->assertContains('unowned_test_path', $rules);

        $this->rmrf($narrow);
    }

    public function test_a_project_with_no_usable_sprint_leaves_ownership_unprovable(): void
    {
        // A real repository with no manifest the process can resolve. Ownership
        // is then unknown for everything in it, and unknown never passes.
        $noSprint = $this->repo . '-no-sprint';
        @mkdir($noSprint . '/app', 0775, true);

        $rules = array_column((new CandidateValidator(12, 60000, $noSprint))->violations(
            $this->payload([], ['tests/Feature/Sandbox/GreeterTest.php' => "<?php\n// Greeter\n"])
        ), 'rule');

        $this->assertContains('unowned_test_path', $rules,
            'a repository whose sprint cannot be resolved cannot prove ownership of anything');

        $this->rmrf($noSprint);
    }

    // ── a repository that is not there ──────────────────────────────────

    public function test_an_unresolvable_repository_refuses_rather_than_skipping(): void
    {
        $violations = (new CandidateValidator(12, 60000, '/var/www/a-project-that-is-not-here'))
            ->violations($this->payload(['tests/GreeterTest.php']));

        $rules = array_column($violations, 'rule');

        $this->assertContains('unresolvable_repository', $rules);
        $this->assertStringContainsString('does not resolve', $violations[0]['detail']);
    }

    public function test_no_repository_at_all_still_skips_the_filesystem_rules(): void
    {
        // The long-standing contract for callers that are not repository-aware.
        // Skipping is correct here precisely because nothing was claimed.
        $this->assertSame([], (new CandidateValidator())->violations($this->payload([])));
    }

    // ── the rebind itself ───────────────────────────────────────────────

    public function test_rebinding_does_not_mutate_the_shared_validator(): void
    {
        $shared = new CandidateValidator(12, 60000, base_path());
        $rebound = $shared->withRepository($this->repo);

        $this->write('tests/GreeterTest.php', "<?php\n");

        $this->assertSame([], $rebound->violations($this->payload(['tests/GreeterTest.php'])),
            'the rebound validator sees the project');
        $this->assertContains('invented_coverage',
            array_column($shared->violations($this->payload(['tests/GreeterTest.php'])), 'rule'),
            'and the original is unchanged, still answering for Engineer888');
    }

    public function test_the_limits_survive_the_rebind(): void
    {
        $tiny = (new CandidateValidator(1, 60000, base_path()))->withRepository($this->repo);

        $rules = array_column($tiny->violations($this->payload([], [
            'app/One.php' => "<?php\n// one\n",
            'app/Two.php' => "<?php\n// two\n",
        ])), 'rule');

        $this->assertContains('too_many_files', $rules,
            'rebinding changes the repository and nothing else');
    }

    // ── end to end, through the engine ──────────────────────────────────

    public function test_the_engine_judges_a_candidate_against_its_own_project(): void
    {
        $this->write('app/Greeter.php', "<?php\nclass Greeter {}\n");
        $this->write('tests/GreeterTest.php', "<?php\n// covers Greeter\n");

        [$project, $task] = $this->project($this->repo);

        $this->useScriptedProvider('app/Greeter.php', ['tests/GreeterTest.php']);

        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame('VALIDATED', $outcome->status,
            'a project citing its own test must validate: ' . json_encode($outcome->violations));
    }

    public function test_the_engine_refuses_a_citation_the_project_cannot_support(): void
    {
        $this->write('app/Greeter.php', "<?php\nclass Greeter {}\n");

        [$project, $task] = $this->project($this->repo);

        // Real in Engineer888, absent from this project.
        $this->useScriptedProvider('app/Greeter.php', ['tests/Feature/Engineer888/CommandCenterTest.php']);

        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame('REJECTED', $outcome->status);
        $this->assertContains('invented_coverage', array_column($outcome->violations, 'rule'));
    }

    public function test_the_engine_refuses_a_project_whose_repository_is_gone(): void
    {
        [$project, $task] = $this->project('/var/www/a-project-that-was-deleted');

        $this->useScriptedProvider('app/Greeter.php', ['tests/GreeterTest.php']);

        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame('REJECTED', $outcome->status);
        $this->assertContains('unresolvable_repository', array_column($outcome->violations, 'rule'),
            'a project pointing at nothing must fail honestly, not validate easily');
    }

    /**
     * Engineer888's own repository is just another project to this pipeline.
     *
     * The original behaviour, still working — proved by pointing a project at
     * base_path() and citing a test that genuinely lives there.
     */
    public function test_engineer888_governing_itself_still_works(): void
    {
        [$project, $task] = $this->project(base_path());

        $this->useScriptedProvider('app/Core/Engineer888/Fixture.php',
            ['tests/Feature/Engineer888/CommandCenterTest.php']);

        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame('VALIDATED', $outcome->status,
            'the LevelUp/Engineer888 repository must still validate: ' . json_encode($outcome->violations));
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function validator(): CandidateValidator
    {
        return new CandidateValidator(12, 60000, $this->repo);
    }

    private function write(string $path, string $body): void
    {
        @mkdir(dirname($this->repo . '/' . $path), 0775, true);
        file_put_contents($this->repo . '/' . $path, $body);
    }

    /**
     * A repository with a manifest the running process will actually resolve.
     *
     * phpunit.e888.xml sets E888_SPRINT_MANIFEST, and OwnershipManifest::active()
     * takes that explicit path in preference to globbing — so a manifest written
     * under any other name is simply not found, and every ownership question in
     * that repository answers UNKNOWN. Two tests here passed for exactly that
     * wrong reason before this was fixed.
     */
    private function seedRepository(string $path, array $ownedPaths): void
    {
        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $path . '/' . ltrim($declared, '/');

        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'multi-project',
            'sprint' => 'multi-project-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => $ownedPaths,
        ], JSON_PRETTY_PRINT));
        @mkdir($path . '/app', 0775, true);
    }

    /** @return array{0:object,1:object} */
    private function project(string $repositoryPath): array
    {
        $key = 'multi-' . substr(md5($repositoryPath . $this->name()), 0, 10);

        $project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => $key, 'name' => 'Multi-project fixture',
            'repository_path' => $repositoryPath, 'phpunit_config' => 'phpunit.e888.xml',
            'test_database' => 'levelup_e888_test',
        ]);

        $task = (new WorkflowEngine())->createTask($key, [
            'title' => 'Multi-project fixture', 'description' => 'x', 'change_set' => [],
        ]);

        return [$project, $task];
    }

    private function useScriptedProvider(string $path, array $existingTests): void
    {
        config([
            'engineer888_reasoning.enabled' => true,
            'engineer888_reasoning.provider' => 'scripted',
            'engineer888_reasoning.scripted.label' => 'multi-project',
            'engineer888_reasoning.scripted.response' => $this->payload($existingTests, [
                $path => "<?php\n// proposed by the fixture\n",
            ]),
        ]);
    }

    /**
     * @param array<int,string>    $existingTests
     * @param array<string,string> $fileChanges
     */
    private function payload(array $existingTests, array $fileChanges = []): array
    {
        if ($fileChanges === []) { $fileChanges = ['app/Greeter.php' => "<?php\n// changed\n"]; }

        $changes = [];
        foreach ($fileChanges as $path => $content) {
            $changes[] = ['path' => $path, 'action' => 'update', 'content' => $content,
                          'rationale' => 'fixture'];
        }

        return [
            'problem_understanding'   => 'A file must change.',
            'assumptions'             => [], 'unknowns' => [],
            'implementation_strategy' => 'Write the declared content.',
            'files_affected'          => array_map(
                fn ($c) => ['path' => $c['path'], 'action' => 'update', 'why' => 'the target'], $changes),
            'migrations'              => ['required' => false, 'detail' => 'none'],
            'risks'                   => [], 'testing_strategy' => 'the project suite',
            'rollback'                => 'SafeInstaller backup',
            'file_changes'            => $changes,
            'test_coverage' => [
                'behaviour_changed' => 'the greeting changes',
                'test_files_proposed' => [],
                'existing_tests' => $existingTests,
                'expected_assertions' => ['the greeting is asserted'],
                'regression_prevented' => 'a silent revert of the greeting',
                'test_database' => 'levelup_e888_test',
                'full_suite_required' => false, 'gaps' => [],
                'classification' => 'TESTED', 'confidence' => 'high',
            ],
            'confidence' => 'high', 'confidence_basis' => 'a one-line change',
        ];
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
