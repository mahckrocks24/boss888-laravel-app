<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0185 (2026-09-17) — the logo contract, in one place.
 *
 * `logo_url` is the ONE logo-image field: the renderer derives logo_img_src / logo_text_display from it, designs with
 * an <img data-field="logo_url"> slot show it directly, and designs whose logo is text get the picture drawn inside the
 * textual logo element by TemplateService::applyLogoImage (the text is kept on the element and comes back when the
 * url is cleared). `logo`, `header_logo`, `nav_logo` and `footer_logo` are TEXT — the brand name — and the preview's
 * click-on-logo door on those elements exists so a customer can ADD a logo image, which must land in logo_url.
 *
 * An image path written into a text field is printed on the page as copy. That is what EV-1056 saw on the live
 * header of site 900 ("/storage/crops/logo-….png" where the brand name was). This class lets the fields route tell
 * an image value from copy and route it to the field that can draw it.
 */
final class LogoFieldSemantics
{
    /** Textual brand-logo fields — exactly the set applyLogoImage renders into. */
    public const TEXTUAL_BRAND_LOGO_FIELDS = ['logo', 'header_logo', 'nav_logo', 'footer_logo'];

    /** The canonical logo-image field. */
    public const LOGO_IMAGE_FIELD = 'logo_url';

    public static function isTextualBrandLogo(string $field): bool
    {
        return in_array($field, self::TEXTUAL_BRAND_LOGO_FIELDS, true);
    }

    /**
     * Does this value look like an image reference rather than copy? A data: image, or a URL / root-relative path
     * ending in an image extension, or anything under /storage/ (uploads, crops, sites, ai-images). Copy never is.
     */
    public static function looksLikeImage(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || preg_match('/\s/', $v)) return false;
        if (str_starts_with($v, 'data:image/')) return true;
        if (! preg_match('#^(https?://|/)#i', $v)) return false;
        if (preg_match('#\.(png|jpe?g|webp|svg|gif|avif|bmp|ico)(\?[^\s]*)?$#i', $v)) return true;
        return (bool) preg_match('#(^|https?://[^/]+)/storage/#i', $v);
    }

    /**
     * Is this field image-typed for this website's design? By name (the rule TemplateService::updateField patches
     * src / background-image for, plus _photo / _avatar which the preview treats as pictures) or by the design's
     * manifest (image_dimensions key, or variables[field].type === 'image'). Text-typed by default.
     */
    public static function isImageField(int $websiteId, string $field): bool
    {
        if ($field === self::LOGO_IMAGE_FIELD) return true;
        if (str_ends_with($field, '_image') || str_ends_with($field, '_photo') || str_ends_with($field, '_avatar')
            || str_contains($field, 'image_') || str_contains($field, '_img')) return true;
        $manifest = self::manifestFor($websiteId);
        if (! $manifest) return false;
        if (isset($manifest['image_dimensions'][$field])) return true;
        $type = $manifest['variables'][$field]['type'] ?? null;
        return $type === 'image';
    }

    /** The design in use wins (settings.template after a layout switch), else template_industry — as the preview does. */
    public static function manifestFor(int $websiteId): ?array
    {
        $w = DB::table('websites')->where('id', $websiteId)->first(['template_industry', 'settings_json']);
        if (! $w) return null;
        $st = json_decode((string) ($w->settings_json ?? '{}'), true) ?: [];
        $slug = (string) ($st['template'] ?? '');
        if ($slug === '' || ! is_file(storage_path('templates/' . preg_replace('/[^a-z0-9_\-]/i', '', $slug) . '/manifest.json'))) {
            $slug = (string) ($w->template_industry ?? '');
        }
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', $slug);
        if ($slug === '') return null;
        $path = storage_path('templates/' . $slug . '/manifest.json');
        if (! is_file($path)) return null;
        return json_decode((string) file_get_contents($path), true) ?: null;
    }
}
