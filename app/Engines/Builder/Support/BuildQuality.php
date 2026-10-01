<?php

namespace App\Engines\Builder\Support;

/**
 * ARTHUR-4 (RFC-0021 wave 4, 2026-10-01) — the first draft must never be WRONG.
 *
 * REPORT-0067 #1 #2 #3 #5 #9 #11 #15: requested pages ignored, another trade's copy and photos bleeding in through the
 * design's own industry, invented reviews, stated colours ignored, a double click building two sites, an Arabic brief
 * getting an English left-to-right site. This class holds the design-trade lexicon and the foreign-word check, the
 * photo pool by the business's own trade, the requested-pages mapping, and the Arabic chrome. Kill switch:
 * storage/app/arthur4.on. New builds only; existing sites are untouched.
 */
final class BuildQuality
{
    public const SWITCH = 'app/arthur4.on';

    /** Words a design of that trade tends to carry in its sample copy; a business of another trade must not inherit them. */
    public const LEXICON = [
        'cafe'               => ['roast', 'roasted', 'roastery', 'coffee', 'espresso', 'latte', 'cappuccino', 'flat white', 'barista', 'brew', 'brewed', 'beans', 'filter coffee', 'cold brew', 'loose-leaf', 'wholesale', 'brunch', 'avocado'],
        'restaurant'         => ['chef', 'kitchen', 'cuisine', 'dishes', 'dining', 'menu', 'reservation', 'reserve a table', 'starters', 'mains', 'desserts', 'wine list', 'private hire', 'the room', 'tasting'],
        'catering'           => ['catering', 'caterer', 'buffet', 'canapés', 'menu', 'chef', 'event food'],
        'home_services'      => ['plumber', 'plumbing', 'boiler', 'drain', 'drains', 'electrician', 'emergency', 'two-hour window', 'call-out', 'callout', 'leak', 'tap', 'radiator', 'gutter', 'gutters', 'downpipe', 'handyman', 'repairs'],
        'construction'       => ['contractor', 'build', 'builders', 'extension', 'renovation', 'site', 'scaffold', 'bricks', 'concrete', 'foundations', 'groundworks'],
        'ecommerce'          => ['workshop', 'the workshop', 'shipping', 'ships worldwide', 'worldwide shipping', 'free shipping', 'returns', '30-day', 'checkout', 'cart', 'collection', 'lookbook', 'clothing', 'rail'],
        'retail_shop'        => ['in store', 'our store', 'shop floor', 'stockists', 'collection', 'boutique', 'lookbook', 'rail'],
        'gym'                => ['gym', 'workout', 'workouts', 'membership', 'memberships', 'trainer', 'trainers', 'classes', 'weights', 'cardio', 'crossfit'],
        'beauty_salon'       => ['salon', 'stylist', 'stylists', 'blow-dry', 'colour', 'nails', 'lashes', 'brows', 'waxing', 'facial', 'facials', 'treatments'],
        'barbershop'         => ['barber', 'barbers', 'fade', 'beard', 'shave', 'cut', 'clippers', 'chair'],
        'dental'             => ['dentist', 'dental', 'teeth', 'whitening', 'implants', 'hygienist', 'smile', 'check-up'],
        'medical_clinic'     => ['clinic', 'doctor', 'doctors', 'patients', 'appointment', 'gp', 'consultation', 'consultations', 'treatment'],
        'aesthetic_clinic'   => ['botox', 'filler', 'fillers', 'aesthetic', 'skin', 'laser', 'treatment', 'treatments', 'practitioner'],
        'real_estate_agency' => ['property', 'properties', 'listings', 'valuation', 'valuations', 'viewing', 'viewings', 'landlord', 'landlords', 'tenants', 'lettings', 'mortgage'],
        'hotel'              => ['rooms', 'suites', 'guests', 'check-in', 'check-out', 'stay', 'breakfast', 'concierge', 'spa'],
        'automotive'         => ['vehicle', 'vehicles', 'mot', 'servicing', 'garage', 'tyres', 'brakes', 'mechanic', 'mechanics', 'car'],
        'consulting'         => ['consultancy', 'consultants', 'advisory', 'strategy', 'clients', 'engagement', 'engagements', 'roadmap'],
        'it_services'        => ['it support', 'helpdesk', 'cloud', 'servers', 'network', 'cyber', 'backup', 'backups', 'software'],
        'marketing_agency'   => ['campaigns', 'campaign', 'seo', 'ppc', 'branding', 'agency', 'creatives', 'content strategy'],
        'tutoring'           => ['tutor', 'tutors', 'tutoring', 'lessons', 'students', 'exam', 'exams', 'gcse', 'a-level', 'homework'],
        'pet_services'       => ['grooming', 'groomer', 'dogs', 'dog', 'cats', 'cat', 'pets', 'pet', 'kennel', 'walks', 'vet'],
        'childcare'          => ['nursery', 'childcare', 'children', 'toddlers', 'preschool', 'ofsted', 'key worker'],
        'travel_agency'      => ['holiday', 'holidays', 'itinerary', 'itineraries', 'flights', 'resort', 'tour', 'tours', 'travel'],
        'event_venue'        => ['venue', 'weddings', 'wedding', 'guests', 'hire', 'private hire', 'the room', 'banquet', 'ceremony'],
        'interior_design'    => ['interiors', 'interior', 'furnishings', 'styling', 'mood board', 'mood boards', 'renovation'],
        'architecture'       => ['architect', 'architects', 'planning', 'drawings', 'design and build', 'riba'],
        'news_channel'       => ['news', 'newsroom', 'editorial', 'reporter', 'reporters', 'broadcast', 'headlines'],
    ];

    /** Photo tags on the platform library for a trade the designs do not cover, guessed from the brief. */
    private const POOL_HINTS = [
        '/drone|aerial|survey|surveys|roof|roofing|thermal|solar/'            => ['subject:aerial', 'subject:architecture', 'building', 'subject:cityscape', 'construction'],
        '/bakery|baker|bread|sourdough|pastry|pastries|cake|cakes|patisserie/' => ['food', 'subject:food', 'cafe', 'catering'],
        '/shoe|shoes|sneaker|sneakers|trainers|footwear|kicks/'                => ['retail', 'subject:product', 'ecommerce', 'retail_shop'],
        '/florist|flowers|garden|gardening|landscap/'                          => ['nature', 'natural', 'home_services'],
        '/photograph|photographer|studio|portrait/'                            => ['photography', 'subject:people', 'photo'],
        '/law|legal|solicitor|accountan|bookkeep|finance|insurance/'           => ['consulting', 'office', 'subject:document'],
        '/yoga|pilates|fitness|personal train/'                                => ['fitness', 'gym'],
        '/clean|cleaning|maid|housekeep/'                                      => ['home_services', 'subject:interior'],
        '/software|app|saas|tech|developer|web design/'                        => ['technology', 'it_services', 'office'],
        '/restaurant|bistro|grill|sushi|pizza|burger|diner|café|cafe|coffee/'  => ['restaurant', 'food', 'subject:food', 'cafe'],
        '/hotel|guest house|b&b|bnb|rental|villa/'                             => ['hotel', 'hospitality', 'luxury_interior'],
    ];
    private const NEUTRAL_TAGS = ['nature', 'building', 'office', 'subject:cityscape', 'subject:architecture'];

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** The design's trade words that the brief itself never uses. */
    public static function foreignWords(string $templateIndustry, string $briefText): array
    {
        $key = strtolower($templateIndustry); $words = self::LEXICON[$key] ?? null;
        if ($words === null) { $parts = explode('_', $key); while (count($parts) > 1 && $words === null) { array_pop($parts); $words = self::LEXICON[implode('_', $parts)] ?? null; } }   // a design variant (restaurant_sorrelroom) speaks its base trade's words
        $words = $words ?? [];
        $brief = mb_strtolower($briefText);
        return array_values(array_filter($words, fn ($w) => ! preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($w), '/') . '(?![a-z])/u', $brief)));
    }

    /** Text variables that carry a foreign trade word, or still hold the design's own sample copy. */
    public static function findForeign(array $variables, array $manifestVars, array $foreignWords): array
    {
        $skip = '/(image|img|photo|logo|url|color|colour|display|icon|bg|background|style|css|href|src|width|height|dim|ratio|font|hex|locale|canonical|slug|og_image|_id$|^lu_)/i';
        $hits = [];
        foreach ($manifestVars as $mk => $spec) {   // a key the model never filled renders as the design's default
            if (! is_array($spec) || preg_match($skip, (string) $mk) || ContactFacts::kindOfKey((string) $mk) !== null) continue;
            $type = strtolower((string) ($spec['type'] ?? 'text')); if (! in_array($type, ['text', 'html', ''], true)) continue;
            $cur = $variables[$mk] ?? null; if (is_string($cur) && trim($cur) !== '') continue;
            $def = trim((string) ($spec['default'] ?? '')); if ($def === '') continue;
            foreach ($foreignWords as $w) { if (preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($w), '/') . '(?![a-z])/u', mb_strtolower($def))) { $hits[$mk] = $w; $variables[$mk] = $def; break; } }
        }
        foreach ($variables as $k => $v) {
            if (! is_string($v) || trim($v) === '' || preg_match($skip, (string) $k)) continue;
            if (ContactFacts::kindOfKey((string) $k) !== null) continue;
            $low = mb_strtolower($v);
            foreach ($foreignWords as $w) {
                if (preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($w), '/') . '(?![a-z])/u', $low)) { $hits[$k] = $w; continue 2; }
            }
            $def = trim((string) (is_array($manifestVars[$k] ?? null) ? ($manifestVars[$k]['default'] ?? '') : ''));
            if ($def !== '' && trim($v) === $def && mb_strlen($def) > 12) $hits[$k] = '(sample copy)';
        }
        return $hits;
    }

    /** Whether any foreign word survives in a rewritten value. */
    public static function stillForeign(string $value, array $foreignWords): bool
    {
        $low = mb_strtolower($value);
        foreach ($foreignWords as $w) if (preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($w), '/') . '(?![a-z])/u', $low)) return true;
        return false;
    }

    /** Platform-library tags for a business outside the designs' trades; neutral scenes when nothing fits. */
    public static function poolTagsFor(string $rawIndustry, string $services = '', string $description = ''): array
    {
        $t = mb_strtolower($rawIndustry . ' ' . $services . ' ' . $description);
        foreach (self::POOL_HINTS as $re => $tags) { if (preg_match($re . 'u', $t)) return $tags; }
        return self::NEUTRAL_TAGS;
    }

    /** The catalogue pages the customer asked for by name (Home and Blog are always there; at most 5). */
    public static function pagesFromRequest(array $pages, string $industry): array
    {
        $out = [];
        foreach ($pages as $p) {
            $name = trim((string) (is_array($p) ? ($p['title'] ?? $p['name'] ?? '') : $p));
            if (isset(self::PAGE_WORDS_AR[$name])) $name = self::PAGE_WORDS_AR[$name];   // an Arabic page name
            if ($name === '' || preg_match('/^(home|blog|news|الرئيسية|الصفحة الرئيسية|المدونة)$/iu', $name)) continue;
            $c = BuilderCapabilities::classify('add a ' . mb_strtolower($name) . ' page', $industry);
            $slug = (($c['kind'] ?? '') === 'page') ? (string) ($c['page'] ?? '') : '';
            if ($slug === '' || in_array($slug, ['cart', 'checkout', 'account'], true) || in_array($slug, $out, true)) continue;
            $out[] = $slug;
            if (count($out) >= 5) break;
        }
        return $out;
    }

    /**
     * PAGE-NOT-SECTION: the customer's words ask for a PAGE the catalogue offers, the site has no such page, and the model read
     * it as something else (an answer, a section, a question) — because a home-page section of that name exists. The page slug
     * to add, or null when the model's reading stands.
     */
    public static function pageRequestOverride(string $request, string $intent, string $industry, int $websiteId): ?string
    {
        if (! preg_match('/\bpages?\b/iu', $request)) return null;
        if (! in_array($intent, ['answer', 'unsupported', 'clarify', 'section_add', 'catalogue', 'copy_edit'], true)) return null;
        $c = BuilderCapabilities::classify($request, $industry !== '' ? $industry : null);
        $slug = (($c['kind'] ?? '') === 'page') ? (string) ($c['page'] ?? '') : '';
        if ($slug === '' || ! preg_match('/^[a-z0-9_\-]+$/', $slug)) return null;
        return is_file(storage_path("app/public/sites/{$websiteId}/{$slug}/index.html")) ? null : $slug;
    }

    /** The requested page names the catalogue has no page design for (Home and Blog are never missing). */
    public static function unsupportedPages(array $pages, string $industry): array
    {
        $out = [];
        foreach ($pages as $p) {
            $name = trim((string) (is_array($p) ? ($p['title'] ?? $p['name'] ?? '') : $p));
            $look = self::PAGE_WORDS_AR[$name] ?? $name;
            if ($look === '' || preg_match('/^(home|blog|news|الرئيسية|الصفحة الرئيسية|المدونة)$/iu', $look)) continue;
            $c = BuilderCapabilities::classify('add a ' . mb_strtolower($look) . ' page', $industry);
            $slug = (($c['kind'] ?? '') === 'page') ? (string) ($c['page'] ?? '') : '';
            if (($slug === '' || in_array($slug, ['cart', 'checkout', 'account'], true)) && ! in_array($name, $out, true)) $out[] = $name;
        }
        return $out;
    }

    /** Arthur's summary names the requested pages that have no page design, right under its Pages line. */
    public static function pagesNote(string $reply, array $names): string
    {
        if ($names === []) return $reply;
        $q = array_map(fn ($n) => '“' . $n . '”', $names);
        $note = (count($names) === 1
                ? 'I have no page design for ' . $q[0] . ' yet, so it is not in this build'
                : 'I have no page designs for ' . implode(', ', array_slice($q, 0, -1)) . ' and ' . end($q) . ' yet, so they are not in this build')
            . ' — once the site is up, ask me and I will find the closest fit.';
        if (str_contains($reply, $note)) return $reply;
        if (preg_match('/^.*\*\*Pages:\*\*[^\n]*/mu', $reply, $m, PREG_OFFSET_CAPTURE)) {
            $end = $m[0][1] + strlen($m[0][0]);
            return substr($reply, 0, $end) . "\n_" . $note . '_' . substr($reply, $end);
        }
        return rtrim($reply) . "\n\n" . $note;
    }

    /** "Pages: Home, Menu, About, Contact" typed by the customer, when the model's JSON left pages out. */
    public static function pagesFromConversation(array $history): array
    {
        $out = [];
        foreach ($history as $h) {
            if (! is_array($h) || (($h['role'] ?? '') !== 'user') || ! is_string($h['content'] ?? null)) continue;
            if (! preg_match_all('/(?<![a-z])(?:pages?|الصفحات)\s*[:：]\s*([^\n.]{3,200})/iu', $h['content'], $m)) continue;
            foreach ($m[1] as $list) {
                foreach (preg_split('/\s*(?:,|،|;|\/|\band\b|\sو\s|\bو(?=[\x{0600}-\x{06FF}]))\s*/u', $list) ?: [] as $p) {
                    $p = trim($p, " \t-–.");
                    if ($p !== '' && mb_strlen($p) <= 40 && ! in_array($p, $out, true)) $out[] = $p;
                }
            }
        }
        return array_slice($out, 0, 8);
    }

    /** Colour words people actually use, as hex; the design engine only knew a dozen. */
    public const COLOUR_WORDS = [
        'cream' => '#F5EEDC', 'ivory' => '#FFFFF0', 'beige' => '#E8DCC8', 'sand' => '#E2CDA5', 'linen' => '#EFE6D8', 'off-white' => '#F7F5F0', 'terracotta' => '#B05020', 'rust' => '#A7401E', 'copper' => '#B87333', 'brick' => '#9C2F1B',
        'navy' => '#1A2744', 'midnight' => '#141B2D', 'royal blue' => '#2447B6', 'sky blue' => '#69B3E7', 'teal' => '#0D9488', 'turquoise' => '#1AA7A1', 'aqua' => '#2BB5C9', 'cyan' => '#06B6D4',
        'safety orange' => '#F26522', 'orange' => '#F97316', 'amber' => '#D97706', 'mustard' => '#C9A227', 'gold' => '#C9943A', 'yellow' => '#EAB308', 'lemon' => '#E8D44D',
        'sage' => '#8AA286', 'olive' => '#6B7A3A', 'forest green' => '#1F3A2B', 'emerald' => '#0F7B5F', 'mint' => '#7AD1B0', 'green' => '#16A34A', 'lime' => '#84CC16',
        'burgundy' => '#6B1F2E', 'maroon' => '#6E1423', 'wine' => '#722F37', 'plum' => '#5B2A86', 'purple' => '#7C3AED', 'violet' => '#7C3AED', 'lavender' => '#A78BFA', 'lilac' => '#C8A2C8',
        'pink' => '#EC4899', 'blush' => '#E8B4B8', 'rose' => '#E11D48', 'coral' => '#F26B5B', 'red' => '#DC2626', 'crimson' => '#B91C1C',
        'charcoal' => '#2B2B2B', 'black' => '#0A0A0A', 'slate' => '#334155', 'grey' => '#6B7280', 'gray' => '#6B7280', 'silver' => '#A8A9AD', 'white' => '#FFFFFF', 'brown' => '#78350F', 'chocolate' => '#4B2E1E', 'taupe' => '#8B7D6B', 'blue' => '#2563EB',
    ];

    /** Colour words in Arabic, mapped onto the same hex values. */
    public const COLOUR_WORDS_AR = [
        'ذهبي' => '#C9943A', 'ذهبية' => '#C9943A', 'أخضر' => '#16A34A', 'خضراء' => '#16A34A', 'اخضر' => '#16A34A', 'أزرق' => '#2563EB', 'زرقاء' => '#2563EB', 'ازرق' => '#2563EB', 'كحلي' => '#1A2744', 'كحلية' => '#1A2744',
        'أحمر' => '#DC2626', 'حمراء' => '#DC2626', 'احمر' => '#DC2626', 'أسود' => '#0A0A0A', 'سوداء' => '#0A0A0A', 'اسود' => '#0A0A0A', 'أبيض' => '#FFFFFF', 'بيضاء' => '#FFFFFF', 'ابيض' => '#FFFFFF', 'رمادي' => '#6B7280', 'رمادية' => '#6B7280',
        'بني' => '#78350F', 'بنية' => '#78350F', 'وردي' => '#EC4899', 'وردية' => '#EC4899', 'بنفسجي' => '#7C3AED', 'بنفسجية' => '#7C3AED', 'برتقالي' => '#F97316', 'برتقالية' => '#F97316', 'أصفر' => '#EAB308', 'صفراء' => '#EAB308', 'اصفر' => '#EAB308',
        'بيج' => '#E8DCC8', 'كريمي' => '#F5EEDC', 'كريمية' => '#F5EEDC', 'تركواز' => '#1AA7A1', 'فضي' => '#A8A9AD', 'فضية' => '#A8A9AD', 'عنابي' => '#6B1F2E', 'زيتي' => '#6B7A3A', 'زيتوني' => '#6B7A3A', 'نحاسي' => '#B87333', 'طوبي' => '#B05020',
    ];

    /** Page names in Arabic, as the catalogue knows them. */
    public const PAGE_WORDS_AR = [
        'القائمة' => 'menu', 'قائمة الطعام' => 'menu', 'المنيو' => 'menu', 'الحجز' => 'booking', 'حجز' => 'booking', 'الحجوزات' => 'booking', 'اتصل بنا' => 'contact', 'تواصل معنا' => 'contact', 'اتصال' => 'contact',
        'من نحن' => 'about', 'عن المطعم' => 'about', 'عنا' => 'about', 'قصتنا' => 'about', 'خدماتنا' => 'services', 'الخدمات' => 'services', 'الأسعار' => 'pricing', 'الاسعار' => 'pricing', 'الأسئلة الشائعة' => 'faq', 'الاسئلة' => 'faq',
        'المعرض' => 'portfolio', 'أعمالنا' => 'portfolio', 'الفعاليات' => 'events', 'الفروع' => 'locations', 'المواقع' => 'locations', 'الشروط' => 'legal', 'الخصوصية' => 'legal',
    ];

    /** The colours named in the brief as hex, in the order given; hex codes pass through; unknown words are dropped. */
    public static function coloursFromText(string $text): array
    {
        $t = mb_strtolower($text); $found = [];
        preg_match_all('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', $text, $hx, PREG_OFFSET_CAPTURE);
        foreach ($hx[0] as $hit) { $h = strtoupper($hit[0]); if (strlen($h) === 4) $h = '#' . $h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3]; $found[$hit[1]] = $h; }
        $names = array_keys(self::COLOUR_WORDS); usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        $taken = [];
        foreach ($names as $n) {
            if (! preg_match_all('/(?<![a-z-])' . preg_quote($n, '/') . '(?![a-z-])/u', $t, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[0] as $hit) { $pos = $hit[1]; $covered = false; foreach ($taken as [$a, $b]) { if ($pos >= $a && $pos < $b) $covered = true; } if ($covered) continue; $taken[] = [$pos, $pos + strlen($n)]; $found[$pos] = self::COLOUR_WORDS[$n]; }
        }
        foreach (self::COLOUR_WORDS_AR as $an => $ahex) { $p = mb_strpos($t, $an); if ($p !== false) $found[100000 + $p] = $ahex; }   // Arabic colour words
        ksort($found); $list = array_values(array_unique($found));
        $out = []; if (isset($list[0])) $out['primary'] = $list[0]; if (isset($list[1])) $out['secondary'] = $list[1]; if (isset($list[2])) $out['accent'] = $list[2];
        // a light first colour (cream, ivory) is a background, not a brand colour: the darker one leads
        if (isset($out['primary'], $out['secondary']) && self::lum($out['primary']) > 0.8 && self::lum($out['secondary']) <= 0.8) { [$out['primary'], $out['secondary']] = [$out['secondary'], $out['primary']]; }
        return $out;
    }

    private static function lum(string $hex): float
    {
        $r = hexdec(substr($hex, 1, 2)) / 255; $g = hexdec(substr($hex, 3, 2)) / 255; $b = hexdec(substr($hex, 5, 2)) / 255;
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function isArabic(string $s): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $s);
    }

    /** Arabic sites read right to left, with Arabic chrome: the design's own labels and placeholders. */
    public static function localiseChrome(string $html, array $variables): string
    {
        $probe = (string) ($variables['business_name'] ?? '') . ' ' . (string) ($variables['hero_title'] ?? '');
        if (! self::isArabic($probe)) return $html;
        $html = (string) preg_replace_callback('/<html\b([^>]*)>/i', function ($m) {
            $a = (string) preg_replace('/\s+(lang|dir)="[^"]*"/i', '', $m[1]);
            return '<html' . $a . ' lang="ar" dir="rtl">';
        }, $html, 1);
        $css = '<style id="lu-rtl">html[dir=rtl] body{direction:rtl;text-align:start}html[dir=rtl] ul,html[dir=rtl] ol{padding-inline-start:1.25em;padding-left:0}html[dir=rtl] input,html[dir=rtl] textarea,html[dir=rtl] select{text-align:start;direction:rtl}html[dir=rtl] .nav-links,html[dir=rtl] nav ul{flex-direction:row-reverse}</style>';
        if (stripos($html, 'id="lu-rtl"') === false && stripos($html, '</head>') !== false) $html = str_ireplace('</head>', $css . "\n</head>", $html);
        $dict = [
            'Your phone (optional)' => 'هاتفك (اختياري)', 'Phone (optional)' => 'الهاتف (اختياري)', 'Email address' => 'البريد الإلكتروني', 'Full name' => 'الاسم الكامل', 'Preferred date' => 'التاريخ المفضل', 'Preferred time' => 'الوقت المفضل', 'Number of guests' => 'عدد الضيوف', 'Special requests' => 'طلبات خاصة', 'Send request' => 'أرسل الطلب', 'Request booking' => 'اطلب الحجز', 'Book a visit' => 'احجز زيارة', 'View menu' => 'عرض القائمة', 'See the menu' => 'عرض القائمة', 'Our menu' => 'قائمتنا', 'Our story' => 'قصتنا', 'Find us' => 'موقعنا', 'Our services' => 'خدماتنا', 'Get a quote' => 'اطلب عرض سعر', 'Order now' => 'اطلب الآن', 'Which service?' => 'أي خدمة؟', 'Your message' => 'رسالتك',
            'Send message' => 'أرسل الرسالة', 'Send Message' => 'أرسل الرسالة', 'Send' => 'إرسال', 'Submit' => 'إرسال', 'Get in touch' => 'تواصل معنا', 'Contact us' => 'اتصل بنا', 'Contact Us' => 'اتصل بنا', 'Contact' => 'اتصل بنا',
            'Home' => 'الرئيسية', 'About' => 'من نحن', 'About us' => 'من نحن', 'About Us' => 'من نحن', 'Services' => 'خدماتنا', 'Menu' => 'القائمة', 'Gallery' => 'المعرض', 'Blog' => 'المدونة', 'Book' => 'احجز', 'Book now' => 'احجز الآن', 'Book Now' => 'احجز الآن', 'Book a table' => 'احجز طاولة', 'Reserve a table' => 'احجز طاولة', 'Reserve' => 'احجز',
            'Read more' => 'اقرأ المزيد', 'Learn more' => 'اعرف المزيد', 'View all' => 'عرض الكل', 'See all' => 'عرض الكل', 'Call us' => 'اتصل بنا', 'Call' => 'اتصال', 'Email' => 'البريد الإلكتروني', 'Phone' => 'الهاتف', 'Address' => 'العنوان', 'Opening hours' => 'ساعات العمل', 'Hours' => 'ساعات العمل', 'Follow us' => 'تابعنا',
            'Your name' => 'اسمك', 'Your email' => 'بريدك الإلكتروني', 'Your phone' => 'رقم هاتفك', 'Your message' => 'رسالتك', 'Name' => 'الاسم', 'Message' => 'الرسالة', 'Which service?' => 'أي خدمة؟', 'Select…' => 'اختر…', 'Select...' => 'اختر...', 'Date' => 'التاريخ', 'Time' => 'الوقت', 'Guests' => 'عدد الضيوف',
            'All rights reserved.' => 'جميع الحقوق محفوظة.', 'All rights reserved' => 'جميع الحقوق محفوظة', 'Privacy' => 'الخصوصية', 'Terms' => 'الشروط', 'Open in Maps' => 'افتح في الخرائط', 'Get directions' => 'الاتجاهات',
        ];
        uksort($dict, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($dict as $en => $ar) {
            $q = preg_quote($en, '/');
            // text nodes: >Label<  (optionally wrapped in whitespace), never inside <script>/<style>
            $html = (string) preg_replace('/(>)\s*' . $q . '\s*(<)/u', '$1' . $ar . '$2', $html);
            // placeholders, values on submit buttons, aria-labels and alt text
            $html = (string) preg_replace('/\b(placeholder|aria-label|alt|title)="' . $q . '"/u', '$1="' . $ar . '"', $html);
            $html = (string) preg_replace('/(<input\b[^>]*type="submit"[^>]*\bvalue=")' . $q . '(")/iu', '$1' . $ar . '$2', $html);
        }
        return $html;
    }
}
