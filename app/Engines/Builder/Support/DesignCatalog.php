<?php

namespace App\Engines\Builder\Support;

/**
 * DESIGN-PICKER-80 (2026-10-07, RFC-0030 / DEC-0090): the vocabulary of the new design system — ten styles, eight
 * layouts, twenty palettes — for the editor's design picker and for Arthur's chooser. A v3 design is a manifest with
 * design.kit = v3 whose slug ends in _{layout} ({industry}_{style}_{layout}); the older {industry}_{style} matrix rows
 * are review artefacts and never offered. Everything else is a "Classic" design.
 *
 * QA override: storage/app/v3picker.on lists workspace ids (one per line or comma separated; * = everyone) that see
 * the v3 designs before they are activated. Activation itself is the manifests' is_active flag (activate3.php).
 */
final class DesignCatalog
{
    /** style => [label, what it feels like (for Arthur and the card), swatch: paper, accent, deep] */
    public const STYLES = [
        'rouge'     => ['Rouge',     'dark, candlelit and rich; deep reds, serif display type, a theatrical evening feel', ['#1A1113', '#8C3A3F', '#F4E9E4']],
        'glass'     => ['Glass',     'light and modern; frosted glass panels over soft colour, rounded pills, calm and premium', ['#EEF1FA', '#3D4E9E', '#0D1126']],
        'editorial' => ['Editorial', 'magazine-like; crisp black type, generous white space, documentary photos with captions', ['#FFFFFF', '#9F6833', '#14171C']],
        'luxe'      => ['Luxe',      'dark and exclusive; low-key photography, brass and gold accents, refined serif type', ['#101418', '#8C702A', '#EDE6D6']],
        'craft'     => ['Craft',     'warm and handmade; natural textures, film grain, honest materials, friendly type', ['#FFFDF9', '#8E7035', '#1D1913']],
        'brutal'    => ['Brutal',    'bold and graphic; heavy type, hard edges, two-tone photos, confident and loud', ['#FFFFFF', '#B4453E', '#1B1214']],
        'soft'      => ['Soft',      'gentle and airy; pastel light, rounded shapes, morning light, reassuring', ['#F7F0F2', '#A95E6E', '#0F1524']],
        'clinic'    => ['Clinic',    'clean and trustworthy; bright surfaces, clear structure, calm teal, easy to scan', ['#FFFFFF', '#1F7A7A', '#0D1819']],
        'coastal'   => ['Coastal',   'fresh and open; sea light, horizons, cool bright colour, relaxed', ['#F2FAF8', '#2B816F', '#0F1E1C']],
        'mono'      => ['Mono',      'minimal black and white; architectural geometry and shadow, precise and quiet', ['#FFFFFF', '#1F1F1F', '#0B0B0B']],
    ];

    /** layout => [label, what the page structure does best] */
    public const LAYOUTS = [
        'cinematic' => ['Cinematic',     'a full-screen photo opening; atmosphere first'],
        'split'     => ['Split',         'half photo, half words; explains clearly from the first screen'],
        'editorial' => ['Editorial',     'story-led with large inset photos and captions'],
        'showcase'  => ['Showcase',      'a photo mosaic; for work, rooms, products or dishes worth seeing'],
        'booking'   => ['Booking-first', 'the booking or enquiry form up front; for businesses that live on appointments'],
        'story'     => ['Story-scroll',  'stacked chapters that tell the story as you scroll'],
        'grid'      => ['Grid',          'organised tiles; many services or items at a glance'],
        'minimal'   => ['Minimal',       'type-led and quiet; one image or none'],
    ];

    /** The twenty palettes the editor offers (name => [paper, accent, deep]). Mirrors LUG_PALETTES. */
    public const PALETTES = [
        'graphite' => ['#FFFFFF', '#9F6833', '#14171C'], 'ironblue' => ['#FFFFFF', '#2F6FA8', '#101720'],
        'forest' => ['#FFFFFF', '#3E6B4B', '#121A14'], 'oxblood' => ['#FFFFFF', '#8C3A3F', '#1A1113'],
        'midnight' => ['#FFFFFF', '#5B5BD6', '#0E1018'], 'sand' => ['#FFFDF9', '#8E7035', '#1D1913'],
        'slate' => ['#FFFFFF', '#4C6A7A', '#12161B'], 'plum' => ['#FFFFFF', '#6B4A87', '#171220'],
        'teal' => ['#FFFFFF', '#1F7A7A', '#0D1819'], 'clay' => ['#FFFFFF', '#B65B39', '#1C1512'],
        'navyrose' => ['#FFFFFF', '#A95E6E', '#0F1524'], 'olive' => ['#FFFFFF', '#6E7A34', '#181A0F'],
        'steelgold' => ['#FFFFFF', '#8C702A', '#101418'], 'indigo' => ['#FFFFFF', '#3D4E9E', '#0D1126'],
        'copper' => ['#FFFFFF', '#9E5B2E', '#191310'], 'mono' => ['#FFFFFF', '#1F1F1F', '#0B0B0B'],
        'seafoam' => ['#FFFFFF', '#2B816F', '#0F1E1C'], 'ember' => ['#FFFFFF', '#B4453E', '#1B1214'],
        'stonewash' => ['#FFFFFF', '#6A7280', '#14161A'], 'marine' => ['#FFFFFF', '#16607F', '#0B1620'],
    ];

    /** Is this manifest a pickable v3 design ({industry}_{style}_{layout}, kit v3)? */
    public static function isV3(string $slug, array $manifest): bool
    {
        if (($manifest['design']['kit'] ?? '') !== 'v3') return false;
        $layout = (string) ($manifest['layout'] ?? '');
        return $layout !== '' && isset(self::LAYOUTS[$layout]) && str_ends_with($slug, '_' . $layout);
    }

    /** A v3 review artefact ({industry}_{style}, kit v3) — never offered to anyone. */
    public static function isV3Artefact(string $slug, array $manifest): bool
    {
        return ($manifest['design']['kit'] ?? '') === 'v3' && ! self::isV3($slug, $manifest);
    }

    /** May this workspace see v3 designs that are not activated yet (QA override)? */
    public static function previewFor(?int $workspaceId): bool
    {
        static $ids = null;
        if ($ids === null) {
            $f = storage_path('app/v3picker.on');
            $ids = is_file($f) ? array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) @file_get_contents($f))))) : [];
        }
        if ($ids === []) return false;
        if (in_array('*', $ids, true)) return true;
        return $workspaceId !== null && in_array((string) $workspaceId, $ids, true);
    }

    /** The extra fields a design card carries. */
    public static function fieldsFor(string $slug, array $manifest): array
    {
        $v3 = self::isV3($slug, $manifest);
        $style = $v3 ? (string) ($manifest['style'] ?? '') : '';
        $layout = $v3 ? (string) ($manifest['layout'] ?? '') : '';
        return [
            'kind'           => $v3 ? 'v3' : 'classic',
            'style'          => $style !== '' ? $style : null,
            'style_label'    => $style !== '' ? (self::STYLES[$style][0] ?? ucfirst($style)) : 'Classic',
            'layout'         => $layout !== '' ? $layout : null,
            'layout_label'   => $layout !== '' ? (self::LAYOUTS[$layout][0] ?? ucfirst($layout)) : null,
            'palette_scheme' => (string) ($manifest['palette_scheme'] ?? ($manifest['design']['mood'] ?? 'light')) === 'dark' ? 'dark' : 'light',
            'pack'           => $v3 ? (string) ($manifest['design']['pack'] ?? ($manifest['industry'] ?? '')) : null,
            'palette'        => $v3 ? (string) ($manifest['design']['palette'] ?? '') : null,
            'design_version' => (string) ($manifest['design_version'] ?? ''),
        ];
    }

    /** The vocabulary for the picker UI. */
    public static function vocabulary(): array
    {
        $styles = []; foreach (self::STYLES as $k => $s) $styles[] = ['key' => $k, 'label' => $s[0], 'feel' => $s[1], 'swatch' => $s[2]];
        $layouts = []; foreach (self::LAYOUTS as $k => $l) $layouts[] = ['key' => $k, 'label' => $l[0], 'does' => $l[1]];
        $pals = []; foreach (self::PALETTES as $k => $p) $pals[] = ['key' => $k, 'label' => ucfirst($k), 'paper' => $p[0], 'accent' => $p[1], 'deep' => $p[2]];
        return ['styles' => $styles, 'layouts' => $layouts, 'palettes' => $pals];
    }

    /** Arthur's catalogue cache follows activation: touch this stamp (activate3.php --go) and the cache key moves. */
    public static function stamp(): string
    {
        $f = storage_path('app/designs-activated.stamp');
        return (string) (@filemtime($f) ?: 0);
    }
}
