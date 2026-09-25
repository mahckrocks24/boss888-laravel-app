<?php

namespace Tests\Feature\Governance;

use App\Core\Governance\ApprovalService;
use App\Core\Governance\MandateService;
use App\Core\Orchestration\AgentMeetingEngine;
use App\Core\TaskSystem\Orchestrator;
use App\Models\Meeting;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * MANDATE-1 (DEC-0018 / SPEC-0023, Owner 2026-09-25: "Fix approval gating … only Sarah's execution plan"):
 * the customer approves a Plan of Action ONCE; its tasks are created on approval, carry the plan's reference,
 * need no approval of their own, and run only while the plan is live and inside its spend ceiling.
 */
class MandateTest extends TestCase
{
    private const WS = 999999913;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Mandate Owner', 'email' => 'mandate-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Mandate WS', 'slug' => 'mandate-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['workspace_id' => self::WS, 'name' => 'Mandate Site', 'subdomain' => 'mandate-site.levelupgrowth.io', 'status' => 'published', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
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
        foreach (['tasks', 'approvals', 'mandates', 'meetings', 'websites', 'credits', 'credit_transactions', 'activities', 'audit_logs', 'workspace_users', 'notifications'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'mandate-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function closedMeeting(array $plan): Meeting
    {
        $id = (int) DB::table('meetings')->insertGetId([
            'workspace_id' => self::WS, 'title' => 'Strategy: Seo', 'type' => 'strategy', 'status' => 'closed', 'created_by' => $this->uid,
            'metadata_json' => json_encode(['goal' => 'More bookings from search', 'phase' => 'synthesis', 'plan' => $plan]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Meeting::find($id);
    }

    private function threeStepPlan(): array
    {
        return [
            ['agent' => 'james', 'engine' => 'seo', 'action' => 'fix_orphans', 'params' => [], 'description' => 'Fix orphan pages with internal links', 'requires_approval' => true],
            ['agent' => 'priya', 'engine' => 'write', 'action' => 'improve_draft', 'params' => ['article_id' => 'thin_BOFU_pages'], 'description' => 'Tighten the thin pages', 'requires_approval' => true],
            ['agent' => 'elena', 'engine' => 'crm', 'action' => 'add_note', 'params' => ['lead_id' => 'new_lead_id', 'text' => 'Offer early-bird'], 'description' => 'Note the early-bird offer', 'requires_approval' => false],
        ];
    }

    private function approvalsFor(int $ws): \Illuminate\Support\Collection
    {
        return DB::table('approvals')->where('workspace_id', $ws)->orderBy('id')->get();
    }

    public function test_a_closed_meeting_proposes_one_plan_and_one_approval_not_one_per_task(): void
    {
        Queue::fake();
        $m = $this->closedMeeting($this->threeStepPlan());
        $engine = app(AgentMeetingEngine::class);
        $out = $engine->proposePlan($m);
        $this->assertTrue((bool) ($out['created'] ?? false));

        $this->assertSame(1, $this->approvalsFor(self::WS)->count(), 'ONE approval for the plan');
        $gate = DB::table('tasks')->where('workspace_id', self::WS)->get();
        $this->assertCount(1, $gate, 'only the gate task exists before approval');
        $this->assertSame('execute_plan', $gate[0]->action); $this->assertSame('sarah', $gate[0]->engine);
        $this->assertSame(1, (int) $gate[0]->requires_approval); $this->assertSame('pending', $gate[0]->approval_status);
        $this->assertSame((int) $out['mandate_id'], (int) $gate[0]->mandate_id);
        $approval = $this->approvalsFor(self::WS)->first();
        $this->assertSame('sarah.execute_plan', $approval->capability_key);
        $this->assertSame('plan_of_action', json_decode($approval->approval_policy_json, true)['classification']);

        $mandate = DB::table('mandates')->where('id', $out['mandate_id'])->first();
        $this->assertSame('proposed', $mandate->status);
        $this->assertSame('meeting', $mandate->source_type); $this->assertSame((int) $m->id, (int) $mandate->source_id);
        $this->assertCount(3, json_decode($mandate->tasks_json, true));
        $b = json_decode($mandate->boundaries_json, true);
        $this->assertGreaterThan(0, $b['spend_ceiling_credits']); $this->assertSame(90, $b['validity_days']);
        $payload = json_decode($gate[0]->payload_json, true);
        $this->assertSame((int) $out['mandate_id'], $payload['mandate_id']);
        $this->assertTrue($payload['credit_estimate']['known']);
        $this->assertSame($b['spend_ceiling_credits'], $payload['credit_estimate']['credits'], 'the card shows the ceiling the customer agrees to');

        // idempotent: a second close proposes nothing new
        $again = $engine->proposePlan($m);
        $this->assertFalse((bool) ($again['created'] ?? true));
        $this->assertSame(1, $this->approvalsFor(self::WS)->count());
    }

    public function test_approving_the_plan_creates_its_tasks_with_no_approvals_of_their_own(): void
    {
        Queue::fake();
        $m = $this->closedMeeting($this->threeStepPlan());
        $out = app(AgentMeetingEngine::class)->proposePlan($m);
        $gate = Task::find($out['task_id']);

        // the customer approves through the ordinary approvals service
        app(ApprovalService::class)->approve((int) $out['approval_id'], $this->uid, 'go');
        $gate->refresh();
        $this->assertSame('approved', $gate->approval_status);

        // the worker runs the gate
        app(Orchestrator::class)->execute($gate);
        $gate->refresh();
        $this->assertSame('completed', $gate->status, 'the gate completed: ' . $gate->progress_message);

        $mandate = DB::table('mandates')->where('id', $out['mandate_id'])->first();
        $this->assertSame('live', $mandate->status);
        $this->assertSame($this->uid, (int) $mandate->decided_by);
        $this->assertNotNull($mandate->ends_at);

        $children = DB::table('tasks')->where('workspace_id', self::WS)->where('action', '!=', 'execute_plan')->orderBy('id')->get();
        $this->assertCount(2, $children, 'two tasks created; the CRM step without a target is held, not created');
        foreach ($children as $c) {
            $this->assertSame(0, (int) $c->requires_approval, 'a plan task needs no approval of its own');
            $this->assertSame((int) $out['mandate_id'], (int) $c->mandate_id, 'every task carries the plan it descends from');
            $this->assertSame((int) $out['mandate_id'], json_decode($c->payload_json, true)['_mandate_id']);
        }
        $this->assertSame(0, $this->approvalsFor(self::WS)->where('status', 'pending')->count(), 'nothing left waiting for the customer');
        foreach ($this->approvalsFor(self::WS)->where('task_id', '!=', $out['task_id']) as $inh) {
            $this->assertSame('approved', $inh->status, 'a child approval the task service insisted on is approved by inheritance');
            $this->assertStringContainsString('Inherited from the approved plan', (string) $inh->decision_note);
            $this->assertSame($this->uid, (int) $inh->decision_by, 'the customer who approved the plan is the decider on record');
        }
        $this->assertSame(2, DB::table('meeting_tasks')->where('meeting_id', $m->id)->count(), 'the meeting still lists its tasks');

        $held = json_decode($mandate->meta_json, true)['held'];
        $this->assertCount(1, $held);
        $this->assertSame('add_note', $held[0]['action']); $this->assertSame('needs_target', $held[0]['reason']);
        $this->assertStringContainsString('which lead or contact', $held[0]['note']);

        // running the gate again duplicates nothing
        app(Orchestrator::class)->execute($gate);
        $this->assertSame(2, DB::table('tasks')->where('workspace_id', self::WS)->where('action', '!=', 'execute_plan')->count());
    }

    public function test_declining_the_plan_creates_nothing_and_marks_it_declined(): void
    {
        Queue::fake();
        $m = $this->closedMeeting($this->threeStepPlan());
        $out = app(AgentMeetingEngine::class)->proposePlan($m);
        app(ApprovalService::class)->reject((int) $out['approval_id'], $this->uid, 'Not now');

        $this->assertSame('declined', DB::table('mandates')->where('id', $out['mandate_id'])->value('status'));
        $this->assertSame(0, DB::table('tasks')->where('workspace_id', self::WS)->where('action', '!=', 'execute_plan')->count());
        $this->assertSame('rejected', DB::table('tasks')->where('id', $out['task_id'])->value('approval_status'));
        $this->assertSame('Not now', json_decode(DB::table('mandates')->where('id', $out['mandate_id'])->value('meta_json'), true)['decision_note']);
    }

    public function test_a_task_under_a_revoked_or_expired_plan_is_blocked_at_execution(): void
    {
        Queue::fake();
        $m = $this->closedMeeting($this->threeStepPlan());
        $out = app(AgentMeetingEngine::class)->proposePlan($m);
        app(ApprovalService::class)->approve((int) $out['approval_id'], $this->uid);
        app(Orchestrator::class)->execute(Task::find($out['task_id']));
        $child = DB::table('tasks')->where('workspace_id', self::WS)->where('action', 'fix_orphans')->first();
        $svc = app(MandateService::class);

        $this->assertTrue($svc->liveness(Task::find($child->id))['ok'], 'live plan: the task may run');

        // expired
        DB::table('mandates')->where('id', $out['mandate_id'])->update(['ends_at' => now()->subDay()]);
        $live = $svc->liveness(Task::find($child->id));
        $this->assertFalse($live['ok']); $this->assertStringContainsString('expired', $live['reason']);
        $this->assertSame('expired', DB::table('mandates')->where('id', $out['mandate_id'])->value('status'));

        // the orchestrator honours it: blocked, not run
        DB::table('tasks')->where('id', $child->id)->update(['status' => 'queued']);
        app(Orchestrator::class)->execute(Task::find($child->id));
        $this->assertSame('blocked', DB::table('tasks')->where('id', $child->id)->value('status'));

        // revoke cancels what is still open under the plan
        DB::table('mandates')->where('id', $out['mandate_id'])->update(['status' => 'live', 'ends_at' => now()->addDays(30)]);
        DB::table('tasks')->where('id', $child->id)->update(['status' => 'pending']);
        $r = $svc->revoke(self::WS, (int) $out['mandate_id'], $this->uid, 'changed my mind');
        $this->assertSame('revoked', $r['status']); $this->assertGreaterThanOrEqual(1, $r['cancelled']);
        $this->assertSame('cancelled', DB::table('tasks')->where('id', $child->id)->value('status'));
        $this->assertFalse($svc->liveness(Task::find($child->id))['ok']);
    }

    public function test_the_spend_ceiling_holds_a_task_that_would_exceed_it(): void
    {
        Queue::fake();
        $m = $this->closedMeeting($this->threeStepPlan());
        $out = app(AgentMeetingEngine::class)->proposePlan($m);
        // the customer saw a ceiling; make it one credit so the second priced step cannot fit
        DB::table('mandates')->where('id', $out['mandate_id'])->update(['boundaries_json' => json_encode(['spend_ceiling_credits' => 1, 'validity_days' => 90, 'destinations' => []])]);
        app(ApprovalService::class)->approve((int) $out['approval_id'], $this->uid);
        app(Orchestrator::class)->execute(Task::find($out['task_id']));
        $held = json_decode(DB::table('mandates')->where('id', $out['mandate_id'])->value('meta_json'), true)['held'];
        $reasons = array_column($held, 'reason');
        $this->assertContains('spend_ceiling', $reasons, 'a step past the ceiling is held, not run: ' . json_encode($held));
        $created = DB::table('tasks')->where('workspace_id', self::WS)->where('action', '!=', 'execute_plan')->sum('credit_cost');
        $this->assertLessThanOrEqual(1, (int) $created);
    }

    public function test_the_legacy_direct_path_still_creates_tasks_as_before(): void
    {
        Queue::fake();
        $m = $this->closedMeeting([
            ['agent' => 'james', 'engine' => 'seo', 'action' => 'deep_audit', 'params' => ['url' => 'sourdough_pre_order_url'], 'description' => 'Audit the pre-order page'],
            ['agent' => 'elena', 'engine' => 'crm', 'action' => 'log_activity', 'params' => ['type' => 'follow_up', 'lead_id' => 'new_lead_id', 'notes' => 'Offer early-bird'], 'description' => 'Follow up'],
        ]);
        $engine = app(AgentMeetingEngine::class);
        $this->assertSame(2, $engine->createTasksFromPlan($m));
        $this->assertSame(0, $engine->createTasksFromPlan($m));
        $crm = DB::table('tasks')->where('workspace_id', self::WS)->where('action', 'log_activity')->first();
        $this->assertSame(1, (int) $crm->requires_approval, 'legacy: a missing target is held for a human');
        $this->assertNull($crm->mandate_id);
        $this->assertNull($engine->proposePlan($m), 'a meeting that already has tasks proposes no plan');
    }
}
