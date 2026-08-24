<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Engines\Marketing\Services\EmailBuilderService;
use App\Jobs\SendEmailCampaignJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EMAIL888 EM-7 PHASE 5 — the SECOND campaign path.
 *
 * EmailBuilderService had its own sendCampaign(), missed by the original
 * inventory, carrying the identical defect to MarketingService: `status => 'sent'`
 * written unconditionally after the loop. It also recorded refused recipients as
 * `bounced` — a terminal verdict from the receiving server that cannot possibly
 * be known at hand-off, which put fictional bounces into campaign history.
 *
 * This service legitimately owns CONTENT (templates, merge variables, open-pixel
 * and click tracking). It must not own TRANSPORT.
 */
class EmailBuilderConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 990200;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'must-not-be-used'], 200)]);

        $user = User::create([
            'name'     => 'em7-ebs',
            'email'    => 'em7-ebs-' . Str::random(8) . '@test.local',
            'password' => Hash::make(Str::random(32)),
            'is_admin' => 0,
        ]);

        DB::table('workspaces')->insertOrIgnore([
            'id' => self::WS, 'name' => 'em7-ebs-ws',
            'slug' => 'em7-ebs-ws-' . Str::random(6),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->templateId = (int) DB::table('email_templates')->insertGetId([
            'workspace_id' => self::WS,
            'name'         => 'EM-7 template',
            'subject'      => 'EM-7 builder campaign',
            'is_active'    => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** @param list<array{email:string,name?:string}> $recipients */
    private function campaign(array $recipients): int
    {
        return (int) DB::table('campaigns')->insertGetId([
            'workspace_id'    => self::WS,
            'name'            => 'EM-7 builder campaign',
            'type'            => 'email',
            'status'          => 'active',
            'subject'         => 'EM-7 builder campaign',
            'template_id'     => $this->templateId,
            'recipients_json' => json_encode($recipients),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('campaigns')->where('id', $id)->first();
    }

    private function svc(): EmailBuilderService
    {
        return app(EmailBuilderService::class);
    }

    // ── the converged happy path ──────────────────────────────────────

    /** @test */
    public function a_builder_campaign_creates_canonical_ledger_evidence(): void
    {
        $id = $this->campaign([
            ['email' => 'builder-one@example.com', 'name' => 'One Person'],
            ['email' => 'builder-two@example.com', 'name' => 'Two Person'],
        ]);

        $this->svc()->sendCampaign($id);

        $rows = EmailDelivery::where('purpose', 'campaign')->get();

        $this->assertCount(2, $rows, 'The second campaign path still bypasses the ledger.');

        foreach ($rows as $r) {
            $this->assertSame('broadcast', $r->stream_class,
                'Bulk mail on the transactional stream poisons password-reset reputation.');
            $this->assertSame(config('email888.streams.broadcast'), $r->provider_stream);
            $this->assertContains($r->sender_address, array_column(config('email888.senders'), 'address'));
            $this->assertStringStartsWith('cmp-', (string) $r->idempotency_key);
            $this->assertSame(self::WS, (int) $r->workspace_id);
        }

        $this->assertSame('sent', $this->row($id)->status);
    }

    /** @test */
    public function no_builder_campaign_reaches_the_provider_directly(): void
    {
        $this->svc()->sendCampaign($this->campaign([['email' => 'builder-one@example.com']]));

        $direct = collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'api.postmarkapp.com'))
            ->count();

        $this->assertSame(0, $direct);
    }

    // ── the defect that was here too ──────────────────────────────────

    /** @test */
    public function a_builder_campaign_with_no_usable_recipient_is_failed_not_sent(): void
    {
        $id = $this->campaign([['email' => 'not an address'], ['email' => '']]);

        $this->svc()->sendCampaign($id);

        $this->assertSame('failed', $this->row($id)->status,
            'Zero dispatches must never be reported as a completed send.');
        $this->assertNull($this->row($id)->sent_at);
    }

    /** @test */
    public function a_partly_usable_builder_campaign_is_partial(): void
    {
        $id = $this->campaign([
            ['email' => 'builder-one@example.com'],
            ['email' => 'not an address'],
        ]);

        $this->svc()->sendCampaign($id);

        $this->assertSame('partial', $this->row($id)->status);

        $stats = json_decode((string) $this->row($id)->stats_json, true);
        $this->assertSame(1, $stats['accepted']);
        $this->assertSame(1, $stats['refused']);
        $this->assertSame(0, $stats['delivered'], 'Acceptance is not delivery.');
        $this->assertSame('email888_delivery_ledger', $stats['delivered_source']);
    }

    /** @test */
    public function a_refused_recipient_is_never_logged_as_bounced(): void
    {
        $id = $this->campaign([['email' => 'builder-one@example.com'], ['email' => 'not an address']]);

        $this->svc()->sendCampaign($id);

        // A bounce is the receiving server's verdict. Writing one at hand-off
        // put fictional bounces into the campaign's own history.
        $this->assertSame(
            0,
            DB::table('email_campaigns_log')->where('campaign_id', $id)->where('status', 'bounced')->count(),
            'A submission refusal was recorded as a bounce.',
        );
    }

    // ── idempotency across the second path ────────────────────────────

    /** @test */
    public function re_running_a_builder_campaign_sends_nobody_twice(): void
    {
        $id = $this->campaign([
            ['email' => 'builder-one@example.com'],
            ['email' => 'builder-two@example.com'],
        ]);

        $this->svc()->sendCampaign($id);
        $after = EmailDelivery::count();

        DB::table('campaigns')->where('id', $id)->update(['status' => 'active']);
        $second = $this->svc()->sendCampaign($id);

        $this->assertSame($after, EmailDelivery::count(), 'Duplicate provider spend on re-run.');
        $this->assertSame(2, $second['duplicate']);
        $this->assertSame(0, $second['accepted']);
    }

    /**
     * @test
     *
     * The two campaign paths must share one key scheme, or the same recipient
     * could be sent twice by going through different services.
     */
    public function both_campaign_paths_agree_on_the_idempotency_key(): void
    {
        $id = $this->campaign([['email' => 'builder-one@example.com']]);

        $this->svc()->sendCampaign($id);

        $key = EmailDelivery::where('purpose', 'campaign')->value('idempotency_key');

        $expected = sprintf('cmp-%d-%d-%s', self::WS, $id,
            substr(hash('sha256', 'builder-one@example.com'), 0, 32));

        $this->assertSame($expected, $key);
    }

    // ── the queued path ───────────────────────────────────────────────

    /** @test */
    public function the_queued_campaign_job_uses_the_same_canonical_path(): void
    {
        $id = $this->campaign([
            ['email' => 'queued-one@example.com', 'name' => 'Q One'],
            ['email' => 'not an address'],
        ]);

        // Run the job body directly: this is the real handler, not a double.
        app()->call([new SendEmailCampaignJob($id), 'handle']);

        $this->assertSame(1, EmailDelivery::where('purpose', 'campaign')->count());
        $this->assertSame('partial', $this->row($id)->status);
        $this->assertSame(
            0,
            DB::table('email_campaigns_log')->where('campaign_id', $id)->where('status', 'bounced')->count(),
        );
    }

    /**
     * @test
     *
     * Retries were pinned at 1 because a re-run would have re-sent to everyone
     * the first attempt reached. That is only safe now because the dispatcher
     * claims a deterministic key per recipient before sending.
     */
    public function the_queued_job_is_now_allowed_to_retry_safely(): void
    {
        $job = new SendEmailCampaignJob(1);

        $this->assertGreaterThan(1, $job->tries, 'A transient failure should not abandon the campaign.');
        $this->assertNotEmpty($job->backoff());
    }

    /** @test */
    public function running_the_queued_job_twice_does_not_duplicate_a_send(): void
    {
        $id = $this->campaign([['email' => 'queued-one@example.com']]);

        app()->call([new SendEmailCampaignJob($id), 'handle']);
        $after = EmailDelivery::count();

        app()->call([new SendEmailCampaignJob($id), 'handle']);

        $this->assertSame($after, EmailDelivery::count(),
            'A queue retry duplicated a provider send.');
    }
}
