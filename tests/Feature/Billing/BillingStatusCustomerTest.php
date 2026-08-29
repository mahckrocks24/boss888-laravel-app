<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\StripeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MONEY-2 (2026-08-29) — RISK-0127 (d): after a cancel the entitled row is the Free row (no Stripe customer
 * id) while the Stripe customer still exists; the billing card said "No Stripe customer yet" and hid
 * "Manage billing". getBillingStatus now falls back to the latest row carrying a customer id.
 */
class BillingStatusCustomerTest extends TestCase
{
    private const WS = 999999909;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Bill Owner', 'email' => 'bill-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Bill WS', 'slug' => 'bill-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        if (!DB::table('plans')->where('slug', 'free')->exists()) {
            DB::table('plans')->insert(['id' => 9901, 'name' => 'Free', 'slug' => 'free', 'price' => 0, 'credit_limit' => 0, 'is_public' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $freeId = (int) DB::table('plans')->where('slug', 'free')->value('id');
        $paidId = (int) (DB::table('plans')->where('slug', '!=', 'free')->value('id') ?: $freeId);
        // The cancelled paid row keeps the Stripe customer; the entitled row is Free with none.
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $paidId, 'status' => 'cancelled', 'stripe_customer_id' => 'cus_test_keep_me', 'stripe_subscription_id' => 'sub_test_old', 'starts_at' => now()->subMonth(), 'created_at' => now()->subMonth(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $freeId, 'status' => 'active', 'stripe_customer_id' => null, 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['subscriptions', 'credits'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'bill-test-owner@example.test')->delete(); } catch (\Throwable) {}
        try { DB::table('plans')->where('id', 9901)->delete(); } catch (\Throwable) {}
    }

    /** @test */
    public function test_billing_status_keeps_the_stripe_customer_after_a_cancel(): void
    {
        $status = app(StripeService::class)->getBillingStatus(self::WS);
        $this->assertSame('free', $status['plan_slug']);
        $this->assertSame('cus_test_keep_me', $status['stripe_customer_id'], 'Manage billing must stay reachable after a cancel');
        $this->assertFalse($status['stripe_connected']);
    }
}
