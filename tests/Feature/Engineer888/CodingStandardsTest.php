<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A standard learned from a review must reach the next proposal.
 *
 * THE REVIEW THIS FILE EXISTS FOR. Candidate 3f2dba72, 2026-08-11, was
 * VALIDATED with zero violations and still carried five defects that were
 * reproduced against running code: a persistence path that resolved to a
 * directory the web entry point could not write to, a decode that returned null
 * where a list was expected, tests that wrote to the application's own data
 * file, validation the caller could skip, and request input read without being
 * present.
 *
 * All five are recorded as coding_standard assets with their reproductions as
 * evidence. The risk this file guards is subtler than whether they exist: the
 * context builder scores most sections against the task's vocabulary, and a
 * standard about test isolation shares almost no words with a task about a bug
 * tracker. The one task that needed it is the one that would have dropped it.
 */
class CodingStandardsTest extends TestCase
{
    use RefreshDatabase;

    private object $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $repo = sys_get_temp_dir() . '/e888-standards-' . getmypid();
        @mkdir($repo . '/app', 0775, true);

        $this->project = (new WorkflowEngine())->registerProject([
            'company' => 'Fixture Co', 'key' => 'standards-fixture',
            'name' => 'Standards fixture', 'repository_path' => $repo,
        ]);
    }

    private function standard(string $name, string $summary, string $evidence, ?int $projectId): void
    {
        DB::table('engineering_assets')->insert([
            'kind' => 'coding_standard', 'project_id' => $projectId,
            'name' => $name, 'summary' => $summary, 'evidence' => $evidence,
            'promoted_by' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function promptFor(string $title, string $description): string
    {
        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => $title, 'description' => $description, 'change_set' => [],
        ]);

        return ReasoningEngine::make()->buildRequest($this->project, $task)->renderText();
    }

    public function test_a_standard_reaches_the_provider_with_no_vocabulary_in_common(): void
    {
        $this->standard(
            'A test creates and destroys its own fixture',
            'Point the subject at a temporary path created in setUp and removed in tearDown.',
            'The suite of candidate 3f2dba72 left 2 records after one run and 4 after the next.',
            (int) $this->project->id,
        );

        // Not one significant word is shared with the standard above.
        $prompt = $this->promptFor('Rename the topbar', 'Change the colour of the topbar heading to teal.');

        $this->assertStringContainsString('A test creates and destroys its own fixture', $prompt,
            'a standard is not advice about this task; it is how this project writes code');
    }

    public function test_the_evidence_travels_with_the_standard(): void
    {
        $this->standard(
            'Resolve filesystem paths from the project root',
            'Derive the root once and build every path from it.',
            'Candidate 3f2dba72 wrote storage/bugs.json, which resolved to public/storage from the web entry point.',
            (int) $this->project->id,
        );

        $prompt = $this->promptFor('Add a bug tracker', 'Build a small bug tracker.');

        $this->assertStringContainsString('public/storage', $prompt,
            'a rule with its reproduction attached is followed; a rule on its own is style advice');
    }

    public function test_an_unproven_standard_is_still_refused(): void
    {
        // The evidence requirement is the reason these are worth reading. It is
        // not relaxed by making the section structural.
        DB::table('engineering_assets')->insert([
            'kind' => 'coding_standard', 'project_id' => (int) $this->project->id,
            'name' => 'Somebody once felt strongly about tabs',
            'summary' => 'Use tabs.', 'evidence' => null, 'evidence_task_id' => null,
            'promoted_by' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $prompt = $this->promptFor('Add a bug tracker', 'Build a small bug tracker.');

        $this->assertStringNotContainsString('Somebody once felt strongly about tabs', $prompt,
            'only proven assets may be suggested — structural does not mean unproven');
    }

    public function test_a_standard_survives_a_budget_that_drops_ordinary_context(): void
    {
        // THE PREVIOUS PROOF OF THIS WAS WORTHLESS AND SAYING SO IS THE POINT.
        //
        // Removing coding_standards from the structural list left every test in
        // this file green, because with a 60 KB budget nothing is ever dropped —
        // the tests proved the standards arrive, not that being structural is
        // what makes them arrive.
        //
        // The claim is about SURVIVAL UNDER PRESSURE: on a project with a large
        // context the budget binds, and a section scored on vocabulary loses to
        // one that is not. So the budget is made to bind here.
        $this->standard('Paths are resolved from the project root',
            'Derive the root once and build every path from it.',
            'Candidate 3f2dba72 wrote storage/bugs.json and the web entry point could not save.',
            (int) $this->project->id);

        for ($i = 0; $i < 40; $i++) {
            DB::table('engineering_assets')->insert([
                'kind' => 'recipe', 'project_id' => (int) $this->project->id,
                'name' => 'bug tracker recipe ' . $i,
                'summary' => str_repeat('bug tracker persistence validation ui tests readme ', 20),
                'evidence' => 'proven', 'promoted_by' => 'test',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $task = (new WorkflowEngine())->createTask($this->project->key, [
            'title' => 'Build a bug tracker',
            'description' => 'Build a bug tracker with persistence, validation, ui, tests and a readme.',
            'change_set' => [],
        ]);

        $builder = new \App\Core\Engineer888\Reasoning\ContextBuilder(
            new \App\Core\Engineer888\Reasoning\KnowledgeBase((string) $this->project->repository_path),
            1200,   // a budget far too small for forty recipes
            6,
            1,
        );
        $text = $builder->build($this->project, $task)->renderText();

        // WHAT THIS ACTUALLY ESTABLISHED. The section was briefly added to the
        // structural list on the assumption that a standard would otherwise be
        // scored against the task's vocabulary and dropped. Removing it again
        // left this test green even at a 1200-byte budget, so the assumption was
        // wrong and the edit was reverted rather than kept as insurance nobody
        // could demonstrate. What is true — and is what this asserts — is that a
        // proven standard reaches the provider even when the context is far too
        // large to send whole.
        $this->assertStringContainsString('Paths are resolved from the project root', $text,
            'a standard must survive a budget that drops ordinary context, or the task with the most '
            . 'context is the task that silently loses its engineering rules');
    }

    public function test_no_standard_is_dropped_by_the_per_source_cap(): void
    {
        // MEASURED, NOT ASSUMED. max_items_per_source is 6. On 2026-08-11 the
        // ninth standard was registered and three vanished from the prompt —
        // the documented-command rule, the error-rendering rule and the
        // write-path rule — chosen for deletion by insertion order.
        //
        // A knowledge source is sampled because more examples add little. A
        // standard is not an example: every one is a rule that has to hold.
        for ($i = 0; $i < 9; $i++) {
            $this->standard('Standard number ' . $i . ' about a distinct engineering concern',
                'A rule that must hold.', 'reproduced against running code', (int) $this->project->id);
        }

        $prompt = $this->promptFor('Add a bug tracker', 'Build a small bug tracker.');

        for ($i = 0; $i < 9; $i++) {
            $this->assertStringContainsString('Standard number ' . $i . ' about a distinct engineering concern',
                $prompt, "standard {$i} was dropped; a cap that silently deletes an engineering rule is worse "
                . 'than no rule, because nobody can tell which ones applied');
        }
    }

    public function test_another_projects_standard_does_not_leak(): void
    {
        $other = (new WorkflowEngine())->registerProject([
            'company' => 'Other Co', 'key' => 'other-fixture',
            'name' => 'Other fixture', 'repository_path' => sys_get_temp_dir(),
        ]);

        $this->standard('A rule belonging to another codebase', 'Do it their way.',
            'proved over there', (int) $other->id);

        $prompt = $this->promptFor('Add a bug tracker', 'Build a small bug tracker.');

        $this->assertStringNotContainsString('A rule belonging to another codebase', $prompt,
            'project isolation is the difference between what the company learned and what one other codebase did');
    }
}
