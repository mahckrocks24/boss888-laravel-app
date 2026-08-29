<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\StripeService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MONEY-1 (2026-08-29) — the Stripe webhook endpoint runs on API 2026-03-25 (dahlia). Since
 * 2025-03-31.basil `invoice.subscription` is gone (→ invoice.parent.subscription_details.subscription),
 * so every renewal (invoice.paid) and every failed payment was dropped as "subscription_not_found".
 * And customer.subscription.created had no handler at all.
 */
class StripeWebhookShapeTest extends TestCase
{
    private const WS = 999999905;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Hook Owner', 'email' => 'hook-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Hook WS', 'slug' => 'hook-ws', 'created_by' => $uid, 'is_trial' => 1, 'trial_credits' => 50, 'trial_started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if (! DB::table('plans')->where('slug', 'ai-lite')->exists()) {
            DB::table('plans')->insert(['id' => 9003, 'name' => 'AI Lite', 'slug' => 'ai-lite', 'price' => 49, 'billing_period' => 'monthly', 'is_public' => 1, 'credit_limit' => 50, 'media_library_limit' => 100, 'features_json' => '{}', 'ai_access' => 'full', 'includes_dmm' => 1, 'agent_count' => 5, 'max_websites' => 3, 'max_team_members' => 1, 'companion_app' => 0, 'white_label' => 0, 'priority_processing' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DB::table('credit_transactions')->where('workspace_id', self::WS)->delete();
        DB::table('credits')->where('workspace_id', self::WS)->delete();
        DB::table('subscriptions')->where('workspace_id', self::WS)->delete();
        DB::table('workspaces')->where('id', self::WS)->delete();
        DB::table('users')->where('email', 'hook-test-owner@example.test')->delete();
        DB::table('plans')->where('id', 9003)->delete();
    }

    private function invokePrivate(string $method, ...$args)
    {
        $svc = app(StripeService::class);
        $m = new \ReflectionMethod($svc, $method);
        $m->setAccessible(true);
        return $m->invoke($svc, ...$args);
    }

    private function obj(array $a): object
    {
        return json_decode(json_encode($a));
    }

    public function test_invoice_subscription_is_read_from_both_the_legacy_and_the_basil_shape(): void
    {
        $this->assertSame('sub_old', $this->invokePrivate('invoiceSubscriptionId', $this->obj(['subscription' => 'sub_old'])));
        $this->assertSame('sub_new', $this->invokePrivate('invoiceSubscriptionId', $this->obj(['parent' => ['subscription_details' => ['subscription' => 'sub_new']]])));
        $this->assertSame('sub_line', $this->invokePrivate('invoiceSubscriptionId', $this->obj(['lines' => ['data' => [['parent' => ['subscription_item_details' => ['subscription' => 'sub_line']]]]]])));
        $this->assertNull($this->invokePrivate('invoiceSubscriptionId', $this->obj(['id' => 'in_x'])));
    }

    public function test_subscription_created_provisions_the_plan_ends_the_trial_and_is_idempotent(): void
    {
        $planId = (int) Plan::where('slug', 'ai-lite')->value('id');
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $planId, 'provider' => 'trial', 'status' => 'trialing', 'starts_at' => now(), 'ends_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now()]);
        $sub = $this->obj(['id' => 'sub_test_1', 'status' => 'active', 'customer' => 'cus_test', 'metadata' => ['workspace_id' => (string) self::WS, 'plan_id' => (string) $planId]]);

        $r = $this->invokePrivate('handleSubscriptionCreated', $sub);
        $this->assertSame('subscription_created', $r['action']);
        $this->assertSame('ai-lite', Subscription::entitledFor(self::WS)?->plan?->slug);
        $this->assertSame('superseded', DB::table('subscriptions')->where('workspace_id', self::WS)->where('provider', 'trial')->value('status'), 'the platform trial row (NULL stripe id) must be superseded — NULL-safe');
        $this->assertSame(0, (int) DB::table('workspaces')->where('id', self::WS)->value('is_trial'));
        $this->assertSame(50, (int) DB::table('credits')->where('workspace_id', self::WS)->value('balance'));

        $again = $this->invokePrivate('handleSubscriptionCreated', $sub);
        $this->assertSame('already_provisioned', $again['action']);
        $this->assertSame(1, DB::table('subscriptions')->where('stripe_subscription_id', 'sub_test_1')->count());
    }

    public function test_subscription_created_without_contract_metadata_is_acknowledged_not_provisioned(): void
    {
        $r = $this->invokePrivate('handleSubscriptionCreated', $this->obj(['id' => 'sub_x', 'status' => 'active', 'metadata' => []]));
        $this->assertFalse($r['handled']);
        $this->assertSame(0, DB::table('subscriptions')->where('workspace_id', self::WS)->count());
    }

    public function test_invoice_paid_in_basil_shape_refreshes_credits(): void
    {
        $planId = (int) Plan::where('slug', 'ai-lite')->value('id');
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $planId, 'provider' => 'stripe', 'status' => 'active', 'stripe_subscription_id' => 'sub_test_2', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => self::WS, 'balance' => 3, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->invokePrivate('handleInvoicePaid', $this->obj(['id' => 'in_1', 'parent' => ['subscription_details' => ['subscription' => 'sub_test_2']]]));
        $this->assertSame('credits_refreshed', $r['action']);
        $this->assertSame(50, (int) DB::table('credits')->where('workspace_id', self::WS)->value('balance'));
    }
}
