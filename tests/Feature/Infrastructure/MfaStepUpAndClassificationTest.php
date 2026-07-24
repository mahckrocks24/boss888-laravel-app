<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\RefreshTokenService;
use App\Core\Auth\TotpMfaService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2B-R2 — MFA HTTP enforcement (WS13) + account classification (WS14).
 *
 * Real JWTs through the real middleware stack. The step-up middleware
 * self-disables below the two-MFA-admin governance bar, so these tests must
 * first PUSH the platform over that bar (two enrolled MFA admins) before
 * enforcement is observable — which is itself the WS3 safe-activation rule under
 * test.
 */
class MfaStepUpAndClassificationTest extends TestCase
{
    use DatabaseTransactions;

    private const SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const BASE = '/api/admin/infrastructure/providers';

    private TotpMfaService $mfa;
    private AdminGovernanceService $gov;
    private int $wsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mfa = app(TotpMfaService::class);
        $this->gov = app(AdminGovernanceService::class);
    }

    private function currentCode(): string
    {
        $ref = new \ReflectionMethod(TotpMfaService::class, 'hotp');
        $ref->setAccessible(true);
        return $ref->invoke($this->mfa, self::SEED, (int) floor(time() / 30));
    }

    private function makeUser(string $prefix, bool $admin, string $class = 'standard'): User
    {
        return User::create([
            'name'                   => $prefix,
            'email'                  => $prefix . '-' . Str::random(8) . '@test.local',
            'password'               => Hash::make(Str::random(16)),
            'is_admin'               => $admin ? 1 : 0,
            'is_platform_admin'      => $admin ? 1 : 0,
            'account_classification' => $class,
        ]);
    }

    private function enrolMfa(User $u): User
    {
        $u->forceFill(['mfa_secret_encrypted' => self::SEED, 'mfa_enabled' => false])->save();
        $this->mfa->confirm($u->fresh(), $this->currentCode());
        return $u->fresh();
    }

    private function token(User $u): string
    {
        if (!$this->wsId ?? false) {
            // no-op guard; wsId set in bootstrapTwoAdmins
        }
        return app(RefreshTokenService::class)
            ->issueTokenPair($u, Workspace::find($this->wsId))['access_token'];
    }

    /** Push the platform over the two-MFA-admin governance bar. */
    private function bootstrapTwoAdmins(): array
    {
        $a = $this->enrolMfa($this->makeUser('mfa-http-a', true));
        $b = $this->enrolMfa($this->makeUser('mfa-http-b', true));

        $ws = Workspace::firstOrCreate(
            ['id' => 992001],
            ['name' => 'mfa-http-ws', 'slug' => 'mfa-http-' . Str::random(6), 'created_by' => $a->id]
        );
        $this->wsId = $ws->id;

        foreach ([$a, $b] as $u) {
            DB::table('workspace_users')->insertOrIgnore([
                'user_id' => $u->id, 'workspace_id' => $ws->id,
                'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertTrue($this->gov->meetsProductionGovernanceBar(),
            'Two enrolled MFA admins should meet the governance bar.');

        return [$a->fresh(), $b->fresh()];
    }

    private function makeProviderAndApproval(User $requester): int
    {
        $token = $this->token($requester);
        $p = $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson(self::BASE, [
            'provider_key'  => 'mfa-' . Str::lower(Str::random(8)),
            'display_name'  => 'MFA test',
            'provider_type' => 'dns',
            'sandbox_ready' => true,
        ])->json('provider');

        $cred = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(self::BASE . "/{$p['id']}/credentials", [
                'credential_key' => 'c', 'secret' => 'seedseedseed', 'capability_scope' => ['dns'],
            ])->json('credential');

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(self::BASE . "/credentials/{$cred['id']}/request-activation", [])
            ->json('approval_id');
    }

    // ── WS14: account classification ──────────────────────────────────────

    public function test_validation_only_account_cannot_be_appointed(): void
    {
        $appointer = $this->makeUser('cls-appointer', true);
        $validation = $this->makeUser('cls-validation', false, AdminGovernanceService::CLASS_VALIDATION_ONLY);

        $this->expectExceptionMessageMatches('/may never hold platform governance/i');
        $this->gov->appoint($validation, $appointer, 'should be refused by classification');
    }

    public function test_service_account_cannot_be_appointed(): void
    {
        $appointer = $this->makeUser('cls-appointer2', true);
        $service = $this->makeUser('cls-service', false, AdminGovernanceService::CLASS_SERVICE);

        $this->expectExceptionMessageMatches('/may never hold platform governance/i');
        $this->gov->appoint($service, $appointer, 'should be refused');
    }

    public function test_standard_account_can_be_appointed(): void
    {
        $appointer = $this->makeUser('cls-appointer3', true);
        $standard = $this->makeUser('cls-standard', false, AdminGovernanceService::CLASS_STANDARD);

        $appt = $this->gov->appoint($standard, $appointer, 'valid appointment');
        $this->assertTrue($standard->fresh()->is_platform_admin);
        $this->assertNotNull($appt->id);
    }

    public function test_classification_excludes_from_production_eligible_count(): void
    {
        // A validation-only admin WITH mfa must not count toward the bar.
        $v = $this->makeUser('cls-count', true, AdminGovernanceService::CLASS_VALIDATION_ONLY);
        $this->enrolMfa($v);

        // Only one standard MFA admin -> bar still not met.
        $this->enrolMfa($this->makeUser('cls-count-std', true));

        $this->assertFalse($this->gov->meetsProductionGovernanceBar(),
            'A validation_only account must not count toward the production bar.');
    }

    public function test_seeded_validation_identity_is_classified(): void
    {
        // The migration reclassified user 990016; if present it must be validation_only.
        $u = User::where('email', 'infra-approver-staging@levelupgrowth.io')->first();
        if ($u) {
            $this->assertSame('validation_only', $u->account_classification);
        } else {
            $this->markTestSkipped('validation identity not present in this database');
        }
    }

    // ── WS3/WS13: MFA step-up enforcement ─────────────────────────────────

    public function test_step_up_self_disables_below_governance_bar(): void
    {
        // Single admin, no second MFA human -> bar not met -> step-up stands down.
        $admin = $this->enrolMfa($this->makeUser('su-solo', true));
        $ws = Workspace::firstOrCreate(['id' => 992002],
            ['name' => 'su-ws', 'slug' => 'su-' . Str::random(6), 'created_by' => $admin->id]);
        $this->wsId = $ws->id;
        DB::table('workspace_users')->insertOrIgnore([
            'user_id' => $admin->id, 'workspace_id' => $ws->id,
            'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($this->gov->meetsProductionGovernanceBar());

        // A protected approve call should NOT be blocked by step-up (it stands
        // down), though it may fail later for unrelated reasons; we assert the
        // header proving the gate deliberately stood down.
        $id = $this->makeProviderAndApproval($admin);
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($admin)])
            ->postJson(self::BASE . "/approvals/{$id}/approve", []);

        $this->assertSame('disabled-below-governance-bar', $r->headers->get('X-INFRA-MFA-Stepup'));
    }

    public function test_fresh_mfa_admin_passes_step_up(): void
    {
        [$a, $b] = $this->bootstrapTwoAdmins();

        // A requests; B (fresh MFA) approves. B just confirmed, so freshness holds.
        $id = $this->makeProviderAndApproval($a);
        $this->mfa->verify($b, $this->currentCode()); // refresh B's step-up

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($b->fresh())])
            ->postJson(self::BASE . "/approvals/{$id}/approve", []);

        // Step-up must NOT block (403 mfa_step_up_required). Any other outcome is
        // acceptable here — we are testing the gate, not the approval logic.
        $this->assertNotSame('mfa_step_up_required', $r->json('error'),
            'A fresh-MFA admin must pass the step-up gate.');
    }

    public function test_stale_mfa_admin_is_challenged(): void
    {
        [$a, $b] = $this->bootstrapTwoAdmins();
        $id = $this->makeProviderAndApproval($a);

        // Age B's verification beyond the 900s window.
        $b->forceFill(['mfa_last_verified_at' => now()->subMinutes(30)])->save();

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($b->fresh())])
            ->postJson(self::BASE . "/approvals/{$id}/approve", []);

        $r->assertStatus(403)->assertJsonPath('error', 'mfa_step_up_required');
    }

    public function test_incident_revocation_is_not_behind_step_up(): void
    {
        [$a] = $this->bootstrapTwoAdmins();
        $token = $this->token($a);

        $p = $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson(self::BASE, [
            'provider_key' => 'inc-' . Str::lower(Str::random(8)),
            'display_name' => 'incident', 'provider_type' => 'dns', 'sandbox_ready' => true,
        ])->json('provider');
        $cred = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(self::BASE . "/{$p['id']}/credentials",
                ['credential_key' => 'r', 'secret' => 'seedseedseed', 'capability_scope' => ['dns']])
            ->json('credential');

        // A has NOT refreshed step-up recently, yet revocation must still work —
        // the incident route is deliberately not wrapped in mfa.stepup.
        $a->forceFill(['mfa_last_verified_at' => now()->subHours(2)])->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($a->fresh())])
            ->postJson(self::BASE . "/credentials/{$cred['id']}/revoke", ['reason' => 'leak drill'])
            ->assertOk();
    }

    public function test_mfa_verify_endpoint_refreshes_freshness_and_hides_code(): void
    {
        [$a] = $this->bootstrapTwoAdmins();
        $a->forceFill(['mfa_last_verified_at' => now()->subMinutes(30)])->save();
        $this->assertFalse($this->mfa->hasFreshVerification($a->fresh(), 900));

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($a->fresh())])
            ->postJson('/api/admin/mfa/verify', ['code' => $this->currentCode()]);

        $r->assertOk()->assertJsonPath('verified', true);
        $this->assertTrue($this->mfa->hasFreshVerification($a->fresh(), 900));
        $this->assertStringNotContainsString($this->currentCode(), $r->content());
    }

    public function test_wrong_totp_at_verify_endpoint_fails_without_leaking(): void
    {
        [$a] = $this->bootstrapTwoAdmins();

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($a)])
            ->postJson('/api/admin/mfa/verify', ['code' => '000000'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'mfa_verification_failed');
    }

    public function test_mfa_routes_reject_api_key(): void
    {
        $this->withHeaders(['X-API-KEY' => 'machine'])
            ->postJson('/api/admin/mfa/verify', ['code' => '123456'])
            ->assertStatus(403);
    }
}
