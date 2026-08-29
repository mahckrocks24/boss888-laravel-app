<?php

namespace Tests\Feature\Lead;

use App\Engines\Chatbot\Services\ChatbotResponseService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LEAD-2 (2026-08-29) — RISK-0127 (t)/(u): booking times are parsed in the WORKSPACE zone (set from the
 * owner's browser at onboarding) instead of UTC, and the visitor is never promised an email nothing sends.
 */
class LeadTimezoneTest extends TestCase
{
    private const WS = 999999907;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'TZ Owner', 'email' => 'tz-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'TZ WS', 'slug' => 'tz-ws', 'created_by' => $this->uid, 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['chatbot_settings', 'workspace_users'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'tz-test-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function parse(?string $date, ?string $time): ?\Carbon\Carbon
    {
        $svc = app(ChatbotResponseService::class);
        $m = new \ReflectionMethod($svc, 'parseDateTime');
        $m->setAccessible(true);
        return $m->invoke($svc, $date, $time, self::WS);
    }

    /** @test */
    public function test_chatbot_times_follow_the_workspace_zone_when_the_chatbot_has_none(): void
    {
        DB::table('workspaces')->where('id', self::WS)->update(['timezone' => 'America/Los_Angeles']);
        $dt = $this->parse('2026-09-01', '09:00');
        $this->assertNotNull($dt);
        $this->assertSame('2026-09-01 16:00', $dt->utc()->format('Y-m-d H:i'), '09:00 in Los Angeles is 16:00 UTC');

        // A chatbot-level zone still wins over the workspace zone.
        DB::table('chatbot_settings')->insert(['workspace_id' => self::WS, 'primary_color' => '#000000', 'enabled' => 1, 'theme' => 'auto', 'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('2026-09-01 08:00', $this->parse('2026-09-01', '09:00')->utc()->format('Y-m-d H:i'));
    }

    /** @test */
    public function test_settings_route_accepts_a_valid_zone_and_ignores_garbage(): void
    {
        $user  = \App\Models\User::find($this->uid);
        $token = app(\App\Core\Auth\RefreshTokenService::class)->issueAccessToken($user, \App\Models\Workspace::find(self::WS));
        $H     = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token];
        $ok = $this->withHeaders($H)
            ->putJson('/api/workspace/settings', ['timezone' => 'Europe/Paris', 'workspace_id' => self::WS]);
        $ok->assertOk();
        $this->assertSame('Europe/Paris', DB::table('workspaces')->where('id', self::WS)->value('timezone'));

        $bad = $this->withHeaders($H)
            ->postJson('/api/workspace/settings', ['timezone' => 'Mars/Olympus', 'workspace_id' => self::WS]);
        $bad->assertOk(); // the save itself succeeds; the garbage zone is simply not applied
        $this->assertSame('Europe/Paris', DB::table('workspaces')->where('id', self::WS)->value('timezone'));
    }

    /** @test */
    public function test_the_visitor_is_not_promised_an_email_nothing_sends(): void
    {
        $src = (string) file_get_contents(app_path('Engines/Chatbot/Services/ChatbotResponseService.php'));
        $this->assertStringNotContainsString("email to confirm", $src);
        $this->assertStringNotContainsString("will email to confirm", $src);
        $arthur = (string) file_get_contents(app_path('Engines/Builder/Services/ArthurService.php'));
        $this->assertStringNotContainsString("We'll confirm by email", $arthur);
    }
}
