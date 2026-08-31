<?php

namespace Tests\Feature\Governance;

use App\Core\Governance\ApprovalService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P1R-5 (2026-08-31): a decision already made is never silently re-made — a rejected request cannot be flipped to
 *  approved and re-dispatched, and repeating the same decision neither overwrites the record nor re-queues work. */
class ApprovalDecisionGuardTest extends TestCase
{
    private function seedApproval(): array
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'ap-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'AP', 'slug' => 'ap-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $t = (int) DB::table('tasks')->insertGetId(['workspace_id' => $ws, 'engine' => 'crm', 'action' => 'list_leads', 'status' => 'pending', 'requires_approval' => 1, 'approval_status' => 'pending', 'credit_cost' => 0, 'payload_json' => json_encode(['limit' => 1]), 'source' => 'agent', 'created_at' => now(), 'updated_at' => now()]);
        $a = (int) DB::table('approvals')->insertGetId(['workspace_id' => $ws, 'task_id' => $t, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        return [$ws, $t, $a, $u];
    }

    public function test_a_rejected_request_cannot_be_flipped_to_approved(): void
    {
        [$ws, $t, $a, $u] = $this->seedApproval();
        $svc = app(ApprovalService::class);
        $svc->reject($a, $u, 'owner said no');
        $this->assertSame('failed', DB::table('tasks')->where('id', $t)->value('status'));

        $this->expectException(\DomainException::class);
        try {
            $svc->approve($a, $u);
        } finally {
            $this->assertSame('rejected', DB::table('approvals')->where('id', $a)->value('status'));
            $this->assertSame('owner said no', DB::table('approvals')->where('id', $a)->value('decision_note'), 'the owner\'s reason survives');
            $this->assertSame('failed', DB::table('tasks')->where('id', $t)->value('status'), 'the task is never re-queued');
        }
    }

    public function test_repeating_a_decision_is_idempotent_and_never_redispatches(): void
    {
        [$ws, $t, $a, $u] = $this->seedApproval();
        $svc = app(ApprovalService::class);
        $svc->approve($a, $u, 'first yes');
        $decidedAt = DB::table('approvals')->where('id', $a)->value('decided_at');
        $svc->approve($a, $u, 'second yes');
        $this->assertSame('approved', DB::table('approvals')->where('id', $a)->value('status'));
        $this->assertSame('first yes', DB::table('approvals')->where('id', $a)->value('decision_note'), 'the original decision stands');
        $this->assertSame($decidedAt, DB::table('approvals')->where('id', $a)->value('decided_at'));

        // rejecting an approved item is refused, not silently applied
        $this->expectException(\DomainException::class);
        $svc->reject($a, $u, 'changed my mind');
    }

    public function test_a_pending_request_still_decides_normally(): void
    {
        [$ws, $t, $a, $u] = $this->seedApproval();
        app(ApprovalService::class)->reject($a, $u, 'no thanks');
        $this->assertSame('rejected', DB::table('approvals')->where('id', $a)->value('status'));
        $this->assertSame('failed', DB::table('tasks')->where('id', $t)->value('status'));
        // repeating the same rejection is a no-op, not an error
        app(ApprovalService::class)->reject($a, $u, 'still no');
        $this->assertSame('no thanks', DB::table('approvals')->where('id', $a)->value('decision_note'));
    }
}
