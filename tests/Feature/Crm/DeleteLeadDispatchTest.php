<?php

namespace Tests\Feature\Crm;

use App\Core\TaskSystem\Orchestrator;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADV-FORENSIC F2 (2026-08-31): delete_lead was capability-mapped but had no Orchestrator handler — an approved
 *  delete task answered "This action isn't supported yet". Now it soft-deletes the lead, workspace-scoped. */
class DeleteLeadDispatchTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'dl-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'DL', 'slug' => 'dl-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    public function test_delete_lead_task_executes_and_is_workspace_scoped(): void
    {
        $ws = $this->ws(); $other = $this->ws();
        $lead = (int) DB::table('leads')->insertGetId(['workspace_id' => $ws, 'name' => 'Doomed Lead', 'status' => 'new', 'score' => 0, 'deal_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = (int) DB::table('leads')->insertGetId(['workspace_id' => $other, 'name' => 'Safe Lead', 'status' => 'new', 'score' => 0, 'deal_value' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $task = Task::create(['workspace_id' => $ws, 'engine' => 'crm', 'action' => 'delete_lead', 'status' => 'queued', 'requires_approval' => 0, 'approval_status' => 'approved', 'credit_cost' => 0, 'payload_json' => ['lead_id' => $lead], 'source' => 'agent']);
        app(Orchestrator::class)->execute($task->fresh());
        $task = $task->fresh();
        $this->assertSame('completed', $task->status, $task->error_text . ' / ' . $task->progress_message);
        $this->assertNotNull(DB::table('leads')->where('id', $lead)->value('deleted_at'), 'the lead is soft-deleted');

        // a cross-workspace lead id must fail, not delete
        $task2 = Task::create(['workspace_id' => $ws, 'engine' => 'crm', 'action' => 'delete_lead', 'status' => 'queued', 'requires_approval' => 0, 'approval_status' => 'approved', 'credit_cost' => 0, 'payload_json' => ['lead_id' => $foreign], 'source' => 'agent']);
        try { app(Orchestrator::class)->execute($task2->fresh()); } catch (\Throwable $e) { /* a hard failure is acceptable */ }
        $this->assertNull(DB::table('leads')->where('id', $foreign)->value('deleted_at'), 'another workspace\'s lead is never touched');
    }
}
