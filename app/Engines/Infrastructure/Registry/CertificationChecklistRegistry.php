<?php

namespace App\Engines\Infrastructure\Registry;

/**
 * The reusable provider-certification checklist (Phase 2B-CERT, WS2).
 *
 * PROVIDER-NEUTRAL. Not one check names a vendor. The same eight domains certify
 * a DNS provider, a registrar, a mail provider or a backup provider.
 *
 * `critical` IS THE LOAD-BEARING FLAG. A critical check that fails can never be
 * waived into an unconditional pass — the best a waiver achieves is
 * `passed_with_conditions`. These are the controls where being wrong means
 * losing customer infrastructure or losing control of who can change it, so
 * "we accepted the risk" must remain visible in the verdict forever.
 *
 * `capabilities` scopes a check to the capabilities it applies to. A propagation
 * check is meaningless for a monitoring provider; marking it `not_applicable`
 * by hand every time would invite marking real gaps the same way.
 */
final class CertificationChecklistRegistry
{
    public const DOMAIN_OWNERSHIP   = 'A';
    public const DOMAIN_CREDENTIALS = 'B';
    public const DOMAIN_CAPABILITY  = 'C';
    public const DOMAIN_RUNTIME     = 'D';
    public const DOMAIN_SECURITY    = 'E';
    public const DOMAIN_GOVERNANCE  = 'F';
    public const DOMAIN_OPERATIONAL = 'G';
    public const DOMAIN_COMMERCIAL  = 'H';

    public static function domains(): array
    {
        return [
            self::DOMAIN_OWNERSHIP   => 'Ownership and identity',
            self::DOMAIN_CREDENTIALS => 'Authentication and credentials',
            self::DOMAIN_CAPABILITY  => 'Provider capability',
            self::DOMAIN_RUNTIME     => 'Runtime behaviour',
            self::DOMAIN_SECURITY    => 'Security',
            self::DOMAIN_GOVERNANCE  => 'Governance',
            self::DOMAIN_OPERATIONAL => 'Operational readiness',
            self::DOMAIN_COMMERCIAL  => 'Commercial readiness',
        ];
    }

    /**
     * @return array<string,array{domain:string,critical:bool,description:string,
     *                            min_level:int,capabilities?:array}>
     */
    public static function checks(): array
    {
        return [
            // ── A. OWNERSHIP AND IDENTITY ────────────────────────────────
            'ownership.account_owner_identified' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 1,
                'description' => 'The account owner is identified and recorded.'],
            'ownership.organizationally_controlled' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 4,
                'description' => 'Production account is owned by the company, not an individual.'],
            'ownership.billing_owner' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => false, 'min_level' => 4,
                'description' => 'Billing ownership is company-controlled and documented.'],
            'ownership.recovery_owner' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 4,
                'description' => 'Account recovery (email/phone) is company-controlled.'],
            'ownership.account_identifier_recorded' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => false, 'min_level' => 1,
                'description' => 'Provider account/tenant identifier is captured from the API.'],
            'ownership.resource_identity_verified' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 1,
                'description' => 'The zone/resource being managed is identified and confirmed.'],
            'ownership.two_admins' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 4,
                'description' => 'At least two individually attributable human administrators.'],
            'ownership.no_shared_login' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 4,
                'description' => 'No shared administrative login is in use.'],
            'ownership.no_personal_dependency' => [
                'domain' => self::DOMAIN_OWNERSHIP, 'critical' => true, 'min_level' => 4,
                'description' => 'Production does not depend on a personal account.'],

            // ── B. AUTHENTICATION AND CREDENTIALS ────────────────────────
            'credentials.purpose_declared' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 1,
                'description' => 'Each credential is bound to exactly one declared purpose.'],
            'credentials.granted_scopes_verified' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 1,
                'description' => 'Exact granted scopes verified against the provider, not assumed.'],
            'credentials.missing_scopes_documented' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 1,
                'description' => 'Scopes the credential lacks are enumerated as evidence.'],
            'credentials.no_excess_scope' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 2,
                'description' => 'Credential holds no authority beyond its purpose.'],
            'credentials.expiry_known' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => false, 'min_level' => 2,
                'description' => 'Credential expiry is known or explicitly unlimited.'],
            'credentials.rotation_proven' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 3,
                'description' => 'Rotation without overwrite is proven end to end.'],
            'credentials.revocation_proven' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 3,
                'description' => 'Immediate revocation is proven and takes effect at once.'],
            'credentials.capability_mapping_enforced' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 2,
                'description' => 'Capability-to-credential mapping is enforced structurally.'],
            'credentials.environment_restricted' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => false, 'min_level' => 3,
                'description' => 'Credential is restricted to one environment.'],
            'credentials.encrypted_at_rest' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 1,
                'description' => 'Secret material is encrypted at rest.'],
            'credentials.never_disclosed' => [
                'domain' => self::DOMAIN_CREDENTIALS, 'critical' => true, 'min_level' => 1,
                'description' => 'Secret is never returned, logged or evented.'],

            // ── C. PROVIDER CAPABILITY ───────────────────────────────────
            'capability.read_operations_verified' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => true, 'min_level' => 1,
                'description' => 'Read operations verified against the live API.'],
            'capability.write_operations_verified' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => true, 'min_level' => 2,
                'description' => 'Write operations verified in a safe environment.'],
            'capability.delete_operations_verified' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => true, 'min_level' => 2,
                'description' => 'Delete operations verified in a safe environment.'],
            'capability.unsupported_documented' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 1,
                'description' => 'Operations the provider does not support are documented.'],
            'capability.propagation_understood' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 2,
                'description' => 'Propagation / eventual-consistency behaviour is characterised.',
                'capabilities' => ['dns', 'certificate', 'custom_hostname']],
            'capability.quota_known' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 2,
                'description' => 'Quotas are known.'],
            'capability.rate_limits_known' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 2,
                'description' => 'Rate limits are known.'],
            'capability.api_version_pinned' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 3,
                'description' => 'The API version in use is recorded.'],
            'capability.account_tier_sufficient' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => true, 'min_level' => 2,
                'description' => 'The account tier actually includes the required capability.'],
            'capability.sandbox_available' => [
                'domain' => self::DOMAIN_CAPABILITY, 'critical' => false, 'min_level' => 2,
                'description' => 'A sandbox or disposable test resource is available.'],

            // ── D. RUNTIME BEHAVIOUR ─────────────────────────────────────
            'runtime.auth_failure_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 2,
                'description' => 'Authentication failure is classified and not retried blindly.'],
            'runtime.unauthorized_scope_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 2,
                'description' => 'Insufficient scope is distinguished from provider failure.'],
            'runtime.missing_resource_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => false, 'min_level' => 2,
                'description' => 'Missing/invalid resources produce a normalized result.'],
            'runtime.timeout_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 2,
                'description' => 'Timeouts are handled and classified retryable.'],
            'runtime.rate_limit_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 2,
                'description' => 'Rate limiting defers rather than storms.'],
            'runtime.quota_exhaustion_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => false, 'min_level' => 2,
                'description' => 'Quota exhaustion is distinguished from failure.'],
            'runtime.idempotency_verified' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 2,
                'description' => 'Duplicate requests do not double-apply.'],
            'runtime.partial_failure_handled' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 3,
                'description' => 'Partial failure leaves no untracked provider resource.'],
            'runtime.recovery_after_failure' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => false, 'min_level' => 3,
                'description' => 'Health recovers correctly after failure.'],
            'runtime.stale_verification_rejected' => [
                'domain' => self::DOMAIN_RUNTIME, 'critical' => true, 'min_level' => 3,
                'description' => 'Stale verification cannot authorize activation.'],

            // ── E. SECURITY ──────────────────────────────────────────────
            'security.no_plaintext_serialization' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 1,
                'description' => 'No secret in any serialization path.'],
            'security.no_secret_in_logs' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 1,
                'description' => 'No secret reaches logs.'],
            'security.no_secret_in_events' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 1,
                'description' => 'No secret reaches the event store.'],
            'security.no_secret_in_approvals' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 1,
                'description' => 'No secret reaches approval payloads.'],
            'security.least_privilege' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 2,
                'description' => 'Credentials hold least privilege for their purpose.'],
            'security.capability_isolation' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 2,
                'description' => 'One capability cannot borrow another capability\'s credential.'],
            'security.audit_attribution' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 1,
                'description' => 'Every action is attributed to an individual identity.'],
            'security.incident_revocation' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 3,
                'description' => 'Emergency revocation is immediate and unblocked.'],
            'security.over_privilege_rejected' => [
                'domain' => self::DOMAIN_SECURITY, 'critical' => true, 'min_level' => 2,
                'description' => 'Over-privileged credentials are rejected or warned.'],

            // ── F. GOVERNANCE ────────────────────────────────────────────
            'governance.requester_attribution' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Requester identity is recorded on every protected action.'],
            'governance.approver_attribution' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Approver identity is recorded and distinct.'],
            'governance.self_approval_blocked' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Requester cannot approve their own request.'],
            'governance.machine_approval_blocked' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Machine credentials cannot approve.'],
            'governance.protected_activation' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Activation is approval-gated.'],
            'governance.protected_rotation' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 3,
                'description' => 'Rotation is approval-gated.'],
            'governance.emergency_revocation_unilateral' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => false, 'min_level' => 3,
                'description' => 'Revocation is deliberately not approval-gated.'],
            'governance.second_human_admin' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 4,
                'description' => 'A genuine second human administrator exists.'],
            'governance.admin_mfa' => [
                'domain' => self::DOMAIN_GOVERNANCE, 'critical' => true, 'min_level' => 4,
                'description' => 'Platform administrators are protected by MFA.'],

            // ── G. OPERATIONAL READINESS ─────────────────────────────────
            'operational.health_monitoring' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => true, 'min_level' => 3,
                'description' => 'Provider and capability health is monitored.'],
            'operational.incident_history' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => false, 'min_level' => 4,
                'description' => 'Incident history is retained and reviewable.'],
            'operational.maintenance_handling' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => false, 'min_level' => 4,
                'description' => 'Planned maintenance is distinguishable from an incident.'],
            'operational.support_channel' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => true, 'min_level' => 4,
                'description' => 'A support channel and escalation path exist.'],
            'operational.rollback_plan' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => true, 'min_level' => 4,
                'description' => 'A tested rollback procedure exists.'],
            'operational.resource_inventory' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => true, 'min_level' => 3,
                'description' => 'Every provider resource we own is inventoried.'],
            'operational.business_continuity' => [
                'domain' => self::DOMAIN_OPERATIONAL, 'critical' => true, 'min_level' => 4,
                'description' => 'Loss of the provider has a documented continuity plan.'],

            // ── H. COMMERCIAL READINESS ──────────────────────────────────
            'commercial.pricing_source' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => true, 'min_level' => 4,
                'description' => 'Authoritative pricing source is recorded.'],
            'commercial.currency_and_unit' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => true, 'min_level' => 4,
                'description' => 'Currency and billing unit are known.'],
            'commercial.overage_model' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => false, 'min_level' => 4,
                'description' => 'Included quota and overage behaviour are known.'],
            'commercial.invoice_available' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => false, 'min_level' => 4,
                'description' => 'Provider invoices are obtainable for reconciliation.'],
            'commercial.cost_ingestion_method' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => true, 'min_level' => 4,
                'description' => 'A method exists to ingest provider cost.'],
            'commercial.margin_understood' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => true, 'min_level' => 4,
                'description' => 'Expected gross margin is calculated, not assumed.'],
            'commercial.tier_dependency_documented' => [
                'domain' => self::DOMAIN_COMMERCIAL, 'critical' => true, 'min_level' => 4,
                'description' => 'Capability dependence on account tier is documented.'],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::checks()[$key] ?? null;
    }

    public static function isCritical(string $key): bool
    {
        return (bool) (self::checks()[$key]['critical'] ?? false);
    }

    /** Checks that must be answered to claim a level, for a given capability. */
    public static function forLevel(int $level, ?string $capability = null): array
    {
        $out = [];

        foreach (self::checks() as $key => $meta) {
            if ($meta['min_level'] > $level) {
                continue;
            }

            // Capability-scoped checks are skipped for capabilities they do not
            // describe, rather than answered `not_applicable` by hand — which
            // would train assessors to use that result carelessly.
            if ($capability !== null
                && isset($meta['capabilities'])
                && !in_array($capability, $meta['capabilities'], true)) {
                continue;
            }

            $out[$key] = $meta;
        }

        return $out;
    }

    public static function criticalKeys(): array
    {
        return array_keys(array_filter(self::checks(), fn ($m) => $m['critical']));
    }
}
