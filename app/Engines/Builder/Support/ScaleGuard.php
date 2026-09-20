<?php

namespace App\Engines\Builder\Support;

/**
 * SCALE GUARD (2026-09-20, Owner: "the website template is too large … desktop is too much too … fix all the other
 * templates"). The 31 original base designs (and the aesthetic_clinic_commercial clone) set their fluid type with
 * desktop minimums — hero titles clamp(42–64px … 152px), section headings 32–44px on a 390px phone, 110–160px section
 * padding, full-screen heroes and single-column 3:4 cards — and carry no phone type rules of their own (measured on
 * every design's preview, EV-1079). This block brings them to a normal scale at every width: phone ≤640px and desktop
 * above it, scoped to the page's content blocks (nav and footer untouched). The 63 variant designs of 2026-09-10
 * already sit at a normal scale and are left alone.
 *
 * Injected at serve time (published-site middleware) so every live site gets it without rewriting files, at deploy
 * (home + pages) so the editor preview matches, and on the template preview route. Idempotent by <style id>.
 */
final class ScaleGuard
{
    public const ID = 'lu-scale-guard';

    /** The designs that need it: the original bases + the commercial clone. Variants are excluded on purpose. */
    public const SLUGS = ['aesthetic_clinic', 'aesthetic_clinic_commercial', 'architecture', 'automotive', 'barbershop', 'beauty_salon', 'cafe', 'catering',
        'childcare', 'construction', 'consulting', 'dental', 'ecommerce', 'event_venue', 'gym', 'home_services', 'hotel', 'interior_design', 'it_services',
        'marketing_agency', 'medical_clinic', 'news_channel', 'online_courses', 'pet_services', 'real_estate_agency', 'resort', 'restaurant', 'retail_shop',
        'short_term_rental', 'training_center', 'travel_agency', 'tutoring'];

    /** Variants whose only excess is the hero headline (42–46px on a phone, 67–79px on desktop): the headline alone is capped. */
    public const HERO_SLUGS = ['aesthetic_quiet', 'barber_lather', 'catering_marquee', 'hotel_terrace', 'interiors_scheme', 'resort_headland', 'restaurant_atrium', 'venue_exclusive',
        'consultant_profile', 'courses_studio', 'gym_platform', 'medical_atrium', 'resort_dune', 'salon_atelier', 'travel_atlas', 'aesthetic_studio', 'barber_corner', 'estate_frontage',
        'medical_rounds', 'realtor_profile', 'rental_coastline', 'resort_reef', 'restaurant_larder', 'retail_shopfront', 'salon_treatment', 'shop_maker', 'travel_compass'];

    public static function applies(?string $designSlug): bool
    {
        $s = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $designSlug));
        return $s !== '' && (in_array($s, self::SLUGS, true) || in_array($s, self::HERO_SLUGS, true));
    }

    public static function heroOnly(?string $designSlug): bool
    {
        $s = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $designSlug));
        return in_array($s, self::HERO_SLUGS, true);
    }

    public static function heroCss(): string
    {
        $h = '[data-block="hero"] h1,[data-block="hero"] .hero-title,[data-block="hero"] .h-display';
        return '<style id="' . self::ID . '">/* LU scale guard (hero) 2026-09-20 */@media (max-width:640px){' . $h . '{font-size:clamp(28px,8.2vw,34px)!important;line-height:1.1!important}}@media (min-width:641px){' . $h . '{font-size:clamp(40px,4.4vw,56px)!important;line-height:1.06!important}}</style>';
    }

    /** Every content block: a data-block that is not the nav or the footer. */
    private const S = '[data-block]:not([data-block="nav"]):not([data-block="footer"])';

    private static function sel(string $list): string
    {
        return implode(',', array_map(fn($x) => self::S . ' ' . trim($x), explode(',', $list)));
    }

    public static function css(): string
    {
        $S = self::S;
        $wraps = '.service-img,.event-img,.card-img,.card-image,.card-media,.blog-img,.post-img,.room-img,.listing-img,.project-img,.portfolio-img,.work-img,.feature-img,.program-img,.course-img,.vehicle-img,.property-img,.menu-img,.dish-img,.package-img,.item-img';
        $imgBlocks = 'services,events,gallery,blog,portfolio,listings,rooms,menu_highlights,programs,courses,products,projects,features,specials,fleet,treatments,classes';
        $blockImgs = implode(',', array_map(fn($b) => '[data-block="' . $b . '"] img', explode(',', $imgBlocks)));
        $teamBlocks = 'team,trainers,doctors,staff,stylists,instructors,agents,tutors,coaches';
        $teamImgs = implode(',', array_map(fn($b) => '[data-block="' . $b . '"] img', explode(',', $teamBlocks)));
        $teamWraps = self::sel('.member-img,.team-img,.team-photo,.member-photo,.trainer-photo,.doctor-photo,.staff-photo,.staff-img,.doctor-img,.trainer-img');
        $phone = '@media (max-width:640px){'
            . self::sel('h1,.hero-title,.h-display') . '{font-size:clamp(28px,8.2vw,34px)!important;line-height:1.1!important;letter-spacing:-0.01em!important;margin-bottom:16px!important}'
            . self::sel('h2,.h-section,.section-title') . '{font-size:clamp(22px,6.4vw,26px)!important;line-height:1.18!important}'
            . self::sel('h3,.h-sub,.card-title') . '{font-size:clamp(17px,4.8vw,19px)!important;line-height:1.3!important}'
            . self::sel('p,li') . '{font-size:14px!important;line-height:1.6!important}' . '[data-block="hero"] p{font-size:16px!important}'
            . self::sel('.eyebrow,.hero-eyebrow,.kicker') . '{font-size:10px!important;margin-bottom:14px!important}'
            . self::sel('.stat-value,.stat-number,.metric-value') . '{font-size:36px!important}'
            . $S . '{padding-top:52px!important;padding-bottom:52px!important}'
            . '[data-block="hero"]{min-height:60vh!important;padding-top:40px!important;padding-bottom:40px!important}' . $S . ' .hero-inner{padding-left:24px!important;padding-right:24px!important;padding-bottom:0!important}'
            . self::sel('.section-head,.section-header') . '{margin-bottom:32px!important}'
            . self::sel($wraps) . '{aspect-ratio:16/9!important;height:auto!important;max-height:none!important;overflow:hidden;min-width:0;width:100%}' . $S . ' [class*="grid"] > *,' . $S . ' [class*="cards"] > *{min-width:0}' . self::sel($wraps) . ' img{width:100%;height:100%;object-fit:cover}'
            . $blockImgs . '{max-height:200px!important;object-fit:cover}'
            . $teamImgs . '{max-width:200px!important;max-height:200px!important;aspect-ratio:1/1!important;margin-left:auto!important;margin-right:auto!important;object-fit:cover}'
            . $teamWraps . '{width:100%!important;max-width:200px!important;height:auto!important;aspect-ratio:1/1!important;margin-left:auto!important;margin-right:auto!important;background-size:cover;overflow:hidden;min-width:0}'
            . self::sel('.btn,.button') . '{padding:14px 24px!important;font-size:11px!important}'
            . '}';
        $desktop = '@media (min-width:641px){'
            . self::sel('h1,.hero-title,.h-display') . '{font-size:clamp(40px,4.4vw,56px)!important;line-height:1.06!important;margin-bottom:22px!important}'
            . self::sel('h2,.h-section,.section-title') . '{font-size:clamp(28px,2.8vw,36px)!important;line-height:1.12!important}'
            . self::sel('h3,.h-sub,.card-title') . '{font-size:20px!important;line-height:1.3!important}'
            . self::sel('p,li') . '{font-size:15px!important;line-height:1.6!important}' . '[data-block="hero"] p{font-size:17px!important}'
            . self::sel('.stat-value,.stat-number,.metric-value') . '{font-size:44px!important}'
            . $S . '{padding-top:96px!important;padding-bottom:96px!important}'
            . '[data-block="hero"]{min-height:70vh!important;padding-top:64px!important;padding-bottom:64px!important}' . $S . ' .hero-inner{padding-bottom:0!important}'
            . self::sel('.section-head,.section-header') . '{margin-bottom:56px!important}'
            . self::sel($wraps) . '{aspect-ratio:16/9!important;height:auto!important;max-height:none!important;overflow:hidden;min-width:0;width:100%}' . $S . ' [class*="grid"] > *,' . $S . ' [class*="cards"] > *{min-width:0}' . self::sel($wraps) . ' img{width:100%;height:100%;object-fit:cover}'
            . $blockImgs . '{max-height:280px!important;object-fit:cover}'
            . $teamImgs . '{max-width:240px!important;max-height:240px!important;aspect-ratio:1/1!important;object-fit:cover}'
            . $teamWraps . '{width:100%!important;max-width:240px!important;height:auto!important;aspect-ratio:1/1!important;background-size:cover;overflow:hidden;min-width:0}'
            . '}';
        return '<style id="' . self::ID . '">/* LU scale guard 2026-09-20 */' . $phone . $desktop . '</style>';
    }

    /**
     * OVERFLOW GUARD (2026-09-20, Owner: 'I don't wanna see this kind of problem') — for EVERY design: nothing spills past
     * the screen. Found by a rect-based audit of all 94 designs at 605/820/1008/1280 (the designs clip the body, so
     * scrollWidth never showed it): a hero figure stretched by its aspect ratio between 640 and 820px, a 3-up metrics
     * grid whose labels ran out of a 605px card, a horizontal gallery strip whose cells were cut at the edge, and card
     * grids whose items could not shrink below their media's intrinsic width.
     */
    public static function overflowCss(): string
    {
        return '<style id="lu-overflow-guard">/* LU overflow guard 2026-09-20 */'
            . '[data-block] > *,[data-block] [class*="grid"] > *,[data-block] [class*="cards"] > *,[data-block] [class*="metric"],[data-block] form,[data-block] [class*="form"]{min-width:0}'
            . '[data-block] input,[data-block] select,[data-block] textarea{min-width:0;max-width:100%;box-sizing:border-box}'
            . '[data-block] [class*="metric"]{overflow-wrap:anywhere}'
            . '@media (max-width:640px){[data-block] [class*="metrics"]{grid-template-columns:repeat(2,minmax(0,1fr))!important}}'
            . '@media (max-width:820px){[data-block="hero"] figure,[data-block="hero"] .hero-shot{width:100%!important;max-width:100%!important;height:auto!important;min-width:0}}'
            . '[data-block="gallery"] .grid{display:flex!important;flex-wrap:wrap!important;overflow:visible!important;gap:12px!important}'
            . '[data-block="gallery"] .grid>.cell{flex:1 1 calc(33.333% - 12px)!important;max-width:calc(33.333% - 8px)!important;min-width:0}'
            . '@media (max-width:640px){[data-block="gallery"] .grid>.cell{flex-basis:calc(50% - 6px)!important;max-width:calc(50% - 6px)!important}}'
            . '</style>';
    }

    /** Inject once before </head> (or </body>): the overflow guard for every design, the scale tiers where the design needs them. */
    public static function inject(string $html, ?string $designSlug): string
    {
        if (!str_contains($html, 'id="lu-overflow-guard"')) {
            $og = self::overflowCss();
            if (stripos($html, '</head>') !== false) $html = preg_replace('#</head>#i', $og . '</head>', $html, 1); elseif (stripos($html, '</body>') !== false) $html = preg_replace('#</body>#i', $og . '</body>', $html, 1); else $html .= $og;
        }
        if (!self::applies($designSlug) || str_contains($html, 'id="' . self::ID . '"')) return $html;
        $css = self::heroOnly($designSlug) ? self::heroCss() : self::css();
        if (stripos($html, '</head>') !== false) return preg_replace('#</head>#i', $css . '</head>', $html, 1);
        if (stripos($html, '</body>') !== false) return preg_replace('#</body>#i', $css . '</body>', $html, 1);
        return $html . $css;
    }
}
