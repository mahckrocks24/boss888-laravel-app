<?php

namespace Tests\Feature\Social888;

use App\Core\PlanGating\PlanGatingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SOCIAL entitlement (Owner GO 2026-08-29 evening, step 4): AI Lite ($49) keeps the Social AI capability;
 * plans without AI access are refused; no tier restriction above $49 (ADR-0012).
 */
class SocialEntitlementTest extends TestCase
{
    private const WS = 999999915;
    private const PLANS = [9931 => ['free', 0, 'none'], 9932 => ['starter', 19, 'none'], 9933 => ['ai-lite', 49, 'full'], 9934 => ['growth', 99, 'full']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Soc Owner', 'email' => 'soc-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Soc WS', 'slug' => 'soc-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::PLANS as $id => [$slug, $price, $ai]) {
            DB::table('plans')->insert(['id' => $id, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'ai_access' => $ai, 'credit_limit' => 50, 'is_public' => 0,
                'features_json' => json_encode(['social' => true, 'ai_agents' => $ai === 'full', 'content_writing' => $ai === 'full']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try { DB::table('subscriptions')->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'soc-owner@example.test')->delete(); } catch (\Throwable) {}
        try { DB::table('plans')->whereIn('id', array_keys(self::PLANS))->delete(); } catch (\Throwable) {}
    }

    private function subscribe(int $planId): void
    {
        DB::table('subscriptions')->where('workspace_id', self::WS)->delete();
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $planId, 'status' => 'active', 'stripe_subscription_id' => 'sub_test_soc', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @test */
    public function test_ai_lite_keeps_social_ai_and_no_tier_above_it_is_gated(): void
    {
        $gate = app(PlanGatingService::class);
        foreach ([9933 => 'ai-lite', 9934 => 'growth'] as $planId => $slug) {
            $this->subscribe($planId);
            foreach (['social_create_post', 'social_ai_post', 'social_publish_post', 'social_schedule_post'] as $action) {
                $r = $gate->check(self::WS, $action);
                $this->assertTrue($r['allowed'] ?? false, "$slug must be allowed $action: " . json_encode($r));
            }
        }
    }

    /** @test */
    public function test_plans_without_ai_access_are_refused_social_ai_generation(): void
    {
        $gate = app(PlanGatingService::class);
        foreach ([9931 => 'free', 9932 => 'starter'] as $planId => $slug) {
            $this->subscribe($planId);
            $r = $gate->check(self::WS, 'social_ai_post');
            $this->assertFalse($r['allowed'] ?? true, "$slug must not get AI social copy");
        }
    }
}
