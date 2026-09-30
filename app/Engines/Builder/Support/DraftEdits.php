<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * DRAFT-5 (RFC-0021 wave 5, 2026-10-01, D1) — review before live.
 *
 * REPORT-0067 #7/#8 and REPORT-0068 #7: on a published site every edit went straight to visitors, half-done or as a
 * placeholder page, and the Back dialog claimed a draft that did not exist. Now the working tree the editor and Arthur
 * write (storage/app/public/sites/{id}/…, unchanged) is the DRAFT; visitors are served a frozen copy in
 * sites/{id}/.live/ that only "Publish changes" refreshes. Blog articles are injected from the database at serve time,
 * so Sarah's articles never wait for the owner. Kill switch: storage/app/draftedits.on (absent = the working tree is
 * served, as before).
 */
final class DraftEdits
{
    public const SWITCH = 'app/draftedits.on';
    public const LIVE_DIR = '.live';
    private const SKIP_DIRS = ['.history', '.live'];

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    public static function workingRoot(int $websiteId): string
    {
        return storage_path('app/public/sites/' . $websiteId);
    }

    public static function liveRoot(int $websiteId): string
    {
        return self::workingRoot($websiteId) . '/' . self::LIVE_DIR;
    }

    /** The tree visitors are served: the frozen live copy when it exists, else the working tree (today's behaviour). */
    public static function servedRoot(string $siteRoot): string
    {
        return (self::on() && is_dir($siteRoot . '/' . self::LIVE_DIR)) ? $siteRoot . '/' . self::LIVE_DIR : $siteRoot;
    }

    public static function hasLive(int $websiteId): bool
    {
        return is_dir(self::liveRoot($websiteId));
    }

    /** Freeze the working tree as the live copy (a full replace, so removed pages disappear too). */
    public static function promote(int $websiteId): array
    {
        $src = self::workingRoot($websiteId); $dst = self::liveRoot($websiteId);
        if (! is_dir($src) || ! is_file($src . '/index.html')) return ['promoted' => false, 'reason' => 'no_export'];
        $tmp = $src . '/.live-next';
        self::rmTree($tmp);
        $n = self::copyTree($src, $tmp);
        if (is_dir($dst)) { $old = $src . '/.live-old'; self::rmTree($old); @rename($dst, $old); @rename($tmp, $dst); self::rmTree($old); }
        else { @rename($tmp, $dst); }
        return ['promoted' => true, 'files' => $n];
    }

    /** What the draft holds that visitors do not see yet. */
    public static function changes(int $websiteId): array
    {
        $src = self::workingRoot($websiteId); $dst = self::liveRoot($websiteId);
        $out = ['has_live' => is_dir($dst), 'count' => 0, 'pages_added' => [], 'pages_removed' => [], 'fields' => [], 'sections' => []];
        if (! is_dir($dst)) return $out;
        $work = self::htmlFiles($src); $live = self::htmlFiles($dst);
        foreach ($work as $rel => $p) { if (! isset($live[$rel])) $out['pages_added'][] = self::pageName($rel); }
        foreach ($live as $rel => $p) { if (! isset($work[$rel])) $out['pages_removed'][] = self::pageName($rel); }
        foreach ($work as $rel => $p) {
            if (! isset($live[$rel])) continue;
            $a = self::fieldTexts((string) file_get_contents($live[$rel])); $b = self::fieldTexts((string) file_get_contents($p));
            foreach ($b as $k => $v) {
                $before = $a[$k] ?? null;
                if ($before === $v) continue;
                if (str_starts_with($k, 'section:')) { $out['sections'][] = ['page' => self::pageName($rel), 'section' => substr($k, 8), 'now' => $v]; continue; }
                if (preg_match('/^(logo_url|.*_(image|photo|avatar|img))$/i', $k)) { $out['fields'][] = ['page' => self::pageName($rel), 'field' => $k, 'kind' => 'picture', 'before' => $before === null ? null : 'a picture', 'after' => 'a new picture']; continue; }
                $out['fields'][] = ['page' => self::pageName($rel), 'field' => $k, 'kind' => 'text', 'before' => $before, 'after' => $v];
            }
            foreach ($a as $k => $v) { if (! array_key_exists($k, $b) && ! str_starts_with($k, 'section:')) $out['fields'][] = ['page' => self::pageName($rel), 'field' => $k, 'kind' => 'text', 'before' => $v, 'after' => null]; }
        }
        $out['count'] = count($out['pages_added']) + count($out['pages_removed']) + count($out['fields']) + count($out['sections']);
        return $out;
    }

    /** The texts and pictures a page carries, by field, plus each section's shown / hidden state. */
    public static function fieldTexts(string $html): array
    {
        $out = [];
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>#is', '', $html);
        if (preg_match_all('/<(img)\b[^>]*\bdata-field="([a-z0-9_\-]+)"[^>]*\bsrc="([^"]*)"/i', $html, $im, PREG_SET_ORDER)) { foreach ($im as $m) $out[$m[2]] = $m[3]; }
        if (preg_match_all('/<(?!img\b)([a-z][a-z0-9]*)\b[^>]*\bdata-field="([a-z0-9_\-]+)"[^>]*>(.*?)<\/\1>/is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                if (isset($out[$m[2]])) continue;
                $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (preg_match('/\bstyle="[^"]*background-image\s*:\s*url\(([^)]*)\)/i', $m[0], $bg) && $t === '') $t = trim($bg[1], "'\" ");
                $out[$m[2]] = mb_substr($t, 0, 160);
            }
        }
        if (preg_match_all('/<[a-z][a-z0-9]*\b([^>]*\bdata-block="([a-z0-9_\-]+)"[^>]*)>/i', $html, $bm, PREG_SET_ORDER)) {
            foreach ($bm as $m) { $out['section:' . $m[2]] = stripos($m[1], 'data-lu-hidden="1"') !== false ? 'hidden' : 'shown'; }
        }
        return $out;
    }

    /** A catalogue-backed page the site has no items for yet; the kind it needs, or null. */
    public static function pageNeedsItems(int $websiteId, string $pageSlug): ?string
    {
        $kind = ['menu' => 'menu', 'listing_browser' => 'listing', 'listing_detail' => 'listing'][$pageSlug] ?? null;
        if ($kind === null) return null;
        try { $n = DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->where('source', '!=', 'seed')->count(); }   // a design's sample dishes are not the business's items
        catch (\Throwable $e) { $n = 0; }   // the test database has no catalogue table
        return $n > 0 ? null : $kind;
    }

    private static function htmlFiles(string $root): array
    {
        $out = [];
        if (! is_dir($root)) return $out;
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), function ($f) { return ! ($f->isDir() && in_array($f->getFilename(), self::SKIP_DIRS, true)); }));
        foreach ($it as $f) { if ($f->isFile() && strtolower($f->getExtension()) === 'html') { $rel = substr($f->getPathname(), strlen($root) + 1); if (str_starts_with($rel, 'blog/') && $rel !== 'blog/index.html') continue; $out[$rel] = $f->getPathname(); } }
        ksort($out); return $out;
    }

    private static function pageName(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === 'index.html') return 'Home';
        return ucfirst(str_replace(['/index.html', '.html', '-', '_'], ['', '', ' ', ' '], $rel));
    }

    private static function copyTree(string $src, string $dst): int
    {
        $n = 0; @mkdir($dst, 0775, true);
        foreach (scandir($src) ?: [] as $e) {
            if ($e === '.' || $e === '..' || in_array($e, self::SKIP_DIRS, true) || $e === '.live-next' || $e === '.live-old') continue;
            $s = $src . '/' . $e; $d = $dst . '/' . $e;
            if (is_dir($s)) { $n += self::copyTree($s, $d); } elseif (is_file($s)) { if (@copy($s, $d)) { @chmod($d, 0664); $n++; } }
        }
        return $n;
    }

    private static function rmTree(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) { if ($e === '.' || $e === '..') continue; $p = $dir . '/' . $e; is_dir($p) ? self::rmTree($p) : @unlink($p); }
        @rmdir($dir);
    }
}
