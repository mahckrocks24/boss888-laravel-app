<?php

namespace App\Http\Controllers\Auth;

use App\Core\Auth\TotpMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MFA enrolment and step-up verification endpoints (Phase 2B-R2, WS3).
 *
 * Behind `auth.jwt` + `DenyApiKeyAuth`: only an individually authenticated human
 * may enrol or verify. A machine identity has no second factor to present.
 *
 * 🔴 The TOTP seed leaves the server exactly ONCE — in the enrol() response so a
 * QR can be rendered. It is never returned again. Recovery codes likewise are
 * shown once and stored only as hashes.
 */
class MfaController extends Controller
{
    public function __construct(private readonly TotpMfaService $mfa)
    {
    }

    /** Begin enrolment. Returns seed + otpauth URI + recovery codes ONCE. MFA stays off. */
    public function enrol(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return response()->json([
                'error'   => 'mfa_already_enabled',
                'message' => 'MFA is already active. Regenerate recovery codes instead of re-enrolling.',
            ], 409);
        }

        $out = $this->mfa->enrol($user);

        return response()->json([
            'otpauth_uri'    => $out['otpauth_uri'],
            'secret'         => $out['secret'],          // shown once, for QR entry
            'recovery_codes' => $out['recovery_codes'],  // shown once
            'note'           => 'Confirm with a code from your authenticator to activate MFA. '
                              . 'The secret and recovery codes will not be shown again.',
        ]);
    }

    /** Confirm enrolment with a live code. Only now is MFA enabled. */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $ok = $this->mfa->confirm($request->user(), (string) $request->input('code'));

        return response()->json(
            $ok ? ['confirmed' => true] : ['error' => 'invalid_code'],
            $ok ? 200 : 422
        );
    }

    /**
     * Step-up: verify a factor to refresh privileged-session freshness.
     *
     * This is the endpoint a client calls before a protected control-plane
     * operation when its step-up has gone stale. On success it stamps the
     * freshness anchor RequireMfaStepUp reads.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $ok = $this->mfa->verify($request->user(), (string) $request->input('code'));

        // 🔴 The submitted code is never echoed, even on failure.
        return response()->json(
            $ok
                ? ['verified' => true, 'fresh_until_secs' => 900]
                : ['error' => 'mfa_verification_failed'],
            $ok ? 200 : 403
        );
    }

    /** Regenerate one-time recovery codes. Shown once. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->mfa_enabled) {
            return response()->json(['error' => 'mfa_not_enabled'], 409);
        }

        return response()->json([
            'recovery_codes' => $this->mfa->regenerateRecoveryCodes($user),
            'note'           => 'Previous recovery codes are now invalid. Store these securely.',
        ]);
    }
}
