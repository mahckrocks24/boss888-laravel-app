<?php

namespace App\Engines\Builder\Services;

use App\Http\Controllers\Api\Widget\ImageVariantController as Img;
use Illuminate\Support\Facades\DB;

/**
 * MRDIGITAL888 G1 (2026-09-17) — enterprise corporate theme for renderer-path sites.
 *
 * Dispatched from websites.settings_json.theme = 'mrdigital-enterprise' via ThemeRegistry.
 * Renders every SectionSchema type the MR Digital site uses (header, hero, logo_wall,
 * services, stats, process_steps, case_studies, testimonials, news_feed, cta, features,
 * pricing, generic, team, faq, contact_form, footer); unknown types go to the
 * BuilderRenderer fallback. Data-backed sections (news_feed, case_studies) read
 * `articles` at render time — Sarah owns the rows, Arthur owns presentation.
 *
 * Case studies are articles with blog_category = 'case-studies' and
 * brief_json.case_study = {sector, practice, client_display, kpis[{label,value}], duration, stack[]}
 * served at /{case_study_base}/{slug}; other articles serve at /{article_base}/{slug}.
 *
 * Pure output — no writes.
 */
class MrDigitalEnterpriseTheme
{
    private array $settings = [];
    private array $brand = [];
    private array $site = [];
    private string $base = 'insights';
    private string $csBase = 'case-studies';
    private array $catNames = [];

    private function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private function url(string $u, string $fallback = '#'): string
    {
        $u = trim($u);
        if ($u === '') return $fallback;
        if (preg_match('#^(https?:)?//#i', $u) || str_starts_with($u, '/') || str_starts_with($u, '#') || str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:')) return $this->e($u);
        return $fallback;
    }

    private function color(?string $c, string $default): string { $c = trim((string) $c); return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $default; }
    private function font(?string $f, string $default): string { $f = trim((string) $f); return preg_match('/^[a-zA-Z0-9 _-]{1,50}$/', $f) ? $f : $default; }
    private function baseSlug(?string $s, string $default): string { $s = strtolower(trim((string) $s)); return preg_match('/^[a-z0-9\-]{1,40}$/', $s) ? $s : $default; }

    private function boot(array $brand, array $website): void
    {
        $s = $website['settings_json'] ?? [];
        if (is_string($s)) $s = json_decode($s, true) ?: [];
        $this->settings = is_array($s) ? $s : [];
        $this->site = $website;
        $this->brand = [
            'primary'   => $this->color($brand['primary'] ?? $brand['primary_color'] ?? $this->settings['primary_color'] ?? null, '#0A1A33'),
            'secondary' => $this->color($brand['secondary'] ?? $brand['secondary_color'] ?? $this->settings['secondary_color'] ?? null, '#12294F'),
            'accent'    => $this->color($brand['accent'] ?? $brand['accent_color'] ?? $this->settings['accent_color'] ?? null, '#2458D6'),
            'deep'      => $this->color($this->settings['primary_deep'] ?? null, '#06122A'),
            'fh'        => $this->font($brand['font_heading'] ?? $brand['heading_font'] ?? $this->settings['font_heading'] ?? null, 'Archivo'),
            'fb'        => $this->font($brand['font_body'] ?? $brand['body_font'] ?? $this->settings['font_body'] ?? null, 'IBM Plex Sans'),
            'fm'        => $this->font($this->settings['font_mono'] ?? null, 'IBM Plex Mono'),
        ];
        $this->base = $this->baseSlug($this->settings['article_base'] ?? null, 'blog');
        $this->csBase = $this->baseSlug($this->settings['case_study_base'] ?? null, 'case-studies');
    }

    private function find(array $secs, string $type): ?array { foreach ($secs as $s) if (is_array($s) && ($s['type'] ?? '') === $type) return $s; return null; }
    private function sub(): string { return str_replace('.levelupgrowth.io', '', (string) ($this->site['subdomain'] ?? '')); }

    private function siteUrl(): string
    {
        $cd = strtolower(trim((string) ($this->site['custom_domain'] ?? ''), " /"));
        if ($cd !== '' && !empty($this->site['domain_verified']) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $cd)) return 'https://' . $cd;
        return 'https://' . $this->sub() . '.levelupgrowth.io';
    }

    private function head(): string
    {
        $css = @file_get_contents(storage_path('app/mrdigital/mrdigital-theme.css')) ?: '';
        $b = $this->brand;
        $vars = ".md-root{--md-primary:{$b['primary']};--md-secondary:{$b['secondary']};--md-accent:{$b['accent']};--md-deep:{$b['deep']};--md-fh:'{$b['fh']}',system-ui,'Segoe UI',Helvetica,Arial,sans-serif;--md-fb:'{$b['fb']}',system-ui,'Segoe UI',Helvetica,Arial,sans-serif;--md-fm:'{$b['fm']}',ui-monospace,Menlo,Consolas,monospace}";
        $fonts = '<link href="https://fonts.googleapis.com/css2?family=' . rawurlencode($b['fh']) . ':wght@400;500;600;700&family=' . rawurlencode($b['fb']) . ':wght@400;500;600&family=' . rawurlencode($b['fm']) . ':wght@400;500&display=swap" rel="stylesheet">';
        return $fonts . "\n<style>" . $vars . "\n" . $css . "</style>\n";
    }

    // ─── head extras (BuilderRenderer hook) ─────────────────────────────────
    public function headExtras(array $website, ?array $page = null, ?array $article = null, ?array $job = null): string
    {
        $this->boot([], $website);
        $name = (string) ($website['name'] ?? 'MR Digital');
        $out = '<meta name="theme-color" content="' . $this->e($this->brand['primary']) . '">' . "\n"
             . '<meta name="color-scheme" content="light dark">' . "\n"
             . '<meta property="og:locale" content="' . $this->e((string) ($this->settings['og_locale'] ?? 'en_CA')) . '">' . "\n";
        $org = ['@context' => 'https://schema.org', '@type' => 'ProfessionalService', 'name' => $name, 'url' => $this->siteUrl() . '/',
            'areaServed' => 'CA', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Vancouver', 'addressRegion' => 'BC', 'addressCountry' => 'CA']];
        if (!empty($this->settings['phone'])) $org['telephone'] = (string) $this->settings['phone'];
        if (!empty($this->settings['email'])) $org['email'] = (string) $this->settings['email'];
        $graph = [$org];
        if ($article) {
            $brief = $article['brief_json'] ?? null; if (is_string($brief)) $brief = json_decode($brief, true); $brief = is_array($brief) ? $brief : [];
            $isCase = $this->isCaseStudy((string) ($article['blog_category'] ?? ''));
            $url = $this->siteUrl() . '/' . ($isCase ? $this->csBase : $this->base) . '/' . (string) ($article['slug'] ?? '');
            $a = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => (string) ($article['title'] ?? ''), 'url' => $url, 'mainEntityOfPage' => $url,
                'author' => ['@type' => 'Organization', 'name' => $name], 'publisher' => ['@type' => 'Organization', 'name' => $name]];
            if (!empty($article['published_at'])) $a['datePublished'] = \Carbon\Carbon::parse($article['published_at'])->toIso8601String();
            if (!empty($article['updated_at'])) $a['dateModified'] = \Carbon\Carbon::parse($article['updated_at'])->toIso8601String();
            if (!empty($article['featured_image_url'])) $a['image'] = (string) $article['featured_image_url'];
            if (!empty($article['excerpt'])) $a['description'] = (string) $article['excerpt'];
            $graph[] = $a;
            $graph[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $this->siteUrl() . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $isCase ? 'Case studies' : 'Insights', 'item' => $this->siteUrl() . '/' . ($isCase ? $this->csBase : $this->base)],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string) ($article['title'] ?? '')]]];
        } elseif ($page && (string) ($page['slug'] ?? 'home') !== 'home') {
            $slug = (string) $page['slug'];
            $graph[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $this->siteUrl() . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($page['title'] ?? $slug), 'item' => $this->siteUrl() . '/' . $slug]]];
        }
        foreach ($graph as $g) $out .= '<script type="application/ld+json">' . json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        return $out;
    }

    // ─── public contract ────────────────────────────────────────────────────
    public function renderBody(array $secs, array $brand, array $website, array $page, ?callable $fallback = null): string
    {
        $this->boot($brand, $website);
        $slug = (string) ($page['slug'] ?? 'home');
        $h = $this->find($secs, 'header'); $f = $this->find($secs, 'footer');
        $body = '';
        foreach ($secs as $sec) {
            if (!is_array($sec)) continue;
            $t = $sec['type'] ?? '';
            if ($t === 'header' || $t === 'footer') continue;
            try { $body .= $this->dispatch($sec, $website, $fallback, $page); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[MrDigitalEnterpriseTheme] section failed', ['type' => $t, 'error' => $e->getMessage()]); }
        }
        return $this->shell($h, $f, $website, $slug, $body);
    }

    private function shell(?array $h, ?array $f, array $website, string $current, string $main): string
    {
        return $this->head() . '<div class="md-root" data-md-base="' . $this->e($this->base) . '">' . $this->chrome($h, $website, $current)
            . "\n<main id=\"md-main\" tabindex=\"-1\">\n" . $main . "\n</main>\n" . $this->footer($f, $website) . '</div>' . $this->js();
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

        $cat = (string) ($article['blog_category'] ?? '');
        $isCase = $this->isCaseStudy($cat);
        $catLabel = $cat !== '' ? $this->catName($cat, $website) : ($isCase ? 'Case study' : 'Insight');
        $brief = $article['brief_json'] ?? null; if (is_string($brief)) $brief = json_decode($brief, true); $brief = is_array($brief) ? $brief : [];
        $cs = is_array($brief['case_study'] ?? null) ? $brief['case_study'] : [];
        $title = $this->e($article['title'] ?? '');
        $when = !empty($article['published_at']) ? \Carbon\Carbon::parse($article['published_at']) : null;
        $tz = (string) ($this->settings['timezone'] ?? 'America/Vancouver');
        $read = (int) ($article['read_time'] ?? 0) ?: max(1, (int) round(((int) ($article['word_count'] ?? 0)) / 200));
        $author = trim((string) ($brief['author'] ?? ''));
        $excerpt = $this->e((string) ($article['excerpt'] ?? ''));
        $content = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#is', '', (string) ($article['content'] ?? ''), 1) ?? (string) ($article['content'] ?? '');
        $content = $this->contentToHtml($content);
        $imgUrl = (string) ($article['featured_image_url'] ?? '');
        $alt = (string) ($article['featured_image_alt'] ?? $article['title'] ?? '');
        $caption = trim((string) ($brief['image_caption'] ?? '')); $credit = trim((string) ($brief['image_credit'] ?? ''));
        $capHtml = ($caption !== '' || $credit !== '') ? '<figcaption>' . $this->e($caption) . ($credit !== '' ? ($caption !== '' ? ' · ' : '') . $this->e($credit) : '') . '</figcaption>' : '';
        $hero = $imgUrl !== '' ? '<figure class="md-art-hero">' . $this->img($imgUrl, $alt, 'hero') . $capHtml . '</figure>' : '';

        $listBase = $isCase ? $this->csBase : $this->base;
        $listLabel = $isCase ? 'Case studies' : 'Insights';
        $meta = '';
        if ($isCase) {
            $kp = '';
            foreach ((array) ($cs['kpis'] ?? []) as $k) { if (!is_array($k) || trim((string) ($k['value'] ?? '')) === '') continue; $kp .= '<div><b class="md-num">' . $this->e($k['value']) . '</b><small>' . $this->e((string) ($k['label'] ?? '')) . '</small></div>'; }
            if ($kp !== '') $meta .= '<div class="md-cs-head" aria-label="Results">' . $kp . '</div>';
            $bits = [];
            if (!empty($cs['client_display'])) $bits[] = 'Client · ' . $this->e($cs['client_display']);
            if (!empty($cs['sector'])) $bits[] = 'Sector · ' . $this->e($this->humanize((string) $cs['sector']));
            if (!empty($cs['practice'])) $bits[] = 'Practice · ' . $this->e($this->humanize((string) $cs['practice']));
            if (!empty($cs['duration'])) $bits[] = 'Duration · ' . $this->e($cs['duration']);
            if (!empty($cs['stack']) && is_array($cs['stack'])) $bits[] = 'Stack · ' . $this->e(implode(', ', array_map('strval', $cs['stack'])));
            if ($bits) $meta .= '<div class="md-cs-meta"><span>' . implode('</span><span>', $bits) . '</span></div>';
            if (!empty($cs['representative'])) $meta .= '<p class="md-rep">Representative engagement: composed from typical work of this kind, not a named client. Results shown are indicative of this engagement type.</p>';
        }
        $byline = '<div class="md-byline">' . ($author !== '' ? '<span>' . $this->e($author) . '</span>' : '<span>' . $this->e((string) ($website['name'] ?? 'MR Digital')) . '</span>')
            . ($when ? '<span><time datetime="' . $this->e($when->toIso8601String()) . '">' . $this->e($when->copy()->setTimezone($tz)->format('j M Y')) . '</time></span>' : '')
            . '<span>' . $read . ' min read</span></div>';
        $related = $isCase
            ? $this->caseStudies(['heading' => 'More case studies', 'limit' => 3, 'hide_when_empty' => true], $website, (int) ($article['id'] ?? 0))
            : $this->newsFeed(['eyebrow' => 'Read next', 'heading' => 'More insights', 'layout' => 'featured_row', 'limit' => 3, 'hide_when_empty' => true, 'exclude_category' => 'case-studies'], $website, (int) ($article['id'] ?? 0));
        $cta = $this->cta(['subheading' => 'Start a conversation', 'heading' => 'Bring us the system that keeps you up at night.', 'body' => 'A 45-minute discovery call with a principal engineer. No sales deck.', 'cta_text' => 'Request a proposal', 'cta_url' => '/contact']);
        $main = '<article class="md-article"><div class="md-wrap"><div class="md-art-head">'
            . '<nav class="md-crumb" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><a href="/' . $this->e($listBase) . '">' . $listLabel . '</a>' . ($isCase ? '' : '<span>/</span><span>' . $this->e($catLabel) . '</span>') . '</nav>'
            . '<h1>' . $title . '</h1>' . ($excerpt !== '' ? '<p class="md-art-dek">' . $excerpt . '</p>' : '') . $byline . $meta . '</div>'
            . $hero . '<div class="md-art-body"><div class="md-prose">' . $content . '</div><a class="md-back" href="/' . $this->e($listBase) . '">← All ' . strtolower($listLabel) . '</a></div></div></article>'
            . $related . $cta;
        return $this->shell($h, $f, $website, $isCase ? 'case-study' : 'article', $main);
    }

    // ─── dispatch ───────────────────────────────────────────────────────────
    private function dispatch(array $sec, array $website, ?callable $fallback, array $page): string
    {
        switch ($sec['type'] ?? '') {
            case 'hero':          return $this->hero($sec, $page);
            case 'logo_wall':     return $this->logoWall($sec);
            case 'services':      return $this->services($sec);
            case 'stats':         return $this->stats($sec);
            case 'process_steps': return $this->processSteps($sec);
            case 'case_studies':  return $this->caseStudies($sec, $website);
            case 'testimonials':  return $this->testimonials($sec);
            case 'news_feed':     return $this->newsFeed($sec, $website);
            case 'blog_list':     return $this->newsFeed(['heading' => $sec['heading'] ?? 'Insights', 'limit' => (int) ($sec['max_posts'] ?? 6), 'layout' => 'featured_row'] + $sec, $website);
            case 'cta':           return $this->cta($sec);
            case 'features':      return $this->features($sec);
            case 'pricing':       return $this->pricing($sec);
            case 'generic':       return $this->generic($sec);
            case 'team':          return $this->team($sec);
            case 'faq':           return $this->faq($sec);
            case 'contact_form':  return $this->contactForm($sec, $website);
            default:
                if ($fallback === null) return '';
                $html = (string) $fallback($sec);
                return $html !== '' ? '<div class="md-generic">' . $html . '</div>' : '';
        }
    }

    // ─── chrome ─────────────────────────────────────────────────────────────
    private function logoHtml(?array $h, array $website): string
    {
        $name = $this->e((string) ($h['logo_text'] ?? $website['name'] ?? 'MR Digital'));
        $logo = $this->url((string) ($h['logo_url'] ?? ''), '');
        if ($logo !== '') return '<a class="md-logo" href="/" aria-label="' . $name . ' home"><img src="' . $logo . '" alt="' . $name . '"></a>';
        $initials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', (string) ($h['logo_text'] ?? $website['name'] ?? 'MR')), 0, 2));
        $tag = $this->e((string) ($this->settings['logo_tagline'] ?? 'Enterprise Systems'));
        return '<a class="md-logo" href="/" aria-label="' . $name . ' home"><span class="md-mark">' . $this->e($initials) . '</span><span class="md-word">' . $name . '<small>' . $tag . '</small></span></a>';
    }

    private function chrome(?array $h, array $website, string $current): string
    {
        $links = '';
        foreach ((array) ($h['nav_links'] ?? []) as $l) {
            if (!is_array($l)) continue;
            $u = $this->url((string) ($l['url'] ?? ''), '#'); $lab = $this->e((string) ($l['label'] ?? ''));
            if ($lab === '') continue;
            $cur = (trim($u, '/') === $current || ($current === 'article' && trim($u, '/') === $this->base) || ($current === 'case-study' && trim($u, '/') === $this->csBase)) ? ' aria-current="page"' : '';
            $links .= '<a class="md-link" href="' . $u . '"' . $cur . '>' . $lab . '</a>';
        }
        $ctaT = $this->e((string) ($h['cta_text'] ?? ''));
        if ($ctaT !== '') $links .= '<a class="md-btn md-nav-cta" href="' . $this->url((string) ($h['cta_url'] ?? '/contact'), '/contact') . '">' . $ctaT . ' <span class="md-arr">→</span></a>';
        $top = '';
        $tl = trim((string) ($this->settings['topline'] ?? ''));
        $phone = trim((string) ($this->settings['phone'] ?? '')); $email = trim((string) ($this->settings['email'] ?? '')); $loc = trim((string) ($this->settings['location_label'] ?? 'Vancouver, BC · Pacific Time'));
        if ($tl !== '' || $phone !== '' || $email !== '') {
            $right = $this->e($loc) . ($phone !== '' ? ' · <b>' . $this->e($phone) . '</b>' : '') . ($email !== '' ? ' · <a href="mailto:' . $this->e($email) . '">' . $this->e($email) . '</a>' : '');
            $top = '<div class="md-topline"><div class="md-wrap"><span><b>' . $this->e((string) ($website['name'] ?? '')) . '</b>' . ($tl !== '' ? ' · ' . $this->e($tl) : '') . '</span><span>' . $right . '</span></div></div>';
        }
        return '<a class="md-skip" href="#md-main">Skip to content</a>' . $top
            . '<header class="md-top"><div class="md-top-in">' . $this->logoHtml($h, $website)
            . '<button type="button" class="md-toggle" data-md="toggle" aria-expanded="false" aria-controls="md-links">MENU</button>'
            . '<nav class="md-links" id="md-links" aria-label="Primary">' . $links . '</nav></div></header>';
    }

    private function footer(?array $f, array $website): string
    {
        $cols = '';
        foreach ((array) ($f['columns'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $li = '';
            foreach ((array) ($c['links'] ?? []) as $l) { if (!is_array($l)) continue; $li .= '<li><a href="' . $this->url((string) ($l['url'] ?? '#')) . '">' . $this->e((string) ($l['label'] ?? '')) . '</a></li>'; }
            $cols .= '<div><h4>' . $this->e((string) ($c['heading'] ?? '')) . '</h4><ul>' . $li . '</ul></div>';
        }
        $name = $this->e((string) ($website['name'] ?? 'MR Digital'));
        $tag = $this->e((string) ($f['tagline'] ?? ''));
        $addr = trim((string) ($f['address'] ?? '')); $email = trim((string) ($f['email'] ?? '')); $phone = trim((string) ($f['phone'] ?? ''));
        $addrHtml = ($addr !== '' || $email !== '' || $phone !== '') ? '<div class="md-addr">' . $name . ' Inc.<br>' . $this->e($addr) . ($email !== '' || $phone !== '' ? '<br>' . $this->e($email) . ($email !== '' && $phone !== '' ? ' · ' : '') . $this->e($phone) : '') . '</div>' : '';
        $badges = implode(' · ', array_map(fn ($b) => $this->e((string) $b), (array) ($f['badges'] ?? [])));
        $copy = $this->e((string) ($f['copyright'] ?? ('© ' . date('Y') . ' ' . $name . '. All rights reserved.')));
        return '<footer class="md-footer"><div class="md-wrap"><div class="md-cols"><div class="md-brand">' . $this->logoHtml(['logo_text' => $website['name'] ?? null], $website) . ($tag !== '' ? '<p>' . $tag . '</p>' : '') . $addrHtml . '</div>' . $cols . '</div>'
            . '<div class="md-base"><span>' . $copy . '</span><span>' . $badges . '</span></div></div></footer>';
    }

    // ─── helpers ────────────────────────────────────────────────────────────
    private function secHead(array $sec, string $ctaClass = 'md-btn md-ghost'): string
    {
        $eye = $this->e((string) ($sec['subheading'] ?? $sec['eyebrow'] ?? '')); $h = $this->e((string) ($sec['heading'] ?? '')); $body = $this->e((string) ($sec['body'] ?? ''));
        $cta = trim((string) ($sec['cta_text'] ?? ''));
        if ($eye === '' && $h === '' && $cta === '') return '';
        $left = ($eye !== '' ? '<p class="md-eyebrow">' . $eye . '</p>' : '') . ($h !== '' ? '<h2>' . $h . '</h2>' : '') . ($body !== '' ? '<p class="md-lede">' . $body . '</p>' : '');
        $right = $cta !== '' ? '<a class="' . $ctaClass . '" href="' . $this->url((string) ($sec['cta_url'] ?? '#')) . '">' . $this->e($cta) . ' <span class="md-arr">→</span></a>' : '';
        return '<div class="md-sec-head"><div>' . $left . '</div>' . $right . '</div>';
    }
    private function sec(string $inner, string $cls = ''): string { return '<div class="md-sec' . ($cls !== '' ? ' ' . $cls : '') . '"><div class="md-wrap">' . $inner . '</div></div>'; }
    private function humanize(string $s): string { return preg_replace_callback('/(ai|it|erp|crm|api|saas)/i', fn ($m) => strtoupper($m[1]), ucfirst(str_replace(['-', '_'], ' ', $s))); }
    private function isCaseStudy(string $cat): bool { $c = strtolower(str_replace(' ', '-', trim($cat))); return $c === 'case-studies' || $c === 'case-study'; }
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

    private function img(string $url, string $alt, string $kind = 'card'): string
    {
        $url = trim($url);
        if ($url === '' || $this->url($url, '') === '') return '';
        $main = Img::variantUrl($url, $kind === 'hero' ? 1200 : 800);
        $srcset = Img::srcset($url, [480, 800, 1200]);
        $sizes = $kind === 'hero' ? '(max-width: 1000px) 100vw, 1000px' : '(max-width: 600px) 100vw, 400px';
        $attrs = $srcset !== '' ? ' srcset="' . $this->e($srcset) . '" sizes="' . $sizes . '"' : '';
        $load = $kind === 'hero' ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"';
        return '<img src="' . $this->e($main) . '"' . $attrs . ' alt="' . $this->e($alt) . '"' . $load . '>';
    }

    private function articles(array $website, array $o = [], int $excludeId = 0)
    {
        $wsId = (int) ($website['workspace_id'] ?? 0); $wid = (int) ($website['id'] ?? 0);
        $q = DB::table('articles')->where('workspace_id', $wsId)
            ->where(function ($q) use ($wid) { if ($wid > 0) { $q->where('website_id', $wid)->orWhereNull('website_id'); } })
            ->where('is_marketing_blog', 1)->where('status', 'published')->whereNull('deleted_at');
        $cat = trim((string) ($o['category'] ?? ''));
        if ($cat !== '' && strtolower($cat) !== 'all') $q->where(function ($w) use ($cat) { $w->where('blog_category', $cat)->orWhere('blog_category', str_replace('-', ' ', $cat))->orWhereRaw('LOWER(REPLACE(blog_category, " ", "-")) = ?', [strtolower($cat)]); });
        $ex = trim((string) ($o['exclude_category'] ?? ''));
        if ($ex !== '') $q->where(function ($w) use ($ex) { $w->whereNull('blog_category')->orWhereRaw('LOWER(REPLACE(blog_category, " ", "-")) != ?', [strtolower($ex)]); });
        if ($excludeId > 0) $q->where('id', '!=', $excludeId);
        return $q->orderByDesc('published_at')->orderByDesc('id')->limit(max(1, min(48, (int) ($o['limit'] ?? 12))))
            ->get(['id', 'title', 'slug', 'excerpt', 'blog_category', 'featured_image_url', 'featured_image_alt', 'read_time', 'word_count', 'published_at', 'brief_json']);
    }
    private function brief(object $a): array { $b = $a->brief_json ?? null; if (is_string($b)) $b = json_decode($b, true); return is_array($b) ? $b : []; }
    private function readMin(object $a): int { $rt = (int) ($a->read_time ?? 0); return $rt > 0 ? $rt : max(1, (int) round(((int) ($a->word_count ?? 0)) / 200)); }
    private function when(object $a): string { return !empty($a->published_at) ? \Carbon\Carbon::parse($a->published_at)->setTimezone((string) ($this->settings['timezone'] ?? 'America/Vancouver'))->format('j M Y') : ''; }

    // ─── sections ───────────────────────────────────────────────────────────
    private function hero(array $sec, array $page): string
    {
        $eye = $this->e((string) ($sec['eyebrow'] ?? '')); $h = $this->e((string) ($sec['heading'] ?? '')); $sub = $this->e((string) ($sec['subheading'] ?? $sec['body'] ?? ''));
        $ctas = '';
        if (!empty($sec['cta_text'])) $ctas .= '<a class="md-btn" href="' . $this->url((string) ($sec['cta_url'] ?? '/contact'), '/contact') . '">' . $this->e($sec['cta_text']) . ' <span class="md-arr">→</span></a>';
        if (!empty($sec['cta_secondary_text'])) $ctas .= '<a class="md-btn md-ghost" href="' . $this->url((string) ($sec['cta_secondary_url'] ?? '#')) . '">' . $this->e($sec['cta_secondary_text']) . '</a>';
        if ($ctas !== '') $ctas = '<div class="md-ctas">' . $ctas . '</div>';
        $isHome = ($page['slug'] ?? 'home') === 'home' || !empty($sec['ledger']);
        if (!$isHome || !empty($sec['breadcrumb'])) {
            $crumb = '<nav class="md-crumb" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>' . $this->e((string) ($page['title'] ?? $sec['heading'] ?? '')) . '</span></nav>';
            return '<div class="md-phero"><div class="md-wrap">' . $crumb . ($eye !== '' ? '<p class="md-eyebrow" style="margin-top:14px">' . $eye . '</p>' : '') . '<h1>' . $h . '</h1>' . ($sub !== '' ? '<p class="md-lede">' . $sub . '</p>' : '') . $ctas . '</div></div>';
        }
        $ledger = '';
        $L = is_array($sec['ledger'] ?? null) ? $sec['ledger'] : [];
        $rows = '';
        foreach ((array) ($L['rows'] ?? []) as $r) { if (!is_array($r)) continue; $rows .= '<dt>' . $this->e((string) ($r['label'] ?? '')) . '</dt><dd>' . $this->e((string) ($r['value'] ?? '')) . '</dd>'; }
        if ($rows !== '') {
            $ledger = '<aside class="md-ledger" aria-label="' . $this->e((string) ($L['caption'] ?? 'Engagement standards')) . '"><div class="md-cap"><span>' . $this->e((string) ($L['caption'] ?? 'Engagement standards')) . '</span><i>● Current</i></div><dl>' . $rows . '</dl>'
                . (!empty($L['footnote']) ? '<div class="md-foot">' . $this->e($L['footnote']) . '</div>' : '') . '</aside>';
        }
        return '<div class="md-panel md-hero"><div class="md-wrap md-hero-grid' . ($ledger === '' ? ' md-single' : '') . '"><div>' . ($eye !== '' ? '<p class="md-eyebrow">' . $eye . '</p>' : '') . '<h1>' . $h . '</h1>' . ($sub !== '' ? '<p class="md-lede">' . $sub . '</p>' : '') . $ctas . '</div>' . $ledger . '</div></div>';
    }

    private function logoWall(array $sec): string
    {
        $items = '';
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $n = $this->e((string) ($it['name'] ?? '')); if ($n === '') continue;
            $logo = $this->url((string) ($it['logo_url'] ?? ''), '');
            $inner = $logo !== '' ? '<img src="' . $logo . '" alt="' . $n . '" loading="lazy">' : $n;
            $u = $this->url((string) ($it['url'] ?? ''), '');
            $items .= $u !== '' ? '<a class="md-lg" href="' . $u . '" rel="noopener">' . $inner . '</a>' : '<span class="md-lg">' . $inner . '</span>';
        }
        if ($items === '') return !empty($sec['hide_when_empty']) ? '' : '';
        $h = $this->e((string) ($sec['heading'] ?? ''));
        return '<div class="md-logos"><div class="md-wrap">' . ($h !== '' ? '<p class="md-eyebrow">' . $h . '</p>' : '<span></span>') . '<div class="md-row" aria-label="Clients">' . $items . '</div></div></div>';
    }

    private function services(array $sec): string
    {
        $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $layout = (string) ($sec['layout'] ?? 'ledger');
        $cards = '';
        foreach ($items as $i => $it) {
            if (!is_array($it)) continue;
            $title = $this->e((string) ($it['title'] ?? $it['name'] ?? '')); if ($title === '') continue;
            $code = $this->e((string) ($it['code'] ?? sprintf('%02d', $i + 1)));
            $slug = (string) ($it['slug'] ?? ''); $link = $this->url((string) ($it['url'] ?? ($slug !== '' ? '/services/' . $slug : '')), '');
            $desc = $this->e((string) ($it['summary'] ?? $it['description'] ?? $it['body'] ?? ''));
            $tags = ''; foreach ((array) ($it['stack'] ?? $it['tags'] ?? []) as $t) $tags .= '<li>' . $this->e((string) $t) . '</li>';
            if ($layout === 'detail') {
                $eng = ''; foreach ((array) ($it['engagements'] ?? []) as $g) $eng .= '<li>' . $this->e((string) $g) . '</li>';
                $cards .= '<article id="' . $this->e($slug ?: $this->slugify($title)) . '"><div><span class="md-code">' . $code . '</span><h3>' . ($link !== '' ? '<a href="' . $link . '" style="color:inherit">' . $title . '</a>' : $title) . '</h3></div>'
                    . '<div><p>' . $desc . '</p>' . ($tags !== '' ? '<ul class="md-tags">' . $tags . '</ul>' : '') . '</div>'
                    . '<div>' . ($eng !== '' ? '<h4>Typical engagements</h4><ul class="md-dash">' . $eng . '</ul>' : '') . '</div></article>';
            } else {
                $cards .= '<article><span class="md-code">' . $code . ' · ' . mb_strtoupper($this->e((string) ($it['short'] ?? ''))) . '</span><h3>' . $title . '</h3><p>' . $desc . '</p>' . ($tags !== '' ? '<ul class="md-tags">' . $tags . '</ul>' : '')
                    . ($link !== '' ? '<a class="md-more" href="' . $link . '">Explore →</a>' : '') . '</article>';
            }
        }
        if ($cards === '') return '';
        return $this->sec($this->secHead($sec) . ($layout === 'detail' ? '<div class="md-sdet">' . $cards . '</div>' : '<div class="md-svc">' . $cards . '</div>'));
    }

    private function stats(array $sec): string
    {
        $tiles = '';
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $v = trim((string) ($it['value'] ?? '')); if ($v === '') continue;
            if (preg_match('/^([\$€£]?[\d.,]+)\s*(.*)$/u', $v, $m) && $m[2] !== '') $vh = $this->e($m[1]) . '<sup>' . $this->e($m[2]) . '</sup>'; else $vh = $this->e($v);
            $tiles .= '<div><div class="md-v md-num">' . $vh . '</div><div class="md-l">' . $this->e((string) ($it['label'] ?? '')) . '</div></div>';
        }
        if ($tiles === '') return '';
        $h = $this->e((string) ($sec['heading'] ?? ''));
        return '<div class="md-panel md-sec"><div class="md-wrap">' . ($h !== '' ? '<p class="md-eyebrow">' . $h . '</p>' : '') . '<div class="md-stats">' . $tiles . '</div></div></div>';
    }

    private function processSteps(array $sec): string
    {
        $li = ''; $n = 0;
        foreach ((array) ($sec['steps'] ?? []) as $st) {
            if (!is_array($st)) continue; $n++;
            $li .= '<li><span class="md-n">STAGE ' . $n . '</span><h4>' . $this->e((string) ($st['title'] ?? '')) . '</h4><p>' . $this->e((string) ($st['body'] ?? '')) . '</p>'
                . (!empty($st['deliverable']) ? '<div class="md-out">Deliverable · <b>' . $this->e($st['deliverable']) . '</b></div>' : '') . '</li>';
        }
        if ($li === '') return '';
        return $this->sec($this->secHead($sec) . '<ol class="md-steps" style="--n:' . $n . '">' . $li . '</ol>');
    }

    private function caseCard(object $a): string
    {
        $b = $this->brief($a); $cs = is_array($b['case_study'] ?? null) ? $b['case_study'] : [];
        $kp = ''; $c = 0;
        foreach ((array) ($cs['kpis'] ?? []) as $k) { if (!is_array($k) || $c >= 2) continue; $kp .= '<div><b class="md-num">' . $this->e((string) ($k['value'] ?? '')) . '</b><small>' . $this->e((string) ($k['label'] ?? '')) . '</small></div>'; $c++; }
        $tag = trim($this->humanize((string) ($cs['sector'] ?? '')) . ((!empty($cs['sector']) && !empty($cs['practice'])) ? ' · ' : '') . $this->humanize((string) ($cs['practice'] ?? '')));
        $img = $this->img((string) ($a->featured_image_url ?? ''), (string) ($a->featured_image_alt ?? $a->title));
        return '<a class="md-case" href="/' . $this->e($this->csBase) . '/' . $this->e($a->slug) . '" data-practice="' . $this->e((string) ($cs['practice'] ?? '')) . '" data-sector="' . $this->e((string) ($cs['sector'] ?? '')) . '"><div class="md-fig">' . $img . ($tag !== '' ? '<span>' . $this->e($tag) . '</span>' : '') . '</div>'
            . '<div class="md-body"><h3>' . $this->e($a->title) . '</h3>' . (!empty($a->excerpt) ? '<p>' . $this->e(mb_substr((string) $a->excerpt, 0, 160)) . '</p>' : '') . ($kp !== '' ? '<div class="md-kpis">' . $kp . '</div>' : '') . '</div></a>';
    }

    private function caseStudies(array $sec, array $website, int $excludeId = 0): string
    {
        $rows = $this->articles($website, ['category' => 'case-studies', 'limit' => (int) ($sec['limit'] ?? 6)], $excludeId)->all();
        $pr = trim((string) ($sec['practice'] ?? '')); $se = trim((string) ($sec['sector'] ?? ''));
        if ($pr !== '' || $se !== '') $rows = array_values(array_filter($rows, function ($a) use ($pr, $se) { $cs = $this->brief($a)['case_study'] ?? []; return ($pr === '' || ($cs['practice'] ?? '') === $pr) && ($se === '' || ($cs['sector'] ?? '') === $se); }));
        $head = $this->secHead($sec);
        if (!$rows) {
            if (!empty($sec['hide_when_empty']) || $excludeId > 0) return '';
            return $this->sec($head . '<p class="md-empty">' . $this->e((string) ($sec['empty_text'] ?? 'Case studies are being prepared for publication.')) . '</p>');
        }
        $chips = '';
        if (!empty($sec['show_filters'])) {
            $by = (string) ($sec['filter_by'] ?? 'practice'); $vals = [];
            foreach ($rows as $a) { $v = (string) (($this->brief($a)['case_study'] ?? [])[$by] ?? ''); if ($v !== '') $vals[$v] = $this->humanize($v); }
            if (count($vals) > 1) { $chips = '<div class="md-chips" role="group" aria-label="Filter" data-md-filter="' . $this->e($by) . '"><button type="button" class="md-chip" aria-pressed="true" data-v="">All</button>'; foreach ($vals as $v => $lab) $chips .= '<button type="button" class="md-chip" aria-pressed="false" data-v="' . $this->e($v) . '">' . $this->e($lab) . '</button>'; $chips .= '</div>'; }
        }
        $cards = ''; foreach ($rows as $a) $cards .= $this->caseCard($a);
        return $this->sec($head . $chips . '<div class="md-cases" data-md-list="1">' . $cards . '</div>', 'md-alt');
    }

    private function testimonials(array $sec): string
    {
        $qs = '';
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $q = $this->e((string) ($it['quote'] ?? $it['text'] ?? '')); if ($q === '') continue;
            $name = (string) ($it['name'] ?? $it['author'] ?? ''); $role = (string) ($it['role'] ?? $it['title'] ?? '');
            $ini = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2))));
            $av = !empty($it['image']) ? '<img src="' . $this->url((string) $it['image']) . '" alt="">' : $this->e($ini);
            $qs .= '<div class="md-quote"><div class="md-mark">“</div><div><p class="md-q">' . $q . '</p><div class="md-who"><div class="md-av">' . $av . '</div><div><b>' . $this->e($name) . '</b><span>' . $this->e($role) . '</span></div></div></div></div>';
        }
        if ($qs === '') return '';
        return $this->sec($this->secHead($sec) . '<div class="md-quotes">' . $qs . '</div>');
    }

    private function newsFeed(array $sec, array $website, int $excludeId = 0): string
    {
        $layout = (string) ($sec['layout'] ?? 'featured_row');
        $rows = $this->articles($website, ['limit' => (int) ($sec['limit'] ?? 6), 'category' => $sec['category'] ?? '', 'exclude_category' => $sec['exclude_category'] ?? ''], $excludeId)->all();
        $head = $this->secHead($sec);
        if (!$rows) {
            if (!empty($sec['hide_when_empty']) || $excludeId > 0) return '';
            return $this->sec($head . '<p class="md-empty">Insights are being prepared for publication.</p>');
        }
        $showEx = !array_key_exists('show_excerpt', $sec) || !empty($sec['show_excerpt']);
        $catOf = fn ($a) => (string) ($a->blog_category ?? '');
        if ($layout === 'featured_list') {
            $chips = '';
            if (!empty($sec['show_category_chips'])) {
                $vals = []; foreach ($rows as $a) { $c = $catOf($a); if ($c !== '') $vals[$this->slugify($c)] = $this->catName($c, $website); }
                if (count($vals) > 1) { $chips = '<div class="md-chips" role="group" aria-label="Filter insights" data-md-filter="cat"><button type="button" class="md-chip" aria-pressed="true" data-v="">All</button>'; foreach ($vals as $v => $lab) $chips .= '<button type="button" class="md-chip" aria-pressed="false" data-v="' . $this->e($v) . '">' . $this->e($lab) . '</button>'; $chips .= '</div>'; }
            }
            $lead = array_shift($rows);
            $b = $this->brief($lead);
            $feat = '<a class="md-feat" href="/' . $this->e($this->base) . '/' . $this->e($lead->slug) . '" data-cat="' . $this->e($this->slugify($catOf($lead))) . '"><div class="md-fig">' . $this->img((string) ($lead->featured_image_url ?? ''), (string) ($lead->featured_image_alt ?? $lead->title), 'lead') . '</div><div><span class="md-cat">Featured · ' . $this->e($this->catName($catOf($lead), $website)) . '</span><h2>' . $this->e($lead->title) . '</h2>'
                . (!empty($lead->excerpt) ? '<p>' . $this->e($lead->excerpt) . '</p>' : '') . '<p class="md-meta">' . $this->e($this->when($lead)) . ' · ' . $this->readMin($lead) . ' min read' . (!empty($b['author']) ? ' · ' . $this->e($b['author']) : '') . '</p></div></a>';
            $list = '';
            foreach ($rows as $a) $list .= '<a class="md-arow" href="/' . $this->e($this->base) . '/' . $this->e($a->slug) . '" data-cat="' . $this->e($this->slugify($catOf($a))) . '"><span class="md-cat">' . $this->e($this->catName($catOf($a), $website)) . '</span><div><h3>' . $this->e($a->title) . '</h3>' . ($showEx && !empty($a->excerpt) ? '<p>' . $this->e(mb_substr((string) $a->excerpt, 0, 180)) . '</p>' : '') . '</div><span class="md-meta">' . $this->e($this->when($a)) . ' · ' . $this->readMin($a) . ' min</span></a>';
            return $this->sec($head . $chips . '<div data-md-list="1">' . $feat . '<div class="md-alist">' . $list . '</div></div>');
        }
        $cards = '';
        foreach ($rows as $i => $a) {
            $cards .= '<a class="md-post" href="/' . $this->e($this->base) . '/' . $this->e($a->slug) . '"><span class="md-cat">' . $this->e($this->catName($catOf($a), $website)) . '</span><h3>' . $this->e($a->title) . '</h3>'
                . ($showEx && $i === 0 && !empty($a->excerpt) ? '<p>' . $this->e(mb_substr((string) $a->excerpt, 0, 200)) . '</p>' : '') . '<span class="md-meta">' . $this->e($this->when($a)) . ' · ' . $this->readMin($a) . ' min read</span></a>';
        }
        return $this->sec($head . '<div class="md-posts">' . $cards . '</div>', 'md-alt');
    }

    private function cta(array $sec): string
    {
        $ctas = '';
        if (!empty($sec['cta_text'])) $ctas .= '<a class="md-btn" href="' . $this->url((string) ($sec['cta_url'] ?? '/contact'), '/contact') . '">' . $this->e($sec['cta_text']) . ' <span class="md-arr">→</span></a>';
        if (!empty($sec['cta_secondary_text'])) $ctas .= '<a class="md-btn md-ghost" href="' . $this->url((string) ($sec['cta_secondary_url'] ?? '/contact'), '/contact') . '">' . $this->e($sec['cta_secondary_text']) . '</a>';
        $eye = $this->e((string) ($sec['subheading'] ?? $sec['eyebrow'] ?? '')); $body = $this->e((string) ($sec['body'] ?? ''));
        return '<div class="md-panel md-sec md-cta"><div class="md-wrap"><div>' . ($eye !== '' ? '<p class="md-eyebrow">' . $eye . '</p>' : '') . '<h2>' . $this->e((string) ($sec['heading'] ?? '')) . '</h2>' . ($body !== '' ? '<p class="md-lede">' . $body . '</p>' : '') . '</div>' . ($ctas !== '' ? '<div class="md-ctas">' . $ctas . '</div>' : '') . '</div></div>';
    }

    private function features(array $sec): string
    {
        $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $layout = (string) ($sec['layout'] ?? 'grid');
        $out = '';
        if ($layout === 'industry_grid') {
            foreach ($items as $i => $it) {
                if (!is_array($it)) continue;
                $t = $this->e((string) ($it['title'] ?? '')); if ($t === '') continue;
                $u = $this->url((string) ($it['url'] ?? '/contact'), '/contact');
                $out .= '<a class="md-ind" id="' . $this->e((string) ($it['slug'] ?? $this->slugify($t))) . '" href="' . $u . '"><span class="md-code">IND · ' . $this->e((string) ($it['code'] ?? sprintf('%02d', $i + 1))) . '</span><h3>' . $t . '</h3><p>' . $this->e((string) ($it['body'] ?? $it['description'] ?? '')) . '</p>'
                    . (!empty($it['regulation']) ? '<span class="md-reg">' . $this->e($it['regulation']) . '</span>' : '') . '</a>';
            }
            return $out === '' ? '' : $this->sec($this->secHead($sec) . '<div class="md-inds">' . $out . '</div>');
        }
        if ($layout === 'tags') {
            foreach ($items as $it) { $t = is_array($it) ? (string) ($it['title'] ?? '') : (string) $it; if ($t !== '') $out .= '<span>' . $this->e($t) . '</span>'; }
            return $out === '' ? '' : $this->sec($this->secHead($sec) . '<div class="md-tags">' . $out . '</div>');
        }
        foreach ($items as $it) {
            $t = is_array($it) ? (string) ($it['title'] ?? '') : (string) $it; if ($t === '') continue;
            $out .= '<div><h4>' . $this->e($t) . '</h4>' . (is_array($it) && !empty($it['body'] ?? $it['description'] ?? '') ? '<p>' . $this->e((string) ($it['body'] ?? $it['description'])) . '</p>' : '') . '</div>';
        }
        if ($out === '') return '';
        $cols = max(2, min(4, (int) ($sec['columns'] ?? 3)));
        return $this->sec($this->secHead($sec) . '<div class="md-feats" style="--cols:' . $cols . '">' . $out . '</div>');
    }

    private function pricing(array $sec): string
    {
        $out = '';
        foreach ((array) ($sec['tiers'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $name = $this->e((string) ($t['name'] ?? '')); if ($name === '') continue;
            $facts = ''; foreach ((array) ($t['facts'] ?? []) as $f) if (is_array($f)) $facts .= '<dt>' . $this->e((string) ($f['label'] ?? '')) . '</dt><dd>' . $this->e((string) ($f['value'] ?? '')) . '</dd>';
            $feat = ''; foreach ((array) ($t['features'] ?? []) as $f) $feat .= '<li>' . $this->e((string) $f) . '</li>';
            $price = (!empty($t['price']) && ($sec['show_prices'] ?? true)) ? '<div class="md-price">' . $this->e($t['price']) . (!empty($t['period']) ? ' <small>' . $this->e($t['period']) . '</small>' : '') . '</div>' : '';
            $out .= '<div class="md-model' . (!empty($t['highlight']) ? ' is-pref' : '') . '">' . (!empty($t['tag']) ? '<span class="md-tag">' . $this->e($t['tag']) . '</span>' : '') . '<h3>' . $name . '</h3>' . $price . (!empty($t['body'] ?? $t['description'] ?? '') ? '<p>' . $this->e((string) ($t['body'] ?? $t['description'])) . '</p>' : '')
                . ($facts !== '' ? '<dl>' . $facts . '</dl>' : '') . ($feat !== '' ? '<ul>' . $feat . '</ul>' : '') . (!empty($t['cta_text']) ? '<a class="md-btn md-ghost" href="' . $this->url((string) ($t['cta_url'] ?? '/contact'), '/contact') . '">' . $this->e($t['cta_text']) . '</a>' : '') . '</div>';
        }
        return $out === '' ? '' : $this->sec($this->secHead($sec) . '<div class="md-models">' . $out . '</div>', 'md-alt');
    }

    private function generic(array $sec): string
    {
        $h = $this->e((string) ($sec['heading'] ?? ''));
        $content = (string) ($sec['content'] ?? $sec['body'] ?? '');
        $prose = ($h !== '' ? '<p class="md-eyebrow" style="margin-bottom:14px">' . $h . '</p>' : '') . '<div class="md-prose">' . $this->contentToHtml($content) . '</div>';
        $facts = '';
        foreach ((array) ($sec['facts'] ?? []) as $f) if (is_array($f)) $facts .= '<div><dt>' . $this->e((string) ($f['label'] ?? '')) . '</dt><dd>' . $this->e((string) ($f['value'] ?? '')) . '</dd></div>';
        if ($facts !== '') return $this->sec('<div class="md-two"><div>' . $prose . '</div><dl class="md-facts">' . $facts . '</dl></div>');
        return $this->sec('<div style="max-width:760px">' . $prose . '</div>');
    }

    private function team(array $sec): string
    {
        $out = '';
        foreach ((array) ($sec['members'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $n = (string) ($m['name'] ?? ''); if ($n === '') continue;
            $ini = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
            $photo = $this->url((string) ($m['photo'] ?? $m['image'] ?? ''), '');
            $out .= '<div class="md-person"><div class="md-ph' . ($photo !== '' ? ' has-img' : '') . '" data-init="' . $this->e(preg_match('/[A-Za-z]/', $ini) ? $ini : '—') . '">' . ($photo !== '' ? '<img src="' . $photo . '" alt="' . $this->e($n) . '" loading="lazy">' : '') . '</div><b>' . $this->e($n) . '</b><span>' . $this->e((string) ($m['role'] ?? '')) . '</span>' . (!empty($m['bio']) ? '<p>' . $this->e($m['bio']) . '</p>' : '') . '</div>';
        }
        return $out === '' ? '' : $this->sec($this->secHead($sec) . '<div class="md-team">' . $out . '</div>', 'md-alt');
    }

    private function faq(array $sec): string
    {
        $out = '';
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $q = $this->e((string) ($it['q'] ?? $it['question'] ?? '')); if ($q === '') continue;
            $out .= '<details><summary>' . $q . '</summary><div class="md-a">' . $this->e((string) ($it['a'] ?? $it['answer'] ?? '')) . '</div></details>';
        }
        if ($out === '') return '';
        $faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
        foreach ((array) ($sec['items'] ?? []) as $it) if (is_array($it) && !empty($it['q'] ?? $it['question'] ?? '')) $faqLd['mainEntity'][] = ['@type' => 'Question', 'name' => (string) ($it['q'] ?? $it['question']), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($it['a'] ?? $it['answer'] ?? '')]];
        return $this->sec($this->secHead($sec) . '<div class="md-faq" style="max-width:820px">' . $out . '</div>') . '<script type="application/ld+json">' . json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    private function contactForm(array $sec, array $website): string
    {
        $sub = $this->e($this->sub()); $wid = (int) ($website['id'] ?? 0); $source = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($sec['source'] ?? 'website_form'))) ?: 'website_form';
        $fields = is_array($sec['fields'] ?? null) && $sec['fields'] ? $sec['fields'] : [
            ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true], ['name' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'], ['name' => 'brief', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => true]];
        $fh = '';
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $n = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($f['name'] ?? ''))); if ($n === '') continue;
            $lab = $this->e((string) ($f['label'] ?? ucfirst($n))); $type = (string) ($f['type'] ?? 'text'); $req = !empty($f['required']) ? ' required' : ''; $id = 'md-f-' . $n;
            $ac = !empty($f['autocomplete']) ? ' autocomplete="' . $this->e($f['autocomplete']) . '"' : '';
            if ($type === 'textarea') $fh .= '<div class="md-field md-full"><label for="' . $id . '">' . $lab . '</label><textarea id="' . $id . '" name="' . $n . '"' . $req . '></textarea></div>';
            elseif ($type === 'choice' || $type === 'multi_choice') {
                $opts = ''; foreach ((array) ($f['options'] ?? []) as $o) $opts .= '<button type="button" class="md-opt" aria-pressed="false" data-v="' . $this->e((string) $o) . '">' . $this->e((string) $o) . '</button>';
                $fh .= '<div class="md-field md-full" data-md-group="' . $n . '" data-multi="' . ($type === 'multi_choice' ? '1' : '0') . '" data-label="' . $lab . '"><span class="md-lbl">' . $lab . '</span><div class="md-opts" role="group" aria-label="' . $lab . '">' . $opts . '</div></div>';
            } else $fh .= '<div class="md-field"><label for="' . $id . '">' . $lab . '</label><input id="' . $id . '" name="' . $n . '" type="' . $this->e(in_array($type, ['text', 'email', 'tel', 'url'], true) ? $type : 'text') . '"' . $req . $ac . '></div>';
        }
        $form = '<form class="md-form" id="md-rfp" data-sub="' . $sub . '" data-wid="' . $wid . '" data-source="' . $this->e($source) . '" novalidate><h3>' . $this->e((string) ($sec['heading'] ?? 'Contact us')) . '</h3>' . (!empty($sec['subheading']) ? '<p class="md-sub">' . $this->e($sec['subheading']) . '</p>' : '')
            . '<div class="md-frow">' . $fh . '</div><div class="md-submit">' . (!empty($sec['fine_print']) ? '<p class="md-fine">' . $this->e($sec['fine_print']) . '</p>' : '<span></span>') . '<button class="md-btn" type="submit">' . $this->e((string) ($sec['submit_label'] ?? 'Send')) . ' <span class="md-arr">→</span></button></div><div class="md-msg" id="md-rfp-msg" role="status" data-ok="' . $this->e((string) ($sec['success_text'] ?? 'Thank you — we will reply within one business day.')) . '"></div></form>';
        $side = '';
        $sb = is_array($sec['sidebar'] ?? null) ? $sec['sidebar'] : [];
        $facts = '';
        foreach ((array) ($sb['facts'] ?? []) as $f) if (is_array($f)) $facts .= '<div><dt>' . $this->e((string) ($f['label'] ?? '')) . '</dt><dd>' . $this->e((string) ($f['value'] ?? '')) . '</dd></div>';
        if ($facts === '') { foreach (['Email' => $sec['email'] ?? '', 'Phone' => $sec['phone'] ?? '', 'Address' => $sec['address'] ?? ''] as $k => $v) if (trim((string) $v) !== '') $facts .= '<div><dt>' . $k . '</dt><dd>' . $this->e($v) . '</dd></div>'; }
        $steps = ''; $i = 0;
        foreach ((array) ($sb['next_steps'] ?? []) as $st) { if (!is_array($st)) continue; $i++; $steps .= '<li><b>' . sprintf('%02d', $i) . '</b><span><strong>' . $this->e((string) ($st['title'] ?? '')) . '</strong> ' . $this->e((string) ($st['body'] ?? '')) . '</span></li>'; }
        if ($facts !== '' || $steps !== '') $side = '<aside class="md-side">' . (!empty($sb['heading']) ? '<h3>' . $this->e($sb['heading']) . '</h3>' : '') . ($facts !== '' ? '<dl class="md-facts">' . $facts . '</dl>' : '') . ($steps !== '' ? '<div class="md-next"><p class="md-eyebrow">What happens next</p><ol>' . $steps . '</ol></div>' : '') . '</aside>';
        return $this->sec('<div class="md-contact">' . $form . $side . '</div>');
    }

    private function contentToHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('/<(p|h[1-6]|ul|ol|div|blockquote|table|figure|pre)\b/i', $raw)) return $raw;
        $parts = preg_split('/\n{2,}/', str_replace("\r\n", "\n", $raw));
        $out = '';
        foreach ($parts as $p) { $p = trim($p); if ($p === '') continue; if (preg_match('/^#{1,3}\s+(.+)$/', $p, $m)) $out .= '<h2>' . $this->e($m[1]) . '</h2>'; else $out .= '<p>' . nl2br($this->e($p)) . '</p>'; }
        return $out;
    }

    private function js(): string
    {
        return <<<'JS'
<script>(function(){var d=document;var t=d.querySelector('[data-md="toggle"]'),n=d.getElementById('md-links');if(t&&n){t.addEventListener('click',function(){var o=n.classList.toggle('is-open');t.setAttribute('aria-expanded',o?'true':'false')})}
d.querySelectorAll('[data-md-filter]').forEach(function(g){var by=g.getAttribute('data-md-filter'),list=g.parentNode.querySelector('[data-md-list]');if(!list)return;g.addEventListener('click',function(e){var b=e.target.closest('.md-chip');if(!b)return;g.querySelectorAll('.md-chip').forEach(function(c){c.setAttribute('aria-pressed','false')});b.setAttribute('aria-pressed','true');var v=b.getAttribute('data-v')||'';list.querySelectorAll('[data-'+by+']').forEach(function(el){el.setAttribute('data-md-hidden',(!v||el.getAttribute('data-'+by)===v)?'0':'1')})})});
d.querySelectorAll('[data-md-group]').forEach(function(g){var multi=g.getAttribute('data-multi')==='1';g.addEventListener('click',function(e){var b=e.target.closest('.md-opt');if(!b)return;if(!multi)g.querySelectorAll('.md-opt').forEach(function(o){if(o!==b)o.setAttribute('aria-pressed','false')});b.setAttribute('aria-pressed',b.getAttribute('aria-pressed')==='true'?'false':'true')})});
var f=d.getElementById('md-rfp');if(f){var m=d.getElementById('md-rfp-msg');f.addEventListener('submit',function(e){e.preventDefault();var fd=new FormData(f),g=function(k){return (fd.get(k)||'').toString().trim()};var name=g('name')||g('firstname'),email=g('email');if(!name||!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){m.textContent='Please enter your name and a valid work email.';m.className='md-msg is-err';return}
var lines=[];f.querySelectorAll('.md-field').forEach(function(fl){var grp=fl.getAttribute('data-md-group');if(grp){var vals=[];fl.querySelectorAll('.md-opt[aria-pressed="true"]').forEach(function(o){vals.push(o.getAttribute('data-v'))});if(vals.length)lines.push(fl.getAttribute('data-label')+': '+vals.join(', '));return}var inp=fl.querySelector('input,textarea');if(!inp||['name','firstname','email','phone'].indexOf(inp.name)>=0)return;var v=inp.value.trim();if(v)lines.push((fl.querySelector('label')||{}).textContent+': '+v)});
var msg=lines.join('\n')||'Contact request';var b=f.querySelector('button[type="submit"]');b.disabled=true;m.className='md-msg';m.textContent='Sending…';
fetch('/api/public/contact/'+encodeURIComponent(f.getAttribute('data-sub')),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({firstname:name,name:name,email:email,phone:g('phone'),message:msg.slice(0,1900),source:f.getAttribute('data-source')||'website_form',website_id:parseInt(f.getAttribute('data-wid')||'0',10),company:g('organisation')})}).then(function(r){if(!r.ok)throw 0;m.textContent=m.getAttribute('data-ok');f.reset();f.querySelectorAll('.md-opt').forEach(function(o){o.setAttribute('aria-pressed','false')})}).catch(function(){m.textContent='Something went wrong. Please email us directly.';m.className='md-msg is-err'}).finally(function(){b.disabled=false})})}})();</script>
JS;
    }
}
