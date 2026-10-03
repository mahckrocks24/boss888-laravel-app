<?php

namespace Tests\Feature\Sarah;

use App\Core\Billing\TaskRefund;
use App\Core\Brand\InspirationService;
use App\Core\Business\BusinessContext;
use App\Core\OwnerModel\RuntimeMemorySync;
use App\Core\Sarah888\BuilderEditPromotion;
use App\Core\Sarah888\CalendarPromotion;
use App\Core\Sarah888\OperationalFacts;
use App\Core\Sarah888\ReplyOwnership;
use App\Core\Sarah888\SarahQaGate;
use App\Core\Sarah888\SpendContext;
use App\Core\Sarah888\SpendPolicy;
use App\Core\TaskSystem\TaskService;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * REPORT-0071 remediation regressions (Sarah certification, 2026-10-02). One case per defect, named by id:
 *   QA-BIZ-1..5  quality gate business context (P0-2)      QA-REFUND-1  rejected charged work refunded once (P0-2)
 *   SPEND-1..3   ambiguity before paid execution (P1-2)    CAL-1..3     calendar intent routing (P1-1)
 *   OWN-1..3     one authoritative answer (P1-3)            OPS-1..3     operational state (P1-4)
 *   SYNC-1..5    runtime memory sync, change-only (P6b)
 */
class CertificationRemediationTest extends TestCase
{
    private const WS = 999961;      // multi-business workspace (bakery default with a site, private chef without)
    private const WS2 = 999962;     // single business, no website
    private const WS3 = 999963;     // another tenant, for isolation
    private int $bakery = 0; private int $chef = 0; private int $solo = 0; private int $other = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) (DB::table('users')->where('email', 'cert-owner@example.test')->value('id') ?: DB::table('users')->insertGetId(['name' => 'Cert Owner', 'email' => 'cert-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]));
        foreach ([self::WS => 'Cert Bakery Workspace', self::WS2 => 'Cert Solo Chef', self::WS3 => 'Cert Other Tenant'] as $id => $n)
            DB::table('workspaces')->insert(['id' => $id, 'name' => $n, 'slug' => 'cert-' . $id, 'timezone' => 'America/Los_Angeles', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->bakery = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS, 'name' => 'Golden Crust Bakery', 'slug' => 'golden-crust', 'industry' => 'bakery', 'differentiators' => 'sourdough bread and pastries baked every morning', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->chef = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS, 'name' => 'Chef Red Private Dining', 'slug' => 'chef-red', 'industry' => 'private_chef', 'target_audience' => 'couples and small groups', 'differentiators' => 'Filipino-French tasting menus cooked at your table', 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->solo = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS2, 'name' => 'Solo Kitchen', 'slug' => 'solo', 'industry' => 'private_chef', 'differentiators' => 'Filipino tasting dinners at home', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->other = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS3, 'name' => 'Other Tenant Florist', 'slug' => 'florist', 'industry' => 'florist', 'differentiators' => 'wedding flowers', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['workspace_id' => self::WS, 'business_id' => $this->bakery, 'name' => 'Golden Crust', 'status' => 'published', 'template_industry' => 'bakery', 'template_variables' => json_encode(['business_name' => 'Golden Crust Bakery', 'industry' => 'bakery', 'tagline' => 'Fresh sourdough every morning']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_memory')->insert(['workspace_id' => self::WS, 'key' => 'business_name', 'value_json' => json_encode('Golden Crust Bakery'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_memory')->insert(['workspace_id' => self::WS, 'key' => 'industry', 'value_json' => json_encode('bakery'), 'created_at' => now(), 'updated_at' => now()]);
        // Judge stand-in: aligned only when the piece shares a topic word with the profile's business(es) - the production
        // judge is a model; this proves WHICH business the gate hands it, which is the defect.
        SarahQaGate::$alignmentResolver = function (array $profile, array $d) {
            $text = strtolower((string) ($d['content'] ?? '') . ' ' . ($d['title'] ?? ''));
            foreach ($profile['businesses'] ?: [['name' => $profile['business_name'], 'industry' => $profile['industry'], 'description' => $profile['description']]] as $b) {
                foreach (preg_split('/[^a-z]+/', strtolower($b['industry'] . ' ' . $b['description'])) as $w) if (strlen($w) >= 6 && str_contains($text, $w)) return ['aligned' => true, 'reason' => 'shares "' . $w . '" with ' . $b['name']];
            }
            return ['aligned' => false, 'reason' => 'does not match ' . $profile['business_name']];
        };
    }

    protected function tearDown(): void { SarahQaGate::$alignmentResolver = null; $this->cleanup(); parent::tearDown(); }

    private function cleanup(): void
    {
        $ws = [self::WS, self::WS2, self::WS3];
        foreach (['marketing_campaigns', 'social_posts', 'tasks', 'approvals', 'credit_transactions', 'credits', 'websites', 'businesses', 'workspace_memory', 'agent_messages', 'owner_model_facts', 'owner_model_events', 'memory_events', 'outcome_ledger', 'notifications', 'repair_log', 'calendar_events', 'experience_owner_feedback', 'business_journal'] as $t) {
            try { DB::table($t)->whereIn('workspace_id', $ws)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->whereIn('id', $ws)->delete(); } catch (\Throwable) {}
    }

    private function socialPost(int $ws, string $content): int
    {
        return (int) DB::table('social_posts')->insertGetId(['workspace_id' => $ws, 'platform' => 'instagram', 'content' => $content, 'status' => 'draft', 'hashtags_json' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }

    private const CHEF_POST = 'Kare-kare our way: oxtail in peanut sauce, Filipino-French tasting menus cooked at your table this Saturday. Two seats left.';
    private const BREAD_POST = 'Our sourdough comes out of the oven at 6am - fresh bread and pastries every morning.';

    // ── P0-2 business context ─────────────────────────────────────────────────────────────────────────────────────
    public function test_QA_BIZ_1_single_business_with_website(): void
    {
        $r = app(SarahQaGate::class)->review(self::WS, 'social_create_post', [], ['post_id' => $this->socialPost(self::WS, self::BREAD_POST)], $this->bakery);
        $this->assertSame(SarahQaGate::ACCEPTED, $r['verdict'], json_encode($r['checks']));
        $this->assertStringContainsString('Golden Crust Bakery', $r['checks']['business_context']['detail']);
    }

    public function test_QA_BIZ_2_single_business_without_website(): void
    {
        $r = app(SarahQaGate::class)->review(self::WS2, 'social_create_post', [], ['post_id' => $this->socialPost(self::WS2, self::CHEF_POST)], $this->solo);
        $this->assertSame(SarahQaGate::ACCEPTED, $r['verdict'], 'a business needs no website to be understood: ' . json_encode($r['checks']));
    }

    public function test_QA_BIZ_3_multi_business_target_is_the_non_default_business(): void
    {
        $pid = $this->socialPost(self::WS, self::CHEF_POST);
        // before: the gate saw only the default business and its website -> the Chef Red post was "off-topic"
        $before = app(SarahQaGate::class)->workspaceProfile(self::WS, 0);
        $this->assertSame('Golden Crust Bakery', $before['business_name']);
        $r = app(SarahQaGate::class)->review(self::WS, 'social_create_post', [], ['post_id' => $pid], $this->chef);
        $this->assertSame(SarahQaGate::ACCEPTED, $r['verdict'], json_encode($r['checks']));
        $this->assertStringContainsString('Chef Red Private Dining', $r['checks']['business_context']['detail']);
        // the business can also arrive in the payload only (older callers)
        $r2 = app(SarahQaGate::class)->review(self::WS, 'social_create_post', ['business_id' => $this->chef], ['post_id' => $pid]);
        $this->assertSame(SarahQaGate::ACCEPTED, $r2['verdict']);
    }

    public function test_QA_BIZ_4_no_business_association_falls_back_to_the_workspace_including_site_less_businesses(): void
    {
        $r = app(SarahQaGate::class)->review(self::WS, 'social_create_post', [], ['post_id' => $this->socialPost(self::WS, self::CHEF_POST)], null);
        $this->assertStringContainsString('no business on the task', $r['checks']['business_context']['detail']);
        $this->assertSame(SarahQaGate::ACCEPTED, $r['verdict'], 'the site-less business is part of the workspace profile: ' . json_encode($r['checks']));
    }

    public function test_QA_BIZ_5_wrong_business_contamination_is_rejected(): void
    {
        // a Chef Red piece filed under the BAKERY must not pass because it suits another business in the workspace
        $r = app(SarahQaGate::class)->review(self::WS, 'social_create_post', [], ['post_id' => $this->socialPost(self::WS, self::CHEF_POST)], $this->bakery);
        $this->assertSame(SarahQaGate::REJECTED, $r['verdict'], json_encode($r['checks']));
        // and a business id from another tenant is ignored (never another workspace's profile)
        $r2 = app(SarahQaGate::class)->review(self::WS, 'social_create_post', [], ['post_id' => $this->socialPost(self::WS, self::BREAD_POST)], $this->other);
        $this->assertStringContainsString('no business on the task', $r2['checks']['business_context']['detail']);
    }

    public function test_QA_REFUND_1_rejected_charged_deliverable_is_refunded_once_across_paths(): void
    {
        DB::table('credits')->insert(['workspace_id' => self::WS, 'balance' => 96, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $pid = $this->socialPost(self::WS, self::CHEF_POST);
        $task = Task::create(['workspace_id' => self::WS, 'engine' => 'social', 'action' => 'social_create_post', 'status' => 'running', 'business_id' => $this->bakery, 'credit_cost' => 4, 'payload_json' => ['platform' => 'instagram']]);
        DB::table('credit_transactions')->insert(['workspace_id' => self::WS, 'type' => 'commit', 'amount' => 4, 'reference_type' => 'Task', 'reference_id' => $task->id, 'created_at' => now()]);
        app(TaskService::class)->markCompleted($task, ['success' => true, 'post_id' => $pid]);
        $row = DB::table('tasks')->where('id', $task->id)->first();
        $this->assertSame('rejected', $row->qa_status);
        $refunds = DB::table('credit_transactions')->where('reference_type', 'Task')->where('reference_id', $task->id)->where('type', 'credit')->get();
        $this->assertCount(1, $refunds);
        $this->assertSame(4, (int) $refunds[0]->amount);
        $this->assertSame(100, (int) DB::table('credits')->where('workspace_id', self::WS)->value('balance'));
        $this->assertSame(4, (int) (json_decode((string) $row->qa_json, true)['refund']['credits'] ?? 0));
        // the repair loop (or a second QA pass) cannot refund it again
        $this->assertNull(TaskRefund::refund(self::WS, (int) $task->id, 'repair'));
        $this->assertSame(1, DB::table('credit_transactions')->where('reference_type', 'Task')->where('reference_id', $task->id)->where('type', 'credit')->count());
    }

    // ── P1-2 ambiguity before paid execution ──────────────────────────────────────────────────────────────────────
    public function test_SPEND_1_vague_quality_and_time_words_name_no_deliverable(): void
    {
        $p = app(SpendPolicy::class);
        foreach (['Make it pop for the weekend.', 'Make it better.', 'Make it punchier for Friday', 'Make it stand out this week', 'Just make it pop'] as $m) $this->assertFalse($p->specifiesAction($m), $m);
        foreach (['Write an Instagram caption for Saturday\'s kare-kare dinner', 'Make the hero headline bold', 'Create an image for the kare-kare dinner', 'Write a blog article about anniversary dinners', 'Make it pop: add a bigger headline to the poster'] as $m) $this->assertTrue($p->specifiesAction($m), $m);
    }

    public function test_SPEND_2_ambiguous_paid_request_creates_nothing_and_charges_nothing(): void
    {
        $turn = app(SpendPolicy::class)->assessTurn('Make it pop for the weekend.');
        app(SpendContext::class)->setTurn($turn, self::WS);
        $before = DB::table('tasks')->where('workspace_id', self::WS)->count();
        try {
            app(TaskService::class)->create(self::WS, ['engine' => 'social', 'action' => 'social_create_post', 'source' => 'agent', 'credit_cost' => 4, 'payload' => ['platform' => 'instagram', 'topic' => 'weekend kare-kare']]);
            $this->fail('an unspecified paid request must not create work');
        } catch (\App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized $e) {
            $this->assertStringContainsString('UNSPECIFIED_DELEGATION', $e->getMessage());
        }
        $this->assertSame($before, DB::table('tasks')->where('workspace_id', self::WS)->count());
        $this->assertSame(0, DB::table('credit_transactions')->where('workspace_id', self::WS)->count());
    }

    public function test_SPEND_3_explicit_requests_keep_their_authorisation_at_both_costs(): void
    {
        $p = app(SpendPolicy::class);
        $explicit = $p->assessTurn('Write a blog article about hiring a private chef for an anniversary dinner.');
        $this->assertTrue($explicit['authorized']);
        $this->assertTrue($explicit['specifies_action']);
        $this->assertFalse($p->gate(['credit_cost' => 1], $explicit, self::WS)['requires_approval']);
        $this->assertFalse($p->gate(['credit_cost' => 4], $explicit, self::WS)['requires_approval']);
        $ambiguous = $p->assessTurn('Make it pop for the weekend.');
        $this->assertFalse($ambiguous['specifies_action']);
        $go = $p->assessTurn('Yes, go ahead with the autumn post.');   // a plan/proposal authorisation still passes (DEC-0018)
        $this->assertTrue($go['authorized']);
    }

    // ── P1-1 calendar ─────────────────────────────────────────────────────────────────────────────────────────────
    public function test_CAL_1_calendar_intent_is_not_a_website_edit(): void
    {
        $m = 'Put a private tasting dinner on my calendar for Saturday 10 October at 7pm, for the Lim family, 6 guests.';
        $this->assertTrue(CalendarPromotion::isScheduling($m));
        $this->assertNull(BuilderEditPromotion::detect($m), 'the website lane must not take a calendar request');
        foreach (['Add a booking calendar section to the homepage', 'Put an events calendar on my website'] as $site) {
            $this->assertFalse(CalendarPromotion::isScheduling($site), $site);
            $this->assertNotNull(BuilderEditPromotion::detect($site), $site);
        }
        $this->assertTrue(CalendarPromotion::isScheduling('Book a call with Grace Liu next Tuesday at 3pm'));
    }

    public function test_CAL_2_date_time_and_title_resolve_in_the_owner_timezone(): void
    {
        $m = 'Put a private tasting dinner on my calendar for Saturday 10 October at 7pm, for the Lim family, 6 guests.';
        $at = CalendarPromotion::when($m, 'America/Los_Angeles');
        $this->assertSame('10-10 19:00', $at->format('m-d H:i'));
        $this->assertSame('America/Los_Angeles', $at->getTimezone()->getName());
        $t = CalendarPromotion::title($m);
        $this->assertStringContainsString('Private tasting dinner', $t);
        $this->assertStringContainsString('Lim family', $t);
        $this->assertNull(CalendarPromotion::when('Put the tasting dinner on my calendar', 'UTC'), 'no date -> Sarah asks, nothing is guessed');
    }

    public function test_CAL_3_promotion_calls_the_governed_calendar_tool(): void
    {
        $svc = \Mockery::mock(\App\Core\Orchestration\ToolSchemaService::class);
        $svc->shouldReceive('executeToolCall')->once()->withArgs(function ($tool, $params, $ws) { return $tool === 'calendar.create_event' && $ws === self::WS && str_starts_with($params['start_at'], date('Y') . '-10-10 19:00') || str_contains($params['start_at'], '-10-10 19:00'); })
            ->andReturn(['success' => true, 'pending_approval' => true, 'approval_id' => 1]);
        $r = CalendarPromotion::promote($svc, self::WS, 'Put a private tasting dinner on my calendar for Saturday 10 October at 7pm, for the Lim family, 6 guests.', 'sarah');
        $this->assertTrue($r['handled']);
        $this->assertStringContainsString('review queue', $r['reply']);
        $this->assertStringNotContainsString('website', strtolower($r['reply']));
    }

    // ── P1-3 one authoritative answer ─────────────────────────────────────────────────────────────────────────────
    private function ownerMessageWithFinalReply(): int
    {
        $u = (int) DB::table('agent_messages')->insertGetId(['workspace_id' => self::WS, 'agent_slug' => 'sarah', 'sender' => 'Owner', 'role' => 'user', 'content' => 'What do you think of this image?', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_messages')->insert(['workspace_id' => self::WS, 'agent_slug' => 'sarah', 'sender' => 'Sarah', 'role' => 'agent', 'content' => 'Honest read: ...', 'metadata_json' => json_encode(['phase' => 'final', 'user_message_id' => $u]), 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    public function test_OWN_1_undeclared_follow_up_after_the_answer_is_withheld(): void
    {
        $u = $this->ownerMessageWithFinalReply();
        $this->assertTrue(ReplyOwnership::answered(self::WS, $u));
        $this->assertFalse(ReplyOwnership::mayFollowUp(self::WS, $u, 'inspiration'), 'a second, competing answer must not post');
    }

    public function test_OWN_2_overlapping_completions_post_exactly_one_declared_follow_up(): void
    {
        $u = $this->ownerMessageWithFinalReply();
        ReplyOwnership::declare(self::WS, $u, 'inspiration');
        $granted = 0; for ($i = 0; $i < 5; $i++) if (ReplyOwnership::mayFollowUp(self::WS, $u, 'inspiration')) $granted++;   // five workers finishing together
        $this->assertSame(1, $granted);
        $this->assertFalse(ReplyOwnership::mayFollowUp(self::WS, $u, 'brand_summary'), 'a different, undeclared kind is still withheld');
    }

    public function test_OWN_3_a_critique_question_about_an_image_is_not_an_inspiration_share(): void
    {
        $img = [['kind' => 'image', 'url' => '/storage/x.jpg', 'media_id' => 1]];
        $this->assertFalse(InspirationService::isInspirationAsk('What do you think of this image for my Instagram? Be honest about what works and what doesn\'t.', $img));
        $this->assertTrue(InspirationService::isInspirationAsk('I love this look, use it for our banners', $img));
        $this->assertTrue(InspirationService::isInspirationAsk('', $img));
        $this->assertFalse(InspirationService::isInspirationAsk('I love this look', [['kind' => 'document']]));
    }

    // ── P1-4 operational state ────────────────────────────────────────────────────────────────────────────────────
    public function test_OPS_1_approvals_credits_and_team_from_fixture_data(): void
    {
        DB::table('credits')->insert(['workspace_id' => self::WS, 'balance' => 100, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['write_article', 3], ['write_article', 3], ['ask_arthur', 1]] as [$a, $c]) {
            $t = (int) DB::table('tasks')->insertGetId(['workspace_id' => self::WS, 'engine' => 'x', 'action' => $a, 'status' => 'pending', 'credit_cost' => $c, 'assigned_agents_json' => json_encode([$a === 'ask_arthur' ? 'arthur' : 'priya']), 'business_id' => $this->chef, 'created_at' => now()->subDays(3), 'updated_at' => now()]);
            DB::table('approvals')->insert(['workspace_id' => self::WS, 'task_id' => $t, 'action' => $a, 'status' => 'pending', 'created_at' => now()->subDays(3), 'updated_at' => now()]);
        }
        DB::table('tasks')->insert(['workspace_id' => self::WS, 'engine' => 'x', 'action' => 'social_create_post', 'status' => 'blocked', 'progress_message' => 'Instagram is not connected', 'assigned_agents_json' => json_encode(['marcus']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credit_transactions')->insert(['workspace_id' => self::WS, 'type' => 'commit', 'amount' => 14, 'reference_type' => 'Task', 'reference_id' => 1, 'created_at' => now()->subDays(2)]);
        // another tenant's approvals must never appear
        DB::table('approvals')->insert(['workspace_id' => self::WS3, 'action' => 'write_article', 'status' => 'pending', 'created_at' => now()->subDays(40), 'updated_at' => now()]);

        $f = OperationalFacts::render(self::WS, 'What needs my approval, will my credits last the month, and who on the team is working on what?');
        $this->assertStringContainsString('APPROVALS WAITING: 3 in total', $f);
        $this->assertStringContainsString('2 x write article', $f);
        $this->assertStringContainsString('6 credits in total', $f);
        $this->assertStringContainsString('CREDITS: 100 available', $f);
        $this->assertStringContainsString('14 in the last 7 days', $f);
        $this->assertStringContainsString('runway at the last 7 days\' pace: 50 days', $f);
        $this->assertStringContainsString('approving everything now waiting would cost 7 credits', $f);
        $this->assertStringContainsString('TEAM AND QUEUE', $f);
        $this->assertStringContainsString('Instagram is not connected', $f);
        $this->assertStringNotContainsString(substr((string) now()->subDays(40)->toDateString(), 0, 10), $f, 'another tenant leaked into the facts');
    }

    public function test_OPS_2_unavailable_state_is_named_not_invented(): void
    {
        DB::table('credits')->insert(['workspace_id' => self::WS2, 'balance' => 20, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $f = OperationalFacts::render(self::WS2, 'Will my credits last the month?');
        $this->assertStringContainsString('not computable (no spend in the last 7 days)', $f);
        $this->assertStringContainsString('CANNOT SEE', $f);
        $this->assertSame('', OperationalFacts::render(self::WS2, 'Write me a caption for Saturday'), 'non-operational turns get no block');
    }

    public function test_OPS_3_operational_questions_never_trigger_the_which_business_clarifier(): void
    {
        config(['business.profiles' => true]);   // RFC-0011 multi-business is on in production for this kind of workspace
        $this->assertSame('ambiguous', app(BusinessContext::class)->resolve(self::WS, 'How are my customers finding us?')['mode'], 'control: a business question with nothing named still asks');
        $ctx = app(BusinessContext::class)->resolve(self::WS, 'Who on your team is working on what for me right now?');
        $this->assertSame('portfolio', $ctx['mode'], json_encode(['mode' => $ctx['mode'], 'source' => $ctx['source'] ?? null, 'multi' => $ctx['multi'] ?? null]));
        $this->assertNull($ctx['ask']);
    }

    // ── P6b runtime memory sync ───────────────────────────────────────────────────────────────────────────────────
    private function syncEnv(bool $fake = true): void
    {
        $_SERVER['RUNTIME_URL'] = $_ENV['RUNTIME_URL'] = 'http://runtime.test'; putenv('RUNTIME_URL=http://runtime.test');
        $_SERVER['RUNTIME_SECRET'] = $_ENV['RUNTIME_SECRET'] = 'test-secret'; putenv('RUNTIME_SECRET=test-secret');
        app()->forgetInstance(AppConnectorsRuntimeClient::class);
        if (! file_exists(storage_path('app/memory1.on'))) $this->markTestSkipped('memory1.on absent');
        if ($fake) Http::fake(['runtime.test/*' => Http::response(['ok' => true, 'memory' => ['business_profile' => []]], 200)]);
        Queue::fake();
        Cache::flush();
    }

    private function fact(int $ws, ?int $biz, string $key, string $value): void
    {
        DB::table('owner_model_facts')->insert(['workspace_id' => $ws, 'business_id' => $biz, 'group' => 'preferences', 'key' => $key, 'value' => $value, 'source' => 'stated', 'confidence' => 0.9, 'status' => 'confirmed', 'first_seen_at' => now(), 'last_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function pushes(int $ws): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/internal/workspace-memory') && (int) ($p[0]->data()['wsId'] ?? 0) === $ws && ($p[0]->data()['field'] ?? '') === 'sarah_memory')->count();
    }

    public function test_SYNC_1_unchanged_memory_is_not_resent_and_a_change_is_not_missed(): void
    {
        $this->syncEnv();
        $this->fact(self::WS2, $this->solo, 'no_emojis', 'You never want emojis.');
        $s = app(RuntimeMemorySync::class);
        $__p = $s->push(self::WS2); $this->assertTrue($__p['ok'], json_encode($__p) . ' enabled=' . var_export(RuntimeMemorySync::enabled(), true) . ' env=' . var_export(env('RUNTIME_URL'), true));
        $this->assertSame('unchanged', $s->push(self::WS2)['skipped'] ?? null);
        $this->assertSame(1, $this->pushes(self::WS2));
        $this->fact(self::WS2, $this->solo, 'sign_chef', 'You want posts signed Chef Red.');
        $this->assertTrue($s->push(self::WS2)['ok']);
        $this->assertSame(2, $this->pushes(self::WS2), 'a real change must be pushed');
    }

    public function test_SYNC_2_business_facts_refresh_queues_only_on_change(): void
    {
        $this->syncEnv();
        $bf = app(\App\Core\Awareness\BusinessFactsService::class);
        $bf->recompute(self::WS2);   // first write is a change
        Queue::assertPushed(\App\Jobs\RuntimeMemorySyncJob::class, 1);
        Cache::flush();              // clear the debounce so only the change test decides
        $bf->recompute(self::WS2);   // nothing changed
        Queue::assertPushed(\App\Jobs\RuntimeMemorySyncJob::class, 1);
    }

    public function test_SYNC_3_erasure_reaches_the_runtime_immediately(): void
    {
        $this->syncEnv();
        $this->fact(self::WS2, $this->solo, 'no_emojis', 'You never want emojis.');
        app(\App\Core\OwnerModel\OwnerModelService::class)->erase(self::WS2);
        $last = collect(Http::recorded())->filter(fn ($p) => ($p[0]->data()['field'] ?? '') === 'sarah_memory' && (int) ($p[0]->data()['wsId'] ?? 0) === self::WS2)->last();
        $this->assertNotNull($last, 'erase must push without waiting for the queue');
        $this->assertNotNull($last[0]->data()['value']['erased_at']);
        $this->assertSame([], $last[0]->data()['value']['owner']['preferences']);
    }

    public function test_SYNC_4_no_cross_workspace_contamination(): void
    {
        $this->syncEnv();
        $this->fact(self::WS2, $this->solo, 'no_emojis', 'SOLO-ONLY preference');
        $this->fact(self::WS3, $this->other, 'florist', 'FLORIST-ONLY preference');
        $p2 = json_encode(app(RuntimeMemorySync::class)->payload(self::WS2));
        $p3 = json_encode(app(RuntimeMemorySync::class)->payload(self::WS3));
        $this->assertStringContainsString('SOLO-ONLY', $p2); $this->assertStringNotContainsString('FLORIST-ONLY', $p2);
        $this->assertStringContainsString('FLORIST-ONLY', $p3); $this->assertStringNotContainsString('SOLO-ONLY', $p3);
        app(RuntimeMemorySync::class)->push(self::WS2);
        $this->assertSame(0, $this->pushes(self::WS3), 'pushing one workspace never pushes another');
    }

    public function test_SYNC_5_a_rejected_push_is_retried_not_marked_synced(): void
    {
        $this->syncEnv(false);
        Http::fake(['runtime.test/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true, 'memory' => []], 200)->push(['ok' => true, 'memory' => []], 200)]);
        $this->fact(self::WS2, $this->solo, 'no_emojis', 'You never want emojis.');
        $s = app(RuntimeMemorySync::class);
        $this->assertFalse($s->push(self::WS2)['ok']);
        $this->assertArrayNotHasKey('skipped', $s->push(self::WS2), 'a failed push must not be remembered as synced (no stale state)');
    }

    // ── rerun findings: memory extraction and business names ──────────────────────────────────────────────────────
    private function extract(int $ws, string $text, array $cands): array
    {
        \App\Core\OwnerModel\OwnerModelExtractor::$candidateResolver = fn () => $cands;
        try { return app(\App\Core\OwnerModel\OwnerModelExtractor::class)->run($ws, null, 1, $text); }
        finally { \App\Core\OwnerModel\OwnerModelExtractor::$candidateResolver = null; }
    }

    public function test_EXTRACT_1_the_language_of_one_message_is_never_a_standing_preference(): void
    {
        $w = $this->extract(self::WS2, 'Pwede mo ba akong gawan ng isang linya para sa Undas para sa Chef Red? Isang caption lang, wag mo nang i-queue.',
            [['group' => 'preferences', 'key' => 'writes_in_filipino', 'value' => 'The owner writes to the manager in Filipino (Tagalog).', 'durable' => true, 'quote' => 'Pwede mo ba akong gawan']]);
        $this->assertSame([], $w);
        $this->assertSame(0, DB::table('owner_model_facts')->where('workspace_id', self::WS2)->count());
    }

    public function test_EXTRACT_2_one_off_instructions_are_not_rules_and_model_durable_is_only_a_guess(): void
    {
        $w = $this->extract(self::WS2, 'Now something for the catering side: a short post about office lunch platters. Just the caption, don\'t queue anything.',
            [['group' => 'preferences', 'key' => 'wants_copy_not_queued', 'value' => 'The owner wants copy as text only, nothing queued.', 'durable' => true, 'quote' => 'Just the caption, don\'t queue anything']]);
        $this->assertSame([], $w, 'a request scoped to this turn is not a standing preference');
        $g = $this->extract(self::WS2, 'I want 20 private dinners a month by December.',
            [['group' => 'goals', 'key' => 'dinners_goal', 'value' => 'The owner wants 20 private dinners a month by December.', 'durable' => true, 'quote' => 'I want 20 private dinners a month']]);
        $this->assertSame('proposed', $g[0]['status'] ?? null, 'the model calling it durable makes it a guess, not a confirmed rule');
        $c = $this->extract(self::WS2, 'From now on always sign my posts as Chef Red and never use emojis.',
            [['group' => 'preferences', 'key' => 'sign_chef_red', 'value' => 'The owner wants posts signed as Chef Red.', 'durable' => true, 'quote' => 'always sign my posts as Chef Red']]);
        $this->assertSame('confirmed', $c[0]['status'] ?? null, 'the owner\'s own "always" makes it a rule');
    }

    public function test_EXTRACT_3_unconfirmed_guesses_are_never_presented_as_rules(): void
    {
        DB::table('owner_model_facts')->insert([
            ['workspace_id' => self::WS2, 'business_id' => null, 'group' => 'preferences', 'key' => 'rule', 'value' => 'RULE: never use emojis.', 'source' => 'stated', 'confidence' => 0.9, 'status' => 'confirmed', 'first_seen_at' => now(), 'last_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => self::WS2, 'business_id' => null, 'group' => 'preferences', 'key' => 'guess', 'value' => 'GUESS: prefers short captions.', 'source' => 'inferred', 'confidence' => 0.6, 'status' => 'proposed', 'first_seen_at' => now(), 'last_confirmed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $pack = app(\App\Core\OwnerModel\MemoryPack::class)->build(self::WS2, null, 'write a caption');
        $rules = substr($pack, (int) strpos($pack, 'STANDING PREFERENCES'));
        $rules = substr($rules, 0, (int) (strpos($rules, "\n\n") ?: strlen($rules)));
        $this->assertStringContainsString('RULE: never use emojis.', $rules);
        $this->assertStringNotContainsString('GUESS', $rules);
        $this->assertStringContainsString('THINGS YOU THINK YOU NOTICED (unconfirmed', $pack);
    }

    public function test_BIZNAME_1_a_business_is_recognised_by_its_short_name(): void
    {
        config(['business.profiles' => true]);
        $ctx = app(BusinessContext::class)->resolve(self::WS, 'Hold off on any new posts for Chef Red until I tell you otherwise.');
        $this->assertSame('named', $ctx['mode']);
        $this->assertSame($this->chef, (int) $ctx['business_id']);
        $this->assertSame('named', app(BusinessContext::class)->resolve(self::WS, 'Write a blog article for Golden Crust about rye')['mode']);
    }

    // ── v2.37.20 probe finding: a negated launch is never consent ──────────────────────────────────────────────────
    public function test_LAUNCH_1_dont_launch_and_new_idea_requests_never_launch_an_open_idea(): void
    {
        $ids = [];
        foreach (['Chef Red\'s Holiday Table: Private Dinners for the Season', 'Heritage Ingredient Spotlight'] as $title)
            $ids[] = (int) DB::table('marketing_campaigns')->insertGetId(['workspace_id' => self::WS, 'business_id' => $this->chef, 'title' => $title, 'objective' => 'x', 'status' => 'idea', 'source' => 'sarah_chat', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_messages')->insert(['workspace_id' => self::WS, 'agent_slug' => 'sarah', 'sender' => 'Sarah', 'role' => 'agent', 'content' => 'Here are your campaign ideas.',
            'metadata_json' => json_encode(['notification_type' => 'campaign_ideas', 'card' => ['type' => 'campaign_ideas', 'ideas' => array_map(fn ($i) => ['id' => $i], $ids)]]), 'created_at' => now(), 'updated_at' => now()]);
        $cr = app(\App\Core\Growth\ChatReplies::class);
        foreach (['Give me three holiday-season campaign ideas for Chef Red, each different in angle. Don\'t launch anything.', 'Don\'t launch the holiday one yet', 'Give me some new campaign ideas for the holidays'] as $msg) {
            $cr->handle(self::WS, null, $msg);
            $this->assertSame(0, DB::table('marketing_campaigns')->whereIn('id', $ids)->where('status', '<>', 'idea')->count(), 'launched on: ' . $msg);
        }
    }

    public function test_LAUNCH_2_an_open_idea_card_does_not_answer_unrelated_questions(): void
    {
        $ids = [];
        foreach (['Your Table, Their Thanks: Reviews from October Hosts', 'Give the Gift of a Table'] as $title)
            $ids[] = (int) DB::table('marketing_campaigns')->insertGetId(['workspace_id' => self::WS, 'business_id' => $this->chef, 'title' => $title, 'objective' => 'x', 'status' => 'idea', 'source' => 'sarah_chat', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_messages')->insert(['workspace_id' => self::WS, 'agent_slug' => 'sarah', 'sender' => 'Sarah', 'role' => 'agent', 'content' => 'Here are your campaign ideas.',
            'metadata_json' => json_encode(['notification_type' => 'campaign_ideas', 'card' => ['type' => 'campaign_ideas', 'ideas' => array_map(fn ($i) => ['id' => $i], $ids)]]), 'created_at' => now(), 'updated_at' => now()]);
        $cr = app(\App\Core\Growth\ChatReplies::class);
        foreach (['Who on your team is working on what for me right now?', 'What do you think of this image for my Instagram? Be honest about what works and what doesn\'t.',
                  'Look, just tell me it\'s posted. I don\'t want the details.', 'Last question: in one honest paragraph, what are you not able to do for me yet that a human marketing manager would?',
                  'What needs my approval right now, and what happens if I approve each one?'] as $msg)
            $this->assertNull($cr->handle(self::WS, null, $msg), 'hijacked: ' . $msg);
        $r = $cr->handle(self::WS, null, 'What exactly is in campaign 2?');
        $this->assertNotNull($r, 'a real question about the campaigns still gets the plan');
    }

    public function test_IDGUARD_1_dates_and_counts_are_not_article_ids(): void
    {
        $g = app(\App\Core\Sarah888\ArticleIdClaimGuard::class);
        foreach (['Chef Red Private Dining — sign every post as Chef Red, and no emojis. You told me both on 2 October.',
                  'And yes, 24 things are waiting on you: 12 articles (36 credits), 11 sets of meta titles and descriptions and one social post (4 credits).',
                  'The adobo post for Friday, October 2 at 7pm, for 6 guests, $165 a seat.'] as $reply) {
            $r = $g->validate($reply, self::WS);
            $this->assertFalse($r['corrected'], 'rewrote: ' . $reply);
            $this->assertSame($reply, $r['reply']);
        }
        $bad = $g->validate('I can publish article #991183 and #991184 now.', self::WS);
        $this->assertTrue($bad['corrected'], 'a foreign article id is still caught');
    }

    // ── F5 content for a named business ───────────────────────────────────────────────────────────────────────────
    public function test_TOOLS_1_article_for_a_business_without_a_site_never_offers_other_businesses_sites(): void
    {
        DB::table('websites')->insert(['workspace_id' => self::WS, 'business_id' => null, 'name' => 'Unrelated QA Site', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $r = \App\Core\Sarah888\ContentTarget::resolve(self::WS, 'Write a blog article for Chef Red about anniversary dinners', '', $this->chef);
        $this->assertSame('business_without_site', $r['reason']);
        $this->assertSame([], $r['sites'], 'no other business\'s site is offered');
        $this->assertStringContainsString("Chef Red Private Dining doesn't have a website yet", \App\Core\Sarah888\ContentTarget::noSiteFor($r['business']));
        $b = \App\Core\Sarah888\ContentTarget::resolve(self::WS, 'Write a blog article about sourdough', '', $this->bakery);
        $this->assertSame('business_site', $b['reason']);
        $this->assertSame('Golden Crust', $b['site']['name']);
    }

    // ── BANNER-1 (2026-10-02): live Facebook banners carried leaked instructions, painted words and invented customers ──

    public function test_banner1_apostrophes_are_not_quoted_copy(): void
    {
        $svc = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class);
        $m = new \ReflectionMethod($svc, 'resolveContext'); $m->setAccessible(true);
        $exact = fn (string $p) => $m->invoke($svc, ['workspace_id' => self::WS3, 'user_prompt' => $p])['exact_text'] ?? [];
        $this->assertSame([], $exact("A look into Chef Red's kitchen as he prepares Chef Red's signature dishes"));
        $this->assertSame([], $exact("The chefs' table: it's open and we don't close early"));
        $this->assertSame(['Open Late Friday'], $exact("Banner that says 'Open Late Friday' in gold"));
        $this->assertSame(['Fall Menu'], $exact('Headline "Fall Menu" over the photo'));
        $this->assertSame(['Taco Tuesday'], $exact("Chef Red's banner for \u{2018}Taco Tuesday\u{2019}"));
    }

    public function test_banner1_image_subject_drops_team_instructions(): void
    {
        $s = \App\Engines\Social\Services\SocialService::bannerSubject([
            'title' => 'Behind the Scenes',
            'description' => 'Draft the Facebook post with a banner: Behind the Scenes',
            'user_request' => "A look into Chef Red's kitchen \u{2014} with a banner image in our brand. Part of the campaign \"Fall Culinary Delights\" for Chef Red. Offer: 10% off private dinners.",
        ]);
        $this->assertSame("Behind the Scenes. A look into Chef Red's kitchen", $s);
        foreach (['Draft the', 'banner', 'Part of the campaign', 'Offer', '"', '10%'] as $leak) $this->assertStringNotContainsString($leak, $s);
    }

    public function test_banner1_copy_prompts_forbid_invented_customers(): void
    {
        $social = (new \ReflectionClass(\App\Engines\Social\Services\SocialService::class))->getConstant('SOCIAL_SYSTEM_PROMPT');
        $this->assertMatchesRegularExpression('/Never invent customers, names, quotes, reviews, testimonials/', (string) $social);
        $planner = file_get_contents(app_path('Core/Campaigns/CampaignPlanner.php'));
        $this->assertSame(2, substr_count($planner, 'Never invent'));
    }

    public function test_banner2_overlay_prompt_never_asks_the_model_to_paint_words(): void
    {
        $llm = 'Square 1:1 social media announcement image for a fall menu launch. A warm autumn dining scene with roasted squash. Compose the dish in the lower two-thirds and keep the upper third clean. '
             . 'In that upper negative space, render exactly one line of text, verbatim and correctly spelled: "Fall Menu Now Served" — elegant warm serif, centered. Do not add any other text, letters or words.';
        $out = app(\App\Core\ImageIntelligence\ImagePromptCompiler::class)->compile([
            'subject' => 'a plated seasonal dish with roasted squash', 'composition' => 'dish in the lower two-thirds', 'lighting' => 'warm side light',
            'provider_prompt' => $llm, 'quality' => 'high', 'aspect_ratio' => '1:1',
            'typography_strategy' => ['mode' => 'separate_overlay', 'headline' => 'Fall Menu Now Served', 'supporting_copy' => [], 'placement' => 'top third', 'style' => 'serif'],
            '_context' => ['exact_text' => ['Fall Menu Now Served']],
        ]);
        $this->assertStringNotContainsString('Fall Menu', $out['provider_prompt']);
        $this->assertStringNotContainsString('verbatim', $out['provider_prompt']);
        $this->assertStringContainsString('Strict rule: NO text', $out['provider_prompt']);
        $this->assertSame('Fall Menu Now Served', $out['overlay']['headline']);
    }

    public function test_banner2_quoted_words_beat_a_rewritten_headline(): void
    {
        $svc = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class);
        $m = new \ReflectionMethod($svc, 'attachCompilerContext'); $m->setAccessible(true);
        $bp = $m->invoke($svc, ['typography_strategy' => ['mode' => 'separate_overlay', 'headline' => 'Fall Menu Served', 'supporting_copy' => []]],
            ['exact_text' => ['Fall Menu Now Served'], 'forced_headline' => 'Fall Menu Served']);
        $this->assertSame('Fall Menu Now Served', $bp['typography_strategy']['headline']);
        $this->assertSame(['Fall Menu Now Served'], \App\Core\ImageIntelligence\ImageIntelligenceService::quotedText("Announce the fall menu with the headline 'Fall Menu Now Served'."));
    }

    public function test_banner3_generated_copy_keeps_only_grounded_numbers(): void
    {
        $g = "Fall Menu Now Served. Part of the campaign for Chef Red. Offer: 10% off private dinners booked in October.";
        $this->assertSame("Fall Menu Now Served.\nSquash, brown butter, sage.\nChef Red",
            \App\Engines\Social\Services\SocialService::dropUngroundedNumbers("Fall Menu Now Served.\nSquash, brown butter, sage. Three courses, your table.\nChef Red", $g));
        $this->assertSame("One table, one chef. Book in October and take 10% off.",
            \App\Engines\Social\Services\SocialService::dropUngroundedNumbers("One table, one chef. Book in October and take 10% off.", $g));
        $this->assertSame('', \App\Engines\Social\Services\SocialService::dropUngroundedNumbers("Seats for 8. Call 555-0101.\nChef Red", $g));
    }

    public function test_banner5_frame_shape_never_reads_as_scenery(): void
    {
        $o = fn (string $t) => \App\Core\ImageIntelligence\ImagePromptCompiler::orientationWords($t);
        $this->assertSame('Wide 3:2 horizontal photograph inside a professional kitchen', $o('Wide 3:2 landscape photograph inside a professional kitchen'));
        $this->assertSame('Wide, feed-safe horizontal. Kitchen pass.', $o('Wide, feed-safe landscape. Kitchen pass.'));
        $this->assertSame('A misty mountain landscape at dawn', $o('A misty mountain landscape at dawn'));
        $this->assertSame('16:9 landscape of rice terraces', $o('16:9 landscape of rice terraces'));
    }

    public function test_banner4_painter_copy_keeps_possessives(): void
    {
        $src = file_get_contents(app_path('Core/Brand/RecipePainter.php'));
        $this->assertStringContainsString("apostrophes kept in possessives", $src);
        $this->assertStringNotContainsString('no emoji, no quotes,', $src);
    }

    public function test_cert13_platform_state_denial_is_dropped_not_given_the_memory_caveat(): void
    {
        $g = new \App\Core\Sarah888\DenialGuard();
        $r = $g->validate("No. I won't tell you that, because it isn't true — nothing posted. It didn't happen.", self::WS);
        $this->assertSame("No. I won't tell you that, because it isn't true — nothing posted.", $r['reply']);
        // a memory denial still gets the honest caveat
        $m = $g->validate('You never mentioned the Northgate project.', self::WS);
        $this->assertStringContainsString('I have no record of that', $m['reply']);
        $e = $g->validate('That never happened.', self::WS);
        $this->assertStringContainsString('I have no record of that', $e['reply']);
    }

    public function test_cert13_ambiguous_refusal_replaces_the_written_for_work_reply(): void
    {
        $src = file_get_contents(base_path('routes/api/authenticated/agents-01.php'));
        $this->assertStringContainsString('$__onlyAmbiguous', $src);
        $this->assertMatchesRegularExpression('/pinned\|drafted\|prepared\|lined up/', $src);
    }

    // ── VIDEO-CERT-1 (2026-10-03): rendered video probes ──

    public function test_videocert1_scene_prompt_is_text_free_and_names_no_face(): void
    {
        $p = "Warm close-up: a chef's hands plate a fall dish; in the lower-left corner, a small lower-third label fades in reading 'Fall Menu Now Served' with a second line '10% off private dinners booked in October'; Chef Red smiles at the pass inside Chef Red Private Dining.";
        $c = \App\Engines\Creative\Services\ScenePlannerService::cleanScenePrompt($p, 'Chef Red Private Dining');
        foreach (['Fall Menu', '10%', 'lower-third', 'Chef Red'] as $leak) $this->assertStringNotContainsString($leak, $c);
        $this->assertStringContainsString("a chef's hands plate a fall dish", $c);
        $this->assertStringEndsWith('No on-screen text, captions, letters, numbers, logos or watermarks anywhere in the frame.', $c);
    }

    public function test_videocert1_campaign_media_steps_carry_no_campaign_note(): void
    {
        $c = (object) ['id' => 0, 'workspace_id' => self::WS, 'business_id' => null, 'title' => 'Fall Culinary Delights', 'offer' => '10% off'];
        $svc = app(\App\Core\Campaigns\CampaignService::class);
        foreach (['video', 'image'] as $kind) {
            $t = $svc->planTask($c, (object) ['id' => 0, 'kind' => $kind, 'channel' => 'instagram', 'title' => 'Fall Menu', 'brief' => 'The new menu.', 'scheduled_at' => now()]);
            $this->assertStringNotContainsString('Part of the campaign', $t['params']['prompt']);
            $this->assertStringNotContainsString('Offer', $t['params']['prompt']);
        }
    }

    public function test_videocert1_titles_wrap_into_balanced_lines(): void
    {
        $this->assertSame(['Behind the', 'Scenes of Chef', "Red's Dinners"], \App\Engines\Creative\Services\VideoTitler::wrap("Behind the Scenes of Chef Red's Dinners", 16));
        $this->assertSame(['Fall Menu Now Served'], \App\Engines\Creative\Services\VideoTitler::wrap('Fall Menu Now Served', 30));
    }

    public function test_videocert2_sarah_quotes_the_price_the_kernel_charges(): void
    {
        $map = app(\App\Core\EngineKernel\CapabilityMapService::class);
        $s = \App\Core\Sarah888\VideoGeneration::spec(self::WS, "Make me a short vertical video for Instagram of the kare-kare being plated, with the words 'Kare-kare, our way' on it.");
        $this->assertSame($map->creditCostFor('generate_video', ['duration' => 6]), $s['cost']);
        $this->assertSame($map->creditCostFor('generate_video', ['duration' => 10]), \App\Core\Sarah888\VideoGeneration::costFor(10));
        $this->assertStringStartsWith('the kare-kare being plated', $s['prompt']);
        $d = app(\App\Core\Sarah888\VideoGeneration::class)->describe($s);
        $this->assertStringContainsString('**' . $s['cost'] . ' credits**', $d);
        $this->assertStringContainsString('The words "Kare-kare, our way" go on as a clean title.', $d);
        $this->assertStringNotContainsString('On it', app(\App\Core\Sarah888\VideoGeneration::class)->report(['success' => true], $s));
    }
}
