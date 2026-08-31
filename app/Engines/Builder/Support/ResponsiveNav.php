<?php

namespace App\Engines\Builder\Support;

/**
 * MOBILE-4 (2026-08-31): the single source of truth for the generated site's responsive navigation.
 *
 * This used to live only in PublishedSiteMiddleware, so it applied when a site was served through its domain and
 * NOT when the same site was served as a static export (/storage/sites/{id}/index.html) -- which is what drafts and
 * previews use. A customer building on a phone therefore saw a site whose nav overflowed the screen.
 *
 * Additive, idempotent and fail-open: it only ever appends a <style> block before </head>, never rewrites markup.
 */
final class ResponsiveNav
{
    public const MARKER = 'lu-mobile-nav';

    public static function css(): string
    {
        return '<style id="' . self::MARKER . '">'
            . '@media(max-width:820px){'
            .   'nav .inner,.nav .inner{flex-wrap:wrap!important}'
            .   '.nav-links{flex-wrap:wrap!important;justify-content:center;row-gap:10px;column-gap:14px;max-width:100%}'
            . '}'
            // MOBILE-4: wrapping alone still left a 32px column gap and a nav that ran past a 390px screen.
            . '@media(max-width:560px){'
            .   '.nav-links{column-gap:10px;row-gap:8px;font-size:13px}'
            .   '.nav-links a{white-space:nowrap}'
            .   '.nav .inner,nav .inner{gap:8px;padding-left:14px;padding-right:14px}'
            .   '.nav-cta{margin-left:0}'
            . '}'
            // MOBILE-6: older template families collapse their nav and hero on a phone but leave multi-column
            // grids (footers especially) at their desktop track counts, which pushes the page wider than the
            // screen. auto-fit keeps whatever genuinely fits instead of forcing one column, so a deliberate
            // two-up mobile layout survives.
            . '@media(max-width:640px){'
            .   '[class*="-grid"],[class*="-inner"],[class*="-cols"],[class*="-strip"],[class*="-slots"],[class*="-list"]{'
            .     'grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr))!important;'
            .     'column-gap:min(3vw,18px)!important'
            .   '}'
            .   'img,video,iframe,table{max-width:100%!important}'
            // MOBILE-7: grids were only half of it — flex ROWS (social icon strips, link rows, button rows) are
            // declared nowrap and spill outside their own box once the column narrows.
            .   '[class*="-social"],[class*="-links"],[class*="-row"],[class*="-actions"],[class*="-buttons"]{flex-wrap:wrap!important}'
            .   'input,select,textarea,.form-field{max-width:100%!important;box-sizing:border-box!important}'
            // MOBILE-8: a grid item's automatic minimum is its MIN-CONTENT, and an <input> carries an intrinsic
            // width, so a single-column grid can still compute a track wider than its own content box. Measured:
            // a 253px booking form with a 343px column. min-width:0 lets the track obey the container.
            .   '[class*="-grid"]>*,[class*="-inner"]>*,[class*="-form"]>*,[class*="-cols"]>*,[class*="-strip"]>*,[class*="-slots"]>*,[class*="-list"]>*{min-width:0!important}'
            .   'input,select,textarea{min-width:0!important;width:100%!important}'
            . '}'
            . '</style>';
    }

    /** Append the rule before </head>. Returns the html untouched if there is no nav, or it is already present. */
    public static function inject(string $html): string
    {
        try {
            if (stripos($html, 'nav-links') === false) return $html;
            if (stripos($html, self::MARKER) !== false) return $html;
            $out = preg_replace('#</head>#i', self::css() . '</head>', $html, 1, $n);
            return ($n && $out !== null) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }
}
