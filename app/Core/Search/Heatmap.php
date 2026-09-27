<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PAGE-ONE-1 heatmaps (Owner 2026-09-27: "we are missing heatmap too").
 *
 * First-party, on every published LevelUp site: where visitors click and tap, and how far they scroll, per device.
 * No cookies, no personal data, no keystrokes, no session recording — a random id per page view that never leaves the
 * page. Honours Do Not Track, Global Privacy Control and a site's own "lug_consent=denied" cookie. The owner can switch
 * it off per site (settings_json.heatmaps = false). Raw events are kept 90 days.
 */
final class Heatmap
{
    public const PATH = '__lug/hm';

    public static function enabled(object $w): bool
    {
        $s = json_decode((string) ($w->settings_json ?? '{}'), true) ?: [];
        return ! array_key_exists('heatmaps', $s) || $s['heatmaps'] !== false;
    }

    /** The collector snippet, before </body>. */
    public static function inject(string $html, ?object $w): string
    {
        if (! $w || $html === '' || str_contains($html, 'data-lug-hm') || ! self::enabled($w)) return $html;
        $js = '<script data-lug-hm>(function(){try{if(window.top!==window||navigator.doNotTrack=="1"||window.globalPrivacyControl||/lug_consent=(denied|no)/.test(document.cookie||""))return;'
            . 'var pv=Math.random().toString(36).slice(2,14),ev=[],mx=0,dev=matchMedia("(max-width:767px)").matches?"m":"d";'
            . 'function dh(){return Math.max(document.documentElement.scrollHeight,document.body?document.body.scrollHeight:0)||1}'
            . 'function sl(e){var s=(e.tagName||"").toLowerCase();if(e.id)s+="#"+e.id;else if(typeof e.className=="string"&&e.className.trim())s+="."+e.className.trim().split(/\s+/).slice(0,2).join(".");var t=(e.children&&e.children.length>3)||/^(html|body|main|section|header|footer)$/i.test(e.tagName||"")?"":(e.innerText||e.alt||"").trim().slice(0,40);return (s+(t?"|"+t:"")).slice(0,158)}'
            . 'addEventListener("click",function(e){if(ev.length>=60)return;var t=e.target,a=t&&t.closest?t.closest("a,button,[role=button],input[type=submit]"):null;ev.push({t:"c",x:Math.round(e.clientX/innerWidth*1000),y:Math.round(e.pageY),h:dh(),s:sl(a||t),l:a?1:0})},true);'
            . 'addEventListener("scroll",function(){var d=(scrollY+innerHeight)/dh();if(d>mx)mx=d},{passive:true});'
            . 'function sd(){if(sd.x)return;sd.x=1;ev.push({t:"v",d:Math.round(Math.min(1,Math.max(mx,(scrollY+innerHeight)/dh()))*1000),h:dh()});var b=JSON.stringify({p:location.pathname,v:pv,dv:dev,e:ev});'
            . 'if(navigator.sendBeacon)navigator.sendBeacon("/' . self::PATH . '",new Blob([b],{type:"text/plain"}));else fetch("/' . self::PATH . '",{method:"POST",body:b,keepalive:true})}'
            . 'document.addEventListener("visibilitychange",function(){if(document.visibilityState=="hidden")sd()});addEventListener("pagehide",sd)}catch(_){}})();</script>';
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $js : substr($html, 0, $pos) . $js . substr($html, $pos);
    }

    /** POST /__lug/hm on the site's own host. Always 204. */
    public static function collect(object $w, \Illuminate\Http\Request $r): void
    {
        try {
            if (! self::enabled($w)) return;
            $ua = strtolower((string) $r->userAgent());
            if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|curl|python|wget/', $ua)) return;
            if (! Cache::add('hm-rl:' . sha1((string) $r->ip()) . ':' . now()->format('YmdHi'), 1, 120)) { if (Cache::increment('hm-rl:' . sha1((string) $r->ip()) . ':' . now()->format('YmdHi')) > 40) return; }
            $raw = (string) $r->getContent();
            if (strlen($raw) > 65536) return;
            $d = json_decode($raw, true);
            if (! is_array($d)) return;
            $path = '/' . ltrim(mb_substr((string) ($d['p'] ?? '/'), 0, 290), '/');
            if (str_starts_with($path, '/' . self::PATH)) return;
            $dev = ($d['dv'] ?? 'd') === 'm' ? 'm' : 'd';
            $pv = substr(preg_replace('/[^a-z0-9]/', '', (string) ($d['v'] ?? '')), 0, 12);
            $rows = [];
            foreach (array_slice((array) ($d['e'] ?? []), 0, 61) as $e) {
                if (! is_array($e)) continue;
                $t = ($e['t'] ?? '') === 'v' ? 'v' : (($e['t'] ?? '') === 'c' ? 'c' : null);
                if (! $t) continue;
                $rows[] = ['website_id' => (int) $w->id, 'path' => $path, 'device' => $dev, 'type' => $t, 'x' => $t === 'c' ? max(0, min(1000, (int) ($e['x'] ?? 0))) : null,
                    'y' => $t === 'c' ? max(0, min(4000000, (int) ($e['y'] ?? 0))) : null, 'doc_h' => max(1, min(4000000, (int) ($e['h'] ?? 1))), 'depth' => $t === 'v' ? max(0, min(1000, (int) ($e['d'] ?? 0))) : null,
                    'sel' => $t === 'c' ? mb_substr(strip_tags((string) ($e['s'] ?? '')), 0, 158) : null, 'link' => $t === 'c' ? ! empty($e['l']) : null, 'pv' => $pv ?: null, 'created_at' => now()];
            }
            if ($rows) DB::table('heatmap_events')->insert($rows);
        } catch (\Throwable $e) {}
    }

    /** Pages with data, most viewed first. */
    public function pages(int $websiteId, int $days = 30): array
    {
        return DB::table('heatmap_events')->where('website_id', $websiteId)->where('type', 'v')->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('path, COUNT(*) views, ROUND(AVG(depth)/10) avg_depth')->groupBy('path')->orderByDesc('views')->limit(60)->get()->map(fn ($r) => ['path' => $r->path, 'views' => (int) $r->views, 'avg_depth_pct' => (int) $r->avg_depth])->all();
    }

    /** Everything the viewer draws for one page and device. */
    public function data(int $websiteId, string $path, string $device, int $days = 30): array
    {
        $base = DB::table('heatmap_events')->where('website_id', $websiteId)->where('path', $path)->where('device', $device)->where('created_at', '>=', now()->subDays($days));
        $views = (clone $base)->where('type', 'v')->pluck('depth')->map(fn ($d) => (int) $d)->all();
        $reach = [];
        for ($p = 0; $p <= 100; $p += 10) $reach[] = ['at' => $p, 'pct' => $views ? (int) round(100 * count(array_filter($views, fn ($d) => $d >= $p * 10)) / count($views)) : 0];
        $clicks = (clone $base)->where('type', 'c')->orderByDesc('id')->limit(3000)->get(['x', 'y', 'doc_h', 'sel', 'link']);
        $pts = $clicks->map(fn ($c) => [(int) $c->x, (int) round(1000 * min(1, $c->y / max(1, $c->doc_h)))])->all();
        $sel = []; $dead = [];
        foreach ($clicks as $c) { $k = (string) $c->sel; if ($k === '') continue; $sel[$k] = ($sel[$k] ?? 0) + 1; if (! $c->link) $dead[$k] = ($dead[$k] ?? 0) + 1; }
        arsort($sel); arsort($dead);
        $label = fn ($k) => trim((explode('|', $k, 2)[1] ?? '') ?: explode('|', $k, 2)[0]);
        $hs = (clone $base)->orderByDesc('id')->limit(200)->pluck('doc_h')->map(fn ($h) => (int) $h)->sort()->values()->all();
        return ['path' => $path, 'device' => $device, 'doc_h' => $hs ? $hs[intdiv(count($hs), 2)] : null, 'views' => count($views), 'avg_depth_pct' => $views ? (int) round(array_sum($views) / count($views) / 10) : 0, 'reach' => $reach, 'clicks' => $pts,
            'top_clicked' => array_map(fn ($k, $n) => ['what' => $label($k), 'clicks' => $n], array_slice(array_keys($sel), 0, 8), array_slice(array_values($sel), 0, 8)),
            'dead_clicks' => array_values(array_filter(array_map(fn ($k, $n) => $n >= 3 ? ['what' => $label($k), 'clicks' => $n] : null, array_slice(array_keys($dead), 0, 5), array_slice(array_values($dead), 0, 5))))];
    }

    /** Articles most readers leave before the end (where the call to action sits): candidates to move it up. */
    public function ctaLate(int $websiteId): array
    {
        $out = [];
        foreach ($this->pages($websiteId) as $p) {
            if ($p['views'] < 30) continue;
            $all = DB::table('heatmap_events')->where('website_id', $websiteId)->where('path', $p['path'])->where('type', 'v')->where('created_at', '>=', now()->subDays(30))->pluck('depth')->all();
            $reach = $all ? (int) round(100 * count(array_filter($all, fn ($x) => $x >= 700)) / count($all)) : 100;
            if ($reach < 40) $out[] = ['path' => $p['path'], 'reach_pct' => $reach, 'views' => $p['views']];
        }
        return $out;
    }

    /** What Sarah can say about how visitors use the site (facts for her messages and the monthly report). */
    public function insights(int $websiteId): array
    {
        $pages = array_slice($this->pages($websiteId), 0, 5);
        $out = ['most_viewed' => $pages, 'readers_leave_early' => $this->ctaLate($websiteId), 'dead_clicks' => []];
        foreach (array_slice($pages, 0, 3) as $p) { foreach ($this->data($websiteId, $p['path'], 'm')['dead_clicks'] as $d) $out['dead_clicks'][] = ['page' => $p['path'], 'what' => $d['what'], 'taps' => $d['clicks']]; }
        return $out;
    }

    public static function prune(): int
    {
        return DB::table('heatmap_events')->where('created_at', '<', now()->subDays(90))->limit(50000)->delete();
    }
}
