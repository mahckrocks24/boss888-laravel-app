<?php

namespace Tests\Feature\Platform;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability;
use App\Core\Platform\Admin\AdminRegistry;
use App\Http\Middleware\AdminSessionIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Platform Security 1.0 — the cookie, through the REAL HTTP pipeline.
 *
 * WHY THIS FILE EXISTS.
 * AdminIdentityTest proves the middleware's own logic by calling handle()
 * directly. That suite was green — 16 tests, 262 assertions — while the
 * platform held a defect that would have locked every administrator out of
 * the console on the first request after activation.
 *
 * The cookie is WRITTEN by /api/auth/login, which is in the `api` middleware
 * group. It is READ on /admin/*, which is in the `web` group. Only `web`
 * carries EncryptCookies, so it called decrypt() on a plaintext JWT, threw
 * DecryptException, and nulled the cookie before the middleware ran. A valid
 * token was answered with a redirect to the login page, which set the same
 * cookie again: an infinite loop with no way in.
 *
 * A direct call to handle() cannot see that, because the bug does not live in
 * handle() — it lives in the composition. So every test here goes through
 * $this->get()/$this->post(), which runs the real kernel: global middleware,
 * the real `web` group, EncryptCookies, and then the middleware under test.
 *
 * The rule this file encodes: identity that crosses middleware groups is
 * certified by crossing them, not by asserting about the crossing.
 */
class AdminCookiePipelineTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CertPassw0rd1';
    private const PROBE = '/__pipeline/admin-probe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->canonicalAdmin();
        $this->ordinaryAdmin();
        $this->customer();
        $this->grantCanonical();
        $this->giveCanonicalAWorkspace();
    }

    // ─────────────────────────────────────────────────────────────────
    //  The login contract, and the cookie it now carries
    // ─────────────────────────────────────────────────────────────────

    public function test_login_returns_the_existing_json_contract_unchanged(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();

        // Every client — the SPA, the admin console, the mobile app — reads
        // these keys. Platform Security 1.0 adds a Set-Cookie header and
        // nothing else; the body must be byte-for-byte the same shape.
        $this->assertSame(
            ['access_token', 'refresh_token', 'user', 'workspaces', 'current_workspace_id'],
            array_keys($response->json()),
            'the login response gained or lost a top-level key'
        );

        $this->assertSame(
            ['id', 'email', 'name', 'is_platform_admin'],
            array_keys($response->json('user')),
            'the user object in the login response changed shape'
        );

        $this->assertTrue($response->json('user.is_platform_admin'));
        $this->assertIsString($response->json('access_token'));
    }

    public function test_login_sets_the_admin_identity_cookie(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $this->assertNotNull($cookie, 'login must set the admin identity cookie');

        // The SAME token the JSON carries. One issuer, one decoder, two
        // transports — not a second credential.
        $this->assertSame($response->json('access_token'), $cookie->getValue());

        $this->assertTrue($cookie->isHttpOnly(), 'JavaScript must not be able to read it');
        $this->assertTrue($cookie->isSecure(), 'it must never travel in clear');
        $this->assertSame('/admin', $cookie->getPath(), 'it must not be sent to the API or marketing site');
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_the_cookie_login_issues_is_accepted_by_the_admin_pipeline(): void
    {
        // The end-to-end claim, and the one the direct-invocation suite could
        // not make: the value login puts in the browser is the value the admin
        // pipeline accepts, with the real `web` group in between.
        $token = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ])->json('access_token');

        $this->probeRoute();

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $token)
            ->get(self::PROBE)
            ->assertOk()
            ->assertSee('PROBE OK');
    }

    // ─────────────────────────────────────────────────────────────────
    //  EncryptCookies: the regression this whole file exists for
    // ─────────────────────────────────────────────────────────────────

    public function test_the_cookie_survives_the_web_groups_cookie_encryption(): void
    {
        $this->probeRoute();

        $response = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor(1))
            ->get(self::PROBE);

        // Remove lu_admin_at from the encryptCookies() exemption in
        // bootstrap/app.php and this becomes a 302. That is the whole point.
        $response->assertOk();
        $response->assertSee('PROBE OK');
    }

    public function test_a_valid_cookie_resolves_the_platform_admin_identity(): void
    {
        Route::middleware(['web', AdminSessionIdentity::class])->get('/__pipeline/whoami', function () {
            $user = request()->user();

            return response()->json([
                'id' => $user?->id,
                'email' => $user?->email,
                'is_platform_admin' => (bool) ($user?->is_platform_admin),
                'auth_via' => request()->attributes->get('auth_via'),
            ]);
        });

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor(1))
            ->get('/__pipeline/whoami')
            ->assertOk()
            ->assertJson([
                'id' => 1,
                'email' => Engineer888Access::CANONICAL_EMAIL,
                'is_platform_admin' => true,
                // Server-resolved provenance, never read from the client.
                'auth_via' => 'jwt_cookie',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Every way the cookie can be wrong
    // ─────────────────────────────────────────────────────────────────

    /** Every way a cookie can be unacceptable, named. */
    private const BAD_COOKIES = [
        'tampered signature' => 'tampered',
        'signed with another key' => 'foreign',
        'expired' => 'expired',
        'structurally not a JWT' => 'garbage',
        'valid token for a non-admin' => 'customer',
    ];

    public function test_an_unacceptable_cookie_is_refused_through_the_real_pipeline(): void
    {
        $this->probeRoute();

        foreach (self::BAD_COOKIES as $label => $kind) {
            $response = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->badToken($kind))
                ->get(self::PROBE);

            $response->assertRedirect(route('admin.login'));
            $this->assertStringNotContainsString('PROBE OK', $response->getContent(),
                "{$label} reached the protected page");
        }
    }

    public function test_every_refusal_looks_identical(): void
    {
        $this->probeRoute();

        $seen = [];
        foreach (self::BAD_COOKIES as $kind) {
            $r = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->badToken($kind))->get(self::PROBE);
            $seen[] = $r->getStatusCode() . ' ' . $r->headers->get('Location');
        }
        $missing = $this->get(self::PROBE);
        $seen[] = $missing->getStatusCode() . ' ' . $missing->headers->get('Location');

        $this->assertCount(1, array_unique($seen),
            'a differing response tells an attacker which failure they achieved: ' . json_encode(array_unique($seen)));
    }

    // ─────────────────────────────────────────────────────────────────
    //  No redirect loop
    // ─────────────────────────────────────────────────────────────────

    public function test_a_missing_cookie_redirects_once_and_lands_on_a_reachable_login_page(): void
    {
        $this->probeRoute();

        $first = $this->get(self::PROBE);
        $first->assertRedirect(route('admin.login'));

        // The landing page must be a 200, not another redirect. If it were a
        // redirect the browser would bounce forever, which is the failure this
        // whole certification exists to prevent.
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_login_page_is_reachable_anonymously(): void
    {
        $this->assertLoginPageIsUsable();
    }

    // ─────────────────────────────────────────────────────────────────
    //  The login page must be TERMINAL. It was not, and that was the
    //  second lockout path — independent of the cookie-encryption one.
    // ─────────────────────────────────────────────────────────────────

    public function test_the_login_page_never_navigates_away_on_a_stale_local_storage_token(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        // The removed block was:
        //     const token = localStorage.getItem('lu_admin_token');
        //     if (token && window.location.pathname === '/admin/login') {
        //         window.location.href = '/admin';
        //     }
        // With /admin/* gated server-side, a browser holding a stale token but
        // no valid cookie bounced between the two forever and could never reach
        // the form. Reading that token here again would restore the loop.
        $this->assertStringNotContainsString(
            "localStorage.getItem('lu_admin_token')",
            $html,
            'the login page reads a client-side token again — this reintroduces the redirect loop'
        );

        $this->assertStringNotContainsString(
            "window.location.pathname === '/admin/login'",
            $html,
            'the login page is deciding navigation from client state again'
        );
    }

    public function test_the_login_page_carries_no_client_side_auth_state_at_all(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        // Neither the old localStorage guard nor a sessionStorage substitute.
        // The server decides admin navigation on every request; nothing the
        // browser remembers may influence it.
        //
        // Matched on the API CALL rather than the bare word, because the code
        // comment that replaced the removed block names sessionStorage in order
        // to say it is deliberately not used.
        foreach (['sessionStorage.getItem', 'sessionStorage.setItem'] as $call) {
            $this->assertStringNotContainsString($call, $html,
                "client-side auth state was reintroduced via {$call}");
        }
    }

    public function test_the_refusal_chain_terminates_at_a_usable_login_page(): void
    {
        $this->probeRoute();

        // Every way in can be refused, and every refusal must come to rest on a
        // page the administrator can actually sign in on. This is the whole
        // no-loop claim, asserted end to end.
        foreach (self::BAD_COOKIES as $label => $kind) {
            $refused = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->badToken($kind))
                ->get(self::PROBE);

            $refused->assertRedirect(route('admin.login'));
            $this->assertLoginPageIsUsable("after refusing: {$label}");
        }

        $this->get(self::PROBE)->assertRedirect(route('admin.login'));
        $this->assertLoginPageIsUsable('after refusing: no cookie at all');
    }

    public function test_logout_returns_the_user_to_a_usable_login_page(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $login->json('access_token'))
            ->postJson('/api/auth/logout', ['refresh_token' => $login->json('refresh_token')])
            ->assertOk();

        $this->assertLoginPageIsUsable('after signing out');
    }

    public function test_a_full_sign_in_round_trip_reaches_a_protected_page(): void
    {
        $this->probeRoute();

        // Start refused.
        $this->get(self::PROBE)->assertRedirect(route('admin.login'));

        // The login page is where that redirect lands, and it works.
        $this->assertLoginPageIsUsable('at the start of the round trip');

        // Sign in exactly as the form does.
        $login = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk();

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);
        $this->assertNotNull($cookie, 'sign-in must issue the identity cookie');

        // Carry it back, as the browser would, and arrive.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $cookie->getValue())
            ->get(self::PROBE)
            ->assertOk()
            ->assertSee('PROBE OK');
    }

    public function test_a_stale_cookie_is_cleared_so_the_browser_cannot_loop(): void
    {
        $this->probeRoute();

        $response = $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, 'not.a.jwt')->get(self::PROBE);

        $cleared = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $this->assertNotNull($cleared, 'the stale cookie must be cleared on the way out');
        $this->assertSame('', (string) $cleared->getValue());
    }

    public function test_a_json_client_gets_401_rather_than_a_redirect(): void
    {
        $this->probeRoute();

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, 'not.a.jwt')
            ->getJson(self::PROBE)
            ->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Capability filtering, composed with the identity the pipeline resolved
    // ─────────────────────────────────────────────────────────────────

    public function test_a_capability_less_page_is_reachable_by_any_platform_admin(): void
    {
        $this->registryRoute();

        foreach ([1 => 'canonical', 2 => 'ordinary'] as $id => $who) {
            $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor($id))
                ->get('/__pipeline/registry/users')
                ->assertOk()
                ->assertSee('RESOLVED:users', false);
        }
    }

    public function test_the_canonical_admin_reaches_engineer888(): void
    {
        $this->registryRoute();

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor(1))
            ->get('/__pipeline/registry/engineer888')
            ->assertOk()
            ->assertSee('RESOLVED:e888Tasks', false);
    }

    public function test_an_ordinary_platform_admin_cannot_reach_engineer888(): void
    {
        $this->registryRoute();

        // 404, not 403: a hidden module must be indistinguishable from a slug
        // that was never registered.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor(2))
            ->get('/__pipeline/registry/engineer888')
            ->assertNotFound();
    }

    public function test_revoking_the_engineer888_grant_closes_the_admin_page_too(): void
    {
        $this->registryRoute();

        DB::table('engineering_access_grants')->update(['revoked_at' => now()->subMinute()]);

        // One policy, not a copy of one.
        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $this->tokenFor(1))
            ->get('/__pipeline/registry/engineer888')
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Logout
    // ─────────────────────────────────────────────────────────────────

    public function test_logout_clears_the_identity_cookie(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'password' => self::PASSWORD,
        ]);

        // /api/auth/logout sits behind auth.jwt, so it is called exactly as the
        // console calls it: bearer token in the header, refresh token in the body.
        $response = $this->withHeader('Authorization', 'Bearer ' . $login->json('access_token'))
            ->postJson('/api/auth/logout', [
                'refresh_token' => $login->json('refresh_token'),
            ]);

        $response->assertOk();

        $cleared = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === AdminSessionIdentity::COOKIE);

        $this->assertNotNull($cleared, 'logout must clear the page-identity cookie');
        $this->assertSame('', (string) $cleared->getValue());
    }

    public function test_a_token_whose_user_was_deactivated_stops_working(): void
    {
        $this->probeRoute();
        $token = $this->tokenFor(1);

        // A token is a claim about the past; the database is the present.
        User::where('id', 1)->update(['status' => 'suspended']);

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $token)
            ->get(self::PROBE)
            ->assertRedirect(route('admin.login'));
    }

    public function test_a_token_whose_admin_flag_was_removed_stops_working(): void
    {
        $this->probeRoute();
        $token = $this->tokenFor(1);

        User::where('id', 1)->update(['is_platform_admin' => false]);

        $this->withUnencryptedCookie(AdminSessionIdentity::COOKIE, $token)
            ->get(self::PROBE)
            ->assertRedirect(route('admin.login'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * The login page is reachable AND can actually be signed in on.
     *
     * "Reachable" alone is not enough: a 200 that immediately navigates the
     * browser elsewhere is exactly the failure this asserts against.
     */
    private function assertLoginPageIsUsable(string $context = ''): void
    {
        $suffix = $context === '' ? '' : " ({$context})";
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString("fetch('/api/auth/login'", $html,
            "the login page cannot submit credentials{$suffix}");
        $this->assertStringContainsString('id="pass"', $html,
            "the login page has no password field{$suffix}");
        $this->assertStringNotContainsString("localStorage.getItem('lu_admin_token')", $html,
            "the login page would navigate away before it could be used{$suffix}");
    }

    /** A route carrying exactly what activation attaches: the web group, then the middleware. */
    private function probeRoute(): void
    {
        Route::middleware(['web', AdminSessionIdentity::class])
            ->get(self::PROBE, fn () => response('PROBE OK'));
    }

    /** The composed shape of the activated controller: identity, then capability filter. */
    private function registryRoute(): void
    {
        Route::middleware(['web', AdminSessionIdentity::class])
            ->get('/__pipeline/registry/{slug}', function (string $slug) {
                $key = AdminRegistry::make()->keyForSlug(request(), $slug);

                if ($key === null) {
                    abort(404);
                }

                return response('RESOLVED:' . $key);
            })->where('slug', '.*');
    }

    /**
     * A token bound to a REAL sign-in record.
     *
     * The `sid` claim names a row in `sessions`, and since 2026-08-04 the
     * middleware refuses a token whose session is missing or revoked — that is
     * what makes signing out terminal. A hand-minted token claiming session #1
     * when no such row exists is therefore correctly rejected, so the fixture
     * has to create the session it claims.
     */
    private function tokenFor(int $userId): string
    {
        $sessionId = DB::table('sessions')->insertGetId([
            'user_id' => $userId,
            'workspace_id' => null,
            'auth_via' => 'password',
            'refresh_token_hash' => hash('sha256', 'fixture-' . $userId . '-' . uniqid('', true)),
            'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return app(\App\Core\Auth\RefreshTokenService::class)
            ->issueAccessToken(User::findOrFail($userId), null, 'password', $sessionId);
    }

    private function badToken(string $kind): string
    {
        return match ($kind) {
            'tampered' => substr($this->tokenFor(1), 0, -4) . 'AAAA',
            'foreign' => \Firebase\JWT\JWT::encode(
                ['sub' => 1, 'exp' => time() + 3600],
                'a-secret-this-platform-never-issued',
                'HS256'
            ),
            'expired' => $this->expiredTokenFor(1),
            'garbage' => 'not.a.jwt',
            'customer' => $this->tokenFor(3),
        };
    }

    private function expiredTokenFor(int $userId): string
    {
        $svc = app(\App\Core\Auth\RefreshTokenService::class);
        $ref = new \ReflectionClass($svc);
        $prop = $ref->getProperty('jwtSecret');
        $prop->setAccessible(true);

        return \Firebase\JWT\JWT::encode(
            ['sub' => $userId, 'via' => 'password', 'sid' => 1, 'iat' => time() - 7200, 'exp' => time() - 60],
            $prop->getValue($svc),
            'HS256'
        );
    }

    private function canonicalAdmin(): User
    {
        return $this->makeUser(
            Engineer888Access::CANONICAL_USER_ID,
            Engineer888Access::CANONICAL_EMAIL,
            true
        );
    }

    private function ordinaryAdmin(): User
    {
        return $this->makeUser(2, 'other@levelupgrowth.io', true);
    }

    private function customer(): User
    {
        return $this->makeUser(3, 'buyer@example.test', false);
    }

    private function makeUser(int $id, string $email, bool $admin): User
    {
        $user = new User();
        $user->id = $id;
        $user->name = 'Cert ' . $id;
        $user->email = $email;
        $user->password = self::PASSWORD;
        $user->is_platform_admin = $admin;
        $user->status = 'active';
        $user->save();

        return $user;
    }

    /**
     * auth.jwt refuses to guess a workspace: zero memberships and more than one
     * are both answered with 401 workspace_unresolved. The logout endpoint sits
     * behind it, so the canonical admin needs exactly one membership for the
     * sign-out path to be exercised the way a real administrator exercises it.
     */
    private function giveCanonicalAWorkspace(): void
    {
        $workspaceId = DB::table('workspaces')->insertGetId([
            'name' => 'Certification',
            'slug' => 'certification',
            'timezone' => 'UTC',
            'created_by' => Engineer888Access::CANONICAL_USER_ID,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => Engineer888Access::CANONICAL_USER_ID,
            'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grantCanonical(): void
    {
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)->delete();

        DB::table('engineering_access_grants')->insert([
            'user_id' => Engineer888Access::CANONICAL_USER_ID,
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'cert fixture',
            'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
