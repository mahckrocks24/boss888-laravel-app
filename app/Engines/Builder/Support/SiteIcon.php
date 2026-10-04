<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FAVICON-1 (Owner 2026-10-04: "websites do not have proper favicon. must have initial letter of the website name/company
 * name as default and add a section in website settings for users to upload").
 *
 * Every website gets real icon files - favicon.ico (32+48), a 32 px and a 192 px PNG and a 180 px Apple touch icon - drawn
 * from the first letter of the website's name on its brand colour, or made from the image the owner uploaded. The page's
 * icon tags are swapped at serve time (SiteIcon::apply), so template, renderer and blog pages all carry the same set, and
 * /favicon.ico on a site's own address answers with it. Before this the only icon was an inline SVG data URI, which Google
 * does not index (search results showed a globe) and iOS does not show; /favicon.ico was a 0-byte file.
 *
 * Sites with a designed icon set of their own (settings_json.favicon_url, FAV-1 - MR Systems, SG Travel) are left alone.
 */
final class SiteIcon
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const DIR = 'site-icons';

    /** The letter shown by default: the first letter or digit of the website's name. */
    public static function letterFor(string $name): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $name, $m)) return mb_strtoupper($m[0]);
        return 'W';
    }

    private static function settings(object $w): array
    {
        $s = $w->settings_json ?? '{}';
        return is_array($s) ? $s : (json_decode((string) $s, true) ?: []);
    }

    private static function colour(object $w): string
    {
        $tv = $w->template_variables ?? null;
        $tv = is_array($tv) ? $tv : (json_decode((string) $tv, true) ?: []);
        $s = self::settings($w);
        foreach ([$tv['primary_color'] ?? null, $s['primary_color'] ?? null, ($s['brand'] ?? [])['primary'] ?? null] as $c) {
            $c = trim((string) $c); if ($c !== '' && $c[0] !== '#') $c = '#' . $c;
            if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c)) return strtoupper(strlen($c) === 4 ? '#' . $c[1] . $c[1] . $c[2] . $c[2] . $c[3] . $c[3] : $c);
        }
        return '#1F2937';
    }

    /** A designed icon set from elsewhere (FAV-1) - not ours to replace. */
    public static function hasOwnSet(object $w): bool
    {
        return trim((string) (self::settings($w)['favicon_url'] ?? '')) !== '';
    }

    private static function uploaded(object $w): ?string
    {
        $p = trim((string) (self::settings($w)['site_icon_upload'] ?? ''));
        if ($p === '') return null;
        $abs = storage_path('app/public/' . ltrim($p, '/'));
        return is_file($abs) ? $abs : null;
    }

    /**
     * Make sure this website's icon files exist for its current letter, colour and upload; return their URLs.
     * @return array{ico:string,png32:string,png192:string,apple:string,letter:string,colour:string,uploaded:bool}|null
     */
    public static function ensure(object $w): ?array
    {
        try {
            $id = (int) ($w->id ?? 0); if ($id <= 0) return null;
            $up = self::uploaded($w);
            $letter = self::letterFor((string) ($w->name ?? ''));
            $colour = self::colour($w);
            $sig = substr(sha1($letter . '|' . $colour . '|' . ($up ? sha1_file($up) : '')), 0, 12);
            $rel = self::DIR . "/{$id}/{$sig}";
            $dir = storage_path('app/public/' . $rel);
            if (! is_file($dir . '/favicon.ico')) {
                if (! is_dir($dir)) @mkdir($dir, 0775, true);
                $master = $up ? self::fromUpload($up) : self::drawLetter($letter, $colour);
                if (! $master) return null;
                $sizes = [32 => 'icon-32.png', 192 => 'icon-192.png', 180 => 'apple-touch-icon.png', 48 => 'icon-48.png'];
                foreach ($sizes as $px => $file) { $im = self::resize($master, $px, $file === 'apple-touch-icon.png'); imagepng($im, $dir . '/' . $file, 9); imagedestroy($im); }
                self::writeIco($dir . '/favicon.ico', [$dir . '/icon-32.png', $dir . '/icon-48.png']);
                imagedestroy($master);
                foreach (glob(storage_path('app/public/' . self::DIR . "/{$id}/*"), GLOB_ONLYDIR) ?: [] as $old) {   // one set per site
                    if (basename($old) !== $sig && basename($old) !== 'upload') { array_map('unlink', glob($old . '/*') ?: []); @rmdir($old); }
                }
            }
            $u = '/storage/' . $rel . '/';
            return ['ico' => $u . 'favicon.ico', 'png32' => $u . 'icon-32.png', 'png192' => $u . 'icon-192.png', 'apple' => $u . 'apple-touch-icon.png', 'letter' => $letter, 'colour' => $colour, 'uploaded' => (bool) $up];
        } catch (\Throwable $e) {
            Log::warning('[FAVICON-1] icon not made', ['site' => $w->id ?? null, 'e' => $e->getMessage()]);
            return null;
        }
    }

    /** Swap the page's icon tags for this website's set. */
    public static function apply(string $html, ?object $w): string
    {
        if (! $w || stripos($html, '</head>') === false || self::hasOwnSet($w)) return $html;
        $u = self::ensure($w); if (! $u) return $html;
        $html = preg_replace('~<link\b[^>]*\brel=["\'](?:shortcut\s+icon|icon|apple-touch-icon(?:-precomposed)?)["\'][^>]*>\s*~i', '', $html);
        $tags = '<link rel="icon" href="' . $u['ico'] . '" sizes="48x48">'
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $u['png32'] . '">'
            . '<link rel="icon" type="image/png" sizes="192x192" href="' . $u['png192'] . '">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . $u['apple'] . '">';
        return preg_replace('~</head>~i', $tags . "\n</head>", $html, 1);
    }

    /** The bytes of this website's favicon.ico (for /favicon.ico on its own address). */
    public static function icoBytes(?object $w): ?string
    {
        if (! $w) return null;
        if (self::hasOwnSet($w)) return null;
        $u = self::ensure($w); if (! $u) return null;
        $f = storage_path('app/public/' . ltrim(substr($u['ico'], strlen('/storage/')), '/'));
        return is_file($f) ? file_get_contents($f) : null;
    }

    // ── drawing ──────────────────────────────────────────────────────────────────────────────────────────────────

    private static function rgb(string $hex): array { $h = ltrim($hex, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }

    private static function lum(array $c): float
    {
        $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    private static function drawLetter(string $letter, string $colour)
    {
        $S = 512; $im = imagecreatetruecolor($S, $S);
        imagesavealpha($im, true); imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        $bgc = self::rgb($colour); $bg = imagecolorallocate($im, ...$bgc);
        $r = 112;   // rounded square, like an app icon
        imagefilledrectangle($im, $r, 0, $S - $r - 1, $S - 1, $bg); imagefilledrectangle($im, 0, $r, $S - 1, $S - $r - 1, $bg);
        foreach ([[$r, $r], [$S - $r - 1, $r], [$r, $S - $r - 1], [$S - $r - 1, $S - $r - 1]] as [$cx, $cy]) imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
        $light = self::lum($bgc) > 0.42;   // dark letter on a light brand colour, white otherwise
        $fg = $light ? imagecolorallocate($im, 17, 24, 39) : imagecolorallocate($im, 255, 255, 255);
        $size = 300; $box = imagettfbbox($size, 0, self::FONT, $letter);
        $w = $box[2] - $box[0]; $h = $box[1] - $box[7];
        $x = (int) round(($S - $w) / 2 - $box[0]); $y = (int) round(($S + $h) / 2 - $box[1]);
        imagettftext($im, $size, 0, $x, $y, $fg, self::FONT, $letter);
        return $im;
    }

    private static function fromUpload(string $path)
    {
        $src = @imagecreatefromstring((string) file_get_contents($path)); if (! $src) return null;
        $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
        $S = 512; $im = imagecreatetruecolor($S, $S);
        imagesavealpha($im, true); imagealphablending($im, false); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $S, $S, $side, $side);   // centred square
        imagedestroy($src);
        return $im;
    }

    private static function resize($master, int $px, bool $opaque)
    {
        $im = imagecreatetruecolor($px, $px);
        imagesavealpha($im, ! $opaque); imagealphablending($im, false);
        imagefill($im, 0, 0, $opaque ? imagecolorallocate($im, 255, 255, 255) : imagecolorallocatealpha($im, 0, 0, 0, 127));
        if ($opaque) imagealphablending($im, true);   // the Apple icon is shown on a solid tile
        imagecopyresampled($im, $master, 0, 0, 0, 0, $px, $px, imagesx($master), imagesy($master));
        return $im;
    }

    /** An .ico holding PNG images (supported by every current browser and by Google). */
    private static function writeIco(string $dest, array $pngs): void
    {
        $imgs = []; foreach ($pngs as $p) { $d = file_get_contents($p); [$w, $h] = getimagesize($p); $imgs[] = [$w, $h, $d]; }
        $out = pack('vvv', 0, 1, count($imgs)); $offset = 6 + 16 * count($imgs); $body = '';
        foreach ($imgs as [$w, $h, $d]) { $out .= pack('CCCCvvVV', $w >= 256 ? 0 : $w, $h >= 256 ? 0 : $h, 0, 0, 1, 32, strlen($d), $offset); $offset += strlen($d); $body .= $d; }
        file_put_contents($dest, $out . $body);
    }

    /** Owner upload: keep the original privately, return the stored relative path. */
    public static function storeUpload(int $websiteId, string $tmpPath): ?string
    {
        $src = @imagecreatefromstring((string) file_get_contents($tmpPath)); if (! $src) return null;
        if (imagesx($src) < 48 || imagesy($src) < 48) { imagedestroy($src); return null; }
        $dir = storage_path('app/public/' . self::DIR . "/{$websiteId}/upload"); if (! is_dir($dir)) @mkdir($dir, 0775, true);
        array_map('unlink', glob($dir . '/*') ?: []);
        $name = 'source-' . substr(sha1_file($tmpPath), 0, 10) . '.png';
        imagesavealpha($src, true); imagepng($src, $dir . '/' . $name, 9); imagedestroy($src);
        return self::DIR . "/{$websiteId}/upload/{$name}";
    }

    public static function website(int $id): ?object
    {
        return DB::table('websites')->where('id', $id)->whereNull('deleted_at')->first(['id', 'workspace_id', 'name', 'settings_json', 'template_variables']);
    }
}
