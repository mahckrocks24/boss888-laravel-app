<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\FeatureGateService;
use App\Core\Billing\TrialService;
use App\Core\PlanGating\PlanGatingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI Lite ($49) entitlement truth (Owner correction #5, ADR-0012): the full AI Growth OS — agents, content,
 * images, AEO, VIDEO — with capacity by credits; the Companion app excluded. MONEY-4: the platform's own
 * trialing row is not a "paid plan" (the trial card said "$99/month · Renews" for a fresh signup).
 */
class AiLiteEntitlementTest extends TestCase
{
    private const WS = 999999914;
    private const PLANS = [
        9921 => ['starter', 19,  'none', 0],
        9922 => ['ai-lite', 49,  'full', 0],
        9923 => ['pro',     199, 'full', 1],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'AL Owner', 'email' => 'al-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'AL WS', 'slug' => 'al-ws', 'created_by' => $uid, 'trial_started_at' => now(), 'is_trial' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::PLANS as $id => [$slug, $price, $ai, $companion]) {
            DB::table('plans')->insert(['id' => $id, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'ai_access' => $ai, 'companion_app' => $companion, 'credit_limit' => 50, 'is_public' => 0,
                'features_json' => json_encode(['ai_agents' => $ai === 'full', 'content_writing' => $ai === 'full', 'image_generation' => $ai === 'full', 'video_generation' => $ai === 'full']),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['subscriptions', 'credits'] as $t) { try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {} }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'al-owner@example.test')->delete(); } catch (\Throwable) {}
        try { DB::table('plans')->whereIn('id', array_keys(self::PLANS))->delete(); } catch (\Throwable) {}
    }

    private function subscribe(int $planId, string $status = 'active', ?string $stripeSub = 'sub_test_x'): void
    {
        DB::table('subscriptions')->where('workspace_id', self::WS)->delete();
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $planId, 'status' => $status, 'stripe_subscription_id' => $stripeSub, 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @test */
    public function test_ai_lite_gets_video_and_agents_but_not_the_companion_app(): void
    {
        $this->subscribe(9922);
        $gate = app(PlanGatingService::class);
        $this->assertTrue($gate->check(self::WS, 'generate_video')['allowed'] ?? false, 'video is part of the $49 AI plan');
        $this->assertTrue($gate->check(self::WS, 'write_article')['allowed'] ?? false);
        $this->assertTrue(app(FeatureGateService::class)->canUseVideo(self::WS));
        $this->assertFalse(app(FeatureGateService::class)->canUseApp888(self::WS), 'Companion stays excluded at $49');
        $this->subscribe(9923);
        $this->assertTrue(app(FeatureGateService::class)->canUseApp888(self::WS), 'Pro keeps the Companion app');
        $this->subscribe(9921);
        $denied = $gate->check(self::WS, 'generate_video');
        $this->assertFalse($denied['allowed'] ?? true);
        $this->assertStringNotContainsString('Pro plan', (string) ($denied['reason'] ?? $denied['message'] ?? ''));
    }

    /** @test */
    public function test_the_platform_trial_row_is_not_a_paid_plan(): void
    {
        // A fresh signup: trialing on a paid plan WITHOUT a Stripe subscription = the platform's own trial.
        $this->subscribe(9923, 'trialing', null);
        $this->assertTrue(app(TrialService::class)->isInTrial(self::WS), 'the trial card must say "free trial", not "$199/month · Renews"');
        // A real Stripe trial (subscription id present) is a paid-plan trial, not the platform trial.
        $this->subscribe(9923, 'trialing', 'sub_test_real');
        $this->assertFalse(app(TrialService::class)->isInTrial(self::WS));
    }
}
