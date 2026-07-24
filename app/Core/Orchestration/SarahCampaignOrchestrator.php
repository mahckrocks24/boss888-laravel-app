<?php

namespace App\Core\Orchestration;

use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Support\Facades\Log;

/**
 * SarahCampaignOrchestrator — single-call cross-engine campaign drafting.
 *
 * Activates the ContentPack data plane. Sarah calls draftCampaign(theme, channels)
 * and gets back a pack ID + per-channel draft outcomes. Each downstream draft is
 * dispatched through EngineExecutionService so cap-map/plan-gating/credit/
 * approval fires per-asset.
 *
 * Channel → engine action map:
 *   article → write.create_article (then generate_outline)
 *   email   → marketing.email_ai_generate
 *   social  → social.social_ai_post  (N posts)
 *   design  → studio.generate_design
 *
 * All assets share the SAME frozen brand snapshot via the pack's
 * brand_kit_snapshot. Even if the workspace edits its brand kit
 * mid-campaign, every asset in this pack stays consistent.
 */
class SarahCampaignOrchestrator
{
    /** Number of social posts to draft by default. */
    private const DEFAULT_SOCIAL_VARIANTS = 3;

    /** Channels supported in v1. */
    private const SUPPORTED_CHANNELS = ['article', 'email', 'social', 'design'];

    public function __construct(private readonly EngineExecutionService $kernel) {}

    /**
     * Draft a cross-engine campaign.
     *
     * Params:
     *   - theme (required)      e.g., "Q3 gym new year challenge"
     *   - channels (optional)   subset of ['article','email','social','design']. Default: all.
     *   - audience (optional)   target audience copy for AI prompts
     *   - campaign_id (optional) bind pack to existing campaigns.id
     *   - social_variants (optional) how many social posts. Default 3.
     *   - source (internal)     execution context source tag
     *
     * Returns:
     *   [
     *     success => bool,
     *     pack_id => int,
     *     pack_status => 'assembling'|'draft',
     *     channels => [
     *       'article' => ['status'=>'drafted'|'approval_pending'|'failed', 'asset_id'=>..., 'entity_id'=>...],
     *       ...
     *     ],
     *     summary => 'drafted N of M assets; K awaiting approval',
     *   ]
     */
    public function draftCampaign(int $wsId, array $params): array
    {
        $theme = trim((string) ($params['theme'] ?? ''));
        if ($theme === '') {
            return ['success' => false, 'error' => 'theme is required'];
        }
        $channels = $this->normalizeChannels($params['channels'] ?? null);
        $audience = (string) ($params['audience'] ?? '');
        $variants = max(1, min(5, (int) ($params['social_variants'] ?? self::DEFAULT_SOCIAL_VARIANTS)));
        $source   = (string) ($params['source'] ?? 'sarah_orchestrator');
        $userId   = $params['user_id'] ?? null;

        // ─── Step 1: create the ContentPack ────────────────────────────
        $packParams = array_filter([
            'name'        => "Sarah campaign: $theme",
            'theme'       => $theme,
            'campaign_id' => $params['campaign_id'] ?? null,
            'created_by'  => $userId,
            'meta'        => array_filter([
                'audience' => $audience,
                'channels' => $channels,
                'requested_by' => 'sarah_orchestrator',
            ]),
        ]);
        $packRes = $this->kernel->execute($wsId, 'content', 'create_pack', $packParams,
            ['source' => $source, 'user_id' => $userId]);
        if (!($packRes['success'] ?? false)) {
            return ['success' => false, 'error' => 'pack creation failed', 'detail' => $packRes];
        }
        // EES wraps the service return; pack_id is in data
        $packId = $packRes['data']['pack_id']
            ?? ($packRes['data']['data']['id'] ?? null)
            ?? ($packRes['pack_id'] ?? null);
        if (!$packId) {
            return ['success' => false, 'error' => 'pack_id not returned', 'detail' => $packRes];
        }

        $brandSnapshot = $packRes['data']['data']['brand_kit_snapshot']
            ?? $packRes['data']['brand_kit_snapshot']
            ?? [];

        $sharedCtx = [
            'theme'          => $theme,
            'audience'       => $audience,
            'brand_snapshot' => $brandSnapshot,
            'pack_id'        => $packId,
        ];

        // ─── Step 2: draft each requested channel ──────────────────────
        $outcomes = [];
        foreach ($channels as $channel) {
            $outcomes[$channel] = $this->draftChannel($wsId, $channel, $sharedCtx, $variants, $source, $userId);
        }

        // ─── Step 3: tally + return summary ────────────────────────────
        $drafted = 0; $pending = 0; $failed = 0; $totalAssets = 0;
        foreach ($outcomes as $entries) {
            // Each channel returns either a single entry or an array of entries (social variants)
            $list = isset($entries[0]) && is_array($entries[0]) ? $entries : [$entries];
            foreach ($list as $entry) {
                $totalAssets++;
                $st = $entry['status'] ?? 'failed';
                if ($st === 'drafted') $drafted++;
                elseif ($st === 'approval_pending') $pending++;
                else $failed++;
            }
        }

        $summary = "drafted $drafted of $totalAssets assets";
        if ($pending > 0) $summary .= "; $pending awaiting approval";
        if ($failed  > 0) $summary .= "; $failed failed";

        return [
            'success'      => true,
            'pack_id'      => $packId,
            'pack_status'  => $drafted > 0 ? 'assembling' : 'draft',
            'channels'     => $outcomes,
            'totals'       => ['drafted' => $drafted, 'pending' => $pending, 'failed' => $failed, 'total' => $totalAssets],
            'summary'      => $summary,
            'brand_neutral'=> (bool) ($brandSnapshot['is_neutral'] ?? false),
        ];
    }

    // ─── Private dispatchers ───────────────────────────────────────────

    private function draftChannel(int $wsId, string $channel, array $ctx, int $variants, string $source, $userId): array
    {
        return match ($channel) {
            'article' => $this->draftArticle($wsId, $ctx, $source, $userId),
            'email'   => $this->draftEmail($wsId, $ctx, $source, $userId),
            'social'  => $this->draftSocial($wsId, $ctx, $variants, $source, $userId),
            'design'  => $this->draftDesign($wsId, $ctx, $source, $userId),
            default   => ['status' => 'failed', 'error' => "unsupported channel: $channel"],
        };
    }

    private function draftArticle(int $wsId, array $ctx, string $source, $userId): array
    {
        $r = $this->kernel->execute($wsId, 'write', 'create_article', [
            'title' => $ctx['theme'],
            'topic' => $ctx['theme'],
            'context' => $ctx['audience'],
        ], ['source' => $source, 'user_id' => $userId]);
        return $this->registerAssetOutcome($wsId, $ctx['pack_id'], 'write', 'article', $r);
    }

    private function draftEmail(int $wsId, array $ctx, string $source, $userId): array
    {
        $r = $this->kernel->execute($wsId, 'marketing', 'email_ai_generate', [
            'topic'    => $ctx['theme'],
            'audience' => $ctx['audience'],
            'tone'     => $ctx['brand_snapshot']['tone'] ?? 'friendly',
            'voice'    => $ctx['brand_snapshot']['voice'] ?? 'professional',
        ], ['source' => $source, 'user_id' => $userId]);
        return $this->registerAssetOutcome($wsId, $ctx['pack_id'], 'marketing', 'email', $r);
    }

    private function draftSocial(int $wsId, array $ctx, int $variants, string $source, $userId): array
    {
        $platforms = ['instagram', 'linkedin', 'facebook', 'twitter', 'tiktok'];
        $results = [];
        for ($i = 0; $i < $variants; $i++) {
            $platform = $platforms[$i % count($platforms)];
            $r = $this->kernel->execute($wsId, 'social', 'social_ai_post', [
                'platform' => $platform,
                'topic'    => $ctx['theme'],
                'tone'     => $ctx['brand_snapshot']['tone'] ?? 'friendly',
                'context'  => ['audience' => $ctx['audience']],
            ], ['source' => $source, 'user_id' => $userId]);
            $results[] = $this->registerAssetOutcome($wsId, $ctx['pack_id'], 'social', 'social_post', $r)
                + ['platform' => $platform, 'sequence' => $i + 1];
        }
        return $results;
    }

    private function draftDesign(int $wsId, array $ctx, string $source, $userId): array
    {
        $r = $this->kernel->execute($wsId, 'studio', 'generate_design', [
            'prompt' => $ctx['theme'],
            'format' => 'square',
            'style'  => 'bold',
        ], ['source' => $source, 'user_id' => $userId]);
        return $this->registerAssetOutcome($wsId, $ctx['pack_id'], 'studio', 'design', $r);
    }

    /**
     * Translate a kernel execute() response into an outcome tuple and, on
     * success, register the produced entity as a content_pack_asset.
     */
    private function registerAssetOutcome(int $wsId, int $packId, string $engine, string $assetKind, array $kernelRes): array
    {
        // Approval-pending path: don't register an asset, but report status
        if (($kernelRes['code'] ?? null) === 'AWAITING_APPROVAL') {
            return ['status' => 'approval_pending', 'approval_id' => $kernelRes['approval_id'] ?? null];
        }
        if (!($kernelRes['success'] ?? false)) {
            $err = $kernelRes['error'] ?? $kernelRes['message'] ?? 'unknown';
            Log::warning("SarahCampaignOrchestrator: $engine.$assetKind failed: $err", [
                'ws' => $wsId, 'pack' => $packId, 'res' => $kernelRes,
            ]);
            return ['status' => 'failed', 'error' => $err];
        }

        // Extract entity_id from the engine response (varies by engine)
        $data     = $kernelRes['data'] ?? [];
        $entityId = $this->extractEntityId($engine, $assetKind, $data);
        if (!$entityId) {
            // Engine succeeded but didn't return a persisted entity ID — record as
            // a draft-only asset with entity_id=0 + the raw payload in meta_json
            $entityId = 0;
        }

        $addRes = $this->kernel->execute($wsId, 'content', 'add_asset', [
            'pack_id'    => $packId,
            'engine'     => $engine,
            'asset_kind' => $assetKind,
            'entity_id'  => $entityId,
            'status'     => 'draft',
            'meta'       => array_filter([
                'engine_response_snippet' => is_array($data) ? array_slice(array_keys($data), 0, 5) : null,
            ]),
        ], ['source' => 'sarah_orchestrator', 'user_id' => null]);

        $assetId = $addRes['data']['asset_id']
            ?? $addRes['data']['data']['id']
            ?? null;
        return ['status' => 'drafted', 'asset_id' => $assetId, 'entity_id' => $entityId];
    }

    /**
     * Try to find the persisted entity ID across the various shapes that
     * engine responses use. Falls back to 0 if no recognized field present.
     */
    private function extractEntityId(string $engine, string $kind, array $data): int
    {
        // Common patterns observed across engines
        $candidates = [
            'id', 'article_id', 'campaign_id', 'post_id', 'design_id',
            'entity_id', 'persisted_id',
        ];
        foreach ($candidates as $k) {
            if (isset($data[$k]) && (int) $data[$k] > 0) return (int) $data[$k];
        }
        // Nested under data.data (common when service-return wrapping happens)
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($candidates as $k) {
                if (isset($data['data'][$k]) && (int) $data['data'][$k] > 0) return (int) $data['data'][$k];
            }
        }
        return 0;
    }

    private function normalizeChannels($input): array
    {
        if ($input === null || $input === '') return self::SUPPORTED_CHANNELS;
        if (is_string($input)) $input = array_map('trim', explode(',', $input));
        if (!is_array($input)) return self::SUPPORTED_CHANNELS;
        $input = array_values(array_filter(array_unique($input), fn($v) => is_string($v) && $v !== ''));
        $valid = array_values(array_intersect($input, self::SUPPORTED_CHANNELS));
        return $valid ?: self::SUPPORTED_CHANNELS;
    }
}