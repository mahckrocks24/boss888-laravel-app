<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraAssetRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The canonical infrastructure graph (Phase 3B).
 *
 * Creates assets and the directed edges between them, and traverses the graph to
 * answer operational questions — chiefly blast radius ("which customers/assets are
 * affected when this node degrades?"). Every operation is workspace-scoped.
 *
 * PROVIDER-AGNOSTIC: an asset references a provider by id, never by vendor name.
 * NO PARALLEL HIERARCHY: an asset points to its rich source record via
 * source_type/source_id; it does not copy it.
 */
class AssetGraphService
{
    /** Create or return the canonical asset for a given source record. */
    public function upsertAsset(array $attrs): InfraAsset
    {
        $type = $attrs['asset_type'] ?? null;
        if (!in_array($type, InfraAsset::assetTypes(), true)) {
            throw new InvalidArgumentException("Unknown asset_type '{$type}'.");
        }
        $mode = $attrs['management_mode'] ?? InfraAsset::MODE_MANAGED_BY_INFRA888;
        if (!in_array($mode, InfraAsset::managementModes(), true)) {
            throw new InvalidArgumentException("Unknown management_mode '{$mode}'.");
        }

        // Idempotent on (source_type, source_id) when supplied.
        if (!empty($attrs['source_type']) && !empty($attrs['source_id'])) {
            $existing = InfraAsset::where('source_type', $attrs['source_type'])
                ->where('source_id', $attrs['source_id'])->first();
            if ($existing) {
                $existing->update(array_intersect_key($attrs, array_flip([
                    'name', 'health_state', 'health_checked_at', 'lifecycle_state',
                    'provider_id', 'external_ref', 'config_json', 'metadata_json',
                ])));
                return $existing->fresh();
            }
        }

        return InfraAsset::create([
            'asset_uid'       => (string) Str::uuid(),
            'asset_type'      => $type,
            'name'            => $attrs['name'] ?? $type,
            'management_mode' => $mode,
            'lifecycle_state' => $attrs['lifecycle_state'] ?? 'active',
            'health_state'    => $attrs['health_state'] ?? InfraAsset::HEALTH_UNKNOWN,
            'risk_state'      => $attrs['risk_state'] ?? InfraAsset::RISK_OK,
            'provider_id'     => $attrs['provider_id'] ?? null,
            'external_ref'    => $attrs['external_ref'] ?? null,
            'source_type'     => $attrs['source_type'] ?? null,
            'source_id'       => $attrs['source_id'] ?? null,
            'config_json'     => $attrs['config_json'] ?? [],
            'metadata_json'   => $attrs['metadata_json'] ?? [],
            'created_by'      => $attrs['created_by'] ?? null,
        ]);
    }

    /** Create a directed edge (idempotent on from+to+type). */
    public function link(
        InfraAsset $from,
        InfraAsset $to,
        string $relationshipType,
        array $metadata = []
    ): InfraAssetRelationship {
        if ($from->workspace_id !== $to->workspace_id) {
            throw new InvalidArgumentException('Cannot link assets across workspaces.');
        }

        return InfraAssetRelationship::firstOrCreate(
            [
                'from_asset_id'     => $from->id,
                'to_asset_id'       => $to->id,
                'relationship_type' => $relationshipType,
            ],
            ['workspace_id' => $from->workspace_id, 'metadata_json' => $metadata]
        );
    }

    /**
     * Blast radius: everything DOWNSTREAM of a degraded asset — the assets that
     * depend on it, and therefore the customers affected.
     *
     * Traverses INCOMING edges (X depends_on / hosted_on / attached_to THIS),
     * bounded by depth to stay O(edges) on a large graph.
     *
     * @return array{assets:array<int,InfraAsset>,workspace_ids:array<int,int>,depth:int}
     */
    public function blastRadius(InfraAsset $asset, int $maxDepth = 5): array
    {
        $visited = [$asset->id => $asset];
        $frontier = [$asset->id];
        $depth = 0;

        while ($frontier !== [] && $depth < $maxDepth) {
            $edges = InfraAssetRelationship::whereIn('to_asset_id', $frontier)
                ->whereIn('relationship_type', [
                    InfraAssetRelationship::REL_DEPENDS_ON,
                    InfraAssetRelationship::REL_HOSTED_ON,
                    InfraAssetRelationship::REL_ATTACHED_TO,
                    InfraAssetRelationship::REL_SECURED_BY,
                    InfraAssetRelationship::REL_PROVIDED_BY,
                ])
                ->get(['from_asset_id']);

            $next = [];
            foreach ($edges as $edge) {
                if (!isset($visited[$edge->from_asset_id])) {
                    $a = InfraAsset::find($edge->from_asset_id);
                    if ($a) {
                        $visited[$a->id] = $a;
                        $next[] = $a->id;
                    }
                }
            }
            $frontier = $next;
            $depth++;
        }

        // The originating asset is the cause, not an affected dependent.
        unset($visited[$asset->id]);

        $assets = array_values($visited);
        $workspaces = array_values(array_unique(array_map(fn ($a) => (int) $a->workspace_id, $assets)));

        return ['assets' => $assets, 'workspace_ids' => $workspaces, 'depth' => $depth];
    }

    /**
     * Orphaned assets: assets with no relationships at all. A real risk signal —
     * an asset nobody depends on and which depends on nothing is often stale.
     *
     * @return array<int,InfraAsset>
     */
    public function orphans(int $workspaceId): array
    {
        return InfraAsset::where('workspace_id', $workspaceId)
            ->whereDoesntHave('outgoing')
            ->whereDoesntHave('incoming')
            ->get()->all();
    }

    /** The full neighbourhood of an asset (both directions), for drill-down. */
    public function neighbourhood(InfraAsset $asset): array
    {
        $out = InfraAssetRelationship::where('from_asset_id', $asset->id)->with('toAsset')->get();
        $in  = InfraAssetRelationship::where('to_asset_id', $asset->id)->with('fromAsset')->get();

        return [
            'asset'    => $asset,
            'depends_on' => $out->map(fn ($e) => ['type' => $e->relationship_type, 'asset' => $e->toAsset])->all(),
            'depended_on_by' => $in->map(fn ($e) => ['type' => $e->relationship_type, 'asset' => $e->fromAsset])->all(),
        ];
    }
}
