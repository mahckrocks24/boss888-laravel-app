<?php

/**
 * CR-22B — extracted route module: studio-02
 *
 * Source: routes/api.php lines 10877-12311 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: STUDIO888   ·   Routes: 47   ·   Statements: 1
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // ── Studio Engine (2026-04-20) ─────────────────────────────
    // Social media image editor. Templates are global; designs are per-workspace.
// ═══════════════════════════════════════════════════════════════
// studio_routes.php — new /api/studio/* routes (HTML-template model)
// Patched in-place into routes/api.php replacing the legacy block.
// Pattern: iframe + postMessage (identical to /builder/websites/*/preview)
// ═══════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════
// studio_routes.php — new /api/studio/* routes (HTML-template model)
// Patched in-place into routes/api.php replacing the legacy block.
// Pattern: iframe + postMessage (identical to /builder/websites/*/preview)
// ═══════════════════════════════════════════════════════════════
    Route::prefix('studio')->group(function () {

        // GET /api/studio/templates/html — scan filesystem, list HTML templates
        Route::get('/templates/html', function () {
            $dir  = storage_path('templates/studio');
            $rows = [];
            if (is_dir($dir)) {
                foreach (scandir($dir) as $slug) {
                    if ($slug === '.' || $slug === '..') continue;
                    $tplPath = $dir . '/' . $slug . '/template.html';
                    $mfPath  = $dir . '/' . $slug . '/manifest.json';
                    if (!is_file($tplPath) || !is_file($mfPath)) continue;
                    $mf = json_decode(file_get_contents($mfPath), true) ?: [];
                    $rows[] = [
                        'slug'          => $slug,
                        'name'          => $mf['name'] ?? $slug,
                        'format'        => $mf['format'] ?? 'square',
                        'category'      => $mf['category'] ?? '',
                        'sub_category'  => $mf['sub_category'] ?? '',
                        'canvas_width'  => (int)($mf['canvas_width']  ?? 1080),
                        'canvas_height' => (int)($mf['canvas_height'] ?? 1080),
                        'industry_tags' => $mf['industry_tags'] ?? [],
                        'preview_url'   => '/storage/studio-previews/' . $slug . '.html?v=' . (@filemtime(storage_path('app/public/studio-previews/' . $slug . '.html')) ?: time()),
                        'thumbnail_url' => '/storage/studio-thumbs/' . $slug . '.png?v=' . (@filemtime(storage_path('app/public/studio-thumbs/' . $slug . '.png')) ?: time()),
                    ];
                }
            }
            return response()->json(['success' => true, 'templates' => $rows]);
        });

        // GET /api/studio/templates — LEGACY: DB-backed layers_json templates (kept for back-compat)
        Route::get('/templates', function (\Illuminate\Http\Request $r) {
            $q = \Illuminate\Support\Facades\DB::table('studio_templates')->where('is_active', 1);
            if ($r->filled('format'))   $q->where('format',   $r->input('format'));
            if ($r->filled('category')) $q->where('category', $r->input('category'));
            $perPage = (int) min(48, max(6, $r->input('per_page', 24)));
            $page    = (int) max(1, $r->input('page', 1));
            $total   = (clone $q)->count();
            $rows    = $q->orderByDesc('use_count')->orderByDesc('id')
                ->limit($perPage)->offset(($page - 1) * $perPage)
                ->get(['id','name','category','sub_category','industry_tags','demographic','format','canvas_width','canvas_height','thumbnail_url','use_count','layers_json']);
            foreach ($rows as $row) {
                $row->industry_tags = json_decode($row->industry_tags ?? '[]', true);
                $row->layers_json   = json_decode($row->layers_json   ?? '{}', true);
            }
            return response()->json(['success' => true, 'templates' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
        });

        // GET /api/studio/templates/{id}  — legacy
        Route::get('/templates/{id}', function ($id) {
            $row = \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', (int)$id)->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $row->industry_tags = json_decode($row->industry_tags ?? '[]', true);
            $row->layers_json   = json_decode($row->layers_json   ?? '{}', true);
            return response()->json(['success' => true, 'template' => $row]);
        });

        // GET /api/studio/designs  — workspace-scoped saved designs
        Route::get('/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('workspace_id', $wsId)->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->get(['id','name','format','canvas_width','canvas_height','thumbnail_url','exported_url','status','created_at','updated_at']);
            return response()->json(['success' => true, 'designs' => $rows]);
        });

        // GET /api/studio/designs/{id}  — full single design (content_html + layers_json)
        Route::get('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $row->layers_json = json_decode($row->layers_json ?? '{}', true);
            return response()->json(['success' => true, 'design' => $row]);
        });

        // POST /api/studio/designs  — create from HTML template slug OR legacy template_id
        Route::post('/designs', function (\Illuminate\Http\Request $r) {
            $wsId  = (int) $r->attributes->get('workspace_id');
            $slug  = $r->input('template_slug');
            $tplId = $r->input('template_id');
            $name  = trim((string) $r->input('name', 'Untitled Design'));

            $format = 'square'; $cw = 1080; $ch = 1080;
            $contentHtml = null;
            $layersJson  = '{}';

            if ($slug) {
                // HTML-template path (new)
                $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
                if (!$slug) return response()->json(['success' => false, 'error' => 'invalid_slug'], 422);
                // Image templates live in templates/studio/{slug}/
                // Video (html_animated) templates live in templates/studio/video/{slug}/
                // Try image dir first, fall back to video dir.
                $isVideo = false;
                $dir = storage_path('templates/studio/' . $slug);
                $tpl = $dir . '/template.html';
                $mfp = $dir . '/manifest.json';
                if (!is_file($tpl) || !is_file($mfp)) {
                    $dirV = storage_path('templates/studio/video/' . $slug);
                    if (is_file($dirV . '/template.html') && is_file($dirV . '/manifest.json')) {
                        $dir = $dirV;
                        $tpl = $dirV . '/template.html';
                        $mfp = $dirV . '/manifest.json';
                        $isVideo = true;
                    } else {
                        return response()->json(['success' => false, 'error' => 'template_not_found'], 404);
                    }
                }
                $mf = json_decode(file_get_contents($mfp), true) ?: [];
                $format = $mf['format'] ?? 'square';
                $cw     = (int)($mf['canvas_width']  ?? 1080);
                $ch     = (int)($mf['canvas_height'] ?? 1080);
                $html   = file_get_contents($tpl);

                // Substitute manifest defaults into {{var}} tokens
                $vars = [];
                foreach (($mf['variables'] ?? []) as $k => $v)     $vars[$k] = $v['default'] ?? '';
                foreach (($mf['css_variables'] ?? []) as $k => $v) $vars[$k] = $v['default'] ?? '';
                foreach ($vars as $k => $v) { $html = str_replace('{{' . $k . '}}', (string)$v, $html); }
                $contentHtml = $html;
                $layersJson  = json_encode(['template_slug' => $slug, 'source' => $isVideo ? 'html_animated' : 'html']);
                if ($isVideo) {
                    $designType = 'video';
                    $duration   = (int)($mf['duration_seconds'] ?? 15);
                }
                /* video-template-fallback */
            } elseif ($tplId) {
                // Legacy DB-template path (layers_json)
                $tpl = \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', (int)$tplId)->first();
                if (!$tpl) return response()->json(['success' => false, 'error' => 'template_not_found'], 404);
                $layersJson = $tpl->layers_json;
                $cw = $tpl->canvas_width; $ch = $tpl->canvas_height; $format = $tpl->format;
                \Illuminate\Support\Facades\DB::table('studio_templates')->where('id', $tpl->id)->increment('use_count');
            } else {
                return response()->json(['success' => false, 'error' => 'template_required'], 422);
            }

            $insertData = [
                'workspace_id'  => $wsId,
                'template_id'   => $tplId ? (int)$tplId : null,
                'name'          => mb_substr($name, 0, 120),
                'format'        => $format,
                'canvas_width'  => $cw,
                'canvas_height' => $ch,
                'layers_json'   => $layersJson,
                'content_html'  => $contentHtml,
                'status'        => 'draft',
                'created_at'    => now(), 'updated_at' => now(),
            ];
            if (!empty($designType)) $insertData['design_type'] = $designType;
            if (!empty($duration))   $insertData['duration_seconds'] = $duration;
            $id = \Illuminate\Support\Facades\DB::table('studio_designs')->insertGetId($insertData);
            // Auto-generate thumbnail (sync; ~2-3s puppeteer render). The
            // gallery's "My Designs" section pulls thumbnail_url, so freshly
            // created designs need a thumb immediately.
            /* auto-thumb-on-create */
            try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail($id); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('thumb gen on create failed: '.$e->getMessage()); }
            return response()->json(['success' => true, 'design_id' => $id], 201);
        });

        // PUT /api/studio/designs/{id}  — save content_html / layers / rename
        Route::put('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            $update = ['updated_at' => now()];
            if ($r->filled('name'))          $update['name']         = mb_substr((string)$r->input('name'), 0, 120);
            if ($r->has('content_html'))     $update['content_html'] = (string)$r->input('content_html');
            if ($r->filled('layers_json')) {
                $lj = $r->input('layers_json');
                $update['layers_json'] = is_string($lj) ? $lj : json_encode($lj);
            }
            if ($r->filled('thumbnail_url')) $update['thumbnail_url'] = (string)$r->input('thumbnail_url');
            // 2026-07-03 (#4) — persist background + canvas-size edits from the
            // element editor (were silently dropped by this PUT → lost on reload).
            foreach (['background_type', 'background_value'] as $c) {
                if ($r->filled($c)) $update[$c] = (string) $r->input($c);
            }
            foreach (['canvas_width', 'canvas_height'] as $c) {
                if ($r->filled($c)) $update[$c] = (int) $r->input($c);
            }
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update($update);
            // Fire-and-forget async thumbnail regen — save returns instantly,
            // thumb refreshes within a few seconds via the registered shutdown
            // function. Using register_shutdown_function so the queue worker
            // doesn't need to be running (acceptable for low-volume use).
            /* auto-thumb-on-save */
            register_shutdown_function(function() use ($id) {
                try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int)$id); }
                catch (\Throwable $e) {}
            });
            return response()->json(['success' => true]);
        });

        // GET /api/studio/designs/{id}/preview — returns content_html with editor script injected
        Route::get('/designs/{id}/preview', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row  = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response('Not found', 404);
            // BUG-001 fix: a design's content_html may be Arthur JSON
            // ({template_slug, fields}) rather than rendered HTML. Resolve it to
            // real template HTML via StudioService::renderHtml() so the editor
            // canvas shows the DESIGN, never raw JSON. renderHtml passes existing
            // HTML through unchanged and never returns raw JSON (unknown → shell).
            $html = (string) (app(\App\Engines\Studio\Services\StudioService::class)->renderHtml($row) ?? '');
            if ($html === '') {
                return response('<!doctype html><html><body style="background:#111;color:#fff;font-family:system-ui;padding:40px">Design has no content.</body></html>')
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            }

            $editScript = <<<'HTMLSCRIPT'
<script>
(function(){
  if (window._luStudioEditor) return;
  window._luStudioEditor = true;

  // Export-parity fix (2026-04-21): ensure every <img> carries
  // crossorigin="anonymous" so html2canvas can capture it without
  // tainting the canvas. Covers designs created before the fix
  // landed in the template source. We re-assign src after setting
  // crossOrigin so the image is re-fetched in CORS mode.
  //
  // Stability fix (2026-08-02, BUG-D): the observer used to watch the whole
  // subtree for 'src' ATTRIBUTE mutations and re-scan every <img>, re-assigning
  // img.src — which is itself a 'src' mutation that re-fired the observer. Under
  // editor interaction (selection overlays, contenteditable, drag/resize) the DOM
  // mutates continuously, so this thrashed the CPU and froze the editor. We now
  // (1) tag each image once (crossOrigin guard), (2) observe childList/subtree
  // ONLY — no attribute observation, so our own src re-assign never re-triggers
  // us, (3) scan only newly-added subtrees (never the whole document per
  // mutation), and (4) coalesce bursts into one pass per animation frame.
  function _coTagImage(img) {
    if (!img || img.tagName !== 'IMG' || img.crossOrigin) return;
    var src = img.src || img.getAttribute('src') || '';
    try { img.crossOrigin = 'anonymous'; } catch(_e) {}
    // Re-assign once, only to force the CORS re-fetch now that crossOrigin is set.
    // The crossOrigin guard above means this runs at most once per image, and we
    // skip it when the value would be unchanged/empty.
    if (src && img.getAttribute('src') !== src) img.setAttribute('src', src);
    else if (src) img.src = src;
  }
  function _coScan(root) {
    if (!root) return;
    _coTagImage(root);
    if (root.querySelectorAll) {
      var imgs = root.querySelectorAll('img');
      for (var i = 0; i < imgs.length; i++) _coTagImage(imgs[i]);
    }
  }
  // Kept for back-compat: a one-shot full-document pass.
  function _ensureCrossOrigin() { _coScan(document); }
  _ensureCrossOrigin();
  // Queue newly-added element subtrees and process them once per frame. Media
  // Picker swaps that replace an <img> node land here; swaps that only change an
  // existing image's src keep the crossOrigin already set on that element.
  var _coQueue = [], _coScheduled = false;
  var _coRaf = window.requestAnimationFrame || function(f){ return setTimeout(f, 16); };
  function _coFlush() {
    _coScheduled = false;
    var q = _coQueue; _coQueue = [];
    for (var i = 0; i < q.length; i++) _coScan(q[i]);
  }
  new MutationObserver(function(muts){
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        if (added[j].nodeType === 1) _coQueue.push(added[j]);
      }
    }
    if (_coQueue.length && !_coScheduled) { _coScheduled = true; _coRaf(_coFlush); }
  }).observe(document.documentElement, { childList: true, subtree: true });

  function _fields() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-field]'));
  }
  function _fieldKind(el) {
    if (el.tagName === 'IMG') return 'image';
    if (el.querySelector && el.querySelector('img')) return 'image';
    return 'text';
  }
  function _send(msg) {
    try { window.parent.postMessage(msg, '*'); } catch(_e){}
  }
  function _emitList() {
    var out = _fields().map(function(el){
      var name = el.getAttribute('data-field');
      var kind = _fieldKind(el);
      var value = '', src = '';
      if (kind === 'image') {
        var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
        if (img) src = img.currentSrc || img.src || img.getAttribute('src') || '';
      } else {
        value = (el.innerText || el.textContent || '').trim();
      }
      return { name: name, kind: kind, value: value, src: src };
    });
    _send({ type: 'fields-list', fields: out });
  }
  function _serializeHtml() {
    try {
      // Ensure body reflects any live contenteditable state.
      document.querySelectorAll('[contenteditable="true"]').forEach(function(el){
        el.removeAttribute('contenteditable');
      });
      var html = '<!DOCTYPE html>\n' + document.documentElement.outerHTML;
      _send({ type: 'html-serialized', html: html });
    } catch(e) {
      _send({ type: 'html-serialized', html: '', error: String(e) });
    }
  }
  function _focusField(name) {
    var el = document.querySelector('[data-field="' + name + '"]');
    if (!el) return;
    el.scrollIntoView({ behavior:'smooth', block:'center' });
    if (_fieldKind(el) === 'text') {
      _enterEdit(el);
    }
  }
  function _hexToHsl(c){
    c = String(c||'').trim();
    if (!c) return null;
    var r,g,b,a=1;
    if (c.charAt(0)==='#') {
      if (c.length===4){r=parseInt(c[1]+c[1],16);g=parseInt(c[2]+c[2],16);b=parseInt(c[3]+c[3],16);}
      else if (c.length===7){r=parseInt(c.slice(1,3),16);g=parseInt(c.slice(3,5),16);b=parseInt(c.slice(5,7),16);}
      else return null;
    } else {
      var m = c.match(/rgba?\(([^)]+)\)/i);
      if (!m) return null;
      var p = m[1].split(',').map(function(x){return parseFloat(x.trim());});
      r=p[0]||0;g=p[1]||0;b=p[2]||0;a=(p[3]==null?1:p[3]);
    }
    var rr=r/255,gg=g/255,bb=b/255;
    var mx=Math.max(rr,gg,bb),mn=Math.min(rr,gg,bb);
    var h=0,s=0,l=(mx+mn)/2;
    if (mx!==mn){
      var d=mx-mn;
      s = l>0.5 ? d/(2-mx-mn) : d/(mx+mn);
      if (mx===rr) h=(gg-bb)/d+(gg<bb?6:0);
      else if (mx===gg) h=(bb-rr)/d+2;
      else h=(rr-gg)/d+4;
      h*=60;
    }
    return {h:h,s:s,l:l,a:a};
  }
  function _readRootVars() {
    var out = {};
    var sheets = document.styleSheets;
    for (var i=0;i<sheets.length;i++){
      try {
        var rules = sheets[i].cssRules || sheets[i].rules || [];
        for (var j=0;j<rules.length;j++){
          var r = rules[j];
          if (!r || !r.selectorText) continue;
          if (r.selectorText.split(',').map(function(x){return x.trim();}).indexOf(':root')===-1) continue;
          var st = r.style;
          for (var k=0;k<st.length;k++){
            var p = st[k];
            if (p && p.indexOf('--')===0) out[p] = st.getPropertyValue(p).trim();
          }
        }
      } catch(_e) {}
    }
    return out;
  }
  function _applyPalette(vars) {
    var root = document.documentElement;
    if (!vars || typeof vars !== 'object') return;
    // 1. Always set the palette's literal names (works for any template that
    //    happens to use --primary / --bg / --text / --accent directly).
    Object.keys(vars).forEach(function(k){
      if (!k) return;
      var prop = (k.charAt(0) === '-') ? k : ('--' + k);
      root.style.setProperty(prop, String(vars[k]));
    });
    // 2. Smart-map: read the template's own :root vars and remap them by
    //    name + brightness/saturation so 'text', 'primary', 'accent' actually
    //    take effect on templates that use --ink / --coral / --champ etc.
    var pBg      = vars['--bg']      || vars['bg'];
    var pText    = vars['--text']    || vars['text'];
    var pPrimary = vars['--primary'] || vars['primary'];
    var pAccent  = vars['--accent']  || vars['accent'];
    var rootVars = _readRootVars();
    var names = Object.keys(rootVars);
    var colorVars = [];
    names.forEach(function(n){
      var v = rootVars[n];
      var hsl = _hexToHsl(v);
      if (!hsl) return;
      colorVars.push({name:n, val:v, hsl:hsl, low:n.toLowerCase()});
    });
    // Name-based mapping first (covers --bg, --ink, --primary, --accent etc.)
    var consumed = {};
    colorVars.forEach(function(cv){
      var nm = cv.low;
      if (pBg && /^--(bg|background|backdrop|surface)$/.test(nm)) {
        root.style.setProperty(cv.name, pBg); consumed[cv.name] = 1;
      } else if (pText && /^--(text|ink|fg|foreground|dark|body)$/.test(nm)) {
        root.style.setProperty(cv.name, pText); consumed[cv.name] = 1;
      } else if (pPrimary && /^--(primary|main|brand|hero|accent-1)$/.test(nm)) {
        root.style.setProperty(cv.name, pPrimary); consumed[cv.name] = 1;
      } else if (pAccent && /^--(accent|accent-2|secondary|highlight|hl)$/.test(nm)) {
        root.style.setProperty(cv.name, pAccent); consumed[cv.name] = 1;
      }
    });
    // Heuristic mapping for remaining color vars by HSL classification
    var remaining = colorVars.filter(function(c){ return !consumed[c.name]; });
    // muted / cream / white / sand / off-white → leave alone (decorative neutrals)
    var chromatic = remaining.filter(function(c){
      // Skip near-grey and pure light/dark neutrals
      if (c.hsl.s < 0.18) return false;
      // Skip vars that are clearly muted/alpha-baked utilities
      if (/^--(muted|shadow|overlay|ring|stroke|border|line|divider)/.test(c.low)) return false;
      return true;
    });
    // Sort by saturation descending — most vivid first
    chromatic.sort(function(a,b){ return b.hsl.s - a.hsl.s; });
    // Assign palette primary to most-saturated, accent to second-most
    var assigned = 0;
    chromatic.forEach(function(cv){
      if (assigned === 0 && pPrimary) { root.style.setProperty(cv.name, pPrimary); assigned++; }
      else if (assigned === 1 && pAccent) { root.style.setProperty(cv.name, pAccent); assigned++; }
    });
    // Also catch text-like vars by lightness (very dark non-bg)
    if (pText) {
      remaining.forEach(function(cv){
        if (consumed[cv.name]) return;
        // dark color that's NOT the bg → likely a text/ink var
        if (cv.hsl.l < 0.28 && !/^--(bg|background)/.test(cv.low)) {
          root.style.setProperty(cv.name, pText);
        }
      });
    }
  }
  function _updateImage(field, url) {
    var el = document.querySelector('[data-field="' + field + '"]');
    if (!el) return;
    var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
    if (img) img.src = url;
  }
  function _updateFieldText(field, value) {
    var el = document.querySelector('[data-field="' + field + '"]');
    if (!el) return;
    if (el.tagName === 'IMG') return; // text handler — skip image elements
    // If element has an <img> child, it's likely image wrapper; skip.
    if (el.querySelector && el.querySelector('img')) return;
    el.textContent = String(value == null ? '' : value);
  }

  // ── Hover / selection outlines ─────────────────────────────
  var _host = document.body;
  _host.classList.add('lu-studio-edit');

  // Wire up per-field listeners
  var _editing = null;
  function _enterEdit(target) {
    if (!target || _fieldKind(target) !== 'text') return;
    if (_editing === target) return;
    target.setAttribute('contenteditable', 'true');
    target.setAttribute('spellcheck', 'false');
    target.focus();
    _editing = target;
    try {
      var range = document.createRange();
      range.selectNodeContents(target);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    } catch(_e){}
  }
  function _exitEdit() {
    if (!_editing) return;
    var t = _editing;
    t.removeAttribute('contenteditable');
    _editing = null;
    _send({ type: 'field-changed', field: t.getAttribute('data-field'), value: t.innerHTML });
  }

  document.addEventListener('click', function(e){
    var el = e.target.closest && e.target.closest('[data-field]');
    if (!el) return;
    var kind = _fieldKind(el);
    if (kind === 'image') {
      e.preventDefault();
      e.stopPropagation();
      var img = (el.tagName === 'IMG') ? el : el.querySelector('img');
      var src = img ? (img.currentSrc || img.src || '') : '';
      _send({ type: 'image-clicked', field: el.getAttribute('data-field'), currentSrc: src });
      return;
    }
    // Single click on text doesn't enter edit mode — dblclick does (matches builder.js behavior).
  });

  document.addEventListener('dblclick', function(e){
    var el = e.target.closest && e.target.closest('[data-field]');
    if (!el) return;
    if (_fieldKind(el) === 'image') return;
    e.preventDefault();
    _enterEdit(el);
  });

  document.addEventListener('blur', function(e){
    if (_editing && e.target === _editing) _exitEdit();
  }, true);

  document.addEventListener('keydown', function(e){
    if (_editing && (e.key === 'Escape' || (e.key === 'Enter' && !e.shiftKey))) {
      e.preventDefault();
      _editing.blur();
    }
  });

  // Parent → iframe messages
  window.addEventListener('message', function(e){
    var d = e.data;
    if (!d || typeof d !== 'object' || !d.type) return;
    if (d.type === 'list-fields')     _emitList();
    else if (d.type === 'serialize-html') _serializeHtml();
    else if (d.type === 'focus-field')    _focusField(d.field);
    else if (d.type === 'apply-palette')  _applyPalette(d.vars || {});
    else if (d.type === 'lu-update-image') _updateImage(d.field, d.url || '');
    else if (d.type === 'update-field-text') _updateFieldText(d.field, d.value);
  });

  // ── Forward wheel events to parent workspace so pinch/zoom/pan works
  //    even when the cursor is over the iframe. Iframe clientX/Y are
  //    local to the iframe viewport; parent converts to workspace coords.
  document.addEventListener('wheel', function(e){
    // Ctrl/Meta+wheel = pinch-zoom on trackpads. preventDefault so the
    // browser doesn't apply its own page-level zoom on top of ours.
    if (e.ctrlKey || e.metaKey) e.preventDefault();
    _send({
      type: 'studio-wheel',
      deltaX: e.deltaX,
      deltaY: e.deltaY,
      ctrlKey: !!e.ctrlKey,
      metaKey: !!e.metaKey,
      clientX: e.clientX,
      clientY: e.clientY
    });
  }, { passive: false });

  // Initial list after a tick (fonts / images may still be loading but DOM is live).
  setTimeout(_emitList, 80);
})();
</script>
HTMLSCRIPT;

            // Inject viewport-fit CSS into <head> so the source HTML's
            // @media(max-width:1160px){.sw{transform:scale(.46)}} (which fires
            // at the iframe viewport width) doesn't shrink the design.
            // The freeze on .scene padding + .sw transform makes .post/.reel
            // fill the iframe at native canvas dims.
            $viewportCss = '<style>'
                . '.scene{padding:0!important;min-height:0!important;background:transparent!important}'
                . '.sw{transform:none!important;display:block!important;width:auto!important;height:auto!important;transform-origin:0 0!important}'
                . '.scale-wrap{transform:none!important;display:block!important;width:auto!important;height:auto!important;transform-origin:0 0!important}'
                . 'html,body{background:transparent!important}'
                . '*,*::before,*::after{animation-delay:-99s!important;animation-duration:0.001s!important;animation-iteration-count:1!important;animation-fill-mode:forwards!important;transition:none!important}'
                . '</style>';
            if (stripos($html, '</head>') !== false) {
                $html = preg_replace('#</head>#i', $viewportCss . '</head>', $html, 1);
            } else {
                $html = $viewportCss . $html;
            }
            // Inject script before </body> if possible, otherwise append.
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $editScript . '</body>', $html, 1);
            } else {
                $html .= $editScript;
            }
            /* viewport-fit-css-preview */

            return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Frame-Options', 'SAMEORIGIN')
                ->header('Cache-Control', 'no-store');
        });

        // POST /api/studio/designs/{id}/export  — body: {image_data: base64} (client renders via html2canvas)
        Route::post('/designs/{id}/export', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            $imageData = (string) $r->input('image_data', '');
            $format    = strtolower((string) $r->input('format', 'png'));
            if (!in_array($format, ['png','jpg','jpeg'], true)) $format = 'png';
            if (!str_starts_with($imageData, 'data:image/')) {
                return response()->json(['success' => false, 'error' => 'invalid_image_data'], 422);
            }
            $bin = base64_decode(preg_replace('#^data:image/[^;]+;base64,#', '', $imageData));
            if ($bin === false || strlen($bin) < 100) {
                return response()->json(['success' => false, 'error' => 'decode_failed'], 422);
            }
            $dir = storage_path('app/public/studio/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = 'design-' . (int)$id . '-' . date('Ymd-His') . '.' . ($format === 'jpg' ? 'jpg' : $format);
            $abs  = $dir . '/' . $name;
            if (file_put_contents($abs, $bin) === false) {
                return response()->json(['success' => false, 'error' => 'write_failed'], 500);
            }
            $url = '/storage/studio/' . $wsId . '/' . $name;

            // Best-effort media entry.
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId,
                    'url'          => $url,
                    'mime_type'    => 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
                    'source'       => 'studio_export',
                    'is_platform_asset' => 0,
                    'created_at'   => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}

            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update([
                'exported_url' => $url,
                'status'       => 'exported',
                'updated_at'   => now(),
            ]);

            return response()->json(['success' => true, 'url' => $url]);
        });

        // DELETE /api/studio/designs/{id}  — soft delete
        Route::delete('/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update(['deleted_at' => now()]);
            return response()->json(['success' => true]);
        });

        // POST /api/studio/designs/{id}/render-png  — server-side export
        //
        // Body: { content_html?: string, width?: int, height?: int }
        //
        // Spawns headless Chrome via `tools/studio-render.cjs` to capture the
        // design HTML at exact template dimensions. Falls back to the saved
        // content_html when the client doesn't send one. Returns a raw PNG
        // stream (Content-Type: image/png). Replaces the client-side html2canvas
        // path — puppeteer preserves object-fit, @font-face and CSS grid
        // correctly where html2canvas cannot.
        Route::post('/designs/{id}/render-png', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', (int)$id)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            // Prefer the request-body HTML (live editor state); fall back to saved.
            $html = (string) $r->input('content_html', $row->content_html ?? '');
            if ($html === '') return response()->json(['success' => false, 'error' => 'empty_design'], 422);
            if (strlen($html) > 5 * 1024 * 1024) {
                return response()->json(['success' => false, 'error' => 'html_too_large'], 413);
            }

            $w = (int) ($r->input('width')  ?: $row->canvas_width  ?: 1080);
            $h = (int) ($r->input('height') ?: $row->canvas_height ?: 1080);
            $w = max(320, min(2400, $w));
            $h = max(320, min(2400, $h));

            // Scratch dir for the node child process — outside public/.
            $tmpDir = storage_path('app/studio-render-tmp');
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

            // Inject a <base> tag so relative /storage/... URLs in content_html
            // resolve against the public host when puppeteer loads the page.
            // (In the editor iframe, srcdoc inherits the parent's base URL —
            // when we write the HTML to disk for puppeteer, that context is
            // lost, so imgs with src="/storage/..." would 404 silently.)
            $baseHref = rtrim((string) config('app.url') ?: 'https://staging.levelupgrowth.io', '/') . '/';
            $baseTag  = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES) . '">';
            if (!preg_match('/<base\s/i', $html)) {
                if (preg_match('/<head[^>]*>/i', $html)) {
                    $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $baseTag, $html, 1);
                } else {
                    $html = $baseTag . $html;
                }
            }

            $stamp    = bin2hex(random_bytes(6));
            $tmpHtml  = $tmpDir . '/' . $stamp . '.html';
            $tmpPng   = $tmpDir . '/' . $stamp . '.png';
            if (file_put_contents($tmpHtml, $html) === false) {
                return response()->json(['success' => false, 'error' => 'write_failed'], 500);
            }

            $script = base_path('tools/studio-render.cjs');
            if (!is_file($script)) {
                @unlink($tmpHtml);
                return response()->json(['success' => false, 'error' => 'renderer_not_installed'], 500);
            }

            $cmd = 'node ' . escapeshellarg($script) . ' '
                 . escapeshellarg($tmpHtml) . ' '
                 . (int)$w . ' ' . (int)$h . ' '
                 . escapeshellarg($tmpPng) . ' 2>&1';

            // Explicit env for the node child: PHP-FPM's proc_open otherwise
            // drops PUPPETEER_CACHE_DIR and HOME, leaving puppeteer unable to
            // locate its bundled Chromium. Pass the minimum needed.
            $childEnv = [
                'HOME'                 => '/tmp',
                'PATH'                 => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PUPPETEER_CACHE_DIR'  => base_path('.puppeteer-cache'),
                'LANG'                 => 'C.UTF-8',
                'LC_ALL'               => 'C.UTF-8',
            ];

            // 60s hard cap — headless Chrome cold-start averages ~2–4s,
            // so 60s leaves generous headroom even on a loaded host.
            $descriptorspec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $start = microtime(true);
            $proc = proc_open($cmd, $descriptorspec, $pipes, null, $childEnv);
            if (!is_resource($proc)) {
                @unlink($tmpHtml);
                return response()->json(['success' => false, 'error' => 'spawn_failed'], 500);
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $status = proc_close($proc);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            @unlink($tmpHtml);

            if ($status !== 0 || !is_file($tmpPng) || filesize($tmpPng) < 200) {
                @unlink($tmpPng);
                \Illuminate\Support\Facades\Log::error('studio.render-png failed', [
                    'design_id' => (int)$id,
                    'status'    => $status,
                    'stdout'    => mb_substr((string)$stdout, 0, 1000),
                    'stderr'    => mb_substr((string)$stderr, 0, 1000),
                    'elapsed_ms'=> $elapsed,
                ]);
                return response()->json([
                    'success' => false,
                    'error'   => 'render_failed',
                    'detail'  => mb_substr((string)$stderr ?: (string)$stdout, 0, 600),
                ], 500);
            }

            $bin = file_get_contents($tmpPng);
            @unlink($tmpPng);

            // P6 (2026-07-28): when save:true, persist the rendered PNG to the
            // public media path, record exported_url on the design, register a
            // media-library row (via saveExportToMedia), and return JSON. This
            // is the "Save to Media Library" path — no client image upload.
            // Default (no save flag) still streams the PNG for Download PNG.
            if ($r->boolean('save')) {
                $pubDir = storage_path('app/public/studio/' . $wsId);
                if (!is_dir($pubDir)) @mkdir($pubDir, 0775, true);
                $pngName = 'design-' . (int)$id . '-' . date('Ymd-His') . '.png';
                if (file_put_contents($pubDir . '/' . $pngName, $bin) === false) {
                    return response()->json(['success' => false, 'error' => 'persist_failed'], 500);
                }
                $url = '/storage/studio/' . $wsId . '/' . $pngName;
                try {
                    \Illuminate\Support\Facades\DB::table('studio_designs')->where('id', (int)$id)->update([
                        'exported_url' => $url, 'status' => 'exported', 'updated_at' => now(),
                    ]);
                } catch (\Throwable $_e) {}
                $media = null;
                try { $media = app(\App\Engines\Studio\Services\StudioService::class)->saveExportToMedia((int)$id, $wsId); } catch (\Throwable $_e) {}
                return response()->json(['success' => true, 'url' => $url, 'media' => $media]);
            }

            $fname = preg_replace('/[^a-z0-9-]+/i', '-', $row->name ?: 'design') . '.png';

            return response($bin, 200, [
                'Content-Type'        => 'image/png',
                'Content-Length'      => (string) strlen($bin),
                'Content-Disposition' => 'attachment; filename="' . $fname . '"',
                'Cache-Control'       => 'no-store, max-age=0',
                'X-Studio-Render'     => 'puppeteer',
                'X-Studio-Render-Ms'  => (string) $elapsed,
            ]);
        });

        // POST /api/studio/chat — AI chat tied to a design
        // Returns { success, reply, actions:[{type:'apply_palette'|'update_field'|'update_image', ...}] }
        // Hands-vs-brain: Laravel persists + validates; runtime (DeepSeek) generates.
        Route::post('/chat', function (\Illuminate\Http\Request $r) {
            $wsId     = (int) $r->attributes->get('workspace_id');
            $designId = (int) $r->input('design_id');
            $message  = trim((string) $r->input('message', ''));
            $history  = $r->input('history', []);
            if ($designId <= 0 || $message === '') {
                // P2-A: structured error per CHAT-CONTRACT-v1 §8 (clause X-01..X-05).
                // Legacy 'error' string retained for unmigrated clients (S-03).
                return response()->json([
                    'success' => false,
                    'error'   => 'missing_input',
                    'chat_error' => [
                        'code'            => 'CHAT_VALIDATION_FAILED',
                        'message'         => 'Pick a design and type a message before sending.',
                        'retryable'       => false,
                        'provider_called' => false,
                        'persistence'     => ['user_message_saved' => false, 'assistant_message_saved' => false],
                        'action'          => null,
                    ],
                ], 422);
            }
            $design = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
            if (!$design) return response()->json(['success' => false, 'error' => 'not_found'], 404);

            // Extract current text fields + CSS variables from content_html so the
            // AI has context about what it can edit.
            $html = (string)($design->content_html ?? '');
            $fields = [];
            $vars   = [];
            if ($html !== '') {
                if (preg_match_all('/data-field="([^"]+)"[^>]*>([^<]*)</', $html, $m)) {
                    foreach ($m[1] as $i => $name) {
                        $val = trim($m[2][$i]);
                        if ($val !== '' && !isset($fields[$name])) $fields[$name] = $val;
                    }
                }
                if (preg_match('/:root\s*\{([^}]+)\}/', $html, $vm)) {
                    if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $vm[1], $vm2)) {
                        foreach ($vm2[1] as $i => $k) $vars[$k] = trim($vm2[2][$i]);
                    }
                }
            }

            // Build a combined user prompt (runtime's task types have fixed system
            // prompts that can't be overridden — fold the instructions into user).
            $instructions = "You are a design AI for the Studio image editor. The user "
              . "is editing a social-media visual. Respond with JSON only — no prose, no "
              . "markdown fences.\n\n"
              . "CURRENT TEXT FIELDS (name => value): " . json_encode($fields, JSON_UNESCAPED_SLASHES) . "\n"
              . "CURRENT CSS COLOR VARIABLES: " . json_encode($vars, JSON_UNESCAPED_SLASHES) . "\n\n"
              . "Valid action types:\n"
              . "  {\"type\":\"update_field\",\"name\":\"<field_name>\",\"value\":\"<new text>\"}\n"
              . "  {\"type\":\"apply_palette\",\"vars\":{\"--primary\":\"#...\",\"--bg\":\"#...\",\"--text\":\"#...\",\"--accent\":\"#...\"}}\n"
              . "  {\"type\":\"update_image\",\"name\":\"<field_name>\",\"url\":\"<absolute url>\"}\n\n"
              . "Return JSON shape: {\"reply\":\"one brief friendly sentence\",\"actions\":[...]}\n"
              . "Only include fields that actually exist above. Only palette keys that exist above.\n\n"
              . "Conversation history (most recent last):\n" . json_encode($history, JSON_UNESCAPED_SLASHES) . "\n\n"
              . "USER MESSAGE: " . $message;

            $reply = '';
            $actions = [];
            $ok = false;
            // PATCH 4 (2026-05-08): route through RuntimeClient (was direct
            // DeepSeekConnector::chatJson — hands-vs-brain bypass).
            try {
                $runtime = app(\App\Connectors\RuntimeClient::class);
                if ($runtime->isConfigured()) {
                    $systemPrompt = 'You are a design AI for the Studio image editor. Return ONLY a single JSON object: '
                                  . '{"reply":"one friendly sentence","actions":[...]}. '
                                  . 'Valid action types: '
                                  . '{"type":"update_field","name":"<existing field>","value":"<new text>"}, '
                                  . '{"type":"apply_palette","vars":{"--primary":"#...","--bg":"#...","--text":"#...","--accent":"#..."}}, '
                                  . '{"type":"update_image","name":"<existing field>","url":"<absolute url>"}. '
                                  . 'Only reference fields that exist in the provided context. No markdown fences, no prose outside the JSON.';
                    $resp = $runtime->chatJson($systemPrompt, $instructions, [], 600);
                    if (!empty($resp['success'])) {
                        $parsed = $resp['parsed'] ?? null;
                        if (is_array($parsed)) {
                            $reply   = (string)($parsed['reply'] ?? '');
                            $actions = is_array($parsed['actions'] ?? null) ? $parsed['actions'] : [];
                            $ok = ($reply !== '' || !empty($actions));
                        } else {
                            $content = (string)($resp['content'] ?? '');
                            if ($content !== '') { $reply = $content; $ok = true; }
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning('studio.chat runtime call failed', ['error' => $resp['error'] ?? null]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('studio.chat runtime exception: ' . $e->getMessage());
            }

            // ── CR-03 FIX (P2-A, 2026-07-26; RESTORED after INC-2026-003) ────
            // This block used to invent an answer whenever the runtime failed:
            // "luxury" and "brand" applied hard-coded palettes, and "color"
            // picked one with array_rand() and reported it as a considered
            // design decision — all returned with success:true. A customer had
            // no way to tell a canned guess from the AI.
            //
            // CHAT-CONTRACT-v1 clause F-06 prohibits it outright: a surface that
            // cannot reach the model says so. Saying "I couldn't do that" is
            // recoverable; silently recolouring someone's design is not.
            if (!$ok) {
                $_msg = 'The design AI is unavailable right now, so nothing was changed. Please try again shortly.';
                return response()->json([
                    'success' => false,
                    'error'   => $_msg,
                    'reply'   => null,
                    'actions' => [],
                    'chat_error' => [
                        'code'            => 'CHAT_PROVIDER_UNAVAILABLE',
                        'message'         => $_msg,
                        'retryable'       => true,
                        'provider_called' => true,
                        'persistence'     => ['user_message_saved' => false, 'assistant_message_saved' => false],
                        'action'          => ['label' => 'Try again', 'href' => null],
                    ],
                ], 503);
            }

            // Credits: best-effort deduction if the credit system is available.
            try {
                if (class_exists(\App\Services\CreditService::class)) {
                    app(\App\Services\CreditService::class)->deduct($wsId, 1, 'studio_chat', [
                        'design_id' => $designId,
                    ]);
                }
            } catch (\Throwable $_e) {
                // Clause O-04: never swallow a billing failure silently.
                // NOTE: \App\Services\CreditService DOES NOT EXIST, so the guard
                // above is always false and Studio has never charged. Left in
                // place deliberately — enabling it is a PRICING decision (CR-23).
                \Illuminate\Support\Facades\Log::warning('studio.chat credit deduct failed', [
                    'workspace_id' => $wsId,
                    'design_id'    => $designId,
                    'exception'    => $_e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'reply'   => $reply,
                'actions' => $actions,
            ]);
        });
// ═════════════════════════════════════════════════════════════════
// Studio VIDEO routes — Slice A
// Patched into the existing Route::prefix('studio')->group(...) block
// right before its closing });
// ═════════════════════════════════════════════════════════════════

        // GET  /api/studio/video/templates — list active video templates
        Route::get('/video/templates', function (\Illuminate\Http\Request $r) {
            $q = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('is_active', 1);
            if ($r->filled('format')) $q->where('format', $r->input('format'));
            // Optional: ?type=html_animated or ?type=clip_json
            if ($r->filled('type')) $q->where('template_type', $r->input('type'));
            $rows = $q->orderBy('id')->get([
                'id','slug','name','category','format','canvas_width','canvas_height',
                'duration_seconds','thumbnail_url','template_type','template_html_path'
            ]);
            foreach ($rows as $row) {
                if ($row->thumbnail_url && strpos($row->thumbnail_url, '?') === false) {
                    $slug = basename($row->thumbnail_url, '.png');
                    $mt = @filemtime(storage_path('app/public/studio-thumbs/' . $slug . '.png'));
                    $row->thumbnail_url .= '?v=' . ($mt ?: time());
                }
            }
            return response()->json(['success' => true, 'templates' => $rows]);
        });

        // GET  /api/studio/video/templates/{slug} — single template with structure_json
        Route::get('/video/templates/{slug}', function ($slug) {
            $row = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('slug', (string)$slug)->where('is_active',1)->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $row->structure_json = json_decode($row->structure_json ?? '{}', true);
            return response()->json(['success'=>true,'template'=>$row]);
        });

        // POST /api/studio/video/designs — create a new video design
        // Body: { template_slug?, format, name }
        Route::post('/video/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $name = trim((string) $r->input('name', 'Untitled Video'));
            $format = (string) $r->input('format', 'reels');
            $templateSlug = $r->input('template_slug');

            $structure = null;
            if ($templateSlug) {
                $tpl = \Illuminate\Support\Facades\DB::table('studio_video_templates')->where('slug', $templateSlug)->first();
                if (!$tpl) return response()->json(['success'=>false,'error'=>'template_not_found'], 404);
                $structure = json_decode($tpl->structure_json ?? '{}', true) ?: [];
                $format = $tpl->format;
            }

            $formatToDims = [
                'reels'     => [1080, 1920],
                'square'    => [1080, 1080],
                'landscape' => [1920, 1080],
            ];
            [$cw, $ch] = $formatToDims[$format] ?? [1080, 1920];
            $duration = (int) ($structure['duration'] ?? 15);

            $dbFormat = match ($format) {
                'reels'     => 'reels',
                'square'    => 'video_square',
                'landscape' => 'video_landscape',
                default     => 'reels',
            };

            $videoData = $structure ?: [
                'format'           => $format,
                'canvas_width'     => $cw,
                'canvas_height'    => $ch,
                'duration'         => $duration,
                'fps'              => 30,
                'background_color' => '#000000',
                'global_filter'    => 'none',
                'clips'            => [],
                'text_overlays'    => [],
                'elements'         => [],
                'audio'            => ['url' => null, 'volume' => 0.8, 'fade_in' => 0.5, 'fade_out' => 1.0],
            ];

            // ─── Normalize template schema → client schema ─────────────────
            // Templates ship compact field names (start/end, x/y, size, weight,
            // animation_in:"slide_up") but the client reader uses the canonical
            // long form (start_time/end_time, position.{x,y}, font_size,
            // font_weight, animation_in:{type,duration}). Also: convert
            // clip_slots[] placeholders into empty clips[] entries so the
            // editor can render them as "drop a clip here" outlines. This runs
            // only when the design is created from a template.
            $videoData = (function($vd) {
                if (!is_array($vd)) return $vd;
                // clip_slots → clips (as placeholders)
                if (empty($vd['clips']) && !empty($vd['clip_slots']) && is_array($vd['clip_slots'])) {
                    $vd['clips'] = [];
                    foreach ($vd['clip_slots'] as $i => $slot) {
                        $s = (float)($slot['start'] ?? 0);
                        $e = (float)($slot['end']   ?? ($s + 3));
                        $vd['clips'][] = [
                            'id'         => $slot['id'] ?? 'slot_' . ($i+1),
                            'type'       => 'placeholder',
                            'source_url' => null,
                            'label'      => (string)($slot['label'] ?? ('Clip ' . ($i+1))),
                            'start_time' => $s,
                            'end_time'   => $e,
                            'duration'   => max(0.5, $e - $s),
                            'transition_in' => $vd['transitions_default'] ?? ['type' => 'fade', 'duration' => 0.5],
                        ];
                    }
                }
                // Existing clips[] — promote short names if present
                if (!empty($vd['clips']) && is_array($vd['clips'])) {
                    foreach ($vd['clips'] as &$c) {
                        if (!isset($c['start_time']) && isset($c['start'])) $c['start_time'] = (float)$c['start'];
                        if (!isset($c['end_time'])   && isset($c['end']))   $c['end_time']   = (float)$c['end'];
                        if (!isset($c['duration']) && isset($c['start_time'], $c['end_time'])) {
                            $c['duration'] = max(0.5, $c['end_time'] - $c['start_time']);
                        }
                    }
                    unset($c);
                }
                // text_overlays → canonical shape
                if (!empty($vd['text_overlays']) && is_array($vd['text_overlays'])) {
                    foreach ($vd['text_overlays'] as $i => &$t) {
                        if (!isset($t['id'])) $t['id'] = 'text_' . ($i + 1);
                        if (!isset($t['start_time']) && isset($t['start'])) $t['start_time'] = (float)$t['start'];
                        if (!isset($t['end_time'])   && isset($t['end']))   $t['end_time']   = (float)$t['end'];
                        if (!isset($t['font_size'])  && isset($t['size']))   $t['font_size']  = (int)$t['size'];
                        if (!isset($t['font_weight'])&& isset($t['weight'])) $t['font_weight']= (string)$t['weight'];
                        if (!isset($t['font_family'])&& isset($t['font']))   $t['font_family']= (string)$t['font'];
                        if (!isset($t['position']) && (isset($t['x']) || isset($t['y']))) {
                            $t['position'] = ['x' => (float)($t['x'] ?? 0), 'y' => (float)($t['y'] ?? 0)];
                        }
                        // animation_in can be a string "slide_up" — wrap it
                        if (isset($t['animation_in']) && is_string($t['animation_in'])) {
                            $t['animation_in'] = ['type' => $t['animation_in'], 'duration' => 0.4];
                        }
                        // Strip the short names so saved data is canonical
                        foreach (['start','end','size','weight','font','x','y'] as $drop) unset($t[$drop]);
                    }
                    unset($t);
                }
                // Ensure audio struct exists
                if (!isset($vd['audio']) || !is_array($vd['audio'])) {
                    $vd['audio'] = ['url' => null, 'volume' => 0.8, 'fade_in' => 0.5, 'fade_out' => 1.0];
                }
                return $vd;
            })($videoData);

            $id = \Illuminate\Support\Facades\DB::table('studio_designs')->insertGetId([
                'workspace_id'      => $wsId,
                'template_id'       => null,
                'name'              => mb_substr($name, 0, 120),
                'format'            => $dbFormat,
                'design_type'       => 'video',
                'canvas_width'      => $cw,
                'canvas_height'     => $ch,
                'layers_json'       => json_encode(['source' => 'video', 'template_slug' => $templateSlug]),
                'video_data'        => json_encode($videoData, JSON_UNESCAPED_SLASHES),
                'duration_seconds'  => $duration,
                'status'            => 'draft',
                'export_status'     => 'pending',
                'created_at'        => now(), 'updated_at' => now(),
            ]);
            return response()->json(['success' => true, 'design_id' => $id, 'video_data' => $videoData], 201);
        });

        // GET  /api/studio/video/designs — list video designs for workspace
        Route::get('/video/designs', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $rows = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('workspace_id', $wsId)->where('design_type','video')->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->get(['id','name','format','canvas_width','canvas_height','duration_seconds','thumbnail_url','exported_video_url','export_status','updated_at']);
            return response()->json(['success'=>true,'designs'=>$rows]);
        });

        // GET  /api/studio/video/designs/{id} — full video_data
        Route::get('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $row->video_data = json_decode($row->video_data ?? '{}', true);
            return response()->json(['success'=>true,'design'=>$row]);
        });

        // PUT  /api/studio/video/designs/{id} — save video_data / name
        Route::put('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            $update = ['updated_at' => now()];
            if ($r->filled('name')) $update['name'] = mb_substr((string)$r->input('name'), 0, 120);
            if ($r->has('video_data')) {
                $vd = $r->input('video_data');
                $vd = is_string($vd) ? json_decode($vd, true) : $vd;
                if (is_array($vd)) {
                    $update['video_data']       = json_encode($vd, JSON_UNESCAPED_SLASHES);
                    $update['duration_seconds'] = (int)($vd['duration'] ?? $row->duration_seconds);
                }
            }
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update($update);
            return response()->json(['success'=>true]);
        });

        // POST /api/studio/video/designs/{id}/export — dispatch render job
        Route::post('/video/designs/{id}/export', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);

            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update([
                'export_status'       => 'pending',
                'export_progress_pct' => 0,
                'export_error'        => null,
                'updated_at'          => now(),
            ]);

            try {
                // v4.4.0: branch by template_type — html_animated uses a different renderer.
                // BUG 3 fix (v4.1.1): worker supervisor listens to tasks-high,tasks,tasks-low.
                // Without onQueue('tasks') the job lands in the unwatched `default` queue.
                $vd = json_decode($row->video_data ?? '{}', true) ?: [];
                $tplSlug = $vd['template_slug'] ?? $vd['slug'] ?? null;
                $templateType = 'clip_json';
                if ($tplSlug) {
                    $tpl = \Illuminate\Support\Facades\DB::table('studio_video_templates')
                        ->where('slug', $tplSlug)->first();
                    if ($tpl && !empty($tpl->template_type)) $templateType = $tpl->template_type;
                }

                if ($templateType === 'html_animated') {
                    \App\Jobs\RenderHtmlAnimatedJob::dispatch((int)$id)->onQueue('tasks');
                } else {
                    \App\Jobs\RenderStudioVideoJob::dispatch((int)$id)->onQueue('tasks');
                }
            } catch (\Throwable $e) {
                return response()->json(['success'=>false,'error'=>'dispatch_failed','detail'=>$e->getMessage()], 500);
            }

            return response()->json(['success'=>true,'status'=>'queued','design_id'=>(int)$id]);
        });

        // GET  /api/studio/video/designs/{id}/export-status — poll render progress
        Route::get('/video/designs/{id}/export-status', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')
                ->first(['export_status','export_progress_pct','export_error','exported_video_url']);
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            return response()->json([
                'success'      => true,
                'status'       => $row->export_status,
                'progress_pct' => (int) $row->export_progress_pct,
                'error'        => $row->export_error,
                'video_url'    => $row->exported_video_url,
            ]);
        });

        // POST /api/studio/video/upload-clip — multipart video upload
        // GET /api/studio/video/export-policy — plan-based export quality cap +
        // watermark, so the editor shows ACCURATE options (server still enforces
        // in RenderStudioVideoJob::resolveVideoPolicy). 2026-07-03.
        Route::get('/video/export-policy', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $p = \App\Jobs\RenderStudioVideoJob::resolveVideoPolicy($wsId);
            $order  = ['720', '1080', '4k'];
            $labels = ['720' => '720p', '1080' => '1080p HD', '4k' => '4K Ultra'];
            $cap = array_search($p['max_quality'], $order, true);
            $tiers = [];
            foreach ($order as $i => $q) {
                $tiers[] = ['value' => $q, 'label' => $labels[$q], 'allowed' => ($i <= $cap)];
            }
            return response()->json([
                'success'     => true,
                'max_quality' => $p['max_quality'],
                'watermark'   => (bool) $p['watermark'],
                'tiers'       => $tiers,
            ]);
        });

        Route::post('/video/upload-clip', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $ok = in_array($file->getMimeType(), ['video/mp4','video/quicktime','video/webm','video/x-matroska'], true);
            if (!$ok) return response()->json(['success'=>false,'error'=>'unsupported_mime','mime'=>$file->getMimeType()], 415);
            if ($file->getSize() > 100 * 1024 * 1024) return response()->json(['success'=>false,'error'=>'too_large'], 413);

            $dir = storage_path('app/public/video-clips/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = bin2hex(random_bytes(5)) . '.' . ($file->getClientOriginalExtension() ?: 'mp4');
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-clips/' . $wsId . '/' . $name;

            // Probe duration + dimensions via ffprobe
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of json ' . escapeshellarg($path), $probe);
            $meta = json_decode(implode('', $probe), true) ?: [];
            $s = $meta['streams'][0] ?? [];
            $out = [
                'success'  => true,
                'clip_url' => $url,
                'width'    => (int)($s['width']  ?? 0),
                'height'   => (int)($s['height'] ?? 0),
                'duration' => (float)($s['duration'] ?? 0),
                'mime'     => $file->getMimeType(),
            ];
            // Best-effort media table insert
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $file->getMimeType(),
                    'source' => 'studio_video_upload', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}
            return response()->json($out);
        });

        // POST /api/studio/video/upload-image — image for slideshow
        Route::post('/video/upload-image', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $ok = str_starts_with((string)$file->getMimeType(), 'image/');
            if (!$ok) return response()->json(['success'=>false,'error'=>'not_an_image'], 415);
            if ($file->getSize() > 25 * 1024 * 1024) return response()->json(['success'=>false,'error'=>'too_large'], 413);

            $dir = storage_path('app/public/video-images/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = bin2hex(random_bytes(5)) . '.' . ($file->getClientOriginalExtension() ?: 'jpg');
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-images/' . $wsId . '/' . $name;
            $info = @getimagesize($path) ?: [0, 0];
            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $file->getMimeType(),
                    'source' => 'studio_video_image', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}
            return response()->json(['success'=>true,'image_url'=>$url,'width'=>(int)$info[0],'height'=>(int)$info[1]]);
        });

        // POST /api/studio/video/upload-audio — audio track upload (mp3/aac/wav/m4a, 50MB max)
        Route::post('/video/upload-audio', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if (!$r->hasFile('file')) return response()->json(['success'=>false,'error'=>'no_file'], 422);
            $file = $r->file('file');
            $mime = (string) $file->getMimeType();
            $allowed = [
                'audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/wav', 'audio/x-wav',
                'audio/x-m4a', 'audio/mp4', 'audio/m4a', 'audio/ogg',
            ];
            if (!in_array($mime, $allowed, true)) {
                return response()->json(['success'=>false,'error'=>'unsupported_mime','mime'=>$mime], 415);
            }
            if ($file->getSize() > 50 * 1024 * 1024) {
                return response()->json(['success'=>false,'error'=>'too_large'], 413);
            }

            $dir = storage_path('app/public/video-audio/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $ext = $file->getClientOriginalExtension() ?: 'mp3';
            $name = bin2hex(random_bytes(5)) . '.' . $ext;
            $file->move($dir, $name);
            $path = $dir . '/' . $name;
            $url = '/storage/video-audio/' . $wsId . '/' . $name;

            // Probe duration via ffprobe
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -show_entries format=duration -of json ' . escapeshellarg($path), $probe);
            $meta = json_decode(implode('', $probe), true) ?: [];
            $duration = (float)($meta['format']['duration'] ?? 0);

            try {
                \Illuminate\Support\Facades\DB::table('media')->insert([
                    'workspace_id' => $wsId, 'url' => $url, 'mime_type' => $mime,
                    'source' => 'studio_video_audio', 'is_platform_asset' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $_e) {}

            return response()->json([
                'success'   => true,
                'audio_url' => $url,
                'duration'  => $duration,
                'mime'      => $mime,
                'size'      => filesize($path),
            ]);
        });

        // POST /api/studio/video/generate-minimax — AI video generation (ASYNC create)
        // 2026-07-03 (#3) — was a 180s SYNCHRONOUS long-poll that 504s behind the
        // 100s Cloudflare / 120s PHP-FPM caps. Now returns task_id immediately;
        // the client polls GET /video/minimax-status. Stateless (task_id = MiniMax handle).
        Route::post('/video/generate-minimax', function (\Illuminate\Http\Request $r) {
            $prompt = trim((string) $r->input('prompt', ''));
            if ($prompt === '') return response()->json(['success'=>false,'error'=>'missing_prompt'], 422);
            $key = env('MINIMAX_API_KEY');
            if (!$key) return response()->json(['success'=>false,'error'=>'MiniMax AI video is not configured yet (missing MINIMAX_API_KEY).'], 503);

            $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(30)
                ->post('https://api.minimax.chat/v1/video_generation', [
                    'model'      => 'MiniMax-Hailuo-02',
                    'prompt'     => $prompt,
                    'duration'   => (int) min(10, max(5, $r->input('duration_seconds', 6))),
                    'resolution' => $r->input('resolution', '1080P'),
                ]);
            if (!$resp->ok()) return response()->json(['success'=>false,'error'=>'minimax_create_failed','detail'=>mb_substr($resp->body(),0,400)], 502);
            $taskId = $resp->json('task_id');
            if (!$taskId) return response()->json(['success'=>false,'error'=>'no_task_id','detail'=>mb_substr($resp->body(),0,400)], 502);

            return response()->json(['success'=>true,'status'=>'processing','task_id'=>$taskId]);
        });

        // GET /api/studio/video/minimax-status?task_id=X — poll MiniMax; on success
        // retrieve + download + persist the clip. Idempotent (hashed by task_id+file_id).
        Route::get('/video/minimax-status', function (\Illuminate\Http\Request $r) {
            $wsId   = (int) $r->attributes->get('workspace_id');
            $taskId = trim((string) $r->input('task_id', ''));
            if ($taskId === '') return response()->json(['success'=>false,'error'=>'missing_task_id'], 422);
            $key   = env('MINIMAX_API_KEY');
            $group = env('MINIMAX_GROUP_ID');
            if (!$key) return response()->json(['success'=>false,'error'=>'MiniMax not configured.'], 503);

            $poll = \Illuminate\Support\Facades\Http::withToken($key)->timeout(15)
                ->get('https://api.minimax.chat/v1/query/video_generation', ['task_id' => $taskId]);
            if (!$poll->ok()) return response()->json(['success'=>true,'status'=>'processing']); // transient — keep polling
            $st = $poll->json('status');
            if (in_array($st, ['Fail','Failed','fail'], true)) {
                return response()->json(['success'=>false,'status'=>'failed','error'=>$poll->json('base_resp.status_msg') ?: 'minimax_failed'], 200);
            }
            if ($st !== 'Success') return response()->json(['success'=>true,'status'=>'processing']);
            $fileId = $poll->json('file_id');
            if (!$fileId) return response()->json(['success'=>true,'status'=>'processing']);

            $dir  = storage_path('app/public/video-clips/minimax');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $hash = substr(hash('sha256', $taskId . $fileId), 0, 16);
            $dest = $dir . '/' . $hash . '.mp4';
            $publicUrl = '/storage/video-clips/minimax/' . $hash . '.mp4';
            if (!is_file($dest)) {
                $fileResp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(15)
                    ->get('https://api.minimax.chat/v1/files/retrieve', array_filter(['file_id'=>$fileId,'GroupId'=>$group]));
                $downloadUrl = $fileResp->json('file.download_url');
                if (!$downloadUrl) return response()->json(['success'=>false,'status'=>'failed','error'=>'minimax_no_download'], 200);
                $bin = @file_get_contents($downloadUrl);
                if ($bin === false || strlen($bin) < 10000) return response()->json(['success'=>false,'status'=>'failed','error'=>'minimax_dl_failed'], 200);
                file_put_contents($dest, $bin);
                try {
                    \Illuminate\Support\Facades\DB::table('media')->insert([
                        'workspace_id'=>$wsId,'url'=>$publicUrl,'mime_type'=>'video/mp4',
                        'source'=>'minimax','is_platform_asset'=>0,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                } catch (\Throwable $_e) {}
            }
            $probe = [];
            @exec('/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of json ' . escapeshellarg($dest), $probe);
            $s = (json_decode(implode('', $probe), true)['streams'][0] ?? []);
            return response()->json([
                'success'=>true,'status'=>'done','clip_url'=>$publicUrl,
                'width'=>(int)($s['width']??0),'height'=>(int)($s['height']??0),'duration'=>(float)($s['duration']??0),
            ]);
        });
        // DELETE /api/studio/video/designs/{id} — soft delete
        Route::delete('/video/designs/{id}', function (\Illuminate\Http\Request $r, $id) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $row = \Illuminate\Support\Facades\DB::table('studio_designs')
                ->where('id',(int)$id)->where('workspace_id',$wsId)->where('design_type','video')->whereNull('deleted_at')->first();
            if (!$row) return response()->json(['success'=>false,'error'=>'not_found'], 404);
            \Illuminate\Support\Facades\DB::table('studio_designs')->where('id',(int)$id)->update(['deleted_at'=>now()]);
            return response()->json(['success'=>true]);
        });


        // studio-phase1-routes
        $studio = \App\Engines\Studio\Services\StudioService::class;

        // Element CRUD
        Route::get('/designs/{id}/elements',             fn(\Illuminate\Http\Request $r, $id)      => response()->json(['elements' => app($studio)->getElements((int) $id)]));
        Route::post('/designs/{id}/elements',            fn(\Illuminate\Http\Request $r, $id)      => response()->json(app($studio)->saveElement($r->attributes->get('workspace_id'), (int) $id, $r->all()), 201));
        Route::put('/designs/{id}/elements/{eid}',       fn(\Illuminate\Http\Request $r, $id, $eid) => response()->json(app($studio)->updateElement((int) $eid, $r->all(), (int) $r->attributes->get('workspace_id'))));
        Route::delete('/designs/{id}/elements/{eid}',    fn(\Illuminate\Http\Request $r, $id, $eid) => response()->json(['deleted' => app($studio)->deleteElement((int) $eid, (int) $r->attributes->get('workspace_id'))]));
        Route::post('/designs/{id}/elements/reorder',    fn(\Illuminate\Http\Request $r, $id)       => response()->json(['reordered' => app($studio)->reorderElements((int) $id, (array) $r->input('element_ids', []), (int) $r->attributes->get('workspace_id'))]));

        // Design-level Phase 1 additions
        Route::post('/designs/{id}/duplicate',           fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->duplicateDesign((int) $id, (int) $r->attributes->get('workspace_id'))));
        Route::post('/designs/{id}/thumbnail',           fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->generateThumbnail((int) $id, (int) $r->attributes->get('workspace_id'))));
        Route::post('/designs/{id}/history',             fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->saveHistory((int) $id, (array) $r->input('snapshot', []), (int) $r->attributes->get('workspace_id'))));
        Route::get('/designs/{id}/history',              fn(\Illuminate\Http\Request $r, $id)       => response()->json(app($studio)->getHistory((int) $id)));

        // Brand kit (per workspace)
        Route::get('/brand-kit',  fn(\Illuminate\Http\Request $r) => response()->json(['brand_kit' => app($studio)->getBrandKit((int) $r->attributes->get('workspace_id'))]));
        Route::put('/brand-kit',  fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->updateBrandKit((int) $r->attributes->get('workspace_id'), $r->all())));

        // Static catalogs
        Route::get('/fonts',      fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->getFonts()));
        Route::get('/formats',    fn(\Illuminate\Http\Request $r) => response()->json(app($studio)->getFormats()));


        // studio-phase4-routes
        $studioAi = \App\Engines\Studio\Services\StudioAiService::class;

        // Arthur AI (Phase 4) — PATCH 4 (2026-05-08): plan-gated + credit-reserved.
        // Was direct service calls with no plan check or credit reservation,
        // letting free-plan users drain provider keys at zero cost.
        $studioAiGate = function (\Illuminate\Http\Request $r, string $method, int $cost, string $reason) use ($studioAi) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if ($wsId <= 0) return response()->json(['error' => 'workspace_required'], 400);

            $gate = app(\App\Core\Billing\FeatureGateService::class);
            if (!$gate->canUseAI($wsId)) {
                return response()->json([
                    'error'            => 'Your plan does not include AI generation.',
                    'upgrade_required' => true,
                ], 403);
            }

            $credits = app(\App\Core\Billing\CreditService::class);
            try {
                $reservationRef = $credits->reserve($wsId, $cost, $reason);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Insufficient credits.', 'message' => $e->getMessage()], 402);
            }

            try {
                $result = app($studioAi)->{$method}($wsId, $r->all());
                if (!empty($result['success'])) {
                    $credits->commit($wsId, $reservationRef, $cost);
                } else {
                    $credits->release($wsId, $reservationRef);
                }
                return response()->json($result);
            } catch (\Throwable $e) {
                $credits->release($wsId, $reservationRef);
                throw $e;
            }
        };
        Route::post('/ai/generate-design', fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'generateDesign', 5, 'studio_ai_generate_design'))->middleware('throttle:20,1');
        // CANONICAL IMAGE INTELLIGENCE (2026-07-28) — Studio 'Generate Image'
        // now routes through the single ImageIntelligenceService pipeline
        // (Arthur reasoning -> ImageBlueprint -> compiler -> credits -> asset ->
        // audit) instead of the old string-wrapping StudioAiService path that
        // hardcoded 8 credits, forced quality low and always stripped text.
        // Credits are owned by the canonical service; we keep the plan gate.
        Route::post('/ai/generate-image', function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            if ($wsId <= 0) return response()->json(['error' => 'workspace_required'], 400);
            $gate = app(\App\Core\Billing\FeatureGateService::class);
            if (!$gate->canUseAI($wsId)) {
                return response()->json(['error' => 'Your plan does not include AI generation.', 'upgrade_required' => true], 403);
            }
            // ── P2 BUG-001: request idempotency (route-level; smallest blast
            // radius; mirrors the Studio edit route's GET_LOCK pattern). One user
            // intent = exactly ONE completed generation. A duplicate submission
            // (double/triple-click, refresh-retry, network retry) reserves no
            // credits, calls no provider, and creates no asset / media /
            // creative_job — it replays the winner or returns 409.
            $prompt    = (string) $r->input('prompt', '');
            $clientKey = trim((string) ($r->header('Idempotency-Key') ?? $r->input('idempotency_key', '')));
            $derived   = ($clientKey === '');
            $key = $derived
                ? 'auto:' . md5(json_encode([
                    $wsId, trim($prompt), $r->input('platform'), $r->input('asset_type', 'social_post'),
                    (int) $r->input('width'), (int) $r->input('height'),
                    (string) $r->input('quality', 'auto'), (string) $r->input('style', 'natural'),
                    (string) $r->input('include_text_preference', 'auto'),
                ]))
                : $clientKey;

            $dims = null;
            if ((int) $r->input('width') > 0 && (int) $r->input('height') > 0) {
                $dims = ['width' => (int) $r->input('width'), 'height' => (int) $r->input('height')];
            }

            // Replay an already-completed identical generation (never re-charge).
            $existing = function () use ($wsId, $key, $derived) {
                $q = \Illuminate\Support\Facades\DB::table('assets')
                    ->where('workspace_id', $wsId)
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.idempotency_key')) = ?", [$key])
                    ->whereIn('status', ['generating', 'completed'])
                    ->orderByDesc('id');
                if ($derived) $q->where('created_at', '>=', now()->subSeconds(120)); // derived key: only dedupe a recent burst
                return $q->first();
            };
            $replay = function ($row) {
                return response()->json([
                    'success'           => true,
                    'url'               => $row->url,
                    'image_url'         => $row->url,
                    'width'             => (int) ($row->width ?: 1024),
                    'height'            => (int) ($row->height ?: 1024),
                    'asset_id'          => (int) $row->id,
                    'idempotent_replay' => true,
                ]);
            };

            if ($hit = $existing()) return $replay($hit); // fast path: already done

            $lock = 'studio_gen:' . $wsId . ':' . md5($key);
            $db   = \Illuminate\Support\Facades\DB::connection();
            $got  = (int) $db->selectOne('SELECT GET_LOCK(?, 10) AS l', [$lock])->l;
            if ($got !== 1) {
                // An identical generation is in flight on another request.
                if ($hit = $existing()) return $replay($hit); // winner finished while we waited
                return response()->json(['success' => false, 'status' => 'in_progress',
                    'error' => 'This image is already being generated.'], 409);
            }
            try {
                if ($hit = $existing()) return $replay($hit); // winner finished during lock acquisition

                $out = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class)->generate([
                    'source'       => 'studio',
                    'platform'     => $r->input('platform'),
                    'asset_type'   => $r->input('asset_type', 'social_post'),
                    'workspace_id' => $wsId,
                    'user_prompt'  => $prompt,
                    'style'        => (string) $r->input('style', 'natural'),
                    'requested_dimensions' => $dims,
                    'requested_quality'    => $r->input('quality', 'auto'),
                    'include_text_preference' => $r->input('include_text_preference', 'auto'),
                ]);

                // Stamp the key on the produced asset so a later retry/refresh
                // replays instead of regenerating. ONLY successful generations are
                // stamped, so a failed attempt can be freely retried.
                if (!empty($out['success']) && !empty($out['asset_id'])) {
                    try {
                        $meta = json_decode((string) \Illuminate\Support\Facades\DB::table('assets')->where('id', $out['asset_id'])->value('metadata_json'), true) ?: [];
                        $meta['idempotency_key'] = $key;
                        \Illuminate\Support\Facades\DB::table('assets')->where('id', $out['asset_id'])->update(['metadata_json' => json_encode($meta), 'updated_at' => now()]);
                    } catch (\Throwable $e) { /* stamping is best-effort */ }
                }

                if (!empty($out['success'])) {
                    // Back-compat keys for studio.js (expects image_url/width/height).
                    [$w, $h] = array_map('intval', array_pad(explode('x', (string) ($out['size'] ?? '1024x1024')), 2, 1024));
                    $out['image_url'] = $out['url'];
                    $out['width']  = $w;
                    $out['height'] = $h;
                    return response()->json($out);
                }
                $code = ($out['error'] ?? '') === 'insufficient_credits' ? 402 : 422;
                return response()->json($out, $code);
            } finally {
                $db->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lock]);
            }
        })->middleware('throttle:20,1');
        Route::post('/ai/suggest-copy',    fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'suggestCopy',    1, 'studio_ai_suggest_copy'));
        Route::post('/ai/chat',            fn(\Illuminate\Http\Request $r) => $studioAiGate($r, 'chat',           1, 'studio_ai_chat'));

        // Phase 5 — publish + thumbnail + resize
        Route::post('/designs/{id}/publish-social', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->publishToSocial((int) $id, (int) $r->attributes->get('workspace_id'), $r->all())));
        Route::post('/designs/{id}/resize',         fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->resizeDesign((int) $id, (int) $r->input('width'), (int) $r->input('height'), (int) $r->attributes->get('workspace_id'))));
        Route::post('/designs/{id}/save-to-media',  fn(\Illuminate\Http\Request $r, $id) => response()->json(app($studio)->saveExportToMedia((int) $id, (int) $r->attributes->get('workspace_id'))));

    });
