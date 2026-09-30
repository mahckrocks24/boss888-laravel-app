<?php

namespace App\Core\Brand;

/**
 * BRAND-B1 (RFC-0017 section 5d): the ten design directions a business picks 3-4 from.
 *
 * A direction is a style tier plus a recipe: type pairing, colour use, background treatment, allowed effects,
 * forbidden elements, photo grade and video style. The owner's picks steer every image, banner and video; the
 * image reasoning receives the chosen direction's recipe as art direction, and its forbidden list as hard limits.
 * Sources: the Owner's reference set (5c) for D1, and the September 2026 trend reports (Adobe Express, Kittl,
 * Envato Elements, Superside, ManyPixels) for the rest.
 */
final class DesignDirections
{
    public const ALL = [
        'D1' => [
            'name' => 'Editorial luxury',
            'blurb' => 'Dark, calm and premium. Big confident headline, one gold-like accent, lots of space.',
            'recipe' => 'Photo with a dark directional fade on the text side; condensed bold capitals or a refined serif for the headline; one metallic or deep accent colour on the payoff word only; a short thin rule; generous margins; logo small at the bottom.',
            'grade' => 'rich contrast, deep shadows, golden-hour or moody interior light',
            'composition' => 'subject in the right third, calm negative space on the left for the headline',
            'forbids' => ['buttons', 'badges', 'stickers', 'glow on text', 'a second accent colour', 'emoji'],
            'video' => 'slow push-ins, fades to black, thin serif or condensed captions, gold accent on key words',
            'suits' => ['architect', 'real estate', 'realty', 'property', 'broker', 'fine dining', 'private chef', 'chef', 'law', 'legal', 'jewel', 'hotel', 'resort', 'interior', 'luxury', 'wealth', 'consult'],
            'preview' => ['ground' => 'dark_fade', 'font' => "'Bebas Neue','Oswald',Impact,sans-serif", 'case' => 'upper', 'text' => 'light'],
        ],
        'D2' => [
            'name' => 'Clean minimal',
            'blurb' => 'Light, tidy and trustworthy. Lots of white space, one brand colour, simple sans type.',
            'recipe' => 'Light or neutral ground; strict grid; one brand colour for the headline mark or a thin bar; clean sans-serif throughout; small text call to action; product or subject isolated on a plain background.',
            'grade' => 'bright, soft daylight, low clutter, true colours',
            'composition' => 'centred or grid-aligned subject with wide clean margins',
            'forbids' => ['texture', 'glow', 'collage', 'heavy gradients', 'stickers'],
            'video' => 'crisp cuts, white frames, sans captions, brand-colour underline',
            'suits' => ['tech', 'software', 'saas', 'it ', 'it services', 'clinic', 'dental', 'medical', 'doctor', 'finance', 'account', 'insurance', 'consult', 'agency', 'online courses'],
            'preview' => ['ground' => 'light', 'font' => "'Inter','Helvetica Neue',Arial,sans-serif", 'case' => 'none', 'text' => 'dark'],
        ],
        'D3' => [
            'name' => 'Bold colour block',
            'blurb' => 'Loud and punchy. Your main colour fills the frame with oversized type and a clear offer.',
            'recipe' => 'One saturated brand colour fills most of the frame; oversized heavy type; a cut-out subject overlapping the colour; a pill-shaped call to action; a percentage or price badge only when the request states one.',
            'grade' => 'high saturation, hard clean light, punchy contrast',
            'composition' => 'cut-out subject large and cropped, plain colour field behind',
            'forbids' => ['muted grades', 'thin type', 'busy photographic backgrounds'],
            'video' => 'fast cuts on the beat, colour-block wipes, heavy captions',
            'suits' => ['retail', 'shop', 'store', 'gym', 'fitness', 'delivery', 'fast food', 'promo', 'sale', 'event', 'pet', 'ecommerce', 'automotive'],
            'preview' => ['ground' => 'brand_block', 'font' => "'Anton','Archivo Black',Impact,sans-serif", 'case' => 'upper', 'text' => 'light'],
        ],
        'D4' => [
            'name' => 'Warm organic',
            'blurb' => 'Handmade and cosy. Earthy colours, paper texture, soft light and a friendly serif.',
            'recipe' => 'Earthy palette; paper or fine grain texture; a warm serif or hand-lettered accent word; soft window light; small hand-drawn marks such as underlines or leaves.',
            'grade' => 'warm, soft natural window light, gentle shadows',
            'composition' => 'close, tactile still life or hands at work, room for text on a calm area',
            'forbids' => ['neon', 'chrome', 'hard gradients', 'glow'],
            'video' => 'handheld close-ups, warm grade, serif captions',
            'suits' => ['bakery', 'cafe', 'coffee', 'wellness', 'spa', 'artisan', 'farm', 'florist', 'organic', 'catering', 'restaurant', 'childcare', 'tutoring'],
            'preview' => ['ground' => 'paper', 'font' => "'Fraunces','Georgia',serif", 'case' => 'none', 'text' => 'dark'],
        ],
        'D5' => [
            'name' => 'Authentic photo-first',
            'blurb' => 'Real moments do the talking. A candid photo with one short line and your logo.',
            'recipe' => 'A candid, documentary-style photo is the design; one short text strip or corner label; handle and logo small; no heavy overlays.',
            'grade' => 'natural, true-to-life, slightly warm, real environments',
            'composition' => 'candid moment, people or work in progress, rule of thirds, a quiet corner for the label',
            'forbids' => ['heavy overlays', 'stock-looking staged scenes', 'big headlines over faces'],
            'video' => 'behind-the-scenes clips, natural sound, small lower-third labels',
            'suits' => ['hospitality', 'salon', 'barber', 'community', 'travel', 'trades', 'home services', 'construction', 'joinery', 'carpentry', 'photography', 'restaurant', 'events'],
            'preview' => ['ground' => 'photo', 'font' => "'DM Sans','Inter',sans-serif", 'case' => 'none', 'text' => 'light'],
        ],
        'D6' => [
            'name' => 'Soft cinematic',
            'blurb' => 'Dreamy and elegant. Soft focus, fine grain, gentle glow and thin graceful type.',
            'recipe' => 'Soft focus and fine film grain; blurred colour gradients from the brand palette; a gentle backlight glow on the subject; thin elegant type with wide letter-spacing.',
            'grade' => 'soft, hazy, pastel-warm, low contrast, backlit',
            'composition' => 'subject softly lit from behind, blurred foreground layers, airy space',
            'forbids' => ['hard shadows', 'stickers', 'heavy type', 'badges'],
            'video' => 'slow motion, soft dissolves, thin spaced captions',
            'suits' => ['beauty', 'aesthetic', 'spa', 'fashion', 'music', 'wedding', 'bridal', 'photography', 'portrait', 'perfume'],
            'preview' => ['ground' => 'blur', 'font' => "'Cormorant Garamond','Playfair Display',serif", 'case' => 'none', 'text' => 'light'],
        ],
        'D7' => [
            'name' => 'Neon night',
            'blurb' => 'High energy after dark. Glowing type, deep backgrounds and electric colour.',
            'recipe' => 'Dark ground; neon glow on type and simple shapes in the brand accent; saturated gradient light; speed lines or light trails; strong contrast.',
            'grade' => 'night, deep blacks, saturated coloured light, rim light',
            'composition' => 'subject lit by coloured light against darkness, dynamic diagonal',
            'forbids' => ['pastels', 'paper texture', 'thin serif type'],
            'video' => 'quick cuts, light flashes, glowing captions, bass-driven pacing',
            'suits' => ['nightlife', 'bar', 'club', 'gaming', 'esports', 'gym', 'fitness', 'concert', 'dj', 'event'],
            'preview' => ['ground' => 'neon', 'font' => "'Bebas Neue','Oswald',sans-serif", 'case' => 'upper', 'text' => 'glow'],
        ],
        'D8' => [
            'name' => 'Layered collage',
            'blurb' => 'Playful storytelling. Cut-outs, torn paper, tape and mixed type like a magazine zine.',
            'recipe' => 'Photo cut-outs, torn paper edges, tape and sticker shapes; mixed type sizes; zine-like layout with controlled overlap; brand colours as paper scraps.',
            'grade' => 'bright, flash-lit, slightly gritty print feel',
            'composition' => 'several cut-out elements arranged with overlap, one hero element',
            'forbids' => ['strict symmetry', 'corporate stock look'],
            'video' => 'stop-motion style cuts, sticker pops, mixed captions',
            'suits' => ['fashion', 'creator', 'youth', 'school', 'festival', 'streetwear', 'music', 'marketing', 'graphic design', 'design'],
            'preview' => ['ground' => 'collage', 'font' => "'Archivo Black','Anton',sans-serif", 'case' => 'upper', 'text' => 'dark'],
        ],
        'D9' => [
            'name' => 'Playful retro',
            'blurb' => 'Fun and friendly. Rounded bubbly type, soft pastels with one bright pop.',
            'recipe' => 'Bubbly or rounded type; pastel ground with one bright pop colour; chrome or iridescent accents; rounded shapes with bold outlines (soft neo-brutal).',
            'grade' => 'bright, clean, candy-coloured, even light',
            'composition' => 'product or character centred, playful shapes around it',
            'forbids' => ['dark luxury grades', 'thin serif type'],
            'video' => 'bouncy transitions, rounded captions, playful sound',
            'suits' => ['kids', 'childcare', 'toy', 'dessert', 'ice cream', 'candy', 'drink', 'beverage', 'app', 'pet', 'party'],
            'preview' => ['ground' => 'pastel', 'font' => "'Baloo 2','Nunito',sans-serif", 'case' => 'none', 'text' => 'dark'],
        ],
        'D10' => [
            'name' => 'Technical blueprint',
            'blurb' => 'Smart and precise. Grids, line drawings, one big number and a single signal colour.',
            'recipe' => 'Grid lines and technical line drawings; annotations and arrows; one large statistic when the request states one; monospace labels; one signal colour on a dark or paper ground.',
            'grade' => 'crisp, neutral, high clarity, cool',
            'composition' => 'subject shown as a clean object or structure with space for annotations',
            'forbids' => ['script type', 'stickers', 'glow on text'],
            'video' => 'line-draw reveals, measured pacing, monospace captions',
            'suits' => ['engineer', 'construction', 'b2b', 'manufactur', 'it services', 'data', 'architect', 'logistics', 'energy', 'solar', 'finance', 'technology'],
            'preview' => ['ground' => 'grid', 'font' => "'Space Grotesk','Space Mono',monospace", 'case' => 'none', 'text' => 'light'],
        ],
    ];

    /** Content signals in a request that favour particular directions (used to choose among the owner's picks). */
    private const AFFINITY = [
        '/\b(offer|sale|discount|promo|% ?off|deal|price|limited)\b/i' => ['D3', 'D9', 'D7'],
        '/\b(quote|fact|did you know|why|tip|insight|statistic|stat)\b/i' => ['D1', 'D10', 'D2'],
        '/\b(behind the scenes|team|our people|staff|day in|making of|process)\b/i' => ['D5', 'D4', 'D8'],
        '/\b(event|party|night|concert|launch party|tickets?)\b/i' => ['D7', 'D3', 'D8'],
        '/\b(new|launch|announce|introducing|coming soon)\b/i' => ['D1', 'D3', 'D2'],
        '/\b(holiday|christmas|eid|ramadan|diwali|new year|mother|father|valentine|greeting)\b/i' => ['D1', 'D6', 'D4'],
        '/\b(testimonial|review|client said|feedback)\b/i' => ['D2', 'D1', 'D5'],
        '/\b(recipe|menu|dish|food|bake|coffee)\b/i' => ['D4', 'D5', 'D1'],
    ];

    public static function ids(): array { return array_keys(self::ALL); }

    public static function get(string $id): ?array { $id = strtoupper(trim($id)); return isset(self::ALL[$id]) ? ['id' => $id] + self::ALL[$id] : null; }

    /** Only valid, distinct direction ids, in order. */
    public static function clean(array $ids, int $max = 10): array
    {
        $out = [];
        foreach ($ids as $i) { $i = strtoupper(trim((string) $i)); if (isset(self::ALL[$i]) && ! in_array($i, $out, true)) $out[] = $i; }
        return array_slice($out, 0, $max);
    }

    /** All ten, ordered by fit to the industry (stable: ties keep D1..D10 order). */
    public static function rankForIndustry(?string $industry): array
    {
        $ind = ' ' . strtolower((string) $industry) . ' ';
        $scores = [];
        $n = 0;
        foreach (self::ALL as $id => $d) {
            $s = 0;
            foreach ($d['suits'] as $kw) { if ($kw !== '' && str_contains($ind, strtolower($kw))) $s += 2; }
            $scores[$id] = $s * 100 - $n++;
        }
        arsort($scores);
        return array_keys($scores);
    }

    /**
     * The direction for one request: the owner's picks (or, while none are chosen, the top 3 for the industry,
     * provisionally), re-ranked by what the request is about. "Never" directions are excluded everywhere.
     */
    public static function choose(array $picks, array $never, ?string $industry, string $request): ?array
    {
        $never = self::clean($never);
        $__picked = self::clean($picks);
        if (! $__picked) {
            // DIRECTION-DEFAULT-1: provisional pool = the industry's top three, minus the playful directions unless the
            // industry is playful; never empty (editorial luxury, photo-first, clean minimal are the safe defaults)
            $__playful = (bool) preg_match('/\b(kids?|children|toy|party|parties|club|bar|pub|gaming|games?|street|skate|festival|youth|teen|fun|candy|ice cream|arcade|comic|zine|music|dj)\b/i', (string) $industry);
            $__ranked = array_values(array_diff(self::rankForIndustry($industry), $never, $__playful ? [] : ['D7', 'D8', 'D9']));
            $pool = array_slice($__ranked, 0, 3) ?: array_values(array_diff(['D1', 'D5', 'D2'], $never));
        } else {
            $pool = array_values(array_diff($__picked, $never));
        }
        if (! $pool) return null;
        $best = $pool[0]; $bestScore = -1;
        foreach ($pool as $rank => $id) {
            $score = (count($pool) - $rank);                 // the owner's order counts
            foreach (self::AFFINITY as $re => $pref) {
                if (preg_match($re, $request)) { $pos = array_search($id, $pref, true); if ($pos !== false) $score += (3 - $pos) * 2; }
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $id; }
        }
        return self::get($best) + ['provisional' => ! self::clean($picks)];
    }

    /** Art direction for the image reasoning (one block of prose; the forbidden list is a hard limit). */
    public static function promptBlock(array $d): string
    {
        return $d['name'] . ' (' . $d['id'] . '). ' . $d['recipe'] . ' Photo grade: ' . $d['grade'] . '. Composition: ' . $d['composition']
            . '. Never use: ' . implode(', ', $d['forbids']) . '.';
    }

    /** For the card and the settings screen: id, name, blurb, preview hints. */
    public static function catalogue(?string $industry = null): array
    {
        $order = $industry !== null ? self::rankForIndustry($industry) : self::ids();
        return array_map(fn ($id) => ['id' => $id, 'name' => self::ALL[$id]['name'], 'blurb' => self::ALL[$id]['blurb'], 'preview' => self::ALL[$id]['preview']], $order);
    }
}
