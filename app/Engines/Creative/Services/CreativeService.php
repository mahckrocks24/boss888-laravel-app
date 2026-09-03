<?php

namespace App\Engines\Creative\Services;

use App\Connectors\CreativeConnector;
use App\Connectors\RuntimeClient;
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
        // 2026-07-02 F1 — was referenced at generateImage() (descriptive alt-text
        // upgrade) but never injected → $this->runtime was undefined → alt-text
        // silently dead. DI-only fix (no logic change).
        private RuntimeClient             $runtime,
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
        $prompt    = $params['prompt'] ?? '';
        $articleId = isset($params['article_id']) ? (int) $params['article_id'] : null;

        // Wave 36c — when called as a chain child with only article_id, build
        // a sensible default prompt from the article title so chain steps
        // succeed without each one re-specifying every field.
        if ($prompt === '' && $articleId) {
            $article = \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)
                ->where('workspace_id', $wsId)
                ->first(['title', 'focus_keyword', 'blog_category']);
            if ($article && !empty($article->title)) {
                $prompt = 'Featured image for the blog article: ' . $article->title;
                if (!empty($article->focus_keyword)) {
                    $prompt .= ' (keyword: ' . $article->focus_keyword . ')';
                }
            }
        }

        if (empty($prompt)) {
            throw new \InvalidArgumentException('Prompt required');
        }

        // CANONICAL IMAGE INTELLIGENCE (2026-07-28) — replaces the old
        // string-concat getImageBlueprint() + global Wave-61 NO-TEXT rule.
        // Arthur now reasons (platform, audience, brand, history, typography)
        // and returns a structured ImageBlueprint; typography is decided
        // per-request (none / separate_overlay / baked_in) instead of always
        // stripping text. Same intelligence the Studio path uses.
        $plan     = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class)->plan([
            'source'      => $params['source'] ?? ($articleId ? 'blog' : 'creative'),
            'platform'    => $params['platform'] ?? null,
            'asset_type'  => $params['asset_type'] ?? ($articleId ? 'featured_image' : 'social_post'),
            'workspace_id'=> $wsId,
            'article_id'  => $articleId,
            'user_prompt' => $prompt,
            'requested_dimensions' => $params['dimensions'] ?? null,
            'requested_quality'    => $params['quality'] ?? 'auto',
            'include_text_preference' => $params['include_text_preference'] ?? 'auto',
            'style'       => $params['style'] ?? 'natural',
        ]);
        $blueprint      = $plan['blueprint'];
        $compiled       = $plan['compiled'];
        $enhancedPrompt = $compiled['provider_prompt'];

        // F-STUDIO-B-ASPECT (2026-09-03): an EXPLICIT caller aspect_ratio must WIN over
        // the AI blueprint's inferred size. Previously the requested aspect only landed in
        // metadata while $compiled['size'] (blueprint asset_type/platform inference) drove
        // generation, so "make it 1:1 / 16:9 / portrait / Instagram" was silently ignored.
        // Snap an explicit request to the provider-supported set and let it flow to both the
        // stored aspect and the connector size. A vague request (no explicit ratio) is
        // untouched — the blueprint still chooses.
        $__explicitAr = strtolower(trim((string) ($params['aspect_ratio'] ?? '')));
        $__arToSize = ['1:1'=>'1024x1024','square'=>'1024x1024','1x1'=>'1024x1024',
            '16:9'=>'1536x1024','3:2'=>'1536x1024','4:3'=>'1536x1024','landscape'=>'1536x1024','wide'=>'1536x1024',
            '9:16'=>'1024x1536','2:3'=>'1024x1536','3:4'=>'1024x1536','portrait'=>'1024x1536','story'=>'1024x1536','vertical'=>'1024x1536','reel'=>'1024x1536'];
        if ($__explicitAr !== '' && isset($__arToSize[$__explicitAr])) {
            $compiled['size'] = $__arToSize[$__explicitAr];
            $__sizeToAr = ['1024x1024'=>'1:1','1536x1024'=>'16:9','1024x1536'=>'9:16'];
            $blueprint['aspect_ratio'] = $__sizeToAr[$compiled['size']];
        }


        $asset   = $this->createAsset($wsId, array_merge($params, [
            'type'         => 'image',
            'prompt'       => $enhancedPrompt,
            'aspect_ratio' => $blueprint['aspect_ratio'] ?? ($params['aspect_ratio'] ?? '1:1'),
            'quality'      => $compiled['quality'],
            'metadata'     => [
                'original_prompt' => $prompt,
                'enhanced'        => true,
                'reasoning_model' => $plan['reasoning']['model'] ?? null,
                'reasoning_fallback' => $plan['reasoning']['fallback'] ?? false,
                'platform'        => $blueprint['platform'] ?? null,
                'asset_type'      => $blueprint['asset_type'] ?? null,
                'typography_mode' => $compiled['typography']['mode'] ?? 'none',
                'typography_overlay' => $compiled['overlay'],
                'blueprint'       => $blueprint,
            ],
        ]));
        $assetId = $asset['asset_id'];

        try {
            DB::table('assets')->where('id', $assetId)->update(['status' => 'generating', 'updated_at' => now()]);

            $result = $this->connector->generateImage($enhancedPrompt, [
                // Canonical size/quality from the ImageBlueprint (was hardcoded
                // resolveSize + 'standard'). Quality now preserved end-to-end.
                'size'         => $compiled['size'],
                'quality'      => $compiled['quality'],
                'style'        => $params['style'] ?? 'natural',
                // 2026-07-02 F2 — thread the known workspace_id all the way to the
                // runtime storage path so images stop landing in the shared
                // ai-images/0/ bucket (tenant-isolation fix).
                'workspace_id' => $wsId,
            ]);

            if ($result['success']) {
                // Typography compositing (separate_overlay) — same canonical
                // fidelity path as the Studio flow: render the captured copy
                // onto the text-free background as REAL typography. Mutating
                // $result flows the finished image into completeAsset, article
                // persistence and the return. (mode 'none' => untouched.)
                if (($compiled['typography']['mode'] ?? '') === 'separate_overlay'
                    && !empty($compiled['overlay']) && !empty($result['storage_path'])) {
                    $ov = app(\App\Core\ImageIntelligence\ImageOverlayRenderer::class)->render(
                        $result['storage_path'], $compiled['overlay'],
                        (int) ($result['width'] ?? 1024), (int) ($result['height'] ?? 1024), $wsId
                    );
                    if (!empty($ov['success'])) {
                        $result['background_url'] = $result['url'];
                        $result['url']            = $ov['url'];
                        $result['storage_path']   = $ov['storage_path'];
                    }
                }
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

                // Wave 36c — when called as part of an article chain, persist the
                // featured_image_url + featured_image_alt onto the article row.
                // v1.4.4 (2026-05-30) — two-step alt:
                //   (1) immediately write a title-based fallback so the field is
                //       never NULL even if the descriptive-alt step below fails;
                //   (2) call the runtime to upgrade it to a real descriptive
                //       SEO-friendly alt (max 120 chars), overwriting the fallback.
                if ($articleId) {
                    try {
                        $existing = \Illuminate\Support\Facades\DB::table('articles')
                            ->where('id', $articleId)->where('workspace_id', $wsId)
                            ->first(['title', 'focus_keyword']);
                        $title = (string) ($existing->title ?? '');
                        $focusKw = (string) ($existing->focus_keyword ?? '');
                        $fallbackAlt = $title !== '' ? $title : (string) ($params['prompt'] ?? $prompt);

                        // (1) Write the fallback alt immediately + the image URL.
                        \Illuminate\Support\Facades\DB::table('articles')
                            ->where('id', $articleId)
                            ->where('workspace_id', $wsId)
                            ->update([
                                'featured_image_url' => $result['url'],
                                'featured_image_alt' => mb_substr($fallbackAlt, 0, 250),
                                'updated_at'         => now(),
                            ]);
                        $altText = $fallbackAlt;

                        // (2) Best-effort upgrade to a real descriptive alt via runtime.
                        // Runs synchronously but cheap (~60 tokens). On any failure
                        // we keep the fallback above. Logs at warning level so
                        // failures actually surface (was a silent debug in the
                        // SEO-Assistant copy of this code).
                        try {
                            $altPrompt = "Write a concise (max 120 chars), SEO-friendly alt text describing a featured image for this blog article. "
                                . "Title: \"{$title}\". Focus keyword: \"{$focusKw}\". "
                                . "Image prompt that was used: \"" . mb_substr((string)($params['prompt'] ?? $prompt), 0, 300) . "\". "
                                . "Describe what the image VISUALLY shows (not the article topic). "
                                . "Return ONLY the alt-text string. No quotes, no preamble.";
                            $altResp = $this->runtime->aiRun('seo_content_generation', $altPrompt, ['workspace_id' => $wsId], 60);
                            $descriptive = trim((string) ($altResp['text'] ?? ''));
                            $descriptive = preg_replace('/^["\']|["\']$/u', '', $descriptive) ?? $descriptive;
                            if ($descriptive !== '' && mb_strlen($descriptive) <= 250 && $descriptive !== $fallbackAlt) {
                                \Illuminate\Support\Facades\DB::table('articles')
                                    ->where('id', $articleId)
                                    ->where('workspace_id', $wsId)
                                    ->update(['featured_image_alt' => $descriptive, 'updated_at' => now()]);
                                $altText = $descriptive;
                            }
                        } catch (\Throwable $altErr) {
                            \Illuminate\Support\Facades\Log::warning('[Creative] descriptive alt upgrade failed (kept title fallback)', [
                                'article_id' => $articleId,
                                'error'      => $altErr->getMessage(),
                            ]);
                        }
                    } catch (\Throwable $persistErr) {
                        \Illuminate\Support\Facades\Log::warning('[Creative] article featured-image persistence failed', [
                            'article_id' => $articleId,
                            'error'      => $persistErr->getMessage(),
                        ]);
                    }
                }

                return $this->sanitize(array_merge($asset, [
                    'status'             => 'completed',
                    'url'                => $result['url'],
                    'article_id'         => $articleId,
                    'featured_image_url' => $result['url'],
                    'featured_image_alt' => isset($altText) ? $altText : null,
                ]));
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

        // D2/D3 (2026-08-13) — carry the originating task AND the requested aspect
        // onto the asset. task_id is what lets the Orchestrator link this asset to
        // its CreativeJob (assets.task_id lookup). aspect_ratio matters because
        // createAsset() defaults it to '1:1': the scene job recorded the true 16:9
        // while the asset reported square, so the editor, Production and Assets all
        // disagreed with what the customer actually asked for.
        $asset   = $this->createAsset($wsId, [
            'type'         => 'video',
            'prompt'       => $prompt,
            'task_id'      => $params['task_id'] ?? null,
            'aspect_ratio' => $params['aspect_ratio'] ?? '16:9',
            'metadata'     => ['scene_count' => count($scenes), 'duration' => $params['duration'] ?? 10],
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
                // D1 (2026-08-13) — the customer must never be handed the provider's
                // expiring CDN URL. Persist the finished MP4 onto our own disk first.
                $durable = $this->persistVideoDurably($assetId, (int) $asset->workspace_id, $stitch['url']);

                if (! ($durable['success'] ?? false)) {
                    // Do NOT complete on a failed download. The scene jobs stay
                    // 'completed', so the next video:finalize-pending tick retries the
                    // DOWNLOAD only — never a second provider generation and never a
                    // second charge. Reporting completion here would be a fabricated
                    // success: a 'completed' asset whose bytes we do not hold.
                    \Illuminate\Support\Facades\Log::warning('[Video D1] completion deferred — durable persist failed', [
                        'asset' => $assetId, 'reason' => $durable['error'] ?? 'unknown',
                    ]);
                    return $this->sanitize([
                        'status'        => 'in_progress',
                        'asset_id'      => $assetId,
                        'scenes_total'  => $jobStatus['total'],
                        'scenes_done'   => $jobStatus['completed'],
                        'scenes_failed' => $jobStatus['failed'],
                    ]);
                }

                $this->completeAsset($assetId, [
                    'url'          => $durable['url'],
                    'storage_path' => $durable['storage_path'],
                    'file_size'    => $durable['file_size'],
                    'mime_type'    => 'video/mp4',
                ]);
                $this->engineIntel->recordToolUsage('creative', 'poll_video', 0.9);
                return $this->sanitize(['status' => 'completed', 'url' => $durable['url'], 'asset_id' => $assetId]);
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

    /**
     * D1 (2026-08-13) — DURABLE VIDEO PERSISTENCE.
     *
     * MiniMax returns a short-lived Aliyun CDN URL (video-product.cdn.minimax.io).
     * Storing that as the customer-facing asset URL meant the paid asset pointed at
     * a link that expires, is not same-origin, and could force a download rather
     * than playing inline. We download the finished MP4 exactly once onto the SAME
     * public disk the image pipeline already uses (storage/app/public, served by
     * nginx at /storage), which supports byte ranges natively — verified HTTP 206
     * with 'accept-ranges: bytes'. No new storage layer and no new controller.
     *
     * Idempotent by design: if the asset already has a storage_path whose file
     * exists, the provider is never contacted again. A browser poll and the
     * video:finalize-pending worker may both run without double-downloading.
     */
    /** Hard ceiling for a downloaded video (48 MB). A Hailuo-02 clip is single-digit MB. */
    private const MAX_VIDEO_BYTES = 50331648;

    private function persistVideoDurably(int $assetId, int $wsId, string $providerUrl): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        // ── Already durable? Never download twice. ──
        $row = DB::table('assets')->where('id', $assetId)->first(['storage_path', 'file_size']);
        if ($row && ! empty($row->storage_path) && $disk->exists($row->storage_path)) {
            return [
                'success'      => true,
                'url'          => $disk->url($row->storage_path),
                'storage_path' => $row->storage_path,
                'file_size'    => $row->file_size ?: $disk->size($row->storage_path),
                'reused'       => true,
            ];
        }

        if (stripos($providerUrl, 'https://') !== 0) {
            return ['success' => false, 'error' => 'provider_url_not_https'];
        }

        $storagePath = 'ai-videos/' . $wsId . '/' . md5($assetId . '|' . $providerUrl) . '.mp4';

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(180)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($providerUrl);

            if (! $resp->successful()) {
                return ['success' => false, 'error' => 'provider_download_http_' . $resp->status()];
            }

            // Reject an oversized payload from its DECLARED length before reading it
            // into memory — this host runs memory_limit=128M, so a hostile or
            // mistaken Content-Length must not be allowed to OOM the worker.
            $declared = (int) ($resp->header('Content-Length') ?: 0);
            if ($declared > self::MAX_VIDEO_BYTES) {
                return ['success' => false, 'error' => 'provider_download_too_large'];
            }

            $mime = strtolower((string) ($resp->header('Content-Type') ?: ''));
            if ($mime !== '' && strpos($mime, 'video/') !== 0 && strpos($mime, 'octet-stream') === false) {
                return ['success' => false, 'error' => 'provider_returned_non_video'];
            }

            $body  = (string) $resp->body();
            $bytes = strlen($body);
            if ($bytes < 1024) {
                return ['success' => false, 'error' => 'provider_download_too_small'];
            }
            if ($bytes > self::MAX_VIDEO_BYTES) {
                return ['success' => false, 'error' => 'provider_download_too_large'];
            }

            // VERIFY the write. Storage::put() returns false on a silent failure
            // (permissions, full disk) WITHOUT throwing; unchecked, that produces a
            // 'completed' asset with a broken URL that the customer already paid for.
            $stored = $disk->put($storagePath, $body);

            if (! $stored || ! $disk->exists($storagePath) || (int) $disk->size($storagePath) !== $bytes) {
                \Illuminate\Support\Facades\Log::warning('[Video D1] durable write did not persist', [
                    'asset' => $assetId, 'path' => $storagePath,
                ]);
                return ['success' => false, 'error' => 'durable_write_failed'];
            }

            return [
                'success'      => true,
                'url'          => $disk->url($storagePath),
                'storage_path' => $storagePath,
                'file_size'    => $bytes,
                'reused'       => false,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Video D1] durable persist failed', [
                'asset' => $assetId, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'durable_persist_exception'];
        }
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
        // Wave 61b — assets.title and assets.prompt are VARCHAR(255).
        // Enhanced prompts can exceed 255 chars. Use original_prompt
        // for title when available (typically short); truncate both
        // to 250 for safety. Full enhanced prompt still goes to the LLM.
        $titleSrc = $data['title']
            ?? ($data['metadata']['original_prompt'] ?? null)
            ?? $data['prompt']
            ?? 'Untitled';
        $promptStored = $data['prompt'] ?? null;
        $titleSafe = mb_substr((string) $titleSrc, 0, 250);
        $promptSafe = $promptStored !== null ? mb_substr((string) $promptStored, 0, 250) : null;

        $id = DB::table('assets')->insertGetId([
            'workspace_id'  => $wsId,
            'type'          => $data['type'] ?? 'image',
            'title'         => $titleSafe,
            'prompt'        => $promptSafe,
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

    /**
     * STUDIO888 Phase P — resolve a Studio image element's URL back to the
     * creative asset it came from, so "AI Edit" can open on a selected Studio
     * image. Tenancy-scoped (workspace-owned images only). Returns the minimal
     * shape the AI editor needs, or null when the image is not an editable
     * creative raster asset (e.g. an upload or an external URL).
     */
    public function resolveAssetByUrl(int $wsId, string $url): ?array
    {
        $url = trim($url);
        if ($url === '') return null;
        $row = DB::table('assets')
            ->where('workspace_id', $wsId)->where('type', 'image')
            ->where('status', 'completed')->whereNull('deleted_at')
            ->where('url', $url)
            ->orderByDesc('id')->first(['id', 'url', 'width', 'height', 'parent_asset_id', 'root_asset_id', 'version']);
        if (! $row) return null;
        return [
            'id' => (int) $row->id, 'url' => $row->url,
            'width' => $row->width, 'height' => $row->height, 'type' => 'image', 'status' => 'completed',
            'parent_asset_id' => $row->parent_asset_id, 'root_asset_id' => $row->root_asset_id, 'version' => $row->version,
        ];
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
            // WP2 Phase 2.1C gate (default OFF): ON => skip the legacy getImageBlueprint
            // string-concat so generateImage reasons the RAW prompt ONCE via ImageIntelligence
            // (eliminates the double stage). OFF => current legacy enrichment.
            $type === 'image'  => (config('studio.image_intelligence_enabled', false)
                ? ['type' => 'image', 'enhanced_prompt' => (string) ($context['prompt'] ?? ''), 'gated_canonical' => true]
                : $this->blueprint->getImageBlueprint($wsId, $context['prompt'] ?? '', $context)),
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

    /**
     * STUDIO888 Phase O — full version tree for an asset (original + all edits),
     * ordered oldest→newest. Tenancy-scoped. Used by the editor's version rail.
     */
    public function getAssetVersions(int $wsId, int $assetId): array
    {
        $anchor = DB::table('assets')->where('id', $assetId)->where('workspace_id', $wsId)->first();
        if (! $anchor) return ['versions' => [], 'root_asset_id' => null];
        $rootId = (int) ($anchor->root_asset_id ?: $anchor->id);
        $rows = DB::table('assets')
            ->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('root_asset_id', $rootId)->orWhere('id', $rootId))
            ->orderBy('version')->orderBy('id')->get()->toArray();
        return $this->sanitize(['versions' => $rows, 'root_asset_id' => $rootId]);
    }

    /**
     * STUDIO888 Phase O — masked / local prompt-based image editing.
     *
     * NON-DESTRUCTIVE: never overwrites the source; always creates a child
     * asset (parent_asset_id / root_asset_id / version / edit_mode). Real local
     * inpainting via ImageEditService → OpenAI gpt-image-1 /v1/images/edits.
     * Tenancy is enforced here (source must belong to $wsId). Billing +
     * idempotency are owned by EngineExecutionService (edit_image capability).
     *
     * @param array $params source_asset_id, prompt, selection_type(mask|rectangle|full),
     *                       region{x,y,w,h normalized}, mask_base64, edit_mode
     */
    public function editImage(int $wsId, array $params): array
    {
        $sourceId = (int) ($params['source_asset_id'] ?? 0);
        $prompt   = trim((string) ($params['prompt'] ?? ''));
        if ($sourceId <= 0) return ['success' => false, 'error' => 'A source image is required.'];
        if ($prompt === '') return ['success' => false, 'error' => 'Describe the change you want to make.'];

        // ── Tenancy: the source asset MUST belong to this workspace ──
        $src = DB::table('assets')->where('id', $sourceId)
            ->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $src) return ['success' => false, 'error' => 'Image not found in this workspace.', 'code' => 'NOT_FOUND'];
        if (($src->type ?? '') !== 'image') return ['success' => false, 'error' => 'Only images can be edited.'];
        if (($src->status ?? '') !== 'completed') return ['success' => false, 'error' => 'This image is not ready to edit yet.'];

        // ── Idempotency: a repeated submit with the same key returns the SAME
        // child version instead of creating (and re-generating) a duplicate.
        // Guards against double-clicks / retries at the data layer. (The editor
        // also disables submit while generating; concurrent-duplicate credit
        // dedup on the sync kernel path is a tracked follow-up.)
        $idemKey = isset($params['idempotency_key']) ? (string) $params['idempotency_key'] : null;
        if ($idemKey !== null && $idemKey !== '') {
            $dupe = DB::table('assets')->where('workspace_id', $wsId)
                ->where('parent_asset_id', (int) $src->id)->whereNull('deleted_at')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.idempotency_key')) = ?", [$idemKey])
                ->first();
            if ($dupe) {
                return $this->sanitize([
                    'success' => true, 'status' => 'completed', 'idempotent_replay' => true,
                    'id' => (int) $dupe->id, 'asset_id' => (int) $dupe->id, 'url' => $dupe->url, 'type' => 'image',
                    'parent_asset_id' => (int) $dupe->parent_asset_id, 'root_asset_id' => (int) $dupe->root_asset_id,
                    'version' => (int) $dupe->version, 'edit_mode' => $dupe->edit_mode,
                ]);
            }
        }

        // ── Load source bytes (local storage first, then its URL) ──
        $bytes = null;
        if (! empty($src->storage_path)) {
            try {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($src->storage_path)) {
                    $bytes = \Illuminate\Support\Facades\Storage::disk('public')->get($src->storage_path);
                }
            } catch (\Throwable) {}
        }
        if ($bytes === null && ! empty($src->url)) {
            try { $bytes = @file_get_contents($src->url); } catch (\Throwable) {}
        }
        if (! $bytes) return ['success' => false, 'error' => 'The original image could not be loaded.'];

        // ── Normalise selection ──
        $stype = $params['selection_type'] ?? 'full';
        if (! in_array($stype, ['mask', 'rectangle', 'full'], true)) $stype = 'full';
        $selection = [
            'type'        => $stype,
            'mask_base64' => $params['mask_base64'] ?? null,
            'region'      => $params['region'] ?? null,
        ];

        // ── Provider call (OpenAI inpainting, Laravel-direct) ──
        // gpt-image-1 edits are GENERATIVE (they concentrate the change in the
        // mask but can subtly re-render outside it). Proven in Phase O testing:
        // spatially-scoped prompt language markedly improves mask adherence, so
        // for a masked/region edit we append an explicit scope instruction. The
        // customer's original prompt is what we store/title; this suffix only
        // shapes the provider request. (Full-image edits get no suffix.)
        $providerPrompt = $prompt;
        if ($stype !== 'full') {
            $providerPrompt = rtrim($prompt, '. ')
                . '. Apply this change ONLY within the selected area; keep everything outside the selection unchanged.';
        }
        $edit = app(\App\Engines\Creative\Services\ImageEditService::class)->inpaint($bytes, $selection, $providerPrompt);
        if (! ($edit['success'] ?? false)) {
            // Returning success=false makes EngineExecutionService RELEASE the
            // reserved credit — the customer is never charged for a failed edit.
            return ['success' => false, 'error' => $this->humaniseEditError($edit['error'] ?? 'edit_failed')];
        }

        // ── Persist edited bytes → public storage URL (same path scheme as generation) ──
        $filename    = md5($prompt . microtime(true) . random_int(0, PHP_INT_MAX)) . '.png';
        $storagePath = 'ai-images/' . $wsId . '/' . $filename;
        try {
            // Phase P — VERIFY the write. Storage::put() returns false on a silent
            // failure (e.g. permissions/full disk) WITHOUT throwing; unchecked, that
            // produced a phantom asset with a broken URL yet still charged the user.
            // A failed write must fail the edit so EngineExecutionService RELEASES the
            // reserved credit (truthful billing) and no fileless asset is created.
            $stored = \Illuminate\Support\Facades\Storage::disk('public')->put($storagePath, $edit['bytes']);
            if (! $stored || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($storagePath)) {
                \Illuminate\Support\Facades\Log::warning('[ImageEdit] storage write did not persist', ['path' => $storagePath, 'ws' => $wsId]);
                return ['success' => false, 'error' => 'The edited image could not be saved. Please try again.'];
            }
            $url = \Illuminate\Support\Facades\Storage::disk('public')->url($storagePath);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'The edited image could not be saved.'];
        }

        // ── Non-destructive child version ──
        $rootId      = (int) ($src->root_asset_id ?: $src->id);
        $maxVersion  = (int) DB::table('assets')
            ->where(fn ($q) => $q->where('root_asset_id', $rootId)->orWhere('id', $rootId))
            ->max('version');
        $nextVersion = max(1, $maxVersion) + 1;
        $editMode    = $params['edit_mode'] ?? ($stype === 'full' ? 'edit_full' : ($stype === 'mask' ? 'edit_mask' : 'edit_region'));

        $childId = DB::table('assets')->insertGetId([
            'workspace_id'    => $wsId,
            'type'            => 'image',
            'title'           => mb_substr('Edit — ' . $prompt, 0, 250),
            'prompt'          => mb_substr($prompt, 0, 250),
            'provider'        => 'LevelUp AI',
            'model'           => 'LevelUp AI',
            'status'          => 'completed',
            'url'             => $url,
            'storage_path'    => $storagePath,
            'mime_type'       => 'image/png',
            'width'           => $edit['width'] ?? null,
            'height'          => $edit['height'] ?? null,
            'parent_asset_id' => $src->id,
            'root_asset_id'   => $rootId,
            'version'         => $nextVersion,
            'edit_mode'       => $editMode,
            'metadata_json'   => json_encode([
                'original_prompt' => $prompt,
                'edit'            => true,
                'edit_mode'       => $editMode,
                'selection_type'  => $stype,
                'region'          => $params['region'] ?? null,
                'has_mask'        => $stype === 'mask',
                'source_asset_id' => (int) $src->id,
                'provider_size'   => $edit['size'] ?? null,
                'provider_usage'  => $edit['usage'] ?? [],
                'idempotency_key' => $idemKey,
            ]),
            'tags_json'       => json_encode(['edit']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $this->sanitize([
            'success'         => true,
            'status'          => 'completed',
            'id'              => $childId,
            'asset_id'        => $childId,
            'url'             => $url,
            'type'            => 'image',
            'parent_asset_id' => (int) $src->id,
            'root_asset_id'   => $rootId,
            'version'         => $nextVersion,
            'edit_mode'       => $editMode,
            'width'           => $edit['width'] ?? null,
            'height'          => $edit['height'] ?? null,
        ]);
    }

    /** Map raw ImageEditService error codes to customer-safe messages. */
    private function humaniseEditError(string $code): string
    {
        return match (true) {
            str_contains($code, 'no OpenAI key')        => 'Image editing is temporarily unavailable.',
            str_contains($code, 'prompt_required')      => 'Describe the change you want to make.',
            str_contains($code, 'source_decode')        => 'The original image could not be read.',
            str_contains($code, 'mask_build')           => 'The selected region was invalid — try selecting again.',
            str_contains($code, 'provider_connection')  => 'The image editor timed out. Please try again.',
            str_contains($code, 'provider_error')       => 'The edit could not be completed. Try a simpler instruction or a different area.',
            default                                     => 'The edit could not be completed. Please try again.',
        };
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
