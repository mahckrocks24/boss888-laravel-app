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

    public static function applies(?string $designSlug): bool
    {
        $s = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $designSlug));
        return $s !== '' && in_array($s, self::SLUGS, true);
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
        $wraps = '.service-img,.event-img,.card-img,.card-image,.card-media,.blog-img,.post-img,.room-img,.listing-img,.project-img,.portfolio-img,.gallery-item,.work-img,.feature-img,.program-img,.course-img,.vehicle-img,.property-img,.menu-img,.dish-img,.package-img,.item-img';
        $imgBlocks = 'services,events,gallery,blog,portfolio,listings,rooms,menu_highlights,programs,courses,products,projects,features,specials,fleet,treatments,classes';
        $blockImgs = implode(',', array_map(fn($b) => '[data-block="' . $b . '"] img', explode(',', $imgBlocks)));
        $teamBlocks = 'team,trainers,doctors,staff,stylists,instructors,agents,tutors,coaches';
        $teamImgs = implode(',', array_map(fn($b) => '[data-block="' . $b . '"] img', explode(',', $teamBlocks)));
        $teamWraps = self::sel('.member-img,.team-img,.team-photo,.member-photo,.trainer-photo,.doctor-photo,.staff-photo,.staff-img,.doctor-img,.trainer-img');
        $phone = '@media (max-width:640px){'
            . self::sel('h1,.hero-title,.h-display') . '{font-size:clamp(30px,8.6vw,38px)!important;line-height:1.08!important;letter-spacing:-0.01em!important;margin-bottom:18px!important}'
            . self::sel('h2,.h-section,.section-title') . '{font-size:clamp(24px,6.6vw,28px)!important;line-height:1.15!important}'
            . self::sel('h3,.h-sub,.card-title') . '{font-size:clamp(18px,5vw,21px)!important;line-height:1.25!important}'
            . self::sel('p,li') . '{font-size:15px!important;line-height:1.6!important}'
            . self::sel('.eyebrow,.hero-eyebrow,.kicker') . '{font-size:10px!important;margin-bottom:14px!important}'
            . self::sel('.stat-value,.stat-number,.metric-value') . '{font-size:36px!important}'
            . $S . '{padding-top:52px!important;padding-bottom:52px!important}'
            . '[data-block="hero"]{min-height:60vh!important;padding-top:40px!important;padding-bottom:40px!important}' . $S . ' .hero-inner{padding-left:24px!important;padding-right:24px!important;padding-bottom:0!important}'
            . self::sel('.section-head,.section-header') . '{margin-bottom:32px!important}'
            . self::sel($wraps) . '{aspect-ratio:16/10!important;max-height:220px!important;overflow:hidden}' . self::sel($wraps) . ' img{width:100%;height:100%;object-fit:cover}'
            . $blockImgs . '{max-height:240px!important;object-fit:cover}'
            . $teamImgs . '{max-width:200px!important;max-height:200px!important;aspect-ratio:1/1!important;margin-left:auto!important;margin-right:auto!important;object-fit:cover}'
            . $teamWraps . '{max-width:200px!important;height:200px!important;aspect-ratio:1/1!important;margin-left:auto!important;margin-right:auto!important;background-size:cover;overflow:hidden}'
            . self::sel('.btn,.button') . '{padding:14px 24px!important;font-size:11px!important}'
            . '}';
        $desktop = '@media (min-width:641px){'
            . self::sel('h1,.hero-title,.h-display') . '{font-size:clamp(44px,4.8vw,64px)!important;line-height:1.04!important;margin-bottom:24px!important}'
            . self::sel('h2,.h-section,.section-title') . '{font-size:clamp(30px,3vw,40px)!important;line-height:1.1!important}'
            . self::sel('h3,.h-sub,.card-title') . '{font-size:clamp(20px,1.7vw,24px)!important}'
            . self::sel('p,li') . '{font-size:16px!important;line-height:1.65!important}'
            . self::sel('.stat-value,.stat-number,.metric-value') . '{font-size:44px!important}'
            . $S . '{padding-top:96px!important;padding-bottom:96px!important}'
            . '[data-block="hero"]{min-height:70vh!important;padding-top:64px!important;padding-bottom:64px!important}' . $S . ' .hero-inner{padding-bottom:0!important}'
            . self::sel('.section-head,.section-header') . '{margin-bottom:56px!important}'
            . self::sel($wraps) . '{aspect-ratio:4/3!important;max-height:360px!important;overflow:hidden}' . self::sel($wraps) . ' img{width:100%;height:100%;object-fit:cover}'
            . $blockImgs . '{max-height:380px!important;object-fit:cover}'
            . $teamImgs . '{max-width:280px!important;max-height:280px!important;aspect-ratio:1/1!important;object-fit:cover}'
            . $teamWraps . '{max-width:280px!important;height:280px!important;aspect-ratio:1/1!important;background-size:cover;overflow:hidden}'
            . '}';
        return '<style id="' . self::ID . '">/* LU scale guard 2026-09-20 */' . $phone . $desktop . '</style>';
    }

    /** Inject once before </head> (or </body>) when the design needs it. */
    public static function inject(string $html, ?string $designSlug): string
    {
        if (!self::applies($designSlug) || str_contains($html, 'id="' . self::ID . '"')) return $html;
        $css = self::css();
        if (stripos($html, '</head>') !== false) return preg_replace('#</head>#i', $css . '</head>', $html, 1);
        if (stripos($html, '</body>') !== false) return preg_replace('#</body>#i', $css . '</body>', $html, 1);
        return $html . $css;
    }
}
