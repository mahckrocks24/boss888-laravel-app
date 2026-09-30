<?php

namespace App\Core\Brand;

/**
 * RECIPE-1 (Owner 2026-10-01: "once 3 out of 10 image inspiration is chosen, Sarah or our engine can prompt dall-e
 * appropriately, without us even using our renderer. Just be strict with the prompt in terms of fidelity and text
 * accuracy and quality"). Fills a recipe's prompt template from the business's brand kit, the copy Sarah wrote and the
 * variables the customer chose. Text elements are kept to what the image model paints reliably: the headline and the
 * brand name always, the subhead only when there is one, small text never.
 */
final class RecipePromptCompiler
{
    private const SERIFS = ['playfair', 'cormorant', 'georgia', 'lora', 'marcellus', 'fraunces', 'garamond', 'baskerville', 'bodoni', 'didot', 'merriweather', 'times', 'serif'];

    /** @return array{prompt:string, text_list:array<string>, size:string, format:string} */
    public static function fill(object $recipe, array $brand, array $copy, array $vars, ?string $format = null): array
    {
        $rj = json_decode((string) $recipe->recipe_json, true) ?: [];
        $colour = json_decode((string) $recipe->colour_json, true) ?: [];
        $format = $format ?: (string) $recipe->format;
        $tpl = (string) $recipe->prompt_template;

        // colours: the recipe says whether its ground is light or dark; the brand supplies the actual values
        $primary = self::hex($brand['colors']['primary'] ?? $brand['primary_color'] ?? null, '#1F2937');
        $accent  = self::hex($brand['colors']['accent'] ?? $brand['colors']['secondary'] ?? $brand['secondary_color'] ?? $brand['accent_color'] ?? null, '#C9943A');
        $recipeGroundDark = self::lum(self::hex($colour['ground'] ?? null, '#111111')) < 0.5;
        $ground = $recipeGroundDark ? (self::lum($primary) < 0.35 ? $primary : '#111111') : (self::lum($primary) > 0.75 ? $primary : '#F7F5F2');
        $text   = self::lum($ground) < 0.5 ? '#FFFFFF' : '#111111';
        if (self::lum($accent) < 0.5 === self::lum($ground) < 0.5 && abs(self::lum($accent) - self::lum($ground)) < 0.25) $accent = self::lum($ground) < 0.5 ? '#F2C94C' : $primary;   // an accent that would vanish on the ground

        // font feel from the brand heading font
        $hf = trim((string) ($brand['heading_font'] ?? '')); $hfLow = strtolower($hf);
        $feel = $hf === '' ? (string) ((($rj['typography'] ?? [])['headline'] ?? [])['family_feel'] ?? 'a clean geometric sans')
            : (array_filter(self::SERIFS, fn ($s) => str_contains($hfLow, $s)) ? "a high-contrast serif in the spirit of {$hf}" : "a clean sans-serif in the spirit of {$hf}");

        $headline = trim((string) ($copy['headline'] ?? ''));
        $subhead  = trim((string) ($copy['subhead'] ?? ''));
        $brandName = trim((string) ($brand['brand_name'] ?? 'the business'));
        $hasSub = $subhead !== '' && ! empty((($rj['typography'] ?? [])['subhead'] ?? [])['present']);

        // small text never; subhead only when written: drop their sentences from the template and the text list
        if (! $hasSub) $tpl = preg_replace('/[^.]*\{SUBHEAD\}[^.]*\.\s*/u', '', $tpl);
        $tpl = preg_replace('/[^.]*\{SMALL_TEXT\}[^.]*\.\s*/u', '', $tpl);
        $tpl = preg_replace('/,?\s*small text "\{SMALL_TEXT\}"/u', '', $tpl);
        $tpl = preg_replace('/,?\s*subhead "\{SUBHEAD\}"/u', $hasSub ? ', subhead "{SUBHEAD}"' : '', $tpl);

        $sizePx = $format === 'square' ? '1024x1024' : '1024x1536';
        $vals = [
            '{BRAND_NAME}' => $brandName, '{HEADLINE}' => $headline, '{SUBHEAD}' => $subhead, '{SMALL_TEXT}' => '',
            '{PRIMARY_HEX}' => $primary, '{ACCENT_HEX}' => $accent, '{GROUND_HEX}' => $ground, '{TEXT_HEX}' => $text,
            '{HEADING_FONT_FEEL}' => $feel,
            '{FORMAT}' => ['square' => 'a square 1:1 social post', 'portrait_4_5' => 'a portrait 4:5 social post', 'story_9_16' => 'a vertical 9:16 story'][$format] ?? 'a portrait 4:5 social post',
            '{FORMAT_PX}' => $sizePx,
        ];
        foreach ($vars as $k => $v) { if ($k !== '' && trim((string) $v) !== '') $vals['{' . strtoupper($k) . '}'] = trim((string) $v); }
        $out = strtr($tpl, $vals);
        $out = (string) preg_replace_callback('/\{([A-Z_0-9]+)\}/', fn ($m) => str_starts_with($m[1], 'PERSON') ? 'a person who fits the business\'s customers' : (str_starts_with($m[1], 'SETTING') ? 'a fitting place for this business' : (str_starts_with($m[1], 'PRODUCT') ? 'the business\'s own product' : 'it')), $out);
        $textList = array_values(array_filter([$headline, $hasSub ? $subhead : '', $brandName]));
        // the brand kit's own hard rules, in the customer's words
        $rules = array_values(array_filter(array_map('strval', (array) ($brand['brand_rules'] ?? []))));
        if ($rules) $out .= ' Brand rules that always apply: ' . implode('; ', array_slice($rules, 0, 6)) . '.';
        return ['prompt' => trim((string) preg_replace('/\s{2,}/', ' ', $out)), 'text_list' => $textList, 'size' => $sizePx, 'format' => $format];
    }

    public static function hex(?string $h, string $default): string { $h = trim((string) $h); return preg_match('/^#[0-9a-f]{6}$/i', $h) ? strtoupper($h) : $default; }
    public static function lum(string $hex): float { $n = hexdec(ltrim($hex, '#')); $r = ($n >> 16 & 255) / 255; $g = ($n >> 8 & 255) / 255; $b = ($n & 255) / 255; return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; }
}
