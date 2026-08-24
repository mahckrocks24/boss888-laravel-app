<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Throwable;

/**
 * AdSlotInjector — put an EMPTY, cacheable ad slot into a rendered page.
 *
 * WHY EMPTY
 * Tenant HTML is served with `Cache-Control: s-maxage=60` behind Cloudflare. A
 * creative baked into that HTML would be served identically to every visitor
 * for 60 seconds, uncounted — which destroys impression accounting, frequency
 * capping and budget pacing simultaneously. Those three are exactly what an
 * advertiser pays for. So the markup that gets cached is a placeholder; the
 * creative is fetched per pageview by the tag.
 *
 * HTML IN, HTML OUT — NOTHING ELSE
 * This class is deliberately a pure string transform with no framework
 * coupling, so it can be unit-tested against real captured tenant HTML without
 * a request, and so wiring it into PublishedSiteMiddleware later is a one-line
 * call at each of the three return sites rather than a large change.
 *
 * ── LAYOUT SAFETY, verified against all 31 templates ──────────────────────
 *  - Every template ends `</footer>` → `<script>` → `</body>`, so injecting
 *    before `</body>` collides with nothing.
 *  - NO template pins anything to the viewport bottom: all 29 `bottom:0` rules
 *    are `position:absolute` decoration inside cards (stat underlines, contact
 *    rows), not fixed elements. Checked rule-block by rule-block, not by grep.
 *  - The CHATBOT888 bubble IS viewport-pinned: `position:fixed; bottom:24px;
 *    z-index:99999`. A naive full-width bar would let that bubble sit on top of
 *    paid creative. So the bar reserves right-side padding for the bubble's
 *    footprint and sits BELOW it in z-order — the tenant's chat always wins,
 *    and the creative is never underneath it.
 *  - The slot reserves its own height in CSS, so filling it causes NO layout
 *    shift. CLS is a Core Web Vitals metric on the tenant's own site and we do
 *    not get to damage it.
 *  - All class names are `lu-ad-` prefixed. Verified: no template uses that
 *    prefix, and templates otherwise use generic names (`.footer-inner`,
 *    `.gallery-item`) that an unprefixed name would collide with.
 */
final class AdSlotInjector
{
    /** Marker used for the idempotency guard — mirrors the chatbot dedup check. */
    public const MARKER = 'lu-ad-slot';

    /** Below the chatbot bubble (99999) so the tenant's chat always wins. */
    private const Z_INDEX = 99990;

    /** Horizontal room reserved so the chat bubble never covers the creative. */
    private const CHAT_BUBBLE_RESERVE_PX = 84;

    public function __construct(
        private readonly AdSettingsService $settings,
    ) {
    }

    /**
     * Inject the slot placeholder and tag loader before `</body>`.
     *
     * Returns the HTML UNCHANGED whenever anything is off, missing or already
     * present. This method must never be a reason a tenant page fails to render.
     *
     * @param  string  $html      the fully rendered page
     * @param  int     $websiteId
     * @param  string  $slotCode  the slot to place (launch slot: footer_sticky)
     */
    public function inject(string $html, int $websiteId, string $slotCode = 'footer_sticky'): string
    {
        try {
            if ($html === '' || $websiteId <= 0) {
                return $html;
            }

            // Already injected (a page can pass through more than one path).
            if (str_contains($html, self::MARKER)) {
                return $html;
            }

            // Master switch. Checked here as well as in the decision engine so
            // that with ads off we do not even add markup to the page.
            if (! $this->settings->bool(AdSettings::MASTER_ENABLED)) {
                return $html;
            }

            $snippet = $this->snippet($websiteId, $slotCode);

            $pos = strripos($html, '</body>');

            if ($pos === false) {
                // No </body> (a fragment, or malformed markup). Appending is
                // still valid HTML and still renders.
                return $html . $snippet;
            }

            return substr($html, 0, $pos) . $snippet . substr($html, $pos);
        } catch (Throwable) {
            // A failure here must never break a customer's website.
            return $html;
        }
    }

    /** The markup + styles + loader. Self-contained; no external requests. */
    public function snippet(int $websiteId, string $slotCode): string
    {
        $origin  = $this->origin();
        $version = $this->settings->string(AdSettings::AD_TAG_VERSION);
        $label   = $this->settings->string(AdSettings::DISCLOSURE_LABEL);

        $slotSafe    = htmlspecialchars($slotCode, ENT_QUOTES, 'UTF-8');
        $labelSafe   = htmlspecialchars($label !== '' ? $label : 'Sponsored', ENT_QUOTES, 'UTF-8');
        $versionSafe = rawurlencode($version !== '' ? $version : 'v1');

        $css = $this->css();

        return <<<HTML

<!-- LevelUp Ads -->
<style>{$css}</style>
<div id="lu-ad-slot" class="lu-ad-slot" data-lu-slot="{$slotSafe}" data-lu-site="{$websiteId}" hidden>
  <span class="lu-ad-label">{$labelSafe}</span>
  <div class="lu-ad-body"></div>
</div>
<script src="{$origin}/ads.js?w={$websiteId}&v={$versionSafe}" async></script>

HTML;
    }

    /**
     * Slot styling.
     *
     * `min-height` reserves the space up front — the slot is `hidden` until
     * filled, and revealing it must not push the page around.
     */
    private function css(): string
    {
        $z       = self::Z_INDEX;
        $reserve = self::CHAT_BUBBLE_RESERVE_PX;

        return <<<CSS
.lu-ad-slot{position:fixed;left:0;right:0;bottom:0;z-index:{$z};display:flex;align-items:center;justify-content:center;gap:8px;
min-height:50px;padding:4px {$reserve}px 4px 8px;box-sizing:border-box;
background:#fff;border-top:1px solid rgba(0,0,0,.12);box-shadow:0 -1px 6px rgba(0,0,0,.08);
font:400 12px/1.3 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#333}
.lu-ad-slot[hidden]{display:none}
/* A position:fixed bar permanently covers the last 50/90px of the page, so a
   visitor scrolled to the bottom loses the end of the tenant's footer.
   Measured: 50px occluded at 390px, 99px at 1280px. The tag adds
   .lu-ad-filled to <html> ONLY when a creative actually renders, so a site with
   no fill gets no dead space. Bottom padding extends the scrollable area
   without moving anything already on screen — no layout shift. Injected last in
   the document, so it wins source order against a template's own body rule at
   equal specificity. */
html.lu-ad-filled body{padding-bottom:50px}
.lu-ad-label{flex:0 0 auto;font-size:9px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8a;
background:rgba(0,0,0,.04);border-radius:2px;padding:2px 5px}
.lu-ad-body{flex:1 1 auto;display:flex;align-items:center;justify-content:center;overflow:hidden;max-height:60px}
.lu-ad-body a{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none}
.lu-ad-body img{display:block;max-width:100%;max-height:60px;height:auto}
@media(min-width:768px){.lu-ad-slot{min-height:90px}.lu-ad-body,.lu-ad-body img{max-height:90px}
html.lu-ad-filled body{padding-bottom:90px}}
@media(prefers-color-scheme:dark){.lu-ad-slot{background:#15171c;color:#e8e8e8;border-top-color:rgba(255,255,255,.12)}
.lu-ad-label{color:#9aa0a6;background:rgba(255,255,255,.06)}}
/* ── Session interstitial ──────────────────────────────────────────────────
   A modal is blocking by definition, so it sits ABOVE the chatbot bubble
   (99999) rather than beside it. Square is width-constrained, portrait is
   height-constrained — sizing both identically would push a 3:4 creative off
   a short viewport. aspect-ratio reserves the box before the asset loads, so
   the modal does not resize as the image arrives. */
.lu-ad-modal-backdrop{position:fixed;inset:0;z-index:2147482000;
display:flex;align-items:center;justify-content:center;padding:20px;
background:radial-gradient(130% 120% at 50% 38%,rgba(9,10,14,.58) 0%,rgba(6,7,10,.88) 100%);
-webkit-backdrop-filter:blur(7px) saturate(.86);backdrop-filter:blur(7px) saturate(.86);
opacity:0;visibility:hidden;pointer-events:none;
transition:opacity .24s cubic-bezier(.2,.8,.25,1),visibility 0s linear .24s}
.lu-ad-modal-backdrop.on{opacity:1;visibility:visible;pointer-events:auto;transition-delay:0s}
.lu-ad-modal{position:relative;max-width:100%;max-height:100%;border-radius:14px;overflow:hidden;
background:#0d0e11;transform:scale(.965) translateY(6px);opacity:0;
box-shadow:0 0 0 1px rgba(255,255,255,.07),0 2px 4px rgba(0,0,0,.24),0 28px 70px -14px rgba(0,0,0,.62);
transition:transform .3s cubic-bezier(.2,.8,.25,1),opacity .22s ease}
.lu-ad-modal-backdrop.on .lu-ad-modal{transform:none;opacity:1}
.lu-ad-modal-media{display:block;width:auto;height:auto}
.lu-ad-modal-media[data-ar="1:1"]{aspect-ratio:1/1;max-width:min(86vw,540px);max-height:80vh}
.lu-ad-modal-media[data-ar="3:4"]{aspect-ratio:3/4;max-width:min(86vw,620px);max-height:82vh}
.lu-ad-modal-media[data-ar="4:5"]{aspect-ratio:4/5;max-width:min(86vw,600px);max-height:82vh}
.lu-ad-modal-media a{display:block;width:100%;height:100%;outline-offset:-2px}
.lu-ad-modal-media img,.lu-ad-modal-media video{display:block;width:100%;height:100%;object-fit:cover;background:#0d0e11}
/* Disclosure: legible on any creative without a heavy slab — a translucent,
   blurred surface reads on both a white product shot and a dark video frame. */
.lu-ad-modal-label{position:absolute;top:12px;left:12px;z-index:3;
font:600 9.5px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.94);
background:rgba(16,17,21,.42);border:1px solid rgba(255,255,255,.13);border-radius:5px;
padding:5px 8px;-webkit-backdrop-filter:blur(10px) saturate(1.3);backdrop-filter:blur(10px) saturate(1.3);
text-shadow:0 1px 2px rgba(0,0,0,.34)}
/* Close control: the remaining seconds, then an X. Nothing else — no ring, no
   disc, no chrome. 44px hit area is kept for accessibility while the mark
   itself stays light; a drop shadow is what makes it legible over any creative
   rather than a background shape. The two states are stacked in one grid cell
   and cross-faded, so the control never shifts position as it swaps. */
.lu-ad-modal-close{position:absolute;top:8px;right:8px;z-index:3;
width:44px;height:44px;display:grid;place-items:center;padding:0;border:0;
background:transparent;cursor:default;-webkit-tap-highlight-color:transparent;
appearance:none;-webkit-appearance:none}
.lu-ad-modal-backdrop:focus{outline:none}
.lu-ad-modal-close:focus{outline:none}
.lu-ad-modal-close:focus-visible{outline:2px solid rgba(255,255,255,.92);outline-offset:-6px;border-radius:4px}
.lu-ad-modal-close[data-ready="1"]{cursor:pointer}
.lu-ad-modal-close .lu-count{grid-area:1/1;
font:600 16px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
font-variant-numeric:tabular-nums;color:rgba(255,255,255,.97);
text-shadow:0 1px 4px rgba(0,0,0,.6),0 0 1px rgba(0,0,0,.5);
transition:opacity .18s ease}
.lu-ad-modal-close .lu-x{grid-area:1/1;width:19px;height:19px;display:block;opacity:0;
stroke:rgba(255,255,255,.97);stroke-width:1.9;stroke-linecap:round;fill:none;
filter:drop-shadow(0 1px 4px rgba(0,0,0,.6));transition:opacity .18s ease}
.lu-ad-modal-close[data-ready="1"] .lu-count{opacity:0}
.lu-ad-modal-close[data-ready="1"] .lu-x{opacity:1}
.lu-ad-modal-close[data-ready="1"]:hover .lu-x{stroke:#fff;transform:scale(1.08)}
@media(prefers-reduced-motion:reduce){
.lu-ad-modal-backdrop,.lu-ad-modal{transition:none}
.lu-ad-modal{transform:none}
.lu-ad-modal-close .lu-count,.lu-ad-modal-close .lu-x{transition:none}}
CSS;
    }

    /**
     * The platform origin the tag is served from.
     * `config('app.url')` can return the bare droplet IP, which would cause
     * mixed-content and CORS failures from a tenant subdomain — so we resolve
     * the same way injectChatbotWidget does.
     */
    private function origin(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';

        return str_contains($host, 'levelupgrowth.io')
            ? 'https://' . $host
            : 'https://staging.levelupgrowth.io';
    }
}
