<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\StripeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0127 xx (2026-08-30) — a plan's credit allocation is a ledger event: every allocation path writes a
 * credit_transactions 'credit' row (plan/allocation) alongside the balance write.
 */
class PlanAllocationLedgerTest extends TestCase
{
    private const WS = 999999916;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Alloc Owner', 'email' => 'alloc-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Alloc WS', 'slug' => 'alloc-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plans')->insert(['id' => 9941, 'name' => 'AI Lite', 'slug' => 'ai-lite', 'price' => 49, 'ai_access' => 'full', 'credit_limit' => 50, 'is_public' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['subscriptions', 'credits', 'credit_transactions'] as $t) { try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {} }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'alloc-owner@example.test')->delete(); } catch (\Throwable) {}
        try { DB::table('plans')->where('id', 9941)->delete(); } catch (\Throwable) {}
    }

    /** @test */
    public function test_assigning_a_plan_sets_the_balance_and_writes_an_allocation_ledger_row(): void
    {
        $svc = app(StripeService::class);
        $m = new \ReflectionMethod($svc, 'devActivate');
        $m->setAccessible(true);
        $m->invoke($svc, self::WS, 9941, $this->uid);

        $this->assertSame(50, (int) DB::table('credits')->where('workspace_id', self::WS)->value('balance'));
        $row = DB::table('credit_transactions')->where('workspace_id', self::WS)->where('reference_type', 'plan/allocation')->first();
        $this->assertNotNull($row, 'the allocation must be visible in the ledger');
        $this->assertSame('credit', $row->type);
        $this->assertSame(50, (int) $row->amount);
        $this->assertSame(9941, (int) $row->reference_id);
        $this->assertStringContainsString('plan_assigned', (string) $row->metadata_json);
    }
}
