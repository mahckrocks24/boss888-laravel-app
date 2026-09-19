<?php

namespace Tests\Feature\Metrics;

use App\Core\Auth\RefreshTokenService;
use App\Core\Metrics\WorkspaceMetrics;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A2 (REPORT-0061, Owner Decision 2, 2026-09-19) — one definition of every task and agent count.
 *
 * A workspace with one task in each state (done, QA-rejected, pending, running, blocked, declined, failed) and a
 * second workspace with its own work: the service, the Command Center (/dashboard/overview), the Agents page
 * (/agents/dashboard), the Workspace canvas (/workspace/state) and the task board (/projects/tasks) must all
 * report the same numbers under the same definitions, and never the other workspace's.
 */
class WorkspaceMetricsTest extends TestCase
{
    private function workspace(): array
    {
        $suffix = Str::random(10);
        $user = User::create(['name' => 'WM ' . $suffix, 'email' => "wm-{$suffix}@test.invalid", 'password' => bcrypt(Str::random(16))]);
        $wsId = (int) DB::table('workspaces')->insertGetId(['name' => 'WM ws ' . $suffix, 'slug' => 'wm-' . strtolower($suffix), 'business_name' => 'WM ' . $suffix, 'created_by' => $user->id, 'onboarded' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $wsId, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        // the test database carries no agent roster — seed the four this test names (Sarah is the DMM)
        foreach (['sarah' => ['Sarah', 'dmm', 1], 'priya' => ['Priya', 'content', 0], 'james' => ['James', 'seo', 0], 'alex' => ['Alex', 'social', 0]] as $slug => [$name, $cat, $dmm]) {
            if (! DB::table('agents')->where('slug', $slug)->exists()) {
                DB::table('agents')->insert(['slug' => $slug, 'name' => $name, 'title' => $name, 'category' => $cat, 'is_dmm' => $dmm, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $sarah = DB::table('agents')->where('slug', 'sarah')->value('id');
        DB::table('workspace_agents')->insert(['workspace_id' => $wsId, 'agent_id' => $sarah, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $token = app(RefreshTokenService::class)->issueAccessToken($user, Workspace::find($wsId), null, null);
        return [$wsId, ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
    }

    private function task(int $wsId, array $o): int
    {
        return (int) DB::table('tasks')->insertGetId($o + [
            'workspace_id' => $wsId, 'engine' => 'write', 'action' => 'write_article', 'category' => 'create', 'status' => 'pending', 'source' => 'agent',
            'assigned_agents_json' => json_encode(['priya']), 'payload_json' => json_encode(['created_via' => 'sarah_proposal']), 'credit_cost' => 0,
            'created_at' => now()->subHour(), 'updated_at' => now()->subMinutes(5),
        ]);
    }

    private function seedStates(int $wsId): array
    {
        return [
            'done'      => $this->task($wsId, ['status' => 'completed', 'completed_at' => now()->subMinutes(10), 'qa_status' => 'accepted']),
            'qa_reject' => $this->task($wsId, ['status' => 'completed', 'completed_at' => now()->subMinutes(10), 'qa_status' => 'rejected']),
            'pending'   => $this->task($wsId, ['status' => 'pending']),
            'running'   => $this->task($wsId, ['status' => 'running', 'assigned_agents_json' => json_encode(['james']), 'engine' => 'seo', 'action' => 'link_suggestions']),
            'blocked'   => $this->task($wsId, ['status' => 'blocked', 'assigned_agents_json' => json_encode(['james']), 'engine' => 'seo', 'action' => 'link_suggestions']),
            'declined'  => $this->task($wsId, ['status' => 'failed', 'approval_status' => 'rejected', 'engine' => 'builder', 'action' => 'ask_arthur', 'assigned_agents_json' => json_encode(['sarah']), 'payload_json' => json_encode([])]),
            'failed'    => $this->task($wsId, ['status' => 'failed', 'engine' => 'builder', 'action' => 'ask_arthur', 'assigned_agents_json' => json_encode(['sarah']), 'payload_json' => json_encode([])]),
        ];
    }

    public function test_the_definitions_hold_and_every_surface_reads_them(): void
    {
        [$wsA, $a] = $this->workspace();
        [$wsB, $b] = $this->workspace();
        $this->seedStates($wsA);
        $this->task($wsB, ['status' => 'completed', 'completed_at' => now(), 'qa_status' => 'accepted']);
        $this->task($wsB, ['status' => 'completed', 'completed_at' => now(), 'qa_status' => 'accepted']);

        $m = app(WorkspaceMetrics::class);
        $c = $m->taskCounts($wsA);
        $this->assertSame(1, $c['tasks_done'], 'done excludes the QA-rejected task');
        $this->assertSame(1, $c['tasks_qa_rejected']);
        $this->assertSame(1, $c['tasks_pending']);
        $this->assertSame(1, $c['tasks_running']);
        $this->assertSame(1, $c['tasks_blocked']);
        $this->assertSame(1, $c['tasks_declined']);
        $this->assertSame(1, $c['tasks_failed'], 'a declined task is not a failure');
        $this->assertSame(7, $c['tasks_total']);
        $this->assertSame(1, $c['tasks_done_today']);
        $this->assertSame(2, $m->taskCounts($wsB)['tasks_done']);

        $ag = $m->agentCounts($wsA);
        $this->assertSame(1, $ag['agents_enabled']);
        $this->assertGreaterThanOrEqual(3, $ag['agents_active_30d']);   // sarah, priya, james, arthur
        $this->assertContains('arthur', $m->rosterSlugs());

        $bk = $m->agentBuckets($wsA);
        // Priya: assigned to the done, QA-rejected and pending tasks
        $this->assertSame([1, 1, 1, 0, 0, 0, 0], [$bk['priya']['completed'], $bk['priya']['qa_rejected'], $bk['priya']['upcoming'], $bk['priya']['ongoing'], $bk['priya']['blocked'], $bk['priya']['declined'], $bk['priya']['failed']]);
        // James: running + blocked
        $this->assertSame([1, 1], [$bk['james']['ongoing'], $bk['james']['blocked']]);
        // Sarah: everything she originated (5 sarah_proposal tasks) plus the two builder tasks assigned to her
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], [$bk['sarah']['completed'], $bk['sarah']['qa_rejected'], $bk['sarah']['upcoming'], $bk['sarah']['ongoing'], $bk['sarah']['blocked'], $bk['sarah']['declined'], $bk['sarah']['failed']]);
        // Arthur: the builder-engine tasks — one declined, one failed; success rate 0/(0+1)
        $this->assertSame([1, 1, 0], [$bk['arthur']['declined'], $bk['arthur']['failed'], $bk['arthur']['completed']]);
        $this->assertSame(0, $bk['arthur']['success_rate']);
        $this->assertSame(50, $bk['sarah']['success_rate'], 'completed / (completed + failed); declined and QA-rejected excluded');
        // an agent with no work is present with zeros, never missing
        $this->assertSame(0, $bk['alex']['total'] ?? 0);

        // ── the surfaces ───────────────────────────────────────────────────────────────────────────────────────
        $dash = $this->getJson('/api/dashboard/overview', $a)->assertStatus(200)->json('stats');
        $this->assertSame(1, $dash['tasks_completed']);
        $this->assertSame(1, $dash['tasks_qa_rejected']);
        $this->assertSame(1, $dash['tasks_declined']);
        $this->assertSame(1, $dash['tasks_pending']);
        $this->assertSame(1, $dash['tasks_blocked']);
        $this->assertSame(1, $dash['agents_enabled']);
        $this->assertSame(count($m->rosterSlugs()), $dash['agents_roster']);

        $agents = $this->getJson('/api/agents/dashboard', $a)->assertStatus(200)->json();
        $rows = collect($agents['agents'])->keyBy('agent_id');
        $this->assertSame(1, $rows['sarah']['completed']);
        $this->assertSame(1, $rows['sarah']['declined']);
        $this->assertSame(1, $rows['sarah']['pending']);
        $this->assertTrue($rows['sarah']['enabled']);
        $this->assertSame(1, $rows['priya']['completed']);
        $this->assertSame(1, $rows['priya']['qa_rejected']);
        $this->assertSame(1, $rows['james']['blocked']);
        $this->assertSame(1, $rows['arthur']['declined'], 'Arthur is on the roster with the builder work');
        $this->assertSame(1, $agents['stats']['total_completed']);
        $this->assertSame(1, $agents['stats']['agents_enabled']);

        $state = $this->getJson('/api/workspace/state', $a)->assertStatus(200)->json();
        $sarah = collect($state['agents'])->firstWhere('slug', 'sarah');
        $this->assertSame(['ongoing' => 1, 'upcoming' => 1, 'completed' => 1, 'blocked' => 1], array_intersect_key($sarah['stats'], ['ongoing' => 1, 'upcoming' => 1, 'completed' => 1, 'blocked' => 1]));

        $board = collect($this->getJson('/api/projects/tasks', $a)->assertStatus(200)->json('tasks'));
        $this->assertSame(1, $board->where('status', 'completed')->count(), 'board Completed = tasks_done');
        $this->assertSame(1, $board->where('status', 'review')->count(), 'the QA-rejected task sits in Review');
        $this->assertSame(1, $board->where('declined', true)->count());
        $this->assertSame(7, $board->count());

        // ── isolation: B's numbers are B's ─────────────────────────────────────────────────────────────────────
        $this->assertSame(2, $this->getJson('/api/dashboard/overview', $b)->json('stats.tasks_completed'));
        $this->assertSame(2, $this->getJson('/api/agents/dashboard', $b)->json('stats.total_completed'));
        $this->assertSame(2, count($this->getJson('/api/projects/tasks', $b)->json('tasks')));
        $this->assertSame(0, $this->getJson('/api/dashboard/overview', $b)->json('stats.tasks_declined'));
    }
}
