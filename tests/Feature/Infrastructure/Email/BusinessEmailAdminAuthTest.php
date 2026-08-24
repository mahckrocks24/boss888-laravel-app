<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminAccess as Access;
use App\Models\User;
use App\Models\Workspace;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INFRA888 · E3.1 — AUTHORIZATION MATRIX, THROUGH THE REAL MIDDLEWARE STACK.
 *
 * ─── WHY THE PREVIOUS ATTEMPT WAS WRONG ─────────────────────────────────────
 *
 * E3 used `actingAs()`. That sets the session guard and never touches
 * `auth.jwt`, so every one of its HTTP assertions returned 401 — and, more
 * importantly, would have proven nothing about the guard even if it had passed.
 * The E3 report called that a harness gap; it was a hypothesis, and this file
 * is where it gets tested rather than asserted.
 *
 * These mint REAL tokens through RefreshTokenService and drive the REAL stack:
 * auth.jwt → admin → (DenyApiKeyAuth on mutating routes). The pattern is copied
 * from ProviderControlPlaneHttpTest, which is INFRA888's own canonical admin
 * HTTP test. No second authentication helper was invented.
 *
 * ─── THE TEN PROOFS STAGE 2 REQUIRED ────────────────────────────────────────
 *
 * Each has its own test below, named for what it proves.
 */
class BusinessEmailAdminAuthTest extends TestCase
{
    /**
     * RefreshDatabase, NOT DatabaseTransactions.
     *
     * The pattern was copied from ProviderControlPlaneHttpTest, which uses
     * DatabaseTransactions — and that trait does not migrate. Run alone this
     * class passed, because a previous RefreshDatabase run had left a migrated
     * schema behind. Run inside the suite it failed all 16 tests with
     * "Unknown column 'is_platform_admin'": it had inherited whatever schema
     * happened to exist at that point in the ordering.
     *
     * A test whose result depends on what ran before it is not a test. Every
     * other Business Email class uses RefreshDatabase; this one now does too,
     * so it builds the schema it needs and cannot depend on order.
     */
    use RefreshDatabase;

    private const BASE = '/api/admin/business-email';
    private const WS_A = 995101;
    private const WS_B = 995102;

    private User $admin;
    private User $adminElevated;
    private User $customer;
    private User $manager;

    private string $tokenAdmin;
    private string $tokenElevated;
    private string $tokenCustomer;
    private string $tokenManager;
    private string $tokenOtherWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Users first: workspaces.created_by is NOT NULL on this schema.
        $this->admin         = $this->makeUser('be-admin', true);
        $this->adminElevated = $this->makeUser('be-admin-elev', true);
        $this->customer      = $this->makeUser('be-customer', false);
        $this->manager       = $this->makeUser('be-manager', false);

        $wsA = $this->makeWorkspace(self::WS_A, $this->admin);
        $wsB = $this->makeWorkspace(self::WS_B, $this->admin);

        foreach ([$this->admin, $this->adminElevated, $this->customer, $this->manager] as $u) {
            $this->join($u, $wsA, $u->id === $this->manager->id ? 'manager' : 'owner');
        }

        $this->join($this->admin, $wsB, 'owner');

        $svc = app(RefreshTokenService::class);
        $this->tokenAdmin          = $svc->issueTokenPair($this->admin, $wsA)['access_token'];
        $this->tokenElevated       = $svc->issueTokenPair($this->adminElevated, $wsA)['access_token'];
        $this->tokenCustomer       = $svc->issueTokenPair($this->customer, $wsA)['access_token'];
        $this->tokenManager        = $svc->issueTokenPair($this->manager, $wsA)['access_token'];
        $this->tokenOtherWorkspace = $svc->issueTokenPair($this->admin, $wsB)['access_token'];

        // The surface under test must be switched on for these to mean anything.
        config([
            'business_email.admin_enabled' => true,
            'business_email.elevated_admin_user_ids' => [$this->adminElevated->id],
        ]);
    }

    // ═══ THE TEN REQUIRED PROOFS ═════════════════════════════════════════════

    /** 1. No token → rejected. */
    public function test_a_request_with_no_token_is_rejected(): void
    {
        $this->getJson(self::BASE . '/overview')->assertStatus(401);
    }

    /** 2. Invalid token → rejected. */
    public function test_a_malformed_token_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson(self::BASE . '/overview')
            ->assertStatus(401);
    }

    /** 3. Expired token → rejected. */
    public function test_an_expired_token_is_rejected(): void
    {
        // Signed with the real secret so only the expiry can be what rejects it.
        // A token that failed for the wrong reason would prove nothing.
        $expired = JWT::encode(
            ['sub' => $this->admin->id, 'ws' => self::WS_A, 'iat' => time() - 7200, 'exp' => time() - 60],
            (string) config('jwt.secret', env('JWT_SECRET')),
            'HS256'
        );

        $this->withHeaders(['Authorization' => 'Bearer ' . $expired])
            ->getJson(self::BASE . '/overview')
            ->assertStatus(401);
    }

    /** 4. Valid customer token → Admin routes rejected. */
    public function test_a_valid_customer_token_cannot_reach_the_admin_surface(): void
    {
        foreach (['overview', 'domains', 'mailboxes', 'operations', 'providers', 'health'] as $path) {
            $this->withHeaders($this->as($this->tokenCustomer))
                ->getJson(self::BASE . '/' . $path)
                ->assertStatus(403);
        }
    }

    /** 5. Workspace manager without platform permission → rejected. */
    public function test_a_workspace_manager_without_platform_admin_is_rejected(): void
    {
        // Membership and a workspace role are not platform authority. This is
        // the distinction the platform's own audit found missing elsewhere.
        $this->withHeaders($this->as($this->tokenManager))
            ->getJson(self::BASE . '/providers')
            ->assertStatus(403);
    }

    /** 6. Admin without the business_email capability → rejected. */
    public function test_a_platform_admin_without_the_capability_is_rejected(): void
    {
        // The capability is withdrawn by closing the gate — which is exactly how
        // it is withdrawn in production, so this is the real path rather than a
        // synthetic one.
        config(['business_email.admin_enabled' => false]);

        $this->withHeaders($this->as($this->tokenAdmin))
            ->getJson(self::BASE . '/overview')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_available');
    }

    /** 7. Valid authorized Admin → allowed. */
    public function test_an_authorized_platform_admin_is_allowed(): void
    {
        $this->withHeaders($this->as($this->tokenAdmin))
            ->getJson(self::BASE . '/overview')
            ->assertStatus(200)
            ->assertJsonPath('screen', 'business_email_overview');
    }

    /** 8. Cross-workspace access is scoped, not assumed. */
    public function test_a_token_scoped_to_another_workspace_still_resolves_platform_scope(): void
    {
        // A platform operator console IS cross-tenant by design — that is what
        // makes it an operator console. What must NOT happen is a non-platform
        // principal reaching it, which proof 5 covers. Here the same admin
        // holding a token for workspace B still sees the platform view, and the
        // filter is explicit rather than implied by the token.
        $response = $this->withHeaders($this->as($this->tokenOtherWorkspace))
            ->getJson(self::BASE . '/domains?workspace_id=' . self::WS_A);

        $response->assertStatus(200);

        foreach ($response->json('domains') as $row) {
            $this->assertSame(self::WS_A, (int) $row['workspace_id']);
        }
    }

    /** 9. Credential management is narrower than ordinary operation. */
    public function test_credential_management_is_narrower_than_ordinary_operation(): void
    {
        $request = $this->requestFor($this->admin);

        $this->assertTrue((new Access())->allows($request, Access::OPERATE));
        $this->assertFalse(
            (new Access())->allows($request, Access::PROVIDER_CREDENTIALS),
            'An ordinary platform admin must not hold credential management.'
        );

        $this->assertTrue(
            (new Access())->allows($this->requestFor($this->adminElevated), Access::PROVIDER_CREDENTIALS)
        );
    }

    /** 10. Destructive permissions are narrower than read permissions. */
    public function test_destructive_permissions_are_narrower_than_read(): void
    {
        $request = $this->requestFor($this->admin);

        $this->assertTrue((new Access())->allows($request, Access::READ));

        foreach ([Access::MAILBOX_DESTROY, Access::DOMAIN_DESTROY] as $capability) {
            $this->assertFalse(
                (new Access())->allows($request, $capability),
                "{$capability} must not be granted to an admin who merely holds read."
            );
        }
    }

    // ═══ MACHINE CREDENTIALS ═════════════════════════════════════════════════

    public function test_an_api_key_cannot_reach_a_mutating_business_email_route(): void
    {
        // DenyApiKeyAuth answers 403, not 401: the credential was recognised and
        // is simply never permitted here, whatever it authenticates. An API key
        // must never be able to provision or destroy a mailbox.
        $this->withHeaders(['X-API-KEY' => 'some-machine-key'])
            ->postJson(self::BASE . '/domains/1/actions/create-mailbox', [])
            ->assertStatus(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function as(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function makeUser(string $prefix, bool $platformAdmin): User
    {
        return User::create([
            'name'                 => $prefix,
            'email'                => $prefix . '-' . Str::random(8) . '@test.local',
            'password'             => Hash::make(Str::random(32)),
            'is_admin'             => $platformAdmin ? 1 : 0,
            'is_platform_admin'    => $platformAdmin ? 1 : 0,
            'current_workspace_id' => self::WS_A,
        ]);
    }

    private function makeWorkspace(int $id, User $creator): Workspace
    {
        return Workspace::firstOrCreate(
            ['id' => $id],
            ['name' => 'be-ws-' . $id, 'slug' => 'be-ws-' . $id . '-' . Str::random(6), 'created_by' => $creator->id]
        );
    }

    private function join(User $user, Workspace $workspace, string $role): void
    {
        DB::table('workspace_users')->insertOrIgnore([
            'user_id'      => $user->id,
            'workspace_id' => $workspace->id,
            'role'         => $role,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function requestFor(User $user)
    {
        $request = \Illuminate\Http\Request::create(self::BASE . '/overview');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
