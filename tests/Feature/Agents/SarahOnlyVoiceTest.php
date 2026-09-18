<?php

namespace Tests\Feature\Agents;

use App\Core\Agents\AgentMessageService;
use App\Core\Agents\SarahVoice;
use App\Core\Notifications\PushDispatcherService;
use App\Jobs\ScheduledFollowupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * Owner, 2026-09-18 — "no more proactive communication from other agents, only Sarah (also on Laravel)".
 * Specialists still do the work; the customer hears about it from Sarah, in Sarah's thread, under Sarah's name.
 */
class SarahOnlyVoiceTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        foreach ([['sarah', 'Sarah', 'Digital Marketing Manager', 'dmm', 1], ['james', 'James', 'SEO Strategist', 'seo', 0]] as [$slug, $name, $title, $cat, $dmm]) {
            if (! DB::table('agents')->where('slug', $slug)->exists()) {
                DB::table('agents')->insert(['slug' => $slug, 'name' => $name, 'title' => $title, 'category' => $cat, 'status' => 'active', 'is_dmm' => $dmm, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function test_a_specialists_delegation_report_is_spoken_by_sarah_in_her_thread(): void
    {
        $ws = $this->testWorkspace->id;
        $id = app(AgentMessageService::class)->postAsAgent($ws, 'james', "Sarah passed me 2 jobs — here's what's done:\n\n• linked up orphan pages", ['notification_type' => 'work_completed', 'delegated_by' => 'sarah', 'push' => false]);
        $row = DB::table('agent_messages')->find($id);
        $this->assertSame('sarah', $row->agent_slug);
        $this->assertSame('Sarah', $row->sender);
        $this->assertStringStartsWith("James finished the 2 jobs I passed along — here's what's done:", $row->content);
        $this->assertStringContainsString('linked up orphan pages', $row->content);
        $meta = json_decode($row->metadata_json, true);
        $this->assertSame('james', $meta['relayed_from']);
        $this->assertSame('James', $meta['relayed_sender']);
        $this->assertSame('sarah', $meta['voiced_by']);
        $this->assertSame(0, DB::table('agent_messages')->where('workspace_id', $ws)->where('agent_slug', 'james')->count(), 'nothing lands in the specialist thread');
    }

    public function test_the_other_phrasings_are_rephrased_and_sarahs_own_messages_are_untouched(): void
    {
        $this->assertSame('James checked the job I passed along — nothing needed changing, so it stays as it is.',
            SarahVoice::rephrase('James', 'Sarah passed me 1 job to look at. Checked it — nothing needed changing, so I left things as they are.'));
        $this->assertSame('James checked the 3 jobs I passed along — nothing needed changing, so they stay as they are.',
            SarahVoice::rephrase('James', 'Sarah passed me 3 jobs to look at. Checked them — nothing needed changing, so I left things as they are.'));
        $this->assertSame("James reports:\n\nInternal link added to \"Yin Yoga\"", SarahVoice::rephrase('James', 'Internal link added to "Yin Yoga"'));

        $ws = $this->testWorkspace->id;
        $id = app(AgentMessageService::class)->postAsAgent($ws, 'sarah', 'Your weekly plan is ready.', ['kind' => 'weekly', 'push' => false]);
        $row = DB::table('agent_messages')->find($id);
        $this->assertSame('sarah', $row->agent_slug);
        $this->assertSame('Your weekly plan is ready.', $row->content);
        $this->assertArrayNotHasKey('relayed_from', json_decode($row->metadata_json, true));
        $this->assertNull(app(AgentMessageService::class)->postAsAgent($ws, 'nobody', 'x'), 'an unknown slug is still refused');
    }

    public function test_every_push_is_from_sarah_and_a_specialist_reply_names_the_specialist_in_the_body(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);
        $ws = $this->testWorkspace->id; $uid = $this->testUser->id;
        $sid = DB::table('sessions')->insertGetId(['user_id' => $uid, 'workspace_id' => $ws, 'refresh_token_hash' => hash('sha256', 'r' . uniqid()), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_tokens')->insert(['user_id' => $uid, 'workspace_id' => $ws, 'session_id' => $sid, 'expo_push_token' => 'ExponentPushToken[test]', 'platform' => 'android', 'created_at' => now(), 'updated_at' => now()]);

        app(PushDispatcherService::class)->dispatchAgentReply($uid, $ws, 'james', 'The audit is done.', 'james', null);
        app(PushDispatcherService::class)->dispatchAgentReply($uid, $ws, 'sarah', 'Your plan is ready.', 'sarah', null);

        $titles = []; $bodies = [];
        Http::assertSent(function ($req) use (&$titles, &$bodies) { foreach ($req->data() as $m) { $titles[] = $m['title'] ?? null; $bodies[] = $m['body'] ?? null; } return true; });
        $this->assertSame(['Sarah', 'Sarah'], $titles);
        $this->assertStringStartsWith('James: ', $bodies[0]);
        $this->assertStringNotContainsString('James', $bodies[1]);
    }

    public function test_a_timed_follow_up_asked_of_a_specialist_arrives_as_sarah(): void
    {
        $ws = $this->testWorkspace->id; $uid = $this->testUser->id;
        $job = new ScheduledFollowupJob($ws, $uid, 'james', 'audit', 'Reminder: the audit report is ready.');
        $job->handle();
        $row = DB::table('agent_messages')->where('workspace_id', $ws)->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('sarah', $row->agent_slug);
        $this->assertSame('Sarah', $row->sender);
        $this->assertStringStartsWith('James reports:', $row->content);
    }
}
