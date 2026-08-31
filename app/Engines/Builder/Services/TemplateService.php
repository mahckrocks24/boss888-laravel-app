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
    public function render(string $industry, array $variables, ?int $websiteId = null): string
    {
        $industry = preg_replace('/[^a-z0-9_]/', '', strtolower($industry)); // slug-guard (path traversal)
        $path = storage_path("templates/{$industry}/template.html");
        if (!file_exists($path)) {
            throw new \Exception("Template not found: {$industry}");
        }

        $html = file_get_contents($path);

        // BUG 2 FIX — resolve the industry's default hero image URL so empty
        // image variables can fall back to it instead of rendering as hollow
        // <img src=""> tags or `background-image:url()`. We try the requested
        // industry, then "default", and finally the built-in path.
        $industryDefaultImg = null;
        try {
            $row = DB::table('builder_default_assets')
                ->where('asset_type', 'hero')->where('industry', $industry)->first();
            if (!$row) {
                $row = DB::table('builder_default_assets')
                    ->where('asset_type', 'hero')->where('industry', 'default')->first();
            }
            if ($row && !empty($row->url)) $industryDefaultImg = $row->url;
        } catch (\Throwable $e) { /* table may not exist in some envs */ }
        if (!$industryDefaultImg) {
            $industryDefaultImg = '/storage/builder-heroes/' . $industry . '.jpg';
        }

        // Walk the manifest so image-typed variables that arrived empty get
        // the industry default before substitution. This way CSS like
        // `background-image:url({{hero_image}})` never lands as url().
        $manifestPath = storage_path("templates/{$industry}/manifest.json");
        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
            foreach (($manifest['variables'] ?? []) as $mKey => $mSpec) {
                $type = is_array($mSpec) ? ($mSpec['type'] ?? 'text') : 'text';
                if ($type !== 'image') continue;
                if ($mKey === 'logo_url') continue; // logo stays empty → text fallback
                $supplied = $variables[$mKey] ?? null;
                if ($supplied === null || $supplied === '') {
                    $variables[$mKey] = $industryDefaultImg;
                }
            }
        }

        // Logo support: empty logo_url means text fallback shows; non-empty hides text.
        if (!isset($variables['logo_url'])) {
            $variables['logo_url'] = '';
        }
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
            $safe = \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtml((string) $key, $n['value']);
            $html = str_replace('{{' . $key . '}}', $safe, $html);
        }

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

    private function stripEmptyPhantomCards(string $html): string
    {
        if (!preg_match_all('/data-field="([a-z0-9_]+)_name"[^>]*>\s*<\/div>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
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
        $html = \App\Engines\Builder\Support\ResponsiveNav::inject($html);
        file_put_contents($path, $html);

        // Also deploy a static /blog index that reuses THIS page's chrome + theme
        // so /blog shares the same top menu and colours instead of falling to the
        // bare dynamic renderer. Fail-open: never let it break the main deploy.
        try {
            $this->deployBlogIndex($websiteId, $html);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TemplateService] blog index deploy failed: ' . $e->getMessage());
        }

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
            $frag = preg_replace('/href="#"/i', 'href="/"', $frag);
            $frag = preg_replace('/href="#([a-z0-9\-]+)"/i', 'href="/#$1"', $frag);
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
    public function updateField(int $websiteId, string $fieldId, string $value): bool
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
                $el->setAttribute('style', preg_replace(
                    '/background-image\s*:\s*url\([^)]*\)/i',
                    "background-image:url('" . $cssUrl . "')",
                    $el->getAttribute('style')
                ));
                $done = true;
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
            // Best-effort: a backup failure must NEVER block the edit. Rolling last-10 under .history.
            try {
                $histDir = storage_path("app/public/sites/{$websiteId}/.history");
                if (! is_dir($histDir)) { @mkdir($histDir, 0775, true); }
                if (is_dir($histDir) && is_string($original) && $original !== '') {
                    $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
                    @file_put_contents($histDir . "/index-{$stamp}.html", $original);
                    $backups = glob($histDir . '/index-*.html') ?: [];
                    if (count($backups) > 10) {
                        sort($backups);   // zero-padded stamp -> lexicographic == chronological
                        foreach (array_slice($backups, 0, count($backups) - 10) as $old) { @unlink($old); }
                    }
                }
            } catch (\Throwable $e) {
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

        $backups = glob($dir . '/index-*.html') ?: [];
        if (count($backups) > 10) {
            sort($backups);
            foreach (array_slice($backups, 0, count($backups) - 10) as $old) { @unlink($old); }
        }
        return ['restored' => true, 'restored_from' => $file, 'prior_backup' => "index-{$preStamp}.html"];
    }
}
