<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

/**
 * AMG Travel theme — pixel-faithful render of the AMG "Friendly" travel
 * prototype, hydrated from pages.sections_json. Multi-page: renders ANY page
 * by dispatching its sections in order. Gated by
 * websites.settings_json.theme === 'amg-travel'. Self-contained CSS
 * (amg-theme.css). Chatbot888 injected separately by middleware.
 */
class AmgTravelTheme
{
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
        $css = @file_get_contents(storage_path('app/amg/amg-theme.css')) ?: '';
        $js  = @file_get_contents(storage_path('app/amg/amg-theme.js')) ?: '';
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

        return $fonts . "\n<style>\n" . $css . "\n</style>\n"
            . $header . "\n<main id=\"top\">\n" . $body . "\n</main>\n" . $footer . "\n" . $drawer
            . "\n<script>\n" . $js . "\n</script>\n";
    }

    /** Blog post inner page — AMG-branded (was falling back to a LevelUp template). */
    public function renderArticle(array $article, array $website): string
    {
        $css = @file_get_contents(storage_path('app/amg/amg-theme.css')) ?: '';
        $js  = @file_get_contents(storage_path('app/amg/amg-theme.js')) ?: '';
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
        $name = $this->e($h['logo_text'] ?? 'AMG Global Travel & Tours');
        $nav  = is_array($h['nav_links'] ?? null) ? $h['nav_links'] : ['Home','About','Services','Destinations','Packages','Blog','Contact'];
        $cta  = $this->e($h['cta_text'] ?? 'Book a trip');
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
  <div class="nav-cta"><button class="btn btn--primary" data-book>{$cta} <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button></div>
  <button class="burger" id="burger" aria-label="Menu"><span></span><span></span><span></span></button>
</div></header>
<div class="mobile-nav" id="mobileNav"><button class="mobile-nav__close" id="mobileClose">&times;</button>{$mobHtml}<div class="mobile-nav__meta">Santa Rosa, Laguna<br>+63 977 807 1188</div></div>
HTML;
    }

    private function hero(array $h): string
    {
        $eyebrow = $this->e($h['eyebrow'] ?? 'PTAA Verified Member · Santa Rosa, Laguna');
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
        <div class="sf"><label>From</label><input id="hbFrom" value="Manila" placeholder="City or airport"></div>
        <div class="sf"><label>To</label><input id="hbTo" value="Tokyo" placeholder="Destination"></div>
        <button class="searchbar__go" id="heroSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> Search</button>
        <div class="sf"><label>Departure</label><input type="date" id="hbDate"></div>
        <div class="sf"><label>Travelers</label><select id="hbPax"><option>1 Adult</option><option>2 Adults</option><option>3 Adults</option><option>4+ Adults</option></select></div>
        <div class="sf"><label>Class</label><select id="hbClass"><option>Economy</option><option>Premium Economy</option><option>Business</option><option>First</option></select></div>
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
            $icon = $this->e($it['icon'] ?? '✦');
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
        $eyebrow = $this->e($s['eyebrow'] ?? 'About AMG');
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
        $heading = $this->e($s['heading'] ?? 'Why choose AMG');
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
            $cards .= "<article class=\"pkg reveal\" data-delay=\"{$i}\"><div class=\"pkg__top {$tone}\" style=\"{$bg}\"><span class=\"pkg__nights\">{$nights}</span><h3>{$title}</h3></div><div class=\"pkg__body\"><ul class=\"pkg__hl\">{$hlHtml}</ul><div class=\"pkg__foot\"><span class=\"pkg__from\">{$price}</span><button class=\"btn btn--primary\" data-book=\"{$title}\" style=\"padding:10px 18px;font-size:14px\">Book now</button></div></div></article>";
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
        $office = $this->e($c['office'] ?? 'Unit 5, Flying V Gas Station Commercial Bldg, Santa Rosa, Laguna 4026');
        $phone = $this->e($c['phone'] ?? '+63 977 807 1188 · 0917 892 6228');
        $email = $this->e($c['email'] ?? 'tours@amgglobaltravel.com');
        return <<<HTML
<section class="section"><div class="wrap contact-grid">
  <div class="info-block">
    <span class="eyebrow">{$heading}</span>
    <p class="lead" style="margin:14px 0 8px">{$body}</p>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg></div><div><h4>Office</h4><p>{$office}</p></div></div>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.6 3 .2 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></div><div><h4>Call / Viber</h4><p>{$phone}</p></div></div>
    <div class="info-item"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></div><div><h4>Email</h4><p><a href="mailto:{$email}">{$email}</a></p></div></div>
  </div>
  <form class="contact-form" id="contact-form-{$wid}" data-subdomain="{$sub}" onsubmit="luSubmitContact(event,this)" style="background:#fff;border:1px solid var(--line);border-radius:var(--r-lg);padding:30px">
    <div class="field"><label>Your name</label><input name="firstname" required maxlength="100" placeholder="Juan dela Cruz"></div>
    <div class="field-row"><div class="field"><label>Email</label><input type="email" name="email" required maxlength="255" placeholder="you@email.com"></div><div class="field"><label>Mobile / Viber</label><input type="tel" name="phone" maxlength="50" placeholder="09xx xxx xxxx"></div></div>
    <div class="field"><label>How can we help?</label><textarea name="message" rows="4" required maxlength="2000" placeholder="Where would you like to go?"></textarea></div>
    <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Send message</button>
    <div class="lu-contact-success" style="display:none;margin-top:14px;padding:12px;background:#d4edda;color:#155724;border-radius:10px;text-align:center">&#10003; Salamat! We'll get back to you soon.</div>
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
        $name = $this->e($f['logo_text'] ?? 'AMG Global Travel & Tours');
        $tag = $this->e($f['tagline'] ?? 'Domestic & international ticketing, package tours and visa processing — handled by a hands-on team that treats every traveler like family.');
        $copy = $this->e($f['copyright'] ?? '© 2026 AMG Global Travel & Tours · PTAA Verified Member · Santa Rosa, Laguna');
        $tiktok = $this->e($f['tiktok'] ?? 'https://tiktok.com/@amgglobaltrvl');
        $facebook = $this->e($f['facebook'] ?? 'https://www.facebook.com/AMGglobaltravel');

        return <<<HTML
<footer class="site-foot"><div class="wrap">
  <div class="foot-grid">
    <div class="foot-brand"><span class="brand__name">{$name}</span><p>{$tag}</p>
      <div class="foot-badges" style="margin-top:20px"><span class="foot-badge">PTAA Member</span><span class="foot-badge">DOT Accredited</span><span class="foot-badge">IATA Ticketing</span></div></div>
    <div><h5>Explore</h5><ul class="foot-links"><li><a href="/about">About</a></li><li><a href="/services">Services</a></li><li><a href="/destinations">Destinations</a></li><li><a href="/packages">Packages</a></li><li><a href="/blog">Blog</a></li></ul></div>
    <div><h5>Connect</h5><ul class="foot-links"><li><a href="{$tiktok}">TikTok</a></li><li><a href="{$facebook}">Facebook</a></li><li><a href="/contact">Contact</a></li></ul></div>
    <div><h5>Visit &amp; Reach Us</h5><div class="foot-contact">
      <div><span class="lab">Office</span>Unit 5, Flying V Gas Station Commercial Bldg, Santa Rosa, Laguna 4026</div>
      <div><span class="lab">Call</span>+63 977 807 1188<br>0917 892 6228</div>
      <div><span class="lab">Email</span><a href="mailto:tours@amgglobaltravel.com">tours@amgglobaltravel.com</a></div>
    </div></div>
  </div>
  <div class="foot-bottom"><span>{$copy}</span>
    <span style="font-family:var(--f-mono);font-size:11px;letter-spacing:.1em;color:var(--silver)">PTAA VERIFIED MEMBER · SANTA ROSA, LAGUNA</span></div>
</div></footer>
HTML;
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
        <button type="button" class="wz-opt" data-svc="Flights"><span class="wz-opt__i">✈️</span>Flights</button>
        <button type="button" class="wz-opt" data-svc="Ship / Ferry"><span class="wz-opt__i">⛴️</span>Ship / Ferry</button>
        <button type="button" class="wz-opt" data-svc="Hotel"><span class="wz-opt__i">🏨</span>Hotel</button>
        <button type="button" class="wz-opt" data-svc="Tour package"><span class="wz-opt__i">🌏</span>Tour package</button>
        <button type="button" class="wz-opt" data-svc="Visa"><span class="wz-opt__i">🛂</span>Visa</button>
        <button type="button" class="wz-opt" data-svc="Complete package"><span class="wz-opt__i">✨</span>Complete package</button>
      </div>
      <div class="bk__nav"><button class="btn btn--primary" id="toStep2" disabled style="opacity:.5">Continue {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <p class="lead" style="font-size:16px;margin-bottom:16px">Trip details</p>
      <div class="wz-row" id="wzTransportRow"><label>Travelling by</label><div class="wz-seg" data-field="transport"><button type="button" class="is-on" data-val="Airline">✈️ Airline</button><button type="button" data-val="Ship">⛴️ Ship / Ferry</button></div></div>
      <div class="field-row" id="wzRouteRow">
        <div class="field" id="wzFromField"><label>From</label><input id="wzFrom" placeholder="e.g. Manila"></div>
        <div class="field"><label id="wzToLabel">Destination</label><input id="wzTo" placeholder="e.g. Tokyo / Cebu"></div>
      </div>
      <div class="wz-row" id="wzTripTypeRow"><label>Trip type</label><div class="wz-seg" data-field="tripType"><button type="button" class="is-on" data-val="Round-trip">Round-trip</button><button type="button" data-val="One-way">One-way</button></div></div>
      <div class="field-row">
        <div class="field"><label id="wzDepartLabel">Flying / sailing out</label><input type="date" id="wzDepart"></div>
        <div class="field" id="wzReturnRow"><label id="wzReturnLabel">Coming back</label><input type="date" id="wzReturn"></div>
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
      <div class="field"><label>Budget per person</label><select id="wzBudget"><option value="">Not sure yet</option><option>Under ₱20,000</option><option>₱20,000 – ₱40,000</option><option>₱40,000 – ₱80,000</option><option>₱80,000+</option></select></div>
      <div class="field"><label>Occasion (optional)</label><select id="wzOccasion"><option value="">—</option><option>Leisure</option><option>Honeymoon</option><option>Family trip</option><option>Barkada / Group</option><option>Business</option></select></div>
      <div class="field"><label>Anything else we should know?</label><textarea id="wzNotes" rows="3" placeholder="Specific dates, dietary needs, must-sees…"></textarea></div>
      <div class="bk__nav"><button class="btn btn--ghost" id="bkBack2">Back</button><button class="btn btn--primary" id="toStep4">Continue {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <p class="lead" style="font-size:16px;margin-bottom:16px">Where should we send your quote?</p>
      <div class="field"><label>Full name</label><input id="wzName" placeholder="Juan dela Cruz"></div>
      <div class="field-row">
        <div class="field"><label>Email</label><input type="email" id="wzEmail" placeholder="you@email.com"></div>
        <div class="field"><label>Mobile / Viber</label><input id="wzPhone" placeholder="09xx xxx xxxx"></div>
      </div>
      <div class="wz-row"><label>Preferred contact</label><div class="wz-seg" data-field="contact"><button type="button" class="is-on" data-val="Viber">Viber</button><button type="button" data-val="Call">Call</button><button type="button" data-val="Email">Email</button></div></div>
      <div class="wz-err" id="wzErr"></div>
      <p class="modal__note" style="text-align:left;margin-top:10px">We'll reply with a tailored quote — no payment online. Sagot agad! 😊</p>
      <div class="bk__nav"><button class="btn btn--ghost" id="bkBack3">Back</button><button class="btn btn--primary" id="wzSubmit">Send my inquiry {$arrow}</button></div>
    </div>
    <div class="bk__pane">
      <div class="bk__done">
        <div class="bk__check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4 10-10"/></svg></div>
        <h3 style="font-size:26px">Inquiry sent 🎉</h3>
        <p class="lead" style="font-size:16px;margin-top:8px">Salamat! An AMG specialist will email your tailored quote and itinerary shortly.</p>
        <div class="bk__ref">Reference: <span id="bkRef">AMG-XXXXXX</span></div>
        <div class="bk__nav" style="justify-content:center;margin-top:28px"><button class="btn btn--primary" id="bkDone">Done</button></div>
      </div>
    </div>
  </div>
</div></div>
HTML;
    }
}
