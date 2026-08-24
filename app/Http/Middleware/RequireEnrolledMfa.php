<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENTERPRISE888 E0.5 — fail-closed MFA enrolment gate.
 *
 * WHY THIS EXISTS SEPARATELY FROM `mfa.stepup`
 * `RequireMfaStepUp` deliberately STANDS DOWN before governance is activated:
 * while `wasEverActivated()` is false it passes the request through with
 * `X-INFRA-MFA-Stepup: disabled-below-governance-bar`, and activation requires
 * two MFA-enrolled admins. Measured 2026-07-27: governance state is `bootstrap`
 * and BOTH platform admins have `mfa_enabled = 0`.
 *
 * So applying `mfa.stepup` to a high-risk surface today would NOT protect it.
 * This middleware has no bootstrap path and no governance dependency: no
 * confirmed MFA enrolment, no access. Ever.
 *
 * Applied to Bella's admin routes, which have never been used in production.
 * The intended effect is that Bella stays closed until an administrator
 * completes real MFA enrolment.
 */
class RequireEnrolledMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Authentication is required.',
            ], 401);
        }

        // A machine identity can never satisfy a human second factor.
        if ($request->attributes->get('auth_via') === 'api_key') {
            return response()->json([
                'error'   => 'mfa_enrolment_required',
                'message' => 'This surface requires an individual human administrator with '
                           . 'multi-factor authentication enrolled.',
            ], 403);
        }

        $enrolled = (bool) ($user->mfa_enabled ?? false)
                 && !empty($user->mfa_secret_encrypted)
                 && !empty($user->mfa_confirmed_at);

        if (!$enrolled) {
            return response()->json([
                'error'   => 'mfa_enrolment_required',
                'message' => 'Multi-factor authentication must be enrolled and confirmed before '
                           . 'this surface can be used. Enrol at POST /api/admin/mfa/enrol, then '
                           . 'confirm with a code from your authenticator at POST /api/admin/mfa/confirm.',
            ], 403);
        }

        return $next($request);
    }
}
