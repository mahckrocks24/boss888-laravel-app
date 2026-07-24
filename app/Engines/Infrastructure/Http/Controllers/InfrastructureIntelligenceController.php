<?php

namespace App\Engines\Infrastructure\Http\Controllers;

use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraIncident;
use App\Engines\Infrastructure\Services\AssetGraphService;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Services\InfrastructureIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Executive infrastructure intelligence API (Phase 3B).
 *
 * READ-ONLY and TENANT-SCOPED: every query is bound to the requesting user's
 * workspace (resolved by JwtAuthMiddleware), so one customer can never see
 * another's infrastructure. It answers the executive questions — what is healthy,
 * what needs attention, what is degrading, which incidents are open, which
 * providers are unhealthy, which assets are at risk — and every figure drills
 * into supporting rows.
 *
 * This is presentation over the canonical graph + real operational data. It
 * mutates nothing.
 */
class InfrastructureIntelligenceController extends Controller
{
    public function __construct(
        private readonly InfrastructureIntelligenceService $intelligence,
        private readonly AssetGraphService $graph,
    ) {
    }

    private function wsId(Request $request): int
    {
        return (int) ($request->attributes->get('workspace_id')
            ?: $request->user()?->current_workspace_id);
    }

    /** The executive dashboard summary. */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->intelligence->dashboard($this->wsId($request)));
    }

    /** Assets in this workspace, filterable by type/health/risk. */
    public function assets(Request $request): JsonResponse
    {
        $ws = $this->wsId($request);

        $assets = WorkspaceContext::run($ws, function () use ($request, $ws) {
            $q = InfraAsset::where('workspace_id', $ws);
            if ($request->filled('type'))   { $q->where('asset_type', $request->string('type')); }
            if ($request->filled('health')) { $q->where('health_state', $request->string('health')); }
            if ($request->filled('risk'))   { $q->where('risk_state', $request->string('risk')); }
            return $q->orderBy('asset_type')->orderBy('name')->limit(500)->get();
        });

        return response()->json(['assets' => $assets->map(fn ($a) => $this->presentAsset($a))]);
    }

    /** One asset with its neighbourhood, incidents and reliability stats. */
    public function asset(Request $request, int $id): JsonResponse
    {
        $ws = $this->wsId($request);

        return WorkspaceContext::run($ws, function () use ($ws, $id) {
            $asset = InfraAsset::where('workspace_id', $ws)->find($id);
            if (!$asset) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $incidents = InfraIncident::where('asset_id', $asset->id)
                ->orderByDesc('detected_at')->limit(50)->get();

            $stats = null;
            if ($asset->source_type === 'monitor_check' && $asset->source_id) {
                $stats = $this->intelligence->checkStats((int) $asset->source_id, now()->subDays(30), now());
            }

            return response()->json([
                'asset'         => $this->presentAsset($asset),
                'neighbourhood' => $this->presentNeighbourhood($this->graph->neighbourhood($asset)),
                'incidents'     => $incidents->map(fn ($i) => $this->presentIncident($i)),
                'reliability'   => $stats,
            ]);
        });
    }

    /** Blast radius: which assets (and customers) a degradation would affect. */
    public function blastRadius(Request $request, int $id): JsonResponse
    {
        $ws = $this->wsId($request);
        $asset = WorkspaceContext::run($ws, fn () => InfraAsset::where('workspace_id', $ws)->find($id));

        if (!$asset) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $blast = WorkspaceContext::run($ws, fn () => $this->graph->blastRadius($asset));

        return response()->json([
            'origin'          => $this->presentAsset($asset),
            'affected_assets' => array_map(fn ($a) => $this->presentAsset($a), $blast['assets']),
            'affected_workspaces' => $blast['workspace_ids'],
            'depth'           => $blast['depth'],
        ]);
    }

    /** Incidents in this workspace. */
    public function incidents(Request $request): JsonResponse
    {
        $ws = $this->wsId($request);

        $incidents = WorkspaceContext::run($ws, function () use ($request, $ws) {
            $q = InfraIncident::where('workspace_id', $ws);
            if ($request->boolean('open_only')) {
                $q->whereIn('lifecycle_state', InfraIncident::openStates());
            }
            return $q->orderByDesc('detected_at')->limit(200)->get();
        });

        return response()->json(['incidents' => $incidents->map(fn ($i) => $this->presentIncident($i))]);
    }

    /** Reliability metrics (MTTR/MTBF/failure frequency) for this workspace. */
    public function reliability(Request $request): JsonResponse
    {
        return response()->json(
            $this->intelligence->reliability($this->wsId($request), (int) $request->input('days', 30))
        );
    }

    // ── presenters ──────────────────────────────────────────────────────────

    private function presentAsset(InfraAsset $a): array
    {
        return [
            'asset_uid'       => $a->asset_uid,
            'id'              => $a->id,
            'asset_type'      => $a->asset_type,
            'name'            => $a->name,
            'management_mode' => $a->management_mode,   // adopted vs provisioned — never conflated
            'lifecycle_state' => $a->lifecycle_state,
            'health_state'    => $a->health_state,
            'risk_state'      => $a->risk_state,
            'risk_reasons'    => $a->risk_reasons_json ?? [],
            'config'          => $a->config_json ?? [],
            'health_checked_at' => optional($a->health_checked_at)->toIso8601String(),
        ];
    }

    private function presentIncident(InfraIncident $i): array
    {
        return [
            'incident_uid'    => $i->incident_uid,
            'title'           => $i->title,
            'severity'        => $i->severity,
            'lifecycle_state' => $i->lifecycle_state,
            'source'          => $i->source,
            'detected_at'     => optional($i->detected_at)->toIso8601String(),
            'resolved_at'     => optional($i->resolved_at)->toIso8601String(),
            'time_to_resolve_seconds' => $i->time_to_resolve_seconds,
        ];
    }

    private function presentNeighbourhood(array $n): array
    {
        return [
            'depends_on' => array_map(fn ($e) => [
                'type' => $e['type'], 'asset' => $e['asset'] ? $this->presentAsset($e['asset']) : null,
            ], $n['depends_on']),
            'depended_on_by' => array_map(fn ($e) => [
                'type' => $e['type'], 'asset' => $e['asset'] ? $this->presentAsset($e['asset']) : null,
            ], $n['depended_on_by']),
        ];
    }
}
