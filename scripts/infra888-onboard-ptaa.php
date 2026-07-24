<?php
/**
 * INFRA888 Phase 3A — onboard PTAA as the first monitored tenant.
 *
 * 🔴 FORENSIC HONESTY: this ADOPTS PTAA's EXISTING infrastructure for MONITORING.
 * INFRA888 did NOT provision PTAA — PTAA is a separate live app (Laravel 12 +
 * Postgres at levelupgrowth.io/ptaa) managed by the house nginx+certbot pattern.
 * The records below REPRESENT that reality so INFRA888 can monitor it; they do not
 * claim INFRA888 owns or provisioned it. Metadata records `adopted` and
 * `managed_externally` so the distinction is permanent and queryable.
 *
 * Idempotent: re-running updates rather than duplicating. Creates DURABLE records
 * (not rolled back) so the scheduled monitor sweep runs against PTAA every 5 min.
 *
 * Creates NOTHING at any provider. No DNS, no SSL, no provisioning. The only
 * outward action anywhere in this vertical is an HTTP GET.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraHostedSite;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Services\InfrastructureMonitoringService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$PTAA_URL = 'https://levelupgrowth.io/ptaa/';
$admin = User::where('email', 'admin@levelupgrowth.io')->first() ?? User::find(1);

if (!$admin) {
    fwrite(STDERR, "ABORT: no admin user to own the workspace.\n");
    exit(1);
}

// ── workspace ──────────────────────────────────────────────────────────────
$ws = Workspace::where('slug', 'ptaa-infra')->first();
if (!$ws) {
    $ws = Workspace::create([
        'name'       => 'PTAA Digital Platform (infra)',
        'slug'       => 'ptaa-infra',
        'created_by' => $admin->id,
    ]);
    echo "Created workspace #{$ws->id} 'ptaa-infra'.\n";
} else {
    echo "Workspace #{$ws->id} 'ptaa-infra' already exists.\n";
}

WorkspaceContext::run($ws->id, function () use ($ws, $PTAA_URL) {
    // ── hosting account (ADOPTED — externally managed) ──────────────────────
    $account = InfraHostingAccount::firstOrNew(['workspace_id' => $ws->id, 'name' => 'PTAA Production']);
    if (!$account->exists) {
        $account->fill([
            'state'         => 'active',   // PTAA is genuinely live
            'region'        => 'ams3',
            'environment'   => 'production',
            'allowed_sites' => 1,
            'health_state'  => 'unknown',
            'provisioned_at'=> now(),
            'created_by'    => 1,
            'metadata_json' => [
                'adopted'            => true,
                'managed_externally' => true,
                'external_stack'     => 'Laravel 12 + Filament + Postgres, nginx + certbot',
                'note'               => 'INFRA888 monitors this; it did not provision it.',
            ],
        ])->save();
        echo "  Created hosting account #{$account->id} (adopted, externally managed).\n";
    } else {
        echo "  Hosting account #{$account->id} already exists.\n";
    }

    // ── hosted site ─────────────────────────────────────────────────────────
    $site = InfraHostedSite::firstOrNew([
        'workspace_id' => $ws->id,
        'primary_hostname' => 'levelupgrowth.io/ptaa',
    ]);
    if (!$site->exists) {
        $site->fill([
            'hosting_account_id' => $account->id,
            'name'               => 'PTAA Platform',
            'state'              => 'active',
            'ssl_state'          => 'active',      // served over HTTPS (external certbot)
            'deployment_state'   => 'deployed',
            'metadata_json'      => ['adopted' => true, 'managed_externally' => true],
        ])->save();
        echo "  Created hosted site #{$site->id} (PTAA Platform).\n";
    } else {
        echo "  Hosted site #{$site->id} already exists.\n";
    }

    // ── monitor check ───────────────────────────────────────────────────────
    $existing = InfraMonitorCheck::where('workspace_id', $ws->id)
        ->where('target_url', $PTAA_URL)->first();

    if (!$existing) {
        $check = app(InfrastructureMonitoringService::class)->createCheck([
            'hosted_site_id'   => $site->id,
            'name'             => 'PTAA Platform (production)',
            'target_url'       => $PTAA_URL,
            'expected_status'  => 200,
            'interval_seconds' => 300,
            'metadata_json'    => ['tenant' => 'ptaa', 'first_customer' => true],
        ]);
        echo "  Created monitor check #{$check->id} for {$PTAA_URL}.\n";

        // Run it once immediately to seed real data.
        $result = app(InfrastructureMonitoringService::class)->runCheck($check->fresh(), 'manual');
        echo "  First observation: status={$result->status} http={$result->http_code} response_ms={$result->response_ms}\n";
    } else {
        echo "  Monitor check #{$existing->id} already exists.\n";
    }
});

echo "\n=== PTAA ONBOARDING SUMMARY (workspace {$ws->id}) ===\n";
WorkspaceContext::run($ws->id, function () use ($ws) {
    $acct  = InfraHostingAccount::where('workspace_id', $ws->id)->count();
    $sites = InfraHostedSite::where('workspace_id', $ws->id)->count();
    $checks = InfraMonitorCheck::where('workspace_id', $ws->id)->get();
    echo "  hosting accounts: {$acct} | hosted sites: {$sites} | monitor checks: {$checks->count()}\n";
    foreach ($checks as $c) {
        echo "  check '{$c->name}': last_status={$c->last_status} last_http={$c->last_http_code} "
           . "last_response_ms={$c->last_response_ms} at " . optional($c->last_checked_at)->toDateTimeString() . "\n";
    }
});
echo "\nDONE. The scheduled sweep (infra:run-monitor-checks, every 5 min) now covers PTAA.\n";
