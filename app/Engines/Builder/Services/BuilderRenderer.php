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

        // /* h2-renderer */ resolver wins over direct creative_brand_identities read
        $brand = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve((int) $website->workspace_id);

        return $this->renderPage((array) $website, (array) $page, $brand);
    }

        public function renderPage(array $website, array $page, array $brand = []): string
    {
        $sections = $page['sections_json'] ?? '{}';
        if (is_string($sections)) $sections = json_decode($sections, true);
        $secs = $sections['sections'] ?? (is_array($sections) ? $sections : []);

        // 2026-07-02 — full-document passthrough. A page migrated from bespoke
        // static HTML stores its EXACT markup as a single {type:'raw_document',
        // html:'...'} section so it is DB-regeneratable (satisfies platform-first)
        // with ZERO design regression. Return it verbatim — do NOT wrap in the
        // section shell (the stored markup is already a complete <html> document).
        if (is_array($secs) && count($secs) === 1 && (($secs[0]['type'] ?? '') === 'raw_document')) {
            return (string) ($secs[0]['html'] ?? '');
        }

        $settings = $website['settings_json'] ?? '{}';
        if (is_string($settings)) $settings = json_decode($settings, true);

        $seoJson = $page['seo_json'] ?? '{}';
        if (is_string($seoJson)) $seoJson = json_decode($seoJson, true);

        // /* h2-renderer-tokens */ — resolver always returns non-null brand colors
        // (workspace-grounded or neutral). Settings still wins for explicit overrides.
        // Platform defaults (#6C5CE7 / #00E5A8 / #F4F7FB) eliminated — those were
        // LevelUp-branding leaks into white-label tenant sites.
        $tokens = [
            'primary'      => $settings['primary_color']   ?? ($brand['primary_color']   ?? '#1F2937'),
            'secondary'    => $settings['secondary_color'] ?? ($brand['secondary_color'] ?? '#94A3B8'),
            'accent'       => $settings['accent_color']    ?? ($brand['accent_color']    ?? '#475569'),
            'font_heading' => $settings['font_heading']    ?? ($brand['heading_font']    ?? 'Syne'),
            'font_body'    => $settings['font_body']       ?? ($brand['body_font']       ?? 'DM Sans'),
        ];

        $allPages = DB::table('pages')
            ->where('website_id', $website['id'])
            ->where('status', 'published')
            ->orderBy('position')
            ->get(['slug', 'title'])
            ->toArray();

        $content = '';
        if (($settings['theme'] ?? null) === 'amg-travel') {
            // AMG Travel theme — 1:1 prototype render, hydrated from sections_json.
            $content = (new \App\Engines\Builder\Services\AmgTravelTheme())
                ->renderBody($secs, $tokens, $website, $page);
        } else {
            // Semantic landmarks (a11y/SEO): nav/header -> <header>, content -> <main>,
            // footer section already emits <footer>. Split the flat section list by role.
            $headerHtml = '';
            $footerHtml = '';
            $bodyHtml   = '';
            foreach ($secs as $sec) {
                $t = is_array($sec) ? ($sec['type'] ?? '') : '';
                // Broken-page resilience: a malformed section (wrong-typed field, etc.)
                // must not crash the whole page. Skip + log the offender, render the rest.
                try {
                    $rendered = $this->renderSection($sec, $tokens, $website, $allPages, $page['slug'] ?? 'home');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[BuilderRenderer] section render failed, skipping', [
                        'type' => $t, 'website_id' => $website['id'] ?? null, 'error' => $e->getMessage(),
                    ]);
                    $rendered = '';
                }
                if ($t === 'header') {
                    $headerHtml .= $rendered;
                } elseif ($t === 'footer') {
                    $footerHtml .= $rendered;
                } else {
                    $bodyHtml .= $rendered;
                }
            }
            $content = ($headerHtml !== '' ? '<header>' . $headerHtml . '</header>' : '')
                     . '<main>' . $bodyHtml . '</main>'
                     . $footerHtml;
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
            // Wave 45 — page-level AEO JSON-LD (Article + FAQPage schema)
            // emitted alongside the existing LocalBusiness schema.
            'jsonld_json'      => $page['jsonld_json'] ?? null,
        ];

        return $this->getFullHtml($content, $tokens, $website['name'] ?? 'Website', $page['title'] ?? 'Home', $seoContext, $website);
    }

    /**
     * Render a blog-post inner page for a builder site (themed). Fixes
     * /blog/{slug} on themed sites falling through to the platform's static
     * LevelUp blog-post template. Returns null if not found.
     */
    public function renderArticle(string $subdomain, string $slug): ?string
    {
        $website = DB::table('websites')
            ->where('subdomain', $subdomain . '.levelupgrowth.io')
            ->where('status', 'published')->first();
        if (!$website) return null;

        $websiteId = (int) $website->id;
        $article = DB::table('articles')
            ->where('workspace_id', $website->workspace_id)
            ->where('slug', $slug)->where('status', 'published')
            ->where('is_marketing_blog', 1)->whereNull('deleted_at')
            ->where(function ($q) use ($websiteId) { if ($websiteId > 0) { $q->where('website_id', $websiteId)->orWhereNull('website_id'); } })
            ->first();
        if (!$article) return null;

        $settings = $website->settings_json ?? '{}';
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        $brand = DB::table('creative_brand_identities')->where('workspace_id', $website->workspace_id)->first();
        $tokens = [
            'primary'      => $settings['primary_color']   ?? ($brand->primary_color   ?? '#1F2937'),
            'secondary'    => $settings['secondary_color'] ?? ($brand->secondary_color ?? '#94A3B8'),
            'accent'       => $settings['accent_color']    ?? '#475569',
            'font_heading' => $settings['font_heading']    ?? 'Syne',
            'font_body'    => $settings['font_body']        ?? 'DM Sans',
        ];

        if (($settings['theme'] ?? null) === 'amg-travel') {
            $content = (new \App\Engines\Builder\Services\AmgTravelTheme())
                ->renderArticle((array) $article, (array) $website);
        } else {
            // CONTENT-2: the page renders the title once; drop the body's own leading <h1>; show the hero.
            $body = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#is', '', (string) ($article->content ?? ''), 1) ?? (string) ($article->content ?? '');
            $hero = !empty($article->featured_image_url)
                ? '<img src="' . e($article->featured_image_url) . '" alt="' . e($article->featured_image_alt ?: $article->title) . '" style="width:100%;height:auto;border-radius:14px;margin:0 0 28px;display:block">'
                : '';
            $when = !empty($article->published_at) ? \Carbon\Carbon::parse($article->published_at)->format('F j, Y') : '';
            $content = '<section style="padding:90px 24px"><div style="max-width:820px;margin:0 auto;line-height:1.75">'
                . '<h1 style="margin:0 0 12px;font-size:2.4rem;line-height:1.15">' . e($article->title) . '</h1>'
                . ($when !== '' ? '<div style="opacity:.7;margin-bottom:24px;font-size:.9rem">' . e($when) . '</div>' : '')
                . $hero . '<div class="post-page-body">' . $body . '</div>'
                . '<a href="/blog" style="display:inline-block;margin-top:40px;font-weight:600;text-decoration:none">&larr; Back to the blog</a>'
                . '</div></section>';
        }

        $sub = str_replace('.levelupgrowth.io', '', (string) ($website->subdomain ?? ''));
        $seoContext = [
            'page_url'         => "https://{$sub}.levelupgrowth.io/blog/{$slug}",
            'site_url'         => "https://{$sub}.levelupgrowth.io",
            'subdomain'        => $sub,
            'meta_description' => $article->meta_description ?? '',
            'meta_title'       => $article->meta_title ?: $article->title,
            'hero_image'       => $article->featured_image_url ?? '',
            'jsonld_json'      => $article->jsonld_json ?? null,
        ];

        return $this->getFullHtml($content, $tokens, $website->name ?? 'Website', $article->title ?? 'Article', $seoContext, (array) $website);
    }

    public function renderSection(array $sec, array $brand, array $website = [], array $allPages = [], string $currentSlug = 'home'): string
    {
        $type = $sec['type'] ?? 'text';
        $style = $sec['style'] ?? [];
        $comps = $sec['components'] ?? [];

        // 2026-07-02 — normalise brand so every renderer has the keys it reads.
        // Renderers reference a MIX of aliases ($brand['primary'] 23×, ['primary_color'] 12×,
        // ['font_heading'] vs ['heading_font'], etc.). A site with incomplete/NULL settings_json
        // otherwise throws "Undefined array key" mid-render. Cross-alias + default all of them.
        $brand = $this->normaliseBrand($brand);

        return match ($type) {
            'header'          => $this->renderHeader($sec, $brand, $allPages, $currentSlug),
            'hero'            => $this->renderHero($sec, $brand),
            'features'        => $this->renderFeatures($sec, $brand),
            'cta'             => $this->renderCta($sec, $brand),
            'contact_form'    => $this->renderContact($sec, $brand, $website),
            'blog_list'       => $this->renderBlogList($website, $brand),
            'footer'          => $this->renderFooter($sec, $brand),
            // 2026-07-02 — content section types emitted by buildDefaultSectionsForPage
            // that previously fell to renderGeneric (dropping their items/tiers/members).
            'services'        => $this->renderServices($sec, $brand),
            'team'            => $this->renderTeam($sec, $brand),
            'testimonials'    => $this->renderTestimonials($sec, $brand),
            'faq'             => $this->renderFaq($sec, $brand),
            'pricing'         => $this->renderPricing($sec, $brand),
            'gallery'         => $this->renderGallery($sec, $brand),
            'stats'           => $this->renderStats($sec, $brand),
            // v1.4.4 Phase D-2 (2026-05-30)
            'booking_form'    => $this->renderBookingForm($sec, $brand),
            'events_calendar' => $this->renderEventsCalendar($sec, $brand),
            // v1.4.4 Phase D-3 (2026-05-30)
            'grid'             => $this->renderGrid($sec, $brand),
            'filter_bar'       => $this->renderFilterBar($sec, $brand),
            'map'              => $this->renderMap($sec, $brand),
            'related_listings' => $this->renderRelatedListings($sec, $brand),
            'trust_signals'    => $this->renderTrustSignals($sec, $brand),
            // v1.4.4 Phase D-5 (2026-05-30)
            'cart_summary'     => $this->renderCartSummary($sec, $brand),
            'checkout_form'    => $this->renderCheckoutForm($sec, $brand),
            'account_nav'      => $this->renderAccountNav($sec, $brand),
            'account_panel'    => $this->renderAccountPanel($sec, $brand),
            default            => $this->renderGeneric($sec, $brand),
        };
    }

    // ─── 2026-07-02 — brand normaliser + content-section renderers ──────────
    // These 7 types (services/team/testimonials/faq/pricing/gallery/stats) are
    // emitted by ArthurService::buildDefaultSectionsForPage but previously had no
    // match arm → they fell to renderGeneric, which reads only heading/body and
    // SILENTLY DROPPED items[]/tiers[]/members[]. Proven live 2026-07-02.

    /**
     * Coerce a brand colour to a SAFE CSS colour so it cannot break out of a
     * style="..." attribute (B7: 'red" onmouseover="alert(1)' etc.). Allows hex,
     * rgb(a)/hsl(a) with only numeric inner chars, and bare named colours; anything
     * else (containing " < ; ( outside those forms) falls back to the default.
     */
    /**
     * Coerce a brand font family to safe font-name characters so it cannot break out of a
     * style="font-family:'...'" attribute (B7). Valid CSS family names are letters, digits,
     * spaces, hyphens and underscores; anything else (" ' < ; } etc.) falls back to the default.
     */
    private function sanitizeFontName(?string $f, string $default): string
    {
        $f = trim((string) $f);
        if ($f === '') { return $default; }
        return preg_match('/^[a-zA-Z0-9 _-]{1,50}$/', $f) ? $f : $default;
    }

    private function sanitizeCssColor(?string $c, string $default): string
    {
        $c = trim((string) $c);
        if ($c === '') { return $default; }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) { return $c; }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/]+\)$/i', $c)) { return $c; }
        if (preg_match('/^[a-zA-Z]{1,30}$/', $c)) { return $c; }
        return $default;
    }

    private function normaliseBrand(array $b): array
    {
        $primary   = $b['primary']      ?? $b['primary_color']   ?? '#6C5CE7';
        $primary = $this->sanitizeCssColor($primary, '#6C5CE7'); // B7: block style-attr breakout via brand colors
        $secondary = $b['secondary']    ?? $b['secondary_color'] ?? '#00E5A8';
        $secondary = $this->sanitizeCssColor($secondary, '#00E5A8'); // B7: block style-attr breakout via brand colors
        $accent    = $b['accent_color'] ?? $b['accent']          ?? '#F4F7FB';
        $accent = $this->sanitizeCssColor($accent, '#F4F7FB'); // B7: block style-attr breakout via brand colors
        $fh        = $b['font_heading'] ?? $b['heading_font']    ?? 'Syne';
        $fh = $this->sanitizeFontName($fh, 'Syne'); // B7: block style-attr breakout via font names
        $fb        = $b['font_body']    ?? $b['body_font']       ?? 'DM Sans';
        $fb = $this->sanitizeFontName($fb, 'DM Sans'); // B7: block style-attr breakout via font names
        return array_merge($b, [
            'primary' => $primary, 'primary_color' => $primary,
            'secondary' => $secondary, 'secondary_color' => $secondary,
            'accent' => $accent, 'accent_color' => $accent,
            'font_heading' => $fh, 'heading_font' => $fh,
            'font_body' => $fb, 'body_font' => $fb,
            'logo_url' => $b['logo_url'] ?? '',
        ]);
    }

    private function sectionShell(string $heading, string $inner, array $brand, string $bg = '#ffffff'): string
    {
        $isLight = $this->isLight($bg);
        $headColor = $isLight ? '#1a1a2e' : '#ffffff';
        $h = $heading !== ''
            ? "<h2 style=\"font-family:'{$brand['font_heading']}',sans-serif;color:{$headColor};text-align:center;font-size:clamp(24px,3.5vw,40px);margin-bottom:40px\">" . e($heading) . "</h2>"
            : '';
        return "<section style=\"background:{$bg};padding:80px 24px\"><div style=\"max-width:1100px;margin:0 auto\">{$h}{$inner}</div></section>";
    }

    private function renderServices(array $sec, array $brand): string
    {
        $cards = '';
        foreach (($sec['items'] ?? []) as $it) {
            $title = e($it['title'] ?? $it['heading'] ?? $it['name'] ?? '');
            $desc  = e($it['description'] ?? $it['text'] ?? $it['body'] ?? '');
            $icon  = e($it['icon'] ?? '•');
            $cards .= "<div style=\"background:#fff;border:1px solid rgba(0,0,0,.07);border-radius:16px;padding:32px;border-top:3px solid {$brand['primary']}\"><span style=\"font-size:32px;display:block;margin-bottom:12px\">{$icon}</span><h3 style=\"color:#1a1a2e;font-size:20px;margin-bottom:8px\">{$title}</h3><p style=\"color:#5a5f72;font-size:14px;line-height:1.6;margin:0\">{$desc}</p></div>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px\">{$cards}</div>";
        return $this->sectionShell($sec['heading'] ?? 'Services', $grid, $brand);
    }

    private function renderTeam(array $sec, array $brand): string
    {
        $cards = '';
        foreach (($sec['members'] ?? $sec['items'] ?? []) as $m) {
            $name = e($m['name'] ?? '');
            $role = e($m['role'] ?? $m['title'] ?? '');
            $bio  = e($m['bio'] ?? $m['description'] ?? '');
            $img  = $m['photo'] ?? $m['image'] ?? '';
            $avatar = $img
                ? "<img src=\"" . e($img) . "\" alt=\"{$name}\" style=\"width:96px;height:96px;border-radius:50%;object-fit:cover;margin:0 auto 16px;display:block\">"
                : "<div style=\"width:96px;height:96px;border-radius:50%;background:{$brand['primary']};margin:0 auto 16px\"></div>";
            $cards .= "<div style=\"text-align:center;padding:24px\">{$avatar}<h3 style=\"color:#1a1a2e;font-size:18px;margin-bottom:4px\">{$name}</h3><p style=\"color:{$brand['primary']};font-size:13px;margin-bottom:8px\">{$role}</p><p style=\"color:#5a5f72;font-size:13px;line-height:1.6;margin:0\">{$bio}</p></div>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px\">{$cards}</div>";
        return $this->sectionShell($sec['heading'] ?? 'Meet the team', $grid, $brand, '#fafafe');
    }

    private function renderTestimonials(array $sec, array $brand): string
    {
        $cards = '';
        foreach (($sec['items'] ?? []) as $t) {
            $quote  = e($t['quote'] ?? $t['text'] ?? '');
            $author = e($t['author'] ?? $t['name'] ?? '');
            $role   = e($t['role'] ?? '');
            $cards .= "<figure style=\"background:#fff;border:1px solid rgba(0,0,0,.07);border-radius:16px;padding:28px;margin:0\"><blockquote style=\"color:#333;font-size:15px;line-height:1.7;margin:0 0 16px\">&ldquo;{$quote}&rdquo;</blockquote><figcaption style=\"color:#1a1a2e;font-weight:600;font-size:14px\">{$author}<span style=\"display:block;color:#5a5f72;font-weight:400;font-size:12px\">{$role}</span></figcaption></figure>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px\">{$cards}</div>";
        return $this->sectionShell($sec['heading'] ?? 'What clients say', $grid, $brand, '#fafafe');
    }

    private function renderFaq(array $sec, array $brand): string
    {
        $rows = '';
        foreach (($sec['items'] ?? []) as $f) {
            $q = e($f['question'] ?? $f['q'] ?? '');
            $a = e($f['answer'] ?? $f['a'] ?? '');
            $rows .= "<details style=\"border-bottom:1px solid rgba(0,0,0,.1);padding:16px 0\"><summary style=\"cursor:pointer;color:#1a1a2e;font-weight:600;font-size:16px\">{$q}</summary><p style=\"color:#5a5f72;font-size:14px;line-height:1.7;margin:12px 0 0\">{$a}</p></details>";
        }
        $inner = "<div style=\"max-width:760px;margin:0 auto\">{$rows}</div>";
        return $this->sectionShell($sec['heading'] ?? 'Frequently asked questions', $inner, $brand);
    }

    private function renderPricing(array $sec, array $brand): string
    {
        $cards = '';
        foreach (($sec['tiers'] ?? $sec['items'] ?? []) as $t) {
            $name  = e($t['name'] ?? $t['title'] ?? '');
            $price = e($t['price'] ?? '');
            $border = !empty($t['highlight']) ? "2px solid {$brand['primary']}" : "1px solid rgba(0,0,0,.1)";
            $feats = '';
            foreach (($t['features'] ?? []) as $ft) {
                $feats .= "<li style=\"color:#5a5f72;font-size:14px;padding:6px 0\">&#10003; " . e($ft) . "</li>";
            }
            $cards .= "<div style=\"background:#fff;border:{$border};border-radius:16px;padding:32px;text-align:center\"><h3 style=\"color:#1a1a2e;font-size:20px;margin-bottom:8px\">{$name}</h3><div style=\"color:{$brand['primary']};font-size:32px;font-weight:700;margin-bottom:16px\">{$price}</div><ul style=\"list-style:none;padding:0;margin:0;text-align:left\">{$feats}</ul></div>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px\">{$cards}</div>";
        return $this->sectionShell($sec['heading'] ?? 'Pricing', $grid, $brand);
    }

    private function renderGallery(array $sec, array $brand): string
    {
        $cols = (int) ($sec['columns'] ?? 3);
        if ($cols < 1) { $cols = 3; }
        $tiles = '';
        foreach (($sec['images'] ?? $sec['items'] ?? []) as $img) {
            $url = is_array($img) ? ($img['url'] ?? $img['src'] ?? '') : (string) $img;
            $cap = is_array($img) ? ($img['caption'] ?? $img['alt'] ?? '') : '';
            if ($url === '') { continue; }
            $tiles .= "<figure style=\"margin:0\"><img src=\"" . e($url) . "\" alt=\"" . e($cap) . "\" style=\"width:100%;height:220px;object-fit:cover;border-radius:12px;display:block\">" . ($cap ? "<figcaption style=\"color:#5a5f72;font-size:12px;margin-top:6px\">" . e($cap) . "</figcaption>" : '') . "</figure>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat({$cols},1fr);gap:16px\">{$tiles}</div>";
        return $this->sectionShell($sec['heading'] ?? 'Gallery', $grid, $brand);
    }

    private function renderStats(array $sec, array $brand): string
    {
        $cells = '';
        foreach (($sec['items'] ?? []) as $s) {
            $val = e($s['value'] ?? '');
            $lbl = e($s['label'] ?? '');
            $cells .= "<div style=\"text-align:center;padding:16px\"><div style=\"color:{$brand['primary']};font-size:40px;font-weight:800;line-height:1\">{$val}</div><div style=\"color:#5a5f72;font-size:14px;margin-top:8px\">{$lbl}</div></div>";
        }
        $grid = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:24px\">{$cells}</div>";
        return $this->sectionShell($sec['heading'] ?? '', $grid, $brand, '#fafafe');
    }

    /**
     * v1.4.4 Phase D-2 (2026-05-30) — Booking / appointment form.
     * Standalone section with optional service selector, date picker,
     * time-slot chips, and contact fields. POSTs to the same /contact
     * intake endpoint as renderContact() — the email/CRM side detects
     * the booking shape from the payload fields and routes accordingly.
     */
    private function renderBookingForm(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary  = $brand['primary_color']   ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? 'Book a time'),  ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''),         ENT_QUOTES, 'UTF-8');
        $body     = htmlspecialchars((string) ($sec['body'] ?? ''),               ENT_QUOTES, 'UTF-8');
        $submit   = htmlspecialchars((string) ($sec['submit_label'] ?? 'Book now'), ENT_QUOTES, 'UTF-8');
        $success  = htmlspecialchars((string) ($sec['success_message'] ?? 'Thanks — we\'ll confirm your booking shortly.'), ENT_QUOTES, 'UTF-8');
        $services = is_array($sec['services'] ?? null) ? $sec['services'] : [];
        $showCal  = !empty($sec['show_calendar']);
        $showTime = !empty($sec['show_time_slots']);
        $fields   = is_array($sec['fields'] ?? null) ? $sec['fields'] : [
            ['name' => 'name',  'label' => 'Your name',    'type' => 'text',  'required' => true],
            ['name' => 'email', 'label' => 'Email',        'type' => 'email', 'required' => true],
            ['name' => 'phone', 'label' => 'Phone',        'type' => 'tel',   'required' => false],
            ['name' => 'notes', 'label' => 'Anything we should know?', 'type' => 'textarea', 'required' => false],
        ];

        $serviceOptions = '';
        if (!empty($services)) {
            $opts = '';
            foreach ($services as $s) {
                $sEsc = htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
                $opts .= "<option value=\"{$sEsc}\">{$sEsc}</option>";
            }
            $serviceOptions = "<div class=\"book-row\"><label>Service<select name=\"service\" required><option value=\"\" disabled selected>Choose…</option>{$opts}</select></label></div>";
        }

        $dateRow = $showCal ? '<div class="book-row"><label>Date<input type="date" name="booking_date" required></label></div>' : '';
        $timeRow = $showTime ? '<div class="book-row"><label>Time<input type="time" name="booking_time" required></label></div>' : '';

        $fieldHtml = '';
        foreach ($fields as $f) {
            $name  = htmlspecialchars((string) ($f['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($f['label'] ?? ucfirst($name)), ENT_QUOTES, 'UTF-8');
            $type  = (string) ($f['type'] ?? 'text');
            $req   = !empty($f['required']) ? 'required' : '';
            if ($type === 'textarea') {
                $fieldHtml .= "<div class=\"book-row\"><label>{$label}<textarea name=\"{$name}\" rows=\"3\" {$req}></textarea></label></div>";
            } else {
                $fieldHtml .= "<div class=\"book-row\"><label>{$label}<input type=\"" . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . "\" name=\"{$name}\" {$req}></label></div>";
            }
        }

        $css = "<style>
.book-section{padding:80px 24px;background:#fafafa;font-family:inherit}
.book-wrap{max-width:680px;margin:0 auto;background:#fff;border-radius:16px;padding:48px 40px;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.book-wrap h2{font-size:32px;margin:0 0 8px;font-weight:700;letter-spacing:-.5px}
.book-wrap .sub{color:#6b7280;font-size:16px;margin-bottom:24px}
.book-wrap .body{color:#374151;font-size:15px;margin-bottom:24px;line-height:1.6}
.book-row{margin-bottom:16px}
.book-row label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
.book-row input,.book-row select,.book-row textarea{width:100%;padding:12px 14px;font-size:15px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit}
.book-row input:focus,.book-row select:focus,.book-row textarea:focus{outline:none;border-color:{$primary};box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.book-submit{margin-top:8px;width:100%;padding:14px 24px;font-size:16px;font-weight:600;background:{$primary};color:{$onPrimary};border:none;border-radius:8px;cursor:pointer;transition:opacity .15s}
.book-submit:hover{opacity:.92}
.book-success{display:none;background:#ecfdf5;color:#065f46;padding:16px;border-radius:8px;margin-bottom:16px}
.book-success.active{display:block}
</style>";

        return $css . "
<section class=\"book-section\" id=\"booking\">
  <div class=\"book-wrap\">
    <h2>{$heading}</h2>" . ($sub ? "<div class=\"sub\">{$sub}</div>" : '') . "
    " . ($body ? "<div class=\"body\">{$body}</div>" : '') . "
    <div class=\"book-success\" id=\"book-success\">{$success}</div>
    <form id=\"booking-form\" onsubmit=\"event.preventDefault();document.getElementById('book-success').classList.add('active');this.style.display='none';\">
      {$serviceOptions}
      {$dateRow}
      {$timeRow}
      {$fieldHtml}
      <button type=\"submit\" class=\"book-submit\">{$submit}</button>
    </form>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-2 (2026-05-30) — Events / classes calendar.
     * Renders a card-grid of upcoming events. View mode controls
     * layout (list | grid | calendar); calendar view falls back to grid
     * if no events list provided.
     */
    private function renderEventsCalendar(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary  = $brand['primary_color']   ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? 'Upcoming events'), ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''),             ENT_QUOTES, 'UTF-8');
        $body     = htmlspecialchars((string) ($sec['body'] ?? ''),                   ENT_QUOTES, 'UTF-8');
        $events   = is_array($sec['events'] ?? null) ? $sec['events'] : [];
        $view     = strtolower((string) ($sec['view'] ?? 'grid'));
        $max      = isset($sec['max_events']) ? max(1, (int) $sec['max_events']) : count($events);
        $events   = array_slice($events, 0, $max);
        $ctaText  = htmlspecialchars((string) ($sec['cta_text'] ?? ''),               ENT_QUOTES, 'UTF-8');
        $ctaUrl   = $this->safeUrl((string) ($sec['cta_url'] ?? ''), '');

        if (empty($events)) {
            return "
<section class=\"events-section\" style=\"padding:80px 24px;text-align:center;font-family:inherit\">
  <h2 style=\"font-size:32px;margin:0 0 8px;letter-spacing:-.5px\">{$heading}</h2>" . ($sub ? "<div style=\"color:#6b7280;margin-bottom:16px\">{$sub}</div>" : '') . "
  <p style=\"color:#6b7280;max-width:520px;margin:0 auto\">No events scheduled yet. Check back soon.</p>
</section>";
        }

        $isList = $view === 'list';
        $gridStyle = $isList
            ? 'display:flex;flex-direction:column;gap:16px;max-width:760px;margin:0 auto'
            : 'display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));max-width:1100px;margin:0 auto';

        $cards = '';
        foreach ($events as $ev) {
            $title = htmlspecialchars((string) ($ev['title'] ?? 'Event'), ENT_QUOTES, 'UTF-8');
            $date  = htmlspecialchars((string) ($ev['date']  ?? ''),     ENT_QUOTES, 'UTF-8');
            $time  = htmlspecialchars((string) ($ev['time']  ?? ''),     ENT_QUOTES, 'UTF-8');
            $loc   = htmlspecialchars((string) ($ev['location'] ?? ''),   ENT_QUOTES, 'UTF-8');
            $desc  = htmlspecialchars((string) ($ev['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img   = htmlspecialchars((string) ($ev['image']   ?? ''),    ENT_QUOTES, 'UTF-8');
            $ctaT  = htmlspecialchars((string) ($ev['cta_text'] ?? 'RSVP'), ENT_QUOTES, 'UTF-8');
            $ctaU  = $this->safeUrl((string) ($ev['cta_url'] ?? '#'), '#');

            $cards .= "
  <article style=\"background:#fff;border:1px solid #eef0f4;border-radius:14px;overflow:hidden;display:flex;flex-direction:column\">" .
        ($img ? "<div style=\"width:100%;height:160px;background:url('{$img}') center/cover no-repeat\"></div>" : '') . "
    <div style=\"padding:20px 22px;display:flex;flex-direction:column;gap:8px;flex:1\">
      <div style=\"font-size:11px;font-weight:700;color:{$primary};letter-spacing:.4px;text-transform:uppercase\">{$date}" . ($time ? " · {$time}" : '') . "</div>
      <h3 style=\"margin:0;font-size:18px;font-weight:700\">{$title}</h3>" .
      ($loc ? "<div style=\"font-size:13px;color:#6b7280\">📍 {$loc}</div>" : '') .
      ($desc ? "<p style=\"margin:8px 0 0;color:#4b5563;font-size:14px;line-height:1.6;flex:1\">{$desc}</p>" : '') . "
      <a href=\"{$ctaU}\" style=\"margin-top:14px;align-self:flex-start;padding:8px 16px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:6px;font-size:14px;font-weight:600\">{$ctaT}</a>
    </div>
  </article>";
        }

        $bottomCta = ($ctaText && $ctaUrl)
            ? "<div style=\"text-align:center;margin-top:32px\"><a href=\"{$ctaUrl}\" style=\"padding:12px 24px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:8px;font-weight:600\">{$ctaText}</a></div>"
            : '';

        return "
<section class=\"events-section\" id=\"events\" style=\"padding:80px 24px;background:#fafafa;font-family:inherit\">
  <div style=\"max-width:1100px;margin:0 auto 32px;text-align:center\">
    <h2 style=\"font-size:32px;margin:0 0 8px;letter-spacing:-.5px\">{$heading}</h2>" .
    ($sub ? "<div style=\"color:#6b7280;font-size:16px\">{$sub}</div>" : '') .
    ($body ? "<p style=\"color:#4b5563;font-size:15px;max-width:640px;margin:12px auto 0;line-height:1.6\">{$body}</p>" : '') . "
  </div>
  <div style=\"{$gridStyle}\">{$cards}</div>
  {$bottomCta}
</section>
";
    }

    private function renderHeader(array $sec, array $brand, array $allPages, string $currentSlug): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
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

        if ($brandName === '') $brandName = $sec['logo_text'] ?? '';
        $logoImg = trim((string) ($sec['logo_image'] ?? $sec['logo_url'] ?? ($brand['logo_url'] ?? '')));
        $brandInner = $logoImg !== '' ? '<img src="' . e($logoImg) . '" alt="' . e($brandName) . '" style="height:46px;width:auto;display:block">' : e($brandName);
        if ($ctaText === '' && !empty($sec['cta_text'])) { $ctaText = $sec['cta_text']; $ctaHref = $sec['cta_url'] ?? $ctaHref; }

        $slugMap = ['home'=>'home','about'=>'about','about us'=>'about','services'=>'services','contact'=>'contact','blog'=>'blog','portfolio'=>'portfolio'];

        // v1.4.4 Phase D-7 (2026-05-30) — three-tier nav resolution:
        //   1) explicit nav_links field on the section (clean shape)
        //   2) legacy "Home · About · …" text component (backwards-compat)
        //   3) auto-derive from $allPages so a newly-added page lands in the
        //      nav with zero manual editing (the orphan-page problem)
        $items = [];   // shape: [['label' => 'About', 'slug' => 'about', 'url' => '/about'], …]

        if (!empty($sec['nav_links']) && is_array($sec['nav_links'])) {
            foreach ($sec['nav_links'] as $nl) {
                if (is_string($nl)) {
                    $lbl  = trim($nl);
                    if ($lbl === '') continue;
                    $slug = $slugMap[strtolower($lbl)] ?? strtolower(str_replace(' ', '-', $lbl));
                    $items[] = ['label' => $lbl, 'slug' => $slug, 'url' => $slug === 'home' ? '/' : "/{$slug}"];
                } elseif (is_array($nl)) {
                    $lbl  = trim((string) ($nl['label'] ?? ''));
                    $url  = (string) ($nl['url'] ?? '');
                    if ($lbl === '') continue;
                    $slug = $slugMap[strtolower($lbl)] ?? strtolower(str_replace(' ', '-', $lbl));
                    $items[] = ['label' => $lbl, 'slug' => $slug, 'url' => $url !== '' ? $url : ($slug === 'home' ? '/' : "/{$slug}")];
                }
            }
        } elseif ($navText !== '') {
            foreach (array_filter(array_map('trim', explode('·', $navText))) as $lbl) {
                $slug = $slugMap[strtolower($lbl)] ?? strtolower(str_replace(' ', '-', $lbl));
                $items[] = ['label' => $lbl, 'slug' => $slug, 'url' => $slug === 'home' ? '/' : "/{$slug}"];
            }
        } elseif (!empty($allPages)) {
            // Auto-derive from published pages. Home first, legal/commerce/account pages
            // are kept out of the primary header (they belong in footer / user menu).
            $exclude = ['privacy', 'privacy-policy', 'privacy_policy', 'terms', 'terms-of-service', 'terms_of_service', 'legal', 'cart', 'checkout', 'account', 'my-account', 'my_account'];
            $homeItem = null;
            $others   = [];
            foreach ($allPages as $p) {
                $pSlug  = is_array($p)  ? ($p['slug']  ?? '') : ($p->slug  ?? '');
                $pTitle = is_array($p)  ? ($p['title'] ?? '') : ($p->title ?? '');
                if ($pSlug === '' || in_array($pSlug, $exclude, true)) continue;
                if ($pSlug === 'home') {
                    $homeItem = ['label' => 'Home', 'slug' => 'home', 'url' => '/'];
                    continue;
                }
                $label = $pTitle !== '' ? $pTitle : ucwords(str_replace(['-', '_'], ' ', $pSlug));
                $others[] = ['label' => $label, 'slug' => $pSlug, 'url' => "/{$pSlug}"];
            }
            if ($homeItem) $items[] = $homeItem;
            $items = array_merge($items, $others);
            $items = array_slice($items, 0, 7);
        }

        $navHtml = '';
        foreach ($items as $it) {
            $lbl    = $it['label'];
            $slug   = $it['slug'];
            $url    = $it['url'];
            $active = $slug === $currentSlug;
            $color  = $active ? $brand['primary'] : 'rgba(255,255,255,.8)';
            $weight = $active ? '700' : '500';
            $border = $active ? "border-bottom:2px solid {$brand['primary']}" : 'border-bottom:2px solid transparent';
            $navHtml .= "<a href=\"" . $this->safeUrl((string) $url) . "\" style=\"color:{$color};text-decoration:none;font-size:14px;font-weight:{$weight};{$border};padding-bottom:4px;transition:all .2s\">" . e($lbl) . "</a>";
        }

        // BUILDER888 P0-3 (2026-08-09) — header CTA reaches every page.
        $ctaHref = $this->safeUrl($ctaHref);
        $ctaBtn = $ctaText ? "<a href=\"{$ctaHref}\" style=\"background:{$brand['primary']};color:{$onPrimary};padding:10px 22px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none\">" . e($ctaText) . "</a>" : '';

        return <<<HTML
<nav style="background:rgba(15,17,23,0.97);border-bottom:1px solid rgba(255,255,255,.06);position:sticky;top:0;z-index:100;backdrop-filter:blur(12px)">
  <div style="max-width:1100px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:68px">
    <a href="/" style="font-family:'{$brand['font_heading']}',sans-serif;font-size:20px;font-weight:800;color:{$brand['primary']};text-decoration:none">{$brandInner}</a>
    <div style="display:flex;align-items:center;gap:28px">{$navHtml}{$ctaBtn}</div>
  </div>
</nav>
HTML;
    }

    /**
     * BUILDER888 P0-3 (2026-08-09) — URL scheme allowlist for anything that
     * lands in an href/src on a published customer site.
     *
     * htmlspecialchars() alone does not help here: "javascript:alert(1)"
     * contains no escapable character, so an escaped value still executes.
     * Anything not plainly navigable collapses to '#'.
     */
    private function safeUrl(?string $url, string $fallback = '#'): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return $fallback;
        }

        // Relative, root-relative, anchor and query links are always fine.
        if (preg_match('#^(/|\#|\?)#', $url)) {
            return e($url);
        }

        // Strip control characters and entities used to smuggle a scheme
        // past a naive check (e.g. "java\tscript:", "java&#09;script:").
        $probe = strtolower(preg_replace('/[\x00-\x20]|&#[0-9a-fx]+;?/i', '', $url));

        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $probe, $m)) {
            if (! in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
                return $fallback;
            }
            return e($url);
        }

        // No scheme at all — a bare host or path such as "example.com/x".
        return e($url);
    }

    /**
     * Make a user-supplied CSS value safe to interpolate inside a double-quoted
     * style="..." attribute. Strips the chars that enable HTML-attribute breakout
     * or CSS-structure injection, then HTML-escapes for the attribute context.
     * Preserves #hex, rgb()/hsl(), and linear-gradient(...).
     */
    private function safeCss(?string $v, string $fallback = ''): string
    {
        $v = trim((string) $v);
        if ($v === '') { return $fallback; }
        $v = str_replace(['<', '>', '"', "'", '\\', ';', '{', '}'], '', $v);
        $probe = strtolower($v);
        foreach (['expression(', 'javascript:', 'vbscript:', 'behavior:', '@import'] as $bad) {
            if (strpos($probe, $bad) !== false) { return $fallback; }
        }
        return e($v);
    }
    private function renderHero(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
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
        $safeBgImg = $bgImg ? $this->safeUrl($bgImg, '') : '';
        if ($safeBgImg !== '' && $safeBgImg !== '#') {
            $bgCss = "background-image:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.6)),url({$safeBgImg});background-size:cover;background-position:center;";
        } elseif (!empty($style['gradient'])) {
            $bgCss = "background:" . $this->safeCss((string) $style['gradient']) . ";";
        } else {
            $bgCss = "background:linear-gradient(135deg, {$brand['primary']} 0%, {$brand['secondary']} 100%);";
        }

        $variant = '';
        foreach ($comps as $c) {
            if (($c['type'] ?? '') === 'button') $variant = $c['variant'] ?? $c['content']['variant'] ?? 'primary';
        }
        $btnStyle = $variant === 'white'
            ? "background:#fff;color:{$brand['primary']}"
            : "background:{$brand['primary']};color:{$onPrimary}";
        // BUILDER888 P0-3 (2026-08-09) — hero heading/subheading were the
        // most exposed fields on the platform: interpolated raw into the
        // heredoc. cta_link / components[].href accepted javascript:.
        $heading = e($heading);
        $sub     = e($sub);
        $ctaHref = $this->safeUrl($ctaHref);
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
        $bg = $this->safeCss((string) ($sec['style']['bg'] ?? ''), '#ffffff');
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
        // BUILDER888 P0-3 (2026-08-09) — heading reaches the heredoc raw.
        $heading = e($heading);

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
        $bgCss = !empty($style['gradient']) ? ("background:" . $this->safeCss((string) $style['gradient']) . ";") : "background:linear-gradient(135deg, {$brand['primary']} 0%, {$brand['secondary']} 100%);";

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

        // BUILDER888 P0-3 (2026-08-09) — heading/text were interpolated raw
        // into the heredoc below and btnHref accepted any scheme.
        $heading = e($heading);
        $text    = e($text);
        $btnHref = $this->safeUrl($btnHref);
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

    private function renderContact(array $sec, array $brand, array $website = []): string
    {
        $bg = $this->safeCss((string) ($sec['style']['bg'] ?? ''), '#ffffff');
        $isLight = $this->isLight($bg);
        $headColor = $isLight ? '#1a1a2e' : '#ffffff';
        $textColor = $isLight ? '#5a5f72' : 'rgba(255,255,255,.7)';

        $heading = '';
        $text = '';
        $submitLabel = 'Send Message';
        foreach ($sec['components'] ?? [] as $c) {
            if ($c['type'] === 'heading') $heading = $c['text'] ?? '';
            if ($c['type'] === 'text')    $text    = $c['text'] ?? '';
            if ($c['type'] === 'form')    $submitLabel = $c['content']['submit_label'] ?? 'Send Message';
        }
        if (!$heading) $heading = $sec['heading'] ?? '';

        // Resolve subdomain prefix (websites.subdomain stores full hostname).
        $fullSubdomain  = (string) ($website['subdomain'] ?? '');
        $subdomain      = str_replace('.levelupgrowth.io', '', $fullSubdomain);
        $subdomainAttr  = e($subdomain);
        $websiteId      = (int) ($website['id'] ?? 0);
        $primaryColor   = $brand['primary'] ?? '#6C5CE7';
        $onPrimary      = $this->isLight($primaryColor) ? '#111111' : '#ffffff';
        $headingSafe    = e($heading);
        $textSafe       = e($text);
        $submitLabelSafe = e($submitLabel);
        $fontHeading    = $brand['font_heading'] ?? 'Syne';

        return <<<HTML
<section style="background:{$bg};padding:80px 24px">
  <div style="max-width:600px;margin:0 auto">
    <h2 style="font-family:'{$fontHeading}',sans-serif;color:{$headColor};text-align:center;margin-bottom:8px">{$headingSafe}</h2>
    <p style="color:{$textColor};text-align:center;margin-bottom:32px">{$textSafe}</p>
    <form class="contact-form" id="contact-form-{$websiteId}" data-subdomain="{$subdomainAttr}" onsubmit="luSubmitContact(event, this)">
      <div style="margin-bottom:16px">
        <input type="text"  name="firstname" aria-label="Your name" placeholder="Your name"  required maxlength="100" style="width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit">
      </div>
      <div style="margin-bottom:16px">
        <input type="email" name="email"     aria-label="Email address" placeholder="you@email.com" required maxlength="255" style="width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit">
      </div>
      <div style="margin-bottom:16px">
        <input type="tel"   name="phone"     aria-label="Phone number (optional)" placeholder="Your phone (optional)" maxlength="50" style="width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit">
      </div>
      <div style="margin-bottom:16px">
        <textarea name="message" aria-label="Your message" placeholder="Your message" rows="4" required maxlength="2000" style="width:100%;padding:12px;border:1px solid rgba(0,0,0,.1);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical"></textarea>
      </div>
      <button type="submit" style="background:{$primaryColor};color:{$onPrimary};border:none;padding:14px 32px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;width:100%">{$submitLabelSafe}</button>
      <div class="lu-contact-success" style="display:none;margin-top:16px;padding:12px;background:#d4edda;color:#155724;border-radius:8px;text-align:center">&#10003; Thank you! We will get back to you soon.</div>
      <div class="lu-contact-error" style="display:none;margin-top:16px;padding:12px;background:#f8d7da;color:#721c24;border-radius:8px;text-align:center">Something went wrong. Please try again.</div>
    </form>
  </div>
</section>
<script>
if (typeof window.luSubmitContact === 'undefined') {
  window.luSubmitContact = function(e, form) {
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]');
    var origLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    var subdomain = form.dataset.subdomain;
    var successEl = form.querySelector('.lu-contact-success');
    var errorEl   = form.querySelector('.lu-contact-error');
    if (successEl) successEl.style.display = 'none';
    if (errorEl)   errorEl.style.display   = 'none';
    fetch('/api/public/contact/' + encodeURIComponent(subdomain), {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify({
        firstname: form.firstname.value,
        email:     form.email.value,
        phone:     form.phone ? form.phone.value : '',
        message:   form.message.value
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.success) {
        if (successEl) successEl.style.display = 'block';
        form.reset();
      } else {
        if (errorEl) errorEl.style.display = 'block';
      }
      btn.disabled = false;
      btn.textContent = origLabel;
    })
    .catch(function() {
      if (errorEl) errorEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = origLabel;
    });
  };
}
</script>
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

        // Escape user-supplied brand/footer text (raw heredoc interpolation).
        $brandName = e($brandName);
        $tagline   = e($tagline);
        $copyright = e($copyright);

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

    /**
     * v1.4.4 Phase D-3 (2026-05-30) — Generic card grid.
     * Powers listing browsers (real estate, hotels, products, courses).
     * Items: {title, subtitle, image, price, badge, cta_text, cta_url, tags?}
     */
    private function renderGrid(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary  = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? ''),    ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''), ENT_QUOTES, 'UTF-8');
        $body     = htmlspecialchars((string) ($sec['body'] ?? ''),       ENT_QUOTES, 'UTF-8');
        $items    = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $columns  = max(2, min(4, (int) ($sec['columns'] ?? 3)));
        $style    = (string) ($sec['style'] ?? 'card');
        $ctaText  = htmlspecialchars((string) ($sec['cta_text'] ?? ''),   ENT_QUOTES, 'UTF-8');
        $ctaUrl   = $this->safeUrl((string) ($sec['cta_url'] ?? ''), '');
        $gridId   = 'grid-' . substr(md5($heading . count($items)), 0, 8);

        if (empty($items)) {
            return "<section class=\"grid-section\" id=\"{$gridId}\" style=\"padding:80px 24px;text-align:center;font-family:inherit\"><h2 style=\"font-size:30px;margin:0 0 12px;letter-spacing:-.5px\">{$heading}</h2><p style=\"color:#6b7280\">No items to show yet.</p></section>";
        }

        $cards = '';
        foreach ($items as $it) {
            $t      = htmlspecialchars((string) ($it['title'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $stitle = htmlspecialchars((string) ($it['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img    = htmlspecialchars((string) ($it['image'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $price  = htmlspecialchars((string) ($it['price'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $badge  = htmlspecialchars((string) ($it['badge'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $tags   = is_array($it['tags'] ?? null) ? $it['tags'] : [];
            $cT     = htmlspecialchars((string) ($it['cta_text'] ?? 'View details'), ENT_QUOTES, 'UTF-8');
            $cU     = $this->safeUrl((string) ($it['cta_url'] ?? '#'), '#');

            $tagsHtml = '';
            foreach ($tags as $tg) {
                $tgEsc = htmlspecialchars((string) $tg, ENT_QUOTES, 'UTF-8');
                $tagsHtml .= "<span style=\"display:inline-block;padding:3px 9px;font-size:11px;background:#eef0f4;color:#374151;border-radius:99px;margin:0 4px 4px 0\">{$tgEsc}</span>";
            }

            $badgeHtml = $badge ? "<span style=\"position:absolute;top:12px;left:12px;background:{$primary};color:{$onPrimary};font-size:11px;font-weight:700;padding:4px 10px;border-radius:99px;letter-spacing:.3px;text-transform:uppercase;z-index:1\">{$badge}</span>" : '';
            $imgBlock  = $img ? "<div style=\"width:100%;height:180px;background:url('{$img}') center/cover no-repeat\"></div>" : '';

            $cards .= "
  <article data-grid-item style=\"position:relative;background:#fff;border:1px solid #eef0f4;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:transform .15s,box-shadow .15s\" onmouseover=\"this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 24px rgba(0,0,0,.08)'\" onmouseout=\"this.style.transform='';this.style.boxShadow=''\">
    {$badgeHtml}
    {$imgBlock}
    <div style=\"padding:20px 22px;display:flex;flex-direction:column;gap:6px;flex:1\">
      <h3 style=\"margin:0;font-size:17px;font-weight:700;color:#111827\">{$t}</h3>" .
      ($stitle ? "<div style=\"font-size:13px;color:#6b7280\">{$stitle}</div>" : '') .
      ($price ? "<div style=\"margin-top:4px;font-size:18px;font-weight:700;color:{$primary}\">{$price}</div>" : '') .
      ($tagsHtml ? "<div style=\"margin-top:10px\">{$tagsHtml}</div>" : '') . "
      <a href=\"{$cU}\" style=\"margin-top:14px;align-self:flex-start;padding:8px 16px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:6px;font-size:13px;font-weight:600\">{$cT}</a>
    </div>
  </article>";
        }

        $bottomCta = ($ctaText && $ctaUrl)
            ? "<div style=\"text-align:center;margin-top:36px\"><a href=\"{$ctaUrl}\" style=\"padding:12px 24px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:8px;font-weight:600\">{$ctaText}</a></div>"
            : '';

        return "
<section class=\"grid-section\" id=\"{$gridId}\" style=\"padding:80px 24px;background:#fafafa;font-family:inherit\">
  " . ($heading ? "<div style=\"max-width:1100px;margin:0 auto 36px;text-align:center\"><h2 style=\"font-size:32px;margin:0 0 8px;letter-spacing:-.5px\">{$heading}</h2>" . ($sub ? "<div style=\"color:#6b7280;font-size:16px\">{$sub}</div>" : '') . ($body ? "<p style=\"color:#4b5563;font-size:15px;max-width:640px;margin:12px auto 0;line-height:1.6\">{$body}</p>" : '') . "</div>" : '') . "
  <div style=\"display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));max-width:1100px;margin:0 auto\">{$cards}</div>
  {$bottomCta}
</section>
";
    }

    /**
     * v1.4.4 Phase D-3 (2026-05-30) — Filter bar with chips + search.
     * Client-side filtering targets a sibling grid by id (target_grid_id).
     * Filters: array of {label, options[]}.
     */
    private function renderFilterBar(array $sec, array $brand): string
    {
        $primary  = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? ''), ENT_QUOTES, 'UTF-8');
        $target   = htmlspecialchars((string) ($sec['target_grid_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $filters  = is_array($sec['filters'] ?? null) ? $sec['filters'] : [];
        $search   = !empty($sec['search_enabled']);
        $sort     = is_array($sec['sort_options'] ?? null) ? $sec['sort_options'] : [];

        $searchHtml = $search
            ? "<input type=\"search\" placeholder=\"Search…\" style=\"flex:1;min-width:200px;padding:10px 14px;font-size:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff\">"
            : '';

        $filterHtml = '';
        foreach ($filters as $f) {
            $lbl  = htmlspecialchars((string) ($f['label'] ?? 'Filter'), ENT_QUOTES, 'UTF-8');
            $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
            $optHtml = '<option value="">' . $lbl . '</option>';
            foreach ($opts as $o) {
                $oEsc = htmlspecialchars((string) $o, ENT_QUOTES, 'UTF-8');
                $optHtml .= "<option value=\"{$oEsc}\">{$oEsc}</option>";
            }
            $filterHtml .= "<select style=\"padding:10px 14px;font-size:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit\">{$optHtml}</select>";
        }

        $sortHtml = '';
        if (!empty($sort)) {
            $sortOpts = '';
            foreach ($sort as $s) {
                $sEsc = htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
                $sortOpts .= "<option value=\"{$sEsc}\">{$sEsc}</option>";
            }
            $sortHtml = "<select style=\"padding:10px 14px;font-size:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit\"><option value=\"\">Sort</option>{$sortOpts}</select>";
        }

        $dataAttr = $target ? " data-target=\"{$target}\"" : '';

        return "
<section class=\"filter-bar\" style=\"padding:32px 24px;background:#fff;border-bottom:1px solid #eef0f4;font-family:inherit\"{$dataAttr}>
  <div style=\"max-width:1100px;margin:0 auto\">
    " . ($heading ? "<h3 style=\"margin:0 0 16px;font-size:18px;font-weight:600\">{$heading}</h3>" : '') . "
    <div style=\"display:flex;gap:12px;flex-wrap:wrap;align-items:center\">
      {$searchHtml}
      {$filterHtml}
      {$sortHtml}
    </div>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-3 (2026-05-30) — Location map + list.
     * Side-by-side map iframe (or static placeholder) and a list of branches.
     */
    private function renderMap(array $sec, array $brand): string
    {
        $primary   = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading   = htmlspecialchars((string) ($sec['heading'] ?? 'Find us'),  ENT_QUOTES, 'UTF-8');
        $sub       = htmlspecialchars((string) ($sec['subheading'] ?? ''),       ENT_QUOTES, 'UTF-8');
        $body      = htmlspecialchars((string) ($sec['body'] ?? ''),             ENT_QUOTES, 'UTF-8');
        $locations = is_array($sec['locations'] ?? null) ? $sec['locations'] : [];
        $embed     = (string) ($sec['embed_url'] ?? '');
        $height    = max(280, (int) ($sec['height'] ?? 480));

        $mapPane = $embed
            ? "<iframe src=\"" . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8') . "\" style=\"width:100%;height:{$height}px;border:0;border-radius:14px\" loading=\"lazy\"></iframe>"
            : "<div style=\"width:100%;height:{$height}px;background:linear-gradient(135deg,#e0e7ff,#fce7f3);border-radius:14px;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:15px\">Map preview</div>";

        $list = '';
        foreach ($locations as $loc) {
            $name  = htmlspecialchars((string) ($loc['name']    ?? ''), ENT_QUOTES, 'UTF-8');
            $addr  = htmlspecialchars((string) ($loc['address'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars((string) ($loc['phone']   ?? ''), ENT_QUOTES, 'UTF-8');
            $hours = htmlspecialchars((string) ($loc['hours']   ?? ''), ENT_QUOTES, 'UTF-8');
            $list .= "
    <div style=\"padding:18px;border:1px solid #eef0f4;border-radius:12px;background:#fff;margin-bottom:12px\">
      <div style=\"font-size:16px;font-weight:700;margin-bottom:6px;color:#111827\">{$name}</div>" .
        ($addr  ? "<div style=\"font-size:13px;color:#4b5563;margin-bottom:4px\">📍 {$addr}</div>"  : '') .
        ($phone ? "<div style=\"font-size:13px;color:#4b5563;margin-bottom:4px\">📞 <a href=\"tel:" . preg_replace('/[^0-9+]/', '', $phone) . "\" style=\"color:{$primary};text-decoration:none\">{$phone}</a></div>" : '') .
        ($hours ? "<div style=\"font-size:13px;color:#6b7280\">🕒 {$hours}</div>" : '') . "
    </div>";
        }

        return "
<section class=\"map-section\" style=\"padding:80px 24px;background:#fff;font-family:inherit\">
  <div style=\"max-width:1200px;margin:0 auto\">
    <div style=\"text-align:center;margin-bottom:36px\">
      <h2 style=\"font-size:32px;margin:0 0 8px;letter-spacing:-.5px\">{$heading}</h2>" .
      ($sub ? "<div style=\"color:#6b7280;font-size:16px\">{$sub}</div>" : '') .
      ($body ? "<p style=\"color:#4b5563;font-size:15px;max-width:640px;margin:12px auto 0;line-height:1.6\">{$body}</p>" : '') . "
    </div>
    <div style=\"display:grid;gap:24px;grid-template-columns:1fr 360px;align-items:start\">
      <div>{$mapPane}</div>
      <div>{$list}</div>
    </div>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-3 (2026-05-30) — Related listings cross-sell.
     * Compact 3-up card row for detail pages.
     */
    private function renderRelatedListings(array $sec, array $brand): string
    {
        $primary  = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? 'You may also like'), ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''),               ENT_QUOTES, 'UTF-8');
        $items    = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $maxItems = max(1, (int) ($sec['max_items'] ?? 6));
        $items    = array_slice($items, 0, $maxItems);

        if (empty($items)) return '';

        $cards = '';
        foreach ($items as $it) {
            $t     = htmlspecialchars((string) ($it['title'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $st    = htmlspecialchars((string) ($it['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img   = htmlspecialchars((string) ($it['image'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $price = htmlspecialchars((string) ($it['price'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $cU    = $this->safeUrl((string) ($it['cta_url'] ?? '#'), '#');
            $imgBlock = $img ? "<div style=\"width:100%;height:140px;background:url('{$img}') center/cover no-repeat\"></div>" : '';
            $cards .= "
  <a href=\"{$cU}\" style=\"text-decoration:none;color:inherit\">
    <article style=\"background:#fff;border:1px solid #eef0f4;border-radius:12px;overflow:hidden\">
      {$imgBlock}
      <div style=\"padding:14px 16px\">
        <div style=\"font-size:14px;font-weight:700;color:#111827;margin-bottom:2px\">{$t}</div>" .
        ($st ? "<div style=\"font-size:12px;color:#6b7280\">{$st}</div>" : '') .
        ($price ? "<div style=\"margin-top:6px;font-size:14px;font-weight:700;color:{$primary}\">{$price}</div>" : '') . "
      </div>
    </article>
  </a>";
        }

        return "
<section class=\"related-listings\" style=\"padding:64px 24px;background:#fafafa;font-family:inherit\">
  <div style=\"max-width:1100px;margin:0 auto\">
    <div style=\"display:flex;justify-content:space-between;align-items:baseline;margin-bottom:24px;flex-wrap:wrap;gap:8px\">
      <h2 style=\"font-size:24px;margin:0;letter-spacing:-.3px\">{$heading}</h2>" .
      ($sub ? "<div style=\"color:#6b7280;font-size:14px\">{$sub}</div>" : '') . "
    </div>
    <div style=\"display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))\">{$cards}</div>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-3 (2026-05-30) — Trust signals row.
     * Logo wall, stat row, or badge row. Compact, sits under hero.
     */
    private function renderTrustSignals(array $sec, array $brand): string
    {
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading = htmlspecialchars((string) ($sec['heading'] ?? ''),    ENT_QUOTES, 'UTF-8');
        $sub     = htmlspecialchars((string) ($sec['subheading'] ?? ''), ENT_QUOTES, 'UTF-8');
        $items   = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $style   = (string) ($sec['style'] ?? 'logo_row');

        if (empty($items)) return '';

        $itemsHtml = '';
        if ($style === 'stat_row') {
            foreach ($items as $it) {
                $val  = htmlspecialchars((string) ($it['value'] ?? ''),    ENT_QUOTES, 'UTF-8');
                $lbl  = htmlspecialchars((string) ($it['label'] ?? ''),    ENT_QUOTES, 'UTF-8');
                $sub2 = htmlspecialchars((string) ($it['sublabel'] ?? ''), ENT_QUOTES, 'UTF-8');
                $itemsHtml .= "
    <div style=\"text-align:center;padding:0 18px\">
      <div style=\"font-size:36px;font-weight:800;color:{$primary};letter-spacing:-1px\">{$val}</div>
      <div style=\"font-size:14px;font-weight:600;color:#111827;margin-top:4px\">{$lbl}</div>" .
      ($sub2 ? "<div style=\"font-size:12px;color:#6b7280;margin-top:2px\">{$sub2}</div>" : '') . "
    </div>";
            }
        } elseif ($style === 'badge_row') {
            foreach ($items as $it) {
                $lbl = htmlspecialchars((string) ($it['label'] ?? ''),    ENT_QUOTES, 'UTF-8');
                $sl  = htmlspecialchars((string) ($it['sublabel'] ?? ''), ENT_QUOTES, 'UTF-8');
                $itemsHtml .= "
    <div style=\"padding:14px 22px;border:1px solid #eef0f4;border-radius:99px;background:#fff;display:inline-flex;flex-direction:column;align-items:center;min-width:140px\">
      <div style=\"font-size:14px;font-weight:700;color:#111827\">{$lbl}</div>" .
      ($sl ? "<div style=\"font-size:11px;color:#6b7280;margin-top:2px\">{$sl}</div>" : '') . "
    </div>";
            }
        } else { // logo_row
            foreach ($items as $it) {
                $lbl = htmlspecialchars((string) ($it['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $logo = htmlspecialchars((string) ($it['logo'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($logo) {
                    $itemsHtml .= "<img src=\"{$logo}\" alt=\"{$lbl}\" style=\"max-height:36px;max-width:140px;opacity:.7;filter:grayscale(1);transition:filter .2s,opacity .2s\" onmouseover=\"this.style.filter='';this.style.opacity='1'\" onmouseout=\"this.style.filter='grayscale(1)';this.style.opacity='.7'\">";
                } else {
                    $itemsHtml .= "<div style=\"font-size:16px;font-weight:700;color:#6b7280;letter-spacing:.5px;text-transform:uppercase\">{$lbl}</div>";
                }
            }
        }

        return "
<section class=\"trust-signals\" style=\"padding:48px 24px;background:#fff;border-bottom:1px solid #eef0f4;font-family:inherit\">
  <div style=\"max-width:1100px;margin:0 auto;text-align:center\">
    " . ($heading ? "<h3 style=\"margin:0 0 6px;font-size:14px;font-weight:700;color:#6b7280;letter-spacing:1px;text-transform:uppercase\">{$heading}</h3>" : '') .
    ($sub ? "<div style=\"color:#4b5563;margin-bottom:24px;font-size:15px\">{$sub}</div>" : '<div style="height:8px"></div>') . "
    <div style=\"display:flex;flex-wrap:wrap;gap:24px;justify-content:center;align-items:center\">{$itemsHtml}</div>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-5 (2026-05-30) — Cart summary.
     * Renders cart line items + totals + checkout CTA. If items empty,
     * shows an "empty cart" message with continue-shopping link.
     */
    private function renderCartSummary(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary  = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? 'Your cart'),    ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''),           ENT_QUOTES, 'UTF-8');
        $items    = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $currency = (string) ($sec['currency'] ?? 'AED');
        $ctaText  = htmlspecialchars((string) ($sec['cta_text'] ?? 'Proceed to checkout'), ENT_QUOTES, 'UTF-8');
        $ctaUrl   = $this->safeUrl((string) ($sec['cta_url'] ?? '/checkout'), '/checkout');
        $contUrl  = $this->safeUrl((string) ($sec['continue_shopping_url'] ?? '/shop'), '/shop');
        $empty    = htmlspecialchars((string) ($sec['empty_message'] ?? 'Your cart is empty.'), ENT_QUOTES, 'UTF-8');

        if (empty($items)) {
            return "
<section class=\"cart-summary\" style=\"padding:80px 24px;text-align:center;font-family:inherit\">
  <h2 style=\"font-size:30px;margin:0 0 12px;letter-spacing:-.5px\">{$heading}</h2>
  <p style=\"color:#6b7280;font-size:16px;margin-bottom:24px\">{$empty}</p>
  <a href=\"{$contUrl}\" style=\"display:inline-block;padding:12px 28px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:8px;font-weight:600\">Continue shopping</a>
</section>";
        }

        $rows = '';
        foreach ($items as $it) {
            $name  = htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $qty   = (int) ($it['qty'] ?? 1);
            $price = htmlspecialchars((string) ($it['price'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $sub2  = htmlspecialchars((string) ($it['subtotal'] ?? ''), ENT_QUOTES, 'UTF-8');
            $img   = htmlspecialchars((string) ($it['image'] ?? ''),    ENT_QUOTES, 'UTF-8');
            $thumb = $img ? "<div style=\"width:64px;height:64px;background:url('{$img}') center/cover no-repeat;border-radius:8px;flex-shrink:0\"></div>" : '<div style="width:64px;height:64px;background:#eef0f4;border-radius:8px;flex-shrink:0"></div>';
            $rows .= "
    <div style=\"display:flex;gap:16px;align-items:center;padding:16px 0;border-bottom:1px solid #eef0f4\">
      {$thumb}
      <div style=\"flex:1\">
        <div style=\"font-weight:600;color:#111827\">{$name}</div>
        <div style=\"font-size:13px;color:#6b7280;margin-top:2px\">Qty {$qty} · {$currency} {$price}</div>
      </div>
      <div style=\"font-weight:700;color:#111827\">{$currency} {$sub2}</div>
    </div>";
        }

        $totalsRow = function (string $label, ?string $val) use ($currency): string {
            if ($val === null || $val === '') return '';
            $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $v = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            return "<div style=\"display:flex;justify-content:space-between;padding:8px 0;color:#4b5563;font-size:14px\"><span>{$l}</span><span>{$currency} {$v}</span></div>";
        };

        $totals = '';
        $totals .= $totalsRow((string) ($sec['subtotal_label'] ?? 'Subtotal'), isset($sec['subtotal']) ? (string) $sec['subtotal'] : null);
        $totals .= $totalsRow((string) ($sec['tax_label']      ?? 'Tax (VAT)'), isset($sec['tax']) ? (string) $sec['tax'] : null);
        $totals .= $totalsRow((string) ($sec['shipping_label'] ?? 'Shipping'), isset($sec['shipping']) ? (string) $sec['shipping'] : null);

        $totalLabel = htmlspecialchars((string) ($sec['total_label'] ?? 'Total'), ENT_QUOTES, 'UTF-8');
        $totalVal   = htmlspecialchars((string) ($sec['total'] ?? ''), ENT_QUOTES, 'UTF-8');
        $totalRow   = $totalVal !== '' ? "<div style=\"display:flex;justify-content:space-between;padding:14px 0 0;margin-top:8px;border-top:1px solid #eef0f4;font-size:18px;font-weight:700;color:#111827\"><span>{$totalLabel}</span><span>{$currency} {$totalVal}</span></div>" : '';

        return "
<section class=\"cart-summary\" style=\"padding:64px 24px;background:#fff;font-family:inherit\">
  <div style=\"max-width:720px;margin:0 auto;background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:32px\">
    <h2 style=\"font-size:24px;margin:0 0 8px;letter-spacing:-.3px\">{$heading}</h2>" .
    ($sub ? "<div style=\"color:#6b7280;font-size:14px;margin-bottom:8px\">{$sub}</div>" : '') . "
    <div style=\"margin-top:16px\">{$rows}</div>
    <div style=\"margin-top:20px\">{$totals}{$totalRow}</div>
    <a href=\"{$ctaUrl}\" style=\"display:block;text-align:center;margin-top:24px;padding:14px 24px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:8px;font-weight:600\">{$ctaText}</a>
    <a href=\"{$contUrl}\" style=\"display:block;text-align:center;margin-top:8px;padding:10px;color:#6b7280;text-decoration:none;font-size:14px\">← Continue shopping</a>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-5 (2026-05-30) — Checkout form.
     * Multi-section form (contact, shipping, payment) + optional sidebar
     * order summary. Renders as a single <form>; submission handler is
     * the storefront's responsibility (out of scope for this section).
     */
    private function renderCheckoutForm(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary  = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading  = htmlspecialchars((string) ($sec['heading'] ?? 'Checkout'), ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($sec['subheading'] ?? ''),      ENT_QUOTES, 'UTF-8');
        $submit   = htmlspecialchars((string) ($sec['submit_label'] ?? 'Place order'), ENT_QUOTES, 'UTF-8');
        $success  = htmlspecialchars((string) ($sec['success_message'] ?? 'Thanks — your order is on its way!'), ENT_QUOTES, 'UTF-8');
        $methods  = is_array($sec['payment_methods'] ?? null) ? $sec['payment_methods'] : [];

        $sections = is_array($sec['sections'] ?? null) ? $sec['sections'] : [
            ['title' => 'Contact', 'fields' => [
                ['name' => 'email', 'label' => 'Email',  'type' => 'email', 'required' => true],
                ['name' => 'phone', 'label' => 'Phone',  'type' => 'tel',   'required' => true],
            ]],
            ['title' => 'Shipping address', 'fields' => [
                ['name' => 'name',     'label' => 'Full name',     'type' => 'text',    'required' => true],
                ['name' => 'address1', 'label' => 'Address line 1', 'type' => 'text',    'required' => true],
                ['name' => 'address2', 'label' => 'Address line 2 (optional)', 'type' => 'text', 'required' => false],
                ['name' => 'city',     'label' => 'City',          'type' => 'text',    'required' => true],
                ['name' => 'country',  'label' => 'Country',       'type' => 'text',    'required' => true],
            ]],
            ['title' => 'Payment', 'fields' => [
                ['name' => 'card_name',   'label' => 'Name on card', 'type' => 'text', 'required' => true],
                ['name' => 'card_number', 'label' => 'Card number',  'type' => 'text', 'required' => true],
                ['name' => 'card_expiry', 'label' => 'Expiry (MM/YY)', 'type' => 'text', 'required' => true],
                ['name' => 'card_cvc',    'label' => 'CVC',          'type' => 'text', 'required' => true],
            ]],
        ];

        $sectionsHtml = '';
        foreach ($sections as $s) {
            $title  = htmlspecialchars((string) ($s['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fields = is_array($s['fields'] ?? null) ? $s['fields'] : [];
            $fHtml = '';
            foreach ($fields as $f) {
                $name = htmlspecialchars((string) ($f['name'] ?? ''),  ENT_QUOTES, 'UTF-8');
                $lbl  = htmlspecialchars((string) ($f['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $type = htmlspecialchars((string) ($f['type'] ?? 'text'), ENT_QUOTES, 'UTF-8');
                $req  = !empty($f['required']) ? 'required' : '';
                $fHtml .= "<div style=\"margin-bottom:14px\"><label style=\"display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px\">{$lbl}</label><input type=\"{$type}\" name=\"{$name}\" {$req} style=\"width:100%;padding:11px 14px;font-size:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit\"></div>";
            }
            $sectionsHtml .= "<fieldset style=\"border:none;padding:0;margin:0 0 28px\"><legend style=\"font-size:16px;font-weight:700;margin-bottom:14px;color:#111827\">{$title}</legend>{$fHtml}</fieldset>";
        }

        $methodsHtml = '';
        if (!empty($methods)) {
            $chips = '';
            foreach ($methods as $m) {
                $mEsc = htmlspecialchars((string) $m, ENT_QUOTES, 'UTF-8');
                $chips .= "<span style=\"display:inline-block;padding:6px 14px;font-size:12px;font-weight:600;background:#f3f4f6;border-radius:99px;color:#374151;margin-right:8px;margin-bottom:8px\">{$mEsc}</span>";
            }
            $methodsHtml = "<div style=\"margin-bottom:20px\"><div style=\"font-size:12px;color:#6b7280;margin-bottom:6px;letter-spacing:.5px;text-transform:uppercase\">We accept</div>{$chips}</div>";
        }

        $summary = is_array($sec['order_summary'] ?? null) ? $sec['order_summary'] : null;
        $sidebarHtml = '';
        if ($summary !== null) {
            $sumItems = is_array($summary['items'] ?? null) ? $summary['items'] : [];
            $sumRows = '';
            foreach ($sumItems as $it) {
                $n = htmlspecialchars((string) ($it['name']  ?? ''), ENT_QUOTES, 'UTF-8');
                $p = htmlspecialchars((string) ($it['price'] ?? ''), ENT_QUOTES, 'UTF-8');
                $sumRows .= "<div style=\"display:flex;justify-content:space-between;padding:6px 0;font-size:14px\"><span>{$n}</span><span>{$p}</span></div>";
            }
            $tot = htmlspecialchars((string) ($summary['total'] ?? ''), ENT_QUOTES, 'UTF-8');
            $sidebarHtml = "<aside style=\"background:#f9fafb;border-radius:12px;padding:24px;height:fit-content\"><h3 style=\"margin:0 0 14px;font-size:16px;font-weight:700\">Order summary</h3>{$sumRows}" . ($tot !== '' ? "<div style=\"margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;font-weight:700\"><span>Total</span><span>{$tot}</span></div>" : '') . "</aside>";
        }

        $layout = $sidebarHtml !== '' ? 'grid;gap:32px;grid-template-columns:1fr 320px' : 'block';

        return "
<section class=\"checkout-form\" style=\"padding:64px 24px;background:#fafafa;font-family:inherit\">
  <div style=\"max-width:1100px;margin:0 auto\">
    <h2 style=\"font-size:30px;margin:0 0 8px;letter-spacing:-.5px\">{$heading}</h2>" .
    ($sub ? "<div style=\"color:#6b7280;font-size:15px;margin-bottom:24px\">{$sub}</div>" : '<div style="height:24px"></div>') . "
    <div id=\"checkout-success\" style=\"display:none;background:#ecfdf5;color:#065f46;padding:16px;border-radius:8px;margin-bottom:16px\">{$success}</div>
    <div style=\"display:{$layout}\">
      <form id=\"checkout-form\" onsubmit=\"event.preventDefault();document.getElementById('checkout-success').style.display='block';this.style.display='none';\" style=\"background:#fff;padding:32px;border-radius:14px;border:1px solid #eef0f4\">
        {$methodsHtml}
        {$sectionsHtml}
        <button type=\"submit\" style=\"width:100%;padding:14px 24px;font-size:16px;font-weight:600;background:{$primary};color:{$onPrimary};border:none;border-radius:8px;cursor:pointer\">{$submit}</button>
      </form>
      {$sidebarHtml}
    </div>
  </div>
</section>
";
    }

    /**
     * v1.4.4 Phase D-5 (2026-05-30) — Account nav.
     * Vertical or top nav for /account/* pages.
     */
    private function renderAccountNav(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading = htmlspecialchars((string) ($sec['heading'] ?? 'Your account'), ENT_QUOTES, 'UTF-8');
        $sub     = htmlspecialchars((string) ($sec['subheading'] ?? ''),           ENT_QUOTES, 'UTF-8');
        $items   = is_array($sec['items'] ?? null) ? $sec['items'] : [
            ['label' => 'Orders',    'url' => '/account/orders'],
            ['label' => 'Addresses', 'url' => '/account/addresses'],
            ['label' => 'Profile',   'url' => '/account/profile'],
            ['label' => 'Wishlist',  'url' => '/account/wishlist'],
        ];
        $orient  = (string) ($sec['orientation'] ?? 'vertical');
        $logout  = $this->safeUrl((string) ($sec['logout_url'] ?? '/logout'), '/logout');

        $navItems = '';
        foreach ($items as $it) {
            $lbl    = htmlspecialchars((string) ($it['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url    = $this->safeUrl((string) ($it['url'] ?? '#'), '#');
            $active = !empty($it['active']);
            $bg     = $active ? "background:{$primary};color:{$onPrimary}" : 'background:transparent;color:#374151';
            $border = $orient === 'top' ? '' : 'border-radius:8px;';
            $navItems .= "<a href=\"{$url}\" style=\"display:block;padding:10px 16px;{$border}font-size:14px;font-weight:600;text-decoration:none;margin-bottom:4px;{$bg}\">{$lbl}</a>";
        }

        if ($orient === 'top') {
            return "
<section class=\"account-nav\" style=\"padding:24px;background:#fff;border-bottom:1px solid #eef0f4;font-family:inherit\">
  <div style=\"max-width:1100px;margin:0 auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center\">
    " . ($heading ? "<div style=\"margin-right:24px\"><div style=\"font-weight:700;font-size:14px;color:#111827\">{$heading}</div>" . ($sub ? "<div style=\"font-size:12px;color:#6b7280\">{$sub}</div>" : '') . "</div>" : '') . "
    {$navItems}
    <a href=\"{$logout}\" style=\"margin-left:auto;padding:10px 16px;font-size:13px;color:#6b7280;text-decoration:none\">Log out</a>
  </div>
</section>";
        }

        return "
<aside class=\"account-nav\" style=\"padding:32px 24px;background:#fff;border-right:1px solid #eef0f4;font-family:inherit\">
  <div style=\"max-width:280px\">
    " . ($heading ? "<div style=\"font-weight:700;font-size:18px;margin-bottom:4px;color:#111827\">{$heading}</div>" : '') .
    ($sub ? "<div style=\"font-size:13px;color:#6b7280;margin-bottom:24px\">{$sub}</div>" : '<div style="height:24px"></div>') . "
    <nav>{$navItems}</nav>
    <a href=\"{$logout}\" style=\"display:block;margin-top:16px;padding:10px 16px;font-size:13px;color:#6b7280;text-decoration:none;border-top:1px solid #eef0f4;padding-top:16px\">Log out</a>
  </div>
</aside>
";
    }

    /**
     * v1.4.4 Phase D-5 (2026-05-30) — Account panel.
     * Renders orders / addresses / profile / wishlist tables.
     */
    private function renderAccountPanel(array $sec, array $brand): string
    {
        $onPrimary = $this->isLight((string) ($brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED')) ? '#111111' : '#ffffff';
        $primary   = $brand['primary_color'] ?? $brand['primary'] ?? '#7C3AED';
        $heading   = htmlspecialchars((string) ($sec['heading'] ?? 'Your activity'), ENT_QUOTES, 'UTF-8');
        $sub       = htmlspecialchars((string) ($sec['subheading'] ?? ''),            ENT_QUOTES, 'UTF-8');
        $panelType = (string) ($sec['panel_type'] ?? 'orders');
        $items     = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        $empty     = htmlspecialchars((string) ($sec['empty_message'] ?? 'Nothing here yet.'), ENT_QUOTES, 'UTF-8');
        $ctaText   = htmlspecialchars((string) ($sec['cta_text'] ?? ''),              ENT_QUOTES, 'UTF-8');
        $ctaUrl    = $this->safeUrl((string) ($sec['cta_url'] ?? ''), '');

        if (empty($items)) {
            $emptyCta = ($ctaText && $ctaUrl) ? "<a href=\"{$ctaUrl}\" style=\"display:inline-block;margin-top:16px;padding:10px 22px;background:{$primary};color:{$onPrimary};text-decoration:none;border-radius:8px;font-weight:600\">{$ctaText}</a>" : '';
            return "
<section class=\"account-panel\" style=\"padding:48px 24px;font-family:inherit\">
  <h2 style=\"font-size:24px;margin:0 0 8px;letter-spacing:-.3px\">{$heading}</h2>" .
  ($sub ? "<div style=\"color:#6b7280;margin-bottom:16px\">{$sub}</div>" : '') . "
  <div style=\"text-align:center;padding:48px 24px;background:#f9fafb;border-radius:14px\">
    <p style=\"color:#6b7280;margin:0\">{$empty}</p>{$emptyCta}
  </div>
</section>";
        }

        $rows = '';
        switch ($panelType) {
            case 'addresses':
                foreach ($items as $it) {
                    $lbl  = htmlspecialchars((string) ($it['label']   ?? 'Address'), ENT_QUOTES, 'UTF-8');
                    $line = htmlspecialchars((string) ($it['address'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $rows .= "<div style=\"padding:18px;border:1px solid #eef0f4;border-radius:12px;margin-bottom:12px;background:#fff\"><div style=\"font-weight:700;margin-bottom:6px;color:#111827\">{$lbl}</div><div style=\"font-size:14px;color:#4b5563\">{$line}</div></div>";
                }
                break;
            case 'profile':
                foreach ($items as $it) {
                    $k = htmlspecialchars((string) ($it['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $v = htmlspecialchars((string) ($it['value'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $rows .= "<div style=\"display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eef0f4\"><span style=\"font-size:14px;color:#6b7280\">{$k}</span><span style=\"font-size:14px;font-weight:600;color:#111827\">{$v}</span></div>";
                }
                $rows = "<div style=\"background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:8px 24px\">{$rows}</div>";
                break;
            case 'wishlist':
                $rows .= '<div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">';
                foreach ($items as $it) {
                    $n   = htmlspecialchars((string) ($it['name']  ?? ''), ENT_QUOTES, 'UTF-8');
                    $img = htmlspecialchars((string) ($it['image'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $p   = htmlspecialchars((string) ($it['price'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $u   = $this->safeUrl((string) ($it['url'] ?? '#'), '#');
                    $thumb = $img ? "<div style=\"width:100%;height:140px;background:url('{$img}') center/cover no-repeat\"></div>" : '';
                    $rows .= "<a href=\"{$u}\" style=\"text-decoration:none;color:inherit;background:#fff;border:1px solid #eef0f4;border-radius:12px;overflow:hidden;display:block\">{$thumb}<div style=\"padding:14px\"><div style=\"font-weight:600;color:#111827\">{$n}</div>" . ($p ? "<div style=\"font-size:14px;color:{$primary};margin-top:4px;font-weight:700\">{$p}</div>" : '') . "</div></a>";
                }
                $rows .= '</div>';
                break;
            case 'orders':
            default:
                $rows .= '<div style="background:#fff;border:1px solid #eef0f4;border-radius:14px;overflow:hidden">';
                foreach ($items as $it) {
                    $ref    = htmlspecialchars((string) ($it['ref']    ?? ''), ENT_QUOTES, 'UTF-8');
                    $date   = htmlspecialchars((string) ($it['date']   ?? ''), ENT_QUOTES, 'UTF-8');
                    $status = htmlspecialchars((string) ($it['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $total  = htmlspecialchars((string) ($it['total']  ?? ''), ENT_QUOTES, 'UTF-8');
                    $u      = $this->safeUrl((string) ($it['url'] ?? '#'), '#');
                    $rows .= "<a href=\"{$u}\" style=\"display:flex;justify-content:space-between;align-items:center;padding:18px 24px;text-decoration:none;color:inherit;border-bottom:1px solid #eef0f4\">
        <div>
          <div style=\"font-weight:700;color:#111827\">{$ref}</div>
          <div style=\"font-size:13px;color:#6b7280;margin-top:2px\">{$date}" . ($status ? " · {$status}" : '') . "</div>
        </div>
        <div style=\"font-weight:700;color:#111827\">{$total}</div>
      </a>";
                }
                $rows .= '</div>';
                break;
        }

        return "
<section class=\"account-panel\" style=\"padding:48px 24px;font-family:inherit\">
  <h2 style=\"font-size:24px;margin:0 0 8px;letter-spacing:-.3px\">{$heading}</h2>" .
  ($sub ? "<div style=\"color:#6b7280;margin-bottom:24px\">{$sub}</div>" : '<div style="height:24px"></div>') . "
  {$rows}
</section>
";
    }

    private function renderGeneric(array $sec, array $brand): string
    {
        $bg = $this->safeCss((string) ($sec['style']['bg'] ?? ''), '#ffffff');
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

    private function renderBlogList(array $website, array $brand): string
    {
        $workspaceId = $website['workspace_id'] ?? 0;
        $websiteId   = (int) ($website['id'] ?? 0);
        $articles = DB::table('articles')
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($websiteId) { if ($websiteId > 0) { $q->where('website_id', $websiteId)->orWhereNull('website_id'); } })
            ->where('is_marketing_blog', 1)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['id','title','slug','blog_category','featured_image_url','word_count','published_at']);

        $primary     = $brand['primary']      ?? '#6C5CE7';
        $fontHeading = $brand['font_heading'] ?? 'Syne';

        if ($articles->isEmpty()) {
            return "<section style=\"padding:80px 24px;background:#f8f9fc\"><div style=\"max-width:1100px;margin:0 auto;text-align:center;color:#5a5f72;font-family:'{$brand['font_body']}',sans-serif\">No articles yet — check back soon.</div></section>";
        }

        $cards = '';
        foreach ($articles as $a) {
            $img     = $a->featured_image_url ?? '';
            $title   = e($a->title);
            $cat     = e($a->blog_category ?? 'Article');
            $slug    = e($a->slug);
            $readMin = max(1, (int) round(((int) ($a->word_count ?? 0)) / 200));

            $imgHtml = $img
                ? "<img src=\"" . e($img) . "\" alt=\"{$title}\" style=\"width:100%;height:200px;object-fit:cover;display:block\">"
                : "<div style=\"width:100%;height:200px;background:linear-gradient(135deg,{$primary}33,{$primary}66)\"></div>";

            $cards .= "<a href=\"/blog/{$slug}\" style=\"text-decoration:none;color:inherit;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.06);display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s\" onmouseover=\"this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)'\" onmouseout=\"this.style.transform='';this.style.boxShadow='0 4px 12px rgba(0,0,0,.06)'\">"
                    . $imgHtml
                    . "<div style=\"padding:24px;flex:1;display:flex;flex-direction:column\">"
                    . "<span style=\"font-size:12px;text-transform:uppercase;letter-spacing:1px;color:{$primary};font-weight:600;margin-bottom:8px\">{$cat}</span>"
                    . "<h3 style=\"font-family:'{$fontHeading}',sans-serif;font-size:22px;line-height:1.3;color:#1a1a2e;margin-bottom:12px\">{$title}</h3>"
                    . "<span style=\"margin-top:auto;font-size:13px;color:#8a8f9a\">{$readMin} min read</span>"
                    . "</div></a>";
        }

        return "<section style=\"padding:80px 24px;background:#f8f9fc\"><div style=\"max-width:1200px;margin:0 auto\"><div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:32px\">{$cards}</div></div></section>";
    }

        public function getFullHtml(string $content, array $brand, string $siteName, string $pageTitle, array $seo = [], array $website = []): string
    {
        $fh = $brand['font_heading'] ?? 'Syne';
        $fh = $this->sanitizeFontName($fh, 'Syne');
        $fb = $brand['font_body'] ?? 'DM Sans';
        $fb = $this->sanitizeFontName($fb, 'DM Sans');
        $gfonts = urlencode($fh) . ':wght@400;700&family=' . urlencode($fb) . ':wght@400;500;600';

        $primary = $brand['primary'] ?? '#6C5CE7';
        $primary = $this->sanitizeCssColor($primary, '#6C5CE7');
        $fullTitle = e(($seo['meta_title'] ?? $pageTitle) . ' — ' . $siteName); // B7: escape into <title>/og:title/twitter:title
        $desc = e($seo['meta_description'] ?? '');
        $pageUrl = $seo['page_url'] ?? '';
        $siteUrl = $seo['site_url'] ?? '';
        $heroImg = $this->safeUrl((string) ($seo['hero_image'] ?? ''), ''); // B7: scheme-guard + escape for og:image/twitter:image content
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
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG); // B7: block </script> breakout
        $metaHtml .= "    <script type=\"application/ld+json\">{$schemaJson}</script>\n";

        // Wave 45 — page-level Article/FAQPage JSON-LD from aeo_enrich.
        // Multiple JSON-LD blocks per page are valid; LLM retrievers parse
        // all of them. Emit only when the page has been AEO-enriched.
        $aeoJsonld = $seo['jsonld_json'] ?? null;
        if ($aeoJsonld) {
            // Validate it's parseable JSON before injecting.
            $decoded = json_decode($aeoJsonld, true);
            if (is_array($decoded)) {
                $aeoOut = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG); // B7: block </script> breakout
                $metaHtml .= "    <script type=\"application/ld+json\">{$aeoOut}</script>\n";
            }
        }

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

        // CHATBOT888 widget — single loader is PublishedSiteMiddleware's
        // /chatbot.js?ws= (workspace-scoped, auto-mints token, injects business
        // name + brand color). getFullHtml must NOT also inject the static
        // chatbot-widget.js or token sites render TWO overlapping widgets.
        $chatbotScript = '';

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
{$chatbotScript}
</body>
</html>
HTML;
    }

    /**
     * CHATBOT888 widget injector. Reads the plaintext widget token from
     * websites.settings_json (populated by ChatbotWidgetTokenService::mint
     * for Laravel-built sites) and emits a script tag if the workspace is
     * entitled and a token has been minted. Returns '' otherwise.
     */
    private function injectChatbotWidget(array $website): string
    {
        $wsId = (int) ($website['workspace_id'] ?? 0);
        if ($wsId <= 0) return '';

        $gate = app(\App\Core\Billing\FeatureGateService::class);
        if (!$gate->canAccessChatbot($wsId)) {
            return '';
        }

        // T2.5 — respect the enabled toggle. INC-0006: resolved per WEBSITE, since one business
        // may run a chatbot on one site and deliberately not on another.
        $chatbotSettings = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $wsId, (int) ($website['id'] ?? 0));
        if (!$chatbotSettings || !$chatbotSettings->enabled) {
            return '';
        }

        $settings = $website['settings_json'] ?? '{}';
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        $token = is_array($settings) ? ($settings['chatbot_widget_token'] ?? null) : null;
        if (!$token) return '';

        $tokenSafe = htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8');
        $apiBase   = rtrim(config('app.url'), '/');

        return <<<HTML

    <!-- CHATBOT888 Widget -->
    <script>
      window.LU_CHATBOT_TOKEN = "{$tokenSafe}";
      window.LU_CHATBOT_API   = "{$apiBase}";
    </script>
    <script src="{$apiBase}/chatbot-widget.js?v=20260528-color" defer></script>
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
