<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

class TemplateService
{
    /**
     * Render a template with variable substitution.
     *
     * @param string $industry  Template industry key (e.g. 'restaurant')
     * @param array  $variables Key-value pairs to substitute into the template
     * @return string Rendered HTML
     * @throws \Exception If template not found
     */
    public function render(string $industry, array $variables): string
    {
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

        foreach ($variables as $key => $value) {
            $html = str_replace(
                '{{' . $key . '}}',
                $value ?? '',
                $html
            );
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

        return $html;
    }

    /**
     * Get the manifest for a specific industry template.
     *
     * @param string $industry
     * @return array|null
     */
    public function getManifest(string $industry): ?array
    {
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

        foreach ($xpath->query("//*[@data-field='{$fieldId}']") as $el) {
            $el->textContent = $value;
            $found = true;
        }

        if ($found) {
            file_put_contents($path, $dom->saveHTML());
        }

        return $found;
    }
}
