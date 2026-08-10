<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\ReasoningRequest;
use App\Core\Engineer888\Reasoning\RequirementSet;
use Tests\TestCase;

/**
 * The requirements the task listed must reach the provider as obligations.
 *
 * THE REGRESSION. Acceptance run 2026-08-11, candidate 7e717943. The brief asked
 * for validation, a responsive UI, tests and a README. The provider returned a
 * BugTracker class, a web page and a test file — no README, and not one line of
 * validation — and the candidate passed every rule, because every rule was about
 * the shape of the answer rather than its coverage of the question.
 */
class RequirementCoverageTest extends TestCase
{
    private const BRIEF = "Engineer,\n\nBuild a small internal Bug Tracker application from scratch.\n\n"
        . "Requirements:\n\n- list bugs\n- create bug\n- edit bug\n- title\n- description\n- severity\n"
        . "- status\n- validation\n- persistence\n- responsive UI\n- tests\n- README\n\n"
        . "Do not execute.\n\nProduce only a reviewable candidate.";

    public function test_every_requirement_in_the_brief_is_extracted(): void
    {
        $set = RequirementSet::fromDescription(self::BRIEF);

        $this->assertSame([
            'list bugs', 'create bug', 'edit bug', 'title', 'description', 'severity',
            'status', 'validation', 'persistence', 'responsive UI', 'tests', 'README',
        ], $set->toArray());
    }

    public function test_the_two_requirements_that_were_silently_dropped_are_present(): void
    {
        $items = RequirementSet::fromDescription(self::BRIEF)->toArray();

        $this->assertContains('README', $items, 'the README was never written and never mentioned');
        $this->assertContains('validation', $items, 'not one line of validation was produced');
    }

    public function test_workflow_instructions_are_not_treated_as_requirements(): void
    {
        $items = RequirementSet::fromDescription(
            "Build a thing.\n\n- a real requirement\n- do not execute\n- produce only a reviewable candidate\n"
        )->toArray();

        $this->assertSame(['a real requirement'], $items,
            'asking a provider to satisfy "do not execute" produces noise in unknowns, not better code');
    }

    public function test_prose_is_not_mistaken_for_a_requirement(): void
    {
        $set = RequirementSet::fromDescription(
            "Please build a bug tracker. It should be simple and easy to maintain.\nThere are no bullets here."
        );

        $this->assertTrue($set->isEmpty(),
            'a sentence is context; treating every sentence as a requirement is how this becomes noise');
    }

    public function test_numbered_lists_count_too(): void
    {
        $this->assertSame(['first thing', 'second thing'],
            RequirementSet::fromDescription("Do:\n1. first thing\n2) second thing\n")->toArray());
    }

    public function test_duplicates_are_collapsed_and_the_list_is_bounded(): void
    {
        $this->assertSame(['same'], RequirementSet::fromDescription("- same\n- same\n")->toArray());

        $many = '';
        for ($i = 0; $i < 60; $i++) { $many .= '- requirement ' . $i . "\n"; }
        $this->assertLessThanOrEqual(30, RequirementSet::fromDescription($many)->count());
    }

    public function test_the_requirements_reach_the_reasoning_request(): void
    {
        $set = RequirementSet::fromDescription(self::BRIEF);

        $bare = new ReasoningRequest(
            ['key' => 'acceptance', 'name' => 'Acceptance', 'company' => 'LevelUp', 'repository_path' => sys_get_temp_dir()],
            ['title' => 'Bug Tracker', 'description' => self::BRIEF],
            [], [], ['problem_understanding' => 'string'],
        );
        $with = $bare->withRequirements($set);

        $this->assertStringNotContainsString('EVERY ONE MUST BE ACCOUNTED FOR', $bare->renderText(),
            'without this the requirements are only prose inside the description — the 2026-08-11 condition');

        $text = $with->renderText();
        $this->assertStringContainsString('EVERY ONE MUST BE ACCOUNTED FOR', $text);
        foreach (['README', 'validation', 'responsive UI', 'persistence'] as $requirement) {
            $this->assertMatchesRegularExpression('/^\d+\. ' . preg_quote($requirement, '/') . '$/m', $text,
                "{$requirement} must appear as a numbered obligation, not merely inside the description");
        }
        $this->assertStringContainsString('record it in unknowns', $text,
            'a requirement that cannot be met must have an honest way out, or the provider will invent one');
    }

    public function test_extraction_is_deterministic(): void
    {
        // The request is fingerprinted; a list that varied between reads would
        // make the approval drift check fire at random.
        $this->assertSame(
            RequirementSet::fromDescription(self::BRIEF)->render(),
            RequirementSet::fromDescription(self::BRIEF)->render()
        );
    }

    public function test_a_task_with_no_bullets_adds_nothing_to_the_prompt(): void
    {
        $set = RequirementSet::fromDescription('Investigate why the queue is slow.');

        $this->assertSame('', $set->render(),
            'an investigation has no checklist, and inventing one would be worse than having none');
    }
}
