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
        $needle = false;
        foreach (['nav-burger', 'mob-nav', 'hamburger', 'menu-toggle', 'nav-toggle', 'mobile-menu'] as $n) {
            if (stripos($html, $n) !== false) { $needle = true; break; }
        }
        if (! $needle) {
            return false;
        }
        // DEAD-BURGER (2026-09-05): 14 of 31 templates ship `<button class="nav-burger" onclick="toggleMob()">`
        // whose toggleMob() ONLY animates the three spans into an X — it opens nothing. The needle test above
        // treated that as "has its own mobile nav" and we skipped injection, so every mobile visitor got a
        // hamburger that does nothing and an empty menu. A mobile nav is only "own" when there is a panel
        // (a .mob-nav / .mobile-menu element) or the toggle function actually opens something.
        if (preg_match('/(?:class|id)="[^"]*\b(?:mob-nav|mobile-menu|mobile-nav|nav-panel)\b/i', $html)) {
            return true;
        }
        // body = up to the first closing brace (a toggle that opens something has no nested braces either)
        if (preg_match('/function\s+(?:toggleMob|toggleMenu|toggleNav|toggleBurger)\s*\([^)]*\)\s*\{([^}]*)\}/is', $html, $m)) {
            return (bool) preg_match('/classList|\.open\b|style\.display|\.hidden\b|aria-expanded|\.active\b/i', $m[1]);
        }
        // The needle sits only in CSS or text (news_channel blog pages keep `.nav-burger{}` rules but drop the
        // button): there is no control at all, so there is no menu on mobile. Inject ours.
        if (! preg_match('/<(?:button|a|div|span|label)[^>]*\b(?:nav-burger|hamburger|menu-toggle|nav-toggle|burger)\b[^>]*>/i', $html, $b)) {
            return false;
        }
        if (preg_match('/onclick="\s*(\w+)\s*\(/i', $b[0], $h)) {
            // The burger calls a handler that is not defined anywhere in the page (blog sub-pages copy the nav
            // markup but not the template script): the click throws and nothing opens. That is a dead burger too.
            $fn = preg_quote($h[1], '/');
            return (bool) preg_match('/function\s+' . $fn . '\s*\(|\b' . $fn . '\s*=\s*(?:function|\()/i', $html);
        }
        // No inline handler: a script must select the burger and bind it. If nothing in the page does, the
        // control is inert (blog sub-pages again).
        return (bool) preg_match('/(?:querySelector(?:All)?|getElementById)\(\s*[\'"](?:#?burger|\.?nav-burger|\.?hamburger|\.?menu-toggle|\.?nav-toggle)\b/i', $html);
    }
    public static function css(bool $withNav = true): string
    {
        $bp = self::BREAKPOINT;

        return '<style id="' . self::MARKER . '-' . self::VERSION . '">'
            . ($withNav ? self::navCss($bp) : '')
            . self::overflowCss()
            . self::controlsCss()
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
            // A template's decorative burger (animates only, opens nothing) must not sit next to the working one.
            .   '.nav-burger,#burger{display:none!important}'
            // The bar itself stays a single row: logo, then the button. It must not wrap any more.
            .   'nav .inner,.nav .inner{position:relative;flex-wrap:nowrap!important;align-items:center;gap:10px}'
            .   'nav .logo,.nav .logo{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            // LONG-NAME GUARD (2026-09-11): templates without .inner/.logo (the dental-clone family) appended the
            // toggle to <nav> itself with an unshrinkable logo, pushing the button past the right edge.
            .   ':is(nav,.nav,header):has(> .lu-nav-toggle){display:flex!important;flex-wrap:nowrap!important;align-items:center;gap:10px;max-width:100%;box-sizing:border-box}'
            .   ':is(nav,.nav,header):has(> .lu-nav-toggle) > :first-child,nav .nav-logo,.nav .nav-logo,nav .brand,.nav .brand,nav .nav-brand{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            .   '.lu-nav-toggle{flex:0 0 auto!important;margin-left:auto}'
            // The links become a panel under the bar rather than a second and third row inside it.
            .   '.nav-links{display:none!important;position:absolute;top:100%;left:0;right:0;'
            .     'flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;'
            .     'background:var(--lu-nav-panel-bg,#fff);color:inherit;'
            .     'border-top:1px solid rgba(128,128,128,.22);box-shadow:0 14px 34px rgba(0,0,0,.18);'
            .     'padding:8px;gap:2px;margin:0;max-height:72vh;overflow-y:auto;z-index:9999}'
            .   '.lu-nav-open .nav-links{display:flex!important}'
            // HAMB-1 (2026-09-23): a template's own phone rule (.nav-links a:not(.nav-cta){display:none}) hid every item inside the open panel
            .   '.lu-nav-open .nav-links>a,.lu-nav-open .nav-links>li,.lu-nav-open .nav-links>li>a,.lu-nav-open .nav-links>.nav-cta{display:flex!important}'
            // Comfortable targets, and a link that is too long wraps instead of forcing the panel wider.
            .   '.nav-links>a,.nav-links>.nav-cta{display:flex;align-items:center;min-height:44px;'
            .     'padding:11px 14px;margin:0;white-space:normal;border-radius:8px;width:auto;text-align:left}'
            .   '.nav-links>a:hover,.nav-links>a:focus-visible{background:rgba(128,128,128,.14)}'
            // The call-to-action keeps its emphasis but sits in the flow like everything else.
            .   '.nav-links>.nav-cta{margin-top:6px;justify-content:center;text-align:center}'
            . '}'
            // BTN-1 (2026-09-23): hero call-to-actions that wrap on a phone stack at full width instead of two ragged widths
            . '@media(max-width:640px){.hero-ctas,.hero-buttons,.hero-actions{flex-direction:column!important;align-items:stretch!important;gap:12px}.hero-ctas>a,.hero-ctas>button,.hero-buttons>a,.hero-buttons>button,.hero-actions>a,.hero-actions>button{width:100%!important;box-sizing:border-box;display:flex;justify-content:center;text-align:center}}'

            ;
    }

    /**
     * Page-overflow work, independent of the nav and wanted on every site: older template families collapse
     * their nav and hero on a phone but leave multi-column grids at desktop track counts, which pushes the
     * page wider than the screen.
     */
    /**
     * SELECT-1 (2026-09-23, Owner: "Always use templates' css on all websites modals and dropdowns and scrollers").
     * The dropdown is drawn by the site: the trigger copies the template's own field styling (set by the script from
     * the select's computed style), the list uses the palette roles, and scrollbars take the accent.
     */
    private static function controlsCss(): string
    {
        return '.lu-dd{position:relative;display:block;width:100%}'
            . '.lu-dd-btn{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;cursor:pointer;text-align:left;font:inherit;line-height:1.3}'
            . '.lu-dd-btn::after{content:"";width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);margin-top:-4px;flex:0 0 auto;opacity:.75}'
            . '.lu-dd.open .lu-dd-btn::after{transform:rotate(-135deg);margin-top:4px}'
            . '.lu-dd-list{position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:9998;display:none;max-height:min(46vh,320px);overflow-y:auto;margin:0;padding:6px;list-style:none;'
            .   'background:var(--lu-surface,var(--lu-nav-panel-bg,#fff));color:var(--lu-text,inherit);border:1px solid rgba(128,128,128,.28);border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)}'
            . '.lu-dd.open .lu-dd-list{display:block}'
            . '.lu-dd.up .lu-dd-list{top:auto;bottom:calc(100% + 6px)}'
            . '.lu-dd-opt{display:flex;align-items:center;min-height:42px;padding:9px 12px;border-radius:7px;cursor:pointer;font:inherit;line-height:1.3;color:inherit}'
            . '.lu-dd-opt:hover,.lu-dd-opt.active{background:rgba(128,128,128,.14)}'
            . '.lu-dd-opt[aria-selected="true"]{background:var(--lu-accent,var(--lu-primary,rgba(128,128,128,.22)));color:var(--lu-on-accent,#fff)}'
            . '.lu-dd-opt[aria-disabled="true"]{opacity:.5;cursor:default}'
            . '.lu-dd-native{position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;border:0!important;opacity:0!important;pointer-events:none!important;left:0;bottom:0}'
            // scrollbars in the site's colours (the page, the nav panel, the dropdown list)
            . 'html{scrollbar-width:thin;scrollbar-color:var(--lu-accent,var(--lu-primary,rgba(128,128,128,.6))) transparent}'
            . '*::-webkit-scrollbar{width:9px;height:9px}*::-webkit-scrollbar-track{background:transparent}'
            . '*::-webkit-scrollbar-thumb{background:var(--lu-accent,var(--lu-primary,rgba(128,128,128,.6)));border-radius:8px;border:2px solid transparent;background-clip:padding-box}';
    }

    /** SELECT-1: the script that turns each <select> into a site-styled dropdown. Opt out with data-lu-native. */
    private static function controlsScript(): string
    {
        return 'function luSelects(){try{var sel=document.querySelectorAll("select:not([multiple]):not([data-lu-native]):not([data-lu-dd])");'
            . 'Array.prototype.forEach.call(sel,function(s){s.setAttribute("data-lu-dd","1");'
            .   'var cs=getComputedStyle(s),wrap=document.createElement("div");wrap.className="lu-dd";'
            .   'var btn=document.createElement("button");btn.type="button";btn.className="lu-dd-btn";btn.setAttribute("aria-haspopup","listbox");btn.setAttribute("aria-expanded","false");'
            .   '["font","color","backgroundColor","borderTop","borderRight","borderBottom","borderLeft","borderRadius","paddingTop","paddingRight","paddingBottom","paddingLeft","minHeight","boxShadow","letterSpacing","textTransform"].forEach(function(k){try{btn.style[k]=cs[k];}catch(e){}});'
            .   'if(!btn.style.minHeight||btn.style.minHeight==="0px"){btn.style.minHeight=Math.max(44,Math.round(s.getBoundingClientRect().height))+"px";}'
            .   'var lab=document.createElement("span");lab.className="lu-dd-label";lab.style.cssText="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1 1 auto";btn.appendChild(lab);'
            .   'var list=document.createElement("ul");list.className="lu-dd-list";list.setAttribute("role","listbox");'
            .   'function paint(){lab.textContent=(s.options[s.selectedIndex]||{}).text||"";list.innerHTML="";Array.prototype.forEach.call(s.options,function(o,i){var li=document.createElement("li");li.className="lu-dd-opt";li.setAttribute("role","option");li.textContent=o.text;li.setAttribute("aria-selected",i===s.selectedIndex?"true":"false");if(o.disabled){li.setAttribute("aria-disabled","true");}li.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();if(o.disabled)return;s.selectedIndex=i;s.dispatchEvent(new Event("change",{bubbles:true}));s.dispatchEvent(new Event("input",{bubbles:true}));paint();close();btn.focus();});list.appendChild(li);});}'
            .   'function open(){wrap.classList.add("open");btn.setAttribute("aria-expanded","true");var r=btn.getBoundingClientRect();wrap.classList.toggle("up",(window.innerHeight-r.bottom)<240&&r.top>240);var a=list.querySelector("[aria-selected=true]");if(a){a.classList.add("active");try{a.scrollIntoView({block:"nearest"});}catch(e){}}}'
            .   'function close(){wrap.classList.remove("open");btn.setAttribute("aria-expanded","false");Array.prototype.forEach.call(list.children,function(li){li.classList.remove("active");});}'
            .   'btn.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();if(wrap.classList.contains("open"))close();else{document.querySelectorAll(".lu-dd.open").forEach(function(o){o.classList.remove("open");});open();}});'
            .   'btn.addEventListener("keydown",function(e){var items=Array.prototype.slice.call(list.children),cur=items.indexOf(list.querySelector(".active"));'
            .     'if(e.key==="ArrowDown"||e.key==="ArrowUp"){e.preventDefault();if(!wrap.classList.contains("open"))open();var n=e.key==="ArrowDown"?Math.min(items.length-1,cur+1):Math.max(0,cur-1);items.forEach(function(li){li.classList.remove("active");});if(items[n]){items[n].classList.add("active");try{items[n].scrollIntoView({block:"nearest"});}catch(x){}}}'
            .     'else if(e.key==="Enter"||e.key===" "){e.preventDefault();if(wrap.classList.contains("open")){var a=list.querySelector(".active");if(a)a.click();else close();}else open();}'
            .     'else if(e.key==="Escape"){close();}});'
            .   's.addEventListener("change",paint);s.classList.add("lu-dd-native");'
            .   's.parentNode.insertBefore(wrap,s);wrap.appendChild(btn);wrap.appendChild(list);wrap.appendChild(s);paint();'
            . '});'
            . 'if(!document.documentElement.hasAttribute("data-lu-dd-doc")){document.documentElement.setAttribute("data-lu-dd-doc","1");document.addEventListener("click",function(e){if(!e.target.closest||!e.target.closest(".lu-dd")){document.querySelectorAll(".lu-dd.open").forEach(function(o){o.classList.remove("open");o.querySelector(".lu-dd-btn").setAttribute("aria-expanded","false");});}},true);}'
            . '}catch(e){}}';
    }

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
            . '});'
            . self::controlsScript()
            . 'ready(luSelects);'
            . '}catch(e){}})();</script>';
    }

    /** The build this markup carries, so an older one can be recognised and replaced rather than kept. */
    public const VERSION = 'mobile9l-controls';   // SELECT-1: site-styled dropdowns and scrollbars (HAMB-1/BTN-1 inside)

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
