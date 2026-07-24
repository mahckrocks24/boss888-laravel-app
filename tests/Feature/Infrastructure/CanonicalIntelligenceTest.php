<?php

namespace Tests\Feature\Infrastructure;

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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3B — canonical asset graph + first-class incidents + intelligence.
 */
class CanonicalIntelligenceTest extends TestCase
{
    use DatabaseTransactions;

    private AssetGraphService $graph;
    private IncidentService $incidents;
    private InfrastructureIntelligenceService $intel;
    private int $wsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph     = app(AssetGraphService::class);
        $this->incidents = app(IncidentService::class);
        $this->intel     = app(InfrastructureIntelligenceService::class);

        $owner = User::create(['name' => 'ci-owner', 'email' => 'ci-' . Str::random(6) . '@t.local',
            'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1,
            'account_classification' => 'standard']);
        $ws = Workspace::create(['name' => 'ci', 'slug' => 'ci-' . Str::random(6), 'created_by' => $owner->id]);
        $this->wsId = $ws->id;
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();
        parent::tearDown();
    }

    private function inWs(callable $fn)
    {
        return WorkspaceContext::run($this->wsId, $fn);
    }

    // ── canonical graph ─────────────────────────────────────────────────────

    public function test_asset_created_with_valid_type_and_mode(): void
    {
        $this->inWs(function () {
            $a = $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'x.com', 'management_mode' => 'adopted']);
            $this->assertSame('website', $a->asset_type);
            $this->assertSame('adopted', $a->management_mode);
            $this->assertNotNull($a->asset_uid);
        });
    }

    public function test_unknown_asset_type_refused(): void
    {
        $this->inWs(function () {
            $this->expectExceptionMessageMatches('/Unknown asset_type/i');
            $this->graph->upsertAsset(['asset_type' => 'wormhole', 'name' => 'x']);
        });
    }

    public function test_upsert_is_idempotent_on_source(): void
    {
        $this->inWs(function () {
            $a = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's', 'source_type' => 'hosting_account', 'source_id' => 42]);
            $b = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's2', 'source_type' => 'hosting_account', 'source_id' => 42]);
            $this->assertSame($a->id, $b->id);
        });
    }

    public function test_cross_workspace_link_refused(): void
    {
        $a = $this->inWs(fn () => $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's']));
        $otherOwner = User::create(['name' => 'o', 'email' => 'o-' . Str::random(6) . '@t.local',
            'password' => Hash::make(Str::random(16))]);
        $otherWs = Workspace::create(['name' => 'o', 'slug' => 'o-' . Str::random(6), 'created_by' => $otherOwner->id]);
        $b = WorkspaceContext::run($otherWs->id, fn () => $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's2']));

        $this->expectExceptionMessageMatches('/across workspaces/i');
        $this->inWs(fn () => $this->graph->link($a, $b, Rel::REL_HOSTED_ON));
    }

    public function test_blast_radius_finds_dependents(): void
    {
        $this->inWs(function () {
            $server = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv']);
            $site   = $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'site']);
            $domain = $this->graph->upsertAsset(['asset_type' => 'domain', 'name' => 'dom']);
            $this->graph->link($site, $server, Rel::REL_HOSTED_ON);
            $this->graph->link($domain, $site, Rel::REL_ATTACHED_TO);

            $blast = $this->graph->blastRadius($server);
            $names = array_map(fn ($a) => $a->name, $blast['assets']);
            $this->assertContains('site', $names);
            $this->assertContains('dom', $names);
            $this->assertNotContains('srv', $names, 'the origin is the cause, not an affected dependent');
        });
    }

    public function test_orphan_detection(): void
    {
        $this->inWs(function () {
            $orphan = $this->graph->upsertAsset(['asset_type' => 'backup', 'name' => 'lonely']);
            $connected = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv']);
            $site = $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'site']);
            $this->graph->link($site, $connected, Rel::REL_HOSTED_ON);

            $orphans = $this->graph->orphans($this->wsId);
            $ids = array_map(fn ($a) => $a->id, $orphans);
            $this->assertContains($orphan->id, $ids);
            $this->assertNotContains($connected->id, $ids);
        });
    }

    // ── first-class incidents ───────────────────────────────────────────────

    public function test_incident_full_lifecycle_and_history(): void
    {
        $this->inWs(function () {
            $asset = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv', 'health_state' => 'healthy']);
            $inc = $this->incidents->open($asset, 'down', InfraIncident::SEV_CRITICAL);
            $this->assertSame('detected', $inc->lifecycle_state);
            $this->assertSame('down', $asset->fresh()->health_state);

            $this->incidents->transition($inc, 'acknowledged', 1);
            $this->incidents->transition($inc->fresh(), 'investigating', 1);
            $this->incidents->transition($inc->fresh(), 'mitigated', 1);
            $r = $this->incidents->transition($inc->fresh(), 'resolved', 1);
            $this->assertNotNull($r->time_to_resolve_seconds);
            $this->assertSame('healthy', $asset->fresh()->health_state);
            $this->incidents->transition($r->fresh(), 'closed', 1);

            $this->assertSame(6, InfraIncidentTransition::where('incident_id', $inc->id)->count());
        });
    }

    public function test_illegal_incident_transition_refused(): void
    {
        $this->inWs(function () {
            $asset = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv']);
            $inc = $this->incidents->open($asset, 'x');
            $this->expectExceptionMessageMatches('/Illegal incident transition/i');
            // detected -> closed is not legal
            $this->incidents->transition($inc, 'closed', 1);
        });
    }

    public function test_incident_transitions_are_append_only(): void
    {
        $this->inWs(function () {
            $asset = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv']);
            $inc = $this->incidents->open($asset, 'x');
            $t = InfraIncidentTransition::where('incident_id', $inc->id)->first();
            $this->expectExceptionMessageMatches('/append-only/i');
            $t->update(['note' => 'tamper']);
        });
    }

    public function test_resolve_only_restores_health_when_no_other_open_incident(): void
    {
        $this->inWs(function () {
            $asset = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv', 'health_state' => 'healthy']);
            $a = $this->incidents->open($asset, 'a');
            $b = $this->incidents->open($asset->fresh(), 'b');

            $this->incidents->resolve($a, 1, 'user');
            $this->assertSame('down', $asset->fresh()->health_state, 'still one open incident');

            $this->incidents->resolve($b->fresh(), 1, 'user');
            $this->assertSame('healthy', $asset->fresh()->health_state);
        });
    }

    // ── intelligence ────────────────────────────────────────────────────────

    public function test_reliability_computes_mttr(): void
    {
        $this->inWs(function () {
            $asset = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'srv']);
            $inc = $this->incidents->open($asset, 'x');
            $inc->update(['detected_at' => now()->subMinutes(10)]);
            $this->incidents->resolve($inc->fresh(), 1, 'user');
        });

        $rel = $this->intel->reliability($this->wsId, 30);
        $this->assertSame(1, $rel['resolved']);
        $this->assertGreaterThanOrEqual(1, $rel['mttr_seconds']);
    }

    public function test_risk_flags_near_expiry_certificate(): void
    {
        $this->inWs(function () {
            $cert = $this->graph->upsertAsset(['asset_type' => 'ssl_certificate', 'name' => 'c',
                'config_json' => ['ssl_expires_at' => now()->addDays(3)->toIso8601String()]]);
            $r = $this->intel->assessAssetRisk($cert);
            $this->assertSame('at_risk', $r['risk_state']);
            $this->assertNotEmpty($r['reasons']);
        });
    }

    public function test_risk_ok_for_healthy_unencumbered_asset(): void
    {
        $this->inWs(function () {
            $a = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 'ok', 'health_state' => 'healthy']);
            $this->assertSame('ok', $this->intel->assessAssetRisk($a)['risk_state']);
        });
    }

    public function test_dashboard_reports_real_counts(): void
    {
        $this->inWs(function () {
            $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'w', 'health_state' => 'healthy']);
            $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's', 'health_state' => 'degraded']);
        });

        $dash = $this->intel->dashboard($this->wsId);
        $this->assertSame(2, $dash['assets']['total']);
        $this->assertSame(1, $dash['health']['healthy']);
        $this->assertSame(1, $dash['health']['degraded']);
    }

    public function test_dashboard_reports_management_mode_composition(): void
    {
        $this->inWs(function () {
            $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'adopted-site', 'management_mode' => 'adopted']);
            $this->graph->upsertAsset(['asset_type' => 'website', 'name' => 'prov-site', 'management_mode' => 'provisioned']);
            $this->graph->upsertAsset(['asset_type' => 'domain', 'name' => 'ext-dom', 'management_mode' => 'managed_externally']);
        });

        $dash = $this->intel->dashboard($this->wsId);
        $this->assertArrayHasKey('management', $dash);
        $this->assertSame(3, $dash['assets']['total']);
        // The PTAA integrity distinction: adopted / externally-managed are counted
        // separately and NEVER folded into provisioned / managed-by-INFRA888.
        $this->assertSame(1, $dash['management']['adopted']);
        $this->assertSame(1, $dash['management']['provisioned']);
        $this->assertSame(1, $dash['management']['managed_externally']);
        $this->assertSame(0, $dash['management']['managed_by_infra888']);
    }

    // ── tenant isolation ────────────────────────────────────────────────────

    public function test_assets_and_incidents_are_tenant_isolated(): void
    {
        $ids = $this->inWs(function () {
            $a = $this->graph->upsertAsset(['asset_type' => 'server', 'name' => 's']);
            $i = $this->incidents->open($a, 'x');
            return [$a->id, $i->id];
        });

        $leakA = WorkspaceContext::run(777777, fn () => InfraAsset::find($ids[0]));
        $leakI = WorkspaceContext::run(777777, fn () => InfraIncident::find($ids[1]));
        $this->assertNull($leakA);
        $this->assertNull($leakI);
    }
}
