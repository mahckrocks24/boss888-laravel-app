<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KABAYAN888 G2 (2026-09-03) — generic renderers for the seven editorial section
 * types added to SectionSchema: ticker, news_feed, category_strips, video_embed,
 * directory, newsletter_signup, ad_slot.
 *
 * These are the theme-less fallbacks used by BuilderRenderer::renderSection so the
 * types degrade gracefully on ANY renderer site. KabayanNewsTheme overrides them
 * with the magazine design. Data-backed types (ticker, news_feed, category_strips,
 * directory) read the DB at render time and never store content in sections_json:
 * Arthur owns presentation fields, Sarah owns the rows.
 *
 * Every method is pure output — no writes — so Law 11 is untouched.
 */
trait EditorialSections
{
    /** Published, site-bound articles for a website, newest first. */
    protected function editorialArticles(array $website, array $opts = [])
    {
        $wsId  = (int) ($website['workspace_id'] ?? 0);
        $wid   = (int) ($website['id'] ?? 0);
        $limit = max(1, min(48, (int) ($opts['limit'] ?? 12)));
        $q = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where(function ($q) use ($wid) { if ($wid > 0) { $q->where('website_id', $wid)->orWhereNull('website_id'); } })
            ->where('is_marketing_blog', 1)
            ->where('status', 'published')
            ->whereNull('deleted_at');
        $cat = trim((string) ($opts['category'] ?? ''));
        if ($cat !== '' && strtolower($cat) !== 'all') {
            // Accept either the slug ("success-stories") or the display name ("Success Stories").
            $q->where(function ($w) use ($cat) {
                $w->where('blog_category', $cat)
                  ->orWhere('blog_category', str_replace('-', ' ', $cat))
                  ->orWhereRaw('LOWER(REPLACE(blog_category, " ", "-")) = ?', [strtolower($cat)]);
            });
        }
        $tag = trim((string) ($opts['tag'] ?? ''));
        if ($tag !== '') {
            $q->whereRaw('JSON_SEARCH(tags_json, "one", ?) IS NOT NULL', [$tag]);
        }
        // KABAYAN888 QATAR-1 — edition filter: a story belongs to its brief_json.region, or to every edition when unset/ALL.
        $region = strtoupper(trim((string) ($opts['region'] ?? '')));
        if ($region !== '' && $region !== 'ALL') {
            $q->where(function ($w) use ($region) {
                $w->whereRaw("JSON_EXTRACT(brief_json, '$.region') IS NULL")
                  ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(brief_json, '$.region'))) IN ('', 'ALL', ?)", [$region]);
            });
        }
        $offset = max(0, (int) ($opts['offset'] ?? 0)) + (!empty($opts['exclude_featured']) ? 1 : 0);
        return $q->orderByDesc('published_at')->orderByDesc('id')
            ->offset($offset)->limit($limit)
            ->get(['id', 'title', 'slug', 'excerpt', 'blog_category', 'featured_image_url', 'featured_image_alt',
                   'read_time', 'word_count', 'published_at', 'brief_json', 'type']);
    }

    /** /{article_base}/{slug} — settings_json.article_base, default 'blog'. */
    protected function editorialArticleBase(array $website): string
    {
        $settings = $website['settings_json'] ?? [];
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        $base = strtolower(trim((string) ($settings['article_base'] ?? 'blog')));
        return preg_match('/^[a-z0-9\-]{1,40}$/', $base) ? $base : 'blog';
    }

    protected function editorialReadMinutes(object $a): int
    {
        $rt = (int) ($a->read_time ?? 0);
        if ($rt > 0) return $rt;
        return max(1, (int) round(((int) ($a->word_count ?? 0)) / 200));
    }

    protected function editorialAuthor(object $a): string
    {
        $brief = $a->brief_json ?? null;
        if (is_string($brief)) $brief = json_decode($brief, true);
        $author = is_array($brief) ? (string) ($brief['author'] ?? '') : '';
        return $author !== '' ? $author : '';
    }

    protected function editorialSlugify(string $s): string
    {
        $s = strtolower(trim($s));
        return trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    }

    // ─── ticker ────────────────────────────────────────────────────────────
    private function renderTicker(array $sec, array $brand, array $website = []): string
    {
        $label = e((string) ($sec['label'] ?? 'BREAKING'));
        $items = [];
        if (($sec['mode'] ?? 'latest') === 'manual' && is_array($sec['items'] ?? null)) {
            foreach ($sec['items'] as $it) {
                if (!is_array($it) || trim((string) ($it['text'] ?? '')) === '') continue;
                $items[] = ['text' => (string) $it['text'], 'url' => (string) ($it['url'] ?? '')];
            }
        } else {
            $base = $this->editorialArticleBase($website);
            foreach ($this->editorialArticles($website, ['limit' => (int) ($sec['limit'] ?? 6), 'category' => $sec['category'] ?? '']) as $a) {
                $items[] = ['text' => (string) $a->title, 'url' => "/{$base}/" . $a->slug];
            }
        }
        if ($items === []) return '';
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $out = '';
        foreach ($items as $it) {
            $t = e($it['text']);
            $u = $this->safeUrl($it['url'], '');
            $out .= $u !== '' ? "<a href=\"{$u}\" style=\"color:inherit;text-decoration:none;margin-right:2.5rem\">{$t}</a>" : "<span style=\"margin-right:2.5rem\">{$t}</span>";
        }
        return "<div class=\"lu-ticker\" style=\"display:flex;align-items:center;height:40px;overflow:hidden;background:#111;color:#fff;font-size:.9rem\">"
            . "<span style=\"background:{$primary};color:#fff;font-weight:700;letter-spacing:.12em;font-size:.72rem;padding:0 12px;height:100%;display:flex;align-items:center;flex:none\">{$label}</span>"
            . "<div style=\"white-space:nowrap;padding-left:1.5rem;overflow:hidden;text-overflow:ellipsis\">{$out}</div></div>";
    }

    // ─── news_feed ─────────────────────────────────────────────────────────
    private function renderNewsFeed(array $sec, array $brand, array $website = []): string
    {
        $heading = e((string) ($sec['heading'] ?? ''));
        $sub     = e((string) ($sec['subheading'] ?? ''));
        $eyebrow = e((string) ($sec['eyebrow'] ?? ''));
        $rows    = $this->editorialArticles($website, [
            'limit' => (int) ($sec['limit'] ?? 12), 'category' => $sec['category'] ?? '', 'tag' => $sec['tag'] ?? '',
            'offset' => (int) ($sec['offset'] ?? 0), 'exclude_featured' => !empty($sec['exclude_featured']),
        ]);
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $base = $this->editorialArticleBase($website);
        $head = ($eyebrow !== '' ? "<div style=\"font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:{$primary};margin-bottom:6px\">{$eyebrow}</div>" : '')
              . ($heading !== '' ? "<h2 style=\"font-family:'{$fh}',sans-serif;font-size:clamp(24px,3vw,36px);margin:0 0 6px\">{$heading}</h2>" : '')
              . ($sub !== '' ? "<p style=\"color:#5a5f72;margin:0 0 24px\">{$sub}</p>" : '<div style="height:20px"></div>');
        if ($rows->isEmpty()) {
            return "<section style=\"padding:60px 24px\"><div style=\"max-width:1100px;margin:0 auto\">{$head}<p style=\"color:#5a5f72\">No stories published yet.</p></div></section>";
        }
        $cols = max(2, min(4, (int) ($sec['columns'] ?? 3)));
        $cards = '';
        foreach ($rows as $a) {
            $t   = e($a->title);
            $u   = "/{$base}/" . e($a->slug);
            $cat = e((string) ($a->blog_category ?? ''));
            $img = $this->safeUrl((string) ($a->featured_image_url ?? ''), '');
            $ex  = !empty($sec['show_excerpt']) ? '<p style="color:#5a5f72;font-size:.95rem;margin:6px 0 0">' . e(mb_substr((string) ($a->excerpt ?? ''), 0, 160)) . '</p>' : '';
            $when = !empty($a->published_at) ? e(\Carbon\Carbon::parse($a->published_at)->format('j M Y')) : '';
            $imgHtml = $img !== '' ? "<img src=\"{$img}\" alt=\"{$t}\" loading=\"lazy\" style=\"width:100%;aspect-ratio:16/10;object-fit:cover;display:block;border-radius:8px\">" : "<div style=\"width:100%;aspect-ratio:16/10;background:linear-gradient(135deg,{$primary}22,{$primary}55);border-radius:8px\"></div>";
            $cards .= "<a href=\"{$u}\" style=\"text-decoration:none;color:inherit;display:block\">{$imgHtml}<div style=\"font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:{$primary};margin:10px 0 4px\">{$cat}</div><h3 style=\"font-family:'{$fh}',sans-serif;font-size:1.1rem;line-height:1.3;margin:0\">{$t}</h3>{$ex}<div style=\"font-size:.8rem;color:#8a8f9f;margin-top:6px\">{$when} · " . $this->editorialReadMinutes($a) . " min read</div></a>";
        }
        $cta = '';
        if (!empty($sec['cta_text'])) {
            $cta = "<div style=\"margin-top:28px\"><a href=\"" . $this->safeUrl((string) ($sec['cta_url'] ?? ''), '#') . "\" style=\"color:{$primary};font-weight:700;text-decoration:none\">" . e((string) $sec['cta_text']) . " →</a></div>";
        }
        return "<section style=\"padding:60px 24px\"><div style=\"max-width:1100px;margin:0 auto\">{$head}<div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(" . (int) floor(1100 / $cols - 24) . "px,1fr));gap:28px\">{$cards}</div>{$cta}</div></section>";
    }

    // ─── category_strips ───────────────────────────────────────────────────
    private function renderCategoryStrips(array $sec, array $brand, array $website = []): string
    {
        $cats = is_array($sec['categories'] ?? null) ? $sec['categories'] : [];
        if ($cats === []) return '';
        $per = max(1, min(6, (int) ($sec['per_category'] ?? 3)));
        $out = '';
        foreach ($cats as $c) {
            if (!is_array($c)) continue;
            $name = (string) ($c['name'] ?? '');
            $slug = (string) ($c['slug'] ?? $this->editorialSlugify($name));
            if ($name === '' && $slug === '') continue;
            $out .= $this->renderNewsFeed([
                'type' => 'news_feed', 'eyebrow' => $name !== '' ? $name : $slug, 'heading' => '', 'subheading' => (string) ($c['tagline'] ?? ''),
                'category' => $slug, 'limit' => (int) ($c['limit'] ?? $per), 'columns' => 3, 'show_excerpt' => false,
                'cta_text' => 'More ' . ($name !== '' ? $name : $slug), 'cta_url' => '/' . $slug,
            ], $brand, $website);
        }
        return $out;
    }

    // ─── video_embed ───────────────────────────────────────────────────────
    private function renderVideoEmbed(array $sec, array $brand): string
    {
        $url = trim((string) ($sec['video_url'] ?? ''));
        // Nothing to show yet → render nothing (an empty "coming soon" box reads as unfinished on a live site).
        if ($url === '' && trim((string) ($sec['thumbnail'] ?? '')) === '') return '';
        $embed = $this->editorialVideoEmbedUrl($url);
        $heading = e((string) ($sec['heading'] ?? ''));
        $eyebrow = e((string) ($sec['eyebrow'] ?? ''));
        $sub     = e((string) ($sec['subheading'] ?? ''));
        $caption = e((string) ($sec['caption'] ?? ''));
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $thumb = $this->safeUrl((string) ($sec['thumbnail'] ?? ''), '');
        if ($embed === null && $url !== '' && preg_match('/\.(mp4|webm)(\?.*)?$/i', $url)) {
            $src = $this->safeUrl($url, '');
            $poster = $thumb !== '' ? " poster=\"{$thumb}\"" : '';
            $media = $src !== '' ? "<video controls preload=\"metadata\"{$poster} style=\"width:100%;aspect-ratio:16/9;background:#000;border-radius:10px\"><source src=\"{$src}\"></video>" : '';
        } elseif ($embed !== null) {
            $media = "<iframe src=\"{$embed}\" title=\"{$heading}\" loading=\"lazy\" allow=\"accelerometer; encrypted-media; picture-in-picture\" allowfullscreen style=\"width:100%;aspect-ratio:16/9;border:0;border-radius:10px;background:#000\"></iframe>";
        } else {
            $media = $thumb !== ''
                ? "<img src=\"{$thumb}\" alt=\"{$heading}\" style=\"width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:10px\">"
                : "<div style=\"width:100%;aspect-ratio:16/9;border-radius:10px;background:linear-gradient(135deg,{$primary}22,{$primary}66);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700\">Video coming soon</div>";
        }
        $head = ($eyebrow !== '' ? "<div style=\"font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:{$primary};margin-bottom:6px\">{$eyebrow}</div>" : '')
              . ($heading !== '' ? "<h2 style=\"font-family:'{$fh}',sans-serif;font-size:clamp(24px,3vw,36px);margin:0 0 6px\">{$heading}</h2>" : '')
              . ($sub !== '' ? "<p style=\"color:#5a5f72;margin:0 0 20px\">{$sub}</p>" : '<div style="height:16px"></div>');
        $cap = $caption !== '' ? "<p style=\"color:#8a8f9f;font-size:.9rem;margin-top:10px\">{$caption}</p>" : '';
        return "<section style=\"padding:60px 24px\"><div style=\"max-width:960px;margin:0 auto\">{$head}{$media}{$cap}</div></section>";
    }

    /** Allow-listed hosts only; returns a privacy-enhanced embed URL or null. */
    protected function editorialVideoEmbedUrl(string $url): ?string
    {
        if ($url === '') return null;
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return null;
        $host = strtolower($p['host']);
        $host = preg_replace('/^www\./', '', $host);
        $path = (string) ($p['path'] ?? '');
        parse_str((string) ($p['query'] ?? ''), $qs);
        $id = null;
        if ($host === 'youtu.be') $id = ltrim($path, '/');
        elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_\-]{6,})#', $path, $m)) $id = $m[1];
            elseif (!empty($qs['v'])) $id = (string) $qs['v'];
        }
        if ($id !== null && preg_match('/^[A-Za-z0-9_\-]{6,20}$/', $id)) {
            return 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0';
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(?:video/)?(\d{6,12})#', $path, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
        }
        return null;
    }

    // ─── directory ─────────────────────────────────────────────────────────
    private function renderDirectory(array $sec, array $brand, array $website = []): string
    {
        $heading = e((string) ($sec['heading'] ?? 'Directory'));
        $eyebrow = e((string) ($sec['eyebrow'] ?? ''));
        $sub     = e((string) ($sec['subheading'] ?? ''));
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $head = ($eyebrow !== '' ? "<div style=\"font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:{$primary};margin-bottom:6px\">{$eyebrow}</div>" : '')
              . "<h2 style=\"font-family:'{$fh}',sans-serif;font-size:clamp(24px,3vw,36px);margin:0 0 6px\">{$heading}</h2>"
              . ($sub !== '' ? "<p style=\"color:#5a5f72;margin:0 0 24px\">{$sub}</p>" : '<div style="height:16px"></div>');
        $rows = $this->editorialListings($website, $sec);
        $ctaText = e((string) ($sec['cta_text'] ?? ''));
        $ctaUrl  = $this->safeUrl((string) ($sec['cta_url'] ?? ''), '#');
        $cta = $ctaText !== '' ? "<div style=\"margin-top:24px\"><a href=\"{$ctaUrl}\" style=\"color:{$primary};font-weight:700;text-decoration:none\">{$ctaText} →</a></div>" : '';
        if ($rows === []) {
            return "<section style=\"padding:60px 24px;background:#f8f9fc\"><div style=\"max-width:1100px;margin:0 auto\">{$head}<p style=\"color:#5a5f72\">Listings are being verified by the desk. Check back soon.</p>{$cta}</div></section>";
        }
        $cards = '';
        foreach ($rows as $r) {
            $n   = e((string) $r->name);
            $u   = '/spots/' . e((string) $r->slug);
            $cat = e((string) ($r->category_slug ?? ''));
            $loc = e(trim(((string) ($r->city ?? '')) . (!empty($r->region) && $r->region !== $r->city ? ', ' . $r->region : '')));
            $img = $this->safeUrl((string) ($r->cover_image_url ?? ''), '');
            $imgHtml = $img !== '' ? "<img src=\"{$img}\" alt=\"{$n}\" loading=\"lazy\" style=\"width:100%;aspect-ratio:4/3;object-fit:cover;display:block;border-radius:8px\">" : "<div style=\"width:100%;aspect-ratio:4/3;background:linear-gradient(135deg,{$primary}22,{$primary}55);border-radius:8px\"></div>";
            $cards .= "<a href=\"{$u}\" style=\"text-decoration:none;color:inherit;display:block\">{$imgHtml}<div style=\"font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:{$primary};margin:10px 0 4px\">{$cat}</div><h3 style=\"font-family:'{$fh}',sans-serif;font-size:1.05rem;margin:0\">{$n}</h3><div style=\"font-size:.85rem;color:#8a8f9f;margin-top:4px\">{$loc}</div></a>";
        }
        return "<section style=\"padding:60px 24px;background:#f8f9fc\"><div style=\"max-width:1100px;margin:0 auto\">{$head}<div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:24px\">{$cards}</div>{$cta}</div></section>";
    }

    /** Published listings if the directory tables exist (G3), else []. Never throws. */
    protected function editorialListings(array $website, array $sec): array
    {
        try {
            if (!Schema::hasTable('directory_listings')) return [];
            $q = DB::table('directory_listings')
                ->where('website_id', (int) ($website['id'] ?? 0))
                ->where('status', 'published')->whereNull('deleted_at');
            foreach (['category_slug' => 'category', 'city' => 'city', 'region' => 'region', 'country' => 'country'] as $col => $key) {
                $v = trim((string) ($sec[$key] ?? ''));
                if ($v !== '') $q->where($col, $v);
            }
            return $q->orderByDesc('is_featured')->orderByDesc('published_at')
                ->limit(max(1, min(96, (int) ($sec['limit'] ?? 12))))->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ─── newsletter_signup ─────────────────────────────────────────────────
    private function renderNewsletterSignup(array $sec, array $brand, array $website = []): string
    {
        $heading = e((string) ($sec['heading'] ?? 'Subscribe'));
        $eyebrow = e((string) ($sec['eyebrow'] ?? ''));
        $sub     = e((string) ($sec['subheading'] ?? ''));
        $cta     = e((string) ($sec['cta_text'] ?? 'Subscribe'));
        $ph      = e((string) ($sec['placeholder'] ?? 'your@email.com'));
        $consent = e((string) ($sec['consent_text'] ?? ''));
        $ok      = e((string) ($sec['success_text'] ?? 'Thank you. You are on the list.'));
        $source  = e(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($sec['source_tag'] ?? 'newsletter'))) ?: 'newsletter');
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $sub_ = e(str_replace('.levelupgrowth.io', '', (string) ($website['subdomain'] ?? '')));
        $wid = (int) ($website['id'] ?? 0);
        $uid = 'nl' . substr(md5($heading . $source), 0, 6);
        return <<<HTML
<section id="newsletter" style="padding:60px 24px;background:#111;color:#fff"><div style="max-width:720px;margin:0 auto;text-align:center">
{$this->editorialEyebrow($eyebrow, $primary)}
<h2 style="font-family:'{$fh}',sans-serif;font-size:clamp(24px,3vw,36px);margin:0 0 8px;color:#fff">{$heading}</h2>
<p style="opacity:.8;margin:0 0 20px">{$sub}</p>
<form id="{$uid}" onsubmit="return false" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
<input type="email" name="email" required placeholder="{$ph}" aria-label="Email address" style="flex:1 1 260px;padding:12px 14px;border-radius:6px;border:1px solid #444;background:#1c1c1c;color:#fff;font-size:1rem">
<button type="submit" style="padding:12px 22px;border-radius:6px;border:0;background:{$primary};color:#fff;font-weight:700;font-size:1rem;cursor:pointer">{$cta}</button>
</form>
<p style="font-size:.8rem;opacity:.6;margin:12px 0 0">{$consent}</p>
<p id="{$uid}-msg" style="margin:10px 0 0;font-weight:600" role="status"></p>
</div></section>
<script>(function(){var f=document.getElementById('{$uid}');if(!f)return;var m=document.getElementById('{$uid}-msg');f.addEventListener('submit',function(){var em=f.email.value.trim();if(!em)return;var b=f.querySelector('button');b.disabled=true;fetch('/api/public/contact/'+encodeURIComponent('{$sub_}'),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:em,name:em.split('@')[0],message:'Newsletter signup',source:'{$source}',website_id:{$wid},consent:true})}).then(function(r){if(!r.ok)throw 0;m.textContent='{$ok}';f.reset();}).catch(function(){m.textContent='Something went wrong. Please try again.';}).finally(function(){b.disabled=false;});});})();</script>
HTML;
    }

    protected function editorialEyebrow(string $eyebrowEscaped, string $color): string
    {
        return $eyebrowEscaped !== '' ? "<div style=\"font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:{$color};margin-bottom:8px\">{$eyebrowEscaped}</div>" : '';
    }

    // ─── jobs_board (KABAYAN888 JOBS-1) ────────────────────────────────────
    /** Published, unexpired listings for the site, or [] when the jobs table is absent. */
    protected function editorialJobs(array $website, array $sec): array
    {
        try {
            if (!Schema::hasTable('job_listings')) return [];
            $q = app(\App\Engines\Jobs\Services\JobsService::class)->publicQuery((int) ($website['id'] ?? 0));
            foreach (['category_slug' => 'category', 'city' => 'city', 'country' => 'country', 'employment_type' => 'employment_type'] as $col => $key) {
                $v = trim((string) ($sec[$key] ?? '')); if ($v !== '' && strtolower($v) !== 'all') $q->where($col, $v);
            }
            return $q->orderByDesc('is_featured')->orderByDesc('posted_at')->orderByDesc('id')->limit(max(1, min(60, (int) ($sec['limit'] ?? 20))))->get()->all();
        } catch (\Throwable) { return []; }
    }

    private function renderJobsBoard(array $sec, array $brand, array $website = []): string
    {
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $heading = e((string) ($sec['heading'] ?? 'Jobs'));
        $eyebrow = e((string) ($sec['eyebrow'] ?? ''));
        $sub = e((string) ($sec['subheading'] ?? ''));
        $head = $this->editorialEyebrow($eyebrow, $primary)
              . "<h2 style=\"font-family:'{$fh}',sans-serif;font-size:clamp(24px,3vw,36px);margin:0 0 6px\">{$heading}</h2>"
              . ($sub !== '' ? "<p style=\"color:#5a5f72;margin:0 0 20px\">{$sub}</p>" : '<div style="height:12px"></div>');
        $rows = $this->editorialJobs($website, $sec);
        if ($rows === []) {
            if (!empty($sec['hide_when_empty'])) return '';
            return "<section style=\"padding:60px 24px;background:#f8f9fc\"><div style=\"max-width:1100px;margin:0 auto\">{$head}<p style=\"color:#5a5f72\">No open positions right now. Check back soon.</p></div></section>";
        }
        $cats = \App\Engines\Jobs\Services\JobsService::CATEGORIES; $types = \App\Engines\Jobs\Services\JobsService::TYPES;
        $items = '';
        foreach ($rows as $j) {
            $t = e($j->title); $c = e($j->company); $loc = e(trim(((string) ($j->city ?? '')) . (!empty($j->region) ? ', ' . $j->region : '')) ?: ($j->is_remote ? 'Remote' : ''));
            $meta = implode(' · ', array_filter([e($types[$j->employment_type] ?? ''), $loc, e((string) ($j->salary_text ?? ''))]));
            $items .= "<a href=\"/jobs/" . e($j->slug) . "\" style=\"display:block;padding:14px 0;border-top:1px solid #e5e7ef;text-decoration:none;color:inherit\"><div style=\"font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:{$primary}\">" . e($cats[$j->category_slug ?? ''] ?? 'Jobs') . "</div><h3 style=\"font-family:'{$fh}',sans-serif;font-size:1.1rem;margin:4px 0\">{$t}</h3><div style=\"color:#1f2937;font-weight:600\">{$c}</div><div style=\"color:#6b7280;font-size:.88rem;margin-top:4px\">{$meta}</div></a>";
        }
        $cta = !empty($sec['cta_text']) ? "<div style=\"margin-top:20px\"><a href=\"" . $this->safeUrl((string) ($sec['cta_url'] ?? '#'), '#') . "\" style=\"color:{$primary};font-weight:700;text-decoration:none\">" . e((string) $sec['cta_text']) . " →</a></div>" : '';
        return "<section style=\"padding:60px 24px\"><div style=\"max-width:900px;margin:0 auto\">{$head}<div>{$items}</div>{$cta}</div></section>";
    }

    /** Plain job page body for un-themed sites. */
    protected function renderJobGeneric(array $job, array $brand): string
    {
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#1F2937';
        $fh = $brand['font_heading'] ?? 'Syne';
        $types = \App\Engines\Jobs\Services\JobsService::TYPES;
        $apply = !empty($job['apply_url']) ? "<a href=\"" . e($job['apply_url']) . "\" rel=\"nofollow noopener\" target=\"_blank\" style=\"display:inline-block;background:{$primary};color:#fff;padding:12px 20px;border-radius:6px;font-weight:700;text-decoration:none\">Apply now</a>"
               : (!empty($job['apply_email']) ? "<a href=\"mailto:" . e($job['apply_email']) . "\" style=\"display:inline-block;background:{$primary};color:#fff;padding:12px 20px;border-radius:6px;font-weight:700;text-decoration:none\">Apply by email</a>" : '');
        $loc = e(trim(((string) ($job['city'] ?? '')) . (!empty($job['region']) ? ', ' . $job['region'] : '')));
        return "<a href=\"/jobs\" style=\"color:{$primary};text-decoration:none;font-weight:700\">← All jobs</a><h1 style=\"font-family:'{$fh}',sans-serif;font-size:2rem;margin:12px 0 6px\">" . e($job['title']) . "</h1><p style=\"font-weight:600;margin:0\">" . e($job['company']) . " · {$loc} · " . e($types[$job['employment_type'] ?? ''] ?? '') . (!empty($job['salary_text']) ? ' · ' . e($job['salary_text']) : '') . "</p><div style=\"margin:20px 0;line-height:1.7\">" . (string) ($job['description'] ?? '') . "</div>{$apply}";
    }

    // ─── ad_slot ───────────────────────────────────────────────────────────
    /**
     * Inline ad position. Emits a RESERVED, hidden placeholder that the ADS888
     * tag can fill once in-content slots are wired (Phase 4 / G4). Until then it
     * costs nothing and never shifts layout. No script is emitted here — the
     * single tag loader stays with AdSlotInjector.
     */
    private function renderAdSlot(array $sec, array $brand, array $website = []): string
    {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($sec['slot_code'] ?? 'in_content_mrec'))) ?: 'in_content_mrec';
        $label = e((string) ($sec['label'] ?? 'Sponsored'));
        $wid = (int) ($website['id'] ?? 0);
        return "<div class=\"lu-ad-inline\" data-lu-slot=\"{$code}\" data-lu-site=\"{$wid}\" data-lu-label=\"{$label}\" hidden aria-hidden=\"true\"></div>";
    }
}
