<?php

namespace Tests\Feature\Awareness;

use App\Core\Awareness\ConnectionFactsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SARAH-AWARE-1 (Owner 2026-09-25): the channel-status fact Sarah reads is computed from the live tables, is written in
 * the shape the chat turn expects, and a connection change becomes a notification.
 */
class ConnectionFactsTest extends TestCase
{
    private const WS = 999999914;
    private int $uid = 0; private int $bizId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Aware Owner', 'email' => 'aware-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Aware WS', 'slug' => 'aware-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->bizId = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS, 'name' => 'Chef Example', 'slug' => 'chef-example-aware', 'created_at' => now(), 'updated_at' => now()]);
        // the hand-written lie that ws 2 carried
        DB::table('workspace_memory')->insert(['workspace_id' => self::WS, 'key' => 'disconnected_engines', 'value_json' => json_encode('Social media and Email are NOT connected for this workspace. Both channels are dead.'), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }

    private function cleanup(): void
    {
        foreach (['social_accounts', 'workspace_memory', 'notifications', 'businesses', 'gsc_connections'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'aware-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function fact(): ?string
    {
        $v = DB::table('workspace_memory')->where('workspace_id', self::WS)->where('key', 'disconnected_engines')->value('value_json');
        return is_string($v) ? json_decode($v, true) : null;
    }

    public function test_the_fact_is_recomputed_from_live_tables_in_the_shape_the_turn_reads(): void
    {
        $svc = app(ConnectionFactsService::class);
        $r = $svc->recompute(self::WS);
        $this->assertTrue($r['first_run']);
        $fact = $this->fact();
        $this->assertIsString($fact, 'the turn keeps only string facts (is_string check in agents-01.php)');
        $this->assertStringContainsString('Social — NOT connected', $fact);
        $this->assertStringNotContainsString('dead', $fact, 'the hand-written sentence is gone');

        DB::table('social_accounts')->insert(['workspace_id' => self::WS, 'platform' => 'facebook', 'account_name' => 'Chef Example Page', 'account_id' => 'pg1', 'status' => 'connected', 'business_id' => $this->bizId, 'created_at' => now(), 'updated_at' => now()]);
        $r = $svc->recompute(self::WS, true);
        $fact = $this->fact();
        $this->assertStringContainsString('Social — CONNECTED: Facebook Page "Chef Example Page" for the business Chef Example', $fact);
        $this->assertStringContainsString('never call a CONNECTED channel disconnected', $fact);
        $this->assertCount(1, $r['changes']); $this->assertSame('social.account.connected', $r['changes'][0]['kind']);

        $n = DB::table('notifications')->where('workspace_id', self::WS)->get();
        $this->assertCount(1, $n, 'a connection change is a notification');
        $this->assertSame('social.account.connected', $n[0]->type);
        $this->assertStringContainsString('Facebook Page "Chef Example Page" is now connected for Chef Example', $n[0]->body);
        $this->assertStringContainsString('nothing is posted without your approval', $n[0]->body);

        // no change → no new notification; recompute is idempotent
        $r = $svc->recompute(self::WS, true);
        $this->assertSame([], $r['changes']);
        $this->assertSame(1, DB::table('notifications')->where('workspace_id', self::WS)->count());

        // disconnect → told
        DB::table('social_accounts')->where('workspace_id', self::WS)->update(['status' => 'disconnected']);
        $r = $svc->recompute(self::WS, true);
        $this->assertSame('social.account.disconnected', $r['changes'][0]['kind']);
        $this->assertStringContainsString('Social — NOT connected', $this->fact());
        $this->assertSame(2, DB::table('notifications')->where('workspace_id', self::WS)->count());
    }

    public function test_the_first_computation_never_spams_a_workspace_with_history(): void
    {
        DB::table('social_accounts')->insert(['workspace_id' => self::WS, 'platform' => 'facebook', 'account_name' => 'Old Page', 'account_id' => 'pg0', 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
        $r = app(ConnectionFactsService::class)->recompute(self::WS, true);
        $this->assertTrue($r['first_run']);
        $this->assertSame(0, DB::table('notifications')->where('workspace_id', self::WS)->count(), 'a baseline is recorded silently');
        $this->assertStringContainsString('CONNECTED: Facebook Page "Old Page"', $this->fact());
    }
}
