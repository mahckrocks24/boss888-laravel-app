<?php

namespace Tests\Feature\Meetings;

use App\Core\Orchestration\AgentMeetingEngine;
use App\Engines\CRM\Services\CrmService;
use App\Models\Meeting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MEET-3/4 (2026-08-29) — a closed meeting's plan becomes tasks exactly once; LLM placeholders never reach a
 * task payload; a URL-taking action is grounded on the live site; a CRM action without a target is held for
 * a human instead of crashing; CrmService::logActivity refuses truthfully without an entity.
 */
class MeetingPlanTasksTest extends TestCase
{
    private const WS = 999999912;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Plan Owner', 'email' => 'plan-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Plan WS', 'slug' => 'plan-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['workspace_id' => self::WS, 'name' => 'Plan Site', 'subdomain' => 'plan-site.levelupgrowth.io', 'status' => 'published', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => self::WS, 'balance' => 50, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try { DB::table('meeting_tasks')->whereIn('meeting_id', DB::table('meetings')->where('workspace_id', self::WS)->pluck('id'))->delete(); } catch (\Throwable) {}
        foreach (['tasks', 'approvals', 'meetings', 'websites', 'credits', 'credit_transactions', 'activities', 'audit_logs'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'plan-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function closedMeetingWithPlan(array $plan): Meeting
    {
        $id = (int) DB::table('meetings')->insertGetId([
            'workspace_id' => self::WS, 'title' => 'Plan test', 'type' => 'strategy', 'status' => 'closed', 'created_by' => $this->uid,
            'metadata_json' => json_encode(['goal' => 'test', 'phase' => 'synthesis', 'plan' => $plan]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Meeting::find($id);
    }

    /** @test */
    public function test_plan_becomes_tasks_once_with_placeholders_stripped_and_url_grounded(): void
    {
        $m = $this->closedMeetingWithPlan([
            ['agent' => 'james', 'engine' => 'seo', 'action' => 'deep_audit', 'params' => ['url' => 'sourdough_pre_order_url'], 'description' => 'Audit the pre-order page'],
            ['agent' => 'elena', 'engine' => 'crm', 'action' => 'log_activity', 'params' => ['type' => 'follow_up', 'lead_id' => 'new_lead_id', 'notes' => 'Offer early-bird'], 'description' => 'Follow up the new leads'],
        ]);
        $engine = app(AgentMeetingEngine::class);
        $this->assertSame(2, $engine->createTasksFromPlan($m));
        $this->assertSame(0, $engine->createTasksFromPlan($m), 'idempotent — a second call creates nothing');
        $this->assertSame(2, DB::table('meeting_tasks')->where('meeting_id', $m->id)->count());

        $audit = DB::table('tasks')->where('workspace_id', self::WS)->where('action', 'deep_audit')->first();
        $payload = json_decode($audit->payload_json, true);
        $this->assertSame('https://plan-site.levelupgrowth.io', $payload['url'], 'placeholder URL replaced by the live site');

        $crm = DB::table('tasks')->where('workspace_id', self::WS)->where('action', 'log_activity')->first();
        $p2 = json_decode($crm->payload_json, true);
        $this->assertArrayNotHasKey('lead_id', $p2, 'placeholder lead id stripped');
        $this->assertStringContainsString('NEEDS: which lead or contact', $p2['description']);
        $this->assertSame(1, (int) $crm->requires_approval, 'held for a human instead of run-and-crash');
    }

    /** @test */
    public function test_log_activity_without_an_entity_is_refused_with_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a lead or contact/');
        app(CrmService::class)->logActivity(self::WS, ['type' => 'follow_up', 'description' => 'no target']);
    }
}
