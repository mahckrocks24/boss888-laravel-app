<?php

namespace App\Core\Governance;

/**
 * PERMISSION REGISTRY — the single lookup layer for privileged capability.
 * P0-B (2026-07-26). Governance Hardening.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before P0-B the platform held FOUR authority vocabularies that never spoke to
 * each other:
 *
 *   1. `AdminMiddleware`        — one boolean, `users.is_platform_admin`
 *   2. `ApprovalPolicyRegistry` — per-capability approval classification
 *   3. `AgentCapabilityService` — per-agent tool grants (agent_capabilities)
 *   4. `LaunchScopePolicy`      — removed engines/actions/agents/tools
 *
 * A forensic audit (LARAVEL-ADMIN-FORENSIC-AUDIT-2026-07-26.md) found zero
 * Gates, zero Policies, and 48 of 64 mutating admin routes without the
 * platform's own `DenyApiKeyAuth` control. The gap was never a missing
 * mechanism — it was four mechanisms with no shared lookup.
 *
 * WHY NOT A PACKAGE
 * -----------------
 * A third-party RBAC package (Spatie et al.) was explicitly REJECTED in
 * GD-007/AZ-1: it would add a FIFTH vocabulary beside the four above. This
 * registry deliberately mirrors the shape of `ApprovalPolicyRegistry` — static
 * map, explicit classification, fail-closed default — because that class
 * already proved the pattern works in production (125 approval rows).
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not decide anything. It is a pure, side-effect-free lookup. The
 * decision belongs to GovernanceService; the enforcement point belongs to a
 * Gate, a Policy, or middleware. Approval classification is NOT duplicated
 * here — `approvalPolicyFor()` defers to ApprovalPolicyRegistry.
 *
 * FAIL CLOSED
 * -----------
 * An unknown capability returns STRICT_DEFAULT (deny, owner-only, approval
 * required). Adding a capability to the platform means adding it here first.
 * This mirrors ApprovalPolicyRegistry::STRICT_DEFAULT.
 *
 * @see \App\Core\Governance\ApprovalPolicyRegistry  approval classification (authoritative)
 * @see \App\Core\Governance\GovernanceService       evaluation + shadow logging
 * @see CAPABILITY-REGISTRY.md                       the ratified P0-A catalogue
 */
final class PermissionRegistry
{
    // ── Principal classes (Masterplan §3.1) ──────────────────────────────
    public const P_OWNER        = 'platform_owner';
    public const P_ADMIN        = 'platform_admin';
    public const P_ENGINEERING  = 'engineering';
    public const P_SUPPORT      = 'support';
    public const P_FINANCE      = 'finance';
    public const P_MARKETING    = 'marketing';
    public const P_CUSTOMER     = 'customer';
    public const P_WORKSPACE    = 'workspace_user';
    public const P_API          = 'api';
    public const P_SERVICE      = 'service';
    public const P_SYSTEM       = 'system';
    public const P_MACHINE      = 'machine';

    // ── Risk levels (P0-A Capability Registry) ───────────────────────────
    public const R_READ     = 'read';
    public const R_LOW      = 'low';
    public const R_MEDIUM   = 'medium';
    public const R_HIGH     = 'high';
    public const R_CRITICAL = 'critical';

    /**
     * Fail-closed default for any capability not explicitly classified.
     * Mirrors ApprovalPolicyRegistry::STRICT_DEFAULT (GD-006 / AP-1).
     */
    private const STRICT_DEFAULT = [
        'roles'          => [self::P_OWNER],
        'approval'       => true,
        'mfa'            => true,
        'audit'          => true,
        'risk'           => self::R_CRITICAL,
        'machine_may_request' => false,
        'classification' => 'strict_default_unclassified',
    ];

    /**
     * Capability catalogue. Keys follow the platform's native `engine.action`
     * idiom, already used by ApprovalPolicyRegistry (GD-007 / AZ-2).
     *
     * @return array<string,array{roles:array<int,string>,approval:bool,mfa:bool,
     *   audit:bool,risk:string,machine_may_request:bool,classification:string}>
     */
    private static function map(): array
    {
        $owner  = self::P_OWNER;
        $admin  = self::P_ADMIN;
        $eng    = self::P_ENGINEERING;
        $sup    = self::P_SUPPORT;
        $fin    = self::P_FINANCE;
        $mkt    = self::P_MARKETING;
        $cust   = self::P_CUSTOMER;

        return [
            // ── IDENTITY & ACCESS ────────────────────────────────────────
            'admin.user_create'   => self::spec([$owner, $admin], true,  false, self::R_HIGH),
            'admin.user_update'   => self::spec([$owner, $admin], true,  false, self::R_HIGH),
            'admin.user_delete'   => self::spec([$owner],         true,  true,  self::R_CRITICAL),
            'admin.user_suspend'  => self::spec([$owner, $admin, $sup], true, false, self::R_HIGH, true),
            'admin.session_revoke'=> self::spec([$owner, $admin, $sup], false, false, self::R_MEDIUM),
            'admin.api_key_revoke'=> self::spec([$owner, $admin], false, false, self::R_HIGH),
            'admin.membership_update' => self::spec([$owner, $admin], true, false, self::R_HIGH),
            'admin.token_exchange'=> self::spec([$owner],         false, false, self::R_CRITICAL),

            // ── FINANCIAL ────────────────────────────────────────────────
            'billing.adjust_credits'       => self::spec([$owner, $admin, $fin], true, true,  self::R_CRITICAL, true),
            'billing.house_account_topup'  => self::spec([$owner, $fin],         true, true,  self::R_CRITICAL),
            'billing.house_account_settings'=> self::spec([$owner, $fin],        true, false, self::R_HIGH),
            'billing.assign_plan'          => self::spec([$owner, $admin, $fin], true, false, self::R_HIGH),
            'billing.plan_create'          => self::spec([$owner, $fin],         true, false, self::R_HIGH),
            'billing.plan_update'          => self::spec([$owner, $fin],         true, false, self::R_HIGH),

            // ── PLATFORM CONFIGURATION ───────────────────────────────────
            'platform.config_write'              => self::spec([$owner],        true,  true,  self::R_CRITICAL),
            'platform.engine_capability_update'  => self::spec([$owner, $admin],true,  false, self::R_HIGH),
            'platform.agent_update'              => self::spec([$owner, $admin],false, false, self::R_MEDIUM),
            'platform.agent_capability_grant'    => self::spec([$owner, $admin],true,  false, self::R_HIGH),
            'platform.agent_capability_revoke'   => self::spec([$owner, $admin],true,  false, self::R_HIGH),
            'platform.notification_broadcast'    => self::spec([$owner, $admin],true,  false, self::R_HIGH),
            'platform.failed_jobs_purge'         => self::spec([$owner],        true,  false, self::R_HIGH),

            // ── RUNTIME & DEPLOYMENT ─────────────────────────────────────
            'runtime.deploy'                => self::spec([$owner],              true,  true,  self::R_CRITICAL),
            'runtime.model_change'          => self::spec([$owner],              true,  true,  self::R_CRITICAL),
            'runtime.fallback_policy'       => self::spec([$owner],              true,  false, self::R_HIGH),
            'runtime.force_task_transition' => self::spec([$owner, $admin],      true,  false, self::R_HIGH),
            'runtime.task_retry'            => self::spec([$owner, $admin, $eng],false, false, self::R_MEDIUM),
            'runtime.task_cancel'           => self::spec([$owner, $admin, $eng],false, false, self::R_MEDIUM),
            'runtime.recover_orphans'       => self::spec([$owner, $admin, $eng],false, false, self::R_LOW),

            // ── INFRASTRUCTURE (approval classification is authoritative in
            //    ApprovalPolicyRegistry — not duplicated here) ────────────
            'infrastructure.provider_create'   => self::spec([$owner, $admin, $eng], true, false, self::R_HIGH),
            'infrastructure.credential_store'  => self::spec([$owner, $admin, $eng], true, false, self::R_CRITICAL),
            'infrastructure.credential_revoke' => self::spec([$owner, $admin, $eng], false, false, self::R_CRITICAL),
            'infrastructure.provider_disable'  => self::spec([$owner, $admin],       false, false, self::R_HIGH),
            'infrastructure.hosting_create'    => self::spec([$owner, $admin, $eng, $cust], true, false, self::R_HIGH),

            // ── DOMAINS ──────────────────────────────────────────────────
            'domain.connect'        => self::spec([$owner, $admin, $eng, $cust], true,  false, self::R_HIGH),
            'domain.remove'         => self::spec([$owner, $admin, $eng, $cust], true,  false, self::R_HIGH),
            'domain.subdomain_set'  => self::spec([$owner, $admin, $eng, $cust], false, false, self::R_MEDIUM),

            // ── DATA & CONTENT ───────────────────────────────────────────
            'data.media_bulk_delete' => self::spec([$owner, $admin, $mkt], true,  false, self::R_HIGH),
            'data.knowledge_purge'   => self::spec([$owner, $admin],       false, false, self::R_MEDIUM),
            'data.audit_log_read'    => self::spec([$owner, $admin, $eng, $sup, $fin], false, false, self::R_READ),

            // ── WORKSPACE LIFECYCLE ──────────────────────────────────────
            'workspace.update'             => self::spec([$owner, $admin], false, false, self::R_MEDIUM),
            // PLANNED — no route or method exists yet (P0-A §9 N-01/N-02).
            // Registered now so they are governed BEFORE they are built.
            'workspace.delete'             => self::spec([$owner], true, true, self::R_CRITICAL),
            'workspace.transfer_ownership' => self::spec([$owner], true, true, self::R_CRITICAL),

            // ── MACHINE / BELLA (GD-001, GD-005) ─────────────────────────
            // Bella is DORMANT. These entries DESCRIBE it to the governance
            // layer; they do not enable, expose, or alter it. Tier 3 entries
            // are request-only: machine_may_request = true, but MA-1/MA-3
            // forbid a machine ever approving.
            'bella.read_analytics'     => self::machine(self::R_READ,   false, false),
            'bella.read_queue'         => self::machine(self::R_READ,   false, false),
            'bella.read_engine_status' => self::machine(self::R_READ,   false, false),
            'bella.generate_report'    => self::machine(self::R_READ,   false, false),
            'bella.read_audit_logs'    => self::machine(self::R_LOW,    false, false),
            'bella.memory_write'       => self::machine(self::R_LOW,    false, false),
            'bella.list_users'         => self::machine(self::R_MEDIUM, false, false),
            'bella.get_workspace'      => self::machine(self::R_MEDIUM, false, false),
            // NOTE: `bella.adjust_credits`, `bella.suspend_user` and
            // `bella.query_database` are all DELIBERATELY ABSENT.
            //
            // `bella.query_database` was removed under GD-002 (2026-07-26).
            // `bella.adjust_credits` and `bella.suspend_user` were removed under
            // ENTERPRISE888 E0.5 (2026-07-27).
            //
            // Both of the latter were declared here as CRITICAL / approval /
            // MFA — and BellaController never called GovernanceService, so the
            // policy was entirely bypassed while the handlers mutated
            // `credits.balance` and `users.status` directly. Declaring a control
            // that the caller does not consult is not a control.
            //
            // The capabilities are now removed from the controller itself, so
            // enforcement no longer depends on governance mode at all.
            // Human admin equivalents remain, correctly gated:
            //   billing.adjust_credits  → AdminController@adjustCredits
            //   admin.user_suspend      → AdminController@suspendUser
            //
            // Do not repair, do not reintroduce under another name.
            // Removed under GD-002 (2026-07-26). Do not repair, do not replace
            // with unrestricted SQL, do not reintroduce under another name.
            // An unknown capability falls through to STRICT_DEFAULT = DENY.
        ];
    }

    /** Build a human-principal capability spec. */
    private static function spec(
        array $roles,
        bool $approval,
        bool $mfa,
        string $risk,
        bool $machineMayRequest = false,
    ): array {
        return [
            'roles'               => $roles,
            'approval'            => $approval,
            'mfa'                 => $mfa,
            'audit'               => true,
            'risk'                => $risk,
            'machine_may_request' => $machineMayRequest,
            'classification'      => 'classified',
        ];
    }

    /** Build a machine-principal capability spec (GD-005 MA-1…MA-6). */
    private static function machine(string $risk, bool $approval, bool $mfa): array
    {
        return [
            'roles'               => [self::P_MACHINE],
            'approval'            => $approval,
            'mfa'                 => $mfa,
            'audit'               => true,
            'risk'                => $risk,
            'machine_may_request' => true,
            'classification'      => 'machine_request_only',
        ];
    }

    // ── PUBLIC LOOKUP API ────────────────────────────────────────────────

    /** Every registered capability key. */
    public static function keys(): array
    {
        return array_keys(self::map());
    }

    /** True when the capability is explicitly classified. */
    public static function has(string $capability): bool
    {
        return array_key_exists($capability, self::map());
    }

    /**
     * Look up a capability. Unknown capabilities return STRICT_DEFAULT — deny,
     * owner-only, approval + MFA required (AP-1 fail-closed).
     */
    public static function get(string $capability): array
    {
        return self::map()[$capability] ?? self::STRICT_DEFAULT;
    }

    /** Roles permitted to exercise the capability. */
    public static function rolesFor(string $capability): array
    {
        return self::get($capability)['roles'];
    }

    public static function requiresApproval(string $capability): bool
    {
        return (bool) self::get($capability)['approval'];
    }

    public static function requiresMfa(string $capability): bool
    {
        return (bool) self::get($capability)['mfa'];
    }

    public static function requiresAudit(string $capability): bool
    {
        return (bool) self::get($capability)['audit'];
    }

    public static function riskOf(string $capability): string
    {
        return self::get($capability)['risk'];
    }

    /** GD-005 MA-2: may a machine principal REQUEST this capability? */
    public static function machineMayRequest(string $capability): bool
    {
        return (bool) self::get($capability)['machine_may_request'];
    }

    /**
     * GD-005 MA-1 / MA-3 — a machine principal may NEVER approve anything,
     * including its own request. This is unconditional and admits no
     * capability-level override.
     */
    public static function machineMayApprove(string $capability): bool
    {
        return false;
    }

    /**
     * Approval classification for a capability.
     *
     * Deliberately DELEGATES to ApprovalPolicyRegistry rather than duplicating
     * it — that class is the authoritative source for approval policy and is
     * already exercised in production. This is the "one approval lookup path"
     * success criterion.
     */
    public static function approvalPolicyFor(string $capability): ?array
    {
        if (! class_exists(ApprovalPolicyRegistry::class)) {
            return null;
        }
        foreach (['policyFor', 'for', 'get', 'resolve'] as $method) {
            if (method_exists(ApprovalPolicyRegistry::class, $method)) {
                try {
                    return ApprovalPolicyRegistry::{$method}($capability);
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /** Capabilities grouped by risk — used by tests and reporting. */
    public static function byRisk(string $risk): array
    {
        return array_keys(array_filter(self::map(), fn ($s) => $s['risk'] === $risk));
    }

    /** Capabilities available to a given principal role. */
    public static function forRole(string $role): array
    {
        return array_keys(array_filter(self::map(), fn ($s) => in_array($role, $s['roles'], true)));
    }
}
