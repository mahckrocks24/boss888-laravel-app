<?php

namespace App\Jobs\SeoOptimization;

use App\Services\SeoOptimization\OptimizationVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OptimizeWpAttachmentJob — ARCHITECTURE-CORRECT (Phase A v2, 2026-05-16).
 *
 * Laravel = brain. Connector = dumb transport. NO LUGS dependency.
 *
 * Flow:
 *   1. GET  /lgsc/v1/fetch-attachment   — pull original bytes from WP
 *   2. GD/Imagick compression IN LARAVEL — Laravel decides quality, format
 *   3. WebP generation IN LARAVEL via imagewebp()
 *   4. POST /lgsc/v1/replace-attachment — push optimized bytes back to WP
 *   5. Independent HEAD verification — never trust connector claim alone
 *   6. Telemetry written to seo_images (Laravel-owned)
 *
 * Status transitions:
 *   queued → running → optimized   (verified)
 *                    → unverified  (compressed but HEAD verification failed)
 *                    → blocked_no_connector (connector unreachable/missing endpoints)
 *                    → failed      (other terminal errors)
 */
class OptimizeWpAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 180;

    // JPEG/PNG/WebP quality settings — Laravel-owned product decisions
    private const JPEG_QUALITY = 82;
    private const PNG_QUALITY  = 8;   // 0 (best) - 9 (worst)
    private const WEBP_QUALITY = 80;
    private const MAX_WIDTH    = 1920;

    public function __construct(
        public int $wsId,
        public int $userId,
        public int $seoImageId,
        public string $imageUrl,
        public string $jobId
    ) {}

    public function handle(OptimizationVerificationService $verifier): void
    {
        $this->setStatus('running');

        // ── 1. Load connector creds ─────────────────────────────────────
        $siteUrl = (string) DB::table('seo_settings')
            ->where('workspace_id', $this->wsId)->where('key', 'site_url')->value('value');
        $secret = (string) DB::table('seo_settings')
            ->where('workspace_id', $this->wsId)->where('key', 'webhook_secret')->value('value');

        if ($siteUrl === '' || $secret === '') {
            $this->setStatus('failed', ['error' => 'no_connector_config']);
            return;
        }
        $base = rtrim($siteUrl, '/');

        // ── 2. FETCH original bytes from WP via connector ───────────────
        try {
            $fetchResp = Http::timeout(45)
                ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (optimize-fetch)'])
                ->get($base . '/wp-json/lgsc/v1/fetch-attachment', [
                    'secret'    => $secret,
                    'image_url' => $this->imageUrl,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO][optimize][job] fetch transport failure', [
                'ws_id' => $this->wsId, 'url' => $this->imageUrl, 'err' => $e->getMessage(),
            ]);
            throw $e; // queue retries
        }

        if (! $fetchResp->successful()) {
            $body = $fetchResp->json() ?: [];
            $code = (string) ($body['code'] ?? 'unknown');

            if ($fetchResp->status() === 404 && $code === 'rest_no_route') {
                $this->setStatus('blocked_no_connector', [
                    'error'   => 'connector_fetch_endpoint_missing',
                    'message' => 'Connector v1.3.0 required for optimization transport.',
                ]);
                $this->fail(new \RuntimeException('connector_fetch_endpoint_missing'));
                return;
            }
            if (in_array($code, ['lgsc_attachment_not_found', 'lgsc_file_missing', 'lgsc_unsupported_mime', 'lgsc_file_too_large'], true)) {
                $this->setStatus('failed', [
                    'error' => $code, 'message' => $body['message'] ?? '',
                ]);
                $this->fail(new \RuntimeException($code));
                return;
            }
            // Transient — let queue retry
            if ($this->attempts() >= $this->tries) {
                $this->setStatus('failed', [
                    'error' => $code, 'http' => $fetchResp->status(),
                ]);
            }
            throw new \RuntimeException('connector_fetch_failed:' . $fetchResp->status());
        }

        $fetched = $fetchResp->json() ?: [];
        if (! ($fetched['success'] ?? false)) {
            $this->setStatus('failed', ['error' => 'fetch_returned_unsuccess']);
            return;
        }

        $attachmentId = (int)    ($fetched['attachment_id'] ?? 0);
        $mime         = (string) ($fetched['mime_type'] ?? '');
        $originalSize = (int)    ($fetched['original_size'] ?? 0);
        $fileB64      = (string) ($fetched['file_b64'] ?? '');
        $filename     = (string) ($fetched['filename'] ?? '');

        $originalBytes = base64_decode($fileB64, true);
        if ($originalBytes === false || strlen($originalBytes) === 0) {
            $this->setStatus('failed', ['error' => 'fetch_payload_corrupt_b64']);
            return;
        }
        if (strlen($originalBytes) !== $originalSize) {
            Log::warning('[SEO][optimize][job] fetched size mismatch (proceeding with actual)', [
                'claimed' => $originalSize, 'actual' => strlen($originalBytes),
            ]);
            $originalSize = strlen($originalBytes);
        }

        // ── 3. COMPRESS IN LARAVEL (GD-based) ───────────────────────────
        $compressed = $this->compressBytes($originalBytes, $mime);
        if (! $compressed['success']) {
            $this->setStatus('failed', [
                'error'   => 'compression_failed',
                'message' => $compressed['error'] ?? 'GD pipeline error',
            ]);
            return;
        }

        $optimizedBytes = $compressed['bytes'];
        $optimizedSize  = strlen($optimizedBytes);

        // Refuse to write if compression made it worse
        if ($optimizedSize >= $originalSize) {
            $this->setStatus('failed', [
                'error'     => 'no_savings',
                'message'   => 'Compressed file is not smaller than original.',
                'original'  => $originalSize,
                'optimized' => $optimizedSize,
            ]);
            return;
        }

        // ── 4. Generate WebP IN LARAVEL ─────────────────────────────────
        $webp = $this->generateWebp($originalBytes, $mime);
        $webpBytes = $webp['success'] ? $webp['bytes'] : null;

        // ── 5. PUSH optimized bytes back via connector ──────────────────
        $payload = [
            'secret'         => $secret,
            'attachment_id'  => $attachmentId,
            'optimized_b64'  => base64_encode($optimizedBytes),
            'original_size'  => $originalSize,
            'optimizer_meta' => [
                'engine'        => $compressed['engine'] ?? 'gd',
                'jpeg_quality'  => self::JPEG_QUALITY,
                'png_quality'   => self::PNG_QUALITY,
                'webp_quality'  => self::WEBP_QUALITY,
                'max_width'     => self::MAX_WIDTH,
                'optimizer'     => 'laravel',
                'job_id'        => $this->jobId,
                'ts'            => now()->toIso8601String(),
            ],
        ];
        if ($webpBytes !== null) {
            $payload['webp_b64'] = base64_encode($webpBytes);
        }

        try {
            $putResp = Http::timeout(60)
                ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (optimize-push)'])
                ->post($base . '/wp-json/lgsc/v1/replace-attachment', $payload);
        } catch (\Throwable $e) {
            Log::warning('[SEO][optimize][job] replace transport failure', [
                'ws_id' => $this->wsId, 'url' => $this->imageUrl, 'err' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (! $putResp->successful()) {
            $body = $putResp->json() ?: [];
            $code = (string) ($body['code'] ?? 'unknown');
            if ($putResp->status() === 404 && $code === 'rest_no_route') {
                $this->setStatus('blocked_no_connector', [
                    'error'   => 'connector_replace_endpoint_missing',
                    'message' => 'Connector v1.3.0 required.',
                ]);
                $this->fail(new \RuntimeException('connector_replace_endpoint_missing'));
                return;
            }
            $this->setStatus('failed', [
                'error' => $code, 'http' => $putResp->status(),
                'message' => $body['message'] ?? '',
            ]);
            return;
        }

        $putBody = $putResp->json() ?: [];
        if (! ($putBody['success'] ?? false)) {
            $this->setStatus('failed', ['error' => 'replace_unsuccess']);
            return;
        }

        $writtenSize = (int)    ($putBody['written_size'] ?? 0);
        $webpUrl     = (string) ($putBody['webp_url'] ?? '');
        $webpUrl     = $webpUrl !== '' ? $webpUrl : null;
        $writtenAt   = (string) ($putBody['optimized_at'] ?? '');
        unset($writtenAt); // future use

        // ── 6. INDEPENDENT verification (HEAD probe the live URL) ───────
        $verification = $verifier->verifyC2(
            $this->imageUrl,
            $writtenSize,
            $webpUrl,
            null
        );

        if ($verification['verdict'] === 'verified') {
            $verifiedSize = $verification['actual_size_bytes'];
            $savedBytes   = max(0, $originalSize - $verifiedSize);

            DB::table('seo_images')->where('id', $this->seoImageId)->update([
                'wp_attachment_id'      => $attachmentId ?: null,
                'optimization_status'   => 'optimized',
                'optimization_provider' => 'laravel',     // Laravel did the compression
                'last_optimized_at'     => now(),
                'last_verified_at'      => now(),
                'verified_size_bytes'   => $verifiedSize,
                'saved_bytes'           => $savedBytes,
                'size_bytes'            => $verifiedSize,
                'webp_url'              => $webpUrl,
                'webp_verified'         => $verification['webp_verified'] ? 1 : 0,
                'updated_at'            => now(),
            ]);

            Log::info('[SEO][optimize][job] VERIFIED', [
                'ws_id'         => $this->wsId,
                'url'           => $this->imageUrl,
                'attachment_id' => $attachmentId,
                'original'      => $originalSize,
                'compressed'    => $optimizedSize,
                'written'       => $writtenSize,
                'verified'      => $verifiedSize,
                'saved'         => $savedBytes,
                'engine'        => $compressed['engine'] ?? 'gd',
                'webp_verified' => $verification['webp_verified'],
            ]);
            return;
        }

        // Verification failed — honest unverified state, DO NOT mark optimized
        DB::table('seo_images')->where('id', $this->seoImageId)->update([
            'wp_attachment_id'      => $attachmentId ?: null,
            'optimization_status'   => 'unverified',
            'optimization_provider' => 'laravel',
            'last_optimized_at'     => now(),
            'webp_url'              => $webpUrl,
            'updated_at'            => now(),
        ]);
        Log::warning('[SEO][optimize][job] verification failed', [
            'ws_id'  => $this->wsId,
            'url'    => $this->imageUrl,
            'issues' => $verification['issues'],
            'checks' => $verification['checks'],
        ]);
    }

    /**
     * Compress image bytes using GD. Returns:
     *   {success, bytes, engine, error?}
     *
     * Strategy per mime:
     *   JPEG: decode → resize-if-wider-than-MAX_WIDTH → re-encode at JPEG_QUALITY
     *   PNG:  decode → resize-if-needed → re-encode at PNG_QUALITY (palettize possible later)
     *   WebP: decode → resize → re-encode at WEBP_QUALITY
     *   GIF:  pass through (animated GIFs need ImageMagick — skip for GD-only)
     */
    private function compressBytes(string $bytes, string $mime): array
    {
        if ($mime === 'image/gif') {
            return ['success' => false, 'error' => 'gif_not_supported_on_gd_only'];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return ['success' => false, 'error' => 'imagecreatefromstring_failed'];
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Resize if wider than MAX_WIDTH
        if ($w > self::MAX_WIDTH) {
            $newW = self::MAX_WIDTH;
            $newH = (int) round($h * ($newW / $w));
            $resized = imagecreatetruecolor($newW, $newH);
            // Preserve alpha for PNG/WebP
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
            }
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $resized;
        }

        // Encode to temp buffer
        ob_start();
        $ok = false;
        switch ($mime) {
            case 'image/jpeg':
                $ok = imagejpeg($src, null, self::JPEG_QUALITY);
                break;
            case 'image/png':
                imagealphablending($src, false);
                imagesavealpha($src, true);
                $ok = imagepng($src, null, self::PNG_QUALITY);
                break;
            case 'image/webp':
                imagealphablending($src, false);
                imagesavealpha($src, true);
                $ok = function_exists('imagewebp') ? imagewebp($src, null, self::WEBP_QUALITY) : false;
                break;
        }
        $outBytes = ob_get_clean();
        imagedestroy($src);

        if (! $ok || $outBytes === false || strlen($outBytes) === 0) {
            return ['success' => false, 'error' => 'encode_failed_for_' . $mime];
        }

        return [
            'success' => true,
            'bytes'   => $outBytes,
            'engine'  => 'gd',
        ];
    }

    /**
     * Generate WebP variant from source bytes.
     */
    private function generateWebp(string $sourceBytes, string $sourceMime): array
    {
        if (! function_exists('imagewebp')) {
            return ['success' => false, 'error' => 'imagewebp_not_available'];
        }
        if ($sourceMime === 'image/gif') {
            return ['success' => false, 'error' => 'gif_to_webp_not_supported'];
        }
        $src = @imagecreatefromstring($sourceBytes);
        if ($src === false) {
            return ['success' => false, 'error' => 'webp_decode_failed'];
        }
        $w = imagesx($src);
        if ($w > self::MAX_WIDTH) {
            $h = imagesy($src);
            $newW = self::MAX_WIDTH;
            $newH = (int) round($h * ($newW / $w));
            $resized = imagecreatetruecolor($newW, $newH);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $t = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $t);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $resized;
        }
        imagealphablending($src, false);
        imagesavealpha($src, true);
        ob_start();
        $ok = imagewebp($src, null, self::WEBP_QUALITY);
        $bytes = ob_get_clean();
        imagedestroy($src);
        if (! $ok || $bytes === false || strlen($bytes) === 0) {
            return ['success' => false, 'error' => 'webp_encode_failed'];
        }
        return ['success' => true, 'bytes' => $bytes];
    }

    public function failed(\Throwable $exception): void
    {
        $current = (string) DB::table('seo_images')->where('id', $this->seoImageId)->value('optimization_status');
        if (! in_array($current, ['blocked_no_connector', 'optimized', 'unverified'], true)) {
            DB::table('seo_images')->where('id', $this->seoImageId)->update([
                'optimization_status' => 'failed',
                'updated_at'          => now(),
            ]);
        }
        Log::error('[SEO][optimize][job] permanently failed', [
            'ws_id' => $this->wsId, 'url' => $this->imageUrl, 'error' => $exception->getMessage(),
        ]);
    }

    private function setStatus(string $status, array $context = []): void
    {
        $update = [
            'optimization_status' => $status,
            'updated_at'          => now(),
        ];
        // Persist failure reason so FE copy map can render the right message
        // (otherwise everything falls through to generic "Couldn't optimize").
        if (in_array($status, ['failed', 'blocked_no_connector'], true)) {
            $err = (string) ($context['error'] ?? '');
            if ($err !== '') {
                $update['optimization_last_error'] = mb_substr($err, 0, 60);
            }
        } elseif (in_array($status, ['running', 'optimized'], true)) {
            // Clear stale error on success or fresh run
            $update['optimization_last_error'] = null;
        }
        DB::table('seo_images')->where('id', $this->seoImageId)->update($update);
        Log::info('[SEO][optimize][job] status set', array_merge([
            'ws_id'  => $this->wsId, 'url' => $this->imageUrl,
            'status' => $status, 'attempt' => $this->attempts(),
        ], $context));
    }
}
