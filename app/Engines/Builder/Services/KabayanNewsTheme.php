<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KABAYAN888 G1 (2026-09-03) — "kabayan-news" magazine theme for renderer-path sites.
 *
 * Ports the editorial design of storage/templates/news_channel (ticker, hero story,
 * top-stories grid, category strips, opinion rail, newsletter) onto the renderer
 * path: everything is hydrated from pages.sections_json + articles at render time,
 * so Arthur edits presentation (ArthurEditService → SectionSchema) and Sarah owns
 * content (articles). Selected by websites.settings_json.theme = 'kabayan-news'
 * through ThemeRegistry.
 *
 * Contract: renderBody($secs, $brand, $website, $page, ?callable $fallback) and
 * renderArticle($article, $website). Section types this theme does not style are
 * handed to $fallback (BuilderRenderer's generic arm). Read-only: no writes.
 */
class KabayanNewsTheme
{
    private array $settings = [];
    private array $brand = [];
    private array $site = [];
    private string $base = 'blog';

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

    private function head(): string
    {
        $css = @file_get_contents(storage_path('app/kabayan/kabayan-theme.css')) ?: '';
        $b = $this->brand;
        $vars = ":root{--kb-primary:{$b['primary']};--kb-secondary:{$b['secondary']};--kb-accent:{$b['accent']};--kb-alert:{$b['alert']};--kb-fh:'{$b['fh']}',Georgia,serif;--kb-fb:'{$b['fb']}',system-ui,sans-serif}";
        $fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=' . rawurlencode($b['fh']) . ':ital,wght@0,500;0,700;0,900;1,500&family=' . rawurlencode($b['fb']) . ':wght@400;500;600;700&display=swap" rel="stylesheet">';
        return $fonts . "\n<style>" . $vars . "\n" . $css . "</style>\n";
    }

    // ─── public contract ────────────────────────────────────────────────────

    public function renderBody(array $secs, array $brand, array $website, array $page, ?callable $fallback = null): string
    {
        $this->boot($brand, $website);
        $header = $this->header($this->find($secs, 'header'), $website, (string) ($page['slug'] ?? 'home'));
        $footer = $this->footer($this->find($secs, 'footer'), $website);
        $body = '';
        foreach ($secs as $sec) {
            if (!is_array($sec)) continue;
            $t = $sec['type'] ?? '';
            if ($t === 'header' || $t === 'footer') continue;
            try {
                $body .= $this->dispatch($sec, $website, $fallback);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[KabayanNewsTheme] section failed', ['type' => $t, 'error' => $e->getMessage()]);
            }
        }
        return $this->head() . $header . "\n<main id=\"top\" class=\"kb-main\">\n" . $body . "\n</main>\n" . $footer;
    }

    public function renderArticle(array $article, array $website): string
    {
        $brand = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve((int) ($website['workspace_id'] ?? 0));
        $s = $website['settings_json'] ?? [];
        if (is_string($s)) $s = json_decode($s, true) ?: [];
        $tokens = [
            'primary' => $s['primary_color'] ?? ($brand['primary_color'] ?? null),
            'secondary' => $s['secondary_color'] ?? ($brand['secondary_color'] ?? null),
            'accent' => $s['accent_color'] ?? ($brand['accent_color'] ?? null),
            'font_heading' => $s['font_heading'] ?? ($brand['heading_font'] ?? null),
            'font_body' => $s['font_body'] ?? ($brand['body_font'] ?? null),
        ];
        $this->boot($tokens, $website);
        $home = DB::table('pages')->where('website_id', (int) ($website['id'] ?? 0))->where('is_homepage', 1)->first(['sections_json']);
        $homeSecs = [];
        if ($home) {
            $hs = is_string($home->sections_json) ? json_decode($home->sections_json, true) : (array) $home->sections_json;
            $homeSecs = $hs['sections'] ?? (is_array($hs) ? $hs : []);
        }
        $header = $this->header($this->find($homeSecs, 'header'), $website, 'article');
        $footer = $this->footer($this->find($homeSecs, 'footer'), $website);

        $title = $this->e($article['title'] ?? 'Story');
        $cat   = (string) ($article['blog_category'] ?? '');
        $catSlug = $this->slugify($cat);
        $when  = !empty($article['published_at']) ? $this->e(\Carbon\Carbon::parse($article['published_at'])->format('j F Y')) : '';
        $brief = $article['brief_json'] ?? null;
        if (is_string($brief)) $brief = json_decode($brief, true);
        $brief = is_array($brief) ? $brief : [];
        $author = $this->e((string) ($brief['author'] ?? 'LevelUp Kabayan Desk'));
        $read  = (int) ($article['read_time'] ?? 0) ?: max(1, (int) round(((int) ($article['word_count'] ?? 0)) / 200));
        $img   = $this->url((string) ($article['featured_image_url'] ?? ''), '');
        $alt   = $this->e((string) ($article['featured_image_alt'] ?? $article['title'] ?? ''));
        $excerpt = $this->e((string) ($article['excerpt'] ?? ''));
        $content = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#is', '', (string) ($article['content'] ?? ''), 1) ?? (string) ($article['content'] ?? '');
        $content = $this->contentToHtml($content);
        $sponsored = false;
        $tags = $article['tags_json'] ?? null;
        if (is_string($tags)) $tags = json_decode($tags, true);
        if (is_array($tags)) $sponsored = in_array('sponsored', array_map('strtolower', array_map('strval', $tags)), true);
        $catLabel = $cat !== '' ? $this->catName($cat, $website) : 'News';
        $kicker = $sponsored ? $this->e((string) ($this->settings['disclosure_label'] ?? 'Sponsored')) : $this->e($catLabel);
        $kickerCls = $sponsored ? ' kb-kicker--sponsored' : '';

        $sources = '';
        if (!empty($brief['sources']) && is_array($brief['sources'])) {
            $li = '';
            foreach ($brief['sources'] as $src) {
                if (!is_array($src)) continue;
                $su = $this->url((string) ($src['url'] ?? ''), '');
                $st = $this->e((string) ($src['title'] ?? $src['url'] ?? ''));
                if ($st === '') continue;
                $li .= $su !== '' ? "<li><a href=\"{$su}\" rel=\"nofollow noopener\" target=\"_blank\">{$st}</a></li>" : "<li>{$st}</li>";
            }
            if ($li !== '') $sources = "<aside class=\"kb-sources\"><div class=\"kb-eyebrow\">Sources</div><ul>{$li}</ul></aside>";
        }
        $hero = $img !== '' ? "<figure class=\"kb-art-hero\"><img src=\"{$img}\" alt=\"{$alt}\"></figure>" : '';
        $related = $this->newsFeed(['eyebrow' => 'More in ' . $catLabel, 'heading' => '', 'layout' => 'cards', 'category' => $catSlug !== '' ? $cat : '', 'limit' => 3, 'columns' => 3], $website, (int) ($article['id'] ?? 0));
        $catLink = $catSlug !== '' ? "<a href=\"/{$this->e($catSlug)}\">" . $this->e($catLabel) . "</a>" : '';
        $main = <<<HTML
<article class="kb-article">
  <div class="kb-wrap kb-art-head">
    <nav class="kb-crumbs" aria-label="Breadcrumb"><a href="/">Home</a> <span>›</span> <a href="/{$this->e($this->base)}">News</a>{$this->crumb($catLink)}</nav>
    <div class="kb-kicker{$kickerCls}">{$kicker}</div>
    <h1>{$title}</h1>
    <p class="kb-art-dek">{$excerpt}</p>
    <div class="kb-byline"><span class="kb-author">{$author}</span><span>{$when}</span><span>{$read} min read</span></div>
  </div>
  {$hero}
  <div class="kb-wrap kb-art-body">
    <div class="kb-prose">{$content}</div>
    {$sources}
    <div class="lu-ad-inline" data-lu-slot="in_content_mrec" hidden aria-hidden="true"></div>
    <a class="kb-back" href="/{$this->e($this->base)}">← All stories</a>
  </div>
</article>
{$related}
HTML;
        return $this->head() . $header . "\n<main id=\"top\" class=\"kb-main\">\n" . $main . "\n</main>\n" . $footer;
    }

    private function crumb(string $link): string { return $link !== '' ? " <span>›</span> {$link}" : ''; }

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
            case 'video_embed':
            case 'blog_list':
            case 'generic':
            case 'features':
            case 'cta':
            case 'contact_form':
            case 'pricing':
            case 'faq':
            case 'stats':
            case 'testimonials':
            case 'team':
            case 'gallery':
            case 'events_calendar':
            case 'filter_bar':
            case 'grid':
            case 'map':
            case 'trust_signals':
            case 'services':
            default:
                if ($fallback === null) return '';
                $html = (string) $fallback($sec);
                return $html !== '' ? "<div class=\"kb-generic\">{$html}</div>" : '';
        }
    }

    // ─── data ───────────────────────────────────────────────────────────────

    private function articles(array $website, array $o = [], int $excludeId = 0)
    {
        $wsId = (int) ($website['workspace_id'] ?? 0);
        $wid  = (int) ($website['id'] ?? 0);
        $q = DB::table('articles')
            ->where('workspace_id', $wsId)
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
        return $q->orderByDesc('published_at')->orderByDesc('id')->offset($offset)
            ->limit(max(1, min(48, (int) ($o['limit'] ?? 12))))
            ->get(['id', 'title', 'slug', 'excerpt', 'blog_category', 'featured_image_url', 'featured_image_alt', 'read_time', 'word_count', 'published_at', 'brief_json', 'tags_json']);
    }

    private function slugify(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($s))) ?? '', '-');
    }

    /** blog_category stores the slug; show the workspace's category NAME (blog_categories) when known. */
    private array $catNames = [];
    private function catName(string $stored, array $website): string
    {
        $stored = trim($stored);
        if ($stored === '') return '';
        $wsId = (int) ($website['workspace_id'] ?? 0);
        if (!isset($this->catNames[$wsId])) {
            $this->catNames[$wsId] = [];
            try {
                foreach (DB::table('blog_categories')->where('workspace_id', $wsId)->get(['name', 'slug']) as $c) {
                    $this->catNames[$wsId][strtolower((string) $c->slug)] = (string) $c->name;
                    $this->catNames[$wsId][strtolower((string) $c->name)] = (string) $c->name;
                }
            } catch (\Throwable) {}
        }
        return $this->catNames[$wsId][strtolower($stored)] ?? ucwords(str_replace('-', ' ', $stored));
    }

    private function readMin(object $a): int
    {
        $rt = (int) ($a->read_time ?? 0);
        return $rt > 0 ? $rt : max(1, (int) round(((int) ($a->word_count ?? 0)) / 200));
    }

    private function author(object $a): string
    {
        $b = $a->brief_json ?? null;
        if (is_string($b)) $b = json_decode($b, true);
        return is_array($b) ? (string) ($b['author'] ?? '') : '';
    }

    private function card(object $a, string $variant = 'card', bool $excerpt = true, bool $byline = true): string
    {
        $t = $this->e($a->title);
        $u = '/' . $this->e($this->base) . '/' . $this->e($a->slug);
        $cat = (string) ($a->blog_category ?? '');
        $catHtml = $cat !== '' ? "<span class=\"kb-cat\">" . $this->e($this->catName($cat, $this->site)) . "</span>" : '';
        $img = $this->url((string) ($a->featured_image_url ?? ''), '');
        $alt = $this->e((string) ($a->featured_image_alt ?? $a->title));
        $imgHtml = $img !== '' ? "<img src=\"{$img}\" alt=\"{$alt}\" loading=\"lazy\">" : "<div class=\"kb-ph\"></div>";
        $ex = $excerpt && !empty($a->excerpt) ? '<p class="kb-ex">' . $this->e(mb_substr((string) $a->excerpt, 0, 180)) . '</p>' : '';
        $when = !empty($a->published_at) ? \Carbon\Carbon::parse($a->published_at)->format('j M Y') : '';
        $au = $byline && $this->author($a) !== '' ? '<span class="kb-author">' . $this->e($this->author($a)) . '</span>' : '';
        $meta = "<div class=\"kb-meta\">{$au}<span>" . $this->e($when) . "</span><span>" . $this->readMin($a) . " min read</span></div>";
        return "<a class=\"kb-card kb-card--{$variant}\" href=\"{$u}\"><div class=\"kb-card-img\">{$imgHtml}</div><div class=\"kb-card-body\">{$catHtml}<h3>{$t}</h3>{$ex}{$meta}</div></a>";
    }

    private function sectionHead(array $sec, string $tagH = 'h2'): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? ''));
        $heading = $this->e((string) ($sec['heading'] ?? ''));
        $sub = $this->e((string) ($sec['subheading'] ?? ''));
        if ($eyebrow === '' && $heading === '' && $sub === '') return '';
        return "<div class=\"kb-sec-head\">" . ($eyebrow !== '' ? "<div class=\"kb-eyebrow\">{$eyebrow}</div>" : '')
            . ($heading !== '' ? "<{$tagH} class=\"kb-sec-title\">{$heading}</{$tagH}>" : '')
            . ($sub !== '' ? "<p class=\"kb-sec-sub\">{$sub}</p>" : '') . "</div>";
    }

    // ─── chrome ─────────────────────────────────────────────────────────────

    private function header(?array $h, array $website, string $current): string
    {
        $name = $this->e((string) (($h['logo_text'] ?? '') !== '' ? $h['logo_text'] : ($website['name'] ?? 'Kabayan')));
        $logo = $this->url((string) ($h['logo_url'] ?? ''), '');
        $plate = $logo !== '' ? "<img src=\"{$logo}\" alt=\"{$name}\" class=\"kb-logo\">" : "<span class=\"kb-plate-text\">{$name}</span>";
        $links = [];
        if (!empty($h['nav_links']) && is_array($h['nav_links'])) {
            foreach ($h['nav_links'] as $nl) {
                if (is_string($nl)) { $links[] = ['label' => $nl, 'url' => '/' . $this->slugify($nl)]; continue; }
                if (!is_array($nl)) continue;
                $lbl = trim((string) ($nl['label'] ?? ''));
                if ($lbl === '') continue;
                $u = (string) ($nl['url'] ?? $nl['href'] ?? '');
                $links[] = ['label' => $lbl, 'url' => $u !== '' ? $u : '/' . $this->slugify($lbl)];
            }
        } else {
            $pages = DB::table('pages')->where('website_id', (int) ($website['id'] ?? 0))->where('status', 'published')->orderBy('position')->get(['slug', 'title']);
            foreach ($pages as $p) {
                if (in_array($p->slug, ['home', 'privacy', 'terms', 'editorial-policy', 'about', 'contact', 'advertise'], true)) continue;
                $links[] = ['label' => (string) $p->title, 'url' => '/' . $p->slug];
            }
        }
        $nav = '';
        foreach ($links as $l) {
            $slug = trim((string) $l['url'], '/');
            $cls = ($slug === $current || ($slug === '' && $current === 'home')) ? ' class="is-active"' : '';
            $nav .= "<li><a{$cls} href=\"" . $this->url((string) $l['url'], '#') . "\">" . $this->e($l['label']) . "</a></li>";
        }
        $cta = '';
        if (!empty($h['cta_text'])) {
            $cta = "<a class=\"kb-btn kb-btn--primary\" href=\"" . $this->url((string) ($h['cta_url'] ?? '#newsletter'), '#newsletter') . "\">" . $this->e((string) $h['cta_text']) . "</a>";
        }
        $date = $this->e(now()->setTimezone('Asia/Dubai')->format('l, j F Y'));
        $tag = $this->e((string) ($this->settings['tagline'] ?? 'Stories of Filipinos rising, wherever they are'));
        return <<<HTML
<header class="kb-header">
  <div class="kb-topbar"><div class="kb-wrap"><span>{$date} · Dubai</span><span class="kb-topbar-tag">{$tag}</span></div></div>
  <div class="kb-plate"><div class="kb-wrap"><a href="/" class="kb-plate-link" aria-label="{$name} home">{$plate}</a>{$cta}</div></div>
  <nav class="kb-nav" aria-label="Sections"><ul class="kb-wrap">{$nav}</ul></nav>
</header>
HTML;
    }

    private function footer(?array $f, array $website): string
    {
        $name = $this->e((string) ($website['name'] ?? 'Kabayan'));
        $year = date('Y');
        $copy = $this->e((string) (($f['copyright'] ?? '') !== '' ? $f['copyright'] : "© {$year} {$name}."));
        $cols = '';
        if (!empty($f['columns']) && is_array($f['columns'])) {
            foreach ($f['columns'] as $c) {
                if (!is_array($c)) continue;
                $li = '';
                foreach ((array) ($c['links'] ?? []) as $l) {
                    if (!is_array($l) || trim((string) ($l['label'] ?? '')) === '') continue;
                    $li .= "<li><a href=\"" . $this->url((string) ($l['url'] ?? '#'), '#') . "\">" . $this->e($l['label']) . "</a></li>";
                }
                $cols .= "<div class=\"kb-fcol\"><div class=\"kb-eyebrow\">" . $this->e((string) ($c['heading'] ?? '')) . "</div><ul>{$li}</ul></div>";
            }
        } elseif (!empty($f['links']) && is_array($f['links'])) {
            $li = '';
            foreach ($f['links'] as $l) {
                if (!is_array($l) || trim((string) ($l['label'] ?? '')) === '') continue;
                $li .= "<li><a href=\"" . $this->url((string) ($l['url'] ?? '#'), '#') . "\">" . $this->e($l['label']) . "</a></li>";
            }
            $cols = "<div class=\"kb-fcol\"><ul>{$li}</ul></div>";
        }
        $social = '';
        if (!empty($f['social_links']) && is_array($f['social_links'])) {
            foreach ($f['social_links'] as $k => $v) {
                $v = is_array($v) ? (string) ($v['url'] ?? '') : (string) $v;
                if (trim($v) === '') continue;
                $social .= "<a href=\"" . $this->url($v, '#') . "\" rel=\"noopener\" target=\"_blank\">" . $this->e(ucfirst((string) $k)) . "</a>";
            }
        }
        $contact = '';
        foreach (['email' => 'mailto:', 'phone' => 'tel:'] as $k => $scheme) {
            if (!empty($f[$k])) $contact .= "<a href=\"{$scheme}" . $this->e((string) $f[$k]) . "\">" . $this->e((string) $f[$k]) . "</a>";
        }
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
            foreach ($sec['items'] as $it) {
                if (!is_array($it) || trim((string) ($it['text'] ?? '')) === '') continue;
                $items[] = "<a href=\"" . $this->url((string) ($it['url'] ?? ''), '#') . "\">" . $this->e($it['text']) . "</a>";
            }
        } else {
            foreach ($this->articles($website, ['limit' => (int) ($sec['limit'] ?? 6), 'category' => $sec['category'] ?? '']) as $a) {
                $items[] = "<a href=\"/" . $this->e($this->base) . "/" . $this->e($a->slug) . "\">" . $this->e($a->title) . "</a>";
            }
        }
        if ($items === []) return '';
        $run = implode('', $items);
        $speed = max(15, min(120, (int) ($sec['speed'] ?? 45)));
        return "<div class=\"kb-ticker\" role=\"region\" aria-label=\"Latest headlines\"><div class=\"kb-wrap kb-ticker-in\"><span class=\"kb-ticker-kick\">{$label}</span><div class=\"kb-ticker-track\"><div class=\"kb-ticker-run\" style=\"animation-duration:{$speed}s\">{$run}{$run}</div></div></div></div>";
    }

    private function newsFeed(array $sec, array $website, int $excludeId = 0): string
    {
        $layout = (string) ($sec['layout'] ?? 'cards');
        $rows = $this->articles($website, [
            'limit' => (int) ($sec['limit'] ?? 12), 'category' => $sec['category'] ?? '', 'tag' => $sec['tag'] ?? '',
            'offset' => (int) ($sec['offset'] ?? 0), 'exclude_featured' => !empty($sec['exclude_featured']),
        ], $excludeId);
        $head = $this->sectionHead($sec);
        $showEx = !array_key_exists('show_excerpt', $sec) || !empty($sec['show_excerpt']);
        $showBy = !array_key_exists('show_byline', $sec) || !empty($sec['show_byline']);
        $cta = '';
        if (!empty($sec['cta_text'])) {
            $cta = "<div class=\"kb-more\"><a href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . " →</a></div>";
        }
        if ($rows->isEmpty()) {
            if ($excludeId > 0) return '';
            return "<section class=\"kb-sec\"><div class=\"kb-wrap\">{$head}<p class=\"kb-empty\">No stories published in this section yet.</p></div></section>";
        }
        $rows = $rows->all();
        if ($layout === 'hero_grid') {
            $lead = array_shift($rows);
            $leadHtml = $this->card($lead, 'lead', true, $showBy);
            $rest = '';
            foreach ($rows as $a) $rest .= $this->card($a, 'mini', false, false);
            return "<section class=\"kb-sec kb-hero-grid\"><div class=\"kb-wrap\">{$head}<div class=\"kb-hg\"><div class=\"kb-hg-lead\">{$leadHtml}</div><div class=\"kb-hg-rest\">{$rest}</div></div>{$cta}</div></section>";
        }
        $cols = max(2, min(4, (int) ($sec['columns'] ?? 3)));
        $variant = $layout === 'list' ? 'row' : ($layout === 'strip' ? 'strip' : 'card');
        $cards = '';
        foreach ($rows as $a) $cards .= $this->card($a, $variant, $showEx, $showBy);
        $gridCls = $layout === 'list' ? 'kb-list' : "kb-grid kb-grid--{$cols}";
        return "<section class=\"kb-sec\"><div class=\"kb-wrap\">{$head}<div class=\"{$gridCls}\">{$cards}</div>{$cta}</div></section>";
    }

    private function categoryStrips(array $sec, array $website): string
    {
        $cats = is_array($sec['categories'] ?? null) ? $sec['categories'] : [];
        if ($cats === []) return '';
        $per = max(1, min(6, (int) ($sec['per_category'] ?? 3)));
        $out = $this->sectionHead($sec);
        $strips = '';
        foreach ($cats as $c) {
            if (!is_array($c)) continue;
            $name = (string) ($c['name'] ?? '');
            $slug = (string) ($c['slug'] ?? $this->slugify($name));
            if ($name === '' && $slug === '') continue;
            $rows = $this->articles($website, ['limit' => (int) ($c['limit'] ?? $per), 'category' => $slug]);
            $cards = '';
            foreach ($rows as $a) $cards .= $this->card($a, 'mini', false, false);
            if ($cards === '') $cards = '<p class="kb-empty">Stories coming soon.</p>';
            $tagline = $this->e((string) ($c['tagline'] ?? ''));
            $strips .= "<div class=\"kb-strip\"><div class=\"kb-strip-head\"><h3><a href=\"/" . $this->e($slug) . "\">" . $this->e($name !== '' ? $name : $slug) . "</a></h3>" . ($tagline !== '' ? "<span>{$tagline}</span>" : '') . "<a class=\"kb-strip-more\" href=\"/" . $this->e($slug) . "\">All →</a></div><div class=\"kb-strip-grid\">{$cards}</div></div>";
        }
        return "<section class=\"kb-sec kb-strips\"><div class=\"kb-wrap\">{$out}{$strips}</div></section>";
    }

    private function pageHero(array $sec): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? ''));
        $heading = $this->e((string) ($sec['heading'] ?? ''));
        $body = $this->e((string) ($sec['body'] ?? $sec['subheading'] ?? ''));
        $cta = '';
        if (!empty($sec['cta_text'])) $cta = "<a class=\"kb-btn kb-btn--primary\" href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . "</a>";
        $bg = $this->url((string) ($sec['background_image'] ?? $sec['image'] ?? ''), '');
        $style = $bg !== '' ? " style=\"background-image:url('{$bg}')\"" : '';
        $cls = $bg !== '' ? ' kb-page-hero--img' : '';
        return "<div class=\"kb-page-hero{$cls}\"{$style}><div class=\"kb-wrap\">" . ($eyebrow !== '' ? "<div class=\"kb-eyebrow\">{$eyebrow}</div>" : '') . "<h1>{$heading}</h1>" . ($body !== '' ? "<p>{$body}</p>" : '') . $cta . "</div></div>";
    }

    private function directory(array $sec, array $website): string
    {
        $head = $this->sectionHead($sec);
        $rows = [];
        try {
            if (Schema::hasTable('directory_listings')) {
                $q = DB::table('directory_listings')->where('website_id', (int) ($website['id'] ?? 0))->where('status', 'published')->whereNull('deleted_at');
                foreach (['category_slug' => 'category', 'city' => 'city', 'region' => 'region', 'country' => 'country'] as $col => $key) {
                    $v = trim((string) ($sec[$key] ?? '')); if ($v !== '') $q->where($col, $v);
                }
                $rows = $q->orderByDesc('is_featured')->orderByDesc('published_at')->limit(max(1, min(96, (int) ($sec['limit'] ?? 12))))->get()->all();
            }
        } catch (\Throwable) { $rows = []; }
        $cta = '';
        if (!empty($sec['cta_text'])) $cta = "<div class=\"kb-more\"><a href=\"" . $this->url((string) ($sec['cta_url'] ?? '#'), '#') . "\">" . $this->e((string) $sec['cta_text']) . " →</a></div>";
        if ($rows === []) {
            return "<section class=\"kb-sec kb-dir\"><div class=\"kb-wrap\">{$head}<div class=\"kb-dir-empty\"><strong>Kabayan Spots opens soon.</strong> The desk is verifying the first listings across Dubai. Own a kabayan-friendly place? <a href=\"/spots#list-your-business\">Tell us about it</a>.</div>{$cta}</div></section>";
        }
        $cards = '';
        foreach ($rows as $r) {
            $n = $this->e((string) $r->name);
            $img = $this->url((string) ($r->cover_image_url ?? ''), '');
            $imgHtml = $img !== '' ? "<img src=\"{$img}\" alt=\"{$n}\" loading=\"lazy\">" : "<div class=\"kb-ph\"></div>";
            $loc = $this->e(trim(((string) ($r->city ?? '')) . (!empty($r->region) && $r->region !== $r->city ? ', ' . $r->region : '')));
            $cards .= "<a class=\"kb-card kb-card--card\" href=\"/spots/" . $this->e((string) $r->slug) . "\"><div class=\"kb-card-img\">{$imgHtml}</div><div class=\"kb-card-body\"><span class=\"kb-cat\">" . $this->e((string) ($r->category_slug ?? '')) . "</span><h3>{$n}</h3><div class=\"kb-meta\"><span>{$loc}</span></div></div></a>";
        }
        return "<section class=\"kb-sec kb-dir\"><div class=\"kb-wrap\">{$head}<div class=\"kb-grid kb-grid--3\">{$cards}</div>{$cta}</div></section>";
    }

    private function newsletter(array $sec, array $website): string
    {
        $eyebrow = $this->e((string) ($sec['eyebrow'] ?? ''));
        $heading = $this->e((string) ($sec['heading'] ?? 'Subscribe'));
        $sub = $this->e((string) ($sec['subheading'] ?? ''));
        $cta = $this->e((string) ($sec['cta_text'] ?? 'Subscribe'));
        $ph = $this->e((string) ($sec['placeholder'] ?? 'your@email.com'));
        $consent = $this->e((string) ($sec['consent_text'] ?? ''));
        $ok = $this->e((string) ($sec['success_text'] ?? 'Salamat! You are on the list.'));
        $source = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($sec['source_tag'] ?? 'newsletter'))) ?: 'newsletter';
        $sub_ = $this->e(str_replace('.levelupgrowth.io', '', (string) ($website['subdomain'] ?? '')));
        $wid = (int) ($website['id'] ?? 0);
        $uid = 'kbnl' . substr(md5($heading . $source), 0, 6);
        return <<<HTML
<section class="kb-sec kb-newsletter" id="newsletter"><div class="kb-wrap kb-nl">
  <div class="kb-nl-text">{$this->eyebrowHtml($eyebrow)}<h2>{$heading}</h2><p>{$sub}</p></div>
  <form id="{$uid}" class="kb-nl-form" onsubmit="return false">
    <input type="email" name="email" required placeholder="{$ph}" aria-label="Email address">
    <button type="submit" class="kb-btn kb-btn--accent">{$cta}</button>
    <p class="kb-nl-consent">{$consent}</p>
    <p id="{$uid}-msg" class="kb-nl-msg" role="status"></p>
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

    private function contentToHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('#<(p|h2|h3|ul|ol|div|blockquote)\b#i', $raw)) return $raw;
        $out = '';
        foreach (preg_split('/\n\s*\n/', $raw) as $b) {
            $b = trim($b);
            if ($b === '') continue;
            $out .= '<p>' . nl2br($this->e($b)) . '</p>';
        }
        return $out;
    }
}
