<?php

namespace App\Engines\Creative\Services;

use App\Connectors\CreativeConnector;
use App\Core\Intelligence\EngineIntelligenceService;
use Illuminate\Support\Facades\DB;

/**
 * CreativeService — The Creative Nervous System
 *
 * Every creative output in the platform routes through this service.
 * No other engine generates images, videos, or creative copy directly.
 *
 * Entry points:
 *   generateThroughBlueprint()  — used by all other engines
 *   generateImage()             — direct image generation
 *   generateVideo()             — direct video generation (async)
 *   pollVideoJob()              — check async video status
 *
 * All responses pass through WhiteLabelService::sanitize() before returning.
 * No provider name ever reaches the user.
 */
class CreativeService
{
    public function __construct(
        private CreativeConnector         $connector,
        private EngineIntelligenceService $engineIntel,
        private CimsService               $cims,
        private BlueprintService          $blueprint,
        private ScenePlannerService       $scenePlanner,
        private WhiteLabelService         $whiteLabel,
    ) {}

    // ═══════════════════════════════════════════════════════
    // PRIMARY ENTRY POINT — ALL ENGINES CALL THIS
    // ═══════════════════════════════════════════════════════

    /**
     * generateThroughBlueprint — the unified cross-engine entry point.
     *
     * @param string $engine   Calling engine slug (write, marketing, social, builder, crm)
     * @param string $type     Content type (article, email, post, page, outreach, ad, image, video)
     * @param int    $wsId     Workspace ID
     * @param array  $context  Request context (goal, audience, prompt, topic, etc.)
     */
    public function generateThroughBlueprint(string $engine, string $type, int $wsId, array $context = []): array
    {
        // 1. Get blueprint for this engine + type combination
        $bp = $this->getBlueprint($engine, $type, $wsId, $context);

        // 2. Generate output based on type
        $output = match (true) {
            $type === 'image' => $this->generateImageWithBlueprint($wsId, $bp, $context),
            $type === 'video' => $this->generateVideoWithBlueprint($wsId, $bp, $context),
            default           => $this->generateTextOutput($engine, $type, $wsId, $bp, $context),
        };

        // 3. Record in CIMS memory (non-blocking)
        $memoryId = null;
        try {
            $memoryId = $this->cims->recordGeneration([
                'workspace_id'   => $wsId,
                'engine'         => $engine,
                'type'           => $type,
                'prompt'         => $context['prompt'] ?? $context['topic'] ?? null,
                'result_summary' => $output['summary'] ?? null,
                'asset_url'      => $output['url'] ?? null,
                'success'        => $output['success'] ?? true,
                'context'        => $context,
                'metadata'       => ['blueprint_confidence' => $bp['confidence'] ?? null],
            ]);
        } catch (\Throwable) {}

        // 4. Intelligence feedback
        $this->engineIntel->recordToolUsage('creative', "blueprint_{$type}", 0.85);

        // 5. White-label sanitize everything before returning
        return $this->sanitize([
            'blueprint'    => $bp,
            'output'       => $output,
            'memory_id'    => $memoryId,
            'engine'       => $engine,
            'type'         => $type,
            'workspace_id' => $wsId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // DIRECT GENERATION
    // ═══════════════════════════════════════════════════════

    public function generateImage(int $wsId, array $params): array
    {
        $prompt = $params['prompt'] ?? '';
        if (empty($prompt)) {
            throw new \InvalidArgumentException('Prompt required');
        }

        $bp             = $this->blueprint->getImageBlueprint($wsId, $prompt, $params);
        $enhancedPrompt = $bp['enhanced_prompt'];

        $asset   = $this->createAsset($wsId, array_merge($params, [
            'type'     => 'image',
            'prompt'   => $enhancedPrompt,
            'metadata' => ['original_prompt' => $prompt, 'enhanced' => true],
        ]));
        $assetId = $asset['asset_id'];

        try {
            DB::table('assets')->where('id', $assetId)->update(['status' => 'generating', 'updated_at' => now()]);

            $result = $this->connector->generateImage($enhancedPrompt, [
                'size'    => $this->resolveSize($params['aspect_ratio'] ?? '1:1'),
                'quality' => $params['quality'] ?? 'standard',
                'style'   => $params['style'] ?? 'natural',
            ]);

            if ($result['success']) {
                $this->completeAsset($assetId, [
                    'url'          => $result['url'],
                    'storage_path' => $result['storage_path'] ?? null,
                    'width'        => $result['width'] ?? null,
                    'height'       => $result['height'] ?? null,
                    'mime_type'    => 'image/png',
                    'file_size'    => $result['file_size'] ?? null,
                ]);
                $this->cims->recordGeneration([
                    'workspace_id'   => $wsId,
                    'engine'         => 'creative',
                    'type'           => 'image',
                    'prompt'         => $enhancedPrompt,
                    'result_summary' => "Image: {$prompt}",
                    'asset_url'      => $result['url'],
                    'success'        => true,
                ]);
                $this->engineIntel->recordToolUsage('creative', 'generate_image', 0.9);
                return $this->sanitize(array_merge($asset, ['status' => 'completed', 'url' => $result['url']]));
            }

            $this->failAsset($assetId, $result['error'] ?? 'Generation failed');
            return $this->sanitize(array_merge($asset, ['status' => 'failed', 'error' => 'Generation failed']));

        } catch (\Throwable $e) {
            $this->failAsset($assetId, $e->getMessage());
            return $this->sanitize(array_merge($asset, ['status' => 'failed', 'error' => 'Generation failed']));
        }
    }

    public function generateVideo(int $wsId, array $params): array
    {
        $prompt = $params['prompt'] ?? '';
        if (empty($prompt)) {
            throw new \InvalidArgumentException('Prompt required');
        }

        $videoBp = $this->blueprint->getVideoBlueprint($wsId, $prompt, $params);

        $scenes = $this->scenePlanner->planScenes($prompt, [
            'duration'    => $params['duration'] ?? 10,
            'style'       => $videoBp['style_additions'] ?? '',
            'aspect_ratio'=> $params['aspect_ratio'] ?? '16:9',
        ]);

        $asset   = $this->createAsset($wsId, [
            'type'     => 'video',
            'prompt'   => $prompt,
            'metadata' => ['scene_count' => count($scenes), 'duration' => $params['duration'] ?? 10],
        ]);
        $assetId = $asset['asset_id'];

        DB::table('assets')->where('id', $assetId)->update(['status' => 'in_progress', 'updated_at' => now()]);

        $jobIds = [];
        foreach ($scenes as $scene) {
            $job      = $this->scenePlanner->dispatchSceneJob($wsId, $assetId, $scene, [
                'aspect_ratio' => $params['aspect_ratio'] ?? '16:9',
            ]);
            $jobIds[] = $job['id'] ?? null;
        }

        $this->engineIntel->recordToolUsage('creative', 'generate_video', 0.85);

        return $this->sanitize(array_merge($asset, [
            'status'      => 'in_progress',
            'scene_count' => count($scenes),
            'job_ids'     => array_filter($jobIds),
            'message'     => 'Video generation started. Use pollVideoJob() to check status.',
        ]));
    }

    public function pollVideoJob(int $assetId): array
    {
        $asset = DB::table('assets')->where('id', $assetId)->first();
        if (!$asset) {
            return $this->sanitize(['status' => 'not_found']);
        }

        foreach ($this->scenePlanner->getAssetJobs($assetId) as $job) {
            if (in_array($job->status ?? '', ['in_progress', 'dispatching'])) {
                $this->scenePlanner->pollJob($job->id);
            }
        }

        $jobStatus = $this->scenePlanner->getAssetJobStatus($assetId);

        if ($jobStatus['status'] === 'completed') {
            $stitch = $this->scenePlanner->stitchScenes($assetId);
            if ($stitch['success'] && !empty($stitch['url'])) {
                $this->completeAsset($assetId, ['url' => $stitch['url'], 'mime_type' => 'video/mp4']);
                $this->engineIntel->recordToolUsage('creative', 'poll_video', 0.9);
                return $this->sanitize(['status' => 'completed', 'url' => $stitch['url'], 'asset_id' => $assetId]);
            }
        }

        if ($jobStatus['status'] === 'failed') {
            $this->failAsset($assetId, 'Scene generation failed');
            return $this->sanitize(['status' => 'failed', 'asset_id' => $assetId]);
        }

        return $this->sanitize([
            'status'        => 'in_progress',
            'asset_id'      => $assetId,
            'scenes_total'  => $jobStatus['total'],
            'scenes_done'   => $jobStatus['completed'],
            'scenes_failed' => $jobStatus['failed'],
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // BRAND IDENTITY (CIMS facade)
    // ═══════════════════════════════════════════════════════

    public function getBrandIdentity(int $wsId): array
    {
        return $this->sanitize($this->cims->getBrandIdentity($wsId));
    }

    public function updateBrandIdentity(int $wsId, array $data): array
    {
        return $this->sanitize($this->cims->updateBrandIdentity($wsId, $data));
    }

    // Legacy shims
    public function getBrandKit(int $wsId): array       { return $this->getBrandIdentity($wsId); }
    public function updateBrandKit(int $wsId, array $d): array { return $this->updateBrandIdentity($wsId, $d); }

    // ═══════════════════════════════════════════════════════
    // MEMORY (CIMS facade)
    // ═══════════════════════════════════════════════════════

    public function getGenerationMemory(int $wsId, string $type = null, int $limit = 20): array
    {
        return $this->sanitize($this->cims->getGenerationHistory($wsId, $type, $limit));
    }

    public function getMemoryStats(int $wsId): array
    {
        return $this->sanitize($this->cims->getStats($wsId));
    }

    // ═══════════════════════════════════════════════════════
    // ASSET CRUD
    // ═══════════════════════════════════════════════════════

    public function createAsset(int $wsId, array $data): array
    {
        $id = DB::table('assets')->insertGetId([
            'workspace_id'  => $wsId,
            'type'          => $data['type'] ?? 'image',
            'title'         => $data['title'] ?? $data['prompt'] ?? 'Untitled',
            'prompt'        => $data['prompt'] ?? null,
            'provider'      => 'LevelUp AI',
            'model'         => 'LevelUp AI',
            'status'        => 'pending',
            'metadata_json' => json_encode(array_merge($data['metadata'] ?? [], [
                'style'        => $data['style'] ?? null,
                'aspect_ratio' => $data['aspect_ratio'] ?? '1:1',
                'quality'      => $data['quality'] ?? 'standard',
            ])),
            'tags_json'     => json_encode($data['tags'] ?? []),
            'task_id'       => $data['task_id'] ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return ['asset_id' => $id, 'status' => 'pending', 'provider' => 'LevelUp AI'];
    }

    public function getAsset(int $wsId, int $id): ?array
    {
        $row = DB::table('assets')->where('workspace_id', $wsId)->where('id', $id)->first();
        return $row ? $this->sanitize((array) $row) : null;
    }

    public function listAssets(int $wsId, array $filters = []): array
    {
        $q = DB::table('assets')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (!empty($filters['type']))   $q->where('type', $filters['type']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['search'])) $q->where('title', 'like', '%' . $filters['search'] . '%');
        $total = $q->count();
        return $this->sanitize([
            'assets' => $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get()->toArray(),
            'total'  => $total,
        ]);
    }

    public function deleteAsset(int $assetId): bool
    {
        return DB::table('assets')->where('id', $assetId)->update(['deleted_at' => now()]) > 0;
    }

    public function getDashboard(int $wsId): array
    {
        $q = DB::table('assets')->where('workspace_id', $wsId)->whereNull('deleted_at');
        return $this->sanitize([
            'total_assets'   => (clone $q)->count(),
            'images'         => (clone $q)->where('type', 'image')->count(),
            'videos'         => (clone $q)->where('type', 'video')->count(),
            'completed'      => (clone $q)->where('status', 'completed')->count(),
            'in_progress'    => (clone $q)->where('status', 'in_progress')->count(),
            'failed'         => (clone $q)->where('status', 'failed')->count(),
            'recent'         => (clone $q)->orderByDesc('created_at')->limit(8)->get()->toArray(),
            'brand_identity' => $this->cims->getBrandIdentity($wsId),
            'memory_stats'   => $this->cims->getStats($wsId),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // WHITE-LABEL SANITIZER (public so BaseEngineController can call it)
    // ═══════════════════════════════════════════════════════

    public function sanitize(mixed $data): mixed
    {
        return $this->whiteLabel->sanitize($data);
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    private function getBlueprint(string $engine, string $type, int $wsId, array $context): array
    {
        return match (true) {
            in_array($type, ['article', 'blog', 'text', 'script', 'copy'])  => $this->blueprint->getContentBlueprint($wsId, $context),
            in_array($type, ['email', 'email_copy', 'newsletter'])          => $this->blueprint->getEmailBlueprint($wsId, $context),
            in_array($type, ['post', 'caption', 'social', 'post_copy'])     => $this->blueprint->getSocialBlueprint($wsId, $context),
            in_array($type, ['page', 'landing', 'page_copy'])               => $this->blueprint->getPageBlueprint($wsId, $context),
            in_array($type, ['outreach', 'follow_up', 'sales'])             => $this->blueprint->getOutreachBlueprint($wsId, $context),
            in_array($type, ['ad', 'ad_copy', 'paid'])                      => $this->blueprint->getAdBlueprint($wsId, $context),
            $type === 'image'  => $this->blueprint->getImageBlueprint($wsId, $context['prompt'] ?? '', $context),
            $type === 'video'  => $this->blueprint->getVideoBlueprint($wsId, $context['prompt'] ?? '', $context),
            default            => $this->blueprint->getContentBlueprint($wsId, $context),
        };
    }

    private function generateImageWithBlueprint(int $wsId, array $bp, array $context): array
    {
        return $this->generateImage($wsId, array_merge($context, [
            'prompt' => $bp['enhanced_prompt'] ?? $context['prompt'] ?? '',
        ]));
    }

    private function generateVideoWithBlueprint(int $wsId, array $bp, array $context): array
    {
        return $this->generateVideo($wsId, $context);
    }

    private function generateTextOutput(string $engine, string $type, int $wsId, array $bp, array $context): array
    {
        return [
            'generated'         => true,
            'type'              => $type,
            'blueprint'         => $bp,
            'brand_context'     => $this->cims->buildBrandContext($wsId),
            'memory_context'    => $this->cims->buildMemoryContext($wsId, $type),
            'tone_instructions' => $bp['tone_instructions'] ?? null,
            'avoid'             => $bp['avoid'] ?? null,
            'summary'           => "Blueprint ready for {$engine}::{$type}",
        ];
    }

    public function completeAsset(int $assetId, array $result): void
    {
        DB::table('assets')->where('id', $assetId)->update(array_merge(
            ['status' => 'completed', 'updated_at' => now()],
            array_intersect_key($result, array_flip([
                'url', 'thumbnail_url', 'storage_path', 'mime_type',
                'file_size', 'width', 'height', 'duration_seconds',
            ]))
        ));

        // STEP 6 dual-write (added 2026-04-19): mirror completed assets into
        // the `media` table so the unified admin Media Library surfaces
        // engine-generated content. Best-effort — never blocks asset
        // completion.
        try {
            $row = DB::table('assets')->where('id', $assetId)->first();
            if (!$row || empty($row->url)) return;

            $path = $row->storage_path ?: $row->url;
            $type = strtolower((string) ($row->type ?? 'image'));
            $category = in_array($type, ['image','video','audio'], true) ? $type : 'website';

            $metadata = [];
            if (!empty($row->metadata_json)) {
                $decoded = json_decode($row->metadata_json, true);
                if (is_array($decoded)) $metadata = $decoded;
            }
            $metadata['source_asset_id'] = $assetId;

            \App\Services\MediaService::register(
                $path,
                $row->url,
                'creative_engine',
                $category,
                $row->workspace_id,
                $row->prompt,
                $row->model ?: 'LevelUp AI',
                $metadata
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[CreativeService] media dual-write failed for asset '.$assetId.': '.$e->getMessage()
            );
        }
    }

    public function failAsset(int $assetId, string $reason): void
    {
        DB::table('assets')->where('id', $assetId)->update([
            'status'        => 'failed',
            'metadata_json' => DB::raw("JSON_SET(COALESCE(metadata_json, '{}'), '$.error', " . DB::getPdo()->quote($reason) . ")"),
            'updated_at'    => now(),
        ]);
    }

    private function resolveSize(string $ratio): string
    {
        return match ($ratio) {
            '16:9'  => '1792x1024',
            '9:16'  => '1024x1792',
            '4:3'   => '1024x768',
            '3:4'   => '768x1024',
            default => '1024x1024',
        };
    }
}
