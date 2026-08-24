<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdEventRecorder;
use App\Engines\Ads\Services\AdInvalidTrafficFilter;
use App\Engines\Ads\Services\AdStatsRollupService;
use App\Engines\Ads\Services\AdTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0 — the measurement chain: tokens → filtering → events → rollup.
 *
 * These tests defend the numbers an advertiser is billed from.
 */
class AdMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private AdTokenService $tokens;
    private AdEventRecorder $recorder;
    private AdInvalidTrafficFilter $filter;
    private AdStatsRollupService $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens   = app(AdTokenService::class);
        $this->recorder = app(AdEventRecorder::class);
        $this->filter   = app(AdInvalidTrafficFilter::class);
        $this->rollup   = app(AdStatsRollupService::class);
    }

    // ── Tokens ──────────────────────────────────────────────────────────

    public function test_token_round_trips(): void
    {
        $issued = $this->tokens->issue(10, 20, 30, 40);
        $result = $this->tokens->verify($issued['token'], 'impression');

        $this->assertTrue($result['valid']);
        $this->assertSame(10, $result['claims']['cr']);
        $this->assertSame(20, $result['claims']['ca']);
        $this->assertSame(30, $result['claims']['w']);
        $this->assertSame(40, $result['claims']['s']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $issued = $this->tokens->issue(10, 20, 30, 40);
        [$payload, $sig] = explode('.', $issued['token']);

        // Re-sign a payload claiming a different creative.
        $forgedPayload = rtrim(strtr(base64_encode(json_encode([
            'cr' => 999, 'ca' => 20, 'w' => 30, 's' => 40,
            'n' => $issued['nonce'], 'exp' => $issued['expires_at'],
        ])), '+/', '-_'), '=');

        $result = $this->tokens->verify($forgedPayload . '.' . $sig, 'impression');

        $this->assertFalse($result['valid']);
        $this->assertSame('bad_signature', $result['reason']);
    }

    public function test_malformed_token_is_rejected(): void
    {
        foreach (['', 'garbage', 'a.b.c'] as $bad) {
            $this->assertFalse($this->tokens->verify($bad, 'impression')['valid']);
        }
    }

    /** One decision must not yield ten billable impressions. */
    public function test_token_cannot_be_replayed_for_a_billable_event(): void
    {
        $issued = $this->tokens->issue(1, 2, 3, 4);

        $this->assertTrue($this->tokens->verify($issued['token'], 'impression')['valid']);

        $second = $this->tokens->verify($issued['token'], 'impression');

        $this->assertFalse($second['valid']);
        $this->assertSame('replayed', $second['reason']);
    }

    public function test_expired_token_is_rejected(): void
    {
        $issued = $this->tokens->issue(1, 2, 3, 4);

        $this->travel(AdTokenService::TTL_SECONDS + 10)->seconds();

        $result = $this->tokens->verify($issued['token'], 'impression');

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
    }

    // ── Invalid traffic ─────────────────────────────────────────────────

    /** @dataProvider crawlerAgents */
    public function test_crawlers_are_flagged(string $ua): void
    {
        $verdict = $this->filter->classify(['user_agent' => $ua, 'ip' => '203.0.113.7']);

        $this->assertTrue($verdict['invalid'], "Should flag: {$ua}");
        $this->assertSame(AdInvalidTrafficFilter::REASON_CRAWLER, $verdict['reason']);
    }

    public static function crawlerAgents(): array
    {
        return [
            'GPTBot'      => ['Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
            'ClaudeBot'   => ['Mozilla/5.0 (compatible; ClaudeBot/1.0)'],
            'Perplexity'  => ['Mozilla/5.0 (compatible; PerplexityBot/1.0)'],
            'Bingbot'     => ['Mozilla/5.0 (compatible; bingbot/2.0)'],
            'AhrefsBot'   => ['Mozilla/5.0 (compatible; AhrefsBot/7.0)'],
            'curl'        => ['curl/8.4.0'],
            'python'      => ['python-requests/2.31.0'],
            'headless'    => ['Mozilla/5.0 HeadlessChrome/120.0.0.0'],
        ];
    }

    public function test_real_browser_is_not_flagged(): void
    {
        $verdict = $this->filter->classify([
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1',
            'ip'         => '203.0.113.7',
        ]);

        $this->assertFalse($verdict['invalid']);
    }

    public function test_empty_user_agent_is_flagged(): void
    {
        $verdict = $this->filter->classify(['user_agent' => '', 'ip' => '203.0.113.7']);

        $this->assertTrue($verdict['invalid']);
        $this->assertSame(AdInvalidTrafficFilter::REASON_NO_UA, $verdict['reason']);
    }

    public function test_datacenter_ip_is_flagged(): void
    {
        $verdict = $this->filter->classify([
            'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15',
            'ip'         => '134.209.93.41',
        ]);

        $this->assertTrue($verdict['invalid']);
        $this->assertSame(AdInvalidTrafficFilter::REASON_DATACENTER, $verdict['reason']);
    }

    public function test_impossible_timing_is_flagged(): void
    {
        $verdict = $this->filter->classify([
            'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15',
            'ip'         => '203.0.113.7',
            'dwell_ms'   => 5,
        ]);

        $this->assertTrue($verdict['invalid']);
        $this->assertSame(AdInvalidTrafficFilter::REASON_TIMING, $verdict['reason']);
    }

    // ── Event recording ─────────────────────────────────────────────────

    public function test_valid_event_is_recorded_as_valid(): void
    {
        $result = $this->recorder->record(
            AdEventRecorder::EVENT_IMPRESSION, 3, 2, 10, 20, 40,
            ['user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15', 'ip' => '203.0.113.9', 'country' => 'AE', 'device' => 'mobile']
        );

        $this->assertTrue($result['recorded']);
        $this->assertFalse($result['invalid']);

        $row = DB::table('ad_events')->latest('id')->first();
        $this->assertFalse((bool) $row->is_invalid);
        $this->assertSame('AE', $row->visitor_country);
        $this->assertSame('mobile', $row->device);
    }

    /** Filtered traffic is RECORDED with a reason, never dropped. */
    public function test_invalid_event_is_recorded_not_dropped(): void
    {
        $result = $this->recorder->record(
            AdEventRecorder::EVENT_IMPRESSION, 3, 2, 10, 20, 40,
            ['user_agent' => 'GPTBot/1.0', 'ip' => '203.0.113.9']
        );

        $this->assertTrue($result['recorded'], 'Invalid traffic must still produce a row');
        $this->assertTrue($result['invalid']);

        $row = DB::table('ad_events')->latest('id')->first();
        $this->assertTrue((bool) $row->is_invalid);
        $this->assertSame(AdInvalidTrafficFilter::REASON_CRAWLER, $row->invalid_reason);
    }

    /**
     * The privacy boundary: the same IP must hash differently on a different
     * day, so events cannot be joined across days to reconstruct a person.
     */
    public function test_ip_hash_rotates_daily(): void
    {
        $today = $this->recorder->ipHash('203.0.113.9');

        $this->travel(2)->days();

        $later = $this->recorder->ipHash('203.0.113.9');

        $this->assertNotSame($today, $later, 'ip_hash MUST rotate daily — this is what keeps ToS clause 9 true');
    }

    public function test_no_ip_yields_no_hash(): void
    {
        $this->assertNull($this->recorder->ipHash(null));
        $this->assertNull($this->recorder->ipHash(''));
    }

    public function test_frequency_counts_only_valid_impressions(): void
    {
        $signals = ['user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15', 'ip' => '203.0.113.9'];

        $this->recorder->record(AdEventRecorder::EVENT_IMPRESSION, 3, 2, 10, 20, 40, $signals);
        $this->recorder->record(AdEventRecorder::EVENT_IMPRESSION, 3, 2, 10, 20, 40, ['user_agent' => 'GPTBot/1.0', 'ip' => '203.0.113.9']);

        $count = (int) DB::table('ad_frequency')->where('campaign_id', 20)->value('count');

        $this->assertSame(1, $count, 'A bot must not consume a real visitor\'s frequency cap');
    }

    // ── Rollup ──────────────────────────────────────────────────────────

    private function seedEvents(): int
    {
        $advertiserId = (int) DB::table('advertisers')->insertGetId([
            'name' => 'Adv', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $campaignId = (int) DB::table('ad_campaigns')->insertGetId([
            'advertiser_id' => $advertiserId, 'name' => 'C', 'kind' => 'paid', 'status' => 'active',
            'pricing_model' => 'cpm', 'rate_micros' => 2_000_000,   // $2.00 CPM
            'priority_tier' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Every row MUST carry an identical key set: a batch insert builds one
        // multi-VALUES statement from the first row's columns, so a row with an
        // extra key fails with "column count doesn't match value count".
        $base = [
            'campaign_id' => $campaignId, 'creative_id' => 7, 'website_id' => 3,
            'workspace_id' => 2, 'slot_id' => 1, 'invalid_reason' => null,
            'occurred_at' => now()->subDay(),
        ];

        $rows = [];
        // 1000 viewable → $2.00 at a $2 CPM
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['event' => 'viewable', 'is_invalid' => false] + $base;
        }
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['event' => 'impression', 'is_invalid' => true, 'invalid_reason' => 'crawler'] + $base;
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('ad_events')->insert($chunk);
        }

        return $campaignId;
    }

    public function test_rollup_computes_revenue_on_viewable_impressions(): void
    {
        $this->seedEvents();

        $result = $this->rollup->rollup(now()->subDay()->toDateString());

        $this->assertSame(1000, $result['viewable']);
        $this->assertSame(50, $result['invalid']);
        // $2.00 CPM over 1000 viewable = $2.00 = 2_000_000 micros
        $this->assertSame(2_000_000, $result['revenue_micros']);
    }

    public function test_invalid_traffic_is_excluded_from_revenue(): void
    {
        $this->seedEvents();
        $date = now()->subDay()->toDateString();

        $this->rollup->rollup($date);

        $row = DB::table('ad_stats_daily')->whereDate('stat_date', $date)->first();

        $this->assertSame(50, (int) $row->invalid_impressions);
        $this->assertSame(0, (int) $row->impressions, 'Invalid events must not count as billable impressions');
    }

    /** You WILL re-run a date. It has to be safe by construction. */
    public function test_rollup_is_idempotent(): void
    {
        $this->seedEvents();
        $date = now()->subDay()->toDateString();

        $first  = $this->rollup->rollup($date);
        $second = $this->rollup->rollup($date);

        $this->assertSame($first['revenue_micros'], $second['revenue_micros']);
        $this->assertSame($first['rows'], $second['rows']);
        $this->assertSame(
            $first['rows'],
            DB::table('ad_stats_daily')->whereDate('stat_date', $date)->count(),
            'Re-running must rewrite, not append'
        );
    }

    public function test_reconciliation_balances(): void
    {
        $this->seedEvents();
        $date = now()->subDay()->toDateString();

        $this->rollup->rollup($date);

        $this->assertTrue($this->rollup->reconcile($date)['balanced']);
    }

    public function test_reconciliation_detects_a_mismatch(): void
    {
        $this->seedEvents();
        $date = now()->subDay()->toDateString();

        $this->rollup->rollup($date);
        DB::table('ad_stats_daily')->whereDate('stat_date', $date)->update(['viewable_impressions' => 1]);

        $result = $this->rollup->reconcile($date);

        $this->assertFalse($result['balanced']);
        $this->assertArrayHasKey('viewable', $result['mismatches']);
    }

    public function test_prune_removes_events_past_retention(): void
    {
        DB::table('ad_events')->insert([
            'event' => 'impression', 'website_id' => 3, 'workspace_id' => 2,
            'is_invalid' => false, 'occurred_at' => now()->subDays(200),
        ]);

        $this->assertSame(1, $this->rollup->prune());
    }
}
