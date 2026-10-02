<?php

namespace App\Core\ImageIntelligence;

/**
 * ImagePromptCompiler — turns a validated ImageBlueprint into a concrete
 * provider request (final prompt + size + quality) and enforces the typography
 * policy deterministically on top of the LLM's provider_prompt.
 *
 * Typography modes:
 *  - none / separate_overlay → the image must be TEXT-FREE with reserved
 *    negative space; overlay copy is returned for the Studio typography engine.
 *  - baked_in → the short headline is embedded (quoted) in the prompt.
 */
class ImagePromptCompiler
{
    /**
     * REGEN-1: the text, as parameters the image model can follow. Built from the brief's own words (style, placement),
     * the brand palette and the design direction - the same brief the renderer honours, expressed for a painter.
     */
    public static function textSpec(string $headline, array $ts, array $bp): string
    {
        $style = (string) ($ts['style'] ?? ''); $place = (string) ($ts['placement'] ?? '');
        $palette = array_values(array_filter(array_map('strval', (array) ($bp['color_palette'] ?? []))));
        preg_match_all('/#[0-9a-fA-F]{6}\b/', $style, $hx); $hexes = array_values(array_unique($hx[0] ?? []));
        $known = ['Playfair Display', 'DM Sans', 'Manrope', 'Inter', 'Syne', 'Anton', 'Archivo Black', 'Bebas Neue', 'Oswald', 'Fraunces', 'Cormorant Garamond', 'Libre Baskerville', 'Lora', 'Montserrat', 'Poppins', 'Raleway', 'Lato', 'Roboto', 'Cinzel', 'Merriweather', 'Barlow Condensed', 'Space Grotesk'];
        $fonts = []; foreach ($known as $k) { if (stripos($style, $k) !== false) $fonts[] = $k; }
        $serif = (bool) preg_match('/\b(serif|editorial|luxury|elegant|refined|classic)\b/i', $style) && ! preg_match('/\bsans[- ]serif\b/i', $style);
        $bold  = (bool) preg_match('/\b(bold|impact|punchy|loud|oversized|block|condensed)\b/i', $style);
        $face  = $fonts ? $fonts[0] . ($serif ? ' (a refined high-contrast serif)' : ' (a clean geometric sans-serif)')
               : ($serif ? 'a refined high-contrast editorial serif like Playfair Display' : ($bold ? 'a heavy condensed sans-serif like Anton or Bebas Neue, all capitals' : 'a clean modern sans-serif like Manrope'));
        $weight = preg_match('/\b(light|thin|delicate|hairline)[- ]?(weight|type|typeface|font|serif|sans|lettering|headline|capitals)\b/i', $style) ? 'light weight' : ($bold ? 'heavy weight' : 'medium-to-bold weight');
        // the design direction the owner chose (or the industry's provisional one) fills what the brief leaves unsaid
        $dir = null; try { $__id = (string) ($bp['_context']['design_direction_id'] ?? ''); if ($__id !== '') $dir = \App\Core\Brand\DesignDirections::get($__id); } catch (\Throwable) {}
        $pv = is_array($dir['preview'] ?? null) ? $dir['preview'] : [];
        if (! $fonts && ! empty($pv['font'])) {
            $__pf = trim((string) preg_replace("/^['\"]?([^'\",]+)['\"]?.*$/", '$1', (string) $pv['font']));
            if ($__pf !== '') { $face = $__pf . ($serif ? ' (a refined high-contrast serif)' : ' (as the brand\'s design direction sets it)'); }
        }
        $caseTxt = preg_match('/\b(all[- ]caps|capitals|uppercase)\b/i', $style) || (! $fonts && (($pv['case'] ?? '') === 'upper' || $bold)) ? ' in capitals' : '';
        $lum = function (string $hex): float { $h = ltrim($hex, '#'); return strlen($h) === 6 ? (0.2126 * hexdec(substr($h, 0, 2)) + 0.7152 * hexdec(substr($h, 2, 2)) + 0.0722 * hexdec(substr($h, 4, 2))) / 255 : 0.5; };
        $colour = $hexes[0] ?? null;
        if (! $colour) { foreach ($palette as $c) { if (preg_match('/#[0-9a-fA-F]{6}/', $c, $m) && $lum($m[0]) > 0.08 && $lum($m[0]) < 0.85) { $colour = $m[0]; break; } } }   // the accent, never the ink or the paper
        if (! $colour && ($pv['text'] ?? '') === 'light') $colour = '#FFFFFF';
        $colourTxt = $colour ? 'in ' . self::colourName($colour) . " (exactly {$colour})" : 'in a single high-contrast colour that reads clearly against the background';
        if (preg_match('/\b(gold|golden|metallic)\b/i', $style)) $colourTxt .= ' with a warm metallic gold finish';
        if ($colour && $lum($colour) < 0.85) $colourTxt .= ' - not white, not cream, not grey: every letter in that colour';
        if ($colour && $lum($colour) >= 0.85) $colourTxt .= ' - bright and clean, never tinted by the scene';
        // the zone only: the brief's placement prose names the copy list and the renderer's layout, none of which the painter should see
        $pl = strtolower($place);
        $row = preg_match('/\b(top|upper)\b/', $pl) ? 'upper' : (preg_match('/\b(bottom|lower|band)\b/', $pl) ? 'lower' : (preg_match('/\b(middle|centre|center)\b/', $pl) ? 'middle' : ''));
        $col = preg_match('/\bleft\b/', $pl) ? 'left' : (preg_match('/\bright\b/', $pl) ? 'right' : (preg_match('/\b(centre|center|centred|centered)\b/', $pl) ? 'centre' : ''));
        $zone = trim($row . ' ' . $col) !== '' ? 'the ' . trim($row . ' ' . $col) . ' of the frame, inside generous margins' : 'the quietest area of the frame';
        $where = $zone . (preg_match('/\b(two|2)\s+(short\s+)?lines\b/i', $place) ? ', set on two short lines' : '') . ', on the calmest, most shadowed part of the picture, away from faces and hands';
        $letters = implode(' ', preg_split('//u', preg_replace('/\s+/', ' ', $headline), -1, PREG_SPLIT_NO_EMPTY));
        $emph = '';
        if (preg_match('/[\'"\x{2018}\x{2019}\x{201C}\x{201D}]([A-Za-z][\w-]*)[\'"\x{2018}\x{2019}\x{201C}\x{201D}]\s+(?:optionally\s+)?(?:the\s+only\s+)?(?:metallic|accent|italic|highlight)/iu', $style, $em)) $emph = " The word \"{$em[1]}\" may be set in italic as the one accented word; every other word upright.";
        $rule = preg_match('/\b(rule|hairline|underline|divider)\b/i', $style) ? ' A short thin horizontal rule in the same colour sits directly beneath the headline.' : '';
        return 'TEXT IN THE IMAGE - non-negotiable: paint exactly ONE line of text, the headline "' . $headline . '", spelled exactly, letter by letter: ' . $letters
            . '. Nothing else is written anywhere: no sub-line, no tagline, no caption, no small print, no logo, no watermark, no signage, no numbers. '
            . 'Typeface: ' . $face . ', ' . $weight . $caseTxt . ', correctly kerned, crisp vector-sharp edges, perfectly legible at feed size. '
            . 'Colour: ' . $colourTxt . '. Placement: ' . $where . '. The words never cross a face, hands or the plated food; the composition leaves that area calm for them. '
            . 'Size: the line spans roughly half of the image width' . ($bold ? ' (larger, poster-like, is welcome)' : '') . '. The text is part of the design, like a printed poster, not a sticker or a sign in the scene.' . $emph . $rule;
    }

    /** A plain colour name for a hex - image models follow words better than codes. */
    public static function colourName(string $hex): string
    {
        $h = ltrim($hex, '#'); if (strlen($h) !== 6) return 'the colour ' . $hex;
        $r = hexdec(substr($h, 0, 2)) / 255; $g = hexdec(substr($h, 2, 2)) / 255; $b = hexdec(substr($h, 4, 2)) / 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min;
        if ($d < 0.08) return $l > 0.9 ? ($r > $b + 0.02 ? 'soft cream white' : 'pure white') : ($l > 0.75 ? 'soft cream white' : ($l > 0.45 ? 'mid grey' : ($l > 0.15 ? 'charcoal' : 'near black')));
        $s = $d / (1 - abs(2 * $l - 1));
        $hue = $max === $r ? fmod(($g - $b) / $d, 6) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4); $hue = fmod($hue * 60 + 360, 360);
        $gold = $hue >= 28 && $hue < 56 && $s > 0.3 && $l > 0.3 && $l < 0.7;
        $name = $gold ? 'warm metallic gold (old gold, antique brass)' : ($hue < 15 || $hue >= 345 ? 'red' : ($hue < 40 ? ($l > 0.5 ? 'warm amber orange' : 'burnt orange') : ($hue < 55 ? 'warm golden yellow' : ($hue < 70 ? 'yellow' : ($hue < 160 ? 'green' : ($hue < 200 ? 'teal' : ($hue < 250 ? ($l > 0.6 ? 'light sky blue' : 'deep blue') : ($hue < 290 ? 'violet purple' : ($hue < 345 ? 'magenta pink' : 'red')))))))));
        if ($l < 0.25 && $name !== 'near black') $name = 'deep ' . $name;
        if ($l > 0.8 && stripos($name, 'light') === false) $name = 'pale ' . $name;
        return $name;
    }

    private const NO_TEXT = ' Strict rule: NO text, NO words, NO letters, NO numbers, NO logos, NO watermarks, NO captions, NO typography of any kind — pure visual composition only, with clean deliberate negative space reserved for text to be added later.';

    /**
     * @return array{
     *   provider_prompt:string, size:string, quality:string, provider:string, model:string,
     *   typography:array, overlay:?array
     * }
     */
    /** BANNER-5: the frame's shape never reads as a scenery request ("landscape photograph" -> "horizontal photograph"). */
    public static function orientationWords(string $p): string
    {
        $p = (string) preg_replace('/\blandscape(?=[\s-]+(?:photograph|photo|image|format|orientation|composition|frame|shot|banner|layout|crop|aspect)\b)/iu', 'horizontal', $p);
        $p = (string) preg_replace('/\b((?:wide|feed-safe|\d+:\d+)[,\s-]+(?:feed-safe[,\s-]+)?)landscape\b(?![\s-]+(?:of|with|scene|view|vista|painting)\b)/iu', '$1horizontal', $p);
        $p = (string) preg_replace('/\b(?:in|as) (?:a )?landscape\b(?![\s-]+(?:of|with|scene|view|vista|painting)\b)/iu', 'in a horizontal frame', $p);
        return $p;
    }

    public function compile(array $bp): array
    {
        // F-STUDIO-PS-PROMPT-ASSEMBLY (2026-09-03): the LLM reasoner is instructed
        // (system prompt) to make provider_prompt a typography/NO-TEXT note for mode
        // none/separate_overlay, so it frequently returns ONLY "…NO text… reserve
        // negative space" and DROPS the actual scene. Trusting that verbatim sent an
        // essentially subject-less brief to the image model — the rich reasoning
        // (subject/composition/scene/lighting/mood/colour/brand) was computed then
        // discarded, gutting the "one prompt → professional result" promise. Fix:
        // assemble the provider prompt DETERMINISTICALLY from the structured blueprint
        // fields (parity with compileFromBrief), independent of whether the LLM put a
        // rich string in provider_prompt. The LLM's own provider_prompt is folded in
        // ONLY when it adds scene detail beyond a NO-TEXT note (avoids duplication).
        $llmPrompt = trim((string) ($bp['provider_prompt'] ?? ''));
        $llmIsThin = $llmPrompt === ''
            || (stripos($llmPrompt, 'no text') !== false || stripos($llmPrompt, 'no words') !== false || stripos($llmPrompt, 'no letters') !== false)
            && stripos($llmPrompt, 'composition') === false && stripos($llmPrompt, 'lighting') === false;
        $parts = [];
        // RFC-0009 P2: a named platform agent is identified by her registry facts, first, verbatim.
        // No appearance is ever composed here; likeness is promised only when a reference can travel.
        $subj = is_array($bp['_context']['subject_reference'] ?? null) ? $bp['_context']['subject_reference'] : null;
        if ($subj && ! empty($subj['prompt_facts'])) {
            $parts[] = 'The person shown is ' . $subj['prompt_facts'] . ' — identify her by name and role only; do not invent her appearance'
                . (! empty($subj['reference_supported']) && ! empty($subj['portrait_url']) ? '; match the reference portrait exactly' : '');
        }
        if (! $llmIsThin) { $parts[] = $llmPrompt; }
        if (($v = trim((string) ($bp['subject'] ?? ''))) !== '') {
            // the reasoner echoes the identity facts as the subject — say them once
            if (! $subj || strcasecmp(rtrim($v, " ."), rtrim((string) ($subj['prompt_facts'] ?? ''), " .")) !== 0) { $parts[] = $v; }
        }
        if (($v = trim((string) ($bp['composition'] ?? ''))) !== '')      { $parts[] = 'Composition: ' . $v; }
        if (($v = trim((string) ($bp['scene'] ?? ''))) !== '')            { $parts[] = 'Scene: ' . $v; }
        if (($v = trim((string) ($bp['visual_hierarchy'] ?? ''))) !== '') { $parts[] = 'Visual hierarchy: ' . $v; }
        if (($v = trim((string) ($bp['lighting'] ?? ''))) !== '')         { $parts[] = 'Lighting: ' . $v; }
        if (($v = trim((string) ($bp['mood'] ?? ''))) !== '')             { $parts[] = 'Mood: ' . $v; }
        $__colors = array_values(array_filter(array_map('strval', (array) ($bp['color_palette'] ?? []))));
        if ($__colors) { $parts[] = 'Colour palette: ' . implode(', ', array_slice($__colors, 0, 5)); }
        if (($v = trim((string) ($bp['brand_application'] ?? ''))) !== '') { $parts[] = $v; }
        // RFC-0009 P5 (2026-09-16): every part is trimmed of its own trailing full stop before the
        // '. ' join — the LLM's provider_prompt usually ends in '.', and joining it produced '..'
        // (EV-1054). The part ORDER above is the contract (pinned by CompilerAssemblyTest).
        $parts = array_values(array_filter(array_map(fn ($p) => rtrim(trim((string) $p), " ."), $parts), fn ($p) => $p !== ''));
        $prompt = implode('. ', $parts);
        if ($prompt !== '') { $prompt .= '.'; }
        if ($prompt === '') { $prompt = $llmPrompt; } // absolute fallback — never send empty

        // RFC-0009 P4 (2026-09-16): factual grounding flags — never rewrite the customer's intent,
        // only remove what the MODEL invented and say so. `has_logo` is resolved from the brand kit
        // (ImageIntelligenceService::resolveContext); `logo_requested` is true when the customer's
        // own words asked for one. An invented logo (no asset, not requested) is dropped from the
        // prompt and audited; a requested logo without an asset is KEPT and flagged so the caller
        // can tell the customer instead of silently drawing a placeholder.
        $flags   = [];
        $hasLogo = (bool) ($bp['_context']['has_logo'] ?? false);
        $logoReq = (bool) ($bp['_context']['logo_requested'] ?? false);
        if (! $hasLogo && preg_match('/\b(logo|watermark)\b/i', $prompt)) {
            if ($logoReq) {
                $flags[] = 'logo_requested_no_logo_asset';
            } else {
                $stripped = preg_replace('/(?:^|(?<=\. ))[^.]*\b(?:logo|watermark)\b[^.]*\.?/i', '', $prompt);
                $stripped = trim(preg_replace('/\s{2,}/', ' ', (string) $stripped));
                if ($stripped !== '' && stripos($stripped, 'logo') === false && stripos($stripped, 'watermark') === false) {
                    $prompt = rtrim($stripped, " .") . '.';
                    $flags[] = 'logo_clause_removed';
                } else {
                    $flags[] = 'logo_clause_present_unresolved';
                }
            }
        }
        // Unsupported claims are surfaced, not rewritten: the reasoner is told to put anything it
        // could not verify into historical_or_factual_constraints prefixed 'UNVERIFIED:'.
        $unverified = array_values(array_filter(array_map('strval', (array) ($bp['historical_or_factual_constraints'] ?? [])), fn ($c) => stripos($c, 'UNVERIFIED:') === 0));
        if ($unverified) { $flags[] = 'unverified_claims'; }
        // Fold blueprint negative_constraints into the sent prompt (compile() previously
        // dropped them; only compileFromBrief surfaced them). These genuinely steer the model.
        $__neg = array_values(array_filter(array_map('strval', (array) ($bp['negative_constraints'] ?? []))));
        if ($__neg) { $prompt = rtrim($prompt, '. ') . '. Avoid: ' . implode('; ', $__neg) . '.'; }

        $ts     = $bp['typography_strategy'] ?? ['mode' => 'none'];
        $mode   = $ts['mode'] ?? 'none';

        if ($mode === 'baked_in') {
            $headline = trim((string) ($ts['headline'] ?? ''));
            // TEXT-ONE-LINE-1 (Owner 2026-09-26: "it is adding unnecessary giberish texts"): an image model draws ONE short,
            // large line reliably and turns anything smaller into gibberish (the gold sub-line on the Chef Red banner). Baked-in
            // text is the headline ONLY: every other quoted line, with the words that introduce it, leaves the prompt, and the
            // model is told the headline is the only text allowed. The dropped copy belongs in the post caption.
            $__q = '/(?:,?\s*(?:with|and|plus)?\s*(?:an?\s+)?(?:small(?:er)?\s+|thin\s+|gold\s+|tiny\s+)*(?:sub-?line|sub-?headline|subtitle|tag-?line|strap-?line|caption|secondary line|small text|fine print)\b[^"\x{201C}.;:]{0,40}:?\s*)?(?:"([^"]{2,160})"|\x{201C}([^\x{201D}]{2,160})\x{201D}|(?<=[\s:])\'([^\']{2,160})\'(?=[\s,.;]|$))/u';
            if ($headline === '' && preg_match($__q, $prompt, $__hm)) { $headline = trim((string) ($__hm[1] ?: ($__hm[2] ?? '') ?: ($__hm[3] ?? ''))); }
            if ($headline !== '') {
                $__dropped = [];
                $prompt = preg_replace_callback($__q, function ($m) use ($headline, &$__dropped) {
                    $t = trim((string) ($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? '')));
                    if (strcasecmp(rtrim($t, ' .!'), rtrim($headline, ' .!')) === 0) return $m[0];
                    $__dropped[] = $t; return '';
                }, $prompt);
                $prompt = preg_replace('/\s{2,}/', ' ', (string) $prompt);
                if ($__dropped) { $flags[] = 'secondary_text_moved_to_caption'; }
                $prompt = rtrim($prompt, '. ') . '. The ONLY text anywhere in the image is the headline "' . $headline . '", spelled exactly, large and legible. '
                    . 'No sub-line, tagline, caption, small print, labels, signage, numbers or any other letters or words.';
            }
            if ($headline !== '' && stripos($prompt, $headline) === false) {
                $prompt = rtrim($prompt, '. ') . '. Render the exact short headline text "' . $headline . '" '
                    . (($ts['placement'] ?? '') !== '' ? 'placed ' . $ts['placement'] . ', ' : '')
                    . 'in a clean, correctly-spelled, high-contrast, professionally-kerned '
                    . (($ts['style'] ?? '') !== '' ? $ts['style'] . ' ' : '') . 'typeface. Do not add any other text.';
            }
            // REGEN-1: the second attempt is ONE generation with the words painted in - every parameter the brief holds is
            // spelled out (the exact words, letter by letter; typeface family and weight; colour; placement away from the
            // subject; size; nothing else written), and every 'no text' instruction from the first attempt is removed.
            if (! empty($bp['_context']['force_baked_in']) && $headline !== '') {
                // the text first, then a condensed scene: the painter reads the words before the picture
                $__cut = fn ($v, int $n) => rtrim(trim(preg_replace('/\s+/', ' ', (string) $v)), ' .');
                $__sc = [];
                foreach ([['subject', 260], ['composition', 240], ['scene', 220], ['lighting', 160], ['mood', 120]] as [$__k, $__n]) {
                    $__v = $__cut($bp[$__k] ?? '', $__n); if ($__v === '') continue;
                    if (mb_strlen($__v) > $__n) {   // cut at a sentence end, else a clause, else a word - never mid-phrase
                        $__v = mb_substr($__v, 0, $__n);
                        $__s = mb_strrpos($__v, '. '); $__c = max((int) mb_strrpos($__v, ', '), (int) mb_strrpos($__v, '; ')); $__w = (int) mb_strrpos($__v, ' ');
                        $__v = $__s !== false && $__s > 40 ? mb_substr($__v, 0, $__s) : ($__c > 40 ? mb_substr($__v, 0, $__c) : mb_substr($__v, 0, max(40, $__w)));
                        $__v = rtrim($__v, ' ,;.');
                    }
                    $__sc[] = ($__k === 'subject' ? '' : ucfirst($__k) . ': ') . $__v;
                }
                $__hexOnly = []; foreach ($__colors as $__c0) { if (preg_match('/#[0-9a-fA-F]{6}/', $__c0, $__hm)) $__hexOnly[] = $__hm[0] . ' ' . self::colourName($__hm[0]); }
                if ($__hexOnly) $__sc[] = 'Colour palette: ' . implode(', ', array_slice($__hexOnly, 0, 4));
                $prompt = implode('. ', $__sc) . '.';
                $prompt = (string) preg_replace('/[^.]*\b(no\s+text|text-?free|no\s+words|no\s+letters|no\s+typography|without\s+(any\s+)?(embedded\s+|visible\s+|drawn\s+)?(text|letters|words|typography)|free\s+of\s+(text|letters|words)|negative\s+space\s+for[^.]*text|reserve[^.]*(space|room)[^.]*|placed\s+later|typeset\s+over|typography\s+engine|reserved\s+(text|headline)\s+(area|block)|for\s+the\s+headline\s+block|text\s+side)[^.]*\.?/i', '', $prompt);
                $prompt = trim((string) preg_replace('/\s{2,}/', ' ', $prompt));
                $prompt = self::textSpec($headline, $ts, $bp) . ' SCENE: ' . ltrim($prompt) . ' Avoid: watermark; logos; any second line of text; letters that are not part of the headline.';
            }
        } else {
            // none OR separate_overlay → force text-free image (only append if not already stated)
            if (stripos($prompt, 'no text') === false && stripos($prompt, 'no words') === false) {
                $prompt = rtrim($prompt, '. ') . '.' . self::NO_TEXT;
            }
            // LAYOUT-A1c: no sentence may ask the model to paint the headline or any words
            if ($mode === 'separate_overlay') {
                $__hl = trim((string) ($ts['headline'] ?? ''));
                $__exq = array_values(array_filter(array_map('strval', (array) ($bp['_context']['exact_text'] ?? []))));
                $__parts = preg_split('/(?<=[.!?])\s+/u', $prompt) ?: [$prompt];
                $__parts = array_filter($__parts, function ($s) use ($__hl, $__exq) {
                    if (stripos($s, 'Strict rule: NO text') !== false) return true;
                    if ($__hl !== '' && mb_stripos($s, $__hl) !== false) return false;
                    // BANNER-2: 'render exactly one line of text, verbatim: "Fall Menu Now Served"' slipped past the list below
                    foreach ($__exq as $__q) if (mb_stripos($s, $__q) !== false) return false;
                    if (preg_match('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]{2,80}["\x{201C}\x{201D}]|\bverbatim\b|\bcorrectly spelled\b/iu', $s)) return false;
                    if (preg_match('/\b(render|paint|print|draw|letter|typeset|inscribe|spell|emboss|overlay)\w*\b[^.]*\b(headline|text|words?|title|caption|lettering|slogan|tagline)\b/i', $s) && stripos($s, 'no text') === false) return false;
                    if (preg_match('/\bbaked[- ]in\b/i', $s)) return false;
                    if (preg_match('/\b(include|add|place|write|display|feature|show)\b[^.]*\b(headline|text|words|title|caption|lettering)\b/i', $s)) return false;
                    return true;
                });
                $prompt = implode(' ', $__parts);
                if (stripos($prompt, 'Strict rule: NO text') === false) $prompt = rtrim($prompt, '. ') . '.' . self::NO_TEXT;   // BANNER-2: the filter never drops the rule itself
            }
            // LAYOUT-A1b: name the empty side so the layout's text never lands on a face
            if ($mode === 'separate_overlay') {
                $__pl = mb_strtolower((string) ($ts['placement'] ?? '') . ' ' . (string) ($ts['style'] ?? ''));
                $__side = str_contains($__pl, 'right') && ! str_contains($__pl, 'left') ? 'right' : 'left';
                $__other = $__side === 'left' ? 'right' : 'left';
                $prompt .= " Frame rule: every person, face and main subject sits in the {$__other} 45% of the frame; the {$__side} 55% is calm, softly lit, out-of-focus background only (no people, no heads, no hands, no faces there), darkening gently toward the {$__side} edge.";
            }
        }

        $prompt = self::orientationWords($prompt);   // BANNER-5

        // overlay instructions returned for the design/typography layer (fidelity path)
        $overlay = null;
        if ($mode === 'separate_overlay') {
            $overlay = [
                'headline'        => (string) ($ts['headline'] ?? ''),
                'supporting_copy' => array_values((array) ($ts['supporting_copy'] ?? [])),
                'placement'       => (string) ($ts['placement'] ?? 'upper-left negative space'),
                'style'           => (string) ($ts['style'] ?? ''),
                'color_palette'   => array_values((array) ($bp['color_palette'] ?? [])),
                'direction_id'    => (string) ($bp['_context']['design_direction_id'] ?? ''),   // LAYOUT-A1
                'note'            => 'Render this copy with real HTML/canvas/SVG typography in the Studio layer for exact fidelity — do NOT ask the image model to draw it.',
            ];
        }

        // RFC-0009 P5 — exact-text FIDELITY (ours) is separate from text RENDERING (the provider's).
        // Every quoted string the customer typed must survive verbatim: in the sent prompt for
        // baked_in, or in the overlay copy for separate_overlay (drawn by Studio's typography
        // layer). A miss is flagged, never patched by rewriting the customer's words.
        $exactText = array_values(array_filter(array_map('strval', (array) ($bp['_context']['exact_text'] ?? []))));
        $exactMissing = [];
        foreach ($exactText as $t) {
            $inPrompt  = stripos($prompt, $t) !== false;
            $inOverlay = $overlay && (stripos((string) $overlay['headline'], $t) !== false || stripos(implode("\n", $overlay['supporting_copy']), $t) !== false);
            if (! $inPrompt && ! $inOverlay) { $exactMissing[] = $t; }
        }
        if ($exactMissing) { $flags[] = 'exact_text_missing'; }
        // A headline the customer asked for must exist somewhere: baked into the prompt or in the overlay copy.
        if (! empty($bp['_context']['headline_requested']) && trim((string) ($ts['headline'] ?? '')) === '') { $flags[] = 'headline_requested_missing'; }

        return [
            'provider_prompt' => $prompt,
            'size'            => $this->size($bp),
            'quality'         => in_array(strtolower((string) ($bp['quality'] ?? 'medium')), ['low','medium','high'], true) ? strtolower((string) $bp['quality']) : 'medium',
            'provider'        => (string) ($bp['provider'] ?? 'openai'),
            'model'           => (string) ($bp['model'] ?? 'gpt-image-1'),
            'typography'      => $ts,
            'overlay'         => $overlay,
            // RFC-0009 audit: what the compiler decided, for the caller and the ledger of intent.
            'assembly_version'    => 'rfc0009-v1',
            'flags'               => $flags,
            'unverified_claims'   => $unverified,
            'exact_text'          => $exactText,
            'exact_text_missing'  => $exactMissing,
            // RFC-0009 P2: identity as resolved from the registry, and the truthful capability state.
            'subject_identity'    => $subj ? array_intersect_key($subj, array_flip(['kind', 'slug', 'name', 'title', 'portrait_url', 'portrait_authorised', 'reference_supported', 'limitation'])) : null,
            'reference_images'    => ($subj && ! empty($subj['reference_supported']) && ! empty($subj['portrait_url'])) ? [$subj['portrait_url']] : [],
        ];
    }

    /**
     * WAVE 3 — compile DIRECTLY from a Creative888 image brief (the enriched
     * BlueprintService::getImageBlueprint output), with NO second creative
     * reasoning. Studio technical realization only: fold the brief's
     * already-reasoned creative direction (composition / lighting / mood / brand
     * application) into ONE provider prompt, then run the SAME deterministic
     * compile() above (typography enforcement, size snap, quality). Additive and
     * dormant — the live generate() path is unchanged, so external behavior,
     * providers, credits and pricing are untouched. Proves Studio can execute
     * from Creative888 without ImageReasoningService.
     */
    public function compileFromBrief(array $brief): array
    {
        $parts = [trim((string) ($brief['provider_prompt'] ?? $brief['enhanced_prompt'] ?? ''))];
        if (($c = trim((string) ($brief['composition'] ?? ''))) !== '')       $parts[] = 'Composition: ' . $c;
        if (($l = trim((string) ($brief['lighting'] ?? ''))) !== '')          $parts[] = 'Lighting: ' . $l;
        if (($m = trim((string) ($brief['mood'] ?? ''))) !== '')              $parts[] = 'Mood: ' . $m;
        if (($b = trim((string) ($brief['brand_application'] ?? ''))) !== '') $parts[] = $b;

        $bp = [
            'provider_prompt'     => implode('. ', array_filter($parts)),
            'quality'             => $brief['quality'] ?? 'medium',
            'dimensions'          => $brief['dimensions'] ?? ['width' => 1024, 'height' => 1024],
            'typography_strategy' => $brief['typography_strategy'] ?? ['mode' => 'none'],
            'color_palette'       => $brief['color_palette'] ?? [],
            'provider'            => $brief['provider'] ?? 'openai',
            'model'               => $brief['model'] ?? 'gpt-image-1',
        ];

        $out = $this->compile($bp);
        // Surface the Creative888-owned negative constraints (text-free enforcement
        // is already applied deterministically by compile()'s typography policy).
        $out['negative_constraints'] = array_values(array_filter(
            array_map('strval', (array) ($brief['negative_constraints'] ?? []))
        ));
        $out['from_brief'] = true;
        return $out;
    }

    private function size(array $bp): string
    {
        $w = (int) ($bp['dimensions']['width'] ?? 1024);
        $h = (int) ($bp['dimensions']['height'] ?? 1024);
        $supported = ['1024x1024', '1024x1536', '1536x1024'];
        $s = $w . 'x' . $h;
        return in_array($s, $supported, true) ? $s : '1024x1024';
    }
}
