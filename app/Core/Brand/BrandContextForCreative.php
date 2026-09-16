<?php

namespace App\Core\Brand;

/**
 * BrandContextForCreative — the ONE brand-context mapping every creative path reads
 * (RFC-0009 P3, 2026-09-16).
 *
 * Until now the image path (ImageIntelligenceService::resolveContext) built this array
 * inline from the canonical WorkspaceBrandKitResolver (ADR-0014), while the video path
 * (BlueprintService::getVideoBlueprint) read CimsService / creative_brand_identities and,
 * for a workspace with no row, fell back to an invented "professional / professional".
 * EV-1054 showed the same workspace receiving two brand voices. This class is the
 * previous inline block, moved, so both paths call the same lines.
 *
 * Precedence (per field, non-destructive): explicit request override > workspace kit
 * (branded workspaces only; a neutral kit never leaks its default greys) > absent.
 * Read-only: nothing here mutates a kit. Never carries identity/tenancy fields.
 */
final class BrandContextForCreative
{
    public const FIELDS = ['brand_name', 'colors', 'heading_font', 'body_font', 'logo_url', 'visual_style', 'voice', 'tone'];

    /**
     * @param  array<string,mixed> $overrides  explicit request-level brand fields (kit keys)
     * @return array<string,mixed>            filtered: only non-empty fields are present
     */
    public static function fromWorkspace(int $wsId, array $overrides = []): array
    {
        try {
            $kit     = app(WorkspaceBrandKitResolver::class)->resolve($wsId);
            $branded = empty($kit['is_neutral']);
            if (! $branded && ! $overrides) {
                return [];
            }
            $val = function (string $kitKey) use ($kit, $overrides, $branded) {
                if (array_key_exists($kitKey, $overrides)) return $overrides[$kitKey];
                return $branded ? ($kit[$kitKey] ?? null) : null;
            };
            $colors = array_values(array_filter([$val('primary_color'), $val('secondary_color'), $val('accent_color')]));
            return array_filter([
                'brand_name'   => $val('brand_name'),
                'colors'       => $colors,
                'heading_font' => $val('heading_font'),
                'body_font'    => $val('body_font'),
                'logo_url'     => $val('logo_url'),
                'visual_style' => $val('visual_style'),
                'voice'        => $val('voice'),
                'tone'         => $val('tone'),
            ]);
        } catch (\Throwable $e) {
            return []; // neutral brand — identical to the previous inline behaviour
        }
    }

    /**
     * The same facts as one line of prose, for prompts that take a string (the video
     * scene planner). Deterministic field order; nothing invented for absent fields.
     */
    public static function toProse(array $brand): string
    {
        $parts = [];
        if (! empty($brand['brand_name']))   { $parts[] = 'Brand: ' . $brand['brand_name']; }
        if (! empty($brand['voice']))        { $parts[] = 'Brand voice: ' . $brand['voice']; }
        if (! empty($brand['tone']))         { $parts[] = 'Tone: ' . $brand['tone']; }
        if (! empty($brand['visual_style'])) { $parts[] = 'Visual style: ' . $brand['visual_style']; }
        if (! empty($brand['colors']))       { $parts[] = 'Brand colors: ' . implode(', ', array_slice((array) $brand['colors'], 0, 3)); }
        $fonts = array_values(array_filter([$brand['heading_font'] ?? null, $brand['body_font'] ?? null]));
        if ($fonts)                          { $parts[] = 'Fonts: ' . implode(', ', $fonts); }
        $parts[] = 'Logo asset: ' . (! empty($brand['logo_url']) ? 'available' : 'none — do not depict a logo');
        return implode('. ', $parts) . '.';
    }
}
