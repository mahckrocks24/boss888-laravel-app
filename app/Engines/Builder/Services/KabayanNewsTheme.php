<?php

namespace App\Engines\Builder\Services;

use App\Http\Controllers\Api\Widget\ImageVariantController as Img;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KABAYAN888 G1 + UX-1 (2026-09-03) — "kabayan-news" magazine theme, v2: enterprise, mobile-first.
 *
 * Renderer-path theme selected by websites.settings_json.theme = 'kabayan-news' (ThemeRegistry).
 * Hydrated from pages.sections_json + articles at render time: Arthur edits presentation
 * (ArthurEditService → SectionSchema), Sarah owns content. Read-only, no writes.
 *
 * v2 patterns (see research/06-worldclass-news-ux.md): compact sticky header with drawer +
 * search, horizontal section chips, lead story + compact thumb-right list rows on phones,
 * reading progress + share row + NewsArticle JSON-LD on articles, bottom app-style navigation,
 * WhatsApp/Telegram capture, responsive WebP variants via /api/public/img, dark mode tokens,
 * skip link + 44px targets + focus rings, reserved ad positions.
 *
 * Contract: renderBody($secs,$brand,$website,$page,?callable $fallback), renderArticle($article,$website),
 * headExtras($website,$page=null,$article=null).
 */
class KabayanNewsTheme
{
    private array $settings = [];
    private array $brand = [];
    private array $site = [];
    private string $base = 'blog';
    private array $catNames = [];

    private function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private function url(string $u, string $fallback = '#'): string
    {
        $u = trim($u);
        if ($u === '') return $fallback;
        if (preg_match('#^(https?:)?//#i', $u) || str_starts_with($u, '/') || str_starts_with($u, '#') || str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:')) {
            return $this->e($u);
        }
        return $fallback;
    }

    private function color(?string $c, string $default): string
    {
        $c = trim((string) $c);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $default;
    }

    private function font(?string $f, string $default): string
    {
        $f = trim((string) $f);
        return preg_match('/^[a-zA-Z0-9 _-]{1,50}$/', $f) ? $f : $default;
    }

    private function boot(array $brand, array $website): void
    {
        $s = $website['settings_json'] ?? [];
        if (is_string($s)) $s = json_decode($s, true) ?: [];
        $this->settings = is_array($s) ? $s : [];
        $this->site = $website;
        $this->brand = [
            'primary'   => $this->color($brand['primary'] ?? $brand['primary_color'] ?? null, '#0038A8'),
            'secondary' => $this->color($brand['secondary'] ?? $brand['secondary_color'] ?? null, '#1A1A2E'),
            'accent'    => $this->color($brand['accent'] ?? $brand['accent_color'] ?? null, '#FCD116'),
            'alert'     => $this->color($this->settings['alert_color'] ?? null, '#CE1126'),
            'fh'        => $this->font($brand['font_heading'] ?? $brand['heading_font'] ?? null, 'Playfair Display'),
            'fb'        => $this->font($brand['font_body'] ?? $brand['body_font'] ?? null, 'Inter'),
        ];
        $base = strtolower(trim((string) ($this->settings['article_base'] ?? 'blog')));
        $this->base = preg_match('/^[a-z0-9\-]{1,40}$/', $base) ? $base : 'blog';
    }

    private function find(array $secs, string $type): ?array
    {
        foreach ($secs as $s) if (is_array($s) && ($s['type'] ?? '') === $type) return $s;
        return null;
    }

    private function sub(): string { return str_replace('.levelupgrowth.io', '', (string) ($this->site['subdomain'] ?? '')); }

    private function head(): string
    {
        $css = @file_get_contents(storage_path('app/kabayan/kabayan-theme.css')) ?: '';
        $b = $this->brand;
        $vars = ":root{--kb-primary:{$b['primary']};--kb-secondary:{$b['secondary']};--kb-accent:{$b['accent']};--kb-alert:{$b['alert']};--kb-fh:'{$b['fh']}',Georgia,serif;--kb-fb:'{$b['fb']}',system-ui,-apple-system,'Segoe UI',sans-serif}";
        $fonts = '<link href="https://fonts.googleapis.com/css2?family=' . rawurlencode($b['fh']) . ':ital,wght@0,500;0,700;0,900;1,500&family=' . rawurlencode($b['fb']) . ':wght@400;500;600;700&display=swap" rel="stylesheet">';
        return $fonts . "\n<style>" . $vars . "\n" . $css . "</style>\n";
    }

    // ─── head extras (BuilderRenderer UX-1 hook) ───────────────────────────
    public function headExtras(array $website, ?array $page = null, ?array $article = null): string
    {
        $this->boot([], $website);
        $s = $this->settings;
        $primary = $this->color($s['primary_color'] ?? null, '#0038A8');
        $secondary = $this->color($s['secondary_color'] ?? null, '#1A1A2E');
        $out = '<meta name="theme-color" content="' . $this->e($secondary) . '">' . "\n"
             . '<meta name="color-scheme" content="light dark">' . "\n"
             . '<meta name="apple-mobile-web-app-title" content="' . $this->e((string) ($website['name'] ?? 'Kabayan')) . '">' . "\n"
             . '<meta name="format-detection" content="telephone=no">' . "\n"
             . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        if ($article) {
            $site = 'https://' . $this->sub() . '.levelupgrowth.io';
            $url = $site . '/' . $this->base . '/' . (string) ($article['slug'] ?? '');
            $brief = $article['brief_json'] ?? null; if (is_string($brief)) $brief = json_decode($brief, true); $brief = is_array($brief) ? $brief : [];
            $img = (string) ($article['featured_image_url'] ?? '');
            $ld = [
                '@context' => 'https://schema.org', '@type' => 'NewsArticle',
                'headline' => mb_substr((string) ($article['title'] ?? ''), 0, 110),
                'description' => (string) ($article['excerpt'] ?? $article['meta_description'] ?? ''),
                'datePublished' => !empty($article['published_at']) ? \Carbon\Carbon::parse($article['published_at'])->toIso8601String() : null,
                'dateModified' => !empty($article['updated_at']) ? \Carbon\Carbon::parse($article['updated_at'])->toIso8601String() : null,
                'author' => [['@type' => 'Organization', 'name' => (string) ($brief['author'] ?? ($website['name'] ?? 'Desk'))]],
                'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => (string) ($website['name'] ?? 'Kabayan'), 'url' => $site],
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
                'articleSection' => (string) ($article['blog_category'] ?? ''),
                'inLanguage' => (string) ($s['locale'] ?? 'en'),
                'isAccessibleForFree' => true,
            ];
            if ($img !== '') $ld['image'] = [$img];
            $ld = array_filter($ld, fn($v) => $v !== null && $v !== '' && $v !== []);
            $out .= '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
        }
        return $out;
    }

    // ─── public contract ────────────────────────────────────────────────────

    public function renderBody(array $secs, array $brand, array $website, array $page, ?callable $fallback = null): string
    {
        $this->boot($brand, $website);
        $slug = (string) ($page['slug'] ?? 'home');
        $h = $this->find($secs, 'header');
        $f = $this->find($secs, 'footer');
        $body = '';
        foreach ($secs as $sec) {
            if (!is_array($sec)) continue;
            $t = $sec['type'] ?? '';
            if ($t === 'header' || $t === 'footer') continue;
            try { $body .= $this->dispatch($sec, $website, $fallback); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[KabayanNewsTheme] section failed', ['type' => $t, 'error' => $e->getMessage()]); }
        }
        return $this->head() . $this->chrome($h, $f, $website, $slug, false)
            . "\n<main id=\"kb-main\" class=\"kb-main\" tabindex=\"-1\">\n" . $body . "\n</main>\n"
            . $this->footer($f, $website) . $this->bottomNav($slug) . $this->overlays($h, $website, $slug) . $this->js(false);
    }

    public function renderArticle(array $article, array $website): string
    {
        $brand = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve((int) ($website['workspace_id'] ?? 0));
        $s = $website['settings_json'] ?? []; if (is_string($s)) $s = json_decode($s, true) ?: [];
        $tokens = ['primary' => $s['primary_color'] ?? ($brand['primary_color'] ?? null), 'secondary' => $s['secondary_color'] ?? ($brand['secondary_color'] ?? null),
            'accent' => $s['accent_color'] ?? ($brand['accent_color'] ?? null), 'font_heading' => $s['font_heading'] ?? ($brand['heading_font'] ?? null), 'font_body' => $s['font_body'] ?? ($brand['body_font'] ?? null)];
        $this->boot($tokens, $website);
        $home = DB::table('pages')->where('website_id', (int) ($website['id'] ?? 0))->where('is_homepage', 1)->first(['sections_json']);
        $homeSecs = [];
        if ($home) { $hs = is_string($home->sections_json) ? json_decode($home->sections_json, true) : (array) $home->sections_json; $homeSecs = $hs['sections'] ?? (is_array($hs) ? $hs : []); }
        $h = $this->find($homeSecs, 'header'); $f = $this->find($homeSecs, 'footer');

        $title = $this->e($article['title'] ?? 'Story');
        $cat = (string) ($article['blog_category'] ?? '');
        $catSlug = $this->slugify($cat);
        $catLabel = $cat !== '' ? $this->catName($cat, $website) : 'News';
        $when = !empty($article['published_at']) ? \Carbon\Carbon::parse($article['published_at']) : null;
        $upd = !empty($article['updated_at']) ? \Carbon\Carbon::parse($article['updated_at']) : null;
        $brief = $article['brief_json'] ?? null; if (is_string($brief)) $brief = json_decode($brief, true); $brief = is_array($brief) ? $brief : [];
        $author = $this->e((string) ($brief['author'] ?? 'LevelUp Kabayan Desk'));
        $read = (int) ($article['read_time'] ?? 0) ?: max(1, (int) round(((int) ($article['word_count'] ?? 0)) / 200));
        $imgUrl = (string) ($article['featured_image_url'] ?? '');
        $alt = (string) ($article['featured_image_alt'] ?? $article['title'] ?? '');
        $excerpt = $this->e((string) ($article['excerpt'] ?? ''));
        $content = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#is', '', (string) ($article['content'] ?? ''), 1) ?? (string) ($article['content'] ?? '');
        $content = $this->contentToHtml($content);
        $tags = $article['tags_json'] ?? null; if (is_string($tags)) $tags = json_decode($tags, true); $tags = is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
        $sponsored = in_array('sponsored', array_map('strtolower', $tags), true);
        $kicker = $sponsored ? $this->e((string) ($this->settings['disclosure_label'] ?? 'Sponsored')) : $this->e($catLabel);
        $kickerCls = $sponsored ? ' kb-kicker--sponsored' : '';
        $pageUrl = 'https://' . $this->sub() . '.levelupgrowth.io/' . $this->base . '/' . (string) ($article['slug'] ?? '');

        $sources = '';
        if (!empty($brief['sources']) && is_array($brief['sources'])) {
            $li = '';
            foreach ($brief['sources'] as $src) {
                if (!is_array($src)) continue;
                $su = $this->url((string) ($src['url'] ?? ''), ''); $st = $this->e((string) ($src['title'] ?? $src['url'] ?? ''));
                if ($st === '') continue;
                $li .= $su !== '' ? "<li><a href=\"{$su}\" rel=\"nofollow noopener\" target=\"_blank\">{$st}</a></li>" : "<li>{$st}</li>";
            }
            if ($li !== '') $sources = "<aside class=\"kb-sources\" aria-label=\"Sources\"><div class=\"kb-eyebrow\">Sources</div><ul>{$li}</ul></aside>";
        }
        $tagHtml = '';
        foreach ($tags as $t) { if (strtolower($t) === 'sponsored' || $t === '') continue; $tagHtml .= '<span>#' . $this->e($t) . '</span>'; }
        if ($tagHtml !== '') $tagHtml = "<div class=\"kb-tags\" aria-label=\"Topics\">{$tagHtml}</div>";
        // Caption only from an explicit caption/credit (featured_image_alt often holds the generation prompt).
        $caption = trim((string) ($brief['image_caption'] ?? ''));
        $credit  = trim((string) ($brief['image_credit'] ?? ''));
        if ($caption === '' && $credit === '' && !empty($brief['ai_illustration'])) $credit = 'AI-generated illustration';
        $capHtml = ($caption !== '' || $credit !== '') ? "<figcaption>" . $this->e($caption) . ($credit !== '' ? ($caption !== '' ? ' · ' : '') . "<span class=\"kb-credit\">" . $this->e($credit) . "</span>" : '') . "</figcaption>" : '';
        $hero = $imgUrl !== '' ? "<figure class=\"kb-art-hero\">" . $this->img($imgUrl, $alt, 'hero') . $capHtml . "</figure>" : '';
        $dateHtml = $when ? "<span><time datetime=\"" . $this->e($when->toIso8601String()) . "\">" . $this->e($when->format('j F Y, g:i a')) . "</time></span>" : '';
        if ($upd && $when && $upd->gt($when->copy()->addHour())) $dateHtml .= "<span>Updated " . $this->e($upd->format('j M, g:i a')) . "</span>";
        $share = $this->shareRow($pageUrl, (string) ($article['title'] ?? ''));
        $related = $this->newsFeed(['eyebrow' => 'Read next', 'heading' => 'More in ' . $catLabel, 'layout' => 'list', 'category' => $catSlug !== '' ? $cat : '', 'limit' => 4, 'show_excerpt' => false], $website, (int) ($article['id'] ?? 0));
        if ($related === '') $related = $this->newsFeed(['eyebrow' => 'Read next', 'heading' => 'Latest stories', 'layout' => 'list', 'limit' => 4, 'show_excerpt' => false], $website, (int) ($article['id'] ?? 0));
        $catLink = $catSlug !== '' ? " <span>›</span> <a href=\"/{$this->e($catSlug)}\">" . $this->e($catLabel) . "</a>" : '';
        $main = <<<HTML
<article class="kb-article" data-kb-article="1">
  <div class="kb-wrap kb-art-head">
    <nav class="kb-crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>›</span> <a href="/{$this->e($this->base)}">News</a>{$catLink}</nav>
    <div class="kb-kicker{$kickerCls}">{$kicker}</div>
    <h1>{$title}</h1>
    <p class="kb-art-dek">{$excerpt}</p>
    <div class="kb-byline"><span class="kb-author">{$author}</span>{$dateHtml}<span>{$read} min read</span></div>
    {$share}
  </div>
  {$hero}
  <div class="kb-wrap kb-art-body">
    <div class="kb-prose">{$content}</div>
    {$sources}
    {$tagHtml}
    <div class="lu-ad-inline" data-lu-slot="in_content_mrec" hidden aria-hidden="true"></div>
    <a class="kb-back" href="/{$this->e($this->base)}">← All stories</a>
  </div>
</article>
{$related}
HTML;
        return $this->head() . $this->chrome($h, $f, $website, 'article', true)
            . "\n<main id=\"kb-main\" class=\"kb-main\" tabindex=\"-1\">\n" . $main . "\n</main>\n"
            . $this->footer($f, $website) . $this->bottomNav('article') . $this->overlays($h, $website, 'article') . $this->js(true);
    }

    // ─── dispatch ───────────────────────────────────────────────────────────

    private function dispatch(array $sec, array $website, ?callable $fallback): string
    {
        switch ($sec['type'] ?? '') {
            case 'ticker':            return $this->ticker($sec, $website);
            case 'news_feed':         return $this->newsFeed($sec, $website);
            case 'category_strips':   return $this->categoryStrips($sec, $website);
            case 'hero':              return $this->pageHero($sec);
            case 'directory':         return $this->directory($sec, $website);
            case 'newsletter_signup': return $this->newsletter($sec, $website);
            case 'ad_slot':           return $this->adSlot($sec, $website);
            default:
                if ($fallback === null) return '';
                $html = (string) $fallback($sec);
                return $html !== '' ? "<div class=\"kb-generic\">{$html}</div>" : '';
        }
    }

    // ─── data ───────────────────────────────────────────────────────────────

    private function articles(array $website, array $o = [], int $excludeId = 0)
    {
        $wsId = (int) ($website['workspace_id'] ?? 0); $wid = (int) ($website['id'] ?? 0);
        $q = DB::table('articles')->where('workspace_id', $wsId)
            ->where(function ($q) use ($wid) { if ($wid > 0) { $q->where('website_id', $wid)->orWhereNull('website_id'); } })
            ->where('is_marketing_blog', 1)->where('status', 'published')->whereNull('deleted_at');
        $cat = trim((string) ($o['category'] ?? ''));
        if ($cat !== '' && strtolower($cat) !== 'all') {
            $q->where(function ($w) use ($cat) {
                $w->where('blog_category', $cat)->orWhere('blog_category', str_replace('-', ' ', $cat))
                  ->orWhereRaw('LOWER(REPLACE(blog_category, " ", "-")) = ?', [strtolower($cat)]);
            });
        }
        $tag = trim((string) ($o['tag'] ?? ''));
        if ($tag !== '') $q->whereRaw('JSON_SEARCH(tags_json, "one", ?) IS NOT NULL', [$tag]);
        if ($excludeId > 0) $q->where('id', '!=', $excludeId);
        $offset = max(0, (int) ($o['offset'] ?? 0)) + (!empty($o['exclude_featured']) ? 1 : 0);
        return $q->orderByDesc('published_at')->orderByDesc('id')->offset($offset)->limit(max(1, min(48, (int) ($o['limit'] ?? 12))))
            ->get(['id', 'title', 'slug', 'excerpt', 'blog_category', 'featured_image_url', 'featured_image_alt', 'read_time', 'word_count', 'published_at', 'brief_json', 'tags_json']);
    }

    private function slugify(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($s))) ?? '', '-'); }

    private function catName(string $stored, array $website): string
    {
        $stored = trim($stored); if ($stored === '') return '';
        $wsId = (int) ($website['workspace_id'] ?? 0);
        if (!isset($this->catNames[$wsId])) {
            $this->catNames[$wsId] = [];
            try { foreach (DB::table('blog_categories')->where('workspace_id', $wsId)->get(['name', 'slug']) as $c) { $this->catNames[$wsId][strtolower((string) $c->slug)] = (string) $c->name; $this->catNames[$wsId][strtolower((string) $c->name)] = (string) $c->name; } } catch (\Throwable) {}
        }
        return $this->catNames[$wsId][strtolower($stored)] ?? ucwords(str_replace('-', ' ', $stored));
    }

    private function readMin(object $a): int { $rt = (int) ($a->read_time ?? 0); return $rt > 0 ? $rt : max(1, (int) round(((int) ($a->word_count ?? 0)) / 200)); }

    private function author(object $a): string { $b = $a->brief_json ?? null; if (is_string($b)) $b = json_decode($b, true); return is_array($b) ? (string) ($b['author'] ?? '') : ''; }

    /** Responsive image: platform-hosted originals get WebP variants + srcset; foreign URLs pass through. */
    private function img(string $url, string $alt, string $kind = 'card'): string
    {
        $url = trim($url);
        if ($url === '' || $this->url($url, '') === '') return '<div class="kb-ph" role="img" aria-label="' . $this->e($alt) . '"></div>';
        $alt = $this->e($alt);
        $eager = $kind === 'lead' || $kind === 'hero';
        $sizes = match ($kind) {
            'hero'  => '(max-width: 599px) 100vw, (max-width: 1199px) calc(100vw - 48px), 1132px',
            'lead'  => '(max-width: 599px) calc(100vw - 32px), (max-width: 899px) calc(100vw - 48px), 640px',
            'thumb' => '(max-width: 599px) 108px, (max-width: 899px) 30vw, 380px',
            default => '(max-width: 599px) calc(100vw - 32px), (max-width: 899px) 45vw, 380px',
        };
        $main = Img::variantUrl($url, $kind === 'hero' ? 1200 : ($kind === 'thumb' ? 480 : 800));
        $srcset = Img::srcset($url, $kind === 'thumb' ? [320, 480, 800] : [480, 800, 1200, 1600]);
        $attrs = $srcset !== '' ? " srcset=\"{$this->e($srcset)}\" sizes=\"{$sizes}\"" : '';
        $load = $eager ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"';
        $dims = $kind === 'thumb' ? ' width="480" height="480"' : ' width="800" height="500"';
        return "<img src=\"{$this->e($main)}\"{$attrs} alt=\"{$alt}\"{$dims}{$load}>";
    }

    private function card(object $a, string $variant = 'card', bool $excerpt = true, bool $byline = true): string
    {
        $t = $this->e($a->title);
        $u = '/' . $this->e($this->base) . '/' . $this->e($a->slug);
        $cat = (string) ($a->blog_category ?? '');
        $catHtml = $cat !== '' ? "<span class=\"kb-cat\">" . $this->e($this->catName($cat, $this->site)) . "</span>" : '';
        $kind = $variant === 'lead' ? 'lead' : (in_array($variant, ['row', 'mini'], true) ? 'thumb' : 'card');
        $imgHtml = $this->img((string) ($a->featured_image_url ?? ''), (string) ($a->featured_image_alt ?? $a->title), $kind);
        $ex = $excerpt && !empty($a->excerpt) ? '<p class="kb-ex">' . $this->e(mb_substr((string) $a->excerpt, 0, 200)) . '</p>' : '';
        $when = !empty($a->published_at) ? \Carbon\Carbon::parse($a->published_at) : null;
        $whenTxt = $when ? ($when->isToday() ? $when->diffForHumans(null, true) . ' ago' : $when->format('j M Y')) : '';
        $au = $byline && $this->author($a) !== '' ? '<span class="kb-author">' . $this->e($this->author($a)) . '</span>' : '';
        $meta = "<div class=\"kb-meta\">{$au}" . ($whenTxt !== '' ? "<span><time datetime=\"" . $this->e($when->toIso8601String()) . "\">" . $this->e($whenTxt) . "</time></span>" : '') . "<span>" . $this->readMin($a) . " min</span></div>";
        return "<a class=\"kb-card kb-card--{$variant}\" href=\"{$u}\"><div class=\"kb-card-img\">{$imgHtml}</div><div class=\"kb-card-body\">{$catHtml}<h3>{$t}</h3>{$ex}{$meta}</div></a>";
    }

    private function sectionHead(array $sec, string $tagH = 'h2'): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? '')); $heading = $this->e((string) ($sec['heading'] ?? '')); $sub = $this->e((string) ($sec['subheading'] ?? ''));
        if ($eyebrow === '' && $heading === '' && $sub === '') return '';
        return "<div class=\"kb-sec-head\">" . ($eyebrow !== '' ? "<div class=\"kb-eyebrow\">{$eyebrow}</div>" : '') . ($heading !== '' ? "<{$tagH} class=\"kb-sec-title\">{$heading}</{$tagH}>" : '') . ($sub !== '' ? "<p class=\"kb-sec-sub\">{$sub}</p>" : '') . "</div>";
    }

    // ─── chrome: header, chips, drawer, search, bottom nav, footer ─────────

    private function navLinks(?array $h, array $website): array
    {
        $links = [];
        if (!empty($h['nav_links']) && is_array($h['nav_links'])) {
            foreach ($h['nav_links'] as $nl) {
                if (is_string($nl)) { $links[] = ['label' => $nl, 'url' => '/' . $this->slugify($nl)]; continue; }
                if (!is_array($nl)) continue;
                $lbl = trim((string) ($nl['label'] ?? '')); if ($lbl === '') continue;
                $u = (string) ($nl['url'] ?? $nl['href'] ?? '');
                $links[] = ['label' => $lbl, 'url' => $u !== '' ? $u : '/' . $this->slugify($lbl)];
            }
        } else {
            $pages = DB::table('pages')->where('website_id', (int) ($website['id'] ?? 0))->where('status', 'published')->orderBy('position')->get(['slug', 'title']);
            foreach ($pages as $p) { if (in_array($p->slug, ['home', 'privacy', 'terms', 'editorial-policy', 'about', 'contact', 'advertise'], true)) continue; $links[] = ['label' => (string) $p->title, 'url' => '/' . $p->slug]; }
        }
        return $links;
    }

    private function chrome(?array $h, ?array $f, array $website, string $current, bool $progress): string
    {
        $name = $this->e((string) (($h['logo_text'] ?? '') !== '' ? $h['logo_text'] : ($website['name'] ?? 'Kabayan')));
        $logo = $this->url((string) ($h['logo_url'] ?? ''), '');
        $plate = $logo !== '' ? "<img src=\"{$logo}\" alt=\"{$name}\" class=\"kb-logo\" width=\"160\" height=\"46\">" : "<span class=\"kb-plate-text\">{$name}</span>";
        $chips = '';
        foreach ($this->navLinks($h, $website) as $l) {
            $slug = trim((string) $l['url'], '/');
            $cls = ($slug === $current || ($slug === '' && $current === 'home')) ? ' class="is-active" aria-current="page"' : '';
            $chips .= "<li><a{$cls} href=\"" . $this->url((string) $l['url'], '#') . "\">" . $this->e($l['label']) . "</a></li>";
        }
        $cta = !empty($h['cta_text']) ? "<a class=\"kb-btn kb-btn--primary\" href=\"" . $this->url((string) ($h['cta_url'] ?? '#newsletter'), '#newsletter') . "\">" . $this->e((string) $h['cta_text']) . "</a>" : '';
        $date = $this->e(now()->setTimezone('Asia/Dubai')->format('l, j F Y'));
        $tag = $this->e((string) ($this->settings['tagline'] ?? 'Stories of Filipinos rising, wherever they are'));
        $prog = $progress ? '<div class="kb-progress" id="kb-progress" aria-hidden="true"></div>' : '';
        return <<<HTML
<a class="kb-skip" href="#kb-main">Skip to content</a>
<header class="kb-header" id="kb-header">
  <div class="kb-date">{$date} · Dubai</div>
  <div class="kb-wrap kb-bar">
    <button class="kb-icon-btn kb-menu-btn" type="button" aria-label="Open menu" aria-controls="kb-drawer" aria-expanded="false" data-kb="menu">{$this->icon('menu')}</button>
    <a href="/" class="kb-plate-link" aria-label="{$name} home">{$plate}</a>
    <div class="kb-tag">{$tag}</div>
    <div class="kb-bar-right">{$cta}<button class="kb-icon-btn" type="button" aria-label="Search" aria-controls="kb-search" aria-expanded="false" data-kb="search">{$this->icon('search')}</button></div>
  </div>
  <nav class="kb-chips" aria-label="Sections"><ul>{$chips}</ul></nav>
  {$prog}
</header>
HTML;
    }

    private function overlays(?array $h, array $website, string $current): string
    {
        $name = $this->e((string) ($website['name'] ?? 'Kabayan'));
        $nav = '';
        foreach ($this->navLinks($h, $website) as $l) {
            $slug = trim((string) $l['url'], '/');
            $cls = $slug === $current ? ' class="is-active"' : '';
            $nav .= "<li><a{$cls} href=\"" . $this->url((string) $l['url'], '#') . "\">" . $this->e($l['label']) . "</a></li>";
        }
        $more = '';
        foreach ([['About', '/about'], ['Editorial policy', '/editorial-policy'], ['Advertise', '/advertise'], ['Contact', '/contact']] as [$lbl, $u]) $more .= "<a href=\"{$u}\">{$lbl}</a>";
        $channels = $this->channelLinks(false);
        $sub = $this->e($this->sub());
        return <<<HTML
<div class="kb-drawer" id="kb-drawer" data-open="0" role="dialog" aria-modal="true" aria-label="Menu" hidden>
  <div class="kb-drawer-bg" data-kb="close"></div>
  <div class="kb-drawer-panel">
    <div class="kb-drawer-head"><span class="kb-plate-text" style="font-size:1.2rem">{$name}</span><button class="kb-icon-btn" type="button" aria-label="Close menu" data-kb="close">{$this->icon('close')}</button></div>
    <nav aria-label="All sections"><div class="kb-eyebrow">Sections</div><ul>{$nav}</ul></nav>
    <div class="kb-drawer-links"><div class="kb-eyebrow">About</div>{$more}</div>
    <div><div class="kb-eyebrow">Follow</div><div class="kb-social">{$channels}</div></div>
  </div>
</div>
<div class="kb-search" id="kb-search" data-open="0" role="dialog" aria-modal="true" aria-label="Search" hidden data-sub="{$sub}">
  <div class="kb-search-bar"><input type="search" id="kb-search-input" placeholder="Search stories…" aria-label="Search stories" autocomplete="off" enterkeyhint="search"><button class="kb-icon-btn" type="button" aria-label="Close search" data-kb="close">{$this->icon('close')}</button></div>
  <div class="kb-search-body"><p class="kb-search-hint">Type at least 2 letters. Searching titles and summaries.</p><div class="kb-list" id="kb-search-results" aria-live="polite"></div></div>
</div>
HTML;
    }

    private function bottomNav(string $current): string
    {
        $base = $this->e($this->base);
        $items = [
            ['Home', '/', 'home', 'home'],
            ['Latest', "/{$base}", $this->base, 'news'],
            ['Guides', '/government', 'government', 'guide'],
            ['Spots', '/spots', 'spots', 'pin'],
        ];
        $li = '';
        foreach ($items as [$lbl, $u, $slug, $ic]) {
            $cls = $slug === $current ? ' class="is-active" aria-current="page"' : '';
            $li .= "<li><a{$cls} href=\"{$u}\">{$this->icon($ic)}<span>{$lbl}</span></a></li>";
        }
        $li .= "<li><button type=\"button\" data-kb=\"search\" aria-controls=\"kb-search\">{$this->icon('search')}<span>Search</span></button></li>";
        return "<div class=\"kb-bnav-spacer\" aria-hidden=\"true\" style=\"height:calc(var(--kb-bnav-h) + env(safe-area-inset-bottom,0px))\"></div><nav class=\"kb-bnav\" aria-label=\"Quick navigation\"><ul>{$li}</ul></nav><style>@media(min-width:900px){.kb-bnav-spacer{display:none}}</style>";
    }

    private function channelLinks(bool $onDark): string
    {
        $s = $this->settings; $out = '';
        $map = [
            ['whatsapp_channel_url', 'WhatsApp', 'whatsapp'], ['telegram_url', 'Telegram', 'telegram'], ['facebook_url', 'Facebook', 'facebook'],
            ['instagram_url', 'Instagram', 'instagram'], ['tiktok_url', 'TikTok', 'tiktok'], ['youtube_url', 'YouTube', 'youtube'],
        ];
        foreach ($map as [$key, $label, $ic]) {
            $u = $this->url((string) ($s[$key] ?? ''), '');
            if ($u === '') continue;
            $out .= "<a href=\"{$u}\" rel=\"noopener\" target=\"_blank\">{$this->icon($ic)}<span>{$label}</span></a>";
        }
        return $out;
    }

    private function footer(?array $f, array $website): string
    {
        $name = $this->e((string) ($website['name'] ?? 'Kabayan')); $year = date('Y');
        $copy = $this->e((string) (($f['copyright'] ?? '') !== '' ? $f['copyright'] : "© {$year} {$name}."));
        $cols = '';
        if (!empty($f['columns']) && is_array($f['columns'])) {
            foreach ($f['columns'] as $c) {
                if (!is_array($c)) continue; $li = '';
                foreach ((array) ($c['links'] ?? []) as $l) { if (!is_array($l) || trim((string) ($l['label'] ?? '')) === '') continue; $li .= "<li><a href=\"" . $this->url((string) ($l['url'] ?? '#'), '#') . "\">" . $this->e($l['label']) . "</a></li>"; }
                $cols .= "<div class=\"kb-fcol\"><div class=\"kb-eyebrow\">" . $this->e((string) ($c['heading'] ?? '')) . "</div><ul>{$li}</ul></div>";
            }
        }
        $social = '';
        if (!empty($f['social_links']) && is_array($f['social_links'])) {
            foreach ($f['social_links'] as $k => $v) { $v = is_array($v) ? (string) ($v['url'] ?? '') : (string) $v; if (trim($v) === '') continue; $social .= "<a href=\"" . $this->url($v, '#') . "\" rel=\"noopener\" target=\"_blank\">" . $this->e(ucfirst((string) $k)) . "</a>"; }
        }
        $contact = '';
        foreach (['email' => 'mailto:', 'phone' => 'tel:'] as $k => $scheme) if (!empty($f[$k])) $contact .= "<a href=\"{$scheme}" . $this->e((string) $f[$k]) . "\">" . $this->e((string) $f[$k]) . "</a>";
        if (!empty($f['address'])) $contact .= "<span>" . $this->e((string) $f['address']) . "</span>";
        return <<<HTML
<footer class="kb-footer">
  <div class="kb-wrap kb-fgrid">
    <div class="kb-fbrand"><div class="kb-plate-text kb-plate-text--sm">{$name}</div><div class="kb-fcontact">{$contact}</div><div class="kb-fsocial">{$social}</div></div>
    {$cols}
  </div>
  <div class="kb-wrap kb-fbottom"><span>{$copy}</span><span>Published with LevelUp Growth</span></div>
</footer>
HTML;
    }

    // ─── sections ───────────────────────────────────────────────────────────

    private function ticker(array $sec, array $website): string
    {
        $label = $this->e((string) ($sec['label'] ?? $this->settings['ticker_label'] ?? 'BREAKING'));
        $items = [];
        if (($sec['mode'] ?? 'latest') === 'manual' && is_array($sec['items'] ?? null)) {
            foreach ($sec['items'] as $it) { if (!is_array($it) || trim((string) ($it['text'] ?? '')) === '') continue; $items[] = "<a href=\"" . $this->url((string) ($it['url'] ?? ''), '#') . "\">" . $this->e($it['text']) . "</a>"; }
        } else {
            foreach ($this->articles($website, ['limit' => (int) ($sec['limit'] ?? 6), 'category' => $sec['category'] ?? '']) as $a) $items[] = "<a href=\"/" . $this->e($this->base) . "/" . $this->e($a->slug) . "\">" . $this->e($a->title) . "</a>";
        }
        if ($items === []) return '';
        $run = implode('', $items); $speed = max(15, min(120, (int) ($sec['speed'] ?? 45)));
        return "<div class=\"kb-ticker\" role=\"region\" aria-label=\"Latest headlines\"><div class=\"kb-wrap kb-ticker-in\"><span class=\"kb-ticker-kick\">{$label}</span><div class=\"kb-ticker-track\"><div class=\"kb-ticker-run\" style=\"animation-duration:{$speed}s\">{$run}{$run}</div></div></div></div>";
    }

    private function newsFeed(array $sec, array $website, int $excludeId = 0): string
    {
        $layout = (string) ($sec['layout'] ?? 'cards');
        $limit = (int) ($sec['limit'] ?? 12);
        $rows = $this->articles($website, ['limit' => $limit, 'category' => $sec['category'] ?? '', 'tag' => $sec['tag'] ?? '', 'offset' => (int) ($sec['offset'] ?? 0), 'exclude_featured' => !empty($sec['exclude_featured'])], $excludeId);
        $head = $this->sectionHead($sec);
        $showEx = !array_key_exists('show_excerpt', $sec) || !empty($sec['show_excerpt']);
        $showBy = !array_key_exists('show_byline', $sec) || !empty($sec['show_byline']);
        $cta = !empty($sec['cta_text']) ? "<div class=\"kb-more\"><a href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . " →</a></div>" : '';
        if ($rows->isEmpty()) {
            if ($excludeId > 0 || !empty($sec['hide_when_empty'])) return '';
            return "<section class=\"kb-sec\"><div class=\"kb-wrap\">{$head}<p class=\"kb-empty\">No stories published in this section yet.</p></div></section>";
        }
        $rows = $rows->all();
        if ($layout === 'hero_grid') {
            $lead = array_shift($rows);
            $leadHtml = $this->card($lead, 'lead', true, $showBy);
            $rest = ''; foreach ($rows as $a) $rest .= $this->card($a, 'mini', false, false);
            return "<section class=\"kb-sec kb-hero-grid\"><div class=\"kb-wrap\">{$head}<div class=\"kb-hg\"><div class=\"kb-hg-lead\">{$leadHtml}</div><div class=\"kb-hg-rest\">{$rest}</div></div>{$cta}</div></section>";
        }
        $cols = max(2, min(4, (int) ($sec['columns'] ?? 3)));
        $variant = $layout === 'list' ? 'row' : ($layout === 'strip' ? 'strip' : 'card');
        $cards = ''; foreach ($rows as $a) $cards .= $this->card($a, $variant, $showEx, $showBy);
        $gridCls = $layout === 'list' ? 'kb-list' : "kb-grid kb-grid--{$cols}";
        $more = '';
        if ($layout === 'list' && $excludeId === 0 && count($rows) >= $limit && $limit >= 8) {
            $cat = $this->e((string) ($sec['category'] ?? ''));
            $more = "<div class=\"kb-more\"><button type=\"button\" data-kb=\"more\" data-cat=\"{$cat}\" data-offset=\"" . ((int) ($sec['offset'] ?? 0) + count($rows)) . "\" data-limit=\"{$limit}\" data-sub=\"{$this->e($this->sub())}\" data-base=\"{$this->e($this->base)}\">Load more stories</button></div>";
        }
        return "<section class=\"kb-sec\"><div class=\"kb-wrap\">{$head}<div class=\"{$gridCls}\" data-kb-list=\"1\">{$cards}</div>{$more}{$cta}</div></section>";
    }

    private function categoryStrips(array $sec, array $website): string
    {
        $cats = is_array($sec['categories'] ?? null) ? $sec['categories'] : [];
        if ($cats === []) return '';
        $per = max(1, min(6, (int) ($sec['per_category'] ?? 3)));
        $out = $this->sectionHead($sec); $strips = '';
        foreach ($cats as $c) {
            if (!is_array($c)) continue;
            $name = (string) ($c['name'] ?? ''); $slug = (string) ($c['slug'] ?? $this->slugify($name));
            if ($name === '' && $slug === '') continue;
            $rows = $this->articles($website, ['limit' => (int) ($c['limit'] ?? $per), 'category' => $slug]);
            $cards = ''; foreach ($rows as $a) $cards .= $this->card($a, 'mini', false, false);
            if ($cards === '') $cards = '<p class="kb-empty">Stories coming soon.</p>';
            $tagline = $this->e((string) ($c['tagline'] ?? ''));
            $strips .= "<div class=\"kb-strip\"><div class=\"kb-strip-head\"><h3><a href=\"/" . $this->e($slug) . "\">" . $this->e($name !== '' ? $name : $slug) . "</a></h3>" . ($tagline !== '' ? "<span>{$tagline}</span>" : '') . "<a class=\"kb-strip-more\" href=\"/" . $this->e($slug) . "\">All →</a></div><div class=\"kb-strip-grid\">{$cards}</div></div>";
        }
        return "<section class=\"kb-sec kb-strips\"><div class=\"kb-wrap\">{$out}{$strips}</div></section>";
    }

    private function pageHero(array $sec): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? '')); $heading = $this->e((string) ($sec['heading'] ?? '')); $body = $this->e((string) ($sec['body'] ?? $sec['subheading'] ?? ''));
        $cta = !empty($sec['cta_text']) ? "<a class=\"kb-btn kb-btn--primary\" href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . "</a>" : '';
        $bg = $this->url((string) ($sec['background_image'] ?? $sec['image'] ?? ''), '');
        $style = $bg !== '' ? " style=\"background-image:url('{$bg}')\"" : ''; $cls = $bg !== '' ? ' kb-page-hero--img' : '';
        return "<div class=\"kb-page-hero{$cls}\"{$style}><div class=\"kb-wrap\">" . ($eyebrow !== '' ? "<div class=\"kb-eyebrow\">{$eyebrow}</div>" : '') . "<h1>{$heading}</h1>" . ($body !== '' ? "<p>{$body}</p>" : '') . $cta . "</div></div>";
    }

    private function directory(array $sec, array $website): string
    {
        $head = $this->sectionHead($sec); $rows = [];
        try {
            if (Schema::hasTable('directory_listings')) {
                $q = DB::table('directory_listings')->where('website_id', (int) ($website['id'] ?? 0))->where('status', 'published')->whereNull('deleted_at');
                foreach (['category_slug' => 'category', 'city' => 'city', 'region' => 'region', 'country' => 'country'] as $col => $key) { $v = trim((string) ($sec[$key] ?? '')); if ($v !== '') $q->where($col, $v); }
                $rows = $q->orderByDesc('is_featured')->orderByDesc('published_at')->limit(max(1, min(96, (int) ($sec['limit'] ?? 12))))->get()->all();
            }
        } catch (\Throwable) { $rows = []; }
        $cta = !empty($sec['cta_text']) ? "<div class=\"kb-more\"><a href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . " →</a></div>" : '';
        if ($rows === []) return "<section class=\"kb-sec kb-dir\"><div class=\"kb-wrap\">{$head}<div class=\"kb-dir-empty\"><strong>Kabayan Spots opens soon.</strong> The desk is verifying the first listings across Dubai. Own a kabayan-friendly place? <a href=\"/spots#list-your-business\">Tell us about it</a>.</div>{$cta}</div></section>";
        $cards = '';
        foreach ($rows as $r) {
            $n = $this->e((string) $r->name);
            $imgHtml = $this->img((string) ($r->cover_image_url ?? ''), (string) $r->name, 'card');
            $loc = $this->e(trim(((string) ($r->city ?? '')) . (!empty($r->region) && $r->region !== $r->city ? ', ' . $r->region : '')));
            $cards .= "<a class=\"kb-card kb-card--card\" href=\"/spots/" . $this->e((string) $r->slug) . "\"><div class=\"kb-card-img\">{$imgHtml}</div><div class=\"kb-card-body\"><span class=\"kb-cat\">" . $this->e((string) ($r->category_slug ?? '')) . "</span><h3>{$n}</h3><div class=\"kb-meta\"><span>{$loc}</span></div></div></a>";
        }
        return "<section class=\"kb-sec kb-dir\"><div class=\"kb-wrap\">{$head}<div class=\"kb-grid kb-grid--3\">{$cards}</div>{$cta}</div></section>";
    }

    private function newsletter(array $sec, array $website): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? '')); $heading = $this->e((string) ($sec['heading'] ?? 'Subscribe')); $sub = $this->e((string) ($sec['subheading'] ?? ''));
        $cta = $this->e((string) ($sec['cta_text'] ?? 'Subscribe')); $ph = $this->e((string) ($sec['placeholder'] ?? 'your@email.com'));
        $consent = $this->e((string) ($sec['consent_text'] ?? '')); $ok = $this->e((string) ($sec['success_text'] ?? 'Salamat! You are on the list.'));
        $source = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($sec['source_tag'] ?? 'newsletter'))) ?: 'newsletter';
        $sub_ = $this->e($this->sub()); $wid = (int) ($website['id'] ?? 0); $uid = 'kbnl' . substr(md5($heading . $source), 0, 6);
        $channels = $this->channelLinks(true);
        $channelsHtml = $channels !== '' ? "<div class=\"kb-channels\" aria-label=\"Follow us\">{$channels}</div>" : '';
        return <<<HTML
<section class="kb-sec kb-newsletter" id="newsletter"><div class="kb-wrap kb-nl">
  <div class="kb-nl-text">{$this->eyebrowHtml($eyebrow)}<h2>{$heading}</h2><p>{$sub}</p></div>
  <form id="{$uid}" class="kb-nl-form" onsubmit="return false">
    <input type="email" name="email" required placeholder="{$ph}" aria-label="Email address" inputmode="email" autocomplete="email">
    <button type="submit" class="kb-btn kb-btn--accent">{$cta}</button>
    <p class="kb-nl-consent">{$consent}</p>
    <p id="{$uid}-msg" class="kb-nl-msg" role="status"></p>
    {$channelsHtml}
  </form>
</div></section>
<script>(function(){var f=document.getElementById('{$uid}');if(!f)return;var m=document.getElementById('{$uid}-msg');f.addEventListener('submit',function(){var em=f.email.value.trim();if(!em)return;var b=f.querySelector('button');b.disabled=true;fetch('/api/public/contact/'+encodeURIComponent('{$sub_}'),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:em,name:em.split('@')[0],message:'Newsletter signup',source:'{$source}',website_id:{$wid},consent:true})}).then(function(r){if(!r.ok)throw 0;m.textContent='{$ok}';f.reset();}).catch(function(){m.textContent='Something went wrong. Please try again.';}).finally(function(){b.disabled=false;});});})();</script>
HTML;
    }

    private function eyebrowHtml(string $escaped): string { return $escaped !== '' ? "<div class=\"kb-eyebrow\">{$escaped}</div>" : ''; }

    private function adSlot(array $sec, array $website): string
    {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($sec['slot_code'] ?? 'in_content_mrec'))) ?: 'in_content_mrec';
        $label = $this->e((string) ($sec['label'] ?? $this->settings['disclosure_label'] ?? 'Sponsored'));
        return "<div class=\"kb-wrap\"><div class=\"lu-ad-inline\" data-lu-slot=\"{$code}\" data-lu-site=\"" . (int) ($website['id'] ?? 0) . "\" data-lu-label=\"{$label}\" hidden aria-hidden=\"true\"></div></div>";
    }

    private function shareRow(string $url, string $title): string
    {
        $u = rawurlencode($url); $t = rawurlencode($title);
        $eu = $this->e($url); $et = $this->e($title);
        return "<div class=\"kb-share\" data-kb-share data-url=\"{$eu}\" data-title=\"{$et}\">"
            . "<a class=\"is-wa\" href=\"https://wa.me/?text={$t}%20{$u}\" rel=\"noopener\" target=\"_blank\">{$this->icon('whatsapp')}WhatsApp</a>"
            . "<a href=\"https://www.facebook.com/sharer/sharer.php?u={$u}\" rel=\"noopener\" target=\"_blank\">{$this->icon('facebook')}Share</a>"
            . "<a href=\"https://t.me/share/url?url={$u}&text={$t}\" rel=\"noopener\" target=\"_blank\">{$this->icon('telegram')}Telegram</a>"
            . "<button type=\"button\" data-kb=\"copy\">{$this->icon('link')}Copy link</button>"
            . "</div>";
    }

    private function contentToHtml(string $raw): string
    {
        $raw = trim($raw); if ($raw === '') return '';
        if (preg_match('#<(p|h2|h3|ul|ol|div|blockquote)\b#i', $raw)) return $raw;
        $out = '';
        foreach (preg_split('/\n\s*\n/', $raw) as $b) { $b = trim($b); if ($b === '') continue; $out .= '<p>' . nl2br($this->e($b)) . '</p>'; }
        return $out;
    }

    private function icon(string $name): string
    {
        $p = match ($name) {
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
            'home' => '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'news' => '<path d="M4 5h13v14H4zM17 8h3v9a2 2 0 0 1-2 2M7 9h7M7 13h7M7 16h4"/>',
            'guide' => '<path d="M9 3h6l4 4v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM9 12l2 2 4-4"/>',
            'pin' => '<path d="M12 21s7-6.5 7-11a7 7 0 1 0-14 0c0 4.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
            'whatsapp' => '<path fill="currentColor" stroke="none" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.1.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.2c0-.1-.2-.2-.4-.3z"/>',
            'telegram' => '<path fill="currentColor" stroke="none" d="M21.9 4.4 18.7 19.5c-.2 1-.9 1.3-1.7.8l-4.8-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.4-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.6 13.3 1.9 11.8c-1-.3-1-1 .2-1.5L20.5 3c.9-.3 1.6.2 1.4 1.4z"/>',
            'facebook' => '<path fill="currentColor" stroke="none" d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.5 1.6-1.5h1.7V4.4a22 22 0 0 0-2.5-.1c-2.4 0-4.1 1.5-4.1 4.2v2.3H7.5V14h2.7v8z"/>',
            'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/>',
            'tiktok' => '<path d="M14 3v9.5a3.5 3.5 0 1 1-3.5-3.5M14 3a5 5 0 0 0 5 5"/>',
            'youtube' => '<path d="M3 8.5A3 3 0 0 1 6 5.5h12a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M10 9.5v5l4.5-2.5z" fill="currentColor"/>',
            default => '',
        };
        return "<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\" focusable=\"false\">{$p}</svg>";
    }

    /** Small, dependency-free behaviour: header shadow, drawer/search, share, load-more, progress. */
    private function js(bool $article): string
    {
        $base = $this->e($this->base);
        return <<<'JS'
<script>(function(){
var d=document,h=d.getElementById('kb-header'),drawer=d.getElementById('kb-drawer'),search=d.getElementById('kb-search'),lastFocus=null;
function open(el,btn){if(!el)return;el.hidden=false;el.setAttribute('data-open','1');d.body.style.overflow='hidden';lastFocus=d.activeElement;if(btn)btn.setAttribute('aria-expanded','true');var f=el.querySelector('input,button,a');if(f)setTimeout(function(){f.focus()},30)}
function close(){[drawer,search].forEach(function(el){if(!el)return;el.setAttribute('data-open','0');el.hidden=true});d.body.style.overflow='';d.querySelectorAll('[data-kb="menu"],[data-kb="search"]').forEach(function(b){b.setAttribute('aria-expanded','false')});if(lastFocus&&lastFocus.focus)lastFocus.focus()}
d.addEventListener('click',function(e){var t=e.target.closest('[data-kb]');if(!t)return;var k=t.getAttribute('data-kb');
 if(k==='menu'){open(drawer,t)}else if(k==='search'){open(search,t)}else if(k==='close'){close()}
 else if(k==='copy'){var s=t.closest('[data-kb-share]'),u=s?s.getAttribute('data-url'):location.href,ti=s?s.getAttribute('data-title'):d.title;if(navigator.share){navigator.share({title:ti,url:u}).catch(function(){})}else if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){var o=t.innerHTML;t.textContent='Copied';setTimeout(function(){t.innerHTML=o},1500)})}}
 else if(k==='more'){more(t)}});
d.addEventListener('keydown',function(e){if(e.key==='Escape')close()});
var stuck=false;function onScroll(){var y=window.scrollY||0;var s=y>8;if(s!==stuck){stuck=s;if(h)h.classList.toggle('is-stuck',s)}var p=d.getElementById('kb-progress');if(p){var a=d.querySelector('[data-kb-article]');if(a){var r=a.getBoundingClientRect(),total=a.offsetHeight-window.innerHeight,done=Math.min(1,Math.max(0,-r.top/(total||1)));p.style.width=(done*100).toFixed(1)+'%'}}}
window.addEventListener('scroll',onScroll,{passive:true});onScroll();
function esc(s){return String(s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function variant(u,w){var m=/^(https?:\/\/[^\/]+)?\/storage\/((?:ai-images|uploads|media|builder-heroes|sites|logos|creative)\/[A-Za-z0-9_\-.\/]+)$/.exec(u||'');return m?((m[1]||'')+'/api/public/img/'+w+'/'+m[2]):u}
function row(p,base){var img=p.featured_image_url?'<img src="'+esc(variant(p.featured_image_url,480))+'" alt="'+esc(p.title)+'" loading="lazy" decoding="async" width="480" height="480">':'<div class="kb-ph"></div>';return '<a class="kb-card kb-card--row" href="/'+base+'/'+encodeURIComponent(p.slug)+'"><div class="kb-card-img">'+img+'</div><div class="kb-card-body"><span class="kb-cat">'+esc(p.category_name||p.category)+'</span><h3>'+esc(p.title)+'</h3><div class="kb-meta"><span>'+esc(p.author||'')+'</span><span>'+esc(p.read_time||'')+'</span></div></div></a>'}
function api(sub){return '/api/public/news/'+encodeURIComponent(sub)+'/stories'}
function more(btn){var sub=btn.getAttribute('data-sub'),base=btn.getAttribute('data-base'),off=parseInt(btn.getAttribute('data-offset')||'0',10),lim=parseInt(btn.getAttribute('data-limit')||'12',10),cat=btn.getAttribute('data-cat')||'';btn.disabled=true;btn.textContent='Loading…';
 fetch(api(sub)+'?limit='+lim+'&offset='+off+(cat?'&category='+encodeURIComponent(cat):''),{headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(j){var list=btn.closest('section').querySelector('[data-kb-list]');(j.posts||[]).forEach(function(p){list.insertAdjacentHTML('beforeend',row(p,base))});if(j.has_more){btn.disabled=false;btn.textContent='Load more stories';btn.setAttribute('data-offset',String(off+(j.posts||[]).length))}else{btn.remove()}}).catch(function(){btn.disabled=false;btn.textContent='Load more stories'})}
var si=d.getElementById('kb-search-input'),sr=d.getElementById('kb-search-results'),timer=null;
if(si&&sr){si.addEventListener('input',function(){clearTimeout(timer);var q=si.value.trim();if(q.length<2){sr.innerHTML='';return}timer=setTimeout(function(){fetch(api(search.getAttribute('data-sub'))+'?limit=20&q='+encodeURIComponent(q),{headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(j){var base=d.body.getAttribute('data-kb-base')||'news';sr.innerHTML=(j.posts&&j.posts.length)?j.posts.map(function(p){return row(p,base)}).join(''):'<p class="kb-empty">No stories found for “'+esc(q)+'”.</p>'}).catch(function(){sr.innerHTML='<p class="kb-empty">Search is unavailable right now.</p>'})},220)})}
})();</script>
JS
        . "<script>document.body.setAttribute('data-kb-base','{$base}');document.body.classList.add('kb-body');</script>\n";
    }
}
