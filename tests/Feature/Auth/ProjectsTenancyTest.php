<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\RefreshTokenService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RISK-0193 (2026-09-19) — the Projects route family resolved every customer to workspace 1 (the house account)
 * because `users` has no workspace_id column. The workspace is now the trusted request attribute set by
 * JwtAuthMiddleware from the signed token (membership re-checked per request); missing context fails closed.
 *
 * Proves: two ordinary customers see and change only their own records; neither can read or modify the house
 * workspace; the house account itself (a member of workspace 1) keeps its own projects; a platform administrator
 * is scoped to the workspace their token names like anyone else (no admin override is intended on these routes);
 * missing and spoofed workspace context is refused or ignored.
 */
class ProjectsTenancyTest extends TestCase
{
    private const HOUSE_WS = 1;

    /** @return array{0: User, 1: Workspace, 2: array} user, workspace, auth headers (token bound to that workspace) */
    private function customer(bool $admin = false, ?int $wsId = null): array
    {
        $suffix = Str::random(10);
        $user = User::create(['name' => 'PT ' . $suffix, 'email' => "pt-{$suffix}@test.invalid", 'password' => bcrypt(Str::random(16))]);
        if ($admin) { DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => 1]); $user->refresh(); }
        $ws = $wsId ? Workspace::find($wsId) : Workspace::find($this->workspace($suffix, $user->id));
        DB::table('workspace_users')->insert(['workspace_id' => $ws->id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        return [$user, $ws, $this->headers($user, $ws)];
    }

    private function workspace(string $suffix, int $createdBy, ?int $id = null): int
    {
        $row = ['name' => 'PT ws ' . $suffix, 'slug' => 'pt-' . strtolower($suffix), 'business_name' => 'PT ' . $suffix, 'created_by' => $createdBy, 'onboarded' => 1, 'created_at' => now(), 'updated_at' => now()];
        if ($id) { $row['id'] = $id; DB::table('workspaces')->insert($row); return $id; }
        return (int) DB::table('workspaces')->insertGetId($row);
    }

    private function headers(User $user, ?Workspace $ws): array
    {
        return ['Authorization' => 'Bearer ' . app(RefreshTokenService::class)->issueAccessToken($user, $ws, null, null), 'Accept' => 'application/json'];
    }

    private function house(): array
    {
        if (! DB::table('workspaces')->where('id', self::HOUSE_WS)->exists()) {
            $seed = User::create(['name' => 'PT house seed', 'email' => 'pt-house-' . Str::random(6) . '@test.invalid', 'password' => bcrypt(Str::random(16))]);
            $this->workspace('house-' . Str::random(4), $seed->id, self::HOUSE_WS);
        }
        [$user, $ws, $h] = $this->customer(false, self::HOUSE_WS);
        $pid = (int) DB::table('projects')->insertGetId(['workspace_id' => self::HOUSE_WS, 'name' => 'House plan', 'goal' => 'house', 'status' => 'active', 'source_type' => 'direct', 'created_at' => now(), 'updated_at' => now()]);
        return [$user, $ws, $h, $pid];
    }

    private function createProject(array $headers, string $name, array $extra = []): int
    {
        $res = $this->postJson('/api/projects', $extra + ['name' => $name, 'goal' => 'goal ' . $name], $headers)->assertStatus(201)->json();
        $this->assertTrue((bool) ($res['success'] ?? false), json_encode($res));
        return (int) ($res['project']['id'] ?? $res['data']['id'] ?? $res['id'] ?? 0);
    }

    private function listIds(array $headers): array
    {
        $res = $this->getJson('/api/projects', $headers)->assertStatus(200)->json();
        return array_map(fn ($p) => (int) $p['id'], (array) ($res['data'] ?? $res['projects'] ?? []));
    }

    public function test_two_customers_see_and_change_only_their_own_projects(): void
    {
        [, $wsA, $a] = $this->customer();
        [, $wsB, $b] = $this->customer();

        $pa = $this->createProject($a, 'A plan');
        $pb = $this->createProject($b, 'B plan');
        $this->assertGreaterThan(0, $pa);
        $this->assertSame($wsA->id, (int) DB::table('projects')->where('id', $pa)->value('workspace_id'), 'A\'s project must land in A\'s workspace');
        $this->assertSame($wsB->id, (int) DB::table('projects')->where('id', $pb)->value('workspace_id'));

        // lists are disjoint
        $this->assertSame([$pa], $this->listIds($a));
        $this->assertSame([$pb], $this->listIds($b));

        // B's nested records exist; A's reads of B's project never return them
        $this->postJson("/api/projects/{$pb}/milestones", ['title' => 'B secret milestone'], $b)->assertStatus(201);
        $this->postJson("/api/projects/{$pb}/kpis", ['name' => 'B secret kpi', 'target_value' => 10], $b)->assertStatus(201);
        foreach (['', '/timeline', '/outcome', '/publish-queue'] as $sub) {
            $this->assertNotSame(200, $this->getJson("/api/projects/{$pb}{$sub}", $a)->status(), "A read B's project{$sub}");
        }
        foreach (['/milestones', '/kpis'] as $sub) {
            // the list services are workspace-scoped: a foreign project id yields nothing, never B's rows
            $this->assertSame([], (array) ($this->getJson("/api/projects/{$pb}{$sub}", $a)->json('data') ?? []), "A read B's {$sub}");
            $this->assertNotEmpty($this->getJson("/api/projects/{$pb}{$sub}", $b)->assertStatus(200)->json('data'));
        }
        $this->assertStringNotContainsString('B secret', $this->getJson("/api/projects/{$pb}/milestones", $a)->getContent() . $this->getJson("/api/projects/{$pb}/kpis", $a)->getContent());
        foreach (['', '/milestones', '/kpis', '/timeline', '/publish-queue'] as $sub) {
            $this->getJson("/api/projects/{$pa}{$sub}", $a)->assertStatus(200);
        }

        // every write to the other's project fails and leaves it untouched
        $this->assertNotSame(200, $this->patchJson("/api/projects/{$pb}", ['name' => 'hijacked'], $a)->status());
        $this->assertNotSame(201, $this->postJson("/api/projects/{$pb}/milestones", ['title' => 'hijack ms'], $a)->status());
        $this->assertNotSame(201, $this->postJson("/api/projects/{$pb}/kpis", ['name' => 'hijack kpi', 'target_value' => 1], $a)->status());
        $this->assertNotSame(200, $this->postJson("/api/projects/{$pb}/dispose", ['outcome' => 'abandoned'], $a)->status());
        $this->assertNotSame(200, $this->postJson("/api/projects/{$pb}/persist-proposal", [], $a)->status());
        $row = DB::table('projects')->where('id', $pb)->first();
        $this->assertSame('B plan', $row->name);
        $this->assertSame($wsB->id, (int) $row->workspace_id);
        $this->assertSame(1, DB::table('project_milestones')->where('project_id', $pb)->count());
        $this->assertSame(1, DB::table('project_kpis')->where('project_id', $pb)->count());
        $this->assertSame(0, DB::table('project_milestones')->where('project_id', $pb)->where('workspace_id', $wsA->id)->count());

        // A's own nested writes still work and stay in A's workspace
        $ms = $this->postJson("/api/projects/{$pa}/milestones", ['title' => 'A ms'], $a)->assertStatus(201)->json();
        $this->assertSame($wsA->id, (int) DB::table('project_milestones')->where('project_id', $pa)->value('workspace_id'));
        $this->patchJson("/api/projects/{$pa}", ['name' => 'A plan v2'], $a)->assertStatus(200);
        $this->assertSame('A plan v2', DB::table('projects')->where('id', $pa)->value('name'));
    }

    public function test_the_house_workspace_is_invisible_to_customers_and_intact_for_the_house_account(): void
    {
        [, , $houseHeaders, $housePid] = $this->house();
        [, , $a] = $this->customer();
        [, , $admin] = $this->customer(true);

        // a customer never sees the house project — this is the RISK-0193 reproduction, now refused
        $this->assertNotContains($housePid, $this->listIds($a));
        $this->getJson("/api/projects/{$housePid}", $a)->assertStatus(404);
        DB::table('project_milestones')->insert(['workspace_id' => self::HOUSE_WS, 'project_id' => $housePid, 'title' => 'House secret milestone', 'status' => 'pending', 'order_index' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], (array) ($this->getJson("/api/projects/{$housePid}/milestones", $a)->json('data') ?? []));
        $this->assertSame([], (array) ($this->getJson("/api/projects/{$housePid}/kpis", $a)->json('data') ?? []));
        $this->assertNotSame(200, $this->getJson("/api/projects/{$housePid}/timeline", $a)->status());
        $this->assertNotSame(200, $this->getJson("/api/projects/{$housePid}/outcome", $a)->status());
        $this->assertNotSame(200, $this->patchJson("/api/projects/{$housePid}", ['name' => 'hijacked'], $a)->status());
        $this->assertNotSame(201, $this->postJson("/api/projects/{$housePid}/milestones", ['title' => 'x'], $a)->status());
        $this->assertNotSame(200, $this->postJson("/api/projects/{$housePid}/dispose", ['outcome' => 'abandoned'], $a)->status());
        $this->assertSame('House plan', DB::table('projects')->where('id', $housePid)->value('name'));
        $this->assertSame('active', DB::table('projects')->where('id', $housePid)->value('status'));

        // a customer's new project never lands in the house workspace
        $pa = $this->createProject($a, 'A plan');
        $this->assertNotSame(self::HOUSE_WS, (int) DB::table('projects')->where('id', $pa)->value('workspace_id'));

        // the house account (a member of workspace 1, token bound to it) keeps its own projects
        $this->assertContains($housePid, $this->listIds($houseHeaders));
        $this->getJson("/api/projects/{$housePid}", $houseHeaders)->assertStatus(200);

        // a platform administrator is scoped to the workspace their token names — no admin override on these routes
        $this->assertNotContains($housePid, $this->listIds($admin));
        $this->assertNotContains($pa, $this->listIds($admin));
        $this->getJson("/api/projects/{$housePid}", $admin)->assertStatus(404);
        $this->getJson('/api/system/queue', $admin)->assertStatus(200);   // admin diagnostics unaffected (A1)
    }

    public function test_missing_workspace_context_fails_closed(): void
    {
        // no token at all
        $this->getJson('/api/projects', ['Accept' => 'application/json'])->assertStatus(401);
        $this->postJson('/api/projects', ['name' => 'x', 'goal' => 'y'], ['Accept' => 'application/json'])->assertStatus(401);

        // a token with no workspace claim for a user with two memberships: never guessed
        [$user, $wsA] = $this->customer();
        $wsB = Workspace::find($this->workspace(Str::random(8), $user->id));
        DB::table('workspace_users')->insert(['workspace_id' => $wsB->id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $noWs = $this->headers($user, null);
        $this->getJson('/api/projects', $noWs)->assertStatus(401)->assertJsonPath('code', 'workspace_unresolved');
        $this->postJson('/api/projects', ['name' => 'x', 'goal' => 'y'], $noWs)->assertStatus(401);

        // a token with no workspace claim for a user with no memberships
        $orphan = User::create(['name' => 'PT orphan', 'email' => 'pt-orphan-' . Str::random(6) . '@test.invalid', 'password' => bcrypt(Str::random(16))]);
        $this->getJson('/api/projects', $this->headers($orphan, null))->assertStatus(401);

        // a token naming a workspace the user is not (or no longer) a member of
        DB::table('workspace_users')->where('user_id', $user->id)->where('workspace_id', $wsB->id)->delete();
        $this->getJson('/api/projects', $this->headers($user, $wsB))->assertStatus(403)->assertJsonPath('code', 'workspace_access_revoked');
        $this->postJson('/api/projects', ['name' => 'x', 'goal' => 'y'], $this->headers($user, $wsB))->assertStatus(403);
        $this->assertSame(0, DB::table('projects')->where('workspace_id', $wsB->id)->count());
    }

    public function test_spoofed_workspace_context_is_ignored(): void
    {
        [, $wsA, $a] = $this->customer();
        [, $wsB, $b] = $this->customer();
        $pb = $this->createProject($b, 'B plan');

        $spoof = $a + ['X-Workspace-Id' => (string) $wsB->id, 'X-Workspace-ID' => (string) $wsB->id];

        // header + body + query naming B's workspace: the project still lands in A's workspace
        $pa = $this->createProject($spoof, 'A spoof', ['workspace_id' => $wsB->id, 'ws' => $wsB->id]);
        $this->assertSame($wsA->id, (int) DB::table('projects')->where('id', $pa)->value('workspace_id'));
        $this->assertSame(0, DB::table('projects')->where('workspace_id', $wsB->id)->where('name', 'A spoof')->count());

        // reads with the spoofed header stay A's
        $res = $this->getJson('/api/projects?workspace_id=' . $wsB->id, $spoof)->assertStatus(200)->json();
        $ids = array_map(fn ($p) => (int) $p['id'], (array) ($res['data'] ?? []));
        $this->assertNotContains($pb, $ids);
        $this->assertContains($pa, $ids);
        $this->getJson("/api/projects/{$pb}", $spoof)->assertStatus(404);
        $this->assertNotSame(200, $this->patchJson("/api/projects/{$pb}", ['name' => 'hijacked', 'workspace_id' => $wsB->id], $spoof)->status());
        $this->assertSame('B plan', DB::table('projects')->where('id', $pb)->value('name'));
    }
}
