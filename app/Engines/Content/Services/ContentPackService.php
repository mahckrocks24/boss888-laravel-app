<?php

namespace App\Engines\Content\Services;

use App\Core\Brand\WorkspaceBrandKitResolver;
use Illuminate\Support\Facades\DB;

/**
 * ContentPackService — cross-engine campaign asset bundle orchestrator.
 *
 * One pack binds: campaign_id (optional) → brand_kit_snapshot (frozen) → assets[].
 * Every asset generated within a pack reads the SAME frozen brand snapshot,
 * so a multi-surface campaign (article + email + 3 social posts + designs)
 * stays brand-consistent and reproducible even if the workspace later edits
 * its brand kit.
 *
 * Approval contract:
 *   - createPack: auto (just metadata; no external send)
 *   - addAsset: auto (registers an existing engine-native asset against pack)
 *   - publishPack: PROTECTED (this is the multi-surface "send everywhere" trigger;
 *                  must be human-approved per BOSS888 publishing safety policy)
 *   - listPacks / listAssets / getPack: read-only, auto
 */
class ContentPackService
{
    public function __construct(
        private readonly WorkspaceBrandKitResolver $brandResolver,
    ) {}

    /**
     * Create a new content pack. Snapshots the workspace brand kit at this
     * moment so all assets within the pack reference the same frozen brand.
     *
     * Params:
     *   - name (required)
     *   - theme (optional)
     *   - campaign_id (optional, FK to campaigns)
     *   - meta (optional array → meta_json)
     *   - created_by (optional user_id)
     */
    public function createPack(int $wsId, array $params): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'pack name required'];
        }
        $snapshot = $this->brandResolver->resolve($wsId);
        $now = now();
        $id = DB::table('content_packs')->insertGetId([
            'workspace_id'        => $wsId,
            'campaign_id'         => $params['campaign_id'] ?? null,
            'name'                => $name,
            'theme'               => $params['theme'] ?? null,
            'status'              => 'draft',
            'brand_kit_snapshot'  => json_encode($snapshot),
            'meta_json'           => isset($params['meta']) ? json_encode($params['meta']) : null,
            'created_by'          => $params['created_by'] ?? null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        return [
            'success' => true,
            'pack_id' => $id,
            'brand_snapshot_neutral' => (bool) $snapshot['is_neutral'],
            'data' => [
                'id' => $id,
                'name' => $name,
                'status' => 'draft',
                'brand_kit_snapshot' => $snapshot,
            ],
        ];
    }

    /**
     * Register an engine-native asset (article, email campaign, social post,
     * design, image) against an existing pack. Asset is registered, not
     * generated here — generation lives in the originating engine.
     *
     * Params:
     *   - pack_id (required)
     *   - engine (required: social|marketing|write|studio|creative)
     *   - asset_kind (required: article|email|social_post|design|image)
     *   - entity_type (optional, defaults to engine.asset_kind)
     *   - entity_id (required)
     *   - sequence (optional)
     *   - meta (optional)
     *   - status (optional, default 'draft')
     */
    public function addAsset(int $wsId, array $params): array
    {
        $packId = (int) ($params['pack_id'] ?? 0);
        $pack   = $this->loadPack($wsId, $packId);
        if (!$pack) {
            return ['success' => false, 'error' => 'pack not found or not in this workspace'];
        }
        $engine     = (string) ($params['engine']     ?? '');
        $assetKind  = (string) ($params['asset_kind'] ?? '');
        $entityId   = (int)    ($params['entity_id']  ?? 0);
        if ($engine === '' || $assetKind === '' || $entityId === 0) {
            return ['success' => false, 'error' => 'engine, asset_kind, entity_id required'];
        }
        $allowedEngines = ['social','marketing','write','studio','creative','builder'];
        $allowedKinds   = ['article','email','social_post','design','image','page'];
        if (!in_array($engine, $allowedEngines, true)) {
            return ['success' => false, 'error' => "engine '$engine' not allowed"];
        }
        if (!in_array($assetKind, $allowedKinds, true)) {
            return ['success' => false, 'error' => "asset_kind '$assetKind' not allowed"];
        }
        $now = now();
        $sequence = (int) ($params['sequence']
            ?? (DB::table('content_pack_assets')->where('pack_id', $packId)->max('sequence') + 1));
        $assetId = DB::table('content_pack_assets')->insertGetId([
            'pack_id'      => $packId,
            'workspace_id' => $wsId,
            'engine'       => $engine,
            'asset_kind'   => $assetKind,
            'entity_type'  => $params['entity_type'] ?? "$engine.$assetKind",
            'entity_id'    => $entityId,
            'sequence'     => $sequence,
            'status'       => $params['status'] ?? 'draft',
            'meta_json'    => isset($params['meta']) ? json_encode($params['meta']) : null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        // Bump pack status: draft → assembling on first asset
        if (($pack['status'] ?? 'draft') === 'draft') {
            DB::table('content_packs')->where('id', $packId)->update([
                'status'     => 'assembling',
                'updated_at' => $now,
            ]);
        }
        return ['success' => true, 'asset_id' => $assetId, 'data' => ['id' => $assetId, 'sequence' => $sequence]];
    }

    /**
     * Return the frozen brand snapshot for a pack. This is what downstream
     * engines should consult when generating additional assets so the look
     * stays consistent across the bundle.
     */
    public function getBrandSnapshot(int $wsId, int $packId): array
    {
        $pack = $this->loadPack($wsId, $packId);
        if (!$pack) return ['success' => false, 'error' => 'pack not found'];
        $snap = json_decode($pack['brand_kit_snapshot'] ?? '{}', true) ?: [];
        return ['success' => true, 'pack_id' => $packId, 'brand_snapshot' => $snap];
    }

    /**
     * Return pack + all its assets.
     */
    public function getPack(int $wsId, int $packId): array
    {
        $pack = $this->loadPack($wsId, $packId);
        if (!$pack) return ['success' => false, 'error' => 'pack not found'];
        $assets = DB::table('content_pack_assets')
            ->where('pack_id', $packId)
            ->orderBy('sequence')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
        $pack['brand_kit_snapshot'] = json_decode($pack['brand_kit_snapshot'] ?? '{}', true) ?: [];
        $pack['meta_json']          = json_decode($pack['meta_json'] ?? '{}', true) ?: [];
        foreach ($assets as &$a) {
            $a['meta_json'] = json_decode($a['meta_json'] ?? '{}', true) ?: [];
        }
        unset($a);
        return ['success' => true, 'data' => ['pack' => $pack, 'assets' => $assets]];
    }

    /**
     * List packs for the workspace.
     */
    public function listPacks(int $wsId, array $params = []): array
    {
        $q = DB::table('content_packs')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at');
        if (!empty($params['status'])) $q->where('status', $params['status']);
        if (!empty($params['campaign_id'])) $q->where('campaign_id', (int) $params['campaign_id']);
        $rows = $q->orderByDesc('id')->limit((int)($params['limit'] ?? 50))->get()->map(fn($r) => (array) $r)->toArray();
        foreach ($rows as &$r) {
            $r['brand_kit_snapshot'] = json_decode($r['brand_kit_snapshot'] ?? '{}', true) ?: [];
            $r['meta_json']          = json_decode($r['meta_json'] ?? '{}', true) ?: [];
        }
        unset($r);
        return ['success' => true, 'data' => $rows, 'count' => count($rows)];
    }

    /**
     * Mark a pack as published. Per BOSS888 publishing safety: this is the
     * 'protected' action — only fires after approval gating in
     * EngineExecutionService. Service-level no-op safeguard: if a pack is
     * already published, just no-op.
     */
    public function publishPack(int $wsId, array $params): array
    {
        $packId = (int) ($params['pack_id'] ?? 0);
        $pack   = $this->loadPack($wsId, $packId);
        if (!$pack) return ['success' => false, 'error' => 'pack not found'];
        if (($pack['status'] ?? '') === 'published') {
            return ['success' => true, 'data' => ['id' => $packId, 'status' => 'published'], 'note' => 'already published'];
        }
        $assets = DB::table('content_pack_assets')->where('pack_id', $packId)->get();
        if ($assets->isEmpty()) {
            return ['success' => false, 'error' => 'pack has no assets — cannot publish'];
        }
        $approvedCount = $assets->filter(fn($a) => in_array($a->status, ['approved','published'], true))->count();
        if ($approvedCount === 0) {
            return ['success' => false, 'error' => 'no approved assets in pack'];
        }
        $now = now();
        DB::table('content_packs')->where('id', $packId)->update([
            'status'     => 'published',
            'updated_at' => $now,
        ]);
        DB::table('content_pack_assets')
            ->where('pack_id', $packId)
            ->where('status', 'approved')
            ->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        return ['success' => true, 'data' => ['id' => $packId, 'status' => 'published', 'assets_published' => $approvedCount]];
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function loadPack(int $wsId, int $packId): ?array
    {
        if ($packId <= 0) return null;
        $row = DB::table('content_packs')
            ->where('id', $packId)
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->first();
        return $row ? (array) $row : null;
    }
}