<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\CompletionGuard;
use Tests\TestCase;

/**
 * U4 (2026-09-02): a genuine this-turn delegation referenced by topic (not by
 * its verbatim task title) must NOT be rewritten to NOT_DONE — but a hard
 * publish/destructive claim, or an unrelated fabrication, still must be.
 */
class CompletionGuardTokenMatchTest extends TestCase
{
    private array $verified = [[
        'action' => 'write_article',
        'entity' => 'Weekend Sourdough: How to Bake a Rustic Loaf at Home',
    ]];

    public function test_genuine_delegation_referenced_by_topic_is_spared(): void
    {
        $g = new CompletionGuard();
        $out = $g->validate("I've completed the sourdough article.", 999994, $this->verified);
        $this->assertStringNotContainsString("haven't done that yet", $out['reply']);
        $this->assertEmpty($out['rewritten']);
    }

    public function test_exact_title_still_spared_control(): void
    {
        $g = new CompletionGuard();
        $reply = "Done — I've completed Weekend Sourdough: How to Bake a Rustic Loaf at Home.";
        $out = $g->validate($reply, 999994, $this->verified);
        $this->assertStringNotContainsString("haven't done that yet", $out['reply']);
    }

    public function test_hard_publish_claim_requires_exact_entity_and_is_still_blocked(): void
    {
        $g = new CompletionGuard();
        // "published" is a hard claim; topic token "sourdough" must NOT spare it.
        $out = $g->validate("I've published the sourdough article.", 999994, $this->verified);
        $this->assertStringContainsString("haven't done that yet", $out['reply']);
    }

    public function test_unrelated_destructive_fabrication_still_blocked(): void
    {
        $g = new CompletionGuard();
        $out = $g->validate("I've deleted the pricing page.", 999994, $this->verified);
        $this->assertStringContainsString("haven't done that yet", $out['reply']);
    }

    public function test_no_verified_action_keeps_strict_gate(): void
    {
        $g = new CompletionGuard();
        $out = $g->validate("I've updated the homepage.", 999994, []);
        $this->assertStringContainsString("haven't done that yet", $out['reply']);
    }

    public function test_state_report_never_gated(): void
    {
        $g = new CompletionGuard();
        $out = $g->validate("You have 12 tasks completed in the last 7 days.", 999994, []);
        $this->assertStringNotContainsString("haven't done that yet", $out['reply']);
    }
}
