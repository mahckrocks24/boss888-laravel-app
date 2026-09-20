<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Arthur AI Website Builder — smart extraction + template generation.
 * Single detailed message → builds immediately.
 * Vague message → asks follow-ups conversationally.
 */
class ArthurService
{
    private \App\Connectors\RuntimeClient $runtime;
    private TemplateService $templates;

    /* ═══════════════════ ELEMENT888 (DEC-0052, 2026-09-15) — align and size one element; shared by chat, toolbox and drag ═══════════════════ */

    /** What one [data-field] element is on the home page: tag, whether it is a button/link/image, its text. */
    public function elementInfo(int $websiteId, string $field): ?array
    {
        $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', $field);
        if ($field === '') return null;
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if (preg_match('/<(img)\b[^>]*data-field="' . preg_quote($field, '/') . '"/i', $home)) return ['field' => $field, 'tag' => 'img', 'kind' => 'image', 'text' => ''];
        if (! preg_match('/<([a-z0-9]+)\b([^>]*)data-field="' . preg_quote($field, '/') . '"[^>]*>(.*?)<\/\1>/su', $home, $m)) return null;
        $tag = strtolower($m[1]);
        $isButton = $tag === 'button' || ($tag === 'a' && preg_match('/class="[^"]*\bbtn/i', $m[2]));
        $kind = $isButton ? 'button' : ($tag === 'a' ? 'link' : (in_array($tag, ['div', 'figure', 'picture'], true) && preg_match('/<img\b/i', $m[3]) ? 'image' : 'text'));
        return ['field' => $field, 'tag' => $tag, 'kind' => $kind, 'text' => mb_substr(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[3])))), 0, 80)];
    }

    /** Align one element left / center / right — text by text-align, buttons/images/links by block + auto margins (+ grid justify-self). */
    public function alignElement(int $websiteId, string $field, string $align): array
    {
        $align = ['centre' => 'center', 'middle' => 'center'][$align] ?? $align;
        if (! in_array($align, ['left', 'center', 'right'], true)) return ['success' => false, 'message' => 'Left, centre or right?'];
        $info = $this->elementInfo($websiteId, $field);
        if ($info === null) return ['success' => false, 'message' => 'I could not find that element on the page.'];
        $tv = json_decode((string) DB::table('websites')->where('id', $websiteId)->value('template_variables'), true) ?: [];
        try { $this->templates->snapshotToHistory($websiteId, 'element_align'); } catch (\Throwable $e) {}
        $s = '[data-field="' . $info['field'] . '"]:not(.lu-x)';
        $js = ['left' => 'start', 'center' => 'center', 'right' => 'end'][$align];
        if ($info['kind'] === 'text') {
            $css = "{$s}{text-align:{$align}!important;justify-self:{$js}!important}";
        } else {
            $ml = $align === 'left' ? '0' : 'auto'; $mr = $align === 'right' ? '0' : 'auto';
            $css = "{$s}{display:block!important;width:fit-content!important;max-width:100%!important;margin-left:{$ml}!important;margin-right:{$mr}!important;justify-self:{$js}!important}";
            if ($info['kind'] === 'image') $css = "{$s}{display:block!important;margin-left:{$ml}!important;margin-right:{$mr}!important;justify-self:{$js}!important}";
        }
        if (! self::writeDesignExtras($websiteId, ['align_field_' . $info['field'] => $css], $tv)) return ['success' => false, 'message' => 'I could not write that change to the page.'];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        $label = ($info['text'] !== '' ? '"' . mb_substr($info['text'], 0, 40) . '"' : 'the ' . str_replace(['_', '-'], ' ', $info['field']));
        return ['success' => true, 'message' => 'aligned ' . $label . ' to the ' . ($align === 'center' ? 'centre' : $align)];
    }

    /** Size one element: text/buttons by zoom (60–180 %), images by width % of their column (30–100 %). dir = bigger | smaller. */
    public function sizeElement(int $websiteId, string $field, string $dir, bool $big = false): array
    {
        $info = $this->elementInfo($websiteId, $field);
        if ($info === null) return ['success' => false, 'message' => 'I could not find that element on the page.'];
        $up = $dir !== 'smaller';
        $tv = json_decode((string) DB::table('websites')->where('id', $websiteId)->value('template_variables'), true) ?: [];
        $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
        $key = 'size_field_' . $info['field']; $s = '[data-field="' . $info['field'] . '"]:not(.lu-x)';
        $label = ($info['text'] !== '' ? '"' . mb_substr($info['text'], 0, 40) . '"' : 'the ' . str_replace(['_', '-'], ' ', $info['field']));
        if ($info['kind'] === 'image') {
            $pct = 100;
            if (isset($extras[$key]) && preg_match('/width:(\d+)%/', (string) $extras[$key], $wm)) $pct = (int) $wm[1];
            if ($up && $pct >= 100) return ['success' => false, 'message' => ucfirst($label) . ' already fills its space — a photo cannot grow past its column. I can make it smaller, or switch to a layout with a larger photo.'];
            $step = $big ? 25 : 15;
            $pct = max(30, min(100, $pct + ($up ? $step : -$step)));
            $css = "{$s}{width:{$pct}%!important;max-width:100%!important;height:auto!important}";
            $said = 'made ' . $label . ($up ? ' bigger' : ' smaller') . " (now {$pct}% of its space)";
        } else {
            $current = 1.0;
            if (isset($extras[$key]) && preg_match('/zoom:([\d.]+)/', (string) $extras[$key], $zm)) $current = (float) $zm[1];
            $step = $big ? 1.3 : 1.15;
            $factor = max(0.6, min(1.8, round($current * ($up ? $step : 1 / $step), 3)));
            if ($factor === $current) return ['success' => false, 'message' => ucfirst($label) . ' is already at the ' . ($up ? 'largest' : 'smallest') . ' size I allow (' . (int) round($factor * 100) . '% of the design).'];
            $css = "{$s}{zoom:{$factor}}";
            $said = 'made ' . $label . ($up ? ' bigger' : ' smaller') . ' (now ' . (int) round($factor * 100) . '% of the design size)';
        }
        try { $this->templates->snapshotToHistory($websiteId, 'element_size'); } catch (\Throwable $e) {}
        if (! self::writeDesignExtras($websiteId, [$key => $css], $tv)) return ['success' => false, 'message' => 'I could not write that change to the page.'];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        return ['success' => true, 'message' => $said];
    }
    /* ═══════════════════ EFFECTS888 (2026-09-15) — opacity, shadow, glow of one element; dark/light overlay on a section ═══════════════════ */

    private const FX_TEXT_SHADOW = ['', '0 1px 2px rgba(0,0,0,.25)', '0 2px 6px rgba(0,0,0,.35)', '0 4px 12px rgba(0,0,0,.45)', '0 6px 20px rgba(0,0,0,.55)'];
    private const FX_BOX_SHADOW  = ['', '0 2px 6px rgba(0,0,0,.15)', '0 6px 16px rgba(0,0,0,.22)', '0 12px 28px rgba(0,0,0,.30)', '0 20px 44px rgba(0,0,0,.38)'];
    private const FX_GLOW_PX     = [0, 8, 16, 28, 44];
    private const FX_LEVEL_WORD  = ['none', 'soft', 'medium', 'strong', 'dramatic'];

    /** The effect state of one target (a field, or 'section:{block}'), rendered to its rule. */
    public function effectElement(int $websiteId, string $field, string $effect, string $dir = 'up', ?float $value = null, ?string $color = null, string $block = ''): array
    {
        $effect = strtolower(trim($effect)); $dir = strtolower(trim($dir)) ?: 'up';
        if (! in_array($effect, ['opacity', 'shadow', 'glow', 'overlay'], true)) return ['success' => false, 'message' => 'Opacity, shadow, glow — or a dark or light overlay on a section?'];
        $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', $field); $block = (string) preg_replace('/[^a-z0-9_\-]/i', '', $block);
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if ($effect === 'overlay') {
            if ($block === '' && $field !== '' && preg_match('/data-block="([a-z_\-]+)"(?:(?!data-block=).)*?data-field="' . preg_quote($field, '/') . '"/su', $home, $bm)) $block = $bm[1];
            if ($block === '' || ! str_contains($home, 'data-block="' . $block . '"')) return ['success' => false, 'message' => 'Which section should get the overlay?'];
            $key = 'section:' . $block; $info = null;
        } else {
            $info = $this->elementInfo($websiteId, $field);
            if ($info === null) return ['success' => false, 'message' => 'I could not find that element on the page.'];
            $key = $info['field'];
        }
        $this->fxSiteId = $websiteId;
        $tv = json_decode((string) DB::table('websites')->where('id', $websiteId)->value('template_variables'), true) ?: [];
        $fx = is_array($tv['element_fx'] ?? null) ? $tv['element_fx'] : [];
        $st = is_array($fx[$key] ?? null) ? $fx[$key] : [];
        $label = $info ? ($info['text'] !== '' ? '"' . mb_substr($info['text'], 0, 40) . '"' : 'the ' . str_replace(['_', '-'], ' ', $info['field'])) : 'the ' . str_replace(['_', '-'], ' ', $block) . ' section';
        $said = '';
        if ($effect === 'opacity') {
            $cur = (int) ($st['opacity'] ?? 100);
            $new = $value !== null ? (int) round($value) : ($dir === 'none' ? 100 : ($dir === 'down' ? $cur - 10 : $cur + 10));
            $new = max(20, min(100, $new));
            if ($new === $cur) return ['success' => false, 'message' => ucfirst($label) . ' is already ' . ($cur === 100 ? 'fully opaque' : $cur . '% opaque') . ($cur <= 20 ? ' — that is as faint as I go' : '') . '.'];
            $st['opacity'] = $new; $said = ($new === 100 ? 'made ' . $label . ' fully opaque again' : 'set ' . $label . ' to ' . $new . '% opacity');
        } elseif ($effect === 'shadow' || $effect === 'glow') {
            $cur = (int) ($st[$effect] ?? 0);
            $new = $value !== null ? (int) round($value) : ($dir === 'none' ? 0 : ($dir === 'down' ? $cur - 1 : $cur + 1));
            $new = max(0, min(4, $new));
            $hex = null;
            if ($effect === 'glow' && $color !== null && trim($color) !== '') { $hex = self::styleHex(strtolower(trim($color))) ?? self::styleHexLoose($color); }
            if ($new === $cur && ($hex === null || $hex === ($st['glow_color'] ?? null))) return ['success' => false, 'message' => ucfirst($label) . ($cur === 0 ? ' has no ' . $effect . ' to remove.' : ' already has a ' . self::FX_LEVEL_WORD[$cur] . ' ' . $effect . ($cur === 4 ? ' — the strongest I do.' : '.'))];
            $st[$effect] = $new; if ($hex !== null) $st['glow_color'] = $hex;
            if ($new === 0) { $said = 'removed the ' . $effect . ' from ' . $label; unset($st['glow_color']); }
            else $said = ($cur === 0 ? 'added a ' : 'set a ') . self::FX_LEVEL_WORD[$new] . ($effect === 'glow' && ! empty($st['glow_color']) ? ' ' . (array_search($st['glow_color'], self::COLOR_MAP, true) ?: '') : '') . ' ' . $effect . ($cur === 0 ? ' to ' : ' on ') . $label;
        } else {
            $cur = (int) ($st['overlay'] ?? 0); $tone = $color !== null && preg_match('/light|white|bright/i', $color) ? 'light' : (($st['overlay_tone'] ?? 'dark'));
            if ($color !== null && preg_match('/dark|black|dim/i', $color)) $tone = 'dark';
            $new = $value !== null ? (int) round($value) : ($dir === 'none' ? 0 : ($dir === 'down' ? $cur - 1 : $cur + 1));
            $new = max(0, min(5, $new));
            if ($new === $cur && $tone === ($st['overlay_tone'] ?? 'dark')) return ['success' => false, 'message' => ucfirst($label) . ($cur === 0 ? ' has no overlay to remove.' : ' already has that overlay (level ' . $cur . ' of 5).')];
            $st['overlay'] = $new; $st['overlay_tone'] = $tone;
            $said = $new === 0 ? 'removed the overlay from ' . $label : (($tone === 'light' ? 'lightened' : 'darkened') . ' the background of ' . $label . ' (level ' . $new . ' of 5)');
        }
        $fx[$key] = $st; $tv['element_fx'] = $fx;
        $rules = $this->fxRules($key, $st, $info);
        try { $this->templates->snapshotToHistory($websiteId, 'element_effect'); } catch (\Throwable $e) {}
        $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
        $ruleKey = $info ? 'fx_field_' . $key : 'fx_section_' . $block;
        if ($rules === '') { unset($extras[$ruleKey]); $tv['design_extras'] = $extras; $ok = self::writeDesignExtras($websiteId, [], $tv); }
        else { $ok = self::writeDesignExtras($websiteId, [$ruleKey => $rules], $tv); }
        if (! $ok) return ['success' => false, 'message' => 'I could not write that change to the page.'];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        return ['success' => true, 'message' => $said, 'state' => $st];
    }

    /** The CSS of one target's effect state (empty when everything is at its default). */
    private function fxRules(string $key, array $st, ?array $info): string
    {
        if ($info === null) {
            $lvl = (int) ($st['overlay'] ?? 0); if ($lvl <= 0) return '';
            $blk = substr($key, 8); $alpha = [0, .15, .3, .45, .6, .75][$lvl];
            $veil = ($st['overlay_tone'] ?? 'dark') === 'light' ? "rgba(255,255,255,{$alpha})" : "rgba(0,0,0,{$alpha})";
            $sel = $blk === 'hero' ? '.hero,[data-block="hero"],header.hero,section.hero' : '[data-block="' . $blk . '"]';
            return "{$sel}{box-shadow:inset 0 0 0 100vmax {$veil}!important}";
        }
        $s = '[data-field="' . $info['field'] . '"]:not(.lu-x)'; $decl = [];
        $op = (int) ($st['opacity'] ?? 100); if ($op < 100) $decl[] = 'opacity:' . ($op / 100) . '!important';
        $isText = $info['kind'] === 'text';
        $shadow = (int) ($st['shadow'] ?? 0); $glow = (int) ($st['glow'] ?? 0);
        $parts = [];
        if ($shadow > 0) $parts[] = $isText ? self::FX_TEXT_SHADOW[$shadow] : self::FX_BOX_SHADOW[$shadow];
        if ($glow > 0) {
            $px = self::FX_GLOW_PX[$glow];
            $col = $st['glow_color'] ?? null;
            if ($col === null) { $col = $isText || $info['kind'] === 'link' ? 'currentColor' : ($info['kind'] === 'image' ? 'rgba(255,255,255,.55)' : (self::siteColorVars((int) ($this->fxSiteId ?? 0))['--cf1'] ?? '#6C5CE7')); }
            $parts[] = $isText ? "0 0 {$px}px {$col}" : "0 0 {$px}px " . (int) round($px / 6) . "px {$col}";
        }
        if ($parts !== []) $decl[] = ($isText ? 'text-shadow:' : 'box-shadow:') . implode(',', $parts) . '!important';
        return $decl === [] ? '' : $s . '{' . implode(';', $decl) . '}';
    }
    private ?int $fxSiteId = null;
    /** SELECTION888 (2026-09-15): the element / section the customer clicked in the editor, for the style executors of the current request. */
    private ?array $selTarget = null;
    private const SEL_WORDS = '/\b(selected|highlighted|chosen|this one|this element|this text|this title|this heading|this button|this section|this|it|that|here)\b/';

    /** What the customer has selected in the editor (data-field key, data-block), with its tag and text read from the export. */
    private function selectionContext(int $websiteId, array $ctx): ?array
    {
        $sel = is_array($ctx['selected'] ?? null) ? $ctx['selected'] : null;
        if ($sel === null) return null;
        $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($sel['field'] ?? '')); $block = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($sel['block'] ?? ''));
        if ($field === '' && $block === '') return null;
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        $tag = ''; $text = '';
        if ($field !== '' && preg_match('/<(a|button|h1|h2|h3|h4|h5|p|span|li|div|strong|em)\b[^>]*data-field="' . preg_quote($field, '/') . '"[^>]*>(.*?)<\/\1>/su', $home, $m)) { $tag = $m[1]; $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2])))); }
        elseif ($field !== '' && preg_match('/<img\b[^>]*data-field="' . preg_quote($field, '/') . '"/', $home)) { $tag = 'img'; }
        if ($block === '' && $field !== '' && preg_match('/data-block="([a-z_\-]+)"(?:(?!data-block=).)*?data-field="' . preg_quote($field, '/') . '"/su', $home, $bm)) { $block = $bm[1]; }
        return ['field' => $field, 'block' => $block, 'tag' => $tag, 'text' => mb_substr($text, 0, 160)];
    }

    /** The image the words name: 'the hero photo', 'the about image' → the first image field inside that section (ELEMENT888). */
    private function imageFieldFor(int $websiteId, string $request): string
    {
        $r = mb_strtolower($request);
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        $block = '';
        if (preg_match('/\b(hero|banner)\b/', $r)) $block = 'hero';
        elseif (preg_match_all('/data-block="([a-z_\-]+)"/', $home, $bm)) { foreach (array_unique($bm[1]) as $b) { $name = str_replace(['_', '-'], ' ', $b); if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $r) || preg_match('/\b' . preg_quote(rtrim($name, 's'), '/') . '\b/', $r)) { $block = $b; break; } } }
        $scope = $home;
        if ($block !== '' && preg_match('/<[a-z0-9]+\b[^>]*data-block="' . preg_quote($block, '/') . '"[^>]*>(.*?)(?=<[a-z0-9]+\b[^>]*data-block="|<\/body>)/su', $home, $sm)) $scope = $sm[1];
        if (preg_match('/<img\b[^>]*data-field="([a-z0-9_\-]+)"/i', $scope, $im)) return $im[1];
        return '';
    }

    /** The target a style request acts on: the model's explicit target, else the selection when the words point at it. */
    private function selectionTargetFor(array $intent, array $ctx, string $request): ?array
    {
        $t = is_array($intent['target'] ?? null) ? $intent['target'] : [];
        $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($t['field'] ?? '')); $block = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($t['block'] ?? ''));
        $sel = is_array($ctx['selected'] ?? null) ? $ctx['selected'] : null;
        if ($field === '' && $block === '' && $sel !== null && preg_match(self::SEL_WORDS, mb_strtolower($request))) { $field = (string) ($sel['field'] ?? ''); $block = (string) ($sel['block'] ?? ''); }
        if ($field === '' && $block === '') return null;
        return ['field' => $field, 'block' => $block, 'text' => (string) ($sel['text'] ?? ''), 'tag' => (string) ($sel['tag'] ?? '')];
    }

    // FIX 1 — named color → hex map used by applyBrandColors()
    private const COLOR_MAP = [
        'black'     => '#0A0A0A', 'white'   => '#FFFFFF', 'red'     => '#DC2626',
        'blue'      => '#2563EB', 'navy'    => '#1A2744', 'gold'    => '#C9943A',
        'green'     => '#16A34A', 'purple'  => '#7C3AED', 'violet'  => '#7C3AED',
        'orange'    => '#F97316', 'pink'    => '#EC4899', 'grey'    => '#6B7280',
        'gray'      => '#6B7280', 'brown'   => '#78350F', 'teal'    => '#0D9488',
        'yellow'    => '#EAB308', 'cyan'    => '#06B6D4', 'rose'    => '#E11D48',
        'emerald'   => '#059669', 'indigo'  => '#4F46E5', 'silver'  => '#9CA3AF',
        'cream'     => '#F5F1EC', 'beige'   => '#D4C5A0', 'ivory'   => '#FFFFF0',
        'charcoal'  => '#1F2937', 'maroon'  => '#7F1D1D', 'crimson' => '#B91C1C',
        'bronze'    => '#8B6F3E', 'copper'  => '#B45309', 'mint'    => '#6EE7B7',
        'coral'     => '#FB7185', 'magenta' => '#C026D3', 'turquoise' => '#06B6D4',
        'olive'     => '#65733C', 'lime'    => '#84CC16', 'peach'   => '#FDBA74',
        'sand'      => '#D4C5A0',
    ];

    // FIX 1 — every accent/brand var name known across the 10 templates.
    // applyBrandColors() walks the manifest and sets only vars that exist.
    // Sourced from: beauty/events/fashion/fitness/healthcare/interior_design/
    // legal/real_estate/restaurant/technology manifests (color-typed vars).
    private const ACCENT_VAR_NAMES = [
        // Canonical trio (restaurant + generic)
        'primary_color', 'secondary_color', 'accent_color',
        'primary_light', 'primary_deep',
        'primary', 'accent',
        // Paired accent + deep variants per industry
        'gold', 'gold_deep',
        'rose', 'rose_deep',
        'terracotta', 'terracotta_deep',
        'orange', 'orange_deep',
        'bronze', 'bronze_deep',
        'brass',  'brass_deep',
        'forest', 'forest_deep',
        'medical_blue', 'medical_deep',
        'cyan', 'violet',
        'sage', 'sage_soft',
        'ember', 'volt',
    ];

    // FIX 4 — industry keyword deny-list. If a generated service title
    // contains a keyword from a DIFFERENT industry, we fall back to the
    // manifest default. Keys are the site's industry.
    // PATCH (deny re-key, 2026-07-24) — Keys MUST be on-disk template slugs
    // (the resolved $industry passed to isCrossIndustryLeak), NOT the old
    // abstract vocab ('fitness','healthcare','legal'…). With abstract keys the
    // lookup missed for every industry except 'restaurant', silently disabling
    // the cross-industry copy-leak guard platform-wide — which is how a
    // clothing store rendered full "Managed IT Services" copy unflagged.
    private const INDUSTRY_DENY = [
        'gym'                => ['dining','restaurant','cuisine','menu','chef','bakery','tasting','wedding','venue','clinic','attorney','law firm','property','listing','couture','atelier','saas','api','endpoint'],
        'restaurant'         => ['workout','fitness','gym','hiit','yoga','pilates','strength training','clinic','attorney','property','listing','couture','saas','api','endpoint'],
        'cafe'               => ['workout','fitness','gym','hiit','yoga','pilates','clinic','attorney','property','listing','saas','api','endpoint'],
        'catering'           => ['workout','fitness','gym','hiit','yoga','clinic','attorney','property','listing','saas','api','endpoint'],
        'dental'             => ['dining','cuisine','menu','workout','hiit','yoga','couture','atelier','property','listing','saas','api','endpoint'],
        'medical_clinic'     => ['dining','cuisine','menu','workout','hiit','yoga','couture','atelier','property','listing','saas','api','endpoint'],
        'aesthetic_clinic'   => ['dining','cuisine','menu','kitchen','workout','hiit','yoga','property','listing','saas','api','endpoint'],
        'consulting'         => ['dining','cuisine','menu','workout','hiit','yoga','clinic','salon','couture','saas','api'],
        'beauty_salon'       => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','property','saas','api','endpoint'],
        'barbershop'         => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','property','saas','api','endpoint'],
        'real_estate_agency' => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'interior_design'    => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'architecture'       => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'retail_shop'        => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api','endpoint'],
        'ecommerce'          => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'it_services'        => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','atelier'],
        'marketing_agency'   => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','atelier'],
        'home_services'      => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'automotive'         => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'pet_services'       => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','saas','api'],
        'event_venue'        => ['dining menu','cuisine','workout','hiit','yoga','clinic','attorney','saas','api'],
    ];

    // BUG 2 FIX — keyword → canonical industry map. Order of keys matters:
    // applyIndustryMap() iterates by descending key length so longer phrases
    // ("digital marketing") match before shorter substrings ("digital").
    private const INDUSTRY_MAP = [
        // PERSONAL BRAND (2026-09-14, Owner brief) — see KEYWORD_TO_TEMPLATE; both maps must agree.
        'independent consultant' => 'consultant_profile', 'freelance consultant' => 'consultant_profile', 'personal brand' => 'consultant_profile',
        'business coach' => 'consultant_profile', 'executive coach' => 'consultant_profile', 'career coach' => 'consultant_profile', 'leadership coach' => 'consultant_profile',
        'management consultant' => 'consultant_profile', 'strategy consultant' => 'consultant_profile', 'fractional' => 'consultant_profile',
        'keynote speaker' => 'consultant_profile', 'public speaker' => 'consultant_profile', 'speaker' => 'consultant_profile', 'mentor' => 'consultant_profile',
        'business advisor' => 'consultant_profile', 'business adviser' => 'consultant_profile', 'strategist' => 'consultant_profile', 'freelancer' => 'consultant_profile',
        // Marketing agency (new industry) — digital marketing agencies are NOT SaaS
        'digital marketing'        => 'marketing_agency',
        'marketing agency'         => 'marketing_agency',
        'seo agency'               => 'marketing_agency',
        'seo'                      => 'marketing_agency',
        'social media agency'      => 'marketing_agency',
        'social media'             => 'marketing_agency',
        'advertising agency'       => 'marketing_agency',
        'advertising'              => 'marketing_agency',
        'ppc'                      => 'marketing_agency',
        'paid ads'                 => 'marketing_agency',
        'growth agency'            => 'marketing_agency',
        'media buying'             => 'marketing_agency',
        'web design'               => 'marketing_agency',
        // Technology (SaaS / dev tools / software only)
        'web development'          => 'technology',
        'it company'               => 'technology',
        'it services'              => 'technology',
        'software'                 => 'technology',
        'app development'          => 'technology',
        'app'                      => 'technology',
        'saas'                     => 'technology',
        'tech'                     => 'technology',
        'digital'                  => 'technology',
        // Education (new)
        'education'                => 'tutoring',
        'school'                   => 'tutoring',
        'university'               => 'tutoring',
        'college'                  => 'tutoring',
        'institute'                => 'tutoring',
        'academy'                  => 'tutoring',
        'tutoring'                 => 'tutoring',
        'training center'          => 'tutoring',
        'online course'            => 'tutoring',
        // Automotive (new)
        'automotive'               => 'automotive',
        'car dealership'           => 'automotive',
        'auto'                     => 'automotive',
        'vehicle'                  => 'automotive',
        'garage'                   => 'automotive',
        'car service'              => 'automotive',
        'auto repair'              => 'automotive',
        'car rental'               => 'automotive',
        'detailing'                => 'automotive',
        // Hospitality (new)
        'hotel'                    => 'hotel',
        'hospitality'              => 'hotel',
        'resort'                   => 'hotel',
        'serviced apartment'       => 'hotel',
        'guesthouse'               => 'hotel',
        'boutique hotel'           => 'hotel',
        'vacation rental'          => 'hotel',
        // Cafe (new — split from restaurant)
        'cafe'                     => 'cafe',
        'coffee shop'              => 'cafe',
        'bakery'                   => 'cafe',
        'juice bar'                => 'cafe',
        'patisserie'               => 'cafe',
        'tea house'                => 'cafe',
        'brunch'                   => 'cafe',
        'breakfast'                => 'cafe',
        'dessert shop'             => 'cafe',
        // Cleaning (new)
        'cleaning services'        => 'home_services',
        'cleaning'                 => 'home_services',
        'maid service'             => 'home_services',
        'housekeeping'             => 'home_services',
        'office cleaning'          => 'home_services',
        'deep cleaning'            => 'home_services',
        'move-out cleaning'        => 'home_services',
        'post construction cleaning' => 'home_services',
        'sanitization'             => 'home_services',
        'disinfection'             => 'home_services',
        // Construction (new)
        'construction'             => 'construction',
        'contractor'               => 'construction',
        'builder'                  => 'construction',
        'civil engineering'        => 'construction',
        'mep'                      => 'construction',
        'fit-out'                  => 'construction',
        'renovation'               => 'construction',
        'infrastructure'           => 'construction',
        'building contractor'      => 'construction',
        'joinery'                  => 'construction',
        // Photography (new)
        'photography'              => 'consulting',
        'photographer'             => 'consulting',
        'photo studio'             => 'consulting',
        'videography'              => 'consulting',
        'videographer'             => 'consulting',
        'wedding photographer'     => 'consulting',
        'portrait studio'          => 'consulting',
        'commercial photography'   => 'consulting',
        // Childcare (new)
        'childcare'                => 'childcare',
        'nursery'                  => 'childcare',
        'daycare'                  => 'childcare',
        'kindergarten'             => 'childcare',
        'preschool'                => 'childcare',
        'early childhood'          => 'childcare',
        'kids activities'          => 'childcare',
        'after school'             => 'childcare',
        'child development center' => 'childcare',
        // Consulting (new — split from legal)
        'management consulting'    => 'consulting',
        'business consulting'      => 'consulting',
        'hr consulting'            => 'consulting',
        'financial consulting'     => 'consulting',
        'consultancy'              => 'consulting',
        'consulting'               => 'consulting',
        'strategy'                 => 'consulting',
        'advisory'                 => 'consulting',
        // Finance (new)
        'finance'                  => 'consulting',
        'accounting'               => 'consulting',
        'accountant'               => 'consulting',
        'financial advisor'        => 'consulting',
        'tax'                      => 'consulting',
        'audit'                    => 'consulting',
        'bookkeeping'              => 'consulting',
        'payroll'                  => 'consulting',
        'vat'                      => 'consulting',
        'cfo services'             => 'consulting',
        'wealth management'        => 'consulting',
        // Wellness (new)
        'wellness'                 => 'medical_clinic',
        'nutrition'                => 'medical_clinic',
        'nutritionist'             => 'medical_clinic',
        'life coach'               => 'medical_clinic',
        'mental health'            => 'medical_clinic',
        'therapist'                => 'medical_clinic',
        'meditation'               => 'medical_clinic',
        'holistic'                 => 'medical_clinic',
        'naturopath'               => 'medical_clinic',
        'health coach'             => 'medical_clinic',
        'mindfulness'              => 'medical_clinic',
        // Pet services (new)
        'pet'                      => 'pet_services',
        'veterinary'               => 'pet_services',
        'vet clinic'               => 'pet_services',
        'pet grooming'             => 'pet_services',
        'pet boarding'             => 'pet_services',
        'pet training'             => 'pet_services',
        'dog grooming'             => 'pet_services',
        'cat clinic'               => 'pet_services',
        'pet shop'                 => 'pet_services',
        'animal hospital'          => 'pet_services',
        'pet daycare'              => 'pet_services',
        // Logistics (new)
        'logistics'                => 'consulting',
        'freight'                  => 'consulting',
        'shipping'                 => 'consulting',
        'courier'                  => 'consulting',
        'delivery'                 => 'consulting',
        'warehousing'              => 'consulting',
        'moving company'           => 'consulting',
        'relocation'               => 'consulting',
        'cargo'                    => 'consulting',
        'supply chain'             => 'consulting',
        'last mile'                => 'consulting',
        'movers'                   => 'consulting',
        // Architecture (new)
        'architecture'             => 'architecture',
        'architect'                => 'architecture',
        'urban planning'           => 'architecture',
        'landscape architecture'   => 'architecture',
        'structural engineering'   => 'architecture',
        'masterplan'               => 'architecture',
        'urban design'             => 'architecture',
        'building design'          => 'architecture',
        // Real estate broker — personal brand (not a company site) — must
        // beat 'real estate' / 'property' which go to the company-site
        // 'real_estate' template. Keys ordered so the more-specific phrase
        // matches first via normalizeIndustry()'s longest-key-wins rule.
        'real estate broker'       => 'realtor_profile',
        'property broker'          => 'realtor_profile',
        'real estate agent'        => 'realtor_profile',
        'property agent'           => 'realtor_profile',
        'property consultant'      => 'realtor_profile',
        'realtor'                  => 'realtor_profile',
        'broker'                   => 'real_estate_agency',
        // v1.4.4 (2026-05-30) — legacy aliases REMAPPED to actual template
        // slugs. Before this, `legal`, `healthcare`, `fitness`, `beauty`,
        // `real_estate`, `fashion`, `events` were the values — but no such
        // templates exist on disk. Routing here pointed at the void; the
        // fallback only saved obvious cases. Now each routes to a real
        // template manifest.
        'law firm'                 => 'consulting',         // no `legal` template; consulting is closest professional services
        'lawyer'                   => 'consulting',
        'attorney'                 => 'consulting',
        'clinic'                   => 'medical_clinic',
        'hospital'                 => 'medical_clinic',
        'doctor'                   => 'medical_clinic',
        'gym'                      => 'gym',
        'yoga'                     => 'gym',
        'pilates'                  => 'gym',
        'personal trainer'         => 'gym',
        'salon'                    => 'beauty_salon',
        'spa'                      => 'beauty_salon',
        'barbershop'               => 'barbershop',         // own template now (was routed to beauty)
        'barber'                   => 'barbershop',
        'restaurant'               => 'restaurant',
        'bistro'                   => 'restaurant',
        'food'                     => 'restaurant',
        'interior'                 => 'interior_design',
        'real estate'              => 'real_estate_agency',
        'property'                 => 'real_estate_agency',
        'fashion'                  => 'retail_shop',        // closest match — `fashion` template doesn't exist
        'clothing'                 => 'retail_shop',
        'boutique'                 => 'retail_shop',
        'wedding'                  => 'event_venue',
        'events'                   => 'event_venue',
        // v1.4.4 — direct routes for templates that the prior map missed
        'home cleaning'            => 'home_services',
        'gardening'                => 'home_services',
        'landscaping'              => 'home_services',
        'pool service'             => 'home_services',
        'handyman'                 => 'home_services',
        'pest control'             => 'home_services',
        'ecommerce'                => 'ecommerce',
        'online store'             => 'ecommerce',
        'e-commerce'               => 'ecommerce',
        'dropshipping'             => 'ecommerce',
        'training center'          => 'training_center',
        'corporate training'       => 'training_center',
        'professional training'    => 'training_center',
        'travel agency'            => 'travel_agency',
        'tour operator'            => 'travel_agency',
        'tour'                     => 'travel_agency',
        'online course'            => 'online_courses',
        'online courses'           => 'online_courses',
        'e-learning'               => 'online_courses',
        'mooc'                     => 'online_courses',
        'news channel'             => 'news_channel',
        'newspaper'                => 'news_channel',
        'publishing'               => 'news_channel',
        'media publication'        => 'news_channel',
        'event venue'              => 'event_venue',
        'banquet hall'             => 'event_venue',
        'conference centre'        => 'event_venue',
        'conference center'        => 'event_venue',
        // Legacy slugs we shouldn't keep emitting (no template manifest):
        // 'cleaning', 'logistics', 'finance', 'wellness', 'photography',
        // 'real_estate_broker', 'education', 'healthcare', 'fitness',
        // 'beauty', 'real_estate', 'fashion', 'events', 'hospitality',
        // 'legal' — keys above now point at real templates; resolveTemplateSlug's
        // catch-all regex handles the residual cases (cleaning → home_services,
        // logistics → consulting, photography → consulting, wellness → medical_clinic).
    ];

    // Canonical industry slugs the template system understands.
    // v1.4.4 (2026-05-30) — synced to the 31 templates that actually
    // exist on disk at storage/templates/. Legacy "category" aliases
    // (healthcare, fashion, beauty, fitness, hospitality, events, education,
    // legal) are still routed via KEYWORD_TO_TEMPLATE + resolveTemplateSlug()
    // — they map TO these 31 template slugs but aren't themselves "valid"
    // raw industry slugs anymore. This keeps normalizeIndustry() from
    // emitting an industry name that has no template manifest.
    private const VALID_INDUSTRIES = [
        'aesthetic_clinic', 'architecture', 'automotive', 'barbershop',
        'beauty_salon', 'cafe', 'catering', 'childcare', 'construction',
        'consulting', 'dental', 'ecommerce', 'event_venue', 'gym',
        'home_services', 'hotel', 'interior_design', 'it_services',
        'marketing_agency', 'medical_clinic', 'news_channel', 'online_courses',
        'pet_services', 'real_estate_agency', 'resort', 'restaurant',
        'retail_shop', 'short_term_rental', 'training_center', 'travel_agency',
        'tutoring',
    ];

    /**
     * v1.4.4 (2026-05-30) — Page template catalogue.
     *
     * Authoritative metadata for the 17 page templates surfaced by
     * `buildDefaultSectionsForPage()`. This array describes WHAT each
     * template is for; the switch in buildDefaultSectionsForPage()
     * remains the source of truth for the actual section stack.
     *
     * Categories:
     *   universal          — works for any industry
     *   bookings_events    — reservations / appointments / classes
     *   listings           — searchable catalogues (property, room, product)
     *   visual_portfolios  — gallery-driven (transformations, menu, work)
     *   commerce_account   — cart → checkout → account flow
     *
     * Used by AdminTemplatesController::pageTemplates() to populate the
     * admin "Page Templates" tab.
     */
    public const PAGE_TEMPLATE_CATALOGUE = [
        // ─── Universal (D-1) ────────────────────────────────────────
        'about'           => ['label' => 'About',           'category' => 'universal',         'aliases' => ['about_us'],                                                            'industries' => ['*'],                                                                                                                                                                                    'description' => "Company story + values + team + testimonials + CTA. Industry-agnostic."],
        'services'        => ['label' => 'Services',        'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Service offerings as cards + testimonials + inline quote form."],
        'pricing'         => ['label' => 'Pricing',         'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Tiered pricing + FAQ + closing CTA."],
        'contact'         => ['label' => 'Contact',         'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Hero + contact form + footer."],
        'faq'             => ['label' => 'FAQ',             'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Common questions with industry-aware sample answers."],
        'legal'           => ['label' => 'Legal',           'category' => 'universal',         'aliases' => ['privacy', 'privacy_policy', 'terms', 'terms_of_service'],              'industries' => ['*'],                                                                                                                                                                                    'description' => "Privacy policy / Terms of service skeleton."],

        // ─── Bookings + events (D-2) ────────────────────────────────
        'booking'         => ['label' => 'Booking / Appointments', 'category' => 'bookings_events', 'aliases' => ['book', 'book_now', 'appointments', 'reservations', 'reserve'],     'industries' => ['*'],            'description' => "Hero + booking_form (service picker, date+time, contact fields) + features + FAQ + footer."],
        'events'          => ['label' => 'Events / Classes',       'category' => 'bookings_events', 'aliases' => ['event', 'classes', 'schedule', 'whats_on'],                        'industries' => ['event_venue', 'training_center', 'online_courses', 'hotel', 'resort', 'cafe', 'restaurant', 'gym', 'news_channel', 'pet_services', 'childcare', 'tutoring', 'beauty_salon', 'barbershop', 'marketing_agency', 'consulting', 'it_services', 'aesthetic_clinic', 'dental', 'medical_clinic', 'retail_shop', 'ecommerce'],                                                                       'description' => "Hero + events_calendar (grid/list of upcoming sessions) + CTA + footer."],

        // ─── Listings (D-3) ─────────────────────────────────────────
        'listing_browser' => ['label' => 'Listing browser',  'category' => 'listings',         'aliases' => ['listings', 'properties', 'rooms', 'products', 'shop', 'catalogue', 'catalog', 'inventory', 'fleet', 'courses', 'menu_browser'], 'industries' => ['real_estate_agency', 'short_term_rental', 'ecommerce', 'retail_shop', 'hotel', 'resort', 'automotive', 'online_courses', 'training_center', 'tutoring', 'travel_agency', 'childcare'],                              'description' => "Hero + filter_bar + grid of cards + cross-sell CTA. Industry-aware kind label (properties / rooms / products / vehicles / courses)."],
        'listing_detail'  => ['label' => 'Listing detail',   'category' => 'listings',         'aliases' => ['property', 'product', 'room', 'course'],                               'industries' => ['real_estate_agency', 'short_term_rental', 'ecommerce', 'hotel', 'resort', 'automotive', 'online_courses', 'travel_agency', 'childcare'],                                                                              'description' => "Hero + key-detail features + gallery + trust signals + CTA + inquiry form + related listings."],
        'locations'       => ['label' => 'Locations',        'category' => 'listings',         'aliases' => ['location', 'branches', 'find_us', 'store_finder', 'stores'],           'industries' => ['*'],                                                                                                                                                                                    'description' => "Hero + map with branch list + trust signals + CTA. Suits any tenant with physical presence."],

        // ─── Visual portfolios (D-4) ────────────────────────────────
        'before_after'    => ['label' => 'Before / After',   'category' => 'visual_portfolios','aliases' => ['before_and_after', 'transformations', 'results', 'case_studies'],      'industries' => ['aesthetic_clinic', 'dental', 'beauty_salon', 'barbershop', 'home_services', 'construction', 'interior_design'],                                                                         'description' => "Hero + gallery (paired before/after) + testimonials + stat row + CTA."],
        'menu'            => ['label' => 'Menu',             'category' => 'visual_portfolios','aliases' => ['food_menu', 'dishes', 'drinks', 'wine_list'],                          'industries' => ['restaurant', 'cafe', 'catering', 'hotel', 'resort'],                                                                                                                                    'description' => "Hero + filter_bar (sections / dietary) + 2-column grid of items + reservation CTA."],
        'portfolio'       => ['label' => 'Portfolio',        'category' => 'visual_portfolios','aliases' => ['work', 'projects', 'gallery_page', 'showcase'],                        'industries' => ['architecture', 'interior_design', 'construction', 'marketing_agency', 'consulting', 'it_services'],                                                                                     'description' => "Hero + filter_bar + media-style grid + testimonials + project CTA."],

        // ─── Commerce + account (D-5) ───────────────────────────────
        'cart'            => ['label' => 'Cart',             'category' => 'commerce_account', 'aliases' => ['basket', 'shopping_cart'],                                              'industries' => ['ecommerce', 'retail_shop', 'online_courses'],                                                                                                                                           'description' => "Cart summary (line items + tax + shipping + total) + trust signals + footer."],
        'checkout'        => ['label' => 'Checkout',         'category' => 'commerce_account', 'aliases' => ['checkout_page'],                                                        'industries' => ['ecommerce', 'retail_shop', 'online_courses'],                                                                                                                                           'description' => "Multi-section checkout form (contact / shipping / payment) + order summary sidebar + trust signals."],
        'account'         => ['label' => 'Account',          'category' => 'commerce_account', 'aliases' => ['my_account', 'dashboard', 'profile_page'],                              'industries' => ['ecommerce', 'retail_shop', 'online_courses', 'short_term_rental'],                                                                                                                      'description' => "Account nav (top) + account panel (orders / addresses / profile / wishlist)."],
    ];

    /**
     * Return the page template catalogue enriched with the actual section
     * stack each template produces. Sample data used so the structure is
     * representative — actual tenant data is substituted at runtime.
     */
    public function listPageTemplates(): array
    {
        $sample = [
            'business_name' => 'Sample Business',
            'industry'      => 'consulting',
            'core_service'  => 'our core service',
            'services'      => ['Service one', 'Service two', 'Service three'],
            'location'      => 'Dubai',
        ];

        $out = [];
        foreach (self::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
            $stack = $this->buildDefaultSectionsForPage($slug, $sample);
            $types = array_values(array_map(fn ($s) => $s['type'] ?? '', $stack));
            $out[] = [
                'slug'                   => $slug,
                'label'                  => $meta['label'],
                'category'               => $meta['category'],
                'aliases'                => $meta['aliases'],
                'recommended_industries' => $meta['industries'],
                'description'            => $meta['description'],
                'section_types'          => $types,
                'section_count'          => count($types),
                'preview_url'            => '/page-templates/' . $slug . '/preview',
            ];
        }
        return $out;
    }

    public function __construct()
    {
        $this->runtime = app(\App\Connectors\RuntimeClient::class);
        $this->templates = new TemplateService();
    }

    // FIX 1 — normalise a color token (name or hex) to a 6-digit hex string.
    private function normalizeHex(?string $val): ?string
    {
        if (!$val) return null;
        $val = strtolower(trim($val));
        if (isset(self::COLOR_MAP[$val])) return self::COLOR_MAP[$val];
        if (preg_match('/^#([0-9a-f]{3})$/', $val, $m)) {
            $c = $m[1];
            return '#' . $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $val)) return strtoupper($val);
        return null;
    }

    // FIX 1 — darken a hex colour by a percentage (0–100).
    private function darkenHex(string $hex, int $pct): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#' . $hex;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $factor = max(0, min(100, 100 - $pct)) / 100;
        $r = max(0, (int) round($r * $factor));
        $g = max(0, (int) round($g * $factor));
        $b = max(0, (int) round($b * $factor));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    // FIX 1 — apply the user's brand colors across the manifest's known
    // accent variables. Rule:
    //   - primary_color / primary → user's primary
    //   - every other industry-specific accent name → user's accent
    //     (accent defaults to secondary → primary if secondary not given)
    //   - *_deep / *_dark variants → darkened shade of whatever the base
    //     would be for that name
    // Soft/muted variants are left alone to avoid inverted tonal ranges.
    /**
     * Content sections whose repeating items are all empty (blanked sample data with no LLM
     * refill) — return their data-block ids so removeBlocks() drops them. Honest: no fake
     * placeholder data, no empty band. Structural / form / image-driven sections are never
     * stripped (they don't rely on blank-able text items).
     */
    private function emptyContentBlocksToRemove(string $industrySlug, array $variables): array
    {
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($industrySlug));
        $path = storage_path("templates/{$slug}/template.html");
        if (!is_file($path)) return [];
        $html = file_get_contents($path);
        $deny = ['nav','header','footer','hero','contact','contact_form','booking','cta','announcement','map','gallery','inventory','featured_vehicles','menu','travel_quiz'];
        if (!preg_match_all('/data-block="([a-z0-9_]+)"/', $html, $m, PREG_OFFSET_CAPTURE)) return [];
        $names = $m[1]; $n = count($names); $out = [];
        $filled = function ($k) use ($variables) { return trim((string) ($variables[$k] ?? '')) !== ''; };
        for ($i = 0; $i < $n; $i++) {
            $block = $names[$i][0];
            if (in_array($block, $deny, true)) continue;
            $start = $names[$i][1];
            $end   = ($i + 1 < $n) ? $names[$i + 1][1] : strlen($html);
            $chunk = substr($html, $start, $end - $start);
            if (!preg_match_all('/data-field="([a-z0-9_]+)"/', $chunk, $fm)) continue; // no text fields -> decorative/static, leave
            $fields = array_values(array_unique($fm[1]));
            $itemFields = array_values(array_filter($fields, function ($fld) { return preg_match('/_\d+_/', $fld); }));
            if (!empty($itemFields)) {
                $anyItem = false; foreach ($itemFields as $fld) { if ($filled($fld)) { $anyItem = true; break; } }
                if (!$anyItem) $out[] = $block; // showcase with zero real items
            } else {
                $any = false; foreach ($fields as $fld) { if ($filled($fld)) { $any = true; break; } }
                if (!$any) $out[] = $block; // every field empty
            }
        }
        return $out;
    }

    private function applyBrandColors(array &$variables, array $manifest, ?array $colors): void
    {
        if (empty($colors) || !is_array($colors)) return;
        $primary   = $this->normalizeHex($colors['primary']   ?? null);
        $secondary = $this->normalizeHex($colors['secondary'] ?? null);
        // COLOR-1: fall back to the PRIMARY, not the secondary. The accent drives every industry accent variable,
        // which is what the templates paint most surfaces with — defaulting it to the secondary made the secondary
        // the dominant colour of the whole site and buried the owner's primary.
        $accent    = $this->normalizeHex($colors['accent']    ?? null) ?? $primary ?? $secondary;
        if (!$primary && !$secondary && !$accent) return;
        // COLOUR THEMES (2026-09-05): whatever reaches the paint step — theme, logo palette, typed hex, named colour —
        // is made readable first: white text on the primary at WCAG AA (4.5:1), on the accent at 3:1, neon tamed.
        // Hue is kept; only lightness/saturation move, and only as far as they must. Boss Mac's #FF00FF → #E31CE3.
        $hz = \App\Engines\Builder\Support\ColorTheme::harmonise(['primary' => $primary, 'secondary' => $secondary, 'accent' => $accent]);
        if (!empty($hz['adjusted'])) Log::info('[Arthur] brand colours harmonised for contrast', $hz['adjusted']);
        $primary   = $hz['primary'];
        $secondary = $hz['secondary'];
        $accent    = $hz['accent'] ?? $primary ?? $secondary;
        $primaryDeep = $primary ? $this->darkenHex($primary, 12) : null;
        $accentDeep  = $accent  ? $this->darkenHex($accent,  12) : null;

        $manifestVars = $manifest['variables'] ?? [];
        foreach (self::ACCENT_VAR_NAMES as $name) {
            if (!array_key_exists($name, $manifestVars)) continue;

            $isDeep = (str_contains($name, '_deep') || str_contains($name, '-deep') || str_contains($name, '_dark'));
            $isSoft = (str_contains($name, '_soft') || str_contains($name, '_light') || str_contains($name, '_muted'));
            if ($isSoft) continue; // leave soft/muted tints untouched

            if (in_array($name, ['primary_color','primary'], true)) {
                $variables[$name] = $isDeep ? ($primaryDeep ?? $primary) : $primary;
            } elseif (in_array($name, ['secondary_color','secondary'], true)) {
                // COLOR-1: a variable literally named "secondary" must hold the owner's secondary colour; it was
                // being swept up with the accent variables and overwritten.
                $variables[$name] = $secondary ?? $accent;
            } else {
                // Every industry-specific accent var (gold, rose, orange,
                // terracotta, cyan, bronze, brass, forest, medical_blue,
                // violet, sage, etc.) takes the user's accent color.
                $variables[$name] = $isDeep ? ($accentDeep ?? $accent) : $accent;
            }
        }
        // COLOR-ROLES (2026-09-05): map brand colours onto THIS template's ACTUAL palette
        // vars, declared per-template in manifest.color_roles. Fixes templates whose accent
        // isn't in ACCENT_VAR_NAMES (terra/copper/red/sky/coral/teal/…) and keeps a two-accent
        // template's secondary distinct instead of collapsing everything to one accent.
        // Runs AFTER the generic loop so the correct role assignment wins.
        $roles = $manifest['color_roles'] ?? null;
        if (is_array($roles)) {
            if (!empty($roles['accent'])) {
                $variables[$roles['accent']] = $accent;
                if (!empty($roles['accent_deep'])) $variables[$roles['accent_deep']] = $accentDeep ?? $accent;
            }
            if (!empty($roles['secondary'])) {
                $variables[$roles['secondary']] = $secondary ?? $accent;
            }
        }

        // PALETTE ROLES (Owner 2026-09-18): the palette is the whole site. Every neutral the manifest names
        // (paper, ink, muted, line, the dark section …) is painted from roles derived with contrast maths, so a
        // palette switch repaints backgrounds and text, not only the accent. Theme bg/text ride along when the
        // colours came from a named theme; otherwise the roles derive them.
        $__roles = \App\Engines\Builder\Support\PaletteRoles::derive(
            ['primary' => $primary, 'secondary' => $secondary, 'accent' => $accent, 'bg' => $colors['bg'] ?? null, 'text' => $colors['text'] ?? null],
            (string) ($manifest['palette_scheme'] ?? 'light')
        );
        \App\Engines\Builder\Support\PaletteRoles::paintManifestVars($variables, $manifest, $__roles);
        // only ever a string: BuilderGenerationDTO refuses a NULL variable, and a fresh build has no named theme
        // (caught by the widget journey on 2026-09-19 — every new build 422'd on "palette_bg is NULL")
        if (isset($colors['bg']))   $variables['palette_bg']   = (string) $colors['bg'];
        if (isset($colors['text'])) $variables['palette_text'] = (string) $colors['text'];

        // Ensure the canonical trio is explicitly set for downstream CSS
        // regardless of whether the template's manifest declares them.
        if ($primary)       $variables['primary_color']   = $primary;
        if ($secondary)     $variables['secondary_color'] = $secondary;
        if ($accent)        $variables['accent_color']    = $accent;
        if ($primaryDeep)   $variables['primary_deep']    = $primaryDeep;
        // Hero-overlay tint follows the brand: a dark version of the primary as "r,g,b", used by
        // templates whose hero gradient was a hardcoded off-brand colour (pet_services teal, it_services cyan).
        $ovBase = $primary ?? $accent;
        if ($ovBase) {
            $ovHex = ltrim($this->darkenHex($ovBase, 55), '#');
            if (strlen($ovHex) === 6) {
                $variables['hero_overlay_rgb'] = hexdec(substr($ovHex,0,2)) . ',' . hexdec(substr($ovHex,2,2)) . ',' . hexdec(substr($ovHex,4,2));
            }
        }
    }

    // FIX 1 — server-side color scanner (mirrors _arthurExtractColors in JS).
    // Returns partial ['primary' => ..., 'secondary' => ..., 'accent' => ...].
    private function scanColorsServerSide(string $msg): array
    {
        if ($msg === '') return [];
        $words = implode('|', array_keys(self::COLOR_MAP));
        $out   = [];

        // Role-explicit phrases first
        if (preg_match_all('/(?:use|make|set)\s+(#(?:[0-9a-f]{3}|[0-9a-f]{6})|[a-z]+)\s+(?:as|for)(?:\s+the)?\s+(primary|main|secondary|accent|brand)/i', $msg, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $v = strtolower($m[1]);
                $r = strtolower($m[2]);
                if ($r === 'main' || $r === 'brand') $r = 'primary';
                if (isset(self::COLOR_MAP[$v]) || preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/', $v)) {
                    $out[$r] = $v;
                }
            }
        }

        // Positional flow: hex codes + remaining named colors into unfilled slots
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $msg, $hex);
        preg_match_all('/\b(' . $words . ')\b/i', $msg, $named);
        $pool = array_merge(array_map('strtolower', $hex[0] ?? []), array_map('strtolower', $named[1] ?? []));
        $slots = ['primary','secondary','accent'];
        foreach ($pool as $c) {
            foreach ($slots as $role) {
                if (empty($out[$role])) { $out[$role] = $c; break; }
            }
        }
        return $out;
    }

    // FIX 4 — returns true if $text contains a keyword from a different
    // industry's deny-list for $industry.
    private function isCrossIndustryLeak(string $text, string $industry): bool
    {
        $deny = self::INDUSTRY_DENY[$industry] ?? [];
        if (empty($deny)) return false;
        $lower = strtolower($text);
        foreach ($deny as $kw) {
            if (strpos($lower, $kw) !== false) return true;
        }
        return false;
    }

    // PATCH (template-resolution, 2026-05-09) — Maps industry keywords/
    // phrases DIRECTLY to on-disk template slugs. The legacy INDUSTRY_MAP
    // above maps to abstract "canonical industries" like 'healthcare' that
    // don't exist as templates on disk — so a "Plastic Surgery" request
    // that goes through INDUSTRY_MAP gets 'healthcare', getManifest()
    // returns null, and generateWebsite falls back to RESTAURANT. This
    // map fixes that by mapping straight to disk template slugs.
    //
    // Disk templates (2026-05-09): aesthetic_clinic, architecture,
    // automotive, barbershop, beauty_salon, cafe, catering, childcare,
    // construction, consulting, dental, ecommerce, event_venue, gym,
    // home_services, hotel, interior_design, it_services,
    // marketing_agency, medical_clinic, online_courses, pet_services,
    // real_estate_agency, resort, restaurant, retail_shop,
    // short_term_rental, training_center, travel_agency, tutoring
    //
    // Longest-match-wins (sorted by key length in resolveTemplateSlug).
    private const KEYWORD_TO_TEMPLATE = [
        // a realtor / agent / broker is a PERSON: the portfolio design (longest match keeps 'real estate agency' an agency)
        'real estate agent' => 'realtor_profile', 'property agent' => 'realtor_profile', 'property broker' => 'realtor_profile',
        'real estate broker' => 'realtor_profile', 'property consultant' => 'realtor_profile', 'estate agent' => 'realtor_profile',
        // PERSONAL BRAND (2026-09-14, Owner brief): an individual consultant / coach / advisor gets the profile design.
        // (Realtor keys are re-pointed in place further down — a later duplicate key would override these.)
        'independent consultant' => 'consultant_profile', 'freelance consultant' => 'consultant_profile', 'personal brand' => 'consultant_profile',
        'business coach' => 'consultant_profile', 'executive coach' => 'consultant_profile', 'career coach' => 'consultant_profile', 'leadership coach' => 'consultant_profile',
        'management consultant' => 'consultant_profile', 'strategy consultant' => 'consultant_profile', 'fractional' => 'consultant_profile',
        'keynote speaker' => 'consultant_profile', 'public speaker' => 'consultant_profile', 'speaker' => 'consultant_profile', 'mentor' => 'consultant_profile',
        'business advisor' => 'consultant_profile', 'business adviser' => 'consultant_profile', 'strategist' => 'consultant_profile', 'freelancer' => 'consultant_profile',
        // PET (2026-09-05): a pet shop/store is a pet business — 'shop' alone was winning → retail_shop boutique.
        'pet shop' => 'pet_services', 'pet store' => 'pet_services', 'petshop' => 'pet_services', 'pet supplies' => 'pet_services',
        'pet services' => 'pet_services', 'pet services and retail' => 'pet_services', 'pets' => 'pet_services',
        // Medical / health (Bico Plastic Surgery → aesthetic_clinic)
        'plastic surgery'        => 'aesthetic_clinic',
        'cosmetic surgery'       => 'aesthetic_clinic',
        'aesthetic clinic'       => 'aesthetic_clinic',
        'aesthetic medicine'     => 'aesthetic_clinic',
        'medical spa'            => 'aesthetic_clinic',
        'medspa'                 => 'aesthetic_clinic',
        'med spa'                => 'aesthetic_clinic',
        'dermatology'            => 'aesthetic_clinic',
        'dermatologist'          => 'aesthetic_clinic',
        'cosmetic'               => 'aesthetic_clinic',
        'aesthetic'              => 'aesthetic_clinic',
        'botox'                  => 'aesthetic_clinic',
        'fillers'                => 'aesthetic_clinic',
        'laser clinic'           => 'aesthetic_clinic',
        'skin clinic'            => 'aesthetic_clinic',
        'botox clinic'           => 'aesthetic_clinic',
        'fillers clinic'         => 'aesthetic_clinic',
        'cosmetic clinic'        => 'aesthetic_clinic',
        'aesthetic doctor'       => 'aesthetic_clinic',
        'dental clinic'          => 'dental',
        'dentist'                => 'dental',
        'dental'                 => 'dental',
        'orthodontist'           => 'dental',
        'orthodontics'           => 'dental',
        'medical clinic'         => 'medical_clinic',
        'medical center'         => 'medical_clinic',
        'general practitioner'   => 'medical_clinic',
        'family doctor'          => 'medical_clinic',
        'physician'              => 'medical_clinic',
        'doctor'                 => 'medical_clinic',
        'hospital'               => 'medical_clinic',
        'clinic'                 => 'medical_clinic',
        'medical'                => 'medical_clinic',
        'healthcare'             => 'medical_clinic',
        'health clinic'          => 'medical_clinic',
        'pediatric'              => 'medical_clinic',
        'pediatrician'           => 'medical_clinic',
        'cardiology'             => 'medical_clinic',
        'gynecology'             => 'medical_clinic',
        // Food
        'restaurant'             => 'restaurant',
        'bistro'                 => 'restaurant',
        'fine dining'            => 'restaurant',
        'food truck'             => 'restaurant',
        'cafe'                   => 'cafe',
        'coffee shop'            => 'cafe',
        'bakery'                 => 'cafe',
        'patisserie'             => 'cafe',
        'tea house'              => 'cafe',
        'catering'               => 'catering',
        'caterer'                => 'catering',
        'catering service'       => 'catering',
        'event catering'         => 'catering',
        'food catering'          => 'catering',
        'wedding catering'       => 'catering',
        'corporate catering'     => 'catering',
        'private chef'           => 'catering',
        'banqueting'             => 'catering',
        // Hospitality
        'short term rental'      => 'short_term_rental',
        'short-term rental'      => 'short_term_rental',
        'vacation rental'        => 'short_term_rental',
        'airbnb'                 => 'short_term_rental',
        'beach resort'           => 'resort',
        'luxury resort'          => 'resort',
        'holiday resort'         => 'resort',
        'island resort'          => 'resort',
        'desert resort'          => 'resort',
        'spa resort'             => 'resort',
        'all-inclusive resort'   => 'resort',
        'resort'                 => 'resort',
        'boutique hotel'         => 'hotel',
        'hotel'                  => 'hotel',
        'inn'                    => 'hotel',
        'hospitality'            => 'hotel',
        // Fitness
        'crossfit'               => 'gym',
        'gym'                    => 'gym',
        'fitness'                => 'gym',
        'pilates'                => 'gym',
        'yoga studio'            => 'gym',
        'yoga'                   => 'gym',
        'personal trainer'       => 'gym',
        'personal training'      => 'gym',
        'martial arts'           => 'gym',
        // Beauty / wellness
        'beauty salon'           => 'beauty_salon',
        'hair salon'             => 'beauty_salon',
        'nail salon'             => 'beauty_salon',
        'salon'                  => 'beauty_salon',
        'spa'                    => 'beauty_salon',
        'massage'                => 'beauty_salon',
        'wellness center'        => 'beauty_salon',
        'wellness'               => 'beauty_salon',
        'beauty'                 => 'beauty_salon',
        'barbershop'             => 'barbershop',
        'barber'                 => 'barbershop',
        'mens grooming'          => 'barbershop',
        // Tech
        'it services'            => 'it_services',
        'it support'             => 'it_services',
        'managed it'             => 'it_services',
        'software development'   => 'it_services',
        'software'               => 'it_services',
        'web development'        => 'it_services',
        'app development'        => 'it_services',
        'cybersecurity'          => 'it_services',
        'cloud services'         => 'it_services',
        'saas'                   => 'it_services',
        'tech'                   => 'it_services',
        'technology'             => 'it_services',
        // Marketing
        'digital marketing'      => 'marketing_agency',
        'marketing agency'       => 'marketing_agency',
        'seo agency'             => 'marketing_agency',
        'social media agency'    => 'marketing_agency',
        'advertising agency'     => 'marketing_agency',
        'web design'             => 'marketing_agency',
        'branding agency'        => 'marketing_agency',
        'marketing'              => 'marketing_agency',
        'photography'            => 'marketing_agency',
        'photographer'           => 'marketing_agency',
        // Real estate
        'real estate agency'     => 'real_estate_agency',
        'real estate'            => 'real_estate_agency',
        'realtor'                => 'realtor_profile',        // 2026-09-14: a realtor is a person, not an agency
        'property'               => 'real_estate_agency',
        'broker'                 => 'real_estate_agency',
        // Education
        'training center'        => 'training_center',
        'training centre'        => 'training_center',
        'vocational training'    => 'training_center',
        'skills training'        => 'training_center',
        'corporate training'     => 'training_center',
        'professional training'  => 'training_center',
        'certification'          => 'training_center',
        'online courses'         => 'online_courses',
        'online course'          => 'online_courses',
        'e-learning'             => 'online_courses',
        'private tutoring'       => 'tutoring',
        'tutoring'               => 'tutoring',
        'tutor'                  => 'tutoring',
        'language school'        => 'tutoring',
        'academy'                => 'tutoring',
        'school'                 => 'tutoring',
        'education'              => 'tutoring',
        'coaching'               => 'tutoring',
        // Professional services
        'consulting'             => 'consulting',
        'consultant'             => 'consulting',
        'consultancy'            => 'consulting',
        'advisory'               => 'consulting',
        'law firm'               => 'consulting',
        'lawyer'                 => 'consulting',
        'attorney'               => 'consulting',
        'legal services'         => 'consulting',
        'accounting'             => 'consulting',
        'accountant'             => 'consulting',
        'finance'                => 'consulting',
        'financial advisor'      => 'consulting',
        // Construction / trades / home
        'construction'           => 'construction',
        'contractor'             => 'construction',
        'builder'                => 'construction',
        'general contractor'    => 'construction',
        'home services'          => 'home_services',
        'home repair'            => 'home_services',
        'plumber'                => 'home_services',
        'plumbing'               => 'home_services',
        'electrician'            => 'home_services',
        'hvac'                   => 'home_services',
        'handyman'               => 'home_services',
        'cleaning'               => 'home_services',
        'cleaning services'      => 'home_services',
        'shelving installation'  => 'home_services',
        'shelving systems'       => 'home_services',
        'shelving'               => 'home_services',
        'installation services'  => 'home_services',
        'installation'           => 'home_services',
        'manufacturing'          => 'construction',
        'fabrication'            => 'construction',
        'metalwork'              => 'construction',
        'carpentry'              => 'construction',
        'woodwork'               => 'construction',
        'joinery'                => 'construction',
        // Auto
        'auto repair'            => 'automotive',
        'car repair'             => 'automotive',
        'mechanic'               => 'automotive',
        'auto detailing'         => 'automotive',
        'detailing'              => 'automotive',
        'automotive'             => 'automotive',
        'auto'                   => 'automotive',
        'car dealership'         => 'automotive',
        // Other
        'interior design'        => 'interior_design',
        'interior designer'      => 'interior_design',
        'home decor'             => 'interior_design',
        'interior decoration'    => 'interior_design',
        'fit out'                => 'interior_design',
        'fitout'                 => 'interior_design',
        'space planning'         => 'interior_design',
        'home staging'           => 'interior_design',
        'architecture'           => 'architecture',
        'architect'              => 'architecture',
        'urban planning'         => 'architecture',
        'event venue'            => 'event_venue',
        'event space'            => 'event_venue',
        'banquet hall'           => 'event_venue',
        'wedding venue'          => 'event_venue',
        'travel agency'          => 'travel_agency',
        'tour operator'          => 'travel_agency',
        'travel'                 => 'travel_agency',
        'pet services'           => 'pet_services',
        'pet grooming'           => 'pet_services',
        'pet boarding'           => 'pet_services',
        'veterinary'             => 'pet_services',
        'veterinarian'           => 'pet_services',
        'vet clinic'             => 'pet_services',
        'pet clinic'             => 'pet_services',
        'animal hospital'        => 'pet_services',
        'animal clinic'          => 'pet_services',
        'pet hospital'           => 'pet_services',
        'pet'                    => 'pet_services',
        'childcare'              => 'childcare',
        'daycare'                => 'childcare',
        'nursery'                => 'childcare',
        'preschool'              => 'childcare',
        'kindergarten'           => 'childcare',
        'online store'           => 'ecommerce',
        'e-commerce'             => 'ecommerce',
        'ecommerce'              => 'ecommerce',
        'shopify'                => 'ecommerce',
        'retail shop'            => 'retail_shop',
        'retail'                 => 'retail_shop',
        'boutique'               => 'retail_shop',
        'fashion boutique'       => 'retail_shop',
        'clothing store'         => 'retail_shop',
        'shop'                   => 'retail_shop',
        'store'                  => 'retail_shop',
        // News / media (added 2026-05-09)
        'news channel'           => 'news_channel',
        'news network'           => 'news_channel',
        'news website'           => 'news_channel',
        'news outlet'            => 'news_channel',
        'online news'            => 'news_channel',
        'newspaper'              => 'news_channel',
        'media company'          => 'news_channel',
        'media outlet'           => 'news_channel',
        'press'                  => 'news_channel',
        'broadcast'              => 'news_channel',
        'broadcasting'           => 'news_channel',
        'journalism'             => 'news_channel',
        'editorial'              => 'news_channel',
        'news'                   => 'news_channel',
    ];

    // PATCH (template-resolution, 2026-05-09) — Resolve a free-form industry
    // string to an on-disk template slug. Tries direct slug match first,
    // then longest-keyword-wins lookup, then coarse-sector keyword fallback,
    // and finally falls through to 'consulting' (more generic than the old
    // 'restaurant' default for unknown businesses).
    /**
     * F-ARTHUR-D-TEMPLATES (2026-09-03): these on-disk manifests are un-customised DENTAL
     * clones (96 dental words vs ~15 industry words) — e.g. a 'restaurant' site would render
     * 'Doctors & Team' / 'Book a Consultation'. Route each to a genuinely appropriate EXISTING
     * template until dedicated manifests are authored. retail_shop/ecommerce have no commerce
     * template yet — consulting is the least-wrong NON-MEDICAL interim (tracked for authoring).
     */
    /**
     * 2026-09-10 — RE-INSTATED. This map was emptied on 2026-09-05 with the note "all templates now
     * serve their own file". That is not true, and the customer-visible result was a restaurant site
     * built out of a dental clinic: circular .cert-check trust badges, a --medical-deep / --sage-soft
     * palette, and 38 occurrences of "medical" against one of "chef" (website 669).
     *
     * Measured on disk, 2026-09-10 — these nine carry ~147 medical words, 7 cert-check circles each,
     * and file sizes within 30 bytes of one another, i.e. the same dental file under nine names:
     *   catering ecommerce online_courses resort restaurant retail_shop short_term_rental
     *   travel_agency tutoring
     * The four targets below are genuinely distinct files (cafe 44.6 KB / 1 medical word, hotel 49.2 KB,
     * training_center 44.7 KB, consulting 45.5 KB, none with a cert-check).
     *
     * This is an INTERIM correctness guard, exactly as it was when F-ARTHUR-D-TEMPLATES first found the
     * clones: an adjacent, honest template beats a dental clinic with the words swapped. It should be
     * removed only when each industry has a real template of its own — verified by reading the files,
     * not by assuming the work was done.
     */
    private const CLONE_OVERRIDE = [
        'restaurant'        => 'cafe',
        'catering'          => 'cafe',
        'resort'            => 'hotel',
        'short_term_rental' => 'hotel',
        'travel_agency'     => 'hotel',
        'tutoring'          => 'training_center',
        'online_courses'    => 'training_center',
        'retail_shop'       => 'consulting',
        'ecommerce'         => 'consulting',
    ];

    private function resolveTemplateSlug(string $industry): string
    {
        $slug = $this->resolveTemplateSlugInner($industry);
        return self::CLONE_OVERRIDE[$slug] ?? $slug;
    }

    private function resolveTemplateSlugInner(string $industry): string
    {
        $industry = strtolower(trim($industry));
        if ($industry === '') return 'consulting';

        // 1. Direct disk-template match (e.g. user passed 'aesthetic_clinic')
        // Traversal hygiene: keep the slug to [a-z0-9_] so a returned slug can never carry
        // ../ into a templates/{slug}/... path (declaredPlaceholders et al.). Behaviour-preserving.
        $direct = preg_replace('/[^a-z0-9_]/', '', preg_replace('/[\s-]+/', '_', $industry));
        if ($this->templates->getManifest($direct)) return $direct;

        // 2. Longest-match-wins keyword search
        $keys = array_keys(self::KEYWORD_TO_TEMPLATE);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (strpos($industry, $kw) !== false) {
                return self::KEYWORD_TO_TEMPLATE[$kw];
            }
        }

        // 3. Coarse sector-word fallback for off-the-map industries
        $patterns = [
            '/medical|surgery|surgeon|clinic|dental|doctor|hospital|nurse|physic|pediatric|cardio|gyneco|ortho|optic/' => 'medical_clinic',
            '/cosmetic|aesthetic|botox|filler|skin|laser|derma/'                                                       => 'aesthetic_clinic',
            '/beauty|salon|spa|nail|wax|brow|lash|makeup|hair/'                                                        => 'beauty_salon',
            '/restaurant|food|kitchen|dining|bistro|grill|sushi|pizz|burger|asian|italian|chef/'                       => 'restaurant',
            '/coffee|cafe|cafe|bakery|tea|brunch|patisserie|donut|dessert|juice/'                                      => 'cafe',
            '/gym|fit|crossfit|pilates|yoga|martial|train/'                                                            => 'gym',
            '/legal|law|attorney|advisor|consult|coach|finance|accounting|tax|audit|advisory/'                         => 'consulting',
            '/agency|marketing|advertis|brand|design|seo|ppc|content|social media|public relations/'                   => 'marketing_agency',
            '/it |tech|software|cloud|cyber|saas|app|web dev|developer|sysadmin/'                                      => 'it_services',
            '/property|estate|realtor|broker|real estate/'                                                             => 'real_estate_agency',
            '/educat|tutor|teach|learn|course|class|academ|institute|school|university|coaching/'                      => 'tutoring',
            '/construct|build|contractor|trade|repair|electric|plumb|hvac|carpent|roof|paint|renovation/'              => 'construction',
            '/home services|cleaning|maid|housekeep|landscap|garden|pool service/'                                     => 'home_services',
            '/auto|car|vehicle|mechanic|detailer/'                                                                     => 'automotive',
            '/architect/'                                                                                              => 'architecture',
            '/event|wedding|banquet|venue|conference/'                                                                 => 'event_venue',
            '/news|journal|press|broadcast|tribune|gazette|media outlet|reporter/'                                     => 'news_channel',
            '/travel|tour|vacation/'                                                                                   => 'travel_agency',
            '/hotel|resort|airbnb|rental|inn|guest house|lodge|motel/'                                                 => 'hotel',
            '/pet|vet|animal|dog |cat |grooming/'                                                                     => 'pet_services',
            '/child|kid |day.*care|nursery|preschool|kindergarten|montessori/'                                         => 'childcare',
            '/retail|shop|store|boutique|fashion|clothing|jewelry/'                                                   => 'retail_shop',
            '/online store|ecommerce|e-?commerce|shopify|dropship/'                                                    => 'ecommerce',
            '/interior/'                                                                                               => 'interior_design',
        ];
        foreach ($patterns as $regex => $slug) {
            if (preg_match($regex, $industry)) return $slug;
        }

        // 4. Final fallback — 'consulting' is the most generic professional
        // template; safer than 'restaurant' for unknown businesses.
        return 'consulting';
    }

    // PATCH (name-grounding, 2026-07-24) — Derive a template slug from a
    // business NAME (or any free text) but ONLY when the text carries a real
    // industry signal; returns null otherwise so callers can fall back to the
    // LLM's classification. Unlike resolveTemplateSlug (which is fed a
    // user-typed industry string and safely uses bare substring matching),
    // this scans a NAME, where bare substrings are dangerous — "Caspian
    // Travel" contains "spa", "Bishop & Co" contains "shop", "Winner Gym"
    // contains "inn". So every match here is WORD-BOUNDARIED. Longest keyword
    // wins, mirroring resolveTemplateSlug's precedence.
    private function confidentSlugFromText(?string $text): ?string
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') return null;

        // 1. Word-boundaried longest-keyword-wins over the disk-slug map.
        $keys = array_keys(self::KEYWORD_TO_TEMPLATE);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $text)) {
                return self::KEYWORD_TO_TEMPLATE[$kw];
            }
        }

        // 2. Small curated stem set for morphological variants the exact-word
        // map misses ("Architectural", "Dentistry", "Orthodontics",
        // "Veterinary"). Prefix-boundaried and deliberately unambiguous so a
        // name can never over-trigger an override.
        $stems = [
            '/\barchitect/'   => 'architecture',
            '/\bdent(al|ist)/' => 'dental',
            '/\borthodont/'   => 'dental',
            '/\bveterinar/'   => 'pet_services',
            '/\bshelv/'       => 'home_services',
        ];
        foreach ($stems as $regex => $slug) {
            if (preg_match($regex, $text)) return $slug;
        }

        // No confident signal in the name — let the LLM's guess stand.
        return null;
    }

    // PATCH (image-injection, 2026-05-09) — Build a fallback image pool
    // from the platform media library, prioritised by industry tag.
    //
    // 1. Workspace-owned uploads tagged with the industry slug
    // 2. Platform-asset images tagged with the industry slug
    // 3. Platform-asset images in category=template_image OR category=hero
    //
    // Returns up to ~20 distinct URLs so injectImagesToTemplate can fill
    // every image slot in the template even with no uploaded images.
    /**
     * EV-1000 (2026-09-12): the platform library is tagged by BASE industry (real_estate_broker, hospitality, medical …)
     * while a build carries a template directory name (estate_frontage). An exact JSON_CONTAINS on that name matched
     * nothing for every design variant, so the pool fell through to random photos from other industries and the
     * gallery to the hero repeated. This returns the tag prefixes that describe the business: the manifest's declared
     * industry, the template slug, the copy industry, and their photo families. Matched as LIKE '%"<prefix>%'.
     */
    public static function galleryTagFamily(string ...$keys): array
    {
        static $fam = [
            'real_estate_agency' => ['real_estate', 'luxury_interior', 'building'],
            'real_estate_broker' => ['real_estate', 'luxury_interior', 'building'],
            'interior_design'    => ['interior_design', 'luxury_interior', 'real_estate_broker'],
            'architecture'       => ['architecture', 'construction', 'building', 'skyline'],
            'construction'       => ['construction', 'building', 'project'],
            'home_services'      => ['home_services', 'construction', 'luxury_interior'],
            'hotel'              => ['hotel', 'hospitality', 'resort', 'room'],
            'resort'             => ['resort', 'hospitality', 'nature'],
            'short_term_rental'  => ['short_term_rental', 'hospitality', 'luxury_interior'],
            'event_venue'        => ['event_venue', 'hospitality', 'catering', 'luxury_interior'],
            'travel_agency'      => ['travel_agency', 'nature', 'resort'],
            'restaurant'         => ['restaurant', 'food', 'catering'],
            'cafe'               => ['cafe', 'food', 'restaurant'],
            'catering'           => ['catering', 'food', 'restaurant'],
            'retail_shop'        => ['retail_shop', 'retail', 'ecommerce'],
            'ecommerce'          => ['ecommerce', 'retail'],
            'it_services'        => ['it_services', 'technology', 'office'],
            'marketing_agency'   => ['marketing_agency', 'technology', 'office'],
            'consulting'         => ['consulting', 'office', 'technology'],
            'finance'            => ['finance', 'office', 'technology'],
            'logistics'          => ['logistics', 'vehicle', 'office'],
            'automotive'         => ['automotive', 'vehicle'],
            'gym'                => ['gym', 'fitness'],
            'training_center'    => ['training_center', 'education', 'fitness'],
            'tutoring'           => ['tutoring', 'education'],
            'online_courses'     => ['online_courses', 'education'],
            'childcare'          => ['childcare', 'education'],
            'medical_clinic'     => ['medical_clinic', 'medical'],
            'dental'             => ['dental', 'medical'],
            'aesthetic_clinic'   => ['aesthetic_clinic', 'medical', 'wellness', 'luxury_interior'],
            'wellness'           => ['wellness', 'medical', 'nature', 'luxury_interior'],
            'beauty_salon'       => ['beauty_salon', 'wellness', 'luxury_interior'],
            'barbershop'         => ['barbershop', 'beauty_salon', 'luxury_interior'],
            'pet_services'       => ['pet_services'],
            'photography'        => ['photography', 'portfolio'],
            'news_channel'       => ['news_channel', 'office', 'technology'],
        ];
        $tags = [];
        foreach ($keys as $k) {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $k)));
            if ($k === '') continue;
            $tags[] = $k;
            $base = $k;
            while (!isset($fam[$base]) && ($p = strrpos($base, '_')) !== false) $base = substr($base, 0, $p);
            if (isset($fam[$base])) { $tags[] = $base; foreach ($fam[$base] as $t) $tags[] = $t; }
        }
        return array_values(array_unique($tags));
    }

    /** ORDER BY clause: rows tagged with an earlier family entry sort first; ties are random. Tags are slug-safe. */
    public static function tagPriorityOrder(array $tags): string
    {
        $case = 'CASE';
        foreach (array_values($tags) as $i => $t) {
            $t = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $t));
            if ($t === '') continue;
            $case .= " WHEN tags LIKE '%\"" . $t . "%' THEN " . $i;
        }
        return $case . ' ELSE 99 END, RAND()';
    }

    private function buildImagePool(string $industry, int $wsId, array $tagFamily = []): array
    {
        $pool = [];
        $tags = $tagFamily !== [] ? $tagFamily : [$industry];
        try {
            // Tier 1 — workspace's own uploads tagged with the industry
            $rows = \Illuminate\Support\Facades\DB::table('media')
                ->where('workspace_id', $wsId)
                ->where('asset_type', 'image')
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->whereRaw('JSON_CONTAINS(tags, ?)', ['"' . $industry . '"'])
                ->limit(15)
                ->pluck('url')->toArray();
            foreach ($rows as $u) if ($u && !in_array($u, $pool, true)) $pool[] = $u;

            // Tier 2 — platform-asset images tagged with this industry FAMILY (EV-1000: the exact-name match found
            // nothing for a design variant such as estate_frontage)
            if (count($pool) < 15) {
                $rows = \Illuminate\Support\Facades\DB::table('media')
                    ->where('is_platform_asset', 1)
                    ->where('asset_type', 'image')
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->where(function ($q) use ($tags) { foreach ($tags as $t) $q->orWhere('tags', 'like', '%"' . $t . '%'); })
                    ->orderByRaw(self::tagPriorityOrder($tags))
                    ->limit(15)->pluck('url')->toArray();
                foreach ($rows as $u) if ($u && !in_array($u, $pool, true)) $pool[] = $u;
            }

            // Tier 3 (any industry) was REMOVED on 2026-09-12 (EV-1000): a gym photo on a realty site is worse than
            // the slot's own default. injectImagesToTemplate() leaves unfilled slots on their floor.
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] buildImagePool query failed: ' . $e->getMessage());
        }
        return $pool;
    }

    // PATCH (image-injection, 2026-05-09) — Fill every manifest type=image
    // variable that's still empty (or still on the industry-hero floor URL)
    // from the media pool. Each pool image is consumed at most once per
    // call; if the pool runs out we cycle back to the start so no var is
    // left blank. Logo and OG slots are deliberately excluded — logo has
    // its own opt-in flow, og falls through to hero.
    //
    // Also writes image_1..image_10 aliases pointing at the same pool so
    // any future template that uses generic image slots Just Works.
    private function injectImagesToTemplate(array &$variables, array $manifest, array $pool, ?string $heroDefaultUrl, bool $logoUploadOptIn): void
    {
        if (empty($manifest['variables'])) return;
        // EV-1077: the hero itself is tagged with the industry and came back as pool[0] — a second copy of the hero in the
        // first service card and again wherever the pool wrapped. The hero is the hero; the pool is everything else.
        $heroNow = (string) ($variables['hero_image'] ?? '');
        $others = array_values(array_filter($pool, fn($u) => $u !== $heroDefaultUrl && $u !== $heroNow && !str_contains((string) $u, '/builder-heroes/')));
        if ($others !== []) $pool = $others;

        // Build the ordered list of slots that still need filling.
        $needFill = [];
        foreach ($manifest['variables'] as $varKey => $varSpec) {
            $type = is_array($varSpec) ? ($varSpec['type'] ?? 'text') : 'text';
            if ($type !== 'image') continue;
            if ($varKey === 'logo_url' && !$logoUploadOptIn) continue;
            if ($varKey === 'og_image') continue; // handled separately
            // DEFAULT TEAM AVATARS (2026-09-06): a person slot never takes a room/gallery photo from the pool — render() gives it an avatar.
            if (\App\Engines\Builder\Services\TemplateService::personSlotFor((string) $varKey) !== null) continue;
            $current = $variables[$varKey] ?? '';
            // EV-1077: the manifest ships every image slot defaulted to the industry hero (/storage/builder-heroes/…); that
            // placeholder is empty by definition, whether or not the floor lookup above found the same URL. A design's own
            // shipped photo (a portrait design's story_image under /storage/template-images/…) is real content and stays.
            $manifestDefault = is_array($varSpec) ? (string) ($varSpec['default'] ?? '') : '';
            $isPlaceholder = $manifestDefault !== '' && $current === $manifestDefault && str_contains($manifestDefault, '/builder-heroes/');
            $isFloor = ($current === '' || $current === null
                || ($heroDefaultUrl && $current === $heroDefaultUrl) || $isPlaceholder);
            // hero_image specifically is allowed to keep the hero floor —
            // it's the most prominent image and the floor IS the right
            // industry hero. Only fill OTHER image vars from the pool.
            if ($varKey === 'hero_image' && ($current === $heroDefaultUrl || $isPlaceholder)) continue;
            if ($isFloor) $needFill[] = $varKey;
        }

        if (empty($needFill)) {
            // Even with nothing to fill on the manifest, set the generic
            // image_1..image_10 aliases for any template that uses them.
        } elseif (empty($pool)) {
            // No media to draw from — leave slots on whatever floor they
            // already have (manifest default / hero floor / empty).
        } else {
            $i = 0;
            foreach ($needFill as $varKey) {
                $variables[$varKey] = self::cropPoolImageForSlot($pool[$i % count($pool)], (string) $varKey); // CROP TOOL 2026-09-06
                $i++;
            }
        }

        // Generic image_1..image_10 aliases — same pool, cycled.
        if (!empty($pool)) {
            for ($n = 1; $n <= 10; $n++) {
                if (empty($variables['image_' . $n])) {
                    $variables['image_' . $n] = $pool[($n - 1) % count($pool)];
                }
            }
        }
    }

    // PATCH (Option C, 2026-05-09) — Seed 6 LLM-generated news articles
    // into the articles table for a freshly-built news_channel site.
    // Mutates $variables to populate static story_N_* slots so the page
    // is non-empty + SEO-friendly on first paint. The PublicNewsController
    // /api/public/news/{subdomain}/stories endpoint then serves the same
    // articles dynamically for live updates.
    private function seedNewsArticles(int $wsId, string $businessName, string $location, array &$variables): int
    {
        $categories = ['Politics', 'Business', 'Technology', 'Sports', 'Culture', 'World'];

        if (! $this->runtime->isConfigured()) {
            Log::warning('[Arthur] runtime not configured — skipping news article seeding');
            return 0;
        }

        $userPrompt = "Generate 6 realistic news headlines and excerpts for a news channel called '{$businessName}' "
            . ($location !== '' ? "based in {$location}. " : '') . "One article per category. Categories: "
            . implode(', ', $categories) . ".\n\n"
            . "Return JSON with the word json — an object with key \"articles\" containing an array of 6 items, "
            . "each shaped: {\"title\": \"verb-led news headline, ~10 words\", "
            . "\"category\": \"one of the 6 categories\", "
            . "\"excerpt\": \"two-sentence news excerpt that reads like real reporting\", "
            . "\"author\": \"professional journalist name with surname\", "
            . "\"read_time_minutes\": 3-9}\n\n"
            . "Tone: editorial, authoritative, journalistic. Headlines must sound like real news with a verb "
            . "and a real claim — never marketing copy. NEVER mention dining, food, fitness, salon, or product sales.";

        try {
            $result = $this->runtime->chatJson(
                "You are a senior news editor at a premium Gulf-region newsroom. Return only valid JSON.",
                $userPrompt,
                ['workspace_id' => $wsId, 'task' => 'news_seed'],
                1500
            );
        } catch (\Throwable $e) {
            Log::warning('[Arthur] news seed chatJson threw: ' . $e->getMessage());
            return 0;
        }

        $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
        $articles = $parsed['articles'] ?? $parsed ?? [];
        if (!is_array($articles)) return 0;

        $count = 0;
        foreach ($articles as $i => $a) {
            if (!is_array($a) || empty($a['title'])) continue;
            if ($count >= 6) break;

            $title    = mb_substr((string) $a['title'], 0, 250);
            $category = (string) ($a['category'] ?? $categories[$i % 6]);
            $excerpt  = mb_substr((string) ($a['excerpt'] ?? ''), 0, 480);
            $author   = mb_substr((string) ($a['author'] ?? 'Staff Reporter'), 0, 80);
            $readMin  = (int) ($a['read_time_minutes'] ?? 4);
            if ($readMin < 1) $readMin = 3;
            $publishedAt = now()->subHours($i * 8 + rand(1, 4));

            $slug = \Illuminate\Support\Str::slug($title) ?: ('news-' . uniqid());

            try {
                DB::table('articles')->insertOrIgnore([
                    'workspace_id'      => $wsId,
                    'title'             => $title,
                    'slug'              => $slug,
                    'content'           => '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>',
                    'excerpt'           => $excerpt,
                    'status'            => 'published',
                    'type'              => 'news',
                    'blog_category'     => $category,
                    'tags_json'         => json_encode([$category, 'news', 'seed']),
                    'is_marketing_blog' => 0,
                    'featured_image_url'=> '',
                    'meta_title'        => $title,
                    'meta_description'  => $excerpt,
                    'focus_keyword'     => $category,
                    'brief_json'        => json_encode([
                        'author'    => $author,
                        'read_time' => $readMin . ' min read',
                        'seed'      => true,
                    ]),
                    'word_count'        => str_word_count($excerpt),
                    'read_time'         => $readMin,
                    'published_at'      => $publishedAt,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                // Inject into static template variables (story_1..story_6).
                $n = $count + 1;
                $variables["story_{$n}_title"]        = $title;
                $variables["story_{$n}_category"]     = strtoupper($category);
                $variables["story_{$n}_excerpt"]      = $excerpt;
                $variables["story_{$n}_byline"]       = $author;
                $variables["story_{$n}_date"]         = $publishedAt->diffForHumans();
                $variables["story_{$n}_reading_time"] = $readMin . ' min read';

                $count++;
            } catch (\Throwable $e) {
                Log::warning('[Arthur] news article insert failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    // BUG 2 FIX — normalize an industry string to a canonical slug.
    // Preference order: explicit keyword from INDUSTRY_MAP (longest match
    // wins) > already-valid slug > raw input.
    private function normalizeIndustry(?string $raw, string $message): ?string
    {
        $haystack = strtolower(trim(($raw ?? '') . ' ' . $message));
        if ($haystack === '') return $raw;
        // E2E-2 (2026-09-05): the user's own words are the strongest signal ("pet shop" -> pet_services, longest
        // keyword wins over "shop"); a raw value that already IS one of the 31 templates passes through untouched.
        $confident = $this->confidentSlugFromText($message);
        if ($confident !== null) return $confident;
        $rawSlug = strtolower(trim((string) $raw));
        if ($rawSlug !== '' && preg_match('/^[a-z_]+$/', $rawSlug) && $this->templates->getManifest($rawSlug)) return $rawSlug;
        $keys = array_keys(self::INDUSTRY_MAP);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (strpos($haystack, $kw) !== false) {
                return self::INDUSTRY_MAP[$kw];
            }
        }
        if ($raw && in_array(strtolower($raw), self::VALID_INDUSTRIES, true)) {
            return strtolower($raw);
        }
        return $raw;
    }

    // BUG 1 FIX — build the all-fields confirmation bubble shown once the
    // first rich message extraction completes. Only includes non-empty
    // fields, so a user who didn't mention e.g. pages won't see an empty
    // "Pages:" line.
    private function buildConfirmationMessage(array $s): string
    {
        $name = $s['business_name'] ?? 'your business';
        $lines = ["Got it! Here's what I have:\n"];
        $lines[] = "Business: " . $name;

        if (!empty($s['colors']) && is_array($s['colors'])) {
            $cp = $s['colors']['primary']   ?? null;
            $cs = $s['colors']['secondary'] ?? null;
            $cc = $s['colors']['accent']    ?? null;
            $clist = array_values(array_filter([$cp, $cs, $cc]));
            if ($clist) $lines[] = "Colours: " . implode(' + ', $clist);
        }
        if (!empty($s['location']))      $lines[] = "Location: " . $s['location'];
        if (!empty($s['services'])) {
            $svc = is_array($s['services']) ? implode(', ', $s['services']) : (string)$s['services'];
            $lines[] = "Services: " . $svc;
        }
        if (!empty($s['target_market'])) $lines[] = "Audience: " . $s['target_market'];
        if (!empty($s['style']))         $lines[] = "Style: " . (is_array($s['style']) ? implode(', ', $s['style']) : (string) $s['style']);
        if (!empty($s['fonts']) && is_array($s['fonts']) && array_filter($s['fonts'])) $lines[] = "Fonts: " . implode(' / ', array_filter($s['fonts']));
        if (!empty($s['pages'])) {
            $pgs = is_array($s['pages'])
                ? implode(', ', array_map(fn($p) => ucfirst(strtolower((string)$p)), $s['pages']))
                : (string)$s['pages'];
            $lines[] = "Pages: " . $pgs;
        }
        $lines[] = "\nDoes this look right?";
        return implode("\n", $lines);
    }

    /**
     * NEW conversational entry point — pure LLM, no regex, no scripted steps.
     *
     * PATCH (Arthur AI conversation, 2026-05-09): the spec called for replacing
     * the entire scripted-wizard flow (handleMessage + extractAllFields +
     * extractFields + simpleExtract + getNextQuestion + scanColorsServerSide
     * + isCrossIndustryLeak + ...) with a single LLM-driven dialogue.
     *
     * Arthur talks like Sarah — natural, contextual, intelligent. He
     * decides when he has enough info to build the website and signals
     * via a [READY_TO_BUILD] marker followed by JSON.
     *
     * Returns:
     *   [
     *     'type'           => 'question' | 'complete',
     *     'reply'          => string,                  // user-visible text
     *     'ready_to_build' => bool,
     *     'build_data'     => array,                   // when ready_to_build
     *     'history'        => array,                   // updated for next turn
     *   ]
     *
     * The route handler (POST /api/builder/arthur/message) is responsible for
     * calling buildFromChat() if ready_to_build is true. This keeps chat()
     * pure (no DB writes, no template work) so it can be unit-tested as a
     * conversation function.
     *
     * handleMessage() above remains for callers that want the older scripted
     * flow with template picker + image upload + color extraction. The
     * route /api/builder/arthur/message now uses chat() exclusively;
     * handleMessage is reachable via direct service calls only.
     */
    public function chat(int $workspaceId, string $userMessage, array $history = []): array
    {
        $systemPrompt = <<<'PROMPT'
You are Arthur, an expert AI website builder for LevelUpGrowth.
You have a natural, warm conversation to understand a business
then build them a professional website.

You are like a skilled web designer on a discovery call.
NOT a form. NOT a robot. A real conversation.

Through natural dialogue, learn:
- Business name
- What they do / industry
- Location / who they serve
- Main services or products
- Style preference — modern, luxury, minimal, classic, or playful (bubbly/colorful/fun). Capture the customer's OWN words for style verbatim in build_data.style (e.g. "bubbly and colorful") — THIS IS APPLIED to typography, shape and colour.
- Font preference (optional: a named Google font like Poppins/Montserrat, or a mood like "elegant serif") — APPLIED

Rules:
- Ask 1-2 questions at a time maximum
- React naturally to what they say
- If they give multiple details at once, acknowledge all
- Keep responses to 2-3 sentences max
- Be warm, encouraging, professional
- Never repeat a question you already got an answer to
- Use "I'll" not "I can"; never hedge ("maybe", "I think")

When you have enough to build (minimum: name + industry + location),
DO NOT build immediately. First show the user a confirmation summary
so they can review and optionally upload a logo. Return ONLY a JSON
object with these fields:
  {"reply": "<summary message — see SUMMARY FORMAT below>",
   "ready_to_confirm": true,
   "build_data": {"business_name":"...","industry":"...","location":"...","services":"...","style":"modern","fonts":{"display":"<named font or null>","body":"<named font or null>"},"description":"..."}}

SUMMARY FORMAT — when ready_to_confirm is true, the "reply" field
MUST be the following summary, translated into the user's language
(keep emoji and **bold** markdown intact). DO NOT ask any questions —
the frontend renders an interactive panel below your message where
the user can upload a logo, add images, pick brand colors, and click
Build. Your reply is just the recap:

Here's what I have for your website:

**Business:** {business name}
**Location:** {location}
**Industry:** {industry}
**Services:** {services}
**Style:** {style}

Add your logo, photos, and brand colors below — then I'll build it.

Until you have enough, return a JSON object with:
  {"reply": "<your short conversational message>",
   "ready_to_confirm": false}

Never set "ready_to_build" yourself — the frontend triggers the actual
build after the user clicks confirm. Always use "ready_to_confirm".

Always respond with valid JSON only — no prose outside the JSON object.
The "reply" field must be in the same language the user is writing in
(Arabic, German, French, Chinese, Korean, Hindi, Urdu, Tagalog, etc.) —
match the user's language naturally. The JSON keys themselves stay
in English; only the value of "reply" mirrors the user's language.
PROMPT;

        // PATCH (i18n 2026-05-09) — append the platform-wide language rule.
        // Single-quoted heredoc above can't interpolate, so concatenate.
        $systemPrompt .= "\n\n" . \App\Core\LLM\PromptTemplates::languageRule();

        // PATCH (Arthur fix 2026-05-09) — speed: trim history to last 10 turns
        // (5 user + 5 arthur). Prevents prompt bloat after long conversations
        // and keeps Arthur as snappy as Sarah / the LevelUp Assistant.
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        // Build a conversation transcript and pass as the user prompt body
        // (chatJson takes a single user prompt; conversation history is
        // folded in as the prompt context). The runtime's chat_json task
        // uses response_format: json_object so the model returns parsed JSON.
        $transcript = '';
        foreach ($history as $msg) {
            $role = (($msg['role'] ?? '') === 'arthur') ? 'Arthur' : 'User';
            $content = (string) ($msg['content'] ?? '');
            if ($content !== '') $transcript .= "{$role}: {$content}\n";
        }
        $transcript .= "User: {$userMessage}";

        $reply           = '';
        $readyToBuild    = false;
        $readyToConfirm  = false;
        $buildData       = [];

        try {
            $result = $this->runtime->chatJson($systemPrompt, $transcript, [
                'workspace_id' => $workspaceId,
                'task'         => 'arthur_chat',
            ], 800);

            // PATCH (Arthur fix 2026-05-09) — defensive widened reply extraction.
            // The runtime's chat_json sometimes returns the reply under different
            // keys depending on whether DeepSeek's response_format=json_object
            // succeeded in parsing or fell back to raw text. Cover all known
            // shapes so we never show an empty bubble.
            // E2E-1 (2026-09-05): the runtime sometimes wraps its answer as {"json":{...}} — peel it here too, or
            // reply/ready_to_confirm/build_data are all missing and the conversation stalls.
            $parsed = is_array($result['parsed'] ?? null) ? \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($result['parsed']) : [];
            $reply  = (string) (
                $parsed['reply']
                ?? $parsed['response']
                ?? $parsed['message']
                ?? $parsed['content']
                ?? $result['reply']
                ?? $result['response']
                ?? $result['content']
                ?? $result['text']
                ?? ''
            );
            $readyToBuild   = (bool) ($parsed['ready_to_build']   ?? $result['ready_to_build']   ?? false);
            $readyToConfirm = (bool) ($parsed['ready_to_confirm'] ?? $result['ready_to_confirm'] ?? false);
            $buildDataRaw = $parsed['build_data'] ?? $result['build_data'] ?? null;
            $buildData    = is_array($buildDataRaw) ? $buildDataRaw : [];
            if (($readyToBuild || $readyToConfirm) && empty($buildData['business_name'])) {
                // protect against the model claiming ready without payload
                $readyToBuild   = false;
                $readyToConfirm = false;
            }

            // PATCH (Arthur Arabic fix 2026-05-09) — sanitize the reply through
            // mb_convert_encoding so any invalid UTF-8 sequences (rare, but
            // possible when the runtime serializes mixed-script content) get
            // dropped rather than triggering a broken JSON response on the
            // way back to the browser. UTF-8 → UTF-8 is the canonical
            // strip-invalid-bytes idiom in PHP. Verified end-to-end with
            // Arabic ("هل يمكنك التحدث باللغة العربية؟") returning 94-char
            // valid UTF-8 reply.
            if (function_exists('mb_convert_encoding')) {
                $reply = mb_convert_encoding($reply, 'UTF-8', 'UTF-8');
            }
            // Belt-and-suspenders: if reply is still empty, try the runtime's
            // raw text payload as a last resort before falling through to the
            // hiccup message.
            if ($reply === '' || $reply === null) {
                $rawText = $result['text'] ?? $result['raw'] ?? '';
                if (is_string($rawText) && $rawText !== '') {
                    $reply = $rawText;
                }
            }

            // PATCH (Arthur fix 2026-05-09) — temporary debug log so we can
            // see exactly what shape the runtime returned in logs when a
            // user reports an empty bubble. Drop this log line once the
            // empty-response issue is confirmed eliminated.
            if ($reply === '') {
                Log::warning('[Arthur:chat] reply extraction came up empty', [
                    'workspace_id' => $workspaceId,
                    'success'      => $result['success'] ?? null,
                    'error'        => $result['error'] ?? null,
                    'parsed_keys'  => array_keys($parsed),
                    'raw_keys'     => array_keys($result),
                    'parsed_dump'  => json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'text_len'     => isset($result['text']) ? strlen((string) $result['text']) : null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur:chat] runtime call threw: ' . $e->getMessage(), [
                'workspace_id' => $workspaceId,
            ]);
        }

        if ($reply === '') {
            $reply = "I'm having a brief connection hiccup — could you say that again?";
            $readyToBuild   = false;
            $readyToConfirm = false;
            $buildData = [];
        }

        $newHistory = array_merge($history, [
            ['role' => 'user',   'content' => $userMessage],
            ['role' => 'arthur', 'content' => $reply],
        ]);

        // PATCH (Arthur confirm flow 2026-05-09) — when the LLM signals
        // ready_to_confirm, cache the build_data server-side so a follow-up
        // {confirm:true} POST can retrieve it without trusting client echo.
        // 5 minute TTL is plenty for a user to upload a logo + click build.
        if ($readyToConfirm && !empty($buildData['business_name'])) {
            try {
                \Illuminate\Support\Facades\Cache::put(
                    'arthur_build_data_' . $workspaceId,
                    $buildData,
                    300
                );
            } catch (\Throwable $e) {
                Log::warning('[Arthur:chat] cache put failed: ' . $e->getMessage());
            }
        }

        $type = 'question';
        if ($readyToBuild)        $type = 'complete';
        elseif ($readyToConfirm)  $type = 'confirm';

        return [
            'type'             => $type,
            'reply'            => $reply,
            'ready_to_build'   => $readyToBuild,
            'ready_to_confirm' => $readyToConfirm,
            'build_data'       => $buildData,
            'history'          => $newHistory,
        ];
    }

    /**
     * Public wrapper around the existing private generateWebsite() so the
     * /arthur/message route can trigger website creation when the user
     * confirms via the full confirm panel (logo + images + colors + build).
     *
     * Plumbs through:
     *   - $logoUrl  → $data['logo_url'] + $data['logo_upload']=true
     *   - $images   → $data['uploaded_images']  (consumed at line ~1383
     *                 of generateWebsite, distributed across all image
     *                 manifest vars in order, hard-capped at 10)
     *   - $colors   → $data['colors']           (applied via applyBrandColors)
     *
     * Returns whatever generateWebsite returns: typically
     *   ['type' => 'website_created'|'error', 'website_id' => ?, 'website_url' => ?, 'message' => ?]
     */
    /* ═══════════════════ DEC-0046 (2026-09-13) — PALETTES, LITERAL GRADIENTS, VERIFIED WRITES ═══════════════════ */

    /**
     * IMAGE GENERATION at edit time (DEC-0046 gap closure, 2026-09-14). "Generate a new hero image of …" goes to the
     * platform's image intelligence (the same service Studio and the blog use), which charges by quality and refunds
     * itself on failure; a generated image is placed into the named slot of the static export. A provider refusal is
     * reported as exactly that — never as a change.
     */
    private function generateSiteImage(int $wsId, int $websiteId, string $request, object $site, array $plan, bool $isStatic, array $tv): array
    {
        $target = (string) ($plan['target'] ?? 'hero');
        $prompt = trim((string) preg_replace('/^(?:please\s+)?(?:can you\s+)?(?:generate|create|produce|draw|render|design|make|give me)\s+(?:me\s+)?(?:an?\s+|the\s+)?(?:new\s+|another\s+|different\s+)?(?:ai\s+)?(?:hero\s+|banner\s+|background\s+|about\s+|gallery\s+)?(?:image|photo|picture|visual|illustration|artwork)\s*(?:of|for|showing|with|that shows)?\s*/i', '', $request));
        $prompt = trim((string) preg_replace('/\s+(?:for|on|in)\s+the\s+(?:hero|banner|about|gallery)(?:\s+section)?\.?$/i', '', $prompt));
        if ($prompt === '') { $prompt = "{$site->name} — " . str_replace('_', ' ', (string) ($tv['industry'] ?? 'business')) . ' hero image'; }
        try {
            $res = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class)->generate([
                'workspace_id' => $wsId, 'user_prompt' => $prompt, 'source' => 'builder',
                'asset_type' => 'website_hero', 'platform' => 'web', 'aspect_ratio' => '16:9',
            ]);
        } catch (\Throwable $e) {
            $res = ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
        }
        if (empty($res['success']) || empty($res['url'])) {
            $why = mb_substr(trim((string) ($res['message'] ?? $res['error'] ?? 'no reason given')), 0, 90);
            Log::warning('[Arthur] image generation unavailable', ['website' => $websiteId, 'error' => $res['error'] ?? null, 'detail' => $why]);
            $insufficient = ($res['error'] ?? '') === 'insufficient_credits';
            return ['success' => false, 'code' => $insufficient ? 'INSUFFICIENT_CREDITS' : 'IMAGE_UNAVAILABLE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => $insufficient
                    ? 'Generating an image needs ' . (int) ($res['credits_required'] ?? 2) . ' credits and this workspace does not have them yet. Nothing was changed.'
                    : "I couldn't generate that image right now — " . self::customerReason($why) . " Nothing was charged and nothing on your site changed. You can still click any image in the preview to upload your own."];
        }
        $url   = (string) $res['url'];
        // Same-origin images go in as a path, never with a host, so the export does not depend on the current domain.
        $pu = parse_url($url);
        if (! empty($pu['path']) && str_starts_with((string) $pu['path'], '/storage/') && (empty($pu['host']) || str_contains((string) $pu['host'], 'levelupgrowth'))) { $url = (string) $pu['path']; }
        $field = $target === 'about' ? 'about_image' : ($target === 'gallery' ? 'gallery_1_image' : 'hero_image');
        $placed = false;
        if ($isStatic) {
            try { $placed = $this->templates->updateField($websiteId, $field, $url); } catch (\Throwable $e) { Log::warning('[Arthur] generated image not placed: ' . $e->getMessage()); }
        }
        $tv[$field] = $url;
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        $charged = (int) ($res['credits'] ?? $res['credits_charged'] ?? 0);
        Log::info('[Arthur] image generated at edit time', ['website' => $websiteId, 'field' => $field, 'placed' => $placed, 'asset' => $res['asset_id'] ?? null, 'credits' => $charged]);
        if (! $placed) {
            return ['success' => false, 'code' => 'IMAGE_NOT_PLACED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => $charged, 'url' => $url,
                'message' => "I generated the image (it is in your Media Library) but this design has no {$target} image slot I can put it in. Click any image in the preview to use it there."];
        }
        return ['success' => true, 'kind' => 'image', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $charged, 'url' => $url,
            'message' => "Done — I generated a new {$target} image for {$site->name} and put it in place." . ($charged > 0 ? " {$charged} credit" . ($charged === 1 ? '' : 's') . ' (image service).' : '')];
    }


    /* ═══════════════ STUDIO → ARTHUR (2026-09-14) — video, text over an image, background / object removal ═══════════════ */

    /** The image currently shown in a site slot: the stored variable first, then the export itself. */
    private function siteImageUrl(int $websiteId, string $field, array $tv): string
    {
        // What the page SHOWS wins over what the record remembers: a crop, a click-to-replace or an earlier undo can
        // leave the record pointing at an image that is no longer on the page (proven on fixture 411).
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        $html  = is_file($index) ? (string) @file_get_contents($index) : '';
        if ($html === '') { return ''; }
        if (preg_match('~<[^>]*data-field="' . preg_quote($field, '~') . '"[^>]*>~i', $html, $m)) {
            $tag = $m[0];
            if (preg_match('~url\((["\']?)([^)"\']+)\1\)~i', $tag, $u1)) { return trim($u1[2]); }
            if (preg_match('~\ssrc="([^"]+)"~i', $tag, $u2)) { return trim($u2[1]); }
            $pos = strpos($html, $tag);
            if (preg_match('~<img[^>]*\ssrc="([^"]+)"~i', substr($html, (int) $pos, 1500), $u3)) { return trim($u3[1]); }
        }
        $u = trim((string) ($tv[$field] ?? ''));
        return ($u !== '' && ! str_starts_with($u, '{{')) ? $u : '';
    }

    /**
     * A site image as a file on the public disk. Our own /storage/ URLs map straight to a path; anything else is
     * fetched once (15 MB cap, raster only) into ai-images/{ws}/, the same shape Studio uses when it ingests.
     * @return array{path:?string, width:int, height:int, error:?string}
     */
    private function siteImageOnDisk(int $wsId, string $url): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $u = trim($url);
        if ($u === '') { return ['path' => null, 'width' => 0, 'height' => 0, 'error' => 'no_image']; }
        $p = parse_url($u) ?: [];
        $pathPart = (string) ($p['path'] ?? $u);
        $path = null;
        $ours = empty($p['host']) || str_contains((string) $p['host'], 'levelupgrowth') || str_contains((string) config('app.url'), (string) $p['host']);
        if ($ours && preg_match('~^/?storage/(.+)$~', $pathPart, $m)) {
            $cand = $m[1];
            if ($disk->exists($cand)) { $path = $cand; }
        }
        if ($path === null && ! empty($p['host']) && in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 25,
                CURLOPT_MAXFILESIZE => 15728640, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $bytes = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($bytes === false || $code !== 200 || $bytes === '' || strlen($bytes) > 15728640) { return ['path' => null, 'width' => 0, 'height' => 0, 'error' => 'download_failed']; }
            $info = @getimagesizefromstring($bytes);
            $ext  = $info ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
            if ($ext === null) { return ['path' => null, 'width' => 0, 'height' => 0, 'error' => 'not_a_raster_image']; }
            $path = 'ai-images/' . $wsId . '/ingest-' . substr(hash('sha256', $u . '|' . strlen($bytes)), 0, 24) . '.' . $ext;
            $disk->put($path, $bytes);
        }
        if ($path === null) { return ['path' => null, 'width' => 0, 'height' => 0, 'error' => 'not_found']; }
        $info = @getimagesize(storage_path('app/public/' . $path));
        return ['path' => $path, 'width' => (int) ($info[0] ?? 0), 'height' => (int) ($info[1] ?? 0), 'error' => null];
    }

    /** The Media Library row for a site image, created on first use in Studio's ingest shape, so the kernel can version it. */
    private function assetForSiteImage(int $wsId, string $url, array $onDisk): ?int
    {
        $existing = DB::table('assets')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->where(function ($q) use ($url, $onDisk) {
                $q->where('url', $url);
                if (! empty($onDisk['path'])) { $q->orWhere('storage_path', $onDisk['path']); }
            })->orderByDesc('id')->value('id');
        if ($existing) { return (int) $existing; }
        if (empty($onDisk['path'])) { return null; }
        $abs  = storage_path('app/public/' . $onDisk['path']);
        $mime = (string) (@mime_content_type($abs) ?: 'image/jpeg');
        $id = DB::table('assets')->insertGetId([
            'workspace_id' => $wsId, 'type' => 'image', 'title' => 'Website image', 'provider' => 'Imported', 'model' => 'Imported', 'status' => 'completed',
            'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($onDisk['path']), 'storage_path' => $onDisk['path'], 'mime_type' => $mime,
            'width' => (int) $onDisk['width'], 'height' => (int) $onDisk['height'], 'version' => 1, 'edit_mode' => 'import',
            'metadata_json' => json_encode(['imported' => true, 'ingest_source_url' => $url, 'edit_origin' => 'arthur_site_image', 'workspace_id' => $wsId, 'imported_at' => now()->toIso8601String()]),
            'tags_json' => json_encode(['imported', 'website']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('assets')->where('id', $id)->update(['root_asset_id' => $id]);
        return (int) $id;
    }

    /**
     * What a customer is told when a provider says no. The raw text (provider name, HTTP code, JSON) is for our
     * log; the customer hears what it means for them. Unknown reasons become "temporarily unavailable".
     */
    private static function customerReason(string $raw): string
    {
        $r = strtolower($raw);
        // OWNER RULE 2026-09-14: generic maintenance wording, never a vendor, never an upstream code.
        if (preg_match('/safety|policy|moderation|blocked/', $r)) {
            return 'the request was declined by the content policy — try describing the image differently.';
        }
        return 'that part of the service is undergoing maintenance. Please try again shortly.';
    }

    /** One honest sentence for a kernel or provider refusal: plan, credits, approval, or the provider itself. */
    private function honestKernelRefusal(array $res, array $data, array $plan, string $what, int $cost): array
    {
        $code = (string) ($res['code'] ?? $data['code'] ?? '');
        $err  = trim((string) ($res['error'] ?? $data['error'] ?? $data['message'] ?? ''));
        if ($code === 'PLAN_GATED') {
            $msg = "I can {$what} on the Pro plan — this workspace's plan does not include the image and video studio yet, so nothing was changed.";
        } elseif ($code === 'NO_CREDITS') {
            $msg = "To {$what} I need {$cost} credits and this workspace does not have them yet. Nothing was changed.";
        } elseif (str_contains(strtolower($code . ' ' . $err), 'approval')) {
            $msg = 'That request is waiting for approval in your workspace before it runs — nothing has changed yet.';
        } else {
            $msg = "I couldn't {$what} right now — " . self::customerReason($err) . ' Nothing was charged and nothing on your site changed.';
        }
        Log::warning('[Arthur] studio capability refused', ['code' => $code, 'error' => mb_substr($err, 0, 200), 'what' => $what]);
        return ['success' => false, 'code' => $code !== '' ? $code : 'STUDIO_UNAVAILABLE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0, 'message' => $msg];
    }

    /**
     * TEXT OVER AN IMAGE — the studio's overlay renderer (headless Chromium, real typography, brand palette) writes
     * the customer's words across the hero or about image; the composed image replaces the slot. No provider, 1 credit.
     */
    private function overlayTextOnSiteImage(int $wsId, int $websiteId, string $request, object $site, array $plan, bool $isStatic, array $tv): array
    {
        $target = (string) ($plan['target'] ?? 'hero');
        $field  = $target === 'about' ? 'about_image' : 'hero_image';
        $text   = '';
        if (preg_match('/["“”]([^"“”]{2,120})["“”]/u', $request, $qm)) { $text = trim($qm[1]); }
        elseif (preg_match("/'([^']{2,120})'/u", $request, $qm2)) { $text = trim($qm2[1]); }
        elseif (preg_match('/\b(?:saying|that says|reading|with the words?|the words?|the text)\s*[:\-]?\s*(.{2,120}?)(?:\s+(?:on|over|onto|across)\b|[.!]?$)/iu', $request, $sm)) { $text = trim($sm[1], " \t\"'"); }
        if ($text === '') {
            return ['success' => false, 'code' => 'NEEDS_TEXT', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'Tell me the exact words in quotes and I will write them across the image — for example: put "Grand Opening" on the hero image.'];
        }
        if (! $isStatic) {
            return ['success' => false, 'code' => 'NOT_STATIC', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'Text on images is for template sites; this site is rendered live, so edit its hero copy instead.'];
        }
        $url = $this->siteImageUrl($websiteId, $field, $tv);
        if ($url === '') {
            return ['success' => false, 'code' => 'NO_IMAGE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => "I could not find a {$target} image on {$site->name} to write on."];
        }
        $disk = $this->siteImageOnDisk($wsId, $url);
        if (empty($disk['path']) || $disk['width'] < 50 || $disk['height'] < 50) {
            return ['success' => false, 'code' => 'IMAGE_UNREADABLE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'I could not read that image file (' . ($disk['error'] ?? 'unreadable') . '), so nothing was changed.'];
        }
        $w = min(1600, $disk['width']);
        $h = (int) round($disk['height'] * ($w / max(1, $disk['width'])));
        $palette = array_values(array_filter([$tv['primary_color'] ?? null, $tv['secondary_color'] ?? null, $tv['accent_color'] ?? null]));
        $res = app(\App\Core\ImageIntelligence\ImageOverlayRenderer::class)->render($disk['path'], ['headline' => $text, 'supporting_copy' => [], 'color_palette' => $palette], $w, $h, $wsId);
        if (empty($res['success']) || empty($res['url'])) {
            Log::warning('[Arthur] overlay render failed', ['website' => $websiteId, 'error' => $res['error'] ?? null]);
            return ['success' => false, 'code' => 'RENDER_FAILED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'I could not render the text onto the image just now (' . ($res['error'] ?? 'render failed') . '); nothing was changed.'];
        }
        $newUrl = (string) $res['url'];
        $pp = parse_url($newUrl);
        if (! empty($pp['path'])) { $newUrl = $pp['path']; }   // same-origin path, so the export never depends on the host name
        $placed = false;
        try { $placed = $this->templates->updateField($websiteId, $field, $newUrl); } catch (\Throwable $e) { Log::warning('[Arthur] overlay not placed: ' . $e->getMessage()); }
        if (! $placed) {
            return ['success' => false, 'code' => 'NOT_PLACED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => "I rendered the image but could not place it in the {$target} slot, so nothing on the site changed."];
        }
        $tv[$field] = $newUrl;
        $tv[$field . '_overlay'] = ['text' => $text, 'source' => $url, 'at' => now()->toIso8601String()];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        app(\App\Core\Billing\CreditService::class)->debit($wsId, (int) $plan['credits'], 'builder_arthur_overlay', $websiteId, ['field' => $field, 'text' => mb_substr($text, 0, 120)]);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        Log::info('[Arthur] text overlaid on site image', ['website' => $websiteId, 'field' => $field, 'text' => $text, 'url' => $newUrl]);
        return ['success' => true, 'kind' => 'overlay', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) $plan['credits'], 'url' => $newUrl,
            'message' => "Done — I wrote “{$text}” across the {$target} image on {$site->name}. Undo puts the original back. {$plan['credits']} credit."];
    }

    /**
     * BACKGROUND / OBJECT REMOVAL — the studio's governed edit_image capability (kernel: plan, credits, lineage,
     * versioning) on the site's own image; the edited child replaces the slot, the original stays in the library.
     */
    private function editSiteImage(int $wsId, int $websiteId, string $request, object $site, array $plan, bool $isStatic, array $tv, array $ctx): array
    {
        $target = (string) ($plan['target'] ?? 'hero');
        $field  = $target === 'about' ? 'about_image' : 'hero_image';
        $op     = (string) ($plan['operation'] ?? 'remove_background');
        if (! $isStatic) {
            return ['success' => false, 'code' => 'NOT_STATIC', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'Image edits are for template sites; this site is rendered live.'];
        }
        $url = $this->siteImageUrl($websiteId, $field, $tv);
        if ($url === '') {
            return ['success' => false, 'code' => 'NO_IMAGE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => "I could not find a {$target} image on {$site->name} to edit."];
        }
        $disk    = $this->siteImageOnDisk($wsId, $url);
        $assetId = $this->assetForSiteImage($wsId, $url, $disk);
        if (! $assetId) {
            return ['success' => false, 'code' => 'IMAGE_UNREADABLE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'I could not import that image into your Media Library (' . ($disk['error'] ?? 'unreadable') . '), so nothing was changed.'];
        }
        if ($op === 'remove_background') {
            $prompt = 'Remove the background entirely and place the main subject on a clean, solid pure-white background. Keep the subject itself unchanged.';
            $done   = 'removed the background of';
        } else {
            $obj = trim((string) ($plan['object'] ?? ''));
            if ($obj === '') {
                return ['success' => false, 'code' => 'NEEDS_OBJECT', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                    'message' => 'Tell me which object to remove — for example: remove the car from the hero image.'];
            }
            $prompt = 'Remove the ' . $obj . ' from the image completely, filling the space naturally and seamlessly so it looks like it was never there, matching the surrounding lighting and texture.';
            $done   = "removed the {$obj} from";
        }
        $res  = app(\App\Core\EngineKernel\EngineExecutionService::class)->execute($wsId, 'creative', 'edit_image',
            ['source_asset_id' => $assetId, 'prompt' => $prompt, 'selection_type' => 'full', 'idempotency_key' => 'arthur-edit-' . $websiteId . '-' . $field . '-' . substr(md5($prompt . $assetId), 0, 12)],
            ['user_id' => $ctx['user_id'] ?? null, 'source' => 'manual', 'origin' => 'arthur_chat']);   // manual + user_id = the customer's own click authorises the spend
        $data = (is_array($res) && isset($res['data']) && is_array($res['data'])) ? $res['data'] : (is_array($res) ? $res : []);
        $ok   = (bool) ($res['success'] ?? ($data['success'] ?? false));
        $new  = (string) ($data['url'] ?? '');
        if (! $ok || $new === '') { return $this->honestKernelRefusal($res, $data, $plan, 'edit that image', 2); }
        $pp  = parse_url($new);
        $rel = (! empty($pp['path']) && str_starts_with((string) $pp['path'], '/storage/')) ? (string) $pp['path'] : $new;
        $placed = false;
        try { $placed = $this->templates->updateField($websiteId, $field, $rel); } catch (\Throwable $e) { Log::warning('[Arthur] edited image not placed: ' . $e->getMessage()); }
        $tv[$field] = $rel;
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        $charged = 2;
        Log::info('[Arthur] site image edited via studio', ['website' => $websiteId, 'field' => $field, 'op' => $op, 'asset' => $assetId, 'child' => $data['asset_id'] ?? null, 'placed' => $placed]);
        if (! $placed) {
            return ['success' => false, 'code' => 'NOT_PLACED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => $charged, 'url' => $rel,
                'message' => "The studio {$done} the image (it is in your Media Library) but I could not place it in the {$target} slot. Click the image in the preview to use it."];
        }
        return ['success' => true, 'kind' => 'image_edit', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $charged, 'url' => $rel,
            'message' => "Done — I {$done} the {$target} image on {$site->name}. The original is kept in your Media Library and Undo puts it back. {$charged} credits (image studio)."];
    }

    /**
     * VIDEO GENERATION — the studio's governed generate_video (kernel: plan, credits, approval; MiniMax; finalised by
     * video:finalize-pending). Arthur starts it, answers at once, and PlaceGeneratedVideoJob puts the finished clip
     * on the home page. 8 credits at kickoff, refunded by the studio if the render fails.
     */
    private function generateSiteVideo(int $wsId, int $websiteId, string $request, object $site, array $plan, bool $isStatic, array $tv, string $industry, array $ctx): array
    {
        if (! $isStatic) {
            return ['success' => false, 'code' => 'NOT_STATIC', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => 'Generated videos go on template sites; this site is rendered live.'];
        }
        $name = (string) $site->name;
        $ind  = str_replace('_', ' ', $industry !== '' ? $industry : (string) ($tv['industry'] ?? 'business'));
        $loc  = trim((string) ($tv['location'] ?? $tv['contact_city'] ?? ''));
        $prompt = trim((string) preg_replace('/^(?:please\s+)?(?:can you\s+)?(?:generate|create|make|produce|render|shoot|design)\s+(?:me\s+|us\s+)?(?:an?\s+|the\s+)?(?:short\s+|quick\s+|new\s+)?(?:promo\s+|intro\s+|hero\s+|background\s+)?(?:video|clip|reel|promo)\s*(?:of|for|showing|about|that shows)?\s*/i', '', $request));
        $prompt = trim((string) preg_replace('/\b(?:for|on)\s+(?:my|the|our)\s+(?:site|website|home ?page|business)\b\.?$/i', '', $prompt));
        if ($prompt === '' || mb_strlen($prompt) < 8 || preg_match('/^(my|our|the)\s+(site|website|business|shop|company)$/i', $prompt)) {
            $prompt = "Cinematic promotional clip for {$name}, a {$ind}" . ($loc !== '' ? " in {$loc}" : '') . ': warm natural light, slow camera movement, inviting atmosphere, no text, no logos.';
        } else {
            $prompt .= " — for {$name}, a {$ind}. Cinematic, natural light, no text, no logos.";
        }
        $res  = app(\App\Core\EngineKernel\EngineExecutionService::class)->execute($wsId, 'creative', 'generate_video',
            ['prompt' => $prompt, 'duration' => 6, 'aspect_ratio' => '16:9'],
            ['user_id' => $ctx['user_id'] ?? null, 'source' => 'manual', 'origin' => 'arthur_chat']);   // manual + user_id = the customer's own click authorises the spend
        $data = (is_array($res) && isset($res['data']) && is_array($res['data'])) ? $res['data'] : (is_array($res) ? $res : []);
        $ok   = (bool) ($res['success'] ?? ($data['success'] ?? false));
        $assetId = (int) ($data['asset_id'] ?? $data['id'] ?? 0);
        $approvalId = (int) ($res['approval_id'] ?? $data['approval_id'] ?? 0);
        if ($ok && $assetId <= 0 && ($approvalId > 0 || ! empty($res['pending_approval']) || ($res['code'] ?? '') === 'AWAITING_APPROVAL')) {
            // The workspace reviews video generation before it runs. The clip will exist only after someone approves,
            // so the watcher adopts it by prompt once it appears, and Arthur says exactly where the customer stands.
            $tv['pending_video'] = ['asset_id' => 0, 'approval_id' => $approvalId, 'requested_at' => now()->toIso8601String(), 'prompt' => $prompt, 'status' => 'awaiting_approval'];
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            \App\Jobs\PlaceGeneratedVideoJob::dispatch($wsId, $websiteId, 0, $prompt)->delay(now()->addSeconds(60));
            Log::info('[Arthur] video generation awaiting approval', ['website' => $websiteId, 'approval' => $approvalId]);
            return ['success' => true, 'kind' => 'video', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0, 'approval_id' => $approvalId, 'pending' => true,
                'message' => "Video generation in this workspace needs a quick approval first — it is waiting under Approvals. Once approved, the studio renders a 6-second clip (about two minutes, 8 credits) and I add it to {$name}'s home page automatically."];
        }
        if (! $ok || $assetId <= 0) { return $this->honestKernelRefusal($res, $data, $plan, 'make a video', 8); }
        $tv['pending_video'] = ['asset_id' => $assetId, 'requested_at' => now()->toIso8601String(), 'prompt' => mb_substr($prompt, 0, 200), 'status' => 'rendering'];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        \App\Jobs\PlaceGeneratedVideoJob::dispatch($wsId, $websiteId, $assetId, $prompt)->delay(now()->addSeconds(45));
        Log::info('[Arthur] video generation started', ['website' => $websiteId, 'asset' => $assetId, 'prompt' => mb_substr($prompt, 0, 160)]);
        return ['success' => true, 'kind' => 'video', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => 8, 'asset_id' => $assetId, 'pending' => true,
            'message' => "Your video is rendering now — about two minutes for a 6-second clip. I'll add it to {$name}'s home page just before the contact section the moment it is ready; refresh the preview then. 8 credits (video studio)."];
    }

    /** Called by PlaceGeneratedVideoJob once the studio has finished: put the finished clip on the home page. */
    public function placeVideoOnSite(int $wsId, int $websiteId, string $videoUrl, ?string $heading = null): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found']; }
        if (! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) { return ['success' => false, 'error' => 'not_static']; }
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $industry = $this->templates->industryOf((string) ($settings['template'] ?? $settings['industry'] ?? $site->template_industry ?? ''));
        app(TemplateService::class)->snapshotToHistory($websiteId, 'video_placed');
        $identity = $this->siteIdentity($site, $tv, (string) $industry);
        $sec = $this->defaultSectionSpec('video_embed', $identity, $tv, $videoUrl);
        if ($heading) { $sec['heading'] = $heading; }
        $sec['subheading'] = 'A short film made for ' . $site->name . '.';
        $renderer = app(BuilderRenderer::class);
        $brand    = ['primary' => $tv['primary_color'] ?? '#6C5CE7', 'secondary' => $tv['secondary_color'] ?? '#00E5A8', 'accent' => $tv['accent_color'] ?? '#F4F7FB'];
        $html = $this->adoptTemplateTypography($renderer->renderSection($sec, $brand, (array) $site));
        if (trim($html) === '') { return ['success' => false, 'error' => 'render_empty']; }
        $html = $this->roleifyForSite($html, $websiteId, $brand);   // RISK-0191 U1
        $__asf = \App\Engines\Builder\Support\AddedSectionFields::assign($html, 'video_embed'); $html = $__asf['html']; $this->mirrorAddedFieldValues($websiteId, $__asf['values']);   // U3
        $html = preg_replace('/(<section\b[^>]*\s)id="(?:booking|contact|services|team|gallery|testimonials|hero|faq|pricing)"/i', '$1data-old-id="$2"', $html) ?? $html;
        $html = '<section data-block="added_video_embed" id="lu-video_embed" ' . \App\Engines\Builder\Support\PaletteRoles::RENDERED_MARK . '="' . \App\Engines\Builder\Support\PaletteRoles::RENDERED_MARK_VERSION . '" style="padding:24px 0;scroll-margin-top:100px">' . $html . '</section>';
        try { $this->templates->removeSplicedSection($websiteId, 'video_embed'); } catch (\Throwable $e) {}   // replace, never stack
        $this->templates->rememberSpliced($websiteId, 'video_embed', $html, 'contact', 'before');
        $placed = $this->templates->spliceSectionIntoHome($websiteId, $html, 'contact', 'before') !== null;
        if ($placed) { try { app(\App\Engines\Builder\Services\BuilderService::class)->appendSectionToHomePage($websiteId, $sec); } catch (\Throwable $e) {} }
        unset($tv['pending_video']);
        $tv['video_url'] = $videoUrl;
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        Log::info('[Arthur] generated video placed on site', ['website' => $websiteId, 'placed' => $placed, 'url' => $videoUrl]);
        return ['success' => $placed, 'url' => $videoUrl, 'error' => $placed ? null : 'not_placed'];
    }


    /* ═══════════════ LAYOUT SWITCHER (2026-09-14, Owner: "allow users to pick a template on arthur editor, like the layout and structure") ═══════════════ */

    /** Credits charged when a switch must write copy the old layout never had (one coverage pass, the build's own). */
    public const LAYOUT_FILL_CREDITS = 5;

    /** The design directory a site is built from, and its industry. */
    private function siteDesignSlug(object $site, array $settings): string
    {
        foreach ([(string) ($settings['template'] ?? ''), (string) ($settings['industry'] ?? ''), (string) ($site->template_industry ?? ''), (string) ($site->template ?? '')] as $cand) {
            $cand = preg_replace('/[^a-z0-9_]/', '', strtolower($cand));
            if ($cand !== '' && is_file(storage_path("templates/{$cand}/manifest.json"))) { return $cand; }
        }
        return '';
    }

    /** Copy variables of a manifest: the same rule the coverage pass uses (text with a real sentence as default). */
    private static function layoutCopyKeys(array $manifest): array
    {
        $skipKey   = '/(image|img|photo|logo|url|color|colour|display|icon|bg|background|style|css|href|src|width|height|dim|ratio|font|hex|locale|canonical|slug|og_image)/i';
        $structKey = '/(nav|menu|link|button|_cta$|^cta|tab|breadcrumb|^logo|_label$)/i';
        $out = [];
        foreach (($manifest['variables'] ?? []) as $k => $spec) {
            if (! is_array($spec)) { continue; }
            $type = strtolower((string) ($spec['type'] ?? ''));
            if (in_array($type, ['color', 'image', 'url', 'file', 'media', 'number', 'bool', 'boolean'], true)) { continue; }
            $key = (string) $k;
            if (preg_match($skipKey, $key) || preg_match($structKey, $key)) { continue; }
            $def = $spec['default'] ?? null;
            if (! is_string($def)) { continue; }
            $def = trim($def);
            if ((strlen($def) < 15 || strpos($def, ' ') === false) && ! preg_match(self::CATALOGUE_KEY, $key)) { continue; }
            if (preg_match('~^(/|https?:|\#|display:)~i', $def)) { continue; }
            $out[$key] = $def;
        }
        return $out;
    }

    /**
     * The other layouts of this site's design family, each with what carries over and what a switch would cost.
     * Screenshots are the ones shot for the home-page carousel.
     */
    public function layoutsFor(int $wsId, int $websiteId): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found', 'layouts' => []]; }
        if (! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) { return ['success' => false, 'error' => 'not_static', 'message' => 'Layouts apply to template sites.', 'layouts' => []]; }
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $current  = $this->siteDesignSlug($site, $settings);
        $industry = $current !== '' ? $this->templates->industryOf($current) : '';
        $out = [];
        foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $mf) {
            $slug = basename(dirname($mf));
            $m = json_decode((string) @file_get_contents($mf), true);
            if (! is_array($m)) { continue; }
            if (array_key_exists('is_active', $m) && ! $m['is_active'] && $slug !== $current) { continue; }
            // CATALOGUE VERIFICATION (2026-09-20): the nine clone bases (one file under nine names, CLONE_OVERRIDE /
            // TemplateSelector::CLONES) are never offered at creation — the Layout switcher must not offer them either,
            // unless the site already sits on one (then it stays listed as the current design).
            if (isset(self::CLONE_OVERRIDE[$slug]) && $slug !== $current) { continue; }
            $ind = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['industry'] ?? $slug)));
            if ($ind !== $industry) { continue; }
            $copy = self::layoutCopyKeys($m);
            $have = 0; $gaps = 0;
            foreach ($copy as $key => $def) {
                $cur = $tv[$key] ?? null;
                if (is_string($cur) && trim($cur) !== '' && trim($cur) !== $def) { $have++; } else { $gaps++; }
            }
            $total = count($copy);
            $shot  = "/assets/product/templates/{$slug}.webp";
            $out[] = [
                'slug' => $slug, 'name' => (string) ($m['name'] ?? ucwords(str_replace('_', ' ', $slug))), 'industry' => $ind,
                'current' => $slug === $current,
                'screenshot' => is_file(public_path('marketing-next/dist-root' . $shot)) ? $shot : null,
                'carry_over' => $total > 0 ? (int) round(100 * $have / $total) : 100,
                'gaps' => $slug === $current ? 0 : $gaps,
                'credits' => ($slug === $current || $gaps === 0) ? 0 : self::LAYOUT_FILL_CREDITS,
            ];
        }
        usort($out, fn ($a, $b) => ((int) $b['current'] <=> (int) $a['current']) ?: strcmp($a['name'], $b['name']));
        return ['success' => true, 'current' => $current, 'industry' => $industry, 'layouts' => $out];
    }

    /**
     * Render another design of the same family with THIS site's content. Text the new design needs and the site
     * never had is filled by the build's own coverage pass when $fillGaps is true, else it keeps the design's copy.
     * @return array{html:string, variables:array, filled:int}
     */
    private function composeLayout(int $wsId, int $websiteId, object $site, array $settings, array $tv, string $design, bool $fillGaps): array
    {
        $manifest = $this->templates->getManifest($design) ?: [];
        $industry = $this->templates->industryOf($design);
        $variables = [];
        foreach (($manifest['variables'] ?? []) as $k => $spec) {
            $variables[(string) $k] = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec;
        }
        // the site's own content wins wherever the names match; everything else the site remembers rides along
        foreach ($tv as $k => $v) { if (is_scalar($v) || is_array($v)) { $variables[(string) $k] = $v; } }
        // …and what the page SHOWS wins over what the record remembers (inline edits, restores and old tests can leave
        // the record behind; the export is what the customer has been looking at).
        foreach ($this->harvestExportFields($websiteId) as $k => $v) { $variables[$k] = $v; }
        $variables['business_name'] = (string) ($tv['business_name'] ?? $site->name);
        // the design's own colour roles take the site's palette, exactly as a build would paint them
        $colors = array_filter(['primary' => $tv['primary_color'] ?? null, 'secondary' => $tv['secondary_color'] ?? null, 'accent' => $tv['accent_color'] ?? null]);
        if ($colors !== []) { $this->applyBrandColors($variables, $manifest, $colors); }
        $filled = 0;
        if ($fillGaps) {
            $svc = [];
            foreach ($tv as $k => $v) { if (preg_match('/^service_\d+_(title|name)$/', (string) $k) && is_string($v) && trim($v) !== '') { $svc[] = trim($v); } }
            $location = (string) ($tv['location'] ?? $tv['city'] ?? $tv['contact_city'] ?? '');
            $data = ['business_name' => $variables['business_name'], 'industry' => $industry, 'location' => $location, 'services' => $svc,
                'description' => (string) ($tv['meta_description'] ?? $tv['business_tagline'] ?? '')];
            $before = $variables;
            try {
                $variables = $this->fillTemplateTextCoverage($variables, $manifest, $data, $industry, implode(', ', $svc), $location !== '' ? $location : 'your area', false);
            } catch (\Throwable $e) { Log::warning('[Arthur] layout coverage pass failed: ' . $e->getMessage()); }
            foreach (self::layoutCopyKeys($manifest) as $key => $def) {
                if (($before[$key] ?? null) !== ($variables[$key] ?? null)) { $filled++; }
            }
        }
        $data = ['business_name' => $variables['business_name']];
        $removeBlocks = \App\Engines\Builder\Support\TemplateArchetypes::blocksToRemove($industry, \App\Engines\Builder\Support\TemplateArchetypes::looksEstablished($data));
        $html = \App\Engines\Builder\Support\TemplateArchetypes::removeBlocks($this->scrubSampleStaff($this->templates->render($design, $variables, $websiteId)), $removeBlocks);
        $html = $this->templates->stripDanglingNavAnchors($html);
        // the design this export was rendered from, readable by the editor and by a later switch
        $html = preg_replace('~<!-- lug-design:[a-z0-9_]+ -->\s*~', '', $html) ?? $html;
        $html = (stripos($html, '</head>') !== false) ? str_ireplace('</head>', "<!-- lug-design:{$design} -->\n</head>", $html) : $html . "<!-- lug-design:{$design} -->";
        return ['html' => $html, 'variables' => $variables, 'filled' => $filled];
    }

    /**
     * Every data-field value the export currently shows: text fields as their visible text, image fields as their
     * URL. Nested wrappers (a hero that contains other fields) are skipped so a container never overwrites its parts.
     * @return array<string,string>
     */
    /** The transparent 1×1 every text-logo design carries in its logo <img>: never a logo, never harvested. */
    private const BLANK_LOGO = 'width%3D%221%22%20height%3D%221%22';

    private function harvestExportFields(int $websiteId): array
    {
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($index)) { return []; }
        $html = (string) @file_get_contents($index);
        if ($html === '') { return []; }
        $out = [];
        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            $xp = new \DOMXPath($dom);
            foreach ($xp->query('//*[@data-field]') as $el) {
                if (! $el instanceof \DOMElement) { continue; }
                $name = trim((string) $el->getAttribute('data-field'));
                if ($name === '' || ! preg_match('/^[a-z0-9_]+$/i', $name)) { continue; }
                $isImg = str_ends_with($name, '_image') || $name === 'logo_url' || str_contains($name, 'image_') || str_contains($name, '_img');
                if ($isImg) {
                    $url = '';
                    if (strtolower($el->nodeName) === 'img') { $url = (string) $el->getAttribute('src'); }
                    if ($url === '') { $img = $el->getElementsByTagName('img')->item(0); if ($img instanceof \DOMElement) { $url = (string) $img->getAttribute('src'); } }
                    if ($url === '' && preg_match('~url\((["\']?)([^)"\']+)\1\)~i', (string) $el->getAttribute('style'), $m)) { $url = $m[2]; }
                    $url = trim($url);
                    if ($url !== '' && ! str_starts_with($url, '{{') && ! str_contains($url, self::BLANK_LOGO)) { $out[$name] = $url; }
                    continue;
                }
                if ($xp->query('.//*[@data-field]', $el)->length > 0) { continue; }   // a wrapper of other fields
                $text = trim(preg_replace('/\s+/u', ' ', (string) $el->textContent) ?? '');
                if ($text !== '' && ! str_starts_with($text, '{{') && mb_strlen($text) <= 2000) { $out[$name] = $text; }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] harvestExportFields: ' . $e->getMessage());
        }
        return $out;
    }

    /** Free: the chosen layout with the site's content, finished exactly as a deploy would be, for the editor's iframe. */
    public function previewLayout(int $wsId, int $websiteId, string $design): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found']; }
        $design = preg_replace('/[^a-z0-9_]/', '', strtolower($design));
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $current  = $this->siteDesignSlug($site, $settings);
        if ($design === '' || ! is_file(storage_path("templates/{$design}/manifest.json"))) { return ['success' => false, 'error' => 'unknown_design', 'message' => 'That layout does not exist.']; }
        if ($current !== '' && $this->templates->industryOf($design) !== $this->templates->industryOf($current)) { return ['success' => false, 'error' => 'other_family', 'message' => 'That layout belongs to a different design family. Ask Arthur to build a new site for it.']; }
        try {
            $c = $this->composeLayout($wsId, $websiteId, $site, $settings, $tv, $design, false);
            $html = $this->templates->finishForPreview($websiteId, $c['html']);
            return ['success' => true, 'design' => $design, 'html' => $html];
        } catch (\Throwable $e) {
            Log::warning('[Arthur] layout preview failed', ['website' => $websiteId, 'design' => $design, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'render_failed', 'message' => 'That layout could not be rendered just now; nothing was changed.'];
        }
    }

    /** Apply: snapshot → compose (filling gaps if needed, charged) → deploy → verify from the file → record. */
    public function applyLayout(int $wsId, int $websiteId, string $design, ?int $actorId = null): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found', 'message' => 'That website is not in this workspace.']; }
        if (! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) { return ['success' => false, 'error' => 'not_static', 'message' => 'Layouts apply to template sites.']; }
        $design = preg_replace('/[^a-z0-9_]/', '', strtolower($design));
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $current  = $this->siteDesignSlug($site, $settings);
        if ($design === '' || ! is_file(storage_path("templates/{$design}/manifest.json"))) { return ['success' => false, 'error' => 'unknown_design', 'message' => 'That layout does not exist.']; }
        if ($design === $current) { return ['success' => false, 'error' => 'already_current', 'message' => 'That is already the layout in use.']; }
        if ($current !== '' && $this->templates->industryOf($design) !== $this->templates->industryOf($current)) { return ['success' => false, 'error' => 'other_family', 'message' => 'That layout belongs to a different design family. Ask Arthur to build a new site for it.']; }
        $info = null;
        foreach ($this->layoutsFor($wsId, $websiteId)['layouts'] ?? [] as $l) { if ($l['slug'] === $design) { $info = $l; } }
        $cost = (int) ($info['credits'] ?? 0);
        $credits = app(\App\Core\Billing\CreditService::class);
        if ($cost > 0 && ! $credits->hasBalance($wsId, $cost)) {
            return ['success' => false, 'error' => 'insufficient_credits', 'message' => "Switching to this layout fills " . (int) ($info['gaps'] ?? 0) . " texts the old one never had, which needs {$cost} credits. Nothing was changed."];
        }
        app(TemplateService::class)->snapshotToHistory($websiteId, 'layout_switch');
        try {
            $c = $this->composeLayout($wsId, $websiteId, $site, $settings, $tv, $design, $cost > 0);
            $this->templates->deploy($websiteId, $c['html']);
        } catch (\Throwable $e) {
            Log::error('[Arthur] layout apply failed', ['website' => $websiteId, 'design' => $design, 'error' => $e->getMessage()]);
            app(TemplateService::class)->undoLatest($websiteId);
            return ['success' => false, 'error' => 'apply_failed', 'message' => 'That layout could not be applied, so I put the site back exactly as it was. Nothing was charged.'];
        }
        $now = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if (! str_contains($now, "<!-- lug-design:{$design} -->")) {
            app(TemplateService::class)->undoLatest($websiteId);
            Log::warning('[Arthur] layout apply did not verify; rolled back', ['website' => $websiteId, 'design' => $design]);
            return ['success' => false, 'error' => 'verify_failed', 'message' => 'The new layout did not land cleanly, so I put the site back exactly as it was. Nothing was charged.'];
        }
        $settings['template'] = $design;
        $settings['layout_switched_at'] = now()->toIso8601String();
        $settings['layout_previous'] = $current;
        DB::table('websites')->where('id', $websiteId)->update([
            'settings_json' => json_encode($settings), 'template_variables' => json_encode($c['variables']), 'updated_at' => now(),
        ]);
        $charged = 0;
        if ($cost > 0 && $c['filled'] > 0) {
            $credits->debit($wsId, $cost, 'builder_arthur_layout', $websiteId, ['design' => $design, 'from' => $current, 'filled' => $c['filled']]);
            $charged = $cost;
        }
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        Log::info('[Arthur] layout switched', ['website' => $websiteId, 'from' => $current, 'to' => $design, 'filled' => $c['filled'], 'credits' => $charged, 'actor' => $actorId]);
        $name = (string) ($info['name'] ?? ucwords(str_replace('_', ' ', $design)));
        return ['success' => true, 'design' => $design, 'from' => $current, 'filled' => $c['filled'], 'credits' => $charged,
            'message' => "Switched to the {$name} layout with your content" . ($c['filled'] > 0 ? ", and wrote {$c['filled']} texts the old layout never had ({$charged} credits)" : '') . '. Undo puts the old layout back.'];
    }


    /**
     * The curated palettes for THIS built site, each with the exact :root variables it would rewrite, so the
     * editor can preview a palette instantly in the iframe and the apply step writes the very same map.
     */
    public function palettesFor(int $wsId, int $websiteId): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found', 'palettes' => []]; }
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        if (empty($settings['industry']) && ! empty($tv['industry'])) { $settings['industry'] = (string) $tv['industry']; }
        $industry = $this->siteIndustrySlug($site, $settings);
        $style    = (string) ($tv['design_style'] ?? $settings['theme'] ?? '');
        $isStatic = is_file(storage_path("app/public/sites/{$websiteId}/index.html"));
        $CT = \App\Engines\Builder\Support\ColorTheme::class;
        $recommended = [];
        foreach ($CT::propose($style ?: null, $industry ?: null, 4) as $t) { $recommended[] = (string) ($t['id'] ?? ''); }
        $out = [];
        foreach ($CT::all() as $key => $theme) {
            $id = (string) ($theme['id'] ?? $key);
            $out[] = [
                'id' => $id, 'label' => (string) ($theme['label'] ?? ucwords(str_replace('_', ' ', $id))),
                'primary' => $theme['primary'], 'secondary' => $theme['secondary'], 'accent' => $theme['accent'],
                'bg' => $theme['bg'] ?? null, 'text' => $theme['text'] ?? null,
                'recommended' => in_array($id, $recommended, true),
                'vars' => $isStatic ? $this->themeVarsForSite($websiteId, $site, $settings, $theme) : [],
            ];
        }
        usort($out, fn ($a, $b) => ((int) $b['recommended'] <=> (int) $a['recommended']));
        return ['success' => true, 'current' => $tv['palette'] ?? null, 'is_static' => $isStatic, 'industry' => $industry,
            'palettes' => $out, 'live' => $isStatic ? self::siteColorVars($websiteId) : []];
    }

    /**
     * Apply a curated palette to a built site: snapshot → paint the template's own variables exactly as a
     * build would → contrast guard → verify from the file → persist. Free of charge: no model is involved.
     * On a verification miss the snapshot is put back and the answer says so.
     */
    public function applyPalette(int $wsId, int $websiteId, string $themeId, ?int $actorId = null): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
        if (! $site) { return ['success' => false, 'error' => 'not_found', 'message' => 'That website is not in this workspace.']; }
        $theme = \App\Engines\Builder\Support\ColorTheme::find($themeId);
        if (! $theme) { return ['success' => false, 'error' => 'unknown_palette', 'message' => 'That palette does not exist.']; }
        $label    = (string) ($theme['label'] ?? ucwords(str_replace('_', ' ', $themeId)));
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $isStatic = is_file(storage_path("app/public/sites/{$websiteId}/index.html"));
        $editor   = app(ArthurEditService::class);
        // One snapshot for both kinds of site: static sites keep the export, renderer sites keep the record.
        app(TemplateService::class)->snapshotToHistory($websiteId, 'palette');
        if ($isStatic) {
            $vars = $this->themeVarsForSite($websiteId, $site, $settings, $theme);
            if ($vars === []) { return ['success' => false, 'error' => 'no_palette_vars', 'message' => 'This design does not expose a colour palette I can switch.']; }
            // PALETTE ROLES (Owner 2026-09-18): the exported page's hard-coded colours become role variables (once,
            // idempotent) and the roles block is written into it, so the switch repaints the whole site.
            $__manifest = $this->siteManifest($site, $settings);
            $__roles = \App\Engines\Builder\Support\PaletteRoles::derive($theme, (string) ($__manifest['palette_scheme'] ?? 'light'));
            $vars = \App\Engines\Builder\Support\PaletteRoles::siteVarsForRoles($vars, $__manifest, $__roles, self::siteColorVars($websiteId));
            // the --lu-* roles ride along for the hover preview only; on apply the roles block is rewritten whole
            $vars = array_filter($vars, fn ($k) => ! str_starts_with((string) $k, '--lu-'), ARRAY_FILTER_USE_KEY);
            app(TemplateService::class)->roleifyStoredSections($websiteId);   // RISK-0191 U1: pre-roles stored sections convert on a palette change
            app(TemplateService::class)->refreshHomeAddedBlocks($websiteId);   // U3: added blocks on the export carry their field ids (before normalising, so they take the new fallbacks)
            \App\Engines\Builder\Support\PaletteRoles::normaliseExport($websiteId, $__roles, $__manifest);
            $res = $editor->applyStyleColors($websiteId, $vars);
            if ((int) ($res['applied'] ?? 0) === 0) {
                return ['success' => false, 'error' => 'not_applied', 'message' => 'The palette did not match any colour on this site.', 'missed' => $res['missed'] ?? []];
            }
            self::writeContrastGuard($websiteId, $vars);
            // Proof comes from the file, never from the reply.
            $now = self::siteColorVars($websiteId);
            $bad = [];
            foreach ($vars as $k => $v) {
                if (strtoupper((string) ($now[strtolower($k)] ?? '')) !== strtoupper((string) $v)) { $bad[] = $k; }
            }
            if ($bad !== []) {
                app(TemplateService::class)->undoLatest($websiteId);
                Log::warning('[Arthur] palette write did not verify; rolled back', ['website' => $websiteId, 'vars' => $bad]);
                return ['success' => false, 'error' => 'verify_failed', 'message' => 'I could not switch the palette cleanly, so I put the site back exactly as it was.'];
            }
        } else {
            $vars = ['primary' => $theme['primary'], 'secondary' => $theme['secondary'], 'accent' => $theme['accent']];
            $res  = $editor->applyStyleColors($websiteId, $vars);
        }
        // Re-read: applyStyleColors mirrors the vars into template_variables; layer on top of that, not over it.
        $tv = json_decode((string) (DB::table('websites')->where('id', $websiteId)->value('template_variables') ?: '{}'), true) ?: [];
        $tv['palette']         = (string) ($theme['id'] ?? $themeId);
        $tv['primary_color']   = $theme['primary'];
        $tv['secondary_color'] = $theme['secondary'];
        $tv['accent_color']    = $theme['accent'];
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        Log::info('[Arthur] palette applied', ['website' => $websiteId, 'palette' => $themeId, 'vars' => array_keys($vars), 'actor' => $actorId]);
        return ['success' => true, 'palette' => (string) ($theme['id'] ?? $themeId), 'label' => $label,
            'applied' => (int) ($res['applied'] ?? 0), 'vars' => $vars, 'credits' => 0,
            'message' => "Switched to {$label}. Undo puts the old colours back."];
    }

    /**
     * A theme painted onto THIS site's real :root variables, through the same painter every build uses
     * (manifest color_roles + the industry accent names), so a palette switch looks exactly like a build
     * with that palette would. Falls back to usage ranking for designs whose manifest names no palette var.
     * @return array<string,string> --css-var => #HEX
     */
    private function themeVarsForSite(int $websiteId, object $site, array $settings, array $theme): array
    {
        $have = self::siteColorVars($websiteId);
        if ($have === []) { return []; }
        $manifest = $this->siteManifest($site, $settings);
        $painted  = [];
        $this->applyBrandColors($painted, $manifest, ['primary' => $theme['primary'], 'secondary' => $theme['secondary'], 'accent' => $theme['accent']]);
        $vars = [];
        foreach ($painted as $name => $hex) {
            if (! is_string($hex) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) { continue; }
            $css = '--' . str_replace('_', '-', (string) $name);
            if (isset($have[$css])) { $vars[$css] = strtoupper($hex); }
        }
        if ($vars === []) {
            $vars = self::mapRolesToSiteVars($websiteId, ['primary' => $theme['primary'], 'secondary' => $theme['secondary'], 'accent' => $theme['accent']]);
        }
        foreach (['--cf1' => 'accent', '--cf2' => 'secondary', '--cf3' => 'primary'] as $cf => $role) {
            if (isset($have[$cf])) {
                $vars[$cf] = strtoupper((string) $theme[$role]);
                if (isset($have[$cf . 't'])) { $vars[$cf . 't'] = self::shiftLightness((string) $theme[$role], 1); }
            }
        }
        // the hover preview paints the same thing apply writes: the design's neutrals and the --lu-* roles
        // (on_accent, accent_text, …) the normalised CSS reads, so what the customer sees on hover is what they get
        $__roles = \App\Engines\Builder\Support\PaletteRoles::derive($theme, (string) ($manifest['palette_scheme'] ?? 'light'));
        $vars = \App\Engines\Builder\Support\PaletteRoles::siteVarsForRoles($vars, $manifest, $__roles, $have);
        foreach (\App\Engines\Builder\Support\PaletteRoles::cssVars($__roles) as $k => $v) { $vars[$k] = $v; }
        return $vars;
    }

    private function siteManifest(object $site, array $settings): array
    {
        $ts = app(TemplateService::class);
        foreach ([(string) ($settings['template'] ?? ''), (string) ($settings['industry'] ?? ''), (string) ($site->template_industry ?? ''), (string) ($site->template ?? '')] as $cand) {
            if ($cand === '') { continue; }
            $m = $ts->getManifest($cand);
            if (is_array($m)) { return $m; }
        }
        return [];
    }

    private function siteIndustrySlug(object $site, array $settings): string
    {
        $ts = app(TemplateService::class);
        foreach ([(string) ($settings['template'] ?? ''), (string) ($settings['industry'] ?? ''), (string) ($site->template_industry ?? ''), (string) ($site->template ?? '')] as $cand) {
            if ($cand === '') { continue; }
            $i = $ts->industryOf($cand);
            if ($i !== '') { return $i; }
        }
        return '';
    }

    /**
     * "gradient from deep green to gold on the buttons" → target + two hex stops (null stops = colours not named).
     * @return array{target:string, from:?string, to:?string}|null  null when the request is not about a gradient
     */
    private function parseGradientAsk(string $request, int $websiteId = 0): ?array
    {
        $r = mb_strtolower($request);
        if (! preg_match('/\bgradients?\b/', $r)) { return null; }
        $target = 'hero';
        if (preg_match('/\b(buttons?|ctas?|call[- ]to[- ]action)\b/', $r))                         { $target = 'buttons'; }
        elseif (preg_match('/\bfooter\b/', $r))                                                     { $target = 'footer'; }
        elseif (preg_match('/\b(nav|navbar|navigation|menu bar|header bar)\b/', $r))               { $target = 'nav'; }
        elseif (preg_match('/\b(whole page|whole site|entire site|entire page|page background|site background|body)\b/', $r) && ! preg_match('/\bhero\b/', $r)) { $target = 'page'; }
        // SELECTION888 / COLOUR SCOPE (2026-09-15): 'make the listings section a gradient …' paints that section, not the hero; so does a selected section
        if ($target === 'hero' && $websiteId > 0 && ! preg_match('/\bhero\b/', $r)) {
            $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
            if (preg_match_all('/data-block="([a-z_\-]+)"/', $home, $bm)) {
                foreach (array_unique($bm[1]) as $blk) {
                    if (in_array($blk, ['nav', 'hero', 'footer'], true)) continue;
                    $name = str_replace(['_', '-'], ' ', $blk);
                    if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $r) || preg_match('/\b' . preg_quote(rtrim($name, 's'), '/') . '\b/', $r)) { $target = 'section:' . $blk; break; }
                }
            }
            if ($target === 'hero' && $this->selTarget !== null && ($this->selTarget['block'] ?? '') !== '' && preg_match(self::SEL_WORDS, $r)) {
                $sb = (string) $this->selTarget['block'];
                $target = in_array($sb, ['nav', 'footer', 'hero'], true) ? $sb : 'section:' . $sb;
            }
        }
        $from = $to = null;
        if (preg_match('/\b(?:from|of|between)\s+(.+?)\s+(?:to|and|into)\s+(.+?)(?:\s+(?:on|for|in|across|over|behind)\b|[.,!;]|$)/', $r, $m)) {
            $from = self::styleHexLoose($m[1]);
            $to   = self::styleHexLoose($m[2]);
        }
        if ($from === null || $to === null) {
            $c = $this->scanColorsServerSide($request);
            $from = $from ?? (isset($c['primary'])   ? self::styleHex((string) $c['primary'])   : null);
            $to   = $to   ?? (isset($c['secondary']) ? self::styleHex((string) $c['secondary']) : null);
        }
        return ['target' => $target, 'from' => $from, 'to' => $to];
    }

    /** "deep green", "light gold", "forest green", "#1E5CFF" → a hex; darkness words shift lightness. */
    private static function styleHexLoose(string $phrase): ?string
    {
        $p = trim((string) preg_replace('/\s+/', ' ', strtolower($phrase)));
        $p = (string) preg_replace('/^(a|an|the|some)\s+/', '', $p);
        if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/', $p)) { return strtoupper($p); }
        $dir = 0;
        if (preg_match('/^(deep|dark|darker|rich)\s+(.+)$/', $p, $m))                       { $dir = -1; $p = $m[2]; }
        elseif (preg_match('/^(light|lighter|pale|soft|bright|pastel)\s+(.+)$/', $p, $m))   { $dir = 1;  $p = $m[2]; }
        $hex = self::COLOR_MAP[$p] ?? null;
        if ($hex === null) {
            $w   = explode(' ', $p);
            $hex = self::COLOR_MAP[end($w)] ?? null;
            if ($hex !== null && in_array($w[0], ['forest', 'olive', 'midnight', 'deep', 'dark'], true)) { $dir = -1; }
        }
        if ($hex === null) { return null; }
        return $dir === 0 ? strtoupper($hex) : self::shiftLightness($hex, $dir);
    }

    /** The CSS for one literal gradient, keyed by the part it paints; text is recoloured so it stays readable. */
    private static function gradientRules(string $target, string $a, string $b): array
    {
        $g   = "linear-gradient(135deg,{$a} 0%,{$b} 100%)";
        $ink = self::readableOnPair($a, $b);
        if (str_starts_with($target, 'section:')) {   // SELECTION888: one home-page section; its own heading/intro text made readable, cards keep their colours
            $blk = substr($target, 8); $s = '[data-block="' . $blk . '"]';
            return ['gradient_section_' . $blk => "{$s}{background-image:{$g}!important;background-color:{$a}!important} {$s} h2,{$s} .section-title,{$s} .eyebrow,{$s} .lede,{$s} .section-sub,{$s} .section-intro,{$s} > .inner > p,{$s} > p{color:{$ink}!important}"];
        }
        switch ($target) {
            case 'buttons':
                return ['buttons' => ".btn-primary,.hero-cta,.nav-cta,.btn.primary,.btn-cta,.cta-btn,button[type=submit]{background-image:{$g}!important;background-color:{$a}!important;border-color:transparent!important;color:{$ink}!important}"];
            case 'footer':
                return ['footer' => "footer,.footer,[data-block=\"footer\"]{background-image:{$g}!important;background-color:{$a}!important;color:{$ink}!important} footer a,.footer a,footer p,footer li,footer h4,footer h3{color:inherit!important}"];
            case 'nav':
                return ['nav' => "nav,.nav,.navbar,[data-block=\"nav\"]{background-image:{$g}!important;background-color:{$a}!important} nav a,.nav a,.navbar a,.nav-links a,nav .logo,.nav .logo{color:{$ink}!important}"];
            case 'page':
                return ['page' => "body{background-image:linear-gradient(180deg,{$a} 0%,{$b} 100%)!important;background-attachment:fixed!important;background-color:{$a}!important}"];
            default:
                return ['hero' => ".hero,[data-block=\"hero\"],header.hero,section.hero{background-image:{$g}!important;background-color:{$a}!important}"
                    . " .hero::before,.hero::after,[data-block=\"hero\"]::before,[data-block=\"hero\"]::after{background:none!important;background-image:none!important}"
                    // Text that sits directly on the gradient: the copy column and the named hero text classes used across the
                    // template library (hero-copy 54, lede 53, eyebrow 73, hero-trust-item 44 …). A lead/booking form card inside the
                    // hero (.hero-form) keeps its own colours: it has its own background.
                    . " .hero h1,.hero .hero-h1,.hero .hero-title,.hero .hero-name,[data-block=\"hero\"] h1,"
                    . ".hero .lede,.hero .hero-sub,.hero .hero-subtitle,.hero .eyebrow,.hero .hero-eyebrow,.hero .hero-note,"
                    . ".hero .hero-trust,.hero .hero-trust-item,.hero .hero-facts,.hero .hero-facts *,.hero .hero-band,"
                    . ".hero .hero-copy,.hero .hero-copy p,.hero .hero-copy li,.hero .hero-copy span:not([class*=btn]),.hero .hero-copy a:not([class*=btn]),"
                    . ".hero > .wrap > p,.hero > .shell > p,.hero > .hero-inner > p{color:{$ink}!important}"
                    . " .hero .hero-title span{color:{$ink}!important;opacity:.85}"];

        }
    }

    /** White must survive the LIGHTER stop and ink the DARKER one; whichever survives its worst case wins. */
    private static function readableOnPair(string $a, string $b): string
    {
        $CT = \App\Engines\Builder\Support\ColorTheme::class;
        try { $la = $CT::luminance($a); $lb = $CT::luminance($b); } catch (\Throwable $e) { return '#FFFFFF'; }
        $lighter = $la >= $lb ? $a : $b;
        $darker  = $la >= $lb ? $b : $a;
        $white = self::contrastRatio('#FFFFFF', $lighter);
        $ink   = self::contrastRatio('#111111', $darker);
        if ($white >= 3.0) { return '#FFFFFF'; }
        if ($ink >= 3.0)   { return '#111111'; }
        return $white >= $ink ? '#FFFFFF' : '#111111';
    }

    /**
     * Customer-specific rules (literal gradients) live in their own replaceable block after the design layer and
     * are remembered in template_variables.design_extras, so a later restyle or palette switch does not erase
     * them. Success is read back from every file, not assumed.
     */
    private static function writeDesignExtras(int $websiteId, array $rules, array &$tv): bool
    {
        $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
        foreach ($rules as $k => $css) { $extras[(string) $k] = (string) $css; }
        $tv['design_extras'] = $extras;
        $block = '<style id="lug-design-extras" data-owner="arthur">' . implode("\n", $extras) . '</style>';
        $root  = storage_path("app/public/sites/{$websiteId}");
        $files = glob("{$root}/*.html") ?: [];
        foreach ((glob("{$root}/*/index.html") ?: []) as $nested) { if (! str_contains($nested, '/.history/')) { $files[] = $nested; } }
        $verified = 0;
        foreach (array_values(array_unique($files)) as $file) {
            $html = @file_get_contents($file);
            if ($html === false) { continue; }
            $new = preg_replace('~<style id="lug-design-extras"[^>]*>.*?</style>~is', $block, $html, 1, $n);
            if ($n === 0) {
                $new = (stripos($html, '</head>') !== false) ? str_ireplace('</head>', $block . "\n</head>", $html) : $html . $block;
            }
            if ($new !== null && $new !== $html) { file_put_contents($file, $new); }
            $chk = (string) @file_get_contents($file);
            if (str_contains($chk, $block)) { $verified++; }
        }
        return $verified > 0;
    }


    /**
     * COLOUR THEMES (2026-09-05): the curated themes that fit this business — style words + resolved template slug.
     * Used by the confirm panel (route adds `themes` to the ready_to_confirm reply) and by the chat palette step.
     */
    public function themesFor(array $buildData, int $n = 4): array
    {
        try {
            $raw  = (string) ($buildData['industry'] ?? '');
            $slug = $raw !== '' ? $this->resolveTemplateSlug($raw) : '';
            $nameSignal = $this->confidentSlugFromText((string) ($buildData['business_name'] ?? ''));
            if ($nameSignal !== null) $slug = $nameSignal;
            return \App\Engines\Builder\Support\ColorTheme::propose($buildData['style'] ?? null, $slug ?: null, $n);
        } catch (\Throwable $e) {
            return \App\Engines\Builder\Support\ColorTheme::propose($buildData['style'] ?? null, null, $n);
        }
    }

    public function buildFromChat(
        int $workspaceId,
        array $buildData,
        ?string $logoUrl = null,
        array $images = [],
        array $colors = []
    , ?int $actorId = null): array {
        if ($logoUrl !== null && $logoUrl !== '') {
            $buildData['logo_url']    = $logoUrl;
            $buildData['logo_upload'] = true;
        }
        if (!empty($images)) {
            $buildData['uploaded_images'] = array_slice(array_values($images), 0, 10);
        }
        if (!empty($colors)) {
            $buildData['colors'] = array_merge(
                (array) ($buildData['colors'] ?? []),
                array_filter($colors, fn($v) => is_string($v) && $v !== '')
            );
        }
        return $this->generateWebsite($workspaceId, $buildData, $actorId);
    }

    /**
     * Handle a message from the user in the Arthur chat.
     */
    public function handleMessage(int $wsId, string $message, array $history = [], array $state = [], ?array $colorsFromPayload = null): array
    {
        // FIX 1 — merge colors from the payload (client extracts, server trusts)
        // and also scan this message server-side as a safety net in case the
        // client failed to extract.
        if (is_array($colorsFromPayload)) {
            $state['colors'] = array_merge($state['colors'] ?? [], array_filter($colorsFromPayload, fn($v) => $v !== null && $v !== ''));
        }
        $serverScan = $this->scanColorsServerSide($message);
        if (!empty($serverScan)) {
            $state['colors'] = $state['colors'] ?? [];
            foreach (['primary','secondary','accent'] as $role) {
                if (empty($state['colors'][$role]) && !empty($serverScan[$role])) {
                    $state['colors'][$role] = $serverScan[$role];
                }
            }
        }

        // Step 1: Extract business info from message + history.
        // When the client has already progressed past extraction (template
        // picked, or images decided), the state is authoritative — skip the
        // LLM round-trip and trust what the UI sent. Otherwise extraction
        // can flip ready=true back to ready=false and stall the flow.
        $inConfirmationFlow = !empty($state['template_confirmed']) || !empty($state['images_done']);
        if ($inConfirmationFlow) {
            $extracted = $state;
            // Recompute ready locally so stale state doesn't misfire.
            $extracted['ready'] = !empty($extracted['business_name']) && !empty($extracted['industry']);
        } else {
            // BUG 1 FIX — rich multi-field extraction in a single LLM call.
            // Pulls business_name, industry, services, location, target_market,
            // colors, pages all at once so Arthur never drops context from a
            // detailed first message.
            $extracted = $this->extractAllFields($message, $history, $state);
            // extractAllFields() may overwrite colors — restore from $state.
            if (!empty($state['colors'])) $extracted['colors'] = $state['colors'];
        }

        // Step 2: Check if ready to generate
        // FIX 5 (2026-04-20) — expanded required set so Arthur collects a
        // complete brief before rendering templates. `services` in the
        // extractor is the canonical field for "core_service(s)".
        $required = ['business_name', 'industry', 'services', 'location'];
        $missing = [];
        foreach ($required as $f) {
            $val = $extracted[$f] ?? null;
            $empty = ($val === null || $val === '' || (is_array($val) && count($val) === 0));
            if ($empty) $missing[] = $f;
        }
        // Back-compat: legacy callers expect 'core_service' alias for 'services'.
        $missingForClient = array_map(fn($f) => $f === 'services' ? 'core_service' : $f, $missing);

        $isReady = empty($missing) && ($extracted['ready'] ?? false);

        if ($isReady) {
            // BUG 1 FIX — before showing the template gallery, surface a
            // single "here is everything I pulled out of your message"
            // bubble so the user can confirm or correct details in one
            // shot. Only fires once: state.details_confirmed gates it.
            if (empty($extracted['details_confirmed']) && empty($extracted['template_confirmed'])) {
                return [
                    'type'     => 'confirm_details',
                    'message'  => $this->buildConfirmationMessage($extracted),
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                    'details'  => [
                        'business_name' => $extracted['business_name'] ?? '',
                        'industry'      => $extracted['industry']      ?? '',
                        'colors'        => $extracted['colors']        ?? [],
                        'location'      => $extracted['location']      ?? '',
                        'services'      => $extracted['services']      ?? [],
                        'target_market' => $extracted['target_market'] ?? '',
                        'pages'         => $extracted['pages']         ?? [],
                    ],
                ];
            }

            // 2026-04-21 — Silent template resolution.
            // Previous behaviour: showed a template_slider (for ambiguous
            // industries) or template_pick (single card) and waited for the
            // user to click "Use Template". That UX has been removed — Arthur
            // now silently resolves the best template from conversation
            // keywords and continues collecting remaining required fields.
            if (empty($extracted['template_confirmed']) && !empty($extracted['industry'])) {
                $industry = strtolower((string) $extracted['industry']);

                // Build haystack of everything the user has said so we can
                // look for secondary qualifying keywords.
                $haystack = ' ' . mb_strtolower((string) $message);
                foreach ((array) $history as $h) {
                    if (is_array($h) && isset($h['content'])) {
                        $haystack .= ' ' . mb_strtolower((string) $h['content']);
                    } elseif (is_string($h)) {
                        $haystack .= ' ' . mb_strtolower($h);
                    }
                }

                // Map each possible starting industry to a disambiguation
                // rule set. Each rule is [candidate, [keywords]]. First
                // match wins. A rule with [] keywords means "default if no
                // other rule matched".
                $rules = [
                    // "real estate" + broker/agent/personal → real_estate_broker
                    //              + agency/company/listings/properties → real_estate
                    //              (no qualifier) → real_estate
                    'real_estate' => [
                        ['real_estate_broker', ['broker', 'agent', 'personal', 'individual', 'realtor', 'my own', 'independent']],
                        ['real_estate',        ['agency', 'company', 'listings', 'properties', 'brokerage', 'team', 'office']],
                        ['real_estate',        []],
                    ],
                    // tech + software/saas/app/platform → technology
                    //      + marketing/agency/creative   → marketing_agency
                    //      (no qualifier) → technology
                    'technology' => [
                        ['technology',       ['software', 'saas', 'app ', 'platform', 'startup', 'product', 'developer']],
                        ['marketing_agency', ['marketing', 'agency', 'creative', 'brand', 'campaign', 'advertising']],
                        ['technology',       []],
                    ],
                    // fitness + yoga/wellness/holistic/mindfulness → wellness
                    //         + gym/training/crossfit/weights/class → fitness
                    //         (no qualifier) → fitness
                    'fitness' => [
                        ['wellness', ['yoga', 'wellness', 'holistic', 'mindfulness', 'meditation', 'spa', 'pilates', 'reiki']],
                        ['fitness',  ['gym', 'training', 'crossfit', 'weights', 'class', 'workout', 'hiit', 'strength', 'bodybuilding']],
                        ['fitness',  []],
                    ],
                    // restaurant + cafe/coffee/bakery/brunch → cafe
                    //            (no qualifier) → restaurant
                    'restaurant' => [
                        ['cafe',       ['cafe', 'coffee', 'bakery', 'brunch', 'pastry', 'espresso', 'tea room']],
                        ['restaurant', []],
                    ],
                    // consulting + legal/law/attorney/lawyer → legal
                    //            (no qualifier) → consulting
                    'consulting' => [
                        ['legal',      ['legal', 'law', 'attorney', 'lawyer', 'litigation', 'barrister', 'counsel', 'solicitor']],
                        ['consulting', []],
                    ],
                    // design + interior/home/decor/space → interior_design
                    //        + architecture/architect/building → architecture
                    //        (no qualifier) → interior_design
                    'design' => [
                        ['interior_design', ['interior', 'home', 'decor', 'space', 'furniture', 'residential', 'living room', 'hospitality interior']],
                        ['architecture',    ['architecture', 'architect', 'building', 'structure', 'urban', 'construction design']],
                        ['interior_design', []],
                    ],
                ];

                // Aliases — map industries that are already one of the leaf
                // candidates back to their group so disambiguation still runs
                // if the user provides different secondary keywords.
                $groupAliases = [
                    'real_estate_broker' => 'real_estate',
                    'marketing_agency'   => 'technology',
                    'wellness'           => 'fitness',
                    'cafe'               => 'restaurant',
                    'legal'              => 'consulting',
                    'interior_design'    => 'design',
                    'architecture'       => 'design',
                ];
                $group = $groupAliases[$industry] ?? $industry;

                if (isset($rules[$group])) {
                    foreach ($rules[$group] as [$candidate, $keywords]) {
                        if (empty($keywords)) { $industry = $candidate; break; }
                        $matched = false;
                        foreach ($keywords as $kw) {
                            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) { $matched = true; break; }
                        }
                        if ($matched) { $industry = $candidate; break; }
                    }
                }

                $extracted['industry'] = $industry;
                $extracted['template_confirmed'] = true;

                // At this point all required fields are already present
                // (this block is gated by $isReady). Acknowledge silently
                // and jump straight to the image-upload step that used to
                // come AFTER template_confirmed.
                $extracted['industry_announced'] = true;
                $prettyInd = ucwords(str_replace('_', ' ', $industry));
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect! I'll build your **{$prettyInd}** website. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }
            // Template confirmed — next, prompt the user to upload photos
            // (gallery / team / services). Hero remains AI-generated.
            // Skip path: state.images_done = true AND uploaded_images = [].
            if (empty($extracted['images_done'])) {
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // T1 (2026-04-20) — logo step comes AFTER images_done, BEFORE build.
            // State flag `logo_decided` gates: once user picks Upload or Skip,
            // this branch is bypassed.
            if (empty($extracted['logo_decided'])) {
                return [
                    'type'     => 'logo_upload',
                    'message'  => "Do you have a logo? I'll place it in the nav and footer of your website.",
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // COLOUR THEMES (2026-09-05): no brand colours and no logo palette → offer the three curated themes that fit
            // the customer's style and industry, in the same card shape the chat already renders. Offered once.
            if (empty($extracted['palette']) && empty($extracted['palettes_proposed']) && empty($extracted['themes_offered'])
                && empty($extracted['colors']['primary']) && empty($extracted['colors']['secondary'])) {
                $extracted['palettes_proposed'] = $this->themesFor($extracted, 3);
                $extracted['palettes_source']   = 'themes';
                $extracted['themes_offered']    = true;
            }
            // T2 (2026-04-20) — if a palette was proposed but not confirmed,
            // hold here. palette_choice fires only when palettes[] is present.
            // A customer who answers a theme offer by typing their own colours is not held (colors now present).
            $themeHoldReleased = (($extracted['palettes_source'] ?? '') === 'themes') && !empty($extracted['colors']['primary']);
            if (!empty($extracted['palettes_proposed']) && empty($extracted['palette']) && !$themeHoldReleased) {
                return [
                    'type'     => 'palette_choice',
                    'message'  => (($extracted['palettes_source'] ?? '') === 'themes')
                        ? 'Which colour theme fits your brand? Each one is tuned for contrast and readability — or just tell me your own colours.'
                        : 'I found these colors in your logo. Which palette works for your brand?',
                    'state'    => $extracted,
                    'palettes' => $extracted['palettes_proposed'],
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // Generate the website (template confirmed + image choice made + logo step done).
            return $this->generateWebsite($wsId, $extracted);
        }

        // Step 3: Ask for missing info
        $question = $this->getNextQuestion($missing, $extracted);
        $progress = $this->getProgress($extracted);

        return [
            'type' => 'question',
            'message' => $question,
            'state' => $extracted,
            'progress' => $progress,
        ];
    }

    // BUG 1 FIX — single-shot extraction of every field Arthur cares about.
    // Called instead of extractFields() from handleMessage(). Returns the
    // merged state (never overwrites existing non-empty values with null).
    // Post-processes the industry through normalizeIndustry() so "digital
    // marketing" lands on "technology" even if the LLM guesses otherwise.
    private function extractAllFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            $state = $this->simpleExtract($message, $state, $history);
            $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
            $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
            return $state;
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant. Extract EVERY business detail the user gives you in ONE shot.

Return a JSON object (include the word "json") with these keys. Use null for fields that are genuinely missing — do NOT invent data.

- business_name: string
- industry: the ONE template slug that best matches what the business actually is, from this list:
  [aesthetic_clinic, architecture, automotive, barbershop, beauty_salon, cafe, catering, childcare, construction, consulting, dental, ecommerce, event_venue, gym, home_services, hotel, interior_design, it_services, marketing_agency, medical_clinic, news_channel, online_courses, pet_services, real_estate_agency, resort, restaurant, retail_shop, short_term_rental, training_center, travel_agency, tutoring]
  GUIDE: pet shop / pet store / grooming / vet / kennel → "pet_services" (NEVER beauty_salon, NEVER retail_shop) · salon / spa / nails / lashes → "beauty_salon" · barber → "barbershop" · botox / fillers / cosmetic / med spa → "aesthetic_clinic" · dentist → "dental" · doctor / clinic / physio → "medical_clinic" · gym / yoga / pilates / trainer → "gym" · restaurant / bistro / diner → "restaurant" · café / coffee / bakery → "cafe" · catering → "catering" · hotel → "hotel" · resort / beach club → "resort" · holiday home / airbnb → "short_term_rental" · travel / tours / visa → "travel_agency" · wedding / venue → "event_venue" · nursery / daycare / kids → "childcare" · school / courses online → "online_courses" · tutor → "tutoring" · training / academy / institute → "training_center" · property / realtor / broker → "real_estate_agency" · architect → "architecture" · interior / fit-out / joinery → "interior_design" · builder / contractor → "construction" · plumber / electrician / cleaning / AC / maintenance → "home_services" · car / garage / detailing / rental → "automotive" · digital marketing / seo / social media / ads / web design → "marketing_agency" · IT / software / saas / app → "it_services" · lawyer / accountant / advisory / agency (other) → "consulting" · online store / dropshipping → "ecommerce" · physical shop / boutique / retail → "retail_shop" · magazine / news / media → "news_channel".
  If nothing fits, return the closest slug anyway — never invent a new label.
- services: array of short strings (e.g. ["SEO","website design","social media","paid ads"])
- location: string — ONLY a city/area the user actually wrote; if none was stated, return "" (never guess, never copy an example)
- target_market: string (who the business serves — e.g. "small and medium sized businesses")
- colors: object {primary: string|null, secondary: string|null} — named color or hex
- style: the customer's OWN words about look, feel or mood (e.g. "bubbly and colorful", "luxury and elegant", "clean and minimal"), copied verbatim; null if they said nothing about design
- fonts: object {display: string|null, body: string|null} — ONLY if the customer names a typeface (e.g. "use Poppins"); otherwise null
- pages: array of page names if the user lists them (e.g. ["home","about","services","testimonials","blog","contact"])
- tagline, hero_title (3-6 words, plain text), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description

Add "ready": true if business_name AND industry are both present. Otherwise "ready": false and "missing": [list of missing fields].
No HTML. No markdown. Only valid JSON.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract_all'], 1200);
            // E2E-1 (2026-09-05): the runtime sometimes wraps its answer as {"json":{...}} / {"data":{...}}. The
            // envelope used to be merged as a key called "json" and the business name was never seen — Arthur
            // asked "What's the name of your business?" three times to a message that opened with the name.
            $parsed = ($result['success'] ?? false) && is_array($result['parsed'] ?? null)
                ? \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($result['parsed'])
                : null;
            if (is_array($parsed) && empty($parsed['business_name']) && empty($state['business_name'])) {
                Log::warning('[Arthur] extractAllFields: model returned no business_name', ['keys' => array_keys($parsed)]);
            }
            if (!is_array($parsed)) {
                Log::warning('[Arthur] extractAllFields: runtime returned no parseable JSON — regex fallback', ['error' => $result['error'] ?? null]);
                $state = $this->simpleExtract($message, $state, $history);
            } else {
                foreach ($parsed as $k => $v) {
                    // Never overwrite with null/empty. Arrays must be non-empty
                    // to win over an existing value.
                    if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;
                    $state[$k] = $v;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] extractAllFields failed: ' . $e->getMessage());
            $state = $this->simpleExtract($message, $state, $history);
        }

        // Post-process the industry through the keyword map so misclassified
        // LLM output (e.g. "marketing" for a digital marketing agency) is
        // corrected before it reaches the template system.
        $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
        // ARTHUR-3 (2026-08-29): a location is kept only when the owner actually wrote it somewhere in
        // this conversation — the model must not invent a city ("Austin, Texas" shipped on a site whose
        // owner never named one). Compare on the first word (city) so "Brighton, UK" ≈ "brighton".
        $loc = trim((string) ($state['location'] ?? ''));
        if ($loc !== '') {
            $hay = mb_strtolower($message . ' ' . implode(' ', array_map(fn($h) => is_array($h) ? (string) ($h['content'] ?? $h['message'] ?? '') : (string) $h, (array) $history)));
            $firstWord = mb_strtolower(trim(preg_split('/[,\s]+/', $loc)[0] ?? ''));
            if ($firstWord === '' || !str_contains($hay, $firstWord)) {
                $state['location'] = '';
            }
        }
        // DESIGN DIRECTION safety net (2026-09-05): if the model dropped the mood words, take them from the
        // conversation deterministically so "bubbly and colorful" can never be silently lost.
        if (empty($state['style'])) {
            $convo = $message . ' ' . implode(' ', array_map(fn($h) => is_array($h) ? (string) ($h['content'] ?? '') : (string) $h, (array) $history));
            if (preg_match('/\b((?:\w+(?:\s+and\s+|\s*,\s*|\s+))?(?:bubbly|colou?rful|playful|fun|vibrant|cheerful|whimsical|luxury|luxurious|elegant|premium|upscale|sophisticated|modern|contemporary|sleek|bold|minimal|minimalist|clean|simple|airy|classic|traditional|timeless|heritage)(?:\s+and\s+\w+)?)\b/iu', $convo, $sm)) {
                $state['style'] = trim($sm[1]);
            }
        }
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
        return $state;
    }

    private function extractFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            // Fallback: simple keyword extraction
            return $this->simpleExtract($message, $state, $history);
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant.
Extract business information from the user's message.
Required fields: business_name, industry, location, services, goal.
Industry MUST be one of: restaurant, interior_design, fitness, healthcare, legal, real_estate, fashion, technology, events, beauty, marketing, general.
If the user gives enough info to build a website (at least business_name + industry), set "ready": true.
If info is missing, set "ready": false and list what's missing in "missing" array.
When ready, also generate: tagline, hero_title (short punchy headline, 3-6 words, plain text only), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description. No HTML tags in any field.
Return ONLY valid JSON. No markdown. Include the word "json" in your thinking.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract'], 800);
            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                // Merge with existing state (don't overwrite with nulls)
                $parsed = \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($result['parsed']);
                foreach ($parsed as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $state[$k] = $v;
                    }
                }
                return $state;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Extraction failed: ' . $e->getMessage());
        }

        return $this->simpleExtract($message, $state, $history);
    }

    private function simpleExtract(string $message, array $state, array $history = []): array
    {
        $lower = mb_strtolower($message);

        // Simple keyword matching fallback. BUG 2 FIX — digital marketing /
        // seo / web design / social media / advertising now all land on
        // "technology" so the template picker shows the right gallery.
        $industries = [
            'restaurant'      => ['restaurant','café','cafe','bistro','dining','chef','catering','food','kitchen','bakery','pizzeria'],
            'fitness'         => ['gym','fitness','yoga','pilates','crossfit','personal trainer','personal training','wellness'],
            'beauty'          => ['salon','spa','beauty','hair','nails','skincare','barbershop'],
            'real_estate'     => ['real estate','property','realtor','broker','apartments','housing'],
            'healthcare'      => ['clinic','doctor','dental','medical','hospital','pharmacy','health'],
            'legal'           => ['law firm','lawyer','attorney','legal','solicitor','consulting'],
            'technology'      => ['digital marketing','marketing agency','seo agency','seo','web design','web development','social media agency','social media','advertising agency','advertising','it company','it services','software','app development','app','saas','tech','digital'],
            'events'          => ['wedding','events','event production'],
            'interior_design' => ['interior','fit-out','joinery'],
            'fashion'         => ['fashion','clothing','boutique'],
        ];

        foreach ($industries as $industry => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($lower, $kw) !== false && empty($state['industry'])) {
                    $state['industry'] = $industry;
                    break 2;
                }
            }
        }

        // Extract location
        $locations = ['dubai', 'abu dhabi', 'sharjah', 'ajman', 'riyadh', 'jeddah', 'doha', 'muscat', 'bahrain', 'kuwait'];
        foreach ($locations as $loc) {
            if (strpos($lower, $loc) !== false && empty($state['location'])) {
                $state['location'] = ucwords($loc) . ', UAE';
                break;
            }
        }

        // Extract business name — look for quoted text or "called X" or "named X"
        if (empty($state['business_name'])) {
            if (preg_match('/(?:called|named|for)\s+"?([A-Z][A-Za-z\s&\']+)"?/i', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            } elseif (preg_match('/"([^"]+)"/', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            }
        }

        // Conversational fallback — when the runtime LLM is unavailable, plain
        // user replies like "MR Digital Media" never match the regex patterns
        // above and the wizard loops forever asking the same question. Look at
        // the most recent Arthur message in history to figure out which field
        // the user is answering and capture the message verbatim.
        $trimmed = trim($message);
        if ($trimmed !== '' && !empty($history)) {
            $lastArthur = '';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $h = $history[$i];
                if (is_array($h) && ($h['role'] ?? '') === 'assistant') {
                    $lastArthur = mb_strtolower((string)($h['content'] ?? ''));
                    break;
                }
            }
            if ($lastArthur !== '') {
                if (empty($state['business_name']) && mb_strpos($lastArthur, "name of your business") !== false) {
                    $state['business_name'] = $trimmed;
                } elseif (empty($state['location']) && mb_strpos($lastArthur, 'based?') !== false) {
                    $state['location'] = $trimmed;
                } elseif (empty($state['services']) && (mb_strpos($lastArthur, 'services or products') !== false || mb_strpos($lastArthur, 'what services') !== false)) {
                    $parts = preg_split('/\s*,\s*|\s*;\s*|\s+and\s+/i', $trimmed);
                    $state['services'] = array_values(array_filter(array_map('trim', $parts)));
                }
            }
        }

        // Check readiness
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);

        return $state;
    }

    private function getNextQuestion(array $missing, array $state): string
    {
        $name = $state['business_name'] ?? 'your business';

        if (in_array('business_name', $missing)) {
            return "I'd love to build your website! What's the name of your business?";
        }
        if (in_array('industry', $missing)) {
            return "Great name! What type of business is **{$name}**? (e.g., restaurant, fitness, beauty salon, etc.)";
        }
        if (empty($state['location'])) {
            return "Where is **{$name}** based? (city, country)";
        }
        if (empty($state['services'])) {
            return "What services or products does **{$name}** offer?";
        }

        return "Tell me more about **{$name}** — what makes it special?";
    }

    private function getProgress(array $state): array
    {
        return [
            ['field' => 'business_name', 'label' => 'Business Name', 'done' => !empty($state['business_name'])],
            ['field' => 'industry', 'label' => 'Industry', 'done' => !empty($state['industry'])],
            ['field' => 'location', 'label' => 'Location', 'done' => !empty($state['location'])],
            ['field' => 'services', 'label' => 'Services', 'done' => !empty($state['services'])],
        ];
    }
    /*
     * INC-0006 (2026-09-01) — provisionWebsiteWorkspace() was REMOVED, not disabled.
     *
     * It created a workspace for every website after the first, which turned one business into many
     * tenants: separate CRM, separate SEO estate, separate Sarah memory, separate wallet. ARCH-1 first
     * gated it behind config('builder.website_workspaces'), but a flag is a defect waiting to be
     * switched back on. There is now no supported configuration in which creating website #2..N
     * creates a workspace, because the code that could do it no longer exists.
     *
     * A website belongs to the business workspace that already exists. MultiWebsiteInvariantTest and
     * ArchitectureInvariantTest hold this permanently.
     */

    /**
     * DEC-0045 (2026-09-11): the first draft is a priced act — BuilderCapabilities::pricing()['draft'] credits
     * (10). Reserved before a single model call, committed only when the build reports 'complete', released on
     * any other outcome (plan-limit error, template failure, exception). A workspace that cannot cover it is told
     * the price and its balance; nothing is generated and nothing is charged. Trial credits are already in the
     * balance at this point (granted at signup), so the 50-credit trial reads 40 after its first draft.
     */
    private function generateWebsite(int $wsId, array $data, ?int $actorId = null): array
    {
        $price   = (int) (\App\Engines\Builder\Support\BuilderCapabilities::pricing()['draft'] ?? 10);
        $credits = app(\App\Core\Billing\CreditService::class);
        $resRef  = null;
        if ($price > 0) {
            try {
                $resRef = $credits->reserve($wsId, $price, 'builder_arthur_draft');
            } catch (\Throwable $e) {
                $bal = [];
                try { $bal = $credits->getBalance($wsId); } catch (\Throwable $ignored) {}
                $available = (int) ($bal['available'] ?? 0);
                Log::info('[Arthur] draft refused: insufficient credits', ['workspace_id' => $wsId, 'required' => $price, 'available' => $available]);
                return [
                    'type'                 => 'error',
                    'message'              => "Building a website takes {$price} credits and this workspace has {$available}. Add credits or upgrade your plan, and I'll pick up exactly where we left off.",
                    'insufficient_credits' => true,
                    'credits_required'     => $price,
                    'credits_available'    => $available,
                ];
            }
        }
        try {
            $result = $this->generateWebsiteCore($wsId, $data, $actorId);
        } catch (\Throwable $e) {
            if ($resRef) { try { $credits->release($wsId, $resRef); } catch (\Throwable $ignored) {} }
            throw $e;
        }
        if ($resRef) {
            try {
                if (($result['type'] ?? '') === 'complete') {
                    $credits->commit($wsId, $resRef, $price);
                    $result['credits_charged'] = $price;
                    try { $result['credits_available'] = (int) ($credits->getBalance($wsId)['available'] ?? 0); } catch (\Throwable $ignored) {}
                } else {
                    $credits->release($wsId, $resRef);
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] draft credit settlement failed: ' . $e->getMessage(), ['workspace_id' => $wsId, 'ref' => $resRef]);
            }
        }
        return $result;
    }

    private function generateWebsiteCore(int $wsId, array $data, ?int $actorId = null): array
    {
        // BUILDER888 fix: $actorId is the authenticated actor threaded from the caller
        // (buildFromChat). It was referenced below (created_by) but never declared here,
        // raising "Undefined variable $actorId" -> PERSISTENCE_FAILED on every build.
        // When absent, derive the workspace OWNER (never the payload user_id, which the
        // security note below forbids as a forgeable owner source).
        if ($actorId === null) {
            $ownerId = DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id');
            $actorId = $ownerId ? (int) $ownerId : null;
        }
        $name = $data['business_name'] ?? 'My Business';

        // ── P1 (2026-06-24): WEBSITE = ITS OWN WORKSPACE ────────────────────
        // Each built website lives in its OWN workspace (isolated CRM/SEO/blog/
        // tasks/agent-memory; they can be different companies). Website #1 uses
        // the current workspace; #2+ each get a freshly-seeded workspace that
        // SHARES the user's credit pool + plan. Quota = plan max_websites
        // counted across ALL the user's workspaces (the pool family).
        $ownerUserId = (int) (DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id')
            ?: DB::table('workspaces')->where('id', $wsId)->value('created_by') ?: 0);
        $billingWs = (int) (DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        try {
            $sub = DB::table('subscriptions')->where('workspace_id', $billingWs)
                ->whereIn('status', ['active', 'trialing'])->latest()->first();
            $plan = $sub ? DB::table('plans')->where('id', $sub->plan_id)->first() : null;
            if (! $plan) $plan = DB::table('plans')->where('slug', 'free')->first();
            $maxWebsites = (int) ($plan->max_websites ?? 1);
            $userWsIds = DB::table('workspaces')->where('billing_workspace_id', $billingWs)->pluck('id')->all();
            if (empty($userWsIds)) $userWsIds = [$billingWs];
            $totalSites = (int) DB::table('websites')->whereIn('workspace_id', $userWsIds)->whereNull('deleted_at')->count();
            if ($totalSites >= $maxWebsites) {
                $planName = $plan->name ?? 'Free';
                return [
                    'type'          => 'error',
                    'message'       => "You've reached your plan limit of {$maxWebsites} website" . ($maxWebsites === 1 ? '' : 's') . " on the {$planName} plan. Upgrade your plan or delete a website to add more.",
                    'limit_reached' => true,
                    'current'       => $totalSites,
                    'max'           => $maxWebsites,
                    'plan'          => $planName,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] plan-limit check failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        // INC-0006: a website is a child of the business workspace, never a tenant of its own. The
        // block that used to spin up a dedicated workspace here is gone — not disabled behind a
        // flag that could resurrect it, but removed, along with the method it called.

        $rawIndustry = (string) ($data['industry'] ?? '');

        // PATCH (template-resolution, 2026-05-09) — Resolve the free-form
        // industry string (from chat() build_data, which never normalises)
        // to an actual on-disk template slug. The old code did:
        //   $manifest = getManifest($industry); fallback restaurant;
        // which sent "Plastic Surgery" → null → restaurant. Now any
        // "plastic surgery" / "cosmetic clinic" / "med spa" maps to
        // aesthetic_clinic. Generic medical → medical_clinic. Etc.
        // TEMPLATE SELECTOR (2026-09-11): the keyword resolver's answer is now the FALLBACK. The decision is
        // made from the whole business — name, description, services, audience, location, style — against a
        // catalogue of every live design, and it comes back with a reason and a confidence.
        $keywordSlug = $this->resolveTemplateSlug($rawIndustry);
        $tplSelection = ['template' => $keywordSlug, 'industry' => $keywordSlug, 'method' => 'keyword', 'confidence' => 0.0, 'reason' => '', 'alternatives' => [], 'keyword_slug' => $keywordSlug];
        try {
            $tplSelection = app(\App\Engines\Builder\Services\TemplateSelector::class)->select($data, $keywordSlug);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] template selector threw; keyword resolver used', ['error' => $e->getMessage()]);
        }
        // The clone safety net applies to the model's pick exactly as it applies to the keyword pick.
        $industry = self::CLONE_OVERRIDE[$tplSelection['template']] ?? $tplSelection['template'];
        \Illuminate\Support\Facades\Log::info('[Arthur] template selected', ['raw' => $rawIndustry] + $tplSelection + ['rendered_as' => $industry]);

        // PATCH (name-grounding, 2026-07-24) — The live chat() path hands
        // industry classification to a free-form LLM that has misrouted on
        // sparse input (Architecture→interior_design, Clothing Store→
        // it_services, Pet Clinic→medical_clinic, Shelving→consulting). The
        // business NAME almost always states the industry outright, so run it
        // through the keyword matcher and let a CONFIDENT name hit override the
        // LLM's guess. confidentSlugFromText() returns null when the text has
        // no real signal (so a signal-free name never overrides a good guess).
        $nameSignal = $this->confidentSlugFromText((string) $name);
        // Only when the selector fell back to keywords: the model has already read the name.
        if ($nameSignal !== null && $nameSignal !== $industry && ($tplSelection['method'] ?? 'keyword') === 'keyword') {
            \Illuminate\Support\Facades\Log::info('[Arthur] name-grounded industry override', [
                'name'         => $name,
                'llm_industry' => $rawIndustry,
                'llm_slug'     => $industry,
                'name_slug'    => $nameSignal,
            ]);
            $industry = $nameSignal;
            // E2E-3 (2026-09-05): the override is the decision. Leaving $data['industry'] as the LLM's guess made
            // templateFits false downstream and borrowed a BEAUTY SALON hero for a pet shop built on pet_services.
            $data['industry'] = $industry;
            $rawIndustry = $industry;
        }

        $manifest = $this->templates->getManifest($industry);

        // Defensive double-check — if resolveTemplateSlug ever returned a
        // slug that doesn't exist (shouldn't happen — all returns map to
        // disk templates), fall back to consulting (more generic than
        // restaurant for unknown businesses).
        if (!$manifest) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] resolveTemplateSlug returned non-existent template', [
                'raw'      => $rawIndustry,
                'resolved' => $industry,
            ]);
            $industry = 'consulting';
            $manifest = $this->templates->getManifest('consulting');
        }
        if (!$manifest) {
            return ['type' => 'error', 'message' => 'Could not build your website right now. Please contact support.'];
        }
        \Illuminate\Support\Facades\Log::info('[Arthur] template resolution', [
            'raw'      => $rawIndustry,
            'resolved' => $industry,
        ]);

        // PATCH (archetype routing, 2026-07-24 · P1) — the generic 'consulting'
        // fallback is an enterprise-advisory layout that fits almost nobody. If
        // we landed there but the business is clearly another archetype (tattoo
        // studio → appointment service), re-route to that archetype's best
        // template so the SECTIONS actually fit the business.
        if ($industry === 'consulting') {
            $bizArch = \App\Engines\Builder\Support\TemplateArchetypes::archetypeForBusiness($rawIndustry, (string) $name);
            if ($bizArch && $bizArch !== 'professional_advisory') {
                $reTpl = \App\Engines\Builder\Support\TemplateArchetypes::fallbackTemplate($bizArch);
                if ($reTpl && $reTpl !== 'consulting' && ($reManifest = $this->templates->getManifest($reTpl))) {
                    \Illuminate\Support\Facades\Log::info('[Arthur] archetype re-route', [
                        'from' => 'consulting', 'archetype' => $bizArch, 'to' => $reTpl, 'raw' => $rawIndustry,
                    ]);
                    $industry = $reTpl;
                    $manifest = $reManifest;
                }
            }
        }

        // Section governance (P2/P3) — blocks that don't belong to this template's
        // archetype (e.g. a "doctors" block on a restaurant — a clone artifact) or
        // that need real track-record data (stats/case_studies/clients) are
        // stripped below. The maturity gate keeps credibility blocks only when the
        // business is established WITH real data (P3); auto-builds stay conservative.
        $established  = \App\Engines\Builder\Support\TemplateArchetypes::looksEstablished($data);
        $removeBlocks = \App\Engines\Builder\Support\TemplateArchetypes::blocksToRemove($industry, $established);

        // Generate content via LLM
        $variables = $this->generateContent($data, $industry);
        if ($this->copyUnavailable !== null) {
            // EV-1000: the writing model was unavailable for every attempt. A draft made of sample copy is not a
            // draft; nothing has been persisted yet and the priced wrapper releases the reservation on 'error'.
            Log::error('[Arthur] draft refused — writing model unavailable', ['workspace_id' => $wsId, 'error' => $this->copyUnavailable]);
            return ['type' => 'error', 'message' => "The writing model is busy right now, so I haven't built anything and nothing has been charged. Please ask me to build it again in a minute.", 'retryable' => true];
        }

        // PATCH (hero-context, 2026-07-24) — signals reused by hero resolution
        // (below). The text-coverage pass and demo-brand sweep intentionally run
        // LATER (after the manifest-default injection) so their neutralizations
        // are not overwritten by that re-defaulting step.
        $rawIndustry  = trim((string) ($data['industry'] ?? ''));
        // SERVICES LIST (2026-09-05): chat() hands services over as ONE string ("Pet food, toys, accessories, grooming");
        // handleMessage() as an array. Everything downstream (cards, copy, hero) wants a clean list.
        $data['services'] = $this->normaliseServicesList($data['services'] ?? null);
        $servicesText = is_array($data['services'] ?? null)
            ? implode(', ', $data['services'])
            : (string) ($data['services'] ?? '');
        // TEMPLATE-FIT (2026-09-05): an exact template slug (e.g. 'travel_agency', 'it_services') obviously
        // fits its own template — confidentSlugFromText() returned NULL for underscored slugs that match no
        // keyword, which flipped templateFits to false, skipped the curated platform hero (findOrGenerate)
        // and generated a junk hero on EVERY build (wasted image credits, office-desk travel heroes).
        $rawSlug = preg_replace('/[^a-z0-9_]/', '', preg_replace('/[\s-]+/', '_', strtolower($rawIndustry)));
        $templateFits = ($rawIndustry === '')
            || ($rawSlug !== '' && $rawSlug === $industry)
            || ($this->confidentSlugFromText($rawIndustry) === $industry);
        $copyIndustry = $templateFits ? $industry : $rawIndustry;

        // /* h2-arthur */ workspace brand colors via single resolver (was direct creative_brand_identities read)
        $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
        if (!$kit['is_neutral']) {
            $variables['primary_color']   = $kit['primary_color'];
            $variables['secondary_color'] = $kit['secondary_color'];
        }

        // Set defaults
        $variables['business_name'] = $name;
        // Logo text fallback — 14 of 31 templates use a separate {{logo}}/{{footer_logo}}
        // text variable that defaults to a template brand placeholder ("Momentum",
        // "Foliage", "APEX MOTORS", etc.). When the user hasn't uploaded a logo,
        // those defaults leak into nav/footer. Force them all to business_name so
        // the brand stays the user's. In-browser logo edits go through a different
        // path (PUT /fields/{field}) and are unaffected.
        foreach (['logo', 'nav_logo', 'footer_logo', 'header_logo', 'brand', 'brand_name'] as $logoKey) {
            $variables[$logoKey] = $name;
        }
        $variables['footer_text'] = '© ' . date('Y') . ' ' . $name . '. All rights reserved.';
        // RISK-0128 (2026-09-07, DEC-0041): never invent a place. Address and city come from the brief or stay empty; the
        // country is the last comma-separated part of the brief's location when there is one — never a hardcoded code.
        $__briefLoc = trim((string) ($data['location'] ?? ''));
        $variables['contact_address'] = $__briefLoc;
        $variables['city'] = \App\Engines\Builder\Support\MetaDescriptionTruth::cityOf($__briefLoc);
        $variables['country'] = \App\Engines\Builder\Support\MetaDescriptionTruth::countryOf($__briefLoc);

        // BUG 2 FIX — guaranteed hero image floor from builder_default_assets.
        // This runs BEFORE any DALL-E attempt so that generation failures
        // never leave the site with an empty hero. DALL-E success below
        // simply overwrites this value.
        // 2026-09-14: a design variant's slug is not an industry — heroes are looked up by the manifest's industry.
        $heroIndustry = $this->templates->industryOf((string) $industry) ?: (string) $industry;
        $heroDefaultUrl = null;
        try {
            $defaultRow = DB::table('builder_default_assets')
                ->where('asset_type', 'hero')
                // Borrowed template → the slug's floor hero is the wrong industry
                // (consulting=office); use the neutral 'default' floor instead.
                ->where('industry', $templateFits ? $heroIndustry : 'default')
                ->first();
            if (!$defaultRow) {
                $defaultRow = DB::table('builder_default_assets')
                    ->where('asset_type', 'hero')
                    ->where('industry', 'default')
                    ->first();
            }
            // RISK-0168 root cause (2026-09-20, EV-1077): there is no 'default' row, so a "borrowed" build (the customer's
            // words did not keyword-match the design the selector chose — 55 of 112 live sites) left $heroDefaultUrl NULL;
            // every image slot then held the manifest's hero path, was judged "already filled", and the pool was never
            // applied — 23 copies of one photograph. The design's own industry hero is the floor when nothing else is.
            if (!$defaultRow) {
                $defaultRow = DB::table('builder_default_assets')->where('asset_type', 'hero')->where('industry', $heroIndustry)->first();
            }
            if ($defaultRow && !empty($defaultRow->url)) {
                $heroDefaultUrl = $defaultRow->url;
                $variables['hero_image'] = $heroDefaultUrl;
                $variables['og_image']   = $heroDefaultUrl;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] builder_default_assets lookup failed: ' . $e->getMessage());
        }

        // Hero image — prefer EXISTING media, generate only if nothing applicable.
        // Matched template: the slug's platform hero fits (findOrGenerate reuses
        // it, one DALL-E call per industry ever). Borrowed template: the slug hero
        // is the WRONG industry (tattoo → consulting → office), so search media by
        // the ACTUAL business first and generate a business-context hero only as a
        // last resort — never reuse or poison the slug's shared platform hero.
        $existingHero = $templateFits
            ? \App\Services\MediaService::findOrGenerate($heroIndustry, 'hero', 'luxury', $wsId)
            : $this->findApplicableHero($rawIndustry, $servicesText, $wsId);
        // PORTRAIT designs (2026-09-14, Owner: 'it must be a photo of a person'): the design ships its own hyper-real
        // portraits; the industry library holds rooms and offices, not people. Uploaded photos still win.
        if ((string) ($manifest['hero_kind'] ?? '') === 'portrait' && empty($data['uploaded_images'])) {
            $portrait = (string) ($manifest['variables']['hero_image']['default'] ?? '');
            if ($portrait !== '') {
                $existingHero = ['url' => $portrait, 'id' => 'design-portrait'];
                $about = (string) ($manifest['variables']['story_image']['default'] ?? '');
                if ($about !== '') { $variables['story_image'] = $about; }
                $variables['og_image'] = $portrait;
            }
        }
        if ($existingHero) {
            $variables['hero_image'] = $existingHero['url'];
            Log::info('[Arthur] Reused existing hero image: ' . ($existingHero['id'] ?? '?'));
        } else {
        // Generate hero image — context depends on whether the template fits.
        $heroPrompt = $templateFits
            ? $this->getHeroImagePrompt($heroIndustry, $data['location'] ?? 'Dubai')
            : $this->getBusinessHeroPrompt($rawIndustry, $servicesText, $data['location'] ?? 'Dubai');

        try {
            $imgResult = $this->runtime->imageGenerate(
                $heroPrompt,
                ['size' => '1792x1024', 'quality' => 'standard']
            );
            if ($imgResult['success'] ?? false) {
                // Download DALL-E image to local storage (URLs expire after 2h)
                    $heroLocalPath = '/storage/sites/heroes/' . uniqid('hero_') . '.png';
                    $heroFullPath = storage_path('app/public/sites/heroes');
                    if (!is_dir($heroFullPath)) mkdir($heroFullPath, 0755, true);
                    $imgData = @file_get_contents($imgResult['url']);
                    if ($imgData) {
                        file_put_contents($heroFullPath . '/' . basename($heroLocalPath), $imgData);
                        $variables['hero_image'] = $heroLocalPath;
                        // PATCH (image-rule, 2026-05-09; hero-context 2026-07-24) —
                        // Matched template: register as a PLATFORM asset keyed by
                        // the slug (workspace_id=null) so every future build of that
                        // industry reuses it — one DALL-E call per industry, ever.
                        // Borrowed template: register WORKSPACE-scoped and keyed by
                        // the real business industry, so it never poisons the slug's
                        // shared hero but is still reused within this workspace.
                        try {
                            \App\Services\MediaService::registerFull(
                                $heroLocalPath, $heroLocalPath, 'dalle', 'hero',
                                $templateFits ? $industry : ($rawIndustry !== '' ? $rawIndustry : $industry),
                                $templateFits ? null : $wsId,
                                $heroPrompt,
                                'dall-e-3', 'luxury'
                            );
                        } catch (\Throwable $e) {}
                    } else {
                        $variables['hero_image'] = $imgResult['url']; // fallback to URL
                    }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Hero image generation failed: ' . $e->getMessage());
        }
        } // end else (no existing hero)

        // BUGFIX (hero-floor, 2026-07-24) — guarantee hero_image points to a file
        // that EXISTS. When generation times out (intermittent runtime 503) and no
        // applicable media is found, some manifests default to a missing file
        // (e.g. beauty_salon → /storage/builder-heroes/beauty_salon.jpg, which doesn't
        // exist), rendering an empty hero. Fall back to the resolved template's
        // own builder-hero, then a guaranteed-present one.
        $heroCur = (string) ($variables['hero_image'] ?? '');
        $heroLocal = $heroCur !== '' && $heroCur[0] === '/'
            ? storage_path('app/public' . preg_replace('#^/storage#', '', $heroCur))
            : '';
        $heroOk = $heroCur !== '' && (str_starts_with($heroCur, 'http') || ($heroLocal !== '' && is_file($heroLocal)));
        if (!$heroOk) {
            foreach ([$industry, $heroIndustry, 'consulting', 'restaurant'] as $slugTry) {
                $cand = '/storage/builder-heroes/' . $slugTry . '.jpg';
                if (is_file(storage_path('app/public/builder-heroes/' . $slugTry . '.jpg'))) {
                    $variables['hero_image'] = $cand;
                    $variables['og_image']   = $variables['og_image'] ?? $cand;
                    Log::info('[Arthur] hero fell back to existing builder-hero', ['from' => $heroCur, 'to' => $cand]);
                    break;
                }
            }
        }

        // PATCH (image-rule, 2026-05-09) — Gallery generation REMOVED.
        // Old behaviour: 3 DALL-E calls per build × every site = ~$0.12/build
        // wasted on gallery shots that lived for one render.
        // New rule: pull from the platform media library (industry-tagged
        // template_image / hero category), fall back to the hero image
        // if the library has nothing for this industry. ZERO new DALL-E
        // calls for gallery slots, ever.
        $galleryImages = [];
        $heroFloor = $variables['hero_image'] ?? '';
        try {
            // EV-1000 (2026-09-12): matched by industry FAMILY (see galleryTagFamily) — the exact-name match found
            // nothing for a design variant and every slot became the hero. The hero itself is excluded.
            $galleryTags = self::galleryTagFamily((string) (($manifest['industry'] ?? '') ?: ''), $industry, (string) ($copyIndustry ?? ''));
            $galleryImages = DB::table('media')
                ->where('is_platform_asset', 1)
                ->where('asset_type', 'image')
                ->whereIn('category', ['template_image', 'hero', 'gallery'])
                ->where(function ($q) use ($galleryTags) { foreach ($galleryTags as $t) $q->orWhere('tags', 'like', '%"' . $t . '%'); })
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->where('url', '!=', (string) $heroFloor)
                ->orderByRaw(self::tagPriorityOrder($galleryTags))
                ->limit(11)
                ->pluck('url')
                ->unique()->values()
                ->toArray();
            Log::info('[Arthur] gallery library', ['tags' => $galleryTags, 'found' => count($galleryImages)]);
        } catch (\Throwable $e) {
            Log::warning('[Arthur] gallery library lookup failed: ' . $e->getMessage());
        }
        // Fill gallery slots — library photos first (cycled when the library is short), the hero only when the
        // library has nothing at all for this family.
        for ($gi = 1; $gi <= 11; $gi++) {
            $variables['gallery_image_' . $gi] = $galleryImages !== [] ? $galleryImages[($gi - 1) % count($galleryImages)] : $heroFloor;
        }
        // EV-1000: the manifest's own gallery_1..N slots (31 templates ship them all defaulted to the hero) get
        // DISTINCT library photos too; a slot already holding the customer's own photo is left alone.
        if ($galleryImages !== [] && is_array($manifest['variables'] ?? null)) {
            $gn = 0;
            foreach ($manifest['variables'] as $gk => $gs) {
                if (!preg_match('/^gallery_\d+$/', (string) $gk)) continue;
                $curG = (string) ($variables[$gk] ?? '');
                $defG = is_array($gs) ? (string) ($gs['default'] ?? '') : '';
                if ($curG !== '' && $curG !== $defG && $curG !== $heroFloor) continue;
                $variables[$gk] = $galleryImages[$gn % count($galleryImages)];
                $gn++;
            }
        }


        // Hide gallery (no photos yet) and extra service slots
        $variables['gallery_display'] = 'display:none';
        $variables['feature_display'] = 'display:none';
        $variables['service_4_display'] = 'display:none';
        $variables['service_5_display'] = 'display:none';
        $variables['service_6_display'] = 'display:none';

        // BUG 1 FIX — respect explicitly-requested pages. When the user
        // lists pages ("home, about, services, testimonials, blog, contact"),
        // force the matching section's *_display var to '' (show) even if
        // it was previously defaulted to display:none (e.g. gallery). Only
        // touches manifest-declared variables so unknown templates stay
        // safe. "home" is implicit.
        if (!empty($data['pages']) && is_array($data['pages'])) {
            $requested = array_map(
                fn($p) => strtolower(trim((string)$p)),
                $data['pages']
            );
            $pageToDisplayVar = [
                'about'        => 'about_display',
                'services'     => 'services_display',
                'gallery'      => 'gallery_display',
                'testimonials' => 'testimonials_display',
                'blog'         => 'blog_display',
                'contact'      => 'contact_display',
                'team'         => 'team_display',
                'portfolio'    => 'portfolio_display',
                'process'      => 'process_display',
            ];
            $manifestVarsForPages = $manifest['variables'] ?? [];
            foreach ($pageToDisplayVar as $page => $displayVar) {
                if (!in_array($page, $requested, true)) continue;
                // Only set when the manifest declares the var OR we already
                // hold a value for it — avoids emitting hollow keys.
                if (array_key_exists($displayVar, $manifestVarsForPages)
                    || array_key_exists($displayVar, $variables)) {
                    $variables[$displayVar] = '';
                }
            }
        }

        // FIX 2 — distribute user uploads across ALL image variables in
        // manifest order. Hero_image → first upload (or DALL-E if DALL-E
        // produced a non-default URL). about/gallery/team/portfolio →
        // remaining uploads in manifest order. Any slot still empty falls
        // back to the industry hero default.
        $uploadedImages = [];
        if (isset($data['uploaded_images']) && is_array($data['uploaded_images'])) {
            foreach ($data['uploaded_images'] as $u) {
                if (is_string($u) && $u !== '') $uploadedImages[] = $u;
            }
        }
        $uploadedImages = array_slice($uploadedImages, 0, 10); // hard cap 10
        $uploadQueue    = $uploadedImages;

        // FIX 1 (2026-04-20) — logo_url must NEVER receive an auto-assigned
        // upload from general image distribution. The wizard shows a text
        // fallback until the user explicitly says "upload a logo" (state.logo_upload=true).
        $logoUploadOptIn = !empty($data['logo_upload']);
        if (!$logoUploadOptIn && !empty($uploadQueue)) {
            $variables['logo_url'] = ''; // force empty so text fallback renders
        }
        // T1 (2026-04-20) — if the wizard collected a temp logo, use it
        // directly on the in-memory variables. Permanent copy happens
        // AFTER the website row exists (we need the websiteId for the path).
        $logoTempPath = $data['logo_temp_path'] ?? null;
        $logoTempUrl  = $data['logo_url']       ?? null;
        if ($logoUploadOptIn && $logoTempUrl) {
            $variables['logo_url'] = $logoTempUrl; // transitional until we move it
        }

        try {
            $manifestForImgs = $this->templates->getManifest($industry) ?: [];
            foreach (($manifestForImgs['variables'] ?? []) as $varKey => $varSpec) {
                $type = is_array($varSpec) ? ($varSpec['type'] ?? 'text') : 'text';
                if ($type !== 'image') continue;

                // Skip logo_url from general distribution unless user opted in.
                if ($varKey === 'logo_url' && !$logoUploadOptIn) continue;

                // A slot already holds a non-default URL → DALL-E or caller
                // put something real there; keep it. The default floor URL
                // is treated as "still empty" so uploads can override it.
                $current = $variables[$varKey] ?? '';
                $isJustFloor = ($current === '' || $current === null || $current === $heroDefaultUrl);

                if ($isJustFloor && !empty($uploadQueue)) {
                    $variables[$varKey] = array_shift($uploadQueue);
                } elseif ($current === '' || $current === null) {
                    $variables[$varKey] = $heroDefaultUrl
                        ?? (is_array($varSpec) ? ($varSpec['default'] ?? '') : '');
                }
            }
            // Guarantee hero + og have something — floor to industry default.
            foreach (['hero_image','og_image'] as $rk) {
                if (empty($variables[$rk]) && $heroDefaultUrl) {
                    $variables[$rk] = $heroDefaultUrl;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // PATCH (image-injection, 2026-05-09) — Media-library fallback.
        // After uploaded-images + manifest defaults are applied, any
        // image var still empty (or still on the industry hero floor)
        // gets filled from the workspace's media library, prioritised by
        // industry tag, then platform-asset template_image category,
        // then any platform asset. This means a Bico Plastic Surgery
        // site with 0 uploads still gets aesthetic_clinic-tagged photos
        // in the doctor_*_image, gallery_*_image slots — instead of the
        // hero leaking into every slot.
        try {
            $imagePool = $this->buildImagePool($industry, $wsId, self::galleryTagFamily((string) (($manifest['industry'] ?? '') ?: ''), $industry, (string) ($copyIndustry ?? '')));
            $this->injectImagesToTemplate(
                $variables,
                $manifestForImgs ?? ($this->templates->getManifest($industry) ?: []),
                $imagePool,
                $heroDefaultUrl,
                $logoUploadOptIn
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] image pool fallback failed: ' . $e->getMessage());
        }

        // FIX 3 — after all image + content work, walk EVERY manifest var
        // and substitute the manifest default when the current value is
        // empty. Rule: generated/uploaded content > manifest default > empty.
        try {
            $mf = $this->templates->getManifest($industry) ?: [];
            foreach (($mf['variables'] ?? []) as $mKey => $mSpec) {
                $default = is_array($mSpec) ? ($mSpec['default'] ?? '') : '';
                $have    = $variables[$mKey] ?? null;
                if (($have === '' || $have === null) && $default !== '' && $default !== null) {
                    $variables[$mKey] = $default;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // PATCH (no-static-text, 2026-07-24) — MUST run AFTER the FIX-3 manifest
        // re-defaulting above, otherwise every blank we set is overwritten by the
        // sample default again. Two passes on the FINAL variable set:
        //   (1) FULL TEXT COVERAGE — regenerate every content field still holding
        //       its manifest SAMPLE value from business context (text is cheap).
        //   (2) DEMO-BRAND SWEEP — neutralize any field still carrying the
        //       template's demo brand (canonical/og URLs, sample e-mails, or
        //       "Summit Advisors is a…" prose) so no static template text renders.
        // DESIGN DIRECTION (2026-09-05): Arthur collects `style` (and now `fonts`) — carry them into
        // the variable set so TemplateService/BuilderRenderer apply the DesignStyle layer. Also parse
        // explicit font mentions from the description so "use Poppins" is honoured.
        $dsStyle = \App\Engines\Builder\Support\DesignStyle::normaliseStyle((string) ($data['style'] ?? $data['design_style'] ?? ''));
        $dsFonts = is_array($data['fonts'] ?? null) ? $data['fonts'] : [];
        $parsedFonts = \App\Engines\Builder\Support\DesignStyle::parseFonts((string) ($data['description'] ?? '') . ' ' . (string) ($data['style'] ?? ''));
        $variables['design_style'] = $dsStyle ?? '';
        $variables['font_display'] = (string) ($dsFonts['display'] ?? $dsFonts['heading'] ?? $parsedFonts['display'] ?? '');
        $variables['font_body']    = (string) ($dsFonts['body'] ?? $parsedFonts['body'] ?? '');

        $variables = $this->fillTemplateTextCoverage(
            $variables, is_array($manifest) ? $manifest : [], $data,
            $copyIndustry, $servicesText, $data['location'] ?? 'Dubai', $established
        );
        // SERVICE CARDS ARE ATOMIC (2026-09-05): title, description, icon and link of one card describe ONE service.
        $variables = $this->reconcileServiceCards($variables, $data, is_array($manifest) ? $manifest : [], $industry);

        // PATCH (section-labels, 2026-07-24 · P2b) — the re-sectioned clone
        // templates carry a generic "services" block that IS the menu / catalog /
        // programs / destinations for their industry. Relabel its heading so it
        // reads correctly (a restaurant shows "Our Menu", retail "Shop", courses
        // "Our Courses"). Content is already industry-correct from generateContent.
        foreach (\App\Engines\Builder\Support\TemplateArchetypes::sectionLabels($industry) as $lk => $lv) {
            $variables[$lk] = $lv;
        }

        // P2b-full — build the bespoke industry section (real menu / catalog /
        // destinations) that will REPLACE the generic "services" block at render.
        $bespokeHtml = $this->bespokeSectionFor($industry, $data, $wsId);

        // PATCH (no-fabricated-credibility, 2026-07-24) — Templates ship invented
        // track-record props: stats ("22 Years in MENA", "AED 6B+ Value Created"),
        // client logos ("Al Noor Holding", "Zenith Capital"), and case studies
        // ("AED 380M Capital Released"). A brand-new business has done NONE of
        // this, and regenerating would only invent DIFFERENT fake numbers — a
        // false claim. Blank every credibility value by variable name; the
        // matching sections are then hidden at render (see scrubSampleStaff).
        // Users add real stats/clients later in the builder. (P3) Only blank when
        // NOT established — an established business keeps its credibility blocks
        // and the real values supplied via build_data / the builder UI.
        $credPattern = '/^(stat_\d|hero_stat_\d|strip_stat_\d|stats_strip_\d|client_logo_?\d|clients_title|clients_eyebrow|case_\d)/i';
        $isCred = fn($mk) => preg_match($credPattern, (string) $mk) || preg_match('/_metric_\d+_(value|label)$/i', (string) $mk);
        // (P3) When ESTABLISHED, inject the business's REAL credibility data
        // (build_data stats/clients/case_studies) into the kept blocks; blank any
        // credibility field NOT supplied so no template SAMPLE ("Summit Advisors",
        // "Al Noor Holding") can survive. When NOT established, blank all.
        $injected = $established
            ? $this->injectCredibilityData($variables, $data, $manifest['variables'] ?? [])
            : [];
        foreach (($manifest['variables'] ?? []) as $mk => $_mspec) {
            if (isset($injected[$mk])) continue;
            if ($isCred($mk)) {
                $variables[$mk] = '';
            }
        }

        // NOTE: lowercase BEFORE stripping — '/[^a-z0-9]/' also removes A-Z, so
        // "Summit Advisors" would wrongly become "ummitdvisors" and never match.
        $domainSlug = preg_replace('/[^a-z0-9]/', '', strtolower((string) $name)) ?: 'business';
        $sampleName = trim((string) ($manifest['variables']['business_name']['default'] ?? ''));
        $sampleSlug = preg_replace('/[^a-z0-9]/', '', strtolower($sampleName));

        // Unconditional SEO/e-mail neutralization — canonical/og URLs carry the
        // template's demo domain (e.g. coralresort.ae) whose slug need not match
        // the demo business_name, so brand-matching alone misses them. A fresh
        // draft has no canonical anyway -> blank; rewrite any e-mail to the
        // business's own domain slug.
        foreach (($manifest['variables'] ?? []) as $mk => $mspec) {
            $cur = $variables[$mk] ?? (is_array($mspec) ? ($mspec['default'] ?? null) : null);
            if (!is_string($cur) || $cur === '') continue;
            if (preg_match('/canonical|og_?url/i', (string) $mk)) {
                $variables[$mk] = '';
            } elseif ((preg_match('/email/i', (string) $mk) || strpos($cur, '@') !== false)
                      && preg_match('/@[a-z0-9.-]+\.[a-z]{2,}/i', $cur)) {
                $variables[$mk] = preg_replace('/@[^\s"\'<>]+/', '@' . $domainSlug . '.com', $cur);
            }
        }

        if (strlen($sampleSlug) >= 4 && is_array($manifest['variables'] ?? null)) {
            foreach ($manifest['variables'] as $mk => $mspec) {
                if (!is_array($mspec)) continue;
                $cur = $variables[$mk] ?? ($mspec['default'] ?? null);
                if (!is_string($cur) || $cur === '') continue;
                $hasName = $sampleName !== '' && stripos($cur, $sampleName) !== false;
                $hasSlug = strpos(preg_replace('/[^a-z0-9]/', '', strtolower($cur)), $sampleSlug) !== false;
                if (!$hasName && !$hasSlug) continue;
                $type = strtolower((string) ($mspec['type'] ?? ''));
                if (strpos($cur, '@') !== false || preg_match('/email/i', (string) $mk)) {
                    $variables[$mk] = preg_replace('/@[^\s"\'<>]+/', '@' . $domainSlug . '.com', $cur);
                } elseif ($type === 'url' || stripos($cur, 'http') === 0 || preg_match('/url|canonical|href|link/i', (string) $mk)) {
                    $variables[$mk] = '';
                } elseif ($hasName) {
                    $variables[$mk] = str_ireplace($sampleName, (string) $name, $cur);
                } else {
                    $variables[$mk] = '';
                }
            }
        }

        // T2 (2026-04-20) — if wizard collected a palette (from logo color
        // extraction), promote it into $data['colors'] so applyBrandColors
        // uses it. Also persist bg/text so templates with those vars pick up.
        if (!empty($data['palette']) && is_string($data['palette'])) {
            $data['palette'] = \App\Engines\Builder\Support\ColorTheme::find($data['palette']) ?? null;
        }
        if (!empty($data['palette']) && is_array($data['palette'])) {
            $pal = $data['palette'];
            $data['colors'] = array_merge(
                (array)($data['colors'] ?? []),
                [
                    'primary'   => $pal['primary']   ?? null,
                    'secondary' => $pal['secondary'] ?? null,
                    'accent'    => $pal['accent']    ?? null,
                ]
            );
            if (!empty($pal['bg']))   $variables['bg_color']   = $pal['bg'];
            if (!empty($pal['text'])) $variables['text_color'] = $pal['text'];
        }

        // FIX 1 — brand colors override (CSS custom properties fed by {{var}})
        try {
            $mf2 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $this->applyBrandColors($variables, $mf2, $data['colors'] ?? []);
        } catch (\Throwable $e) { /* non-fatal */ }

        // FIX 4 — cross-industry leak guard on generated service titles.
        // If any service_N_title smells like the wrong industry (e.g. "Fine
        // Dining" on a fitness site), fall back to the manifest default for
        // that specific field.
        try {
            $mf3 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $leakFields = ['service_1_title','service_1_text','service_2_title','service_2_text',
                           'service_3_title','service_3_text','service_4_title','service_5_title',
                           'service_6_title','program_1_title','program_2_title','program_3_title',
                           'cuisine_1_name','cuisine_2_name','cuisine_3_name',
                           'blog_section_title','blog_1_title','blog_2_title','blog_3_title'];
            foreach ($leakFields as $lf) {
                if (empty($variables[$lf])) continue;
                if ($this->isCrossIndustryLeak((string)$variables[$lf], $industry)) {
                    $spec = $mf3['variables'][$lf] ?? null;
                    $variables[$lf] = is_array($spec) ? ($spec['default'] ?? '') : '';
                    Log::info("[Arthur] Cross-industry leak fallback on {$lf} for {$industry}");
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // NEVER A FAKE PERSON (B3, generalises EV-0757). Some manifests ship SAMPLE
        // personnel names as the variable default (e.g. consulting member_1_name
        // "Tariq Al-Sayed"); FIX-3 re-defaulting applies them and they slip past the
        // coverage-pass blanking. If a personnel _N_name still equals its manifest
        // sample default, the customer supplied no real team -> blank it, and phantom-
        // card + empty-section stripping removes the fabricated person. A real name or
        // LLM value never equals the sample default, so this is safe.
        try {
            $mfPeople = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $personRx = '/^(doctor|dentist|physician|surgeon|therapist|trainer|'
                . 'instructor|coach|staff|team|member|attorney|lawyer|agent|broker|'
                . 'realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist|'
                . 'expert|leader|principal|partner|founder)_\\d+_name$/i';
            foreach (($mfPeople['variables'] ?? []) as $mk => $mspec) {
                if (! preg_match($personRx, (string) $mk)) {
                    continue;
                }
                $def = is_array($mspec) ? trim((string) ($mspec['default'] ?? '')) : '';
                $cur = trim((string) ($variables[$mk] ?? ''));
                if ($cur !== '' && $cur === $def) {
                    $variables[$mk] = '';
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // NEVER FABRICATE A PERSON (2026-09-05): unnumbered hero personnel-name fields
        // (broker_name, agent_name, …) are the single-person identity of a personal-brand
        // template. The LLM/manifest would otherwise ship an invented individual (e.g.
        // "James Whitfield" / "Arthur Khalil"). Force them to the business name so the hero
        // shows the real business; the owner edits to their own name in the editor.
        try {
            $heroPersonRx = '/^(broker|agent|realtor|doctor|dentist|physician|surgeon|therapist|'
                . 'trainer|instructor|coach|attorney|lawyer|stylist|barber|nurse|advisor|consultant|'
                . 'specialist|founder|principal|owner|host|chef)_name$/i';
            $bizName = trim((string) ($data['business_name'] ?? $name));
            if ($bizName !== '') {
                foreach (($mfPeople['variables'] ?? []) as $mk => $mspec) {
                    if (preg_match($heroPersonRx, (string) $mk)) { $variables[$mk] = $bizName; }
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Empty-content-block guard (2026-09-05): showcase sections whose repeating items
        // (stat_N_*, project_N_*, client_N_*, result_N_*, …) were blanked by the no-static-text
        // policy would otherwise render as empty bands. Drop any content section that has no
        // real content — no fake data AND no empty section.
        foreach ($this->emptyContentBlocksToRemove($industry, $variables) as $eb) {
            if (!in_array($eb, $removeBlocks, true)) { $removeBlocks[] = $eb; }
        }

        // RISK-0128 (2026-09-07, DEC-0041; moved before render() in RISK-0128e — the served HTML is rendered from these
        // variables HERE, so a guard placed after the render only corrected the stored row): the description that feeds the meta tag, og:description and the JSON-LD must be
        // truthful to the brief's geography — never a template default, never a place the brief did not name (EV-0921).
        $variables['meta_description'] = \App\Engines\Builder\Support\MetaDescriptionTruth::resolve(
            (string) ($variables['meta_description'] ?? ''),
            (string) (is_array($manifest['variables']['meta_description'] ?? null) ? ($manifest['variables']['meta_description']['default'] ?? '') : ''),
            (string) $name, (string) $industry,
            \App\Engines\Builder\Support\MetaDescriptionTruth::text($data['services'] ?? ''),   // services may be a list here (build_data), not a sentence
            \App\Engines\Builder\Support\MetaDescriptionTruth::text($data['location'] ?? '')
        );
        // RISK-0128 residual (2026-09-07, DEC-0041): a manifest default the copy pass never overwrote must not carry the
        // template's origin place onto the customer's page (site 629: venue_7 "Dubai Opera" on a Manchester brief).
        try {
            [$variables, $__blankedDefaults] = \App\Engines\Builder\Support\MetaDescriptionTruth::neutraliseSurvivingDefaults(
                $variables, is_array($manifest['variables'] ?? null) ? $manifest['variables'] : [],
                \App\Engines\Builder\Support\MetaDescriptionTruth::text($data['location'] ?? ''), (string) $name);
            if ($__blankedDefaults !== []) Log::info('[Arthur] origin-place defaults blanked', ['workspace_id' => $wsId, 'keys' => $__blankedDefaults]);
        } catch (\Throwable $__e) { Log::warning('[Arthur] origin-place default check failed: ' . $__e->getMessage()); }

        // Render template — TemplateService also carries industry-scoped
        // image defaults so even variables unknown to the manifest won't
        // render as hollow sections.
        try {
            $html = \App\Engines\Builder\Support\TemplateArchetypes::removeBlocks(
                $this->scrubSampleStaff($this->templates->render($industry, $variables)),
                $removeBlocks
            );
            $html = \App\Engines\Builder\Support\SectionLibrary::replaceBlock($html, 'services', $bespokeHtml);
            // removeBlocks can delete a whole section (e.g. certifications) AFTER
            // TemplateService::render already ran its in-render anchor cleanup, orphaning
            // that section's nav links. Re-run the dangling-anchor pass on the FINAL html.
            $html = $this->templates->stripDanglingNavAnchors($html);
        } catch (\Throwable $e) {
            // BUILDER888 P1-8B (2026-08-10) — this line showed a customer a raw
            // PHP TypeError during the frozen Journey A. The internal cause is
            // logged in full with a correlation id; the customer gets safe wording.
            $failure = \App\Engines\Builder\Support\BuilderErrorContract::fromThrowable(
                $e,
                \App\Engines\Builder\Support\BuilderErrorContract::TEMPLATE_RENDER_FAILED,
                ['workspace_id' => $wsId ?? null, 'industry' => $industry ?? null, 'stage' => 'template_render']
            );

            return [
                'type'           => 'error',
                'message'        => $failure['build_error'],
                'error_category' => $failure['error_category'],
                'correlation_id' => $failure['correlation_id'],
                'retryable'      => $failure['retryable'],
            ];
        }

        // Create website record
        // ── BUILDER888 P1-6 · Law 11 ────────────────────────────────────────
        // Arthur no longer writes Builder tables. It compiles ONE generation
        // document and hands it to the Builder domain, which persists the
        // website and every page inside a single transaction.
        //
        // Page specs are assembled here (they depend only on $data, never on
        // $websiteId) so the whole document exists before anything is written.
        // Ordering, dedupe and positions are preserved exactly as before.
        $isNewsChannel = ($industry === 'news_channel');
        $homePos       = 0;
        $pageSpecs     = [];

        $pageSpecs[] = [
            'title'       => 'Home',
            'slug'        => 'home',
            'type'        => 'page',
            'status'      => 'published',
            'position'    => $homePos++,
            'is_homepage' => true,
            'sections'    => $this->buildDefaultSectionsForPage('home', $data),
        ];

        // User-requested pages (wizard state.pages[]), excluding Home + Blog.
        $extraPages = $data['pages'] ?? [];
        $seen = ['home' => true, 'blog' => true];
        if (is_array($extraPages)) {
            foreach ($extraPages as $p) {
                $title = trim((string) (is_array($p) ? ($p['title'] ?? $p['name'] ?? '') : $p));
                if ($title === '') continue;
                $slug = \Illuminate\Support\Str::slug($title);
                if ($slug === '' || isset($seen[$slug])) continue;
                $seen[$slug] = true;
                $pageSpecs[] = [
                    'title'       => ucfirst($title),
                    'slug'        => $slug,
                    'type'        => 'page',
                    'status'      => 'published',
                    'position'    => $homePos++,
                    'is_homepage' => false,
                    'sections'    => $this->buildDefaultSectionsForPage($slug, $data),
                ];
            }
        }

        // news_channel sites label this page "News" (slug=news); same type and
        // sections, only the user-visible label differs.
        $pageSpecs[] = [
            'title'       => $isNewsChannel ? 'News' : 'Blog',
            'slug'        => $isNewsChannel ? 'news' : 'blog',
            'type'        => 'blog',
            'status'      => 'published',
            'position'    => $homePos++,
            'is_homepage' => false,
            'sections'    => $this->buildDefaultSectionsForPage('blog', $data),
        ];

        try {
            $generation = \App\Engines\Builder\Support\BuilderGenerationDTO::fromArray([
                'workspace_id'       => $wsId,
                // BUILDER888 P1-6 — the actor is threaded explicitly from the
                // authenticated request. Deliberately NOT $data['user_id']: that
                // arrives from chat/provider payload and must never be able to
                // forge the owner of a website.
                'created_by'         => $actorId,
                'name'               => $name,
                // Representation convergence is deferred (audit R5): generated
                // sites stay on the template model until the semantic tree can
                // carry their fidelity.
                'type'               => 'template',
                'template_industry'  => $industry,
                'template_variables' => $variables,
                'settings'           => [
                    'industry'     => $industry,
                    'template'     => $industry,
                    'generated_by' => 'arthur',
                    // Why this design: method, confidence, reasoning, runners-up. Auditable, and readable by Sarah.
                    'template_selection' => $tplSelection,
                ],
                'pages'              => $pageSpecs,
                'generation_meta'    => ['source' => 'arthur_wizard'],
            ]);

            $persisted = app(\App\Engines\Builder\Services\BuilderApplicationService::class)
                ->generateWebsite($generation);

            $websiteId = (int) $persisted['website_id'];
        } catch (\App\Engines\Builder\Exceptions\BuilderRefusedException $e) {
            // BUILDER888 P1-6-a — a plan/entitlement refusal. Not a fault: the
            // customer gets the platform's own actionable wording, unchanged.
            $refusal = \App\Engines\Builder\Support\BuilderErrorContract::failureWithMessage(
                \App\Engines\Builder\Support\BuilderErrorContract::LIMIT_REACHED,
                $e->getMessage()
            );

            \Illuminate\Support\Facades\Log::info('[Builder888] generation refused', [
                'workspace_id'   => $wsId,
                'correlation_id' => $refusal['correlation_id'],
                'limit_reached'  => $e->limitReached,
            ]);

            return [
                'type'           => 'error',
                'message'        => $refusal['build_error'],
                'error_category' => $refusal['error_category'],
                'correlation_id' => $refusal['correlation_id'],
                'retryable'      => false,
            ];
        } catch (\Throwable $e) {
            // Persistence failed atomically — no website, no pages. The AI work
            // is already paid for and is NOT repeated; the caller is told the
            // truth via the canonical error contract.
            $failure = \App\Engines\Builder\Support\BuilderErrorContract::fromThrowable(
                $e,
                \App\Engines\Builder\Support\BuilderErrorContract::PERSISTENCE_FAILED,
                ['workspace_id' => $wsId, 'industry' => $industry, 'stage' => 'generation_persistence']
            );

            return [
                'type'           => 'error',
                'message'        => $failure['build_error'],
                'error_category' => $failure['error_category'],
                'correlation_id' => $failure['correlation_id'],
                'retryable'      => $failure['retryable'],
            ];
        }

        // ── post-persistence side effects (audit R9) ────────────────────────
        // All BEST-EFFORT. The website and its pages are already committed, so
        // none of these may fail the generation. Each is guarded individually
        // and records its own failure; caveats are collected for the response.
        $sideEffectWarnings = [];

        // PATCH (Option C, 2026-05-09) — for news_channel sites, seed
        // 6 LLM-generated news articles into the articles table so the
        // public news API has live content from day 1, AND inject the
        // first 6 into the static template variables for SEO + no
        // empty-state on first paint.
        if ($industry === 'news_channel') {
            try {
                $seeded = $this->seedNewsArticles($wsId, $name, $data['location'] ?? 'Dubai', $variables);
                if ($seeded > 0) {
                    // BUILDER888 P1-6 · Law 11 — the Builder domain owns this write.
                    app(\App\Engines\Builder\Services\BuilderService::class)
                        ->updateTemplateVariables($websiteId, $variables);
                    Log::info('[Arthur] news_channel seeded ' . $seeded . ' articles for ws=' . $wsId);
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] news_channel article seeding failed: ' . $e->getMessage());
            }
        }

        // T1 (2026-04-20) — move temp logo into permanent site storage.
        // Done BEFORE deploy so the rendered HTML points at the final URL.
        if ($logoUploadOptIn && $logoTempPath && is_file($logoTempPath)) {
            try {
                $ext = pathinfo($logoTempPath, PATHINFO_EXTENSION) ?: 'png';
                $siteDir = storage_path("app/public/sites/{$websiteId}");
                if (!is_dir($siteDir)) @mkdir($siteDir, 0775, true);
                $destName = 'logo.' . strtolower($ext);
                $destPath = $siteDir . '/' . $destName;
                if (@copy($logoTempPath, $destPath)) {
                    @chmod($destPath, 0644);
                    @unlink($logoTempPath);
                    $permUrl = '/storage/sites/' . $websiteId . '/' . $destName . '?v=' . time();
                    $variables['logo_url'] = $permUrl;
                    // BUILDER888 P1-6 · Law 11 — the Builder domain owns this write.
                    app(\App\Engines\Builder\Services\BuilderService::class)
                        ->updateTemplateVariables($websiteId, $variables);
                    // Re-render with the final URL (transitional render above used temp URL).
                    try {
                        $html = \App\Engines\Builder\Support\TemplateArchetypes::removeBlocks(
                            $this->scrubSampleStaff($this->templates->render($industry, $variables)),
                            $removeBlocks
                        );
                        $html = \App\Engines\Builder\Support\SectionLibrary::replaceBlock($html, 'services', $bespokeHtml);
            // removeBlocks can delete a whole section (e.g. certifications) AFTER
            // TemplateService::render already ran its in-render anchor cleanup, orphaning
            // that section's nav links. Re-run the dangling-anchor pass on the FINAL html.
            $html = $this->templates->stripDanglingNavAnchors($html);
                    } catch (\Throwable $_e) { /* keep previous html */ }
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] logo copy failed: ' . $e->getMessage());
            }
        }

        // Deploy HTML
        // LEGACY: T3.4 — static-HTML-only path retained for backwards
        // compatibility (Chef Red-style sites). New sites also get a
        // canonical sections_json below so BuilderRenderer can serve them.
        // Remove this deploy() call once Chef Red is migrated and arthur-edit
        // closure is rewritten on top of sections_json (Patch 8.5+).
        // BUILDER888 P1-6 (R9) — was unguarded. The site and its pages are now
        // already committed, so an unhandled throw here would report total
        // failure for a website that exists and works. The static export feeds
        // the Admin draft link only; public serving does not use it.
        try {
            $this->templates->deploy($websiteId, $html);
        } catch (\Throwable $e) {
            $sideEffectWarnings[] = 'static_export';
            Log::warning('[Builder888] post-persistence side effect failed', [
                'effect' => 'template_deploy', 'website_id' => $websiteId,
                'workspace_id' => $wsId, 'error' => $e->getMessage(),
            ]);
        }

        // FIX 2 (2026-04-20) — always create default pages: Home (homepage)
        // + Blog for every generated website. Any other pages the wizard
        // extracted via state.pages[] also get created. Failures are
        // non-fatal; logged but don't block the wizard response.
        //
        // PATCH 8 (2026-05-08) — every page row now ALSO carries a
        // canonical sections_json built from the wizard data so
        // BuilderRenderer can serve the site without falling back to the
        // static index.html. New sites are pure-canonical from now on.
        // BUILDER888 P1-6 — the three page inserts that lived here are gone.
        // Pages are now created inside the same transaction as the website by
        // BuilderApplicationService, from the page specs assembled above.
        // Law 11: Arthur performs no Builder-owned persistence.

        // PATCH (FIX 2, 2026-05-09) — Auto-enable the chatbot widget on
        // every build. INC-0006: the row is keyed on the WEBSITE just built, not the
        // workspace, so building a second site for the same business gives that site its
        // own chatbot and its own business context instead of overwriting the first's.
        // We updateOrInsert keyed on (workspace_id, website_id) and
        // FORCE enabled=1 (per owner directive — auto-enable should
        // override any prior user toggle).
        // Once the row is enabled, PublishedSiteMiddleware injects
        // <script src="…/chatbot.js?ws=N" async></script> before </body>
        // on every served page automatically.
        try {
            DB::table('chatbot_settings')->updateOrInsert(
                ['workspace_id' => $wsId, 'website_id' => (int) $websiteId],
                [
                    'enabled'               => 1,
                    // PATCH (per-website greeting, 2026-05-09) — {{business}}
                    // is substituted at config-fetch time with the actual
                    // website name (resolved from Origin header), so each
                    // tenant subdomain greets as its own brand.
                    'greeting'              => 'Hi! Welcome to {{business}}. How can I help you today?',
                    'business_context_text' => $data['description'] ?? null,
                    'updated_at'            => now(),
                    'created_at'            => now(),
                ]
            );
            Log::info('[Arthur] chatbot auto-enabled', ['workspace_id' => $wsId, 'website_id' => (int) $websiteId]);
        } catch (\Throwable $e) {
            Log::warning('[Arthur] chatbot auto-enable failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        // PATCH (FIX 4, 2026-05-09) — Inject the chatbot bootstrap script
        // directly into the static index.html on disk. The
        // PublishedSiteMiddleware already injects on subdomain serve, but
        // direct /storage/sites/{id}/index.html access (admin previews,
        // raw URL sharing, etc.) wouldn't see the script otherwise. Idempotent.
        try {
            $staticHtmlPath = storage_path('app/public/sites/' . $websiteId . '/index.html');
            if (is_file($staticHtmlPath)) {
                $html = file_get_contents($staticHtmlPath);
                if ($html && strpos($html, 'chatbot.js?ws=') === false) {
                    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
                    $origin = (str_contains($appHost, 'levelupgrowth.io'))
                        ? 'https://' . ($appHost ?: 'staging.levelupgrowth.io')
                        : 'https://staging.levelupgrowth.io';
                    // INC-0006: the exported file is served straight from disk, so the website id has to
                    // be baked in — without it the widget cannot tell which of the business's sites it
                    // is running on, and every site would answer with the same greeting and context.
                    $tag = '<script src="' . $origin . '/chatbot.js?ws=' . $wsId
                         . '&w=' . $websiteId . '" async></script>';
                    $pos = strripos($html, '</body>');
                    if ($pos !== false) {
                        $html = substr($html, 0, $pos) . $tag . substr($html, $pos);
                    } else {
                        $html .= "\n" . $tag;
                    }
                    file_put_contents($staticHtmlPath, $html);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] static chatbot script injection failed: ' . $e->getMessage());
        }

        // Log intelligence
        try {
            app(\App\Core\Intelligence\EngineIntelligenceService::class)
                ->recordToolUsage('builder', 'wizard_generate', 0.9);
        } catch (\Throwable $e) {}

        return [
            'type' => 'complete',
            'message' => "Your website for **{$name}** is ready! I built it from scratch with a premium " . str_replace('_', ' ', $industry) . " design and generated all the copy for you. You can preview it, edit the text, or publish it right away.",
            'website_id' => $websiteId,
            'workspace_id' => $wsId, // P1 — the (possibly new) workspace this site lives in; FE switches to it
            'name' => $name,
            'industry' => $industry,
        ];
    }

    /**
     * EV-1000 (2026-09-12): every build copy call runs on the Runtime's synthesis lane (55 s provider budget instead of
     * the interactive lane's ~12 s) with an explicit reasoning budget. DeepSeek V4 flash spends 1.3-3k reasoning
     * tokens on a 35-field request; the Runtime's default budget of 2,000 was exhausted before the answer was
     * written (DEEPSEEK_EMPTY_FINAL_CONTENT → reported as 429 rate_limited). Measured: 35 fields in 12.0 s with this.
     */
    private const BUILD_CALL_EXTRA = ['workload' => 'synthesis', 'reasoning_budget' => 6000];

    /**
     * EV-1000 (2026-09-12): CATALOGUE items — listings, rooms, menu dishes, plans, vehicles … — are the template's sample
     * inventory. The model writes them as realistic SAMPLES in the local currency (even the short fields: price, badge,
     * specs) and they are exempt from the facts sweep; the customer replaces them with real stock in the editor.
     */
    private const CATALOGUE_KEY = '/^(listing|property|room|menu|plan|vehicle|featured|special|area|product|package|course|class|dish|item)_\d+_(price|currency|badge|specs|beds|baths|sqft|sqm|size|location|title|name|meta|tag|detail)$/i';

    /** EV-1000: variables that hold a FACT about the business (never generated, never sampled). $fm[2] = the kind. */
    // 2026-09-14: licence / registration numbers are facts too (a realtor's licence line was open to invention).
    private const FACT_KEY = '/^(?!.*_label$)(?!.*_display$)(?!.*_icon$)(?!.*_link$)(?!.*_color$)(?!.*_green$)(.*(?:^|_))(price|fee|cost|rate|phone|whatsapp|fax|website|email|licen[cs]e|registration)(?:_|$)/i';

    /** EV-1000: set by generateContent() when the site-copy call failed even after the Runtime's retries. */
    private ?string $copyUnavailable = null;

    private function generateContent(array $data, string $industry): array
    {
        $this->copyUnavailable = null;
        $name = $data['business_name'] ?? 'Our Business';
        $location = $data['location'] ?? '';  // ARTHUR-3 (2026-08-29): never invent a city
        // BUG 2 FIX — services may arrive as an array from extractAllFields();
        // join into a comma-separated list for the LLM copy prompt.
        $servicesRaw = $data['services'] ?? 'various services';
        $services = is_array($servicesRaw) ? implode(', ', $servicesRaw) : (string)$servicesRaw;
        $emailSlug = strtolower(preg_replace('/[^a-z0-9]/', '', $name));

        // PATCH (FIX 1, 2026-05-09) — Restaurant-flavored hardcoded defaults
        // were leaking through to non-food sites (Bico Plastic Surgery shipped
        // with "From Our Kitchen", "Signature Dishes", "International Cuisine"
        // because the manifest didn't declare those slots so the restaurant
        // seed survived). New defaults are NEUTRAL: industry-agnostic
        // placeholders the LLM is expected to overwrite. Manifest defaults
        // (loaded next) and the LLM call below win over these.
        $industryHuman = ucfirst(str_replace('_', ' ', $industry));
        $defaults = [
            'business_name'      => $name,
            'business_tagline'   => $location !== '' ? "{$industryHuman} · {$location}" : $industryHuman,
            'hero_title'         => $name,
            'hero_subtitle'      => $data['description'] ?? ($location !== '' ? "Trusted {$industryHuman} in {$location}." : "Trusted {$industryHuman}."),
            'hero_cta'           => 'Get Started',
            'hero_cta_secondary' => 'Learn More',
            'hero_eyebrow'       => $location !== '' ? "{$industryHuman} · {$location}" : $industryHuman,
            'about_eyebrow'      => 'About Us',
            'about_title'        => 'About Us',
            'about_text_1'       => "At {$name}, we are committed to delivering exceptional quality to our clients" . ($location !== '' ? " in {$location}" : '') . ".",
            'about_text_2'       => 'With years of experience, we bring expertise and dedication to every engagement.',
            'about_text_3'       => "Our commitment to excellence sets us apart.",
            'about_signature'    => $name,
            'about_image'        => '',
            'about_image_display'=> 'display:none',
            'services_eyebrow'   => 'What We Do',
            'services_title'     => 'Our Services',
            'services_intro'     => "Comprehensive {$industryHuman} services tailored to your needs.",
            'service_1_title'    => '',
            'service_1_text'     => '',
            'service_2_title'    => '',
            'service_2_text'     => '',
            'service_3_title'    => '',
            'service_3_text'     => '',
            'service_4_display'  => 'display:none',
            'service_5_display'  => 'display:none',
            'service_6_display'  => 'display:none',
            'gallery_display'    => 'display:none',
            'gallery_eyebrow'    => 'Our Work',
            'gallery_title'      => 'Gallery',
            'stats_display'      => '',
            'stat_1_value'       => '',
            'stat_1_label'       => '',
            'stat_2_value'       => '',
            'stat_2_label'       => '',
            'stat_3_value'       => '',
            'stat_3_label'       => '',
            'stat_4_value'       => '',
            'stat_4_label'       => '',
            // cuisine_* removed — only food templates declare them in their
            // manifest, where they get the right defaults. Non-food sites
            // never need them.
            'testimonial_1_quote'  => '',
            'testimonial_1_author' => '',
            'testimonial_2_quote'  => '',
            'testimonial_2_author' => '',
            'testimonial_3_quote'  => '',
            'testimonial_3_author' => '',
            'process_eyebrow'    => 'How It Works',
            'process_title'      => 'Our Process',
            'process_1_title'    => 'Discovery',
            'process_1_text'     => 'We learn about your needs and goals.',
            'process_2_title'    => 'Plan',
            'process_2_text'     => 'We design a tailored solution for you.',
            'process_3_title'    => 'Delivery',
            'process_3_text'     => 'We execute with precision and care.',
            'process_4_title'    => 'Follow-Up',
            'process_4_text'     => 'We ensure your complete satisfaction.',
            'contact_eyebrow'    => 'Get In Touch',
            'contact_title'      => 'Contact Us',
            'contact_form_title' => "Let's Start a Conversation",
            'contact_email'      => 'info@' . $emailSlug . '.com',
            'contact_website'    => strtolower(str_replace([' ', "'"], ['', ''], $name)) . '.com',
            'contact_service_area' => $location,
            'contact_availability_text' => 'Currently accepting new clients' . ($location !== '' ? " in {$location}" : '') . '.',
            // Neutral blog title — LLM overwrites with industry-appropriate
            // (e.g. 'Health Tips' for medical, 'Training Tips' for gym).
            'blog_section_title' => 'Our Blog',
            'blog_1_title'       => '',
            'blog_1_excerpt'     => '',
            'blog_1_category'    => '',
            'blog_2_title'       => '',
            'blog_2_excerpt'     => '',
            'blog_2_category'    => '',
            'blog_3_title'       => '',
            'blog_3_excerpt'     => '',
            'blog_3_category'    => '',
            'meta_description'   => $location !== '' ? "{$name} — {$industryHuman} in {$location}." : "{$name} — {$industryHuman}.",
            'footer_text'        => '© ' . date('Y') . ' ' . $name . '. All rights reserved.',
        ];

        // FIX 3 — seed defaults from the industry's manifest, not the
        // hardcoded restaurant dictionary. Manifest defaults are already
        // tailored to each of the 10 industries we ship. The old $defaults
        // dictionary is kept above as a final-last-resort floor for old
        // restaurant field names.
        try {
            $manifestDefaults = [];
            $manifest = $this->templates->getManifest($industry) ?: [];
            foreach (($manifest['variables'] ?? []) as $mk => $mv) {
                $d = is_array($mv) ? ($mv['default'] ?? '') : '';
                if ($d !== '' && $d !== null) $manifestDefaults[$mk] = $d;
            }
            // Manifest defaults WIN over restaurant-themed hardcoded $defaults.
            $defaults = array_merge($defaults, $manifestDefaults);
            // Business-specific overrides (computed per-call) win over all.
            $defaults['business_name'] = $name;
            $defaults['business_tagline'] = ucfirst(str_replace('_',' ', $industry)) . ($location !== '' ? ' · ' . $location : '');
            $defaults['meta_description'] = $location !== '' ? "{$name} — {$industry} in {$location}." : "{$name} — {$industry}.";
            $defaults['contact_email']    = 'info@' . $emailSlug . '.com';
            // ARTHUR-3 (2026-08-29): with no known location, strip the dangling " in ." / " · " artefacts
            // any default copy would otherwise carry. Never invent a city.
            if ($location === '') {
                $defaults = array_map(fn($v) => is_string($v) ? preg_replace(['/ in \.(\s|$)/', '/\s*·\s*$/', '/ in \s*,/'], ['.$1', '', ','], $v) : $v, $defaults);
            }
        } catch (\Throwable $e) { /* non-fatal, keep hardcoded $defaults */ }

        if (!$this->runtime->isConfigured()) {
            return $defaults;
        }

        // PATCH (FIX 2, 2026-05-09) — Industry hints expanded from 10 → 30
        // entries. Keys MUST match actual on-disk template slugs — the
        // resolved industry (post resolveTemplateSlug) is what the lookup
        // uses, NOT abstract names like 'healthcare' or 'fitness'.
        $industryHints = [
            // Food & hospitality
            'restaurant'         => 'Write for an upscale restaurant. Services = dining experiences (tasting menu, private events, catering). CTAs: Reserve, Book a Table. NEVER mention clinics, gyms, SaaS, or law firms.',
            'cafe'               => 'Write for a specialty coffee shop / bakery. Cozy, artisan, community feel. Services = roasted coffee, pastries, brunch, light meals. CTAs: Visit Us, Order Now.',
            'catering'           => 'Write for a premium catering company. Services = event catering categories (weddings, corporate, private dinners). CTAs: Get a Quote, Plan Your Event.',
            'hotel'              => 'Write for a boutique luxury hotel. Services = room types, dining, spa, events. CTAs: Book Now, Check Availability.',
            'resort'             => 'Write for a luxury resort. Services = experiences (suites, spa, dining, activities). CTAs: Book Your Stay, Explore.',
            'short_term_rental'  => 'Write for a short-term vacation rental. Services = property types (studios, apartments, villas). CTAs: Book Now, View Availability.',
            'travel_agency'      => 'Write for a travel agency. Services = trip categories (luxury, family, honeymoons, corporate). CTAs: Plan Your Trip, Get a Quote.',
            // Medical / health
            'dental'             => 'Write for a dental practice. Services = treatments (cleanings, whitening, implants, orthodontics, cosmetic). CTAs: Book Appointment, Call Us. NEVER mention dining, gym, or food.',
            'medical_clinic'     => 'Write for a multi-specialty medical clinic. Services = consultations, diagnostics, primary care, specialist referrals. CTAs: Book Consultation, Call Now. NEVER mention dining or food.',
            'aesthetic_clinic'   => 'Write for an aesthetic / cosmetic / plastic-surgery clinic. Services = procedures (rhinoplasty, liposuction, fillers, botox, laser). Premium, doctor-led, results-focused. CTAs: Book Consultation, Learn More. NEVER mention dining, food, kitchen, cuisine, or menus.',
            // Fitness / beauty
            'gym'                => 'Write for a fitness gym / training studio. Services = training programs (HIIT, strength, conditioning, yoga, pilates, personal training). CTAs: Start Training, Join Now. NEVER mention dining or food.',
            'beauty_salon'       => 'Write for a beauty salon. Services = treatments (hair, facials, manicures, makeup, waxing). CTAs: Book Appointment, See Services. NEVER mention dining or food.',
            'barbershop'         => 'Write for a traditional barbershop. Services = mens grooming (cuts, fades, hot-towel shaves, beard, kids cuts). CTAs: Book a Chair, Walk In.',
            // Pet & childcare
            'pet_services'       => 'Write for a pet care business (vet, grooming, daycare). Services = pet care categories. CTAs: Book Now, Meet the Team.',
            'childcare'          => 'Write for a childcare / nursery / preschool. Services = age groups, programs, activities. CTAs: Enroll Now, Schedule a Visit.',
            // Professional services
            'consulting'         => 'Write for a professional consulting firm. Services = practice areas (strategy, operations, finance, advisory). CTAs: Book a Call, Get Started. NEVER mention dining, food, or fitness.',
            'marketing_agency'   => 'Write for a digital marketing agency. Services = (SEO, paid ads, social media, content, web design). CTAs: Get a Free Audit, Let’s Talk. NEVER mention dining, food, or fitness.',
            'it_services'        => 'Write for an IT services company. Services = (managed IT, cloud, cybersecurity, support, software). CTAs: Get Support, Request a Quote.',
            // Real estate / design / construction
            'real_estate_agency' => 'Write for a real estate agency. Services = (sales, leasing, property management, investment advisory). CTAs: View Listings, Contact Agent.',
            'architecture'       => 'Write for an architecture practice. Services = (residential, commercial, masterplanning, interiors). CTAs: View Portfolio, Get in Touch.',
            'interior_design'    => 'Write for an interior design studio. Services = (residential, commercial, consultation, project management). CTAs: Book Consultation, View Work.',
            'construction'       => 'Write for a construction company. Services = (residential build, commercial fit-out, MEP, turnkey, renovation). CTAs: Get a Quote, View Projects.',
            'home_services'      => 'Write for a home-services company (cleaning / handyman / HVAC / plumbing). Services = service categories. CTAs: Book Service, Get a Quote.',
            'automotive'         => 'Write for an automotive business (dealership / service / detailing). Services = (sales, service, detailing, parts, finance). CTAs: Book Service, View Inventory.',
            // Retail / commerce
            'retail_shop'        => 'Write for a brick-and-mortar retail shop. Services = product categories. CTAs: Shop Now, View Collection.',
            'ecommerce'          => 'Write for an online ecommerce store. Services = product categories. CTAs: Shop Now, Browse Collection.',
            // Education
            'tutoring'           => 'Write for a tutoring / private-education service. Services = subject areas, age groups. CTAs: Book a Session, Enroll Now.',
            'training_center'    => 'Write for a vocational training center. Services = courses, certifications, corporate training. CTAs: Enroll Now, View Courses.',
            'online_courses'     => 'Write for an online-course platform. Services = course categories, formats. CTAs: Enroll Now, Start Learning.',
            // Events
            'event_venue'        => 'Write for an event venue / event production company. Services = event types (weddings, corporate, private celebrations). CTAs: Check Availability, Book a Consultation.',
            // News / media (added 2026-05-09)
            'news_channel'       => 'Write for a premium online news channel. Editorial tone, authoritative, journalistic. Headlines must sound like real news with a verb and a real claim — never marketing copy. Categories: Politics, Business, Technology, Sports, Culture, Opinion. Bylines = professional journalist names with surnames (e.g. "Layla Al-Mansoori", "Karim Hashem"). CTAs: Read More, Subscribe, Watch Live. Reading times in minutes. NEVER mention dining, food, kitchen, gym, salon, or product sales.',
        ];
        // PATCH (copy-identity, 2026-07-24) — $industry is the resolved TEMPLATE
        // (layout) slug. For unlisted businesses it is a GENERIC fallback
        // (tattoo studio → consulting), so telling the copywriter the business
        // IS a {$industry} makes it write consulting copy for a tattoo studio
        // (real symptom: "Inkredible Tattoo Studio is a premier management
        // consultancy"). Anchor the copy on the STATED industry whenever the
        // template is a non-matching fallback; matched industries are unchanged.
        $rawIndustry  = trim((string) ($data['industry'] ?? ''));
        // TEMPLATE-FIT (2026-09-05): an exact template slug (e.g. 'travel_agency', 'it_services') obviously
        // fits its own template — confidentSlugFromText() returned NULL for underscored slugs that match no
        // keyword, which flipped templateFits to false, skipped the curated platform hero (findOrGenerate)
        // and generated a junk hero on EVERY build (wasted image credits, office-desk travel heroes).
        $rawSlug = preg_replace('/[^a-z0-9_]/', '', preg_replace('/[\s-]+/', '_', strtolower($rawIndustry)));
        $templateFits = ($rawIndustry === '')
            || ($rawSlug !== '' && $rawSlug === $industry)
            || ($this->confidentSlugFromText($rawIndustry) === $industry);
        $copyIndustry = $templateFits ? $industry : $rawIndustry;

        $hint = $industryHints[$industry] ?? "Use {$industry}-appropriate content only. Do not generate content from a different industry.";
        if (!$templateFits) {
            // Novel/unlisted business on a borrowed layout — force the copy to
            // the real business identity, not the template's industry.
            $hint = "This business is a {$rawIndustry} — NOT a {$industry}. Write EVERY field authentically for a {$rawIndustry}, using its real services ({$services}). Professional, premium tone. Do NOT describe it as a {$industry}, a consultancy, or an agency, and never invent services from another industry.";
        }

        // PATCH (FIX 4, 2026-05-09) — Add blog_section_title to the prompt
        // (was leaking 'From Our Kitchen' restaurant default before).
        // cuisine_* fields are now ONLY requested for food templates;
        // every other industry never gets cuisine_* generated, so they
        // can't leak.
        $isFoodIndustry = in_array($industry, ['restaurant', 'cafe', 'catering'], true);
        $cuisineBlock = $isFoodIndustry
            ? "cuisine_1_name, cuisine_1_note (a signature dish or category), cuisine_2_name, cuisine_2_note, cuisine_3_name, cuisine_3_note,\n"
            : '';

        try {
            $prompt = "Generate complete website content for '{$name}', a {$copyIndustry} business in {$location}. "
                . "Services: {$services}. "
                . "INDUSTRY RULES: {$hint}\n\n"
                . "Return a JSON object with the word json. ALL fields must have real, {$copyIndustry}-appropriate content — no placeholders:\n\n"
                . "hero_title (short punchy headline, 3-6 words, plain text),\n"
                . "hero_subtitle (one compelling sentence),\n"
                . "hero_cta (action button appropriate for {$copyIndustry}),\n"
                . "hero_eyebrow (short badge text),\n"
                . "business_tagline (short brand tagline for a {$copyIndustry} business; do NOT repeat the business name, it is shown separately),\n"
                . "about_title, about_text_1, about_text_2, about_text_3 (2-3 sentences each, {$copyIndustry}-appropriate),\n"
                . "service_1_title, service_1_text, service_2_title, service_2_text, service_3_title, service_3_text "
                . "(each service MUST be a {$copyIndustry} offering — not a different industry's service),\n"
                . $cuisineBlock
                . "testimonial_1_quote, testimonial_1_author (Full Name — Title, City),\n"
                . "testimonial_2_quote, testimonial_2_author,\n"
                . "testimonial_3_quote, testimonial_3_author (all testimonials must read like real {$copyIndustry} clients),\n"
                . "contact_email, contact_service_area, contact_availability_text,\n"
                . "blog_section_title (a short section title appropriate to {$copyIndustry} — e.g. 'Health Tips' for medical, 'Training Tips' for gym, 'Industry Insights' for consulting, 'Brewer's Notes' for cafe; for an aesthetic clinic try 'Beauty & Confidence'. NEVER use 'From Our Kitchen' unless this is literally a restaurant/cafe/catering business),\n"
                . "blog_1_title, blog_1_excerpt, blog_1_category "
                . "(blog content must be about {$copyIndustry} topics — e.g. for fitness: training methodology, recovery; for restaurant: cooking technique, sourcing; for aesthetic_clinic: pre/post-op care, treatment Q&A; for medical_clinic: preventive health, chronic care; for marketing_agency: SEO/PPC/content strategy),\n"
                . "blog_2_title, blog_2_excerpt, blog_2_category,\n"
                . "blog_3_title, blog_3_excerpt, blog_3_category,\n"
                . "meta_description (under 160 chars for SEO).\n\n"
                . "IMPORTANT: No HTML tags. No markdown. Plain text. Premium quality. "
                // RISK-0128 (2026-09-07, DEC-0041): the geography comes from the brief, never from the prompt. Any place the copy
                // names must be the customer's own; with no location known, no place is named at all.
                . ($location !== '' ? "The business is in {$location}: every city, region or country you mention MUST be {$location} and nothing else. " : "Do not mention any city, region or country anywhere. ")
                . "Do NOT use content appropriate for any industry OTHER than {$copyIndustry}. "
                . ($isFoodIndustry ? '' : "Do NOT mention cuisine, menus, dishes, kitchen, dining, or chefs anywhere — this is NOT a food business.");

            // FAST FIRST DRAFT (2026-09-06): the copy call and the text-coverage chunks are independent — send them in one
            // pooled round-trip. fillTemplateTextCoverage() consumes $this->prefetchedCoverage later.
            $copySystem = "You are a professional website copywriter for a {$copyIndustry} business" . ($location !== '' ? " in {$location}" : '') . ". "
                . "Never generate content from a different industry. Return only valid JSON with the word json.";
            $poolCalls = ['content' => [$copySystem, $prompt, ['task' => 'arthur_copywrite'], 2000, self::BUILD_CALL_EXTRA]];
            $this->prefetchedCoverage = [];
            try {
                $mfVars = is_array($manifest ?? null) ? ($manifest['variables'] ?? []) : [];
                $cands  = $this->coverageCandidates(is_array($mfVars) ? $mfVars : [], []);
                $estab  = \App\Engines\Builder\Support\TemplateArchetypes::looksEstablished($data);
                foreach ($this->coverageChunkCalls($cands, (string) $name, $copyIndustry, $services, (string) $location, $estab) as $ck => $call) $poolCalls[$ck] = $call;
            } catch (\Throwable $e) { Log::warning('[Arthur] coverage prefetch skipped: ' . $e->getMessage()); }
            Log::info('[Arthur] pool start', ['calls' => array_keys($poolCalls), 'bytes' => strlen(json_encode($poolCalls))]);
            $pooled = $this->runtime->chatJsonPool($poolCalls);
            $result = $pooled['content'] ?? ['success' => false, 'error' => 'pool_missing_content'];
            if (!($result['success'] ?? false)) {
                // EV-1000: this used to fall through silently to the template's sample copy.
                $rawErr = is_array($result['raw'] ?? null) ? array_intersect_key($result['raw'], array_flip(['error', 'message', 'stage', 'provider', 'retry_after_ms'])) : null;
                Log::error('[Arthur] site copy call failed after retries', ['error' => $result['error'] ?? null, 'raw' => $rawErr]);
                $this->copyUnavailable = (string) ($result['error'] ?? 'unknown');
            }
            foreach ($pooled as $ck => $pr) {
                if ($ck === 'content' || !($pr['success'] ?? false) || !is_array($pr['parsed'] ?? null)) continue;
                $pm = \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($pr['parsed']);
                foreach ($pm as $k => $v) { if (is_string($v) && trim($v) !== '') $this->prefetchedCoverage[(string) $k] = trim($v); }
            }
            Log::info('[Arthur] pooled generation', ['calls' => count($poolCalls), 'prefetched_fields' => count($this->prefetchedCoverage)]);

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                // BUILDER888 P1-8B (2026-08-10) — this is the provider boundary.
                // Model JSON used to be merged straight into the template
                // variable map, so the first array-valued key it returned
                // reached str_replace() and killed the customer's build.
                // Everything now passes through the canonical contract, which
                // yields a flat scalar map, expands lists into the indexed
                // families the templates actually declare, and refuses shapes
                // it cannot represent rather than guessing at them.
                $contract = \App\Engines\Builder\Support\GenerationVariableContract::fromProvider(
                    $result['parsed'],
                    $this->declaredPlaceholders($industry)
                );

                if ($contract['expanded'] !== [] || $contract['rejected'] !== []) {
                    Log::info('[Builder888] provider output normalised', [
                        'industry' => $industry,
                        'expanded' => $contract['expanded'],
                        'rejected' => $contract['rejected'],
                    ]);
                }

                $merged = array_merge($defaults, $contract['variables']);

                return $this->overlayUserServices($merged, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Content generation failed: ' . $e->getMessage());
        }

        return $this->overlayUserServices($defaults, $data);
    }

    // BUG 2 FIX — when the user supplied an explicit services list
    // (e.g. ["SEO","website design","social media","paid ads"]), overwrite
    // the service_1..6_title slots with those exact services and hide the
    // unused slots. User wins over LLM output and template defaults.
    /**
     * BUILDER888 P1-8B — placeholders a template declares, e.g. service_3_title.
     * Lets the generation contract expand a list only into slots the markup
     * really lays out. Cached per request; empty set means "accept on faith".
     *
     * @return array<string,bool>
     */
    private function declaredPlaceholders(string $industry): array
    {
        static $cache = [];

        $slug = $this->resolveTemplateSlug($industry);
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $path = storage_path("templates/{$slug}/template.html");
        if (! is_file($path)) {
            return $cache[$slug] = [];
        }

        $html = (string) @file_get_contents($path);
        preg_match_all('/\{\{([a-z_0-9]+)\}\}/i', $html, $m);

        $set = [];
        foreach ($m[1] ?? [] as $name) {
            $set[$name] = true;
        }

        return $cache[$slug] = $set;
    }
    /**
     * SERVICE CARDS ARE ATOMIC (2026-09-05).
     *
     * Found on the Boss Mac Pet Shop E2E (site 615): the card titled "Pet Food" carried the grooming write-up,
     * "Toys" carried the pet-food write-up, the icon letters read V / G / B (the template's demo Vet / Grooming /
     * Boarding) and the links said "See vet services". Three sources were zipped by slot number: the customer's
     * services list overwrote the titles (overlayUserServices), the model's descriptions stayed in the model's own
     * order, and icon/link never left the manifest defaults. The customer's 4th service was then hidden by the
     * blanket "hide slots 4-6" rule.
     *
     * This runs LAST, after every text field exists, and re-pairs each visible card so that all four fields
     * describe the same service: the customer's services (in their order) own the titles; each title takes the
     * existing description that actually mentions it (each used once), else a clean fallback; the icon follows the
     * template's own scheme (letter initials or 01/02 numbering) derived from the title; a link still equal to the
     * manifest demo label becomes "<verb> <service>". Slots beyond the customer's list are hidden, slots within it
     * are shown — the customer's list is the truth, not the number 3.
     */
    /**
     * SERVICES LIST (2026-09-05): a string like "Pet food, toys, accessories, and grooming" or "SEO / web design & ads"
     * becomes ['Pet food','toys','accessories','grooming']. Arrays are cleaned the same way (an array of one comma string
     * is split too). Order kept, duplicates dropped, max 6.
     */
    private function normaliseServicesList(mixed $services): array
    {
        $items = [];
        foreach ((array) $services as $s) {
            if (is_array($s)) $s = $s['title'] ?? $s['name'] ?? '';
            if (!is_string($s) || trim($s) === '') continue;
            $parts = preg_split('/\s*(?:,|;|\/|\||&|\band\b|\n)\s*/iu', $s) ?: [];
            foreach ($parts as $p) {
                $p = trim($p, " \t\n\r.-");
                if ($p === '' || mb_strlen($p) > 60) continue;
                $key = mb_strtolower($p);
                if (!isset($items[$key])) $items[$key] = $p;
            }
        }
        return array_slice(array_values($items), 0, 6);
    }

    private function reconcileServiceCards(array $variables, array $data, array $manifest, string $industry): array
    {
        try {
            $declared = $this->declaredPlaceholders($industry);
            $has = fn(string $k) => empty($declared) || isset($declared[$k]);
            if (!$has('service_1_title')) return $variables;
            $mvars = is_array($manifest['variables'] ?? null) ? $manifest['variables'] : $manifest;
            $def = fn(string $k) => (string) ($mvars[$k]['default'] ?? '');

            $slots = 0;
            for ($i = 1; $i <= 6; $i++) { if ($has("service_{$i}_title")) $slots = $i; }
            if ($slots === 0) return $variables;

            $user = [];
            foreach ((array) ($data['services'] ?? []) as $s) {
                if (is_string($s) && trim($s) !== '') $user[] = ucwords(mb_strtolower(trim($s)));
            }
            $user = array_slice(array_values(array_unique($user)), 0, $slots);

            $stop  = ['and','the','for','our','your','with','from','of','in','to','a','an','pet','pets','services','service','professional','premium'];
            $stem  = fn(string $w) => preg_replace('/(ies|ing|es|s)$/', '', $w);
            $tokens = function (string $t) use ($stop, $stem): array {
                $out = [];
                foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($t)) ?: [] as $w) {
                    if (strlen($w) >= 3 && !in_array($w, $stop, true)) $out[] = $stem($w);
                }
                return array_values(array_unique(array_filter($out)));
            };
            $name = trim((string) ($data['business_name'] ?? '')) ?: 'our team';
            $loc  = trim((string) ($data['location'] ?? ''));

            if (!empty($user)) {
                $original = $variables;
                // Pool of usable descriptions, whichever slot they sit in today.
                $pool = [];
                for ($i = 1; $i <= 6; $i++) {
                    $t = trim((string) ($variables["service_{$i}_text"] ?? ''));
                    if ($t === '' || str_contains($t, 'tailored to your business goals')) continue;
                    if ($this->isCrossIndustryLeak($t, $industry)) continue;
                    $pool[$i] = $t;
                }
                // Pass 1: every title claims the description that mentions it best (each description once).
                $texts = array_fill(0, count($user), null);
                foreach ($user as $idx => $title) {
                    $tk = $tokens($title); $best = null; $bestScore = 0;
                    foreach ($pool as $pi => $t) {
                        $lt = mb_strtolower($t); $score = 0;
                        foreach ($tk as $w) { if (str_contains($lt, $w)) $score++; }
                        if ($score > $bestScore) { $bestScore = $score; $best = $pi; }
                    }
                    if ($best !== null) { $texts[$idx] = $pool[$best]; unset($pool[$best]); }
                }
                // Pass 2: a title nothing mentions gets a clean, honest line — never another service's write-up.
                foreach ($user as $idx => $title) {
                    if ($texts[$idx] !== null) continue;
                    $texts[$idx] = "{$title} from {$name}" . ($loc !== '' ? " in {$loc}" : '')
                        . " — chosen and delivered with care, so you get exactly what you came for.";
                }
                // Model cards the customer did not name, kept as intact title+text pairs, for slots the template
                // cannot hide (most templates always lay out cards 1-3): a real suggested service beats a stale card.
                $leftover = [];
                for ($i = 1; $i <= 6; $i++) {
                    $t = trim((string) ($original["service_{$i}_title"] ?? ''));
                    $x = trim((string) ($original["service_{$i}_text"] ?? ''));
                    if ($t === '' || $x === '' || in_array($t, $user, true) || in_array($x, $texts, true)) continue;
                    if ($this->isCrossIndustryLeak($x, $industry)) continue;
                    $leftover[] = [$t, $x];
                }
                for ($i = 1; $i <= 6; $i++) {
                    $idx = $i - 1;
                    if (isset($user[$idx])) {
                        $variables["service_{$i}_title"]   = $user[$idx];
                        $variables["service_{$i}_text"]    = $texts[$idx];
                        $variables["service_{$i}_display"] = '';
                    } elseif ($has("service_{$i}_display")) {
                        $variables["service_{$i}_display"] = 'display:none';
                    } elseif ($lo = array_shift($leftover)) {
                        [$variables["service_{$i}_title"], $variables["service_{$i}_text"]] = $lo;
                        $variables["service_{$i}_icon"] = $def("service_{$i}_icon"); // re-derived below from the new title
                        $variables["service_{$i}_link"] = $def("service_{$i}_link");
                    }
                }
            }

            // Icon and link belong to the title of THEIR card. Template demo initials / labels never ship.
            $d1 = $def('service_1_icon');
            for ($i = 1; $i <= 6; $i++) {
                $title = trim((string) ($variables["service_{$i}_title"] ?? ''));
                if ($title === '') continue;
                if ($has("service_{$i}_icon")) {
                    $cur = trim((string) ($variables["service_{$i}_icon"] ?? ''));
                    if ($cur === '' || $cur === $def("service_{$i}_icon")) {
                        if (preg_match('/^\d{1,2}$/', $d1)) {
                            $variables["service_{$i}_icon"] = str_pad((string) $i, strlen($d1), '0', STR_PAD_LEFT);
                        } elseif (preg_match('/^[A-Z]{1,3}$/', $d1)) {
                            // Single-letter scheme: the initial of the DISTINCTIVE word ("Pet Food" → F, "Pet Grooming" → G), so
                            // three cards that all start with "Pet" do not all read P. Multi-letter scheme: initials of every word.
                            $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^A-Za-z0-9 ]/', '', $title)) ?: []));
                            $distinct = array_values(array_filter($words, fn($w) => !in_array(mb_strtolower($w), $stop, true)));
                            if (strlen($d1) === 1) {
                                $pick = $distinct[0] ?? $words[0] ?? $title;
                                $variables["service_{$i}_icon"] = strtoupper(substr($pick, 0, 1));
                            } else {
                                $ini = '';
                                foreach ($words as $w) { $ini .= strtoupper($w[0]); }
                                $variables["service_{$i}_icon"] = substr($ini, 0, 3) ?: strtoupper(substr($title, 0, 1));
                            }
                        }
                    }
                }
                if ($has("service_{$i}_link")) {
                    $cur = trim((string) ($variables["service_{$i}_link"] ?? ''));
                    $d   = $def("service_{$i}_link");
                    // A verb-style label ("See grooming") is a generated label, not copy: always re-derive it from THIS card's
                    // title, so a label can never name another card's service. Custom copy ("Browse our food range") stays.
                    $isLabel = ($cur === '' || $cur === $d || preg_match('/^(See|Explore|View|Discover|Visit)\b/i', $cur));
                    if ($isLabel) {
                        $verb = 'Explore';
                        if ($d !== '' && preg_match('/^(See|Explore|View|Discover|Visit|Book)\b/i', $d, $vm)) $verb = ucfirst(strtolower($vm[1]));
                        $variables["service_{$i}_link"] = $verb . ' ' . mb_strtolower($title);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] reconcileServiceCards failed: ' . $e->getMessage());
        }
        return $variables;
    }
    private function overlayUserServices(array $vars, array $data): array
    {
        $svc = $data['services'] ?? null;
        if (!is_array($svc) || empty($svc)) return $vars;

        $svc = array_values(array_filter(array_map(
            fn($s) => is_string($s) ? trim($s) : '',
            $svc
        ), fn($s) => $s !== ''));
        if (empty($svc)) return $vars;

        for ($i = 0; $i < 6; $i++) {
            $slot = $i + 1;
            if (isset($svc[$i])) {
                $title = ucwords($svc[$i]);
                $vars["service_{$slot}_title"] = $title;
                // Only fabricate a description when the template/LLM didn't
                // provide one — never clobber a real write-up.
                if (empty($vars["service_{$slot}_text"])
                    || $this->isCrossIndustryLeak((string)$vars["service_{$slot}_text"], $this->resolveTemplateSlug((string)($data['industry'] ?? '')))) {
                    $vars["service_{$slot}_text"] = "Professional {$title} tailored to your business goals.";
                }
                $vars["service_{$slot}_display"] = '';
            } else {
                $vars["service_{$slot}_display"] = 'display:none';
            }
        }
        return $vars;
    }

    private function getHeroImagePrompt(string $industry, string $location): string
    {
        // PATCH (FIX 3, 2026-05-09) — Hero prompts expanded from 8 → 30
        // entries. Keys MUST match on-disk template slugs (the resolved
        // industry post resolveTemplateSlug). Generic fallback retained
        // for any future template that lands here without a specific
        // prompt, so the call never fails.
        $prompts = [
            // Food & hospitality
            'restaurant'         => 'Elegant upscale restaurant interior, warm ambient candlelight, dark wood tables set for dinner, intimate fine dining atmosphere, soft bokeh background, professional food photography style, wide cinematic shot, warm golden tones',
            'cafe'               => 'Cozy modern coffee shop interior, plant-filled, latte art on bar counter, artisan bakery items on wood counter, warm morning light streaming through windows',
            'catering'           => 'Elegant catering spread on long banquet table, beautifully arranged platters, professional event setup with stemware and floral centerpieces',
            'hotel'              => 'Luxurious boutique hotel lobby, marble floors, crystal chandelier, premium hospitality, golden hour daylight',
            'resort'             => 'Tropical luxury resort, infinity pool overlooking calm sea, palm trees, dramatic golden-hour landscape',
            'short_term_rental'  => 'Modern stylish apartment interior, floor-to-ceiling windows with city view, premium furnishings, bright daylight',
            'travel_agency'      => 'Stunning travel destination, exotic landscape, mountains or coastline at golden hour, sense of adventure and exploration',
            // Medical / health
            'dental'             => 'Modern dental clinic, clean bright white interior, professional dental chair, welcoming reception, calming atmosphere',
            'medical_clinic'     => 'Professional modern medical clinic reception, clean bright interior, soft daylight, trusted healthcare environment',
            'aesthetic_clinic'   => 'Luxury aesthetic / plastic-surgery clinic, pristine white and warm-marble interior, premium beauty treatment room, sophisticated calm ambiance',
            // Fitness / beauty
            'gym'                => 'Modern premium gym interior, professional equipment racks, dramatic lighting, wide angle, motivational atmosphere',
            'beauty_salon'       => 'Elegant beauty salon interior, styling chairs, vanity mirrors with warm bulb lighting, sophisticated decor, spa atmosphere',
            'barbershop'         => 'Classic modern barbershop, leather chairs, vintage barber tools, warm Edison-bulb lighting, masculine sophisticated interior',
            // Pet & childcare
            'pet_services'       => 'Happy pet being groomed by a calm professional, warm welcoming environment, natural daylight, modern clean facility',
            'childcare'          => 'Bright colorful childcare center play area, age-appropriate toys, soft natural light, safe nurturing environment, no children visible',
            // Professional services
            'consulting'         => 'Professional corporate boardroom, glass walls, city view, leather chairs around walnut conference table, confident sophisticated atmosphere',
            'marketing_agency'   => 'Modern creative agency office, team collaborating around large screens with analytics dashboards, dynamic professional environment, daylight',
            'it_services'        => 'Modern technology workspace, server racks softly lit, multiple monitors with code, professional IT environment, blue accent lighting',
            // Real estate / design / construction
            'real_estate_agency' => 'Stunning luxury property exterior, modern architecture, floor-to-ceiling windows, manicured landscaping, golden hour',
            'architecture'       => 'Award-winning modern architecture, striking building geometry, blue-hour exterior, dramatic detail of facade',
            'interior_design'    => 'Stunning luxury interior design, curated living space, premium materials and finishes, warm natural light',
            'construction'       => 'Modern construction site at golden hour, partially completed structure, clean professional, skilled workers in branded uniforms (no faces)',
            'home_services'      => 'Professional home-service technician working in a clean modern home, quality workmanship in progress, natural daylight',
            'automotive'         => 'Premium automotive showroom or service bay, luxury cars under dramatic lighting, polished concrete floor, professional environment',
            // Retail / commerce
            'retail_shop'        => 'Elegant modern retail store, beautiful product displays on warm wood shelves, premium shopping experience, soft daylight',
            'ecommerce'          => 'Premium ecommerce product flat-lay or photo studio scene, clean white background, beautifully styled product, soft studio lighting',
            // Education
            'tutoring'           => 'Engaged student studying at a modern desk with tutor pointing at a textbook, warm natural light, focused educational atmosphere',
            'training_center'    => 'Modern training facility classroom, projector screen with content, students at desks (no clear faces), professional atmosphere',
            'online_courses'     => 'Modern online-learning setup, laptop with course content on screen, headphones, notebook, warm desk light, focused environment',
            // Events
            'event_venue'        => 'Stunning event venue ballroom, dramatic uplighting, elegant tablescapes with floral centerpieces, premium celebration space, golden warm tones',
            // News / media (added 2026-05-09)
            'news_channel'       => 'Premium editorial newsroom, modern journalism workspace, multiple monitors with breaking news headlines, professional broadcast studio with anchor desk, dramatic blue and red key lighting, high-end TV-news production aesthetic',
        ];

        $base = $prompts[$industry] ?? "Professional modern {$industry} business interior, clean design, warm lighting, premium atmosphere";
        return $base . ", photorealistic, 16:9 aspect ratio, no text, no signs, no logos, no words, no lettering, no watermarks";
    }

    // PATCH (hero-context, 2026-07-24) — Hero prompt anchored on the ACTUAL
    // business (raw industry + services), used when the template is a borrowed
    // fallback so the hero depicts the real business, not the template's slug.
    private function getBusinessHeroPrompt(string $rawIndustry, string $services, string $location): string
    {
        $ri  = trim($rawIndustry) !== '' ? trim($rawIndustry) : 'business';
        $svc = trim($services) !== '' ? " that offers {$services}" : '';
        return "Photorealistic hero background for a {$ri}{$svc} in {$location}. "
            . "Depict an authentic, on-brand real-world environment for a {$ri} — premium atmosphere, "
            . "natural lighting, wide cinematic 16:9 composition, no people's faces. "
            . "photorealistic, no text, no signs, no logos, no words, no lettering, no watermarks";
    }

    // PATCH (hero-context, 2026-07-24) — Find an EXISTING media asset applicable
    // to the actual business (by industry tag / tags matching the raw industry +
    // service tokens), preferring the workspace's own uploads. Returns null when
    // nothing applicable exists so the caller generates a fresh business hero.
    private function findApplicableHero(string $rawIndustry, string $services, int $wsId): ?array
    {
        // HERO-SAFE (2026-09-05). The old version matched ANY image (asset_type 'image') by
        // `category LIKE token` / `tags LIKE token`, workspace-first — so a pet shop whose services
        // included "pet food" matched the SAME workspace's Chef Red dish photos (category 'food'),
        // and shipped a broken restaurant image as its hero. Rules now:
        //   1. Resolve the raw industry to the best-fit industry slug (keyword/pattern), then take
        //      that industry's CURATED PLATFORM hero (the media library). Industry-matched, always exists.
        //   2. Otherwise only a genuine category='hero' asset whose tags match, platform first,
        //      and only if its file actually exists on disk.
        //   3. Otherwise null — the caller keeps the resolved template's platform hero (already set).
        $raw = trim($rawIndustry);
        try {
            $best = $this->confidentSlugFromText($raw);
            if (!$best) {
                // fall through the coarse sector patterns used by resolveTemplateSlugInner
                $inner = $this->resolveTemplateSlugInner($raw);
                if ($inner !== '' && $this->templates->getManifest($inner)) $best = $inner;
            }
            if ($best) {
                $hit = \App\Services\MediaService::findOrGenerate($best, 'hero', null, null); // platform-only
                if ($hit && !empty($hit['url'])) {
                    $p = $hit['path'] ?? '';
                    if ($p === '' || is_file(storage_path('app/public/' . ltrim($p, '/')))) {
                        Log::info('[Arthur] borrowed-template hero from platform library', ['raw' => $raw, 'industry' => $best, 'media_id' => $hit['id'] ?? null]);
                        return ['id' => $hit['id'] ?? null, 'url' => $hit['url']];
                    }
                }
            }
            // Strict fallback: real hero assets only, tag match on meaningful tokens (>=3 chars), platform first.
            $tokens = array_values(array_filter(array_unique(preg_split('/[^a-z0-9_]+/', mb_strtolower($raw . ' ' . $services))), fn($t) => strlen($t) >= 3 && !in_array($t, ['and','the','for','with','our','your','services','service','shop','store'], true)));
            if (empty($tokens)) return null;
            $tokens = array_slice($tokens, 0, 8);
            $row = DB::table('media')
                ->where('category', 'hero')
                ->whereNotNull('url')->where('url', '!=', '')
                ->where(function ($w) use ($tokens) { foreach ($tokens as $t) { $w->orWhere('tags', 'like', '%"' . $t . '"%'); } })
                ->where(function ($w) use ($wsId) { $w->where('is_platform_asset', 1)->orWhere('workspace_id', $wsId); })
                ->orderByDesc('is_platform_asset')->orderByDesc('created_at')
                ->first(['id', 'url', 'path']);
            if ($row && !empty($row->url)) {
                $p = (string) ($row->path ?? '');
                if ($p === '' || is_file(storage_path('app/public/' . ltrim($p, '/')))) {
                    Log::info('[Arthur] applicable existing hero found for borrowed template', ['raw' => $raw, 'media_id' => $row->id]);
                    return ['id' => $row->id, 'url' => $row->url];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] findApplicableHero failed: ' . $e->getMessage());
        }
        return null;
    }
    // PATCH (credibility-injection, 2026-07-24 · P3) — When a business is
    // established, map its REAL data (build_data.stats / clients / case_studies)
    // into the template's credibility variables so the kept blocks show the
    // business's own numbers, not the template sample. Returns the set of keys it
    // populated (assoc, key => true) so the caller can blank everything it didn't.
    private function injectCredibilityData(array &$variables, array $data, array $manifestVars): array
    {
        $set = [];
        $put = function (string $key, $val) use (&$variables, &$set, $manifestVars) {
            if (array_key_exists($key, $manifestVars) && is_string($val) && trim($val) !== '') {
                $variables[$key] = $val;
                $set[$key] = true;
            }
        };

        // STATS — [{value,label}] (or {label:value}); fills stat_N / hero_stat_N / strip_stat_N.
        $stats = $this->normalizeKV($data['stats'] ?? [], 'value', 'label');
        foreach ($stats as $i => $s) {
            $n = $i + 1;
            foreach (['stat', 'hero_stat', 'strip_stat', 'stats_strip'] as $pfx) {
                $put("{$pfx}_{$n}_value", (string) ($s['value'] ?? ''));
                $put("{$pfx}_{$n}_label", (string) ($s['label'] ?? ''));
            }
        }

        // CLIENTS — [names]; fills client_logo_N (+ clients_title if provided).
        $clients = array_values(array_filter(array_map('strval', (array) ($data['clients'] ?? $data['client_logos'] ?? [])), fn($v) => trim($v) !== ''));
        foreach ($clients as $i => $name) {
            $put('client_logo_' . ($i + 1), $name);
            $put('client_logo' . ($i + 1), $name); // some templates omit the underscore
        }
        if (!empty($data['clients_title'])) $put('clients_title', (string) $data['clients_title']);

        // CASE STUDIES — [{client,title,metrics:[{value,label}]}].
        foreach ((array) ($data['case_studies'] ?? []) as $idx => $case) {
            if (!is_array($case)) continue;
            $n = $idx + 1;
            $put("case_{$n}_client", (string) ($case['client'] ?? ''));
            $put("case_{$n}_title", (string) ($case['title'] ?? ''));
            foreach ($this->normalizeKV($case['metrics'] ?? [], 'value', 'label') as $mi => $m) {
                $mn = $mi + 1;
                $put("case_{$n}_metric_{$mn}_value", (string) ($m['value'] ?? ''));
                $put("case_{$n}_metric_{$mn}_label", (string) ($m['label'] ?? ''));
            }
        }
        return $set;
    }

    /** Normalise [{value,label}] OR ['label'=>'value'] OR ['label'=>N] into [{value,label}]. */
    private function normalizeKV($raw, string $vk, string $lk): array
    {
        if (!is_array($raw) || empty($raw)) return [];
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                $out[] = [$vk => (string) ($v[$vk] ?? $v['value'] ?? $v[0] ?? ''), $lk => (string) ($v[$lk] ?? $v['label'] ?? $v[1] ?? '')];
            } elseif (is_string($k)) {
                $out[] = [$vk => (string) $v, $lk => (string) $k]; // label=>value map
            }
        }
        return $out;
    }

    // PATCH (bespoke-blocks, 2026-07-24 · P2b-full) — Build a real industry
    // section (menu with prices / product catalog / destinations) to REPLACE the
    // generic 3-card "services" block on templates that lack one. Returns section
    // HTML (reusing the template's own classes so it themes automatically) or ''
    // to keep the original block. Slug-gated to the clones that need it.

    /**
     * CUR-1 (2026-08-31): the price hints are authored with AED examples. Rewrite them for the business's own
     * market so a location of "Manila, Philippines" is priced in pesos, not Dirhams.
     *
     * An unrecognised location is NOT left as AED — the model is told to use the local currency of that place,
     * which is the honest instruction when we cannot name it.
     */
    private function localisePriceHints(array $cfg, string $location): array
    {
        $cur = $this->currencyForLocation($location);
        foreach (['price', 'meta'] as $k) {
            if (empty($cfg[$k]) || !is_string($cfg[$k])) continue;
            $hint = $cfg[$k];
            if (stripos($hint, 'AED') === false) continue;
            if ($cur === null) {
                $where = trim($location) !== '' ? trim($location) : 'the business location';
                $cfg[$k] = str_ireplace('AED ', '', $hint)
                    . " — priced in the local currency of {$where}, written the way locals write it";
            } else {
                $cfg[$k] = str_ireplace('AED', $cur['code'], $hint)
                    . " — use realistic {$cur['name']} amounts for this market, not a converted figure";
            }
        }
        return $cfg;
    }

    /** @return array{code:string,name:string}|null null when the market is not recognised. */
    private function currencyForLocation(string $location): ?array
    {
        $l = mb_strtolower($location);
        if ($l === '') return null;
        $map = [
            'philippin' => ['PHP', 'Philippine peso'], 'manila' => ['PHP', 'Philippine peso'],
            'cebu' => ['PHP', 'Philippine peso'], 'davao' => ['PHP', 'Philippine peso'],
            'uae' => ['AED', 'UAE dirham'], 'dubai' => ['AED', 'UAE dirham'], 'abu dhabi' => ['AED', 'UAE dirham'],
            'emirates' => ['AED', 'UAE dirham'], 'sharjah' => ['AED', 'UAE dirham'],
            'saudi' => ['SAR', 'Saudi riyal'], 'riyadh' => ['SAR', 'Saudi riyal'], 'jeddah' => ['SAR', 'Saudi riyal'],
            'qatar' => ['QAR', 'Qatari riyal'], 'doha' => ['QAR', 'Qatari riyal'],
            'kuwait' => ['KWD', 'Kuwaiti dinar'], 'bahrain' => ['BHD', 'Bahraini dinar'], 'oman' => ['OMR', 'Omani rial'],
            'united kingdom' => ['GBP', 'pound sterling'], 'england' => ['GBP', 'pound sterling'],
            'london' => ['GBP', 'pound sterling'], 'scotland' => ['GBP', 'pound sterling'], 'britain' => ['GBP', 'pound sterling'],
            'united states' => ['USD', 'US dollar'], 'usa' => ['USD', 'US dollar'], 'new york' => ['USD', 'US dollar'],
            'california' => ['USD', 'US dollar'], 'texas' => ['USD', 'US dollar'], 'florida' => ['USD', 'US dollar'],
            'canada' => ['CAD', 'Canadian dollar'], 'toronto' => ['CAD', 'Canadian dollar'],
            'australia' => ['AUD', 'Australian dollar'], 'sydney' => ['AUD', 'Australian dollar'],
            'melbourne' => ['AUD', 'Australian dollar'], 'new zealand' => ['NZD', 'New Zealand dollar'],
            'singapore' => ['SGD', 'Singapore dollar'], 'malaysia' => ['MYR', 'Malaysian ringgit'],
            'kuala lumpur' => ['MYR', 'Malaysian ringgit'], 'indonesia' => ['IDR', 'Indonesian rupiah'],
            'jakarta' => ['IDR', 'Indonesian rupiah'], 'thailand' => ['THB', 'Thai baht'], 'bangkok' => ['THB', 'Thai baht'],
            'vietnam' => ['VND', 'Vietnamese dong'], 'india' => ['INR', 'Indian rupee'], 'mumbai' => ['INR', 'Indian rupee'],
            'delhi' => ['INR', 'Indian rupee'], 'bangalore' => ['INR', 'Indian rupee'],
            'japan' => ['JPY', 'Japanese yen'], 'tokyo' => ['JPY', 'Japanese yen'],
            'south africa' => ['ZAR', 'South African rand'], 'nigeria' => ['NGN', 'Nigerian naira'],
            'kenya' => ['KES', 'Kenyan shilling'], 'ireland' => ['EUR', 'euro'], 'germany' => ['EUR', 'euro'],
            'france' => ['EUR', 'euro'], 'spain' => ['EUR', 'euro'], 'italy' => ['EUR', 'euro'],
            'netherlands' => ['EUR', 'euro'], 'portugal' => ['EUR', 'euro'],
        ];
        foreach ($map as $needle => $cur) {
            if (str_contains($l, $needle)) return ['code' => $cur[0], 'name' => $cur[1]];
        }
        return null;
    }

    private function bespokeSectionFor(string $slug, array $data, int $wsId = 0): string
    {
        // 'images' mode: false = text cards; 'pool' = generic industry photos are
        // fine (travel destinations); 'uploads' = photo cards ONLY if the OWNER
        // uploaded real product photos (retail/ecommerce — the generic platform
        // gallery is store scenes that mismatch product names, so text unless the
        // owner brought their own shots).
        $cfg = [
            'restaurant'    => ['kind' => 'menu',    'images' => false,     'count' => 8, 'noun' => 'signature menu dishes',              'price' => "a realistic dish price like 'AED 45'",        'labels' => ['eyebrow' => 'What We Serve',     'title' => 'Our Menu']],
            'catering'      => ['kind' => 'menu',    'images' => false,     'count' => 6, 'noun' => 'catering menu packages',             'price' => "a per-head/package price like 'AED 120 / head'", 'labels' => ['eyebrow' => 'Catering Menus',    'title' => 'Menus & Packages']],
            'retail_shop'   => ['kind' => 'catalog', 'images' => 'uploads', 'count' => 6, 'noun' => 'featured products or collections',   'price' => "a price like 'AED 120' or 'From AED 90'",     'labels' => ['eyebrow' => 'Our Collection',    'title' => 'Shop by Category']],
            'ecommerce'     => ['kind' => 'catalog', 'images' => 'uploads', 'count' => 6, 'noun' => 'featured products',                  'price' => "a price like 'AED 120'",                      'labels' => ['eyebrow' => 'Our Collection',    'title' => 'Shop the Collection']],
            'travel_agency' => ['kind' => 'catalog', 'images' => 'pool',    'count' => 6, 'noun' => 'destination trips or packages',      'price' => "a from-price like 'From AED 2,400'",          'labels' => ['eyebrow' => 'Where We Take You', 'title' => 'Destinations & Packages']],
            'resort'            => ['kind' => 'units', 'images' => 'pool', 'count' => 6, 'noun' => 'room / suite / villa types', 'price' => "a per-night rate like 'From AED 1,200 / night'", 'meta' => "a short capacity line like 'Sleeps 4 · 65m² · Sea view'", 'labels' => ['eyebrow' => 'Your Stay', 'title' => 'Rooms & Suites']],
            'short_term_rental' => ['kind' => 'units', 'images' => 'pool', 'count' => 6, 'noun' => 'rental unit / apartment types', 'price' => "a per-night rate like 'From AED 600 / night'",  'meta' => "a short capacity line like 'Sleeps 3 · 1 bed · City view'", 'labels' => ['eyebrow' => 'Your Stay', 'title' => 'Our Spaces']],
        ][$slug] ?? null;
        if (!$cfg) return '';
        // CUR-1: those examples are written in AED. Re-point them at the currency of THIS business's location, or
        // the model prices a Philippine bakery in Dirhams because that is the example it was shown.
        $cfg = $this->localisePriceHints($cfg, (string) ($data['location'] ?? ''));
        $items = $this->generateBespokeItems($data, $cfg);
        if (empty($items)) return '';
        $labels = $cfg['labels'] + ['intro' => ''];
        if ($cfg['kind'] === 'menu') {
            return \App\Engines\Builder\Support\SectionLibrary::menuSection($items, $labels);
        }
        // catalog / units — enrich with photos per the mode; otherwise text cards.
        $images = [];
        $mode = $cfg['images'] ?? false;
        if ($mode === 'pool' && $wsId > 0) {
            try { $images = $this->buildImagePool($slug, $wsId); } catch (\Throwable $e) {}
        } elseif ($mode === 'uploads') {
            // ONLY the owner's own uploaded product photos — never the generic pool.
            $up = array_values(array_filter(array_map('strval', (array) ($data['uploaded_images'] ?? [])), fn($u) => trim($u) !== ''));
            if (count($up) >= 3) $images = $up; // enough to fill a grid; else stay text
        }
        if ($cfg['kind'] === 'units') {
            return \App\Engines\Builder\Support\SectionLibrary::unitsSection($items, $labels, $images);
        }
        return \App\Engines\Builder\Support\SectionLibrary::catalogSection($items, $labels, $images);
    }

    private function generateBespokeItems(array $data, array $cfg): array
    {
        if (!$this->runtime->isConfigured()) return [];
        $name     = (string) ($data['business_name'] ?? 'the business');
        $industry = (string) ($data['industry'] ?? 'business');
        $location = (string) ($data['location'] ?? '');  // ARTHUR-3 (2026-08-29): never invent a city
        $services = is_array($data['services'] ?? null) ? implode(', ', $data['services']) : (string) ($data['services'] ?? '');
        $sys = "You write website content for '{$name}', a {$industry} in {$location}. Every item must be specific "
             . "and realistic for a {$industry} — never placeholders. Return ONLY valid JSON (include the word json).";
        $metaKey = !empty($cfg['meta']) ? ',"meta":"..."' : '';
        $metaRule = !empty($cfg['meta']) ? " and meta is {$cfg['meta']}" : '';
        $prompt = "Generate {$cfg['count']} {$cfg['noun']} for this business. Services: {$services}. "
             . 'Return {"items":[{"name":"...","price":"..."' . $metaKey . ',"desc":"short 6-12 word description"}]} '
             . "where price is {$cfg['price']}{$metaRule}.";
        try {
            $r = $this->runtime->chatJson($sys, $prompt, ['task' => 'arthur_bespoke_items'], 1300);
            $items = $r['parsed']['items'] ?? ($r['parsed'] ?? null);
            if (is_array($items)) {
                return array_values(array_filter($items, fn($i) => is_array($i) && !empty($i['name'])));
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] bespoke items failed: ' . $e->getMessage());
        }
        return [];
    }

    // PATCH (no-static-text, 2026-07-24) — Several template.html files hardcode
    // SAMPLE staff names — in booking <option>s AND team cards ("Dr. James
    // Whitfield", "Lila Hadid", "Marcus Idowu", …); even non-medical templates
    // inherited the doctor block, so a catering site could show a "Dr. James
    // Whitfield" option. These are HTML literals (not variables), so replace
    // every occurrence post-render with a realistic, locale-neutral placeholder
    // name (a fresh site has no real team yet — the user edits these in the
    // builder). Replacing the surname-bearing form also fixes "Dr. " instances.
    private function scrubSampleStaff(string $html): string
    {
        $map = [
            'Aisha Rahman'    => 'Layla Hassan',
            'Omar Al-Sayed'   => 'Karim Nasser',
            'Sarah Nakamura'  => 'Mariam Saleh',
            'James Whitfield' => 'Adam Farouk',
            'Lila Hadid'      => 'Yasmin Aziz',
            'Priya Desai'     => 'Hana Malik',
            'Sofia Moreau'    => 'Reem Khalil',
            'Nadia Chen'      => 'Sara Mansour',
            'Layla Nader'     => 'Dana Rashed',
            'Marcus Chen'     => 'Tariq Aziz',
            'Marcus Idowu'    => 'Zaid Haddad',
            'Nadia Petrov'    => 'Lina Fares',
            'Jordan Walker'   => 'Nour Sami',
            'Rania Al Sabah'  => 'Salma Darwish',
            'Priya Menon'     => 'Amira Fadel',
        ];
        $html = str_ireplace(array_keys($map), array_values($map), $html);
        // Backstop — remove any remaining "<option>Dr. Firstname Lastname</option>".
        $html = preg_replace('~<option\b[^>]*>\s*Dr\.?\s+[A-Z][a-z]+\s+[A-Z][a-z]+\s*</option>~', '', $html);

        // Hide the fabricated-credibility sections (their VALUES are blanked in
        // generateWebsite). Classes are credibility-specific — deliberately NOT
        // generic layout classes (.reveal/.section/.wrap). A fresh business has
        // no stats/clients/case-studies; the user re-enables and fills them in
        // the builder.
        $hideCss = '<style id="lu-hide-fabricated">'
            . '.stats,.stats-grid,.stats-strip,.stats-strip-item,.stats-strip-label,'
            . '.stat-item,.stat-label,.stat-value,.hero-stats,.hero-stat,.hero-stat-label,.hero-stat-value,'
            . '.clients,.clients-grid,.clients-title,.client-logo,.client-logos,'
            . '.case-metrics,.case-metric,.case-metric-value,.case-metric-label,'
            . '.results,.result-item,.result-label,.result-value'
            . '{display:none !important}</style>';
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('~</head>~i', $hideCss . '</head>', $html, 1);
        } else {
            $html .= $hideCss;
        }
        return is_string($html) ? $html : '';
    }

    // PATCH (text-coverage, 2026-07-24) — Regenerate EVERY remaining template
    // text field from business context so no manifest SAMPLE text (sample names,
    // wrong-industry taglines/prose) can render. Skips colors/images/urls and
    // short structural labels (nav/menu/buttons), targets only unfilled content
    // prose. A deterministic net neutralizes anything the LLM still misses.
    /** Coverage answers fetched in the same round-trip as the site copy (FAST FIRST DRAFT). key => text */
    private array $prefetchedCoverage = [];

    /** The text fields whose value is still the manifest sample (the coverage pass's work list). */
    private function coverageCandidates(array $vars, array $variables): array
    {
        $skipKey = '/(image|img|photo|logo|url|color|colour|display|icon|bg|background|style|css|href|src|width|height|dim|ratio|font|hex|locale|canonical|slug|og_image|_id$)/i';
        $structKey = '/(nav|menu|link|button|_cta$|^cta|tab|breadcrumb|^logo|_label$)/i';
        $toFill = [];
        foreach ($vars as $k => $spec) {
            if (!is_array($spec)) continue;
            $type = strtolower((string) ($spec['type'] ?? ''));
            if (in_array($type, ['color', 'image', 'url', 'file', 'media', 'number', 'bool', 'boolean'], true)) continue;
            $key = (string) $k;
            if (preg_match($skipKey, $key) || preg_match($structKey, $key)) continue;
            $def = $spec['default'] ?? null;
            if (!is_string($def)) continue;
            $def = trim($def);
            if ((strlen($def) < 15 || strpos($def, ' ') === false) && !preg_match(self::CATALOGUE_KEY, $key)) continue;
            if (preg_match('~^(/|https?:|\#|display:)~i', $def)) continue;
            $cur = $variables[$key] ?? null;
            if (is_string($cur) && $cur !== '' && $cur !== $def) continue;
            $toFill[$key] = (string) ($spec['label'] ?? $spec['description'] ?? $key);
        }
        return $toFill;
    }

    /** The coverage prompts, chunked, minus personnel fields (never invent people). key => [system, prompt, ctx, maxTokens] */
    private function coverageChunkCalls(array $toFill, string $name, string $copyIndustry, string $services, string $location, bool $established): array
    {
        $personnelKey = '/^(doctor|dentist|physician|surgeon|therapist|trainer|instructor|coach|staff|team|member|attorney|lawyer|agent|broker|realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist)_\\d+_(name|title|specialty|speciality|bio|role|credential|qualification|position)/i';
        $llmToFill = array_filter($toFill, fn ($k) => ! preg_match($personnelKey, (string) $k), ARRAY_FILTER_USE_KEY);
        $sys = "You are a website copywriter for '{$name}', a {$copyIndustry} in {$location}. "
             . "Return ONLY valid JSON (include the word json). Every value must be authentic, specific "
             . "content for THIS {$copyIndustry} business — realistic names (never 'John Doe'), concise "
             . "taglines, 1-2 sentence body copy — and NEVER content from any other industry. "
             . "Listings, rooms, menu items, plans and any price, rent or fee are illustrative inventory: realistic for the {$location} market, "
             . "in its local currency (symbol or ISO code), with local place names — the customer replaces them with real stock later. "
             . "Write every item as a real offer; NEVER use the words sample, placeholder, example, illustrative or dummy anywhere in the copy, "
             . "and never put the business name inside an address."
             . ($established ? '' : ' CRITICAL: this is a NEW business with NO track record yet — NEVER '
                . 'fabricate numbers or claims of experience (no "X years", "X+ clients/projects", revenue '
                . 'figures, awards, "trusted by", or big-name clients). For trust/badge/credential fields '
                . 'write qualitative value propositions (approach, quality, care), not invented metrics.');
        $calls = []; $i = 0;
        foreach (array_chunk($llmToFill, 35, true) as $chunk) {
            $lines = [];
            foreach ($chunk as $k => $label) $lines[] = "- {$k}: {$label}";
            $prompt = "Services: {$services}.\nWrite on-brand website text for EACH field below, matching its "
                 . "label/role. Return a JSON object keyed EXACTLY by these field keys:\n" . implode("\n", $lines);
            $calls['coverage_' . (++$i)] = [$sys, $prompt, ['task' => 'arthur_coverage'], 2000, self::BUILD_CALL_EXTRA];
        }
        return $calls;
    }

    private function fillTemplateTextCoverage(array $variables, array $manifest, array $data, string $copyIndustry, string $services, string $location, bool $established = false): array
    {
        $vars = $manifest['variables'] ?? [];
        if (!is_array($vars) || empty($vars)) return $variables;

        $skipKey = '/(image|img|photo|logo|url|color|colour|display|icon|bg|background|style|css|href|src|width|height|dim|ratio|font|hex|locale|canonical|slug|og_image|_id$)/i';
        // structural labels we must NOT rewrite/blank (would break nav/buttons)
        $structKey = '/(nav|menu|link|button|_cta$|^cta|tab|breadcrumb|^logo|_label$)/i';

        $name = $data['business_name'] ?? 'the business';
        $toFill = [];
        foreach ($vars as $k => $spec) {
            if (!is_array($spec)) continue;
            $type = strtolower((string) ($spec['type'] ?? ''));
            if (in_array($type, ['color', 'image', 'url', 'file', 'media', 'number', 'bool', 'boolean'], true)) continue;
            $key = (string) $k;
            if (preg_match($skipKey, $key) || preg_match($structKey, $key)) continue;
            $def = $spec['default'] ?? null;
            if (!is_string($def)) continue;
            $def = trim($def);
            // Only CONTENT prose (multi-word, >=15 chars) — leaves short generic
            // labels ("About Us", "Our Services") untouched.
            if ((strlen($def) < 15 || strpos($def, ' ') === false) && !preg_match(self::CATALOGUE_KEY, $key)) continue;
            if (preg_match('~^(/|https?:|\#|display:)~i', $def)) continue;
            $cur = $variables[$key] ?? null;
            $filledByLLM = is_string($cur) && $cur !== '' && $cur !== $def;
            if ($filledByLLM) continue;
            $toFill[$key] = (string) ($spec['label'] ?? $spec['description'] ?? $key);
        }
        if (empty($toFill)) return $variables;

        // NEVER FABRICATE PEOPLE. Numbered personnel-card fields
        // (doctor_1_name / team_2_name / staff_3_bio / attorney_1_title / ...)
        // are EXCLUDED from the LLM pass — asking for "realistic names" invents
        // fake individuals, whose photos injectImagesToTemplate then fills with
        // mismatched, often-broken cross-industry pool images. Left unfilled the
        // deterministic net below blanks them, and phantom-card + empty-section
        // stripping removes the card/section so the customer adds their REAL team
        // in the editor. Product/service/business names are NOT personnel and
        // stay in the LLM pass.
        $personnelKey = '/^(doctor|dentist|physician|surgeon|therapist|trainer|'
            . 'instructor|coach|staff|team|member|attorney|lawyer|agent|broker|'
            . 'realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist)'
            . '_\\d+_(name|title|specialty|speciality|bio|role|credential|'
            . 'qualification|position)/i';
        $llmToFill = array_filter(
            $toFill,
            fn ($k) => ! preg_match($personnelKey, (string) $k) && (! preg_match(self::FACT_KEY, (string) $k) || preg_match(self::CATALOGUE_KEY, (string) $k)),
            ARRAY_FILTER_USE_KEY
        );

        // Regenerate in chunks so a large template stays reliable.
        // FAST FIRST DRAFT (2026-09-06): answers were fetched in the same round-trip as the site copy; take them for every
        // field still at its sample value, then ask the model only for what is genuinely left (usually nothing).
        if ($this->prefetchedCoverage !== []) {
            foreach ($toFill as $k => $label) {
                if (isset($this->prefetchedCoverage[$k]) && !preg_match($personnelKey, (string) $k)) {
                    $variables[$k] = $this->prefetchedCoverage[$k];
                }
            }
            $llmToFill = array_filter($llmToFill, function ($k) use ($variables, $vars) {
                $def = trim((string) ($vars[$k]['default'] ?? '')); $cur = $variables[$k] ?? null;
                return !(is_string($cur) && $cur !== '' && $cur !== $def);
            }, ARRAY_FILTER_USE_KEY);
            Log::info('[Arthur] coverage from prefetch', ['left_for_llm' => count($llmToFill)]);
        }
        if ($llmToFill !== []) {
            $calls = $this->coverageChunkCalls($llmToFill, (string) $name, $copyIndustry, $services, $location, $established);
            try {
                foreach ($this->runtime->chatJsonPool($calls) as $res) {
                    if (($res['success'] ?? false) && is_array($res['parsed'] ?? null)) {
                        foreach (\App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($res['parsed']) as $k => $v) {
                            if (isset($toFill[$k]) && is_string($v) && trim($v) !== '') {
                                $variables[$k] = trim($v);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] text coverage pass failed: ' . $e->getMessage());
            }
        }
        // EV-1000 (2026-09-12): a field the model did not answer keeps the template's OWN industry copy — a headline,
        // a section title, a step or a story reads as a real page and the customer edits it. It used to be blanked, so
        // one rate-limited round-trip shipped a site with an empty <h1>, empty section titles and empty stat cells.
        // People (names/authors) and facts about the business (phone, hours, prices, stat values …) are still never
        // invented and stay empty.
        $gaps = [];
        foreach ($toFill as $k => $label) {
            $def = trim((string) ($vars[$k]['default'] ?? ''));
            $cur = $variables[$k] ?? null;
            if (is_string($cur) && $cur !== '' && $cur !== $def) continue; // filled
            if (preg_match('/name|author|signature|broker|agent|partner|founder|owner|byline|member|manager|attorney|realtor/i', $k)) {
                $variables[$k] = '';
            } elseif (preg_match($personnelKey, (string) $k) && preg_match('/_(bio|credential|qualification|specialty|speciality)$/i', (string) $k)) {
                // EV-1077 (2026-09-20): the name above became the "Team Member" placeholder, so the biography must not keep the
                // design's demo copy ("Fourteen years in luxury events — the last ten at Aurora" shipped on the Owner's live site).
                $variables[$k] = '';
            } elseif (preg_match('/tagline|meta|subtitle|description/i', $k)) {
                $variables[$k] = $name . ' — ' . ucfirst($copyIndustry) . ' in ' . $location . '.';
            } elseif (preg_match('/phone|email|address|whatsapp|hours|opening|legal|canonical|price|fee|_value$|_number$|licen[cs]e|registration|vat|tax/i', $k)) {
                $variables[$k] = '';
            } else {
                $variables[$k] = $def;
                $gaps[] = $k;
            }
        }
        if ($gaps !== []) Log::warning('[Arthur] coverage gaps kept template copy', ['count' => count($gaps), 'keys' => array_slice($gaps, 0, 40)]);
        // EV-1000: FACTS ARE NEVER INVENTED. A phone number, WhatsApp, website or price on a customer's site comes from
        // the customer or not at all — the site copy call used to answer contact_phone with a made-up number and the
        // 62 templates that carry service prices shipped their sample '£2,950 fixed'. Labels ("Phone") stay.
        $given = [
            'phone'    => trim((string) ($data['phone'] ?? $data['contact_phone'] ?? '')),
            'whatsapp' => trim((string) ($data['whatsapp'] ?? '')),
            'website'  => trim((string) ($data['website'] ?? '')),
            'email'    => trim((string) ($data['email'] ?? $data['contact_email'] ?? '')),
        ];
        $cleared = [];
        foreach ($vars as $k => $spec) {
            $k = (string) $k;
            if (!preg_match(self::FACT_KEY, $k, $fm) || preg_match(self::CATALOGUE_KEY, $k)) continue;
            $kind = strtolower($fm[2]);
            $val  = $given[$kind] ?? '';
            if ($val === '' && $kind === 'fax') $val = '';
            $cur = $variables[$k] ?? null;
            if ($val !== '') { $variables[$k] = $val; continue; }
            if (is_string($cur) && trim($cur) !== '') { $variables[$k] = ''; $cleared[] = $k; }
        }
        if ($cleared !== []) Log::info('[Arthur] facts cleared (customer supplies them)', ['keys' => $cleared]);
        return $variables;
    }

    /**
     * Per-industry gallery prompts. Added 2026-04-19 to replace a hardcoded
     * restaurant-themed array that was polluting every non-restaurant build.
     * Returns 3 prompts per industry. Unknown industries fall back to a
     * generic professional set.
     */
    private function getGalleryPrompts(string $industry, string $location): array
    {
        $map = [
            'restaurant' => [
                'Elegant gourmet cuisine plating on dark slate, professional food photography, warm lighting',
                'Luxury restaurant table setting, candlelight, gold cutlery, dark moody intimate atmosphere, no people',
                'Fresh colorful ingredients and spices flatlay, dark background, overhead shot, professional food photography',
            ],
            'cafe' => [
                'Artisan coffee being poured into ceramic cup with latte art, warm morning light, cozy cafe ambiance',
                'Fresh pastries and baked goods on wooden counter, warm golden lighting, artisan cafe aesthetic',
                'Cozy cafe interior with exposed brick, wooden tables, string lights, inviting atmosphere',
            ],
            'fitness' => [
                'Modern premium gym interior with free weights and equipment, dramatic lighting, motivational atmosphere',
                'Athletic person performing bodyweight exercise, professional sports photography, dynamic composition',
                'Premium fitness equipment detail shot, dumbbells and kettlebells, gym aesthetic, natural light',
            ],
            'beauty' => [
                'Luxury beauty salon styling chair, professional lighting, elegant mirror, upscale interior',
                'Skincare products flatlay on marble surface, soft daylight, minimalist beauty photography',
                'Professional makeup brushes and cosmetics palette arranged elegantly, soft pink backdrop',
            ],
            'healthcare' => [
                'Clean modern medical examination room, stethoscope on desk, professional healthcare setting',
                'Caring medical consultation between doctor and patient in modern clinic, warm welcoming light',
                'Medical instruments and charts arranged neatly on clinical surface, professional environment',
            ],
            'legal' => [
                'Prestigious law office bookshelf, leather-bound legal volumes, warm library lighting',
                'Legal contract signing desk with fountain pen and documents, professional law firm atmosphere',
                'Scales of justice statue on mahogany desk, dramatic lighting, classical legal aesthetic',
            ],
            'real_estate' => [
                'Luxury property exterior at golden hour, modern architecture, manicured landscape',
                'High-end property interior, open-concept living space with floor-to-ceiling windows',
                'Premium residential kitchen, marble countertops, designer fixtures, natural light',
            ],
            'real_estate_broker' => [
                'Real estate broker showing luxury property to client, handshake moment, elegant home interior',
                'Dubai skyline view from high-floor luxury apartment, panoramic windows at sunset',
                'Property documents being signed, premium pen on closing contract, real estate transaction moment',
            ],
            'fashion' => [
                'Elegant fashion boutique interior, curated garments on hangers, soft ambient lighting',
                'Luxury fabric texture close-up, haute couture detail, artisan craftsmanship',
                'Fashion model in editorial pose, studio lighting, minimalist backdrop',
            ],
            'technology' => [
                'Modern tech office workspace with multiple monitors showing code, clean minimalist design',
                'Server room with cascading blue LED lights, enterprise infrastructure aesthetic',
                'Developer hands typing on mechanical keyboard, dim ambient office, focused productivity',
            ],
            'marketing_agency' => [
                'Modern marketing agency office with team collaborating, monitors displaying analytics dashboards',
                'Creative brainstorm session with sticky notes and whiteboards, dynamic workspace atmosphere',
                'Social media creative assets being designed on laptop, mood board with color palette',
            ],
            'events' => [
                'Elegant wedding reception table setting, floral centerpieces, candlelight ambiance',
                'Event venue setup with dramatic uplighting, empty chairs in formation, anticipation',
                'Corporate event stage with spotlights and audience silhouettes, professional production',
            ],
            'interior_design' => [
                'Modern living room interior with designer furniture, curated art, natural light',
                'Luxury bedroom design, premium bedding, accent lighting, sophisticated color palette',
                'High-end kitchen interior, open shelving, marble island, pendant lighting',
            ],
            'education' => [
                'Modern university lecture hall with engaged students, natural light through large windows',
                'Library interior with study areas, wooden shelves, scholarly atmosphere',
                'Science laboratory class with students and microscopes, STEM education, bright lighting',
            ],
            'automotive' => [
                'Luxury car showroom floor with gleaming vehicles, polished concrete, dramatic lighting',
                'Auto technician working on engine bay, professional garage, attention to detail',
                'Premium car interior detail, leather seats and stitched dashboard, luxury cabin',
            ],
            'hospitality' => [
                'Luxury hotel lobby with grand chandelier, marble floors, elegant reception desk',
                'Premium hotel suite bedroom with panoramic city view, silk bedding, ambient lighting',
                'Resort infinity pool at sunset, tropical palms, aspirational travel destination',
            ],
            'cleaning' => [
                'Sparkling clean modern kitchen after professional service, natural light, pristine surfaces',
                'Professional cleaner in uniform using eco-friendly products in bright home interior',
                'Organized cleaning supplies and equipment arranged neatly, hygienic aesthetic',
            ],
            'construction' => [
                'Construction site with cranes and steel structure, blue sky backdrop, dynamic composition',
                'Architect reviewing blueprints on site, hard hat, focused professional atmosphere',
                'Modern building under construction, concrete and steel detail, industrial aesthetic',
            ],
            'photography' => [
                'Professional photography studio with lighting setup, seamless backdrop, camera on tripod',
                'Portrait session with natural light, artistic model composition, editorial mood',
                'Camera lens detail macro shot, aperture blades visible, photography equipment artistry',
            ],
            'childcare' => [
                'Bright colorful nursery classroom with wooden toys, cheerful learning environment',
                'Happy children playing with educational materials, safe nursery interior, natural light',
                'Teacher reading to attentive preschool children, cozy story corner, warm atmosphere',
            ],
            'consulting' => [
                'Modern executive boardroom with strategic presentation on screen, professional meeting',
                'Consultants reviewing business charts and financial data, collaborative problem-solving',
                'Corporate strategy whiteboard session with frameworks, modern consulting office',
            ],
            'finance' => [
                'Financial advisor office with charts and laptop on desk, professional consultation setting',
                'Modern accounting workspace with documents, calculator, tax preparation materials',
                'Corporate finance meeting, executives reviewing quarterly reports, professional atmosphere',
            ],
            'wellness' => [
                'Serene yoga studio with natural light, meditation cushions, calming sage tones',
                'Holistic wellness treatment room with natural materials, aromatherapy, healing atmosphere',
                'Mindfulness session outdoors with nature backdrop, peaceful contemplative mood',
            ],
            'pet_services' => [
                'Happy dog being groomed at professional salon, gentle caring hands, clean environment',
                'Modern veterinary clinic examination room, caring vet with friendly pet, bright interior',
                'Pets playing together at daycare facility, joyful atmosphere, safe indoor play area',
            ],
            'logistics' => [
                'Modern warehouse with organized shelving and forklift in action, efficient operations',
                'Shipping containers at port with cranes, global logistics, industrial scale',
                'Delivery vehicles lined up professionally, logistics fleet, transportation service',
            ],
            'architecture' => [
                'Stunning modern architecture detail, geometric forms, dramatic shadow play',
                'Architecture studio with physical models and technical drawings, creative design process',
                'Contemporary building facade close-up, materials and texture, architectural photography',
            ],
        ];

        $prompts = $map[$industry] ?? [
            "Professional {$industry} business interior in {$location}, clean modern design, warm lighting, premium atmosphere",
            "Contemporary {$industry} workspace, team collaboration, aspirational environment",
            "Premium {$industry} service in action, professional quality, attention to detail",
        ];
        $suffix = ', no text no logos no words, photorealistic';
        return array_map(fn($p) => $p . $suffix, $prompts);
    }

    /**
     * PATCH 8 (2026-05-08) — Architecture Lock Tier 1.
     *
     * Build a canonical sections_json array for an Arthur-generated page so
     * BuilderRenderer can serve the page without depending on the static
     * index.html file. Tier 1 produces a minimal-but-valid set covering the
     * 7 BuilderRenderer-supported types (header / hero / features / cta /
     * contact_form / blog_list / footer). Tier 2 will derive richer content
     * from Arthur's wizard state instead of these defaults.
     *
     * Section schema is what BuilderRenderer expects:
     *   [{"type": "...", "heading": "...", "body": "...", ...}, ...]
     */
    /**
     * v1.4.4 (2026-05-30) — made public so BuilderService::addPageFromTemplate
     * can reuse it when Sarah / the user asks Arthur to add a new page.
     */
    // PATCH (pages-context, 2026-07-24 · P4) — Public entry: build the page's
    // section SKELETON (structure), then enrich its copy with business-context,
    // archetype-aware text so an added About/Services/Contact page no longer
    // ships identical consultancy filler ("Quality first", "work with you on
    // your project") for a bakery or a law firm alike.
    // ═══════════════════════════════════════════════════════════════════════════════════════════════════
    // ARTHUR DELEGATION (2026-09-06). Boss's rule: Sarah never builds — she asks Arthur. Arthur never codes
    // from scratch — he adds pages and sections FROM THE TEMPLATES (BuilderCapabilities), in the site's current
    // palette, with the copy rewritten for the business, and every addition is priced.
    // Entry: EngineExecutionService action 'ask_arthur' (Sarah tool builder.ask_arthur and the legacy
    // add_page_from_template / create_page / edit_page_with_arthur tools all land here).
    // ═══════════════════════════════════════════════════════════════════════════════════════════════════
    public function handleSiteRequest(int $wsId, int $websiteId, string $request, array $ctx = []): array
    {
        // DEC-0046: one history snapshot per request, deduplicated, so Undo and Versions cover every Arthur change.
        try { app(TemplateService::class)->snapshotToHistory($websiteId, 'arthur_request'); } catch (\Throwable $e) {}
        // U3 (2026-09-20): added blocks on the export carry their field ids before Arthur reads it (copy edits target them).
        try { app(TemplateService::class)->refreshHomeAddedBlocks($websiteId); } catch (\Throwable $e) {}
        $caps = \App\Engines\Builder\Support\BuilderCapabilities::class;
        $site = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        if (!$site || (int) $site->workspace_id !== $wsId) {
            return ['success' => false, 'error' => 'Website not found in this workspace', 'code' => 'NOT_FOUND'];
        }
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $tv       = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        // VARIANTS (2026-09-11): settings.template may be a design directory (travel_meridian), not an industry.
        // Element eligibility (trip quiz, shop pages) must be judged on the industry the manifest declares.
        $industry = $this->templates->industryOf((string) ($settings['template'] ?? $settings['industry'] ?? $site->template_industry ?? ''));
        $isStatic = is_file(storage_path("app/public/sites/{$websiteId}/index.html"));
        // FILE HAND-OFF (2026-09-06): attached files (media ids from Sarah / the editor) are placed, not classified
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', (array) ($ctx['attachments'] ?? [])))));
        if (preg_match_all('/media[_ ]id\s*#?(\d+)/i', $request, $mm)) $mediaIds = array_values(array_unique(array_merge($mediaIds, array_map('intval', $mm[1]))));
        $mentionsFile = (bool) preg_match('/\b(logo|photo|photos|picture|pictures|image|images|pic|pics)\b/i', $request);
        if ($mediaIds !== [] && is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) {
            return $this->placeMedia($wsId, $websiteId, $request, $mediaIds, $site, json_decode((string) ($site->template_variables ?: '{}'), true) ?: [], $ctx);
        }
        if ($mediaIds === [] && $mentionsFile && preg_match('/\b(attached|this|these|here is|here are|sent|uploaded)\b/i', $request) && !preg_match('/\b(section|page|gallery section|remove|delete)\b/i', $request)) {
            return ['success' => false, 'code' => 'NO_FILE', 'applied' => 0, 'actions_applied' => 0,
                'message' => "I didn't receive the file itself — attach the logo or photos in the chat and ask again, and I'll place them on {$site->name}."];
        }
        // ARTHUR LLM-FIRST (DEC-0050, 2026-09-14, Owner: 'Arthur must be LLM first … must understand full context. he is not a robot'):
        // the model reads the message with the whole site and the conversation, decides one intent or asks; executors act.
        if ($isStatic && empty($ctx['_clause']) && empty($ctx['_no_brain'])) {
            $intent = null; $brain = null;
            $ctx['selected'] = $this->selectionContext($websiteId, $ctx);   // SELECTION888: what the customer clicked, with its text
            Log::info('[Arthur] selection', ['website' => $websiteId, 'selected' => $ctx['selected'] === null ? 'none' : $ctx['selected']['field'] . '@' . $ctx['selected']['block'] . ' <' . $ctx['selected']['tag'] . '>']);
            try { $brain = app(ArthurIntentService::class); $intent = $brain->interpret($wsId, $websiteId, $site, $request, $ctx, $this->intentContext($wsId, $websiteId, $site, $tv, (string) $industry) + ['selected' => $ctx['selected']]); }
            catch (\Throwable $e) { Log::warning('[Arthur] intent failed, classic path', ['website' => $websiteId, 'error' => $e->getMessage()]); }
            if ($intent !== null && $brain !== null) {
                $out = $this->dispatchIntent($wsId, $websiteId, $site, $request, $ctx, $tv, (string) $industry, $intent, $isStatic);
                if ($out !== null) { $brain->remember($wsId, $websiteId, $ctx, $request, $out, $intent); return $out; }
                // the classic executors run with the model's explicit wording (sections, pages, images, video …), then the turn is remembered
                $classic = $this->handleSiteRequest($wsId, $websiteId, $request, $ctx + ['_no_brain' => true]);
                $brain->remember($wsId, $websiteId, $ctx, $request, $classic, $intent);
                return $classic;
            }
        }
        // CATALOGUE888 (DEC-0049, 2026-09-14): on a design that carries a catalogue (listings, services, menu …), item commands go to the
        // catalogue backend (add / reprice / mark sold / remove). Every other site never enters this branch.
        if ($isStatic) {
            try {
                $cat = app(CatalogueService::class);
                $catSpecs = $cat->enabledSpecs($websiteId, $site);
                if ($catSpecs !== [] && CatalogueService::looksLikeCatalogueRequest($request, $catSpecs, $websiteId)) { $catRes = $cat->arthur($wsId, $websiteId, $request, $ctx); if (($catRes['code'] ?? '') !== 'PASS') { return $catRes; } }   // credits set by CatalogueService
            } catch (\Throwable $e) { Log::warning('[Arthur] catalogue branch failed: ' . $e->getMessage()); }
        }
        $plan     = $caps::classify($request, $industry ?: null);
        // U2 (2026-09-20): the section picker names the section, the anchor and the side — no words to parse. The
        // catalogue still decides whether the type is offered to this industry (unsupported → honest refusal, no charge).
        if (is_array($ctx['_plan'] ?? null) && ! empty($ctx['_plan']['section'])) {
            $secType = (string) $ctx['_plan']['section'];
            $offered = $caps::sections($industry ?: null);
            if (isset($offered[$secType])) {
                $plan = ['kind' => 'section', 'page' => null, 'section' => $secType, 'anchor' => (string) ($ctx['_plan']['anchor'] ?? 'contact'), 'where' => (string) ($ctx['_plan']['where'] ?? 'before'),
                    'label' => $offered[$secType]['label'], 'credits' => (int) $offered[$secType]['credits'], 'reason' => 'explicit plan (section picker)'];
            } else {
                $plan = ['kind' => 'unsupported', 'page' => null, 'section' => $secType, 'anchor' => null, 'where' => null, 'label' => '', 'credits' => 0,
                    'reason' => "the '" . ($caps::SECTIONS[$secType]['label'] ?? $secType) . "' section is not offered to " . ($industry ?: 'this') . ' sites'];
            }
            $ctx['_clause'] = true;   // one unit, never split
        }
        // STRESS C19 (2026-09-06): "change X and add Y" — run each clause, report both, sum the credits.
        if (empty($ctx['_clause'])) {
            $clauses = self::splitClauses($request);
            if (count($clauses) > 1) {
                // Many instructions at once: consecutive copy edits merge into ONE model call (up to 20 field changes);
                // additions/removals run one by one; at most 10 units per message, the rest is reported back honestly.
                $units = []; $editBuf = [];
                foreach ($clauses as $cl) {
                    if ($caps::classify($cl, $industry ?: null)['kind'] === 'edit') { $editBuf[] = $cl; continue; }
                    if ($editBuf !== []) { $units[] = implode('; ', $editBuf); $editBuf = []; }
                    $units[] = $cl;
                }
                if ($editBuf !== []) $units[] = implode('; ', $editBuf);
                $overflow = array_slice($units, self::MAX_UNITS_PER_MESSAGE); $units = array_slice($units, 0, self::MAX_UNITS_PER_MESSAGE);
                $agg = ['success' => false, 'kind' => 'compound', 'plan' => $plan, 'credits' => 0, 'applied' => 0, 'parts' => [], 'messages' => []];
                foreach ($units as $clause) {
                    try { $p = $this->handleSiteRequest($wsId, $websiteId, $clause, $ctx + ['_clause' => true]); }
                    catch (\Throwable $e) { Log::error('[Arthur] compound clause failed', ['clause' => $clause, 'error' => $e->getMessage()]); $p = ['success' => false, 'code' => 'CLAUSE_FAILED', 'message' => 'I could not do "' . mb_substr($clause, 0, 60) . '" — ' . 'please try that one again.']; }
                    $agg['parts'][] = ['request' => $clause, 'success' => (bool) ($p['success'] ?? false), 'code' => $p['code'] ?? null];
                    if ($p['success'] ?? false) { $agg['success'] = true; $agg['applied'] += max(1, (int) ($p['applied'] ?? 1)); }
                    $agg['credits'] += (int) ($p['credits'] ?? 0);
                    $agg['messages'][] = trim((string) ($p['message'] ?? $p['reply'] ?? $p['error'] ?? ''));
                    if (!empty($p['url'])) $agg['url'] = $p['url'];
                }
                if ($overflow !== []) $agg['messages'][] = 'I stopped after ' . self::MAX_UNITS_PER_MESSAGE . ' changes in one go — send the remaining ' . count($overflow) . ' as a new message and I will carry on.';
                $agg['message'] = implode(' ', array_filter($agg['messages'])); $agg['reply'] = $agg['message']; $agg['actions_applied'] = $agg['applied'];
                unset($agg['messages']);
                return $agg;
            }
        }
        $dryRun   = !empty($ctx['dry_run']);

        // Shop pages need the store engine (live cart / checkout / account); a static template site cannot run them yet.
        if ($plan['kind'] === 'page' && $isStatic && in_array($plan['page'], ['cart', 'checkout', 'account'], true)) {
            return ['success' => false, 'code' => 'NEEDS_STORE_ENGINE', 'plan' => $plan,
                'message' => "A {$plan['page']} page needs the live store engine, which this template site doesn't run yet. I can add a listing browser or a product detail page from the templates instead."];
        }
        // DESIGN (2026-09-11) — colours, palette, gradients, fonts. Placed BEFORE the unsupported
        // branch so a design request can never fall out of the bottom as "I can't build that".
        if ($plan['kind'] === 'style') {
            try { return $this->applySiteStyle($wsId, $websiteId, $request, $site, $tv, $plan, $isStatic); }
            catch (\Throwable $e) {
                Log::error('[Arthur] applySiteStyle failed', ['website' => $websiteId, 'error' => $e->getMessage()]);
                return ['success' => false, 'code' => 'STYLE_FAILED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0,
                    'message' => 'I could not apply that design change just now — nothing on your site was altered.'];
            }
        }
        // IMAGE GENERATION at edit time (DEC-0046 gap closure, 2026-09-14): the image service existed; Builder never called it.
        if ($plan['kind'] === 'image') {
            return $this->generateSiteImage($wsId, $websiteId, $request, $site, $plan, $isStatic, $tv);
        }
        // STUDIO → ARTHUR (2026-09-14): the studio's video, text-on-image and image-edit capabilities, from the chat.
        if ($plan['kind'] === 'video')      { return $this->generateSiteVideo($wsId, $websiteId, $request, $site, $plan, $isStatic, $tv, (string) $industry, $ctx); }
        if ($plan['kind'] === 'overlay')    { return $this->overlayTextOnSiteImage($wsId, $websiteId, $request, $site, $plan, $isStatic, $tv); }
        if ($plan['kind'] === 'image_edit') { return $this->editSiteImage($wsId, $websiteId, $request, $site, $plan, $isStatic, $tv, $ctx); }
        if ($plan['kind'] === 'clarify') {
            // RISK-0195 (2026-09-20): section or page — ask, never guess and charge
            return ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => (string) ($plan['question'] ?? 'Section on the home page, or a separate page?'),
                'options' => array_map(fn ($o) => is_array($o) ? ($o['label'] ?? '') : (string) $o, (array) ($plan['options'] ?? [])),
                'option_messages' => array_map(fn ($o) => is_array($o) ? ($o['message'] ?? '') : (string) $o, (array) ($plan['options'] ?? []))];
        }
        if ($plan['kind'] === 'unsupported' && preg_match('/\bblog\b/i', $request)) {
            $hasBlog = is_file(storage_path("app/public/sites/{$websiteId}/blog/index.html"));
            return ['success' => false, 'code' => 'BLOG_VIA_WRITE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => $hasBlog ? "{$site->name} already has a blog at /blog/ — articles you publish from Write appear there automatically; I don't add blog pages by hand."
                    : "The blog is not a page I add by hand: publish an article from Write and {$site->name} gets its blog at /blog/ automatically, with a Blog link in the menu."];
        }
        if ($plan['kind'] === 'unsupported') {
            $pages = implode(', ', array_keys($caps::pages($industry ?: null)));
            $secs  = implode(', ', array_keys($caps::sections($industry ?: null)));
            return ['success' => false, 'code' => 'UNSUPPORTED', 'plan' => $plan,
                'message' => "I can't build that from the templates I have. For {$site->name} I can add these pages: {$pages}; or these sections on the home page: {$secs}. Tell me which, and where."];
        }
        if ($plan['kind'] === 'remove' && $isStatic) {
            try { return $this->removeFromStaticSite($wsId, $websiteId, $request, $plan, $site, $industry ?: null); }
            catch (\Throwable $e) { Log::error('[Arthur] removeFromStaticSite failed', ['error' => $e->getMessage(), 'website' => $websiteId]); return ['success' => false, 'code' => 'REMOVE_FAILED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'message' => 'I could not remove that just now — nothing on your site was changed.']; }
        }
        if ($plan['kind'] === 'remove') {
            return ['success' => false, 'code' => 'USE_EDITOR', 'plan' => $plan,
                'message' => "Removing content is done in the page editor (select the section → Remove), so nothing disappears by accident. I can add or rewrite sections from here."];
        }
        if ($dryRun) {
            return ['success' => true, 'dry_run' => true, 'plan' => $plan, 'credits' => $plan['credits'],
                'message' => "Plan: add {$plan['label']} to {$site->name} for {$plan['credits']} credits."];
        }

        $identity = $this->siteIdentity($site, $tv, $industry);
        $brand    = $this->paletteBrand($tv, $settings);
        $renderer = app(BuilderRenderer::class);
        $credits  = app(\App\Core\Billing\CreditService::class);

        try {
            if ($plan['kind'] === 'edit' && $isStatic) {
                $r = $this->editStaticCopy($wsId, $websiteId, $request, $site, $tv, $plan);
                if (($r['success'] ?? false) && $plan['credits'] > 0) $credits->debit($wsId, $plan['credits'], 'builder_arthur_edit', $websiteId, ['request' => mb_substr($request, 0, 200), 'fields' => $r['changes'] ?? []]);
                return $r + ['plan' => $plan, 'credits' => ($r['success'] ?? false) ? $plan['credits'] : 0];
            }
            if ($plan['kind'] === 'edit') {
                $home = DB::table('pages')->where('website_id', $websiteId)->where(function ($q) { $q->where('is_homepage', 1)->orWhere('slug', 'home'); })->orderBy('id')->first();
                if (!$home) return ['success' => false, 'error' => 'No home page row to edit', 'code' => 'NO_PAGE'];
                $r = app(ArthurEditService::class)->editPage((int) $home->id, $request, null, ['workspace_id' => $wsId, 'agent_slug' => $ctx['agent_slug'] ?? 'sarah']);
                if (($r['success'] ?? false) && $plan['credits'] > 0) $credits->debit($wsId, $plan['credits'], 'builder_arthur_edit', $websiteId, ['request' => mb_substr($request, 0, 200)]);
                return $r + ['plan' => $plan, 'credits' => ($r['success'] ?? false) ? $plan['credits'] : 0];
            }

            if ($plan['kind'] === 'page') {
                $slug = $plan['page'];
                $meta = \App\Engines\Builder\Services\ArthurService::PAGE_TEMPLATE_CATALOGUE[$slug] ?? [];
                $title = (string) ($meta['label'] ?? ucfirst($slug));
                $urlSlug = str_replace('_', '-', $slug);
                if (DB::table('pages')->where('website_id', $websiteId)->where('slug', $urlSlug)->exists()) {
                    return ['success' => false, 'code' => 'EXISTS', 'plan' => $plan, 'message' => "{$site->name} already has a {$title} page (/{$urlSlug}). I can rewrite it instead — tell me what to change."];
                }
                $sections = $this->buildDefaultSectionsForPage($slug, $identity);
                // persist the page row (published: the static export is what is served, the row keeps the editor + listing honest)
                $created = app(\App\Engines\Builder\Services\BuilderService::class)->createPage($websiteId, ['title' => $title, 'slug' => $urlSlug, 'sections' => $sections, 'status' => 'published']);
                $pageId = (int) ($created['page_id'] ?? 0);
                $url = null;
                if ($isStatic) {
                    $body = $this->renderSectionsInTemplateChrome($renderer, $sections, $brand, (array) $site);
                    $path = $this->templates->deployPage($websiteId, $urlSlug, $body, $title);
                    if ($path) { $this->templates->addNavLink($websiteId, $urlSlug, trim(explode('/', $title)[0])); $url = "/storage/sites/{$websiteId}/{$urlSlug}/index.html"; }
                }
                $credits->debit($wsId, $plan['credits'], 'builder_arthur_page', $websiteId, ['page' => $slug, 'page_id' => $pageId]);
                Log::info('[Arthur] delegated page added', ['website_id' => $websiteId, 'page' => $slug, 'static' => $isStatic, 'credits' => $plan['credits']]);
                return ['success' => true, 'kind' => 'page', 'plan' => $plan, 'page_id' => $pageId, 'slug' => $urlSlug, 'url' => $url, 'credits' => $plan['credits'],
                    'message' => "Added the {$title} page to {$site->name}" . ($url ? " — linked from the menu, in your palette" : '') . ". {$plan['credits']} credits."];
            }

            if ($plan['kind'] === 'section') {
                if ($isStatic && str_contains((string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html")), 'data-block="added_' . (string) $plan['section'] . '"')) {
                    return ['success' => false, 'code' => 'EXISTS', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0,
                        'message' => "{$site->name}'s home page already has a {$plan['label']} section. Tell me what to change in it, or ask me to remove it first."];
                }
                $type = $plan['section'];
                $sec = $this->defaultSectionSpec($type, $identity, $tv, $request);
                if ($type === 'video_embed' && trim((string) ($sec['video_url'] ?? '')) === '') {
                    return ['success' => false, 'code' => 'NEEDS_VIDEO_URL', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                        'message' => 'Happy to add a video section — send me the YouTube or Vimeo link (or a direct .mp4 URL) and I will place it, for example "add a video section with https://youtu.be/…".'];
                }
                $html = $renderer->renderSection($sec, $brand, (array) $site);
                if ($type === 'video_embed' && trim($html) === '') {
                    return ['success' => false, 'code' => 'VIDEO_HOST_UNSUPPORTED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                        'message' => 'I can embed YouTube and Vimeo links, or a direct .mp4/.webm file — that link is not one of those, so nothing was added.'];
                }
                $html = $this->adoptTemplateTypography($html);
                // RISK-0191 U1 (2026-09-19): the section speaks the site's palette roles (var(--lu-*, current hex)) —
                // light or dark scheme alike — and the stored fragment carries them, so a later palette switch repaints it.
                $html = $this->roleifyForSite($html, $websiteId, $brand);
                // U3 (2026-09-20): stable field ids (added_{type}_{n}) + the texts in template_variables — the added section is
                // editable like the template's own blocks (inline, Arthur copy edits, element ops)
                $__asf = \App\Engines\Builder\Support\AddedSectionFields::assign($html, $type);
                $html = $__asf['html']; $this->mirrorAddedFieldValues($websiteId, $__asf['values']);
                $blockId = 'added_' . $type;
                // Wrap, don't rewrite: the rendered markup keeps its own ids (self-initialising elements such as the trip
                // quiz look themselves up by id). Any id the renderer emitted that collides with a template block is
                // neutralised, and OUR anchor lives on the wrapper.
                $html = preg_replace('/(<section\b[^>]*\s)id="(?:booking|contact|services|team|gallery|testimonials|hero|faq|pricing)"/i', '$1data-old-id="$2"', $html) ?? $html;
                // VISUAL QA 2026-09-06: clears the sticky nav when reached from the menu (template sections carry ~110px top padding)
                $html = '<section data-block="' . e($blockId) . '" id="lu-' . e($type) . '" ' . \App\Engines\Builder\Support\PaletteRoles::RENDERED_MARK . '="' . \App\Engines\Builder\Support\PaletteRoles::RENDERED_MARK_VERSION . '" style="padding:24px 0;scroll-margin-top:100px">' . $html . '</section>';
                $placed = false;
                if ($isStatic) {
                    $this->templates->rememberSpliced($websiteId, $type, $html, (string) ($plan['anchor'] ?? 'contact'), (string) ($plan['where'] ?? 'before'));
                    $placed = $this->templates->spliceSectionIntoHome($websiteId, $html, (string) ($plan['anchor'] ?? 'contact'), (string) ($plan['where'] ?? 'before')) !== null;
                }
                // keep the home sections row in step so the editor lists it (Law 11: persistence lives in BuilderService)
                $rowOk = app(\App\Engines\Builder\Services\BuilderService::class)->appendSectionToHomePage($websiteId, $sec);
                if (!$placed && !$isStatic) $placed = $rowOk;
                if (!$placed) return ['success' => false, 'code' => 'NOT_PLACED', 'plan' => $plan, 'message' => 'The section could not be placed on the home page.'];
                $credits->debit($wsId, $plan['credits'], 'builder_arthur_section', $websiteId, ['section' => $type, 'anchor' => $plan['anchor'], 'where' => $plan['where']]);
                Log::info('[Arthur] delegated section added', ['website_id' => $websiteId, 'section' => $type, 'anchor' => $plan['anchor'], 'where' => $plan['where'], 'credits' => $plan['credits']]);
                // Say where it really landed: a café has no "services" block, so "after the services" became "after the menu".
                $usedAnchor = $this->templates->lastAnchorUsed ?? (string) ($plan['anchor'] ?? 'contact');
                $whereTxt = ($usedAnchor === 'footer' && ($plan['anchor'] ?? '') !== 'footer')
                    ? 'at the end of the page (it has no ' . str_replace('_', ' ', (string) ($plan['anchor'] ?? 'contact')) . ' section to sit ' . ($plan['where'] ?? 'before') . ')'
                    : ($plan['where'] ?? 'before') . ' the ' . str_replace('_', ' ', $usedAnchor) . ' section';
                return ['success' => true, 'kind' => 'section', 'plan' => $plan, 'section' => $type, 'credits' => $plan['credits'],
                    'url' => $isStatic ? "/storage/sites/{$websiteId}/index.html#lu-{$type}" : null,
                    'message' => "Added a {$plan['label']} to {$site->name}'s home page {$whereTxt}, in your palette. {$plan['credits']} credits."];
            }
        } catch (\Throwable $e) {
            Log::error('[Arthur] handleSiteRequest failed: ' . $e->getMessage(), ['website_id' => $websiteId, 'request' => mb_substr($request, 0, 200)]);
            return ['success' => false, 'error' => 'Arthur could not complete that: ' . $e->getMessage(), 'code' => 'ARTHUR_FAILED', 'plan' => $plan];
        }
        return ['success' => false, 'error' => 'Unhandled plan', 'code' => 'UNHANDLED', 'plan' => $plan];
    }

    /** The business facts every added page/section is written from: THIS site's variables first. */
    private function siteIdentity(object $site, array $tv, string $industry): array
    {
        // Whatever this template calls the things it sells (SERVICE-TITLES 2026-09-11).
        $services = \App\Engines\Builder\Services\TemplateService::serviceTitles($tv);
        $loc = trim((string) ($tv['city'] ?? $tv['contact_service_area'] ?? $tv['location'] ?? ''));
        if ($loc === '' && preg_match('/\bin\s+([A-Z][a-zA-Z ]{2,30})$/', (string) ($tv['hero_eyebrow'] ?? ''), $m)) $loc = trim($m[1]);
        return [
            'business_name' => (string) ($tv['business_name'] ?? $site->name ?? 'Your Business'),
            'industry'      => $industry ?: 'business',
            'core_service'  => $services[0] ?? '',
            'services'      => $services,
            'location'      => $loc,
            'phone'         => (string) ($tv['contact_phone'] ?? $tv['phone'] ?? ''),
            'email'         => (string) ($tv['contact_email'] ?? $tv['email'] ?? ''),
            'style'         => (string) ($tv['design_style'] ?? ''),
        ];
    }

    /** Brand tokens for BuilderRenderer from the site's OWN palette, never the workspace kit. */
    /**
     * DESIGN CHANGES (2026-09-11) — colours, palette, gradients, fonts, overall style.
     *
     * Nothing here is new capability. applyStyleColors() has recoloured static exports since 2026-09-02
     * and DesignStyle::layer() has produced the gradient/typography layer since 2026-09-05; both were
     * simply unreachable from chat once the delegation shortcut landed. This method is the road back.
     */
    /**
     * Make the brand mark readable. Three cases, in order: (1) the export carries the transparent placeholder as
     * its logo and the brand text is hidden → show the text again and forget the placeholder (free); (2) a visible
     * TEXT logo → one replaceable rule colours it for the header it sits on; (3) an uploaded IMAGE logo → nothing
     * we can recolour from here; say so.
     */
    private function fixLogoVisibility(int $websiteId, object $site, array &$tv): array
    {
        $did = []; $missed = []; $charge = false;
        $root  = storage_path("app/public/sites/{$websiteId}");
        $index = "{$root}/index.html";
        if (! is_file($index)) { return ['did' => [], 'missed' => ['This site has no page export yet.'], 'charge' => false]; }
        $files = [$index];
        foreach ((glob("{$root}/*/index.html") ?: []) as $f) { if (! str_contains($f, '/.history/')) { $files[] = $f; } }
        $home = (string) file_get_contents($index);
        $name = trim((string) ($tv['logo'] ?? $tv['business_name'] ?? $site->name));
        $blankStored = str_contains((string) ($tv['logo_url'] ?? ''), self::BLANK_LOGO);
        $hiddenText  = (bool) preg_match('/<[^>]*class="[^"]*brand-text[^"]*"[^>]*style="display:none"/', $home);
        if ($hiddenText && ($blankStored || preg_match('/<img[^>]*src="[^"]*' . preg_quote(self::BLANK_LOGO, '/') . '[^"]*"[^>]*data-field="logo_url"/', $home))) {
            $n = 0;
            foreach ($files as $f) {
                $h = (string) file_get_contents($f);
                $new = preg_replace('/(<[^>]*class="[^"]*brand-text[^"]*"[^>]*style=")display:none(")/', '$1display:block$2', $h);
                if ($new !== null && $new !== $h) { file_put_contents($f, $new); $n++; }
            }
            $tv['logo_url'] = ''; $tv['logo_text_display'] = 'display:block';
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            if ($n > 0) { $did[] = "put the text logo “{$name}” back in the header — the logo image was blank, so nothing showed there"; return compact('did', 'missed', 'charge'); }
            $missed[] = 'The logo text could not be restored on this export — open the page editor and click the logo to set it.';
            return compact('did', 'missed', 'charge');
        }
        $hasImageLogo = (bool) preg_match('/<img[^>]*class="[^"]*(?:lu-logo-img|brand-mark)[^"]*"[^>]*src="(?!data:image\/svg\+xml;utf8,%3Csvg)[^"]+"/', $home)
            || (bool) preg_match('/<img[^>]*src="(?!data:image\/svg\+xml;utf8,%3Csvg)[^"]+"[^>]*class="[^"]*(?:lu-logo-img|brand-mark)[^"]*"/', $home);
        if ($hasImageLogo) {
            $missed[] = 'Your logo is an uploaded image, so I cannot recolour it from here — click the logo in the editor to upload a version with lighter or darker lettering, or ask me to remove the logo image so the name shows as text.';
            return compact('did', 'missed', 'charge');
        }
        $bg = $this->headerBackground($websiteId, $home);
        $fg = self::readableOn($bg);
        $rule = ['logo' => '.logo,.logo *,.brand-text,.nav-logo,.site-logo,[data-field="logo"],[data-field="logo"] *,[data-field="nav_logo"],[data-field="header_logo"]{color:' . $fg . '!important;opacity:1!important;visibility:visible!important}'];
        if (self::writeDesignExtras($websiteId, $rule, $tv)) {
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            $did[] = 'set the logo to ' . ($fg === '#FFFFFF' ? 'white' : 'dark ink') . ' so it reads clearly on the ' . (self::luminance($bg) < 0.4 ? 'dark' : 'light') . ' header';
            $charge = true;
        } else {
            $missed[] = 'The logo colour could not be written to this site.';
        }
        return compact('did', 'missed', 'charge');
    }

    /** The colour the header paints behind the logo, resolved through the site's own :root variables. */
    private function headerBackground(int $websiteId, string $home): string
    {
        $vars = self::siteRootVars($websiteId);
        $resolve = function (string $val) use ($vars): ?string {
            $val = trim($val);
            for ($i = 0; $i < 4 && preg_match('/var\(\s*(--[a-z0-9-]+)\s*(?:,\s*([^)]+))?\)/i', $val, $m); $i++) {
                $val = trim((string) ($vars[strtolower($m[1])] ?? ($m[2] ?? '')));
                if ($val === '') { return null; }
            }
            if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val)) { return strtoupper($val); }
            if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $val, $c)) { return sprintf('#%02X%02X%02X', (int) $c[1], (int) $c[2], (int) $c[3]); }
            if (preg_match('/^(white|#fff)\b/i', $val)) { return '#FFFFFF'; }
            if (preg_match('/^black\b/i', $val)) { return '#000000'; }
            if (stripos($val, 'transparent') !== false) { return '#1A1A1A'; }   // header over the hero photo: dark
            return null;
        };
        foreach (['\.nav-bar', 'nav\[data-block="?nav"?\]', '#main-nav', '\.navbar', '\.site-header', '\.topbar', '\.nav-wrap', '\.nav', 'header'] as $sel) {
            if (! preg_match_all('/(?:^|[},\s])' . $sel . '\s*\{([^}]*)\}/i', $home, $mm)) { continue; }
            foreach ($mm[1] as $decls) {
                if (preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $decls, $b)) {
                    $hex = $resolve($b[1]);
                    if ($hex !== null) { return $hex; }
                }
            }
        }
        foreach (['--paper', '--bg', '--surface', '--cf-bg', '--background'] as $v) {
            if (isset($vars[$v]) && ($hex = $resolve($vars[$v])) !== null) { return $hex; }
        }
        return '#FFFFFF';
    }

    /** The design's fact fields (phone, email, WhatsApp, address, hours, licence …) as data-field keys in its template. */
    private function templateFactKeys(int $websiteId, object $site): array
    {
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $design = $this->siteDesignSlug($site, $settings);
        if ($design === '') { return []; }
        $tpl = (string) @file_get_contents(storage_path("templates/{$design}/template.html"));
        if ($tpl === '' || ! preg_match_all('/data-field="([a-z0-9_]+)"/i', $tpl, $m)) { return []; }
        $keys = [];
        foreach (array_unique($m[1]) as $k) {
            if (preg_match('/(phone|email|whatsapp|address|hours|licen[cs]e|registration|fax|mobile)/i', $k) && ! preg_match('/(_label|_title|_display|_icon|_link)$/i', $k)) { $keys[] = $k; }
        }
        return $keys;
    }

    /**
     * A fact line the build stripped (empty at the time) comes back from the template markup: the template line that
     * carries the field is rendered with the site's variables and put back next to a neighbouring line that is still on
     * the page. Home page only; sub-pages pick it up from the chrome the next time they are written.
     */
    private function reinsertTemplateField(int $websiteId, object $site, string $key, string $value, array $tv): bool
    {
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $design = $this->siteDesignSlug($site, $settings);
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        if ($design === '' || ! is_file($index)) { return false; }
        $lines = preg_split('/\r?\n/', (string) @file_get_contents(storage_path("templates/{$design}/template.html"))) ?: [];
        $html = (string) file_get_contents($index);
        $vars = $tv; $vars[$key] = $value;
        $render = function (string $line) use ($vars): string {
            return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn($m) => e((string) ($vars[$m[1]] ?? '')), $line) ?? $line;
        };
        $done = 0;
        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'data-field="' . $key . '"')) { continue; }
            $rendered = $render($line);
            $placed = false;
            // a neighbour that survived: next lines first (insert before), then previous lines (insert after)
            foreach ([1, 2, 3, 4, -1, -2, -3, -4] as $d) {
                $j = $i + $d;
                if (! isset($lines[$j])) { continue; }
                $anchor = trim($render($lines[$j]));
                if (strlen($anchor) < 12 || ! str_contains($anchor, '<')) { continue; }
                $pos = strpos($html, $anchor);
                if ($pos === false) { continue; }
                // this copy of the line is already next to its neighbour (the field may legitimately appear twice on the page)
                $window = substr($html, max(0, $pos - 700), 700 + strlen($anchor) + 700);
                if (str_contains($window, 'data-field="' . $key . '"')) { $placed = true; break; }
                $html = $d > 0
                    ? substr($html, 0, $pos) . $rendered . "\n" . substr($html, $pos)
                    : substr($html, 0, $pos + strlen($anchor)) . "\n" . $rendered . substr($html, $pos + strlen($anchor));
                $placed = true; break;
            }
            if ($placed) { $done++; }
        }
        if ($done > 0) { file_put_contents($index, $html); Log::info('[Arthur] fact line re-inserted', ['website' => $websiteId, 'field' => $key, 'lines' => $done]); }
        return $done > 0;
    }

    /** A phone or e-mail link dials / mails the NEW value: tel:/mailto: hrefs on the field's own <a> follow the text. */
    private function patchFactHref(int $websiteId, string $key, string $value): void
    {
        $isPhone = (bool) preg_match('/(phone|whatsapp|mobile|fax)/i', $key);
        $isMail  = (bool) preg_match('/email/i', $key);
        if (! $isPhone && ! $isMail) { return; }
        $href = $isPhone ? 'tel:' . preg_replace('/[^\d+]/', '', $value) : 'mailto:' . trim($value);
        $root = storage_path("app/public/sites/{$websiteId}");
        $files = [$root . '/index.html'];
        foreach ((glob("{$root}/*/index.html") ?: []) as $f) { if (! str_contains($f, '/.history/')) { $files[] = $f; } }
        foreach ($files as $f) {
            $h = (string) @file_get_contents($f);
            if ($h === '' || ! str_contains($h, 'data-field="' . $key . '"')) { continue; }
            $new = preg_replace('/(<a\b[^>]*\bhref=")(?:tel|mailto):[^"]*("[^>]*\bdata-field="' . preg_quote($key, '/') . '")/i', '$1' . $href . '$2', $h) ?? $h;
            if ($new !== $h) { file_put_contents($f, $new); }
        }
    }

    /** Size changes as a remembered zoom factor per target, written into the customer's design-extras block. */
    private function applySizeChange(int $websiteId, string $request, array &$tv): ?string
    {
        $r = mb_strtolower($request);
        $up = (bool) preg_match('/\b(bigger|larger|huge|enlarge|increase|more prominent)\b/', $r);
        $step = preg_match('/\b(huge|much bigger|much larger|a lot bigger|way bigger|much smaller|a lot smaller|tiny)\b/', $r) ? 1.3 : 1.15;
        // SELECTION888: 'make it bigger' with an element selected in the editor sizes that element alone
        if ($this->selTarget !== null && ($this->selTarget['field'] ?? '') !== '') {   // a targeted element is sized on its own
            $sf = (string) $this->selTarget['field']; $key = 'size_field_' . $sf;
            $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
            $current = 1.0;
            if (isset($extras[$key]) && preg_match('/zoom:([\d.]+)/', (string) $extras[$key], $zm)) { $current = (float) $zm[1]; }
            $factor = max(0.6, min(1.8, round($current * ($up ? $step : 1 / $step), 3)));
            if (! self::writeDesignExtras($websiteId, [$key => '[data-field="' . $sf . '"]{zoom:' . $factor . '}'], $tv)) { return null; }
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            return 'made the selected text' . (($this->selTarget['text'] ?? '') !== '' ? ' ("' . mb_substr((string) $this->selTarget['text'], 0, 40) . '")' : '') . ($up ? ' bigger' : ' smaller') . ' (now ' . (int) round($factor * 100) . '% of the design size)';
        }
        $targets = [
            'headline' => ['/\b(headline|title|heading|h1)\b/', '.hero h1,[data-block="hero"] h1,.hero .hero-title,.hero-h1,.hero .hero-name', 'the headline'],
            'hero'     => ['/\bhero\b/', '.hero h1,[data-block="hero"] h1,.hero .hero-title,.hero .lede,.hero .hero-sub,.hero .hero-subtitle,[data-block="hero"] p', 'the hero text'],
            'buttons'  => ['/\b(button|buttons|cta)\b/', '.btn,.btn-primary,.nav-cta,.hero-cta,button[type=submit],a[class*="btn"]', 'the buttons'],
            'nav'      => ['/\b(nav|menu|navigation)\b/', '.nav-links a,.nav-link,nav a', 'the menu text'],
            'logo'     => ['/\blogo\b/', '.logo,.brand-text,[data-field="logo"]', 'the logo'],
            'headings' => ['/\b(headings|section titles|titles)\b/', 'h2.section-title,section h2,[data-block] h2', 'the section headings'],
            'text'     => ['/\b(text|font|fonts|copy|paragraph|paragraphs|lettering|type)\b/', 'main p,section p,section li,.lede,section dd', 'the body text'],
        ];
        $picked = null;
        foreach (['hero', 'headline', 'buttons', 'nav', 'logo', 'headings', 'text'] as $k) { if (preg_match($targets[$k][0], $r)) { $picked = $k; break; } }
        if ($picked === 'hero' && preg_match('/\b(headline|title|heading)\b/', $r)) { $picked = 'headline'; }
        if ($picked === null) { $picked = 'text'; }
        [, $selector, $label] = $targets[$picked];
        $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
        $current = 1.0;
        if (isset($extras['size_' . $picked]) && preg_match('/zoom:([\d.]+)/', (string) $extras['size_' . $picked], $zm)) { $current = (float) $zm[1]; }
        $factor = max(0.6, min(1.8, round($current * ($up ? $step : 1 / $step), 3)));
        $rule = ['size_' . $picked => $selector . '{zoom:' . $factor . '}'];
        if (! self::writeDesignExtras($websiteId, $rule, $tv)) { return null; }
        DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
        return 'made ' . $label . ($up ? ' bigger' : ' smaller') . ' (now ' . (int) round($factor * 100) . '% of the design size)';
    }

    /** Which part of the page a colour request names, in which colour, and whether it means the background or the text.
     *  COLOUR SCOPE (2026-09-15): resolution order is a quoted element → a section the page has (with an optional part
     *  inside it: its text, its title, its buttons) → footer/header/hero → site-wide parts → the page. Shade words
     *  ("light blue", "dark green", "pale grey") tint or darken the named colour. */
    private function colourTargetIn(string $request, int $websiteId): ?array
    {
        $r = mb_strtolower($request);
        $hex = null; $word = '';
        if (preg_match('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $request, $hm)) { $hex = self::styleHex($hm[0]); $word = strtoupper($hm[0]); }
        else {
            $names = implode('|', array_map('preg_quote', array_keys(self::COLOR_MAP)));
            if (preg_match('/\b(?:(light|lighter|pale|soft|bright|dark|darker|deep|rich)\s+)?(' . $names . ')\b/i', $r, $cm)) {
                $hex = self::styleHex($cm[2]); $word = trim($cm[1] . ' ' . $cm[2]);
                $hex = self::shadeHex($hex, $cm[1]);
            }
        }
        if ($hex === null) return null;
        $textWords = '/\b(text|texts|font|fonts|lettering|wording|letters|words|title|titles|heading|headings|headline|subheading|subheadings|h1|h2|h3|paragraph|paragraphs|copy)\b/';
        $titleWords = '/\b(title|titles|heading|headings|headline|subheading|subheadings|h1|h2|h3)\b/';
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        $partsOf = fn (string $b) => str_replace(['_', '-'], ' ', $b);

        // SELECTION888: the element or section the customer clicked in the editor, when the request points at it (or the model targeted it)
        if ($this->selTarget !== null) {
            $sf = (string) ($this->selTarget['field'] ?? ''); $sb = (string) ($this->selTarget['block'] ?? '');
            if ($sf !== '' && preg_match('/<(a|button|h1|h2|h3|h4|h5|p|span|li|div|strong|em)\b[^>]*data-field="' . preg_quote($sf, '/') . '"/', $home, $sm)) {
                $isButton = $sm[1] === 'button' || ($sm[1] === 'a' && preg_match('/class="[^"]*\bbtn/i', $sm[0]));
                $mode = ($isButton && ! preg_match('/\b(text|lettering|letters|font|wording|words)\b/', $r)) ? 'background' : 'text';
                $what = 'the selected ' . ($isButton ? 'button' : 'text') . (($this->selTarget['text'] ?? '') !== '' ? ' ("' . mb_substr((string) $this->selTarget['text'], 0, 40) . '")' : '');
                return ['target' => 'element', 'block' => $sf, 'hex' => $hex, 'mode' => $mode, 'label' => 'made ' . $what . ($isButton && $mode === 'background' ? ' background' : '') . ' ' . $word, 'button' => $isButton];
            }
            if ($sb !== '' && in_array($sb, ['nav', 'hero', 'footer'], true)) {
                $mode = preg_match($textWords, $r) ? 'text' : 'background';
                return ['target' => $sb, 'block' => '', 'hex' => $hex, 'mode' => $mode, 'label' => 'made the ' . ['nav' => 'header', 'hero' => 'hero', 'footer' => 'footer'][$sb] . ($mode === 'text' ? ' text' : ' background') . ' ' . $word];
            }
            if ($sb !== '' && str_contains($home, 'data-block="' . $sb . '"')) {
                if (preg_match($textWords, $r)) return ['target' => 'scoped_text', 'block' => $sb, 'hex' => $hex, 'mode' => 'text', 'label' => 'made the text in the ' . $partsOf($sb) . ' section ' . $word];
                return ['target' => 'section', 'block' => $sb, 'hex' => $hex, 'mode' => 'background', 'label' => 'made the ' . $partsOf($sb) . ' section background ' . $word];
            }
        }

        // (a) a quoted element the page has: "the 'Ask about a property' button", "the title 'A Clear Path…'"
        if (preg_match_all('/["\x{201C}\x{201D}\x{2018}\x{2019}\']([^"\x{201C}\x{201D}\x{2018}\x{2019}\']{3,80})["\x{201C}\x{201D}\x{2018}\x{2019}\']/u', $request, $qm)) {
            foreach ($qm[1] as $phrase) {
                $needle = mb_strtolower(trim(preg_replace('/\s+/', ' ', $phrase)));
                if (preg_match_all('/<(a|button|h1|h2|h3|h4|p|span|li|div|strong)\b[^>]*data-field="([a-z0-9_\-]+)"[^>]*>(.*?)<\/\1>/su', $home, $em, PREG_SET_ORDER)) {
                    // best match wins: the exact text, else an element whose text contains the phrase, else (long phrases only)
                    // an element whose whole text sits inside the phrase; a request that says "button" prefers buttons.
                    $wantsButton = (bool) preg_match('/\b(button|cta)\b/', $r); $best = null; $bestScore = 0;
                    foreach ($em as $e) {
                        $txt = mb_strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($e[3])))));
                        if ($txt === '' || mb_strlen($txt) < 3) continue;
                        $score = $txt === $needle ? 30 : (str_contains($txt, $needle) && mb_strlen($needle) >= 6 ? 20 : (mb_strlen($txt) >= 12 && str_contains($needle, $txt) ? 10 : 0));
                        if ($score === 0) continue;
                        $btn = $e[1] === 'button' || ($e[1] === 'a' && preg_match('/class="[^"]*\bbtn/i', $e[0]));
                        if ($wantsButton === $btn) $score += 5;
                        if ($score > $bestScore) { $bestScore = $score; $best = $e; }
                    }
                    if ($best !== null) {
                        $e = $best;
                        {
                            $isButton = $e[1] === 'button' || ($e[1] === 'a' && preg_match('/class="[^"]*\bbtn/i', $e[0]));
                            $mode = ($isButton && ! preg_match('/\b(text|lettering|letters|font|wording|words)\b/', $r)) ? 'background' : 'text';
                            $what = ($isButton ? 'the "' : 'the "') . trim($phrase) . '"' . ($isButton ? ' button' : ($e[1] === 'a' ? ' link' : ' text'));
                            return ['target' => 'element', 'block' => $e[2], 'hex' => $hex, 'mode' => $mode, 'label' => 'made ' . $what . ($isButton && $mode === 'background' ? ' background' : '') . ' ' . $word, 'button' => $isButton];
                        }
                    }
                }
            }
        }

        // (b) the structural parts and the sections the page has — a container the request names
        $container = null; $block = '';
        if (preg_match('/\bfooter\b/', $r)) $container = 'footer';
        elseif (preg_match('/\b(header|nav|navigation|navbar|menu bar|top bar)\b/', $r) && ! preg_match('/\bsection header\b/', $r)) $container = 'nav';
        elseif (preg_match('/\b(hero|banner|cover)\b/', $r)) $container = 'hero';
        if (preg_match_all('/data-block="([a-z_\-]+)"/', $home, $bm)) {
            foreach (array_unique($bm[1]) as $b) {
                if (in_array($b, ['nav', 'hero', 'footer'], true)) continue;
                $name = $partsOf($b);
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $r) || preg_match('/\b' . preg_quote(rtrim($name, 's'), '/') . '\b/', $r)) { $container = 'section'; $block = $b; break; }
            }
        }

        // (c) the part inside (or without) a container
        $part = null;
        foreach (['buttons' => '/\b(button|buttons|cta|call to action)\b/', 'logo' => '/\blogo\b/', 'headline' => '/\b(headline|main title|hero title|big title|h1)\b/', 'headings' => '/\b(headings|section titles|titles|subheadings|title|heading)\b/', 'text' => '/\b(body text|paragraphs|paragraph|the text|text|copy|font colour|font color|text colour|text color|font|lettering|wording|words)\b/'] as $t => $re) {
            if (preg_match($re, $r)) { $part = $t; break; }
        }
        if ($container !== null && $part !== null && $part !== 'logo') {
            $key = $container === 'section' ? $block : $container;
            $where = $container === 'section' ? 'the ' . $partsOf($block) . ' section' : ['footer' => 'the footer', 'nav' => 'the header', 'hero' => 'the hero'][$container];
            if ($part === 'buttons') {
                $mode = preg_match('/\b(text|lettering|letters|font|wording)\b/', $r) ? 'text' : 'background';
                return ['target' => 'scoped_buttons', 'block' => $key, 'hex' => $hex, 'mode' => $mode, 'label' => 'made the buttons in ' . $where . ($mode === 'background' ? ' background' : ' text') . ' ' . $word];
            }
            if ($part === 'headline' || $part === 'headings' || preg_match($titleWords, $r)) {
                return ['target' => 'scoped_headings', 'block' => $key, 'hex' => $hex, 'mode' => 'text', 'label' => 'made the title in ' . $where . ' ' . $word];
            }
            return ['target' => 'scoped_text', 'block' => $key, 'hex' => $hex, 'mode' => 'text', 'label' => 'made the text in ' . $where . ' ' . $word];
        }
        if ($container !== null) {
            $target = $container;
            $mode = preg_match($textWords, $r) ? 'text' : 'background';
        } elseif ($part !== null) {
            $target = $part;
            $mode = in_array($target, ['logo', 'headline', 'headings', 'text'], true) ? 'text' : (preg_match('/\b(text|lettering|letters|font|wording)\b/', $r) ? 'text' : 'background');
        } elseif (preg_match('/\b(page background|site background|whole page|whole site|entire site|entire page|background of the (?:page|site)|the background|background colou?r)\b/', $r)) {
            $target = 'page'; $mode = 'background';
        } else {
            return null;
        }
        $what = $target === 'section' ? 'the ' . $partsOf($block) . ' section' : ['footer' => 'the footer', 'nav' => 'the header', 'hero' => 'the hero', 'buttons' => 'the buttons', 'logo' => 'the logo', 'headline' => 'the headline', 'headings' => 'the section headings', 'text' => 'the body text', 'page' => 'the page background'][$target];
        $label = 'made ' . $what . ($mode === 'text' && ! in_array($target, ['logo', 'headline', 'headings', 'text'], true) ? ' text' : ($mode === 'background' && in_array($target, ['footer', 'nav', 'hero', 'section'], true) ? ' background' : '')) . ' ' . $word;
        return ['target' => $target, 'block' => $block, 'hex' => $hex, 'mode' => $mode, 'label' => $label];
    }

    /** "light blue" is a tint of blue, "dark green" a shade of green — mixed towards white or black, never the base colour. */
    private static function shadeHex(?string $hex, string $shade): ?string
    {
        if ($hex === null || $shade === '') return $hex;
        $mix = ['light' => ['#FFFFFF', 0.55], 'lighter' => ['#FFFFFF', 0.55], 'pale' => ['#FFFFFF', 0.72], 'soft' => ['#FFFFFF', 0.62], 'bright' => [null, 0], 'dark' => ['#000000', 0.38], 'darker' => ['#000000', 0.38], 'deep' => ['#000000', 0.45], 'rich' => ['#000000', 0.2]][strtolower($shade)] ?? [null, 0];
        if ($mix[0] === null || $mix[1] <= 0) return $hex;
        $c = sscanf($hex, '#%02x%02x%02x'); $t = sscanf($mix[0], '#%02x%02x%02x');
        return sprintf('#%02X%02X%02X', (int) round($c[0] + ($t[0] - $c[0]) * $mix[1]), (int) round($c[1] + ($t[1] - $c[1]) * $mix[1]), (int) round($c[2] + ($t[2] - $c[2]) * $mix[1]));
    }
    /** The part of the page a request names (no colour needed): footer, header, hero, buttons, page, or a section the page has. */
    private function partIn(string $request, int $websiteId): ?array
    {
        $r = mb_strtolower($request);
        $targets = ['footer' => '/\bfooter\b/', 'nav' => '/\b(header|nav|navigation|navbar|menu bar|top bar)\b/', 'hero' => '/\b(hero|banner|cover)\b/', 'buttons' => '/\b(button|buttons|cta)\b/', 'page' => '/\b(page background|site background|whole page|whole site|the background|the page)\b/'];
        foreach ($targets as $t => $re) { if (preg_match($re, $r)) { return ['target' => $t, 'block' => '', 'what' => ['footer' => 'the footer', 'nav' => 'the header', 'hero' => 'the hero', 'buttons' => 'the buttons', 'page' => 'the page background'][$t]]; } }
        $home = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if (preg_match_all('/data-block="([a-z_\-]+)"/', $home, $bm)) {
            foreach (array_unique($bm[1]) as $b) {
                if (in_array($b, ['nav', 'hero', 'footer'], true)) continue;
                $name = str_replace(['_', '-'], ' ', $b);
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $r) || preg_match('/\b' . preg_quote(rtrim($name, 's'), '/') . '\b/', $r)) { return ['target' => 'section', 'block' => $b, 'what' => 'the ' . $name . ' section']; }
            }
        }
        return null;
    }

    /** The background a part is painted with today, read from the page's CSS and its :root variables (null when it sits on a photo or cannot be read). */
    private function partBackground(int $websiteId, string $home, string $target, string $block = ''): ?string
    {
        $vars = self::siteRootVars($websiteId);
        $resolve = function (string $val) use ($vars): ?string {
            $val = trim($val);
            for ($i = 0; $i < 4 && preg_match('/var\(\s*(--[a-z0-9-]+)\s*(?:,\s*([^)]+))?\)/i', $val, $m); $i++) { $val = trim((string) ($vars[strtolower($m[1])] ?? ($m[2] ?? ''))); if ($val === '') return null; }
            if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val)) return strtoupper(strlen($val) === 4 ? '#' . $val[1] . $val[1] . $val[2] . $val[2] . $val[3] . $val[3] : $val);
            if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $val, $c)) return sprintf('#%02X%02X%02X', (int) $c[1], (int) $c[2], (int) $c[3]);
            if (preg_match('/^white\b/i', $val)) return '#FFFFFF';
            if (preg_match('/^black\b/i', $val)) return '#000000';
            return null;
        };
        // the customer's own earlier rule wins (design extras are the last word on the page)
        $tv = json_decode((string) DB::table('websites')->where('id', $websiteId)->value('template_variables'), true) ?: [];
        $key = 'colour_' . ($target === 'section' ? 'section_' . $block : $target);
        if (! empty($tv['design_extras'][$key]) && preg_match('/background:(#[0-9A-Fa-f]{6})/', (string) $tv['design_extras'][$key], $km)) return strtoupper($km[1]);
        $sels = ['footer' => ['footer', '\.footer', '\[data-block="footer"\]'], 'nav' => ['\.nav-bar', 'nav\[data-block="?nav"?\]', '#main-nav', '\.navbar', '\.site-header', '\.nav', 'header'], 'buttons' => ['\.btn-primary', '\.hero-cta', '\.btn\.primary', '\.nav-cta', '\.btn'], 'page' => ['body'], 'section' => ['\[data-block="' . preg_quote($block, '/') . '"\]', '\.' . preg_quote($block, '/'), 'section\.' . preg_quote($block, '/')]][$target] ?? [];
        foreach ($sels as $sel) {
            if (! preg_match_all('/(?:^|[},\s])' . $sel . '\s*\{([^}]*)\}/i', $home, $mm)) continue;
            foreach ($mm[1] as $decls) {
                if (preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $decls, $b)) { if (stripos($b[1], 'url(') !== false) return null; $hex = $resolve($b[1]); if ($hex !== null) return $hex; }
            }
        }
        if ($target === 'page') { foreach (['--paper', '--bg', '--surface', '--background'] as $v) { if (isset($vars[$v]) && ($hex = $resolve($vars[$v])) !== null) return $hex; } return '#FFFFFF'; }
        if ($target === 'section') { return $this->partBackground($websiteId, $home, 'page'); }
        return null;
    }

    /** A translucent overlay on one part only — for parts on photos, where no colour can be shifted. */
    private static function overlayRules(string $target, int $dir, string $block = ''): array
    {
        $sel = ['footer' => 'footer,.footer,[data-block="footer"]', 'nav' => 'nav,.nav,.navbar,.nav-bar,header,[data-block="nav"]', 'hero' => '.hero,[data-block="hero"],header.hero,section.hero', 'buttons' => '.btn-primary,.hero-cta,.nav-cta,.btn.primary,.btn-cta,.cta-btn,button[type=submit],a[class*="btn"],.btn', 'page' => 'body', 'section' => '[data-block="' . $block . '"]'][$target] ?? null;
        if ($sel === null) return [];
        $veil = $dir < 0 ? 'rgba(0,0,0,.28)' : 'rgba(255,255,255,.22)';
        $key = 'tone_' . ($target === 'section' ? 'section_' . $block : $target);
        return [$key => $sel . '{box-shadow:inset 0 0 0 100vmax ' . $veil . '!important}'];
    }

    /** One replaceable rule per part; readable text is set on a recoloured background so nothing vanishes. */
    private static function targetedColourRules(string $target, string $hex, string $mode, string $block = ''): array
    {
        $ink = self::readableOn($hex);
        $sel = [
            'footer'   => ['footer,.footer,[data-block="footer"]', 'footer,.footer,[data-block="footer"],footer a,.footer a,footer p,footer li,footer h3,footer h4,footer span,[data-block="footer"] a,[data-block="footer"] p,[data-block="footer"] h4'],
            'nav'      => ['nav,.nav,.navbar,.nav-bar,header:not(.hero):not([data-block="hero"]),[data-block="nav"]', 'nav a,.nav a,.navbar a,.nav-bar a,.nav-links a,.nav-link,nav .logo,.nav .logo,.brand-text,[data-block="nav"] a'],
            'hero'     => ['.hero,[data-block="hero"],header.hero,section.hero', '.hero h1,.hero .hero-title,.hero .hero-name,[data-block="hero"] h1,.hero .lede,.hero .hero-sub,.hero .hero-subtitle,.hero .eyebrow,.hero .hero-eyebrow,.hero p,[data-block="hero"] p'],
            'buttons'  => ['.btn-primary,.hero-cta,.nav-cta,.btn.primary,.btn-cta,.cta-btn,button[type=submit],a[class*="btn"],.btn', '.btn-primary,.hero-cta,.nav-cta,.btn.primary,.btn-cta,.cta-btn,button[type=submit],a[class*="btn"],.btn'],
            'logo'     => ['', '.logo,.logo *,.brand-text,[data-field="logo"]'],
            'headline' => ['', '.hero h1,[data-block="hero"] h1,.hero .hero-title,.hero .hero-name,.hero-h1'],
            'headings' => ['', 'h2.section-title,section h2,[data-block] h2'],
            'text'     => ['', 'main p,section p,section li,.lede,section dd'],
            'page'     => ['body', 'body'],
            'section'  => ['[data-block="' . $block . '"]', '[data-block="' . $block . '"] h2,[data-block="' . $block . '"] h3,[data-block="' . $block . '"] p,[data-block="' . $block . '"] li,[data-block="' . $block . '"] span,[data-block="' . $block . '"] .eyebrow,[data-block="' . $block . '"] .lede'],
        ][$target] ?? null;
        // COLOUR SCOPE (2026-09-15): one element, or one part inside one section / the footer / the header / the hero.
        if ($target === 'element') {
            // :not(.lu-x) lifts specificity above the section rule's own readable-ink line, whatever order the rules were written in
            $s = '[data-field="' . $block . '"]:not(.lu-x)';
            if ($mode === 'text') return ['colour_field_' . $block => $s . ',' . $s . ' *{color:' . $hex . '!important}'];
            return ['colour_field_' . $block => $s . '{background:' . $hex . '!important;background-image:none!important;border-color:' . $hex . '!important;color:' . $ink . '!important}'];
        }
        if (in_array($target, ['scoped_buttons', 'scoped_headings', 'scoped_text'], true)) {
            $scope = in_array($block, ['footer', 'nav', 'hero'], true) ? ['footer' => 'footer,.footer,[data-block="footer"]', 'nav' => 'nav,.nav,.navbar,.nav-bar,[data-block="nav"]', 'hero' => '.hero,[data-block="hero"],header.hero,section.hero'][$block] : '[data-block="' . $block . '"]';
            $inner = ['scoped_buttons' => '.btn,a[class*="btn"],button,.btn-primary,.hero-cta,.nav-cta,.cta-btn', 'scoped_headings' => 'h1,h2,h3,.section-title,.hero-title,.eyebrow', 'scoped_text' => 'h1,h2,h3,h4,p,li,span,dd,dt,.lede,.eyebrow,a:not([class*="btn"])'][$target];
            $sels = [];
            foreach (explode(',', $scope) as $sc) { foreach (explode(',', $inner) as $in) { $sels[] = trim($sc) . ' ' . trim($in) . ':not(.lu-x)'; } }
            $s = implode(',', $sels);
            $key = 'colour_' . str_replace('scoped_', '', $target) . '_' . $block;
            if ($target === 'scoped_buttons' && $mode === 'background') return [$key => $s . '{background:' . $hex . '!important;background-image:none!important;border-color:' . $hex . '!important;color:' . $ink . '!important}'];
            return [$key => $s . '{color:' . $hex . '!important}'];
        }
        if ($sel === null) return [];
        [$bgSel, $textSel] = $sel;
        $key = 'colour_' . ($target === 'section' ? 'section_' . $block : $target);
        if ($mode === 'text' || $bgSel === '') {
            return [$key => $textSel . '{color:' . $hex . '!important}'];
        }
        $css = $bgSel . '{background:' . $hex . '!important;background-image:none!important;border-color:' . $hex . '!important}';
        // a light page background keeps the design's own dark text; only a dark page needs its text lifted to white
        if ($target === 'page' && $ink !== '#FFFFFF') return [$key => $css];
        if ($target === 'hero') $css .= ' .hero::before,.hero::after,[data-block="hero"]::before,[data-block="hero"]::after{background:none!important;background-image:none!important}';
        $css .= ' ' . $textSel . '{color:' . $ink . '!important}';
        if ($target === 'buttons') $css = $bgSel . '{background:' . $hex . '!important;background-image:none!important;border-color:' . $hex . '!important;color:' . $ink . '!important}';
        return [$key => $css];
    }

    private function applySiteStyle(int $wsId, int $websiteId, string $request, object $site, array $tv, array $plan, bool $isStatic): array
    {
        $editor  = app(ArthurEditService::class);
        $credits = app(\App\Core\Billing\CreditService::class);
        $did     = [];
        $missed  = [];

        // ── 0a. LOGO VISIBILITY / CONTRAST (2026-09-14, Owner: "fix logo contrast" on Raymundo Realty) ──
        // A blanked text logo comes back free (it was our placeholder, not the customer's choice); a legible
        // colour for a visible text logo is a design change at the style price.
        if ($isStatic && preg_match(\App\Engines\Builder\Support\BuilderCapabilities::LOGO_VISIBILITY, $request)) {
            $fix = $this->fixLogoVisibility($websiteId, $site, $tv);
            if ($fix['did'] !== []) {
                $cost = $fix['charge'] ? (int) $plan['credits'] : 0;
                if ($cost > 0) { $credits->debit($wsId, $cost, 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => $fix['did']]); }
                Log::info('[Arthur] logo visibility', ['website' => $websiteId, 'changes' => $fix['did'], 'credits' => $cost]);
                return ['success' => true, 'kind' => 'style', 'plan' => $plan, 'applied' => count($fix['did']), 'actions_applied' => count($fix['did']), 'credits' => $cost,
                    'message' => 'Done — I ' . self::joinList($fix['did']) . " on {$site->name}." . ($cost > 0 ? " {$cost} credit." : ' No charge.'),
                    'url' => "/storage/sites/{$websiteId}/index.html"];
            }
            if ($fix['missed'] !== []) {
                return ['success' => false, 'code' => 'LOGO_NO_CHANGE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0, 'message' => implode(' ', $fix['missed'])];
            }
        }

        // ── 0b. SIZE (2026-09-14, "make the hero text bigger") — one replaceable rule per target, factor remembered ──
        if ($isStatic && preg_match(\App\Engines\Builder\Support\BuilderCapabilities::STYLE_SIZE, $request)) {
            $sz = $this->applySizeChange($websiteId, $request, $tv);
            if ($sz !== null) {
                $credits->debit($wsId, (int) $plan['credits'], 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$sz]]);
                Log::info('[Arthur] size change', ['website' => $websiteId, 'change' => $sz]);
                return ['success' => true, 'kind' => 'style', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $plan['credits'],
                    'message' => "Done — I {$sz} on {$site->name}. {$plan['credits']} credit.", 'url' => "/storage/sites/{$websiteId}/index.html"];
            }
        }

        // ── 0c. A NAMED PART IN A NAMED COLOUR (2026-09-14): "make the footer background blue", "header white", "buttons green",
        //        "hero text white", "listings section navy" — a rule for that part, not a repaint of the whole palette.
        if ($isStatic && $this->parseGradientAsk($request, $websiteId) === null
            && ! preg_match('/\b(luxur\w+|elegant|premium|upscale|sophisticated|minimal\w*|modern|contemporary|bold|sleek|classic|traditional|timeless|playful|fun|vibrant|colou?rful|look|feel|mood|vibe|style)\b/i', $request)) {
            $tc = $this->colourTargetIn($request, $websiteId);
            if ($tc !== null) {
                $rules = self::targetedColourRules($tc['target'], $tc['hex'], $tc['mode'], $tc['block']);
                if ($rules !== [] && self::writeDesignExtras($websiteId, $rules, $tv)) {
                    DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
                    $credits->debit($wsId, (int) $plan['credits'], 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$tc['label']]]);
                    Log::info('[Arthur] targeted colour', ['website' => $websiteId, 'target' => $tc['target'], 'mode' => $tc['mode'], 'hex' => $tc['hex']]);
                    return ['success' => true, 'kind' => 'style', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $plan['credits'],
                        'message' => "Done — I {$tc['label']} on {$site->name}. {$plan['credits']} credit. Undo puts it back.", 'url' => "/storage/sites/{$websiteId}/index.html"];
                }
            }
        }

        // ── 0. LITERAL GRADIENT (DEC-0046, 2026-09-13) ──────────────────────────────
        // "make the hero a gradient from deep green to gold": the customer named the stops, so paint exactly
        // that, in its own replaceable block, and say so. The mood treatment below still handles a bare
        // "add gradients" with no colours. Answering a gradient ask with a recolour was the defect (EV-1010).
        $skipColours = false;
        $grad = $this->parseGradientAsk($request, $websiteId);
        if ($grad !== null && $grad['from'] !== null && $grad['to'] !== null) {
            if ($isStatic) {
                $rules = self::gradientRules($grad['target'], $grad['from'], $grad['to']);
                if (self::writeDesignExtras($websiteId, $rules, $tv)) {
                    DB::table('websites')->where('id', $websiteId)->update([
                        'template_variables' => json_encode($tv), 'updated_at' => now(),
                    ]);
                    $where = $grad['target'] === 'page' ? 'page background' : (str_starts_with($grad['target'], 'section:') ? str_replace(['_', '-'], ' ', substr($grad['target'], 8)) . ' section' : $grad['target']);
                    $did[] = "painted the {$where} with a gradient from {$grad['from']} to {$grad['to']}";
                    $skipColours = true;
                } else {
                    $missed[] = 'the gradient could not be written to this site';
                }
            } else {
                $missed[] = 'this site is rendered live, so gradients are set in its design settings';
            }
        }

        // ── 1. COLOURS ───────────────────────────────────────────────────────────────────────
        $roles = [];
        foreach ($this->scanColorsServerSide($request) as $role => $val) {
            $hex = self::styleHex((string) $val);
            if ($hex !== null) { $roles[$role] = $hex; }
        }
        if ($roles !== [] && ! $skipColours) {
            $args = $isStatic ? self::mapRolesToSiteVars($websiteId, $roles) : $roles;
            if ($args === []) {
                $missed[] = 'I could not find a colour variable on this site to change';
            } else {
                $res = $editor->applyStyleColors($websiteId, $args);
                if ((int) ($res['applied'] ?? 0) > 0) {
                    // Write through to the stored variables too. The file alone is not enough: the
                    // design-style layer re-declares the palette from these, and so does any rebuild,
                    // so a file-only recolour is undone by the very next restyle.
                    $tvKey = ['primary' => 'primary_color', 'secondary' => 'secondary_color', 'accent' => 'accent_color'];
                    foreach ($roles as $role => $hex) {
                        if (isset($tvKey[$role])) { $tv[$tvKey[$role]] = $hex; }
                    }
                    DB::table('websites')->where('id', $websiteId)->update([
                        'template_variables' => json_encode($tv), 'updated_at' => now(),
                    ]);
                    if ($isStatic) { self::writeContrastGuard($websiteId, $args); }
                    $hit = 0;
                    foreach ($args as $vn => $_v) {
                        if (strncmp($vn, '--cf', 4) === 0) { continue; }
                        // Companion shades moved with their parent; they are not separate colours.
                        if (preg_match('/(-deep|-dark|-strong|-soft|-light|-tint|t)$/', $vn)) { continue; }
                        $hit++;
                    }
                    $asked = count($roles);
                    $did[] = $hit <= 1 ? 'changed the main brand colour to ' . strtolower((string) array_values($this->scanColorsServerSide($request))[0]) . ' (it colours the buttons, links and highlights)' : 'updated ' . min($asked, $hit) . ' colours';
                    if ($hit > 0 && $asked > $hit) { $missed[] = 'this template only exposes ' . $hit . ' brand colour' . ($hit === 1 ? '' : 's') . ', so I applied the first'; }
                } else {
                    $missed[] = 'the colour did not match anything on the page';
                }
            }
        }

        // ── 1a. A NAMED PART, DARKER OR LIGHTER (2026-09-14): its own colour shifted, or an overlay on that part alone ──
        if ($roles === [] && $isStatic && preg_match(\App\Engines\Builder\Support\BuilderCapabilities::STYLE_TONES, $request, $tm0)) {
            $part = $this->partIn($request, $websiteId);
            if ($part !== null) {
                $dir0 = self::toneDirection(strtolower($tm0[1]));
                $home0 = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
                $cur = in_array($part['target'], ['hero'], true) ? null : $this->partBackground($websiteId, $home0, $part['target'], $part['block']);
                if ($cur !== null) { $rules = self::targetedColourRules($part['target'], self::shiftLightness($cur, $dir0), 'background', $part['block']); }
                else { $rules = self::overlayRules($part['target'], $dir0, $part['block']); }
                if ($rules !== [] && self::writeDesignExtras($websiteId, $rules, $tv)) {
                    DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
                    $credits->debit($wsId, (int) $plan['credits'], 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => ['made ' . $part['what'] . ' ' . strtolower($tm0[1])]]);
                    Log::info('[Arthur] targeted tone', ['website' => $websiteId, 'target' => $part['target'], 'dir' => $dir0, 'from' => $cur]);
                    return ['success' => true, 'kind' => 'style', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $plan['credits'],
                        'message' => 'Done — I made ' . $part['what'] . ' ' . strtolower($tm0[1]) . " on {$site->name}. {$plan['credits']} credit. Undo puts it back.", 'url' => "/storage/sites/{$websiteId}/index.html"];
                }
            }
        }

        // ── 1b. RELATIVE TONE ────────────────────────────────────────────────────────────────
        // "darker", "lighter", "softer" name no colour, so there is nothing to look up. Read what the
        // variable is NOW and shift its lightness, which keeps the site's own hue rather than
        // replacing it with a colour the customer never asked for.
        if ($roles === [] && $isStatic
            && preg_match(\App\Engines\Builder\Support\BuilderCapabilities::STYLE_TONES, $request, $tm)) {
            $dir = self::toneDirection(strtolower($tm[1]));
            $targets = self::toneTargets($websiteId, $request);
            $shift = [];
            foreach ($targets as $var => $curHex) {
                $shift[$var] = self::shiftLightness($curHex, $dir);
            }
            if ($shift !== []) {
                $res = $editor->applyStyleColors($websiteId, $shift);
                if ((int) ($res['applied'] ?? 0) > 0) {
                    $did[] = 'made ' . self::toneScopeLabel($request) . ' ' . strtolower($tm[1]);
                } else {
                    $missed[] = 'the tone change did not match anything on the page';
                }
            } else {
                $missed[] = 'I could not find which colour you meant to shift';
            }
        }

        // ── 2. MOOD / FONTS / GRADIENTS ──────────────────────────────────────────────────────
        // DesignStyle turns "make it more luxurious" or "add gradients" into a real CSS layer. It is
        // the same layer the site was built with, so this stays inside the template's design language.
        $ds    = \App\Engines\Builder\Support\DesignStyle::class;
        $style = $ds::normaliseStyle($request);
        $fonts = $ds::parseFonts($request);
        $wantsGradient = ! $skipColours && (bool) preg_match('/\bgradients?\b/i', $request);
        if ($style !== null || $fonts['display'] !== null || $fonts['body'] !== null || $wantsGradient) {
            $effStyle = $style ?: (string) ($tv['design_style'] ?? '') ?: ($wantsGradient ? 'modern' : null);
            // The live export is the truth about what colour this site is right now — it may have been
            // recoloured a moment ago, or in an earlier message. Falling back to stored variables only.
            $live = $isStatic ? self::siteColorVars($websiteId) : [];
            $layer = $ds::layer(
                $effStyle,
                $fonts['display'] ?? ($tv['font_display'] ?? null),
                $fonts['body'] ?? ($tv['font_body'] ?? null),
                // palette() takes its LEAD hue from 'accent' and derives the rest, so the customer's
                // own lead colour (--cf1) must go in that slot or the restyle discards it.
                [
                    'accent'    => $live['--cf1'] ?? ($tv['primary_color'] ?? null),
                    'secondary' => $live['--cf2'] ?? ($tv['secondary_color'] ?? null),
                    'primary'   => $live['--cf1'] ?? ($tv['primary_color'] ?? null),
                ]
            );
            if ($layer !== '' && $isStatic && self::writeDesignLayer($websiteId, $layer)) {
                if ($style !== null)          { $did[] = "restyled the site as {$style}"; }
                elseif ($wantsGradient)       { $did[] = 'applied a gradient treatment'; }
                if ($fonts['display'] || $fonts['body']) { $did[] = 'changed the typography'; }
                // Remember it, so a later rebuild does not silently undo what the customer asked for.
                $tv['design_style'] = $effStyle;
                if ($fonts['display']) { $tv['font_display'] = $fonts['display']; }
                if ($fonts['body'])    { $tv['font_body'] = $fonts['body']; }
                DB::table('websites')->where('id', $websiteId)->update([
                    'template_variables' => json_encode($tv), 'updated_at' => now(),
                ]);
            } elseif ($layer !== '' && ! $isStatic) {
                $missed[] = 'this site is rendered live, so its style is set in the design settings';
            }
        }

        if ($did === []) {
            $hint = $missed !== [] ? ' (' . implode('; ', $missed) . ')' : '';
            // Naming what this site DOES expose turns a dead end into something the customer can act on.
            $live = $isStatic ? self::siteColorVars($websiteId) : [];
            $offer = '';
            if ($live !== []) {
                $named = [];
                foreach (['--cf1' => 'main', '--cf2' => 'second', '--cf3' => 'third'] as $v => $label) {
                    if (isset($live[$v])) { $named[] = "the {$label} colour (now {$live[$v]})"; }
                }
                if ($named !== []) { $offer = ' On this site I can change ' . self::joinList($named) . '.'; }
            }
            return ['success' => false, 'code' => 'STYLE_NO_TARGET', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'credits' => 0,
                'message' => "I understood that as a design change but could not tell exactly what to alter{$hint}.{$offer} "
                    . 'Give me the part and the colour — for example "make the buttons #1E5CFF", or ask for a whole look like "make it more luxurious".'];
        }

        $credits->debit($wsId, $plan['credits'], 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => $did]);
        // The static export is served straight off disk, so there is no cache layer to clear here.
        Log::info('[Arthur] design change applied', ['website' => $websiteId, 'changes' => $did, 'missed' => $missed]);

        $note = $missed !== [] ? ' I could not do the rest: ' . implode('; ', $missed) . '.' : '';
        return ['success' => true, 'kind' => 'style', 'plan' => $plan, 'applied' => count($did), 'actions_applied' => count($did),
            'credits' => $plan['credits'],
            'message' => 'Done — I ' . self::joinList($did) . " on {$site->name}."
                . " {$plan['credits']} credit." . $note];
    }

    /**
     * Every CSS custom property declared in ANY :root block of a site's export, later declarations
     * winning exactly as the browser would resolve them. Reading only the first block was the bug:
     * the design-style layer's own :root sits ahead of the template's palette.
     *
     * @return array<string,string> lower-cased var name => raw value
     */
    private static function siteRootVars(int $websiteId): array
    {
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($index)) { return []; }
        $html = (string) @file_get_contents($index);
        if (! preg_match_all('/:root\s*\{([^}]*)\}/', $html, $blocks)) { return []; }
        $vars = [];
        foreach ($blocks[1] as $body) {
            if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+)/i', $body, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $p) { $vars[strtolower(trim($p[1]))] = trim($p[2]); }
            }
        }
        return $vars;
    }

    /** Just the ones holding a literal hex colour — the only ones we can safely rewrite. */
    private static function siteColorVars(int $websiteId): array
    {
        $out = [];
        foreach (self::siteRootVars($websiteId) as $k => $v) {
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($v))) { $out[$k] = strtoupper(trim($v)); }
        }
        return $out;
    }

    /** darker/softer/muted -> -1 (down), lighter/brighter -> +1 (up). */
    private static function toneDirection(string $word): int
    {
        return preg_match('/^(lighter|lighten|brighter|brighten|paler|washed out|softer|soften|muted)$/', $word) ? 1 : -1;
    }

    /**
     * Which :root variables the tone applies to. If the customer named a part of the page, aim at the
     * variables that part uses; otherwise shift the brand colours and leave text and paper alone.
     */
    private static function toneTargets(int $websiteId, string $request): array
    {
        $vars = self::siteColorVars($websiteId);
        if ($vars === []) { return []; }

        $r = mb_strtolower($request);
        $want = [];
        if (preg_match('/\b(background|backdrop|page|body|header|footer|nav|navigation|hero|banner|section)\b/', $r)) {
            $want = ['--paper', '--bg', '--background', '--surface', '--tint', '--deep', '--s1', '--s2'];
        } elseif (preg_match('/\b(button|buttons|link|links|cta|accent|brand)\b/', $r)) {
            $want = ['--accent', '--primary', '--brand', '--c1', '--cf1'];
        } elseif (preg_match('/\b(text|copy|heading|headings|font)\b/', $r)) {
            $want = ['--ink', '--text', '--body', '--t1', '--deep'];
        }
        if ($want === []) { $want = ['--accent', '--primary', '--brand', '--c1', '--cf1']; }

        $out = [];
        foreach ($want as $v) { if (isset($vars[$v])) { $out[$v] = $vars[$v]; } }
        return $out;
    }

    private static function toneScopeLabel(string $request): string
    {
        $r = mb_strtolower($request);
        if (preg_match('/\b(background|backdrop|page|body|header|footer|nav|hero|banner|section)\b/', $r, $m)) { return 'the ' . $m[1]; }
        if (preg_match('/\b(button|buttons|link|links|cta)\b/', $r, $m)) { return 'the ' . $m[1]; }
        if (preg_match('/\b(text|copy|heading|headings)\b/', $r, $m)) { return 'the ' . $m[1]; }
        return 'the brand colours';
    }

    /** Shift a hex colour's lightness by ~14% in $dir, staying inside the same hue. */
    private static function shiftLightness(string $hex, int $dir): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $h)) { return $hex; }
        $rgb = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        $out = '#';
        foreach ($rgb as $c) {
            $v = $dir > 0 ? $c + (255 - $c) * 0.28 : $c * 0.72;
            $out .= str_pad(dechex((int) max(0, min(255, round($v)))), 2, '0', STR_PAD_LEFT);
        }
        return strtoupper($out);
    }

    /**
     * Keep text readable on any background we just repainted.
     *
     * Scans the export's own CSS for rules that use a changed variable as a background, works out what
     * foreground those rules set, and overrides only the ones that fall below WCAG AA (4.5:1). Written
     * as one replaceable block, so this never accumulates.
     */
    private static function writeContrastGuard(int $websiteId, array $applied): bool
    {
        $root = storage_path("app/public/sites/{$websiteId}");
        $index = "{$root}/index.html";
        if (! is_file($index)) { return false; }
        $html = (string) @file_get_contents($index);

        // The variables we actually repainted, and what they are now.
        $vars = self::siteColorVars($websiteId);
        $rules = [];
        foreach (array_keys($applied) as $var) {
            $newHex = $vars[strtolower($var)] ?? null;
            if ($newHex === null) { continue; }
            $q = preg_quote($var, '/');
            // Rules whose declarations paint a background with this variable.
            if (! preg_match_all('/([^{}]+)\{([^{}]*background[^{}]*var\(\s*' . $q . '\s*\)[^{}]*)\}/i', $html, $mm, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($mm as $m) {
                $selector = trim(preg_replace('/\s+/', ' ', $m[1]));
                if ($selector === '' || str_contains($selector, '@')) { continue; }
                $decls = $m[2];
                // What colour does this rule put ON that background?
                $fg = null;
                if (preg_match('/(?<![-a-z])color\s*:\s*([^;}]+)/i', $decls, $cm)) {
                    $fg = trim($cm[1]);
                    if (preg_match('/var\(\s*(--[a-z0-9-]+)/i', $fg, $vm)) {
                        $fg = $vars[strtolower($vm[1])] ?? null;
                    }
                }
                // No explicit foreground means it inherits — which is exactly how dark-on-dark happens.
                $ratio = $fg !== null ? self::contrastRatio($fg, $newHex) : 0.0;
                if ($ratio >= 4.5) { continue; }
                $best = self::readableOn($newHex);
                $rules[$selector] = $best;
            }
        }
        if ($rules === []) {
            // Nothing to guard: drop any stale block so an earlier guard does not outlive its reason.
            $rules = [];
        }

        $css = '';
        foreach ($rules as $sel => $fg) {
            $css .= $sel . '{color:' . $fg . " !important}\n";
        }
        $block = $css === '' ? '' : "<style id=\"lu-contrast-guard\">\n/* readable text on colours changed from chat */\n{$css}</style>\n";

        $files = glob("{$root}/*.html") ?: [];
        foreach ((glob("{$root}/*/index.html") ?: []) as $n) { $files[] = $n; }
        $wrote = 0;
        foreach (array_unique($files) as $file) {
            $h = @file_get_contents($file);
            if ($h === false) { continue; }
            $stripped = preg_replace('~<style id="lu-contrast-guard".*?</style>\s*~is', '', $h) ?? $h;
            $new = $block === ''
                ? $stripped
                : ((stripos($stripped, '</head>') !== false)
                    ? str_ireplace('</head>', $block . '</head>', $stripped)
                    : $block . $stripped);
            if ($new !== $h) { file_put_contents($file, $new); $wrote++; }
        }
        return $wrote > 0;
    }

    /** Relative luminance per WCAG. */
    private static function luminance(string $hex): float
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $h)) { return 0.0; }
        $out = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($h, $i, 2)) / 255;
            $out[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
    }

    private static function contrastRatio(string $a, string $b): float
    {
        if (! preg_match('/^#?[0-9a-fA-F]{3,6}$/', trim($a)) || ! preg_match('/^#?[0-9a-fA-F]{3,6}$/', trim($b))) { return 21.0; }
        $la = self::luminance($a); $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or near-black, whichever this background can actually carry. */
    private static function readableOn(string $bg): string
    {
        return self::contrastRatio('#FFFFFF', $bg) >= self::contrastRatio('#111111', $bg) ? '#FFFFFF' : '#111111';
    }

    /** A colour name or hex from the customer's own words, normalised to #RRGGBB. */
    private static function styleHex(string $val): ?string
    {
        $v = strtolower(trim($val));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) { return strtoupper($v); }
        return self::COLOR_MAP[$v] ?? null;
    }

    /**
     * A static export's :root uses whatever variable names its template chose (--accent, --primary,
     * --c1, --cf1 …). Map the semantic roles the customer spoke in onto the names THIS site actually
     * has, preferring the most specific match and never inventing a variable that is not there.
     */
    private static function mapRolesToSiteVars(int $websiteId, array $roles): array
    {
        $have = self::siteColorVars($websiteId);
        if ($have === []) { return []; }

        $ranked = self::rankedBrandVars($websiteId, $have);

        // An explicitly named variable always beats a guess.
        $prefs = [
            'primary'   => ['--primary', '--brand', '--brand-primary', '--color-primary', '--c1'],
            'secondary' => ['--secondary', '--brand-secondary', '--color-secondary', '--c2'],
            'accent'    => ['--accent', '--brand-accent', '--color-accent', '--c3'],
        ];
        $slot = ['primary' => 0, 'secondary' => 1, 'accent' => 2];

        $out = [];
        $taken = [];
        foreach ($roles as $role => $hex) {
            $target = null;
            foreach (($prefs[$role] ?? []) as $var) {
                if (isset($have[$var]) && ! isset($taken[$var])) { $target = $var; break; }
            }
            if ($target === null) {
                // Fall back to the derived ranking: the colour this page paints with most.
                $idx = $slot[$role] ?? 0;
                $names = array_keys($ranked);
                foreach (array_slice($names, $idx) as $cand) {
                    if (! isset($taken[$cand])) { $target = $cand; break; }
                }
            }
            if ($target === null) { continue; }
            $out[$target] = $hex;
            $taken[$target] = true;

            // Companion shades of the same colour must move with it, or a recoloured button keeps its
            // old hover state and its old tinted background.
            foreach (['-deep' => -1, '-dark' => -1, '-strong' => -1, '-soft' => 1, '-light' => 1, '-tint' => 1, 't' => 1] as $suffix => $dir) {
                $companion = $target . $suffix;
                if (isset($have[$companion]) && ! isset($taken[$companion])) {
                    $out[$companion] = self::shiftLightness($hex, $dir);
                    $taken[$companion] = true;
                }
            }
        }

        // Keep the design-layer palette in step, so a later restyle does not reintroduce the old hue.
        $cfOrder = ['--cf1', '--cf2', '--cf3'];
        $i = 0;
        foreach ($roles as $role => $hex) {
            $cf = $cfOrder[$i++] ?? null;
            if ($cf !== null && isset($have[$cf])) {
                $out[$cf] = $hex;
                if (isset($have[$cf . 't'])) { $out[$cf . 't'] = self::shiftLightness($hex, 1); }
            }
        }

        return $out;
    }

    /**
     * Colour variables ranked by how much of the page they actually paint, neutrals excluded.
     *
     * Neutrals are the page ground, the body text and the hairlines. They are usually the MOST used
     * variables on a site, so ranking without excluding them would repaint the background on "make it
     * navy" and leave the site unreadable. Saturation and lightness separate them reliably enough.
     *
     * @param array<string,string> $have var name => hex
     * @return array<string,int> var name => usage count, most used first
     */
    private static function rankedBrandVars(int $websiteId, array $have): array
    {
        $index = storage_path("app/public/sites/{$websiteId}/index.html");
        $html = is_file($index) ? (string) @file_get_contents($index) : '';
        $scored = [];
        foreach ($have as $name => $hex) {
            if (strncmp($name, '--cf', 4) === 0) { continue; }   // treatment palette, handled separately
            if (substr($name, -1) === 't' && isset($have[substr($name, 0, -1)])) { continue; }
            try { [$h, $sat, $lig] = \App\Engines\Builder\Support\ColorTheme::hexToHsl($hex); }
            catch (\Throwable $e) { continue; }
            // Structure, not brand: a hairline, a shadow or a page ground can be perfectly saturated
            // (--divider:#3B2E57) and would still be the wrong thing to repaint on "make it navy".
            if (preg_match('/(divider|border|line|rule|outline|shadow|muted|disabled|placeholder|overlay|scrim|bg|background|ground|paper|surface|canvas|ink|text|body)/', $name)) { continue; }
            if ($sat < 0.18) { continue; }                        // grey/paper/ink
            if ($lig < 0.25 || $lig > 0.78) { continue; }         // page grounds, not brand colours
            $scored[$name] = substr_count($html, "var({$name})");
        }
        arsort($scored);
        return $scored;        return $out;
    }

    /** Replace (or insert) the marked design-style layer in every exported HTML file. */
    private static function writeDesignLayer(int $websiteId, string $layer): bool
    {
        $root = storage_path("app/public/sites/{$websiteId}");
        $files = glob("{$root}/*.html") ?: [];
        foreach ((glob("{$root}/*/index.html") ?: []) as $nested) { $files[] = $nested; }
        $files = array_values(array_unique($files));
        $stamp = date('YmdHis');
        $wrote = 0;
        foreach ($files as $file) {
            $html = @file_get_contents($file);
            if ($html === false) { continue; }
            // (DEC-0046) no loose .bak-style beside the served file — the request-level history snapshot covers it
            // The layer is <link preconnect> + <link font css> + <style id="lug-design-style">. Replacing
            // only the <style> left the old font links behind, so each restyle added two more.
            $html = preg_replace('~<link rel="preconnect" href="https://fonts\.(?:googleapis|gstatic)\.com"[^>]*>~i', '', $html) ?? $html;
            $html = preg_replace('~<link[^>]+fonts\.googleapis\.com/css2[^>]*>~i', '', $html) ?? $html;
            $new = preg_replace('~<style id="lug-design-style".*?</style>~is', $layer, $html, 1, $n);
            if ($n === 0) {
                $new = (stripos($html, '</head>') !== false)
                    ? str_ireplace('</head>', $layer . '</head>', $html)
                    : $layer . $html;
            }
            if ($new !== null && $new !== $html) { file_put_contents($file, $new); $wrote++; }
        }
        return $wrote > 0;
    }

    /** "a, b and c" */
    private static function joinList(array $parts): string
    {
        if (count($parts) <= 1) { return (string) ($parts[0] ?? ''); }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    private function paletteBrand(array $tv, array $settings): array
    {
        $pick = fn(array $keys) => (function () use ($keys, $tv, $settings) { foreach ($keys as $k) { $v = trim((string) ($tv[$k] ?? $settings[$k] ?? '')); if (preg_match('/^#[0-9a-f]{6}$/i', $v)) return strtoupper($v); } return null; })();
        $primary   = $pick(['primary_color', 'accent_color']) ?? '#1F2937';
        $accent    = $pick(['accent_color', 'primary_color']) ?? $primary;
        $secondary = $pick(['secondary_color']) ?? $accent;
        return ['primary' => $primary, 'primary_color' => $primary, 'secondary' => $secondary, 'secondary_color' => $secondary,
                'accent' => $accent, 'accent_color' => $accent, 'logo_url' => (string) ($tv['logo_url'] ?? '')];
    }

    /** Render a page's sections (minus header/footer — the template chrome supplies them) as a body fragment. */
    private function renderSectionsInTemplateChrome(BuilderRenderer $renderer, array $sections, array $brand, array $site): string
    {
        $out = '';
        foreach ($sections as $sec) {
            $type = (string) ($sec['type'] ?? '');
            if (in_array($type, ['header', 'footer'], true)) continue;
            $frag = $renderer->renderSection($sec, $brand, $site);
            // RISK-0191 U1 (2026-09-19): an added page's sections speak the site's palette roles like the home's
            $out .= $this->roleifyForSite($this->adoptTemplateTypography($frag), (int) ($site['id'] ?? 0), $brand) . "\n";
        }
        return $out;
    }

    /** U3: the texts of an added section live in template_variables under their field ids, like every template field. */
    private function mirrorAddedFieldValues(int $websiteId, array $values): void
    {
        if ($values === [] || $websiteId <= 0) return;
        try {
            $tv = json_decode((string) (DB::table('websites')->where('id', $websiteId)->value('template_variables') ?: '{}'), true) ?: [];
            foreach ($values as $k => $v) { if (preg_match('/^added_[a-z0-9_]+_\d+$/', (string) $k)) $tv[$k] = (string) $v; }
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable $e) { Log::warning('[Arthur] mirrorAddedFieldValues: ' . $e->getMessage()); }
    }

    /** RISK-0191 U1: a rendered fragment's literal colours → the site's role variables (PaletteRoles::roleifyRendered). */
    private function roleifyForSite(string $html, int $websiteId, array $brand): string
    {
        try {
            $ctx = $websiteId > 0 ? app(TemplateService::class)->rolesForSite($websiteId) : ['roles' => []];
            if (($ctx['roles'] ?? []) === []) return $html;
            return \App\Engines\Builder\Support\PaletteRoles::roleifyRendered($html, $ctx['roles'], $brand + ($ctx['brand'] ?? []));
        } catch (\Throwable $e) { Log::warning('[Arthur] roleifyForSite: ' . $e->getMessage()); return $html; }
    }

    /** Strip inline font-family so the template's own typography (and the DesignStyle layer) cascades into the new markup. */
    private function adoptTemplateTypography(string $html): string
    {
        return preg_replace('/font-family:[^;"]*;?/i', '', $html) ?? $html;
    }

    /** A ready-to-render section for THIS business (deterministic; no LLM needed for the structure). */
    private function defaultSectionSpec(string $type, array $id, array $tv, string $request = ''): array
    {
        $name = $id['business_name']; $svc = $id['services']; $loc = $id['location'];
        switch ($type) {
            case 'travel_quiz':
                $cur = trim((string) ($tv['currency'] ?? ''));
                if ($cur === '') { $cur = preg_match('/philippin|manila|cebu|laguna|davao/i', $loc . ' ' . (string) ($tv['contact_address'] ?? '')) ? '₱' : 'AED'; }
                return ['type' => 'travel_quiz', 'business_name' => $name, 'currency' => $cur, 'heading' => "Plan your trip with {$name}",
                    'subheading' => 'Four quick questions and we send you a tailored quote — no payment online.', 'reference_prefix' => substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3)];
            case 'booking_form':
                return ['type' => 'booking_form', 'heading' => 'Book an appointment', 'subheading' => $loc ? "Choose a service and a time that suits you — we're in {$loc}." : 'Choose a service and a time that suits you.',
                    'services' => $svc, 'show_calendar' => true, 'show_time_slots' => true, 'submit_label' => 'Request booking', 'success_message' => "Thanks — {$name} will confirm your appointment shortly."];
            case 'events_calendar':
                return ['type' => 'events_calendar', 'heading' => "What's on at {$name}", 'subheading' => 'Upcoming classes, sessions and events.', 'events' => [], 'view' => 'grid', 'cta_text' => 'Ask about dates', 'cta_url' => '#contact'];
            case 'pricing':
                $tiers = [];
                foreach (array_slice($svc, 0, 3) as $i => $s) $tiers[] = ['name' => $s, 'price' => 'From AED —', 'features' => ['Tailored to you', 'Book online', 'Friendly experts'], 'cta_text' => 'Enquire', 'cta_url' => '#contact'];
                return ['type' => 'pricing', 'heading' => 'Simple pricing', 'body' => 'Transparent prices, no surprises. Ask us for a quote on anything not listed.', 'tiers' => $tiers];
            case 'faq':
                $items = [['q' => "How do I book with {$name}?", 'a' => 'Use the booking form or call us — we confirm within the day.'], ['q' => 'Where are you located?', 'a' => $loc ? "We're in {$loc}. Directions are in the contact section." : 'See the contact section for our address and directions.'], ['q' => 'Do you offer packages?', 'a' => 'Yes — ask us and we will put together a package that fits.']];
                return ['type' => 'faq', 'heading' => 'Questions, answered', 'items' => $items];
            case 'testimonials':
                return ['type' => 'testimonials', 'heading' => 'What our clients say', 'items' => []];
            case 'team':
                return ['type' => 'team', 'heading' => 'Meet the team', 'members' => []];
            case 'gallery':
                return ['type' => 'gallery', 'heading' => 'Gallery', 'images' => [], 'columns' => 3];
            case 'stats':
                return ['type' => 'stats', 'heading' => '', 'items' => [['value' => count($svc) ?: 3, 'label' => 'Services'], ['value' => $loc ?: 'Local', 'label' => 'Based in'], ['value' => '5★', 'label' => 'Client rating']]];
            case 'features':
                $items = array_map(fn($s) => ['title' => $s, 'description' => "{$s} by the {$name} team."], array_slice($svc, 0, 4)) ?: [['title' => 'Expert team', 'description' => "The {$name} team brings care to every visit."]];
                return ['type' => 'features', 'heading' => "Why choose {$name}", 'items' => $items, 'columns' => min(4, max(2, count($items)))];
            case 'services':
                return ['type' => 'services', 'heading' => 'Our services', 'items' => array_map(fn($s) => ['title' => $s, 'description' => ''], $svc)];
            case 'map':
                return ['type' => 'map', 'heading' => 'Find us', 'address' => $loc, 'body' => $loc ? "We're in {$loc}." : ''];
            case 'trust_signals':
                return ['type' => 'trust_signals', 'heading' => 'Why people trust us', 'items' => [['label' => 'Licensed & insured'], ['label' => 'Transparent pricing'], ['label' => 'Friendly, expert team']]];
            case 'contact_form':
                return ['type' => 'contact_form', 'heading' => 'Get in touch', 'body' => 'Tell us what you need and we will reply within the day.', 'phone' => $id['phone'], 'email' => $id['email'], 'address' => $loc];
            case 'video_embed':
                // DEC-0046 gap closure: the customer's own link (YouTube, Vimeo or a direct file); the renderer allow-lists hosts.
                $url = preg_match('~https?://[^\s"\'<>]+~i', $request, $um) ? rtrim($um[0], '.,;)') : '';
                return ['type' => 'video_embed', 'video_url' => $url, 'eyebrow' => 'Watch', 'heading' => "See {$name} in action",
                    'subheading' => $loc ? "A closer look at what we do in {$loc}." : 'A closer look at what we do.'];
            case 'cta':
            default:
                return ['type' => 'cta', 'heading' => "Ready to get started with {$name}?", 'body' => 'Book today or send us a message.', 'cta_text' => 'Book now', 'cta_url' => '#booking'];
        }
    }
    public function buildDefaultSectionsForPage(string $slug, array $data): array
    {
        $sections = $this->buildRawPageSections($slug, $data);
        // RISK-0097 residue — carry the industry hero image into hero sections so the
        // dynamic renderer (structural-edit fallback) shows a hero image like the
        // static template, not an imageless hero. Runs on the always-built skeleton.
        // Leading-slash-normalized (some builder_default_assets rows lack it).
        try {
            $heroUrl = trim((string) ($data['hero_image'] ?? ''));
            if ($heroUrl === '') {
                $ind = $this->confidentSlugFromText((string) ($data['industry'] ?? '')) ?: '';
                if ($ind !== '') {
                    $heroUrl = (string) DB::table('builder_default_assets')
                        ->where('asset_type', 'hero')->where('industry', $ind)->value('url');
                }
            }
            if ($heroUrl !== '') {
                if ($heroUrl[0] !== '/' && ! preg_match('#^https?://#', $heroUrl)) {
                    $heroUrl = '/' . ltrim($heroUrl, '/');
                }
                foreach ($sections as &$sec) {
                    if (($sec['type'] ?? '') === 'hero' && empty($sec['background_image'])) {
                        $sec['background_image'] = $heroUrl;
                    }
                }
                unset($sec);
            }
        } catch (\Throwable $e) { /* non-fatal: hero stays imageless */ }
        try {
            $sections = $this->enrichPageSections($sections, $data, $slug);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] enrichPageSections failed: ' . $e->getMessage());
        }
        return $sections;
    }

    /**
     * One LLM call → business-context copy for the given page. Returns [] on any
     * failure so the raw skeleton's (now archetype-improved) copy stands.
     */
    private function pageCopy(string $pageType, array $data): array
    {
        if (!$this->runtime->isConfigured()) return [];
        $name     = (string) ($data['business_name'] ?? 'the business');
        $industry = trim((string) ($data['industry'] ?? 'business')) ?: 'business';
        $location = (string) ($data['location'] ?? '');  // ARTHUR-3 (2026-08-29): never invent a city
        $services = is_array($data['services'] ?? null) ? implode(', ', $data['services']) : (string) ($data['services'] ?? '');
        $spec = [
            'about'    => 'story_body (2-3 sentences on how this business started and what it stands for), value_1_title (2-3 words), value_1_body (1 sentence), value_2_title, value_2_body, value_3_title, value_3_body, team_heading (short), reviews_heading (short), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)',
            'services' => 'hero_body (1 sentence), section_heading (short), reviews_heading (short), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)',
            'contact'  => 'hero_subheading (short), hero_body (1-2 sentences), form_heading (short), form_body (1 sentence)',
            'pricing'  => "hero_subheading (short), hero_body (1 sentence), pricing_heading (short), tiers (an array of EXACTLY 3 objects {name: short plan name that fits a {$industry}, price: a realistic price like 'AED 250' or 'AED 250/mo' where a {$industry} has standard pricing, otherwise 'Contact us' or 'Custom' — never '\$X', features: array of 3-4 short benefit strings specific to a {$industry}}), faq_heading (short), faq (array of 3 objects {question, answer} that a {$industry} customer actually asks about pricing), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)",
            'faq'      => "hero_body (1 sentence), faq (array of 5 objects {question, answer} — real questions a {$industry} customer asks, answered in the business's voice)",
        ][$pageType] ?? '';
        if ($spec === '') return [];
        $sys = "You are a website copywriter for '{$name}', a {$industry} in {$location}. "
             . "Write authentic, {$industry}-specific copy in the business's own voice. NEVER use generic "
             . "consultancy filler like 'we help clients with your project' or 'your success is our metric' "
             . "unless this literally IS a consultancy, and NEVER leave placeholders like '\$X' or 'Feature 1'. "
             . "Return ONLY valid JSON (include the word json).";
        $prompt = "Services: {$services}.\nFor the {$pageType} page, return a JSON object with EXACTLY these keys: {$spec}.";
        try {
            $r = $this->runtime->chatJson($sys, $prompt, ['task' => 'arthur_page_copy'], 1300);
            if (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) {
                // Same LLM JSON-mode artifact as generateContent: some providers wrap the whole
                // payload in a single {"json":{...}} envelope. Peel it before reading keys, or
                // every field misses and the page silently keeps its skeleton copy.
                $parsed = \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($r['parsed']);
                // keep both scalar copy and nested arrays (tiers / faq)
                return array_filter($parsed, fn($v) => (is_string($v) && trim($v) !== '') || (is_array($v) && !empty($v)));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] pageCopy failed: ' . $e->getMessage());
        }
        return [];
    }

    /** Overlay business-context copy from pageCopy() onto the page skeleton. */
    private function enrichPageSections(array $sections, array $data, string $slug): array
    {
        $slugN = preg_replace('/[\s\-]+/', '_', strtolower(trim($slug)));
        $pageType = in_array($slugN, ['about', 'about_us'], true) ? 'about'
            : (in_array($slugN, ['services', 'contact', 'pricing', 'faq'], true) ? $slugN : '');
        if ($pageType === '') return $sections; // blog skeleton is fine
        $c = $this->pageCopy($pageType, $data);
        if (empty($c)) return $sections;

        foreach ($sections as &$sec) {
            $t = $sec['type'] ?? '';
            if ($t === 'hero') {
                if (!empty($c['hero_body']))       $sec['body']       = $c['hero_body'];
                if (!empty($c['story_body']))      $sec['body']       = $c['story_body'];
                if (!empty($c['hero_subheading'])) $sec['subheading'] = $c['hero_subheading'];
            } elseif ($t === 'features' && $pageType === 'about') {
                for ($i = 1; $i <= 3; $i++) {
                    if (!empty($c["value_{$i}_title"]) && isset($sec['items'][$i - 1])) {
                        $sec['items'][$i - 1]['title'] = $c["value_{$i}_title"];
                        if (!empty($c["value_{$i}_body"])) $sec['items'][$i - 1]['body'] = $c["value_{$i}_body"];
                    }
                }
            } elseif ($t === 'team' && !empty($c['team_heading'])) {
                $sec['heading'] = $c['team_heading'];
            } elseif ($t === 'testimonials' && !empty($c['reviews_heading'])) {
                $sec['heading'] = $c['reviews_heading'];
            } elseif ($t === 'services' && !empty($c['section_heading'])) {
                $sec['heading'] = $c['section_heading'];
            } elseif ($t === 'cta') {
                if (!empty($c['cta_heading'])) $sec['heading']  = $c['cta_heading'];
                if (!empty($c['cta_body']))    $sec['body']     = $c['cta_body'];
                if (!empty($c['cta_text']))    $sec['cta_text'] = $c['cta_text'];
            } elseif ($t === 'contact_form') {
                if (!empty($c['form_heading'])) $sec['heading'] = $c['form_heading'];
                if (!empty($c['form_body']))    $sec['body']    = $c['form_body'];
            } elseif ($t === 'pricing') {
                if (!empty($c['pricing_heading'])) $sec['heading'] = $c['pricing_heading'];
                if (!empty($c['tiers']) && is_array($c['tiers'])) {
                    $tiers = [];
                    foreach ($c['tiers'] as $ti) {
                        if (!is_array($ti) || empty($ti['name'])) continue;
                        $tiers[] = [
                            'name'     => (string) $ti['name'],
                            'price'    => (string) ($ti['price'] ?? 'Contact us'),
                            'features' => array_values(array_filter(array_map('strval', (array) ($ti['features'] ?? [])), fn($s) => trim($s) !== '')),
                        ];
                    }
                    if (count($tiers) >= 2) {
                        $tiers[1]['highlight'] = true; // middle tier highlighted, matching the skeleton
                        $sec['tiers'] = $tiers;
                    }
                }
            } elseif ($t === 'faq') {
                if (!empty($c['faq_heading'])) $sec['heading'] = $c['faq_heading'];
                if (!empty($c['faq']) && is_array($c['faq'])) {
                    $items = [];
                    foreach ($c['faq'] as $q) {
                        if (is_array($q) && !empty($q['question'])) {
                            $items[] = ['question' => (string) $q['question'], 'answer' => (string) ($q['answer'] ?? '')];
                        }
                    }
                    if ($items) $sec['items'] = $items;
                }
            }
        }
        unset($sec);
        return $sections;
    }

    private function buildRawPageSections(string $slug, array $data): array
    {
        $businessName = (string) ($data['business_name'] ?? 'Your Business');
        $industry     = (string) ($data['industry']      ?? 'business');
        $coreService  = (string) ($data['core_service']  ?? '');
        $services     = (array)  ($data['services']      ?? []);
        $location     = (string) ($data['location']      ?? '');

        $heroHeadline = $businessName;
        $heroSub      = $coreService !== ''
            ? $coreService
            : "A trusted {$industry} business" . ($location !== '' ? " in {$location}" : '');

        $featureItems = array_slice(array_filter(array_map('strval', $services)), 0, 6);

        // Normalise common slug aliases so "about-us" / "About Us" all match the same case.
        $slugN = strtolower(trim($slug));
        $slugN = preg_replace('/[\s\-]+/', '_', $slugN);

        switch ($slugN) {
            case 'blog':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Latest from ' . $businessName, 'body' => 'News, updates, and stories.'],
                    ['type' => 'blog_list'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 (2026-05-30) — universal page templates ─────────
            case 'about':
            case 'about_us':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our story',
                        'subheading' => $businessName,
                        'body'       => $coreService !== ''
                            ? "We help our clients with {$coreService}. Here's how we got started."
                            : "Get to know the team behind {$businessName}."],
                    ['type' => 'features',
                        'heading' => 'What we believe in',
                        'body'    => 'The principles that guide our work.',
                        'items'   => [
                            ['title' => 'Quality first', 'body' => "We never cut corners on the things that matter."],
                            ['title' => 'Client focused', 'body' => 'Your success is the metric we measure ourselves against.'],
                            ['title' => 'Always improving', 'body' => 'We learn from every engagement and bring that forward.'],
                        ]],
                    ['type' => 'team', 'heading' => 'Meet the team'],
                    ['type' => 'testimonials', 'heading' => 'What our clients say'],
                    ['type' => 'cta',
                        'heading'  => "Ready to work with {$businessName}?",
                        'body'     => "Let's talk about your project.",
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'services':
                $svcItems = $featureItems
                    ? array_map(fn($s) => ['title' => $s, 'body' => "Learn more about our {$s} offering."], $featureItems)
                    : [['title' => 'Service one', 'body' => 'Describe what this service does for the client.']];
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our services',
                        'subheading' => "What we do for {$industry} clients",
                        'body'       => 'A focused set of services tailored to your needs.'],
                    ['type' => 'services',
                        'heading' => 'Services we offer',
                        'body'    => '',
                        'items'   => $svcItems],
                    ['type' => 'testimonials', 'heading' => 'Client success stories'],
                    ['type' => 'cta',
                        'heading'  => 'Need something specific?',
                        'body'     => 'Tell us about your project and we will tailor a solution.',
                        'cta_text' => 'Request a quote',
                        'cta_url'  => '/contact'],
                    ['type' => 'contact_form',
                        'heading'      => 'Get a quote',
                        'body'         => 'Tell us what you need.',
                        'submit_label' => 'Request quote'],
                    ['type' => 'footer'],
                ];

            case 'pricing':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Simple, transparent pricing',
                        'subheading' => 'Choose the option that fits your needs',
                        'body'       => 'No hidden fees. Cancel anytime.'],
                    ['type' => 'pricing',
                        'heading' => 'Our plans',
                        'tiers'   => [
                            ['name' => 'Starter',    'price' => '$X',     'features' => ['Feature 1', 'Feature 2', 'Feature 3']],
                            ['name' => 'Pro',        'price' => '$Y',     'features' => ['Everything in Starter', 'Feature 4', 'Feature 5'], 'highlight' => true],
                            ['name' => 'Enterprise', 'price' => 'Custom', 'features' => ['Everything in Pro', 'Custom integrations', 'Dedicated support']],
                        ]],
                    ['type' => 'faq',
                        'heading' => 'Pricing questions',
                        'items'   => [
                            ['question' => 'Can I switch plans later?',     'answer' => 'Yes — upgrade or downgrade at any time.'],
                            ['question' => 'Is there a free trial?',         'answer' => 'Get in touch and we will set you up.'],
                            ['question' => 'Do you offer custom pricing?',   'answer' => 'Yes, contact us for Enterprise needs.'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Ready to get started?',
                        'cta_text' => 'Choose your plan',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'contact':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => "Get in touch with {$businessName}",
                        'subheading' => 'We would love to hear from you',
                        'body'       => $location !== ''
                            ? "Based in {$location}. Available across the regions we serve."
                            : 'Reach out for any inquiry — we respond within one business day.'],
                    ['type' => 'contact_form',
                        'heading'      => 'Send us a message',
                        'body'         => '',
                        'submit_label' => 'Send message'],
                    ['type' => 'footer'],
                ];

            case 'faq':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Questions, answered',
                        'subheading' => "Common questions about {$businessName}",
                        'body'       => "Can't find what you're looking for? Get in touch."],
                    ['type' => 'faq',
                        'heading' => 'Frequently asked questions',
                        'items'   => [
                            ['question' => "How do I get started with {$businessName}?", 'answer' => 'Reach out via our contact form and we will follow up.'],
                            ['question' => 'What areas do you serve?',                   'answer' => $location !== '' ? "We serve {$location} and surrounding areas." : 'We work with clients across all regions.'],
                            ['question' => 'How much does it cost?',                     'answer' => 'See our Pricing page for current rates and packages.'],
                            ['question' => 'Do you offer custom solutions?',             'answer' => 'Yes — every engagement is tailored to your specific needs.'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Still have questions?',
                        'body'     => "We're happy to answer.",
                        'cta_text' => 'Contact us',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'gallery':
            case 'galleries':
            case 'photos':
            case 'photo_gallery':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Gallery', 'body' => 'A look at our work.'],
                    ['type' => 'gallery', 'heading' => 'Our work'],
                    ['type' => 'cta', 'heading' => 'Like what you see?', 'body' => 'Get in touch to start your project.'],
                    ['type' => 'footer'],
                ];

            case 'team':
            case 'our_team':
            case 'staff':
            case 'people':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Meet the team', 'body' => 'The people behind ' . $businessName . '.'],
                    ['type' => 'team', 'heading' => 'Our team'],
                    ['type' => 'testimonials', 'heading' => 'What our clients say'],
                    ['type' => 'cta', 'heading' => 'Work with us', 'body' => 'Get in touch to learn more.'],
                    ['type' => 'footer'],
                ];

            case 'testimonials':
            case 'reviews':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'What our clients say', 'body' => 'Real results from real people.'],
                    ['type' => 'testimonials', 'heading' => 'Client stories'],
                    ['type' => 'cta', 'heading' => 'Ready to join them?', 'body' => 'Get in touch today.'],
                    ['type' => 'contact_form', 'heading' => 'Contact us'],
                    ['type' => 'footer'],
                ];

            case 'legal':
            case 'privacy':
            case 'privacy_policy':
            case 'terms':
            case 'terms_of_service':
                $legalTitle = in_array($slugN, ['privacy', 'privacy_policy'], true) ? 'Privacy Policy'
                            : (in_array($slugN, ['terms', 'terms_of_service'], true) ? 'Terms of Service' : 'Legal');
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => $legalTitle, 'subheading' => 'Last updated: ' . date('F j, Y')],
                    ['type' => 'generic',
                        'content' => "Placeholder for {$legalTitle}. Add the full legal text here. This template is industry-agnostic — substitute the appropriate content for your jurisdiction. Sarah can be asked to draft a starting version and Arthur will edit it once placed."],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-2 (2026-05-30) — booking + events ──────
            case 'book':
            case 'booking':
            case 'book_now':
            case 'appointments':
            case 'reservations':
            case 'reserve':
                $bookingServices = $featureItems
                    ?: ($coreService !== '' ? [$coreService] : []);
                $bookingHeroSub  = $coreService !== ''
                    ? "Schedule your {$coreService} with {$businessName}."
                    : "Pick a time that works for you and we'll confirm it with you.";
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Book your appointment',
                        'subheading' => $businessName,
                        'body'       => $bookingHeroSub],
                    ['type' => 'booking_form',
                        'heading'         => 'Choose a time',
                        'subheading'      => 'Quick and easy — under 60 seconds.',
                        'submit_label'    => 'Confirm booking',
                        'success_message' => 'Thanks — we will confirm your booking by email shortly.',
                        'services'        => $bookingServices,
                        'show_calendar'   => true,
                        'show_time_slots' => true,
                        'fields'          => [
                            ['name' => 'name',  'label' => 'Your name', 'type' => 'text',     'required' => true],
                            ['name' => 'email', 'label' => 'Email',     'type' => 'email',    'required' => true],
                            ['name' => 'phone', 'label' => 'Phone',     'type' => 'tel',      'required' => true],
                            ['name' => 'notes', 'label' => 'Notes (optional)', 'type' => 'textarea', 'required' => false],
                        ]],
                    ['type' => 'features',
                        'heading' => 'Why book with us',
                        'body'    => '',
                        'items'   => [
                            ['title' => 'Confirmed quickly',  'body' => 'We respond within one business day to confirm your time.'],
                            ['title' => 'Easy rescheduling',  'body' => 'Need to move the booking? Just reply to the confirmation email.'],
                            ['title' => 'No surprises',       'body' => "What you book is what you get — clear, fixed expectations."],
                        ]],
                    ['type' => 'faq',
                        'heading' => 'Booking FAQ',
                        'items'   => [
                            ['question' => 'How do I cancel or reschedule?',  'answer' => 'Reply to the confirmation email at least 24 hours before your slot.'],
                            ['question' => 'Do you offer same-day bookings?', 'answer' => 'Depending on availability — submit the form and we will let you know.'],
                            ['question' => 'Is a deposit required?',          'answer' => "Most bookings don't require a deposit. We'll tell you in the confirmation if yours does."],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'event':
            case 'events':
            case 'classes':
            case 'schedule':
            case 'whats_on':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Upcoming events',
                        'subheading' => "What's on at {$businessName}",
                        'body'       => $location !== ''
                            ? "Join us in {$location} for these upcoming sessions."
                            : 'Join us for these upcoming sessions and classes.'],
                    ['type' => 'events_calendar',
                        'heading'    => 'This month',
                        'subheading' => 'Reserve your spot — limited capacity.',
                        'view'       => 'grid',
                        'events'     => [
                            [
                                'title'       => 'Event title one',
                                'date'        => 'Sat, June 14',
                                'time'        => '6:00 PM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                            [
                                'title'       => 'Event title two',
                                'date'        => 'Wed, June 18',
                                'time'        => '7:30 PM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                            [
                                'title'       => 'Event title three',
                                'date'        => 'Sat, June 28',
                                'time'        => '11:00 AM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Want to be the first to hear about new events?',
                        'body'     => 'Join the mailing list and we will let you know.',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-3 (2026-05-30) — listings/locations ────
            case 'listings':
            case 'listing_browser':
            case 'properties':
            case 'rooms':
            case 'products':
            case 'shop':
            case 'catalogue':
            case 'catalog':
            case 'inventory':
            case 'fleet':
            case 'courses':
            case 'menu_browser':
                // Industry-flavoured copy
                $kindLabel  = match (true) {
                    in_array($industry, ['real_estate_agency', 'short_term_rental'], true) => 'properties',
                    in_array($industry, ['hotel', 'resort'], true)                          => 'rooms',
                    in_array($industry, ['ecommerce', 'retail_shop'], true)                => 'products',
                    in_array($industry, ['automotive'], true)                              => 'vehicles',
                    in_array($industry, ['online_courses', 'training_center', 'tutoring'], true) => 'courses',
                    default                                                                => 'listings',
                };
                $listHeading = 'Browse our ' . $kindLabel;
                $sampleItems = [];
                for ($i = 1; $i <= 6; $i++) {
                    $sampleItems[] = [
                        'title'    => ucfirst($kindLabel) . ' ' . $i,
                        'subtitle' => 'A short descriptor goes here',
                        'price'    => '',
                        'badge'    => $i === 1 ? 'New' : '',
                        'cta_text' => 'View details',
                        'cta_url'  => '#',
                    ];
                }
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => $listHeading,
                        'subheading' => $businessName,
                        'body'       => "Browse the latest {$kindLabel} from {$businessName}" . ($location !== '' ? " in {$location}." : '.')],
                    ['type' => 'filter_bar',
                        'heading'        => 'Filter ' . $kindLabel,
                        'target_grid_id' => 'listings-grid',
                        'search_enabled' => true,
                        'filters'        => [
                            ['label' => 'Category',  'options' => ['All']],
                            ['label' => 'Price',     'options' => ['Any', 'Low', 'Mid', 'High']],
                        ],
                        'sort_options' => ['Newest first', 'Price: low to high', 'Price: high to low']],
                    ['type' => 'grid',
                        'heading'    => '',
                        'columns'    => 3,
                        'style'      => 'card',
                        'items'      => $sampleItems],
                    ['type' => 'cta',
                        'heading'  => "Can't find what you're looking for?",
                        'body'     => 'Tell us what you need — we may have something off-market.',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'listing_detail':
            case 'property':
            case 'product':
            case 'room':
            case 'course':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Listing title',
                        'subheading' => 'A short tagline for this listing',
                        'body'       => 'Replace with a hero summary of the listing — key specs, headline price, and the single most compelling reason to inquire.'],
                    ['type' => 'features',
                        'heading' => 'Key details',
                        'body'    => '',
                        'items'   => [
                            ['title' => 'Detail one',   'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail two',   'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail three', 'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail four',  'body' => 'Replace with a key spec.'],
                        ]],
                    ['type' => 'gallery', 'heading' => 'Gallery'],
                    ['type' => 'trust_signals',
                        'heading' => 'Why us',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Verified listing'],
                            ['label' => 'Fast response'],
                            ['label' => 'Best price guarantee'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Interested?',
                        'body'     => 'Reach out and we will get back to you within one business day.',
                        'cta_text' => 'Request information',
                        'cta_url'  => '/contact'],
                    ['type' => 'contact_form',
                        'heading'      => 'Send an inquiry',
                        'body'         => '',
                        'submit_label' => 'Send inquiry'],
                    ['type' => 'related_listings',
                        'heading' => 'You may also like',
                        'items'   => [
                            ['title' => 'Related 1', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                            ['title' => 'Related 2', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                            ['title' => 'Related 3', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'locations':
            case 'location':
            case 'branches':
            case 'find_us':
            case 'store_finder':
            case 'stores':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Visit us',
                        'subheading' => $businessName,
                        'body'       => $location !== ''
                            ? "Find {$businessName} in {$location} — directions, hours, and contact below."
                            : 'Directions, hours, and contact details for our locations.'],
                    ['type' => 'map',
                        'heading'   => 'Our locations',
                        'locations' => [
                            ['name' => $location !== '' ? $location : 'Main branch', 'address' => 'Replace with full address', 'phone' => '', 'hours' => 'Mon–Fri 9:00–18:00'],
                        ]],
                    ['type' => 'trust_signals',
                        'heading' => 'Why visit',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Easy parking'],
                            ['label' => 'Friendly staff'],
                            ['label' => 'Walk-ins welcome'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Have a question before you visit?',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-4 (2026-05-30) — visual portfolios ─────
            case 'before_after':
            case 'before_and_after':
            case 'transformations':
            case 'results':
            case 'case_studies':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Results that speak for themselves',
                        'subheading' => $businessName,
                        'body'       => 'Browse a selection of recent transformations and case studies.'],
                    ['type' => 'gallery',
                        'heading' => 'Before and after',
                        'body'    => 'Tap any image to see the full transformation.',
                        'columns' => 3,
                        'style'   => 'card'],
                    ['type' => 'testimonials',
                        'heading' => 'What clients say',
                        'items'   => [
                            ['quote' => 'Replace with a real quote from a happy client.',  'author' => 'Client name', 'role' => 'Treatment / project'],
                            ['quote' => 'Replace with a real quote from a happy client.',  'author' => 'Client name', 'role' => 'Treatment / project'],
                        ]],
                    ['type' => 'stats',
                        'heading' => 'By the numbers',
                        'items'   => [
                            ['label' => 'Projects completed', 'value' => '500+'],
                            ['label' => 'Client satisfaction', 'value' => '98%'],
                            ['label' => 'Years of experience', 'value' => '10+'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Want results like these?',
                        'body'     => 'Tell us about your goals and we will design a tailored plan.',
                        'cta_text' => 'Book a consultation',
                        'cta_url'  => '/booking'],
                    ['type' => 'footer'],
                ];

            case 'menu':
            case 'food_menu':
            case 'dishes':
            case 'drinks':
            case 'wine_list':
                $menuItems = $featureItems
                    ? array_map(fn($s) => ['title' => $s, 'subtitle' => 'Description goes here', 'price' => 'AED 00'], $featureItems)
                    : [
                        ['title' => 'Signature dish 1', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 2', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 3', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 4', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                    ];
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our menu',
                        'subheading' => $businessName,
                        'body'       => $location !== ''
                            ? "Crafted with care in {$location}. Updated seasonally."
                            : 'Crafted with care. Updated seasonally.'],
                    ['type' => 'filter_bar',
                        'heading'        => 'Browse by section',
                        'target_grid_id' => 'menu-grid',
                        'search_enabled' => false,
                        'filters'        => [
                            ['label' => 'Section', 'options' => ['All', 'Starters', 'Mains', 'Desserts', 'Drinks']],
                            ['label' => 'Dietary', 'options' => ['Any', 'Vegetarian', 'Vegan', 'Gluten-free']],
                        ]],
                    ['type' => 'grid',
                        'heading'    => 'On the menu',
                        'columns'    => 2,
                        'style'      => 'compact',
                        'items'      => $menuItems],
                    ['type' => 'cta',
                        'heading'  => 'Reserve a table',
                        'body'     => 'Walk-ins welcome — bookings recommended on weekends.',
                        'cta_text' => 'Book a table',
                        'cta_url'  => '/booking'],
                    ['type' => 'footer'],
                ];

            case 'portfolio':
            case 'work':
            case 'projects':
            case 'gallery_page':
            case 'showcase':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Selected work',
                        'subheading' => $businessName,
                        'body'       => $coreService !== ''
                            ? "A selection of recent {$coreService} projects."
                            : 'A selection of recent projects.'],
                    ['type' => 'filter_bar',
                        'heading'        => 'Filter by',
                        'target_grid_id' => 'portfolio-grid',
                        'search_enabled' => true,
                        'filters'        => [
                            ['label' => 'Category', 'options' => ['All']],
                            ['label' => 'Year',     'options' => ['All', '2026', '2025', '2024']],
                        ]],
                    ['type' => 'grid',
                        'heading' => '',
                        'columns' => 3,
                        'style'   => 'media',
                        'items'   => [
                            ['title' => 'Project one',   'subtitle' => 'Category · 2026', 'badge' => 'Featured'],
                            ['title' => 'Project two',   'subtitle' => 'Category · 2026'],
                            ['title' => 'Project three', 'subtitle' => 'Category · 2025'],
                            ['title' => 'Project four',  'subtitle' => 'Category · 2025'],
                            ['title' => 'Project five',  'subtitle' => 'Category · 2024'],
                            ['title' => 'Project six',   'subtitle' => 'Category · 2024'],
                        ]],
                    ['type' => 'testimonials', 'heading' => 'Client feedback'],
                    ['type' => 'cta',
                        'heading'  => "Have a project in mind?",
                        'body'     => "Let's talk about what you want to build.",
                        'cta_text' => 'Start a project',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-5 (2026-05-30) — commerce + account ────
            case 'cart':
            case 'basket':
            case 'shopping_cart':
                return [
                    ['type' => 'header'],
                    ['type' => 'cart_summary',
                        'heading' => 'Your cart',
                        'items'   => [
                            ['name' => 'Sample item 1', 'qty' => 1, 'price' => '120', 'subtotal' => '120'],
                            ['name' => 'Sample item 2', 'qty' => 2, 'price' => '60',  'subtotal' => '120'],
                        ],
                        'currency' => 'AED',
                        'subtotal' => '240',
                        'tax'      => '12',
                        'shipping' => '25',
                        'total'    => '277',
                        'cta_text' => 'Proceed to checkout',
                        'cta_url'  => '/checkout',
                        'continue_shopping_url' => '/shop'],
                    ['type' => 'trust_signals',
                        'heading' => 'Shop with confidence',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Secure checkout'],
                            ['label' => 'Easy returns'],
                            ['label' => 'Fast delivery'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'checkout':
            case 'checkout_page':
                return [
                    ['type' => 'header'],
                    ['type' => 'checkout_form',
                        'heading'    => 'Checkout',
                        'subheading' => "You're moments away — fill in the details below.",
                        'submit_label' => 'Place order',
                        'payment_methods' => ['Visa', 'Mastercard', 'Apple Pay', 'Cash on delivery'],
                        'order_summary' => [
                            'items' => [
                                ['name' => 'Sample item 1', 'price' => 'AED 120'],
                                ['name' => 'Sample item 2 × 2', 'price' => 'AED 120'],
                                ['name' => 'Shipping',     'price' => 'AED 25'],
                                ['name' => 'Tax (VAT)',    'price' => 'AED 12'],
                            ],
                            'total' => 'AED 277',
                        ]],
                    ['type' => 'trust_signals',
                        'heading' => 'Secure transaction',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'SSL encrypted'],
                            ['label' => 'PCI compliant'],
                            ['label' => 'No card stored'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'account':
            case 'my_account':
            case 'dashboard':
            case 'profile_page':
                return [
                    ['type' => 'header'],
                    ['type' => 'account_nav',
                        'heading'    => 'Welcome back',
                        'subheading' => 'Manage your orders, addresses, and profile.',
                        'orientation' => 'top',
                        'items' => [
                            ['label' => 'Orders',    'url' => '/account/orders',    'active' => true],
                            ['label' => 'Addresses', 'url' => '/account/addresses'],
                            ['label' => 'Profile',   'url' => '/account/profile'],
                            ['label' => 'Wishlist',  'url' => '/account/wishlist'],
                        ]],
                    ['type' => 'account_panel',
                        'heading'    => 'Recent orders',
                        'panel_type' => 'orders',
                        'items'      => [
                            ['ref' => '#1042', 'date' => 'May 28, 2026', 'status' => 'Delivered',  'total' => 'AED 277', 'url' => '/account/orders/1042'],
                            ['ref' => '#1038', 'date' => 'May 14, 2026', 'status' => 'Processing', 'total' => 'AED 150', 'url' => '/account/orders/1038'],
                        ],
                        'empty_message' => "You haven't placed any orders yet.",
                        'cta_text' => 'Start shopping',
                        'cta_url'  => '/shop'],
                    ['type' => 'footer'],
                ];

            case 'home':
            default:
                $sections = [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => $heroHeadline, 'body' => $heroSub],
                ];

                if (! empty($featureItems)) {
                    $sections[] = [
                        'type'    => 'features',
                        'heading' => 'What we do',
                        'body'    => 'Services we provide:',
                        'items'   => array_map(fn($s) => ['title' => $s], $featureItems),
                    ];
                }

                $sections[] = [
                    'type'    => 'cta',
                    'heading' => "Ready to work with {$businessName}?",
                    'body'    => 'Get in touch and we\'ll be in contact shortly.',
                ];
                $sections[] = ['type' => 'contact_form', 'heading' => 'Contact us'];
                $sections[] = ['type' => 'footer'];

                return $sections;
        }
    }
    /** Most page/section/edit units Arthur performs from one message; the rest is reported back, never silently dropped. */
    public const MAX_UNITS_PER_MESSAGE = 10;

    /** COMPOUND REQUESTS (stress C19, 2026-09-06): "change X and add Y" → ["change X", "add Y"]. Only splits before an action verb. */
    public static function splitClauses(string $request): array
    {
        $verbs = 'add|create|insert|include|put|change|update|edit|rewrite|revise|reword|replace|tweak|rename|remove|delete|hide|make|set|swap|correct|fix';
        $parts = preg_split('/\s*(?:;|\band\s+(?:also\s+|then\s+)?(?=(?:' . $verbs . ')\b)|\bthen\s+(?=(?:' . $verbs . ')\b)|,?\s*\balso\s+(?=(?:' . $verbs . ')\b))\s*/iu', trim($request)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($p) => mb_strlen($p) > 3));
        return count($parts) > 1 ? $parts : [trim($request)];
    }

    /** The model sometimes shortens a key ("hero_cta" for hero_cta_primary): resolve to a real field or null. */
    public static function resolveFieldKey(string $k, array $fields): ?string
    {
        $k = trim($k);
        if ($k === '') return null;
        if (isset($fields[$k])) return $k;
        foreach ([$k . '_primary', $k . '_text', $k . '_title', $k . '_1', $k . '_body'] as $cand) { if (isset($fields[$cand])) return $cand; }
        $pref = array_values(array_filter(array_keys($fields), fn($f) => str_starts_with($f, $k . '_')));
        return count($pref) === 1 ? $pref[0] : null;
    }

    /** The text fields a customer can see on a template site (no images, flags, colours, fonts, urls). */
    public static function editableTextVariables(array $tv): array
    {
        $out = [];
        foreach ($tv as $k => $v) {
            if (!is_string($v)) continue;
            $val = trim($v);
            if ($val === '' || mb_strlen($val) > 600) continue;
            if (preg_match('/(_image|image_\d+|_img|_display|_url|_src|_href|_link|_icon|_color|_colour|color$|colour$|_font|^font_|^design_|^theme|^og_|^logo|_id$|^nav_logo$|^footer_logo$|^header_logo$|^site_url|^lang(uage)?$)/i', (string) $k)) continue;
            if (preg_match('#^(https?:)?/|^\#|^display:|^[0-9a-f]{6}$#i', $val)) continue;
            $out[(string) $k] = $val;
        }
        return $out;
    }

    /**
     * STRESS C12 (2026-09-06): copy edits on a template (static-export) site. The model picks which of the site's REAL
     * text fields change; each change is patched into every export file (home + added pages) and stored in
     * template_variables so a re-render keeps it. Deterministic apply, honest reply when nothing fits.
     */
    private function editStaticCopy(int $wsId, int $websiteId, string $request, object $site, array $tv, array $plan): array
    {
        $fields = self::editableTextVariables($tv);
        // only fields this template actually renders (template_variables carry keys from other manifests too)
        $exportHtml = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if ($exportHtml !== '') { $onPage = array_filter($fields, fn($v, $k) => str_contains($exportHtml, 'data-field="' . $k . '"'), ARRAY_FILTER_USE_BOTH); if ($onPage !== []) $fields = $onPage; }
        if ($fields === []) return ['success' => false, 'code' => 'NO_FIELDS', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'message' => "This site has no editable text fields I can change from here."];
        // FACTS LEFT EMPTY AT BUILD (2026-09-14): a phone / email / WhatsApp / address line the customer never gave is stripped
        // from the page (never invented), so its key vanished from this list and the customer could not add it by chat.
        // The template's own fact fields stay offered, empty; a filled one is re-inserted from the template markup below.
        $factKeys = $this->templateFactKeys($websiteId, $site);
        foreach ($factKeys as $fk) { if (! isset($fields[$fk])) { $fields[$fk] = trim((string) ($tv[$fk] ?? '')); } }
        $list = '';
        foreach ($fields as $k => $v) $list .= $k . ': ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $system = "You are Arthur, editing the text of a finished website for {$site->name}. The website's text lives in named fields; you change fields, nothing else.\n"
            . "Return ONLY a JSON object: {\"changes\":[{\"key\":\"<field key>\",\"value\":\"<new text>\"}],\"reply\":\"<one or two sentences to the customer>\"}\n"
            . "Rules:\n- Use only keys from FIELDS. Change every field the request applies to (a phone number or address may appear in several fields).\n"
            . "- Keep each field's language, tone and rough length; keep existing inline <br>, <em>, <strong> markup when a field has it.\n"
            . "- Never invent facts, names, prices or claims that are not in the request or already on the site.\n"
            . "- If the request needs something these fields cannot express (new sections, pages, images, colours, layout), return an empty changes list and say plainly what you cannot do here.\n"
            . "- Maximum 20 changes. No markdown, no commentary outside the JSON.";
        $user = "FIELDS (key: current value)\n{$list}\nREQUEST: {$request}";
        $result = $this->runtime->chatJson($system, $user, ['task' => 'arthur_static_edit', 'workspace_id' => $wsId], 1400);
        $parsed = ($result['success'] ?? false) && is_array($result['parsed'] ?? null)
            ? \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($result['parsed']) : null;
        if (!is_array($parsed)) {
            Log::warning('[Arthur] editStaticCopy: no parseable JSON', ['error' => $result['error'] ?? null, 'website' => $websiteId]);
            return ['success' => false, 'code' => 'MODEL_FAILED', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0, 'message' => "I couldn't work out that change just now — please try rephrasing it."];
        }
        $changes = is_array($parsed['changes'] ?? null) ? $parsed['changes'] : [];
        $reply   = trim((string) ($parsed['reply'] ?? ''));
        return $this->applyCopyChanges($wsId, $websiteId, $site, $tv, $fields, $factKeys, $exportHtml, $changes, $reply, $plan);
    }

    /** Apply a list of {key, value} text changes to a template site: facts re-inserted, hrefs patched, record saved, honest reply. */
    private function applyCopyChanges(int $wsId, int $websiteId, object $site, array $tv, array $fields, array $factKeys, string $exportHtml, array $changes, string $reply, array $plan): array
    {
        $applied = []; $skipped = [];
        foreach (array_slice($changes, 0, 20) as $ch) {
            $k = self::resolveFieldKey((string) ($ch['key'] ?? ''), $fields); $v = $ch['value'] ?? null;
            if ($k === null || !is_string($v)) { $skipped[] = (string) ($ch['key'] ?? ''); continue; }
            $v = trim(strip_tags($v, '<br><em><strong><b><i><span>'));
            if ($v === '' || mb_strlen($v) > 2000 || $v === $fields[$k]) { $skipped[] = $k; continue; }
            if (in_array($k, $factKeys, true) && ! str_contains($exportHtml, 'data-field="' . $k . '"')) { $this->reinsertTemplateField($websiteId, $site, $k, $v, $tv); $exportHtml = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html")); }
            if (!$this->templates->updateField($websiteId, $k, $v)) { $skipped[] = $k; continue; }
            $this->templates->patchFieldInSubPages($websiteId, $k, $v);
            if (in_array($k, $factKeys, true)) { $this->patchFactHref($websiteId, $k, $v); }
            $tv[$k] = $v; $applied[] = $k;
        }
        if ($applied !== []) {
            $this->templates->saveTemplateVariables($websiteId, $tv); // Law 11: Builder-owned persistence
            try { $this->templates->refreshServiceSelects($websiteId, $tv); } catch (\Throwable $e) {}
            try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        }
        Log::info('[Arthur] editStaticCopy', ['website' => $websiteId, 'applied' => $applied, 'skipped' => $skipped]);
        $n = count($applied);
        // never echo a model reply that claims a change when nothing was applied
        $msg = $n > 0
            ? ($reply !== '' ? $reply : "Done — I updated {$n} " . ($n === 1 ? 'field' : 'fields') . " on {$site->name}.")
            : (($changes === [] && $reply !== '' && !preg_match('/\b(updated|changed|done|replaced|set)\b/i', $reply)) ? $reply : "I couldn't find text on {$site->name} that matches that request, so nothing was changed. Tell me the exact words you see on the page and what they should become — for colours, photos, sections or listings, say what should change.");
        return ['success' => $n > 0, 'kind' => 'edit', 'code' => $n > 0 ? 'EDITED' : 'NO_CHANGE', 'plan' => $plan, 'applied' => $n, 'actions_applied' => $n,
            'changes' => $applied, 'message' => $msg, 'reply' => $msg, 'url' => "/storage/sites/{$websiteId}/index.html"];
    }

    /** The text fields a template site exposes for editing (on-page only), plus its fact fields kept offered when empty. */
    private function copyFieldsFor(int $websiteId, object $site, array $tv): array
    {
        $fields = self::editableTextVariables($tv);
        $exportHtml = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        if ($exportHtml !== '') { $onPage = array_filter($fields, fn($v, $k) => str_contains($exportHtml, 'data-field="' . $k . '"'), ARRAY_FILTER_USE_BOTH); if ($onPage !== []) $fields = $onPage; }
        $factKeys = $this->templateFactKeys($websiteId, $site);
        foreach ($factKeys as $fk) { if (! isset($fields[$fk])) { $fields[$fk] = trim((string) ($tv[$fk] ?? '')); } }
        return [$fields, $factKeys, $exportHtml];
    }

    /** Everything the model should see before deciding what a message means (DEC-0050). */
    private function intentContext(int $wsId, int $websiteId, object $site, array $tv, string $industry): array
    {
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        [$fields] = $this->copyFieldsFor($websiteId, $site, $tv);
        $fields = array_slice($fields, 0, 140, true);
        $images = [];
        foreach ($tv as $k => $v) { if (is_string($v) && preg_match('/(_image|^image_\d+|_img)$/', (string) $k) && $v !== '') $images[] = (string) $k; }
        $root = storage_path("app/public/sites/{$websiteId}");
        $home = (string) @file_get_contents($root . '/index.html');
        $sections = preg_match_all('/data-block="([a-z_\-]+)"/', $home, $bm) ? array_values(array_unique($bm[1])) : [];
        $pages = [];
        foreach (glob($root . '/*/index.html') ?: [] as $f) { $slug = basename(dirname($f)); if ($slug !== '.history') $pages[] = '/' . $slug . '/'; }
        $catalogues = [];
        try {
            $cat = app(CatalogueService::class);
            foreach ($cat->specs($websiteId, $site) as $kind => $spec) {
                $items = [];
                foreach (DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->orderByDesc('featured')->orderBy('sort_order')->orderBy('id')->limit(30)->get() as $r) {
                    $items[] = $r->title . ' — ' . $cat->priceText($r) . ' — ' . ($spec['statuses'][$r->status] ?? $r->status);
                }
                $catalogues[$kind] = ['label' => $spec['label'], 'statuses' => array_keys($spec['statuses']), 'enabled' => $spec['enabled'], 'items' => $items];
            }
        } catch (\Throwable $e) {}
        $caps = \App\Engines\Builder\Support\BuilderCapabilities::class;
        $colours = [];
        try { foreach (['--cf1' => 'main', '--cf2' => 'second', '--cf3' => 'third'] as $var => $label) { $v = self::siteColorVars($websiteId)[$var] ?? null; if ($v) $colours[$label] = $v; } } catch (\Throwable $e) {}
        return [
            'name' => (string) $site->name, 'industry' => $industry ?: (string) ($settings['industry'] ?? ''), 'design' => (string) ($settings['template'] ?? $settings['industry'] ?? ''),
            'fields' => $fields, 'images' => array_slice($images, 0, 40), 'sections' => $sections, 'pages' => $pages, 'catalogues' => $catalogues, 'colours' => $colours,
            'addable_sections' => array_keys($caps::sections($industry ?: null)), 'addable_pages' => array_keys($caps::pages($industry ?: null)),
            'abilities' => ['generate a photo for the hero, about or gallery', 'generate a short video', 'write text over a photo', 'remove a photo background or an object', 'switch colour palettes, gradients, darker/lighter, luxury or minimal looks, bigger/smaller text', 'restore or recolour the logo', 'undo the last change (Undo button)'],
        ];
    }

    /** Route the model's decision to the deterministic executor. Returns null to let the classic path handle it (with the normalized wording). */
    private function dispatchIntent(int $wsId, int $websiteId, object $site, string &$request, array $ctx, array $tv, string $industry, array $intent, bool $isStatic): ?array
    {
        $customerWords = $request;   // the customer's own words — the model's normalised sentence replaces $request below
        $base = ['plan' => ['kind' => $intent['intent'], 'credits' => 0], 'credits' => 0, 'applied' => 0, 'actions_applied' => 0];
        $normalized = trim((string) ($intent['normalized'] ?? ''));
        switch ($intent['intent']) {
            case 'clarify':
                $q = trim((string) ($intent['question'] ?? '')) ?: "I want to get this right — what exactly should change on {$site->name}?";
                return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => $q, 'options' => array_slice($intent['options'], 0, 4)];
            case 'answer':
                return $base + ['success' => false, 'kind' => 'answer', 'code' => 'ANSWER', 'method' => 'chat', 'message' => trim((string) ($intent['reply'] ?? '')) ?: "Here is what I can tell you about {$site->name}."];
            case 'unsupported':
                return $base + ['success' => false, 'kind' => 'unsupported', 'code' => 'UNSUPPORTED', 'method' => 'chat', 'message' => trim((string) ($intent['reply'] ?? '')) ?: "That is not something I can do from here."];
            case 'element_effect': {
                // EFFECTS888: opacity / shadow / glow of one element, or an overlay on a section
                $el = is_array($intent['element'] ?? null) ? $intent['element'] : [];
                $sel = is_array($ctx['selected'] ?? null) ? $ctx['selected'] : [];
                $fld = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($el['field'] ?? '')); $blk = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($el['block'] ?? ''));
                $eff = strtolower((string) ($el['effect'] ?? ''));
                if ($fld === '' && $blk === '') { $fld = (string) ($sel['field'] ?? ''); $blk = (string) ($sel['block'] ?? ''); }
                if ($fld === '' && $blk === '') return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => 'Which element or section? Tap it in the preview, or tell me the words you see on it.', 'options' => []];
                if (! \App\Engines\Builder\Support\EditorCredits::canAfford($wsId, 'element_effect')) return $base + ['success' => false, 'code' => 'INSUFFICIENT_CREDITS', 'message' => \App\Engines\Builder\Support\EditorCredits::refusal('element_effect')];
                $val = isset($el['value']) && $el['value'] !== '' && $el['value'] !== null ? (float) $el['value'] : null;
                $dirIn = strtolower((string) ($el['dir'] ?? 'up'));
                if ($val !== null && $val <= 0 && $dirIn !== 'none') $val = null;   // the schema's 0 is not a wish — only 'none' removes
                if ($eff === 'opacity' && preg_match('/(\d{1,3})\s*%\s*transparen/i', $customerWords, $tm)) { $val = max(20, 100 - (int) $tm[1]); }   // '60% transparent' = 40% opaque
                elseif ($eff === 'opacity' && preg_match('/(\d{1,3})\s*%/', $customerWords, $om)) { $val = (int) $om[1]; }
                if ($eff !== 'opacity' && preg_match('/\b(a little|a bit|slightly|a touch|somewhat)\b/i', $customerWords)) $val = null;
                if (preg_match('/\b(remove|no more|get rid|take off|turn off|without)\b/i', $customerWords) && ! preg_match('/\b(less|lighter|weaker)\b/i', $customerWords)) $dirIn = 'none';
                $el['dir'] = $dirIn;
                $res = $this->effectElement($websiteId, $eff === 'overlay' ? '' : $fld, $eff, (string) ($el['dir'] ?? 'up'), $val, isset($el['color']) ? (string) $el['color'] : null, $eff === 'overlay' ? ($blk !== '' ? $blk : '') : '');
                if ($eff === 'overlay' && empty($res['success']) && $blk === '' && $fld !== '') $res = $this->effectElement($websiteId, $fld, 'overlay', (string) ($el['dir'] ?? 'up'), $val, isset($el['color']) ? (string) $el['color'] : null, '');
                if (empty($res['success'])) return $base + ['success' => false, 'kind' => 'answer', 'code' => 'ANSWER', 'message' => (string) $res['message']];
                $cost = \App\Engines\Builder\Support\EditorCredits::charge($wsId, 'element_effect', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$res['message']]]);
                Log::info('[Arthur] element op', ['website' => $websiteId, 'action' => 'element_effect', 'field' => $fld ?: 'section:' . $blk, 'change' => $res['message']]);
                return $base + ['success' => true, 'kind' => 'element', 'applied' => 1, 'actions_applied' => 1, 'credits' => $cost, 'message' => 'Done — I ' . $res['message'] . " on {$site->name}." . ($cost > 0 ? " {$cost} credit" . ($cost === 1 ? '' : 's') . '.' : '') . ' Undo puts it back.'];
            }
            case 'element_move':
            case 'element_align': {
                // ELEMENT888 (DEC-0052): move / swap / align one element; the model names the field (from FIELDS or the selection)
                $el = is_array($intent['element'] ?? null) ? $intent['element'] : [];
                $sel = is_array($ctx['selected'] ?? null) ? $ctx['selected'] : [];
                $fld = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($el['field'] ?? '')); if ($fld === '') $fld = (string) ($sel['field'] ?? '');
                if ($fld === '') return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => 'Which element? Tap it in the preview, or tell me the words you see on it.', 'options' => []];
                $action = $intent['intent'] === 'element_move' ? 'element_move' : 'element_align';
                if (! \App\Engines\Builder\Support\EditorCredits::canAfford($wsId, $action)) return $base + ['success' => false, 'code' => 'INSUFFICIENT_CREDITS', 'message' => \App\Engines\Builder\Support\EditorCredits::refusal($action)];
                if ($action === 'element_move') {
                    $op = strtolower((string) ($el['op'] ?? '')); $ref = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($el['ref'] ?? '')) ?: null;
                    $res = $this->templates->moveElement($websiteId, $fld, $op, $ref);
                } else {
                    $res = $this->alignElement($websiteId, $fld, strtolower((string) ($el['align'] ?? '')));
                }
                if (empty($res['success'])) return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => (string) $res['message'], 'options' => []];
                $cost = \App\Engines\Builder\Support\EditorCredits::charge($wsId, $action, $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$res['message']]]);
                Log::info('[Arthur] element op', ['website' => $websiteId, 'action' => $action, 'field' => $fld, 'change' => $res['message']]);
                return $base + ['success' => true, 'kind' => 'element', 'applied' => 1, 'actions_applied' => 1, 'credits' => $cost, 'message' => 'Done — I ' . $res['message'] . " on {$site->name}." . ($cost > 0 ? " {$cost} credit" . ($cost === 1 ? '' : 's') . '.' : '') . ' Undo puts it back.'];
            }
            case 'section_move':
            case 'section_hide':
            case 'section_show':
                $sec = is_array($intent['section'] ?? null) ? $intent['section'] : [];
                $blk = preg_replace('/[^a-z0-9_\-]/', '', strtolower(str_replace(' ', '_', (string) ($sec['block'] ?? ''))));
                if ($blk === '') return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => 'Which section do you mean?', 'options' => array_slice(array_values(array_diff($this->intentContext($wsId, $websiteId, $site, $tv, $industry)['sections'], ['nav', 'hero', 'footer'])), 0, 4)];
                if ($intent['intent'] === 'section_move') {
                    $pos = strtolower((string) ($sec['position'] ?? '')); $ref = preg_replace('/[^a-z0-9_\-]/', '', strtolower(str_replace(' ', '_', (string) ($sec['ref'] ?? '')))) ?: null;
                    $res = $this->templates->moveSection($websiteId, $blk, $pos, $ref);
                    if (empty($res['success'])) return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => (string) $res['message'], 'options' => []];
                    $credits->debit($wsId, 1, 'builder_arthur_style', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$res['message']]]);
                    return $base + ['success' => true, 'kind' => 'section', 'applied' => 1, 'actions_applied' => 1, 'credits' => 1, 'message' => 'Done — I ' . $res['message'] . " on {$site->name}. 1 credit. Undo puts it back."];
                }
                $extras = is_array($tv['design_extras'] ?? null) ? $tv['design_extras'] : [];
                if ($intent['intent'] === 'section_hide') { $rules = ['hide_' . $blk => '[data-block="' . $blk . '"],a[href="#' . $blk . '"]{display:none!important}']; }
                else { unset($extras['hide_' . $blk]); $tv['design_extras'] = $extras; $rules = []; }
                if ($rules !== [] ? ! self::writeDesignExtras($websiteId, $rules, $tv) : ! self::writeDesignExtras($websiteId, [], $tv)) return $base + ['success' => false, 'message' => 'I could not change that section just now.'];
                DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
                $tc = \App\Engines\Builder\Support\EditorCredits::charge($wsId, 'section_toggle', $websiteId, ['block' => $blk, 'op' => $intent['intent']]);
                return $base + ['success' => true, 'kind' => 'section', 'applied' => 1, 'actions_applied' => 1, 'credits' => $tc, 'message' => 'Done — the ' . str_replace('_', ' ', $blk) . ' section is now ' . ($intent['intent'] === 'section_hide' ? 'hidden (its menu link too). Say "show the ' . str_replace('_', ' ', $blk) . ' section" to bring it back.' : 'visible again.')];
            case 'tracking':
                $tr = is_array($intent['tracking'] ?? null) ? array_filter(array_map(fn($v) => trim((string) $v), $intent['tracking'])) : [];
                if ($tr === []) return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => 'Which id should I add — a Google Analytics id (G-…), a Tag Manager id (GTM-…), a Meta pixel number or a TikTok pixel?', 'options' => []];
                $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
                $cur = (array) ($settings['tracking'] ?? []); $bad = [];
                foreach (['ga4' => '/^G-[A-Z0-9]{4,20}$/', 'gtm' => '/^GTM-[A-Z0-9]{4,12}$/', 'meta_pixel' => '/^\d{8,20}$/', 'tiktok_pixel' => '/^[A-Z0-9]{10,40}$/i'] as $k => $re) { if (! isset($tr[$k])) continue; $v = $k === 'tiktok_pixel' ? $tr[$k] : strtoupper($tr[$k]); if (preg_match($re, $v)) $cur[$k] = $v; else $bad[] = $tr[$k]; }
                if ($bad !== []) return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => '“' . implode('”, “', $bad) . '” does not look like a valid id. GA4 ids look like G-XXXXXXXX, Tag Manager like GTM-XXXXXXX, a Meta pixel is a 15–16 digit number — can you check it?', 'options' => []];
                $settings['tracking'] = $cur;
                DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
                try { \Illuminate\Support\Facades\Artisan::call('sites:inject-scripts', ['--site' => $websiteId]); } catch (\Throwable $e) {}
                return $base + ['success' => true, 'kind' => 'tracking', 'applied' => 1, 'actions_applied' => 1, 'message' => 'Done — tracking is now on every page of ' . $site->name . ' (' . implode(', ', array_map(fn($k) => ['ga4' => 'Google Analytics', 'gtm' => 'Tag Manager', 'meta_pixel' => 'Meta pixel', 'tiktok_pixel' => 'TikTok pixel'][$k] . ' ' . $cur[$k], array_keys($cur))) . ').'];
            case 'copy_edit':
                $changes = is_array($intent['copy'] ?? null) ? $intent['copy'] : [];
                if ($changes === []) { if ($normalized !== '') $request = $normalized; return null; }   // the copy model will pick the fields
                [$fields, $factKeys, $exportHtml] = $this->copyFieldsFor($websiteId, $site, $tv);
                $plan = ['kind' => 'edit', 'credits' => \App\Engines\Builder\Support\BuilderCapabilities::pricing()['text_edit'] ?? 1];
                $res = $this->applyCopyChanges($wsId, $websiteId, $site, $tv, $fields, $factKeys, $exportHtml, $changes, trim((string) ($intent['reply'] ?? '')), $plan);
                if (! empty($res['success'])) { $res['credits'] = \App\Engines\Builder\Support\EditorCredits::charge($wsId, 'text_edit', $websiteId, ['request' => mb_substr($request, 0, 200), 'fields' => $res['changes'] ?? []]); }
                if (empty($res['success'])) {   // the model named text that is not on the page: ask instead of a dead end
                    $opts = [];
                    foreach ($changes as $ch) { $k = (string) ($ch['key'] ?? ''); if ($k !== '' && ! isset($fields[$k])) { foreach (array_keys($fields) as $fk) { if (levenshtein($k, $fk) <= 4 && count($opts) < 4) $opts[] = $fk; } } }
                    $res['kind'] = 'clarify'; $res['method'] = 'clarify'; $res['options'] = [];
                    $res['message'] = "I couldn't match that to a text on {$site->name}. Tell me the exact words you see on the page and what they should become.";
                }
                return $res;
            case 'catalogue':
                $p = is_array($intent['catalogue'] ?? null) ? $intent['catalogue'] : [];
                if ($p === [] || empty($p['kind'])) { if ($normalized !== '') $request = $normalized; return null; }
                $res = app(CatalogueService::class)->execute($wsId, $websiteId, $p + ['_customer' => $request], $ctx);
                if (($res['code'] ?? '') === 'WHICH') { return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => (string) $res['message'], 'options' => array_slice((array) ($res['options'] ?? []), 0, 4)]; }
                if (($res['code'] ?? '') === 'PASS') { if ($normalized !== '') $request = $normalized; return null; }
                return array_merge($base, $res, ['kind' => 'catalogue']);   // the service's own credits win over the zero in $base
            case 'style':
                if ($normalized !== '') $request = $normalized;
                $plan = ['kind' => 'style', 'credits' => \App\Engines\Builder\Support\BuilderCapabilities::pricing()['style'] ?? 1];
                $this->selTarget = $this->selectionTargetFor($intent, $ctx, $customerWords);   // SELECTION888 (judged on the customer's words)
                // ELEMENT888: a size request on one element (the selected one, or an image the words name) — images by width, the rest by zoom
                if (preg_match('/\b(bigger|larger|smaller|enlarge|shrink|increase|decrease|reduce)\b/i', $request) && (($this->selTarget['field'] ?? '') !== '' || ! preg_match('/\b(text|font|fonts|headline|headings|titles|paragraphs|buttons|menu|nav|logo)\b/i', $request))) {
                    $szField = (string) ($this->selTarget['field'] ?? '');
                    if ($szField === '' && preg_match('/\b(image|photo|picture|portrait)\b/i', $request)) { $szField = $this->imageFieldFor($websiteId, $request); }
                    if ($szField !== '') {
                        if (! \App\Engines\Builder\Support\EditorCredits::canAfford($wsId, 'element_size')) { $this->selTarget = null; return $base + ['success' => false, 'code' => 'INSUFFICIENT_CREDITS', 'message' => \App\Engines\Builder\Support\EditorCredits::refusal('element_size')]; }
                        $szRes = $this->sizeElement($websiteId, $szField, preg_match('/\b(smaller|shrink|decrease|reduce)\b/i', $request) ? 'smaller' : 'bigger', (bool) preg_match('/\b(much|a lot|way|huge|tiny)\b/i', $request));
                        $this->selTarget = null;
                        if (empty($szRes['success'])) return $base + ['success' => false, 'kind' => 'answer', 'code' => 'ANSWER', 'message' => (string) $szRes['message']];
                        $szCost = \App\Engines\Builder\Support\EditorCredits::charge($wsId, 'element_size', $websiteId, ['request' => mb_substr($request, 0, 200), 'changes' => [$szRes['message']]]);
                        return $base + ['success' => true, 'kind' => 'style', 'applied' => 1, 'actions_applied' => 1, 'credits' => $szCost, 'message' => 'Done — I ' . $szRes['message'] . " on {$site->name}." . ($szCost > 0 ? " {$szCost} credit" . ($szCost === 1 ? '' : 's') . '.' : '') . ' Undo puts it back.'];
                    }
                }
                try { $res = $this->applySiteStyle($wsId, $websiteId, $request, $site, $tv, $plan, $isStatic); $this->selTarget = null; }
                catch (\Throwable $e) { $this->selTarget = null; Log::error('[Arthur] applySiteStyle failed', ['website' => $websiteId, 'error' => $e->getMessage()]); return $base + ['success' => false, 'code' => 'STYLE_FAILED', 'message' => 'I could not apply that design change just now — nothing on your site was altered.']; }
                if (($res['code'] ?? '') === 'STYLE_NO_TARGET') {
                    return $base + ['success' => false, 'kind' => 'clarify', 'code' => 'CLARIFY', 'method' => 'clarify', 'message' => 'Which part should change, and to what? For example the buttons, the header, the hero or the whole page — and a colour or a look.', 'options' => ['The buttons', 'The header', 'The hero', 'The whole page']];
                }
                return $res;
            default:
                // section_add, section_remove, page_add, image, video, overlay, image_edit, logo: the classic executors, with explicit wording.
                // RISK-0195 (2026-09-20): for an addition the customer's OWN words decide section vs page — the model's
                // rewrite ("… to the home page") made every shared-name section a page. The rewrite is used only when the
                // customer's words cannot be read at all (a synonym the classifier does not know).
                if (in_array($intent['intent'], ['section_add', 'page_add'], true)) {
                    $own = \App\Engines\Builder\Support\BuilderCapabilities::classify($request, $industry ?: null);
                    if ($own['kind'] === 'unsupported' && $normalized !== '') $request = $normalized;
                    return null;
                }
                if ($normalized !== '') $request = $normalized;
                return null;
        }
    }

    /** STRESS C15 (2026-09-06): remove an Arthur-added section, or an added page, from a template site. Template-native sections stay editor-only. */
    private function removeFromStaticSite(int $wsId, int $websiteId, string $request, array $plan, object $site, ?string $industry): array
    {
        $r = mb_strtolower($request);
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $added = array_values(array_filter(array_map(fn($x) => (string) ($x['type'] ?? ''), (array) ($settings['arthur_sections'] ?? []))));
        // the export is the truth: a wrapper still on the page is removable even if the record was already forgotten
        if (preg_match_all('/data-block="added_([a-z0-9_]+)"/', (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html")), $mm)) $added = array_values(array_unique(array_merge($added, $mm[1])));
        $credits = app(\App\Core\Billing\CreditService::class);
        $price = (int) (\App\Engines\Builder\Support\BuilderCapabilities::pricing()['text_edit'] ?? 1);
        if (preg_match('/\bpage\b/', $r)) {
            $slug = \App\Engines\Builder\Support\BuilderCapabilities::pageSlugFor($r, $industry);
            $row = $slug ? DB::table('pages')->where('website_id', $websiteId)->whereNotIn('slug', ['home', 'blog', 'news', ''])
                ->where(function ($q) use ($slug) { $q->where('slug', $slug)->orWhere('page_template', $slug)->orWhere('slug', str_replace('_', '-', $slug)); })->first() : null;
            if (!$row) return ['success' => false, 'code' => 'NO_SUCH_PAGE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0,
                'message' => "{$site->name} doesn't have that page, so there is nothing to remove."];
            app(\App\Engines\Builder\Services\BuilderService::class)->deletePage((int) $row->id, $wsId);
            $dir = storage_path("app/public/sites/{$websiteId}/{$row->slug}");
            if (is_dir($dir)) \Illuminate\Support\Facades\File::deleteDirectory($dir);
            $this->templates->removeNavLink($websiteId, (string) $row->slug);
            try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
            $credits->debit($wsId, $price, 'builder_arthur_remove', $websiteId, ['page' => $row->slug]);
            $msg = "Removed the " . trim(explode('/', (string) $row->title)[0]) . " page from {$site->name} and its menu link.";
            return ['success' => true, 'kind' => 'remove', 'plan' => $plan, 'applied' => 1, 'actions_applied' => 1, 'credits' => $price, 'message' => $msg, 'reply' => $msg, 'url' => "/storage/sites/{$websiteId}/index.html"];
        }
        $type = $plan['section'] ?? null;
        if ($type === null) { foreach ($added as $t) { if (str_contains($r, str_replace('_', ' ', $t)) || str_contains($r, $t)) { $type = $t; break; } } }
        if ($type === null || !in_array($type, $added, true)) {
            $labels = implode(', ', array_map(fn($t) => ucfirst(str_replace('_', ' ', $t)), $added));
            return ['success' => false, 'code' => 'NOT_REMOVABLE', 'plan' => $plan, 'applied' => 0, 'actions_applied' => 0,
                'message' => $added !== []
                    ? "I can remove the sections I added to {$site->name} ({$labels}). The template's own sections are removed in the page editor (select the section → Remove), so nothing disappears by accident."
                    : "That is part of {$site->name}'s template. Remove it in the page editor (select the section → Remove) — I only remove sections I added myself."];
        }
        $ok = $this->templates->removeSplicedSection($websiteId, $type);
        try { app(\App\Engines\Builder\Services\BuilderService::class)->removeLastSectionOfTypeFromHomePage($websiteId, $type); } // Law 11: Builder-owned persistence
        catch (\Throwable $e) { Log::warning('[Arthur] removeFromStaticSite stub: ' . $e->getMessage()); }
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        if ($ok) $credits->debit($wsId, $price, 'builder_arthur_remove', $websiteId, ['section' => $type]);
        $label = ucfirst(str_replace('_', ' ', $type));
        $msg = $ok ? "Removed the {$label} section from {$site->name}'s home page." : "I couldn't find the {$label} section on the page any more, so nothing was changed.";
        return ['success' => $ok, 'kind' => 'remove', 'plan' => $plan, 'section' => $type, 'applied' => $ok ? 1 : 0, 'actions_applied' => $ok ? 1 : 0, 'credits' => $ok ? $price : 0, 'message' => $msg, 'reply' => $msg, 'url' => "/storage/sites/{$websiteId}/index.html"];
    }
    /** Where a hand-off file should go, from the customer's words. */
    public static function mediaIntent(string $request): string
    {
        $r = mb_strtolower($request);
        if (preg_match('/\blogo\b/', $r)) return 'logo';
        if (preg_match('/\b(hero|banner|cover|header (photo|image)|main (photo|image)|background|top of the (site|page|home))\b/', $r)) return 'hero';
        if (preg_match('/\b(about|our story|story)\b/', $r)) return 'about';
        if (preg_match('/\b(team|staff|member|members|doctor|doctors|stylist|trainer|people)\b/', $r)) return 'team';
        if (preg_match('/\b(share|social|og|preview) (image|photo)\b/', $r)) return 'og';
        return 'gallery';
    }

    /** Every placement the customer names, in order: "logo … and … hero" → ['logo','hero']. */
    public static function mediaIntents(string $request): array
    {
        $r = mb_strtolower($request); $found = [];
        $pat = ['logo' => '/\blogo\b/', 'hero' => '/\b(hero|banner|cover|header (?:photo|image)|main (?:photo|image)|background|top of the (?:site|page|home))\b/', 'about' => '/\b(about|our story|story)\b/', 'team' => '/\b(team|staff|member|members|doctor|doctors|stylist|trainer|people)\b/', 'og' => '/\b(?:share|social|og|preview) (?:image|photo)\b/', 'gallery' => '/\bgallery\b/'];
        foreach ($pat as $intent => $re) { if (preg_match($re, $r, $m, PREG_OFFSET_CAPTURE)) $found[$intent] = $m[0][1]; }
        asort($found);
        return $found === [] ? ['gallery'] : array_keys($found);
    }

    /** The template fields an intent can fill on this export, in order. */
    private static function mediaTargets(string $intent, string $export): array
    {
        $onPage = fn(string $field) => str_contains($export, 'data-field="' . $field . '"');
        if ($intent === 'logo') return ['logo_url'];
        if ($intent === 'og') return ['og_image'];
        if ($intent === 'hero') return array_values(array_filter(['hero_image'], $onPage));
        if ($intent === 'about') return array_values(array_filter(['about_image', 'about_image_1', 'story_image'], $onPage));
        if ($intent === 'team') { $t = []; for ($i = 1; $i <= 8; $i++) { foreach (["member_{$i}_image", "team_{$i}_image", "doctor_{$i}_image", "staff_{$i}_image", "stylist_{$i}_image", "trainer_{$i}_image"] as $f) { if ($onPage($f)) { $t[] = $f; break; } } } return $t; }
        return preg_match_all('/data-field="(gallery_image_\d+|gallery_\d+_image|image_\d+)"/', $export, $gm) ? array_values(array_unique($gm[1])) : [];
    }

    /**
     * FILE HAND-OFF (2026-09-06): place attached media on a template site. Files are copied into the site's folder,
     * normalised to the slot by ImagePolicy, wired through TemplateService::updateField (export) + saveTemplateVariables
     * (re-render safe). Logo → text logo swapped on every page. 1 credit per request.
     */
    private function placeMedia(int $wsId, int $websiteId, string $request, array $mediaIds, object $site, array $tv, array $ctx): array
    {
        $rows = DB::table('media')->where('workspace_id', $wsId)->whereIn('id', $mediaIds)
            ->where(function ($q) { $q->where('asset_type', 'image')->orWhere('mime_type', 'like', 'image/%'); })->orderByRaw('FIELD(id,' . implode(',', array_map('intval', $mediaIds)) . ')')->get();
        if ($rows->isEmpty()) {
            return ['success' => false, 'code' => 'NO_IMAGE', 'applied' => 0, 'actions_applied' => 0,
                'message' => "The attached file isn't an image I can place (PNG, JPG, WEBP, SVG). Attach the logo or photo again and I'll put it on {$site->name}."];
        }
        $intents = self::mediaIntents($request); $intent = $intents[0];
        $export = (string) @file_get_contents(storage_path("app/public/sites/{$websiteId}/index.html"));
        // file i → the i-th named placement; extra files follow the last one. Each intent hands out its own slots in order.
        $perIntentTargets = []; $assign = [];
        foreach ($rows as $k => $row) {
            $it = $intents[min($k, count($intents) - 1)];
            if (!isset($perIntentTargets[$it])) $perIntentTargets[$it] = self::mediaTargets($it, $export);
            $assign[$k] = ['intent' => $it, 'field' => array_shift($perIntentTargets[$it])];
        }
        if (!array_filter(array_column($assign, 'field'))) {
            return ['success' => false, 'code' => 'NO_SLOT', 'applied' => 0, 'actions_applied' => 0,
                'message' => "{$site->name}'s template has no " . ($intent === 'gallery' ? 'gallery' : $intent) . " image slot on the page, so I have nowhere to put that photo. Ask me to add a gallery section first, or tell me a different place (hero, about, team)."];
        }
        $dir = storage_path("app/public/sites/{$websiteId}/media"); @mkdir($dir, 0775, true);
        $placed = []; $skipped = [];
        foreach ($rows as $k => $row) {
            $field = $assign[$k]['field'] ?? null;
            if (!$field) { $skipped[] = (string) $row->filename; continue; }
            $src = storage_path('app/public/' . preg_replace('#^/?storage/#', '', ltrim((string) $row->path, '/'))); // uploads: /uploads/x; platform: /storage/x
            if (!is_file($src)) { $alt = public_path(ltrim((string) ($row->url ?: ''), '/')); if (is_file($alt)) $src = $alt; }
            if (!is_file($src)) { $skipped[] = (string) $row->filename; continue; }
            $ext = strtolower(pathinfo((string) $row->path, PATHINFO_EXTENSION) ?: 'jpg');
            $slot = \App\Engines\Builder\Support\ImagePolicy::slotFor($field);
            $file = ($field === 'logo_url' ? 'logo' : $slot . '-' . (int) $row->id) . '.' . $ext;
            $dest = $field === 'logo_url' ? storage_path("app/public/sites/{$websiteId}/{$file}") : $dir . '/' . $file;
            if ($field === 'logo_url') { foreach (glob(storage_path("app/public/sites/{$websiteId}/logo.*")) ?: [] as $old) @unlink($old); }
            // CROP TOOL 2026-09-06: every placement has a fixed size — photos are cut to it, logos fitted onto the logo canvas
            $norm = \App\Engines\Builder\Support\ImageCrop::autoSafe($src, $slot, $dest) ?? \App\Engines\Builder\Support\ImagePolicy::normalise($src, $slot, $dest);
            if (!empty($norm['path']) && $norm['path'] !== $dest && is_file($norm['path'])) { $dest = $norm['path']; $file = basename($dest); }
            $norm['changed'] = $norm['changed'] ?? true;
            if (!is_file($dest)) { $skipped[] = (string) $row->filename; continue; }
            @chmod($dest, 0664);
            $url = ($field === 'logo_url' ? "/storage/sites/{$websiteId}/{$file}" : "/storage/sites/{$websiteId}/media/{$file}") . '?v=' . time();
            $ok = $this->templates->updateField($websiteId, $field, $url);
            if (!$ok) { $skipped[] = (string) $row->filename; continue; }
            $tv[$field] = $url;
            $placed[] = ['file' => (string) $row->filename, 'field' => $field, 'slot' => $slot, 'intent' => $assign[$k]['intent'], 'size' => ($norm['width'] && $norm['height']) ? "{$norm['width']}×{$norm['height']}" : '', 'resized' => (bool) ($norm['changed'] ?? false)];
        }
        if ($placed === []) {
            return ['success' => false, 'code' => 'NOT_PLACED', 'applied' => 0, 'actions_applied' => 0, 'message' => "I couldn't place " . implode(', ', $skipped) . " on {$site->name} — the file could not be read."];
        }
        $this->templates->saveTemplateVariables($websiteId, $tv);
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($websiteId); } catch (\Throwable $e) {}
        $price = (int) (\App\Engines\Builder\Support\BuilderCapabilities::pricing()['text_edit'] ?? 1);
        app(\App\Core\Billing\CreditService::class)->debit($wsId, $price, 'builder_arthur_media', $websiteId, ['placed' => array_column($placed, 'field'), 'media' => $mediaIds]);
        $where = ['logo' => 'as the logo in the header and footer on every page', 'hero' => 'as the hero photo', 'about' => 'in the about section', 'team' => 'on the team cards', 'og' => 'as the social share image', 'gallery' => 'in the gallery'];
        $parts = [];
        foreach ($placed as $p) $parts[] = $p['file'] . ($p['resized'] ? " (resized to {$p['size']})" : '') . ' ' . ($where[$p['intent']] ?? 'on the site');
        $msg = 'Placed ' . implode('; ', $parts) . " on {$site->name}." . ($skipped !== [] ? ' Not placed: ' . implode(', ', $skipped) . ' (no free slot left).' : '');
        Log::info('[Arthur] placeMedia', ['website' => $websiteId, 'intent' => $intent, 'placed' => $placed, 'skipped' => $skipped]);
        return ['success' => true, 'kind' => 'media', 'intent' => $intent, 'applied' => count($placed), 'actions_applied' => count($placed), 'placed' => $placed, 'skipped' => $skipped,
            'credits' => $price, 'message' => $msg, 'reply' => $msg, 'url' => "/storage/sites/{$websiteId}/index.html", 'delegated' => true];
    }
    /**
     * CROP TOOL (2026-09-06): a customer photo dropped into a template slot at build time is cut to that slot's fixed
     * size (a derived copy under storage/app/public/crops); the original upload is untouched. Non-local or unreadable
     * images are used as they are.
     */
    public static function cropPoolImageForSlot(string $url, string $field): string
    {
        try {
            $p = (string) parse_url($url, PHP_URL_PATH);
            if (!preg_match('#^/storage/([A-Za-z0-9_\-./]+)$#', $p, $m) || str_contains($m[1], '..') || str_starts_with($m[1], 'template-images/') || str_starts_with($m[1], 'builder-heroes/') || str_starts_with($m[1], 'crops/')) return $url;
            $src = storage_path('app/public/' . $m[1]);
            if (!is_file($src)) return $url;
            $slot = \App\Engines\Builder\Support\ImagePolicy::slotFor($field);
            if (\App\Engines\Builder\Support\ImageCrop::targetFor($slot) === null) return $url;
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'jpg');
            $dest = storage_path('app/public/crops/' . $slot . '-' . substr(md5($m[1]), 0, 12) . '.' . $ext);
            if (!is_file($dest) || !is_file(preg_replace('/\.[a-z0-9]+$/i', '.png', $dest))) {
                $r = \App\Engines\Builder\Support\ImageCrop::autoSafe($src, $slot, $dest);
                if (!$r || empty($r['path']) || !is_file($r['path'])) return $url;
                $dest = $r['path'];
            } elseif (!is_file($dest)) { $dest = preg_replace('/\.[a-z0-9]+$/i', '.png', $dest); }
            return '/storage/' . ltrim(str_replace(storage_path('app/public/'), '', $dest), '/');
        } catch (\Throwable $e) { return $url; }
    }
}
