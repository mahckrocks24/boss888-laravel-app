<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\QueueCancellation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** QUEUE-CANCEL (2026-08-30): the owner cancels what is waiting for their OK — described first, executed on yes, scoped by kind/age. */
class QueueCancellationTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'qc-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('workspaces')->insertGetId(['name' => 'QC', 'slug' => 'qc-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function task(int $ws, string $engine, string $action, int $cost, string $status = 'pending', ?string $createdAt = null): int
    {
        $id = (int) DB::table('tasks')->insertGetId(['workspace_id' => $ws, 'engine' => $engine, 'action' => $action, 'status' => $status, 'requires_approval' => 1, 'approval_status' => $status === 'pending' ? 'pending' : 'approved', 'credit_cost' => $cost, 'payload_json' => '{}', 'created_at' => $createdAt ?? now(), 'updated_at' => now()]);
        DB::table('approvals')->insert(['workspace_id' => $ws, 'task_id' => $id, 'status' => $status === 'pending' ? 'pending' : 'approved', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_intent_detection(): void
    {
        $this->assertTrue(QueueCancellation::asks('Cancel everything in the queue'));
        $this->assertTrue(QueueCancellation::asks('delete those pending tasks please'));
        $this->assertTrue(QueueCancellation::asks('clear the waiting audits'));
        $this->assertFalse(QueueCancellation::asks('How many tasks are pending?'));
        $this->assertFalse(QueueCancellation::asks('cancel my meeting with the supplier'));
        $this->assertTrue(QueueCancellation::confirms('Yes, cancel them'));
        $this->assertTrue(QueueCancellation::declines('No, leave them'));
        $this->assertFalse(QueueCancellation::confirms('How much would that cost?'));
    }

    public function test_describe_then_yes_cancels_only_the_scope_and_leaves_running_work(): void
    {
        $ws = $this->ws(); $other = $this->ws();
        $a1 = $this->task($ws, 'seo', 'deep_audit', 3, 'pending', now()->subDays(20)->toDateTimeString());
        $a2 = $this->task($ws, 'seo', 'deep_audit', 3);
        $w1 = $this->task($ws, 'write', 'write_article', 2);
        $done = $this->task($ws, 'write', 'write_article', 2, 'completed');
        $foreign = $this->task($other, 'seo', 'deep_audit', 3);

        $qc = app(QueueCancellation::class);
        $scope = $qc->scope($ws, 'cancel the waiting audits');
        $this->assertEqualsCanonicalizing([$a1, $a2], $scope['tasks']->pluck('id')->map(fn ($i) => (int) $i)->all());
        $text = $qc->describe($scope);
        $this->assertStringContainsString('2 site audits', $text);
        $this->assertStringContainsString('cannot be undone', $text);
        $this->assertStringContainsString('6 credits', $text);
        $this->assertStringContainsString('Say **yes**', $text);

        $qc->remember($ws, $scope, 'cancel the waiting audits');
        $this->assertNotNull($qc->pending($ws));
        $res = $qc->execute($ws, $qc->pending($ws)['ids'], null);
        $this->assertSame(2, $res['cancelled']);
        $this->assertSame('cancelled', DB::table('tasks')->where('id', $a1)->value('status'));
        $this->assertSame('rejected', DB::table('approvals')->where('task_id', $a2)->value('status'));
        $this->assertSame('pending', DB::table('tasks')->where('id', $w1)->value('status'), 'articles were outside the scope');
        $this->assertSame('completed', DB::table('tasks')->where('id', $done)->value('status'));
        $this->assertSame('pending', DB::table('tasks')->where('id', $foreign)->value('status'), 'another workspace is never touched');
        $this->assertStringContainsString("cancelled 2 items", $qc->report($res));

        // age filter and "everything"
        $w2 = $this->task($ws, 'write', 'write_article', 2, 'pending', now()->subDays(30)->toDateTimeString());
        $old = $qc->scope($ws, 'delete the pending tasks older than two weeks');
        $this->assertSame([$w2], $old['tasks']->pluck('id')->map(fn ($i) => (int) $i)->all());
        $all = $qc->scope($ws, 'clear everything waiting');
        $this->assertEqualsCanonicalizing([$w1, $w2], $all['tasks']->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertStringContainsString("there's nothing to cancel", $qc->describe($qc->scope($ws, 'cancel the waiting leads')));
        Cache::forget(QueueCancellation::key($ws));
    }
}
