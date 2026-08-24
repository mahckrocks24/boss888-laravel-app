<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdSlotInjector;
use App\Engines\Builder\Services\TemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * ADS888 — generate a VISUAL PREVIEW of the ad slot on real templates.
 *
 * WHY THIS EXISTS
 * There is currently no free-plan published website, and the three sites that
 * do exist belong to PAYING customers — putting a test advertisement on
 * chefredraymundo.com or amgtravelandtours.com is not an acceptable way to
 * check a layout. This command renders the real templates with their own sample
 * content, runs them through the REAL AdSlotInjector, and bakes in a placeholder
 * creative so the slot is visible.
 *
 * WHAT IS REAL AND WHAT IS SIMULATED — read this before drawing conclusions
 *   REAL  : the template markup, the injector's slot markup, its CSS, the
 *           z-index and chat-bubble reservation, the disclosure label,
 *           the reserved height, the position in the document.
 *   FAKED : the creative itself, and the fill. On a live page the tag fetches
 *           a creative from /api/ads/decide; those endpoints are not wired yet,
 *           so the preview fills the slot with an inline placeholder instead.
 *
 * So this proves the LAYOUT is right. It does not prove delivery works —
 * that needs the delivery plane wired, and `ads:simulate` already proves the
 * decision half.
 *
 *   php artisan ads:preview                      # 3 representative templates
 *   php artisan ads:preview --template=gym
 *   php artisan ads:preview --all                # every one of the 31
 *   php artisan ads:preview --clean              # remove the preview directory
 */
class AdsPreviewCommand extends Command
{
    protected $signature = 'ads:preview
        {--template=* : Template industry slug(s) to render}
        {--all : Render every template}
        {--clean : Delete the preview directory and exit}';

    protected $description = 'ADS888: generate visual previews of the ad slot on real templates';

    /**
     * Chosen to cover the structural variants found in the audit:
     *   restaurant   — position:fixed nav (16 templates behave this way)
     *   architecture — position:sticky nav (15 templates)
     *   news_channel — the outlier: no data-block attributes, different footer
     */
    private const DEFAULT_SET = ['restaurant', 'architecture', 'news_channel'];

    private const OUT_DIR = 'ads-preview';

    public function handle(AdSlotInjector $injector, TemplateService $templates): int
    {
        $outDir = public_path(self::OUT_DIR);

        if ($this->option('clean')) {
            File::deleteDirectory($outDir);
            $this->info("Removed {$outDir}");

            return self::SUCCESS;
        }

        $slugs = $this->resolveSlugs();

        if ($slugs === []) {
            $this->error('No templates matched.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($outDir, 0755);

        $built = [];

        foreach ($slugs as $slug) {
            try {
                $html = $this->renderTemplate($templates, $slug);

                if ($html === null) {
                    $this->warn("  skip {$slug} — template or manifest missing");
                    continue;
                }

                $html = $this->applyPreviewSlot($injector, $html, $slug);
                $html = $this->addPreviewChrome($html, $slug);

                File::put($outDir . '/' . $slug . '.html', $html);
                $built[] = $slug;

                $this->line(sprintf('  %-20s %s KB', $slug, number_format(strlen($html) / 1024, 1)));
            } catch (Throwable $e) {
                $this->warn("  skip {$slug} — " . $e->getMessage());
            }
        }

        if ($built === []) {
            $this->error('Nothing generated.');

            return self::FAILURE;
        }

        File::put($outDir . '/index.html', $this->indexPage($built));

        $origin = $this->origin();

        $this->line('');
        $this->info('Preview URLs:');
        $this->line("  {$origin}/" . self::OUT_DIR . '/');
        foreach ($built as $slug) {
            $this->line("  {$origin}/" . self::OUT_DIR . "/{$slug}.html");
        }
        $this->line('');
        $this->comment('  The slot markup, CSS and position are REAL (AdSlotInjector).');
        $this->comment('  The creative is a PLACEHOLDER — /api/ads/decide is not wired yet.');
        $this->comment('  Remove with: php artisan ads:preview --clean');
        $this->line('');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function resolveSlugs(): array
    {
        if ($this->option('all')) {
            return collect(glob(storage_path('templates/*'), GLOB_ONLYDIR))
                ->map(fn ($dir) => basename($dir))
                ->filter(fn ($slug) => is_file(storage_path("templates/{$slug}/manifest.json")))
                ->values()->all();
        }

        $requested = array_filter((array) $this->option('template'));

        return $requested !== [] ? array_values($requested) : self::DEFAULT_SET;
    }

    /** Render a template using its own manifest defaults — no customer data. */
    private function renderTemplate(TemplateService $templates, string $slug): ?string
    {
        $manifest = $templates->getManifest($slug);

        if ($manifest === null || ! is_file(storage_path("templates/{$slug}/template.html"))) {
            return null;
        }

        $variables = [];
        foreach (($manifest['variables'] ?? []) as $key => $spec) {
            $variables[$key] = is_array($spec) ? ($spec['default'] ?? '') : (string) $spec;
        }

        // No websiteId — that would gate on a chatbot widget we do not want here.
        return $templates->render($slug, $variables);
    }

    /**
     * Insert the REAL slot markup, then fill it with a placeholder.
     *
     * `AdSlotInjector::snippet()` is used rather than `inject()` because
     * `inject()` correctly refuses to add anything while the master switch is
     * off — which is the state we want production to stay in. The snippet is
     * byte-for-byte what production would emit.
     */
    private function applyPreviewSlot(AdSlotInjector $injector, string $html, string $slug): string
    {
        $snippet = $injector->snippet(0, 'footer_sticky');

        // Drop the tag loader: /ads.js is not wired, and a 404'd script would
        // just sit in the console looking like a bug.
        $snippet = preg_replace('#<script src="[^"]*ads\.js[^"]*"[^>]*></script>#i', '', $snippet) ?? $snippet;

        // Reveal the slot (production ships it hidden until the tag fills it).
        $snippet = str_replace('hidden>', '>', $snippet);

        // Fill with the placeholder creative.
        $snippet = str_replace(
            '<div class="lu-ad-body"></div>',
            '<div class="lu-ad-body">' . $this->placeholderCreative($slug) . '</div>',
            $snippet
        );

        // The tag normally reserves document bottom padding equal to the bar's
        // RENDERED height, so a fixed bar never covers the end of the footer.
        // The preview strips the tag, so it must do the same thing itself —
        // otherwise the preview would look correct while production occludes,
        // or vice versa, and the preview would be worthless as a check.
        $snippet .= "\n<script>(function(){function r(){var e=document.getElementById('lu-ad-slot');"
            . "if(!e||e.hidden)return;var h=e.offsetHeight;if(h>0)document.body.style.paddingBottom=h+'px';}"
            . "document.documentElement.classList.add('lu-ad-filled');"
            . "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',r);}else{r();}"
            . "var t=null;window.addEventListener('resize',function(){clearTimeout(t);t=setTimeout(r,150);});"
            . "window.addEventListener('load',r);})();</script>\n";

        $pos = strripos($html, '</body>');

        return $pos === false
            ? $html . $snippet
            : substr($html, 0, $pos) . $snippet . substr($html, $pos);
    }

    /**
     * A self-contained placeholder creative — inline SVG data URI, no external
     * request, matching the "nothing leaves the page" discipline of the tag.
     */
    private function placeholderCreative(string $slug): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="728" height="90">'
             . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
             . '<stop offset="0" stop-color="#6C5CE7"/><stop offset="1" stop-color="#00E5A8"/>'
             . '</linearGradient></defs>'
             . '<rect width="728" height="90" rx="4" fill="url(#g)"/>'
             . '<text x="24" y="42" font-family="Segoe UI,Helvetica,Arial,sans-serif" font-size="20" font-weight="700" fill="#fff">'
             . 'Your advertisement here</text>'
             . '<text x="24" y="66" font-family="Segoe UI,Helvetica,Arial,sans-serif" font-size="13" fill="rgba(255,255,255,.85)">'
             . 'Placeholder creative &#183; 728&#215;90 leaderboard</text>'
             . '</svg>';

        $uri = 'data:image/svg+xml;base64,' . base64_encode($svg);

        return '<a href="#" onclick="return false"><img src="' . $uri . '" alt="Placeholder advertisement" width="728" height="90"></a>';
    }

    /** noindex + a banner so nobody mistakes a preview for a live site. */
    private function addPreviewChrome(string $html, string $slug): string
    {
        $meta = '<meta name="robots" content="noindex,nofollow">';

        $html = preg_match('#<head[^>]*>#i', $html)
            ? preg_replace('#(<head[^>]*>)#i', '$1' . $meta, $html, 1)
            : $meta . $html;

        $banner = '<div style="position:fixed;top:0;left:0;right:0;z-index:2147483000;'
            . 'background:#111;color:#fff;font:600 11px/1.4 system-ui,sans-serif;'
            . 'padding:6px 10px;text-align:center;letter-spacing:.03em">'
            . 'ADS888 PREVIEW &#183; ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')
            . ' &#183; the interstitial opens automatically &nbsp;'
            . '<button onclick="luModal(\'1x1\')" style="' . self::BTN . '">1:1 square</button> '
            . '<button onclick="luModal(\'3x4\')" style="' . self::BTN . '">3:4 portrait</button>'
            . '</div>';

        $modal = $this->modalMock();

        return preg_match('#<body[^>]*>#i', $html)
            ? preg_replace('#(<body[^>]*>)#i', '$1' . $banner, $html, 1) . $modal
            : $banner . $html . $modal;
    }

    private const BTN = 'margin-left:6px;background:#6C5CE7;color:#fff;border:0;border-radius:3px;'
        . 'padding:3px 8px;font:600 11px system-ui,sans-serif;cursor:pointer';

    /**
     * Static render of the session interstitial, using the REAL production CSS.
     *
     * The interstitial IS built — this is a preview harness, not a mock of an
     * unbuilt feature. What is faithful here: the markup structure, every class
     * name, the 1:1 / 3:4 sizing rules, the disclosure label, the 44px close
     * target, and ESC / backdrop / button dismissal.
     *
     * What is simulated: the trigger. In production the modal fires only after
     * the second pageview, past a dwell threshold, when the visitor did not
     * arrive from a search engine — none of which can be demonstrated by
     * clicking a button. Frequency capping, measurement and video are likewise
     * runtime behaviours that need the wired endpoints.
     */
    private function modalMock(): string
    {
        $square   = $this->squareCreative('1:1', 1080, 1080);
        $portrait = $this->squareCreative('3:4', 1080, 1440);

        // Read the real setting so the preview's countdown always matches what
        // production would actually enforce.
        $closeDelayMs = (int) app(\App\Engines\Ads\Services\AdSettingsService::class)
            ->int(\App\Engines\Ads\Support\AdSettings::MODAL_CLOSE_DELAY_MS);

        // NOTE: no <style> block here on purpose. The modal CSS comes from the
        // production AdSlotInjector snippet already injected into the page, so
        // the preview cannot drift from what really renders. The markup below
        // mirrors, element for element, what AdTagAssetService builds at runtime.
        return <<<HTML

<div class="lu-ad-modal-backdrop" id="lu-modal-backdrop" role="dialog" aria-modal="true" tabindex="-1" aria-label="Sponsored message">
  <div class="lu-ad-modal">
    <span class="lu-ad-modal-label">Sponsored</span>
    <button class="lu-ad-modal-close" id="lu-modal-close" type="button" aria-disabled="true">
      <span class="lu-count" id="lu-count" aria-hidden="true">6</span>
      <svg class="lu-x" viewBox="0 0 19 19" aria-hidden="true" focusable="false">
        <path d="M4 4 L15 15 M15 4 L4 15"></path>
      </svg>
    </button>
    <div class="lu-ad-modal-media" id="lu-modal-media" data-ar="1:1">
      <a href="#" onclick="return false"><img id="lu-modal-img" src="{$square}" alt="Placeholder advertisement"></a>
    </div>
  </div>
</div>

<script>
(function(){
  var SQ = "{$square}", PT = "{$portrait}";
  var DELAY = {$closeDelayMs};

  var bd = document.getElementById('lu-modal-backdrop');
  var media = document.getElementById('lu-modal-media');
  var img = document.getElementById('lu-modal-img');
  var btn = document.getElementById('lu-modal-close');
  var count = document.getElementById('lu-count');
  var lastFocus = null, unlocked = false, timer = null;

  function unlock(){
    unlocked = true;
    btn.setAttribute('data-ready', '1');
    btn.removeAttribute('aria-disabled');
    btn.setAttribute('aria-label', 'Close advertisement');
    count.textContent = '';
  }

  function startCountdown(){
    unlocked = false;
    btn.removeAttribute('data-ready');
    btn.setAttribute('aria-disabled', 'true');
    if (timer) clearTimeout(timer);
    if (DELAY <= 0) { unlock(); return; }
    var secs = Math.ceil(DELAY / 1000);
    count.textContent = String(secs);
    btn.setAttribute('aria-label', 'Advertisement — closes in ' + secs + ' seconds');
    var startedAt = Date.now();
    (function tick(){
      var left = Math.max(0, Math.ceil((DELAY - (Date.now() - startedAt)) / 1000));
      if (left <= 0) { unlock(); return; }
      if (count.textContent !== String(left)) {
        count.textContent = String(left);
        btn.setAttribute('aria-label', 'Advertisement — closes in ' + left + ' seconds');
      }
      timer = setTimeout(tick, 200);
    })();
  }

  window.luModal = function(ratio){
    lastFocus = document.activeElement;
    var portrait = ratio === '3x4';
    media.setAttribute('data-ar', portrait ? '3:4' : '1:1');
    img.src = portrait ? PT : SQ;
    bd.classList.add('on');
    document.body.style.overflow = 'hidden';
    startCountdown();
    bd.focus();   /* the dialog, not the button — see the tag's buildModal() */
  };

  /* Every dismissal path is gated on the same countdown — gating only the
     button would leave ESC and the backdrop as ways around it. */
  function close(){
    if (!unlocked) return;
    if (timer) clearTimeout(timer);
    bd.classList.remove('on');
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  btn.addEventListener('click', close);
  bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && bd.classList.contains('on')) close();
  });

  /* Open it automatically, because that is what an interstitial DOES — waiting
     for someone to find a button is not a preview of the format. The delay
     stands in for the real dwell threshold. Ratio comes from ?ratio=3x4 so a
     link can point straight at either variant.
     In production the trigger is far stricter: second pageview or later, past
     the dwell, and never when the visitor arrived from a search engine. */
  var initial = (location.search.match(/ratio=(1x1|3x4)/) || [])[1] || '1x1';
  setTimeout(function(){ window.luModal(initial); }, 1200);
})();
</script>

HTML;
    }

    /** Self-contained placeholder at a given ratio — inline SVG, no external request. */
    private function squareCreative(string $label, int $w, int $h): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '">'
             . '<defs><linearGradient id="m" x1="0" y1="0" x2="1" y2="1">'
             . '<stop offset="0" stop-color="#6C5CE7"/><stop offset="1" stop-color="#00E5A8"/>'
             . '</linearGradient></defs>'
             . '<rect width="' . $w . '" height="' . $h . '" fill="url(#m)"/>'
             . '<text x="50%" y="46%" text-anchor="middle" font-family="Segoe UI,Helvetica,Arial,sans-serif" '
             . 'font-size="64" font-weight="700" fill="#fff">Your advertisement</text>'
             . '<text x="50%" y="54%" text-anchor="middle" font-family="Segoe UI,Helvetica,Arial,sans-serif" '
             . 'font-size="38" fill="rgba(255,255,255,.9)">' . $label . ' &#183; ' . $w . '&#215;' . $h . '</text>'
             . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** @param array<int,string> $slugs */
    private function indexPage(array $slugs): string
    {
        $links = '';
        foreach ($slugs as $slug) {
            $safe = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            $links .= "<li><a href=\"{$safe}.html\">{$safe}</a></li>";
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>ADS888 slot previews</title>'
            . '<style>body{font:15px/1.6 system-ui,sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#222}'
            . 'h1{font-size:20px}li{margin:6px 0}code{background:#f2f2f2;padding:1px 5px;border-radius:3px}'
            . '.note{background:#fff8e1;border-left:3px solid #f0b429;padding:10px 14px;margin:20px 0;font-size:14px}</style>'
            . '</head><body><h1>ADS888 — ad slot previews</h1>'
            . '<div class="note"><strong>What is real:</strong> the slot markup, CSS, position, '
            . 'z-index, chat-bubble clearance and disclosure label all come from the production '
            . '<code>AdSlotInjector</code>.<br><strong>What is not:</strong> the creative is a placeholder, '
            . 'and the fill is baked in — <code>/api/ads/decide</code> is not wired yet.</div>'
            . '<p>Templates rendered with their own sample content. No customer data.</p>'
            . "<ul>{$links}</ul>"
            . '<p style="color:#777;font-size:13px">Remove with <code>php artisan ads:preview --clean</code></p>'
            . '</body></html>';
    }

    private function origin(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';

        return str_contains($host, 'levelupgrowth.io')
            ? 'https://' . $host
            : 'https://staging.levelupgrowth.io';
    }
}
