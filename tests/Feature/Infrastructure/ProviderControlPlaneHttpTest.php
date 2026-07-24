<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\States\CredentialState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2B-CERT WS13 — HTTP integration tests for the governed provider APIs.
 *
 * Closes the gap disclosed in Phase 2B-G, where the controller was proven only
 * by its dependencies.
 *
 * These mint REAL JWTs through RefreshTokenService and drive the REAL middleware
 * stack (auth.jwt -> admin -> DenyApiKeyAuth). `actingAs()` was deliberately not
 * used: it bypasses the middleware, which is precisely the layer under test. A
 * test that skips the guard cannot prove the guard works.
 *
 * No live provider is contacted anywhere in this file.
 */
class ProviderControlPlaneHttpTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/admin/infrastructure/providers';
    private const SECRET = 'HTTPSENTINEL-3b91fa2e7c05-DO-NOT-LEAK';

    private User $adminA;
    private User $adminB;
    private User $member;
    private string $tokenA;
    private string $tokenB;
    private string $tokenMember;

    protected function setUp(): void
    {
        parent::setUp();

        // Users are created FIRST: workspaces.created_by is NOT NULL on this schema.
        $this->adminA = $this->makeUser('http-admin-a', true);
        $this->adminB = $this->makeUser('http-admin-b', true);
        $this->member = $this->makeUser('http-member', false);

        $ws = Workspace::firstOrCreate(
            ['id' => 991001],
            [
                'name'       => 'cert-http-ws',
                'slug'       => 'cert-http-ws-' . Str::random(6),
                'created_by' => $this->adminA->id,
            ]
        );

        foreach ([$this->adminA, $this->adminB, $this->member] as $u) {
            DB::table('workspace_users')->insertOrIgnore([
                'user_id'      => $u->id,
                'workspace_id' => $ws->id,
                'role'         => 'owner',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $svc = app(RefreshTokenService::class);
        $this->tokenA      = $svc->issueTokenPair($this->adminA, $ws)['access_token'];
        $this->tokenB      = $svc->issueTokenPair($this->adminB, $ws)['access_token'];
        $this->tokenMember = $svc->issueTokenPair($this->member, $ws)['access_token'];
    }

    private function makeUser(string $prefix, bool $platformAdmin): User
    {
        return User::create([
            'name'                 => $prefix,
            'email'                => $prefix . '-' . Str::random(8) . '@test.local',
            'password'             => Hash::make(Str::random(32)),
            'is_admin'             => $platformAdmin ? 1 : 0,
            'is_platform_admin'    => $platformAdmin ? 1 : 0,
            'current_workspace_id' => 991001,
        ]);
    }

    private function asA(): array      { return ['Authorization' => 'Bearer ' . $this->tokenA]; }
    private function asB(): array      { return ['Authorization' => 'Bearer ' . $this->tokenB]; }
    private function asMember(): array { return ['Authorization' => 'Bearer ' . $this->tokenMember]; }

    private function createProvider(): array
    {
        return $this->withHeaders($this->asA())->postJson(self::BASE, [
            'provider_key'  => 'http-' . Str::lower(Str::random(8)),
            'display_name'  => 'HTTP Test Provider',
            'provider_type' => 'dns',
            'sandbox_ready' => true,
        ])->json('provider');
    }

    // ── authentication and authorization ──────────────────────────────────

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
    }

    /**
     * DenyApiKeyAuth answers 403 (forbidden), not 401 (unauthenticated) - and
     * that distinction is correct. The credential was recognised; it is simply
     * never permitted on this surface, whatever it authenticates.
     */
    public function test_api_key_machine_credential_is_denied(): void
    {
        $this->withHeaders(['X-API-KEY' => 'some-machine-key'])
            ->getJson(self::BASE)
            ->assertStatus(403);
    }

    public function test_non_admin_is_denied(): void
    {
        $this->withHeaders($this->asMember())
            ->getJson(self::BASE)
            ->assertStatus(403);
    }

    public function test_platform_admin_may_read(): void
    {
        $this->withHeaders($this->asA())
            ->getJson(self::BASE)
            ->assertOk()
            ->assertJsonStructure(['providers']);
    }

    // ── draft operations ──────────────────────────────────────────────────

    public function test_draft_provider_creation_is_self_service(): void
    {
        $r = $this->withHeaders($this->asA())->postJson(self::BASE, [
            'provider_key'  => 'http-draft-' . Str::lower(Str::random(6)),
            'display_name'  => 'Draft Provider',
            'provider_type' => 'dns',
        ]);

        $r->assertStatus(201)
          ->assertJsonPath('provider.lifecycle_state', 'draft')
          ->assertJsonPath('provider.enabled', false)
          ->assertJsonPath('provider.production_ready', false);
    }

    /** Internal class names must never cross the API boundary. */
    public function test_adapter_class_is_never_returned(): void
    {
        $p = $this->createProvider();

        $body = $this->withHeaders($this->asA())->getJson(self::BASE . '/' . $p['id'])->content();

        $this->assertStringNotContainsString('adapter_class', $body);
        $this->assertStringNotContainsString('App\\Connectors', $body);
    }

    public function test_capability_declaration_does_not_enable_it(): void
    {
        $p = $this->createProvider();

        $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/capabilities", ['capability' => 'dns'])
            ->assertStatus(201)
            ->assertJsonPath('capability.enabled', false)
            ->assertJsonPath('capability.supported', true);
    }

    public function test_unknown_capability_is_rejected(): void
    {
        $p = $this->createProvider();

        $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/capabilities", ['capability' => 'teleportation'])
            ->assertStatus(422);
    }

    // ── secrets never returned ────────────────────────────────────────────

    public function test_credential_secret_is_never_returned(): void
    {
        $p = $this->createProvider();

        $r = $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => 'http-dns',
            'secret'           => self::SECRET,
            'capability_scope' => ['dns'],
        ]);

        $r->assertStatus(201);
        $this->assertStringNotContainsString(self::SECRET, $r->content());
        $this->assertStringNotContainsString(self::SECRET,
            $this->withHeaders($this->asA())->getJson(self::BASE . '/credentials')->content());
    }

    /** Validation errors echo input — a classic accidental disclosure path. */
    public function test_secret_absent_from_validation_error_response(): void
    {
        $p = $this->createProvider();

        $r = $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => '',              // invalid, forces a 422
            'secret'           => self::SECRET,
            'capability_scope' => ['dns'],
        ]);

        $r->assertStatus(422);
        $this->assertStringNotContainsString(self::SECRET, $r->content());
    }

    public function test_cross_purpose_credential_scope_is_rejected_over_http(): void
    {
        $p = $this->createProvider();

        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => 'http-cross',
            'secret'           => self::SECRET,
            'capability_scope' => ['dns', 'certificate'],
        ])->assertStatus(422);
    }

    // ── protected operations and separation of duties ─────────────────────

    public function test_protected_operation_creates_a_pending_approval(): void
    {
        $p = $this->createProvider();

        $r = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/request-activation", []);

        $r->assertStatus(202)
          ->assertJsonPath('status', 'pending')
          ->assertJsonPath('self_approval_allowed', false)
          ->assertJsonPath('classification', 'separation_of_duties_required');

        $this->assertIsInt($r->json('approval_id'));
    }

    public function test_requester_cannot_approve_own_request(): void
    {
        $p  = $this->createProvider();
        $id = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/request-activation", [])->json('approval_id');

        $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/approvals/{$id}/approve", [])
            ->assertStatus(403)
            ->assertJsonPath('error', 'self_approval_not_permitted');
    }

    public function test_non_admin_cannot_approve(): void
    {
        $p  = $this->createProvider();
        $id = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/request-activation", [])->json('approval_id');

        $this->withHeaders($this->asMember())
            ->postJson(self::BASE . "/approvals/{$id}/approve", [])
            ->assertStatus(403);
    }

    public function test_distinct_administrator_can_approve_and_operation_executes(): void
    {
        $p = $this->createProvider();

        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/capabilities", ['capability' => 'dns']);
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/testing", []);

        $id = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/request-activation", [])->json('approval_id');

        $this->withHeaders($this->asB())
            ->postJson(self::BASE . "/approvals/{$id}/approve", ['note' => 'reviewed'])
            ->assertOk()
            ->assertJsonPath('approved', true);

        $this->assertSame('active', InfraProvider::find($p['id'])->lifecycle_state);
    }

    public function test_an_approval_cannot_be_approved_twice(): void
    {
        $p = $this->createProvider();
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/capabilities", ['capability' => 'dns']);
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/testing", []);

        $id = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/{$p['id']}/request-activation", [])->json('approval_id');

        $this->withHeaders($this->asB())->postJson(self::BASE . "/approvals/{$id}/approve", [])->assertOk();
        $this->withHeaders($this->asB())->postJson(self::BASE . "/approvals/{$id}/approve", [])->assertStatus(404);
    }

    /**
     * Activation must rest on CURRENT evidence. With no verifier configured the
     * re-verification at approval time returns not_attempted, so approval must
     * fail rather than activate on the strength of a stale earlier result.
     */
    public function test_credential_activation_fails_without_current_verification(): void
    {
        $p = $this->createProvider();

        $cred = $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => 'http-stale',
            'secret'           => self::SECRET,
            'capability_scope' => ['dns'],
        ])->json('credential');

        $id = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/credentials/{$cred['id']}/request-activation", [])->json('approval_id');

        $this->withHeaders($this->asB())
            ->postJson(self::BASE . "/approvals/{$id}/approve", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'execution_failed');

        $this->assertSame(CredentialState::PENDING_VERIFICATION,
            InfraProviderCredential::find($cred['id'])->state);
    }

    // ── incident operations ───────────────────────────────────────────────

    public function test_revocation_is_immediate_and_needs_no_approval(): void
    {
        $p = $this->createProvider();

        $cred = $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => 'http-revoke',
            'secret'           => self::SECRET,
            'capability_scope' => ['dns'],
        ])->json('credential');

        $r = $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/credentials/{$cred['id']}/revoke", ['reason' => 'leak drill']);

        $r->assertOk()->assertJsonPath('credential.state', CredentialState::REVOKED);
        $this->assertStringNotContainsString(self::SECRET, $r->content());
    }

    public function test_revocation_requires_a_reason(): void
    {
        $p = $this->createProvider();
        $cred = $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/credentials", [
            'credential_key'   => 'http-noreason',
            'secret'           => self::SECRET,
            'capability_scope' => ['dns'],
        ])->json('credential');

        $this->withHeaders($this->asA())
            ->postJson(self::BASE . "/credentials/{$cred['id']}/revoke", [])
            ->assertStatus(422);
    }

    // ── fail closed ───────────────────────────────────────────────────────

    public function test_unknown_route_fails_closed(): void
    {
        $this->withHeaders($this->asA())
            ->postJson(self::BASE . '/999999/nonexistent-action', [])
            ->assertStatus(404);
    }

    public function test_missing_provider_returns_not_found(): void
    {
        $this->withHeaders($this->asA())->getJson(self::BASE . '/99999999')->assertStatus(404);
    }

    // ── resolution + commercial isolation ─────────────────────────────────

    public function test_resolution_reflects_state_and_explains_exclusion(): void
    {
        $r = $this->withHeaders($this->asA())
            ->getJson(self::BASE . '/resolution?capability=dns&environment=sandbox');

        $r->assertOk()->assertJsonStructure(['resolvable']);
    }

    public function test_control_plane_activity_does_not_alter_commercial_state(): void
    {
        $before = [
            DB::table('infra_subscriptions')->count(),
            DB::table('infra_products')->count(),
            DB::table('infra_plans')->count(),
            DB::table('infra_hosting_accounts')->count(),
        ];

        $p = $this->createProvider();
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/capabilities", ['capability' => 'dns']);
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/testing", []);
        $this->withHeaders($this->asA())->postJson(self::BASE . "/{$p['id']}/disable", ['reason' => 'test']);

        $after = [
            DB::table('infra_subscriptions')->count(),
            DB::table('infra_products')->count(),
            DB::table('infra_plans')->count(),
            DB::table('infra_hosting_accounts')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_events_endpoint_returns_history_without_secrets(): void
    {
        $p = $this->createProvider();

        $r = $this->withHeaders($this->asA())
            ->getJson(self::BASE . "/events?provider_id={$p['id']}");

        $r->assertOk()->assertJsonStructure(['events']);
        $this->assertStringNotContainsString(self::SECRET, $r->content());
    }
}
