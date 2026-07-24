<?php
/**
 * INFRA888 Phase 3B — canonical asset intelligence runtime proof.
 *
 * Exercises the whole intelligence stack against the real staging DB inside a
 * rolled-back transaction (PTAA's durable graph is left intact). No provider
 * contacted. Proves the canonical graph, first-class incident lifecycle, blast
 * radius, deterministic intelligence, rule-based risk, tenant isolation and the
 * executive dashboard.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraAssetRelationship as Rel;
use App\Engines\Infrastructure\Models\InfraIncident;
use App\Engines\Infrastructure\Models\InfraIncidentTransition;
use App\Engines\Infrastructure\Services\AssetGraphService;
use App\Engines\Infrastructure\Services\IncidentService;
use App\Engines\Infrastructure\Services\InfrastructureIntelligenceService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$l}\n"; }
    else { $fail++; echo "  [FAIL] {$l}" . ($d ? " -> {$d}" : '') . "\n"; }
}

$graph = app(AssetGraphService::class);
$incidents = app(IncidentService::class);
$intel = app(InfrastructureIntelligenceService::class);

DB::beginTransaction();
try {
    echo "\n=== 1. PTAA IS IN THE CANONICAL GRAPH (durable) ===\n";
    $ptaaAssets = InfraAsset::withoutGlobalScopes()->where('workspace_id', 990006)->get();
    check('PTAA has canonical assets', $ptaaAssets->count() >= 3, 'count=' . $ptaaAssets->count());
    $ptaaWebsite = $ptaaAssets->firstWhere('asset_type', 'website');
    check('PTAA website is ADOPTED, not provisioned', $ptaaWebsite && $ptaaWebsite->management_mode === 'adopted');
    check('PTAA website health is real (from monitoring)', $ptaaWebsite && in_array($ptaaWebsite->health_state, ['healthy','degraded','down'], true), $ptaaWebsite->health_state ?? '?');

    echo "\n=== 2. SYNTHETIC CUSTOMER GRAPH + FIRST-CLASS INCIDENT LIFECYCLE ===\n";
    $owner = User::create(['name' => 'i-own', 'email' => 'iown-' . Str::random(6) . '@t.local',
        'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1, 'account_classification' => 'standard']);
    $ws = Workspace::create(['name' => 'intel-test', 'slug' => 'intel-' . Str::random(6), 'created_by' => $owner->id]);

    WorkspaceContext::run($ws->id, function () use ($graph, $incidents, $intel, $ws, $owner, &$results) {
        $server  = $graph->upsertAsset(['asset_type' => 'server', 'name' => 'web-01', 'management_mode' => 'provisioned']);
        $website = $graph->upsertAsset(['asset_type' => 'website', 'name' => 'acme.com', 'management_mode' => 'provisioned', 'health_state' => 'healthy']);
        $domain  = $graph->upsertAsset(['asset_type' => 'domain', 'name' => 'acme.com', 'management_mode' => 'provisioned']);
        $graph->link($website, $server, Rel::REL_HOSTED_ON);
        $graph->link($domain, $website, Rel::REL_ATTACHED_TO);

        $results['server'] = $server; $results['website'] = $website; $results['domain'] = $domain;

        // Open a first-class incident on the server.
        $inc = $incidents->open($server, 'web-01 unreachable', InfraIncident::SEV_CRITICAL, 'monitoring', 'probe', 'connection refused');
        $results['incident'] = $inc;
        check('incident opens in detected', $inc->lifecycle_state === 'detected');
        check('server health -> down on incident', $server->fresh()->health_state === 'down');

        // Drive the full lifecycle.
        $incidents->transition($inc, 'acknowledged', $owner->id, 'user', 'on it');
        $incidents->transition($inc->fresh(), 'investigating', $owner->id, 'user');
        $incidents->transition($inc->fresh(), 'mitigated', $owner->id, 'user', 'restarted');
        $resolved = $incidents->transition($inc->fresh(), 'resolved', $owner->id, 'user', 'fixed');
        check('incident resolved', $resolved->lifecycle_state === 'resolved');
        check('time-to-resolve computed', $resolved->time_to_resolve_seconds !== null);
        check('server health restored on resolve', $server->fresh()->health_state === 'healthy');

        $incidents->transition($resolved->fresh(), 'closed', $owner->id, 'user');
        check('incident closed', $inc->fresh()->lifecycle_state === 'closed');

        // Transition history is complete + append-only.
        $trans = InfraIncidentTransition::where('incident_id', $inc->id)->orderBy('id')->get();
        check('full transition history recorded (6 states)', $trans->count() === 6, 'count=' . $trans->count());
        $immutable = false;
        try { $trans->first()->update(['note' => 'x']); } catch (\Throwable $e) { $immutable = str_contains($e->getMessage(), 'append-only'); }
        check('transitions are append-only', $immutable);

        // Illegal transition refused.
        $illegal = false;
        try { $incidents->transition($inc->fresh(), 'acknowledged'); } catch (\Throwable $e) { $illegal = str_contains($e->getMessage(), 'Illegal'); }
        check('illegal transition (closed->acknowledged) refused', $illegal);
    });

    echo "\n=== 3. BLAST RADIUS ===\n";
    WorkspaceContext::run($ws->id, function () use ($graph, $results) {
        // server down -> which assets affected? website (hosted_on) + domain (attached_to website).
        $blast = $graph->blastRadius($results['server']);
        $names = array_map(fn ($a) => $a->name, $blast['assets']);
        check('blast radius finds dependent website', in_array('acme.com', $names, true) && count($blast['assets']) >= 2, 'affected=' . implode(',', $names));
    });

    echo "\n=== 4. INTELLIGENCE (MTTR / reliability, deterministic) ===\n";
    $rel = $intel->reliability($ws->id, 30);
    echo "  MTTR={$rel['mttr_seconds']}s incidents={$rel['incidents']} resolved={$rel['resolved']}\n";
    check('MTTR computed from resolved incidents', $rel['mttr_seconds'] !== null && $rel['resolved'] >= 1);
    check('failure frequency reported', $rel['failure_frequency_per_day'] >= 0);

    echo "\n=== 5. RULE-BASED RISK ===\n";
    WorkspaceContext::run($ws->id, function () use ($graph, $intel) {
        // Cert expiring in 3 days -> at_risk.
        $cert = $graph->upsertAsset(['asset_type' => 'ssl_certificate', 'name' => 'acme cert',
            'config_json' => ['ssl_expires_at' => now()->addDays(3)->toIso8601String()]]);
        $r = $intel->assessAssetRisk($cert);
        check('near-expiry cert flagged at_risk', $r['risk_state'] === 'at_risk', $r['risk_state']);
        check('risk reason recorded', !empty($r['reasons']));

        // Healthy asset -> ok.
        $ok = $graph->upsertAsset(['asset_type' => 'server', 'name' => 'healthy-01', 'health_state' => 'healthy']);
        check('healthy asset -> ok', $intel->assessAssetRisk($ok)['risk_state'] === 'ok');
    });

    echo "\n=== 6. TENANT ISOLATION ===\n";
    $otherWs = Workspace::create(['name' => 'other', 'slug' => 'other-' . Str::random(6), 'created_by' => $owner->id]);
    $mineId = $results['website']->id;
    $leak = WorkspaceContext::run($otherWs->id, fn () => InfraAsset::find($mineId));
    check('asset invisible from another workspace', $leak === null);
    $incId = $results['incident']->id;
    $leakInc = WorkspaceContext::run($otherWs->id, fn () => InfraIncident::find($incId));
    check('incident invisible from another workspace', $leakInc === null);

    echo "\n=== 7. EXECUTIVE DASHBOARD (real PTAA data) ===\n";
    $dash = $intel->dashboard(990006);
    echo "  PTAA assets total={$dash['assets']['total']} healthy={$dash['health']['healthy']} open_incidents={$dash['needs_attention']['open_incidents']}\n";
    check('PTAA dashboard returns real asset counts', $dash['assets']['total'] >= 3);
    check('dashboard reports health breakdown', isset($dash['health']['healthy']));
    check('dashboard reports reliability block', isset($dash['reliability']['mttr_seconds']) || $dash['reliability']['incidents'] >= 0);

} catch (\Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> ROLLED BACK. PTAA graph intact: "
        . InfraAsset::withoutGlobalScopes()->where('workspace_id', 990006)->count() . " assets\n";
}

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
