<?php

namespace App\Engines\Builder\Support;

/**
 * FONTS-7 (RFC-0021 closure, 2026-10-01; Owner: "Fonts stay the design's own. Curated pairings were not built — are we able
 * to add some options?").
 *
 * The first draft keeps the typography its design was made with; a brief that names a mood or a typeface already goes
 * through DesignStyle. This adds the OPTIONS: curated pairings the owner can preview and apply from the editor (free,
 * snapshotted, Undo puts the old fonts back) and "the design's own" to go back. A pairing changes fonts only — never
 * the design's radii, eyebrows or colour treatment — unless the site was built with a style preset, which keeps its
 * shapes and swaps its faces. Every pairing loads its own real weights, so Google Fonts never refuses the request.
 *
 * Kill switch: storage/app/fonts7.on (absent = no Fonts panel, no fonts-only layer; previous behaviour).
 */
final class FontPairs
{
    public const SWITCH = 'app/fonts7.on';
    public const DESIGN = 'design';   // "the design's own": no pairing, the template's typography

    /** id => [label, note, moods, display, dw, body, bw, display_weight] — weights are the faces' real axes on Google Fonts. */
    public const PAIRS = [
        'playfair_source'   => ['label' => 'Editorial',        'note' => 'A high-contrast serif over a quiet sans',        'moods' => ['classic', 'luxury'],  'display' => 'Playfair Display',   'dw' => '500;600;700', 'body' => 'Source Sans 3', 'bw' => '400;600',     'display_weight' => '600'],
        'cormorant_inter'   => ['label' => 'Quiet luxury',     'note' => 'Fine old-style serif, clean modern body',        'moods' => ['luxury'],             'display' => 'Cormorant Garamond', 'dw' => '500;600;700', 'body' => 'Inter',         'bw' => '400;500;600', 'display_weight' => '600'],
        'dmserif_dmsans'    => ['label' => 'Modern serif',     'note' => 'One bold serif voice with a geometric body',     'moods' => ['modern', 'classic'],  'display' => 'DM Serif Display',   'dw' => '400',         'body' => 'DM Sans',       'bw' => '400;500;700', 'display_weight' => '400'],
        'fraunces_worksans' => ['label' => 'Warm and crafted', 'note' => 'A soft serif with character, for makers',        'moods' => ['playful', 'classic'], 'display' => 'Fraunces',           'dw' => '500;600;700', 'body' => 'Work Sans',     'bw' => '400;500',     'display_weight' => '600'],
        'lora_lato'         => ['label' => 'Trusted',          'note' => 'Calm serif headings, friendly body',             'moods' => ['classic'],            'display' => 'Lora',               'dw' => '500;600;700', 'body' => 'Lato',          'bw' => '400;700',     'display_weight' => '600'],
        'libre_source'      => ['label' => 'Heritage',         'note' => 'Book-weight serif with a plain sans',            'moods' => ['classic'],            'display' => 'Libre Baskerville',  'dw' => '400;700',     'body' => 'Source Sans 3', 'bw' => '400;600',     'display_weight' => '700'],
        'spacegrotesk_inter'=> ['label' => 'Studio',           'note' => 'Grotesk headings with an even body',             'moods' => ['modern'],             'display' => 'Space Grotesk',      'dw' => '500;600;700', 'body' => 'Inter',         'bw' => '400;500;600', 'display_weight' => '700'],
        'syne_worksans'     => ['label' => 'Bold',             'note' => 'Wide, confident headings',                       'moods' => ['modern'],             'display' => 'Syne',               'dw' => '600;700;800', 'body' => 'Work Sans',     'bw' => '400;500',     'display_weight' => '700'],
        'bebas_roboto'      => ['label' => 'Loud',             'note' => 'Tall condensed capitals, for energy',            'moods' => ['modern', 'playful'],  'display' => 'Bebas Neue',         'dw' => '400',         'body' => 'Roboto',        'bw' => '400;500',     'display_weight' => '400'],
        'manrope'           => ['label' => 'Soft modern',      'note' => 'One rounded sans at two weights',                'moods' => ['minimal', 'modern'],  'display' => 'Manrope',            'dw' => '600;700;800', 'body' => 'Manrope',       'bw' => '400;500',     'display_weight' => '700'],
        'inter'             => ['label' => 'Plain and clear',  'note' => 'The quietest choice: one neutral sans',          'moods' => ['minimal'],            'display' => 'Inter',              'dw' => '500;600;700', 'body' => 'Inter',         'bw' => '300;400;500', 'display_weight' => '600'],
        'raleway_lato'      => ['label' => 'Light',            'note' => 'Elegant thin headings, easy body',               'moods' => ['minimal', 'luxury'],  'display' => 'Raleway',            'dw' => '500;600;700', 'body' => 'Lato',          'bw' => '400;700',     'display_weight' => '500'],
        'poppins_opensans'  => ['label' => 'Friendly',         'note' => 'Round geometric headings, open body',            'moods' => ['playful', 'modern'],  'display' => 'Poppins',            'dw' => '500;600;700', 'body' => 'Open Sans',     'bw' => '400;600',     'display_weight' => '600'],
        'fredoka_nunito'    => ['label' => 'Rounded and fun',  'note' => 'Bubbly headings for a cheerful brand',           'moods' => ['playful'],            'display' => 'Fredoka',            'dw' => '500;600;700', 'body' => 'Nunito',        'bw' => '400;600;700', 'display_weight' => '600'],
    ];

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** @return array<string,array> */
    public static function all(): array
    {
        return self::PAIRS;
    }

    public static function find(string $id): ?array
    {
        $id = strtolower(trim($id));
        return isset(self::PAIRS[$id]) ? self::PAIRS[$id] + ['id' => $id] : null;
    }

    /** The pairing ids that suit a style mood (modern | luxury | minimal | classic | playful); three sensible ones when none. */
    public static function recommended(?string $style): array
    {
        $style = DesignStyle::normaliseStyle($style);
        $out = [];
        foreach (self::PAIRS as $id => $p) { if ($style !== null && in_array($style, $p['moods'], true)) $out[] = $id; }
        return $out !== [] ? $out : ['dmserif_dmsans', 'spacegrotesk_inter', 'fraunces_worksans'];
    }

    /** The pairing a site carries: the recorded id, 'custom' when fonts were named in words, or the design's own. */
    public static function currentFor(array $tv): string
    {
        $id = (string) ($tv['font_pair'] ?? '');
        if ($id !== '' && isset(self::PAIRS[$id])) return $id;
        return ((string) ($tv['font_display'] ?? '') !== '' || (string) ($tv['font_body'] ?? '') !== '') ? 'custom' : self::DESIGN;
    }

    /**
     * The injectable layer for a pairing on a site: fonts only when the site has no style preset (the design keeps its
     * shapes); the preset's full layer with the pairing's faces when it has one. '' when the id is unknown.
     */
    public static function layerFor(string $id, ?string $style, array $colors = []): string
    {
        $p = self::find($id);
        if ($p === null) return '';
        $style = DesignStyle::normaliseStyle($style);
        $t = DesignStyle::preset($style ?? 'modern');
        $t['display'] = $p['display']; $t['dw'] = $p['dw']; $t['body'] = $p['body']; $t['bw'] = $p['bw']; $t['display_weight'] = $p['display_weight'];
        $t['style'] = $style ?? 'fonts';
        return DesignStyle::layerFromTokens($t, $colors, $style === null);
    }

    /** One Google Fonts stylesheet that carries every pairing's faces, for the editor's panel to show them in themselves. */
    public static function previewStylesheet(): string
    {
        $fam = [];   // one entry per family, weights merged — the same family twice is refused
        foreach (self::PAIRS as $p) {
            foreach ([[$p['display'], $p['dw']], [$p['body'], $p['bw']]] as [$f, $w]) { foreach (explode(';', $w) as $x) $fam[$f][(int) $x] = true; }
        }
        $parts = [];
        foreach ($fam as $f => $ws) { $k = array_keys($ws); sort($k); $parts[] = str_replace(' ', '+', $f) . ':wght@' . implode(';', $k); }
        return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $parts) . '&display=swap';
    }
}
