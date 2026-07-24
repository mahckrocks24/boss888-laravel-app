<?php
/**
 * INFRA888 Provider Certification #001 — Cloudflare, read-only.
 *
 * This PERSISTS. It is a real certification record, not a probe: Cloudflare is
 * genuinely a provider we are evaluating, and the verdict below is the honest
 * outcome of the evidence gathered on 2026-07-19.
 *
 * The provider is registered as DRAFT and DISABLED. Registration is Level 0 by
 * definition and grants nothing — no capability is enabled, no credential is
 * stored, and nothing routes to it.
 *
 * ALL EVIDENCE IS READ-ONLY. Every API call cited was a GET. No DNS record, no
 * custom hostname, no certificate and no zone setting was created or altered.
 *
 * Idempotent: re-running recertifies rather than duplicating.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Engines\Infrastructure\Models\InfraCertificationCheck as Check;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCertification as Cert;
use App\Engines\Infrastructure\Services\ProviderCertificationService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\States\CertificationLevel as Level;

$registry = app(ProviderRegistryService::class);
$certSvc  = app(ProviderCertificationService::class);

$ASSESSOR = 1;
$PROBE    = 'scripts/infra888-cf-preflight.sh @ 2026-07-19';

// ── register (Level 0, inert) ─────────────────────────────────────────────
$provider = InfraProvider::where('provider_key', 'cloudflare')->first();

if (!$provider) {
    $provider = $registry->register([
        'provider_key'      => 'cloudflare',
        'display_name'      => 'Cloudflare',
        'provider_type'     => 'composite',
        'environments_json' => ['sandbox', 'production'],
        'regions_json'      => [],
        'currencies_json'   => ['USD'],
        'sandbox_ready'     => false,
        'production_ready'  => false,
        'operational_notes' => 'Registered for certification only. NOT enabled. '
            . 'Zone ownership (D1) and credential scope (D2) unresolved.',
    ], $ASSESSOR);

    foreach (['dns', 'certificate', 'custom_hostname'] as $cap) {
        $registry->declareCapability($provider, $cap, 'production',
            ['supported' => true, 'notes' => 'Declared for certification. NOT enabled.'], $ASSESSOR);
    }
    echo "Registered provider 'cloudflare' as DRAFT (disabled, no capabilities enabled).\n";
} else {
    echo "Provider 'cloudflare' already registered (id={$provider->id}).\n";
}

echo "  lifecycle={$provider->lifecycle_state} enabled="
   . var_export((bool) $provider->enabled, true)
   . " production_ready=" . var_export((bool) $provider->production_ready, true) . "\n\n";

// ── certification ─────────────────────────────────────────────────────────
// Chain from the latest prior attempt regardless of its verdict — a failed
// assessment is history too, and orphaning it would leave two unsuperseded
// certifications claiming to describe the same provider.
$previous = Cert::where('provider_id', $provider->id)
    ->where('capability', 'dns')
    ->where('environment', 'production')
    ->whereNull('superseded_by_id')
    ->whereIn('status', Cert::terminalStatuses())
    ->orderByDesc('id')
    ->first();

$cert = $previous
    ? $certSvc->recertify($previous, 'certification_expiry', Level::READ_ONLY_VERIFIED, $ASSESSOR)
    : $certSvc->start($provider, 'dns', 'production', Level::READ_ONLY_VERIFIED,
        $ASSESSOR, 'INFRA888 read-only preflight');

echo "Certification {$cert->certification_uid}\n";
echo "  target: " . Level::label($cert->level_target) . "\n";
echo "  checks: " . $cert->checks()->count() . "\n\n";

/**
 * Evidence recorded below is EXACTLY what the probes returned. Where access was
 * denied, the result is `blocked` with the provider's own error code — denied
 * access is evidence of missing scope, never evidence of a working capability.
 */
$findings = [

    // ── A. OWNERSHIP ──────────────────────────────────────────────────────
    ['ownership.account_owner_identified', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'GET /zones/{id} -> account.name',
     ['account_name' => "Markfloresraymundo@gmail.com's Account",
      'account_id'   => 'cf2a61033cf3c724aaa0b9f4c2832a23'],
     'Owner IS identified — and is a personal account. Identification passes; suitability does not.'],

    ['ownership.resource_identity_verified', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'GET /zones/{id}',
     ['zone' => 'levelupgrowth.io', 'status' => 'active', 'type' => 'full',
      'nameservers' => ['chase.ns.cloudflare.com', 'clarissa.ns.cloudflare.com'],
      'original_registrar' => 'godaddy.com, llc'],
     'Zone identity confirmed directly from the API.'],

    ['ownership.account_identifier_recorded', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'GET /zones/{id} -> account.id',
     ['account_id' => 'cf2a61033cf3c724aaa0b9f4c2832a23'], null],

    // ── B. CREDENTIALS ────────────────────────────────────────────────────
    ['credentials.purpose_declared', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'CredentialPurposeRegistry + ProviderCertificationTest',
     ['purposes' => ['dns_operations', 'certificate_operations', 'readonly_diagnostics']],
     'Purpose binding enforced at creation; cross-purpose scope refused.'],

    ['credentials.granted_scopes_verified', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'GET /user/tokens/verify + capability probes',
     ['token_id' => '46f8da4fbbe48e8dbd3e598b0d82570b', 'token_status' => 'active',
      'granted' => ['dns_records:read', 'dns_records:edit', 'zone:read', 'dnssec:read'],
      'account_list_result' => 'success but EMPTY — token holds no account-level scope'],
     'Scope determined by probing, not by assumption.'],

    ['credentials.missing_scopes_documented', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'capability probes — denials enumerated',
     ['zone_settings'     => 'HTTP 403 code 9109',
      'ssl_setting'       => 'HTTP 403 code 9109',
      'custom_hostnames'  => 'HTTP 403 code 10000',
      'fallback_origin'   => 'HTTP 403 code 10000',
      'certificate_packs' => 'HTTP 403 code 9109',
      'firewall_rules'    => 'HTTP 403 code 10000',
      'page_rules'        => 'HTTP 403 code 9109',
      'rulesets'          => 'HTTP 403 code 10000',
      'workers_routes'    => 'HTTP 403 code 10000',
      'analytics'         => 'HTTP 403 code 10000'],
     'Ten denied endpoints enumerated. 9109 = valid token lacking permission; '
     . '10000 = token not permitted for that endpoint group at all.'],

    ['credentials.encrypted_at_rest', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'ProviderCredentialTest::test_secret_is_encrypted_at_rest', [], null],

    ['credentials.never_disclosed', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'sentinel sweep across 7 surfaces — 0 leaks', [], null],

    // ── C. CAPABILITY ─────────────────────────────────────────────────────
    ['capability.read_operations_verified', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'GET /zones/{id}/dns_records — 17 records returned',
     ['records' => 17, 'types' => ['A' => 6, 'CNAME' => 9, 'TXT' => 2], 'proxied' => 14],
     'DNS read verified live.'],

    ['capability.unsupported_documented', Check::RESULT_PASS, Check::EVIDENCE_READONLY_API,
     'meta.custom_certificate_quota from GET /zones/{id}',
     ['plan' => 'Free Website', 'custom_certificate_quota' => 0, 'page_rule_quota' => 3],
     'Free plan reports custom_certificate_quota = 0 — a TIER limit independent of token scope.'],

    // ── E. SECURITY ───────────────────────────────────────────────────────
    // These are platform controls rather than Cloudflare controls, but they are
    // required at Level 1 because a provider cannot be "read-only verified" if
    // the platform holding its credential leaks secrets. Evidence is the runtime
    // sentinel sweep and framework tests from this phase and 2B-G.
    ['security.no_plaintext_serialization', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'ProviderCredentialTest + infra888-verify-governance.php sentinel sweep',
     ['surfaces' => ['toArray', 'attributesToArray', 'toSafeArray', 'var_dump'], 'leaks' => 0], null],

    ['security.no_secret_in_logs', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'sentinel sweep — exception rendering and queue serialize',
     ['leaks' => 0], null],

    ['security.no_secret_in_events', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'sentinel sweep across infra_provider_events',
     ['leaks' => 0], null],

    ['security.no_secret_in_approvals', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'sentinel sweep across approvals.data_json',
     ['leaks' => 0], null],

    ['security.audit_attribution', Check::RESULT_PASS, Check::EVIDENCE_TEST,
     'infra888-verify-governance.php — both administrators attributed distinctly',
     ['proof' => 'events carry actor_user_id and actor_type'], null],
];

foreach ($findings as [$key, $result, $evType, $ref, $ev, $note]) {
    $certSvc->recordCheck($cert, $key, $result, $evType, $ref, $ev, $ASSESSOR, $note, 'high');
}

// ── BLOCKED: denied by scope, and one genuinely untestable ────────────────
$blocked = [
    ['capability.write_operations_verified',
     'Not attempted. Writing to a live production zone is forbidden by directive.'],
    ['capability.delete_operations_verified',
     'Not attempted. Deleting from a live production zone is forbidden by directive.'],
];

foreach ($blocked as [$key, $why]) {
    if (\App\Engines\Infrastructure\Models\InfraCertificationCheck::where('certification_id', $cert->id)
        ->where('check_key', $key)->exists()) {
        $certSvc->recordCheck($cert, $key, Check::RESULT_BLOCKED, Check::EVIDENCE_ATTESTATION,
            'Phase 2B-CERT hard rules', ['reason' => $why], $ASSESSOR, $why, 'high');
    }
}

$concluded = $certSvc->conclude($cert->fresh(), $ASSESSOR);

// ── report ────────────────────────────────────────────────────────────────
echo str_repeat('=', 72) . "\n";
echo "  CLOUDFLARE CERTIFICATION #001 — RESULT\n";
echo str_repeat('=', 72) . "\n";
echo "  status : {$concluded->status}\n";
echo "  level  : " . Level::label($concluded->level) . "\n";
echo "  target : " . Level::label($concluded->level_target) . "\n";
echo "  checks : {$concluded->checks_passed}/{$concluded->checks_total} satisfied, "
   . "{$concluded->checks_failed} failed, {$concluded->checks_blocked} blocked, "
   . "{$concluded->checks_not_tested} not tested\n";
echo "  expires: " . optional($concluded->expires_at)->toDateString() . "\n\n";

echo "  BLOCKERS TO THE NEXT LEVEL (" . Level::label($concluded->level + 1) . "):\n";
foreach (($concluded->blockers_json ?? []) as $b) {
    printf("    [%s] %-46s %s\n",
        $b['critical'] ? 'CRITICAL' : '        ', $b['check_key'], $b['result']);
}

echo "\n  PRODUCTION SELECTABLE: "
   . var_export($concluded->permitsProduction(), true) . "\n";
echo "  PROVIDER ENABLED     : "
   . var_export((bool) $provider->fresh()->enabled, true) . "\n";
echo str_repeat('=', 72) . "\n";
