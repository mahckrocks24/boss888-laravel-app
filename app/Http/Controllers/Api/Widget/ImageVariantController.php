<?php

namespace App\Http\Controllers\Api\Widget;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * KABAYAN888 UX-1 (2026-09-03) — on-demand image variants for published sites.
 *
 * GET /api/public/img/{w}/{path}
 *   w    ∈ WIDTHS (px). Anything else → nearest allowed width.
 *   path = a file under storage/app/public within the ROOTS allow-list, e.g.
 *          ai-images/999999/abc.png · uploads/… · media/… · builder-heroes/…
 *
 * Output is WebP (quality 80), resized to fit the width (never upscaled), written
 * once to storage/app/public/img-cache/{w}/{sha1(path)}.webp and served with an
 * immutable cache header so Cloudflare and browsers keep it. Source images stay
 * untouched (Creative888 PNGs are ~2 MB; a 640-px WebP is ~40 KB).
 *
 * Safety: path is normalised, must not contain "..", must resolve INSIDE the
 * public storage root and one of the allowed ROOTS; only real image bytes are
 * decoded (getimagesize), SVG is refused. Pure read + cache write; no DB.
 */
class ImageVariantController
{
    public const WIDTHS = [320, 480, 640, 800, 1080, 1200, 1600];
    private const ROOTS  = ['ai-images', 'uploads', 'media', 'builder-heroes', 'sites', 'logos', 'creative'];
    private const MAX_SOURCE_BYTES = 25 * 1024 * 1024;

    public function show(Request $request, int $w, string $path)
    {
        $w = self::nearestWidth($w);
        $rel = str_replace('\\', '/', trim($path));
        $rel = preg_replace('#/+#', '/', $rel);
        if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9_\-./]+$#', $rel)) {
            return response('Not found', 404);
        }
        $root = explode('/', $rel)[0] ?? '';
        if (!in_array($root, self::ROOTS, true)) return response('Not found', 404);

        $base = realpath(storage_path('app/public'));
        $src  = realpath(storage_path('app/public/' . $rel));
        if ($base === false || $src === false || !str_starts_with($src, $base . DIRECTORY_SEPARATOR) || !is_file($src)) {
            return response('Not found', 404);
        }
        if (filesize($src) > self::MAX_SOURCE_BYTES) return response('Too large', 413);

        $cacheDir  = storage_path('app/public/img-cache/' . $w);
        $cacheFile = $cacheDir . '/' . sha1($rel . '|' . filemtime($src)) . '.webp';
        if (!is_file($cacheFile)) {
            $info = @getimagesize($src);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                return response('Unsupported', 415);
            }
            [$sw, $sh] = [$info[0], $info[1]];
            $img = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
                IMAGETYPE_PNG  => @imagecreatefrompng($src),
                IMAGETYPE_GIF  => @imagecreatefromgif($src),
                IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            };
            if (!$img) return response('Unreadable', 415);
            $tw = min($w, $sw);
            $th = (int) round($sh * ($tw / $sw));
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $sw, $sh);
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            $tmp = $cacheFile . '.' . getmypid() . '.tmp';
            imagewebp($dst, $tmp, 80);
            imagedestroy($dst); imagedestroy($img);
            @rename($tmp, $cacheFile);
            if (!is_file($cacheFile)) return response('Encode failed', 500);
        }
        $resp = new BinaryFileResponse($cacheFile, 200, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Img-Variant' => (string) $w,
        ], true, null, false, false);
        return $resp;
    }

    public static function nearestWidth(int $w): int
    {
        $best = self::WIDTHS[0];
        foreach (self::WIDTHS as $cand) { if (abs($cand - $w) < abs($best - $w)) $best = $cand; }
        return $best;
    }

    /**
     * Rewrite a platform-hosted image URL into a variant URL. Foreign hosts and
     * unknown roots are returned unchanged. Used by themes to build srcset.
     */
    public static function variantUrl(string $url, int $w): string
    {
        $u = trim($url);
        if ($u === '') return $u;
        $p = parse_url($u);
        $path = (string) ($p['path'] ?? '');
        if (!preg_match('#^/storage/((?:' . implode('|', self::ROOTS) . ')/[A-Za-z0-9_\-./]+)$#', $path, $m)) return $u;
        if (str_contains($m[1], '..')) return $u;
        $host = isset($p['host']) ? (($p['scheme'] ?? 'https') . '://' . $p['host']) : '';
        return $host . '/api/public/img/' . self::nearestWidth($w) . '/' . $m[1];
    }

    /** srcset string for the given widths (only for platform-hosted images). */
    public static function srcset(string $url, array $widths = [480, 800, 1200]): string
    {
        $out = [];
        foreach ($widths as $w) {
            $v = self::variantUrl($url, (int) $w);
            if ($v === $url) return '';
            $out[] = $v . ' ' . (int) $w . 'w';
        }
        return implode(', ', $out);
    }
}
