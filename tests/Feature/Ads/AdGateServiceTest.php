<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdGateService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0 — AdGateService.
 *
 * This is the class whose failure mode is "an advertisement appeared on a paying
 * customer's website". Every test here is a safety test.
 */
class AdGateServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdGateService $gate;
    private AdSettingsService $settings;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate     = app(AdGateService::class);
        $this->settings = app(AdSettingsService::class);
        $this->settings->flush();
    }

    private function seedSite(array $workspace = [], array $website = []): array
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "gate-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId(array_merge([
            'name' => "WS{$n}", 'slug' => "gate-ws-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'created_at' => now()->subDays(60), 'updated_at' => now(),
        ], $workspace));

        $siteId = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $wsId, 'name' => "Site{$n}",
            'subdomain' => "gate-{$n}.levelupgrowth.io", 'status' => 'published',
            'created_at' => now()->subDays(60), 'updated_at' => now(),
        ], $website));

        return [$wsId, $siteId];
    }

    /** The default posture: the platform is OFF until someone turns it on. */
    public function test_master_switch_ships_false_and_blocks_everything(): void
    {
        [, $siteId] = $this->seedSite();

        $this->assertFalse($this->settings->bool(AdSettings::MASTER_ENABLED));

        $result = $this->gate->evaluate($siteId);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AdGateService::DENY_MASTER_OFF, $result['reason']);
    }

    public function test_free_workspace_with_no_subscription_row_is_allowed(): void
    {
        // The trap: a MISSING subscriptions row means FREE. A raw SQL join
        // would exempt this workspace from ads entirely.
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [$wsId, $siteId] = $this->seedSite();

        $this->assertSame(0, DB::table('subscriptions')->where('workspace_id', $wsId)->count());

        $result = $this->gate->evaluate($siteId);

        $this->assertTrue($result['allowed'], 'A workspace with no subscription row is FREE and must show ads');
        $this->assertSame('free', $result['plan_slug']);
    }

    public function test_paid_workspace_is_blocked(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [$wsId, $siteId] = $this->seedSite();

        $proPlanId = DB::table('plans')->where('slug', 'pro')->value('id')
            ?? DB::table('plans')->insertGetId([
                'name' => 'Pro', 'slug' => 'pro', 'price' => 199, 'billing_period' => 'monthly',
                'features_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('subscriptions')->insert([
            'workspace_id' => $wsId, 'plan_id' => $proPlanId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->gate->forgetWorkspace($wsId);

        $result = $this->gate->evaluate($siteId);

        $this->assertFalse($result['allowed'], 'A paying customer must NEVER see ads');
        $this->assertSame(AdGateService::DENY_PLAN_NOT_ELIGIBLE, $result['reason']);
    }

    public function test_exempt_workspace_is_blocked(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [$wsId, $siteId] = $this->seedSite();
        $this->settings->set(AdSettings::EXEMPT_WORKSPACE_IDS, [1, $wsId]);

        $result = $this->gate->evaluate($siteId);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AdGateService::DENY_EXEMPT_WORKSPACE, $result['reason']);
    }

    public function test_unpublished_site_is_blocked(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [, $siteId] = $this->seedSite([], ['status' => 'draft']);

        $result = $this->gate->evaluate($siteId);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AdGateService::DENY_NOT_PUBLISHED, $result['reason']);
    }

    public function test_missing_site_is_blocked(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);

        $result = $this->gate->evaluate(999999);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AdGateService::DENY_SITE_NOT_FOUND, $result['reason']);
    }

    public function test_force_off_override_blocks(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [, $siteId] = $this->seedSite();

        DB::table('ad_site_overrides')->insert([
            'website_id' => $siteId, 'slot_id' => null, 'mode' => 'force_off',
            'reason' => 'brand safety test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->gate->evaluate($siteId);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AdGateService::DENY_FORCE_OFF, $result['reason']);
    }

    public function test_house_only_override_allows_but_flags_house_only(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [, $siteId] = $this->seedSite();

        DB::table('ad_site_overrides')->insert([
            'website_id' => $siteId, 'slot_id' => null, 'mode' => 'house_only',
            'reason' => 'unreviewed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->gate->evaluate($siteId);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['house_only']);
    }

    /**
     * A brand-new AI-generated site must not carry a paying advertiser's brand
     * before a human has looked at it.
     */
    public function test_new_site_is_house_only(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [, $siteId] = $this->seedSite([], ['created_at' => now()->subDay()]);

        $result = $this->gate->evaluate($siteId);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['house_only'], 'Sites younger than the hold window are house-only');
    }

    public function test_eligible_plan_list_is_configurable(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        [, $siteId] = $this->seedSite();

        $this->settings->set(AdSettings::ELIGIBLE_PLAN_SLUGS, ['starter']);

        $this->assertFalse($this->gate->evaluate($siteId)['allowed'], 'free is no longer eligible');

        $this->settings->set(AdSettings::ELIGIBLE_PLAN_SLUGS, ['free', 'starter']);

        $this->assertTrue($this->gate->evaluate($siteId)['allowed']);
    }
}
