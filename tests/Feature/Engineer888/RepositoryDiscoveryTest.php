<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Core\Engineer888\Reasoning\RepositoryMap;
use App\Core\Engineer888\Reasoning\ReasoningRequest;
use Tests\TestCase;

/**
 * Does Engineer888 know what it is looking at before it designs anything?
 *
 * THE REGRESSION THIS FILE EXISTS FOR. Task #45 / candidate 86cc830a, 2026-08-10:
 * asked to build a bug tracker in a bare PSR-4 PHP project, Engineer888 proposed
 * app/Http/Controllers/BugController.php, three .blade.php views and an UPDATE to
 * routes/web.php — Laravel, in a repository with no Laravel, no resources/ and no
 * routes/. Its own recorded unknown was "what specific technology stack should be
 * used?".
 *
 * Nothing was written, because three separate guards refused it. But refusing a
 * fantasy is not the same as never having one, and the cause was that the model
 * was handed the repository as a PATH STRING and nothing else.
 */
class RepositoryDiscoveryTest extends TestCase
{
    private string $bare;
    private string $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bare    = $this->makeBareProject();
        $this->laravel = $this->makeLaravelProject();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->bare);
        $this->rmrf($this->laravel);
        parent::tearDown();
    }

    // ── 1-10 · the acceptance repository, told truthfully ───────────────────

    public function test_a_bare_psr4_project_is_classified_as_a_plain_php_project(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertNotNull($map);
        $this->assertSame(RepositoryMap::CUSTOM_PHP, $map->framework,
            'composer + PSR-4 and no framework marker is a plain PHP project, not a guess');
    }

    public function test_laravel_is_not_detected_merely_because_php_exists(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertStringNotContainsStringIgnoringCase('laravel', $map->framework);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $map->render(),
            'the whole prompt block must never name a framework the repository cannot prove');
    }

    public function test_the_map_does_not_present_resources_views_as_existing(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertFalse($map->has('resources/views'));
        $this->assertNotContains('resources', $map->topLevelDirectories());
        $this->assertStringNotContainsString('resources/', $map->render());
    }

    public function test_the_map_does_not_present_routes_web_php_as_existing(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertFalse($map->has('routes/web.php'));
        $this->assertNotContains('routes', $map->topLevelDirectories());
    }

    public function test_the_real_app_directory_is_surfaced(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertContains('app', $map->topLevelDirectories());
        $this->assertStringContainsString('app/', $map->render());
    }

    public function test_the_real_tests_directory_is_surfaced(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertContains('tests', $map->topLevelDirectories());
        $this->assertContains('tests/', $map->testing['directories']);
    }

    public function test_phpunit_is_surfaced_as_the_test_framework(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertSame('PHPUnit', $map->testing['framework']);
        $this->assertContains('phpunit.acceptance.xml', $map->testing['configs']);
        $this->assertStringContainsString('vendor/bin/phpunit', $map->testing['command'],
            'a project with no artisan must not be told to run artisan test');
    }

    public function test_the_composer_psr4_mapping_is_surfaced(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $this->assertStringContainsString('App\\ => app/', implode(' ', $map->frameworkEvidence));
    }

    public function test_a_project_with_no_evidence_at_all_is_unknown_not_guessed(): void
    {
        $empty = sys_get_temp_dir() . '/e888-empty-' . bin2hex(random_bytes(4));
        mkdir($empty, 0775, true);

        $map = RepositoryMap::discover($empty);

        $this->assertSame(RepositoryMap::UNKNOWN, $map->framework);
        $this->rmrf($empty);
    }

    public function test_nonexistent_infrastructure_is_never_presented_as_existing(): void
    {
        $map = RepositoryMap::discover($this->bare);

        // The claim under test is about what is PRESENTED AS PRESENT. The
        // evidence line legitimately reads "no artisan, no bin/console, no
        // framework dependency" — saying a thing is absent is the opposite of
        // inventing it, so the assertion is made against the structure and the
        // entry points rather than against any mention anywhere.
        $presented = implode("\n", array_map(
            static fn (array $node): string => $node['path'] . ' ' . implode(' ', $node['children']),
            $map->structure
        )) . "\n" . implode("\n", $map->entryPoints);

        foreach (['routes', 'resources', 'artisan', 'bootstrap'] as $fiction) {
            $this->assertStringNotContainsString($fiction, $presented,
                "{$fiction} does not exist here and must not appear as part of the repository");
            $this->assertFalse($map->has($fiction));
        }

        $this->assertStringContainsString('no artisan', implode(' ', $map->frameworkEvidence),
            'and its absence must be stated positively, so the model cannot assume it');
    }

    // ── 11-12 · and it still recognises a real framework ────────────────────

    public function test_laravel_is_detected_when_the_evidence_is_actually_there(): void
    {
        $map = RepositoryMap::discover($this->laravel);

        $this->assertSame('LARAVEL', $map->framework);
        $this->assertContains('artisan exists', $map->frameworkEvidence);
        $this->assertContains('composer requires laravel/framework', $map->frameworkEvidence);
    }

    public function test_a_real_laravel_projects_routes_views_and_artisan_are_surfaced(): void
    {
        $map = RepositoryMap::discover($this->laravel);

        $this->assertTrue($map->has('routes/web.php'));
        $this->assertTrue($map->has('resources/views'));
        $this->assertContains('artisan', $map->entryPoints);
        $this->assertContains('routes', $map->topLevelDirectories());
        $this->assertContains('resources', $map->topLevelDirectories());
        $this->assertStringContainsString('php artisan test', $map->testing['command']);
    }

    // ── 13-16 · the planning contract ───────────────────────────────────────

    public function test_a_bare_php_project_cannot_claim_an_existing_laravel_route_file(): void
    {
        $violations = (new CandidateValidator(12, 60000, $this->bare))
            ->violations($this->payload([
                ['path' => 'routes/web.php', 'action' => 'update',
                 'content' => "<?php\n\nRoute::resource('bugs', BugController::class);\n"],
            ]));

        $this->assertContains('update_target_missing', array_column($violations, 'rule'),
            'this is the exact claim candidate 86cc830a made, and it must be refused by name');
    }

    public function test_update_of_a_nonexistent_path_is_refused(): void
    {
        $violations = (new CandidateValidator(12, 60000, $this->bare))
            ->violations($this->payload([
                ['path' => 'app/Nowhere.php', 'action' => 'update', 'content' => "<?php\n\nreturn 1;\n"],
            ]));

        $this->assertContains('update_target_missing', array_column($violations, 'rule'));
    }

    public function test_a_valid_create_target_is_accepted(): void
    {
        $violations = (new CandidateValidator(12, 60000, $this->bare))
            ->violations($this->payload([
                ['path' => 'app/Bug.php', 'action' => 'create',
                 'content' => "<?php\n\nnamespace App;\n\nfinal class Bug\n{\n    public function __construct(public string \$title) {}\n\n    public function title(): string\n    {\n        return \$this->title;\n    }\n}\n"],
                ['path' => 'tests/BugTest.php', 'action' => 'create',
                 'content' => "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass BugTest extends TestCase\n{\n    public function testItKeepsItsTitle(): void\n    {\n        \$this->assertSame('x', (new \\App\\Bug('x'))->title());\n    }\n}\n"],
            ]));

        $this->assertSame([], $violations, 'a real create into an owned directory must pass: ' . json_encode($violations));
    }

    public function test_an_update_that_does_exist_is_accepted(): void
    {
        file_put_contents($this->bare . '/app/Existing.php', "<?php\n\nnamespace App;\n\nclass Existing {}\n");

        $violations = (new CandidateValidator(12, 60000, $this->bare))
            ->violations($this->payload([
                ['path' => 'app/Existing.php', 'action' => 'update',
                 'content' => "<?php\n\nnamespace App;\n\nclass Existing\n{\n    public function ping(): string\n    {\n        return 'pong';\n    }\n}\n"],
            ]));

        $this->assertNotContains('update_target_missing', array_column($violations, 'rule'));
    }

    // ── 17 · the map actually reaches the provider ──────────────────────────

    public function test_the_project_map_reaches_the_reasoning_request_before_file_selection(): void
    {
        $map = RepositoryMap::discover($this->bare);

        $bare = new ReasoningRequest(
            ['key' => 'acceptance', 'name' => 'Acceptance', 'company' => 'LevelUp', 'repository_path' => $this->bare],
            ['title' => 'build a bug tracker', 'description' => 'x'],
            [], [], ['problem_understanding' => 'string'],
        );
        $withMap = $bare->withRepositoryMap($map);

        $this->assertStringNotContainsString('ESTABLISHED BY DISCOVERY', $bare->renderText(),
            'without the map the provider is told nothing about the tree — the 2026-08-10 condition');
        $this->assertStringContainsString('ESTABLISHED BY DISCOVERY', $withMap->renderText());
        $this->assertStringContainsString(RepositoryMap::CUSTOM_PHP, $withMap->renderText());
        $this->assertStringContainsString('NO FICTIONAL INFRASTRUCTURE', $withMap->renderText());

        // The prompt is fingerprinted; a map that varied between reads would make
        // approval drift fire at random.
        $this->assertSame(
            $bare->withRepositoryMap(RepositoryMap::discover($this->bare))->fingerprint(),
            $withMap->fingerprint(),
            'discovery must be deterministic'
        );
    }

    public function test_the_map_is_bounded_and_does_not_dump_the_repository(): void
    {
        for ($i = 0; $i < 200; $i++) {
            file_put_contents($this->bare . '/app/Generated' . $i . '.php', "<?php\n");
        }

        $render = RepositoryMap::discover($this->bare)->render();

        $this->assertLessThan(8000, strlen($render),
            'a repository with 200 files must not become 200 lines of prompt');
        $this->assertStringContainsString('…', $render, 'truncation must be visible, not silent');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function payload(array $changes): array
    {
        return [
            'problem_understanding'   => 'x',
            'assumptions'             => [],
            'unknowns'                => [],
            'implementation_strategy' => 'x',
            'files_affected'          => [],
            'migrations'              => ['required' => false, 'detail' => 'none'],
            'risks'                   => [],
            'testing_strategy'        => 'x',
            'rollback'                => 'x',
            'file_changes'            => $changes,
            'confidence'              => 'high',
            // Sprint 10 makes coverage part of the implementation. The fixture
            // satisfies the real contract rather than a convenient subset, so a
            // failure here is always about the claim under test.
            'test_coverage'           => [
                'behaviour_changed'    => 'a bug can be constructed and its title read back',
                'test_files_proposed'  => array_values(array_filter(
                    array_column($changes, 'path'),
                    static fn (string $p): bool => str_ends_with($p, 'Test.php')
                )),
                'existing_tests'       => [],
                'expected_assertions'  => ['title() returns the title it was constructed with'],
                'regression_prevented' => 'a future rename of the property silently returning null',
                'test_database'        => 'e888_acceptance_test',
                'full_suite_required'  => false,
                'gaps'                 => [],
                'confidence'           => 'high',
            ],
        ];
    }

    /** The acceptance project: composer + PSR-4, PHPUnit, app/ tests/ public/. */
    private function makeBareProject(): string
    {
        $dir = sys_get_temp_dir() . '/e888-bare-' . bin2hex(random_bytes(4));
        foreach (['', '/app', '/tests', '/public', '/.engineer888/sprints'] as $d) { mkdir($dir . $d, 0775, true); }

        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'engineer888/acceptance-app',
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($dir . '/phpunit.acceptance.xml', '<?xml version="1.0"?><phpunit/>');

        // OWNERSHIP HAS TO RESOLVE FOR *THIS* REPOSITORY.
        //
        // OwnershipManifest::active() reads E888_SPRINT_MANIFEST first, and this
        // suite runs under phpunit.e888.xml which sets it to Engineer888's own
        // sprint file. Relative to a fixture repository that path does not exist,
        // so every path would classify UNKNOWN and the fixture would fail for a
        // reason that has nothing to do with what it is testing — the same
        // cross-repository confusion E1-G fixed in the validator itself.
        //
        // The manifest is therefore written where the resolver will actually
        // look, rather than the environment being mutated underneath it.
        $manifest = json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'e888-acceptance',
            'sprint' => 'acceptance', 'owned_paths' => ['app/**', 'tests/**', 'public/**'],
            'owned_files' => ['README.md'],
        ], JSON_PRETTY_PRINT);

        $declared = (string) (getenv('E888_SPRINT_MANIFEST') ?: '');
        $target = $declared !== '' ? $dir . '/' . ltrim($declared, '/') : $dir . '/.engineer888/sprints/acceptance.json';
        if (! is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); }
        file_put_contents($target, $manifest);

        return $dir;
    }

    /** A real Laravel signature: artisan + laravel/framework + routes + views. */
    private function makeLaravelProject(): string
    {
        $dir = sys_get_temp_dir() . '/e888-laravel-' . bin2hex(random_bytes(4));
        foreach (['', '/app', '/routes', '/resources/views', '/bootstrap', '/tests'] as $d) { mkdir($dir . $d, 0775, true); }

        file_put_contents($dir . '/artisan', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($dir . '/bootstrap/app.php', "<?php\n\nreturn 1;\n");
        file_put_contents($dir . '/routes/web.php', "<?php\n\nRoute::get('/', fn () => 'ok');\n");
        file_put_contents($dir . '/resources/views/welcome.blade.php', "<h1>hello</h1>\n");
        file_put_contents($dir . '/phpunit.xml', '<?xml version="1.0"?><phpunit/>');
        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'acme/site',
            'require' => ['laravel/framework' => '^11.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) { return; }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($dir);
    }
}
