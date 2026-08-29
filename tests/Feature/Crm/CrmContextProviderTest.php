<?php

namespace Tests\Feature\Crm;

use App\Core\Intelligence\Providers\CRMContextProvider;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0127 uu (2026-08-30) — the CRM context brief counts leads as contacts on file (it used to say
 * "0 total contacts but 4 new this month" because total_contacts read the contacts table only).
 */
class CrmContextProviderTest extends TestCase
{
    private const WS = 999999917;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Ctx Owner', 'email' => 'ctx-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Ctx WS', 'slug' => 'ctx-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['A', 'B', 'C'] as $n) {
            DB::table('leads')->insert(['workspace_id' => self::WS, 'name' => 'Lead ' . $n, 'email' => strtolower($n) . '@ctx.test', 'status' => 'new', 'source' => 'chatbot888', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['leads', 'contacts'] as $t) { try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {} }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'ctx-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    /** @test */
    public function test_leads_count_as_contacts_on_file(): void
    {
        $p = app(CRMContextProvider::class);
        $m = null;
        foreach (['provide', 'gather', 'collect', 'get', 'build', 'context', 'handle', '__invoke'] as $name) {
            if (method_exists($p, $name)) { $m = $name; break; }
        }
        $this->assertNotNull($m, 'provider entry method');
        $out = $p->{$m}(self::WS);
        $out = is_array($out) ? $out : (array) $out;
        $this->assertSame(3, (int) ($out['total_contacts'] ?? -1), 'three leads = three contacts on file');
        $this->assertSame(3, (int) ($out['total_leads'] ?? -1));
        $this->assertSame(3, (int) ($out['leads_last_30d'] ?? -1));
    }
}
