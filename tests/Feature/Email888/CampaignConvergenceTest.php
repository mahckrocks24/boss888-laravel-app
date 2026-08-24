<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Engines\Marketing\Services\MarketingService;
use App\Engines\Marketing\Support\CampaignPlanner;
use App\Jobs\SendEmailCampaignJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EMAIL888 EM-7 - the marketing campaign path, end to end.
 *
 * Originally written RED against a sendCampaign() that called a method
 * EmailConnector does not have: every recipient raised an Error, the loop's
 * catch swallowed it, and the campaign was marked 'sent' having delivered
 * nothing. Those assertions still stand below - they now run against the queued
 * worker rather than the request, because PHASE 12 moved the fan-out off the
 * HTTP path.
 *
 * The distinction this file exists to keep visible:
 *   request accepted  !=  queued
 *   queued            !=  dispatched
 *   dispatched        !=  delivered
 *
 * Http::fake() guards the PROVIDER boundary only. Nothing here may reach a real
 * provider; if anything does, a test says so.
 */
class CampaignConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 990100;

    /** @var list<string> */
    private array $recipients = [
        'campaign-one@example.com',
        'campaign-two@example.com',
        'campaign-three@example.com',
    ];

    private int $mailEvents = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.postmarkapp.com/*' => Http::response(
            ['MessageID' => 'fake-must-not-be-used', 'SubmittedAt' => now()->toIso8601String()],
            200,
        )]);

        Event::listen(MessageSending::class, function () { $this->mailEvents++; });

        // The queue is faked for EVERY test here, deliberately.
        //
        // Without it the suite's `sync` driver runs the fan-out inside
        // sendCampaign(), so the request path LOOKS like it is still doing the
        // work - and a later explicit run of the job becomes a SECOND pass whose
        // recipients all come back `duplicate`. That is idempotency behaving
        // correctly, but it makes every assertion about the request path
        // meaningless. Faking separates "what the request does" from "what the
        // worker does" whatever the driver happens to be configured as.
        Queue::fake();

        $user = User::create([
            'name'     => 'em7-campaign',
            'email'    => 'em7-campaign-' . Str::random(8) . '@test.local',
            'password' => Hash::make(Str::random(32)),
            'is_admin' => 0,
        ]);

        // `id` is not mass-assignable, so the model would silently discard it.
        DB::table('workspaces')->insertOrIgnore([
            'id'         => self::WS,
            'name'       => 'em7-ws',
            'slug'       => 'em7-ws-' . Str::random(6),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(array $overrides = []): int
    {
        return (int) DB::table('campaigns')->insertGetId(array_merge([
            'workspace_id'    => self::WS,
            'name'            => 'EM-7 convergence campaign',
            'type'            => 'email',
            'status'          => 'active',
            'subject'         => 'EM-7 convergence',
            'body_html'       => '<p>Hello {{first_name}}</p>',
            'recipients_json' => json_encode($this->recipients),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    private function accept(int $id): array
    {
        return app(MarketingService::class)->sendCampaign(self::WS, $id);
    }

    /** Run the queued work in-process. The real handler, not a double. */
    private function work(int $id): void
    {
        app()->call([new SendEmailCampaignJob($id), 'handle']);
    }

    private function row(int $id): object
    {
        return DB::table('campaigns')->where('id', $id)->first();
    }

    private function directProviderCalls(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'api.postmarkapp.com'))
            ->count();
    }

    // -- PHASE 12: the request accepts, it does not send ------------------

    /** @test */
    public function the_request_returns_an_acknowledgement_and_never_claims_a_send(): void
    {
        $id = $this->campaign();
        $r  = $this->accept($id);

        $this->assertTrue($r['accepted']);
        $this->assertSame('pending', $r['status']);
        $this->assertSame(3, $r['queued_recipients']);

        // The legacy keys must be truthful at this instant, not optimistic.
        $this->assertSame(0, $r['sent'], 'The request reported a send it had not performed.');
        $this->assertSame(0, $r['failed']);

        // 'pending' is queued-but-not-started, and is distinct from 'sending'.
        $this->assertSame('pending', $this->row($id)->status);
    }

    /** @test */
    public function the_request_performs_no_provider_fan_out_at_all(): void
    {
        $before = EmailDelivery::count();
        $this->accept($this->campaign());

        $this->assertSame($before, EmailDelivery::count(),
            'The HTTP path dispatched to the provider instead of queueing.');
        $this->assertSame(0, $this->mailEvents);
        $this->assertSame(0, $this->directProviderCalls());
    }

    /** @test */
    public function the_request_queues_exactly_one_fan_out_job(): void
    {
        $id = $this->campaign();
        $this->accept($id);

        Queue::assertPushed(SendEmailCampaignJob::class, 1);
        Queue::assertPushed(SendEmailCampaignJob::class,
            fn (SendEmailCampaignJob $j) => $j->campaignId === $id);
    }

    /** @test */
    public function a_duplicate_submit_does_not_queue_a_second_fan_out(): void
    {
        $id = $this->campaign();
        $this->accept($id);
        $second = $this->accept($id);

        Queue::assertPushed(SendEmailCampaignJob::class, 1);

        $this->assertTrue($second['accepted']);
        $this->assertTrue($second['already_in_flight']);
        $this->assertSame(0, $second['queued_recipients']);
    }

    // -- PHASE 9: nothing is queued when nothing can be planned -----------

    /** @test */
    public function a_campaign_with_no_recipients_queues_nothing_and_stays_out_of_flight(): void
    {
        $id = $this->campaign(['recipients_json' => json_encode([])]);

        try {
            $this->accept($id);
            $this->fail('An unplannable campaign was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No recipients', $e->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertSame('active', $this->row($id)->status,
            'A campaign that was never queued must not be left looking in-flight.');
    }

    // -- PHASE 13: the request stops scaling with the audience ------------

    /** @test */
    public function request_work_does_not_scale_with_recipient_count(): void
    {
        $count = function (int $n): int {
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $ids[] = "perf-{$i}@example.com";
            }
            $id = $this->campaign(['recipients_json' => json_encode($ids)]);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->accept($id);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };

        // The first measurement is discarded: cold-path work unrelated to
        // audience size (a first-time usage row is inserted rather than
        // updated) shifts it by one query, which would make an exact
        // comparison brittle for a reason that has nothing to do with scaling.
        $count(10);

        $hundred  = $count(100);
        $thousand = $count(1000);

        // 10x the audience, IDENTICAL query count. The planner resolves the
        // whole audience in one query and the request never touches the
        // provider. Linear behaviour here would be ~1000 queries.
        $this->assertSame($hundred, $thousand,
            "100 -> {$hundred} queries, 1000 -> {$thousand}");

        $this->assertLessThan(15, $thousand,
            'The request path should do a handful of queries, not work per recipient.');
    }

    // -- the worker does the real work, and tells the truth about it ------

    /** @test */
    public function the_queued_work_produces_canonical_ledger_evidence(): void
    {
        $id = $this->campaign();
        $this->accept($id);
        $this->work($id);

        $rows = EmailDelivery::where('purpose', 'campaign')->get();

        $this->assertCount(3, $rows);

        foreach ($rows as $r) {
            $this->assertSame('broadcast', $r->stream_class,
                'Bulk mail on the transactional stream poisons password-reset reputation.');
            $this->assertSame(config('email888.streams.broadcast'), $r->provider_stream);
            $this->assertContains($r->sender_address, array_column(config('email888.senders'), 'address'));
            $this->assertStringNotContainsString('noreply', (string) $r->sender_address);
            $this->assertStringStartsWith('cmp-', (string) $r->idempotency_key);
        }

        $this->assertSame(0, $this->directProviderCalls(),
            'A business service posted straight to the provider.');
    }

    /** @test */
    public function merge_tags_are_rendered_before_the_message_is_dispatched(): void
    {
        DB::table('leads')->insert([
            'workspace_id' => self::WS,
            'email'        => 'campaign-one@example.com',
            'name'         => 'Ada Lovelace',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $plan = app(CampaignPlanner::class)->plan($this->campaign(), self::WS);

        $first = $plan->recipients[0];

        $this->assertSame('campaign-one@example.com', $first->address);
        $this->assertStringContainsString('Ada', (string) $first->html,
            'Personalisation was lost when rendering moved into the planner.');
        $this->assertStringNotContainsString('{{first_name}}', (string) $first->html);
    }

    /** @test */
    public function a_campaign_is_never_marked_sent_when_nothing_was_dispatched(): void
    {
        $id = $this->campaign(['recipients_json' => json_encode(['not an address', 'also bad'])]);

        $this->accept($id);
        $this->work($id);

        $this->assertSame('failed', $this->row($id)->status,
            'Zero messages were dispatched and the campaign still reported success.');
        $this->assertNull($this->row($id)->sent_at);
    }

    /** @test */
    public function one_unusable_recipient_makes_the_campaign_partial(): void
    {
        $id = $this->campaign(['recipients_json' => json_encode([
            'good-one@example.com', 'this is not an address', 'good-two@example.com',
        ])]);

        $this->accept($id);
        $this->work($id);

        $this->assertSame('partial', $this->row($id)->status);

        $stats = json_decode((string) $this->row($id)->stats_json, true);
        $this->assertSame(2, $stats['accepted']);
        $this->assertSame(1, $stats['refused']);
        $this->assertSame('this is not an address', $stats['failures'][0]['recipient'],
            'The failure must be named, not merely counted.');
    }

    /** @test */
    public function a_fully_dispatched_campaign_reports_sent_but_delivers_nothing_yet(): void
    {
        $id = $this->campaign();
        $this->accept($id);
        $this->work($id);

        $this->assertSame('sent', $this->row($id)->status);

        $stats = json_decode((string) $this->row($id)->stats_json, true);

        $this->assertSame(3, $stats['sent']);
        $this->assertSame(0, $stats['delivered'],
            'Acceptance is not delivery. Only a provider event may establish that.');
        $this->assertSame('email888_delivery_ledger', $stats['delivered_source']);
    }

    // -- PHASE 7/12: retries cannot duplicate mail ------------------------

    /** @test */
    public function running_the_queued_work_twice_sends_nobody_twice(): void
    {
        $id = $this->campaign();
        $this->accept($id);

        $this->work($id);
        $afterFirst = EmailDelivery::count();

        // Exactly what a worker crash-and-retry looks like.
        $this->work($id);

        $this->assertSame($afterFirst, EmailDelivery::count(),
            'A retry duplicated provider spend and put a second copy in real inboxes.');
        $this->assertSame('sent', $this->row($id)->status);
    }

    /** @test */
    public function the_retry_policy_is_explicit_and_bounded(): void
    {
        $job = new SendEmailCampaignJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    /** @test */
    public function the_idempotency_key_is_deterministic_and_hides_the_address(): void
    {
        $id = $this->campaign();
        $this->accept($id);
        $this->work($id);

        $expected = sprintf('cmp-%d-%d-%s', self::WS, $id,
            substr(hash('sha256', 'campaign-one@example.com'), 0, 32));

        $this->assertSame(
            $expected,
            EmailDelivery::where('recipient_address', 'campaign-one@example.com')->value('idempotency_key'),
        );
    }
}
