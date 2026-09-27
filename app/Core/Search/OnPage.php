<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1: the on-page pass every new page and article gets (and every optimization reuses).
 *   - one search it is meant to be found for (the focus keyword)
 *   - a title written to be clicked (<= 60 characters, the search near the front) and a description (140-158)
 *   - alt text on every image in the article body
 *   - structured data that fits: Article (+ FAQPage when the article answers questions) for articles; LocalBusiness on
 *     the home page, Service on service pages, WebPage elsewhere
 * The body the owner wrote is never rewritten here. Empty fields are filled; existing ones change only when asked
 * (force), which is what an approved optimization does.
 */
final class OnPage
{
    /** @return array{changed:string[], target:?string} */
    public function article(int $articleId, bool $force = false, ?string $target = null): array
    {
        $a = DB::table('articles')->where('id', $articleId)->whereNull('deleted_at')->first();
        if (! $a) return ['changed' => [], 'target' => null];
        $w = $a->website_id ? DB::table('websites')->where('id', $a->website_id)->first() : null;
        $biz = $w ? SearchSites::business($w) : DB::table('businesses')->where('workspace_id', $a->workspace_id)->whereNull('deleted_at')->orderByDesc('is_default')->first();
        $html = (string) $a->content;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        preg_match_all('#<h2[^>]*>(.*?)</h2>#is', $html, $h2);
        preg_match_all('#<img\b[^>]*>#i', $html, $imgs);
        $noAlt = [];
        foreach ($imgs[0] as $i => $tag) if (! preg_match('/\balt\s*=\s*"[^"]+"/i', $tag)) $noAlt[$i] = (preg_match('/src="([^"]+)"/i', $tag, $m) ? basename(parse_url($m[1], PHP_URL_PATH) ?: '') : '');
        $faq = $this->faqFrom($html);

        $need = $force || ! $a->meta_title || mb_strlen((string) $a->meta_title) > 65 || ! $a->meta_description || mb_strlen((string) $a->meta_description) < 90 || mb_strlen((string) $a->meta_description) > 170 || ! $a->focus_keyword || $noAlt || ! $a->featured_image_alt;
        $ai = [];
        if ($need) {
            $sys = 'You are a senior SEO specialist writing on-page fields for one article on a small business website. '
                . 'Rules: meta_title <= 60 characters, the main search phrase near the start, specific and worth clicking, no clickbait, no ALL CAPS, no year unless the article is about this year, no brand suffix (the site adds it). '
                . 'meta_description 140-158 characters: what the reader gets, one concrete detail, a soft call to act; no quotes. '
                . 'target_query: the one search this article should rank for, as people type it (lowercase, 2-6 words, include the place when the business is local and the topic is local). '
                . 'image_alts: for each image listed, a plain description of what the picture shows (max 110 characters) that fits the article. featured_alt the same for the featured image. '
                . 'Return ONLY JSON {"target_query":"","meta_title":"","meta_description":"","featured_alt":"","image_alts":{"<index>":"..."}}';
            $user = json_encode(['business' => ['name' => $biz->name ?? null, 'industry' => $biz->industry ?? null, 'place' => SearchSites::place($biz)],
                'article_title' => $a->title, 'wanted_search' => $target ?: ($a->focus_keyword ?: null), 'headings' => array_slice(array_map(fn ($x) => trim(strip_tags($x)), $h2[1] ?? []), 0, 12),
                'opening' => mb_substr($text, 0, 1200), 'current' => ['meta_title' => $a->meta_title, 'meta_description' => $a->meta_description],
                'images_without_alt' => $noAlt, 'featured_image' => $a->featured_image_url ? basename((string) parse_url($a->featured_image_url, PHP_URL_PATH)) : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'JSON input: ' . $user, ['task' => 'seo_onpage', 'workspace_id' => (string) $a->workspace_id], 700);
            $ai = ($r['success'] ?? false) && is_array($r['parsed'] ?? null) ? $r['parsed'] : [];
        }
        $up = []; $changed = [];
        $tq = self::clean($ai['target_query'] ?? '', 120);
        $target = $target ?: ($a->focus_keyword ?: ($tq ?: null));
        if ((! $a->focus_keyword || $force) && $target && $target !== $a->focus_keyword) { $up['focus_keyword'] = mb_substr($target, 0, 120); $changed[] = 'focus search'; }
        $mt = self::clean($ai['meta_title'] ?? '', 70);
        if ($mt !== '' && mb_strlen($mt) <= 65 && ($force || ! $a->meta_title || mb_strlen((string) $a->meta_title) > 65)) { $up['meta_title'] = $mt; $changed[] = 'title'; }
        $md = self::clean($ai['meta_description'] ?? '', 175);
        if ($md !== '' && mb_strlen($md) >= 90 && ($force || ! $a->meta_description || mb_strlen((string) $a->meta_description) < 90 || mb_strlen((string) $a->meta_description) > 170)) { $up['meta_description'] = $md; $changed[] = 'description'; }
        $fa = self::clean($ai['featured_alt'] ?? '', 150);
        if ($fa !== '' && $a->featured_image_url && (! $a->featured_image_alt || $force)) { $up['featured_image_alt'] = $fa; $changed[] = 'featured image alt'; }
        $alts = is_array($ai['image_alts'] ?? null) ? $ai['image_alts'] : [];
        if ($noAlt && $alts) {
            $n = -1;
            $new = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use (&$n, $alts, $noAlt) {
                $n++;
                if (! array_key_exists($n, $noAlt)) return $m[0];
                $alt = self::clean($alts[(string) $n] ?? ($alts[$n] ?? ''), 150);
                if ($alt === '') return $m[0];
                $tag = preg_replace('/\balt\s*=\s*"[^"]*"/i', '', $m[0]);
                return preg_replace('#^<img\b#i', '<img alt="' . e($alt) . '"', $tag);
            }, $html);
            if ($new !== $html) { $up['content'] = $new; $changed[] = 'image alt text'; }
        }
        $ld = $this->articleSchema($a, $w, $biz, $faq, $up['meta_description'] ?? $a->meta_description);
        if ($ld && ($force || ! $a->jsonld_json || ! str_contains((string) $a->jsonld_json, '"Article"') || ($faq && ! str_contains((string) $a->jsonld_json, 'FAQPage')))) { $up['jsonld_json'] = $ld; $changed[] = 'structured data'; }
        if ($up) {
            DB::table('articles')->where('id', $a->id)->update($up + ['updated_at' => now()]);
            try { app(\App\Engines\SEO\Services\SeoService::class)->syncFromArticle((int) $a->workspace_id, DB::table('articles')->find($a->id)); } catch (\Throwable $e) {}
            if ($w) try { app(\App\Engines\Publisher\Services\DeskService::class)->invalidate((int) $w->id, (int) $a->id); } catch (\Throwable $e) {}
        }
        Log::info('[PAGE-ONE-1] on-page article', ['article' => $a->id, 'changed' => $changed]);
        return ['changed' => $changed, 'target' => $target];
    }

    /** A builder page: title, description, structured data. The page's own words are left alone. */
    public function page(int $pageId, bool $force = false): array
    {
        $p = DB::table('pages')->where('id', $pageId)->first();
        if (! $p) return ['changed' => [], 'target' => null];
        $w = DB::table('websites')->where('id', $p->website_id)->first();
        $biz = $w ? SearchSites::business($w) : null;
        $sections = json_decode((string) $p->sections_json, true) ?: [];
        $words = [];
        array_walk_recursive($sections, function ($v, $k) use (&$words) { if (is_string($v) && ! preg_match('#^(https?:|/|\#|[a-z_]+$)#i', $v) && mb_strlen($v) > 3) $words[] = strip_tags($v); });
        $text = mb_substr(trim(preg_replace('/\s+/', ' ', implode(' ', $words))), 0, 1500);
        $up = []; $changed = []; $target = null; $kind = null;
        if ($force || ! $p->meta_title || ! $p->meta_description || ! $p->jsonld_json) {
            $sys = 'You are a senior SEO specialist writing the title and description of one page on a small business website. '
                . 'meta_title <= 60 characters: what the page offers and the place for a local business, the main search phrase near the start, no brand suffix. '
                . 'meta_description 140-158 characters with one concrete detail and a soft call to act. target_query: the search this page should be found for (lowercase, 2-6 words). page_kind: service (a service or product the business sells), about, contact, blog (a list of posts), gallery, legal, or other. '
                . 'Return ONLY JSON {"target_query":"","meta_title":"","meta_description":"","page_kind":""}';
            $user = json_encode(['business' => ['name' => $biz->name ?? ($w->name ?? null), 'industry' => $biz->industry ?? null, 'place' => SearchSites::place($biz), 'services' => json_decode((string) ($biz->services_json ?? 'null'), true)],
                'page' => ['title' => $p->title, 'slug' => $p->slug, 'is_home' => (bool) $p->is_homepage || $p->slug === 'home'], 'page_text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $r = app(\App\Connectors\RuntimeClient::class)->chatJson($sys, 'JSON input: ' . $user, ['task' => 'seo_onpage', 'workspace_id' => (string) ($w->workspace_id ?? 0)], 400);
            $ai = ($r['success'] ?? false) && is_array($r['parsed'] ?? null) ? $r['parsed'] : [];
            $mt = self::clean($ai['meta_title'] ?? '', 70); $md = self::clean($ai['meta_description'] ?? '', 175); $target = self::clean($ai['target_query'] ?? '', 120) ?: null; $kind = strtolower(self::clean($ai['page_kind'] ?? '', 20)) ?: null;
            if ($mt !== '' && mb_strlen($mt) <= 65 && ($force || ! $p->meta_title)) { $up['meta_title'] = $mt; $changed[] = 'title'; }
            if ($md !== '' && mb_strlen($md) >= 90 && ($force || ! $p->meta_description)) { $up['meta_description'] = $md; $changed[] = 'description'; }
        }
        if ($force || ! $p->jsonld_json) {
            $ld = $this->pageSchema($p, $w, $biz, $up['meta_description'] ?? $p->meta_description, $kind);
            if ($ld) { $up['jsonld_json'] = $ld; $changed[] = 'structured data'; }
        }
        if ($up) {
            DB::table('pages')->where('id', $p->id)->update($up + ['updated_at' => now()]);
            if ($w && $w->subdomain) { $sub = str_replace('.levelupgrowth.io', '', (string) $w->subdomain); Cache::forget("published_site:{$sub}:{$p->slug}"); if ($p->slug === 'home') Cache::forget("published_site:{$sub}:home"); }
        }
        Log::info('[PAGE-ONE-1] on-page page', ['page' => $p->id, 'changed' => $changed]);
        return ['changed' => $changed, 'target' => $target];
    }

    /** Question-and-answer pairs from an FAQ section of the article (H2 "Frequently asked…" followed by H3 questions). */
    public function faqFrom(string $html): array
    {
        if (! preg_match('#<h2[^>]*>[^<]*(frequently asked|faq|questions)[^<]*</h2>(.*?)(?=<h2|$)#is', $html, $m)) return [];
        preg_match_all('#<h3[^>]*>(.*?)</h3>\s*(.*?)(?=<h3|$)#is', $m[2], $qa, PREG_SET_ORDER);
        $out = [];
        foreach ($qa as $x) {
            $q = trim(html_entity_decode(strip_tags($x[1]))); $ans = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($x[2]))));
            if ($q !== '' && $ans !== '') $out[] = ['q' => mb_substr($q, 0, 200), 'a' => mb_substr($ans, 0, 700)];
        }
        return array_slice($out, 0, 8);
    }

    private function articleSchema(object $a, ?object $w, ?object $biz, array $faq, ?string $desc): ?string
    {
        $host = $w ? SearchSites::host($w) : null;
        $url = null;
        if ($host) foreach (SearchSites::urls($w) as $u => $x) { if ($x['kind'] === 'article' && (int) $x['ref_id'] === (int) $a->id) { $url = $u; break; } }
        $org = array_filter(['@type' => 'Organization', 'name' => $biz->name ?? ($w->name ?? null), 'url' => $host ? 'https://' . $host . '/' : null, 'logo' => ! empty($biz->logo_url) ? $biz->logo_url : null]);
        $graph = [array_filter([
            '@type' => 'Article', 'headline' => mb_substr((string) $a->title, 0, 110), 'description' => $desc ?: null,
            'image' => $a->featured_image_url ? [$a->featured_image_url] : null, 'datePublished' => $a->published_at ? date('c', strtotime($a->published_at)) : null,
            'dateModified' => date('c', strtotime($a->updated_at ?: 'now')), 'author' => $org ?: null, 'publisher' => $org ?: null,
            'mainEntityOfPage' => $url ? ['@type' => 'WebPage', '@id' => $url] : null, 'keywords' => $a->focus_keyword ?: null,
        ])];
        if (count($faq) >= 2) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq)];
        return json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function pageSchema(object $p, ?object $w, ?object $biz, ?string $desc, ?string $kind = null): ?string
    {
        $host = $w ? SearchSites::host($w) : null;
        if (! $host) return null;
        $url = 'https://' . $host . '/' . ($p->slug === 'home' ? '' : $p->slug);
        $name = $biz->name ?? $w->name;
        $addr = json_decode((string) ($biz->address_json ?? 'null'), true);
        $isHome = (bool) $p->is_homepage || $p->slug === 'home';
        $services = json_decode((string) ($biz->services_json ?? 'null'), true) ?: [];
        $isService = ! $isHome && ($kind === 'service' || preg_match('/service|menu|pricing|package|catering|class|treatment|product/i', $p->slug . ' ' . $p->title) || in_array(strtolower((string) $p->title), array_map('strtolower', $services), true));
        $local = array_filter(['@type' => 'LocalBusiness', 'name' => $name, 'url' => 'https://' . $host . '/', 'telephone' => $biz->phone ?? null, 'email' => $biz->email ?? null,
            'address' => is_array($addr) && $addr ? ['@type' => 'PostalAddress'] + $addr : null, 'areaServed' => SearchSites::place($biz) ?: null, 'image' => ! empty($biz->logo_url) ? $biz->logo_url : null,
            'description' => $isHome ? ($desc ?: null) : null]);
        $node = $isHome ? $local : ($isService
            ? array_filter(['@type' => 'Service', 'name' => $p->title, 'description' => $desc ?: null, 'provider' => $local, 'areaServed' => SearchSites::place($biz) ?: null, 'url' => $url])
            : array_filter(['@type' => 'WebPage', 'name' => $p->title, 'description' => $desc ?: null, 'url' => $url, 'isPartOf' => ['@type' => 'WebSite', 'name' => $name, 'url' => 'https://' . $host . '/']]));
        return json_encode(['@context' => 'https://schema.org'] + $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function clean($v, int $n): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags(is_scalar($v) ? (string) $v : '')), " \"'");
        return mb_substr($s, 0, $n);
    }
}
