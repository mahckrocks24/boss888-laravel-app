<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

/**
 * TRAVEL-FRIENDLY theme (SGTRAVEL T1, 2026-09-21) — the AMG "Friendly" travel design generalised: brand tokens,
 * prototype, hydrated from pages.sections_json. Multi-page: renders ANY page
 * by dispatching its sections in order. Gated by
 * contact, social and copy come from websites.settings_json (theme = 'travel-friendly'). Self-contained CSS
 * (amg-theme.css). Chatbot888 injected separately by middleware.
 */
class TravelFriendlyTheme
{
    /** SGTRAVEL T1 (2026-09-21) — site settings (colours, contact, social) drive everything AMG hard-coded. */
    private array $settings = [];
    private string $siteName = '';
    private function bootSettings(array $website): void
    {
        $s = $website['settings_json'] ?? []; if (is_string($s)) $s = json_decode($s, true) ?: [];
        $this->settings = is_array($s) ? $s : [];
        $this->siteName = (string) ($website['name'] ?? 'Travel & Tours');
    }
    private function set(string $k, string $d = ''): string { $v = trim((string) ($this->settings[$k] ?? '')); return $v !== '' ? $v : $d; }
    private function col(string $k, string $d): string { $v = trim((string) ($this->settings[$k] ?? '')); return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $d; }
    private function mix(string $hex, string $with, float $t): string { $a = sscanf($hex, '#%02x%02x%02x'); $b = sscanf($with, '#%02x%02x%02x'); return sprintf('#%02x%02x%02x', (int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)); }
    private function rgb(string $hex): string { $a = sscanf($hex, '#%02x%02x%02x'); return $a[0] . ',' . $a[1] . ',' . $a[2]; }
    /** Brand tokens → the Friendly CSS variables (emitted after the stylesheet so they win). */
    private function tokens(): string
    {
        $sky = $this->col('primary_color', '#1B6FC4'); $deep = $this->col('primary_deep', $this->mix($sky, '#000000', .35));
        $sec = $this->col('secondary_color', '#19A7B5'); $acc = $this->col('accent_color', '#F4C95D'); $hi = $this->col('highlight_color', $acc);
        $ink = $this->mix($deep, '#000000', .2); $soft = $this->mix($deep, '#8A99AD', .55); $mist = $this->mix($sky, '#FFFFFF', .9); $cloud = $this->mix($sky, '#FFFFFF', .975); $silver = $this->mix($sky, '#FFFFFF', .78);
        $r = $this->rgb($sky);
        return ":root{--sky:{$sky};--navy:{$sky};--navy-deep:{$deep};--teal:{$sec};--emerald:{$sec};--aqua:" . $this->mix($sec, '#FFFFFF', .28) . ";--emerald-bright:" . $this->mix($sec, '#FFFFFF', .28) . ";--lime:{$acc};--sand:{$hi};--gold-line:{$hi};--mist:{$mist};--cloud:{$cloud};--bone:{$cloud};--silver:{$silver};--ink:{$ink};--ink-soft:{$soft};--line:rgba({$r},.12);--line-strong:rgba({$r},.24);--shadow-sm:0 2px 6px rgba({$r},.06),0 8px 22px rgba({$r},.07);--shadow-md:0 12px 34px rgba({$r},.13);--shadow-lg:0 28px 64px rgba({$r},.18)}\n.btn--gold{color:var(--navy-deep)}.chat-fab__dot,.mock-ribbon{color:var(--navy-deep)}";
    }
    /** WhatsApp deep link (settings_json.whatsapp = digits with country code). */
    private function wa(string $text = ''): string { $n = preg_replace('/\D/', '', $this->set('whatsapp')); if ($n === '') return ''; return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : ''); }
    private function waFloat(): string { $u = $this->wa('Hi ' . $this->siteName . '! I would like to ask about a trip.'); if ($u === '') return ''; return '<a class="wa-float" href="' . $this->e($u) . '" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2zm0 1.8a8.2 8.2 0 1 1-4.2 15.3l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 0 1 12 3.8zm-3 4.4c-.2 0-.5 0-.7.3-.3.3-1 1-1 2.3s1 2.7 1.2 2.9c.1.2 2 3.1 4.9 4.3 2.4 1 2.9.8 3.4.7.5 0 1.7-.7 1.9-1.4.2-.7.2-1.2.2-1.4-.1-.1-.3-.2-.6-.3l-2.1-1c-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.3.2-.6.1-.3-.2-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.5-.5.3-.5c.1-.2 0-.4 0-.5l-1-2.3c-.2-.6-.5-.5-.7-.5h-.6z"/></svg></a>'; }
    private function autoBook(array $secs): string { foreach ($secs as $s) if (is_array($s) && ($s['type'] ?? '') === 'booking_wizard') return '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector("[data-book]");if(b)setTimeout(function(){b.click()},250)})</script>'; return ''; }
    private function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private function find(array $secs, string $type, ?string $key = null): ?array
    {
        foreach ($secs as $s) {
            if (($s['type'] ?? '') !== $type) continue;
            if ($key === null) return $s;
            if (($s['variant'] ?? null) === $key || ($s['style'] ?? null) === $key) return $s;
        }
        return null;
    }

    public function renderBody(array $secs, array $brand, array $website, array $page): string
    {
        $css = @file_get_contents(storage_path('app/travel/travel-theme.css')) ?: '';
        $js  = @file_get_contents(storage_path('app/travel/travel-theme.js')) ?: '';
        $this->bootSettings($website);
        $sub = str_replace('.levelupgrowth.io', '', (string) ($website['subdomain'] ?? ''));

        $fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400..600;1,9..144,400..500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">';

        // Header + footer rendered as fixed chrome; everything else dispatched in order.
        // Interior pages lead with a dark page_hero → header needs light text.
        $darkHeader = $this->find($secs, 'page_hero') !== null;
        $header = $this->header($this->find($secs, 'header'), $darkHeader);
        $footer = $this->footer($this->find($secs, 'footer'));

        $body = '';
        foreach ($secs as $sec) {
            $t = $sec['type'] ?? '';
            if ($t === 'header' || $t === 'footer') continue;
            $body .= $this->dispatch($sec, $website);
        }

        $drawer = $this->bookingDrawer($sub);

        $cfg = '<script>window.__TF=' . json_encode(['site' => $this->siteName, 'ref' => $this->set('booking_ref_prefix', 'BK'), 'whatsapp' => preg_replace('/\D/', '', $this->set('whatsapp'))], JSON_UNESCAPED_SLASHES) . '</script>';
        return $fonts . "\n<style>\n" . $css . "\n" . $this->tokens() . "\n</style>\n" . $cfg
            . $header . "\n<main id=\"top\">\n" . $body . "\n</main>\n" . $footer . "\n" . $drawer . $this->waFloat() . $this->autoBook($secs)
            . "\n<script>\n" . $js . "\n</script>\n";
    }

    /** Blog post inner page — AMG-branded (was falling back to a LevelUp template). */
    public function renderArticle(array $article, array $website): string
    {
        $css = @file_get_contents(storage_path('app/travel/travel-theme.css')) ?: '';
        $js  = @file_get_contents(storage_path('app/travel/travel-theme.js')) ?: '';
        $this->bootSettings($website);
        $sub = str_replace('.levelupgrowth.io', '', (string) ($website['subdomain'] ?? ''));
        $fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400..600;1,9..144,400..500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">';

        $header = $this->header(null, true); // article leads with dark page-hero
        $footer = $this->footer(null);
        $drawer = $this->bookingDrawer($sub);

        $title = $this->e($article['title'] ?? 'Article');
        $cat   = $this->e($article['blog_category'] ?? 'Article');
        $when  = !empty($article['published_at']) ? $this->e(\Carbon\Carbon::parse($article['published_at'])->format('F j, Y')) : '';
        $img   = $this->e($article['featured_image_url'] ?? '');
        $content = $this->contentToHtml((string) ($article['content'] ?? ''));
        $heroImg = $img ? "<div class=\"wrap\" style=\"margin-top:-34px;position:relative;z-index:2\"><img src=\"{$img}\" alt=\"{$title}\" style=\"width:100%;max-height:440px;object-fit:cover;border-radius:var(--r-lg);box-shadow:var(--shadow-md)\"></div>" : '';

        $main = <<<HTML
<section class="page-hero"><div class="wrap"><span class="crumbs"><a href="/">Home</a> · <a href="/blog">Blog</a> · {$cat}</span><h1 style="font-size:clamp(28px,4.5vw,46px)">{$title}</h1><p class="lead">{$when}</p></div></section>
{$heroImg}
<section class="section"><div class="wrap"><div class="amg-prose">{$content}</div><a class="amg-back" href="/blog">← Back to the blog</a></div></section>
HTML;

        return $fonts . "\n<style>\n" . $css . "\n</style>\n"
            . $header . "\n<main id=\"top\">\n" . $main . "\n</main>\n" . $footer . "\n" . $drawer
            . "\n<script>\n" . $js . "\n</script>\n";
    }

    private function contentToHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('#<(p|h2|h3|ul|ol|div)\b#i', $raw)) return $raw; // already HTML
        $out = '';
        foreach (preg_split('/\n\s*\n/', $raw) as $b) {
            $b = trim($b);
            if ($b === '') continue;
            $out .= '<p>' . nl2br($this->e($b)) . '</p>';
        }
        return $out;
    }

    private function dispatch(array $sec, array $website): string
    {
        $t = $sec['type'] ?? '';
        $v = $sec['variant'] ?? '';
        $st = $sec['style'] ?? '';
        switch ($t) {
            case 'hero':          return $this->hero($sec);
            case 'page_hero':     return $this->pageHero($sec);
            case 'features':      return $this->services($sec);
            case 'about_split':   return $this->aboutSplit($sec);
            case 'feat_list':     return $this->featList($sec);
            case 'filter_bar':    return '';            // chips fold into the grid header
            case 'booking_wizard': return $this->bookingCard($sec); // SGTRAVEL T2 — /book page: card + auto-open drawer
            case 'generic':       return $this->generic($sec);          // SGTRAVEL — prose pages (holiday planner, policies)
            case 'travel_quiz':   return \App\Engines\Builder\Support\TravelQuizElement::render(array_merge(['business_name' => $this->siteName], $sec), ['primary' => $this->col('primary_color', '#1B6FC4'), 'accent' => $this->col('accent_color', '#F4C95D')], (string) config('app.url'), 'lq' . (int) ($website['id'] ?? 0)); // SGTRAVEL — trip-planner quiz (LEAD-1 element)
            case 'cta':           return $this->ctaBand($sec);
            case 'contact_form':  return $this->contactSection($sec, $website);
            case 'blog_list':     return $this->blogList($website);
            case 'blog_teaser':   return $this->blogTeaser($sec, $website);
            case 'grid':
                if ($v === 'packages')  return $this->packages($sec);
                if ($v === 'reviews')   return $this->reviews($sec);
                return $this->destinations($sec);
            case 'trust_signals':
                if ($st === 'badge_row') return $this->badgeRow($sec);
                return $this->trust($sec);
            default:              return '';
        }
    }

    private function seal(string $id): string
    {
        return '<svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--navy)"/><stop offset="1" stop-color="var(--emerald)"/></linearGradient></defs>'
            . '<path d="M50 4 84 16v34c0 22-16 36-34 46C32 86 16 72 16 50V16Z" fill="none" stroke="url(#' . $id . ')" stroke-width="3"/>'
            . '<path d="M50 11 78 21v29c0 18-13 30-28 38C35 80 22 68 22 50V21Z" fill="none" stroke="var(--gold-line)" stroke-width="1" opacity=".7"/>'
            . '<path d="M38 50c4-6 20-6 24 0-4 6-20 6-24 0Z" fill="none" stroke="var(--navy)" stroke-width="2.4"/>'
            . '<circle cx="50" cy="50" r="3.4" fill="var(--emerald)"/>'
            . '<path d="M50 30v6M50 64v6M30 50h6M64 50h6" stroke="var(--gold-line)" stroke-width="1.6"/></svg>';
    }

    private function slug(string $label): string
    {
        $l = strtolower(trim($label));
        if ($l === 'home') return '/';
        return '/' . preg_replace('/[^a-z0-9]+/', '-', $l);
    }

    private function header(?array $h, bool $dark = false): string
    {
        $h = $h ?: [];
        $name = $this->e($h['logo_text'] ?? $this->siteName);
        $nav  = is_array($h['nav_links'] ?? null) ? $h['nav_links'] : ['Home','About','Services','Destinations','Packages','Blog','Contact'];
        $cta  = $this->e($h['cta_text'] ?? 'Start a booking');
        $waUrl = $this->wa('Hi ' . $this->siteName . '! I would like to ask about a trip.'); $waBtn = $waUrl !== '' ? '<a class="btn btn--wa" href="' . $this->e($waUrl) . '" target="_blank" rel="noopener" aria-label="WhatsApp"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2z"/></svg> WhatsApp</a>' : '';
        $sealH = $this->seal('sgH');
        $logo = $this->e($h['logo_image'] ?? $h['logo_url'] ?? '');
        $brandInner = $logo !== ''
            ? "<img src=\"{$logo}\" alt=\"{$name}\" class=\"brand__logo\" style=\"height:46px;width:auto;display:block\"><span><span class=\"brand__name\">{$name}</span></span>"
            : "<span class=\"brand__mark\"><span class=\"seal\">{$sealH}</span></span><span><span class=\"brand__name\">{$name}</span></span>";
        $darkCls = $dark ? ' site-head--dark' : '';

        $navHtml = ''; $mobHtml = '';
        foreach ($nav as $n) {
            $lbl = $this->e($n); $href = $this->e($this->slug((string) $n));
            $navHtml .= "<a href=\"{$href}\">{$lbl}</a>";
            $mobHtml .= "<a href=\"{$href}\">{$lbl}</a>";
        }

        return <<<HTML
<header class="site-head{$darkCls}" id="head"><div class="wrap site-head__inner">
  <a class="brand" href="/">{$brandInner}</a>
  <nav class="nav">{$navHtml}</nav>
  <div class="nav-cta">{$waBtn}<button class="btn btn--primary" data-book>{$cta} <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button></div>
  <button class="burger" id="burger" aria-label="Menu"><span></span><span></span><span></span></button>
</div></header>
<div class="mobile-nav" id="mobileNav"><button class="mobile-nav__close" id="mobileClose">&times;</button>{$mobHtml}<div class="mobile-nav__meta">{$this->e($this->set('location'))}<br>{$this->e($this->set('phone'))}</div></div>
HTML;
    }

    private function hero(array $h): string
    {
        $eyebrow = $this->e($h['eyebrow'] ?? $this->set('tagline'));
        $heading = $this->e($h['heading'] ?? 'Your trip, handled end to end.');
        $accent  = $h['heading_accent'] ?? '';
        $headHtml = $accent && str_contains($heading, $this->e($accent))
            ? str_replace($this->e($accent), '<em>' . $this->e($accent) . '</em>', $heading) : $heading;
        $lead = $this->e($h['subheading'] ?? '');
        $sealHero = $this->seal('sgHero');
        $heroLogo = $this->e($h['logo_image'] ?? $h['logo_url'] ?? '');
        $heroMark = $heroLogo !== '' ? '<img src="' . $heroLogo . '" alt="" style="width:100%;height:auto;display:block">' : $sealHero;

        return <<<HTML
<section class="hero" id="home">
  <div class="hero__bg"></div>
  <div class="wrap hero__inner">
    <span class="eyebrow">{$eyebrow}</span>
    <h1>{$headHtml}</h1>
    <p class="lead">{$lead}</p>
    <div class="searchbar">
      <div class="searchbar__tabs">
        <button class="searchbar__tab is-active">Flights</button>
        <button class="searchbar__tab">Packages</button>
        <button class="searchbar__tab">Hotels</button>
        <button class="searchbar__tab">Visa</button>
      </div>
      <div class="searchbar__grid">
        <div class="sf"><label>From</label><input id="hbFrom" value="{$this->e($this->set('home_city', 'Manila'))}" placeholder="City or airport"></div>
        <div class="sf"><label>To</label><input id="hbTo" value="{$this->e($this->set('sample_destination', 'Bangkok'))}" placeholder="Destination"></div>
        <button class="searchbar__go" id="heroSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> Search</button>
        <div class="sf"><label>Departure</label><input id="hbDate" data-datepick placeholder="Pick a date" readonly></div>
        <div class="sf sf--seg"><label>Travelers</label><div class="wz-seg" data-field="hbPax"><button type="button" data-val="1 Adult">1 Adult</button><button type="button" class="is-on" data-val="2 Adults">2 Adults</button><button type="button" data-val="3 Adults">3 Adults</button><button type="button" data-val="4+ Adults">4+ Adults</button></div></div>
        <div class="sf sf--seg"><label>Class</label><div class="wz-seg" data-field="hbClass"><button type="button" class="is-on" data-val="Economy">Economy</button><button type="button" data-val="Premium">Premium</button><button type="button" data-val="Business">Business</button></div></div>
      </div>
    </div>
  </div>
  <span class="hero__seal">{$heroMark}</span>
</section>
HTML;
    }

    private function pageHero(array $h): string
    {
        $crumb = $this->e($h['crumb'] ?? 'Home · Page');
        $heading = $this->e($h['heading'] ?? '');
        $lead = $this->e($h['subheading'] ?? '');
        $leadHtml = $lead ? "<p class=\"lead\">{$lead}</p>" : '';
        return <<<HTML
<section class="page-hero"><div class="wrap"><span class="crumbs"><a href="/">Home</a> · {$crumb}</span><h1>{$heading}</h1>{$leadHtml}</div></section>
HTML;
    }

    private function trust(array $t): string
    {
        $items = is_array($t['items'] ?? null) ? $t['items'] : [];
        $cells = '';
        foreach ($items as $it) {
            $cells .= "<div class=\"trust__cell reveal\"><div class=\"trust__val\">" . $this->e($it['value'] ?? '') . "</div><div class=\"trust__lab\">" . $this->e($it['label'] ?? '') . "</div></div>";
        }
        return "<section class=\"trust\"><div class=\"wrap\" style=\"padding-block:0\"><div class=\"trust__grid\">{$cells}</div></div></section>";
    }

    private function badgeRow(array $t): string
    {
        $items = is_array($t['items'] ?? null) ? $t['items'] : [];
        $heading = $this->e($t['heading'] ?? 'Accredited & trusted');
        $badges = '';
        foreach ($items as $b) $badges .= '<span class="foot-badge" style="border-color:var(--line-strong);color:var(--ink-soft)">' . $this->e($b['label'] ?? '') . '</span>';
        return <<<HTML
<section class="section section--tight"><div class="wrap center">
  <span class="eyebrow" style="justify-content:center">Accreditations</span>
  <h2 style="margin:14px 0 22px;font-size:clamp(24px,3vw,34px)">{$heading}</h2>
  <div class="foot-badges" style="justify-content:center">{$badges}</div>
</div></section>
HTML;
    }

    private function services(array $f): string
    {
        $eyebrow = $this->e($f['eyebrow'] ?? 'What we do');
        $heading = $this->e($f['heading'] ?? '');
        $sub = $this->e($f['subheading'] ?? '');
        $subHtml = $sub ? "<p class=\"lead\">{$sub}</p>" : '';
        $items = is_array($f['items'] ?? null) ? $f['items'] : [];
        $cards = ''; $i = 0;
        foreach ($items as $it) {
            $tag = $this->e($it['tag'] ?? 'Core service');
            // An icon we authored is inline SVG and passes through; a character supplied per-site is escaped.
            $__ic = (string) ($it['icon'] ?? '');
            $icon = $__ic === '' ? '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5 13.6 9 19 10.6 13.6 12.2 12 17.6 10.4 12.2 5 10.6 10.4 9Z"/></svg>' : (str_starts_with($__ic, '<svg ') ? $__ic : $this->e($__ic));
            $h3 = $this->e($it['heading'] ?? $it['title'] ?? '');
            $p = $this->e($it['text'] ?? '');
            $pts = is_array($it['points'] ?? null) ? $it['points'] : [];
            $ptsHtml = '';
            foreach ($pts as $pt) $ptsHtml .= '<li>' . $this->e($pt) . '</li>';
            if ($ptsHtml) $ptsHtml = "<ul class=\"svc__points\">{$ptsHtml}</ul>";
            $cards .= "<article class=\"svc reveal\" data-delay=\"{$i}\"><span class=\"svc__tag\">{$tag}</span><div class=\"svc__ico\" style=\"font-size:26px\">{$icon}</div><h3>{$h3}</h3><p>{$p}</p>{$ptsHtml}</article>";
            $i++;
        }
        return <<<HTML
<section class="section" id="services"><div class="wrap">
  <div class="shead"><span class="eyebrow">{$eyebrow}</span><h2>{$heading}</h2>{$subHtml}</div>
  <div class="grid svc-grid">{$cards}</div>
</div></section>
HTML;
    }

    private function aboutSplit(array $s): string
    {
        $eyebrow = $this->e($s['eyebrow'] ?? ('About ' . $this->siteName));
        $heading = $this->e($s['heading'] ?? '');
        $body = $this->e($s['body'] ?? '');
        $quote = $this->e($s['quote'] ?? '');
        $quoteHtml = $quote ? "<p class=\"quote\">{$quote}</p>" : '';
        $cardTitle = $this->e($s['card_title'] ?? 'Treated like family');
        $cardBody = $this->e($s['card_body'] ?? '');
        return <<<HTML
<section class="section"><div class="wrap split">
  <div>
    <span class="eyebrow">{$eyebrow}</span>
    <h2 style="font-size:clamp(28px,4vw,44px);margin:16px 0">{$heading}</h2>
    <p class="lead">{$body}</p>
  </div>
  <div class="about-card">
    <h3>{$cardTitle}</h3><p>{$cardBody}</p>{$quoteHtml}
  </div>
</div></section>
HTML;
    }

    private function featList(array $s): string
    {
        $heading = $this->e($s['heading'] ?? 'Why choose us');
        $items = is_array($s['items'] ?? null) ? $s['items'] : [];
        $lis = ''; $i = 1;
        foreach ($items as $it) {
            $h4 = $this->e($it['heading'] ?? '');
            $p = $this->e($it['text'] ?? '');
            $n = $i < 10 ? "0{$i}" : (string) $i;
            $lis .= "<li><span class=\"n\">{$n}</span><div><h4>{$h4}</h4><p>{$p}</p></div></li>";
            $i++;
        }
        return <<<HTML
<section class="section section--tight"><div class="wrap">
  <div class="shead"><h2>{$heading}</h2></div>
  <ul class="feat-list">{$lis}</ul>
</div></section>
HTML;
    }

    private function destinations(array $g): string
    {
        $eyebrow = $this->e($g['eyebrow'] ?? 'Where we send travelers');
        $heading = $this->e($g['heading'] ?? '');
        $items = is_array($g['items'] ?? null) ? $g['items'] : [];
        $tones = ['tone-navy','tone-emerald','tone-silver'];
        $cards = ''; $i = 0;
        foreach ($items as $it) {
            $tone = $tones[$i % 3];
            $region = $this->e($it['region'] ?? '');
            $tag = $this->e($it['tag'] ?? '');
            $title = $this->e($it['title'] ?? '');
            $p = $this->e($it['subtitle'] ?? '');
            $img = $this->e($it['image'] ?? '');
            $bg = $img ? "background-image:linear-gradient(rgba(12,30,51,.15),rgba(12,30,51,.55)),url('{$img}');background-size:cover;background-position:center;" : '';
            $cards .= "<article class=\"dest reveal {$tone}\" style=\"{$bg}\"><span class=\"dest__region\">{$region}</span><span class=\"dest__tag\">{$tag}</span><h4>{$title}</h4><p>{$p}</p></article>";
            $i++;
        }
        return <<<HTML
<section class="section" id="destinations" style="background:#fff;border-block:1px solid var(--line)"><div class="wrap">
  <div class="shead center"><span class="eyebrow" style="justify-content:center">{$eyebrow}</span><h2>{$heading}</h2></div>
  <div class="grid dest-grid">{$cards}</div>
</div></section>
HTML;
    }

    private function packages(array $g): string
    {
        $eyebrow = $this->e($g['eyebrow'] ?? 'Sample packages');
        $heading = $this->e($g['heading'] ?? '');
        $sub = $this->e($g['subheading'] ?? '');
        $subHtml = $sub ? "<p class=\"lead\">{$sub}</p>" : '';
        $items = is_array($g['items'] ?? null) ? $g['items'] : [];
        $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4 10-10"/></svg>';
        $cards = ''; $i = 0;
        foreach ($items as $it) {
            $tone = $this->e($it['tone'] ?? 'tone-navy');
            $nights = $this->e($it['nights'] ?? '');
            $title = $this->e($it['title'] ?? '');
            $price = $this->e($it['price'] ?? 'Inquire for fare');
            $img = $this->e($it['image'] ?? '');
            $bg = $img ? "background-image:linear-gradient(rgba(12,30,51,.1),rgba(12,30,51,.5)),url('{$img}');background-size:cover;background-position:center;" : '';
            $hls = is_array($it['highlights'] ?? null) ? $it['highlights'] : [];
            $hlHtml = '';
            foreach ($hls as $hl) $hlHtml .= "<li>{$check}<span>" . $this->e($hl) . "</span></li>";
            $cards .= "<article class=\"pkg reveal\" data-delay=\"{$i}\"><div class=\"pkg__top {$tone}\" style=\"{$bg}\"><span class=\"pkg__nights\">{$nights}</span><h3>{$title}</h3></div><div class=\"pkg__body\"><ul class=\"pkg__hl\">{$hlHtml}</ul><div class=\"pkg__foot\"><span class=\"pkg__from\">{$price}</span><span class=\"pkg__acts\">" . ($this->wa('Hi ' . $this->siteName . '! I am interested in the ' . ($it['title'] ?? 'package') . ' package (' . ($it['nights'] ?? '') . '). Can you send me the details?') !== '' ? "<a class=\"btn btn--wa\" href=\"" . $this->e($this->wa('Hi ' . $this->siteName . '! I am interested in the ' . ($it['title'] ?? 'package') . ' package (' . ($it['nights'] ?? '') . '). Can you send me the details?')) . "\" target=\"_blank\" rel=\"noopener\" style=\"padding:10px 14px;font-size:14px\">WhatsApp</a>" : '') . "<button class=\"btn btn--primary\" data-book=\"{$title}\" style=\"padding:10px 18px;font-size:14px\">Book now</button></span></div></div></article>";
            $i++;
        }
        return <<<HTML
<section class="section" id="packages"><div class="wrap">
  <div class="shead"><span class="eyebrow">{$eyebrow}</span><h2>{$heading}</h2>{$subHtml}</div>
  <div class="grid pkg-grid">{$cards}</div>
</div></section>
HTML;
    }

    private function reviews(array $g): string
    {
        $eyebrow = $this->e($g['eyebrow'] ?? 'In their words');
        $heading = $this->e($g['heading'] ?? '');
        $items = is_array($g['items'] ?? null) ? $g['items'] : [];
        $cards = ''; $i = 0;
        foreach ($items as $it) {
            $stars = $this->e($it['badge'] ?? '★★★★★');
            $text = $this->e($it['subtitle'] ?? '');
            $name = $this->e($it['title'] ?? '');
            $src = $this->e(is_array($it['tags'] ?? null) ? ($it['tags'][0] ?? 'Facebook review') : 'Facebook review');
            $av = $this->e(mb_substr($name, 0, 1));
            $cards .= "<article class=\"rev reveal\" data-delay=\"{$i}\"><div class=\"rev__stars\">{$stars}</div><p class=\"rev__text\">{$text}</p><div class=\"rev__by\"><span class=\"rev__av\">{$av}</span><span><span class=\"rev__name\">{$name}</span><br><span class=\"rev__src\">{$src}</span></span></div></article>";
            $i++;
        }
        return <<<HTML
<section class="section rev-section" id="reviews"><div class="wrap">
  <div class="shead center"><span class="eyebrow" style="justify-content:center">{$eyebrow}</span><h2>{$heading}</h2></div>
  <div class="grid rev-grid">{$cards}</div>
</div></section>
HTML;
    }

    private function blogTeaser(array $s, array $website): string
    {
        $eyebrow = $this->e($s['eyebrow'] ?? 'From the journal');
        $heading = $this->e($s['heading'] ?? 'Travel tips & inspiration');
        $cards = $this->articleCards($website, 3);
        if ($cards === '') return '';
        return <<<HTML
<section class="section"><div class="wrap">
  <div class="shead"><span class="eyebrow">{$eyebrow}</span><h2>{$heading}</h2></div>
  <div class="grid pkg-grid">{$cards}</div>
  <div class="center" style="margin-top:36px"><a class="btn btn--ghost" href="/blog">Read the blog <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></div>
</div></section>
HTML;
    }

    private function blogList(array $website): string
    {
        $cards = $this->articleCards($website, 24);
        if ($cards === '') $cards = '<p class="lead" style="text-align:center">New stories coming soon.</p>';
        return "<section class=\"section\"><div class=\"wrap\"><div class=\"grid pkg-grid\">{$cards}</div></div></section>";
    }

    private function articleCards(array $website, int $limit): string
    {
        $wsId = (int) ($website['workspace_id'] ?? 0);
        $articles = DB::table('articles')
            ->where('workspace_id', $wsId)->where('is_marketing_blog', 1)
            ->where('status', 'published')->whereNull('deleted_at')
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)
            ->get(['title','slug','blog_category','featured_image_url','word_count']);
        $tones = ['tone-navy','tone-emerald','tone-silver'];
        $cards = ''; $i = 0;
        foreach ($articles as $a) {
            $tone = $tones[$i % 3];
            $img = $this->e($a->featured_image_url ?? '');
            $bg = $img ? "background-image:linear-gradient(rgba(12,30,51,.1),rgba(12,30,51,.5)),url('{$img}');background-size:cover;background-position:center;" : '';
            $cat = $this->e($a->blog_category ?? 'Article');
            $title = $this->e($a->title);
            $slug = $this->e($a->slug);
            $read = max(1, (int) round(((int) ($a->word_count ?? 0)) / 200));
            $cards .= "<article class=\"pkg reveal\" data-delay=\"{$i}\"><a href=\"/blog/{$slug}\" style=\"display:block;color:inherit\"><div class=\"pkg__top {$tone}\" style=\"{$bg}\"><span class=\"pkg__nights\">{$cat}</span></div><div class=\"pkg__body\"><h3 style=\"font-size:21px;margin-bottom:10px\">{$title}</h3><div class=\"pkg__foot\"><span class=\"pkg__from\">{$read} min read</span><span class=\"btn btn--primary\" style=\"padding:10px 18px;font-size:14px\">Read</span></div></div></a></article>";
            $i++;
        }
        return $cards;
    }

    private function ctaBand(array $c): string
    {
        $h = $this->e($c['heading'] ?? 'Ready when you are');
        $p = $this->e($c['body'] ?? '');
        $btn = $this->e($c['cta_text'] ?? 'Start a booking');
        $sec = $this->e($c['secondary_text'] ?? '');
        $secLink = $this->e($c['secondary_link'] ?? '/contact');
        $secHtml = $sec ? "<a class=\"btn btn--ghost-light\" href=\"{$secLink}\">{$sec}</a>" : '';
        return <<<HTML
<section class="section"><div class="wrap"><div class="cta-band reveal">
  <h2>{$h}</h2><p>{$p}</p>
  <div class="btn-row"><button class="btn btn--gold" data-book>{$btn}</button>{$secHtml}</div>
</div></div></section>
HTML;
    }

    private function contactSection(array $c, array $website): string
    {
        $sub = $this->e(str_replace('.levelupgrowth.io', '', (string) ($website['subdomain'] ?? '')));
        $wid = (int) ($website['id'] ?? 0);
        $heading = $this->e($c['heading'] ?? 'Get in touch');
        $body = $this->e($c['body'] ?? "Tell us about your dream trip and we'll reply fast.");
        $office = $this->e($c['office'] ?? $this->set('office'));
        $phone = $this->e($c['phone'] ?? $this->set('phone'));
        $email = $this->e($c['email'] ?? $this->set('email'));
        return <<<HTML
<section class="section"><div class="wrap contact-grid">
  <div class="info-block">
    <span class="eyebrow">{$heading}</span>
    <p class="lead" style="margin:14px 0 8px">{$body}</p>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg></div><div><h4>Office</h4><p>{$office}</p></div></div>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.6 3 .2 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></div><div><h4>Call / WhatsApp</h4><p>{$phone}</p></div></div>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></div><div><h4>Email</h4><p><a href="mailto:{$email}">{$email}</a></p></div></div>
  </div>
  <form class="contact-form" id="contact-form-{$wid}" data-subdomain="{$sub}" onsubmit="luSubmitContact(event,this)" style="background:#fff;border:1px solid var(--line);border-radius:var(--r-lg);padding:30px">
    <div class="field"><label>Your name</label><input name="firstname" required maxlength="100" placeholder="Juan dela Cruz"></div>
    <div class="field-row"><div class="field"><label>Email</label><input type="email" name="email" required maxlength="255" placeholder="you@email.com"></div><div class="field"><label>Mobile / WhatsApp</label><input type="tel" name="phone" maxlength="50" placeholder="09xx xxx xxxx"></div></div>
    <div class="field"><label>How can we help?</label><textarea name="message" rows="4" required maxlength="2000" placeholder="Where would you like to go?"></textarea></div>
    <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Send message</button>
    <div class="lu-contact-success" style="display:none;margin-top:14px;padding:12px;background:#d4edda;color:#155724;border-radius:10px;text-align:center">&#10003; Thank you! We'll get back to you soon.</div>
    <div class="lu-contact-error" style="display:none;margin-top:14px;padding:12px;background:#f8d7da;color:#721c24;border-radius:10px;text-align:center">Something went wrong. Please try again.</div>
  </form>
</div></section>
<script>
if(typeof window.luSubmitContact==='undefined'){window.luSubmitContact=function(e,form){e.preventDefault();var b=form.querySelector('button[type=submit]'),o=b.textContent;b.disabled=true;b.textContent='Sending...';var s=form.dataset.subdomain,ok=form.querySelector('.lu-contact-success'),er=form.querySelector('.lu-contact-error');ok.style.display='none';er.style.display='none';fetch('/api/public/contact/'+encodeURIComponent(s),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({firstname:form.firstname.value,email:form.email.value,phone:form.phone?form.phone.value:'',message:form.message.value})}).then(function(r){return r.json()}).then(function(d){if(d&&d.success){ok.style.display='block';form.reset()}else{er.style.display='block'}b.disabled=false;b.textContent=o}).catch(function(){er.style.display='block';b.disabled=false;b.textContent=o})}}
</script>
HTML;
    }

    private function footer(?array $f): string
    {
        $f = $f ?: [];
        $name = $this->e($f['logo_text'] ?? $this->siteName);
        $tag = $this->e($f['tagline'] ?? $this->set('footer_tagline'));
        $copy = $this->e($f['copyright'] ?? ('© ' . date('Y') . ' ' . $this->siteName));
        $badges = ''; foreach ((array) ($f['badges'] ?? []) as $b) $badges .= '<span class="foot-badge">' . $this->e((string) $b) . '</span>';
        $socials = ''; foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $k => $lab) { $u = trim((string) ($f[$k] ?? $this->set($k))); if ($u !== '') $socials .= '<li><a href="' . $this->e($u) . '" target="_blank" rel="noopener">' . $lab . '</a></li>'; }
        $waU = $this->wa(); if ($waU !== '') $socials .= '<li><a href="' . $this->e($waU) . '" target="_blank" rel="noopener">WhatsApp</a></li>';
        $office = $this->e($f['office'] ?? $this->set('office')); $phone = $this->e($f['phone'] ?? $this->set('phone')); $email = $this->e($f['email'] ?? $this->set('email')); $note = $this->e($f['note'] ?? $this->set('footer_note'));

        return <<<HTML
<footer class="site-foot"><div class="wrap">
  <div class="foot-grid">
    <div class="foot-brand"><span class="brand__name">{$name}</span><p>{$tag}</p>
      <div class="foot-badges" style="margin-top:20px">{$badges}</div></div>
    <div><h5>Explore</h5><ul class="foot-links"><li><a href="/about">About</a></li><li><a href="/services">Services</a></li><li><a href="/destinations">Destinations</a></li><li><a href="/packages">Packages</a></li><li><a href="/blog">Blog</a></li></ul></div>
    <div><h5>Connect</h5><ul class="foot-links">{$socials}<li><a href="/contact">Contact</a></li></ul></div>
    <div><h5>Visit &amp; Reach Us</h5><div class="foot-contact">
      <div><span class="lab">Office</span>{$office}</div>
      <div><span class="lab">Call</span>{$phone}</div>
      <div><span class="lab">Email</span><a href="mailto:{$email}">{$email}</a></div>
    </div></div>
  </div>
  <div class="foot-bottom"><span>{$copy}</span>
    <span style="font-family:var(--f-mono);font-size:11px;letter-spacing:.1em;color:var(--silver)">{$note}</span></div>
</div></footer>
HTML;
    }

    /** SGTRAVEL — generic prose section: heading + trusted HTML content (holiday tables, policies). */
    private function generic(array $s): string
    {
        $h = $this->e($s['heading'] ?? ''); $eye = $this->e($s['eyebrow'] ?? '');
        $content = (string) ($s['content'] ?? $s['body'] ?? '');
        if ($content !== '' && !preg_match('/<(p|div|ul|ol|h[1-6]|table)\b/i', $content)) $content = '<p>' . nl2br($this->e($content)) . '</p>';
        return '<section class="section"><div class="wrap"><div class="shead">' . ($eye !== '' ? '<span class="eyebrow">' . $eye . '</span>' : '') . ($h !== '' ? '<h2>' . $h . '</h2>' : '') . '</div><div class="amg-prose tf-prose">' . $content . '</div></div></section>';
    }

    /** SGTRAVEL T2 — a card that opens the inquiry drawer (the /book page auto-opens it on load). */
    private function bookingCard(array $s): string
    {
        $h = $this->e($s['heading'] ?? 'Start your booking'); $b = $this->e($s['body'] ?? 'Three short steps — what to arrange, your trip details, how to reach you. No payment online; we send a quote first.'); $c = $this->e($s['cta_text'] ?? 'Open the booking form');
        $wa = $this->wa('Hi ' . $this->siteName . '! I would like to book a trip.'); $waHtml = $wa !== '' ? '<a class="btn btn--wa" href="' . $this->e($wa) . '" target="_blank" rel="noopener">Or message us on WhatsApp</a>' : '';
        return '<section class="section"><div class="wrap" style="max-width:760px"><div class="shead"><span class="eyebrow">Booking</span><h2>' . $h . '</h2><p class="lead">' . $b . '</p></div><div class="btn-row" style="justify-content:center"><button class="btn btn--primary" data-book>' . $c . '</button>' . $waHtml . '</div></div></section>';
    }

    private function bookingDrawer(string $sub): string
    {
        $sub = $this->e($sub);
        $arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
        return <<<HTML
<div class="bk-overlay" id="bkOverlay"><div class="bk" data-subdomain="{$sub}">
  <div class="bk__head">
    <div class="bk__head-top"><h3 id="bkTitle">Plan your trip</h3><button class="bk__close" id="bkClose">&times;</button></div>
    <div class="bk__steps">
      <div class="bk__step active"><div class="bar"></div><div class="lab">1 · Trip</div></div>
      <div class="bk__step"><div class="bar"></div><div class="lab">2 · Details</div></div>
      <div class="bk__step"><div class="bar"></div><div class="lab">3 · Stay</div></div>
      <div class="bk__step"><div class="bar"></div><div class="lab">4 · Contact</div></div>
    </div>
  </div>
  <div class="bk__body">
    <div class="bk__pane is-active">
      <p class="lead" style="font-size:16px;margin-bottom:4px">What can we help you arrange?</p>
      <p style="color:var(--ink-soft);font-size:13.5px;margin-bottom:16px">Pick all that apply — this is an inquiry, no payment.</p>
      <div class="wz-grid">
        <button type="button" class="wz-opt" data-svc="Flights"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5c.6 0 1.1.5 1.1 1.1v4.9l7.4 4.3v1.9l-7.4-2.3v4.4l2.5 1.8v1.4L12 19.8l-3.6 1.2v-1.4l2.5-1.8v-4.4L3.5 15.7v-1.9l7.4-4.3V3.6c0-.6.5-1.1 1.1-1.1Z"/></svg></span>Flights</button>
        <button type="button" class="wz-opt" data-svc="Ship / Ferry"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20a3 3 0 0 0 2.5-1 3 3 0 0 1 5 0 3 3 0 0 0 5 0 3 3 0 0 1 5 0 3 3 0 0 0 2.5 1"/><path d="M4 15.5 5.6 10a2 2 0 0 1 1.9-1.5h9a2 2 0 0 1 1.9 1.5L20 15.5"/><path d="M12 8.5V4"/><path d="M9 4h6"/></svg></span>Ship / Ferry</button>
        <button type="button" class="wz-opt" data-svc="Hotel"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20v-9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9"/><path d="M3 16h18"/><path d="M6 9V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3"/></svg></span>Hotel</button>
        <button type="button" class="wz-opt" data-svc="Tour package"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a14 14 0 0 0 0 18 14 14 0 0 0 0-18"/><path d="M3 12h18"/></svg></span>Tour package</button>
        <button type="button" class="wz-opt" data-svc="Visa"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M9 17h6"/></svg></span>Visa</button>
        <button type="button" class="wz-opt" data-svc="Complete package"><span class="wz-opt__i"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 8.5v7a2 2 0 0 1-1 1.7l-6 3.5a2 2 0 0 1-2 0l-6-3.5a2 2 0 0 1-1-1.7v-7a2 2 0 0 1 1-1.7l6-3.5a2 2 0 0 1 2 0l6 3.5a2 2 0 0 1 1 1.7Z"/><path d="M4.3 7.6 12 12l7.7-4.4"/><path d="M12 12v9"/></svg></span>Complete package</button>
      </div>
      <div class="bk__nav"><button class="btn btn--primary" id="toStep2" disabled style="opacity:.5">Continue {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <p class="lead" style="font-size:16px;margin-bottom:16px">Trip details</p>
      <div class="wz-row" id="wzTransportRow"><label>Travelling by</label><div class="wz-seg" data-field="transport"><button type="button" class="is-on" data-val="Airline"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5c.6 0 1.1.5 1.1 1.1v4.9l7.4 4.3v1.9l-7.4-2.3v4.4l2.5 1.8v1.4L12 19.8l-3.6 1.2v-1.4l2.5-1.8v-4.4L3.5 15.7v-1.9l7.4-4.3V3.6c0-.6.5-1.1 1.1-1.1Z"/></svg> Airline</button><button type="button" data-val="Ship"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20a3 3 0 0 0 2.5-1 3 3 0 0 1 5 0 3 3 0 0 0 5 0 3 3 0 0 1 5 0 3 3 0 0 0 2.5 1"/><path d="M4 15.5 5.6 10a2 2 0 0 1 1.9-1.5h9a2 2 0 0 1 1.9 1.5L20 15.5"/><path d="M12 8.5V4"/><path d="M9 4h6"/></svg> Ship / Ferry</button></div></div>
      <div class="field-row" id="wzRouteRow">
        <div class="field" id="wzFromField"><label>From</label><input id="wzFrom" placeholder="e.g. Manila"></div>
        <div class="field"><label id="wzToLabel">Destination</label><input id="wzTo" placeholder="e.g. Tokyo / Cebu"></div>
      </div>
      <div class="wz-row" id="wzTripTypeRow"><label>Trip type</label><div class="wz-seg" data-field="tripType"><button type="button" class="is-on" data-val="Round-trip">Round-trip</button><button type="button" data-val="One-way">One-way</button></div></div>
      <div class="field-row">
        <div class="field"><label id="wzDepartLabel">Flying / sailing out</label><input id="wzDepart" data-datepick placeholder="Pick a date" readonly></div>
        <div class="field" id="wzReturnRow"><label id="wzReturnLabel">Coming back</label><input id="wzReturn" data-datepick placeholder="Pick a date" readonly></div>
      </div>
      <div class="field-row">
        <div class="wz-row"><label>Adults</label><div class="wz-count"><button type="button" data-c="adults" data-d="-1">−</button><span id="cAdults">1</span><button type="button" data-c="adults" data-d="1">+</button></div></div>
        <div class="wz-row"><label>Children</label><div class="wz-count"><button type="button" data-c="children" data-d="-1">−</button><span id="cChildren">0</span><button type="button" data-c="children" data-d="1">+</button></div></div>
      </div>
      <div class="bk__nav"><button class="btn btn--ghost" id="bkBack1">Back</button><button class="btn btn--primary" id="toStep3">Continue {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <p class="lead" style="font-size:16px;margin-bottom:16px" id="wzStepStayTitle">Stay &amp; preferences</p>
      <div class="wz-row" id="wzHotelRow"><label>Need a hotel?</label><div class="wz-seg" data-field="hotel"><button type="button" data-val="Yes">Yes</button><button type="button" class="is-on" data-val="No">No</button></div></div>
      <div class="wz-row" id="wzStarRow" style="display:none"><label>Hotel class</label><div class="wz-seg" data-field="star"><button type="button" data-val="3-star">3★</button><button type="button" data-val="4-star">4★</button><button type="button" data-val="5-star">5★</button><button type="button" data-val="Any">Any</button></div></div>
      <div class="wz-row"><label>Budget per person</label><div class="wz-seg" data-field="budget"><button type="button" class="is-on" data-val="Not sure yet">Not sure yet</button><button type="button" data-val="Under ₱20,000">Under ₱20,000</button><button type="button" data-val="₱20,000 – ₱40,000">₱20,000 – ₱40,000</button><button type="button" data-val="₱40,000 – ₱80,000">₱40,000 – ₱80,000</button><button type="button" data-val="₱80,000+">₱80,000+</button></div></div>
      <div class="wz-row"><label>Occasion (optional)</label><div class="wz-seg" data-field="occasion"><button type="button" data-val="Leisure">Leisure</button><button type="button" data-val="Honeymoon">Honeymoon</button><button type="button" data-val="Family trip">Family trip</button><button type="button" data-val="Barkada / Group">Barkada / Group</button><button type="button" data-val="Business">Business</button><button type="button" data-val="OFW vacation">OFW vacation</button></div></div>
      <div class="field"><label>Anything else we should know?</label><textarea id="wzNotes" rows="3" placeholder="Specific dates, dietary needs, must-sees…"></textarea></div>
      <div class="bk__nav"><button class="btn btn--ghost" id="bkBack2">Back</button><button class="btn btn--primary" id="toStep4">Continue {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <p class="lead" style="font-size:16px;margin-bottom:16px">Where should we send your quote?</p>
      <div class="field"><label>Full name</label><input id="wzName" placeholder="Juan dela Cruz"></div>
      <div class="field-row">
        <div class="field"><label>Email</label><input type="email" id="wzEmail" placeholder="you@email.com"></div>
        <div class="field"><label>Mobile / WhatsApp</label><input id="wzPhone" placeholder="+63 9xx xxx xxxx"></div>
      </div>
      <div class="wz-row"><label>Preferred contact</label><div class="wz-seg" data-field="contact"><button type="button" class="is-on" data-val="WhatsApp">WhatsApp</button><button type="button" data-val="Viber">Viber</button><button type="button" data-val="Call">Call</button><button type="button" data-val="Email">Email</button></div></div>
      <div class="wz-err" id="wzErr"></div>
      <p class="modal__note" style="text-align:left;margin-top:10px">We'll reply with a tailored quote — no payment online.</p>
      <div class="bk__nav"><button class="btn btn--ghost" id="bkBack3">Back</button><button class="btn btn--primary" id="wzSubmit">Send my inquiry {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <div class="bk__done">
        <div class="bk__check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4 10-10"/></svg></div>
        <h3 style="font-size:26px">Inquiry sent</h3>
        <p class="lead" style="font-size:16px;margin-top:8px">Thank you! A travel specialist will send your tailored quote and itinerary shortly.</p>
        <div class="bk__ref">Reference: <span id="bkRef">BK-XXXXXX</span></div>
        <div class="bk__nav" style="justify-content:center;margin-top:28px"><button class="btn btn--primary" id="bkDone">Done</button></div>
      </div>
    </div>
  </div>
</div></div>
HTML;
    }
}
