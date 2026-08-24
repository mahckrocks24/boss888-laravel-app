<?php

namespace App\Core\Governance;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * GOVERNANCE GATE REGISTRAR — P0-B (2026-07-26).
 *
 * Registers one Gate per capability in the PermissionRegistry, plus the two
 * model-scoped Policies. Deliberately mirrors the existing
 * `InfrastructurePolicyRegistrar::register()` pattern already called from
 * AppServiceProvider::boot() — this codebase registers authorization
 * explicitly rather than by convention, and P0-B follows that precedent
 * instead of inventing a new ServiceProvider.
 *
 * ⚠️ THIS RUNS ON EVERY REQUEST AND EVERY QUEUE JOB.
 * It is the single highest-risk change in P0-B (impact matrix C-05). The whole
 * body is therefore wrapped to FAIL OPEN: any exception is logged and
 * swallowed, so a governance defect can never take the platform down.
 *
 * ZERO DUPLICATED AUTHORIZATION LOGIC: every Gate closure delegates to
 * GovernanceService, which delegates to PermissionRegistry. No Gate contains
 * its own role check.
 */
final class GovernanceGateRegistrar
{
    public static function register(): void
    {
        try {
            if ((string) config('governance.mode', 'shadow') === GovernanceService::MODE_OFF) {
                return;
            }

            if (config('governance.register_gates', true)) {
                self::registerGates();
            }

            if (config('governance.register_policies', true)) {
                self::registerPolicies();
            }
        } catch (\Throwable $e) {
            // FAIL OPEN — never break boot.
            try {
                Log::warning('[governance] gate registration failed — continuing without gates', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * One Gate per registered capability. Each closure is a thin delegation —
     * the authorization decision lives in exactly one place.
     */
    private static function registerGates(): void
    {
        foreach (PermissionRegistry::keys() as $capability) {
            Gate::define($capability, static function ($user = null, $resource = null) use ($capability) {
                try {
                    /** @var GovernanceService $svc */
                    $svc = app(GovernanceService::class);

                    $workspaceId = null;
                    if (is_object($resource) && isset($resource->workspace_id)) {
                        $workspaceId = (int) $resource->workspace_id;
                    } elseif (is_object($resource) && isset($resource->id)
                              && str_contains($capability, 'workspace.')) {
                        $workspaceId = (int) $resource->id;
                    }

                    return $svc->evaluate(
                        capability: $capability,
                        user: $user,
                        workspaceId: $workspaceId,
                        resource: is_object($resource) ? $resource::class : null,
                    )->effective;
                } catch (\Throwable $e) {
                    // FAIL OPEN — a gate must not break the request in P0-B.
                    try {
                        Log::debug('[governance] gate error, allowing: ' . $e->getMessage());
                    } catch (\Throwable) {
                    }
                    return true;
                }
            });
        }
    }

    /**
     * Model-scoped Policies only where they improve maintainability
     * (impact matrix C-06). No policy is created for non-model operations
     * such as `platform.config_write` — that would be framework purity, not
     * maintainability.
     */
    private static function registerPolicies(): void
    {
        $map = [
            \App\Models\User::class      => \App\Policies\UserPolicy::class,
            \App\Models\Workspace::class => \App\Policies\WorkspacePolicy::class,
        ];

        foreach ($map as $model => $policy) {
            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }
    }

    /** Diagnostic used by the governance test suite. */
    public static function registeredCapabilityCount(): int
    {
        return count(PermissionRegistry::keys());
    }
}
