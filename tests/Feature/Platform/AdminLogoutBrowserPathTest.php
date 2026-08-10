<?php

namespace Tests\Feature\Platform;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Http\Middleware\AdminSessionIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Signing out, along the path a browser actually takes.
 *
 * WHY THIS FILE EXISTS, AND WHY THE EARLIER ONE WAS NOT ENOUGH.
 * AdminCookiePipelineTest asserted "logout clears the identity cookie" and
 * passed. It was wrong twice over, and a live browser found both on 2026-08-04:
 *
 *   1. It called POST /api/auth/logout directly. The admin console never calls
 *      that endpoint — its Sign out cleared two localStorage keys and navigated
 *      away, so the cookie was untouched and a "signed out" browser kept
 *      receiving the full admin shell for the token's remaining 12 hours.
 *
 *   2. Even the endpoint did not work. It cleared the cookie with
 *      withoutCookie(), whose forget-cookie defaults to path "/", and the cookie
 *      lives at /admin. The assertion checked the cookie was present and empty;
 *      it never checked the path. A deletion at the wrong path is not a
 *      deletion — the browser keeps the original and gains a second, empty
 *      cookie it never sends.
 *
 * So every assertion here goes through the real HTTP kernel against the real
 * /admin/* routes, and every cookie assertion checks ATTRIBUTES, not just
 * presence.
 */
class AdminLogoutBrowserPathTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CertPassw0rd1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
        $this->seedCanonicalAdmin();
    }

    // ─────────────────────────────────────────────────────────────────
    //  The full sequence, in order
    // ─────────────────────────────────────────────────────────────────

    public function test_the_whole_sign_in_use_sign_out_sign_in_again_journey(): void
    {
        // 1-2. Sign in and receive the identity cookie.
        $first = $this->signIn();
        $this->assertNotNull($first['cookie'], 'sign-in must issue lu_admin_at');

        // 3. The protected page opens.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $first['cookie']->getValue())
            ->get('/admin/engineer888')
            ->assertOk()
            ->assertSee('ADMIN_PAGES', false);

        // 4. Sign out the way the console signs out.
        $logout = $this->postAdminLogout($first['cookie']->getValue());
        $logout->assertRedirect(route('admin.login'));

        // 5-6. The cookie is cleared, with attributes that actually remove it.
        $this->assertCookieTrulyRemoved($logout);

        // 7. The protected page no longer returns admin HTML.
        $after = $this->get('/admin/engineer888');
        $after->assertRedirect(route('admin.login'));
        $after->assertDontSee('ADMIN_PAGES', false);

        // 8. The API is closed too.
        $this->getJson('/api/admin/stats')->assertStatus(401);

        // 9-10. Login renders, and is terminal — no loop.
        $this->get('/admin/login')->assertOk()->assertSee('id="pass"', false);

        // 11-12. Signing in again restores everything.
        $second = $this->signIn();
        $this->assertNotNull($second['cookie']);
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $second['cookie']->getValue())
            ->get('/admin/engineer888')
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────
    //  The cookie must actually be removable
    // ─────────────────────────────────────────────────────────────────

    public function test_the_forget_cookie_matches_the_issued_cookie_attribute_for_attribute(): void
    {
        $issued = $this->signIn()['cookie'];
        $forget = AdminSessionIdentity::forgetCookie();

        // Name, domain and path are what a browser matches on. Miss any of them
        // and the original cookie simply stays.
        $this->assertSame($issued->getName(), $forget->getName());
        $this->assertSame($issued->getPath(), $forget->getPath(), 'a mismatched path is not a deletion');
        $this->assertSame($issued->getDomain(), $forget->getDomain());

        $this->assertSame($issued->isSecure(), $forget->isSecure());
        $this->assertSame($issued->isHttpOnly(), $forget->isHttpOnly());
        $this->assertSame(strtolower((string) $issued->getSameSite()), strtolower((string) $forget->getSameSite()));

        $this->assertSame('', (string) $forget->getValue());
        $this->assertLessThan(time(), $forget->getExpiresTime());
        $this->assertSame(AdminSessionIdentity::PATH, $forget->getPath());
    }

    public function test_the_middleware_clears_a_stale_cookie_at_the_right_path(): void
    {
        // The refusal path had the same defect: it "cleared" the cookie to "/",
        // so a browser holding a bad token was never actually relieved of it.
        $response = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, 'not.a.jwt')
            ->get('/admin/engineer888');

        $this->assertCookieTrulyRemoved($response);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Logout must be terminal, not merely local
    // ─────────────────────────────────────────────────────────────────

    public function test_a_copy_of_the_cookie_kept_from_before_logout_is_rejected(): void
    {
        $session = $this->signIn();
        $stolen = $session['cookie']->getValue();

        // It works while the session is live.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $stolen)
            ->get('/admin/engineer888')->assertOk();

        $this->postAdminLogout($stolen);

        // Clearing the browser's copy is not enough: the token stays
        // cryptographically valid for hours. The server-side session record is
        // what makes signing out mean something.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $stolen)
            ->get('/admin/engineer888')
            ->assertRedirect(route('admin.login'));
    }

    public function test_logout_revokes_the_server_session_record(): void
    {
        $session = $this->signIn();

        $this->assertDatabaseHas('sessions', ['id' => $session['session_id'], 'revoked_at' => null]);

        $this->postAdminLogout($session['cookie']->getValue());

        $this->assertNotNull(
            DB::table('sessions')->where('id', $session['session_id'])->value('revoked_at'),
            'the sign-in record must be revoked, not just forgotten by the browser'
        );
    }

    public function test_stale_local_storage_alone_restores_nothing(): void
    {
        // localStorage is the client's memory of a session, never its authority.
        // A browser that kept the bearer token but lost the cookie must be
        // refused at the page layer.
        $session = $this->signIn();
        $bearer = $session['access_token'];

        $this->get('/admin/engineer888')->assertRedirect(route('admin.login'));

        // And the bearer alone opens no page, however valid it is for the API.
        $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->get('/admin/engineer888')
            ->assertRedirect(route('admin.login'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  The route's own properties
    // ─────────────────────────────────────────────────────────────────

    public function test_sign_out_is_not_reachable_by_GET(): void
    {
        // A GET that destroys a session can be fired by any <img> on any site.
        $this->get('/admin/logout')->assertRedirect(route('admin.login'));

        $get = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'admin/logout' && in_array('GET', $r->methods(), true));

        $this->assertNull($get, 'a state-changing GET logout route must not exist');
    }

    public function test_sign_out_is_csrf_protected(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'admin/logout');

        // WHAT THIS TEST CAN AND CANNOT PROVE.
        // Laravel's ValidateCsrfToken short-circuits under PHPUnit
        // (runningUnitTests()), and the framework does not expand it into the
        // test-time web group, so a unit test CANNOT observe enforcement. What
        // it can pin is the configuration that decides enforcement in
        // production: the route runs in the web group, and nothing has excused
        // it. Live enforcement — an unaccompanied POST answered with 419 — is
        // certified against the running platform in the browser, not here.
        $this->assertContains('web', $route->gatherMiddleware(),
            'sign-out must run in the web group, which is where CSRF validation lives');

        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])),
            'sign-out must accept POST and nothing else');

        // Nothing in the app's CSRF exception list may cover /admin/logout.
        $instance = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $except = new \ReflectionProperty($instance, 'except');
        $except->setAccessible(true);

        foreach ((array) $except->getValue($instance) as $pattern) {
            $this->assertFalse(
                fnmatch((string) $pattern, 'admin/logout') || fnmatch((string) $pattern, '/admin/logout'),
                "sign-out was excused from CSRF validation by pattern: {$pattern}"
            );
        }
    }

    public function test_repeated_sign_out_is_idempotent(): void
    {
        $session = $this->signIn();
        $cookie = $session['cookie']->getValue();

        $first = $this->postAdminLogout($cookie);
        $second = $this->postAdminLogout($cookie);
        $third = $this->postAdminLogout($cookie);

        foreach ([$first, $second, $third] as $r) {
            $r->assertRedirect(route('admin.login'));
            $this->assertCookieTrulyRemoved($r);
        }
    }

    public function test_anonymous_sign_out_neither_errors_nor_discloses_anything(): void
    {
        $anonymous = $this->postAdminLogout(null);
        $withGarbage = $this->postAdminLogout('not.a.jwt');
        $signedIn = $this->postAdminLogout($this->signIn()['cookie']->getValue());

        // All three answer identically. A caller cannot learn whether they held
        // a session, whose it was, or whether anything happened. (A Laravel
        // redirect carries a boilerplate HTML body; what matters is that the
        // three are indistinguishable, not that the body is empty.)
        $signatures = [];
        foreach ([$anonymous, $withGarbage, $signedIn] as $r) {
            $r->assertRedirect(route('admin.login'));
            $signatures[] = $r->getStatusCode() . '|' . $r->headers->get('Location') . '|' . $r->getContent();
        }

        $this->assertCount(1, array_unique($signatures),
            'the response differs by whether the caller held a session, which discloses it');

        // And nothing of the caller's own is echoed back.
        foreach ([$anonymous, $withGarbage, $signedIn] as $r) {
            $this->assertStringNotContainsString('not.a.jwt', (string) $r->getContent());
            $this->assertStringNotContainsString(Engineer888Access::CANONICAL_EMAIL, (string) $r->getContent());
        }
    }

    public function test_sign_out_cannot_be_turned_into_an_open_redirect(): void
    {
        $session = $this->signIn();

        foreach ([
            '?redirect=https://evil.test',
            '?next=https://evil.test',
            '?return_to=//evil.test',
            '?url=https://evil.test',
        ] as $attempt) {
            $r = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $session['cookie']->getValue())
                ->post('/admin/logout' . $attempt);

            $this->assertSame(route('admin.login'), $r->headers->get('Location'),
                "sign-out followed caller-supplied input: {$attempt}");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  The console's own script must do what this route expects
    // ─────────────────────────────────────────────────────────────────

    public function test_the_console_script_asks_the_server_before_clearing_local_state(): void
    {
        // admin-core.js is never lint-checked or executed by this suite, so its
        // contract with the route above is asserted here. The ordering is the
        // whole point: clearing localStorage first makes every failure look like
        // a successful sign-out.
        $js = file_get_contents(public_path('js/admin-core.js'));

        $this->assertMatchesRegularExpression('/async function logout/', $js,
            'sign-out must be able to await the server');

        $apiCall = strpos($js, "/api/auth/logout");
        $fallback = strpos($js, "'/admin/logout'");
        $clear = strpos($js, "localStorage.removeItem('lu_admin_token')");

        $this->assertNotFalse($apiCall, 'the API logout contract must be attempted');
        $this->assertNotFalse($fallback, 'the server-side fallback must be present');
        $this->assertNotFalse($clear);

        $this->assertLessThan($clear, $apiCall, 'local state was cleared before the API was asked');
        $this->assertLessThan($clear, $fallback, 'local state was cleared before the fallback was asked');
    }

    // ─────────────────────────────────────────────────────────────────
    //  helpers
    // ─────────────────────────────────────────────────────────────────

    /** @return array{cookie:\Symfony\Component\HttpFoundation\Cookie|null, access_token:string, session_id:int} */
    private function signIn(): array
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $token = $response->json('access_token');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        return [
            'cookie' => $cookie,
            'access_token' => $token,
            'session_id' => (int) ($claims['sid'] ?? 0),
        ];
    }

    private function postAdminLogout(?string $cookieValue)
    {
        $test = $cookieValue === null
            ? $this
            : $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $cookieValue);

        return $test->post('/admin/logout');
    }

    private function assertCookieTrulyRemoved($response): void
    {
        $cleared = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $this->assertNotNull($cleared, 'no deletion cookie was sent at all');
        $this->assertSame('', (string) $cleared->getValue());
        $this->assertSame(AdminSessionIdentity::PATH, $cleared->getPath(),
            'the deletion is scoped to the wrong path, so the browser keeps the original');
        $this->assertTrue($cleared->isHttpOnly());
        $this->assertTrue($cleared->isSecure());
        $this->assertLessThan(time(), $cleared->getExpiresTime());
    }

    private function seedCanonicalAdmin(): void
    {
        $user = new User();
        $user->id = Engineer888Access::CANONICAL_USER_ID;
        $user->name = 'Mark';
        $user->email = Engineer888Access::CANONICAL_EMAIL;
        $user->password = self::PASSWORD;
        $user->is_platform_admin = true;
        $user->status = 'active';
        $user->save();

        $wsId = DB::table('workspaces')->insertGetId([
            'name' => 'Certification', 'slug' => 'certification', 'timezone' => 'UTC',
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => $wsId, 'user_id' => $user->id, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A migration already seeds this grant, and (user_id, policy_version)
        // is unique — so replace rather than insert.
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)->delete();

        DB::table('engineering_access_grants')->insert([
            'user_id' => Engineer888Access::CANONICAL_USER_ID,
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'cert fixture',
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
