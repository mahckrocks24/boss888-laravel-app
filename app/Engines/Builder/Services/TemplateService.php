<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

class TemplateService
{
    /**
     * Render a template with variable substitution.
     *
     * @param string   $industry  Template industry key (e.g. 'restaurant')
     * @param array    $variables Key-value pairs to substitute into the template
     * @param int|null $websiteId Owning website id — when set, gates + injects CHATBOT888 widget
     * @return string Rendered HTML
     * @throws \Exception If template not found
     */
    /**
     * Builder safety net markup (LUG-REVEAL-FAILSAFE, 2026-09-05).
     * Scroll-reveal content (`.reveal{opacity:0}` toggled by an IntersectionObserver)
     * must NEVER stay hidden if page JS fails — a single broken <script>, a CSP block,
     * or JS disabled would otherwise blank the whole site. Two independent layers:
     *   (a) a pure-CSS animation that fades `.reveal` in after 4s (works with ZERO JS);
     *   (b) a separate <script> block (survives a syntax error in any other block) that
     *       also adds the reveal classes so sliders/transitions settle.
     */
    public static function revealFailsafeHtml(): string
    {
        return "\n<style id=\"lug-reveal-failsafe\">@keyframes lugRevealFailsafe{to{opacity:1;transform:none}}.reveal{animation:lugRevealFailsafe .35s ease 4s forwards}</style>\n"
             . "<script>(function(){function s(){try{var n=document.querySelectorAll('.reveal'),i;for(i=0;i<n.length;i++){n[i].classList.add('visible');n[i].classList.add('in');}}catch(e){}}try{window.addEventListener('load',function(){setTimeout(s,4200);});setTimeout(s,6000);if(document.readyState!=='loading'){setTimeout(s,4200);}}catch(e){}})();</script>\n";
    }

    /**
     * MOBILE SAFETY (2026-09-07). Boss Mac Gym's "TRANSFORMATIONS" heading — one
     * uppercase word at the template's 2.6rem minimum — was wider than a phone
     * column, and body{overflow-x:hidden} (which iOS Safari ignores) let the
     * whole page scroll sideways. Every template shares these two facts, so the
     * guard is injected once here rather than edited into 31 files:
     *   - html/body overflow-x: clip — never widens the viewport, and unlike
     *     `hidden` it does not create a scroll container (sticky navs keep working);
     *   - on phones, headings may hyphenate / break inside a word before they
     *     overflow, and media can never exceed its column.
     */
    public static function mobileSafetyHtml(): string
    {
        return "\n<style id=\"lug-mobile-safe\">html,body{overflow-x:clip}@supports not (overflow:clip){html,body{overflow-x:hidden}}"
             . "@media (max-width:640px){h1,h2,h3{max-width:100%;overflow-wrap:anywhere;-webkit-hyphens:auto;hyphens:auto}"
             . "img,video,iframe,svg,table{max-width:100%}}</style>\n";
    }

    /** Insert the mobile-safety stylesheet once, before </head> (or before </body>, or append). Idempotent. */
    public static function injectMobileSafety(string $html): string
    {
        if (strpos($html, 'lug-mobile-safe') !== false) return $html;
        $ms = self::mobileSafetyHtml();
        if (stripos($html, '</head>') !== false) {
            return preg_replace('#</head>#i', $ms . '</head>', $html, 1);
        }
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $ms . '</body>', $html, 1);
        }
        return $html . $ms;
    }

    /** Insert the reveal failsafe once, just before </body> (or append if none). Idempotent. */
    public static function injectRevealFailsafe(string $html): string
    {
        if (strpos($html, 'lug-reveal-failsafe') !== false) return $html;
        $fs = self::revealFailsafeHtml();
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $fs . '</body>', $html, 1);
        }
        return $html . $fs;
    }
    public function render(string $industry, array $variables, ?int $websiteId = null): string
    {
        $industry = preg_replace('/[^a-z0-9_]/', '', strtolower($industry)); // slug-guard (path traversal)
        $path = storage_path("templates/{$industry}/template.html");
        if (!file_exists($path)) {
            throw new \Exception("Template not found: {$industry}");
        }

        $html = file_get_contents($path);

        // DESIGN VARIANTS (2026-09-10): a template directory is no longer always an industry name.
        // A variant such as restaurant_larder declares its industry as "restaurant" in the manifest,
        // and the default-asset lookup must follow THAT. Keyed on the directory name instead, every
        // variant falls back to a hero path that does not exist and renders a broken image. The
        // directory name stays the fallback, so the 31 original templates behave exactly as before.
        $assetIndustry = $industry;
        $mfPath = storage_path("templates/{$industry}/manifest.json");
        if (is_file($mfPath)) {
            $mf = json_decode((string) file_get_contents($mfPath), true);
            $declared = is_array($mf) ? (string) ($mf['industry'] ?? '') : '';
            $declared = preg_replace('/[^a-z0-9_]/', '', strtolower($declared));
            if ($declared !== '') { $assetIndustry = $declared; }
        }

        // BUG 2 FIX — resolve the industry's default hero image URL so empty
        // image variables can fall back to it instead of rendering as hollow
        // <img src=""> tags or `background-image:url()`. We try the requested
        // industry, then "default", and finally the built-in path.
        $industryDefaultImg = null;
        try {
            $row = DB::table('builder_default_assets')
                ->where('asset_type', 'hero')->where('industry', $assetIndustry)->first();
            if (!$row) {
                $row = DB::table('builder_default_assets')
                    ->where('asset_type', 'hero')->where('industry', 'default')->first();
            }
            if ($row && !empty($row->url)) $industryDefaultImg = $row->url;
        } catch (\Throwable $e) { /* table may not exist in some envs */ }
        if (!$industryDefaultImg) {
            $industryDefaultImg = '/storage/builder-heroes/' . $assetIndustry . '.jpg';
        }

        // Walk the manifest so image-typed variables that arrived empty get
        // the industry default before substitution. This way CSS like
        // `background-image:url({{hero_image}})` never lands as url().
        $manifestPath = storage_path("templates/{$industry}/manifest.json");
        $varTypes = []; // HTML-TYPED VARIABLES (2026-09-05): key => declared type, consulted at substitution
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
            $variables = $this->scrubSampleAuthors($variables, is_array($manifest['variables'] ?? null) ? $manifest['variables'] : []);
            foreach (($manifest['variables'] ?? []) as $mKey => $mSpec) {
                $type = is_array($mSpec) ? ($mSpec['type'] ?? 'text') : 'text';
                $varTypes[$mKey] = $type;
                if ($type !== 'image') continue;
                if ($mKey === 'logo_url') continue; // logo stays empty → text fallback
                $supplied = $variables[$mKey] ?? null;
                // DEFAULT TEAM AVATARS (2026-09-06): a person slot (team/member/staff/doctor/trainer/… _N_image or
                // testimonial_N_avatar) never shows a room, a hero or a gallery photo as a face. An empty or generic-pool
                // photo becomes a platform avatar; a customer upload stays. A person with no name is labelled
                // "Team Member" — an obvious placeholder to edit, never an invented person.
                $personSlot = self::personSlotFor((string) $mKey);
                if ($personSlot !== null) {
                    $cur = trim((string) $supplied);
                    if ($cur === '' || self::isGenericPoolImage($cur)) {
                        $variables[$mKey] = self::defaultAvatar($industry, (int) $personSlot['n']) ?? $cur;
                    }
                    if ($personSlot['prefix'] !== 'testimonial' && $personSlot['prefix'] !== 'review') {
                        $nameVar = $personSlot['prefix'] . '_' . $personSlot['n'] . '_name';
                        if (array_key_exists($nameVar, $variables) && trim((string) $variables[$nameVar]) === '') {
                            $variables[$nameVar] = 'Team Member';
                        }
                    }
                    continue;
                }
                if ($supplied === null || $supplied === '') {
                    $variables[$mKey] = $industryDefaultImg;
                }
            }
        }

        // Logo support: empty logo_url means text fallback shows; non-empty hides text.
        if (!isset($variables['logo_url'])) {
            $variables['logo_url'] = '';
        }
        // LOGO PLACEHOLDER (2026-09-14): the transparent 1×1 the text-logo designs carry is not a logo. Stored as one
        // (layout switch harvest), it hid the brand text on Raymundo Realty — a blank header.
        if (str_contains((string) $variables['logo_url'], 'width%3D%221%22%20height%3D%221%22')) { $variables['logo_url'] = ''; }
        // Brand-agnostic dark fallback so hero overlays never render a broken rgba().
        if (empty($variables['hero_overlay_rgb'])) { $variables['hero_overlay_rgb'] = '17,20,28'; }
        $variables['logo_text_display'] = !empty($variables['logo_url'])
            ? 'display:none'
            : 'display:block';
        // logo_img_src: real URL when set; transparent 1×1 SVG placeholder when empty.
        // Keeps the <img> hit-testable (clickable 40×40 area) without showing a broken-icon glyph.
        $variables['logo_img_src'] = !empty($variables['logo_url'])
            ? $variables['logo_url']
            : 'data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221%22%20height%3D%221%22%2F%3E';

        // BUILDER888 P1-8A (2026-08-10) — `$value ?? ''` guarded null and
        // nothing else, so the first array-valued variable from the model
        // raised a TypeError and killed the whole creation journey.
        // Normalisation policy lives in TemplateVariableNormalizer, not here:
        // this loop must not become the place where every structured field's
        // rendering is decided. P1-8B replaces DEFERRED with real markup.
        $deferred = [];

        foreach ($variables as $key => $value) {
            $n = \App\Engines\Builder\Support\TemplateVariableNormalizer::normalize($value);

            if ($n['disposition'] === \App\Engines\Builder\Support\TemplateVariableNormalizer::DEFERRED) {
                $deferred[] = $key;
            }

            // G-SEC2 (2026-08-25): escape/scheme-guard every substituted value (stored XSS defence).
            // HTML-TYPED VARIABLES (2026-09-05): curated `type:"html"` headings keep their inline markup (sanitised);
            // every other variable is escaped exactly as before.
            $safe = \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtmlTyped((string) $key, $n['value'], (string) ($varTypes[$key] ?? 'text'));
            $html = str_replace('{{' . $key . '}}', $safe, $html);
        }

        // SERVICE SELECTS (2026-09-06): booking/contact forms list the customer's real services, never the template's demo
        // specialties (13 templates shipped "Family Medicine / Cardiology / Paediatrics").
        $html = $this->fillServiceSelects($html, $variables);
        $html = $this->scrubPlaceholderNames($html);
        if (!empty($variables['logo_url'])) $html = self::applyLogoImage($html, (string) $variables['logo_url'], (string) ($variables['business_name'] ?? ''), (string) ($variables['logo'] ?? ''));

        if ($deferred !== []) {
            // Structured content was omitted rather than guessed at. Recorded
            // so P1-8B has a real inventory of which fields need a renderer.
            \Illuminate\Support\Facades\Log::warning('[Builder888] structured template variables omitted (P1-8A containment)', [
                'industry'   => $industry,
                'website_id' => $websiteId,
                'keys'       => $deferred,
            ]);
        }

        // Any unfilled variable that LOOKS like an image reference (appears
        // inside url(...), src="", or is a known *_image key) resolves to
        // the industry default rather than being wiped. Caught by a single
        // pass before the final strip below.
        $html = preg_replace_callback(
            '/\{\{([a-z_0-9]+)\}\}/',
            function ($m) use ($industryDefaultImg) {
                $name = $m[1];
                if (
                    $name === 'hero_image' || $name === 'og_image' ||
                    str_ends_with($name, '_image') || str_contains($name, 'image_')
                ) {
                    return $industryDefaultImg;
                }
                return '';
            },
            $html
        );
        // Also clear PHP-style {$variable} patterns that leaked through
        $html = preg_replace('/\{\$[a-z_0-9]+\}/', '', $html);
        
        // Remove empty list items (cuisine items 4-6 unfilled)
        $html = preg_replace('/<li[^>]*>\s*<span[^>]*>\s*<\/span>\s*<span[^>]*>\s*<\/span>\s*<\/li>/i', '', $html);
        // Remove empty expertise/service cards
        $html = preg_replace('/<div[^>]*class="expertise-card[^"]*"[^>]*style="display:none"[^>]*>.*?<\/div>\s*<\/div>/si', '', $html);
// Hide gallery items with empty image src
        $html = preg_replace(
            '/<div[^>]*class="gallery-item[^"]*"[^>]*data-src=""[^>]*>.*?<\/div>\s*<\/div>/si',
            '',
            $html
        );
        // Also hide gallery items where img src is empty
        $html = preg_replace(
            '/<div[^>]*class="gallery-item[^"]*"[^>]*>\s*<img[^>]*src=""[^>]*>.*?<\/div>/si',
            '',
            $html
        );

        
        // Remove <img> tags that end up with empty src after substitution.
        // Covers both self-closing and normal <img> forms; single- or double-quoted empty src.
        // EXCEPTION: logo images (data-field="logo_url") stay even when empty so the
        // sibling text-fallback span reads correctly via {{logo_text_display}}.
        $html = preg_replace('/<img\b(?![^>]*data-field="logo_url")[^>]*\bsrc=(""|\'\')[^>]*\/?>/i', '', $html);
        // Also handle edge case: src attribute literally missing (not just empty).
        $html = preg_replace('/<img\b(?![^>]*\bsrc=)[^>]*\/?>/i', '', $html);
        // Remove PHANTOM personnel/item cards: a repeated *-card the customer left
        // unfilled still carried the industry-default photo (non-empty src survives the
        // strip above) with an EMPTY name — a fabricated team member. See EV (phantom-cards).
        $html = $this->stripEmptyPhantomCards($html);
        // A section whose entire repeated-item grid is now empty (e.g. a business
        // with no certifications) would render as a lone heading over nothing.
        $html = $this->stripEmptySections($html);
        // Social icons whose URL the business did not set resolve to href="" —
        // a dead link that reloads the page. Drop them (social handles are never
        // fabricated), and any social container left with no links.
        $html = $this->stripEmptySocialLinks($html);
        // EV-1000: a fact the customer has not supplied (phone, email, WhatsApp, price …) renders as nothing — and the
        // label that introduced it ("Call", "Email") goes with it instead of standing over a blank line.
        $html = $this->stripEmptyFactRows($html);
        $html = $this->stripDanglingNavAnchors($html);
        // Decode HTML entities (fixes &RARR; showing as literal text)
        $html = str_replace(['&RARR;', '&rarr;', '&amp;rarr;'], '→', $html);
        $html = str_replace(['&LARR;', '&larr;', '&amp;larr;'], '←', $html);
        $html = str_replace(['&amp;amp;', '&amp;nbsp;'], ['&amp;', '&nbsp;'], $html);

        if ($websiteId) {
            $widget = $this->buildChatbotWidget($websiteId);
            if ($widget !== '' && stripos($html, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $widget . '</body>', $html, 1);
            }
        }

        // BUILDER888 SEO: inject LocalBusiness JSON-LD into the static export head so
        // freshly-generated sites carry structured data (the dynamic renderer already
        // emits it; the template export did not). Guarded: only when absent + a name
        // exists. JSON_HEX_TAG blocks any </script> breakout from a variable value.
        if (stripos($html, 'application/ld+json') === false && stripos($html, '</head>') !== false) {
            $bizName = trim((string) ($variables['business_name'] ?? $variables['site_name'] ?? ''));
            if ($bizName !== '') {
                $schema = array_filter([
                    '@context'    => 'https://schema.org',
                    '@type'       => 'LocalBusiness',
                    'name'        => $bizName,
                    'description' => trim((string) ($variables['meta_description'] ?? $variables['hero_subheading'] ?? '')),
                    'image'       => trim((string) ($variables['hero_image'] ?? $variables['og_image'] ?? '')),
                ], fn($v) => $v !== '' && $v !== null);
                $json = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json !== false) {
                    $html = str_ireplace('</head>', '  <script type="application/ld+json">' . $json . '</script>' . "\n</head>", $html);
                }
            }
        }

        // BUILDER888 SEO/polish: add twitter card, theme-color, and a branded
        // contrast-aware favicon (business initial on the brand color) — none of which
        // the templates carried. Guarded: skip anything already present. Relative
        // twitter:image is absolutized at serve time (absolutizeSocialMeta).
        if (stripos($html, '</head>') !== false) {
            $primary = trim((string) ($variables['primary_color'] ?? ''));
            if ($primary === '') { $primary = '#1F2937'; }
            if ($primary[0] !== '#') { $primary = '#' . $primary; }
            // B7 defense-in-depth: theme-color/favicon fill must be a valid hex color,
            // never a brand-kit value carrying CSS/HTML metacharacters.
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary)) { $primary = '#1F2937'; }
            $bn = trim((string) ($variables['business_name'] ?? $variables['site_name'] ?? 'Website'));
            $ogimg = trim((string) ($variables['og_image'] ?? $variables['hero_image'] ?? ''));
            $add = '';
            if (stripos($html, 'twitter:card') === false) {
                $add .= '<meta name="twitter:card" content="summary_large_image">';
                if ($bn !== '') { $add .= '<meta name="twitter:title" content="' . htmlspecialchars($bn, ENT_QUOTES) . '">'; }
                if ($ogimg !== '') { $add .= '<meta name="twitter:image" content="' . htmlspecialchars($ogimg, ENT_QUOTES) . '">'; }
            }
            if (stripos($html, 'theme-color') === false) {
                $add .= '<meta name="theme-color" content="' . htmlspecialchars($primary, ENT_QUOTES) . '">';
            }
            if (stripos($html, 'rel="icon"') === false && stripos($html, "rel='icon'") === false) {
                $initial = strtoupper(mb_substr($bn !== '' ? $bn : 'W', 0, 1));
                $letter = $this->readableOn($primary);
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="20" fill="' . $primary . '"/><text x="50" y="55" font-family="Arial,Helvetica,sans-serif" font-size="60" font-weight="bold" fill="' . $letter . '" text-anchor="middle" dominant-baseline="central">' . htmlspecialchars($initial, ENT_QUOTES) . '</text></svg>';
                $add .= '<link rel="icon" href="data:image/svg+xml;base64,' . base64_encode($svg) . '">';
            }
            if ($add !== '') { $html = str_ireplace('</head>', $add . "\n</head>", $html); }
        }

        $html = self::injectRevealFailsafe($html);
        $html = self::injectMobileSafety($html);

        // DESIGN-STYLE LAYER (2026-09-05): apply the customer's chosen style/fonts (Arthur used
        // to collect `style` and silently discard it). No direction => no layer => template design stands.
        $dsLayer = \App\Engines\Builder\Support\DesignStyle::layer(
            $variables['design_style'] ?? null, $variables['font_display'] ?? null, $variables['font_body'] ?? null,
            ['accent' => $variables['accent_color'] ?? null, 'secondary' => $variables['secondary_color'] ?? null, 'primary' => $variables['primary_color'] ?? null]
        );
        if ($dsLayer !== '' && stripos($html, 'lug-design-style') === false) {
            $html = (stripos($html, '</head>') !== false) ? str_ireplace('</head>', $dsLayer . '</head>', $html) : $dsLayer . $html;
        }
        // DEC-0046 gap closure (2026-09-14): a customer's literal gradients survive a rebuild. They live in
        // template_variables.design_extras and used to vanish the moment the site was re-rendered.
        $extras = is_array($variables['design_extras'] ?? null) ? array_filter($variables['design_extras'], 'is_string') : [];
        if ($extras !== [] && stripos($html, 'lug-design-extras') === false) {
            $block = '<style id="lug-design-extras" data-owner="arthur">' . implode("\n", $extras) . '</style>';
            $html = (stripos($html, '</head>') !== false) ? str_ireplace('</head>', $block . "\n</head>", $html) : $html . $block;
        }
        // CATALOGUE888 (DEC-0049, 2026-09-14): a rebuilt home hides the slots its catalogues do not fill.
        if ($websiteId) { try { $html = app(CatalogueService::class)->decorateRendered($websiteId, $html); } catch (\Throwable $e) {} }

        return $html;
    }

    /**
     * Build the CHATBOT888 widget snippet for a website, or '' when not entitled / not configured.
     * Mirrors BuilderRenderer::injectChatbotWidget so both render paths gate identically.
     */
    /**
     * Remove phantom personnel/item cards. A repeated card slot the customer did not
     * fill keeps the industry-DEFAULT image (its *_image token defaults to a non-empty
     * URL, so the empty-src strip never fires) while every text field resolves empty.
     * Rendering it advertises a fabricated team member with a stock photo and blank name
     * (an enterprise-quality + a11y defect). Detect an empty primary name field
     * (data-field="*_name" with no content) whose enclosing *-card wrapper contains an
     * <img>, and cut that wrapper with a balanced <div> scan (regex cannot match the
     * nested card structure reliably). Real, filled cards are untouched.
     */
    /**
     * Remove dead in-page nav links. A section stripped as empty/fabricated
     * (e.g. certifications) can leave its <a href="#id"> in the header/footer
     * nav — a dead anchor that jumps nowhere. Collect every id present, then drop
     * any nav anchor (with its <li> wrapper, if any) whose #target is absent.
     * A bare href="#" and real/external hrefs are never touched.
     */
    public function stripDanglingNavAnchors(string $html): string
    {
        $ids = [];
        if (preg_match_all('/\bid="([^"]+)"/i', $html, $idm)) {
            foreach ($idm[1] as $id) { $ids[strtolower($id)] = true; }
        }
        $out = preg_replace_callback(
            '~(?:<li\b[^>]*>\s*)?<a\b[^>]*href="#([^"]+)"[^>]*>.*?</a>(?:\s*</li>)?\s*~is',
            function ($mm) use ($ids) {
                $target = strtolower($mm[1]);
                if ($target === '' || isset($ids[$target])) { return $mm[0]; }
                return ''; // dangling — the target section no longer exists
            },
            $html
        );
        // Drop any now-empty <li></li> a removed nav anchor left behind.
        if (is_string($out)) {
            $out = preg_replace('~<li\b[^>]*>\s*</li>~i', '', $out) ?? $out;
        }
        return is_string($out) ? $out : $html;
    }

    /**
     * EV-1000 (2026-09-12). An element whose data-field is a business FACT (contact_phone, contact_email, *_price …)
     * and whose content is empty is removed. When its nearest enclosing <div> holds nothing else but a short label
     * ("Call", "Email", a <dt>, a .contact-item-label span, an icon), that whole row is removed, so the label does not
     * stand over a blank line. A row with other content keeps its content and loses only the empty element.
     */
    private function stripEmptyFactRows(string $html): string
    {
        $re = '/<(a|span|div|dd|p|b|strong|em)\b[^>]*\bdata-field="(?![^"]*_label")[a-z0-9_]*(?:price|fee|cost|rate|phone|whatsapp|fax|website|email)(?:_[a-z0-9_]*)?"[^>]*>\s*<\/\1>/i';
        if (!preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE)) { return $html; }
        for ($i = count($m[0]) - 1; $i >= 0; $i--) {
            $elStart = $m[0][$i][1]; $elLen = strlen($m[0][$i][0]);
            $removed = false;
            $open = strrpos(substr($html, 0, $elStart), '<div');
            if ($open !== false) {
                $close = $this->matchDivClose($html, $open);
                if ($close !== null && $close > $elStart + $elLen) {
                    $row   = substr($html, $open, $close - $open);
                    $rest  = str_replace($m[0][$i][0], '', $row);
                    $text  = trim(preg_replace('/\s+/u', ' ', (string) strip_tags($rest)));
                    $other = preg_match('/data-field=|<(?:img|input|textarea|select|button|a)\b/i', $rest);
                    if (!$other && mb_strlen($text) <= 40) {
                        $html = substr($html, 0, $open) . substr($html, $close);
                        $removed = true;
                    }
                }
            }
            if (!$removed) { $html = substr($html, 0, $elStart) . substr($html, $elStart + $elLen); }
        }
        return $html;
    }

    private function stripEmptyPhantomCards(string $html): string
    {
        if (!preg_match_all('/data-field="([a-z0-9_]+)_name"[^>]*>\s*<\/(?:div|h[1-6]|span|p|strong)>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        for ($i = count($m[0]) - 1; $i >= 0; $i--) {
            $namePos = $m[0][$i][1];
            $open = $this->findEnclosingCardOpen($html, $namePos);
            if ($open === null) { continue; }
            $close = $this->matchDivClose($html, $open);
            if ($close === null || $close <= $namePos) { continue; }
            $card = substr($html, $open, $close - $open);
            // Remove only when the card has NO real content: strip tags + non-alnum
            // decorations (a lone â check, punctuation). The industry-default <img> and its
            // empty alt contribute no text, so a phantom photo card collapses to '' and is
            // cut; a card with a real quote/desc but a blank name keeps its content and stays.
            // A PERSONNEL card (doctor/team/staff/... _N) with an empty NAME is a
            // fake person regardless of any leftover default specialty/bio text — cut
            // it. For a non-personnel card (e.g. a testimonial with a real quote but a
            // blank author) keep the old rule: cut only when it has no text at all.
            $prefix = strtolower((string) ($m[1][$i][0] ?? ''));
            $isPersonnel = (bool) preg_match('/^(doctor|dentist|physician|surgeon|therapist|trainer|instructor|coach|staff|team|member|attorney|lawyer|agent|broker|realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist)(_\d+)?$/', $prefix);
            if (! $isPersonnel) {
                $text = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) strip_tags($card));
                if ($text !== '' && $text !== null) { continue; }
            }
            $html = substr($html, 0, $open) . substr($html, $close);
        }
        return $html;
    }

    /**
     * Remove a section that has become empty after phantom-card removal. A section is
     * empty when its inner HTML contains an empty *grid/*list container (all repeated
     * items were stripped) AND no <img> (so a section with other imagery is never cut).
     * Sections in these templates are flat (never nested), so a non-greedy match to the
     * first </section> is exact. Works for id'd sections (drop the matching nav anchor
     * link too) and id-less sections (nothing can anchor to them, so just cut).
     */
    private function stripEmptySections(string $html): string
    {
        if (!preg_match_all('#<section\b[^>]*>(.*?)</section>#is', $html, $secs, PREG_SET_ORDER)) {
            return $html;
        }
        foreach ($secs as $sec) {
            $full  = $sec[0];
            $inner = $sec[1];
            if (stripos($inner, '<img') !== false) { continue; }
            if (!preg_match('#<div class="[^"]*(?:grid|list)[^"]*">\s*</div>#i', $inner)) { continue; }
            $pos = strpos($html, $full);
            if ($pos === false) { continue; }
            $html = substr_replace($html, '', $pos, strlen($full));
            if (preg_match('/\bid="([^"]+)"/i', $full, $idm)) {
                $q = preg_quote($idm[1], '~');
                $html = preg_replace('~<a\b[^>]*href="#' . $q . '"[^>]*>.*?</a>\s*~is', '', $html) ?? $html;
            }
        }
        return $html;
    }

    /**
     * Remove dead social-icon links. A social anchor whose href resolved empty
     * (<a href="" ... data-field="social_*">) is a dead link that reloads the page;
     * social handles are customer data that is never fabricated, so an unset network
     * simply should not render. After removing the empty ones, a social container
     * (class contains "social") left with no <a> children is removed too so no empty
     * icon row remains.
     */
    private function stripEmptySocialLinks(string $html): string
    {
        // Drop <a ... href="" ... data-field="social_*" ...>...</a> (icon may nest svg).
        $html = preg_replace(
            '~<a\b(?=[^>]*\bhref="#?")(?=[^>]*\bdata-field="social_[a-z0-9_]+")[^>]*>.*?</a>\s*~is',
            '',
            $html
        ) ?? $html;
        // Remove a now-empty social container. The estate uses exactly two social-link
        // container classes (footer-social / footer-socials) and no other "social" class,
        // so this cannot hit a social-proof/testimonial block. Flat (no nested div) in the
        // templates; a couple of passes cover any minor nesting.
        for ($i = 0; $i < 3; $i++) {
            $new = preg_replace_callback(
                '~<(div|ul|nav)\b[^>]*class="[^"]*footer-socials?[^"]*"[^>]*>(.*?)</\1>\s*~is',
                function ($m) {
                    return stripos($m[2], '<a') === false ? '' : $m[0];
                },
                $html
            );
            if ($new === null || $new === $html) { break; }
            $html = $new;
        }
        return $html;
    }

    private function findEnclosingCardOpen(string $html, int $pos): ?int
    {
        if (!preg_match_all('/<div\b[^>]*class="[^"]*(?:card|member|team|item)[^"]*"[^>]*>/i', $html, $mm, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $best = null;
        foreach ($mm[0] as $mo) {
            $off = $mo[1];
            if ($off >= $pos) { break; }
            $best = $off;
        }
        return $best;
    }

    private function matchDivClose(string $html, int $openPos): ?int
    {
        $len = strlen($html);
        $i = strpos($html, '>', $openPos);
        if ($i === false) { return null; }
        $i++;
        $depth = 1;
        while ($i < $len && $depth > 0) {
            $lt = strpos($html, '<div', $i);
            $gt = strpos($html, '</div>', $i);
            if ($gt === false) { return null; }
            if ($lt !== false && $lt < $gt) { $depth++; $i = $lt + 4; }
            else { $depth--; $i = $gt + 6; }
        }
        return $depth === 0 ? $i : null;
    }

    private function buildChatbotWidget(int $websiteId): string
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website) return '';
        $wsId = (int) ($website->workspace_id ?? 0);
        if ($wsId <= 0) return '';

        try {
            $gate = app(\App\Core\Billing\FeatureGateService::class);
            if (!$gate->canAccessChatbot($wsId)) return '';
        } catch (\Throwable $e) { return ''; }

        // INC-0006: this website's own chatbot row, falling back to the business-wide default.
        $cs = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $wsId, $websiteId);
        if (!$cs || !$cs->enabled) return '';

        $settings = $website->settings_json ?? '{}';
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];
        $token = is_array($settings) ? ($settings['chatbot_widget_token'] ?? null) : null;
        if (!$token) return '';

        $tokenSafe = htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8');
        $apiBase = rtrim((string) config('app.url'), '/');
        return "<!-- CHATBOT888 Widget -->\n"
            . "<script>window.LU_CHATBOT_TOKEN = \"{$tokenSafe}\"; window.LU_CHATBOT_API = \"{$apiBase}\";</script>\n"
            . "<script src=\"{$apiBase}/chatbot-widget.js?v=20260528-color\" defer></script>\n";
    }

    /**
     * Get the manifest for a specific industry template.
     *
     * @param string $industry
     * @return array|null
     */
    /** Contrast-aware text color (#111 or #fff) for text/icon on a brand color. */
    private function readableOn(string $hex): string
    {
        $h = ltrim(strtolower(trim($hex)), '#');
        if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
        if (strlen($h) !== 6 || ! ctype_xdigit($h)) { return '#ffffff'; }
        $r = hexdec(substr($h,0,2))/255; $g = hexdec(substr($h,2,2))/255; $b = hexdec(substr($h,4,2))/255;
        $f = function ($c) { return $c <= 0.03928 ? $c/12.92 : pow(($c+0.055)/1.055, 2.4); };
        $L = 0.2126*$f($r) + 0.7152*$f($g) + 0.0722*$f($b);
        return (($L+0.05)/0.05) >= (1.05/($L+0.05)) ? '#111111' : '#ffffff';
    }

    /** @return array{prefix:string,n:int}|null  e.g. doctor_2_image → ['doctor',2]; testimonial_1_avatar → ['testimonial',1] */
    public static function personSlotFor(string $key): ?array
    {
        if (preg_match('/^(team|member|staff|doctor|trainer|agent|broker|instructor|tutor|barber|stylist|chef|coach|host|planner|therapist|dentist|consultant|designer|advisor|teacher|founder|partner|expert|specialist|testimonial|review)_(\d+)_(image|photo|avatar|img)$/i', strtolower($key), $m)) {
            return ['prefix' => $m[1], 'n' => (int) $m[2]];
        }
        return null;
    }

    /** A generic library/pool photo (never a customer upload) that must not stand in for a person. */
    public static function isGenericPoolImage(string $url): bool
    {
        return (bool) preg_match('#/storage/(template-images|builder-heroes|sites/heroes|ai-generated|ai-images|img-cache)/|gallery_\d+_image#i', $url);
    }

    /** Deterministic platform avatar for a slot: same site → same faces on every render; different industries rotate. */
    public static function defaultAvatar(string $industry, int $slot): ?string
    {
        static $files = null;
        if ($files === null) {
            // 2026-09-14 (Owner: 'image placeholders for team that is like human not cartoon'): photorealistic headshots
            // (photo_*.jpg) are the default; the illustrated set is the fallback when none exist.
            $files = array_values(array_map('basename', glob(storage_path('app/public/builder-avatars/photo_*.jpg')) ?: []));
            if ($files === []) { $files = array_values(array_map('basename', glob(storage_path('app/public/builder-avatars/avatar_*.jpg')) ?: [])); }
            sort($files);
        }
        if ($files === []) return null;
        $offset = crc32($industry) % count($files);
        return '/storage/builder-avatars/' . $files[($offset + $slot - 1) % count($files)];
    }

    /**
     * The canonical industry for a template directory. A variant such as restaurant_larder declares
     * `industry: restaurant`; the 31 originals are their own industry. Anything unknown comes back as given.
     */
    /** Preview parity: the same mobile-safety guard deploy() applies, callable from the preview route. */
    public function injectMobileSafetyPublic(string $html): string
    {
        return self::injectMobileSafety($html);
    }

    public function industryOf(string $slugOrIndustry): string
    {
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($slugOrIndustry)));
        if ($slug === '') { return ''; }
        $m = $this->getManifest($slug);
        $ind = is_array($m) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['industry'] ?? ''))) : '';
        return $ind !== '' ? $ind : $slug;
    }

    public function getManifest(string $industry): ?array
    {
        $industry = preg_replace('/[^a-z0-9_]/', '', strtolower($industry)); // slug-guard (path traversal)
        $path = storage_path("templates/{$industry}/manifest.json");
        if (!file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true);
    }

    /**
     * List all available templates.
     *
     * @return array
     */
    /**
     * List templates. By default filters out manifests with "is_active": false
     * — so the user-facing gallery only sees active templates. Admin callers
     * pass $includeInactive=true to see every template regardless of flag.
     */
    public function listTemplates(bool $includeInactive = false): array
    {
        // Load thumbnails keyed by industry (builder_default_assets: hero asset_type).
        $thumbs = [];
        try {
            $rows = \Illuminate\Support\Facades\DB::table('builder_default_assets')
                ->where('asset_type', 'hero')->get(['industry', 'url']);
            foreach ($rows as $r) $thumbs[$r->industry] = $r->url;
        } catch (\Throwable $_e) { /* table may not exist in some envs */ }

        $templates = [];
        foreach (glob(storage_path('templates/*'), GLOB_ONLYDIR) as $dir) {
            $mp = $dir . '/manifest.json';
            if (!file_exists($mp)) continue;
            $manifest = json_decode(file_get_contents($mp), true);
            if (!$manifest) continue;

            // is_active defaults to true when the key is absent — that's how
            // every existing manifest stays live after this field is added.
            $isActive = array_key_exists('is_active', $manifest)
                ? (bool) $manifest['is_active']
                : true;
            if (!$includeInactive && !$isActive) continue;

            $industry = $manifest['industry'] ?? basename($dir);
            $htmlPath = $dir . '/template.html';
            $elementCount = 0;
            foreach (($manifest['blocks'] ?? []) as $b) {
                $elementCount += count($b['elements'] ?? []);
            }

            $templates[] = [
                'id'            => $manifest['id'] ?? basename($dir),
                'name'          => $manifest['name'] ?? basename($dir),
                'industry'      => $industry,
                'description'   => $manifest['description'] ?? '',
                'version'       => $manifest['version'] ?? '1.0.0',
                'variation'     => $manifest['variation'] ?? 'luxury',
                'category'      => $manifest['category'] ?? '',
                'is_active'     => $isActive,
                'thumbnail'     => $thumbs[$industry] ?? ($thumbs['default'] ?? ''),
                'preview_url'   => '/templates/' . $industry . '/preview',
                'block_count'   => count($manifest['blocks'] ?? []),
                'field_count'   => count($manifest['variables'] ?? []),
                'element_count' => $elementCount,
                'html_bytes'    => is_file($htmlPath) ? filesize($htmlPath) : 0,
                // Keep old keys for backwards-compat with existing callers.
                'blocks'        => count($manifest['blocks'] ?? []),
                'variables'     => count($manifest['variables'] ?? []),
            ];
        }

        return $templates;
    }

    /**
     * RISK-0101 — honest blog section. If the website's workspace has NO published
     * articles, strip the baked placeholder blog cards (whose /blog/{slug} hrefs are
     * template defaults that 302 to /blog) and leave a single honest 'coming soon'
     * line inside the grid, so a generated site never advertises articles that do not
     * exist. When real articles exist the html is returned unchanged (the serve-time
     * injector owns that case). Fail-open: any error returns the html untouched.
     */
    private function honestBlogSection(int $websiteId, string $html): string
    {
        try {
            if (stripos($html, 'blog-card') === false && stripos($html, 'blog-grid') === false) {
                return $html;
            }
            // Capture the first styled placeholder card as a reusable template — the
            // serve-time injector populates it from live articles. There is no
            // .blog-card CSS rule (styling is inline), so a real styled card must be kept.
            if (! preg_match('#<a\s[^>]*class="[^"]*blog-card[^"]*"[^>]*>.*?</a>#is', $html, $m)) {
                return $html;
            }
            $firstCard = $m[0];
            // Strip ALL placeholder cards (fake titles + dead /blog/{slug} hrefs).
            $stripped = preg_replace('#<a\s[^>]*class="[^"]*blog-card[^"]*"[^>]*>.*?</a>#is', '', $html, -1, $n);
            if (! $n || $stripped === null) return $html;
            // Honest empty-state line (shown when there are 0 real articles) + the
            // hidden card template (a <template> does not render, so it is invisible).
            $msg = '<p class="blog-empty" data-field="blog_empty" style="color:var(--carbon-soft,#475569);'
                . 'font-size:1.05rem;line-height:1.6;">New articles are on the way — check back soon.</p>';
            $msg .= '<template class="lu-blog-card-tpl">' . $firstCard . '</template>';
            $out = preg_replace('#(<div\s[^>]*class="[^"]*blog-grid[^"]*"[^>]*>)#i', '$1' . $msg, $stripped, 1, $g);
            return ($g && $out !== null) ? $out : $stripped;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Deploy rendered HTML to the sites directory.
     *
     * @param int    $websiteId
     * @param string $html
     * @return string Path to the deployed file
     */
    // ── ARTHUR DELEGATION (2026-09-06): added pages and sections live INSIDE the template ─────────────────────
    /** The pieces of a site's static home export that every page shares: head, nav, footer, lang. */
    public function siteChrome(int $websiteId): ?array
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (!is_file($path)) return null;
        $home = (string) file_get_contents($path);
        if (!preg_match('/<head\b[^>]*>.*?<\/head>/is', $home, $hm)) return null;
        $nav = '';
        if (preg_match('/<nav\b[^>]*(?:id="main-nav"|data-block="nav")[^>]*>.*?<\/nav>/is', $home, $nm)) $nav = $nm[0];
        elseif (preg_match('/<header\b[^>]*>.*?<\/header>/is', $home, $nm2)) $nav = $nm2[0];
        elseif (preg_match('/<nav\b[^>]*>.*?<\/nav>/is', $home, $nm3)) $nav = $nm3[0];
        $footer = preg_match('/<footer\b[^>]*>.*?<\/footer>/is', $home, $fm) ? $fm[0] : '';
        $lang = preg_match('/<html\b[^>]*lang="([^"]+)"/i', $home, $lm) ? $lm[1] : 'en';
        $siteName = preg_match('/<title>\s*([^<|\x{2014}-]+)/iu', $hm[0], $tn) ? trim($tn[1]) : '';
        return ['home' => $home, 'head' => $hm[0], 'nav' => $nav, 'footer' => $footer, 'lang' => $lang, 'site_name' => $siteName];
    }

    /**
     * Write sites/{id}/{slug}/index.html: the home's head/nav/footer (so fonts, palette, design layer, colour
     * treatment and mobile nav are identical) around $bodyHtml. In-page anchors become ../#anchor, blog → ../blog/.
     */
    public function deployPage(int $websiteId, string $slug, string $bodyHtml, string $title): ?string
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        if ($slug === '' || in_array($slug, ['index', 'blog', 'home'], true)) return null;
        $c = $this->siteChrome($websiteId);
        if (!$c) return null;
        $head = preg_replace('/<title>.*?<\/title>/is', '<title>' . e($title) . ($c['site_name'] !== '' ? ' — ' . e($c['site_name']) : '') . '</title>', $c['head'], 1) ?? $c['head'];
        $head = preg_replace('/<link\b[^>]*rel="canonical"[^>]*>/i', '', $head) ?? $head;
        $toHome = function (string $frag): string {
            $frag = preg_replace('/href="#"/i', 'href="../"', $frag) ?? $frag;
            $frag = preg_replace('/href="#([a-z0-9\-_]+)"/i', 'href="../#$1"', $frag) ?? $frag;
            $frag = preg_replace('#href="(?:/blog|blog)/?"#i', 'href="../blog/"', $frag) ?? $frag;
            // links to sibling pages written as "x/" from the home become "../x/"
            $frag = preg_replace('#href="(?!\.\./|https?:|/|\#|mailto:|tel:)([a-z0-9\-]+)/"#i', 'href="../$1/"', $frag) ?? $frag;
            return $frag;
        };
        // A section CTA that targets an anchor this page does not have (#contact, #booking…) goes home instead.
        $ids = [];
        if (preg_match_all('/\bid="([^"]+)"/i', $bodyHtml, $im)) { foreach ($im[1] as $x) $ids[strtolower($x)] = true; }
        $bodyHtml = preg_replace_callback('/href="#([a-z0-9\-_]+)"/i', fn($m) => isset($ids[strtolower($m[1])]) ? $m[0] : 'href="../#' . $m[1] . '"', $bodyHtml) ?? $bodyHtml;
        // Every page has one H1: a visible title strip when the stack brought none (cart / checkout / account).
        if (!preg_match('/<h1\b/i', $bodyHtml)) {
            $bodyHtml = '<section class="lu-page-title" style="padding:56px 24px 8px"><div style="max-width:1100px;margin:0 auto"><h1 style="margin:0;font-size:clamp(28px,4vw,44px)">' . e($title) . '</h1></div></section>' . $bodyHtml;
        }
        // A fixed/sticky template nav must not cover the page's first section (the home hero carries its own offset).
        $offset = '<script>(function(){try{var n=document.querySelector("nav[data-block=nav],#main-nav,header nav,nav");var m=document.querySelector("main[data-lu-page]");if(!n||!m)return;var p=getComputedStyle(n).position;if(p==="fixed"||p==="absolute"){m.style.paddingTop=(n.offsetHeight+8)+"px";}}catch(e){}})();</script>';
        $doc = '<!doctype html><html lang="' . e($c['lang']) . '">' . $head . '<body>'
             . $toHome($c['nav']) . '<main data-lu-page="' . e($slug) . '">' . $bodyHtml . '</main>' . $toHome($c['footer']) . $offset . '</body></html>';
        $doc = \App\Engines\Builder\Support\ResponsiveNav::inject($doc);
        $doc = \App\Engines\Builder\Support\SiteScripts::inject($doc, $websiteId);
        $doc = $this->applyElementOps($websiteId, $doc);   // ELEMENT888: nav/footer element moves live on every page
        $dir = storage_path("app/public/sites/{$websiteId}/{$slug}");
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/index.html';
        file_put_contents($path, $doc);
        return $path;
    }

    /**
     * Put a link to a page into every nav of the site's exports (home, blog, other pages). Idempotent.
     * Relative depth is handled per file: home → "slug/", sub-pages → "../slug/".
     */
    public function addNavLink(int $websiteId, string $slug, string $label): int
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        $root = storage_path("app/public/sites/{$websiteId}");
        if ($slug === '' || !is_dir($root)) return 0;
        $files = array_merge([$root . '/index.html'], glob($root . '/*/index.html') ?: []);
        $n = 0;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $html = (string) file_get_contents($file);
            if (preg_match('/data-page="' . preg_quote($slug, '/') . '"/', $html)) continue; // already linked
            $depth = dirname($file) === $root ? '' : '../';
            $a = '<a href="' . $depth . e($slug) . '/" class="nav-link lu-page-link" data-page="' . e($slug) . '">' . e($label) . '</a>';
            $new = preg_replace_callback('/(<(?:nav|header)\b[^>]*>.*?<\/(?:nav|header)>)/is', function ($m) use ($a, $label, $slug, $depth) {
                $navHtml = $m[1];
                // A link with the page's wording already exists (template "Contact" → home #contact): point it at the
                // dedicated page instead of adding a twin. The customer added the page; the menu must reach it.
                $repointed = preg_replace_callback('/<a\b([^>]*)>(\s*' . preg_quote($label, '/') . '\s*)<\/a>/iu', function ($am) use ($depth, $slug) {
                    $attrs = preg_replace('/\shref="[^"]*"/i', ' href="' . $depth . e($slug) . '/"', $am[1], 1) ?? $am[1];
                    if (!str_contains($attrs, 'data-page=')) $attrs .= ' data-page="' . e($slug) . '"';
                    return '<a' . $attrs . '>' . $am[2] . '</a>';
                }, $navHtml, 1, $rc);
                if ($rc > 0 && is_string($repointed)) return $repointed;
                // NAV CAPACITY: 6+ links already → added pages live in a "More" menu (site CSS, no native select)
                $linkCount = preg_match_all('/<a\b[^>]*class="[^"]*\bnav-link\b[^"]*"[^>]*>/i', $navHtml, $lm) + preg_match_all('/<li\b[^>]*>\s*<a\b/i', $navHtml, $ll);
                if ($linkCount >= 6) {
                    $item = '<a href="' . $depth . e($slug) . '/" class="lu-page-link" data-page="' . e($slug) . '">' . e($label) . '</a>';
                    if (preg_match('/<div class="lu-more">.*?<div class="lu-more-menu">/is', $navHtml, $mm, PREG_OFFSET_CAPTURE)) {
                        $pos = $mm[0][1] + strlen($mm[0][0]);
                        return substr($navHtml, 0, $pos) . $item . substr($navHtml, $pos);
                    }
                    $more = '<div class="lu-more"><button type="button" class="lu-more-btn" aria-haspopup="true" aria-expanded="false" onclick="this.parentNode.classList.toggle(\'open\');this.setAttribute(\'aria-expanded\',this.parentNode.classList.contains(\'open\'))">More <span aria-hidden="true">&#9662;</span></button><div class="lu-more-menu">' . $item . '</div></div>';
                    $isList = (bool) preg_match('/<ul\b[^>]*class="[^"]*nav-links[^"]*"/i', $navHtml);
                    $moreItem = $isList ? '<li style="list-style:none">' . $more . '</li>' : $more;
                    if (preg_match('/<a\b[^>]*class="[^"]*\bnav-cta\b[^"]*"[^>]*>/i', $navHtml, $cta, PREG_OFFSET_CAPTURE)) {
                        $pos = $cta[0][1]; return substr($navHtml, 0, $pos) . $moreItem . substr($navHtml, $pos);
                    }
                    if (preg_match('/<\/(?:ul|div)>/i', $navHtml, $end, PREG_OFFSET_CAPTURE, (int) strpos($navHtml, 'nav-links'))) {
                        $pos = $end[0][1]; return substr($navHtml, 0, $pos) . $moreItem . substr($navHtml, $pos);
                    }
                    return $navHtml;
                }
                // <ul class="nav-links"> → wrap in <li>; <div class="nav-links"> → bare <a>. Insert before the CTA if present.
                $isList = (bool) preg_match('/<ul\b[^>]*class="[^"]*nav-links[^"]*"/i', $navHtml);
                $item = $isList ? '<li style="list-style:none">' . $a . '</li>' : $a;
                if (preg_match('/<a\b[^>]*class="[^"]*\bnav-cta\b[^"]*"[^>]*>/i', $navHtml, $cta, PREG_OFFSET_CAPTURE)) {
                    $pos = $cta[0][1];
                    // if the CTA sits inside the list container, insert before it; else append at container end
                    return substr($navHtml, 0, $pos) . $item . substr($navHtml, $pos);
                }
                if (preg_match('/<\/(?:ul|div)>/i', $navHtml, $end, PREG_OFFSET_CAPTURE, (int) strpos($navHtml, 'nav-links'))) {
                    $pos = $end[0][1];
                    return substr($navHtml, 0, $pos) . $item . substr($navHtml, $pos);
                }
                return $navHtml;
            }, $html, 1);
            if (is_string($new) && $new !== $html) {
                if (str_contains($new, 'class="lu-more"') && !str_contains($new, 'id="lu-more-css"')) {
                    $css = '<style id="lu-more-css">.lu-more{position:relative;display:inline-block}.lu-more-btn{font:inherit;background:none;border:0;cursor:pointer;color:inherit;padding:0;display:inline-flex;align-items:center;gap:4px}.lu-more-menu{display:none;position:absolute;top:calc(100% + 10px);left:0;min-width:200px;background:#fff;color:#1a1f26;border:1px solid rgba(15,23,42,.12);border-radius:12px;box-shadow:0 14px 34px rgba(0,0,0,.14);padding:8px;z-index:9998;flex-direction:column}.lu-more.open .lu-more-menu,.lu-more:hover .lu-more-menu{display:flex}.lu-more-menu a{display:block;padding:10px 12px;border-radius:8px;color:inherit;text-decoration:none;white-space:nowrap}.lu-more-menu a:hover{background:rgba(15,23,42,.06)}@media(max-width:900px){.lu-more{display:block}.lu-more-menu{position:static;display:flex;box-shadow:none;border:0;padding:0 0 0 12px;background:transparent;color:inherit}.lu-more-btn{display:none}}</style>';
                    $new = str_ireplace('</head>', $css . '</head>', $new);
                }
                file_put_contents($file, $new); $n++;
            }
        }
        return $n;
    }

    /** Remember an Arthur-spliced section so deploy() can restore it after any re-render. */
    public function rememberSpliced(int $websiteId, string $type, string $sectionHtml, string $anchorBlock, string $where): void
    {
        $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['settings_json']);
        if (!$w) return;
        $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
        $list = is_array($s['arthur_sections'] ?? null) ? $s['arthur_sections'] : [];
        $list = array_values(array_filter($list, fn($x) => ($x['type'] ?? '') !== $type));
        $list[] = ['type' => $type, 'html' => $sectionHtml, 'anchor' => $anchorBlock, 'where' => $where, 'added_at' => now()->toDateTimeString()];
        $s['arthur_sections'] = $list;
        \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($s)]);
    }

    /** LAYOUT SWITCHER (2026-09-14): everything deploy() does to the markup, without writing — for a faithful preview. */
    public function finishForPreview(int $websiteId, string $html): string
    {
        $html = $this->honestBlogSection($websiteId, $html);
        $html = $this->reapplyStoredSections($websiteId, $html);
        $html = \App\Engines\Builder\Support\ResponsiveNav::inject($html);
        $html = self::injectMobileSafety($html);
        return preg_replace('#href="/blog/?"#i', 'href="blog/"', $html) ?? $html;
    }

    /**
     * SECTIONS BY CHAT (DEC-0051, 2026-09-15). Move a home-page block before/after another, or to the top (right after
     * the hero) or the bottom (right before the footer). The move is written to the export now and remembered in
     * settings_json.section_ops so every later deploy re-applies it.
     */
    public function moveSection(int $websiteId, string $block, string $position, ?string $ref = null): array
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($path)) return ['success' => false, 'message' => 'This site has no page export yet.'];
        $html = (string) file_get_contents($path);
        $res = $this->applyOneMove($html, $block, $position, $ref);
        if (! $res['success']) return $res;
        try { $this->snapshotToHistory($websiteId, 'section_move'); } catch (\Throwable $e) {}
        file_put_contents($path, $res['html']);
        $settings = json_decode((string) (\Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}'), true) ?: [];
        $ops = array_values(array_filter((array) ($settings['section_ops'] ?? []), fn($o) => ($o['block'] ?? '') !== $block));   // one remembered place per block
        $ops[] = ['block' => $block, 'position' => $position, 'ref' => $ref];
        $settings['section_ops'] = $ops;
        \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
        return ['success' => true, 'message' => $res['message']];
    }

    /* ═══════════════════ ELEMENT888 (DEC-0052, 2026-09-15) — one [data-field] element moves among its siblings ═══════════════════ */

    /** Move / swap one element: op = up | down | top | bottom | before | after | swap (ref = the other element's field key).
     *  Applied to every page carrying the field, remembered in settings.element_ops for deploy(), snapshotted first for Undo. */
    public function moveElement(int $websiteId, string $field, string $op, ?string $ref = null): array
    {
        $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', $field);
        $ref = $ref !== null && $ref !== '' ? (string) preg_replace('/[^a-z0-9_\-]/i', '', $ref) : null;
        if ($field === '' || ! in_array($op, ['up', 'down', 'top', 'bottom', 'before', 'after', 'swap'], true)) {
            return ['success' => false, 'message' => 'Tell me which element and where it should go — up, down, to the top or bottom of its group, before or after another element, or swapped with one.'];
        }
        if (in_array($op, ['before', 'after', 'swap'], true) && ($ref === null || $ref === $field)) {
            return ['success' => false, 'message' => 'Which element should it go ' . ($op === 'swap' ? 'in place of' : $op) . '? Name the text or button you mean.'];
        }
        $root = storage_path("app/public/sites/{$websiteId}");
        if (! is_file("{$root}/index.html")) return ['success' => false, 'message' => 'This site has no page export yet.'];
        $files = ["{$root}/index.html"];
        foreach (glob("{$root}/*/index.html") ?: [] as $f) { if (! str_contains($f, '/.history/')) $files[] = $f; }
        $done = 0; $message = ''; $firstFailure = null;
        foreach ($files as $f) {
            $html = (string) @file_get_contents($f);
            if (! str_contains($html, 'data-field="' . $field . '"')) continue;
            $r = $this->applyOneElementOp($html, $field, $op, $ref);
            if (! $r['success']) { $firstFailure = $firstFailure ?? $r; continue; }
            if ($done === 0) { try { $this->snapshotToHistory($websiteId, 'element_move'); } catch (\Throwable $e) {} }
            file_put_contents($f, $r['html']);
            $done++; $message = $r['message'];
        }
        if ($done === 0) return $firstFailure ?? ['success' => false, 'message' => 'I could not find that element on the page.'];
        $settings = json_decode((string) (\Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}'), true) ?: [];
        $ops = array_values((array) ($settings['element_ops'] ?? []));
        $ops[] = ['field' => $field, 'op' => $op, 'ref' => $ref, 'at' => date('c')];
        if (count($ops) > 80) $ops = array_slice($ops, -80);
        $settings['element_ops'] = $ops;
        \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        return ['success' => true, 'message' => $message, 'pages' => $done];
    }

    /** Re-apply remembered element moves to a freshly rendered page (deploy / deployPage). */
    private function applyElementOps(int $websiteId, string $html): string
    {
        $settings = json_decode((string) (\Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}'), true) ?: [];
        foreach ((array) ($settings['element_ops'] ?? []) as $op) {
            $field = (string) ($op['field'] ?? '');
            if ($field === '' || ! str_contains($html, 'data-field="' . $field . '"')) continue;
            $r = $this->applyOneElementOp($html, $field, (string) ($op['op'] ?? ''), $op['ref'] ?? null);
            if ($r['success']) $html = $r['html'];
        }
        return $html;
    }

    /** The DOM operation itself. Siblings = element children of the same parent (scripts/styles ignored); before/after/swap stay inside one section. */
    private function applyOneElementOp(string $html, string $field, string $op, ?string $ref): array
    {
        $pretty = fn(string $k) => str_replace(['_', '-'], ' ', $k);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $find = fn(string $k) => $xp->query('//*[@data-field="' . $k . '"]')->item(0);
        $node = $find($field);
        if (! $node) return ['success' => false, 'message' => 'I could not find the ' . $pretty($field) . ' on this page.'];
        $blockOf = function (\DOMNode $n): string { for ($p = $n; $p; $p = $p->parentNode) { if ($p instanceof \DOMElement && $p->hasAttribute('data-block')) return $p->getAttribute('data-block'); } return ''; };
        $isEl = fn(?\DOMNode $n) => $n instanceof \DOMElement && ! in_array(strtolower($n->tagName), ['script', 'style', 'template', 'noscript'], true);
        $prevEl = function (\DOMNode $n) use ($isEl) { for ($s = $n->previousSibling; $s; $s = $s->previousSibling) { if ($isEl($s)) return $s; } return null; };
        $nextEl = function (\DOMNode $n) use ($isEl) { for ($s = $n->nextSibling; $s; $s = $s->nextSibling) { if ($isEl($s)) return $s; } return null; };
        $parent = $node->parentNode;
        if (! $parent) return ['success' => false, 'message' => 'That element cannot be moved.'];
        $nameOf = function (\DOMNode $n) use ($pretty): string { $t = trim((string) preg_replace('/\s+/', ' ', $n->textContent ?? '')); $k = $n instanceof \DOMElement ? $n->getAttribute('data-field') : ''; return ($t !== '' && mb_strlen($t) <= 60) ? '"' . mb_substr($t, 0, 40) . '"' : 'the ' . $pretty($k); };
        $what = $nameOf($node);
        switch ($op) {
            case 'up':
                $prev = $prevEl($node);
                if (! $prev) return ['success' => false, 'message' => ucfirst($what) . ' is already the first thing in its group.'];
                $parent->insertBefore($node, $prev); $message = 'moved ' . $what . ' up'; break;
            case 'down':
                $next = $nextEl($node);
                if (! $next) return ['success' => false, 'message' => ucfirst($what) . ' is already the last thing in its group.'];
                $after = $nextEl($next) ?? $next->nextSibling;
                if ($after) $parent->insertBefore($node, $after); else $parent->appendChild($node);
                $message = 'moved ' . $what . ' down'; break;
            case 'top':
                $first = null; foreach ($parent->childNodes as $c) { if ($isEl($c)) { $first = $c; break; } }
                if ($first === $node) return ['success' => false, 'message' => ucfirst($what) . ' is already at the top of its group.'];
                $parent->insertBefore($node, $first); $message = 'moved ' . $what . ' to the top of its group'; break;
            case 'bottom':
                if ($nextEl($node) === null) return ['success' => false, 'message' => ucfirst($what) . ' is already at the bottom of its group.'];
                $parent->appendChild($node); $message = 'moved ' . $what . ' to the bottom of its group'; break;
            case 'before':
            case 'after':
            case 'swap':
                $refNode = $ref !== null ? $find($ref) : null;
                if (! $refNode) return ['success' => false, 'message' => 'I could not find the ' . $pretty((string) $ref) . ' on this page.'];
                if ($refNode === $node || $refNode->contains($node) || $node->contains($refNode)) return ['success' => false, 'message' => 'Those are the same element, or one sits inside the other.'];
                if ($blockOf($refNode) !== $blockOf($node)) return ['success' => false, 'message' => 'I can move things around inside their own section. ' . ucfirst($what) . ' and the ' . $pretty((string) $ref) . ' sit in different sections — move the whole section instead, or ask me to add the same text there.'];
                if ($op === 'swap') {
                    $ph = $dom->createElement('span');
                    $refNode->parentNode->insertBefore($ph, $refNode);
                    $parent->insertBefore($refNode, $node);
                    $ph->parentNode->insertBefore($node, $ph);
                    $ph->parentNode->removeChild($ph);
                    $message = 'swapped ' . $what . ' and ' . $nameOf($refNode);
                } elseif ($op === 'before') {
                    $refNode->parentNode->insertBefore($node, $refNode); $message = 'moved ' . $what . ' before ' . $nameOf($refNode);
                } else {
                    $n2 = $refNode->nextSibling; if ($n2) $refNode->parentNode->insertBefore($node, $n2); else $refNode->parentNode->appendChild($node);
                    $message = 'moved ' . $what . ' after ' . $nameOf($refNode);
                }
                break;
            default:
                return ['success' => false, 'message' => 'Tell me where it should go.'];
        }
        $out = $dom->saveHTML();
        $out = preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $out) ?? $out;
        $out = $this->restoreUtf8Entities($out);
        return ['success' => true, 'html' => $out, 'message' => $message];
    }

    /** The order of the data-field elements on the home page (for proofs and the model's context). */
    public function fieldOrder(int $websiteId, string $block = ''): array
    {
        $html = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if ($block !== '' && preg_match('/<[a-z0-9]+\b[^>]*data-block="' . preg_quote($block, '/') . '"[^>]*>(.*?)(?=<[a-z0-9]+\b[^>]*data-block="|<\/body>)/su', $html, $m)) $html = $m[1];
        return preg_match_all('/data-field="([a-z0-9_\-]+)"/i', $html, $mm) ? array_values(array_unique($mm[1])) : [];
    }
    /** Re-apply remembered moves to a freshly rendered home (called from deploy()). */
    private function applySectionOps(int $websiteId, string $html): string
    {
        $settings = json_decode((string) (\Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}'), true) ?: [];
        foreach ((array) ($settings['section_ops'] ?? []) as $op) {
            $r = $this->applyOneMove($html, (string) ($op['block'] ?? ''), (string) ($op['position'] ?? ''), $op['ref'] ?? null);
            if ($r['success']) $html = $r['html'];
        }
        return $html;
    }

    private function applyOneMove(string $html, string $block, string $position, ?string $ref): array
    {
        if (! preg_match('/^[a-z0-9_\-]+$/', $block) || ($ref !== null && $ref !== '' && ! preg_match('/^[a-z0-9_\-]+$/', $ref))) return ['success' => false, 'message' => 'I could not tell which section you mean.'];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $find = fn(string $b) => $xp->query('//*[@data-block="' . $b . '"]')->item(0);
        $node = $find($block);
        if (! $node) return ['success' => false, 'message' => "There is no “" . str_replace('_', ' ', $block) . "” section on the home page."];
        if (in_array($block, ['nav', 'hero', 'footer'], true)) return ['success' => false, 'message' => 'The header, hero and footer stay where they are — everything between them can move.'];
        $parent = $node->parentNode;
        if ($position === 'top') {
            $hero = $find('hero'); $target = $hero ? $hero->nextSibling : null;
            while ($target && $target->nodeType !== XML_ELEMENT_NODE) $target = $target->nextSibling;
            if (! $hero) return ['success' => false, 'message' => 'I could not find the hero to place it after.'];
            if ($target) $hero->parentNode->insertBefore($node, $target); else $hero->parentNode->appendChild($node);
            $where = 'right after the hero';
        } elseif ($position === 'bottom') {
            $footer = $find('footer');
            if ($footer) $footer->parentNode->insertBefore($node, $footer); else $parent->appendChild($node);
            $where = 'at the bottom, just above the footer';
        } elseif (in_array($position, ['before', 'after'], true) && $ref) {
            $refNode = $find($ref);
            if (! $refNode) return ['success' => false, 'message' => "There is no “" . str_replace('_', ' ', $ref) . "” section to place it " . $position . '.'];
            if ($refNode === $node) return ['success' => false, 'message' => 'That is the same section.'];
            if ($position === 'before') $refNode->parentNode->insertBefore($node, $refNode);
            else { $next = $refNode->nextSibling; if ($next) $refNode->parentNode->insertBefore($node, $next); else $refNode->parentNode->appendChild($node); }
            $where = $position . ' the ' . str_replace('_', ' ', $ref) . ' section';
        } else {
            return ['success' => false, 'message' => 'Tell me where it should go — before or after another section, or to the top or the bottom.'];
        }
        $out = $dom->saveHTML();
        $out = preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $out) ?? $out;
        $out = $this->restoreUtf8Entities($out);
        return ['success' => true, 'html' => $out, 'message' => 'moved the ' . str_replace('_', ' ', $block) . ' section ' . $where];
    }

    private function reapplyStoredSections(int $websiteId, string $html): string
    {
        try {
            $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['settings_json']);
            if (!$w) return $html;
            $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
            foreach ((array) ($s['arthur_sections'] ?? []) as $sec) {
                $type = (string) ($sec['type'] ?? ''); $frag = (string) ($sec['html'] ?? '');
                if ($type === '' || $frag === '' || str_contains($html, 'data-block="added_' . $type . '"')) continue;
                $html = $this->insertBeforeOrAfter($html, $frag, (string) ($sec['anchor'] ?? 'contact'), (string) ($sec['where'] ?? 'before'));
            }
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[TemplateService] reapplyStoredSections: ' . $e->getMessage()); }
        return $html;
    }

    private function reapplyPageNavLinks(int $websiteId): void
    {
        try {
            $pages = \Illuminate\Support\Facades\DB::table('pages')->where('website_id', $websiteId)->where('status', 'published')
                ->whereNotIn('slug', ['home', 'blog', 'news', ''])->orderBy('position')->get(['slug', 'title']);
            foreach ($pages as $p) {
                $slug = (string) $p->slug;
                if (!is_file(storage_path("app/public/sites/{$websiteId}/{$slug}/index.html"))) continue;
                $this->addNavLink($websiteId, $slug, trim(explode('/', (string) $p->title)[0]) ?: ucfirst($slug));
            }
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[TemplateService] reapplyPageNavLinks: ' . $e->getMessage()); }
    }

    /** Pure string insertion used by both the live splice and the re-apply. */
    private function insertBeforeOrAfter(string $home, string $sectionHtml, string $anchorBlock, string $where): string
    {
        $anchorBlock = preg_replace('/[^a-z0-9_\-]/', '', strtolower($anchorBlock)) ?: 'contact';
        $pos = null;
        if ($anchorBlock === 'footer') {
            if (preg_match('/<footer\b/i', $home, $m, PREG_OFFSET_CAPTURE)) $pos = $m[0][1];
        } elseif (($hit = $this->locateAnchor($home, $anchorBlock)) !== null) {
            $start = $hit[0];
            if ($where === 'after') { $close = stripos($home, '</section>', $start); $pos = $close === false ? null : $close + strlen('</section>'); }
            else { $pos = $start; }
        }
        if ($pos === null) {
            if (preg_match('/<footer\b/i', $home, $m, PREG_OFFSET_CAPTURE)) $pos = $m[0][1];
            elseif (($b = stripos($home, '</body>')) !== false) $pos = $b;
            else return $home;
        }
        return substr($home, 0, $pos) . "\n" . $sectionHtml . "\n" . substr($home, $pos);
    }

    /**
     * Insert a rendered section into the static home before/after a data-block anchor, then redeploy (nav
     * injection, blog index and page-relative blog links are re-applied by deploy()). Returns the new home html.
     */
    /** What each spoken anchor may be called in a template that names its blocks differently. */
    private const ANCHOR_SYNONYMS = [
        'services'     => ['services', 'services_menu', 'menu_highlights', 'specials', 'programs', 'programmes', 'treatments', 'inventory', 'featured_vehicles', 'room_types', 'listings', 'features', 'use_cases', 'offers', 'packages', 'courses', 'experiences', 'amenities', 'venues', 'service_types', 'curriculum', 'expertise'],
        'about'        => ['about', 'story', 'methodology', 'why_us', 'mission', 'philosophy', 'values'],
        'process'      => ['process', 'how_it_works', 'steps', 'admissions', 'methodology'],
        'team'         => ['team', 'doctors', 'staff', 'trainers', 'faculty', 'stylists', 'agents', 'people'],
        'gallery'      => ['gallery', 'portfolio', 'projects', 'transformations', 'campus_gallery', 'results', 'case_studies', 'work'],
        'testimonials' => ['testimonials', 'reviews', 'social_proof', 'clients'],
        'pricing'      => ['pricing', 'membership', 'packages', 'plans', 'rates', 'financing'],
        'stats'        => ['stats', 'stats_strip', 'numbers', 'results', 'achievements'],
        'booking'      => ['booking', 'contact_form', 'contact', 'appointments', 'reservations'],
        'contact'      => ['contact', 'contact_form', 'booking', 'cta_banner'],
        'blog'         => ['blog', 'news', 'journal', 'articles', 'press'],
        'why_us'       => ['why_us', 'features', 'trust_signals', 'certifications', 'awards', 'about'],
        'menu_highlights' => ['menu_highlights', 'specials', 'services_menu', 'services'],
    ];

    /**
     * Find the block the customer meant. Returns [offset-of-<section>, block-name-actually-used] or null.
     * Exact name first; then the synonyms; the caller falls back to the footer as it always did.
     */
    private function locateAnchor(string $home, string $anchorBlock): ?array
    {
        $candidates = array_values(array_unique(array_merge([$anchorBlock], self::ANCHOR_SYNONYMS[$anchorBlock] ?? [])));
        foreach ($candidates as $name) {
            if (preg_match('/<section\b[^>]*data-block="' . preg_quote($name, '/') . '"[^>]*>/i', $home, $m, PREG_OFFSET_CAPTURE)) {
                return [$m[0][1], $name];
            }
        }
        return null;
    }

    /** The anchor a splice actually used, for the reply. Set by spliceSectionIntoHome. */
    public ?string $lastAnchorUsed = null;

    public function spliceSectionIntoHome(int $websiteId, string $sectionHtml, string $anchorBlock = 'contact', string $where = 'before'): ?string
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (!is_file($path)) return null;
        $home = (string) file_get_contents($path);
        $anchorBlock = preg_replace('/[^a-z0-9_\-]/', '', strtolower($anchorBlock)) ?: 'contact';
        $pos = null;
        $this->lastAnchorUsed = null;
        if ($anchorBlock === 'footer') {
            if (preg_match('/<footer\b/i', $home, $m, PREG_OFFSET_CAPTURE)) { $pos = $m[0][1]; $this->lastAnchorUsed = 'footer'; }
        } elseif (($hit = $this->locateAnchor($home, $anchorBlock)) !== null) {
            [$start, $used] = $hit;
            $this->lastAnchorUsed = $used;
            if ($where === 'after') {
                $close = stripos($home, '</section>', $start);
                $pos = $close === false ? null : $close + strlen('</section>');
            } else { $pos = $start; }
        }
        if ($pos === null) {
            // fall back: before the footer, else before </body>
            $this->lastAnchorUsed = 'footer';
            if (preg_match('/<footer\b/i', $home, $m, PREG_OFFSET_CAPTURE)) $pos = $m[0][1];
            elseif (($b = stripos($home, '</body>')) !== false) $pos = $b;
            else return null;
        }
        $home = substr($home, 0, $pos) . "\n" . $sectionHtml . "\n" . substr($home, $pos);
        $this->deploy($websiteId, $home);
        return $home;
    }
    /**
     * Form selects never ship demo options: service/specialty/treatment/program selects list the site's visible services;
     * people selects (doctor/practitioner/stylist/trainer/specialist/member) list the site's real named people or only the
     * placeholder. The placeholder option (value="" / disabled) is always kept.
     */
    /**
     * The names of what a site sells, whatever its template calls them. service_N_title where it exists;
     * otherwise the first family present, in this order. A *_N_display of display:none hides that item.
     *
     * @return array<int,string>
     */
    public static function serviceTitles(array $variables, int $max = 8): array
    {
        $families = [
            ['service', 'title'], ['treatment', 'title'], ['menu', 'title'], ['special', 'title'], ['program', 'title'],
            ['programme', 'title'], ['course', 'title'], ['room', 'name'], ['amenity', 'title'], ['featured', 'title'],
            ['feature', 'title'], ['usecase', 'title'], ['specialty', 'name'], ['package', 'title'], ['plan', 'name'],
            ['session', 'name'], ['dining', 'name'], ['exp', 'title'], ['offer', 'title'], ['product', 'title'],
        ];
        foreach ($families as [$fam, $leaf]) {
            $out = [];
            for ($i = 1; $i <= $max; $i++) {
                $t = trim((string) ($variables["{$fam}_{$i}_{$leaf}"] ?? ''));
                if ($t === '' || (string) ($variables["{$fam}_{$i}_display"] ?? '') === 'display:none') { continue; }
                $out[] = $t;
            }
            if ($out !== []) { return array_values(array_unique($out)); }
        }
        return [];
    }

    public function fillServiceSelects(string $html, array $variables): string
    {
        // Every family a template uses for its offerings, not only service_N_title (SERVICE-TITLES 2026-09-11).
        $titles = self::serviceTitles($variables);
        $people = [];
        foreach ($variables as $k => $v) {
            if (!is_string($v)) continue;
            if (preg_match('/^(doctor|dentist|team|member|staff|trainer|stylist|barber|therapist|instructor|coach|specialist|agent|broker)_\d+_name$/i', (string) $k)) {
                $n = trim($v);
                if ($n !== '' && !preg_match('/^(team member|our team|staff member)$/i', $n)) $people[] = $n;
            }
        }
        $people = array_values(array_unique($people));
        $rebuild = function (string $open, string $inner, string $close, array $options) : string {
            $placeholder = preg_match('#<option\b[^>]*(?:value=""|disabled)[^>]*>.*?</option>#is', $inner, $pm) ? $pm[0] : '<option value="" disabled selected>Select…</option>';
            $opts = '';
            foreach ($options as $o) { $opts .= '<option>' . htmlspecialchars($o, ENT_QUOTES, 'UTF-8') . '</option>'; }
            return $open . $placeholder . $opts . $close;
        };
        $html = preg_replace_callback('#(<select\b[^>]*\bname="(?:service|services|service_type|service_needed|specialty|speciality|treatment|procedure|program|programme|course_type)[^"]*"[^>]*>)(.*?)(</select>)#is',
            fn($m) => $titles === [] ? $m[0] : $rebuild($m[1], $m[2], $m[3], $titles), $html) ?? $html;
        $html = preg_replace_callback('#(<select\b[^>]*\bname="(?:doctor|dentist|practitioner|physician|stylist|barber|trainer|coach|therapist|specialist|member|staff|agent|broker)[^"]*"[^>]*>)(.*?)(</select>)#is',
            fn($m) => $rebuild($m[1], $m[2], $m[3], $people), $html) ?? $html;
        return $html;
    }

    /** First-and-last names used as sample personas anywhere in the 31 manifests (cached per process). */
    public static function samplePersonaNames(): array
    {
        static $names = null;
        if ($names !== null) return $names;
        // stock personas from earlier manifest generations and the model's favourite placeholder people
        $names = array_fill_keys(['sarah mitchell', 'ahmed hassan', 'emily chen', 'james carter', 'john smith', 'jane doe', 'john doe', 'sarah johnson', 'michael chen', 'fatima al-rashid', 'omar khalil', 'priya sharma', 'david miller', 'emma wilson', 'ali hassan', 'layla ahmed', 'mohammed ali', 'aisha rahman', 'sarah nakamura', 'james whitfield', 'maria santos', 'juan dela cruz'], true);
        foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $p) {
            $m = json_decode((string) @file_get_contents($p), true) ?: [];
            foreach (($m['variables'] ?? []) as $k => $spec) {
                if (!is_array($spec) || !preg_match('/_(author|name)$/i', (string) $k) || preg_match('/^(business|brand|company|logo|site)_/i', (string) $k)) continue;
                $d = trim((string) ($spec['default'] ?? ''));
                if (preg_match('/^(?:Dr\.?\s+)?([A-Z][a-z]+(?:\s[A-Z][a-z]+){1,2})/u', $d, $mm)) $names[strtolower($mm[1])] = true;
            }
        }
        return $names;
    }

    /**
     * A testimonial author that is the template sample, or reuses any sample persona's name, is a fabricated person —
     * show "Verified client" instead. Sample names in form placeholders become "Your name".
     */
    public function scrubSampleAuthors(array $variables, array $manifestVars): array
    {
        $personas = self::samplePersonaNames();
        foreach ($variables as $k => $v) {
            if (!is_string($v) || !preg_match('/^(testimonial|review)_\d+_(author|name)$/i', (string) $k)) continue;
            $cur = trim($v); if ($cur === '') continue;
            $def = trim((string) ($manifestVars[$k]['default'] ?? ''));
            $first = preg_match('/^(?:Dr\.?\s+)?([A-Z][a-z]+(?:\s[A-Z][a-z]+){1,2})/u', $cur, $mm) ? strtolower($mm[1]) : '';
            if (($def !== '' && $cur === $def) || ($first !== '' && isset($personas[$first]))) $variables[$k] = 'Verified client';
        }
        return $variables;
    }

    /** Sample names in input placeholders ("Ahmed Hassan") become neutral prompts. */
    public function scrubPlaceholderNames(string $html): string
    {
        $personas = array_keys(self::samplePersonaNames());
        if ($personas === []) return $html;
        $alt = implode('|', array_map(fn($n) => preg_quote($n, '/'), $personas));
        return preg_replace('/placeholder="(?:Dr\.?\s+)?(?:' . $alt . ')[^"]*"/iu', 'placeholder="Your name"', $html) ?? $html;
    }
    public function deploy(int $websiteId, string $html): string
    {
        // RISK-0101 — never ship placeholder blog cards that link nowhere. When the
        // workspace has no real articles, replace the baked sample cards with an
        // honest empty state (applies to the home export AND, via deployBlogIndex
        // below which receives this same $html, the /blog index).
        $html = $this->honestBlogSection($websiteId, $html);
        $dir = storage_path("app/public/sites/{$websiteId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/index.html';
        // MOBILE-4: the static export is served straight off disk by nginx, so PublishedSiteMiddleware never sees
        // it. Without this the nav overflows a phone in every draft and preview.
        // DURABLE ADDITIONS (2026-09-06): Arthur-spliced sections are stored in settings_json.arthur_sections; put back any
        // that a re-render from variables dropped (idempotent — skipped when the block is already present).
        $html = $this->reapplyStoredSections($websiteId, $html);
        $html = $this->applySectionOps($websiteId, $html);   // remembered section moves (DEC-0051)
        $html = $this->applyElementOps($websiteId, $html);   // remembered element moves (ELEMENT888, DEC-0052)
        $html = \App\Engines\Builder\Support\ResponsiveNav::inject($html);
        $html = self::injectMobileSafety($html);
        $html = \App\Engines\Builder\Support\SiteScripts::inject($html, $websiteId);   // forms → CRM, tracking ids (DEC-0051)
        // STATIC-EXPORT BLOG LINK (2026-09-05): the export is browsed under /storage/sites/{id}/,
        // so a root-absolute "/blog" hits the PLATFORM blog, not this site's. Make blog nav links
        // relative so they reach THIS site's own blog export (sites/{id}/blog/). Export-only —
        // subdomain serving via getFullHtml keeps /blog.
        // Only the BARE nav link is rewritten. A permalink (/blog/{slug}) is left root-absolute: that is the
        // real address of a post on the published site, and it is what the serve-time card injector matches on.
        $html = preg_replace('#href="/blog/?"#i', 'href="blog/"', $html);
        file_put_contents($path, $html);
        // …and the menu links of every added page (published, exported) come back too.
        $this->reapplyPageNavLinks($websiteId);

        // Also deploy a static /blog index that reuses THIS page's chrome + theme
        // so /blog shares the same top menu and colours instead of falling to the
        // bare dynamic renderer. Fail-open: never let it break the main deploy.
        try {
            $this->deployBlogIndex($websiteId, $html);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TemplateService] blog index deploy failed: ' . $e->getMessage());
        }
        // SITE THUMBNAIL (2026-09-15): the Websites-page card shows a shot of the home page; re-shoot after each deploy (unique per site, 20 s).
        try { \App\Jobs\GenerateSiteThumbnailJob::dispatch($websiteId)->delay(now()->addSeconds(8)); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[TemplateService] thumbnail job not queued: ' . $e->getMessage()); }

        return $path;
    }

    /**
     * Build + write sites/{id}/blog/index.html from the home page's chrome so the
     * blog listing shares the same <head> (theme/CSS), top nav, and footer. In-page
     * #anchors in the nav/footer are rewritten to /#anchor (they target home
     * sections). The home's blog section (its post-card grid) becomes the listing
     * body so styling matches and the serve-time article injector (Wave 63) has a
     * post-card template to clone. Returns the path, or null if no <head> found.
     */
    public function deployBlogIndex(int $websiteId, string $homeHtml): ?string
    {
        if (!preg_match('/<head\b[^>]*>.*?<\/head>/is', $homeHtml, $hm)) {
            return null;
        }
        $head = $hm[0];
        // The reused home <head> carries the HOME <title>; give /blog its own.
        $siteName = preg_match('/<title>\\s*([^<|\xe2\x80\x94-]+)/iu', $head, $tn)
            ? trim($tn[1]) : 'Blog';
        $head = preg_replace('/<title>.*?<\\/title>/is', '<title>Blog — ' . e($siteName) . '</title>', $head, 1) ?? $head;

        $nav = '';
        if (preg_match('/<nav\b[^>]*(?:id="main-nav"|data-block="nav")[^>]*>.*?<\/nav>/is', $homeHtml, $nm)) {
            $nav = $nm[0];
        } elseif (preg_match('/<header\b[^>]*>.*?<\/header>/is', $homeHtml, $nm2)) {
            $nav = $nm2[0];
        } elseif (preg_match('/<nav\b[^>]*>.*?<\/nav>/is', $homeHtml, $nm3)) {
            $nav = $nm3[0];
        }

        $footer = '';
        if (preg_match('/<footer\b[^>]*>.*?<\/footer>/is', $homeHtml, $fm)) {
            $footer = $fm[0];
        }

        // The home's blog section is the themed listing body (contains post-cards).
        $blog = '';
        if (preg_match('/<section\b[^>]*(?:blog|insights|articles)[^>]*>.*?<\/section>/is', $homeHtml, $bm)) {
            $blog = $bm[0];
        }

        // RISK-0101 — on /blog the blog title is the page's main heading: promote the
        // blog_title <h2> to <h1> so the listing page has exactly one top-level heading.
        if ($blog !== '') {
            $blog = preg_replace(
                '#<h2(\s[^>]*data-field="blog_title"[^>]*)>(.*?)</h2>#is',
                '<h1$1>$2</h1>',
                $blog, 1
            ) ?? $blog;
        }

        // Rewrite in-page anchors so the shared nav/footer navigate back to home.
        $toHome = function (string $frag): string {
            $frag = preg_replace('/href="#"/i', 'href="../"', $frag);
            $frag = preg_replace('/href="#([a-z0-9\-]+)"/i', 'href="../#$1"', $frag);
            return (string) $frag;
        };
        $nav = $toHome($nav);
        $footer = $toHome($footer);

        // Fallback listing when the template has no blog section — still on-brand,
        // still carries a post-card template for the serve-time injector.
        if ($blog === '') {
            $blog = '<section class="blog" id="blog" data-block="blog">'
                . '<div style="max-width:1240px;margin:0 auto;padding:6rem 2.5rem">'
                . '<h2 class="section-h2">From the Blog</h2>'
                . '<div class="post-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;margin-top:2rem">'
                . '<a href="/blog/welcome" class="post-card-link"><article class="post-card">'
                . '<h3>Articles coming soon</h3><p class="excerpt">Fresh posts are on the way — check back shortly.</p>'
                . '<div class="post-cat">News</div></article></a>'
                . '</div></div></section>';
        }

        $lang = preg_match('/<html\b[^>]*lang="([^"]+)"/i', $homeHtml, $lm) ? $lm[1] : 'en';
        $doc = '<!doctype html><html lang="' . e($lang) . '">' . $head . '<body>'
             . $nav . '<main>' . $blog . '</main>' . $footer . '</body></html>';

        $dir = storage_path("app/public/sites/{$websiteId}/blog");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/index.html';
        // Only the BARE blog link is a nav link back to this page. A permalink (/blog/{slug}) must survive,
        // or the serve-time card injector has no href to point at the article and every card links here.
        $doc = preg_replace('#href="/blog/?"#i', 'href="./"', $doc);
        file_put_contents($path, $doc);

        return $path;
    }

    /**
     * RISK-0103 — DOMDocument::saveHTML() encodes every non-ASCII character to an
     * HTML entity (● -> &#9679;, → -> &rarr;, curly quotes, em-dashes,
     * accents). That is harmless in HTML text (entities render) but FATAL inside
     * <style>/CSS `content:` values, where entities are not decoded — so a single
     * field save corrupted every template bullet/arrow site-wide. This restores
     * literal UTF-8 for all entities EXCEPT the five structural ones (&amp; &lt;
     * &gt; &quot; and the apostrophe forms), which must stay escaped for valid
     * markup. Also self-heals an already-corrupted file on the next save (the
     * literal &#9679; text decodes back to ●).
     */
    private function restoreUtf8Entities(string $html): string
    {
        $keep = ['&amp;' => "A", '&lt;' => "L", '&gt;' => "G", '&quot;' => "Q", '&#39;' => "P", '&apos;' => "P"];
        $html = strtr($html, $keep);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strtr($html, ["A" => '&amp;', "L" => '&lt;', "G" => '&gt;', "Q" => '&quot;', "P" => '&#39;']);
    }

    /**
     * Update a single field in a deployed site's HTML using data-field attributes.
     *
     * @param int    $websiteId
     * @param string $fieldId
     * @param string $value
     * @return bool
     */
    public function updateField(int $websiteId, string $fieldId, string $value, bool $snapshot = true): bool
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (!file_exists($path)) {
            return false;
        }

        // RISK-0113 (defence-in-depth) — reject a non-identifier field name so it can never be
        // interpolated into the XPath below. The route validates too; this guards other callers.
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fieldId)) {
            return false;
        }
        // STRESS C33 (2026-09-06): no template has an <img> logo slot — an uploaded logo replaces the text logo instead.
        if ($fieldId === 'logo_url') {
            $fp = @fopen($path, 'r+');
            if ($fp === false) return false;
            if (! flock($fp, LOCK_EX)) { fclose($fp); return false; }
            $html = (string) stream_get_contents($fp);
            $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['name', 'template_variables']);
            $tvL = json_decode((string) ($w->template_variables ?? '{}'), true) ?: [];
            $alt = (string) ($w->name ?? ''); $text = (string) ($tvL['logo'] ?? $alt);
            $new = self::applyLogoSlot(self::applyLogoImage($html, $value, $alt, $text), $value);   // RISK-0185: both logo shapes
            $changed = $new !== $html;
            if ($changed) { ftruncate($fp, 0); rewind($fp); fwrite($fp, $new); fflush($fp); }
            flock($fp, LOCK_UN); fclose($fp);
            foreach (glob(dirname($path) . '/*/index.html') ?: [] as $sub) { $h = (string) file_get_contents($sub); $n = self::applyLogoSlot(self::applyLogoImage($h, $value, $alt, $text), $value); if ($n !== $h) file_put_contents($sub, $n); }
            return $changed;
        }

        // RISK-0112 — serialise concurrent field-saves with an exclusive file lock.
        // This is read-modify-write on the deployed static file; without a lock two
        // concurrent saves (customer+Arthur, two tabs, a rapid multi-field flush) both
        // read the same base and the later write clobbers the earlier field — a silent
        // lost edit that still returned 200. LOCK_EX makes each save read the latest state.
        $fp = @fopen($path, 'r+');
        if ($fp === false) return false;
        if (! flock($fp, LOCK_EX)) { fclose($fp); return false; }

        $original = stream_get_contents($fp);   // RISK-0107 — pre-edit content, for backup
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML(
            $original,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $found = false;

        // Image-typed fields set src / background-image; text fields set textContent.
        // Doing this surgically (in-place) instead of a full template re-render is
        // what preserves the site's post-processed content (archetype-governed
        // sections, injected menu/catalog/units blocks, scrubbed names). A full
        // re-render can't reproduce those without the original build_data.
        $isImg = str_ends_with($fieldId, '_image') || $fieldId === 'logo_url'
              || str_contains($fieldId, 'image_') || str_contains($fieldId, '_img');

        // BUILDER888 D4 (2026-08-28) — a value written into a CSS url() must not be able to
        // close the url()/style attribute (CSS injection into the served page).
        $cssUrl = str_replace(["'", '"', '(', ')', ';', '\\', "\n", "\r"], '', $value);

        // BUILDER888 D3 (2026-08-28) — templates keep hero text legible with a wash layered
        // over the photo IN THE CLASS RULE (.hero{background-image:linear-gradient(...),url(...)}).
        // Writing a bare inline url() replaces the whole layer list, drops the wash and leaves
        // dark text on a busy photo. Reuse the class rule's declaration with the url swapped.
        $bgDecl = function (\DOMElement $el) use ($original): ?string {
            if (! preg_match_all('/<style[^>]*>(.*?)<\/style>/is', (string) $original, $m)) return null;
            $css = implode("\n", $m[1]);
            foreach (preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [] as $c) {
                if ($c === '') continue;
                if (! preg_match_all('/(?:^|[\s,}])\.' . preg_quote($c, '/') . '\s*\{([^}]*)\}/s', $css, $rules)) continue;
                foreach ($rules[1] as $body) {
                    if (preg_match('/background(?:-image)?\s*:\s*([^;}]*url\([^;}]*)/i', $body, $b)) return trim($b[1]);
                }
            }
            return null;
        };

        $applyImg = function (\DOMElement $el, string $value) use ($cssUrl, $bgDecl) {
            $done = false;
            if (strtolower($el->nodeName) === 'img') {
                $el->setAttribute('src', $value);
                if ($el->hasAttribute('srcset')) $el->setAttribute('srcset', $value);
                $done = true;
            }
            foreach ($el->getElementsByTagName('img') as $img) {
                $img->setAttribute('src', $value);
                if ($img->hasAttribute('srcset')) $img->setAttribute('srcset', $value);
                $done = true;
            }
            if ($el->hasAttribute('style') && stripos($el->getAttribute('style'), 'background') !== false) {
                // 2026-09-14: the inline declaration is usually "linear-gradient(wash),url(photo)" — swap the url() inside
                // the declaration and keep the wash. The old pattern only matched a bare url() and reported success anyway.
                $styleNow = (string) $el->getAttribute('style');
                $styleNew = preg_replace_callback('/(background(?:-image)?\s*:\s*)([^;]*)/i', function ($m) use ($cssUrl) {
                    if (stripos($m[2], 'url(') === false) { return $m[0]; }
                    return $m[1] . preg_replace('/url\([^)]*\)/i', "url('" . $cssUrl . "')", $m[2]);
                }, $styleNow);
                if (is_string($styleNew) && $styleNew !== $styleNow) { $el->setAttribute('style', $styleNew); $done = true; }
            }
            // BUILDER888 D4 (2026-08-28) — a background wrapper whose image comes from a CSS
            // class (no inline style) used to fall through to the TEXT branch below, which
            // replaced the wrapper's ENTIRE subtree (hero h1, subtitle, trust items, booking
            // form) with the URL string — on the published site, while returning "saved".
            // Any non-<img> element that carries content gets an inline background-image
            // instead (inline wins over the class rule), and its children are preserved.
            if (! $done && ($el->getElementsByTagName('*')->length > 0 || trim((string) $el->textContent) !== '')) {
                $style = trim((string) $el->getAttribute('style'));
                if ($style !== '' && ! str_ends_with($style, ';')) $style .= ';';
                $decl = $bgDecl($el);
                $bg = $decl ? preg_replace('/url\([^)]*\)/i', "url('" . $cssUrl . "')", $decl) : "url('" . $cssUrl . "')";
                $el->setAttribute('style', $style . 'background-image:' . $bg);
                $done = true;
            }
            return $done;
        };

        // RISK-0104 — the editor posts innerHTML (entity-encoded); textContent
        // re-encodes on saveHTML, so a typed "&" would double-escape to
        // "&amp;amp;". Decode once for text writes. XSS-safe: textContent +
        // saveHTML re-escape <>& so decoded markup serialises back inert.
        $textValue = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($xpath->query("//*[@data-field='{$fieldId}']") as $el) {
            // RISK-0113 — never write into a <script>/<style> node (raw serialisation = XSS).
            if (in_array(strtolower($el->nodeName), ['script', 'style'], true)) { continue; }
            // BUILDER888 D4 — a text write into an element that wraps OTHER fields would
            // destroy them (the wrapper's subtree is replaced by a text node). Refuse and
            // leave the served file untouched; the durable value still lands in
            // template_variables and the route reports export_patched=false (degraded).
            $wrapsOtherFields = $xpath->query(".//*[@data-field]", $el)->length > 0;
            if ($isImg) {
                if ($applyImg($el, $value)) { $found = true; }
                elseif (! $wrapsOtherFields) { $el->textContent = $textValue; $found = true; } // empty text logo
            } elseif (! $wrapsOtherFields) {
                $el->textContent = $textValue;
                $found = true;
            } else {
                \Illuminate\Support\Facades\Log::warning('[Builder] updateField refused: text write would destroy nested fields', ['website_id' => $websiteId, 'field' => $fieldId]);
            }
        }

        if ($found) {
            // RISK-0107 — preserve the pre-edit served content so a bad inline edit is recoverable.
            // DEC-0046 (2026-09-14): through the shared snapshot (deduplicated, nested pages, record sidecar) — a raw copy
            // here produced a sidecar-less entry that Undo consumed, leaving the record stale.
            // LISTINGS (2026-09-14): a catalogue sync patches many fields under ONE snapshot of its own ($snapshot=false).
            if ($snapshot) try { $this->snapshotToHistory($websiteId, 'field_edit'); } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[TemplateService] RISK-0107 pre-edit backup failed: ' . $e->getMessage());
            }

            $new = $this->restoreUtf8Entities($dom->saveHTML());
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $new);
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $found;
    }

    /**
     * DEC-0046 (2026-09-13) — one history snapshot per Arthur request, so Versions and Undo cover everything
     * Arthur does (text, sections, colours, gradients, palettes). Skips when the newest entry already holds these
     * exact bytes, so a no-op request never consumes undo depth. Nested page exports travel in a sibling
     * directory index-{stamp}.d/ so a restore puts the whole site back, not just the home page.
     */
    public function snapshotToHistory(int $websiteId, string $reason = 'arthur'): ?string
    {
        try {
            $root  = storage_path("app/public/sites/{$websiteId}");
            $index = "{$root}/index.html";
            if (! is_file($index)) { return $this->snapshotRecordOnly($websiteId, $root, $reason); }
            $bytes = (string) @file_get_contents($index);
            if ($bytes === '') { return null; }
            $dir = "{$root}/.history";
            if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (! is_dir($dir)) { return null; }
            $existing = glob($dir . '/index-*.html') ?: [];
            sort($existing);
            $latest = $existing !== [] ? end($existing) : null;
            if ($latest !== null && @md5_file($latest) === md5($bytes) && $this->nestedUnchangedSince($root, $latest) && $this->recordUnchangedSince($websiteId, $latest)) {
                return basename($latest);
            }
            $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
            $file  = "{$dir}/index-{$stamp}.html";
            if (@file_put_contents($file, $bytes) === false) { return null; }
            // The record travels with the file: template_variables hold palette/design_extras/colours, and an undo
            // that put the old bytes back while the record still claimed the new palette would lie on rebuild.
            try {
                $row = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['template_variables', 'settings_json']);
                $tvRaw = $row->template_variables ?? null;
                @file_put_contents("{$dir}/index-{$stamp}.json", json_encode(['template_variables' => $tvRaw !== null ? (string) $tvRaw : null, 'settings_json' => isset($row->settings_json) ? (string) $row->settings_json : null, 'saved_at' => date('c'), 'reason' => $reason]));
            } catch (\Throwable $e) {}
            foreach ($this->nestedPages($root) as $rel => $abs) {
                $dst = "{$dir}/index-{$stamp}.d/{$rel}";
                @mkdir(dirname($dst), 0775, true);
                @copy($abs, $dst);
            }
            $this->pruneHistory($dir);
            \Illuminate\Support\Facades\Log::info('[TemplateService] history snapshot', ['website' => $websiteId, 'file' => basename($file), 'reason' => $reason]);
            return basename($file);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TemplateService] history snapshot failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * DEC-0046 — Undo: put the newest history entry live and consume it. What was live is kept as redo-*.html
     * (outside the Versions list) so nothing is ever lost. Reports from the file, never from intent.
     * @return array{undone:bool, restored_from?:string, error?:string, remaining:int}
     */
    public function undoLatest(int $websiteId): array
    {
        $root    = storage_path("app/public/sites/{$websiteId}");
        $dir     = "{$root}/.history";
        $entries = glob($dir . '/index-*.html') ?: [];
        if ($entries === []) { return $this->undoRecordOnly($websiteId, $dir); }
        sort($entries);
        $latest = end($entries);
        $target = "{$root}/index.html";
        $bytes  = @file_get_contents($latest);
        if ($bytes === false || $bytes === '' || ! is_file($target)) { return ['undone' => false, 'error' => 'not_found', 'remaining' => count($entries)]; }
        $fp = @fopen($target, 'r+');
        if ($fp === false) { return ['undone' => false, 'error' => 'lock_failed', 'remaining' => count($entries)]; }
        if (! flock($fp, LOCK_EX)) { fclose($fp); return ['undone' => false, 'error' => 'lock_failed', 'remaining' => count($entries)]; }
        $current = stream_get_contents($fp);
        $stamp   = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        if (is_string($current) && $current !== '') { @file_put_contents("{$dir}/redo-{$stamp}.html", $current); }
        rewind($fp); ftruncate($fp, 0); fwrite($fp, $bytes); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        $d = preg_replace('/\.html$/', '.d', $latest);
        if (is_dir($d)) {
            foreach ($this->nestedPages($root) as $rel => $abs) { $snap = "{$d}/{$rel}"; if (is_file($snap)) { @copy($snap, $abs); } }
            $this->rmTree($d);
        }
        $this->restoreRecordSidecar($websiteId, $latest);
        @unlink($latest);
        $redos = glob($dir . '/redo-*.html') ?: [];
        sort($redos);
        foreach (array_slice($redos, 0, max(0, count($redos) - 5)) as $old) { @unlink($old); }
        $ok = @md5_file($target) === md5($bytes);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        \Illuminate\Support\Facades\Log::info('[TemplateService] undo', ['website' => $websiteId, 'from' => basename($latest), 'ok' => $ok]);
        return ['undone' => $ok, 'restored_from' => basename($latest), 'remaining' => max(0, count($entries) - 1)];
    }

    /** @return array<string,string> relative path => absolute path of every export other than index.html */
    private function nestedPages(string $root): array
    {
        $out = [];
        foreach (glob("{$root}/*/index.html") ?: [] as $abs) {
            $rel = ltrim(str_replace($root, '', $abs), '/');
            if (str_starts_with($rel, '.history/')) { continue; }
            $out[$rel] = $abs;
        }
        foreach (glob("{$root}/*.html") ?: [] as $abs) {
            $rel = basename($abs);
            if ($rel === 'index.html') { continue; }
            $out[$rel] = $abs;
        }
        return $out;
    }

    /** The sidecar beside the newest entry holds the record as it was; a record-only change (an image slot, a palette id) still deserves an entry. */
    private function recordUnchangedSince(int $websiteId, string $latestIndexFile): bool
    {
        $side = preg_replace('/\.html$/', '.json', $latestIndexFile);
        if (! is_file($side)) { return false; }
        $j = json_decode((string) @file_get_contents($side), true);
        if (! is_array($j) || ! array_key_exists('template_variables', $j)) { return false; }
        $row = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['template_variables', 'settings_json']);
        if ((string) $j['template_variables'] !== (string) ($row->template_variables ?? '')) { return false; }
        return ! array_key_exists('settings_json', $j) || (string) $j['settings_json'] === (string) ($row->settings_json ?? '');
    }

    private function nestedUnchangedSince(string $root, string $latestIndexFile): bool
    {
        $d = preg_replace('/\.html$/', '.d', $latestIndexFile);
        foreach ($this->nestedPages($root) as $rel => $abs) {
            $snap = "{$d}/{$rel}";
            if (! is_file($snap) || @md5_file($snap) !== @md5_file($abs)) { return false; }
        }
        return true;
    }

    private function pruneHistory(string $dir, int $keep = 10): void
    {
        $backups = glob($dir . '/index-*.html') ?: [];
        if (count($backups) <= $keep) { return; }
        sort($backups);
        foreach (array_slice($backups, 0, count($backups) - $keep) as $old) {
            @unlink($old);
            @unlink(preg_replace('/\.html$/', '.json', $old));
            $d = preg_replace('/\.html$/', '.d', $old);
            if (is_dir($d)) { $this->rmTree($d); }
        }
    }

    /**
     * Renderer (non-static) sites have no index.html: their palette lives in settings_json and their design in
     * template_variables. Snapshot the record itself so Undo covers a palette switch on those sites too.
     */
    private function snapshotRecordOnly(int $websiteId, string $root, string $reason): ?string
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['settings_json', 'template_variables']);
            if (! $row) { return null; }
            $dir = "{$root}/.history";
            if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (! is_dir($dir)) { return null; }
            $payload = ['settings_json' => $row->settings_json, 'template_variables' => $row->template_variables, 'saved_at' => date('c'), 'reason' => $reason];
            $existing = glob($dir . '/settings-*.json') ?: [];
            sort($existing);
            if ($existing !== []) {
                $prev = json_decode((string) @file_get_contents(end($existing)), true);
                if (is_array($prev) && ($prev['settings_json'] ?? null) === $row->settings_json && ($prev['template_variables'] ?? null) === $row->template_variables) {
                    return basename(end($existing));
                }
            }
            $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
            $file  = "{$dir}/settings-{$stamp}.json";
            if (@file_put_contents($file, json_encode($payload)) === false) { return null; }
            $all = glob($dir . '/settings-*.json') ?: [];
            if (count($all) > 10) { sort($all); foreach (array_slice($all, 0, count($all) - 10) as $old) { @unlink($old); } }
            return basename($file);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TemplateService] record snapshot failed: ' . $e->getMessage());
            return null;
        }
    }

    private function undoRecordOnly(int $websiteId, string $dir): array
    {
        $entries = glob($dir . '/settings-*.json') ?: [];
        if ($entries === []) { return ['undone' => false, 'error' => 'nothing_to_undo', 'remaining' => 0]; }
        sort($entries);
        $latest = end($entries);
        $j = json_decode((string) @file_get_contents($latest), true);
        if (! is_array($j)) { @unlink($latest); return ['undone' => false, 'error' => 'corrupt_entry', 'remaining' => max(0, count($entries) - 1)]; }
        $upd = ['updated_at' => now()];
        if (array_key_exists('settings_json', $j))      { $upd['settings_json'] = $j['settings_json']; }
        if (array_key_exists('template_variables', $j)) { $upd['template_variables'] = $j['template_variables']; }
        \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update($upd);
        @unlink($latest);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        $now = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['settings_json', 'template_variables']);
        $ok = $now && (! array_key_exists('settings_json', $j) || $now->settings_json === $j['settings_json']);
        return ['undone' => (bool) $ok, 'restored_from' => basename($latest), 'remaining' => max(0, count($entries) - 1), 'record_only' => true];
    }

    /** Put the template_variables saved beside a history entry back on the record (and remove the sidecar). */
    private function restoreRecordSidecar(int $websiteId, string $indexFile): void
    {
        $side = preg_replace('/\.html$/', '.json', $indexFile);
        if (! is_file($side)) { return; }
        try {
            $j = json_decode((string) @file_get_contents($side), true);
            if (is_array($j)) {
                $upd = [];
                if (array_key_exists('template_variables', $j) && $j['template_variables'] !== null) { $upd['template_variables'] = $j['template_variables']; }
                if (array_key_exists('settings_json', $j) && $j['settings_json'] !== null) { $upd['settings_json'] = $j['settings_json']; }
                if ($upd !== []) { $upd['updated_at'] = now(); \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update($upd); }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TemplateService] record sidecar restore failed: ' . $e->getMessage());
        }
        @unlink($side);
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = "{$dir}/{$e}";
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }

/** RISK-0107 — list a template site's pre-edit backups (newest first). */
    public function listHistory(int $websiteId): array
    {
        $dir = storage_path("app/public/sites/{$websiteId}/.history");
        if (! is_dir($dir)) { return []; }
        $out = [];
        foreach (glob($dir . '/index-*.html') ?: [] as $path) {
            $out[] = [
                'file'     => basename($path),
                'size'     => (int) (@filesize($path) ?: 0),
                'saved_at' => date('c', (int) (@filemtime($path) ?: time())),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['file'], $a['file'])); // zero-padded stamp => newest first
        return $out;
    }

    /**
     * RISK-0107 — restore a template site's served index.html from a .history backup. Backs up the
     * CURRENT served content first (so the restore is itself reversible), under LOCK_EX. The backup
     * filename is strictly validated (no path traversal).
     * @return array{restored:bool, restored_from?:string, prior_backup?:string, error?:string}
     */
    public function restoreFromHistory(int $websiteId, string $file): array
    {
        if (! preg_match('/^index-\d{8}-\d{6}-[0-9a-f]{4}\.html$/', $file)) {
            return ['restored' => false, 'error' => 'invalid_backup'];
        }
        $dir    = storage_path("app/public/sites/{$websiteId}/.history");
        $src    = $dir . '/' . $file;
        $target = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($src) || ! is_file($target)) { return ['restored' => false, 'error' => 'not_found']; }
        $restoreBytes = @file_get_contents($src);
        if ($restoreBytes === false || $restoreBytes === '') { return ['restored' => false, 'error' => 'empty_backup']; }

        $fp = @fopen($target, 'r+');
        if ($fp === false) { return ['restored' => false, 'error' => 'lock_failed']; }
        if (! flock($fp, LOCK_EX)) { fclose($fp); return ['restored' => false, 'error' => 'lock_failed']; }
        $current  = stream_get_contents($fp);
        $preStamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        if (is_string($current) && $current !== '') { @file_put_contents($dir . "/index-{$preStamp}.html", $current); }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $restoreBytes);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        // DEC-0046: nested page exports and the record saved with this version come back with it.
        $this->restoreRecordSidecar($websiteId, $src);
        $nestedDir = preg_replace('/\.html$/', '.d', $src);
        if (is_dir($nestedDir)) {
            foreach ($this->nestedPages(storage_path("app/public/sites/{$websiteId}")) as $rel => $abs) {
                if (is_file("{$nestedDir}/{$rel}")) { @copy("{$nestedDir}/{$rel}", $abs); }
            }
        }

        $backups = glob($dir . '/index-*.html') ?: [];
        if (count($backups) > 10) {
            sort($backups);
            foreach (array_slice($backups, 0, count($backups) - 10) as $old) { @unlink($old); }
        }
        return ['restored' => true, 'restored_from' => $file, 'prior_backup' => "index-{$preStamp}.html"];
    }
    /**
     * STRESS C33 (2026-09-06): 0 of 31 templates carry an <img> logo slot, so an uploaded logo never showed. Swap the
     * text inside the logo elements for the image (the text is kept on the element so an empty url restores it).
     */
    public static function applyLogoImage(string $html, string $url, string $alt, string $textFallback = ''): string
    {
        foreach (['logo', 'header_logo', 'nav_logo', 'footer_logo'] as $f) {
            $html = preg_replace_callback('/(<(\w+)\b[^>]*\bdata-field="' . $f . '"[^>]*>)(.*?)(<\/\2>)/s', function ($m) use ($url, $alt, $textFallback) {
                $open = $m[1]; $inner = $m[3];
                if ($url === '') {
                    if (!str_contains($inner, 'lu-logo-img')) return $m[0];
                    $text = preg_match('/data-lu-logo-text="([^"]*)"/', $open, $t) ? $t[1] : e($textFallback !== '' ? $textFallback : $alt);
                    return preg_replace('/\sdata-lu-logo-text="[^"]*"/', '', $open) . $text . $m[4];
                }
                $text = str_contains($inner, 'lu-logo-img') ? (preg_match('/data-lu-logo-text="([^"]*)"/', $open, $t) ? $t[1] : '') : e(trim(strip_tags($inner)));
                if (!str_contains($open, 'data-lu-logo-text=')) $open = preg_replace('/>$/', ' data-lu-logo-text="' . $text . '">', $open);
                $img = '<img class="lu-logo-img" src="' . e($url) . '" alt="' . e($alt) . '" style="height:44px;width:auto;max-width:200px;display:block;object-fit:contain">';
                return $open . $img . $m[4];
            }, $html) ?? $html;
        }
        return $html;
    }

    /**
     * RISK-0185 (2026-09-17): designs WITH an <img data-field="logo_url"> slot render it from logo_img_src and hide
     * the brand text beside it with logo_text_display. The STRESS C33 export patch above only knew the text-logo
     * shape, so on these designs an upload reached template_variables (preview) but never the export (published
     * page). Patch the slot the way render() would: src = url or the transparent 1x1, and the text element that
     * follows the slot shown only while there is no logo.
     */
    public static function applyLogoSlot(string $html, string $url): string
    {
        if (! str_contains($html, 'data-field="logo_url"')) return $html;
        $placeholder = 'data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221%22%20height%3D%221%22%2F%3E';
        $src = $url === '' ? $placeholder : e($url);
        $html = preg_replace_callback('/<img\b[^>]*\bdata-field="logo_url"[^>]*>/', function ($m) use ($src) {
            $tag = preg_replace('/\bsrc="[^"]*"/', 'src="' . $src . '"', $m[0], 1, $n);
            return $n ? $tag : preg_replace('/^<img\b/', '<img src="' . $src . '"', $m[0]);
        }, $html) ?? $html;
        $display = $url === '' ? 'display:block' : 'display:none';
        return preg_replace('/(<img\b[^>]*\bdata-field="logo_url"[^>]*>\s*<[a-z]+\b[^>]*\bstyle=")display:(?:none|block)/i', '$1' . $display, $html) ?? $html;
    }

    /** STRESS C02 (2026-09-06): rebuild the service/people <select>s from the current variables after an inline rename. */
    public function refreshServiceSelects(int $websiteId, ?array $vars = null): bool
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (!is_file($path)) return false;
        $vars = $vars ?? (json_decode((string) \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('template_variables'), true) ?: []);
        $html = (string) file_get_contents($path);
        $new = $this->fillServiceSelects($html, $vars);
        if ($new === $html) return false;
        file_put_contents($path, $new);
        return true;
    }

    /** STRESS C12 (2026-09-06): a text field edited on the home export is patched on every added page too (footer phone, nav labels…). */
    public function patchFieldInSubPages(int $websiteId, string $field, string $value): int
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $field)) return 0;
        $root = storage_path("app/public/sites/{$websiteId}"); $n = 0;
        $safe = str_contains($value, '<') ? strip_tags($value, '<br><em><strong><b><i><span>') : e($value);
        foreach (glob($root . '/*/index.html') ?: [] as $file) {
            $h = (string) file_get_contents($file);
            $new = preg_replace_callback('/(<(\w+)\b[^>]*\bdata-field="' . preg_quote($field, '/') . '"[^>]*>)(.*?)(<\/\2>)/s',
                fn($m) => preg_match('/<(?!\/?(?:br|em|strong|b|i|span)\b)/', $m[3]) ? $m[0] : $m[1] . $safe . $m[4], $h);
            if (is_string($new) && $new !== $h) { file_put_contents($file, $new); $n++; }
        }
        return $n;
    }

    /** STRESS C15 (2026-09-06): drop a page's menu link from every export (and an emptied "More" menu). */
    public function removeNavLink(int $websiteId, string $slug): int
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        $root = storage_path("app/public/sites/{$websiteId}"); $n = 0;
        if ($slug === '' || !is_dir($root)) return 0;
        foreach (array_merge([$root . '/index.html'], glob($root . '/*/index.html') ?: []) as $file) {
            if (!is_file($file)) continue;
            $h = (string) file_get_contents($file);
            $new = preg_replace('/<li[^>]*>\s*<a\b[^>]*data-page="' . preg_quote($slug, '/') . '"[^>]*>.*?<\/a>\s*<\/li>/is', '', $h);
            $new = preg_replace('/<a\b[^>]*data-page="' . preg_quote($slug, '/') . '"[^>]*>.*?<\/a>/is', '', (string) $new);
            $new = preg_replace('/(?:<li style="list-style:none">)?<div class="lu-more">(?:(?!<a\b).)*?<div class="lu-more-menu">\s*<\/div>\s*<\/div>(?:<\/li>)?/is', '', (string) $new);
            if (is_string($new) && $new !== $h) { file_put_contents($file, $new); $n++; }
        }
        return $n;
    }

    /** Remove an Arthur-added section (wrapper `<section data-block="added_{type}">`) from the home export and forget it. */
    public function removeSplicedSection(int $websiteId, string $type): bool
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (!is_file($path)) return false;
        $html = (string) file_get_contents($path);
        $new = self::removeBlock($html, 'added_' . $type);
        $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->first(['settings_json']);
        if ($w) {
            $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
            $s['arthur_sections'] = array_values(array_filter((array) ($s['arthur_sections'] ?? []), fn($x) => ($x['type'] ?? '') !== $type));
            \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($s)]);
        }
        if ($new === $html) return false;
        file_put_contents($path, $new);
        return true;
    }

    /** Balanced removal of `<section … data-block="{block}">…</section>` (nested sections respected). */
    public static function removeBlock(string $html, string $block): string
    {
        for ($guard = 0; $guard < 20; $guard++) { $once = self::removeBlockOnce($html, $block); if ($once === $html) break; $html = $once; }
        return $html;
    }

    private static function removeBlockOnce(string $html, string $block): string
    {
        if (!preg_match('/<section\b[^>]*data-block="' . preg_quote($block, '/') . '"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) return $html;
        $start = $m[0][1]; $pos = $start + strlen($m[0][0]); $depth = 1;
        while ($depth > 0 && preg_match('/<\/?section\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $pos = $t[0][1] + strlen($t[0][0]);
            $depth += str_starts_with($t[0][0], '</') ? -1 : 1;
        }
        if ($depth !== 0) return $html;
        $out = substr($html, 0, $start) . substr($html, $pos);
        return preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;
    }
    /** Law 11: template_variables are written here, never by Arthur. */
    public function saveTemplateVariables(int $websiteId, array $variables): bool
    {
        return \Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)
            ->update(['template_variables' => json_encode($variables), 'updated_at' => now()]) >= 0;
    }
}
