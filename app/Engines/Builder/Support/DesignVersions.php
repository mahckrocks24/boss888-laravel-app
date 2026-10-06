<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * DESIGN-UPDATES-1 (Owner 2026-10-06: "build it. Also, whenever we are pushing updates, we need to inform them using a
 * design preview of what the website will look and the possible changes on their customizations, with and agree or
 * cancel option and a revert back option if needed").
 *
 * A design's version is a content hash of its template.html and the structural part of its manifest. Every version a
 * website can stand on is archived under storage/app/design-versions/{slug}/{version}/, so the update can compare the
 * site with the design it was built on (the base), the design as it is now (the new) and the site as the owner has it
 * (the current). Each website records the design and version it stands on in settings_json.design. hash() is pure (no
 * framework calls): the v3 generator requires this file and stamps manifests with the same function.
 */
final class DesignVersions
{
    /** Manifest keys that never change what a visitor sees. */
    private const VOLATILE = ['design_version', 'is_active', 'thumbnail', 'generated_at', 'updated_at', 'created_at', 'design_note'];

    public static function hash(string $html, array $manifest): string
    {
        foreach (self::VOLATILE as $k) unset($manifest[$k]);
        if (isset($manifest['design']) && is_array($manifest['design'])) unset($manifest['design']['generated']);
        $canon = function ($v) use (&$canon) { if (! is_array($v)) return $v; if (array_keys($v) !== range(0, count($v) - 1)) ksort($v); foreach ($v as $k => $x) $v[$k] = $canon($x); return $v; };
        return substr(sha1($html . "\n" . json_encode($canon($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    public static function slugSafe(string $slug): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
    }

    /**
     * The version of the design on disk now ('' when the design does not exist). Read fresh every time (a design is about
     * 100 KB): file times are whole seconds, and a same-second, same-size rewrite must still count as a new version.
     */
    public static function current(string $slug): string
    {
        $slug = self::slugSafe($slug); if ($slug === '') return '';
        $dir = storage_path("templates/{$slug}");
        $h = "{$dir}/template.html"; $m = "{$dir}/manifest.json";
        if (! is_file($h)) return '';
        $mf = is_file($m) ? (json_decode((string) file_get_contents($m), true) ?: []) : [];
        return self::hash((string) file_get_contents($h), $mf);
    }

    public static function archiveDir(string $slug, string $version): string
    {
        return storage_path('app/design-versions/' . self::slugSafe($slug) . '/' . preg_replace('/[^a-f0-9]/', '', $version));
    }

    public static function archived(string $slug, string $version): bool
    {
        return $version !== '' && is_file(self::archiveDir($slug, $version) . '/template.html');
    }

    /** Keep a copy of the design as it is now; returns its version ('' when there is no design). Idempotent. */
    public static function archive(string $slug): string
    {
        $slug = self::slugSafe($slug); $ver = self::current($slug);
        if ($ver === '' || self::archived($slug, $ver)) return $ver;
        $dir = self::archiveDir($slug, $ver); @mkdir($dir, 0775, true);
        $src = storage_path("templates/{$slug}");
        $tmp = $dir . '/.tmp-' . getmypid();
        @copy("{$src}/manifest.json", "{$dir}/manifest.json");
        if (@copy("{$src}/template.html", $tmp)) @rename($tmp, "{$dir}/template.html");
        // the copy must be the version it is filed under (the generator may have rewritten the design meanwhile)
        $mf = json_decode((string) @file_get_contents("{$dir}/manifest.json"), true) ?: [];
        if (! is_file("{$dir}/template.html") || self::hash((string) file_get_contents("{$dir}/template.html"), $mf) !== $ver) { self::rmTree($dir); return $ver; }
        return $ver;
    }

    /** Stamp the design version into a manifest (what the generator does at build time). Returns true when written. */
    public static function stampManifest(string $slug): bool
    {
        $slug = self::slugSafe($slug); $p = storage_path("templates/{$slug}/manifest.json");
        if (! is_file($p)) return false;
        $mf = json_decode((string) file_get_contents($p), true); if (! is_array($mf)) return false;
        $ver = self::current($slug); if (($mf['design_version'] ?? null) === $ver) return false;
        // one line changes; the rest of the file keeps its own formatting (the manifests are tracked in git)
        $raw = (string) file_get_contents($p);
        $new = array_key_exists('design_version', $mf)
            ? (string) preg_replace('/"design_version"\s*:\s*"[^"]*"/', '"design_version": "' . $ver . '"', $raw, 1)
            : (string) preg_replace('/^\s*\{/', "{\n    \"design_version\": \"{$ver}\",", $raw, 1);
        if (! is_array(json_decode($new, true))) return false;
        return @file_put_contents($p, $new) !== false;
    }

    /** The design a website stands on: ['slug' => …, 'version' => …] (version '' when never recorded). */
    public static function ofSite(int $websiteId): array
    {
        $w = DB::table('websites')->where('id', $websiteId)->first(['settings_json', 'template', 'template_industry']);
        if (! $w) return ['slug' => '', 'version' => ''];
        $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
        $d = is_array($s['design'] ?? null) ? $s['design'] : [];
        $slug = self::slugSafe((string) ($d['slug'] ?? $s['template'] ?? $w->template ?? $s['industry'] ?? $w->template_industry ?? ''));
        return ['slug' => $slug, 'version' => (string) ($d['version'] ?? '')];
    }

    /** Record the design version a website stands on (and archive it, so it can always be compared with later). */
    public static function stampSite(int $websiteId, ?string $version = null, string $why = 'build'): ?string
    {
        $cur = self::ofSite($websiteId); if ($cur['slug'] === '') return null;
        $ver = $version ?? self::archive($cur['slug']);
        if ($ver === '') return null;
        if ($version !== null) self::archive($cur['slug']);
        $raw = (string) (DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}');
        $s = json_decode($raw, true) ?: [];
        $s['design'] = ['slug' => $cur['slug'], 'version' => $ver, 'since' => date('c'), 'why' => $why];
        DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($s)]);
        return $ver;
    }

    /**
     * After a deploy: the page names the version it was rendered from (<meta name="lu-dv">); the site's record follows it,
     * so a re-render on the current design never leaves the site offered an update it already has. A page with no marker
     * (an export older than this) only gets a record when it has none.
     */
    public static function recordDeployed(int $websiteId, string $html): void
    {
        try {
            if (! preg_match('/<meta name="lu-dv" content="([a-f0-9]{12})"/', $html, $m)) { self::stampIfMissing($websiteId); return; }
            $d = self::ofSite($websiteId); if ($d['slug'] === '' || $d['version'] === $m[1]) return;
            if (! self::archived($d['slug'], $m[1]) && self::archive($d['slug']) !== $m[1]) return;   // only a version we can compare with later
            self::stampSite($websiteId, $m[1], 'deploy');
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::info('[DesignVersions] record skipped: ' . $e->getMessage()); }
    }

    /** The version a page says it was rendered from ('' when it carries no marker). */
    public static function ofHtml(string $html): string
    {
        return preg_match('/<meta name="lu-dv" content="([a-f0-9]{12})"/', $html, $m) ? $m[1] : '';
    }

    /** Stamp only when the site has no record yet (deploy hook: a first build records the version it was built on). */
    public static function stampIfMissing(int $websiteId): void
    {
        try { $d = self::ofSite($websiteId); if ($d['slug'] !== '' && $d['version'] === '' && is_file(storage_path("templates/{$d['slug']}/template.html"))) self::stampSite($websiteId, null, 'first_deploy'); }
        catch (\Throwable $e) { \Illuminate\Support\Facades\Log::info('[DesignVersions] stamp skipped: ' . $e->getMessage()); }
    }

    private static function rmTree(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) { if ($e === '.' || $e === '..') continue; $p = "{$dir}/{$e}"; is_dir($p) ? self::rmTree($p) : @unlink($p); }
        @rmdir($dir);
    }
}
