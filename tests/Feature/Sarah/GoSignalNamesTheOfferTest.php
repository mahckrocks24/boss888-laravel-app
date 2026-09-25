<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\SpendPolicy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0204 part 3 (Owner's companion screenshot 2026-09-25): "go" after Sarah's "I can share the articles to Facebook,
 * but I need your approval first" was accepted as authorisation and then refused as UNSPECIFIED_DELEGATION because the
 * one word names nothing. It names what she offered.
 */
class GoSignalNamesTheOfferTest extends TestCase
{
    private const WS = 999999915;

    protected function setUp(): void
    {
        parent::setUp(); $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Go Owner', 'email' => 'go-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Go WS', 'slug' => 'go-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
    }
    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }
    private function cleanup(): void
    {
        try { DB::table('agent_messages')->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'go-owner@example.test')->delete(); } catch (\Throwable) {}
    }
    private function sarahSaid(string $content): void
    {
        DB::table('agent_messages')->insert(['workspace_id' => self::WS, 'agent_slug' => 'sarah', 'sender' => 'Sarah', 'role' => 'agent', 'content' => $content, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_go_after_an_offer_specifies_the_offered_work(): void
    {
        $this->sarahSaid("I can share the articles to Facebook, but I need your approval first. Once you give the go-ahead, I can publish those posts with the captions we discussed. Let me know if you want to proceed.");
        $t = app(SpendPolicy::class)->assessTurnInConversation('go', self::WS);
        $this->assertTrue($t['authorized']);
        $this->assertTrue($t['specifies_action'], 'the go names what Sarah offered: ' . json_encode($t));
        $this->assertStringContainsString('offered in her previous message', $t['reason']);

        $t = app(SpendPolicy::class)->assessTurnInConversation('okay go', self::WS);
        $this->assertTrue($t['specifies_action']);
    }

    public function test_the_offer_may_sit_behind_a_refusal_notice(): void
    {
        $this->sarahSaid("I can share the articles to Facebook, but I need your approval first. Let me know if you want to proceed.");
        $this->sarahSaid("I couldn't start this:\n\n- (2x) a temporary system issue (logged for the team)");
        $t = app(SpendPolicy::class)->assessTurnInConversation('go', self::WS);
        $this->assertTrue($t['specifies_action'], 'the refusal notice does not erase the offer: ' . json_encode($t));
    }

    public function test_go_with_no_offer_behind_it_still_names_nothing(): void
    {
        $this->sarahSaid("Both articles are live now on chefredraymundo.com.");
        $t = app(SpendPolicy::class)->assessTurnInConversation('go', self::WS);
        $this->assertTrue($t['authorized']);
        $this->assertFalse($t['specifies_action'], 'nothing was offered, so a bare go must not let Sarah invent work: ' . json_encode($t));
    }

    public function test_a_named_order_never_needed_the_offer(): void
    {
        $t = app(SpendPolicy::class)->assessTurnInConversation('share both articles on the facebook page', self::WS);
        $this->assertTrue($t['authorized']); $this->assertTrue($t['specifies_action']);
    }
}
