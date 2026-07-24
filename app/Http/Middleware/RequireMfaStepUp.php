<?php

namespace App\Http\Middleware;

use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\TotpMfaService;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA step-up gate for privileged control-plane operations.
 *
 * Phase 2B-R2 built this as a simple self-disabling gate. Phase 2B-R3 (WS4)
 * makes it a STATE MACHINE, because the R2 version was fail-open after
 * activation: offboard an admin, drop below the bar, and enforcement silently
 * vanished. The directive: "falling below the governance bar must block
 * privileged operations rather than automatically disabling MFA."
 *
 * FOUR STATES:
 *
 *   BOOTSTRAP (never activated)
 *     Step-up stands down. This is the ONLY state where self-disable is
 *     legitimate — you cannot enrol the second admin if step-up locks the
 *     enrolment route. Header advertises the stand-down.
 *
 *   ACTIVATED + bar met
 *     Normal enforcement: a fresh second factor from an individual human is
 *     required. Stale → 403 step-up. Machine identity → 403.
 *
 *   ACTIVATED + bar NOT met (DEGRADED)
 *     🔴 FAIL CLOSED. Privileged operations are BLOCKED, not silently allowed.
 *     Restoring a second admin requires an explicit recovery transition. This is
 *     the whole point of WS4 — losing an admin must never be a way to switch off
 *     MFA.
 *
 *   RECOVERY (explicit, audited, time-boxed)
 *     A human has deliberately entered break-glass to restore governance. Step-up
 *     is still required from the acting human (no machine, no stale session), but
 *     the degraded-block is lifted so a replacement admin can be appointed. The
 *     window expires; it is never open-ended.
 *
 * In every state, the incident routes (credential revocation, provider disable)
 * are handled at the ROUTE level and are NOT wrapped in this middleware, so a
 * leaked credential can always be killed by an authenticated human.
 */
class RequireMfaStepUp
{
    public function __construct(
        private readonly TotpMfaService $mfa,
        private readonly AdminGovernanceService $governance,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $window = null): Response
    {
        $maxAgeSeconds = (int) ($window ?? 900);
        $state = $this->governance->governanceState();

        // ── BOOTSTRAP — stand down (pre-activation only) ────────────────────
        if (!$state->wasEverActivated()) {
            // Opportunistically activate if the bar is now met, so the very first
            // privileged call after two admins enrol flips into enforcement.
            if ($this->governance->maybeActivateGovernance()) {
                $state = $this->governance->governanceState(); // refresh: now activated
            } else {
                $response = $next($request);
                $response->headers->set('X-INFRA-MFA-Stepup', 'disabled-below-governance-bar');
                return $response;
            }
        }

        $user = $request->user();

        // A machine identity can never satisfy step-up, in any activated state.
        if (!$user || $request->attributes->get('auth_via') === 'api_key') {
            return $this->deny('mfa_step_up_required',
                'This operation requires a recently verified second factor from an individual '
                . 'human administrator.');
        }

        // ── DEGRADED — activated but below the bar, and not in recovery ─────
        // Fail closed. The R2 fail-open is exactly this branch, now blocked.
        if ($this->governance->isDegraded()) {
            return $this->deny('governance_degraded',
                'Platform governance has fallen below the required two administrators. '
                . 'Privileged operations are blocked until governance is restored through an '
                . 'explicit recovery. Emergency credential revocation remains available.', 423);
        }

        // ── RECOVERY — break-glass window; still require a fresh human factor ─
        // (No special-casing needed beyond the freshness check below: recovery
        // lifts the degraded block but never the human-factor requirement.)
        if ($state->mode === InfraGovernanceState::MODE_RECOVERY && !$state->inRecovery()) {
            // Recovery window expired while still degraded → back to fail-closed.
            if ($this->governance->isDegraded()) {
                return $this->deny('governance_degraded',
                    'The recovery window has expired and governance is still below the bar.', 423);
            }
        }

        // ── ENFORCE freshness ───────────────────────────────────────────────
        if (!$this->mfa->hasFreshVerification($user, $maxAgeSeconds)) {
            return $this->deny('mfa_step_up_required',
                'Your second-factor verification has expired. Re-verify to continue with this '
                . 'privileged operation.', 403, ['freshness_secs' => $maxAgeSeconds]);
        }

        return $next($request);
    }

    private function deny(string $error, string $message, int $status = 403, array $extra = []): Response
    {
        return response()->json(array_merge([
            'error'   => $error,
            'message' => $message,
        ], $extra), $status);
    }
}
