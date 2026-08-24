<?php

namespace Tests\Feature\Ads;

use App\Http\Controllers\Api\Admin\AdminAdsCampaignController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ADS888 admin API — phase A3, campaign management.
 *
 * The rules under test are the ones the UI cannot be trusted to enforce,
 * because a UI can be bypassed by anyone with a terminal.
 */
class AdminAdsCampaignTest extends TestCase
{
    use RefreshDatabase;

    private AdminAdsCampaignController $c;
    private int $slotId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c = app(AdminAdsCampaignController::class);

        $this->slotId = (int) DB::table('ad_slots')->insertGetId([
            'code' => 'interstitial_modal', 'name' => 'Interstitial', 'format' => 'modal',
            'width' => 1080, 'height' => 1080, 'position' => 'modal', 'device' => 'all',
            'is_active' => false, 'is_interstitial' => true,
            'allowed_ratios' => '["1:1","3:4"]',
            'sort_order' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function advertiser(array $o = []): int
    {
        return (int) DB::table('advertisers')->insertGetId(array_merge([
            'name' => 'Adv ' . uniqid(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function campaign(array $o = []): int
    {
        return (int) DB::table('ad_campaigns')->insertGetId(array_merge([
            'advertiser_id' => $this->advertiser(), 'name' => 'C', 'kind' => 'paid',
            'status' => 'draft', 'pricing_model' => 'cpm', 'rate_micros' => 1000000,
            'priority_tier' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    // ── Route wiring ────────────────────────────────────────────────────

    public function test_every_ads_admin_route_is_gated(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/ads'));

        $this->assertNotEmpty($routes, 'ads admin routes should be registered');

        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            $this->assertContains('auth.jwt', $mw, $r->uri() . ' missing auth.jwt');
            $this->assertContains('admin', $mw, $r->uri() . ' missing the admin gate');
        }
    }

    /** Approval is the action that puts third-party content on customer sites. */
    public function test_creative_approval_requires_mfa_step_up(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'creatives/{id}/approve')
                             || str_contains($r->uri(), 'creatives/{id}/reject'));

        $this->assertCount(2, $routes);

        foreach ($routes as $r) {
            $this->assertContains('mfa.stepup', $r->gatherMiddleware(),
                $r->uri() . ' must require MFA step-up');
        }
    }

    /** Upload must NOT require step-up — only the approval that lets it serve. */
    public function test_upload_does_not_require_step_up(): void
    {
        $upload = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/ads/creatives' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($upload);
        $this->assertNotContains('mfa.stepup', $upload->gatherMiddleware());
    }

    // ── Advertisers ─────────────────────────────────────────────────────

    public function test_blocked_category_cannot_be_onboarded(): void
    {
        $r = $this->c->createAdvertiser(new Request([
            'name' => 'Casino Co', 'category' => 'gambling',
        ]));

        $this->assertSame(422, $r->getStatusCode());
        $this->assertStringContainsString('blocklist', $r->getData(true)['error']);
        $this->assertDatabaseMissing('advertisers', ['name' => 'Casino Co']);
    }

    public function test_advertiser_is_created_and_audited(): void
    {
        $r = $this->c->createAdvertiser(new Request([
            'name' => 'Acme', 'category' => 'retail', 'contact_email' => 'a@acme.test',
        ]));

        $this->assertSame(201, $r->getStatusCode());
        $this->assertDatabaseHas('advertisers', ['name' => 'Acme', 'status' => 'active']);
        $this->assertDatabaseHas('ad_audit_log', ['action' => 'advertiser.create']);
    }

    // ── Campaigns ───────────────────────────────────────────────────────

    public function test_campaign_is_created_as_draft_never_live(): void
    {
        $r = $this->c->createCampaign(new Request([
            'advertiser_id' => $this->advertiser(), 'name' => 'Q3', 'kind' => 'paid',
            'pricing_model' => 'cpm', 'rate_micros' => 2000000,
            'budget_total_micros' => 50000000,
        ]));

        $this->assertSame(201, $r->getStatusCode());
        $this->assertSame('draft', $r->getData(true)['status'],
            'a new campaign must never be created live');
    }

    public function test_house_campaign_is_forced_uncapped(): void
    {
        $r = $this->c->createCampaign(new Request([
            'advertiser_id' => $this->advertiser(), 'name' => 'House', 'kind' => 'house',
            'pricing_model' => 'flat', 'rate_micros' => 0,
            'budget_total_micros' => 999,     // should be ignored
        ]));

        $row = DB::table('ad_campaigns')->find($r->getData(true)['id']);

        $this->assertNull($row->budget_total_micros, 'house campaigns guarantee fill, so must be uncapped');
        $this->assertSame(5, (int) $row->priority_tier, 'house sits below paid');
    }

    public function test_targeting_rules_are_stored(): void
    {
        $r = $this->c->createCampaign(new Request([
            'advertiser_id' => $this->advertiser(), 'name' => 'T', 'kind' => 'paid',
            'pricing_model' => 'cpm', 'rate_micros' => 1000000,
            'targeting' => [
                ['dimension' => 'industry', 'values' => ['dental']],
                ['dimension' => 'business_country', 'values' => ['AE']],
            ],
        ]));

        $id = $r->getData(true)['id'];

        $this->assertDatabaseCount('ad_targeting', 2);

        $t = $this->c->campaignTargeting($id)->getData(true);
        $this->assertCount(2, $t['targeting']);

        // Assert on the SET — row order from the DB is not guaranteed and is
        // not something the product depends on.
        $byDimension = collect($t['targeting'])->keyBy('dimension');
        $this->assertSame(['dental'], $byDimension['industry']['values']);
        $this->assertSame(['AE'], $byDimension['business_country']['values']);
    }

    /**
     * A campaign that cannot serve must not be allowed to sit "active"
     * delivering nothing while nobody understands why.
     */
    public function test_cannot_activate_a_campaign_with_no_approved_creative(): void
    {
        $id = $this->campaign();

        $r = $this->c->updateCampaign(new Request(['status' => 'active']), $id);

        $this->assertSame(422, $r->getStatusCode());
        $this->assertStringContainsString('no APPROVED creative', $r->getData(true)['error']);
        $this->assertSame('draft', DB::table('ad_campaigns')->find($id)->status);
    }

    public function test_can_activate_once_a_creative_is_approved(): void
    {
        $id = $this->campaign();
        DB::table('ad_creatives')->insert([
            'campaign_id' => $id, 'slot_id' => $this->slotId, 'type' => 'image',
            'asset_url' => '/x.jpg', 'click_url' => 'https://e.test', 'weight' => 100,
            'review_state' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->c->updateCampaign(new Request(['status' => 'active']), $id);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('active', DB::table('ad_campaigns')->find($id)->status);
    }

    // ── Creative review ─────────────────────────────────────────────────

    public function test_rejection_requires_a_note(): void
    {
        $id = (int) DB::table('ad_creatives')->insertGetId([
            'campaign_id' => $this->campaign(), 'slot_id' => $this->slotId, 'type' => 'image',
            'asset_url' => '/x.jpg', 'click_url' => 'https://e.test', 'weight' => 100,
            'review_state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->c->rejectCreative(new Request([]), $id);
    }

    public function test_approval_is_recorded_with_reviewer_and_time(): void
    {
        $id = (int) DB::table('ad_creatives')->insertGetId([
            'campaign_id' => $this->campaign(), 'slot_id' => $this->slotId, 'type' => 'image',
            'asset_url' => '/x.jpg', 'click_url' => 'https://e.test', 'weight' => 100,
            'review_state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->c->approveCreative(new Request(['note' => 'checked']), $id);

        $row = DB::table('ad_creatives')->find($id);

        $this->assertSame('approved', $row->review_state);
        $this->assertNotNull($row->reviewed_at);
        $this->assertDatabaseHas('ad_audit_log', ['action' => 'creative.approved']);
    }

    public function test_review_404s_for_a_missing_creative(): void
    {
        $this->assertSame(404, $this->c->approveCreative(new Request(['note' => 'x']), 999999)->getStatusCode());
    }

    // ── Reach ───────────────────────────────────────────────────────────

    public function test_reach_refuses_to_quote_thin_inventory(): void
    {
        $r = $this->c->reach(new Request([
            'targeting' => [['dimension' => 'industry', 'values' => ['dental']]],
        ]));

        $d = $r->getData(true);

        $this->assertFalse($d['quotable']);
        $this->assertNotNull($d['quote_refusal_reason']);
    }

    public function test_targeting_vocabulary_is_exposed_for_the_builder(): void
    {
        $d = $this->c->campaignTargeting($this->campaign())->getData(true);

        $this->assertContains('industry', $d['available_dimensions']);
        $this->assertArrayHasKey('dental', $d['industries']);
        $this->assertArrayHasKey('medical', $d['archetypes']);
    }
}
