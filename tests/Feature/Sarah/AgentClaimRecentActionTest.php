<?php

namespace Tests\Feature\Sarah;

use App\Core\Integrity\AgentClaimValidator;
use Tests\TestCase;

/**
 * F-U8-REVIEW (2026-09-02): a review turn reports work done earlier in the SAME
 * conversation. Such TRUE accomplishment claims must be kept when backed by real
 * this-conversation tasks (recentActions/recentSpecialists), while fabricated
 * claims with no matching real task are still stripped (RISK-0123 preserved).
 */
class AgentClaimRecentActionTest extends TestCase
{
    private array $recent = [
        'Generate meta descriptions for the 4 pages missing them on Fable QA Cafe Two.',
        'Insert internal links for pages on Fable QA Cafe Two that currently have none.',
    ];

    public function test_review_claim_backed_by_real_conversation_task_is_kept(): void
    {
        $g = new AgentClaimValidator();
        $reply = 'Today I queued tasks to address the missing meta descriptions and internal links for Fable QA Cafe Two.';
        $out = $g->validate($reply, 999993, 'sarah', false, [], $this->recent, []);
        $this->assertEmpty($out['stripped']);
        $this->assertStringNotContainsString("haven't queued", $out['reply']);
    }

    public function test_fabricated_claim_with_no_matching_task_is_still_stripped(): void
    {
        $g = new AgentClaimValidator();
        $reply = 'I queued a full redesign of the pricing page.';
        $out = $g->validate($reply, 999993, 'sarah', false, [], $this->recent, []);
        $this->assertNotEmpty($out['stripped']);
    }

    public function test_risk0123_impersonal_queue_claim_with_no_backing_is_stripped(): void
    {
        $g = new AgentClaimValidator();
        $reply = 'The task to change the hero subtitle is already queued and awaiting execution.';
        $out = $g->validate($reply, 999993, 'sarah', false, [], [], []);
        $this->assertNotEmpty($out['stripped']);
    }

    public function test_named_specialist_engaged_this_conversation_is_kept(): void
    {
        $g = new AgentClaimValidator();
        $out = $g->validate('I asked James to handle the audit.', 999993, 'sarah', false, [], [], ['james']);
        $this->assertEmpty($out['stripped']);
    }

    public function test_named_specialist_not_engaged_is_stripped(): void
    {
        $g = new AgentClaimValidator();
        $out = $g->validate('I asked Diana to handle the local SEO.', 999993, 'sarah', false, [], [], ['james']);
        $this->assertNotEmpty($out['stripped']);
    }

    public function test_backward_compat_didqueue_generic_kept(): void
    {
        $g = new AgentClaimValidator();
        $out = $g->validate("I've queued the fix.", 999993, 'sarah', true, [], [], []);
        $this->assertEmpty($out['stripped']);
    }
}
