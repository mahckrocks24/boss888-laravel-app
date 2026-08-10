<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Chat\IntentRouter;
use Tests\TestCase;

/**
 * Normal engineering language must not execute anything — and must not be
 * mistaken for trying to.
 *
 * THE REGRESSION. On 2026-08-10 the acceptance brief was refused outright and no
 * task was created:
 *
 *   "…a README explaining the structure and how to run it."
 *   → matched the bare phrase "run it" → REQUIRES_ACTION_CARD / EXECUTE_TASK
 *
 * Asking for documentation about running the application was read as an
 * instruction to run it. The table also held "approve", "do it", "install it"
 * and "deploy it" — all ordinary words in a description of work.
 *
 * The refusals themselves are not the bug and are all still here: free text may
 * never authorise anything. What changed is that a high-risk match now requires
 * command semantics rather than the mere presence of a word.
 */
class IntentRoutingTest extends TestCase
{
    private IntentRouter $router;

    /** The exact message that failed on 2026-08-10. */
    private const ACCEPTANCE_BRIEF =
        'Engineer, build a small internal Bug Tracker application from scratch in the acceptance sandbox. '
        . 'Requirements: list bugs; create a bug; edit a bug; fields: title, description, severity '
        . '(Low, Medium, High, Critical), status (Open, In Progress, Resolved); server-side validation; '
        . 'simple persistence; clean minimal responsive UI; tests for the important behavior; README '
        . 'explaining the structure and how to run it. Do not implement anything until the candidate is '
        . 'ready for my review. Keep the architecture intentionally simple. This is a small internal tool, '
        . 'not a platform.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new IntentRouter();
    }

    private function intent(string $text): string
    {
        return $this->router->route($text)['intent'];
    }

    // ── 26-27, 30 · description must not execute ────────────────────────────

    public function test_the_acceptance_brief_creates_a_task(): void
    {
        $this->assertSame(IntentRouter::CREATE_TASK, $this->intent(self::ACCEPTANCE_BRIEF),
            'this exact message produced no task at all on 2026-08-10');
    }

    public function test_asking_for_a_readme_about_running_the_app_is_not_an_execution_command(): void
    {
        $this->assertNotSame(IntentRouter::REQUIRES_ACTION_CARD,
            $this->intent('README explaining how to run it'));
    }

    public function test_asking_how_to_run_tests_is_not_an_execution_command(): void
    {
        foreach (['tell me how to run tests',
                  'tell me how to execute tests',
                  'document deployment',
                  'write up instructions describing how to deploy it'] as $text) {
            $this->assertNotSame(IntentRouter::REQUIRES_ACTION_CARD, $this->intent($text),
                "'{$text}' describes work; it does not order any");
        }
    }

    public function test_a_governed_verb_inside_a_sentence_about_code_is_not_a_command(): void
    {
        $this->assertNotSame(IntentRouter::REQUIRES_ACTION_CARD,
            $this->intent('the code should approve users before they can post'));

        $this->assertNotSame(IntentRouter::REQUIRES_ACTION_CARD,
            $this->intent('do it yourself is not allowed in this codebase'));
    }

    public function test_a_documentation_clause_does_not_disarm_a_command_elsewhere(): void
    {
        $this->assertSame(IntentRouter::REQUIRES_ACTION_CARD,
            $this->intent('the README explains the layout. execute the approved task.'),
            'suppression is per clause, not per message — otherwise one stray noun disarms the guard');
    }

    // ── 28-29 · real commands still refuse ──────────────────────────────────

    public function test_real_execution_commands_still_require_an_action_card(): void
    {
        foreach (['run it', 'execute it', 'execute the approved task', 'apply the change',
                  'deploy the implementation', 'ship the approved task', 'make the change'] as $text) {
            $result = $this->router->route($text);
            $this->assertSame(IntentRouter::REQUIRES_ACTION_CARD, $result['intent'], "'{$text}' must refuse");
            $this->assertSame('EXECUTE_TASK', $result['blocked_action'], "'{$text}' must name the execute card");
        }
    }

    public function test_real_approval_commands_still_require_an_action_card(): void
    {
        foreach (['approve the candidate', 'accept the candidate', 'approve it', 'lgtm',
                  'ship it', 'go ahead', 'do it'] as $text) {
            $result = $this->router->route($text);
            $this->assertSame(IntentRouter::REQUIRES_ACTION_CARD, $result['intent'], "'{$text}' must refuse");
            $this->assertSame('APPROVE_CANDIDATE', $result['blocked_action'], "'{$text}' must name the approve card");
        }
    }

    public function test_recovery_migration_and_access_commands_still_refuse(): void
    {
        $this->assertSame('APPROVE_RECOVERY', $this->router->route('roll it back')['blocked_action']);
        $this->assertSame('APPROVE_RECOVERY', $this->router->route('approve the recovery')['blocked_action']);
        $this->assertSame('APPROVE_MIGRATION', $this->router->route('run the migration')['blocked_action']);
        $this->assertSame('GRANT_ACCESS', $this->router->route('grant access to the console')['blocked_action']);
    }

    // ── 31 · the invariant that must never move ─────────────────────────────

    public function test_free_text_can_never_authorise_a_high_risk_action(): void
    {
        foreach (['approve the candidate', 'execute the approved task', 'run it', 'lgtm'] as $text) {
            $this->assertFalse(
                $this->router->isSafeForFreeText($this->intent($text)),
                "'{$text}' must never be performable from a typed sentence"
            );
        }

        $this->assertTrue($this->router->isSafeForFreeText(IntentRouter::CREATE_TASK));
    }

    public function test_the_earlier_status_widget_regression_is_still_fixed(): void
    {
        $this->assertSame(IntentRouter::CREATE_TASK,
            $this->intent('investigate why the topbar status widget is stuck'),
            'a work request containing a status-shaped noun is still a task');
    }
}
