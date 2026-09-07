<?php

namespace Tests\Feature\Security;

use App\Core\Auth\RefreshTokenService;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LAUNCH-P0-1 (2026-09-07, DEC-0039 / EV-0916). GET /api/tasks/{id}, /tasks/{id}/status, /tasks/{id}/events,
 *  GET /api/projects/tasks/{id} and POST /api/projects/tasks/{id}/note resolved a task by bare id, so any
 *  authenticated customer could read (or annotate) any workspace's task. Proven live against a QA tenant during
 *  the launch pass. Foreign ids must answer 404 exactly like nonexistent ones; own tasks stay readable. */
class TaskReadIsWorkspaceScopedTest extends TestCase
{
    /** @return array{0:int,1:int,2:string} user id, workspace id, access token */
    private function tenant(): array
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'p0-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'P0', 'slug' => 'p0-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insertOrIgnore(['user_id' => $u, 'workspace_id' => $ws, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $token = app(RefreshTokenService::class)->issueTokenPair(User::find($u), Workspace::find($ws))['access_token'];
        return [$u, $ws, $token];
    }

    private function task(int $ws, string $title): Task
    {
        return Task::create(['workspace_id' => $ws, 'engine' => 'seo', 'action' => 'fix_orphans', 'status' => 'completed', 'requires_approval' => 0, 'approval_status' => null, 'source' => 'agent', 'payload_json' => ['title' => $title]]);
    }

    public function test_task_reads_are_confined_to_the_callers_workspace(): void
    {
        [, $mine, $tok] = $this->tenant();
        [, $theirs] = $this->tenant();
        $own = $this->task($mine, 'mine');
        $foreign = $this->task($theirs, 'THEIR SECRET TITLE');
        $h = ['Authorization' => 'Bearer ' . $tok, 'Accept' => 'application/json'];

        foreach (["/api/tasks/{$foreign->id}", "/api/tasks/{$foreign->id}/status", "/api/tasks/{$foreign->id}/events", "/api/projects/tasks/{$foreign->id}"] as $url) {
            $r = $this->withHeaders($h)->getJson($url);
            $this->assertSame(404, $r->getStatusCode(), "$url must 404 for a foreign task (got {$r->getStatusCode()})");
            $this->assertStringNotContainsString('THEIR SECRET TITLE', $r->getContent(), "$url leaked foreign content");
        }
        $r = $this->withHeaders($h)->postJson("/api/projects/tasks/{$foreign->id}/note", ['note' => 'x', 'content' => 'x']);
        $this->assertSame(404, $r->getStatusCode(), 'note-write on a foreign task must 404');

        $this->withHeaders($h)->getJson("/api/tasks/{$own->id}")->assertStatus(200)->assertJsonPath('task.id', $own->id);
        $this->withHeaders($h)->getJson("/api/tasks/{$own->id}/status")->assertStatus(200);
        $this->withHeaders($h)->getJson("/api/tasks/{$own->id}/events")->assertStatus(200);
        $this->withHeaders($h)->getJson("/api/projects/tasks/{$own->id}")->assertStatus(200)->assertJsonPath('id', $own->id);
        $this->withHeaders($h)->getJson('/api/tasks/999999999')->assertStatus(404);
    }
}
