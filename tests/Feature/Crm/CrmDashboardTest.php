<?php

namespace Tests\Feature\Crm;

use App\Engines\CRM\Services\CrmService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRM-2 (2026-08-29) — RISK-0127 (w)/(x): the dashboard returns the widget data crm.js renders, and a
 * note passed with a lead update becomes a real activity.
 */
class CrmDashboardTest extends TestCase
{
    private const WS = 999999908;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'CRM Owner', 'email' => 'crm-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'CRM WS', 'slug' => 'crm-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['A', 'new', 'chatbot888'], ['B', 'new', 'website_form'], ['C', 'contacted', 'website_form']] as [$n, $st, $src]) {
            DB::table('leads')->insert(['workspace_id' => self::WS, 'name' => 'Lead ' . $n, 'email' => strtolower($n) . '@example.test', 'status' => $st, 'source' => $src, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('calendar_events')->insert(['workspace_id' => self::WS, 'title' => 'Booking request — Lead A', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'category' => 'booking_pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['activities', 'calendar_events', 'leads', 'workspace_users'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'crm-test-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    /** @test */
    public function test_dashboard_returns_the_widget_data_the_ui_renders(): void
    {
        $user  = \App\Models\User::find($this->uid);
        $token = app(\App\Core\Auth\RefreshTokenService::class)->issueAccessToken($user, \App\Models\Workspace::find(self::WS));
        $res = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token])->getJson('/api/crm/dashboard');
        $res->assertOk();
        $d = $res->json();
        $this->assertSame(3, (int) $d['total_leads']);
        $stage = collect($d['leads_by_stage'])->keyBy('status');
        $this->assertSame(2, (int) $stage['new']['count']);
        $this->assertSame(1, (int) $stage['contacted']['count']);
        $src = collect($d['leads_by_source'])->keyBy('source');
        $this->assertSame(2, (int) $src['website_form']['count']);
        $this->assertSame(1, (int) $src['chatbot888']['count']);
        $this->assertCount(1, $d['upcoming_appointments']);
        $this->assertSame('Booking request — Lead A', $d['upcoming_appointments'][0]['title']);
        $this->assertIsArray($d['today_tasks']);
    }

    /** @test */
    public function test_a_note_on_update_lead_is_stored_as_an_activity(): void
    {
        $leadId = (int) DB::table('leads')->where('workspace_id', self::WS)->where('name', 'Lead A')->value('id');
        app(CrmService::class)->updateLead($leadId, ['status' => 'contacted', 'note' => 'Called back; wants a Saturday tasting.'], $this->uid, self::WS);
        $notes = DB::table('activities')->where('workspace_id', self::WS)->where('activitable_type', 'Lead')->where('activitable_id', $leadId)->where('type', 'note')->get();
        $this->assertCount(1, $notes);
        $this->assertSame('Called back; wants a Saturday tasting.', $notes[0]->description);
        $this->assertSame(1, DB::table('activities')->where('activitable_id', $leadId)->where('type', 'status_changed')->count());
    }
}
