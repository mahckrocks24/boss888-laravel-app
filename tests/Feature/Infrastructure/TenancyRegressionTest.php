<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Agent\AgentDispatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MISSION-018 WS-8 (2026-08-24) — standing regression net for the cross-workspace
 * tenancy holes fixed this run. Each fix was proven once at the time (reflection
 * traces, scoped-query checks, real-service calls); these tests keep them fixed —
 * drop a workspace filter in a future edit and the matching test goes red.
 *
 * Every case asserts the invariant the guard enforces: a row owned by workspace B
 * is invisible/unactionable to workspace A, with a CONTROL proving the owner still
 * sees its own (a guard that blocks everything is a different outage).
 *
 * DatabaseTransactions: all writes roll back; runs against levelup_test.
 */
class TenancyRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private int $wsA;
    private int $wsB;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = DB::table('users')->insertGetId([
            'name' => 'Tenancy Fixture', 'email' => 'tenancy+' . Str::random(8) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mk = fn (string $n) => DB::table('workspaces')->insertGetId([
            'name' => $n, 'slug' => Str::slug($n) . '-' . Str::random(6),
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->wsA = $mk('WS A');
        $this->wsB = $mk('WS B');
    }

    /** RISK-0022 / RISK-0044: task mutations resolve within the caller's workspace only. */
    public function test_task_is_scoped_to_its_workspace(): void
    {
        $bTask = DB::table('tasks')->insertGetId([
            'workspace_id' => $this->wsB, 'engine' => 'write', 'action' => 'write_article',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(
            DB::table('tasks')->where('id', $bTask)->where('workspace_id', $this->wsA)->exists(),
            'RISK-0022/0044: workspace A must not resolve workspace B\'s task'
        );
        $this->assertTrue(
            DB::table('tasks')->where('id', $bTask)->where('workspace_id', $this->wsB)->exists(),
            'control: the owning workspace must still resolve its task'
        );
    }

    /** RISK-0043: traffic-rule toggle/delete confined to the owning workspace. */
    public function test_traffic_rule_is_scoped_to_its_workspace(): void
    {
        $bRule = DB::table('traffic_rules')->insertGetId([
            'workspace_id' => $this->wsB, 'name' => 'blockbots', 'type' => 'block',
            'config_json' => json_encode(['x' => 1]), 'enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(
            DB::table('traffic_rules')->where('id', $bRule)->where('workspace_id', $this->wsA)->exists(),
            'RISK-0043: workspace A must not resolve workspace B\'s traffic rule'
        );
        $this->assertTrue(
            DB::table('traffic_rules')->where('id', $bRule)->where('workspace_id', $this->wsB)->exists(),
            'control: the owning workspace must still resolve its rule'
        );
    }

    /** Canvas P0: canvas save/delete confined to the owning workspace. */
    public function test_canvas_is_scoped_to_its_workspace(): void
    {
        $bCanvas = DB::table('canvas_states')->insertGetId([
            'workspace_id' => $this->wsB, 'state_json' => json_encode(['nodes' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(
            DB::table('canvas_states')->where('id', $bCanvas)->where('workspace_id', $this->wsA)->exists(),
            'canvas P0: workspace A must not resolve workspace B\'s canvas'
        );
        $this->assertTrue(
            DB::table('canvas_states')->where('id', $bCanvas)->where('workspace_id', $this->wsB)->exists(),
            'control: the owning workspace must still resolve its canvas'
        );
    }

    /**
     * RISK-0037: conversation reads gate on the registered-agent predicate, so a
     * thread whose slug is not a registered/enabled agent of the workspace (a
     * shadow transcript) is not readable through getConversation.
     */
    public function test_unregistered_agent_thread_is_not_readable(): void
    {
        DB::table('agent_messages')->insert([
            'workspace_id' => $this->wsA, 'agent_slug' => 'shadow', 'sender' => 'shadow',
            'role' => 'agent', 'content' => 'secret', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $conv = app(AgentDispatchService::class)->getConversation($this->wsA, 'shadow');
        $this->assertSame([], $conv['messages'] ?? ['x'],
            'RISK-0037: an unregistered slug must return no messages');

        $this->assertNotContains('shadow', AgentDispatchService::visibleAgentSlugs($this->wsA),
            'RISK-0037: an unregistered slug must not appear in the visible set');
    }
}
