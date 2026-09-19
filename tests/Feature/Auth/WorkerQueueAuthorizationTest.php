<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\RefreshTokenService;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A1 (REPORT-0061, 2026-09-19) — the worker queue is platform diagnostics.
 *
 * GET /api/system/queue answers platform administrators only (is_platform_admin — the canonical gate, AdminMiddleware);
 * a customer is refused with 403 whether or not the menu shows the page. A customer keeps their own task visibility
 * (GET /api/tasks, workspace-scoped) and can only retry a task in their own workspace, and only a BLOCKED one.
 */
class WorkerQueueAuthorizationTest extends TestCase
{
    private function actor(bool $admin = false): array
    {
        $suffix = Str::random(10);
        $user = User::create(['name' => 'WQ ' . ($admin ? 'Admin' : 'Customer'), 'email' => "wq-{$suffix}@test.invalid", 'password' => bcrypt(Str::random(16))]);
        if ($admin) { DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => 1]); $user->refresh(); }
        $wsId = (int) DB::table('workspaces')->insertGetId(['name' => 'WQ ws ' . $suffix, 'slug' => 'wq-' . strtolower($suffix), 'business_name' => 'WQ ' . $suffix, 'created_by' => $user->id, 'onboarded' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ws = Workspace::find($wsId);
        DB::table('workspace_users')->insert(['workspace_id' => $ws->id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $token = app(RefreshTokenService::class)->issueAccessToken($user, $ws, null, null);
        return [$user, $ws, ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
    }

    public function test_a_customer_is_refused_the_worker_queue_and_an_administrator_is_not(): void
    {
        [, , $customer] = $this->actor(false);
        $this->getJson('/api/system/queue', $customer)->assertStatus(403);

        [, , $admin] = $this->actor(true);
        $this->getJson('/api/system/queue', $admin)->assertStatus(200);
    }

    public function test_a_customer_keeps_their_own_tasks_and_cannot_touch_another_workspaces_task(): void
    {
        [$userA, $wsA, $a] = $this->actor(false);
        [$userB, $wsB, $b] = $this->actor(false);
        $taskB = Task::create(['workspace_id' => $wsB->id, 'engine' => 'builder', 'action' => 'ask_arthur', 'status' => 'blocked', 'payload_json' => '{}', 'source' => 'manual']);
        $taskA = Task::create(['workspace_id' => $wsA->id, 'engine' => 'builder', 'action' => 'ask_arthur', 'status' => 'failed', 'payload_json' => '{}', 'source' => 'manual']);

        // A sees only A's work
        $list = $this->getJson('/api/tasks?limit=50', $a)->assertStatus(200)->json();
        $ids = array_map(fn ($t) => (int) ($t['id'] ?? 0), (array) ($list['tasks'] ?? $list['data'] ?? $list));
        $this->assertContains($taskA->id, $ids);
        $this->assertNotContains($taskB->id, $ids);

        // A cannot retry B's task; A's own failed (declined) task is not retried either — retry applies to blocked only
        $this->postJson('/api/tasks/' . $taskB->id . '/retry', [], $a)->assertStatus(404);
        $own = $this->postJson('/api/tasks/' . $taskA->id . '/retry', [], $a)->assertStatus(200)->json();
        $this->assertSame('not_blocked', $own['result']['action'] ?? null);
        $this->assertSame('failed', Task::find($taskA->id)->status);
    }
}
