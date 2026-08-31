<?php

namespace Tests\Feature\Security;

use App\Services\QueueControlService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SEC-2 (2026-08-31). `GET /api/system/queue` is authenticated but NOT admin, and its metrics counted tasks with
 *  no workspace filter — every signed-in customer saw the whole platform's volume. Chef Red's Worker Queue read
 *  "431 Pending" against his own 140.
 *
 *  Operator surfaces (CLI report, system health, validation report) legitimately want the platform view, so the
 *  unscoped call must keep working — both halves are asserted here. */
class QueueMetricsScopeTest extends TestCase
{
    private function wsWithTasks(int $pending): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'qm-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'QM', 'slug' => 'qm-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < $pending; $i++) {
            DB::table('tasks')->insert([
                'workspace_id' => $ws, 'engine' => 'write', 'action' => 'write_article', 'status' => 'pending',
                'requires_approval' => 0, 'credit_cost' => 0, 'payload_json' => json_encode([]), 'source' => 'agent',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $ws;
    }

    public function test_a_customer_sees_only_their_own_queue(): void
    {
        $mine = $this->wsWithTasks(3);
        $theirs = $this->wsWithTasks(5);

        $scoped = app(QueueControlService::class)->getMetrics($mine);

        $this->assertSame(3, $scoped['pending'], 'another workspace\'s pending work must not be counted as mine');
        $this->assertSame(0, $scoped['running']);
        $this->assertSame(0, $scoped['stale'], 'stale is scoped too, not just the simple counts');
    }

    public function test_the_operator_view_still_sees_the_whole_platform(): void
    {
        $a = $this->wsWithTasks(3);
        $b = $this->wsWithTasks(5);

        $global = app(QueueControlService::class)->getMetrics();
        $scoped = app(QueueControlService::class)->getMetrics($a);

        $this->assertGreaterThanOrEqual(8, $global['pending'], 'the unscoped call is the operator view and must keep it');
        $this->assertGreaterThan($scoped['pending'], $global['pending']);
    }
}
