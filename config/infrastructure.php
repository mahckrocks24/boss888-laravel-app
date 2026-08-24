<?php

use App\Connectors\Infrastructure\Null\NullCustomHostnameConnector;
use App\Connectors\Infrastructure\Null\NullHostingConnector;

/**
 * INFRA888 configuration.
 *
 * NOTE: this file EXISTS, unlike config/services.php which CustomDomainService
 * reads from and which is absent on this install (Phase 0 audit §2.1) — that
 * service survives only on its env() fallback. Same defect class in
 * config/billing.php (missing chatbot_addon_price_id). INFRA888 does not repeat it:
 * every config key this engine reads is declared here.
 *
 * NO SECRETS IN THIS FILE. Credentials are referenced by env key name only.
 */
return [

    /*
     |--------------------------------------------------------------------------
     | Capability -> connector map
     |--------------------------------------------------------------------------
     | Defaults are the Null connectors. A capability stays a deterministic no-op
     | until a real provider is deliberately configured — an unconfigured provider
     | must never half-work.
     */
    // array_filter: an unset connector must be ABSENT, not present-and-null.
    // A null entry still counts as a configured capability to anything that
    // iterates this map, which made 'registrar' look declared while being
    // unresolvable.
    'connectors' => array_filter([

        // Domain registrar. Namecheap is the first adapter; it resolves only
        // when explicitly selected, so the default remains unset and loud.
        // 2026-08-11. This line set Namecheap as the DEFAULT, contradicting the
        // comment directly above it ("resolves only when explicitly selected, so
        // the default remains unset and loud"). The comment was right.
        //
        // NamecheapClient takes seven required constructor arguments and has no
        // container binding, so resolving the registrar threw
        // BindingResolutionException instead of the RuntimeException the
        // resolver contract promises - an unconfigured capability that failed
        // in the wrong shape rather than loudly. Opt in with the env var.
        "registrar" => env("INFRA_REGISTRAR_CONNECTOR"),

        'hosting'         => env('INFRA_HOSTING_CONNECTOR', NullHostingConnector::class),
        'custom_hostname' => env('INFRA_CUSTOM_HOSTNAME_CONNECTOR', NullCustomHostnameConnector::class),
        // registrar / email / backup / monitoring are intentionally absent until
        // their modules are built (Phase 1C / 1D). Resolving them throws, loudly.
    ]),

    /*
     |--------------------------------------------------------------------------
     | Null connector behaviour (non-production only)
     |--------------------------------------------------------------------------
     | Deterministic outcome for the Null connectors so the governed lifecycle can
     | be proven without a provider: success | retryable_failure | terminal_failure.
     | Never random (directive 13).
     */
    'null_connector' => [
        'outcome' => env('INFRA_NULL_OUTCOME', 'success'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Operation execution
     |--------------------------------------------------------------------------
     */
    'operations' => [
        'default_max_attempts' => (int) env('INFRA_OP_MAX_ATTEMPTS', 3),
        'timeout_seconds'      => (int) env('INFRA_OP_TIMEOUT', 300),
        // A reaper sweeps operations stuck beyond this. Mirrors the existing
        // tasks:recover-orphans / studio:reap-stuck-renders pattern.
        'stuck_after_minutes'  => (int) env('INFRA_OP_STUCK_MINUTES', 30),
    ],

    /*
     |--------------------------------------------------------------------------
     | Provider resource synchronization
     |--------------------------------------------------------------------------
     | How old a provider_resources row may be before it is considered stale and
     | must not be presented to a customer as current truth.
     */
    'sync' => [
        'stale_after_minutes' => (int) env('INFRA_SYNC_STALE_MINUTES', 60),
    ],

    /*
     |--------------------------------------------------------------------------
     | Commercial defaults
     |--------------------------------------------------------------------------
     | Currency has NO platform-wide default answer yet (open question in
     | CLAUDE.md). It is required per plan; this is only the fallback used when
     | seeding catalog rows.
     */
    /*
     |--------------------------------------------------------------------------
     | Credential verifiers (Phase 2B-3)
     |--------------------------------------------------------------------------
     | provider_key => class implementing CredentialVerifier.
     |
     | Any provider absent from this map falls back to NullCredentialVerifier,
     | which ALWAYS returns notAttempted() and therefore can never activate a
     | credential. That is deliberate: with no real verifier there is no
     | evidence, and without evidence activation must be impossible.
     |
     | NO SECRETS HERE. This maps provider keys to verifier CLASSES only.
     | Credentials live encrypted in infra_provider_credentials, never in
     | configuration and never in source control.
     */
    'credential_verifiers' => [
        // Populated in Phase 2B-5 once a real provider is integrated.
    ],

    'default_currency' => env('INFRA_DEFAULT_CURRENCY', 'USD'),

    /*
     |--------------------------------------------------------------------------
     | Feature exposure
     |--------------------------------------------------------------------------
     | Gates the Infrastructure section. Mirrors the frontend data-feature key and
     | must stay in sync with PlanGatingService.
     */
    'feature_key' => 'infrastructure',

    /*
     |--------------------------------------------------------------------------
     | Product entitlement (directive 1C section 3)
     |--------------------------------------------------------------------------
     | Keys read from plans.features_json - the same payload /workspace/status
     | exposes to the SPA - so backend enforcement and frontend visibility cannot
     | disagree. Configurable because commercial tiers are NOT finalised.
     */
    'entitlement' => [
        'access_key'      => env('INFRA_ENT_ACCESS_KEY', 'infrastructure_access'),
        'hosting_key'     => env('INFRA_ENT_HOSTING_KEY', 'hosting_access'),
        'sites_limit_key' => env('INFRA_ENT_SITES_LIMIT_KEY', 'hosting_sites_limit'),
    ],
];
