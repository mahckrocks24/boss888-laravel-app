<?php

namespace Tests\Feature\Platform;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability;
use App\Core\Platform\Admin\AdminAccess;
use App\Core\Platform\Admin\AdminRegistry;
use App\Http\Middleware\AdminSessionIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Server-side admin identity, and the capability-aware registry.
 *
 * The claim that matters most is the boring one: **the 54 pages that have no
 * capability must behave exactly as they do today**. A security layer that
 * quietly hides a working page is a worse outage than the disclosure it fixed,
 * so compatibility is asserted before privacy.
 */
class AdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
        $this->grantCanonical();
    }

    // ── compatibility comes first ───────────────────────────────────────

    public function test_every_page_without_a_capability_stays_visible_to_any_platform_admin(): void
    {
        $all = AdminRegistry::all();
        $uncategorised = array_filter($all, fn ($e) => ! isset($e['capability']));

        $visible = AdminRegistry::make()->visibleFor($this->requestAs(['id' => 2, 'email' => 'other@levelupgrowth.io']));

        foreach ($uncategorised as $key => $entry) {
            $this->assertArrayHasKey($key, $visible, "{$key} disappeared for an ordinary platform admin");
            $this->assertSame($entry['slug'], $visible[$key]['slug'], "{$key} changed slug");
        }
    }

    public function test_keys_and_slugs_are_unchanged_by_the_filter(): void
    {
        $all = AdminRegistry::all();
        $visible = AdminRegistry::make()->visibleFor($this->requestAs());

        foreach ($visible as $key => $entry) {
            $this->assertSame($all[$key]['slug'], $entry['slug']);
            $this->assertSame($all[$key]['label'], $entry['label']);
        }
    }

    public function test_the_canonical_admin_sees_the_whole_registry(): void
    {
        $this->assertCount(
            count(AdminRegistry::all()),
            AdminRegistry::make()->visibleFor($this->requestAs()),
            'the canonical account must lose nothing'
        );
    }

    // ── the filter, once a capability is declared ───────────────────────

    public function test_a_capability_entry_is_hidden_from_an_ordinary_admin_and_shown_to_the_owner(): void
    {
        // Declared here rather than in the shipped config so this test proves
        // the MECHANISM independently of whether step 10 has landed yet.
        config(['admin_pages.e888Probe' => [
            'slug' => 'engineer888/probe', 'group' => 'Engineer888', 'label' => 'Probe',
            'title' => 'Probe', 'icon' => '&#129302;', 'capability' => Engineer888Capability::DISCOVER,
        ]]);

        $registry = AdminRegistry::make();

        $this->assertArrayHasKey('e888Probe', $registry->visibleFor($this->requestAs()));
        $this->assertArrayNotHasKey('e888Probe',
            $registry->visibleFor($this->requestAs(['id' => 2, 'email' => 'other@levelupgrowth.io'])));
    }

    public function test_a_hidden_slug_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        config(['admin_pages.e888Probe' => [
            'slug' => 'engineer888/probe', 'group' => 'Engineer888', 'label' => 'Probe',
            'title' => 'Probe', 'icon' => '&#129302;', 'capability' => Engineer888Capability::DISCOVER,
        ]]);

        $registry = AdminRegistry::make();
        $ordinary = $this->requestAs(['id' => 2, 'email' => 'other@levelupgrowth.io']);

        $this->assertNull($registry->keyForSlug($ordinary, 'engineer888/probe'));
        $this->assertNull($registry->keyForSlug($ordinary, 'a-slug-nobody-ever-registered'));
        $this->assertSame('e888Probe', $registry->keyForSlug($this->requestAs(), 'engineer888/probe'));
    }

    public function test_the_client_slug_map_carries_only_what_the_server_approved(): void
    {
        config(['admin_pages.e888Probe' => [
            'slug' => 'engineer888/probe', 'group' => 'Engineer888', 'label' => 'Probe',
            'title' => 'Probe', 'icon' => '&#129302;', 'capability' => Engineer888Capability::DISCOVER,
        ]]);

        $map = AdminRegistry::make()->slugMapFor($this->requestAs(['id' => 2, 'email' => 'other@levelupgrowth.io']));

        $this->assertArrayNotHasKey('e888Probe', $map,
            'window.ADMIN_PAGES must never carry a slug the viewer may not use');
        $this->assertNotEmpty($map, 'the ordinary admin still receives the pages they may use');
    }

    // ── AdminAccess routes, it does not decide ──────────────────────────

    public function test_engineer888_capabilities_are_answered_by_engineer888s_own_policy(): void
    {
        $access = new AdminAccess();

        $this->assertTrue($access->allows($this->requestAs(), Engineer888Capability::DISCOVER));

        DB::table('engineering_access_grants')->update(['revoked_at' => now()->subMinute()]);

        $this->assertFalse($access->allows($this->requestAs(), Engineer888Capability::DISCOVER),
            'revoking the Engineer888 grant must close the admin page too — one policy, not two');
    }

    public function test_an_unroutable_capability_fails_closed(): void
    {
        $this->assertFalse((new AdminAccess())->allows($this->requestAs(), 'studio888.secret'),
            'a capability nobody implements is a defect; granting it would hide the defect');
    }

    public function test_a_non_admin_sees_nothing_whatever_the_capability(): void
    {
        $customer = $this->requestAs(['id' => 900, 'email' => 'buyer@example.test', 'is_platform_admin' => false]);

        $this->assertFalse((new AdminAccess())->allows($customer, null));
        $this->assertSame([], AdminRegistry::make()->visibleFor($customer));
    }

    public function test_an_anonymous_request_sees_nothing(): void
    {
        $anonymous = Request::create('/admin/dashboard', 'GET');

        $this->assertSame([], AdminRegistry::make()->visibleFor($anonymous));
        $this->assertSame([], AdminRegistry::make()->slugMapFor($anonymous));
    }

    public function test_client_supplied_role_data_grants_nothing(): void
    {
        $request = $this->requestAs(['id' => 5, 'is_platform_admin' => false]);
        $request->merge(['is_platform_admin' => true]);
        $request->headers->set('X-Platform-Admin', 'true');

        $this->assertSame([], AdminRegistry::make()->visibleFor($request));
    }

    // ── the middleware, still unwired ───────────────────────────────────

    public function test_a_request_with_no_cookie_is_sent_to_login(): void
    {
        $response = $this->passThrough(Request::create('/admin/dashboard', 'GET'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/login', $response->headers->get('Location'));
    }

    public function test_a_tampered_cookie_is_sent_to_login_and_the_cookie_is_cleared(): void
    {
        $request = Request::create('/admin/dashboard', 'GET', [], [AdminSessionIdentity::COOKIE => 'not.a.jwt']);

        $response = $this->passThrough($request);

        $this->assertSame(302, $response->getStatusCode());

        $cleared = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $this->assertNotNull($cleared, 'a stale cookie must be cleared or the browser loops');
        $this->assertSame('', (string) $cleared->getValue());
    }

    public function test_the_middleware_reveals_nothing_about_why_it_refused(): void
    {
        $missing = $this->passThrough(Request::create('/admin/dashboard', 'GET'));
        $tampered = $this->passThrough(
            Request::create('/admin/dashboard', 'GET', [], [AdminSessionIdentity::COOKIE => 'not.a.jwt'])
        );

        $this->assertSame($missing->getStatusCode(), $tampered->getStatusCode());
        $this->assertSame(
            $missing->headers->get('Location'),
            $tampered->headers->get('Location'),
            'differing responses would tell an attacker which failure they achieved'
        );
    }

    public function test_a_json_client_gets_401_rather_than_a_redirect(): void
    {
        $request = Request::create('/admin/dashboard', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->assertSame(401, $this->passThrough($request)->getStatusCode());
    }

    public function test_the_login_route_is_not_behind_this_middleware(): void
    {
        // Proven structurally: the middleware is not attached to any route yet,
        // and when it is attached the login route must remain outside it or the
        // platform cannot be signed into at all.
        $login = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'admin/login');

        $this->assertNotNull($login, 'the login route must exist');
        $this->assertNotContains(AdminSessionIdentity::class, $login->gatherMiddleware(),
            'gating the login page behind identity is how a platform locks everybody out');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function passThrough(Request $request)
    {
        return (new AdminSessionIdentity())->handle($request, fn () => response('ok', 200));
    }

    private function requestAs(array $overrides = []): Request
    {
        $request = Request::create('/admin/dashboard', 'GET');

        $user = (object) array_merge([
            'id'                => Engineer888Access::CANONICAL_USER_ID,
            'email'             => Engineer888Access::CANONICAL_EMAIL,
            'name'              => 'Mark',
            'status'            => 'active',
            'is_platform_admin' => true,
            'mfa_enabled'       => false,
        ], $overrides);

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'jwt_cookie');

        return $request;
    }

    private function grantCanonical(): void
    {
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)->delete();

        DB::table('engineering_access_grants')->insert([
            'user_id'        => Engineer888Access::CANONICAL_USER_ID,
            'email'          => Engineer888Access::CANONICAL_EMAIL,
            'capabilities'   => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by'     => 'test fixture',
            'granted_at'     => now(),
            'created_at'     => now(), 'updated_at' => now(),
        ]);
    }
}
