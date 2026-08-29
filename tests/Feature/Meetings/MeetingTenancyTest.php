<?php

namespace Tests\Feature\Meetings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MEET-1 (2026-08-29) — id-addressed meeting routes are tenant-scoped (the SPA aliases in agents-04 and the
 * canonical /sarah/meeting/* routes in agents-03), and the alias /meeting/start is priced like the canonical one.
 */
class MeetingTenancyTest extends TestCase
{
    private const WS_A = 999999910;
    private const WS_B = 999999911;
    private int $uidA = 0;
    private int $meetingB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uidA = (int) DB::table('users')->insertGetId(['name' => 'Meet A', 'email' => 'meet-a@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $uidB = (int) DB::table('users')->insertGetId(['name' => 'Meet B', 'email' => 'meet-b@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS_A, 'name' => 'Meet WS A', 'slug' => 'meet-ws-a', 'created_by' => $this->uidA, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS_B, 'name' => 'Meet WS B', 'slug' => 'meet-ws-b', 'created_by' => $uidB, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS_A, 'user_id' => $this->uidA, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS_B, 'user_id' => $uidB, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->meetingB = (int) DB::table('meetings')->insertGetId(['workspace_id' => self::WS_B, 'title' => 'B private meeting', 'type' => 'strategy', 'status' => 'active', 'created_by' => $uidB, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => self::WS_A, 'balance' => 3, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach ([self::WS_A, self::WS_B] as $ws) {
            foreach (['meeting_messages', 'meetings', 'credits', 'credit_transactions', 'workspace_users'] as $t) {
                try {
                    if ($t === 'meeting_messages') { DB::table($t)->whereIn('meeting_id', DB::table('meetings')->where('workspace_id', $ws)->pluck('id'))->delete(); }
                    else { DB::table($t)->where('workspace_id', $ws)->delete(); }
                } catch (\Throwable) {}
            }
            try { DB::table('workspaces')->where('id', $ws)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('users')->whereIn('email', ['meet-a@example.test', 'meet-b@example.test'])->delete(); } catch (\Throwable) {}
    }

    private function headersA(): array
    {
        $user  = \App\Models\User::find($this->uidA);
        $token = app(\App\Core\Auth\RefreshTokenService::class)->issueAccessToken($user, \App\Models\Workspace::find(self::WS_A));
        return ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token];
    }

    /** @test */
    public function test_another_workspaces_meeting_is_not_found_on_every_id_route(): void
    {
        $H = $this->headersA();
        $id = $this->meetingB;
        $this->withHeaders($H)->getJson("/api/meeting/{$id}")->assertStatus(404);
        $this->withHeaders($H)->getJson("/api/meeting/{$id}/status")->assertStatus(404);
        $this->withHeaders($H)->getJson("/api/meeting/{$id}/pending-tasks")->assertStatus(404);
        $this->withHeaders($H)->postJson("/api/meeting/{$id}/message", ['content' => 'hi'])->assertStatus(404);
        $this->withHeaders($H)->postJson("/api/meeting/{$id}/wrap")->assertStatus(404);
        $this->withHeaders($H)->postJson("/api/sarah/meeting/{$id}/advance")->assertStatus(404);
        $this->withHeaders($H)->postJson("/api/sarah/meeting/{$id}/end")->assertStatus(404);
        $this->assertSame('active', DB::table('meetings')->where('id', $id)->value('status'), 'the foreign meeting was not touched');
        $this->assertSame(0, DB::table('meeting_messages')->where('meeting_id', $id)->count());
    }

    /** @test */
    public function test_starting_a_meeting_through_the_alias_is_refused_without_eight_credits(): void
    {
        $res = $this->withHeaders($this->headersA())->postJson('/api/meeting/start', ['type' => 'strategy', 'topic' => 'Test topic']);
        $res->assertStatus(402);
        $this->assertSame(8, (int) $res->json('required_credits'));
        $this->assertSame(0, DB::table('meetings')->where('workspace_id', self::WS_A)->count(), 'no meeting is created when the wallet cannot cover it');
    }
}
