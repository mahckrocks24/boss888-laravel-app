<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\RequireEnrolledMfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE888 E0.5 — fail-closed MFA enrolment gate on Bella.
 *
 * `RequireMfaStepUp` deliberately STANDS DOWN before governance activation, and
 * activation requires two MFA-enrolled admins — of which there are currently
 * zero. So `mfa.stepup` alone would have left Bella unprotected. These tests pin
 * the behaviour of the dedicated gate, which has no bootstrap path.
 */
class AdminMfaEnforcementTest extends TestCase
{
    private function pass(): \Closure
    {
        return fn () => response()->json(['ok' => true]);
    }

    private function userStub(array $attrs): object
    {
        return new class($attrs) {
            public function __construct(private array $a) {}
            public function __get($k) { return $this->a[$k] ?? null; }
            public function __isset($k) { return isset($this->a[$k]); }
        };
    }

    private function through(?object $user, ?string $authVia = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/api/admin/bella', 'POST');
        $request->setUserResolver(fn () => $user);
        if ($authVia !== null) {
            $request->attributes->set('auth_via', $authVia);
        }

        return (new RequireEnrolledMfa())->handle($request, $this->pass());
    }

    // ── denials ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_denied(): void
    {
        $r = $this->through(null);
        $this->assertSame(401, $r->getStatusCode());
        $this->assertSame('unauthenticated', json_decode($r->getContent(), true)['error']);
    }

    public function test_machine_identity_can_never_satisfy_the_gate(): void
    {
        $user = $this->userStub([
            'mfa_enabled' => true, 'mfa_secret_encrypted' => 'x', 'mfa_confirmed_at' => now(),
        ]);
        $r = $this->through($user, 'api_key');

        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame('mfa_enrolment_required', json_decode($r->getContent(), true)['error']);
    }

    public function test_admin_without_mfa_is_denied(): void
    {
        $r = $this->through($this->userStub([
            'mfa_enabled' => false, 'mfa_secret_encrypted' => null, 'mfa_confirmed_at' => null,
        ]));
        $this->assertSame(403, $r->getStatusCode());
    }

    public function test_enrolled_but_unconfirmed_admin_is_denied(): void
    {
        // Enrolment started (secret issued) but never confirmed with a code.
        $r = $this->through($this->userStub([
            'mfa_enabled' => true, 'mfa_secret_encrypted' => 'secret', 'mfa_confirmed_at' => null,
        ]));
        $this->assertSame(403, $r->getStatusCode(),
            'a half-finished enrolment must not grant access');
    }

    public function test_flag_set_without_a_secret_is_denied(): void
    {
        // Guards against someone flipping mfa_enabled in the database.
        $r = $this->through($this->userStub([
            'mfa_enabled' => true, 'mfa_secret_encrypted' => null, 'mfa_confirmed_at' => now(),
        ]));
        $this->assertSame(403, $r->getStatusCode(),
            'the boolean alone must not be sufficient');
    }

    // ── the one allow ───────────────────────────────────────────────────────

    public function test_fully_enrolled_admin_is_allowed(): void
    {
        $r = $this->through($this->userStub([
            'mfa_enabled' => true, 'mfa_secret_encrypted' => 'secret', 'mfa_confirmed_at' => now(),
        ]));
        $this->assertSame(200, $r->getStatusCode());
        $this->assertTrue(json_decode($r->getContent(), true)['ok']);
    }

    // ── wiring ──────────────────────────────────────────────────────────────

    public function test_all_bella_routes_carry_the_gate(): void
    {
        $gated = 0;
        $bella = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/admin/bella')) {
                continue;
            }
            $bella++;
            $this->assertContains('mfa.enrolled', $route->gatherMiddleware(),
                "Bella route {$route->uri()} is missing the MFA gate");
            $gated++;
        }

        $this->assertSame(4, $bella, 'expected exactly 4 Bella routes');
        $this->assertSame(4, $gated);
    }

    public function test_the_gate_is_not_applied_to_other_admin_routes(): void
    {
        // E0.5 must not lock administrators out of the wider admin panel.
        $others = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/')
                && !str_starts_with($route->uri(), 'api/admin/bella')
                && in_array('mfa.enrolled', $route->gatherMiddleware(), true)) {
                $others++;
            }
        }

        $this->assertSame(0, $others,
            'E0.5 scope is Bella only — other admin routes must be unaffected');
    }

    /*
     * NOTE: administrator MFA enrolment state is NOT asserted here.
     *
     * This suite runs against `levelup_chat_test`, which has no platform
     * administrators, so any assertion about real enrolment would be testing
     * the wrong database. Enrolment state is verified against production by
     * scripts/e05_production_verify.sh and recorded in
     * ADMIN-MFA-ENFORCEMENT-REPORT.md.
     */
}
