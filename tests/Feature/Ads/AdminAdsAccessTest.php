<?php

namespace Tests\Feature\Ads;

use App\Http\Controllers\Api\Admin\AdminAdsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ADS888 admin API — access control and payload shape.
 *
 * THE REASON THIS TEST EXISTS
 * INFRA888 shipped its Operations tab hidden in the UI but open on the API. A
 * hidden nav item is not a permission. Every ads admin route must be behind
 * ['auth.jwt','admin'] in the route table itself, and this asserts it rather
 * than trusting the file to stay correct.
 */
class AdminAdsAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Every ads admin route must carry BOTH middleware. */
    public function test_every_admin_ads_route_is_behind_auth_and_admin(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/ads'));

        if ($routes->isEmpty()) {
            $this->markTestSkipped(
                'Ads admin routes are not registered yet — the require line into the '
                . 'governed routes/api.php has not landed. This test activates the moment it does.'
            );
        }

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth.jwt', $middleware,
                "{$route->uri()} is missing auth.jwt");
            $this->assertContains('admin', $middleware,
                "{$route->uri()} is missing the admin gate — hidden in the UI is not a permission");
        }
    }

    /**
     * A3 introduced mutations, so "everything is GET" is no longer the rule.
     * What still holds: reporting stays read-only, and nothing DESTRUCTIVE is
     * exposed over HTTP at all — an advertiser or campaign is paused, never
     * deleted, because delivery history has to remain explicable afterwards.
     */
    public function test_reporting_stays_read_only_and_nothing_is_deletable(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/ads'));

        if ($routes->isEmpty()) {
            $this->markTestSkipped('Ads admin routes not registered yet.');
        }

        $readOnly = [
            'api/admin/ads/status', 'api/admin/ads/dashboard', 'api/admin/ads/inventory',
            'api/admin/ads/invalid-traffic', 'api/admin/ads/revenue', 'api/admin/ads/settings',
            'api/admin/ads/reconcile', 'api/admin/ads/audit',
        ];

        foreach ($routes as $route) {
            $verbs = array_values(array_diff($route->methods(), ['HEAD']));

            $this->assertNotContains('DELETE', $verbs,
                "{$route->uri()} exposes DELETE — ad records are paused, never destroyed");

            if (in_array($route->uri(), $readOnly, true)) {
                $this->assertSame(['GET'], $verbs,
                    "{$route->uri()} is a reporting surface and must stay read-only");
            }
        }
    }

    // ── Payload shape, tested against the controller directly so it is ──
    // ── proven even before the governed route wiring lands.            ──

    private function controller(): AdminAdsController
    {
        return app(AdminAdsController::class);
    }

    public function test_status_states_plainly_whether_ads_are_live(): void
    {
        $data = $this->controller()->status()->getData(true);

        $this->assertFalse($data['serving'], 'ships off');
        $this->assertStringContainsString('OFF platform-wide', $data['note']);
        $this->assertArrayHasKey('inventory', $data);
        $this->assertArrayHasKey('creatives_pending', $data);
    }

    public function test_dashboard_carries_freshness_and_timezone(): void
    {
        $data = $this->controller()->dashboard(new Request())->getData(true);

        $this->assertSame('UTC', $data['timezone']);
        $this->assertArrayHasKey('note', $data['freshness']);
        // Gross / invalid / net must always be three separate figures.
        $this->assertArrayHasKey('impressions_gross', $data['totals']);
        $this->assertArrayHasKey('impressions_invalid', $data['totals']);
        $this->assertArrayHasKey('impressions_net', $data['totals']);
    }

    public function test_settings_exposes_defaults_and_the_fixed_list(): void
    {
        $data = $this->controller()->settings()->getData(true);

        $this->assertGreaterThanOrEqual(34, count($data['settings']));
        $this->assertNotEmpty($data['fixed_by_design']);

        // A2 made settings editable, so `editable: false` is no longer the rule.
        // What still matters is that the flag tells the UI the truth — if it
        // claims editable, the write endpoints must actually exist.
        $this->assertTrue($data['editable']);

        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'admin/ads/settings/'));

        $this->assertNotEmpty($writeRoutes,
            'the settings endpoint advertises itself as editable, so write routes must exist');

        // Changing eligible_plan_slugs or the master switch decides whether ads
        // appear on customer sites at all — that needs more than the admin role.
        foreach ($writeRoutes as $r) {
            $this->assertContains('mfa.stepup', $r->gatherMiddleware(),
                $r->uri() . ' changes ad behaviour platform-wide and must require step-up');
        }

        $first = reset($data['settings']);
        foreach (['value', 'default', 'overridden', 'description'] as $k) {
            $this->assertArrayHasKey($k, $first);
        }
    }

    /** A guardrail must refuse with a reason, and be overridable on purpose. */
    public function test_settings_guardrail_refuses_then_allows_a_deliberate_override(): void
    {
        $c = app(\App\Http\Controllers\Api\Admin\AdminAdsController::class);

        $refused = $c->updateSetting(new Request(['value' => 5000]), 'refresh_interval_ms');
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertTrue($refused->getData(true)['refused']);
        $this->assertStringContainsString('IAB', $refused->getData(true)['error']);

        $forced = $c->updateSetting(new Request(['value' => 5000, 'force' => true]), 'refresh_interval_ms');
        $this->assertSame(200, $forced->getStatusCode());
        $this->assertTrue($forced->getData(true)['forced'], 'an override must be recorded as forced');

        $this->assertDatabaseHas('ad_audit_log', ['action' => 'settings.update']);
    }

    public function test_unknown_setting_key_is_rejected(): void
    {
        $c = app(\App\Http\Controllers\Api\Admin\AdminAdsController::class);

        $this->assertSame(404, $c->updateSetting(new Request(['value' => 1]), 'not_a_real_setting')->getStatusCode());
        $this->assertSame(404, $c->resetSetting(new Request(), 'not_a_real_setting')->getStatusCode());
    }

    public function test_inventory_reports_sellability(): void
    {
        $data = $this->controller()->inventory(new Request())->getData(true);

        $this->assertArrayHasKey('sellable', $data);
        $this->assertArrayHasKey('by_archetype', $data);
        $this->assertArrayHasKey('unclassified', $data);
    }

    public function test_reconcile_rejects_a_malformed_date(): void
    {
        $r = $this->controller()->reconcile(new Request(['date' => 'yesterday']));

        $this->assertSame(422, $r->getStatusCode());
    }

    public function test_campaign_report_404s_for_an_unknown_campaign(): void
    {
        $r = $this->controller()->campaignReport(new Request(), 999999);

        $this->assertSame(404, $r->getStatusCode());
    }

    public function test_audit_returns_recent_entries(): void
    {
        DB::table('ad_audit_log')->insert([
            'action' => 'settings.update', 'actor_label' => 'test',
            'before' => json_encode(['a' => 1]), 'after' => json_encode(['a' => 2]),
            'occurred_at' => now(),
        ]);

        $data = $this->controller()->audit(new Request(['limit' => 10]))->getData(true);

        $this->assertSame(1, $data['count']);
        $this->assertSame('settings.update', $data['entries'][0]['action']);
        $this->assertSame(['a' => 2], $data['entries'][0]['after'], 'JSON columns are decoded for the UI');
    }

    public function test_day_windows_are_clamped(): void
    {
        // A hostile ?days=99999 must not turn into an unbounded scan.
        $data = $this->controller()->dashboard(new Request(['days' => 99999]))->getData(true);

        $this->assertLessThanOrEqual(365, $data['window']['days']);
    }
}
