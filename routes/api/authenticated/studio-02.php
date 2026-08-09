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
            // Sprint 4: detect a design REVIEW request (critique flag on, ws in scope). A review is
            // read-only -> its response is forced to actions:[] below. 'apply ...' is never a review.
            $__reviewPilotWs = (int) config('studio_chat_apply.pilot_workspace_id');
            $__isReview = (config('studio_chat_apply.design_critique') === true)
                && ($__reviewPilotWs === 0 || $wsId === $__reviewPilotWs)
                && ! (bool) preg_match('/\bapply\b/i', $message)
                && ((bool) preg_match('/\b(review|critique|feedback|audit|assess|recommendations?)\b/i', $message)
                    || (bool) preg_match('/how (can|could|would|do) (i|we|you)\b.*(improve|better|premium|professional|design)/i', $message)
                    || (bool) preg_match('/what would make .*(better|premium|professional|stronger|award)/i', $message));

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
                    // Feature Sprint 1: when AI Style Editing is active for this workspace,
                    // let the model also emit update_style actions (6 committed properties only).
                    $__saPilotWs = (int) config('studio_chat_apply.pilot_workspace_id');
                    if (config('studio_chat_apply.server_apply_style') === true && ($__saPilotWs === 0 || $wsId === $__saPilotWs)) {
                        $__styleClause = ' You may ALSO return style actions to restyle existing fields: '
                            . '{"type":"update_style","name":"<existing field>","property":"color|background-color|font-size|font-weight|text-align|opacity","value":"<concrete css value>"}. '
                            . 'Use concrete CSS values: colors as a name or hex (blue, #0000ff); font-size in px (72px; read bigger/smaller as a sensible px); font-weight bold/normal/700; text-align left/center/right; opacity 0 to 1. '
                            . 'For plural requests (all buttons, every heading, all stats) return one update_style per matching field from the list above. '
                            . 'Only these 6 properties are supported; for margin, padding, border, radius, shadow, animation or layout, say you cannot do that yet.';
                        $systemPrompt .= $__styleClause;
                        $instructions .= $__styleClause;
                    }
                    // Feature Sprint 2: when AI Image Editing is active, let the model emit
                    // generate_and_replace_image / update_image, and refuse unsupported edits.
                    if (config('studio_chat_apply.server_apply_image') === true && ($__saPilotWs === 0 || $wsId === $__saPilotWs)) {
                        $__imgFields = [];
                        if (preg_match_all('/<img\b[^>]*\bdata-field="([^"]+)"/i', $html, $__mi)) { $__imgFields = array_merge($__imgFields, $__mi[1]); }
                        if (preg_match_all('/\bdata-field="([^"]+)"[^>]*>\s*<img\b/i', $html, $__mw)) { $__imgFields = array_merge($__imgFields, $__mw[1]); }
                        $__imgFields = array_values(array_unique($__imgFields));
                        $__imgClause = ' Image fields you can target: ' . (empty($__imgFields) ? '(none in this design)' : implode(', ', $__imgFields)) . '.'
                            . ' To CREATE a new image from a description return {"type":"generate_and_replace_image","name":"<image field>","prompt":"<vivid description>"}; to place a concrete approved URL return {"type":"update_image","name":"<image field>","url":"<approved url>"} (never invent or use an untrusted URL).'
                            . ' You cannot remove backgrounds, crop, resize, blur, or apply filters yet - if asked for those, say they are not supported.';
                        $systemPrompt .= $__imgClause;
                        $instructions .= $__imgClause;
                    // STUDIO888 Phase A1-A3: AI Image Editing. When image ops are active, teach the model
                    // to answer an image-EDIT request (remove background, remove an object, replace an
                    // object) on an EXISTING image field with an edit_image action - never a generation.
                    if (config('studio_chat_apply.server_apply_image') === true && ($__saPilotWs === 0 || $wsId === $__saPilotWs)) {
                        $__editClause = ' IMAGE EDITING: to REMOVE THE BACKGROUND, REMOVE AN OBJECT, or REPLACE AN OBJECT in an EXISTING image field, return {"type":"edit_image","name":"<image field>","operation":"remove_background" | "remove_object" | "replace_object", ...}. For remove_background also add "background":"white" or "studio" (transparent is not available yet). For remove_object also add "target":"<the thing to remove, e.g. the crane>". For replace_object also add "target":"<the thing>" and "replacement":"<what to put instead>". Use edit_image ONLY to change an EXISTING image; use generate_and_replace_image to create a brand-new image. Never invent an image field that does not exist.';
                        $systemPrompt .= $__editClause;
                        $instructions .= $__editClause;
                    }
                    }
                    // Feature Sprint 3: when brand colour application is active, tell the model the
                    // workspace brand palette (resolver) + the apply_brand action.
                    if (config('studio_chat_apply.server_apply_brand') === true && ($__saPilotWs === 0 || $wsId === $__saPilotWs)) {
                        $__kitP = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
                        if (! empty($__kitP['is_neutral'])) {
                            $__brandClause = ' The workspace has NO configured brand palette. If the user asks to apply their brand colours, tell them to set up their brand colours first; never invent colours.';
                        } else {
                            $__brandClause = ' The workspace brand palette is: primary ' . $__kitP['primary_color'] . ', secondary ' . $__kitP['secondary_color'] . ', accent ' . $__kitP['accent_color'] . ', background ' . $__kitP['background_color'] . ', text ' . $__kitP['text_color'] . '. To apply the workspace brand colours to this design, return {"type":"apply_brand"} (no values needed). Use apply_brand ONLY for the workspace own brand/company colours.';
                        }
                        $systemPrompt .= $__brandClause;
                        $instructions .= $__brandClause;
                    }
                    // Feature Sprint 4: AI Design Critique. Provide grounded facts (real content_html
                    // + brand kit). For a REVIEW request the response is FORCED to actions:[] server-side
                    // (see the review gate below) so a review can never edit; applying a recommendation
                    // reuses the existing verified update_style/apply_brand/generate_and_replace_image blocks.
                    if (config('studio_chat_apply.design_critique') === true && ($__saPilotWs === 0 || $wsId === $__saPilotWs)) {
                        $__cStyles = [];
                        if (preg_match_all('/data-field="([^"]+)"[^>]*\bstyle="([^"]*)"/i', $html, $__csm)) {
                            foreach ($__csm[1] as $__i => $__nm) { if (!isset($__cStyles[$__nm])) $__cStyles[$__nm] = trim($__csm[2][$__i]); }
                        }
                        $__cImgs = [];
                        if (preg_match_all('/<img[^>]*\bdata-field="([^"]+)"/i', $html, $__cim)) { $__cImgs = array_values(array_unique($__cim[1])); }
                        $__cKit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
                        $__cBrand = !empty($__cKit['is_neutral']) ? 'none configured yet'
                            : ('primary ' . $__cKit['primary_color'] . ', secondary ' . $__cKit['secondary_color'] . ', accent ' . $__cKit['accent_color'] . ', background ' . $__cKit['background_color'] . ', text ' . $__cKit['text_color']);
                        $__critique = "\n\nDESIGN-CRITIQUE FACTS (never invent beyond these): PER-ELEMENT INLINE STYLES " . json_encode($__cStyles, JSON_UNESCAPED_SLASHES)
                          . "; IMAGE FIELDS " . json_encode($__cImgs, JSON_UNESCAPED_SLASHES)
                          . "; WORKSPACE BRAND PALETTE " . $__cBrand . " (you also have CURRENT TEXT FIELDS and CURRENT CSS COLOR VARIABLES above)."
                          . " Supported recommendation categories and the action each maps to: Typography (font-size/font-weight/text-align) -> update_style; Colour/Contrast (an element colour, or the design palette vs the brand palette) -> update_style or apply_brand; Image (weak/off-message hero or photo) -> generate_and_replace_image."
                          . " NOT SUPPORTED (always 'Supported: NO', reason 'layout editing is not implemented yet'): spacing, padding, margins, positioning, alignment BETWEEN elements, overlap, resizing/geometry, adding/removing elements.";
                        if ($__isReview) {
                            $__critique .= " THIS MESSAGE IS A DESIGN REVIEW, NOT AN EDIT. You MUST return \"actions\":[] (an empty array) and put a DESIGN SCORECARD in \"reply\" as plain text."
                                . " Start with a line 'OVERALL: <n>/100'. Then, for EACH category you can genuinely ground in the facts above, output a block: '<Category>: <n>/100', then a line 'Evidence:' followed by the specific observed values you used (actual px sizes, hex colours, field names, brand vs design colours), then 'Recommendation:' (one line), then 'Supported: YES' or 'Supported: NO'."
                                . " Score ONLY these categories and ONLY when you have real evidence for them: Typography, Colour Usage, Brand Consistency, Hero Image, CTA Strength, Content Hierarchy, Readability. If a category has no supporting evidence in the facts above, OMIT it entirely - never invent a score, a px value, or a hex you did not observe."
                                . " Every score must be derived from the evidence, and every deduction must cite the observed value(s). Do NOT score spacing, alignment, balance, composition, whitespace, or accessibility (not measurable) - if the user asks for one of those, list it as 'Supported: NO' with reason 'layout editing is not implemented yet'."
                                . " After the category blocks, add a numbered 'Recommendations:' list where each item maps to a supported action (Typography -> update_style; Colour/Contrast/Brand -> update_style or apply_brand; Image -> generate_and_replace_image). Do NOT edit anything now.";
                        } else {
                            $__critique .= " If the user is applying a previous recommendation (e.g. 'apply recommendation 2' or 'apply 1,2,4'), emit ONLY the matching supported action(s) from your previous review and skip any that were 'Supported: NO' (say so).";
                        }
                        $systemPrompt .= $__critique;
                        $instructions .= $__critique;
                    }


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

            // ══ Integration Milestone 1: server-verified single text edit ══════════
            // DEFAULT OFF => the legacy return below is byte-identical to today.
            // When ON and the LLM proposed EXACTLY ONE supported update_field, apply
            // + verify + persist on the committed HtmlProjectionAdapter and return NO
            // action (browser stays inert: no _applyChatActions, no autosave, no 2nd
            // PUT). ANY unsupported/unsafe/unverified/dirty-editor/concurrent case
            // falls through to the unchanged legacy response. Never both.
            // Sprint 4 review gate: a review NEVER edits — return the critique reply with no actions,
            // regardless of what the model returned (safety net against auto-apply).
            if ($__isReview) {
                return response()->json(['success' => true, 'reply' => $reply, 'actions' => []]);
            }
            $__pilotWs = (int) config('studio_chat_apply.pilot_workspace_id');

            if (config('studio_chat_apply.server_apply_text') === true && ($__pilotWs === 0 || $wsId === $__pilotWs)) {
                $__ic = null;
                $__eligible = is_array($actions) && count($actions) === 1
                    && is_array($actions[0] ?? null)
                    && (($actions[0]['type'] ?? null) === 'update_field')
                    && is_string($actions[0]['name'] ?? null) && ($actions[0]['name'] !== '')
                    && array_key_exists('value', $actions[0])
                    && (is_string($actions[0]['value']) || is_numeric($actions[0]['value']));
                // Dirty-editor guard: only proceed when the browser asserts a CLEAN
                // editor (no unsaved manual edits ahead of persisted content_html).
                // Absent flag (older client) => treated as NOT clean => legacy.
                if ($__eligible && $r->boolean('client_clean')) {
                    try {
                        // Re-read the CURRENT persisted row as the concurrency base.
                        $__row = \Illuminate\Support\Facades\DB::table('studio_designs')
                            ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                        if ($__row) {
                            $__base  = (string) ($__row->content_html ?? '');
                            $__field = (string) $actions[0]['name'];
                            $__value = (string) $actions[0]['value'];
                            // Canonical document form: Arthur JSON {template_slug, fields} => structured; else raw HTML.
                            $__dec = json_decode($__base, true);
                            $__structured = is_array($__dec) && isset($__dec['template_slug'])
                                && isset($__dec['fields']) && is_array($__dec['fields']);
                            $__adapter = $__structured
                                ? \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forStructured($__dec)
                                : \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base);
                            $__before = $__adapter->document()->get($__field, 'text');
                            $__req = \App\Engines\Studio\Projection\ProjectionRequest::fromArray([
                                'schema_version'            => 1,
                                'operation_id'              => 'studio-chat-text',
                                'document_id'               => (string) $designId,
                                'target_id'                 => $__field,
                                'changed_fields'            => ['text'],
                                'desired_after_state'       => ['text' => $__value],
                                'expected_document_version' => $__adapter->currentVersion()->token,
                                'before_snapshot_hash'      => \App\Engines\Studio\Projection\ProjectionRequest::hashState(['text' => $__before]),
                                'correlation_id'            => 'chat-' . $designId . '-' . $__field,
                                'idempotency_key'           => null,
                                'batch_id'                  => null,
                                'projection_meta'           => [],
                            ]);
                            $__res = $__adapter->project($__req);
                            $__verified = ($__res->status === \App\Engines\Studio\Projection\ProjectionStatus::APPLIED)
                                && (($__res->verification['verified'] ?? false) === true)
                                && in_array('text', $__res->appliedFields, true)
                                && array_key_exists('text', $__res->actualAfterState);
                            if ($__verified) {
                                // Persist the VERIFIED payload with an optimistic compare-and-swap on
                                // the exact row we read (updated_at) => never overwrite a newer state.
                                $__payload = $__adapter->payload();
                                $__store   = $__structured ? json_encode($__payload) : (string) $__payload;
                                $__affected = \Illuminate\Support\Facades\DB::table('studio_designs')
                                    ->where('id', $designId)->where('workspace_id', $wsId)
                                    ->whereNull('deleted_at')->where('updated_at', $__row->updated_at)
                                    ->update(['content_html' => $__store, 'updated_at' => now()]);
                                if ($__affected === 1) {
                                    // Fire-and-forget thumbnail regen (mirror of the PUT /designs path).
                                    register_shutdown_function(function () use ($designId) {
                                        try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int) $designId); }
                                        catch (\Throwable $e) {}
                                    });
                                    // Truthful reply built ONLY from the verified ProjectionResult.
                                    $__after = (string) ($__res->actualAfterState['text'] ?? $__value);
                                    $__ic = [
                                        'success'         => true,
                                        'reply'           => 'Changed "' . (string) $__before . '" to "' . $__after . '" and verified the saved result.',
                                        'actions'         => [],            // XOR: no browser action on verified server-apply
                                        'server_applied'  => true,
                                        'refresh_preview' => true,
                                        'verified'        => [
                                            'target_id'          => $__res->targetId,
                                            'status'             => $__res->status,
                                            'applied_fields'     => $__res->appliedFields,
                                            'actual_after_state' => $__res->actualAfterState,
                                            'verification'       => $__res->verification,
                                            'document_form'      => $__structured ? 'structured' : 'raw',
                                        ],
                                    ];
                                }
                                // $__affected !== 1 => design changed under us => fall through to legacy (nothing persisted).
                            }
                            // not verified (target_missing/ambiguous/stale/snapshot/no_change/failed) => fall through to legacy.
                        }
                    } catch (\Throwable $__e) {
                        \Illuminate\Support\Facades\Log::warning('studio.chat server-apply text failed: ' . $__e->getMessage());
                        $__ic = null; // safe fallback to legacy
                    }
                }
                if ($__ic !== null) {
                    return response()->json($__ic);
                }
                // else: fall through to the unchanged legacy response below.
            }

            // ══ Feature Sprint 1: server-verified AI Style Editing (set_style) ═════════
            // Exposes the committed set_style capability via chat, reusing Integration 1's
            // verified-apply flow + the committed ProjectionBatch for plural edits. When the
            // model proposed ANY update_style action and this workspace is in scope, WE OWN
            // the response: a verified server apply (actions:[]) or a truthful decline — never
            // the model's unverified reply, never a browser style mutation. Atomic batch =>
            // all-or-nothing. Structured templates decline (engine is text-only there).
            if (config('studio_chat_apply.server_apply_style') === true && ($__pilotWs === 0 || $wsId === $__pilotWs)) {
                $__hasStyle = is_array($actions) && count(array_filter($actions, function ($a) {
                    return is_array($a) && (($a['type'] ?? null) === 'update_style');
                })) > 0;
                if ($__hasStyle) {
                    $__props = ['color', 'background-color', 'font-size', 'font-weight', 'text-align', 'opacity'];
                    $__st = ['success' => true, 'reply' => 'I could not apply that style change.', 'actions' => [], 'server_applied' => false];
                    $__allStyle = is_array($actions) && count($actions) >= 1 && count($actions) <= 24;
                    if ($__allStyle) {
                        foreach ($actions as $a) {
                            if (!is_array($a) || (($a['type'] ?? null) !== 'update_style')
                                || !is_string($a['name'] ?? null) || ($a['name'] === '')
                                || !in_array($a['property'] ?? null, $__props, true)
                                || !array_key_exists('value', $a)
                                || !(is_string($a['value']) || is_numeric($a['value']))) { $__allStyle = false; break; }
                        }
                    }
                    if (!$r->boolean('client_clean')) {
                        $__st['reply'] = 'Please save your current edits first, then ask me to restyle.';
                    } elseif (!$__allStyle) {
                        $__st['reply'] = 'I can only change color, background, size, weight, alignment or opacity right now.';
                    } else {
                        try {
                            $__row = \Illuminate\Support\Facades\DB::table('studio_designs')
                                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                            if (!$__row) {
                                $__st['reply'] = 'That design could not be found.';
                            } else {
                                $__base = (string) ($__row->content_html ?? '');
                                $__dec = json_decode($__base, true);
                                $__structured = is_array($__dec) && isset($__dec['template_slug']) && isset($__dec['fields']) && is_array($__dec['fields']);
                                if ($__structured) {
                                    $__st['reply'] = 'Styling is not available for this template type yet, so nothing was changed.';
                                } else {
                                    $__adapter = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base);
                                    $__reqs = [];
                                    foreach (array_values($actions) as $__i => $a) {
                                        $__p = 'style.' . $a['property'];
                                        $__reqs[] = \App\Engines\Studio\Projection\ProjectionRequest::fromArray([
                                            'schema_version' => 1, 'operation_id' => 'chat-style-' . $__i, 'document_id' => (string) $designId,
                                            'target_id' => (string) $a['name'], 'changed_fields' => [$__p],
                                            'desired_after_state' => [$__p => (string) $a['value']],
                                            'expected_document_version' => null, 'before_snapshot_hash' => null,
                                            'correlation_id' => 'chat-style-' . $designId, 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
                                        ]);
                                    }
                                    $__batch = new \App\Engines\Studio\Projection\ProjectionBatch(
                                        'chat-' . $designId, (string) $designId, $__reqs,
                                        \App\Engines\Studio\Projection\ProjectionTransactionBoundary::atomic(), null, 'chat-style-' . $designId
                                    );
                                    $__bres = $__adapter->projectBatch($__batch);
                                    $__allok = ($__bres->status === \App\Engines\Studio\Projection\ProjectionStatus::APPLIED)
                                        && ($__bres->appliedCount() === count($__reqs));
                                    if ($__allok) {
                                        foreach ($__bres->results as $__rr) {
                                            if ((($__rr->verification['verified'] ?? false) !== true)) { $__allok = false; break; }
                                        }
                                    }
                                    if ($__allok) {
                                        $__store = (string) $__adapter->payload();
                                        $__aff = \Illuminate\Support\Facades\DB::table('studio_designs')
                                            ->where('id', $designId)->where('workspace_id', $wsId)
                                            ->whereNull('deleted_at')->where('updated_at', $__row->updated_at)
                                            ->update(['content_html' => $__store, 'updated_at' => now()]);
                                        if ($__aff === 1) {
                                            register_shutdown_function(function () use ($designId) {
                                                try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int) $designId); }
                                                catch (\Throwable $e) {}
                                            });
                                            $__n = count($__reqs);
                                            $__st = [
                                                'success' => true,
                                                'reply' => 'Applied and verified ' . $__n . ' style change' . ($__n === 1 ? '' : 's') . '.',
                                                'actions' => [],
                                                'server_applied' => true,
                                                'refresh_preview' => true,
                                                'verified' => ['count' => $__n, 'status' => $__bres->status, 'document_form' => 'raw'],
                                            ];
                                        } else {
                                            $__st['reply'] = 'Your design changed while I was working - please try again.';
                                        }
                                    } else {
                                        $__st['reply'] = 'I could not apply that style - the target may not exist or the value was not valid.';
                                    }
                                }
                            }
                        } catch (\Throwable $__e) {
                            \Illuminate\Support\Facades\Log::warning('studio.chat server-apply style failed: ' . $__e->getMessage());
                        }
                    }
                    return response()->json($__st);
                }
            }

            // ══ Feature Sprint 2: server-verified AI Image Editing (replace + generate) ══
            // Reuses the projection `src` field + the existing image-generation pipeline.
            // WE OWN the response when the model proposed any image action: a verified apply
            // (actions:[]) or a truthful decline - never the model's unverified reply, never a
            // browser image mutation. Structured designs + unsupported edits decline safely.
            if (config('studio_chat_apply.server_apply_image') === true && ($__pilotWs === 0 || $wsId === $__pilotWs)) {
                $__imgActs = is_array($actions) ? array_values(array_filter($actions, function ($a) {
                    return is_array($a) && in_array($a['type'] ?? null, ['update_image', 'generate_and_replace_image'], true);
                })) : [];
                if (count($__imgActs) > 0) {
                    $__appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
                    $__trusted = (array) config('studio_chat_apply.image_trusted_hosts', []);
                    $__sec = new \App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy();
                    $__urlApproved = function (string $u) use ($__sec, $__appHost, $__trusted): bool {
                        if (! $__sec->isSafeImageUrl($u)) return false;
                        if (str_starts_with($u, '/') && ! str_starts_with($u, '//')) return true;
                        $h = strtolower((string) parse_url($u, PHP_URL_HOST));
                        return $h !== '' && ($h === $__appHost || in_array($h, $__trusted, true));
                    };
                    $__im = ['success' => true, 'reply' => 'I could not update that image.', 'actions' => [], 'server_applied' => false];

                    $__row = \Illuminate\Support\Facades\DB::table('studio_designs')
                        ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                    $__base = $__row ? (string) ($__row->content_html ?? '') : '';
                    $__dec = $__row ? json_decode($__base, true) : null;
                    $__structured = is_array($__dec) && isset($__dec['template_slug']) && isset($__dec['fields']) && is_array($__dec['fields']);

                    // shared: verified src replacement of {field=>url} pairs, atomic + optimistic CAS persist
                    $__applySrc = function (array $pairs) use ($wsId, $designId, $__row, $__base) {
                        $adapter = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base);
                        $reqs = [];
                        foreach (array_values($pairs) as $i => $pr) {
                            $reqs[] = \App\Engines\Studio\Projection\ProjectionRequest::fromArray([
                                'schema_version' => 1, 'operation_id' => 'chat-img-' . $i, 'document_id' => (string) $designId,
                                'target_id' => (string) $pr[0], 'changed_fields' => ['src'], 'desired_after_state' => ['src' => (string) $pr[1]],
                                'expected_document_version' => null, 'before_snapshot_hash' => null,
                                'correlation_id' => 'chat-img-' . $designId, 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
                            ]);
                        }
                        $batch = new \App\Engines\Studio\Projection\ProjectionBatch('chat-' . $designId, (string) $designId, $reqs,
                            \App\Engines\Studio\Projection\ProjectionTransactionBoundary::atomic(), null, 'chat-img-' . $designId);
                        $bres = $adapter->projectBatch($batch);
                        $ok = ($bres->status === \App\Engines\Studio\Projection\ProjectionStatus::APPLIED) && ($bres->appliedCount() === count($reqs));
                        if ($ok) { foreach ($bres->results as $rr) { if ((($rr->verification['verified'] ?? false) !== true)) { $ok = false; break; } } }
                        if (! $ok) { return [false, null]; }
                        $aff = \Illuminate\Support\Facades\DB::table('studio_designs')
                            ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')
                            ->where('updated_at', $__row->updated_at)
                            ->update(['content_html' => (string) $adapter->payload(), 'updated_at' => now()]);
                        if ($aff !== 1) { return [false, 'stale']; }
                        register_shutdown_function(function () use ($designId) {
                            try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int) $designId); } catch (\Throwable $e) {}
                        });
                        return [true, $bres];
                    };

                    try {
                        if (! $__row) {
                            $__im['reply'] = 'That design could not be found.';
                        } elseif (! $r->boolean('client_clean')) {
                            $__im['reply'] = 'Please save your current edits first, then ask me to change the image.';
                        } elseif ($__structured) {
                            $__im['reply'] = 'Image editing is not available for this template type yet, so nothing was changed.';
                        } else {
                            $__gen = array_values(array_filter($__imgActs, fn ($a) => ($a['type'] ?? null) === 'generate_and_replace_image'));
                            $__rep = array_values(array_filter($__imgActs, fn ($a) => ($a['type'] ?? null) === 'update_image'));

                            if (count($__gen) >= 1) {
                                if (count($__gen) > 1 || count($__rep) > 0) {
                                    $__im['reply'] = 'I can generate and place one image at a time - please ask for a single image.';
                                } else {
                                    $g = $__gen[0];
                                    $field = (string) ($g['name'] ?? '');
                                    $prompt2 = trim((string) ($g['prompt'] ?? ''));
                                    $preAdapter = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base);
                                    if ($field === '' || ! $preAdapter->document()->supports($field, 'src')) {
                                        $__im['reply'] = 'I could not find that image on the design, so I did not generate anything.';
                                    } elseif ($prompt2 === '') {
                                        $__im['reply'] = 'Tell me what the image should show and I will generate it.';
                                    } elseif (! app(\App\Core\Billing\FeatureGateService::class)->canUseAI($wsId)) {
                                        $__im = ['success' => false, 'reply' => null, 'actions' => [], 'server_applied' => false,
                                                 'chat_error' => ['code' => 'AI_NOT_IN_PLAN', 'message' => 'Your plan does not include AI image generation.', 'retryable' => false]];
                                    } else {
                                        $out = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class)->generate([
                                            'source' => 'studio', 'platform' => null, 'asset_type' => 'social_post',
                                            'workspace_id' => $wsId, 'user_prompt' => $prompt2, 'style' => 'natural',
                                            'requested_dimensions' => null, 'requested_quality' => 'auto', 'include_text_preference' => 'auto',
                                        ]);
                                        if (empty($out['success']) || empty($out['url'])) {
                                            $__im['reply'] = 'I could not generate that image right now, so nothing was changed.';
                                        } elseif (! $__urlApproved((string) $out['url'])) {
                                            $__im['reply'] = 'The generated image is in your Media Library, but I could not safely place it, so nothing on the design changed.';
                                        } else {
                                            [$okp, $bres] = $__applySrc([[$field, (string) $out['url']]]);
                                            if ($okp) {
                                                $__im = ['success' => true, 'actions' => [], 'server_applied' => true, 'refresh_preview' => true,
                                                    'reply' => 'Generated a new image and placed it on "' . $field . '" - verified the saved result.',
                                                    'verified' => ['count' => 1, 'status' => 'applied', 'document_form' => 'raw', 'generated' => true,
                                                        'asset_id' => $out['asset_id'] ?? null, 'quality' => $out['quality'] ?? null, 'credits' => $out['credits'] ?? null]];
                                            } else {
                                                $__im['reply'] = 'I generated the image (it is in your Media Library'
                                                    . (isset($out['asset_id']) ? ', asset #' . (int) $out['asset_id'] : '')
                                                    . '), but the design changed while I was working so I did not place it. Please try again.';
                                            }
                                        }
                                    }
                                }
                            } else {
                                $pairs = []; $bad = false;
                                foreach ($__rep as $a) {
                                    $name = $a['name'] ?? null; $url = $a['url'] ?? null;
                                    if (! is_string($name) || $name === '' || ! is_string($url) || $url === '') { $bad = true; break; }
                                    if (! $__urlApproved($url)) { $bad = 'url'; break; }
                                    $pairs[] = [$name, $url];
                                }
                                if ($bad === 'url') {
                                    $__im['reply'] = 'That image URL is not from an approved source, so I did not change anything. Use a Media Library image or ask me to generate one.';
                                } elseif ($bad || count($pairs) === 0 || count($pairs) > 12) {
                                    $__im['reply'] = 'I can replace images with an approved Media Library image or a generated one.';
                                } else {
                                    [$okp, $bres] = $__applySrc($pairs);
                                    if ($okp) {
                                        $n = count($pairs);
                                        $__im = ['success' => true, 'actions' => [], 'server_applied' => true, 'refresh_preview' => true,
                                            'reply' => 'Replaced ' . $n . ' image' . ($n === 1 ? '' : 's') . ' and verified the saved result.',
                                            'verified' => ['count' => $n, 'status' => 'applied', 'document_form' => 'raw']];
                                    } else {
                                        $__im['reply'] = ($bres === 'stale')
                                            ? 'Your design changed while I was working - please try again.'
                                            : 'I could not place that image - the target may not exist or is not an image.';
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $__e) {
                        \Illuminate\Support\Facades\Log::warning('studio.chat server-apply image failed: ' . $__e->getMessage());
                    }
                    return response()->json($__im);
                }
            }

            // ══ Feature Sprint 3: server-verified brand colour application (apply_brand) ══════
            // Maps the workspace brand kit (WorkspaceBrandKitResolver) onto the design's single
            // :root palette, updating ONLY the canonical variables that already exist, verified +
            // atomic + CAS persist. WE OWN the response; never the model's reply, never a browser
            // palette mutation. Model-supplied colours are IGNORED (resolver is authoritative).
            // Neutral kits, structured designs, and non-standard-only templates decline truthfully.
            // ══ STUDIO888 Phase A1-A3: AI Image Editing (background removal / object removal / replace) ══
            // Exposes the COMMITTED edit_image capability (kernel -> Creative -> ImageEditService ->
            // gpt-image-1 /v1/images/edits, Laravel-DIRECT, NOT the Railway runtime) through chat, then
            // places the verified child asset on the design via the SAME Sprint-2 `src` projection. The
            // kernel owns credits (2cr reserve/commit/release), tenancy, idempotency and non-destructive
            // versioning - we add NO new engine / projection capability / billing. WE OWN the response:
            // a verified server apply (actions:[]) or a truthful decline. Reuses the server_apply_image
            // flag + ws scope. First increment: edits an image that is already a workspace asset (a
            // generated/library image); an on-design image with no asset row declines honestly.
            if (config('studio_chat_apply.server_apply_image') === true && ($__pilotWs === 0 || $wsId === $__pilotWs)) {
                $__edActs = is_array($actions) ? array_values(array_filter($actions, function ($a) {
                    return is_array($a) && (($a['type'] ?? null) === 'edit_image');
                })) : [];
                if (count($__edActs) > 0) {
                    if (count($__edActs) > 1) {
                        return response()->json(['success' => true, 'reply' => 'I can edit one image at a time - please ask for a single change.', 'actions' => [], 'server_applied' => false]);
                    }
                    $__ed = ['success' => true, 'reply' => 'I could not edit that image.', 'actions' => [], 'server_applied' => false];
                    $a = $__edActs[0];
                    $__field = is_string($a['name'] ?? null) ? $a['name'] : '';
                    $__op = is_string($a['operation'] ?? null) ? $a['operation'] : '';
                    $__appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
                    $__trusted = (array) config('studio_chat_apply.image_trusted_hosts', []);
                    $__sec = new \App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy();
                    $__urlApproved = function (string $u) use ($__sec, $__appHost, $__trusted): bool {
                        if (! $__sec->isSafeImageUrl($u)) return false;
                        if (str_starts_with($u, '/') && ! str_starts_with($u, '//')) return true;
                        $h = strtolower((string) parse_url($u, PHP_URL_HOST));
                        return $h !== '' && ($h === $__appHost || in_array($h, $__trusted, true));
                    };
                    try {
                        if (! $r->boolean('client_clean')) {
                            return response()->json(['success' => true, 'reply' => 'Please save your current edits first, then ask me to edit the image.', 'actions' => [], 'server_applied' => false]);
                        }
                        $__row = \Illuminate\Support\Facades\DB::table('studio_designs')
                            ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                        if (! $__row) {
                            return response()->json(['success' => true, 'reply' => 'That design could not be found.', 'actions' => [], 'server_applied' => false]);
                        }
                        $__base = (string) ($__row->content_html ?? '');
                        $__dec = json_decode($__base, true);
                        $__structured = is_array($__dec) && isset($__dec['template_slug']) && isset($__dec['fields']) && is_array($__dec['fields']);
                        if ($__structured) {
                            return response()->json(['success' => true, 'reply' => 'Image editing is not available for this template type yet, so nothing was changed.', 'actions' => [], 'server_applied' => false]);
                        }
                        $__doc = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base)->document();
                        if ($__field === '' || ! $__doc->supports($__field, 'src')) {
                            return response()->json(['success' => true, 'reply' => 'I could not find that image on the design, so nothing was changed.', 'actions' => [], 'server_applied' => false]);
                        }
                        $__curSrc = (string) $__doc->get($__field, 'src');
                        // Resolve the on-design image to a workspace asset (first increment: asset-backed only).
                        $__srcAsset = \Illuminate\Support\Facades\DB::table('assets')
                            ->where('workspace_id', $wsId)->where('type', 'image')->whereNull('deleted_at')
                            ->where('url', $__curSrc)->first();
                        if (! $__srcAsset) {
                            // ══ STUDIO888 Phase A4: Universal Image Ingest ═══════════════════════════════
                            // The on-design image is NOT already a workspace asset. When ingest is enabled,
                            // import it (safe local read for app-hosted images / SSRF-guarded download for
                            // external https), create a COMPLETED provenance-tagged ROOT asset, and edit it
                            // like any Studio asset. Reuses the Asset model, lineage, edit_image capability,
                            // src projection, credits, and Production history. NO new engine/model/provider.
                            // Dormant behind the image_ingest flag (default OFF => Phase A1-A3 decline).
                            if (config('studio_chat_apply.image_ingest') !== true) {
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                    'reply' => 'I can only edit an image that is in your library or one I generated. Generate this image first, then ask me to edit it.']);
                            }
                            try {
                                // (a) DEDUP: reuse a prior import of this exact source (no re-download, no new asset/credit).
                                $__srcAsset = \Illuminate\Support\Facades\DB::table('assets')
                                    ->where('workspace_id', $wsId)->where('type', 'image')->where('status', 'completed')->whereNull('deleted_at')
                                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.ingest_source_url')) = ?", [$__curSrc])
                                    ->orderByDesc('id')->first();
                                if (! $__srcAsset) {
                                    // (b) SAFETY (syntax): scheme/format/literal-private-IP/svg/https/port/userinfo.
                                    if (! $__sec->isSafeImageUrl($__curSrc)) {
                                        return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                            'reply' => 'That image cannot be imported safely, so nothing was changed.']);
                                    }
                                    // (c) FETCH bytes: app-hosted/relative => local read; external https => SSRF-guarded download.
                                    $__ingBytes = null; $__ingSourceType = 'external';
                                    $__isRel = str_starts_with($__curSrc, '/') && ! str_starts_with($__curSrc, '//');
                                    $__ingHost = $__isRel ? $__appHost : strtolower((string) parse_url($__curSrc, PHP_URL_HOST));
                                    if ($__isRel || $__ingHost === $__appHost) {
                                        $__ingSourceType = 'app';
                                        $__ingPath = $__isRel ? $__curSrc : (string) parse_url($__curSrc, PHP_URL_PATH);
                                        if (preg_match('#^/storage/(.+)$#', $__ingPath, $__pm)) {
                                            try { if (\Illuminate\Support\Facades\Storage::disk('public')->exists($__pm[1])) { $__ingBytes = \Illuminate\Support\Facades\Storage::disk('public')->get($__pm[1]); } } catch (\Throwable $e) {}
                                        }
                                        if ($__ingBytes === null) {
                                            $__ingReal = realpath(public_path(ltrim($__ingPath, '/'))); $__ingRoot = realpath(public_path());
                                            if ($__ingReal !== false && $__ingRoot !== false && str_starts_with($__ingReal, $__ingRoot . DIRECTORY_SEPARATOR) && is_file($__ingReal) && filesize($__ingReal) <= 15728640) {
                                                $__ingBytes = @file_get_contents($__ingReal);
                                            }
                                        }
                                        if ($__ingBytes === null) {
                                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                                'reply' => 'I could not read that image to import it, so nothing was changed.']);
                                        }
                                    } else {
                                        // external https — resolve host to IPv4(s); EVERY resolved IP must be public (anti-rebind), then pin it.
                                        $__ingIps = @gethostbynamel($__ingHost) ?: [];
                                        if (empty($__ingIps)) {
                                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                                'reply' => 'That image source could not be reached safely, so nothing was changed.']);
                                        }
                                        foreach ($__ingIps as $__ip) {
                                            if (! filter_var($__ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                                    'reply' => 'That image source is not allowed, so nothing was changed.']);
                                            }
                                        }
                                        $__ch = curl_init($__curSrc);
                                        curl_setopt_array($__ch, [
                                            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                                            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                                            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                                            CURLOPT_RESOLVE => [$__ingHost . ':443:' . $__ingIps[0]],
                                            CURLOPT_MAXFILESIZE => 15728640, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                                        ]);
                                        $__ingBytes = curl_exec($__ch);
                                        $__ingCode = (int) curl_getinfo($__ch, CURLINFO_HTTP_CODE);
                                        $__ingCtype = strtolower((string) curl_getinfo($__ch, CURLINFO_CONTENT_TYPE));
                                        curl_close($__ch);
                                        if ($__ingBytes === false || $__ingCode !== 200 || $__ingBytes === '' || strpos($__ingCtype, 'svg') !== false) {
                                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                                'reply' => 'I could not download that image, so nothing was changed.']);
                                        }
                                    }
                                    // (d) VALIDATE: size + real decodable raster + allowed MIME (never SVG).
                                    if (strlen((string) $__ingBytes) > 15728640) {
                                        return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                            'reply' => 'That image is too large to import, so nothing was changed.']);
                                    }
                                    $__ingInfo = @getimagesizefromstring((string) $__ingBytes);
                                    $__ingExtMap = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
                                    if ($__ingInfo === false || ! isset($__ingExtMap[$__ingInfo[2]])) {
                                        return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                            'reply' => 'That file is not a supported image, so nothing was changed.']);
                                    }
                                    $__ingExt = $__ingExtMap[$__ingInfo[2]]; $__ingW = (int) $__ingInfo[0]; $__ingH = (int) $__ingInfo[1];
                                    // (e) STORE bytes -> public storage (verify the write).
                                    $__ingSp = 'ai-images/' . $wsId . '/ingest-' . substr(hash('sha256', $__curSrc . '|' . strlen((string) $__ingBytes)), 0, 24) . '.' . $__ingExt;
                                    $__ingStored = \Illuminate\Support\Facades\Storage::disk('public')->put($__ingSp, (string) $__ingBytes);
                                    if (! $__ingStored || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($__ingSp)) {
                                        return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                            'reply' => 'I could not save the imported image, so nothing was changed.']);
                                    }
                                    $__ingUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($__ingSp);
                                    // (f) CREATE completed ROOT asset with provenance (imported=true, source, timestamp, ws, edit origin).
                                    $__ingId = \Illuminate\Support\Facades\DB::table('assets')->insertGetId([
                                        'workspace_id' => $wsId, 'type' => 'image', 'title' => 'Imported image',
                                        'provider' => 'Imported', 'model' => 'Imported', 'status' => 'completed',
                                        'url' => $__ingUrl, 'storage_path' => $__ingSp, 'mime_type' => image_type_to_mime_type($__ingInfo[2]),
                                        'width' => $__ingW, 'height' => $__ingH, 'version' => 1, 'edit_mode' => 'import',
                                        'metadata_json' => json_encode(['imported' => true, 'ingest_source_url' => $__curSrc,
                                            'ingest_source_type' => $__ingSourceType, 'imported_at' => now()->toIso8601String(),
                                            'edit_origin' => 'studio_chat_ingest', 'workspace_id' => $wsId]),
                                        'tags_json' => json_encode(['imported']), 'created_at' => now(), 'updated_at' => now(),
                                    ]);
                                    \Illuminate\Support\Facades\DB::table('assets')->where('id', $__ingId)->update(['root_asset_id' => $__ingId]);
                                    $__srcAsset = \Illuminate\Support\Facades\DB::table('assets')->where('id', $__ingId)->first();
                                }
                            } catch (\Throwable $__ie) {
                                \Illuminate\Support\Facades\Log::warning('studio.chat image ingest failed: ' . $__ie->getMessage());
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                    'reply' => 'I could not import that image, so nothing was changed.']);
                            }
                            if (! $__srcAsset) {
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                    'reply' => 'I could not import that image, so nothing was changed.']);
                            }
                        }
                        // Build the provider prompt from the operation (server-controlled, never the raw LLM string).
                        $__prompt = null; $__done = null;
                        if ($__op === 'remove_background') {
                            $__bg = strtolower(trim((string) ($a['background'] ?? 'white')));
                            if ($__bg === 'transparent') {
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                    'reply' => 'Transparent PNGs are not available yet - I can place the subject on a clean white or studio background instead.']);
                            }
                            $__prompt = ($__bg === 'studio')
                                ? 'Remove the background entirely and place the main subject on a smooth, neutral studio backdrop with soft even lighting. Keep the subject itself unchanged.'
                                : 'Remove the background entirely and place the main subject on a clean, solid pure-white background. Keep the subject itself unchanged.';
                            $__done = 'Removed the background';
                        } elseif ($__op === 'remove_object') {
                            $__tgt = preg_replace('/^(the|a|an)\s+/i', '', trim((string) ($a['target'] ?? '')));
                            if ($__tgt === '') {
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false, 'reply' => 'Tell me which object to remove.']);
                            }
                            $__prompt = 'Remove the ' . $__tgt . ' from the image completely, filling the space naturally and seamlessly so it looks like it was never there, matching the surrounding scene, lighting and perspective.';
                            $__done = 'Removed the ' . $__tgt;
                        } elseif ($__op === 'replace_object') {
                            $__tgt = preg_replace('/^(the|a|an)\s+/i', '', trim((string) ($a['target'] ?? '')));
                            $__rep = preg_replace('/^(a|an)\s+/i', '', trim((string) ($a['replacement'] ?? '')));
                            if ($__tgt === '' || $__rep === '') {
                                return response()->json(['success' => true, 'actions' => [], 'server_applied' => false, 'reply' => 'Tell me what to replace and what to replace it with.']);
                            }
                            $__prompt = 'Replace the ' . $__tgt . ' in the image with ' . $__rep . ', matching the scene lighting, perspective, scale and style so it looks natural.';
                            $__done = 'Replaced the ' . $__tgt . ' with ' . $__rep;
                        } else {
                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                'reply' => 'I can remove the background, remove an object, or replace an object right now.']);
                        }
                        // Invoke the governed edit_image capability (credits/tenancy/persistence/versioning owned by the kernel).
                        $__res = app(\App\Core\EngineKernel\EngineExecutionService::class)->execute(
                            $wsId, 'creative', 'edit_image',
                            ['source_asset_id' => (int) $__srcAsset->id, 'prompt' => $__prompt, 'selection_type' => 'full',
                             'idempotency_key' => 'stchat-edit-' . $designId . '-' . $__field . '-' . substr(md5($__prompt), 0, 12)],
                            ['user_id' => optional($r->user())->id, 'source' => 'studio_chat']
                        );
                        $__data = (is_array($__res) && isset($__res['data']) && is_array($__res['data'])) ? $__res['data'] : (is_array($__res) ? $__res : []);
                        $__ok = (bool) ($__res['success'] ?? ($__data['success'] ?? false));
                        $__newUrl = (string) ($__data['url'] ?? '');
                        $__newAsset = $__data['asset_id'] ?? ($__data['id'] ?? null);
                        if (! $__ok || $__newUrl === '') {
                            $__msg = (string) ($__res['error'] ?? ($__data['error'] ?? ''));
                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                'reply' => $__msg !== '' ? $__msg : 'I could not edit that image right now, so nothing was changed.']);
                        }
                        if (! $__urlApproved($__newUrl)) {
                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                'reply' => 'The edited image was saved to your Media Library, but I could not safely place it, so nothing on the design changed.']);
                        }
                        // Place the edited child asset on the field via the committed `src` projection (verified + CAS).
                        // Re-read the row (the edit took time) as the concurrency base.
                        $__row2 = \Illuminate\Support\Facades\DB::table('studio_designs')
                            ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                        if (! $__row2) {
                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                'reply' => 'I edited the image (it is in your Media Library) but the design was no longer available, so I did not place it.']);
                        }
                        $__base2 = (string) ($__row2->content_html ?? '');
                        $__ad2 = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base2);
                        if (! $__ad2->document()->supports($__field, 'src')) {
                            return response()->json(['success' => true, 'actions' => [], 'server_applied' => false,
                                'reply' => 'I edited the image (it is in your Media Library) but the design changed while I was working, so I did not place it. Please try again.']);
                        }
                        $__req = \App\Engines\Studio\Projection\ProjectionRequest::fromArray([
                            'schema_version' => 1, 'operation_id' => 'chat-edit-img', 'document_id' => (string) $designId,
                            'target_id' => (string) $__field, 'changed_fields' => ['src'], 'desired_after_state' => ['src' => $__newUrl],
                            'expected_document_version' => null, 'before_snapshot_hash' => null,
                            'correlation_id' => 'chat-edit-' . $designId, 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
                        ]);
                        $__bres = $__ad2->projectBatch(new \App\Engines\Studio\Projection\ProjectionBatch(
                            'chat-edit-' . $designId, (string) $designId, [$__req],
                            \App\Engines\Studio\Projection\ProjectionTransactionBoundary::atomic(), null, 'chat-edit-' . $designId));
                        $__pok = ($__bres->status === \App\Engines\Studio\Projection\ProjectionStatus::APPLIED) && ($__bres->appliedCount() === 1);
                        if ($__pok) { foreach ($__bres->results as $__rr) { if ((($__rr->verification['verified'] ?? false) !== true)) { $__pok = false; break; } } }
                        if ($__pok) {
                            $__aff = \Illuminate\Support\Facades\DB::table('studio_designs')
                                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->where('updated_at', $__row2->updated_at)
                                ->update(['content_html' => (string) $__ad2->payload(), 'updated_at' => now()]);
                            if ($__aff === 1) {
                                register_shutdown_function(function () use ($designId) {
                                    try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int) $designId); } catch (\Throwable $e) {}
                                });
                                $__ed = ['success' => true, 'actions' => [], 'server_applied' => true, 'refresh_preview' => true,
                                    'reply' => $__done . ' and updated "' . $__field . '" - verified the saved result.',
                                    'verified' => ['count' => 1, 'status' => 'applied', 'document_form' => 'raw', 'edited' => true,
                                        'operation' => $__op, 'asset_id' => $__newAsset]];
                            } else {
                                $__ed['reply'] = 'I edited the image (it is in your Media Library) but the design changed while I was working, so I did not place it. Please try again.';
                            }
                        } else {
                            $__ed['reply'] = 'I edited the image but could not place it on the design safely, so nothing on the design changed.';
                        }
                    } catch (\Throwable $__e) {
                        \Illuminate\Support\Facades\Log::warning('studio.chat server-apply image-edit failed: ' . $__e->getMessage());
                        $__ed = ['success' => true, 'reply' => 'I could not edit that image, so nothing was changed.', 'actions' => [], 'server_applied' => false];
                    }
                    return response()->json($__ed);
                }
            }

            if (config('studio_chat_apply.server_apply_brand') === true && ($__pilotWs === 0 || $wsId === $__pilotWs)) {
                $__hasBrand = is_array($actions) && count(array_filter($actions, function ($a) {
                    return is_array($a) && (($a['type'] ?? null) === 'apply_brand');
                })) > 0;
                if ($__hasBrand) {
                    $__bm = ['success' => true, 'reply' => 'I could not apply your brand colours.', 'actions' => [], 'server_applied' => false];
                    $__kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
                    if (! empty($__kit['is_neutral'])) {
                        $__bm['reply'] = 'Your workspace does not have a configured brand palette yet, so nothing was changed. Set up your brand colours first.';
                    } elseif (! $r->boolean('client_clean')) {
                        $__bm['reply'] = 'Please save your current edits first, then ask me to apply your brand colours.';
                    } else {
                        try {
                            $__row = \Illuminate\Support\Facades\DB::table('studio_designs')
                                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                            if (! $__row) {
                                $__bm['reply'] = 'That design could not be found.';
                            } else {
                                $__base = (string) ($__row->content_html ?? '');
                                $__dec = json_decode($__base, true);
                                $__structured = is_array($__dec) && isset($__dec['template_slug']) && isset($__dec['fields']) && is_array($__dec['fields']);
                                if ($__structured) {
                                    $__bm['reply'] = 'Brand colours are not available for this template type yet, so nothing was changed.';
                                } elseif (preg_match_all('/:root\s*\{/', $__base) !== 1) {
                                    $__bm['reply'] = 'This design has no single colour palette I can update, so nothing was changed.';
                                } else {
                                    $__norm = new \App\Engines\Studio\Transform\ColorNormalizer();
                                    $__inner = '';
                                    if (preg_match('/:root\s*\{([^{}]*)\}/', $__base, $__rm)) { $__inner = $__rm[1]; }
                                    $__map = [
                                        '--primary' => $__kit['primary_color'] ?? null, '--secondary' => $__kit['secondary_color'] ?? null,
                                        '--accent' => $__kit['accent_color'] ?? null, '--background' => $__kit['background_color'] ?? null,
                                        '--text' => $__kit['text_color'] ?? null,
                                    ];
                                    $__adapter = \App\Engines\Studio\Projection\Html\HtmlProjectionAdapter::forRawHtml($__base);
                                    $__reqs = []; $__changed = []; $__anyCanonical = false;
                                    foreach ($__map as $__var => $__col) {
                                        if (! preg_match('/(?:^|;|\s)' . preg_quote($__var, '/') . '\s*:/', $__inner)) { continue; }
                                        $__anyCanonical = true;
                                        if (! is_string($__col) || $__col === '') { continue; }
                                        $__nv = $__norm->normalize($__col);
                                        if ($__nv === null) { continue; }
                                        $__cur = $__adapter->document()->get(':root', 'var.' . $__var);
                                        $__curn = is_string($__cur) ? $__norm->normalize($__cur) : null;
                                        if ($__curn !== null && strtolower($__curn) === strtolower($__nv)) { continue; }
                                        $__reqs[] = \App\Engines\Studio\Projection\ProjectionRequest::fromArray([
                                            'schema_version' => 1, 'operation_id' => 'chat-brand-' . ltrim($__var, '-'), 'document_id' => (string) $designId,
                                            'target_id' => ':root', 'changed_fields' => ['var.' . $__var], 'desired_after_state' => ['var.' . $__var => $__nv],
                                            'expected_document_version' => null, 'before_snapshot_hash' => null,
                                            'correlation_id' => 'chat-brand-' . $designId, 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
                                        ]);
                                        $__changed[] = ltrim($__var, '-');
                                    }
                                    if (empty($__reqs)) {
                                        $__bm['reply'] = $__anyCanonical
                                            ? 'This design already uses your brand colours - nothing to change.'
                                            : 'This design uses custom colour variables I cannot map to your brand yet, so nothing was changed.';
                                    } else {
                                        $__batch = new \App\Engines\Studio\Projection\ProjectionBatch('chat-' . $designId, (string) $designId, $__reqs,
                                            \App\Engines\Studio\Projection\ProjectionTransactionBoundary::atomic(), null, 'chat-brand-' . $designId);
                                        $__bres = $__adapter->projectBatch($__batch);
                                        $__ok = ($__bres->status === \App\Engines\Studio\Projection\ProjectionStatus::APPLIED) && ($__bres->appliedCount() === count($__reqs));
                                        if ($__ok) { foreach ($__bres->results as $__rr) { if ((($__rr->verification['verified'] ?? false) !== true)) { $__ok = false; break; } } }
                                        if ($__ok) {
                                            $__aff = \Illuminate\Support\Facades\DB::table('studio_designs')
                                                ->where('id', $designId)->where('workspace_id', $wsId)->whereNull('deleted_at')
                                                ->where('updated_at', $__row->updated_at)
                                                ->update(['content_html' => (string) $__adapter->payload(), 'updated_at' => now()]);
                                            if ($__aff === 1) {
                                                register_shutdown_function(function () use ($designId) {
                                                    try { app(\App\Engines\Studio\Services\StudioService::class)->generateThumbnail((int) $designId); } catch (\Throwable $e) {}
                                                });
                                                $__n = count($__changed);
                                                $__bm = ['success' => true, 'actions' => [], 'server_applied' => true, 'refresh_preview' => true,
                                                    'reply' => 'Applied and verified your brand ' . implode(', ', $__changed) . ' colour' . ($__n === 1 ? '' : 's') . '.',
                                                    'verified' => ['count' => $__n, 'status' => 'applied', 'document_form' => 'raw', 'variables' => $__changed]];
                                            } else {
                                                $__bm['reply'] = 'Your design changed while I was working - please try again.';
                                            }
                                        } else {
                                            $__bm['reply'] = 'I could not apply your brand colours to this design.';
                                        }
                                    }
                                }
                            }
                        } catch (\Throwable $__e) {
                            \Illuminate\Support\Facades\Log::warning('studio.chat server-apply brand failed: ' . $__e->getMessage());
                        }
                    }
                    return response()->json($__bm);
                }
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

        // ══ STUDIO888 Production Workflow Phase 1 — read-only Production log (job queue / history / lineage) ══
        // Surfaces the EXISTING creative_jobs + assets state as a production log. Answers "what happened?"
        // truthfully from committed data: every image generate/edit already writes a creative_jobs row
        // (ImageIntelligenceService + the kernel CreativeJob hook) and every edit already records asset
        // lineage (parent/root/version/edit_mode). READ-ONLY. Reuses creative_jobs + assets - NO new
        // table, engine, registry, orchestration, or mutation. Progress phase is derived STRICTLY from the
        // stored status (never faked). Lineage detail reuses the existing GET /assets/{id}/versions.
        Route::get('/production/jobs', function (\Illuminate\Http\Request $r) {
            // Dormant until authorized: default-OFF flag keeps this inert on production.
            if (config('studio_chat_apply.production_log') !== true) { abort(404); }
            $wsId  = (int) $r->attributes->get('workspace_id');
            $limit = min(100, max(1, (int) $r->query('limit', 50)));
            $q = \Illuminate\Support\Facades\DB::table('creative_jobs')->where('workspace_id', $wsId);
            if ($r->filled('capability')) { $q->where('capability', (string) $r->query('capability')); }
            if ($r->filled('status'))     { $q->where('status', (string) $r->query('status')); }
            $rows = $q->orderByDesc('id')->limit($limit)->get();

            $afterIds = [];
            foreach ($rows as $row) { if ($row->asset_id) { $afterIds[(int) $row->asset_id] = 1; } }
            $after = \Illuminate\Support\Facades\DB::table('assets')->where('workspace_id', $wsId)
                ->whereIn('id', array_keys($afterIds) ?: [0])->get()->keyBy('id');
            $beforeIds = [];
            foreach ($after as $a) { if ($a->parent_asset_id) { $beforeIds[(int) $a->parent_asset_id] = 1; } }
            $before = \Illuminate\Support\Facades\DB::table('assets')->where('workspace_id', $wsId)
                ->whereIn('id', array_keys($beforeIds) ?: [0])->get()->keyBy('id');

            // Truthful mapping from the STORED status only - no fabricated intermediate progress.
            $queueOf = function (?string $s): string {
                return match ($s) {
                    'completed' => 'Completed',
                    'failed'    => 'Failed',
                    'cancelled', 'canceled' => 'Cancelled',
                    'running', 'processing', 'in_progress' => 'Running',
                    default => 'Queued',
                };
            };
            $phaseOf = function (?string $s): string {
                return match ($s) {
                    'completed' => 'Completed',
                    'failed'    => 'Failed',
                    'cancelled', 'canceled' => 'Cancelled',
                    'running', 'processing', 'in_progress' => 'Executing',
                    'queued', 'pending', null, '' => 'Queued',
                    default => ucfirst((string) $s),
                };
            };

            $jobs = [];
            foreach ($rows as $row) {
                $a = $row->asset_id ? ($after[(int) $row->asset_id] ?? null) : null;
                $b = ($a && $a->parent_asset_id) ? ($before[(int) $a->parent_asset_id] ?? null) : null;
                $meta = json_decode((string) ($row->metadata ?? ''), true) ?: [];
                $jobs[] = [
                    'id'            => (int) $row->id,
                    'uuid'          => $row->uuid,
                    'capability'    => $row->capability,
                    'type'          => $row->type,
                    'status'        => $row->status,
                    'queue'         => $queueOf($row->status),
                    'phase'         => $phaseOf($row->status),
                    'provider'      => $row->provider,
                    'model'         => $row->provider_model,
                    'asset_id'      => $a ? (int) $a->id : null,
                    'after_url'     => $a->url ?? null,
                    'before_url'    => $b->url ?? null,
                    'version'       => $a->version ?? null,
                    'edit_mode'     => $a->edit_mode ?? null,
                    'root_asset_id' => $a && $a->root_asset_id ? (int) $a->root_asset_id : null,
                    'prompt'        => $row->original_prompt,
                    'error'         => $row->status === 'failed' ? (string) ($meta['error'] ?? $meta['reason'] ?? 'failed') : null,
                    'created_at'    => $row->created_at,
                    'started_at'    => $row->started_at,
                    'completed_at'  => $row->completed_at,
                    'failed_at'     => $row->failed_at,
                ];
            }
            return response()->json(['success' => true, 'count' => count($jobs), 'jobs' => $jobs]);
        });

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
