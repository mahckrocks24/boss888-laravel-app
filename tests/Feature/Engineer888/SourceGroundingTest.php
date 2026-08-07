<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Core\Engineer888\Reasoning\ContextBuilder;
use App\Core\Engineer888\Reasoning\KnowledgeBase;
use App\Core\Engineer888\Reasoning\SourceGrounding;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Repository grounding — permanent regression.
 *
 * THE DEFECT THIS GUARDS. CandidateImplementation asks for "the complete file
 * content, not a diff", and the reasoning context contained only class
 * surfaces. The model was asked to reproduce files it had never seen. On
 * 2026-08-07 two real runs answered with a sentence describing the change
 * instead of code, and CandidateValidator rejected them as not_php. The
 * validator was correct; the request was impossible.
 *
 * What is defended here: a target file's exact bytes reach the prompt, nothing
 * unverified does, secrets never do, and a file is never half-supplied while
 * the contract still demands all of it.
 */
class SourceGroundingTest extends TestCase
{
    private string $repo;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $stamp = bin2hex(random_bytes(5));
        $this->repo = sys_get_temp_dir() . '/e888-ground-' . $stamp;
        // A SIBLING of the repository, not a child. An earlier version of this
        // fixture put it inside, so the "escaping" symlink resolved to a path
        // that really was within the project and the test failed for the wrong
        // reason. A containment test whose bait is already contained proves
        // nothing.
        $this->outside = sys_get_temp_dir() . '/e888-outside-' . $stamp;

        mkdir($this->repo . '/app/Core', 0777, true);
        mkdir($this->outside, 0777, true);

        file_put_contents($this->repo . '/app/Core/Widget.php', "<?php\n\nclass Widget { public function go(): string { return 'x'; } }\n");
        file_put_contents($this->repo . '/app/Core/Big.php', str_repeat("// filler line to make this file large\n", 3000));
        file_put_contents($this->repo . '/.env', "APP_KEY=base64:supersecret\nDB_PASSWORD=hunter2\n");
        file_put_contents($this->outside . '/secret.php', "<?php // must never be reachable\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo) . ' ' . escapeshellarg($this->outside));
        parent::tearDown();
    }

    private function grounding(int $max = 60000): SourceGrounding
    {
        return new SourceGrounding($this->repo, $max);
    }

    // ── the source actually reaches the prompt ───────────────────────

    public function test_a_verified_target_file_is_included_complete(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'update', 'why' => 'fix'],
        ]);

        $this->assertCount(1, $out['items']);
        $this->assertCount(1, $out['grounded']);

        $body = $out['items'][0]['body'];
        $disk = file_get_contents($this->repo . '/app/Core/Widget.php');

        $this->assertStringContainsString($disk, $body, 'the exact bytes on disk must be present');
        $this->assertStringContainsString('COMPLETE: true', $out['items'][0]['label']);
    }

    public function test_the_declared_hash_matches_the_bytes_supplied(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'update'],
        ]);

        $disk = file_get_contents($this->repo . '/app/Core/Widget.php');

        $this->assertSame(hash('sha256', $disk), $out['grounded'][0]['sha256']);
        $this->assertSame(strlen($disk), $out['grounded'][0]['bytes']);
        $this->assertStringContainsString(hash('sha256', $disk), $out['items'][0]['label']);
    }

    public function test_source_is_never_normalised_before_hashing(): void
    {
        // CRLF and a trailing blank line survive verbatim: the approval
        // contract fingerprints bytes, so a "tidied" file is a different file.
        file_put_contents($this->repo . '/app/Core/Crlf.php', "<?php\r\n// windows\r\n\r\n");
        $out = $this->grounding()->forPaths(['app/Core/Crlf.php' => 'update']);

        $disk = file_get_contents($this->repo . '/app/Core/Crlf.php');
        $this->assertStringContainsString("\r\n", $out['items'][0]['body']);
        $this->assertSame(hash('sha256', $disk), $out['grounded'][0]['sha256']);
    }

    // ── nothing unverified gets in ───────────────────────────────────

    public function test_a_nonexistent_file_is_refused_not_invented(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'resources/views/admin/topbar.blade.php', 'action' => 'update'],
        ]);

        $this->assertSame([], $out['items'], 'no context may be built for a fictional file');
        $this->assertCount(1, $out['refused']);
        $this->assertStringContainsString('does not exist', $out['refused'][0]['reason']);
    }

    public function test_a_path_outside_the_project_root_is_refused(): void
    {
        $out = $this->grounding()->forPaths(['../' . basename($this->outside) . '/secret.php' => 'update']);

        $this->assertSame([], $out['items']);
        $this->assertStringContainsString('traversal', $out['refused'][0]['reason']);
    }

    public function test_an_absolute_path_is_refused(): void
    {
        $out = $this->grounding()->forPaths(['/etc/passwd' => 'update']);

        $this->assertSame([], $out['items']);
        $this->assertStringContainsString('absolute', $out['refused'][0]['reason']);
    }

    public function test_a_symlink_escaping_the_project_is_refused(): void
    {
        @symlink($this->outside . '/secret.php', $this->repo . '/app/Core/Escape.php');
        if (! is_link($this->repo . '/app/Core/Escape.php')) {
            $this->markTestSkipped('symlinks unavailable in this environment');
        }

        $out = $this->grounding()->forPaths(['app/Core/Escape.php' => 'update']);

        $this->assertSame([], $out['items']);
        $this->assertStringContainsString('outside the project', $out['refused'][0]['reason']);
    }

    public function test_a_secret_bearing_file_is_refused_and_not_redacted(): void
    {
        $out = $this->grounding()->forPaths(['.env' => 'update']);

        $this->assertSame([], $out['items'], 'a secret file is refused outright, never redacted');
        $this->assertStringContainsString('prohibited', $out['refused'][0]['reason']);

        $rendered = json_encode($out);
        $this->assertStringNotContainsString('hunter2', $rendered);
        $this->assertStringNotContainsString('supersecret', $rendered);
    }

    public function test_only_create_and_update_entries_are_grounded(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'read'],
            ['path' => 'app/Core/Widget.php', 'action' => 'none'],
        ]);

        $this->assertSame([], $out['items'], 'a file merely consulted need not be reproduced');
    }

    public function test_unrelated_repository_files_are_not_included(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'update'],
        ]);

        $paths = array_column($out['grounded'], 'path');
        $this->assertSame(['app/Core/Widget.php'], $paths);
    }

    public function test_a_new_file_is_grounded_as_empty_rather_than_refused(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Brand new.php', 'action' => 'create'],
        ]);

        $this->assertCount(1, $out['items']);
        $this->assertFalse($out['grounded'][0]['exists']);
        $this->assertStringContainsString('does not exist yet', $out['items'][0]['label']);
    }

    // ── budget: refuse, never truncate ───────────────────────────────

    public function test_an_oversized_target_reports_budget_exceeded_and_supplies_nothing(): void
    {
        $out = $this->grounding(20000)->forFilesAffected([
            ['path' => 'app/Core/Big.php', 'action' => 'update'],
        ]);

        $this->assertTrue($out['budget_exceeded']);
        $this->assertSame([], $out['items'],
            'a half-supplied file plus "return the whole file" is the same impossible contract');
        $this->assertStringContainsString(SourceGrounding::BUDGET_EXCEEDED, $out['refused'][0]['reason']);
    }

    public function test_a_file_that_fits_is_still_supplied_whole(): void
    {
        $out = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'update'],
        ]);

        $this->assertFalse($out['budget_exceeded']);
        $disk = file_get_contents($this->repo . '/app/Core/Widget.php');
        $this->assertStringContainsString($disk, $out['items'][0]['body']);
    }

    // ── the prompt really carries it ─────────────────────────────────

    public function test_grounded_source_reaches_the_rendered_prompt(): void
    {
        $project = (object) ['id' => 1, 'company' => 'LevelUp', 'name' => 'Fixture',
                             'key' => 'fixture', 'repository_path' => $this->repo,
                             // KnowledgeBase reads these when it builds the
                             // execution-constraints section.
                             'phpunit_config' => 'phpunit.e888.xml',
                             'test_database' => 'levelup_e888_test',
                             'deploy_command' => null, 'branch' => null];
        $task = (object) ['uuid' => 'u', 'title' => 'Fix the widget', 'description' => 'the widget is broken',
                          'kind' => 'bug', 'priority' => 'normal', 'acceptance_criteria' => '[]',
                          'constraints' => '[]', 'modules' => '[]', 'id' => 1];

        $items = $this->grounding()->forFilesAffected([
            ['path' => 'app/Core/Widget.php', 'action' => 'update'],
        ])['items'];

        $builder = new ContextBuilder(new KnowledgeBase($this->repo), 60000, 6, 1);
        $text = $builder->build($project, $task, $items)->renderText();

        $this->assertStringContainsString('GROUNDED SOURCE', $text);
        $this->assertStringContainsString('public function go()', $text,
            'the model must actually receive the bytes it is asked to reproduce');
    }

    // ── the whole-file mandate ───────────────────────────────────────

    /** @return string the rendered prompt for one grounded update target */
    private function promptFor(string $path, string $action = 'update'): string
    {
        $project = (object) ['id' => 1, 'company' => 'LevelUp', 'name' => 'Fixture',
                             'key' => 'fixture', 'repository_path' => $this->repo,
                             'phpunit_config' => 'phpunit.e888.xml',
                             'test_database' => 'levelup_e888_test',
                             'deploy_command' => null, 'branch' => null];
        $task = (object) ['uuid' => 'u', 'title' => 'Fix the widget', 'description' => 'broken',
                          'kind' => 'bug', 'priority' => 'normal', 'acceptance_criteria' => '[]',
                          'constraints' => '[]', 'modules' => '[]', 'id' => 1];

        $items = $this->grounding()->forFilesAffected([
            ['path' => $path, 'action' => $action],
        ])['items'];

        return (new ContextBuilder(new KnowledgeBase($this->repo), 60000, 6, 1))
            ->build($project, $task, $items)->renderText();
    }

    public function test_the_prompt_demands_the_complete_final_file(): void
    {
        $text = $this->promptFor('app/Core/Widget.php');

        $this->assertStringContainsString('COMPLETE FINAL FILE CONTENT', $text);
        $this->assertStringContainsString('app/Core/Widget.php', $text);
    }

    public function test_the_prompt_forbids_prose_diffs_and_placeholders(): void
    {
        $text = $this->promptFor('app/Core/Widget.php');

        $this->assertStringContainsString('DO NOT return prose', $text);
        $this->assertStringContainsString('DO NOT return a diff', $text);
        $this->assertStringContainsString('...existing code...', $text);
        $this->assertStringContainsString('rest unchanged', $text);
        $this->assertStringContainsString('DO NOT describe the change', $text);
    }

    public function test_the_prompt_requires_unchanged_lines_to_be_reproduced(): void
    {
        $text = $this->promptFor('app/Core/Widget.php');

        $this->assertStringContainsString('reproduce every unchanged portion', $text);
        $this->assertStringContainsString('DO NOT omit unchanged lines', $text);
    }

    public function test_the_mandate_offers_an_honest_refusal_instead_of_a_fragment(): void
    {
        $this->assertStringContainsString('COMPLETE_FILE_OUTPUT_UNAVAILABLE',
            $this->promptFor('app/Core/Widget.php'));
    }

    public function test_the_mandate_appears_immediately_before_the_output_schema(): void
    {
        $text = $this->promptFor('app/Core/Widget.php');

        $mandate = strpos($text, '# COMPLETE FILE OUTPUT IS REQUIRED');
        $schema  = strpos($text, '# REQUIRED OUTPUT');

        $this->assertNotFalse($mandate);
        $this->assertNotFalse($schema);
        $this->assertLessThan($schema, $mandate, 'the mandate must be the last thing read before the schema');
    }

    public function test_a_create_only_target_gets_no_existing_file_mandate(): void
    {
        $text = $this->promptFor('app/Core/Fresh.php', 'create');

        $this->assertStringNotContainsString('# COMPLETE FILE OUTPUT IS REQUIRED', $text,
            'a new file has no current bytes to reproduce; its behaviour is unchanged');
    }

    public function test_fingerprinting_is_unchanged_and_stable(): void
    {
        $a = $this->promptFor('app/Core/Widget.php');
        $b = $this->promptFor('app/Core/Widget.php');

        $this->assertSame(hash('sha256', $a), hash('sha256', $b),
            'the same inputs must render the same prompt');
    }

    // ── the contract and the validator are unchanged ─────────────────

    public function test_the_contract_still_demands_the_complete_file(): void
    {
        $this->assertStringContainsString(
            'complete file content, not a diff',
            json_encode(CandidateImplementation::CONTRACT),
            'grounding exists to make this satisfiable, never to relax it'
        );
    }

    public function test_the_validator_still_rejects_prose_pretending_to_be_php(): void
    {
        $violations = (new CandidateValidator())->violations($this->payloadWith([
            'path' => 'app/Core/Widget.php', 'action' => 'update',
            'content' => 'Modify the not_php rule to check for .blade.php files separately.',
            'rationale' => 'because',
        ]));

        $this->assertNotSame([], $violations);
        $this->assertContains('not_php', array_column($violations, 'rule'));
    }

    public function test_real_php_still_passes_the_same_rule(): void
    {
        $violations = (new CandidateValidator())->violations($this->payloadWith([
            'path' => 'app/Core/Widget.php', 'action' => 'update',
            'content' => "<?php\n\nclass Widget { public function go(): string { return 'y'; } }\n",
            'rationale' => 'because',
        ]));

        $this->assertNotContains('not_php', array_column($violations, 'rule'),
            'ordinary PHP validation must be untouched by this repair');
    }

    private function payloadWith(array $change): array
    {
        return [
            'problem_understanding'   => 'x',
            'assumptions'             => [],
            'unknowns'                => [],
            'implementation_strategy' => 'x',
            'files_affected'          => [['path' => $change['path'], 'action' => 'update', 'why' => 'x']],
            'migrations'              => ['required' => 'false', 'detail' => 'none'],
            'risks'                   => [],
            'testing_strategy'        => 'x',
            'rollback'                => 'x',
            'file_changes'            => [$change],
            'confidence'              => 'medium',
            'confidence_basis'        => 'x',
        ];
    }
}
