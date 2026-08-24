<?php

namespace App\Core\Governance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GOVERNANCE SERVICE — evaluates a capability for a principal. P0-B (2026-07-26).
 *
 * THE ONE RULE OF P0-B
 * --------------------
 * Governance must NEVER be the reason a request fails during this phase.
 * Every path here is wrapped so that any exception is logged and treated as
 * ALLOW. Shadow mode records what WOULD have happened and nothing more.
 *
 * PRINCIPAL RESOLUTION reuses what already exists — it invents no new identity:
 *   - `users.is_platform_admin`                  → platform_owner / platform_admin
 *   - request attribute `auth_via` (JwtAuthMiddleware) → api / service
 *   - request attribute `auth_via_claim`         → shared_admin_token provenance
 *   - `workspace_users.role`                     → customer / workspace_user
 *
 * AUDIT reuses the existing `audit_logs` table and `AuditLogService` shape.
 * No migration is involved (P0-B-IMPACT-MATRIX §2 C-03).
 *
 * @see PermissionRegistry   the single capability lookup
 * @see GovernanceDecision   the immutable outcome
 */
class GovernanceService
{
    public const MODE_OFF     = 'off';
    public const MODE_SHADOW  = 'shadow';
    public const MODE_AUDIT   = 'audit';
    public const MODE_ENFORCE = 'enforce';

    /** Effective mode for a capability (per-capability override wins). */
    public function mode(?string $capability = null): string
    {
        try {
            $global = (string) config('governance.mode', self::MODE_SHADOW);
            if ($capability !== null) {
                $overrides = (array) config('governance.capability_modes', []);
                if (isset($overrides[$capability])) {
                    return (string) $overrides[$capability];
                }
            }
            return $global;
        } catch (\Throwable) {
            return self::MODE_SHADOW;
        }
    }

    public function isEnforcing(?string $capability = null): bool
    {
        return $this->mode($capability) === self::MODE_ENFORCE;
    }

    /**
     * Evaluate a capability for a principal.
     *
     * ALWAYS returns a decision — never throws. In any mode other than
     * `enforce`, `effective` is true regardless of the conclusion.
     */
    public function evaluate(
        string $capability,
        mixed $user = null,
        ?int $workspaceId = null,
        ?string $resource = null,
        ?string $principalTypeOverride = null,
    ): GovernanceDecision {
        try {
            $mode = $this->mode($capability);

            if ($mode === self::MODE_OFF) {
                return new GovernanceDecision(
                    capability: $capability, allowed: true, effective: true,
                    mode: $mode, reason: 'governance_off',
                    principalType: 'unknown', workspaceId: $workspaceId, resource: $resource,
                );
            }

            $spec      = PermissionRegistry::get($capability);
            $principal = $principalTypeOverride ?? $this->resolvePrincipalType($user);

            [$allowed, $reason] = $this->conclude($capability, $spec, $principal);

            // Outside enforce mode the caller's behaviour is unchanged.
            $effective = $mode === self::MODE_ENFORCE ? $allowed : true;

            $decision = new GovernanceDecision(
                capability: $capability,
                allowed: $allowed,
                effective: $effective,
                mode: $mode,
                reason: $reason,
                principalType: $principal,
                principalId: is_object($user) ? ($user->id ?? null) : null,
                workspaceId: $workspaceId,
                resource: $resource,
                risk: $spec['risk'],
                approvalRequired: (bool) $spec['approval'],
                mfaRequired: (bool) $spec['mfa'],
            );

            $this->record($decision);

            return $decision;
        } catch (\Throwable $e) {
            // FAIL OPEN. Governance never breaks a request in P0-B.
            try {
                Log::warning('[governance] evaluation failed — failing open', [
                    'capability' => $capability,
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }

            return new GovernanceDecision(
                capability: $capability, allowed: true, effective: true,
                mode: self::MODE_SHADOW, reason: 'evaluation_error_fail_open',
                principalType: 'unknown', workspaceId: $workspaceId, resource: $resource,
            );
        }
    }

    /** Convenience: the answer a Gate should return. */
    public function allows(string $capability, mixed $user = null, ?int $workspaceId = null): bool
    {
        return $this->evaluate($capability, $user, $workspaceId)->effective;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * Reach a conclusion. Reads only what the platform already knows; it does
     * not consult a role table, because none exists yet (that arrives in P0-D).
     *
     * @return array{0:bool,1:string}
     */
    private function conclude(string $capability, array $spec, string $principal): array
    {
        if (! PermissionRegistry::has($capability)) {
            return [false, 'unclassified_capability_strict_default'];
        }

        // GD-005 MA-1/MA-3 — a machine may never approve, and may only act on
        // capabilities explicitly marked request-only.
        if ($principal === PermissionRegistry::P_MACHINE) {
            if (! PermissionRegistry::machineMayRequest($capability)) {
                return [false, 'machine_principal_not_permitted'];
            }
            if ($spec['approval']) {
                return [false, 'machine_request_requires_human_approval'];
            }
            return [true, 'machine_request_permitted'];
        }

        // The owner bootstrap: until P0-D introduces roles, `is_platform_admin`
        // is the only durable authority signal (GD-007 / AZ-4).
        if (in_array($principal, [PermissionRegistry::P_OWNER, PermissionRegistry::P_ADMIN], true)) {
            if (in_array(PermissionRegistry::P_OWNER, $spec['roles'], true)
                || in_array(PermissionRegistry::P_ADMIN, $spec['roles'], true)) {
                return [true, 'platform_admin_permitted'];
            }
            return [false, 'capability_not_granted_to_platform_admin'];
        }

        // Machine credentials can never satisfy privileged capability
        // (mirrors DenyApiKeyAuth + RequireMfaStepUp precedent).
        if (in_array($principal, [PermissionRegistry::P_API, PermissionRegistry::P_SERVICE], true)) {
            return [false, 'machine_credential_not_permitted'];
        }

        if (in_array($principal, $spec['roles'], true)) {
            return [true, 'role_permitted'];
        }

        return [false, 'role_not_permitted'];
    }

    /** Map an authenticated user to a principal class using existing signals. */
    private function resolvePrincipalType(mixed $user): string
    {
        if ($user === null) {
            return 'anonymous';
        }

        try {
            $request = request();
            if ($request) {
                $via = $request->attributes->get('auth_via');
                if ($via === 'api_key') {
                    return PermissionRegistry::P_API;
                }
            }
        } catch (\Throwable) {
        }

        if (is_object($user) && ! empty($user->is_platform_admin)) {
            // Until P0-D, user 1 is treated as the owner bootstrap; every other
            // platform admin is P_ADMIN.
            return ((int) ($user->id ?? 0) === 1)
                ? PermissionRegistry::P_OWNER
                : PermissionRegistry::P_ADMIN;
        }

        return PermissionRegistry::P_WORKSPACE;
    }

    /**
     * Persist the decision to `audit_logs`. Never throws; audit failure must
     * not affect the request. In shadow mode only divergences are written by
     * default, because shadow decisions are high-volume and low-signal.
     */
    private function record(GovernanceDecision $d): void
    {
        try {
            if (! config('governance.audit_decisions', true)) {
                return;
            }
            if ($d->mode === self::MODE_SHADOW
                && config('governance.audit_denials_only_in_shadow', true)
                && ! $d->isShadowDivergence()) {
                return;
            }

            DB::table('audit_logs')->insert([
                'workspace_id'  => $d->workspaceId,
                'user_id'       => $d->principalId,
                'action'        => 'governance.decision',
                'entity_type'   => 'governance',
                'entity_id'     => null,
                'metadata_json' => json_encode($d->toArray()),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            try {
                Log::debug('[governance] audit write failed: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }
}
