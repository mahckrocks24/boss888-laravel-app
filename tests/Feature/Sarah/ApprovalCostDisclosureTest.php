<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\SpendContext;
use App\Core\TaskSystem\TaskService;
use App\Engines\Builder\Support\ArthurCostEstimate;
use App\Engines\Builder\Support\BuilderCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * RISK-0189 (2026-09-17) — the credit cost the owner approves is the cost Arthur will charge, or is declared unknown;
 * "Uses no credits" is said only for a cost that is known to be zero, and a stale figure never authorises a spend.
 */
class ApprovalCostDisclosureTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->site = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->testWorkspace->id, 'name' => 'QA Harbour Yoga', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        app(SpendContext::class)->setTurn(['authorized' => true, 'specifies_action' => true, 'reason' => 'test', 'classification' => 'directive'], $this->testWorkspace->id);
    }

    public function test_the_estimate_is_the_registry_arthur_charges_from_or_unknown(): void
    {
        $p = BuilderCapabilities::pricing();
        $e = ArthurCostEstimate::forRequest('Change the hero headline on QA Harbour Yoga to: Breathe, Move, Belong');
        $this->assertTrue($e['known']); $this->assertSame($p['text_edit'], $e['credits']); $this->assertSame('edit', $e['kind']);
        $e = ArthurCostEstimate::forRequest('Make the primary button colour dark green');
        $this->assertTrue($e['known']); $this->assertSame($p['style'], $e['credits']);
        $e = ArthurCostEstimate::forRequest('Add a testimonials section after the menu');
        $this->assertTrue($e['known']); $this->assertSame($p['section'], $e['credits']);
        $e = ArthurCostEstimate::forRequest('Add a pricing page');
        $this->assertTrue($e['known']); $this->assertSame($p['page'], $e['credits']);
        foreach (['Remove the background from the hero image', 'Make me a promo video', 'asdf qwerty'] as $req) {
            $e = ArthurCostEstimate::forRequest($req);
            $this->assertFalse($e['known'], $req); $this->assertSame(0, $e['credits']);
            $this->assertStringContainsString('not free', $e['note']);
        }
        $this->assertSame('1 credit', ArthurCostEstimate::describe(0, ['known' => true, 'credits' => 1]), 'the disclosed figure is the estimate, not the task cost');
        $this->assertSame(1, ArthurCostEstimate::disclosed(0, ['known' => true, 'credits' => 1]));
        $this->assertSame('no credits', ArthurCostEstimate::describe(0, null), 'a genuinely known zero is free');
        $this->assertStringContainsString('not free', ArthurCostEstimate::describe(0, ['known' => false]));
    }

    private function askArthur(string $request): int
    {
        $t = app(TaskService::class)->create($this->testWorkspace->id, [
            'engine' => 'builder', 'action' => 'ask_arthur', 'source' => 'agent', 'priority' => 'normal',
            'payload' => ['website_id' => $this->site, 'request' => $request], 'assigned_agents' => ['arthur'],
            'user_id' => $this->testUser->id, 'agent_id' => 'sarah',
        ]);
        return (int) $t->id;
    }

    public function test_an_ask_arthur_task_carries_the_applicable_cost_and_the_card_shows_it(): void
    {
        $tid = $this->askArthur('Change the hero headline on QA Harbour Yoga to: Breathe, Move, Belong');
        $row = DB::table('tasks')->where('id', $tid)->first(['credit_cost', 'payload_json', 'requires_approval']);
        $this->assertSame(0, (int) $row->credit_cost, "tasks.credit_cost stays the capability map cost — the disclosure must not be reserved a second time (Arthur's executor charges)");
        $p = json_decode((string) $row->payload_json, true);
        $this->assertTrue($p["credit_estimate"]["known"]);
        $this->assertSame(1, $p["credit_estimate"]["credits"]);
        $this->assertSame('BuilderCapabilities::classify', $p['credit_estimate']['source']);
        $this->assertSame(1, (int) $row->requires_approval, 'ask_arthur stays review-gated');

        $list = $this->withHeaders($this->authHeaders())->getJson('/api/approvals?status=pending&per_page=20')->assertOk()->json('items');
        $item = collect($list)->first(fn ($i) => (int) ($i['task']['id'] ?? 0) === $tid);
        $this->assertNotNull($item, 'the approval is listed');
        $this->assertTrue($item['task']['credit_cost_known']);
        $this->assertSame(1, $item["task"]["credit_disclosed"]);
        $this->assertSame('1 credit', $item['task']['credit_note']);
    }

    public function test_a_cost_arthur_cannot_price_yet_is_never_shown_as_free(): void
    {
        $tid = $this->askArthur('Remove the background from the hero image');
        $row = DB::table('tasks')->where('id', $tid)->first(['credit_cost', 'payload_json']);
        $this->assertSame(0, (int) $row->credit_cost);
        $this->assertFalse(json_decode((string) $row->payload_json, true)['credit_estimate']['known']);
        $list = $this->withHeaders($this->authHeaders())->getJson('/api/approvals?status=pending&per_page=20')->assertOk()->json('items');
        $item = collect($list)->first(fn ($i) => (int) ($i['task']['id'] ?? 0) === $tid);
        $this->assertFalse($item['task']['credit_cost_known']);
        $this->assertStringContainsString('not free', $item['task']['credit_note']);
        $this->assertStringNotContainsString('no credits', $item['task']['credit_note']);
    }

    public function test_a_stale_cost_never_authorises_the_approval(): void
    {
        $tid = $this->askArthur('Change the hero headline on QA Harbour Yoga to: Breathe, Move, Belong');
        $aid = (int) DB::table('approvals')->where('task_id', $tid)->where('status', 'pending')->value('id');
        $this->assertGreaterThan(0, $aid);
        // the card showed "no credits" (0) but the task costs 1 — refused, current disclosure returned, nothing approved
        $r = $this->withHeaders($this->authHeaders())->postJson("/api/approvals/{$aid}/approve", ['expected_credit_cost' => 0]);
        $r->assertStatus(409)->assertJson(["error" => "stale_cost", "expected" => 0, "current" => 1, "credit_note" => "1 credit"]);
        $this->assertSame('pending', DB::table('approvals')->where('id', $aid)->value('status'));
        $this->assertSame('pending', DB::table('tasks')->where('id', $tid)->value('status'));
        // the card showed a figure but the cost became unknown after it was drawn — refused as well
        DB::table('tasks')->where('id', $tid)->update(['payload_json' => json_encode(['website_id' => $this->site, 'request' => 'x', 'credit_estimate' => ['known' => false, 'credits' => 0]])]);
        $this->withHeaders($this->authHeaders())->postJson("/api/approvals/{$aid}/approve", ['expected_credit_cost' => 1])->assertStatus(409)->assertJson(['error' => 'stale_cost', 'current' => 'unknown']);
        $this->assertSame('pending', DB::table('approvals')->where('id', $aid)->value('status'));
    }

    public function test_other_actions_keep_their_capability_map_cost(): void
    {
        $t = app(TaskService::class)->create($this->testWorkspace->id, [
            'engine' => 'write', 'action' => 'write_article', 'source' => 'agent', 'priority' => 'normal',
            'payload' => ['title' => 'A piece', 'website_id' => $this->site], 'assigned_agents' => ['priya'], 'user_id' => $this->testUser->id, 'agent_id' => 'sarah',
        ]);
        $p = json_decode((string) DB::table('tasks')->where('id', $t->id)->value('payload_json'), true);
        $this->assertArrayNotHasKey('credit_estimate', $p);
    }
}
