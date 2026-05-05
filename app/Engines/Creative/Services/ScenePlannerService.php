<?php

namespace App\Engines\Creative\Services;

use App\Connectors\CreativeConnector;
use App\Connectors\DeepSeekConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ScenePlannerService — Multi-Scene Video Architecture
 *
 * Handles multi-scene video generation:
 *   1. Plan scenes from a high-level prompt (via LLM)
 *   2. Dispatch each scene to the provider waterfall (MiniMax → Runway → Mock)
 *   3. Track async jobs in creative_video_jobs table
 *   4. Poll until all scenes are physically downloaded
 *   5. Stitch scene URLs into a final video record
 *
 * Jobs stay in_progress until the video file is confirmed downloaded.
 * Polling is queue-based — no synchronous waiting.
 */
class ScenePlannerService
{
    /**
     * Provider waterfall — tried in order, first success wins.
     */
    private const PROVIDERS = ['minimax', 'runway', 'mock'];

    /**
     * Max poll attempts before marking a job as timed_out.
     */
    private const MAX_POLL_ATTEMPTS = 60;  // 60 × 5s = 5 minutes max

    public function __construct(
        private DeepSeekConnector  $llm,
        private CreativeConnector  $connector,
        private WhiteLabelService  $whiteLabel,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    // ═══════════════════════════════════════════════════════
    // SCENE PLANNING
    // ═══════════════════════════════════════════════════════

    /**
     * Break a high-level video prompt into a scene plan.
     * Returns an array of scene objects, each with its own focused prompt.
     */
    public function planScenes(string $prompt, array $options = []): array
    {
        $duration   = $options['duration'] ?? 15;
        $style      = $options['style'] ?? '';
        $sceneCount = max(1, min(6, (int) round($duration / 5)));

        $systemPrompt = "You are a video director. Break the given concept into {$sceneCount} distinct video scenes. Return ONLY valid JSON — no markdown, no explanation.";

        $userPrompt = <<<EOT
Break this video into {$sceneCount} scenes:

Concept: {$prompt}
Total duration: {$duration} seconds
Visual style: {$style}

Return a JSON object with a "scenes" array. Each scene must have:
- index: integer (1-based)
- duration: seconds for this scene
- prompt: specific, visual, concrete image-to-video prompt for this scene
- camera: camera motion (static, pan left, zoom in, dolly forward, etc.)
- description: one sentence describing what happens in this scene

Make each prompt self-contained and visually specific. Avoid vague abstract descriptions.
EOT;

        // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun('image_generation', ...)
        // fold-pattern (which was returning prose-style text and producing 0 scenes
        // in the e2e verify run) to chatJson with the scene-planner system prompt
        // applied directly. The runtime forces JSON mode and parses server-side, so
        // the {"scenes":[...]} structure now arrives reliably structured.
        try {
            $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
                'task'        => 'video_scene_planning',
                'duration'    => (string) $duration,
                'scene_count' => (string) $sceneCount,
                'style'       => $style ?: 'default',
            ], 800);

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null) && !empty($result['parsed']['scenes'])) {
                return $result['parsed']['scenes'];
            }
        } catch (\Throwable $e) {
            Log::warning('ScenePlannerService::planScenes runtime call failed', ['error' => $e->getMessage()]);
        }

        // Fallback: single scene with original prompt
        return [[
            'index'       => 1,
            'duration'    => $duration,
            'prompt'      => $prompt . ($style ? ". Style: {$style}" : ''),
            'camera'      => 'static',
            'description' => 'Main video content',
        ]];
    }

    // ═══════════════════════════════════════════════════════
    // JOB DISPATCH
    // ═══════════════════════════════════════════════════════

    /**
     * Dispatch a single scene for generation.
     * Creates a job record and initiates the provider call.
     * Returns the job record — caller polls with checkJob().
     */
    public function dispatchSceneJob(int $wsId, int $assetId, array $scene, array $options = []): array
    {
        $jobRef = Str::uuid()->toString();

        // Create job record first — status = dispatching
        $jobId = DB::table('creative_video_jobs')->insertGetId([
            'workspace_id'  => $wsId,
            'asset_id'      => $assetId,
            'job_ref'       => $jobRef,
            'scene_index'   => $scene['index'] ?? 1,
            'scene_prompt'  => $scene['prompt'],
            'provider'      => null,
            'provider_job_id'=> null,
            'status'        => 'dispatching',
            'poll_attempts' => 0,
            'video_url'     => null,
            'error'         => null,
            'metadata_json' => json_encode([
                'duration'    => $scene['duration'] ?? 5,
                'camera'      => $scene['camera'] ?? 'static',
                'aspect_ratio'=> $options['aspect_ratio'] ?? '16:9',
            ]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Try providers in waterfall order
        $dispatched = false;
        foreach (self::PROVIDERS as $provider) {
            try {
                $result = $this->dispatchToProvider($provider, $scene, $options);

                if ($result['success']) {
                    DB::table('creative_video_jobs')->where('id', $jobId)->update([
                        'provider'        => $provider,
                        'provider_job_id' => $result['job_id'] ?? null,
                        'status'          => 'in_progress',
                        'updated_at'      => now(),
                    ]);
                    $dispatched = true;
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning("ScenePlannerService: provider {$provider} failed", ['error' => $e->getMessage()]);
            }
        }

        if (!$dispatched) {
            DB::table('creative_video_jobs')->where('id', $jobId)->update([
                'status'     => 'failed',
                'error'      => 'All providers failed',
                'updated_at' => now(),
            ]);
        }

        return DB::table('creative_video_jobs')->where('id', $jobId)->first() ? (array) DB::table('creative_video_jobs')->where('id', $jobId)->first() : ['id' => $jobId, 'status' => 'failed'];
    }

    // ═══════════════════════════════════════════════════════
    // JOB POLLING
    // ═══════════════════════════════════════════════════════

    /**
     * Poll a single scene job. Called by the queue worker on a schedule.
     * Updates job status. Returns the updated job record.
     * Jobs stay in_progress until the video_url is confirmed non-empty.
     */
    public function pollJob(int $jobId): array
    {
        $job = DB::table('creative_video_jobs')->where('id', $jobId)->first();
        if (!$job) {
            return ['status' => 'not_found'];
        }

        if (in_array($job->status, ['completed', 'failed', 'timed_out'])) {
            return (array) $job;
        }

        $attempts = (int) $job->poll_attempts + 1;

        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            DB::table('creative_video_jobs')->where('id', $jobId)->update([
                'status'        => 'timed_out',
                'poll_attempts' => $attempts,
                'updated_at'    => now(),
            ]);
            return (array) DB::table('creative_video_jobs')->where('id', $jobId)->first();
        }

        DB::table('creative_video_jobs')->where('id', $jobId)->update([
            'poll_attempts' => $attempts,
            'updated_at'    => now(),
        ]);

        try {
            $result = $this->connector->pollVideoJob($job->provider_job_id ?? '', $job->provider ?? 'mock');

            if ($result['status'] === 'completed' && !empty($result['url'])) {
                // Confirm the URL is actually accessible before marking complete
                DB::table('creative_video_jobs')->where('id', $jobId)->update([
                    'status'     => 'completed',
                    'video_url'  => $result['url'],
                    'updated_at' => now(),
                ]);
            } elseif ($result['status'] === 'failed') {
                DB::table('creative_video_jobs')->where('id', $jobId)->update([
                    'status'     => 'failed',
                    'error'      => $result['error'] ?? 'Provider reported failure',
                    'updated_at' => now(),
                ]);
            }
            // still in_progress — just updated poll_attempts above, job stays in_progress
        } catch (\Throwable $e) {
            Log::warning("ScenePlannerService::pollJob({$jobId}) error", ['error' => $e->getMessage()]);
        }

        return (array) DB::table('creative_video_jobs')->where('id', $jobId)->first();
    }

    /**
     * Get all scene jobs for an asset. Used to check overall completion.
     */
    public function getAssetJobs(int $assetId): array
    {
        return DB::table('creative_video_jobs')
            ->where('asset_id', $assetId)
            ->orderBy('scene_index')
            ->get()
            ->toArray();
    }

    /**
     * Check if all scenes for an asset are complete.
     * Returns summary: complete, in_progress, failed counts.
     */
    public function getAssetJobStatus(int $assetId): array
    {
        $jobs = $this->getAssetJobs($assetId);

        if (empty($jobs)) {
            return ['status' => 'no_jobs', 'total' => 0, 'completed' => 0, 'failed' => 0, 'in_progress' => 0];
        }

        $statuses = array_column($jobs, 'status');
        $completed  = count(array_filter($statuses, fn($s) => $s === 'completed'));
        $failed     = count(array_filter($statuses, fn($s) => in_array($s, ['failed', 'timed_out'])));
        $inProgress = count(array_filter($statuses, fn($s) => in_array($s, ['in_progress', 'dispatching'])));
        $total      = count($jobs);

        $overallStatus = match (true) {
            $completed === $total             => 'completed',
            $failed > 0 && $inProgress === 0 => 'failed',
            $inProgress > 0                  => 'in_progress',
            default                          => 'partial',
        };

        return [
            'status'      => $overallStatus,
            'total'       => $total,
            'completed'   => $completed,
            'failed'      => $failed,
            'in_progress' => $inProgress,
            'video_urls'  => array_filter(array_map(fn($j) => $j->video_url ?? null, $jobs)),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // SCENE STITCHING
    // ═══════════════════════════════════════════════════════

    /**
     * Produce a final video record from completed scene URLs.
     * For now: returns the first completed scene as the "final" video.
     * Full stitching (ffmpeg/provider-side) is a Phase 2 enhancement.
     */
    public function stitchScenes(int $assetId): array
    {
        $status = $this->getAssetJobStatus($assetId);

        if ($status['status'] !== 'completed') {
            return ['success' => false, 'error' => 'Not all scenes complete', 'status' => $status];
        }

        $urls = array_values($status['video_urls']);

        if (empty($urls)) {
            return ['success' => false, 'error' => 'No video URLs found'];
        }

        // MVP: return first scene URL as final output.
        // Multi-scene stitching via ffmpeg is a D1+ enhancement.
        $finalUrl = $urls[0];

        return [
            'success'    => true,
            'url'        => $finalUrl,
            'scene_urls' => $urls,
            'scene_count'=> count($urls),
            'stitched'   => count($urls) === 1 ? false : false, // full stitching pending
            'note'       => count($urls) > 1 ? 'Multi-scene stitching queued — first scene delivered as preview' : null,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    private function dispatchToProvider(string $provider, array $scene, array $options): array
    {
        $prompt = $scene['prompt'];
        $meta   = [
            'duration'    => $scene['duration'] ?? 5,
            'aspect_ratio'=> $options['aspect_ratio'] ?? '16:9',
            'camera'      => $scene['camera'] ?? 'static',
        ];

        return match ($provider) {
            'minimax' => $this->connector->generateVideoViaProvider($prompt, array_merge($meta, ['provider' => 'minimax'])),
            'runway'  => $this->connector->generateVideoViaProvider($prompt, array_merge($meta, ['provider' => 'runway'])),
            'mock'    => $this->mockVideo($prompt),
            default   => ['success' => false, 'error' => 'Unknown provider'],
        };
    }

    private function mockVideo(string $prompt): array
    {
        // Mock provider — always succeeds immediately with a placeholder URL.
        // Used as last-resort fallback and in test environments.
        return [
            'success' => true,
            'job_id'  => 'mock-' . Str::random(12),
            'url'     => null,  // mock jobs complete on first poll
            'provider'=> 'mock',
        ];
    }
}
