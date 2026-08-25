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

        $cs = DB::table('chatbot_settings')->where('workspace_id', $wsId)->first();
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
     * Deploy rendered HTML to the sites directory.
     *
     * @param int    $websiteId
     * @param string $html
     * @return string Path to the deployed file
     */
    public function deploy(int $websiteId, string $html): string
    {
        $dir = storage_path("app/public/sites/{$websiteId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/index.html';
        file_put_contents($path, $html);

        return $path;
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

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML(
            file_get_contents($path),
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

        $applyImg = function (\DOMElement $el, string $value) {
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
                    "background-image:url('" . $value . "')",
                    $el->getAttribute('style')
                ));
                $done = true;
            }
            return $done;
        };

        foreach ($xpath->query("//*[@data-field='{$fieldId}']") as $el) {
            if ($isImg) {
                if ($applyImg($el, $value)) $found = true;
                else { $el->textContent = $value; $found = true; } // fallback (e.g. alt/text logo)
            } else {
                $el->textContent = $value;
                $found = true;
            }
        }

        if ($found) {
            file_put_contents($path, $dom->saveHTML());
        }

        return $found;
    }
}
