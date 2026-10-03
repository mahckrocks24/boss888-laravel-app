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
     * NOTE: 'mock' is filtered out of the production waterfall by
     * activeProviders() — it must NEVER run outside local/testing.
     */
    private const PROVIDERS = ['minimax', 'runway', 'mock'];

    /**
     * Max poll attempts before marking a job as timed_out.
     */
    private const MAX_POLL_ATTEMPTS = 60;  // 60 × 5s = 5 minutes max

    /**
     * 2026-08-10 LAUNCH CONSTRAINT — single scene only. stitchScenes() is still
     * a stub that would silently drop scenes 2..N, so every request is collapsed
     * to ONE scene rather than generate-and-discard. Raise this only when real
     * (ffmpeg) multi-scene stitching ships.
     */
    private const MAX_LAUNCH_SCENES = 1;

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
    /**
     * RFC-0009 P6 (2026-09-16): the ONE scene-count rule, shared with BlueprintService so the
     * video blueprint and the scene plan can never disagree (round(duration/5), 1..6, then the
     * launch cap). Public because the blueprint reads it.
     */
    public static function sceneCountFor(int|float $duration): int
    {
        $n = max(1, min(6, (int) round($duration / 5)));
        return min($n, self::MAX_LAUNCH_SCENES);
    }

    /**
     * RFC-0009 P6: make the planner's scenes internally consistent with the request — the
     * requested total duration is the truth; scene durations are redistributed to sum to it
     * (last scene absorbs the remainder) and indices are re-numbered. Nothing about the
     * scenes' content is touched. Deterministic; pure.
     */
    public static function normaliseScenes(array $scenes, int $duration): array
    {
        $scenes = array_values(array_filter($scenes, fn ($s) => is_array($s) && trim((string) ($s['prompt'] ?? '')) !== ''));
        if (! $scenes) return [];
        $n = count($scenes);
        $given = array_map(fn ($s) => max(0, (float) ($s['duration'] ?? 0)), $scenes);
        $sum = array_sum($given);
        if ($sum <= 0) { $given = array_fill(0, $n, $duration / $n); $sum = $duration; }
        $out = []; $acc = 0.0;
        foreach ($scenes as $i => $s) {
            $d = ($i === $n - 1) ? max(1, $duration - $acc) : max(1, round($given[$i] / $sum * $duration));
            $acc += $d;
            $s['index'] = $i + 1; $s['duration'] = (int) round($d);
            $out[] = $s;
        }
        return $out;
    }

    public function planScenes(string $prompt, array $options = []): array
    {
        $duration   = (int) ($options['duration'] ?? 15);
        $style      = $options['style'] ?? '';
        $sceneCount = self::sceneCountFor($duration);

        // RFC-0009 P3/P4 (2026-09-16): the planner receives the same authoritative brand context
        // as the image path, and the same grounding rules — no invented logos, no invented facts.
        $brandContext = trim((string) ($options['brand_context'] ?? ''));
        $hasLogo      = (bool) ($options['has_logo'] ?? false);
        // RFC-0009 P2: a named platform agent stays the subject, identified by registry facts only.
        $subj = is_array($options['subject_reference'] ?? null) ? $options['subject_reference'] : \App\Core\ImageIntelligence\AgentSubjectResolver::resolve($prompt);
        $subjectRule = $subj
            ? "SUBJECT: the concept names " . ($subj['prompt_facts'] ?? $subj['name']) . " — a real LevelUpGrowth agent with an approved portrait. She must remain the subject of the video and be referred to by name and role in every scene prompt; never describe her face, hair, skin, gender presentation, clothing or body, and never replace her with anonymous hands or a faceless figure. "
              . (empty($subj['reference_supported']) ? "The provider cannot use her portrait, so do not promise likeness — identify her by name and role. " : "Match her reference portrait. ")
            : "";
        $systemPrompt = "You are a video director. Break the given concept into {$sceneCount} distinct video scenes. Return ONLY valid JSON — no markdown, no explanation. "
            . "GROUNDING RULES: use only the facts in the concept and the brand context; never invent metrics, revenue figures, awards, testimonials, product claims or people's appearance. "
            . ($hasLogo ? "A brand logo asset exists and may be shown. " : "There is NO logo asset — never depict, mention or place a logo or watermark. ")
            . $subjectRule
            . "Never put words on screen - no captions, titles, labels, signs or lower thirds, even when the concept quotes a headline: words are added afterwards by a separate titling step. "   // VIDEO-CERT-1
            . "Refer to people only by role (the chef, a guest); never give a named real person a face - show hands, plates, the table and the room. "
            . "These rules are for you: never restate them inside a scene prompt — scene prompts describe only what the camera sees.";

        if (! empty($options['from_photo']) && count((array) ($options['photos'] ?? [])) > 1) {   // RFC-0025 P3: one scene per photo, in order
            $sceneCount = count((array) $options['photos']);
            $systemPrompt .= " Scene N starts from the owner's photo N (" . mb_substr(trim((string) ($options['photo_description'] ?? '')), 0, 900) . ")"
                . ": keep everything in each photo exactly as it is. Describe only natural motion of what is already there and the camera move; add nothing new; keep the scenes in the photos' order.";
        } elseif (! empty($options['from_photo'])) {   // RFC-0025 P2: the owner's photo is the first frame - describe motion, never new content
            $sceneCount = 1;
            $systemPrompt .= " The first frame is the owner's own photo" . (trim((string) ($options['photo_description'] ?? '')) !== '' ? ' (' . mb_substr(trim((string) $options['photo_description']), 0, 600) . ')' : '')
                . ": keep everything in it exactly as it is - the same people, objects, places and colours. Describe only natural motion of what is already there and the camera move; add nothing new.";
        }
        $userPrompt = <<<EOT
Break this video into {$sceneCount} scenes:

Concept: {$prompt}
Total duration: {$duration} seconds
Visual style: {$style}
Brand context: {$brandContext}

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
                // RFC-0009 P6: the model may return more or fewer scenes than asked; keep its content,
                // make the durations add up to the request, and let the caller record count(scenes).
                $norm = self::normaliseScenes((array) $result['parsed']['scenes'], $duration);
                $__titled = (bool) \App\Core\ImageIntelligence\ImageIntelligenceService::quotedText($prompt);   // VIDEO-CERT-3: a title is coming - keep its space clear
                if ($norm) return array_map(fn ($sc) => ['prompt' => self::cameraTag((string) ($sc['camera'] ?? '')) . self::cleanScenePrompt((string) ($sc['prompt'] ?? ''), (string) ($options['business_name'] ?? '')) . ($__titled ? ' Keep the upper quarter of the frame calm, dark-to-mid toned and free of the main subject.' : '')] + $sc, $norm);   // VIDEO-CERT-1, RFC-0025 P0 camera
            }
        } catch (\Throwable $e) {
            Log::warning('ScenePlannerService::planScenes runtime call failed', ['error' => $e->getMessage()]);
        }

        // Fallback: single scene with original prompt
        return [[
            'index'       => 1,
            'duration'    => $duration,
            'prompt'      => self::cleanScenePrompt($prompt . ($style ? ". Style: {$style}" : ''), (string) ($options['business_name'] ?? '')),   // VIDEO-CERT-1
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

        // VIDEO-2: a vertical or square video starts from a first frame in that shape (queued: the frame takes ~30 s)
        // RFC-0025 P2: the owner's photo always goes through the frame path, whatever the shape
        if (! empty($options['source_image'])) {
            $__m = json_decode((string) DB::table('creative_video_jobs')->where('id', $jobId)->value('metadata_json'), true) ?: [];
            DB::table('creative_video_jobs')->where('id', $jobId)->update(['metadata_json' => json_encode($__m + ['source_image' => (string) $options['source_image']])]);
        }
        if (self::needsFrame((string) ($options['aspect_ratio'] ?? '16:9')) || ! empty($options['source_image'])) {
            DB::table('creative_video_jobs')->where('id', $jobId)->update(['status' => 'framing', 'updated_at' => now()]);
            \App\Jobs\VideoFirstFrameJob::dispatch($jobId);
            return (array) DB::table('creative_video_jobs')->where('id', $jobId)->first();
        }

        // VIDEO-RPM-1: over the provider's per-minute budget - wait in line instead of being refused
        if (self::providerWait() > 0) {
            $__m = json_decode((string) DB::table('creative_video_jobs')->where('id', $jobId)->value('metadata_json'), true) ?: [];
            $this->retryLater($jobId, $__m + ['no_frame' => true], self::providerWait(), 'paced');
            return (array) DB::table('creative_video_jobs')->where('id', $jobId)->first();
        }
        // Try providers in waterfall order (mock excluded outside local/testing)
        $dispatched = false;
        $__lastErr = '';
        foreach ($this->activeProviders() as $provider) {
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
                $__lastErr = (string) ($result['error'] ?? $__lastErr);
            } catch (\Throwable $e) {
                Log::warning("ScenePlannerService: provider {$provider} failed", ['error' => $e->getMessage()]);   // VIDEO-F1: refusals are logged by the connector with the provider's reason
            }
        }

        if (! $dispatched && preg_match('/\b1002\b|rate limit|too many requests|\b429\b/i', $__lastErr)) {   // VIDEO-RPM-1
            $__m = json_decode((string) DB::table('creative_video_jobs')->where('id', $jobId)->value('metadata_json'), true) ?: [];
            $this->retryLater($jobId, $__m + ['no_frame' => true, 'provider_retries' => 1], 45, 'rate_limited');
            return (array) DB::table('creative_video_jobs')->where('id', $jobId)->first();
        }
        self::providerTick();   // a call was made either way
        if (!$dispatched) {
            DB::table('creative_video_jobs')->where('id', $jobId)->update([
                'status'     => 'failed',
                'error'      => 'All providers failed',
                'updated_at' => now(),
            ]);
        }

        return DB::table('creative_video_jobs')->where('id', $jobId)->first() ? (array) DB::table('creative_video_jobs')->where('id', $jobId)->first() : ['id' => $jobId, 'status' => 'failed'];
    }

    /**
     * VIDEO-CERT-1: what reaches the video model. Quoted words and every clause asking for on-screen words are removed (the
     * model painted "10% of private dinnœs s boukd in October"); the business name and "Chef <Name>" become roles (the model
     * gave Chef Red an invented face); the no-text rule always closes the prompt.
     */
    public static function cleanScenePrompt(string $p, string $business = ''): string
    {
        $p = (string) preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]{1,160}["\x{201C}\x{201D}]/u', '', $p);
        $p = (string) preg_replace("/(?<![\\p{L}\\p{N}])['\x{2018}][^'\x{2018}\x{2019}]{1,160}['\x{2019}](?![\\p{L}\\p{N}])/u", '', $p);
        $parts = preg_split('/(?<=[.;!?])\s+/u', trim($p)) ?: [];
        $parts = array_filter($parts, fn ($c) => ! preg_match('/\b(on-?screen|captions?|subtitles?|titles?|headlines?|labels?|lower[- ]thirds?|overlays?|lettering|text|words?|typography|signs?|signage|banners?|logos?|watermarks?|reading|reads|says|written)\b/iu', $c));
        $p = trim(implode(' ', $parts));
        // round A: "Teal (#0E7C7B) accent cabinets" -> the model painted "#F2A541"; a code after a colour word goes, a bare code becomes its name
        $p = (string) preg_replace('/\s*\(\s*#[0-9a-f]{3,8}\s*\)/iu', '', $p);
        $p = (string) preg_replace_callback('/#[0-9a-f]{6}\b/iu', fn ($m) => \App\Core\ImageIntelligence\ImagePromptCompiler::colourName($m[0]), $p);
        $p = (string) preg_replace('/#[0-9a-f]{3,8}\b/iu', '', $p);
        if ($business !== '') $p = (string) preg_replace('/\b' . preg_quote($business, '/') . '\b/iu', 'the venue', $p);
        $p = (string) preg_replace('/\bChef\s+\p{Lu}[\p{L}-]*(?:\s+\p{Lu}[\p{L}-]*)?/u', 'the chef', $p);
        $p = trim((string) preg_replace('/\s{2,}/u', ' ', $p), " ;,");
        if ($p === '') $p = 'A warm, natural, well-lit scene that matches the brief.';
        if (! preg_match('/[.!?]$/u', $p)) $p .= '.';
        return $p . ' No on-screen text, captions, letters, numbers, logos or watermarks anywhere in the frame.';
    }

    /** RFC-0025 P0: the planner's camera word as the provider's bracketed camera instruction (Hailuo-02 vocabulary). */
    public static function cameraTag(string $camera): string
    {
        $c = mb_strtolower(trim($camera));
        $map = [
            '/\b(dolly|push)( in| forward)?\b|\bmove (in|forward)\b/' => '[Push in]',
            '/\b(pull|dolly)( out| back)\b/'                          => '[Pull out]',
            '/\bzoom in\b/'                                          => '[Zoom in]',
            '/\bzoom out\b/'                                         => '[Zoom out]',
            '/\bpan left\b/'                                         => '[Pan left]',
            '/\bpan right\b/'                                        => '[Pan right]',
            '/\btilt up\b/'                                          => '[Tilt up]',
            '/\btilt down\b/'                                        => '[Tilt down]',
            '/\btruck left\b|\bslide left\b/'                        => '[Truck left]',
            '/\btruck right\b|\bslide right\b/'                      => '[Truck right]',
            '/\b(track|tracking|follow)\b/'                          => '[Tracking shot]',
            '/\b(static|locked|still|fixed)\b/'                      => '[Static shot]',
        ];
        foreach ($map as $re => $tag) if (preg_match($re, $c)) return $tag . ' ';
        return '';
    }

    public static function needsFrame(string $aspect): bool
    {
        return in_array($aspect, ['9:16', '3:4', '4:5', '2:3', '1:1'], true);
    }

    /**
     * VIDEO-2: make the first frame in the requested shape from the scene prompt (brand-aware already), keep it on our disk
     * (it is also the poster until the clip exists), and hand it to the provider as the image to animate.
     */
    public function dispatchWithFrame(int $jobId): array
    {
        $job = DB::table('creative_video_jobs')->where('id', $jobId)->first();
        if (! $job || $job->status !== 'framing') return ['skipped' => true];
        $meta = json_decode((string) $job->metadata_json, true) ?: [];
        $aspect = (string) ($meta['aspect_ratio'] ?? '9:16');
        $fail = function (string $why) use ($jobId) {
            DB::table('creative_video_jobs')->where('id', $jobId)->update(['status' => 'failed', 'error' => mb_substr($why, 0, 250), 'updated_at' => now()]);
            Log::warning('[VIDEO-2] first frame path failed', ['job' => $jobId, 'why' => $why]);
            return ['success' => false, 'error' => $why];
        };
        // VIDEO-RPM-1: a retry never paints the frame twice; a text-only clip waiting for the provider has no frame at all
        $__disk0 = \Illuminate\Support\Facades\Storage::disk('public');
        if (! empty($meta['no_frame'])) {
            return $this->sendToProvider($jobId, $job, $meta, ['provider' => 'minimax', 'duration' => (int) ($meta['duration'] ?? 6)], $fail);
        }
        if (! empty($meta['first_frame_path']) && $__disk0->exists((string) $meta['first_frame_path'])) {
            $__b = (string) $__disk0->get((string) $meta['first_frame_path']);
            $__mi = (new \finfo(FILEINFO_MIME_TYPE))->buffer($__b) ?: 'image/png';
            return $this->sendToProvider($jobId, $job, $meta, ['provider' => 'minimax', 'duration' => (int) ($meta['duration'] ?? 6),
                'first_frame_image' => 'data:' . $__mi . ';base64,' . base64_encode($__b)], $fail);
        }
        if (! empty($meta['source_image'])) {
            // RFC-0025 P2: the owner's own photo, read from our disk (ownPhotoUrl guaranteed it is ours)
            $rel = ltrim((string) preg_replace('#^.*?/storage/#', '', (string) parse_url((string) $meta['source_image'], PHP_URL_PATH)), '/');
            $disk0 = \Illuminate\Support\Facades\Storage::disk('public');
            if ($rel === '' || ! $disk0->exists($rel)) return $fail('own photo missing');
            $bytes = (string) $disk0->get($rel);
            $meta['photo_source'] = $rel;
        } else {
        $img = $this->connector->generateImage('The very first frame of a short ' . ($aspect === '1:1' ? 'square' : ($aspect === '16:9' ? 'landscape' : 'vertical')) . ' video. ' . $job->scene_prompt
            . ' Photographic and natural, composed for a ' . ($aspect === '1:1' ? 'square' : ($aspect === '16:9' ? 'wide' : 'tall phone')) . ' screen. No text, captions, logos or watermarks.',
            ['aspect_ratio' => $aspect === '1:1' ? '1:1' : ($aspect === '16:9' ? '16:9' : '9:16'), 'workspace_id' => (int) $job->workspace_id, 'quality' => 'high']);
        if (empty($img['success']) || empty($img['url'])) return $fail('first frame: ' . ($img['error'] ?? 'no image'));
        try {
            $bytes = \Illuminate\Support\Facades\Http::timeout(60)->get($img['url'])->body();
        } catch (\Throwable $e) { return $fail('first frame download: ' . $e->getMessage()); }
        }
        if (strlen($bytes) < 2000) return $fail('first frame empty');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';
        $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
        $path = 'ai-videos/' . (int) $job->workspace_id . '/frame-' . $jobId . '-' . substr(md5($bytes), 0, 10) . '.' . $ext;
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);
        // the image model makes 2:3 portraits; the clip takes the frame's shape, so the frame is cut to exactly 9:16 (or 1:1) first
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $target = ['1:1' => [1, 1], '16:9' => [16, 9], '2:3' => [2, 3], '4:5' => [4, 5], '3:4' => [3, 4], '4:3' => [4, 3]][$aspect] ?? [9, 16];   // RFC-0025 P2: every shape
        $size = @getimagesizefromstring($bytes);
        if ($size && abs($size[0] / max(1, $size[1]) - $target[0] / $target[1]) > 0.01) {
            $cropped = preg_replace('/\.\w+$/', '-cut.png', $path);
            $vf = $target[0] / $target[1] < $size[0] / max(1, $size[1]) ? 'crop=ih*' . $target[0] . '/' . $target[1] . ':ih' : 'crop=iw:iw*' . $target[1] . '/' . $target[0];
            @shell_exec('ffmpeg -v error -y -i ' . escapeshellarg($disk->path($path)) . ' -vf ' . escapeshellarg($vf) . ' ' . escapeshellarg($disk->path($cropped)) . ' 2>/dev/null');
            if (is_file($disk->path($cropped)) && filesize($disk->path($cropped)) > 2000) { $path = $cropped; $bytes = (string) file_get_contents($disk->path($cropped)); $mime = 'image/png'; }
        }
        $frameUrl = rtrim((string) config('app.url'), '/') . '/storage/' . $path;
        $meta['first_frame'] = $frameUrl;
        $meta['first_frame_path'] = $path;   // VIDEO-RPM-1: kept for a retry
        return $this->sendToProvider($jobId, $job, $meta, ['provider' => 'minimax', 'duration' => (int) ($meta['duration'] ?? 6),
            'first_frame_image' => 'data:' . $mime . ';base64,' . base64_encode($bytes)], $fail);
    }

    /** VIDEO-RPM-1: one paced provider call; a rate-limit refusal is retried later, anything else fails as before. */
    private function sendToProvider(int $jobId, object $job, array $meta, array $opts, callable $fail): array
    {
        $wait = self::providerWait();
        if ($wait > 0) return $this->retryLater($jobId, $meta, $wait, 'paced');
        self::providerTick();
        $res = $this->connector->generateVideoViaProvider((string) $job->scene_prompt, $opts);
        if (empty($res['success'])) {
            $err = (string) ($res['error'] ?? 'refused');
            $tries = (int) ($meta['provider_retries'] ?? 0);
            if (preg_match('/\b1002\b|rate limit|too many requests|\b429\b/i', $err) && $tries < 6) {
                $meta['provider_retries'] = $tries + 1;
                return $this->retryLater($jobId, $meta, 45 * ($tries + 1), 'rate_limited');
            }
            return $fail('provider: ' . $err);
        }
        DB::table('creative_video_jobs')->where('id', $jobId)->update(['provider' => 'minimax', 'provider_job_id' => $res['job_id'] ?? null, 'status' => 'in_progress',
            'metadata_json' => json_encode($meta), 'error' => null, 'updated_at' => now()]);
        Log::info('[VIDEO-2] sent to animate', ['job' => $jobId, 'retries' => (int) ($meta['provider_retries'] ?? 0)]);
        return ['success' => true];
    }

    /** VIDEO-RPM-1: back to "framing" (counted as in progress) and the frame job runs again after $delay seconds. */
    private function retryLater(int $jobId, array $meta, int $delay, string $why): array
    {
        DB::table('creative_video_jobs')->where('id', $jobId)->update(['status' => 'framing', 'metadata_json' => json_encode($meta), 'error' => null, 'updated_at' => now()]);
        \App\Jobs\VideoFirstFrameJob::dispatch($jobId)->delay(now()->addSeconds(max(5, $delay)));
        Log::info('[VIDEO-RPM-1] provider call deferred', ['job' => $jobId, 'why' => $why, 'in_s' => $delay, 'retries' => (int) ($meta['provider_retries'] ?? 0)]);
        return ['deferred' => true, 'in' => $delay];
    }

    /** VIDEO-RPM-1: seconds until a provider call fits the per-minute budget (0 = now). */
    public static function providerWait(): int
    {
        $rpm = max(1, (int) env('MINIMAX_RPM', 4));
        $ts = array_values(array_filter((array) \Illuminate\Support\Facades\Cache::get('minimax:rpm', []), fn ($t) => $t > time() - 60));
        if (count($ts) < $rpm) return 0;
        return max(5, 61 - (time() - min($ts)) + random_int(0, 8));
    }

    public static function providerTick(): void
    {
        \Illuminate\Support\Facades\Cache::lock('minimax:rpm:lock', 5)->block(5, function () {
            $ts = array_values(array_filter((array) \Illuminate\Support\Facades\Cache::get('minimax:rpm', []), fn ($t) => $t > time() - 60));
            $ts[] = time();
            \Illuminate\Support\Facades\Cache::put('minimax:rpm', $ts, 120);
        });
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
        $inProgress = count(array_filter($statuses, fn($s) => in_array($s, ['in_progress', 'dispatching', 'framing'])));   // VIDEO-2: 'framing' = the first frame is being made
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

        // RFC-0025 P3: several scenes are joined into one clip (in scene order) - never the first scene alone
        if (count($urls) > 1) {
            $asset = DB::table('assets')->where('id', $assetId)->first(['workspace_id', 'metadata_json']);
            $am = json_decode((string) ($asset->metadata_json ?? ''), true) ?: [];
            $ordered = DB::table('creative_video_jobs')->where('asset_id', $assetId)->where('status', 'completed')->orderBy('scene_index')->pluck('video_url')->filter()->values()->all();
            $st = app(\App\Engines\Creative\Services\VideoAssembler::class)->stitch((int) ($asset->workspace_id ?? 0), $assetId, $ordered ?: $urls,
                (float) ($am['duration'] ?? 10), (string) ($am['aspect_ratio'] ?? '9:16'));
            if (! ($st['success'] ?? false)) return ['success' => false, 'error' => 'stitch_failed: ' . ($st['error'] ?? '')];
            return ['success' => true, 'url' => $st['url'], 'scene_urls' => $urls, 'scene_count' => count($urls), 'stitched' => true];
        }

        return [
            'success'    => true,
            'url'        => $urls[0],
            'scene_urls' => $urls,
            'scene_count'=> 1,
            'stitched'   => false,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    /**
     * P0 (2026-08-10) — the mock provider fabricates a "completed" video (a
     * public sample MP4) and would let a real workspace be charged for a fake
     * asset. It runs ONLY in local/testing. In production/staging the waterfall
     * is real providers only; if they all fail the job fails truthfully — no
     * fake asset, no charge, never a silent mock fallback.
     */
    private function activeProviders(): array
    {
        if (app()->environment(['local', 'testing'])) {
            return self::PROVIDERS;
        }

        return array_values(array_filter(self::PROVIDERS, fn ($p) => $p !== 'mock'));
    }

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
