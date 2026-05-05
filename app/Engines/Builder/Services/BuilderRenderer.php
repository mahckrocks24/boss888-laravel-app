<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

class BuilderRenderer
{
    public function renderWebsite(string $subdomain, string $slug = 'home'): ?string
    {
        $website = DB::table('websites')
            ->where('subdomain', $subdomain . '.levelupgrowth.io')
            ->where('status', 'published')
            ->first();

        if (!$website) return null;

        $page = DB::table('pages')
            ->where('website_id', $website->id)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (!$page) return null;

        $brand = DB::table('creative_brand_identities')
            ->where('workspace_id', $website->workspace_id)
            ->first();

        return $this->renderPage((array) $website, (array) $page, $brand ? (array) $brand : []);
    }

        public function renderPage(array $website, array $page, array $brand = []): string
    {
        $sections = $page['sections_json'] ?? '{}';
        if (is_string($sections)) $sections = json_decode($sections, true);
        $secs = $sections['sections'] ?? (is_array($sections) ? $sections : []);

        $settings = $website['settings_json'] ?? '{}';
        if (is_string($settings)) $settings = json_decode($settings, true);

        $seoJson = $page['seo_json'] ?? '{}';
        if (is_string($seoJson)) $seoJson = json_decode($seoJson, true);

        $tokens = [
            'primary'      => $brand['primary_color']   ?? $settings['primary_color']   ?? '#6C5CE7',
            'secondary'    => $brand['secondary_color']  ?? $settings['secondary_color']  ?? '#00E5A8',
            'accent'       => $brand['accent_color']     ?? $settings['accent_color']     ?? '#F4F7FB',
            'font_heading' => $settings['font_heading']  ?? 'Syne',
            'font_body'    => $settings['font_body']     ?? 'DM Sans',
        ];

        $allPages = DB::table('pages')
            ->where('website_id', $website['id'])
            ->where('status', 'published')
            ->orderBy('position')
            ->get(['slug', 'title'])
            ->toArray();

        $content = '';
        foreach ($secs as $sec) {
            $content .= $this->renderSection($sec, $tokens, $website, $allPages, $page['slug'] ?? 'home');
        }

        // Build SEO context
        $subdomain = str_replace('.levelupgrowth.io', '', $website['subdomain'] ?? '');
        $slug = $page['slug'] ?? 'home';
        $pageUrl = "https://{$subdomain}.levelupgrowth.io" . ($slug === 'home' ? '/' : "/{$slug}");
        $siteUrl = "https://{$subdomain}.levelupgrowth.io";

        $metaDescription = $page['meta_description']
            ?? $seoJson['description']
            ?? $this->generateMetaDescription($secs, $website['name'] ?? '');

        $metaTitle = $page['meta_title'] ?? $seoJson['title'] ?? null;
        $heroImage = $this->findHeroImage($secs);

        $seoContext = [
            'page_url'         => $pageUrl,
            'site_url'         => $siteUrl,
            'subdomain'        => $subdomain,
            'meta_description' => $metaDescription,
            'meta_title'       => $metaTitle,
            'hero_image'       => $heroImage,
            'ga4_id'           => $settings['ga4_id'] ?? null,
            'gtm_id'           => $settings['gtm_id'] ?? null,
        ];

        return $this->getFullHtml($content, $tokens, $website['name'] ?? 'Website', $page['title'] ?? 'Home', $seoContext);
    }

    public function renderSection(array $sec, array $brand, array $website = [], array $allPages = [], string $currentSlug = 'home'): string
    {
        $type = $sec['type'] ?? 'text';
        $style = $sec['style'] ?? [];
        $comps = $sec['components'] ?? [];

        return match ($type) {
            'header'       => $this->renderHeader($sec, $brand, $allPages, $currentSlug),
            'hero'         => $this->renderHero($sec, $brand),
            'features'     => $this->renderFeatures($sec, $brand),
            'cta'          => $this->renderCta($sec, $brand),
            'contact_form' => $this->renderContact($sec, $brand),
            'footer'       => $this->renderFooter($sec, $brand),
            default        => $this->renderGeneric($sec, $brand),
        };
    }

    private function renderHeader(array $sec, array $brand, array $allPages, string $currentSlug): string
    {
        $comps = $sec['components'] ?? [];
        $brandName = '';
        $navText = '';
        $ctaText = '';
        $ctaHref = '#contact';

        foreach ($comps as $c) {
            if ($c['type'] === 'heading') $brandName = $c['text'] ?? '';
            if ($c['type'] === 'text') $navText = $c['text'] ?? '';
            if ($c['type'] === 'button') { $ctaText = $c['text'] ?? ''; $ctaHref = $c['href'] ?? '#contact'; }
        }

        $navItems = array_filter(array_map('trim', explode('·', $navText)));
        $slugMap = ['home'=>'home','about'=>'about','about us'=>'about','services'=>'services','contact'=>'contact','blog'=>'blog','portfolio'=>'portfolio'];

        $navHtml = '';
        foreach ($navItems as $item) {
            $slug = $slugMap[strtolower($item)] ?? strtolower(str_replace(' ', '-', $item));
            $active = $slug === $currentSlug;
            $color = $active ? $brand['primary'] : 'rgba(255,255,255,.8)';
            $weight = $active ? '700' : '500';
            $border = $active ? "border-bottom:2px solid {$brand['primary']}" : 'border-bottom:2px solid transparent';
            $navHtml .= "<a href=\"/{$slug}\" style=\"color:{$color};text-decoration:none;font-size:14px;font-weight:{$weight};{$border};padding-bottom:4px;transition:all .2s\">" . e($item) . "</a>";
        }

        $ctaBtn = $ctaText ? "<a href=\"{$ctaHref}\" style=\"background:{$brand['primary']};color:#fff;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none\">" . e($ctaText) . "</a>" : '';

        return <<<HTML
<nav style="background:rgba(15,17,23,0.97);border-bottom:1px solid rgba(255,255,255,.06);position:sticky;top:0;z-index:100;backdrop-filter:blur(12px)">
  <div style="max-width:1100px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:68px">
    <a href="/" style="font-family:'{$brand['font_heading']}',sans-serif;font-size:20px;font-weight:800;color:{$brand['primary']};text-decoration:none">{$brandName}</a>
    <div style="display:flex;align-items:center;gap:28px">{$navHtml}{$ctaBtn}</div>
  </div>
</nav>
HTML;
    }

    private function renderHero(array $sec, array $brand): string
    {
        $style = $sec['style'] ?? [];
        $comps = $sec['components'] ?? [];
        $heading = $sec['heading'] ?? '';
        $sub = $sec['subheading'] ?? '';
        $ctaText = $sec['cta_text'] ?? '';
        $ctaHref = $sec['cta_link'] ?? '#contact';
        $bgImg = $sec['background_image'] ?? '';

        foreach ($comps as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? $heading;
            if ($c['type'] === 'text') $sub = $c['text'] ?? $sub;
            if ($c['type'] === 'button') { $ctaText = $c['text'] ?? $ctaText; $ctaHref = $c['href'] ?? $c['content']['href'] ?? $ctaHref; }
        }

        $bgCss = '';
        if ($bgImg) {
            $bgCss = "background-image:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.6)),url({$bgImg});background-size:cover;background-position:center;";
        } elseif (!empty($style['gradient'])) {
            $bgCss = "background:{$style['gradient']};";
        } else {
            $bgCss = "background:linear-gradient(135deg, {$brand['primary']} 0%, {$brand['secondary']} 100%);";
        }

        $variant = '';
        foreach ($comps as $c) {
            if (($c['type'] ?? '') === 'button') $variant = $c['variant'] ?? $c['content']['variant'] ?? 'primary';
        }
        $btnStyle = $variant === 'white'
            ? "background:#fff;color:{$brand['primary']}"
            : "background:{$brand['primary']};color:#fff";
        $btn = $ctaText ? "<a href=\"{$ctaHref}\" style=\"{$btnStyle};padding:14px 32px;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;display:inline-block;margin-top:12px\">" . e($ctaText) . "</a>" : '';

        return <<<HTML
<section style="{$bgCss}padding:100px 24px;text-align:center">
  <div style="max-width:800px;margin:0 auto">
    <h1 style="font-family:'{$brand['font_heading']}',sans-serif;font-size:clamp(32px,5vw,56px);font-weight:800;color:#fff;margin-bottom:16px;line-height:1.15">{$heading}</h1>
    <p style="font-size:18px;color:rgba(255,255,255,.85);line-height:1.7;margin-bottom:24px">{$sub}</p>
    {$btn}
  </div>
</section>
HTML;
    }

    private function renderFeatures(array $sec, array $brand): string
    {
        $bg = $sec['style']['bg'] ?? '#ffffff';
        $isLight = $this->isLight($bg);
        $headColor = $isLight ? '#1a1a2e' : '#ffffff';
        $textColor = $isLight ? '#5a5f72' : 'rgba(255,255,255,.7)';
        $cardBg = $isLight ? '#fff' : 'rgba(255,255,255,.05)';
        $cardBorder = $isLight ? '1px solid rgba(0,0,0,.07)' : '1px solid rgba(255,255,255,.08)';

        $heading = '';
        $items = [];
        foreach ($sec['components'] ?? [] as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? '';
            if ($c['type'] === 'cards') $items = $c['items'] ?? [];
        }
        if (!$heading) $heading = $sec['heading'] ?? '';
        if (!$items && isset($sec['items'])) $items = $sec['items'];

        $cardsHtml = '';
        foreach ($items as $item) {
            $icon = $item['icon'] ?? '⭐';
            $title = e($item['heading'] ?? $item['title'] ?? '');
            $text = e($item['text'] ?? $item['description'] ?? '');
            $cardsHtml .= "<div style=\"background:{$cardBg};border:{$cardBorder};border-radius:16px;padding:32px;border-top:3px solid {$brand['primary']}\"><span style=\"font-size:36px;display:block;margin-bottom:16px\">{$icon}</span><h3 style=\"color:{$headColor};font-size:20px;margin-bottom:8px\">{$title}</h3><p style=\"color:{$textColor};font-size:14px;line-height:1.6;margin:0\">{$text}</p></div>";
        }

        return <<<HTML
<section style="background:{$bg};padding:80px 24px">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="font-family:'{$brand['font_heading']}',sans-serif;color:{$headColor};text-align:center;font-size:clamp(24px,3.5vw,40px);margin-bottom:40px">{$heading}</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px">{$cardsHtml}</div>
  </div>
</section>
HTML;
    }

    private function renderCta(array $sec, array $brand): string
    {
        $style = $sec['style'] ?? [];
        $bgCss = !empty($style['gradient']) ? "background:{$style['gradient']};" : "background:linear-gradient(135deg, {$brand['primary']} 0%, {$brand['secondary']} 100%);";

        $heading = '';
        $text = '';
        $btnText = '';
        $btnHref = '#contact';
        foreach ($sec['components'] ?? [] as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? '';
            if ($c['type'] === 'text') $text = $c['text'] ?? '';
            if ($c['type'] === 'button') { $btnText = $c['text'] ?? ''; $btnHref = $c['href'] ?? '#contact'; }
        }
        if (!$heading) $heading = $sec['heading'] ?? '';
        if (!$text) $text = $sec['body'] ?? '';
        if (!$btnText) $btnText = $sec['cta_text'] ?? 'Get Started';

        $btn = "<a href=\"{$btnHref}\" style=\"background:#fff;color:{$brand['primary']};padding:14px 32px;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;display:inline-block\">" . e($btnText) . "</a>";

        return <<<HTML
<section style="{$bgCss}padding:80px 24px;text-align:center">
  <div style="max-width:700px;margin:0 auto">
    <h2 style="font-family:'{$brand['font_heading']}',sans-serif;color:#fff;font-size:clamp(24px,3.5vw,40px);margin-bottom:12px">{$heading}</h2>
    <p style="color:rgba(255,255,255,.85);font-size:16px;margin-bottom:28px">{$text}</p>
    {$btn}
  </div>
</section>
HTML;
    }

    private function renderContact(array $sec, array $brand): string
    {
        $bg = $sec['style']['bg'] ?? '#ffffff';
        $isLight = $this->isLight($bg);
        $headColor = $isLight ? '#1a1a2e' : '#ffffff';
        $textColor = $isLight ? '#5a5f72' : 'rgba(255,255,255,.7)';

        $heading = '';
        $text = '';
        $fields = [];
        $submitLabel = 'Send Message';
        foreach ($sec['components'] ?? [] as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? '';
            if ($c['type'] === 'text') $text = $c['text'] ?? '';
            if ($c['type'] === 'form') {
                $fields = $c['fields'] ?? [];
                $submitLabel = $c['content']['submit_label'] ?? 'Send Message';
            }
        }
        if (!$heading) $heading = $sec['heading'] ?? '';
        if (!$fields) $fields = $sec['fields'] ?? [['label'=>'Name','placeholder'=>'Your name'],['label'=>'Email','placeholder'=>'you@email.com'],['label'=>'Message','placeholder'=>'Your message']];

        $fieldsHtml = '';
        foreach ($fields as $f) {
            $label = e(is_string($f) ? ucfirst($f) : ($f['label'] ?? ''));
            $ph = e(is_string($f) ? '' : ($f['placeholder'] ?? ''));
            $tag = (strtolower($label) === 'message') ? 'textarea' : 'input';
            $fieldsHtml .= "<div style=\"margin-bottom:16px\"><label style=\"display:block;font-size:13px;font-weight:600;color:{$headColor};margin-bottom:6px\">{$label}</label>";
            if ($tag === 'textarea') {
                $fieldsHtml .= "<textarea placeholder=\"{$ph}\" rows=\"4\" style=\"width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical\"></textarea>";
            } else {
                $fieldsHtml .= "<input type=\"text\" placeholder=\"{$ph}\" style=\"width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit\">";
            }
            $fieldsHtml .= '</div>';
        }

        return <<<HTML
<section style="background:{$bg};padding:80px 24px">
  <div style="max-width:600px;margin:0 auto">
    <h2 style="font-family:'{$brand['font_heading']}',sans-serif;color:{$headColor};text-align:center;margin-bottom:8px">{$heading}</h2>
    <p style="color:{$textColor};text-align:center;margin-bottom:32px">{$text}</p>
    <form onsubmit="event.preventDefault();alert('Thank you! We will get back to you soon.')">
      {$fieldsHtml}
      <button type="submit" style="background:{$brand['primary']};color:#fff;border:none;padding:14px 32px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;width:100%">{$submitLabel}</button>
    </form>
  </div>
</section>
HTML;
    }

    private function renderFooter(array $sec, array $brand): string
    {
        $comps = $sec['components'] ?? [];
        $brandName = '';
        $tagline = '';
        $navText = '';
        $copyright = '';

        foreach ($comps as $i => $c) {
            if ($c['type'] === 'heading') $brandName = $c['text'] ?? '';
            if ($c['type'] === 'text') {
                if (!$tagline && $i === 1) $tagline = $c['text'] ?? '';
                elseif (str_contains($c['text'] ?? '', '·')) $navText = $c['text'] ?? '';
                elseif (str_contains($c['text'] ?? '', '©')) $copyright = $c['text'] ?? '';
                elseif (!$tagline) $tagline = $c['text'] ?? '';
            }
        }

        $navLinks = array_filter(array_map('trim', explode('·', $navText)));
        $slugMap = ['home'=>'home','about'=>'about','about us'=>'about','services'=>'services','contact'=>'contact','blog'=>'blog'];
        $navHtml = implode(' · ', array_map(function($item) use ($slugMap) {
            $slug = $slugMap[strtolower($item)] ?? strtolower(str_replace(' ', '-', $item));
            return "<a href=\"/{$slug}\" style=\"color:#6B7280;text-decoration:none;font-size:13px\">" . e($item) . "</a>";
        }, $navLinks));

        return <<<HTML
<footer style="background:#0B0E14;padding:60px 24px 40px">
  <div style="max-width:1100px;margin:0 auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:16px">
      <div>
        <div style="font-family:'{$brand['font_heading']}',sans-serif;font-size:18px;font-weight:800;color:{$brand['primary']};margin-bottom:4px">{$brandName}</div>
        <div style="font-size:13px;color:#6B7280">{$tagline}</div>
      </div>
      <nav>{$navHtml}</nav>
    </div>
    <div style="border-top:1px solid #1E2230;padding-top:16px;font-size:12px;color:#4A566B;text-align:center">{$copyright}</div>
  </div>
</footer>
HTML;
    }

    private function renderGeneric(array $sec, array $brand): string
    {
        $bg = $sec['style']['bg'] ?? '#ffffff';
        $isLight = $this->isLight($bg);
        $headColor = $isLight ? '#1a1a2e' : '#ffffff';
        $textColor = $isLight ? '#5a5f72' : 'rgba(255,255,255,.7)';

        $heading = $sec['heading'] ?? '';
        $body = $sec['body'] ?? '';
        foreach ($sec['components'] ?? [] as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? $heading;
            if ($c['type'] === 'text') $body = $c['text'] ?? $body;
        }

        $h = $heading ? "<h2 style=\"font-family:'{$brand['font_heading']}',sans-serif;color:{$headColor};margin-bottom:12px\">" . e($heading) . "</h2>" : '';
        $p = $body ? "<p style=\"color:{$textColor};line-height:1.75\">" . e($body) . "</p>" : '';

        return "<section style=\"background:{$bg};padding:80px 24px\"><div style=\"max-width:1100px;margin:0 auto\">{$h}{$p}</div></section>";
    }

        public function getFullHtml(string $content, array $brand, string $siteName, string $pageTitle, array $seo = []): string
    {
        $fh = $brand['font_heading'] ?? 'Syne';
        $fb = $brand['font_body'] ?? 'DM Sans';
        $gfonts = urlencode($fh) . ':wght@400;700&family=' . urlencode($fb) . ':wght@400;500;600';

        $primary = $brand['primary'] ?? '#6C5CE7';
        $fullTitle = ($seo['meta_title'] ?? $pageTitle) . ' — ' . $siteName;
        $desc = e($seo['meta_description'] ?? '');
        $pageUrl = $seo['page_url'] ?? '';
        $siteUrl = $seo['site_url'] ?? '';
        $heroImg = $seo['hero_image'] ?? '';
        $initial = mb_strtoupper(mb_substr($siteName, 0, 1));

        // Primary meta
        $metaHtml = "<title>{$fullTitle}</title>\n";
        $metaHtml .= "    <meta name=\"title\" content=\"{$fullTitle}\">\n";
        if ($desc) {
            $metaHtml .= "    <meta name=\"description\" content=\"{$desc}\">\n";
        }
        $metaHtml .= "    <meta name=\"robots\" content=\"index, follow\">\n";
        if ($pageUrl) {
            $metaHtml .= "    <link rel=\"canonical\" href=\"{$pageUrl}\">\n";
        }

        // Open Graph
        $metaHtml .= "    <!-- Open Graph -->\n";
        $metaHtml .= "    <meta property=\"og:type\" content=\"website\">\n";
        if ($pageUrl) $metaHtml .= "    <meta property=\"og:url\" content=\"{$pageUrl}\">\n";
        $metaHtml .= "    <meta property=\"og:title\" content=\"{$fullTitle}\">\n";
        if ($desc) $metaHtml .= "    <meta property=\"og:description\" content=\"{$desc}\">\n";
        if ($heroImg) $metaHtml .= "    <meta property=\"og:image\" content=\"{$heroImg}\">\n";
        $metaHtml .= "    <meta property=\"og:site_name\" content=\"" . e($siteName) . "\">\n";
        $metaHtml .= "    <meta property=\"og:locale\" content=\"en_AE\">\n";

        // Twitter Card
        $metaHtml .= "    <!-- Twitter Card -->\n";
        $metaHtml .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        $metaHtml .= "    <meta name=\"twitter:title\" content=\"{$fullTitle}\">\n";
        if ($desc) $metaHtml .= "    <meta name=\"twitter:description\" content=\"{$desc}\">\n";
        if ($heroImg) $metaHtml .= "    <meta name=\"twitter:image\" content=\"{$heroImg}\">\n";

        // Favicon — generated SVG from brand color + initial
        $faviconSvg = urlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='{$primary}'/><text y='.88em' x='50' font-size='65' font-family='system-ui' font-weight='700' fill='white' text-anchor='middle' dominant-baseline='auto'>{$initial}</text></svg>");
        $metaHtml .= "    <link rel=\"icon\" type=\"image/svg+xml\" href=\"data:image/svg+xml,{$faviconSvg}\">\n";

        // JSON-LD Schema
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $siteName,
            'url' => $siteUrl ?: $pageUrl,
        ];
        if ($desc) $schema['description'] = html_entity_decode($desc);
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $metaHtml .= "    <script type=\"application/ld+json\">{$schemaJson}</script>\n";

        // Google Analytics / GTM
        $analyticsHtml = '';
        $ga4 = $seo['ga4_id'] ?? '';
        $gtm = $seo['gtm_id'] ?? '';
        if ($ga4 && preg_match('/^G-[A-Z0-9]+$/', $ga4)) {
            $analyticsHtml .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga4}\"></script>\n";
            $analyticsHtml .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','{$ga4}');</script>\n";
        }
        if ($gtm && preg_match('/^GTM-[A-Z0-9]+$/', $gtm)) {
            $analyticsHtml .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm}');</script>\n";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
    {$metaHtml}
    {$analyticsHtml}
<link href="https://fonts.googleapis.com/css2?family={$gfonts}&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'{$fb}',sans-serif;line-height:1.6;-webkit-font-smoothing:antialiased;color:#1a1a2e}
h1,h2,h3,h4{font-family:'{$fh}',sans-serif;line-height:1.15;font-weight:800}
img{max-width:100%}
a{transition:opacity .2s}a:hover{opacity:.85}
input,textarea,select{font-family:inherit}
@media(max-width:768px){
  nav>div{flex-direction:column;height:auto;padding:16px!important;gap:12px!important}
  nav>div>div{flex-wrap:wrap;gap:12px!important}
  section{padding:60px 16px!important}
}
</style>
</head>
<body>
{$content}
</body>
</html>
HTML;
    }


    /**
     * Generate a meta description from page sections content.
     */
    private function generateMetaDescription(array $sections, string $siteName): string
    {
        // Try hero subheading first
        foreach ($sections as $sec) {
            $type = $sec['type'] ?? '';
            if ($type === 'hero') {
                $sub = $sec['subheading'] ?? '';
                if (!$sub) {
                    foreach ($sec['components'] ?? [] as $c) {
                        if (($c['type'] ?? '') === 'text') $sub = $c['text'] ?? '';
                    }
                }
                if ($sub && mb_strlen($sub) >= 30) {
                    return mb_substr(strip_tags($sub), 0, 155);
                }
            }
        }

        // Try first text section body
        foreach ($sections as $sec) {
            $body = $sec['body'] ?? '';
            if (!$body) {
                foreach ($sec['components'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'text') { $body = $c['text'] ?? ''; break; }
                }
            }
            if ($body && mb_strlen($body) >= 30) {
                return mb_substr(strip_tags($body), 0, 155);
            }
        }

        // Fallback
        return "{$siteName} — Professional services and solutions for your business.";
    }

    /**
     * Find hero background image URL from sections.
     */
    private function findHeroImage(array $sections): string
    {
        foreach ($sections as $sec) {
            if (($sec['type'] ?? '') === 'hero') {
                return $sec['background_image'] ?? '';
            }
        }
        return '';
    }

        private function isLight(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) !== 6) return true; // default to light for unknown colors
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return ($r * 0.299 + $g * 0.587 + $b * 0.114) > 160;
    }
}
