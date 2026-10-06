<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FONTS-8 (2026-10-06). Owner: "add that option in the editor but as default we give them the templates' default font".
 *
 * The full Google Fonts library in the editor's Fonts panel, next to the FONTS-7 pairings. The design's own fonts stay
 * the default: nothing changes on any site until its owner picks a heading face, a body face or a pairing.
 *
 *  - Catalogue: Google's public metadata (no key), fetched server-side, normalised to a compact list and kept in
 *    storage/app/google-fonts.json (refreshed weekly by `fonts:refresh-google`, or on demand when the file is older
 *    than a week) and in the Laravel cache. Only open-source families with Latin glyphs; brand fonts are left out.
 *  - Weights: the design's own font links say which weights each role needs; a family that lacks one gets the nearest
 *    weight it has, and the panel says so. The published page loads one css2 link with only the chosen families in
 *    those weights (display=swap); the design's link loses the families nothing uses any more.
 *  - Layer: the same <style id="lug-design-style"> slot FONTS-7 writes, so snapshot / Undo / Reset are unchanged. It
 *    repoints the design's own tokens (v3 --display/--body, v1/v2 --fh/--fb/--fd) and the heading/body selectors.
 *
 * Kill switch: the FONTS-7 switch storage/app/fonts7.on (absent = no Fonts panel at all).
 */
final class FontLibrary
{
    public const FILE = 'app/google-fonts.json';
    public const CACHE = 'fonts8:google:v1';
    public const SOURCE = 'https://fonts.google.com/metadata/fonts';
    public const MAX_AGE = 7 * 86400;

    private const CATS = ['Serif' => 'serif', 'Sans Serif' => 'sans-serif', 'Display' => 'display', 'Handwriting' => 'handwriting', 'Monospace' => 'monospace'];
    private const FALLBACK = ['serif' => 'Georgia,serif', 'sans-serif' => 'system-ui,sans-serif', 'display' => 'system-ui,sans-serif', 'handwriting' => 'cursive', 'monospace' => 'ui-monospace,monospace'];

    // ── catalogue ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{fetched:int,count:int,families:array<int,array>} most popular first */
    public static function catalogue(): array
    {
        $hit = Cache::get(self::CACHE);
        if (is_array($hit) && ! empty($hit['families'])) return $hit;
        $data = self::readFile();
        if ($data === null || (time() - (int) ($data['fetched'] ?? 0)) > self::MAX_AGE) {
            $fresh = self::refresh();
            if ($fresh !== null) $data = $fresh;
        }
        if ($data === null || empty($data['families'])) return ['fetched' => 0, 'count' => 0, 'families' => []];
        Cache::put(self::CACHE, $data, 6 * 3600);
        return $data;
    }

    /** One family by name (case-insensitive), or null. */
    public static function find(?string $family): ?array
    {
        $family = strtolower(trim((string) $family));
        if ($family === '') return null;
        foreach (self::catalogue()['families'] as $f) { if (strtolower($f['f']) === $family) return $f; }
        return null;
    }

    private static function readFile(): ?array
    {
        $raw = @file_get_contents(storage_path(self::FILE));
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) && isset($j['families']) ? $j : null;
    }

    /** Fetch Google's metadata, normalise, write the JSON file atomically. null on failure (the old file stays). */
    public static function refresh(): ?array
    {
        try {
            $res = Http::timeout(40)->withHeaders(['Accept' => 'application/json'])->get(self::SOURCE);
            if (! $res->successful()) { Log::warning('[FONTS-8] catalogue fetch failed', ['status' => $res->status()]); return null; }
            $body = (string) $res->body();
            $p = strpos($body, '{');                      // the response starts with )]}' on its own line
            $j = $p === false ? null : json_decode(substr($body, $p), true);
            $list = is_array($j) ? ($j['familyMetadataList'] ?? null) : null;
            if (! is_array($list) || count($list) < 500) { Log::warning('[FONTS-8] catalogue shape unexpected'); return null; }
            $out = [];
            foreach ($list as $m) {
                $e = self::normalise($m);
                if ($e !== null) $out[] = $e;
            }
            usort($out, fn ($a, $b) => ($a['p'] <=> $b['p']) ?: strcmp($a['f'], $b['f']));
            $data = ['fetched' => time(), 'source' => self::SOURCE, 'count' => count($out), 'families' => $out];
            $path = storage_path(self::FILE); $tmp = $path . '.tmp' . getmypid();
            file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @chmod($tmp, 0664);
            rename($tmp, $path);
            Cache::forget(self::CACHE);
            return $data;
        } catch (\Throwable $e) {
            Log::warning('[FONTS-8] catalogue refresh error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** One metadata row → {f, c, w, i, p, v} or null when it is not a usable web font for a Latin site. */
    private static function normalise(array $m): ?array
    {
        $f = trim((string) ($m['family'] ?? ''));
        if ($f === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,60}$/', $f)) return null;
        if (! empty($m['isBrandFont'])) return null;
        if (! in_array('latin', (array) ($m['subsets'] ?? []), true)) return null;
        $c = self::CATS[(string) ($m['category'] ?? '')] ?? null;
        if ($c === null) return null;
        $w = []; $i = [];
        foreach (array_keys((array) ($m['fonts'] ?? [])) as $k) {
            if (preg_match('/^(\d{3})(i?)$/', (string) $k, $mm)) { if ($mm[2] === 'i') $i[] = (int) $mm[1]; else $w[] = (int) $mm[1]; }
        }
        if ($w === [] && $i === []) return null;
        if ($w === []) return null;                       // italic-only families cannot carry a heading or body
        sort($w); sort($i);
        $variable = false;
        foreach ((array) ($m['axes'] ?? []) as $ax) { if (($ax['tag'] ?? '') === 'wght') $variable = true; }
        return ['f' => $f, 'c' => $c, 'w' => array_values(array_unique($w)), 'i' => array_values(array_unique($i)), 'p' => (int) ($m['popularity'] ?? 99999), 'v' => $variable];
    }

    // ── weights ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** The weight in $have nearest to $want; ties go heavier for headings, lighter for body text. */
    public static function nearest(array $have, int $want, bool $heavier): int
    {
        $best = null;
        foreach ($have as $h) {
            $h = (int) $h;
            if ($best === null) { $best = $h; continue; }
            $d = abs($h - $want); $bd = abs($best - $want);
            if ($d < $bd || ($d === $bd && ($heavier ? $h > $best : $h < $best))) $best = $h;
        }
        return $best ?? 400;
    }

    /**
     * Resolve a chosen family for a role against the weights the design needs.
     * @return array{f:string,c:string,w:int[],i:int[],swaps:array<int,int>}|null  swaps = needed weight => weight used
     */
    public static function resolve(string $family, array $need, bool $italic, string $role): ?array
    {
        $e = self::find($family);
        if ($e === null) return null;
        $need = array_values(array_unique(array_map('intval', $need ?: [400, 700])));
        sort($need);
        $w = []; $swaps = [];
        foreach ($need as $n) {
            $u = self::nearest($e['w'], $n, $role === 'heading');
            $w[] = $u;
            if ($u !== $n) $swaps[$n] = $u;
        }
        $w = array_values(array_unique($w)); sort($w);
        $i = [];
        if ($italic && $e['i'] !== []) { foreach ($w as $x) { $i[] = self::nearest($e['i'], $x, false); } $i = array_values(array_unique($i)); sort($i); }
        return ['f' => $e['f'], 'c' => $e['c'], 'w' => $w, 'i' => $i, 'swaps' => $swaps];
    }

    /** What the panel says about weights a family does not have, one sentence per role ('' when every weight exists). */
    public static function swapNote(?array $spec, string $roleLabel): string
    {
        if (! $spec || empty($spec['swaps'])) return '';
        $from = array_keys($spec['swaps']); $to = array_values($spec['swaps']);
        $list = fn (array $a, string $and) => count($a) === 1 ? (string) $a[0] : implode(', ', array_slice($a, 0, -1)) . " {$and} " . end($a);
        $to = array_values(array_unique($to));
        return $spec['f'] . ' has no ' . $list($from, 'or') . ' weight, so ' . $roleLabel . ($roleLabel === 'body text' ? ' uses' : ' use') . ' the nearest ' . (count($to) === 1 ? 'one it has (' : 'ones it has (') . $list($to, 'and') . ').';
    }

    /** css2 family parameter for a resolved spec: "Bodoni+Moda:ital,wght@0,400;0,700;1,400". */
    public static function familyParam(array $spec): string
    {
        $name = str_replace(' ', '+', $spec['f']);
        $w = array_map('intval', $spec['w'] ?? [400]);
        $i = array_map('intval', $spec['i'] ?? []);
        if ($i === []) return $name . ':wght@' . implode(';', $w);
        $t = [];
        foreach ($w as $x) $t[] = '0,' . $x;
        foreach ($i as $x) $t[] = '1,' . $x;
        return $name . ':ital,wght@' . implode(';', $t);
    }

    /** Merge two specs of the same family (heading and body in one face). */
    private static function merge(array $a, array $b): array
    {
        $w = array_values(array_unique(array_merge($a['w'], $b['w']))); sort($w);
        $i = array_values(array_unique(array_merge($a['i'] ?? [], $b['i'] ?? []))); sort($i);
        return ['f' => $a['f'], 'c' => $a['c'], 'w' => $w, 'i' => $i];
    }

    // ── the design's own fonts ──────────────────────────────────────────────────────────────────────────────────

    /** Families in Google Fonts link tags: family => ['w' => int[], 'ital' => bool, 'param' => raw]. */
    public static function linkFamilies(array $links): array
    {
        $out = [];
        foreach ($links as $l) {
            if (! preg_match('~href="([^"]+)"~i', $l, $m)) continue;
            $href = html_entity_decode($m[1], ENT_QUOTES);
            $q = (string) parse_url($href, PHP_URL_QUERY);
            foreach (preg_split('/&/', $q) as $part) {
                if (! str_starts_with($part, 'family=')) continue;
                $raw = substr($part, 7);
                [$name, $spec] = array_pad(explode(':', $raw, 2), 2, '');
                $name = trim(str_replace('+', ' ', urldecode($name)));
                if ($name === '') continue;
                $w = []; $ital = false;
                if ($spec !== '' && str_contains($spec, '@')) {
                    [$axes, $vals] = explode('@', $spec, 2);
                    $axes = explode(',', $axes);
                    $wi = array_search('wght', $axes, true); $ii = array_search('ital', $axes, true);
                    foreach (explode(';', $vals) as $tuple) {
                        $v = explode(',', $tuple);
                        if ($ii !== false && (int) ($v[$ii] ?? 0) === 1) $ital = true;
                        if ($wi !== false && isset($v[$wi])) {
                            $x = $v[$wi];
                            if (str_contains($x, '..')) { [$a, $b] = array_map('intval', explode('..', $x)); for ($k = (int) ceil($a / 100) * 100; $k <= $b; $k += 100) $w[] = $k; }
                            else $w[] = (int) $x;
                        }
                    }
                }
                if ($w === []) $w = [400];
                $prev = $out[$name] ?? ['w' => [], 'ital' => false];
                $all = array_values(array_unique(array_merge($prev['w'], $w))); sort($all);
                $out[$name] = ['w' => $all, 'ital' => $prev['ital'] || $ital];
            }
        }
        return $out;
    }

    /** The first family of a CSS font stack value: "\"Instrument Serif\", Georgia, serif" → Instrument Serif. */
    private static function firstFamily(string $stack): string
    {
        $first = trim(explode(',', $stack)[0] ?? '');
        return trim($first, " \t'\"");
    }

    /**
     * Which family the design uses for headings and which for body text, and the weights its own link loads for each.
     * $css = the design's own HTML (template, else the export); $links = the design's own Google Fonts link tags.
     * @return array{heading:?array,body:?array,vars:bool}  role = ['f' => family, 'w' => int[], 'ital' => bool]
     */
    public static function designRoles(string $css, array $links): array
    {
        $fam = self::linkFamilies($links);
        $h = null; $b = null; $vars = false;
        if (preg_match('/--(?:display|fh|fd)\s*:\s*([^;}]+)/', $css, $m)) { $h = self::firstFamily($m[1]); $vars = true; }
        if (preg_match('/--(?:body|fb)\s*:\s*([^;}]+)/', $css, $m)) { $b = self::firstFamily($m[1]); $vars = true; }
        if ($h === null && preg_match('/(?:^|[}\s,])h1[^{]*\{[^}]*font-family\s*:\s*([^;}]+)/', $css, $m)) $h = self::firstFamily($m[1]);
        if ($b === null && preg_match('/(?:^|[}\s,])body\s*\{[^}]*font-family\s*:\s*([^;}]+)/', $css, $m)) $b = self::firstFamily($m[1]);
        $names = array_keys($fam);
        if (($h === null || str_starts_with($h, 'var(')) && $names) $h = $names[0];
        if (($b === null || str_starts_with($b, 'var(')) && $names) $b = end($names);
        $role = function (?string $f) use ($fam): ?array {
            if ($f === null || $f === '') return null;
            $x = $fam[$f] ?? null;
            return ['f' => $f, 'w' => $x['w'] ?? [400, 700], 'ital' => (bool) ($x['ital'] ?? false)];
        };
        return ['heading' => $role($h), 'body' => $role($b), 'vars' => $vars];
    }

    /**
     * Whether a design family can stop loading once its role is overridden: nothing in the page names it outside the
     * font tokens (v3/v2 designs read it through var(--display)); a v1 export that writes font-family:'X' keeps it.
     */
    public static function droppable(string $html, string $family): bool
    {
        $h = preg_replace('~<style id="lug-design-style".*?</style>~is', '', $html) ?? $html;
        $h = preg_replace('~<link[^>]*>~i', '', $h) ?? $h;
        $h = preg_replace('/--[A-Za-z0-9_-]+\s*:[^;}]*/', '', $h) ?? $h;
        $q = preg_quote($family, '/');
        return ! preg_match('/[\'"]' . $q . '[\'"]/i', $h);
    }

    // ── the layer ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The injectable layer for a custom choice. $h / $b are resolved specs (null = that role keeps the design's own face).
     * With a style preset ($style) the preset's shapes stay and both faces are written (the preset's own for an unset role).
     */
    public static function layer(?array $h, ?array $b, ?string $style = null, array $colors = []): string
    {
        $style = DesignStyle::normaliseStyle($style);
        $preset = $style !== null ? DesignStyle::preset($style) : null;
        if ($preset !== null) {
            if ($h === null) $h = ['f' => $preset['display'], 'c' => 'serif', 'w' => array_map('intval', explode(';', $preset['dw'])), 'i' => []];
            if ($b === null) $b = ['f' => $preset['body'], 'c' => 'sans-serif', 'w' => array_map('intval', explode(';', $preset['bw'])), 'i' => []];
        }
        if ($h === null && $b === null) return '';
        $params = [];
        if ($h && $b && $h['f'] === $b['f']) { $params[] = self::familyParam(self::merge($h, $b)); }
        else { if ($h) $params[] = self::familyParam($h); if ($b) $params[] = self::familyParam($b); }
        $gf = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $params) . '&display=swap';
        $vars = []; $rules = '';
        if ($h) {
            $d = htmlspecialchars($h['f'], ENT_QUOTES); $fb = self::FALLBACK[$h['c']] ?? 'Georgia,serif';
            $stack = "'{$d}',{$fb}";
            $vars[] = "--ds-font-display:'{$d}';--display:{$stack};--fh:{$stack};--fd:{$stack}";
            $wt = '';
            if ($preset !== null) { $vars[] = '--ds-display-weight:' . self::nearest($h['w'], (int) $preset['display_weight'], true); $wt = 'font-weight:var(--ds-display-weight)!important;'; }
            $rules .= DesignStyle::HEAD_SEL . "{font-family:var(--ds-font-display),{$fb}!important;{$wt}}\n";
        }
        if ($b) {
            $d = htmlspecialchars($b['f'], ENT_QUOTES); $fb = self::FALLBACK[$b['c']] ?? 'system-ui,sans-serif';
            $stack = "'{$d}',{$fb}";
            $vars[] = "--ds-font-body:'{$d}';--body:{$stack};--fb:{$stack}";
            $rules .= DesignStyle::BODY_SEL . "{font-family:var(--ds-font-body),{$fb}!important}\n";
        }
        $shape = '';
        if ($preset !== null) {
            $vars[] = "--ds-radius:{$preset['radius']};--ds-eyebrow-ls:{$preset['eyebrow_ls']}";
            $shape = DesignStyle::shapeCss($preset + ['style' => $style], $colors);
        }
        return "\n<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link rel=\"stylesheet\" href=\"" . htmlspecialchars($gf, ENT_QUOTES) . "\">\n"
            . "<style id=\"lug-design-style\" data-style=\"" . htmlspecialchars($style ?? 'custom', ENT_QUOTES) . "\" data-fonts=\"fonts8\">\n"
            . 'html:root{' . implode(';', $vars) . "}\n" . $rules . $shape
            . "</style>\n";
    }

    /** The saved choice on a site, as a layer — what a rebuild writes so the owner's fonts survive it. null = none saved. */
    public static function savedLayer(array $tv, array $colors = []): ?string
    {
        if (! FontPairs::on()) return null;
        $c = $tv['font_custom'] ?? null;
        if (is_array($c) && (! empty($c['heading']) || ! empty($c['body']))) {
            $ok = fn ($s) => is_array($s) && isset($s['f'], $s['w']) && preg_match('/^[A-Za-z0-9 \-]+$/', (string) $s['f']) ? $s + ['c' => 'sans-serif', 'i' => []] : null;
            $l = self::layer($ok($c['heading'] ?? null), $ok($c['body'] ?? null), $tv['design_style'] ?? null, $colors);
            return $l !== '' ? $l : null;
        }
        $pair = (string) ($tv['font_pair'] ?? '');
        if ($pair !== '' && FontPairs::find($pair) !== null) {
            $l = FontPairs::layerFor($pair, $tv['design_style'] ?? null, $colors);
            return $l !== '' ? $l : null;
        }
        return null;
    }
}
