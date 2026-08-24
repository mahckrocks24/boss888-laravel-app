<?php

/**
 * Governance layer configuration — P0-B (2026-07-26).
 *
 * The governance layer connects mechanisms that already exist
 * (ApprovalPolicyRegistry, LaunchScopePolicy, agent_capabilities, workspace
 * roles, middleware, AuditLogService) into one lookup path. It does not
 * replace any of them.
 *
 * MODE LADDER — see P0-B-IMPACT-MATRIX.md §4
 *
 *   off      registry inert · no gates registered · no audit    (kill switch)
 *   shadow   evaluate · log the would-be decision · ALWAYS ALLOW  ← P0-B
 *   audit    evaluate · log · always allow · emit events          ← P0-C
 *   enforce  evaluate · log · DENY                                ← P0-D
 *
 * P0-B ships in `shadow`. Governance must never be the reason a request fails
 * during this phase: every governance path is wrapped to fail OPEN.
 */
return [

    // off | shadow | audit | enforce
    'mode' => env('GOVERNANCE_MODE', 'shadow'),

    // Write a governance decision row to audit_logs. Reuses the existing table
    // and shape (action='governance.decision'); no migration is involved.
    'audit_decisions' => env('GOVERNANCE_AUDIT_DECISIONS', true),

    // Shadow-mode decisions are high-volume and low-signal. When true, only
    // decisions that WOULD have been denied are written.
    'audit_denials_only_in_shadow' => env('GOVERNANCE_AUDIT_DENIALS_ONLY', true),

    // Register Gates from the PermissionRegistry at boot.
    'register_gates' => env('GOVERNANCE_REGISTER_GATES', true),

    // Register the model-scoped Policies (User, Workspace).
    'register_policies' => env('GOVERNANCE_REGISTER_POLICIES', true),

    /**
     * Per-capability enforcement overrides, applied only when mode=enforce.
     * P0-D will populate this to roll enforcement out one capability at a
     * time rather than all 83 at once. Empty in P0-B.
     *
     *   'billing.adjust_credits' => 'enforce',
     */
    'capability_modes' => [],

];
