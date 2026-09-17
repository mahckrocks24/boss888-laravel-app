<?php

namespace Tests\Feature\Sarah;

use App\Core\Integrity\AgentClaimValidator;
use Tests\TestCase;

/**
 * P2 delegation precision (2026-09-02): a delegation attribution is verified against the specialists actually
 * engaged this turn (assigned_agents_json), instead of "any task exists" licensing every named specialist.
 * Correct attributions and generic queue claims survive; a name with no task this turn is stripped; and with
 * no engaged-agent evidence nothing is over-stripped.
 */
class AgentClaimAttributionTest extends TestCase
{
    private function v(): AgentClaimValidator
    {
        return app(AgentClaimValidator::class);
    }

    public function test_correct_attribution_is_kept(): void
    {
        // Elena (Lead & CRM Manager) genuinely got the lead task this turn.
        $r = $this->v()->validate('Queued Elena to add the lead.', 1, 'sarah', true, ['elena']);
        $this->assertSame([], $r['stripped']);
    }

    public function test_unbacked_named_specialist_is_stripped(): void
    {
        // A task exists this turn (didQueue) but James was NOT engaged — the attribution is invented.
        $r = $this->v()->validate('James is inserting the internal links now.', 1, 'sarah', true, ['elena']);
        $this->assertNotEmpty($r['stripped']);
        // RISK-0186 (2026-09-17): work WAS queued this turn, so "I haven't queued that yet" would itself be a false statement —
        // the footer says the unbacked part is not in hand, and only the started work is running.
        $this->assertStringContainsString("isn't in hand", $r['reply']);
        $this->assertStringNotContainsString("haven't queued", $r['reply']);
    }

    public function test_generic_queue_claim_is_kept_when_work_exists(): void
    {
        $r = $this->v()->validate("I've queued that for you.", 1, 'sarah', true, ['elena']);
        $this->assertSame([], $r['stripped']);
    }

    public function test_no_engaged_evidence_does_not_over_strip(): void
    {
        // A task exists but we could not determine who — don't strip a named claim (fall back to safe behaviour).
        $r = $this->v()->validate('James is inserting the internal links now.', 1, 'sarah', true, []);
        $this->assertSame([], $r['stripped']);
    }

    public function test_no_task_this_turn_strips_the_claim(): void
    {
        $r = $this->v()->validate('Queued Elena to add the lead.', 1, 'sarah', false, []);
        $this->assertNotEmpty($r['stripped']);
    }

    public function test_mixed_reply_keeps_backed_and_strips_unbacked(): void
    {
        $r = $this->v()->validate('Queued Elena to add the lead. James is drafting the SEO fixes now.', 1, 'sarah', true, ['elena']);
        $this->assertStringContainsString('Elena', $r['reply']);
        $this->assertStringNotContainsString('James is drafting', $r['reply']);
    }

    public function test_specialist_can_never_claim_delegation(): void
    {
        $r = $this->v()->validate("I've queued the fix for myself.", 1, 'james', true, ['james']);
        $this->assertNotEmpty($r['stripped']);
    }
}
