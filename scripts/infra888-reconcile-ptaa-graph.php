<?php
/**
 * INFRA888 Phase 3B — reconcile PTAA into the canonical asset graph.
 *
 * Turns the existing PTAA records (hosting account, hosted site, monitor check)
 * into canonical assets and wires the graph:
 *
 *   [website] PTAA Platform
 *      ├─ hosted_on ─▶ [server] PTAA Production (hosting account)
 *      └─ monitored by ◀─ monitors ─ [monitoring_check] PTAA check
 *
 * management_mode is preserved honestly: the server/site are ADOPTED (INFRA888
 * monitors PTAA, did not provision it). Idempotent. Links the monitor check to
 * the website asset so it now drives FIRST-CLASS incidents.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraAssetRelationship as Rel;
use App\Engines\Infrastructure\Models\InfraHostedSite;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Services\AssetGraphService;

$WS = 990006; // PTAA infra workspace (created in 3A)
$graph = app(AssetGraphService::class);

WorkspaceContext::run($WS, function () use ($WS, $graph) {
    $account = InfraHostingAccount::where('workspace_id', $WS)->first();
    $site    = InfraHostedSite::where('workspace_id', $WS)->first();
    $check   = InfraMonitorCheck::where('workspace_id', $WS)->first();

    if (!$account || !$site || !$check) {
        fwrite(STDERR, "ABORT: PTAA base records missing (run onboard first).\n");
        exit(1);
    }

    // ── server asset (from hosting account) ─────────────────────────────────
    $server = $graph->upsertAsset([
        'asset_type'      => InfraAsset::TYPE_SERVER,
        'name'            => $account->name,
        'management_mode' => InfraAsset::MODE_ADOPTED,
        'health_state'    => InfraAsset::HEALTH_UNKNOWN,
        'source_type'     => 'hosting_account',
        'source_id'       => $account->id,
        'metadata_json'   => ['region' => $account->region, 'adopted' => true],
    ]);
    $account->update(['asset_id' => $server->id]);

    // ── website asset (from hosted site) ────────────────────────────────────
    $website = $graph->upsertAsset([
        'asset_type'      => InfraAsset::TYPE_WEBSITE,
        'name'            => $site->name,
        'management_mode' => InfraAsset::MODE_ADOPTED,
        'health_state'    => $check->last_status === 'up' ? InfraAsset::HEALTH_HEALTHY : InfraAsset::HEALTH_UNKNOWN,
        'health_checked_at' => $check->last_checked_at,
        'external_ref'    => $site->primary_hostname,
        'source_type'     => 'hosted_site',
        'source_id'       => $site->id,
        // SSL/DNS/deployment are externally managed — HONESTLY unknown until an
        // adapter can read them. Never faked.
        'config_json'     => [
            'ssl_state'        => 'managed_externally',
            'dns_state'        => 'managed_externally',
            'deployment_state' => $site->deployment_state,
        ],
        'metadata_json'   => ['adopted' => true, 'managed_externally' => true],
    ]);
    $site->update(['asset_id' => $website->id]);

    // ── monitoring_check asset (the check itself is a graph citizen) ────────
    $monitorAsset = $graph->upsertAsset([
        'asset_type'      => InfraAsset::TYPE_MONITOR,
        'name'            => $check->name,
        'management_mode' => InfraAsset::MODE_MANAGED_BY_INFRA888, // WE run the monitor
        'health_state'    => $check->last_status === 'up' ? InfraAsset::HEALTH_HEALTHY : InfraAsset::HEALTH_DOWN,
        'source_type'     => 'monitor_check',
        'source_id'       => $check->id,
        'metadata_json'   => ['target' => $check->target_url],
    ]);

    // ── link the check to the WEBSITE it monitors, so incidents land on the site
    $check->update(['asset_id' => $website->id]);

    // ── graph edges ─────────────────────────────────────────────────────────
    $graph->link($website, $server, Rel::REL_HOSTED_ON);
    $graph->link($monitorAsset, $website, Rel::REL_MONITORS);

    echo "PTAA reconciled into the canonical graph:\n";
    echo "  [website #{$website->id}] {$website->name}  (adopted, health={$website->health_state})\n";
    echo "  [server  #{$server->id}] {$server->name}  (adopted)\n";
    echo "  [monitor #{$monitorAsset->id}] {$monitorAsset->name}  (managed_by_infra888)\n";
    echo "  edges: website -hosted_on-> server ; monitor -monitors-> website\n";
    echo "  monitor check #{$check->id} now attached to website asset -> drives first-class incidents\n";
});

echo "\n=== GRAPH SUMMARY (workspace {$WS}) ===\n";
WorkspaceContext::run($WS, function () use ($WS) {
    $assets = InfraAsset::where('workspace_id', $WS)->get();
    $edges  = Rel::where('workspace_id', $WS)->count();
    echo "  assets: {$assets->count()} | edges: {$edges}\n";
    foreach ($assets as $a) {
        echo "  - {$a->asset_type} '{$a->name}' mode={$a->management_mode} health={$a->health_state} risk={$a->risk_state}\n";
    }
});
