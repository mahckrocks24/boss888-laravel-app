<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\FeatureGateService;
use App\Core\PlanGating\PlanGatingService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MONEY-1 (2026-08-29) — a 3-day-trial customer is entitled to the plan the trial mimics, and
 * every gate agrees. Before this, PlanGatingService (the execution kernel's gate) only counted
 * status='active' and a trialing customer's write_article task failed with
 * "AI features require AI Lite plan or above" while FeatureGateService said Growth/AI-yes.
 */
class EntitlementResolutionTest extends TestCase
{
    private const WS = 999999903;
    private const CHILD = 999999904;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->seedPlans();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Ent Owner', 'email' => 'ent-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Ent Test WS', 'slug' => 'ent-test-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::CHILD, 'name' => 'Ent Child WS', 'slug' => 'ent-child-ws', 'created_by' => $uid, 'billing_workspace_id' => self::WS, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    /** The test DB carries only the wp_* plans; seed the Owner's public ladder (ids 9001+) for the run. */
    private const PLANS = [
        9001 => ['free',    0,  'none', 0, 0, 1, 0,   []],
        9002 => ['starter', 19, 'none', 0, 0, 1, 0,   ['custom_domain' => true]],
        9003 => ['ai-lite', 49, 'full', 1, 0, 3, 50,  ['ai_agents' => true, 'content_writing' => true, 'image_generation' => true, 'marketing' => true, 'video_generation' => true]],
        9004 => ['growth',  99, 'full', 1, 0, 5, 300, ['ai_agents' => true, 'content_writing' => true, 'image_generation' => true, 'marketing' => true]],
    ];

    private function cleanup(): void
    {
        DB::table('subscriptions')->whereIn('workspace_id', [self::WS, self::CHILD])->delete();
        DB::table('workspaces')->whereIn('id', [self::WS, self::CHILD])->delete();
        DB::table('users')->where('email', 'ent-test-owner@example.test')->delete();
        DB::table('plans')->whereIn('id', array_keys(self::PLANS))->delete();
    }

    private function seedPlans(): void
    {
        foreach (self::PLANS as $id => [$slug, $price, $ai, $dmm, $companion, $sites, $credits, $features]) {
            if (DB::table('plans')->where('slug', $slug)->exists()) continue;
            DB::table('plans')->insert([
                'id' => $id, 'name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug, 'price' => $price, 'billing_period' => 'monthly',
                'is_public' => 1, 'credit_limit' => $credits, 'media_library_limit' => 100, 'features_json' => json_encode($features),
                'ai_access' => $ai, 'includes_dmm' => $dmm, 'agent_count' => $dmm ? 5 : 0, 'max_websites' => $sites, 'max_team_members' => 1,
                'companion_app' => $companion, 'white_label' => 0, 'priority_processing' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function sub(int $ws, string $slug, string $status, ?\DateTimeInterface $endsAt = null): void
    {
        DB::table('subscriptions')->insert([
            'workspace_id' => $ws, 'plan_id' => Plan::where('slug', $slug)->value('id'), 'provider' => 'test',
            'status' => $status, 'starts_at' => now(), 'ends_at' => $endsAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_no_subscription_resolves_to_free_and_denies_ai(): void
    {
        $this->assertSame('free', Subscription::entitledPlanFor(self::WS)->slug);
        $this->assertFalse(app(PlanGatingService::class)->check(self::WS, 'write_article')['allowed']);
        $this->assertFalse(app(FeatureGateService::class)->canUseAI(self::WS));
    }

    public function test_platform_trial_confers_the_growth_plan_on_every_gate(): void
    {
        $this->sub(self::WS, 'free', 'cancelled', now());               // what TrialService leaves behind
        $this->sub(self::WS, 'growth', 'trialing', now()->addDays(3));
        $this->assertSame('growth', Subscription::entitledPlanFor(self::WS)->slug);
        $this->assertTrue(app(PlanGatingService::class)->check(self::WS, 'write_article')['allowed'], 'the trial exists to sell AI — AI must work');
        $this->assertSame('Growth', app(PlanGatingService::class)->getPlanRules(self::WS)['plan_name']);
        $this->assertTrue(app(FeatureGateService::class)->canUseAI(self::WS));
    }

    public function test_an_ended_trial_no_longer_confers_entitlement_even_before_the_cron_expires_it(): void
    {
        $this->sub(self::WS, 'growth', 'trialing', now()->subMinute());
        $this->assertSame('free', Subscription::entitledPlanFor(self::WS)->slug);
        $this->assertFalse(app(PlanGatingService::class)->check(self::WS, 'write_article')['allowed']);
    }

    public function test_website_workspace_follows_its_billing_pool_not_its_stale_inherited_row(): void
    {
        $this->sub(self::WS, 'ai-lite', 'active');
        $this->sub(self::CHILD, 'free', 'active');                       // stale 'inherited' row
        $this->assertSame('ai-lite', Subscription::entitledPlanFor(self::CHILD)->slug);
        $this->assertTrue(app(PlanGatingService::class)->check(self::CHILD, 'write_article')['allowed']);
    }

    public function test_ai_lite_is_the_full_ai_growth_os(): void
    {
        $this->sub(self::WS, 'ai-lite', 'active');
        $g = app(PlanGatingService::class);
        foreach (['write_article', 'generate_image', 'autonomous_goal', 'create_campaign'] as $action) {
            $this->assertTrue($g->check(self::WS, $action)['allowed'], "$action must be allowed on the \$49 AI tier");
        }
        $this->assertFalse($g->check(self::WS, 'x', ['exec_api' => true])['allowed'], 'Companion App is NOT included on AI Lite');
    }

    public function test_starter_and_free_have_no_ongoing_ai(): void
    {
        $this->sub(self::WS, 'starter', 'active');
        $this->assertFalse(app(PlanGatingService::class)->check(self::WS, 'write_article')['allowed']);
        $this->assertFalse(app(FeatureGateService::class)->canUseAI(self::WS));
    }
}
