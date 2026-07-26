<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\Log;

/**
 * STUDIO888 Phase O — server-side masked image editing (inpainting).
 *
 * Calls OpenAI /v1/images/edits (gpt-image-1) DIRECTLY from Laravel using the
 * key in config('llm.openai.api_key'), bypassing the Railway runtime (which
 * exposes only generation). Pure image I/O + provider call — NO asset
 * persistence, NO billing (the caller / EngineExecutionService owns those).
 *
 * Mask convention (OpenAI): fully TRANSPARENT pixels (alpha 0) mark the region
 * to edit; opaque pixels are preserved. GD alpha is inverted (0=opaque,
 * 127=transparent), handled in buildMask().
 *
 * gpt-image-1 accepts square/portrait/landscape sizes only; we normalise the
 * working image to the nearest supported size and build the mask at the SAME
 * dimensions so selection coordinates stay aligned.
 */
class ImageEditService
{
    /** gpt-image-1 supported edit sizes. */
    private const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    /**
     * @param string      $sourceBytes  raw source image bytes (any GD-readable format)
     * @param array       $selection    ['type'=>'mask'|'rectangle'|'full', 'mask_base64'?, 'region'?=>['x','y','w','h'] normalized 0..1]
     * @param string      $prompt       edit instruction
     * @return array{success:bool, bytes?:string, width?:int, height?:int, size?:string, mode?:string, usage?:array, error?:string}
     */
    public function inpaint(string $sourceBytes, array $selection, string $prompt): array
    {
        $key  = config('llm.openai.api_key');
        $base = rtrim((string) config('llm.openai.base_url', 'https://api.openai.com'), '/');
        if (! $key) {
            return ['success' => false, 'error' => 'image_edit_unavailable: no OpenAI key configured'];
        }
        if (trim($prompt) === '') {
            return ['success' => false, 'error' => 'prompt_required'];
        }

        $src = @imagecreatefromstring($sourceBytes);
        if ($src === false) {
            return ['success' => false, 'error' => 'source_decode_failed'];
        }
        $sw = imagesx($src); $sh = imagesy($src);

        // Choose the supported size whose aspect ratio best matches the source.
        [$tw, $th, $sizeStr] = $this->pickSize($sw, $sh);

        // Normalise source to working size (PNG).
        $work = imagecreatetruecolor($tw, $th);
        imagecopyresampled($work, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        $srcPng = $this->pngBytes($work);
        imagedestroy($src);
        imagedestroy($work);

        $mode = $selection['type'] ?? 'full';
        $tmpDir = sys_get_temp_dir();
        $srcPath = tempnam($tmpDir, 'st_src_') . '.png';
        file_put_contents($srcPath, $srcPng);

        $maskPath = null;
        if ($mode !== 'full') {
            $maskPng = $this->buildMask($mode, $selection, $tw, $th);
            if ($maskPng === null) {
                @unlink($srcPath);
                return ['success' => false, 'error' => 'mask_build_failed'];
            }
            $maskPath = tempnam($tmpDir, 'st_mask_') . '.png';
            file_put_contents($maskPath, $maskPng);
        }

        $fields = [
            'model'  => 'gpt-image-1',
            'prompt' => $prompt,
            'size'   => $sizeStr,
            'image'  => new \CURLFile($srcPath, 'image/png', 'image.png'),
        ];
        if ($maskPath !== null) {
            $fields['mask'] = new \CURLFile($maskPath, 'image/png', 'mask.png');
        }

        $ch = curl_init("$base/v1/images/edits");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $key"],
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_POSTFIELDS     => $fields,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        @unlink($srcPath);
        if ($maskPath) { @unlink($maskPath); }

        if ($resp === false) {
            return ['success' => false, 'error' => 'provider_connection_failed: ' . $cerr];
        }
        $j = json_decode($resp, true) ?: [];
        if ($code !== 200 || empty($j['data'][0]['b64_json'])) {
            $err = $j['error']['message'] ?? ('provider_http_' . $code);
            Log::warning('[ImageEdit] provider edit failed', ['code' => $code, 'error' => $err]);
            return ['success' => false, 'error' => 'provider_error: ' . $err];
        }
        $bytes = base64_decode($j['data'][0]['b64_json'], true);
        if ($bytes === false || strlen($bytes) === 0) {
            return ['success' => false, 'error' => 'provider_decode_failed'];
        }

        return [
            'success' => true,
            'bytes'   => $bytes,
            'width'   => $tw,
            'height'  => $th,
            'size'    => $sizeStr,
            'mode'    => $mode,
            'usage'   => $j['usage'] ?? [],
        ];
    }

    /** Nearest supported size by aspect ratio. @return array{0:int,1:int,2:string} */
    private function pickSize(int $sw, int $sh): array
    {
        $ar = $sh > 0 ? $sw / $sh : 1.0;
        $best = null; $bestDiff = INF;
        foreach (self::SIZES as $s) {
            [$w, $h] = array_map('intval', explode('x', $s));
            $diff = abs(($w / $h) - $ar);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = [$w, $h, $s]; }
        }
        return $best;
    }

    /**
     * Build an OpenAI-format mask (transparent = edit) at $w×$h.
     * Region coords are normalized (0..1) and map to any working size.
     */
    private function buildMask(string $mode, array $selection, int $w, int $h): ?string
    {
        if ($mode === 'mask' && ! empty($selection['mask_base64'])) {
            // Client brush mask: a PNG where transparent (alpha=0) marks edit.
            $raw = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $selection['mask_base64']), true);
            if ($raw === false) { return null; }
            $m = @imagecreatefromstring($raw);
            if ($m === false) { return null; }
            $out = imagecreatetruecolor($w, $h);
            imagesavealpha($out, true);
            imagealphablending($out, false);
            imagecopyresampled($out, $m, 0, 0, 0, 0, $w, $h, imagesx($m), imagesy($m));
            imagedestroy($m);
            $png = $this->pngBytes($out);
            imagedestroy($out);
            return $png;
        }

        if ($mode === 'rectangle' && ! empty($selection['region'])) {
            $r = $selection['region'];
            $x0 = (int) round(max(0.0, min(1.0, (float) ($r['x'] ?? 0))) * $w);
            $y0 = (int) round(max(0.0, min(1.0, (float) ($r['y'] ?? 0))) * $h);
            $x1 = (int) round(max(0.0, min(1.0, (float) ($r['x'] ?? 0) + (float) ($r['w'] ?? 0))) * $w);
            $y1 = (int) round(max(0.0, min(1.0, (float) ($r['y'] ?? 0) + (float) ($r['h'] ?? 0))) * $h);
            if ($x1 <= $x0 || $y1 <= $y0) { return null; }
            $out = imagecreatetruecolor($w, $h);
            imagesavealpha($out, true);
            imagealphablending($out, false);
            imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocatealpha($out, 0, 0, 0, 0));      // opaque preserve
            imagefilledrectangle($out, $x0, $y0, $x1, $y1, imagecolorallocatealpha($out, 0, 0, 0, 127)); // transparent edit
            $png = $this->pngBytes($out);
            imagedestroy($out);
            return $png;
        }

        return null;
    }

    private function pngBytes($im): string
    {
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }
}
