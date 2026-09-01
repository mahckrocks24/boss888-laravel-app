<?php

namespace App\Engines\Builder\Support;

/**
 * The generated site's responsive navigation — the single source of truth.
 *
 * This lives here rather than in PublishedSiteMiddleware because a site is served two ways: through its own
 * domain, and as the static export at /storage/sites/{id}/index.html that drafts, previews and the editor use.
 * A rule that lives in only one of those is a rule that half the customer's own site never gets.
 *
 * MOBILE-9 (2026-09-01) — it used to make the nav WRAP. On a phone that produced exactly what the owner
 * described as a pinched menu: seven links folded onto three cramped rows, each tap target a few pixels tall,
 * eating a third of the screen above the fold. Wrapping stops a nav overflowing; it does not make it usable.
 *
 * So on a phone the links now collapse behind a hamburger, which is what a visitor expects and what the rest
 * of the web does. Two constraints shaped the implementation:
 *
 *   It must work on sites that ALREADY EXIST. Thousands of exports are sitting on disk with no toggle button
 *   in their markup, so the button is created at runtime rather than required in the template.
 *
 *   It must not guess the palette. Templates run from cream to near-black, so the panel reads the nav's own
 *   effective background instead of assuming white — a hardcoded colour would make the menu unreadable on
 *   every dark template.
 *
 * Additive, idempotent and fail-open throughout: it appends before </head>, never rewrites markup, and any
 * error leaves the page exactly as it was.
 */
final class ResponsiveNav
{
    public const MARKER = 'lu-mobile-nav';

    /** Below this the links collapse behind the hamburger. */
    private const BREAKPOINT = 820;

    /**
     * Some sites already have a mobile nav of their own.
     *
     * Chef Red's site ships a hand-built `<button class="nav-burger" onclick="toggleMob()">` with its own
     * `.mob-nav` panel, and it works. Adding ours on top would have put TWO hamburgers in the corner of a
     * live customer site — a regression introduced by a fix. Where a site has already solved this, we add
     * nothing to the nav and contribute only the page-overflow rules, which are orthogonal and still wanted.
     */
    public static function hasOwnMobileNav(string $html): bool
    {
        foreach (['nav-burger', 'mob-nav', 'hamburger', 'menu-toggle', 'nav-toggle', 'mobile-menu'] as $needle) {
            if (stripos($html, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function css(bool $withNav = true): string
    {
        $bp = self::BREAKPOINT;

        return '<style id="' . self::MARKER . '-' . self::VERSION . '">'
            . ($withNav ? self::navCss($bp) : '')
            . self::overflowCss()
            . '</style>';
    }

    private static function navCss(int $bp): string
    {
        return ''
            // The button does not exist on desktop, and does not exist at all until the script builds it.
            . '.lu-nav-toggle{display:none;align-items:center;justify-content:center;width:44px;height:44px;'
            .   'margin-left:auto;padding:0;background:transparent;border:0;border-radius:8px;cursor:pointer;'
            .   'color:currentColor;flex:0 0 auto}'
            . '.lu-nav-toggle:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
            . '.lu-nav-toggle span{display:block;width:22px;height:2px;background:currentColor!important;'
            .   'border-radius:2px;position:relative;transform:none!important;opacity:1!important}'
            . '.lu-nav-toggle span::before,.lu-nav-toggle span::after{content:"";position:absolute;left:0;'
            .   'width:22px;height:2px;background:currentColor!important;border-radius:2px;'
            .   'transform:none!important;opacity:1!important}'
            . '.lu-nav-toggle span::before{top:-7px}'
            . '.lu-nav-toggle span::after{top:7px}'
            // The bars stay bars. Templates style span::before/::after for their own purposes, so a morph
            // held together by !important becomes a specificity argument inside someone else's stylesheet.
            // The open state is announced by the panel itself, by aria-expanded, and by the button tint.
            . '.lu-nav-open .lu-nav-toggle{background:rgba(128,128,128,.18)}'

            . '@media(max-width:' . $bp . 'px){'
            .   '.lu-nav-toggle{display:flex}'
            // The bar itself stays a single row: logo, then the button. It must not wrap any more.
            .   'nav .inner,.nav .inner{position:relative;flex-wrap:nowrap!important;align-items:center;gap:10px}'
            .   'nav .logo,.nav .logo{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            // The links become a panel under the bar rather than a second and third row inside it.
            .   '.nav-links{display:none!important;position:absolute;top:100%;left:0;right:0;'
            .     'flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;'
            .     'background:var(--lu-nav-panel-bg,#fff);color:inherit;'
            .     'border-top:1px solid rgba(128,128,128,.22);box-shadow:0 14px 34px rgba(0,0,0,.18);'
            .     'padding:8px;gap:2px;margin:0;max-height:72vh;overflow-y:auto;z-index:9999}'
            .   '.lu-nav-open .nav-links{display:flex!important}'
            // Comfortable targets, and a link that is too long wraps instead of forcing the panel wider.
            .   '.nav-links>a,.nav-links>.nav-cta{display:flex;align-items:center;min-height:44px;'
            .     'padding:11px 14px;margin:0;white-space:normal;border-radius:8px;width:auto;text-align:left}'
            .   '.nav-links>a:hover,.nav-links>a:focus-visible{background:rgba(128,128,128,.14)}'
            // The call-to-action keeps its emphasis but sits in the flow like everything else.
            .   '.nav-links>.nav-cta{margin-top:6px;justify-content:center;text-align:center}'
            . '}'

            ;
    }

    /**
     * Page-overflow work, independent of the nav and wanted on every site: older template families collapse
     * their nav and hero on a phone but leave multi-column grids at desktop track counts, which pushes the
     * page wider than the screen.
     */
    private static function overflowCss(): string
    {
        return ''
            // older template families collapse their nav and hero on a phone but leave multi-column grids at
            // desktop track counts, which pushes the page wider than the screen.
            . '@media(max-width:640px){'
            .   '[class*="-grid"],[class*="-inner"],[class*="-cols"],[class*="-strip"],[class*="-slots"],[class*="-list"]{'
            .     'grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr))!important;'
            .     'column-gap:min(3vw,18px)!important'
            .   '}'
            .   'img,video,iframe,table{max-width:100%!important}'
            // Flex ROWS (social strips, link rows, button rows) are declared nowrap and spill once narrow.
            .   '[class*="-social"],[class*="-links"],[class*="-row"],[class*="-actions"],[class*="-buttons"]{flex-wrap:wrap!important}'
            .   'input,select,textarea,.form-field{max-width:100%!important;box-sizing:border-box!important}'
            // A grid item's automatic minimum is its min-content, and an <input> carries an intrinsic width,
            // so a single-column grid can still compute a track wider than its own content box.
            .   '[class*="-grid"]>*,[class*="-inner"]>*,[class*="-form"]>*,[class*="-cols"]>*,[class*="-strip"]>*,[class*="-slots"]>*,[class*="-list"]>*{min-width:0!important}'
            .   'input,select,textarea{min-width:0!important;width:100%!important}'
            . '}'
            // The nav panel is the one place the 640 rule must not apply: it is a deliberate single column.
            . '@media(max-width:640px){.nav-links{flex-wrap:nowrap!important}}';
    }

    /**
     * The toggle, built at runtime so sites exported before the hamburger existed get it too.
     *
     * Deliberately plain ES5 with no dependencies: this runs inside a customer's own published page, which may
     * be served from a static file with nothing else on it.
     */
    public static function script(): string
    {
        return '<script id="' . self::MARKER . '-' . self::VERSION . '-js">(function(){try{'
            . 'function ready(fn){if(document.readyState!=="loading"){fn();}'
            .   'else{document.addEventListener("DOMContentLoaded",fn);}}'
            . 'ready(function(){'
            .   'var links=document.querySelector(".nav-links");if(!links)return;'
            .   'var inner=links.parentNode;if(!inner)return;'
            .   'if(inner.querySelector(".lu-nav-toggle"))return;'   // idempotent
            // Read the nav's real background so the panel matches the template instead of assuming white.
            .   'var probe=inner,bg="";'
            .   'while(probe&&probe!==document.documentElement){'
            .     'var c=getComputedStyle(probe).backgroundColor;'
            .     'if(c&&c!=="transparent"&&c.indexOf("rgba(0, 0, 0, 0)")===-1){bg=c;break;}'
            .     'probe=probe.parentNode;'
            .   '}'
            .   'if(bg){links.style.setProperty("--lu-nav-panel-bg",bg);}'
            .   'var btn=document.createElement("button");'
            .   'btn.type="button";btn.className="lu-nav-toggle";'
            .   'btn.setAttribute("aria-label","Menu");'
            .   'btn.setAttribute("aria-expanded","false");'
            .   'btn.appendChild(document.createElement("span"));'
            .   'if(!links.id){links.id="lu-nav-links";}'
            .   'btn.setAttribute("aria-controls",links.id);'
            .   'inner.appendChild(btn);'
            .   'function setOpen(v){'
            .     'inner.classList.toggle("lu-nav-open",v);'
            .     'document.documentElement.classList.toggle("lu-nav-open",v);'
            .     'btn.setAttribute("aria-expanded",v?"true":"false");'
            .   '}'
            .   'btn.addEventListener("click",function(e){'
            .     'e.stopPropagation();setOpen(btn.getAttribute("aria-expanded")!=="true");'
            .   '});'
            // Choosing a destination closes the menu, or the panel covers the section just jumped to.
            .   'links.addEventListener("click",function(e){'
            .     'if(e.target&&e.target.closest&&e.target.closest("a")){setOpen(false);}'
            .   '});'
            .   'document.addEventListener("click",function(e){'
            .     'if(!inner.contains(e.target)){setOpen(false);}'
            .   '});'
            .   'document.addEventListener("keydown",function(e){'
            .     'if(e.key==="Escape"){setOpen(false);btn.focus();}'
            .   '});'
            // Resizing back to desktop must not leave the page in the open state.
            .   'window.addEventListener("resize",function(){'
            .     'if(window.innerWidth>' . self::BREAKPOINT . '){setOpen(false);}'
            .   '});'
            . '});}catch(e){}})();</script>';
    }

    /** The build this markup carries, so an older one can be recognised and replaced rather than kept. */
    public const VERSION = 'mobile9d-hamburger';

    /**
     * Append the rules and the toggle before </head>.
     *
     * Deliberately REPLACES an older block rather than skipping when one is found. Exports already on disk
     * carry the previous wrapping-only CSS baked in at build time, and a plain "already present" check would
     * have left every one of them on the pinched menu forever while this code looked fixed. Only a block of
     * the current version is left alone.
     */
    public static function inject(string $html): string
    {
        try {
            if (stripos($html, 'nav-links') === false) {
                return $html;
            }

            // The current build is already here — nothing to do.
            if (str_contains($html, self::MARKER . '-' . self::VERSION)) {
                return $html;
            }

            // An older build is here: take its style and script out before adding the new one.
            $html = (string) preg_replace('#<style id="' . self::MARKER . '[^"]*".*?</style>#is', '', $html);
            $html = (string) preg_replace('#<script id="' . self::MARKER . '[^"]*".*?</script>#is', '', $html);

            // A site with its own working hamburger keeps it; we contribute only the overflow rules.
            $ownNav = self::hasOwnMobileNav($html);
            $payload = self::css(! $ownNav) . ($ownNav ? '' : self::script());
            $pos = stripos($html, '</head>');

            return $pos === false
                ? $payload . $html
                : substr($html, 0, $pos) . $payload . substr($html, $pos);
        } catch (\Throwable) {
            return $html;
        }
    }
}
