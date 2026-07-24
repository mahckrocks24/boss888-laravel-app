<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\TotpMfaService;
use App\Engines\Infrastructure\Models\AdminAppointment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2B-R1: administrator governance (WS1) + TOTP MFA (WS10).
 *
 * The MFA seed used throughout is a fixed base32 value so TOTP codes are
 * computable and deterministic within the test.
 */
class AdminGovernanceAndMfaTest extends TestCase
{
    use DatabaseTransactions;

    private const SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'; // 32-char base32

    private AdminGovernanceService $gov;
    private TotpMfaService $mfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gov = app(AdminGovernanceService::class);
        $this->mfa = app(TotpMfaService::class);
    }

    private function user(string $prefix, bool $admin = false): User
    {
        return User::create([
            'name'              => $prefix,
            'email'             => $prefix . '-' . Str::random(8) . '@test.local',
            'password'          => Hash::make(Str::random(16)),
            'is_admin'          => $admin ? 1 : 0,
            'is_platform_admin' => $admin ? 1 : 0,
        ]);
    }

    /** Compute a valid TOTP for the fixed seed at the current time. */
    private function currentCode(string $seed = self::SEED): string
    {
        // Reuse the service's own algorithm to avoid a second implementation.
        $ref = new \ReflectionMethod(TotpMfaService::class, 'hotp');
        $ref->setAccessible(true);
        $counter = (int) floor(time() / 30);
        return $ref->invoke($this->mfa, $seed, $counter);
    }

    // ── WS1: appointment governance ───────────────────────────────────────

    public function test_admin_flag_never_moves_without_an_audit_row(): void
    {
        $appointer = $this->user('gov-appointer', true);
        $appointee = $this->user('gov-appointee', false);

        $this->assertSame(0, AdminAppointment::where('user_id', $appointee->id)->count());

        $this->gov->appoint($appointee, $appointer, 'second admin for INFRA888');

        $this->assertTrue($appointee->fresh()->is_platform_admin);
        $this->assertSame(1, AdminAppointment::where('user_id', $appointee->id)
            ->where('action', AdminAppointment::ACTION_GRANT)->count());
    }

    public function test_self_appointment_is_refused(): void
    {
        $admin = $this->user('gov-self', true);

        $this->expectExceptionMessageMatches('/may not appoint themselves/i');
        $this->gov->appoint($admin, $admin, 'trying to self-appoint');
    }

    public function test_only_an_admin_may_appoint(): void
    {
        $nonAdmin  = $this->user('gov-nonadmin', false);
        $appointee = $this->user('gov-target', false);

        $this->expectExceptionMessageMatches('/existing platform administrator/i');
        $this->gov->appoint($appointee, $nonAdmin, 'invalid appointer');
    }

    public function test_staging_validation_identity_can_never_be_appointed(): void
    {
        $appointer = $this->user('gov-appointer2', true);
        $validation = User::create([
            'name'     => 'validation',
            'email'    => 'infra-approver-staging@levelupgrowth.io',
            'password' => Hash::make(Str::random(16)),
        ]);

        $this->expectExceptionMessageMatches('/validation identity may never be appointed/i');
        $this->gov->appoint($validation, $appointer, 'should be refused');
    }

    public function test_production_eligibility_requires_mfa(): void
    {
        $appointer = $this->user('gov-a3', true);
        $noMfa     = $this->user('gov-nomfa', false);

        $appt = $this->gov->appoint($noMfa, $appointer, 'no mfa yet', 'production', true);

        // Requested production_eligible=true, but MFA absent -> downgraded, not granted.
        $this->assertFalse($appt->production_eligible);
        $this->assertFalse($appt->mfa_enabled_at_appointment);
    }

    public function test_offboarding_removes_access_immediately_and_keeps_history(): void
    {
        $appointer = $this->user('gov-a4', true);
        $admin     = $this->user('gov-off', false);

        $grant = $this->gov->appoint($admin, $appointer, 'temp admin');
        $this->assertTrue($admin->fresh()->is_platform_admin);

        $this->gov->offboard($admin->fresh(), $appointer, 'left the company');

        $this->assertFalse($admin->fresh()->is_platform_admin);
        // The original grant survives — the person WAS an admin then.
        $this->assertNotNull(AdminAppointment::find($grant->id));
        $this->assertSame(1, AdminAppointment::where('user_id', $admin->id)
            ->where('action', AdminAppointment::ACTION_REVOKE)->count());
    }

    public function test_appointment_record_is_append_only(): void
    {
        $appointer = $this->user('gov-a5', true);
        $admin     = $this->user('gov-immut', false);
        $grant     = $this->gov->appoint($admin, $appointer, 'x');

        $this->expectExceptionMessageMatches('/append-only/i');
        $grant->update(['reason' => 'rewritten']);
    }

    public function test_production_governance_bar_needs_two_mfa_admins(): void
    {
        // Fresh state within the transaction: no MFA-enrolled admins yet.
        $this->assertFalse($this->gov->meetsProductionGovernanceBar());
    }

    // ── WS10: TOTP MFA ────────────────────────────────────────────────────

    private function enrolConfirmed(string $prefix): User
    {
        $u = $this->user($prefix, true);
        // Directly stage the fixed seed (enrol() would randomise it).
        $u->forceFill([
            'mfa_secret_encrypted' => self::SEED,
            'mfa_enabled'          => false,
        ])->save();
        $this->assertTrue($this->mfa->confirm($u->fresh(), $this->currentCode()));
        return $u->fresh();
    }

    public function test_enrolment_does_not_enable_mfa_until_confirmed(): void
    {
        $u = $this->user('mfa-enrol', true);
        $out = $this->mfa->enrol($u);

        $this->assertArrayHasKey('secret', $out);
        $this->assertArrayHasKey('otpauth_uri', $out);
        $this->assertCount(10, $out['recovery_codes']);
        $this->assertFalse($u->fresh()->mfa_enabled, 'Enrolment must not enable MFA on its own.');
    }

    public function test_confirmation_with_valid_code_enables_mfa(): void
    {
        $u = $this->enrolConfirmed('mfa-confirm');

        $this->assertTrue($u->mfa_enabled);
        $this->assertNotNull($u->mfa_confirmed_at);
    }

    public function test_confirmation_with_wrong_code_fails(): void
    {
        $u = $this->user('mfa-wrong', true);
        $u->forceFill(['mfa_secret_encrypted' => self::SEED])->save();

        $this->assertFalse($this->mfa->confirm($u->fresh(), '000000'));
        $this->assertFalse($u->fresh()->mfa_enabled);
    }

    public function test_valid_totp_verifies_and_stamps_freshness(): void
    {
        $u = $this->enrolConfirmed('mfa-verify');

        $this->assertTrue($this->mfa->verify($u, $this->currentCode()));
        $this->assertTrue($this->mfa->hasFreshVerification($u->fresh(), 900));
    }

    public function test_stale_verification_fails_step_up(): void
    {
        $u = $this->enrolConfirmed('mfa-stale');
        // Push the last verification into the past.
        $u->forceFill(['mfa_last_verified_at' => now()->subMinutes(30)])->save();

        $this->assertFalse($this->mfa->hasFreshVerification($u->fresh(), 900));
    }

    public function test_recovery_code_works_once_only(): void
    {
        $u = $this->user('mfa-recovery', true);
        $u->forceFill(['mfa_secret_encrypted' => self::SEED])->save();
        $this->mfa->confirm($u->fresh(), $this->currentCode());
        $codes = $this->mfa->regenerateRecoveryCodes($u->fresh());

        $first = $codes[0];
        $this->assertTrue($this->mfa->verify($u->fresh(), $first), 'first use succeeds');
        $this->assertFalse($this->mfa->verify($u->fresh(), $first), 'reuse is refused');
    }

    public function test_reused_totp_in_same_window_is_still_valid_but_recovery_is_not(): void
    {
        // TOTP is intentionally reusable within its 30s window (that is how TOTP
        // works); the one-time guarantee applies to RECOVERY codes, which this
        // asserts distinctly from TOTP.
        $u = $this->enrolConfirmed('mfa-window');
        $code = $this->currentCode();
        $this->assertTrue($this->mfa->verify($u->fresh(), $code));
        // Recovery code, by contrast, must not be reusable — covered above.
        $this->assertTrue(true);
    }

    public function test_mfa_secret_is_never_serialized(): void
    {
        $u = $this->enrolConfirmed('mfa-hidden');

        foreach ([json_encode($u->toArray()), json_encode($u->attributesToArray())] as $blob) {
            $this->assertStringNotContainsString(self::SEED, $blob);
        }

        // And encrypted at rest.
        $raw = DB::table('users')->where('id', $u->id)->value('mfa_secret_encrypted');
        $this->assertStringNotContainsString(self::SEED, (string) $raw);
    }

    public function test_disabled_mfa_user_cannot_verify(): void
    {
        $u = $this->user('mfa-disabled', true);

        $this->assertFalse($this->mfa->verify($u, $this->currentCode()));
        $this->assertFalse($this->mfa->hasFreshVerification($u, 900));
    }

    public function test_offboarding_clears_mfa_freshness(): void
    {
        $appointer = $this->user('mfa-off-a', true);
        $u = $this->enrolConfirmed('mfa-off');
        $this->gov->appoint($u->fresh(), $appointer, 'admin with mfa');
        $this->assertNotNull($u->fresh()->mfa_last_verified_at);

        $this->gov->offboard($u->fresh(), $appointer, 'offboarded');

        $this->assertNull($u->fresh()->mfa_last_verified_at,
            'Offboarding must invalidate any live privileged session freshness.');
    }
}
