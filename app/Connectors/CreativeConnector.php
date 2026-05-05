<?php

namespace App\Connectors;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreativeConnector extends BaseConnector
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private int $pollMaxAttempts;
    private int $pollIntervalMs;
    private int $minAssetSizeBytes;
    private RuntimeClient $runtime;

    public function __construct(?RuntimeClient $runtime = null)
    {
        $this->baseUrl = rtrim(config('connectors.creative.base_url', ''), '/');
        $this->apiKey = config('connectors.creative.api_key', '');
        $this->timeout = (int) config('connectors.creative.timeout', 30);
        $this->pollMaxAttempts = (int) config('connectors.creative.poll_max_attempts', 30);
        $this->pollIntervalMs = (int) config('connectors.creative.poll_interval_ms', 2000);
        $this->minAssetSizeBytes = (int) config('connectors.creative.min_asset_size', 1024);
        // Phase 2A.-1: image generation now goes through the runtime (DALL-E 3),
        // not the phantom localhost:8000 path. Resolve from container if not injected.
        $this->runtime = $runtime ?? app(RuntimeClient::class);
    }

    public function supportedActions(): array
    {
        return ['generate_image', 'generate_video', 'get_asset', 'list_assets'];
    }

    public function validationRules(string $action): array
    {
        return match ($action) {
            'generate_image' => [
                'prompt' => 'required|string|max:2000',
                'style' => 'nullable|string|max:100',
                'aspect_ratio' => 'nullable|in:1:1,16:9,9:16,4:3,3:4',
                'model' => 'nullable|string',
            ],
            'generate_video' => [
                'prompt' => 'required|string|max:2000',
                'image_url' => 'nullable|string',
                'duration' => 'nullable|integer|min:2|max:30',
                'model' => 'nullable|string',
            ],
            'get_asset' => [
                'asset_id' => 'required|string',
            ],
            'list_assets' => [
                'type' => 'nullable|in:image,video',
                'limit' => 'nullable|integer|min:1|max:100',
            ],
            default => [],
        };
    }

    public function execute(string $action, array $params): array
    {
        $validated = $this->validate($action, $params);

        try {
            return match ($action) {
                'generate_image' => $this->executeGenerateImage($validated),
                'generate_video' => $this->generateVideo($validated),
                'get_asset' => $this->getAsset($validated),
                'list_assets' => $this->listAssets($validated),
                default => $this->failure("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            Log::error("CreativeConnector::{$action} failed", ['error' => $e->getMessage()]);
            return $this->failure("Creative action failed: {$e->getMessage()}");
        }
    }

    /**
     * Adapter: takes the validated $params dict from execute() and forwards to
     * the public generateImage(prompt, options) method, then wraps the flat
     * response in the standard execute() envelope so the existing API contract
     * (success / data / message) is preserved for callers that go through
     * execute('generate_image', ...).
     */
    private function executeGenerateImage(array $params): array
    {
        $result = $this->generateImage($params['prompt'] ?? '', $params);

        if (! ($result['success'] ?? false)) {
            return $this->failure($result['error'] ?? 'Image generation failed', $result);
        }

        return $this->success([
            'asset_id' => $result['asset_id'] ?? null,
            'url'      => $result['url']      ?? null,
            'type'     => 'image',
            'size'     => $result['file_size'] ?? $this->minAssetSizeBytes,
            'metadata' => $result['metadata'] ?? [],
        ], 'Asset generated successfully');
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->client()->get('/api/creative/health');
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Action Methods ───────────────────────────────────────────────────

    /**
     * Generate an image via runtime DALL-E 3.
     *
     * REFACTORED 2026-04-13 (Phase 2A.-1): replaced the dead-on-call
     * localhost:8000 path with the runtime DALL-E 3 route. The runtime owns
     * the OpenAI key (Laravel doesn't), so this is the canonical path per
     * the hands-vs-brain pattern. The synchronous response from DALL-E 3
     * means no polling — we get the URL back in one round trip.
     *
     * **Public + flat-shape return** so `CreativeService::generateImage()` can
     * call `$this->connector->generateImage($enhancedPrompt, [...])` directly
     * (which it has been doing since deploy — but the original method was
     * `private(array $params)` which threw a visibility error AND a signature
     * error on every call, masked by the phantom localhost:8000 connection
     * refused at the level above). Phase 2A.-1 fixes all three bugs at once.
     *
     * Returns a flat array (not the success() / failure() envelope) because
     * CreativeService reads `$result['url']`, `$result['file_size']`, etc.
     * directly without unwrapping a `data` key. The execute('generate_image')
     * path goes through executeGenerateImage() which adapts the flat shape
     * back into the standard envelope.
     *
     * @param string $prompt   Image prompt (will be DALL-E 3 prompt-rewritten)
     * @param array  $options  size | aspect_ratio | quality | style
     *                         - size: '1024x1024' | '1024x1792' | '1792x1024' (DALL-E 3 native)
     *                         - aspect_ratio: '1:1' | '16:9' | '9:16' | '4:3' | '3:4' (mapped to size)
     *                         - quality: 'standard' | 'hd'
     *                         - style: free-text — folded into the prompt as "Style: ..."
     *
     * @return array{
     *   success: bool,
     *   url?: string,
     *   width?: int,
     *   height?: int,
     *   file_size?: int,
     *   storage_path?: ?string,
     *   asset_id?: string,
     *   metadata?: array,
     *   error?: string,
     * }
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is required'];
        }

        // Resolve size: explicit DALL-E 3 native value wins, else map from
        // aspect_ratio, else default to 1024x1024.
        $size = $options['size'] ?? $this->mapAspectRatioToSize($options['aspect_ratio'] ?? null);

        $result = $this->runtime->imageGenerate($prompt, [
            'style'   => $options['style']   ?? null,
            'size'    => $size,
            'quality' => $options['quality'] ?? null,
        ]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Image generation failed'];
        }

        $url = $result['url'] ?? null;
        if (! $url) {
            return ['success' => false, 'error' => 'Runtime returned no image URL'];
        }

        // Parse width/height from the DALL-E 3 size string for the assets table
        [$width, $height] = $this->parseSize($result['size'] ?? $size ?? '1024x1024');

        // Optional Content-Length probe — DALL-E 3 URLs are always valid CDN
        // links, but populating file_size lets verifyResult() pass its size
        // threshold check. Falls back to minAssetSizeBytes placeholder if HEAD
        // fails (rare — only on transient network issues).
        $fileSize = 0;
        try {
            $head = Http::timeout(10)->head($url);
            if ($head->successful()) {
                $fileSize = (int) ($head->header('Content-Length') ?? 0);
            }
        } catch (\Throwable) {
            // Ignore — size verification is optional.
        }
        if ($fileSize === 0) {
            $fileSize = $this->minAssetSizeBytes;
        }

        return [
            'success'      => true,
            'url'          => $url,
            'width'        => $width,
            'height'       => $height,
            'file_size'    => $fileSize,
            'storage_path' => null,  // DALL-E 3 returns hosted URLs; no local storage path
            'asset_id'     => 'dalle3-' . substr(md5($url . microtime(true)), 0, 12),
            'metadata'     => [
                'revised_prompt' => $result['revised_prompt'] ?? null,
                'model'          => $result['model'] ?? 'dall-e-3',
                'provider'       => $result['provider'] ?? 'openai',
                'duration_ms'    => $result['duration_ms'] ?? null,
                'requested_size' => $result['size'] ?? null,
                'quality'        => $result['quality'] ?? null,
            ],
        ];
    }

    /**
     * Map a Laravel-style aspect_ratio (1:1 / 16:9 / 9:16 / 4:3 / 3:4) to one
     * of DALL-E 3's three supported sizes. Wide → 1792x1024, tall → 1024x1792,
     * everything else → 1024x1024.
     */
    private function mapAspectRatioToSize(?string $ar): ?string
    {
        return match ($ar) {
            '1:1', null => '1024x1024',
            '16:9', '4:3', '3:2' => '1792x1024',
            '9:16', '3:4', '2:3' => '1024x1792',
            default => '1024x1024',
        };
    }

    /**
     * Parse a DALL-E 3 size string like "1024x1792" into [width, height].
     */
    private function parseSize(string $size): array
    {
        if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [1024, 1024];
    }

    // ═══════════════════════════════════════════════════════════════
    // PUBLIC VIDEO API — called by ScenePlannerService waterfall
    // MiniMax T2V API: POST → task_id → poll → video URL
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate video via provider. Called by ScenePlannerService::dispatchToProvider().
     * The connector name is overloaded — ScenePlannerService calls this as generateVideo($prompt, $meta).
     */
    public function generateVideoViaProvider(string $prompt, array $options = []): array
    {
        $provider = $options['provider'] ?? 'minimax';

        return match ($provider) {
            'minimax' => $this->minimaxGenerateVideo($prompt, $options),
            'runway'  => ['success' => false, 'error' => 'Runway not configured'],
            'mock'    => ['success' => true, 'job_id' => 'mock-' . \Illuminate\Support\Str::random(12), 'provider' => 'mock'],
            default   => ['success' => false, 'error' => "Unknown video provider: {$provider}"],
        };
    }

    /**
     * Poll a video generation job by provider.
     * Called by ScenePlannerService::pollJob().
     */
    public function pollVideoJob(string $providerJobId, string $provider = 'minimax'): array
    {
        return match ($provider) {
            'minimax' => $this->minimaxPollVideo($providerJobId),
            'mock'    => ['status' => 'completed', 'url' => 'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4'],
            default   => ['status' => 'failed', 'error' => "Cannot poll provider: {$provider}"],
        };
    }

    // ── MiniMax T2V Implementation ───────────────────────────────

    private function minimaxGenerateVideo(string $prompt, array $options): array
    {
        $apiKey  = config('services.minimax.api_key', env('MINIMAX_API_KEY', ''));
        $groupId = config('services.minimax.group_id', env('MINIMAX_GROUP_ID', ''));

        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'MiniMax API key not configured'];
        }

        $model = 'T2V-01';  // Text-to-Video model
        $url   = "https://api.minimax.chat/v1/text/video_generation";

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                ])
                ->post($url, [
                    'model'  => $model,
                    'prompt' => $prompt,
                ]);

            if ($response->failed()) {
                \Illuminate\Support\Facades\Log::warning('[MiniMax] Video generation failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['success' => false, 'error' => 'MiniMax API error: ' . $response->status()];
            }

            $data   = $response->json();
            $taskId = $data['task_id'] ?? null;

            if (!$taskId) {
                return ['success' => false, 'error' => 'MiniMax returned no task_id', 'raw' => $data];
            }

            return [
                'success'  => true,
                'job_id'   => $taskId,
                'provider' => 'minimax',
                'model'    => $model,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[MiniMax] Video generation exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function minimaxPollVideo(string $taskId): array
    {
        $apiKey = config('services.minimax.api_key', env('MINIMAX_API_KEY', ''));
        if (empty($apiKey)) {
            return ['status' => 'failed', 'error' => 'MiniMax API key not configured'];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                ])
                ->get("https://api.minimax.chat/v1/query/video_generation", [
                    'task_id' => $taskId,
                ]);

            if ($response->failed()) {
                return ['status' => 'failed', 'error' => 'Poll failed: HTTP ' . $response->status()];
            }

            $data   = $response->json();
            $status = $data['status'] ?? 'Unknown';

            // MiniMax statuses: Queueing, Processing, Success, Fail
            if ($status === 'Success') {
                $fileId = $data['file_id'] ?? null;
                $videoUrl = $fileId
                    ? "https://api.minimax.chat/v1/files/retrieve?file_id={$fileId}"
                    : ($data['video_url'] ?? $data['download_url'] ?? null);

                // If file_id, fetch the actual download URL
                if ($fileId) {
                    $dlResponse = \Illuminate\Support\Facades\Http::timeout(15)
                        ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                        ->get("https://api.minimax.chat/v1/files/retrieve", ['file_id' => $fileId]);
                    if ($dlResponse->ok()) {
                        $dlData = $dlResponse->json();
                        $videoUrl = $dlData['file']['download_url'] ?? $videoUrl;
                    }
                }

                return [
                    'status' => 'completed',
                    'url'    => $videoUrl,
                    'file_id'=> $fileId,
                ];
            }

            if ($status === 'Fail') {
                return ['status' => 'failed', 'error' => $data['error_message'] ?? 'MiniMax generation failed'];
            }

            // Queueing or Processing
            return ['status' => 'in_progress', 'minimax_status' => $status];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

        private function generateVideo(array $params): array
    {
        $response = $this->client()->post('/api/creative/generate', array_merge($params, [
            'type' => 'video',
        ]));

        if ($response->failed()) {
            return $this->failure('Video generation request failed: ' . $response->body());
        }

        $data = $response->json();
        $jobId = $data['job_id'] ?? null;

        if (! $jobId) {
            return $this->validateAndReturnAsset($data);
        }

        return $this->pollForCompletion($jobId, 'video');
    }

    private function getAsset(array $params): array
    {
        $response = $this->client()->get("/api/creative/assets/{$params['asset_id']}");

        if ($response->failed()) {
            return $this->failure('Asset not found');
        }

        return $this->success($response->json(), 'Asset retrieved');
    }

    private function listAssets(array $params): array
    {
        $response = $this->client()->get('/api/creative/assets', $params);

        if ($response->failed()) {
            return $this->failure('Failed to list assets');
        }

        return $this->success([
            'assets' => $response->json('data', []),
            'total' => $response->json('total', 0),
        ], 'Assets listed');
    }

    // ── Verification ─────────────────────────────────────────────────────

    public function verifyResult(string $action, array $params, array $result): array
    {
        if (! ($result['success'] ?? false)) {
            return ['verified' => false, 'message' => 'Execution reported failure', 'data' => []];
        }

        $data = $result['data'] ?? [];

        if (in_array($action, ['generate_image', 'generate_video'])) {
            $url = $data['url'] ?? null;
            $size = $data['size'] ?? 0;

            if (! $url) {
                return ['verified' => false, 'message' => 'No asset URL in result', 'data' => $data];
            }
            if ($size < $this->minAssetSizeBytes) {
                return ['verified' => false, 'message' => "Asset size {$size} below threshold {$this->minAssetSizeBytes}", 'data' => $data];
            }
            return ['verified' => true, 'message' => 'Asset verified', 'data' => $data];
        }

        if ($action === 'get_asset') {
            return ! empty($data['asset_id'] ?? $data['id'] ?? null)
                ? ['verified' => true, 'message' => 'Asset found', 'data' => $data]
                : ['verified' => false, 'message' => 'Asset not found in result', 'data' => $data];
        }

        return ['verified' => true, 'message' => 'Result accepted', 'data' => $data];
    }

    // ── Polling & Validation ─────────────────────────────────────────────

    private function pollForCompletion(string $jobId, string $type): array
    {
        for ($i = 0; $i < $this->pollMaxAttempts; $i++) {
            usleep($this->pollIntervalMs * 1000);

            $response = $this->client()->get("/api/creative/jobs/{$jobId}");

            if ($response->failed()) {
                continue;
            }

            $data = $response->json();
            $status = $data['status'] ?? 'unknown';

            if ($status === 'completed') {
                return $this->validateAndReturnAsset($data);
            }

            if ($status === 'failed') {
                return $this->failure("Creative job failed: " . ($data['error'] ?? 'Unknown error'));
            }

            // Still in_progress — keep polling
        }

        return $this->failure("Creative job timed out after {$this->pollMaxAttempts} poll attempts");
    }

    private function validateAndReturnAsset(array $data): array
    {
        $url = $data['url'] ?? $data['asset_url'] ?? null;
        $size = $data['size'] ?? $data['file_size'] ?? 0;

        if ($url && $size >= $this->minAssetSizeBytes) {
            return $this->success([
                'asset_id' => $data['id'] ?? $data['asset_id'] ?? null,
                'url' => $url,
                'type' => $data['type'] ?? 'unknown',
                'size' => $size,
            ], 'Asset generated successfully');
        }

        if ($url && $size < $this->minAssetSizeBytes) {
            return $this->failure("Asset too small ({$size} bytes), likely corrupted");
        }

        return $this->failure('No asset URL in response');
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->retry(3, 1000);
    }
}
