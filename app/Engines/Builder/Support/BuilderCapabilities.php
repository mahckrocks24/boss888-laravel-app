<?php

namespace App\Engines\Builder\Support;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\ArthurService;

/**
 * BUILDER CAPABILITIES (2026-09-06) — the ONE list of what Arthur can add to an existing website, what each
 * addition costs, and what the limits are. Read by Arthur (to act), by Sarah (to delegate and to answer
 * "can you add…"), by the editor's /library endpoint, and by the tests. Nothing here is invented at call
 * time: pages come from ArthurService::PAGE_TEMPLATE_CATALOGUE, sections from SectionSchema.
 *
 * Boss's rule (2026-09-06): Sarah never builds — she asks Arthur. Arthur never codes from scratch — he uses
 * these templates, in the site's current palette, with the copy rewritten for the business.
 */
final class BuilderCapabilities
{
    /** Credits per action. Reviewed by the Owner; override via config('builder.pricing'). */
    public const PRICING = ['page' => 5, 'section' => 2, 'text_edit' => 1, 'style' => 1, 'draft' => 10, // DEC-0045: first draft 10
        // EDITOR ACTIONS (2026-09-15): every change in the editor is priced here, chat and panel alike; 0 = free
        // OWNER RULE 2026-09-15: manual edits are free — inline text, image replace, logo, palette, layout; Arthur's work is priced.
        'catalogue' => 1, 'inline' => 0, 'image_replace' => 0, 'logo' => 0, 'palette' => 0, 'layout' => 0, 'section_move' => 1, 'section_toggle' => 1, 'element_move' => 1, 'element_align' => 1, 'element_size' => 1, 'element_effect' => 1, 'tracking' => 0, 'export' => 0, 'undo' => 0];

    /**
     * Sections Arthur can add to an existing page. Synonyms are what customers say; the type is what the
     * renderer draws. Order matters: first match wins in classify().
     */
    public const SECTIONS = [
        'travel_quiz'     => ['label' => 'Trip planner quiz',           'synonyms' => ['travel quiz', 'trip quiz', 'customer quiz', 'trip planner', 'plan my trip', 'plan your trip', 'inquiry wizard', 'booking wizard', 'quote wizard', 'trip inquiry', 'trip finder', 'quiz'], 'industries' => ['travel_agency', 'hotel', 'resort', 'short_term_rental', 'event_venue']],
        'booking_form'    => ['label' => 'Booking / appointment form',  'synonyms' => ['booking', 'book now', 'appointment', 'appointments', 'reservation', 'reserve', 'schedule a', 'consultation form']],
        'events_calendar' => ['label' => 'Events / classes calendar',   'synonyms' => ['calendar', 'events', 'classes', 'timetable', 'schedule', "what's on", 'whats on', 'upcoming']],
        'pricing'         => ['label' => 'Pricing table',               'synonyms' => ['pricing', 'prices', 'price list', 'plans', 'packages', 'rates', 'membership']],
        'faq'             => ['label' => 'FAQ',                          'synonyms' => ['faq', 'faqs', 'questions', 'q&a']],
        'testimonials'    => ['label' => 'Testimonials / reviews',       'synonyms' => ['testimonial', 'testimonials', 'reviews', 'what clients say']],
        'team'            => ['label' => 'Team',                         'synonyms' => ['team', 'staff', 'our people', 'doctors', 'trainers', 'instructors']],
        'gallery'         => ['label' => 'Photo gallery',                'synonyms' => ['gallery', 'photos', 'portfolio', 'our work', 'before and after']],
        'stats'           => ['label' => 'Numbers / stats strip',        'synonyms' => ['stats', 'statistics', 'numbers', 'achievements', 'milestones']],
        'features'        => ['label' => 'Features / benefits grid',     'synonyms' => ['features', 'benefits', 'why us', 'why choose', 'highlights', 'usp']],
        'services'        => ['label' => 'Services grid',                'synonyms' => ['services', 'what we do', 'offerings', 'treatments']],
        'map'             => ['label' => 'Map / location',               'synonyms' => ['map', 'location', 'directions', 'find us', 'where we are']],
        'trust_signals'   => ['label' => 'Trust signals / badges',       'synonyms' => ['trust', 'badges', 'guarantees', 'certifications', 'accreditations', 'awards']],
        'contact_form'    => ['label' => 'Contact form',                 'synonyms' => ['contact form', 'enquiry form', 'inquiry form', 'get in touch form']],
        'video_embed'     => ['label' => 'Video',                        'synonyms' => ['video', 'a video', 'video section', 'youtube', 'vimeo', 'embed a video', 'my video', 'promo video', 'intro video', 'video block']],
        'cta'             => ['label' => 'Call-to-action banner',        'synonyms' => ['cta', 'call to action', 'banner', 'promo strip']],
    ];

    /** Words that mean "a whole page" vs "a row on an existing page". */
    private const PAGE_WORDS    = '/\b(page|pages|landing page|subpage|sub-page)\b/i';
    private const SECTION_WORDS = '/\b(section|sections|row|rows|block|blocks|element|elements|strip|widget|module|area)\b/i';
    private const EDIT_WORDS    = '/\b(change|update|edit|rewrite|revise|reword|replace|tweak|improve|shorten|lengthen|fix|rename|swap|correct)\b/i';
    private const REMOVE_WORDS  = '/\b(remove|delete|hide|take (?:out|off|down)|get rid of)\b/i';
    /**
     * DESIGN VOCABULARY (2026-09-11). Without these, a colour request was read as a COPY edit
     * ("change" matched EDIT_WORDS) and silently rewrote nothing, or fell through to
     * "unsupported" — which is what made Arthur look like he could only change text.
     */
    private const STYLE_NOUNS   = '/\b(colou?rs?|recolou?r|palette|gradients?|theme|styling|look and feel|font|fonts|typeface|typography|dark mode|light mode)\b/i';
    /** A mood word only counts as a design request when it is clearly about the site. */
    private const STYLE_MOODS   = '/\b(luxur\w+|elegant|premium|upscale|sophisticated|minimal\w*|clean|modern|contemporary|bold|sleek|classic|traditional|timeless|playful|fun|vibrant|colou?rful|bubbly)\b/i';
    private const ELEMENT_NOUNS = '/\b(site|website|page|homepage|header|footer|nav|navigation|hero|banner|button|buttons|background|backdrop|text|heading|headings|link|links|section|sections|brand|logo)\b/i';
    private const COLOR_NAMES   = '/\b(black|white|red|blue|navy|gold|green|purple|violet|orange|pink|grey|gray|brown|teal|yellow|cyan|rose|emerald|indigo|silver|cream|beige|ivory|charcoal|maroon|crimson|bronze|copper|mint|coral|magenta|turquoise)\b/i';
    private const HEX_CODE      = '/#(?:[0-9a-f]{3}|[0-9a-f]{6})\b/i';
    /** Relative shifts: no colour named, but still a design instruction we can compute. */
    /** "fix logo contrast", "the logo is invisible", "bring the logo back" — a design request about the brand mark (2026-09-14). */
    public const LOGO_VISIBILITY = '/\blogo\b.{0,40}\b(contrast|visib\w*|invisible|missing|gone|disappear\w*|hidden|not showing|show(?:ing)? up|readable|legib\w*|see it|can\'?t see|back)\b|\b(contrast|visib\w*|invisible|missing|gone|disappear\w*|hidden|show|see|bring back|restore)\b.{0,30}\blogo\b/i';
    /** Legibility words: with a part of the page named they are a design request, never a copy edit. */
    public const LEGIBILITY     = '/\b(contrast|legib\w*|readab\w*|hard to (?:read|see)|can\'?t (?:read|see)|invisible|blends? in|too faint|unreadable)\b/i';
    /** "make the hero text bigger", "smaller buttons" — a size change is a design change (2026-09-14). */
    public const STYLE_SIZE     = '/\b(bigger|larger|smaller|tinier|huge|enlarge|shrink|increase the size|reduce the size|more prominent|less prominent)\b.{0,30}\b(text|font|fonts|heading|headings|headline|title|hero|button|buttons|nav|menu|logo|lettering|type|copy|paragraph|paragraphs)\b|\b(text|font|fonts|heading|headings|headline|title|hero|button|buttons|nav|menu|logo|lettering|paragraph|paragraphs)\b.{0,30}\b(bigger|larger|smaller|tinier|huge|more prominent|less prominent)\b/i';
    public const STYLE_TONES    = '/\b(darker|darken|lighter|lighten|brighter|brighten|dimmer|softer|soften|warmer|cooler|paler|richer|deeper|bolder|muted|more contrast|less contrast|washed out)\b/i';
    /** Anchors a customer names when placing a section: "after the services", "above the footer". */
    private const ANCHORS = ['services' => 'services', 'team' => 'team', 'testimonials' => 'testimonials', 'reviews' => 'testimonials', 'gallery' => 'gallery',
        'contact' => 'contact', 'booking' => 'booking', 'hero' => 'hero', 'top' => 'hero', 'header' => 'hero', 'footer' => 'footer', 'bottom' => 'footer',
        'blog' => 'blog', 'pricing' => 'pricing', 'about' => 'about', 'why us' => 'why_us', 'stats' => 'stats', 'process' => 'process', 'menu' => 'menu_highlights'];

    public static function pricing(): array
    {
        $cfg = config('builder.pricing');
        return is_array($cfg) ? array_merge(self::PRICING, array_intersect_key($cfg, self::PRICING)) : self::PRICING;
    }

    /** Page templates available to an industry (universal + industry-specific), with cost. */
    public static function pages(?string $industry = null): array
    {
        $ind = strtolower(trim((string) $industry));
        $out = [];
        foreach (ArthurService::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
            $inds = $meta['industries'] ?? ['*'];
            $universal = in_array('*', $inds, true);
            if ($ind !== '' && !$universal && !in_array($ind, $inds, true)) continue;
            $out[$slug] = [
                'slug' => $slug, 'label' => $meta['label'] ?? $slug, 'category' => $meta['category'] ?? '',
                'aliases' => $meta['aliases'] ?? [], 'universal' => $universal, 'description' => $meta['description'] ?? '',
                'credits' => self::pricing()['page'],
            ];
        }
        return $out;
    }

    /** Sections Arthur can add, with cost. Only types the schema knows are listed. */
    public static function sections(?string $industry = null): array
    {
        $allowed = method_exists(SectionSchema::class, 'allowedTypes') ? SectionSchema::allowedTypes() : array_keys(self::SECTIONS);
        $ind = strtolower(trim((string) $industry));
        $out = [];
        foreach (self::SECTIONS as $type => $m) {
            if (!in_array($type, $allowed, true)) continue;
            if ($ind !== '' && !empty($m['industries']) && !in_array($ind, $m['industries'], true)) continue;
            $out[$type] = ['type' => $type, 'label' => $m['label'], 'synonyms' => $m['synonyms'], 'credits' => self::pricing()['section']];
        }
        return $out;
    }

    /** Plain-language limits both assistants must state instead of promising. */
    public static function limitations(): array
    {
        return [
            'Additions use the templates above; Arthur does not code custom layouts from scratch.',
            'A new page gets the site\'s own header, footer, fonts and colour palette; its copy is written for the business.',
            'A new section is placed on the home page at a named position (e.g. after Services); it uses the site\'s palette.',
            'Booking and calendar sections collect requests by form; they do not sync to external calendars yet.',
            'Cart, checkout and account pages are only offered to shop-type sites (ecommerce, retail, online courses).',
            'Colours, palette, fonts and overall style CAN be changed from chat — Arthur rewrites them on the live site.',
            'Every addition is priced in credits and shown before it is applied.',
        ];
    }

    /** Compact prompt text for Arthur and Sarah (kept under ~1.2 KB). */
    public static function describe(?string $industry = null): string
    {
        $p = self::pricing();
        $pages = array_map(fn($x) => $x['slug'], self::pages($industry));
        $secs  = array_map(fn($x) => $x['type'], self::sections($industry));
        return "BUILDER CAPABILITIES (Arthur adds these to an existing site; Sarah asks Arthur, she never builds herself):\n"
            . "- Pages (" . $p['page'] . " credits each" . ($industry ? ", available for {$industry}" : '') . "): " . implode(', ', $pages) . ".\n"
            . "- Sections/rows (" . $p['section'] . " credits each, added to the home page at a named position): " . implode(', ', $secs) . ".\n"
            . "- Copy edits on an existing page: " . $p['text_edit'] . " credit.\n"
            . "- Design changes (colours, palette, gradients, fonts, overall style): " . $p['style'] . " credit.\n"
            . "- Limits: " . implode(' ', self::limitations());
    }

    /**
     * Deterministic reading of a customer request. Returns
     * ['kind' => 'page'|'section'|'edit'|'remove'|'unsupported', 'page' => slug|null, 'section' => type|null,
     *  'anchor' => block|null, 'where' => 'before'|'after'|null, 'label' => human, 'credits' => int, 'reason' => string]
     */
    /**
     * CONVERSATION, NOT A COMMAND (2026-09-13). Arthur is a chat surface; people say hello to him, thank him, and
     * ask what he can do. None of that is an edit, and none of it should be answered with "no matching page
     * template or section type". Returns the reply to send, or null when the message really is a request to act.
     * Deliberately narrow: it only matches a message that is ENTIRELY small talk, so "hi, change the headline"
     * still goes to the classifier and gets done.
     */
    public static function smalltalk(string $message): ?string
    {
        $m = trim(mb_strtolower($message));
        $m = trim(preg_replace('/[\s,.!?¡¿]+$/u', '', $m) ?? $m);
        if ($m === '' || mb_strlen($m) > 60) return null;
        // strip a trailing address to Arthur: "hi arthur" → "hi"
        $m = trim(preg_replace('/\s+(arthur|mate|there|buddy)$/u', '', $m) ?? $m);

        $greet = '/^(hi|hey|hello|helo|yo|hiya|howdy|good\s+(morning|afternoon|evening)|greetings)$/u';
        $thank = '/^(thanks|thank\s+you|thx|ta|cheers|nice|great|perfect|awesome|lovely|brilliant|ok|okay|cool)$/u';
        $help  = '/^(help|what\s+can\s+you\s+do|what\s+do\s+you\s+do|how\s+does\s+this\s+work|how\s+do\s+i\s+use\s+this|what\s+are\s+you)$/u';

        if (preg_match($greet, $m)) {
            return "Hello. Tell me what you would like changed on this page and I will do it — "
                 . "a new headline or paragraph, a different photo, new colours, or a section added or removed.";
        }
        if (preg_match($thank, $m)) {
            return "Any time. Tell me the next change whenever you are ready.";
        }
        if (preg_match($help, $m)) {
            $p = self::pricing();
            return "I edit this website for you. I can rewrite any text on the page ("
                 . (int) ($p['text_edit'] ?? 1) . " credit), change the colours or styling ("
                 . (int) ($p['style'] ?? 1) . " credit), add or remove a section ("
                 . (int) ($p['section'] ?? 2) . " credits) and add a whole page ("
                 . (int) ($p['page'] ?? 5) . " credits). Say what you want in plain words — "
                 . "for example \"change the headline to Welcome to Raymundo Realty\" or \"make the buttons green\".";
        }
        return null;
    }
    public static function classify(string $request, ?string $industry = null): array
    {
        $r = mb_strtolower(trim($request));
        $out = ['kind' => 'unsupported', 'page' => null, 'section' => null, 'anchor' => null, 'where' => null, 'label' => '', 'credits' => 0, 'reason' => ''];
        if ($r === '') { $out['reason'] = 'empty request'; return $out; }

        $wantsPage    = (bool) preg_match(self::PAGE_WORDS, $r);
        $wantsSection = (bool) preg_match(self::SECTION_WORDS, $r);
        $isRemove     = (bool) preg_match(self::REMOVE_WORDS, $r);
        $isEdit       = (bool) preg_match(self::EDIT_WORDS, $r) && !preg_match('/\b(add|create|insert|include|put|new)\b/', $r);

        // position words
        if (preg_match('/\b(after|below|under|beneath|following)\s+(?:the\s+)?([a-z &\']{3,25}?)(?:\s+section|\s+block|\s+row)?\b/', $r, $m)) { $out['where'] = 'after'; $out['anchor'] = self::anchorFor($m[2]); }
        elseif (preg_match('/\b(before|above|on top of|preceding)\s+(?:the\s+)?([a-z &\']{3,25}?)(?:\s+section|\s+block|\s+row)?\b/', $r, $m)) { $out['where'] = 'before'; $out['anchor'] = self::anchorFor($m[2]); }

        // The position phrase names WHERE, not WHAT. Left in, "after the services" out-scores "faq" on
        // length and the customer gets a second services grid instead of the FAQ they asked for.
        $what = preg_replace('/\b(after|below|under|beneath|following|before|above|on top of|preceding)\s+(?:the\s+)?[a-z &\']{3,25}?(?:\s+section|\s+block|\s+row)?\b/', ' ', $r) ?? $r;
        // page template match (catalogue slugs + aliases + spoken synonyms)
        $pageSlug = self::pageSlugFor($what, $industry);
        // section type match
        $secType = self::sectionTypeFor($what);

        // DESIGN (2026-09-11) — decided first: a colour or font request matches the edit verb too,
        // and losing that race is what turned "make the buttons green" into a no-op copy edit.
        // STUDIO CAPABILITIES IN ARTHUR (2026-09-14, Owner: "we are able to generate videos too using studio …
        // removing background … adding text over image, we want to utilize that"). Decided before image, style
        // and remove: "remove the background" would otherwise be a section removal, and "make a video" a section.
        $hasUrl = (bool) preg_match('~https?://~i', $r);
        if (preg_match('/\b(put|add|write|place|overlay|print|burn|stamp)\b.{0,40}\b(text|words?|headline|title|caption|slogan|tagline)\b.{0,60}\b(on|over|onto|across|into)\b.{0,30}\b(image|photo|picture|hero|banner|background)\b/i', $r)
            || preg_match('/\b(text|words?|headline|caption|slogan)\b.{0,10}\b(on|over|onto|across)\b.{0,10}\b(the\s+)?(hero|image|photo|picture|banner)\b/i', $r)
            || preg_match('/\b(write|print|stamp|overlay)\b.{2,80}\b(across|over|onto|on top of)\b.{0,20}\b(the\s+)?(hero|image|photo|picture|banner)\b/i', $r)) {
            $out['kind']    = 'overlay';
            $out['credits'] = self::pricing()['style'];
            $out['label']   = 'text on image';
            $out['target']  = preg_match('/\b(about|story)\b/i', $r) ? 'about' : 'hero';
            return $out;
        }
        if (! preg_match('/\bbackground\s+(colou?r|gradient|image)\b/i', $r)
            && (preg_match('/\b(remove|cut out|strip|erase|delete|take out|get rid of)\b.{0,20}\bbackground\b/i', $r)
                || preg_match('/\b(remove|erase|take out|get rid of)\b\s+(?:the\s+)?([a-z][a-z ]{2,30}?)\s+(?:from|out of|in)\s+(?:the\s+)?(?:hero|about|gallery|banner)?\s*(?:image|photo|picture)\b/i', $r))) {
            $out['kind']      = 'image_edit';
            $out['credits']   = 0; // the image studio charges 2 credits and releases the hold on failure
            $out['label']     = 'image edit';
            $out['operation'] = preg_match('/\bbackground\b/i', $r) ? 'remove_background' : 'remove_object';
            $out['target']    = preg_match('/\b(about|story)\b/i', $r) ? 'about' : 'hero';
            if ($out['operation'] === 'remove_object' && preg_match('/\b(?:remove|erase|take out|get rid of)\b\s+(?:the\s+)?([a-z][a-z ]{2,30}?)\s+(?:from|out of|in)\b/i', $r, $om)) { $out['object'] = trim($om[1]); }
            return $out;
        }
        if (! $hasUrl && ! preg_match('/\bsection\b/i', $r)
            && preg_match('/\b(generate|create|make|produce|render|shoot|design)\b.{0,40}\b(video|clip|reel|promo video|intro video|promo)\b/i', $r)) {
            $out['kind']    = 'video';
            $out['credits'] = 0; // the video studio charges 8 credits at kickoff and refunds on failure
            $out['label']   = 'video generation';
            return $out;
        }
        // "change the hero image to a photo of downtown at sunset" describes a picture that does not exist yet → generate it.
        if (preg_match('/\b(change|replace|swap|update|set|make)\b.{0,25}\b(image|photo|picture|banner|background)\b.{0,12}\b(to|with|into|for)\b\s+(?:a|an|the)?\s*(?:new |fresh |different |nice |beautiful )?(?:photo|image|picture|shot|render|illustration)\b.{0,6}\b(of|showing|with|featuring)\b/i', $r)) {
            $out['kind']    = 'image';
            $out['credits'] = 0;
            $out['label']   = 'image generation';
            $out['target']  = preg_match('/\b(about|story)\b/i', $r) ? 'about' : (preg_match('/\bgallery\b/i', $r) ? 'gallery' : 'hero');
            return $out;
        }
        // IMAGE GENERATION (DEC-0046 gap closure, 2026-09-14): "generate a hero image of …" is neither copy nor style.
        // Decided before style so "create a photo in warm colours" is an image ask, not a recolour.
        if (preg_match('/\b(generate|create|produce|draw|render|design|ai[- ]generate)\b.{0,40}\b(image|photo|picture|visual|illustration|artwork)\b/i', $r)
            || preg_match('/\b(new|another|different|fresh|ai)\s+(hero\s+|banner\s+|background\s+|about\s+)?(image|photo|picture)\b/i', $r)) {
            $out['kind']    = 'image';
            $out['credits'] = 0; // the image service charges by quality (1–4) and releases the hold on failure
            $out['label']   = 'image generation';
            $out['target']  = preg_match('/\b(about|story)\b/i', $r) ? 'about' : (preg_match('/\bgallery\b/i', $r) ? 'gallery' : 'hero');
            return $out;
        }
        if (self::isStyleRequest($r)) {
            $out['kind'] = 'style';
            $out['credits'] = self::pricing()['style'];
            $out['label'] = 'design change';
            return $out;
        }
        if ($isRemove) { $out['kind'] = 'remove'; $out['section'] = $secType; $out['reason'] = 'removal goes through the page editor'; return $out; }
        // STRESS C11 (2026-09-06): an edit verb without an add verb is ALWAYS a copy edit — "rewrite the about section
        // text" used to add an About page (5 credits) because the page/section nouns outranked the verb.
        if ($isEdit) {
            $out['kind'] = 'edit'; $out['credits'] = self::pricing()['text_edit']; $out['label'] = 'copy edit'; return $out;
        }

        // Decide page vs section. Explicit "page" wins; explicit section words win; otherwise a section if we
        // have a section type (cheaper, stays on home), else a page.
        if ($wantsPage && $pageSlug !== null) {
            return self::asPage($out, $pageSlug, $industry);
        }
        if ($secType !== null && !isset(self::sections($industry)[$secType])) {
            $out['kind'] = 'unsupported'; $out['section'] = $secType;
            $out['reason'] = "the '" . self::SECTIONS[$secType]['label'] . "' element is offered to " . implode(', ', self::SECTIONS[$secType]['industries'] ?? []) . ' sites only';
            return $out;
        }
        if (($wantsSection || !$wantsPage) && $secType !== null) {
            $out['kind'] = 'section'; $out['section'] = $secType; $out['label'] = self::SECTIONS[$secType]['label'];
            $out['credits'] = self::pricing()['section'];
            if ($out['anchor'] === null) { $out['anchor'] = 'contact'; $out['where'] = 'before'; }
            return $out;
        }
        if ($pageSlug !== null) return self::asPage($out, $pageSlug, $industry);
        if ($isEdit) { $out['kind'] = 'edit'; $out['credits'] = self::pricing()['text_edit']; $out['label'] = 'copy edit'; return $out; }

        $out['reason'] = 'no matching page template or section type';
        return $out;
    }

    /**
     * True when the customer is talking about how the site LOOKS rather than what it says or
     * contains. Deliberately conservative: a bare colour word is not enough ("a gallery of blue
     * flowers" is not a design change), so a named colour must be paired with a site element.
     */
    public static function isStyleRequest(string $r): bool
    {
        if (preg_match(self::STYLE_NOUNS, $r)) { return true; }
        if (preg_match(self::HEX_CODE, $r)) { return true; }
        $hasElement = (bool) preg_match(self::ELEMENT_NOUNS, $r);
        if ($hasElement && preg_match(self::COLOR_NAMES, $r)) { return true; }
        if (preg_match(self::STYLE_TONES, $r)) { return true; }
        if (preg_match(self::LOGO_VISIBILITY, $r)) { return true; }
        if (preg_match(self::STYLE_SIZE, $r)) { return true; }
        if ($hasElement && preg_match(self::LEGIBILITY, $r)) { return true; }
        // "make the site look more luxurious" — a mood, aimed at the site, with a change verb.
        if ($hasElement && preg_match(self::STYLE_MOODS, $r)
            && preg_match('/\b(make|change|update|give|want|more|less|feel|look)\b/', $r)) { return true; }
        return false;
    }

    private static function asPage(array $out, string $slug, ?string $industry): array
    {
        $avail = self::pages($industry);
        if (!isset($avail[$slug])) {
            $out['kind'] = 'unsupported'; $out['page'] = $slug;
            $out['reason'] = "the '{$slug}' page template is not offered for " . ($industry ?: 'this industry');
            return $out;
        }
        $out['kind'] = 'page'; $out['page'] = $slug; $out['label'] = $avail[$slug]['label'] . ' page'; $out['credits'] = self::pricing()['page'];
        return $out;
    }

    private static function anchorFor(string $words): ?string
    {
        $w = trim($words);
        foreach (self::ANCHORS as $needle => $block) { if (str_contains($w, $needle)) return $block; }
        return null;
    }

    public static function pageSlugFor(string $r, ?string $industry = null): ?string
    {
        $extra = ['appointment' => 'booking', 'appointments' => 'booking', 'reservation' => 'booking', 'schedule' => 'booking', 'calendar' => 'events', 'class' => 'events',
            'shop' => 'listing_browser', 'catalogue' => 'listing_browser', 'catalog' => 'listing_browser', 'products' => 'listing_browser', 'listings' => 'listing_browser', 'properties' => 'listing_browser',
            'privacy' => 'legal', 'terms' => 'legal', 'team' => 'about', 'story' => 'about', 'work' => 'portfolio', 'projects' => 'portfolio', 'results' => 'before_after', 'transformations' => 'before_after',
            'branches' => 'locations', 'find us' => 'locations', 'stores' => 'locations', 'basket' => 'cart', 'account' => 'account', 'my account' => 'account'];
        $cands = [];
        foreach (ArthurService::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
            $cands[str_replace('_', ' ', $slug)] = $slug;
            foreach (($meta['aliases'] ?? []) as $a) $cands[str_replace('_', ' ', $a)] = $slug;
        }
        foreach ($extra as $k => $v) $cands[$k] = $v;
        uksort($cands, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($cands as $needle => $slug) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/', $r)) return $slug;
        }
        return null;
    }

    public static function sectionTypeFor(string $r): ?string
    {
        $best = null; $bestLen = 0;
        foreach (self::SECTIONS as $type => $m) {
            foreach ($m['synonyms'] as $syn) {
                if (preg_match('/\b' . preg_quote($syn, '/') . '\b/', $r) && strlen($syn) > $bestLen) { $best = $type; $bestLen = strlen($syn); }
            }
        }
        return $best;
    }
}
