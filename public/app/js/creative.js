/**
 * CREATIVE888 — Creative Engine SPA v1.0.0
 * Mounts into #creative-root when nav('creative') is called.
 *
 * Views: dashboard | generate-image | generate-video | library | brand-kits | presets | settings
 * Integration hooks: window.luCreativePickAsset(cb) — used by Builder, Social, Marketing
 */

// ── Claim engine slot ──────────────────────────────────────────────────────────
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['creative'] = true;
console.log('[LuCreative] v1.0.0 — engine slot claimed');

// ═══════════════════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════════════════

var _cr = {
    view:       'dashboard',
    assets:     [],
    jobs:       [],
    brandKits:  [],
    presets:    { channel: [], industry: [] },
    settings:   {},
    dashStats:  { images: 0, videos: 0, credits: 0.0, recent: [] },
    generating: false,
    pickMode:   false,
    pickCb:     null,
    filterType:  '',
    filterPreset:'',
    searchQ:     '',
    rootEl:      null,
};

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function _crUrl(p) {
    var b = (window.LU_API_BASE || '/api');
    return b + '/api/creative' + p;
}
function _crNonce() { return (window.LU_CFG && '') || ''; }
function _crHeaders() { return { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}; }
function _e(s) { return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;'); }
function _fmtDate(dt) {
    if (!dt) return '—';
    var d = new Date((dt+'').replace(' ','T'));
    return isNaN(d.getTime()) ? dt : d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function _fmtCost(v) { return '$' + (parseFloat(v)||0).toFixed(4); }

async function _crGet(path) {
    var res = await fetch(_crUrl(path), { method:'GET', headers: _crHeaders() });
    var body; try { body = await res.json(); } catch(e) { body = {}; }
    if (!res.ok) throw new Error((body && body.message) || 'HTTP ' + res.status);
    return body;
}
async function _crPost(path, data) {
    var res = await fetch(_crUrl(path), { method:'POST', headers: _crHeaders(), body: JSON.stringify(data) });
    var body; try { body = await res.json(); } catch(e) { body = {}; }
    if (!res.ok) throw new Error((body && body.message) || 'HTTP ' + res.status);
    return body;
}
async function _crPut(path, data) {
    var res = await fetch(_crUrl(path), { method:'PUT', headers: _crHeaders(), body: JSON.stringify(data) });
    var body; try { body = await res.json(); } catch(e) { body = {}; }
    if (!res.ok) throw new Error((body && body.message) || 'HTTP ' + res.status);
    return body;
}
async function _crDelete(path) {
    var res = await fetch(_crUrl(path), { method:'DELETE', headers: _crHeaders() });
    var body; try { body = await res.json(); } catch(e) { body = {}; }
    if (!res.ok) throw new Error((body && body.message) || 'HTTP ' + res.status);
    return body;
}

// ── Toast notification ─────────────────────────────────────────────────────────
function _crToast(msg, type) {
    type = type || 'info';
    var el = document.getElementById('cr-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'cr-toast';
        el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(el);
    }
    var colors = { success:'var(--ac)', error:'var(--rd)', info:'var(--p)', warning:'var(--am)' };
    var t = document.createElement('div');
    t.style.cssText = 'background:var(--s2);border:1px solid ' + (colors[type]||colors.info) + ';border-radius:10px;padding:10px 16px;color:var(--t1);font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.4);max-width:320px;animation:crSlideIn .2s ease;';
    t.textContent = msg;
    el.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 3500);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ENTRY POINT — called by Core nav('creative')
// ═══════════════════════════════════════════════════════════════════════════════

window.creativeLoad = async function(el) {
  // M5 Batch 1: mobile preview-only gate
  if (window.innerWidth < 768) {
    el.innerHTML =
      '<div class="lu-engine-panel" style="width:100%">' +
        '<div class="lu-desktop-gate">' +
          '<h3>Creative Engine</h3>' +
          '<p>Full creative tools are available on desktop. ' +
          'View and download existing assets here, edit on desktop.</p>' +
        '</div>' +
      '</div>';
    return;
  }

    if (!el) { console.error('[LuCreative] creativeLoad: no element'); return; }
    _cr.rootEl = el;
    console.log('[LuCreative] creativeLoad() → #' + el.id);
    el.innerHTML = _crLoadingHTML();
    try {
        await _crBootstrap();
        _crRender();
    } catch(e) {
        el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--rd)">Creative Engine failed to load: ' + _e(e.message) + '</div>';
        console.error('[LuCreative]', e);
    }
};

async function _crBootstrap() {
    var [presetsData, settingsData, assetsData, brandKitsData] = await Promise.all([
        _crGet('/presets').catch(function(){return {presets:[]}}),
        _crGet('/settings').catch(function(){return {settings:{}}}),
        _crGet('/assets?limit=20').catch(function(){return {assets:[]}}),
        _crGet('/brand-kits').catch(function(){return {brand_kits:[]}}),
    ]);

    var allPresets = presetsData.presets || [];
    _cr.presets.channel  = allPresets.filter(function(p){ return p.type === 'channel'; });
    _cr.presets.industry = allPresets.filter(function(p){ return p.type === 'industry'; });
    _cr.settings  = settingsData.settings || {};
    _cr.assets    = assetsData.assets || [];
    _cr.brandKits = brandKitsData.brand_kits || [];

    // Compute dashboard stats from asset list
    _cr.dashStats.images  = _cr.assets.filter(function(a){ return a.type==='image'; }).length;
    _cr.dashStats.recent  = _cr.assets.slice(0, 8);
    _cr.dashStats.credits = _cr.assets.reduce(function(sum,a){ return sum + parseFloat(a.credit_cost||0); }, 0);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER ORCHESTRATOR
// ═══════════════════════════════════════════════════════════════════════════════

function _crRender() {
    if (!_cr.rootEl) return;
    try {
        _cr.rootEl.innerHTML = _crShell();
    } catch(shellErr) {
        console.error('[LuCreative] Shell render failed:', shellErr);
        _cr.rootEl.innerHTML = '<div style="padding:40px;color:#f87171">Creative Engine UI failed to initialize. Please reload.</div>';
        return;
    }
    var content = document.getElementById('cr-content');
    if (!content) return;
    try {
    switch (_cr.view) {
        case 'generate-image': content.innerHTML = _crViewGenerateImage(); _crBindGenerate(); break;
        case 'generate-video': content.innerHTML = _crViewGenerateVideo(); _crBindVideo(); break;
        case 'library':        content.innerHTML = _crViewLibrary(); _crBindLibrary(); break;
        case 'brand-kits':     content.innerHTML = _crViewBrandKits(); _crBindBrandKits(); break;
        case 'presets':        content.innerHTML = _crViewPresets(); break;
        case 'settings':       content.innerHTML = _crViewSettings(); _crBindSettings(); break;
        default:               content.innerHTML = _crViewDashboard(); _crBindDashboard(); break;
    }
    } catch(viewErr) {
        console.error('[LuCreative] View render error (' + _cr.view + '):', viewErr);
        if (content) content.innerHTML = '<div style="padding:32px;color:#f87171;text-align:center">'  
            + ''+window.icon("warning",14)+' This view failed to render. Try a different section or reload the page.<br><small style="opacity:.5">' 
            + viewErr.message + '</small></div>';
    }

}

// ── Shell (persistent header + sub-nav) ───────────────────────────────────────
function _crShell() {
    var navItems = [
        { id:'dashboard',      icon:''+window.icon("ai",14)+'', label:'Dashboard' },
        { id:'generate-image', icon:''+window.icon("ai",14)+'', label:'Generate Image' },
        { id:'generate-video', icon:''+window.icon("video",14)+'', label:'Generate Video' },
        { id:'library',        icon:''+window.icon("more",14)+'', label:'Media Library' },
        { id:'brand-kits',     icon:''+window.icon("tag",14)+'', label:'Brand Kits' },
        { id:'presets',        icon:''+window.icon("ai",14)+'', label:'Presets' },
        { id:'settings',       icon:''+window.icon("edit",14)+'', label:'Settings' },
    ];
    var navHTML = navItems.map(function(n) {
        var active = _cr.view === n.id ? 'cr-tab-active' : '';
        return '<button class="cr-tab ' + active + '" onclick="_crNav(\'' + n.id + '\')">' + n.icon + ' ' + n.label + '</button>';
    }).join('');

    return '<style>' + _crCSS() + '</style>' +
        '<div class="cr-wrap">' +
            '<div class="cr-header">' +
                '<div class="cr-header-left">' +
                    '<div class="cr-logo">'+window.icon("ai",14)+'</div>' +
                    '<div><div class="cr-title">Creative Engine</div><div class="cr-sub">AI Image & Video Generation</div></div>' +
                '</div>' +
                '<div class="cr-header-right">' +
                    '<button class="cr-btn cr-btn-primary" onclick="_crNav(\'generate-image\')">'+window.icon("ai",14)+' Generate Image</button>' +
                '</div>' +
            '</div>' +
            '<div class="cr-nav">' + navHTML + '</div>' +
            '<div id="cr-content" class="cr-content"></div>' +
        '</div>';
}

function _crNav(view) {
    _cr.view = view;
    _crRender();
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewDashboard() {
    var s = _cr.dashStats;
    var recentHTML = s.recent.length ? s.recent.map(function(a) {
        return '<div class="cr-thumb-card" onclick="_crOpenAsset(' + a.id + ')">' +
            '<img src="' + _e(a.thumbnail_url || a.public_url) + '" alt="asset" loading="lazy" onerror="this.style.display=\'none\'">' +
            '<div class="cr-thumb-meta">' + (a.channel_preset || a.type) + '</div>' +
        '</div>';
    }).join('') : '<p style="color:var(--t3);grid-column:1/-1">No assets yet — generate your first image!</p>';

    return '<div class="cr-dash">' +
        '<div class="cr-stat-row">' +
            _crStatCard('Images Generated', s.images, ''+window.icon("image",14)+'', 'var(--p)') +
            _crStatCard('Videos Generated', s.videos||0, ''+window.icon("video",14)+'', 'var(--ac)') +
            _crStatCard('Total Spend', _fmtCost(s.credits), ''+window.icon("tag",14)+'', 'var(--am)') +
            _crStatCard('Brand Kits', _cr.brandKits.length, ''+window.icon("tag",14)+'', 'var(--bl)') +
        '</div>' +
        '<div class="cr-section-header">' +
            '<h3>Quick Actions</h3>' +
        '</div>' +
        '<div class="cr-quick-actions">' +
            '<button class="cr-qa-btn" onclick="_crNav(\'generate-image\')">'+window.icon("ai",14)+'<span>Generate Image</span></button>' +
            '<button class="cr-qa-btn" onclick="_crNav(\'generate-video\')">'+window.icon("video",14)+'<span>Generate Video</span></button>' +
            '<button class="cr-qa-btn" onclick="_crNav(\'library\')">'+window.icon("more",14)+'<span>Media Library</span></button>' +
            '<button class="cr-qa-btn" onclick="_crNav(\'brand-kits\')">'+window.icon("tag",14)+'<span>Create Brand Kit</span></button>' +
            '<button class="cr-qa-btn" onclick="_crScanWebsite()">'+window.icon("globe",14)+'<span>Scan Website</span></button>' +
        '</div>' +
        '<div class="cr-section-header"><h3>Recent Assets</h3><button class="cr-link" onclick="_crNav(\'library\')">View all →</button></div>' +
        '<div class="cr-thumb-grid">' + recentHTML + '</div>' +
    '</div>';
}

function _crStatCard(label, val, icon, color) {
    return '<div class="cr-stat-card">' +
        '<div class="cr-stat-icon" style="background:' + color + '22;color:' + color + '">' + icon + '</div>' +
        '<div class="cr-stat-val">' + _e(String(val)) + '</div>' +
        '<div class="cr-stat-label">' + _e(label) + '</div>' +
    '</div>';
}

function _crBindDashboard() {}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: GENERATE IMAGE
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewGenerateImage() {
    var channelOpts = '<option value="">None</option>' + _cr.presets.channel.map(function(p) {
        return '<option value="' + _e(p.slug) + '">' + _e(p.name) + '</option>';
    }).join('');
    var industryOpts = '<option value="">None</option>' + _cr.presets.industry.map(function(p) {
        return '<option value="' + _e(p.slug) + '">' + _e(p.name) + '</option>';
    }).join('');
    var kitOpts = '<option value="">None</option>' + _cr.brandKits.map(function(k) {
        return '<option value="' + _e(k.id) + '">' + _e(k.name) + '</option>';
    }).join('');

    return '<div class="cr-gen-layout">' +

        // Left panel — controls
        '<div class="cr-gen-left">' +
            '<div class="cr-card">' +
                '<div class="cr-card-title">Prompt</div>' +
                '<div class="cr-field-group">' +
                    '<div class="cr-mode-tabs">' +
                        '<button class="cr-mode-tab cr-mode-active" id="cr-mode-simple" onclick="_crSetMode(\'simple\')">Simple</button>' +
                        '<button class="cr-mode-tab" id="cr-mode-advanced" onclick="_crSetMode(\'advanced\')">Advanced</button>' +
                        '<button class="cr-mode-tab" id="cr-mode-task" onclick="_crSetMode(\'task\')">Task</button>' +
                    '</div>' +
                    '<textarea id="cr-prompt" class="cr-textarea" placeholder="Describe the image you want to generate..." rows="4"></textarea>' +
                '</div>' +
                '<div id="cr-advanced-fields" style="display:none">' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Negative Prompt (what to avoid)</label>' +
                        '<input id="cr-neg-prompt" class="cr-input" type="text" placeholder="blurry, low quality, watermark...">' +
                    '</div>' +
                '</div>' +
                '<div id="cr-task-fields" style="display:none">' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Task</label>' +
                        '<select id="cr-task-type" class="cr-select" onchange="_crApplyTaskPreset()">' +
                            '<option value="">Select task...</option>' +
                            '<option value="website-hero">Website Hero Image</option>' +
                            '<option value="social-square">Social Media Post</option>' +
                            '<option value="email-banner">Email Banner</option>' +
                            '<option value="blog-featured">Blog Featured Image</option>' +
                            '<option value="ad-square">Ad Creative</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            '<div class="cr-card">' +
                '<div class="cr-card-title">Generation Settings</div>' +
                '<div class="cr-field-row">' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Aspect Ratio</label>' +
                        '<select id="cr-aspect" class="cr-select">' +
                            '<option value="1:1">Square (1:1)</option>' +
                            '<option value="16:9">Landscape (16:9)</option>' +
                            '<option value="9:16">Portrait (9:16)</option>' +
                            '<option value="4:5">Portrait (4:5)</option>' +
                            '<option value="1.91:1">LinkedIn (1.91:1)</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Quality</label>' +
                        '<select id="cr-quality" class="cr-select">' +
                            '<option value="standard">Standard</option>' +
                            '<option value="hd">HD (2×)</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
                '<div class="cr-field-row">' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Variations</label>' +
                        '<select id="cr-count" class="cr-select">' +
                            '<option value="1">1 image</option>' +
                            '<option value="2">2 images</option>' +
                            '<option value="3">3 images</option>' +
                            '<option value="4">4 images</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="cr-field-group">' +
                        '<label class="cr-label">Style</label>' +
                        '<select id="cr-style" class="cr-select">' +
                            '<option value="vivid">Vivid</option>' +
                            '<option value="natural">Natural</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            '<div class="cr-card">' +
                '<div class="cr-card-title">Presets & Branding</div>' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Channel Preset</label>' +
                    '<select id="cr-channel-preset" class="cr-select">' + channelOpts + '</select>' +
                '</div>' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Industry Preset</label>' +
                    '<select id="cr-industry-preset" class="cr-select">' + industryOpts + '</select>' +
                '</div>' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Brand Kit</label>' +
                    '<select id="cr-brand-kit" class="cr-select">' + kitOpts + '</select>' +
                '</div>' +
            '</div>' +

            '<div id="cr-cost-preview" class="cr-cost-preview"></div>' +

            '<div class="cr-gen-actions">' +
                '<button id="cr-gen-btn" class="cr-btn cr-btn-primary cr-btn-lg" onclick="_crGenerate()">'+window.icon("ai",14)+' Generate</button>' +
            '</div>' +
        '</div>' +

        // Right panel — output grid
        '<div class="cr-gen-right">' +
            '<div id="cr-gen-output" class="cr-gen-output">' +
                '<div class="cr-gen-empty">Your generated images will appear here.</div>' +
            '</div>' +
        '</div>' +

    '</div>';
}

function _crSetMode(mode) {
    document.querySelectorAll('.cr-mode-tab').forEach(function(b){ b.classList.remove('cr-mode-active'); });
    var tab = document.getElementById('cr-mode-' + mode);
    if (tab) tab.classList.add('cr-mode-active');
    var adv  = document.getElementById('cr-advanced-fields');
    var task = document.getElementById('cr-task-fields');
    if (adv)  adv.style.display  = mode === 'advanced' ? 'block' : 'none';
    if (task) task.style.display = mode === 'task'     ? 'block' : 'none';
}

function _crApplyTaskPreset() {
    var sel = document.getElementById('cr-task-type');
    if (!sel) return;
    var presetSlug  = sel.value;
    var presetEl    = document.getElementById('cr-channel-preset');
    var aspectEl    = document.getElementById('cr-aspect');
    if (!presetSlug) return;
    if (presetEl) presetEl.value = presetSlug;
    // Map to aspect ratio
    var aspectMap = {
        'website-hero': '16:9', 'social-square': '1:1', 'email-banner': '16:9',
        'blog-featured': '16:9', 'ad-square': '1:1', 'story-format': '9:16',
        'social-portrait': '4:5', 'linkedin-post': '1.91:1'
    };
    if (aspectEl && aspectMap[presetSlug]) aspectEl.value = aspectMap[presetSlug];
}

function _crBindGenerate() {
    // Live cost preview
    var selQuality = document.getElementById('cr-quality');
    var selAspect  = document.getElementById('cr-aspect');
    var selCount   = document.getElementById('cr-count');
    function _updateCost() {
        var creditCostMap = { 'standard': 10, 'hd': 20 };
        var q = selQuality ? selQuality.value : 'standard';
        var a = selAspect  ? selAspect.value  : '1:1';
        var n = selCount   ? parseInt(selCount.value) || 1 : 1;
        var unitCredits = creditCostMap[q] || 10;
        var totalCredits = unitCredits * n;
        var el = document.getElementById('cr-cost-preview');
        if (el) el.innerHTML = '<span class="cr-cost-chip">✦ <strong>' + totalCredits + ' credits</strong>' + (n > 1 ? ' (' + n + '×' + unitCredits + ')' : '') + '<span id="cr-balance-hint" style="margin-left:8px;opacity:.6;font-size:10px"></span></span>';
        // Load live balance hint
        _crGet('/credits/balance').then(function(r) {
            var hint = document.getElementById('cr-balance-hint');
            if (hint && r.balance !== undefined) {
                var affordable = r.balance >= totalCredits;
                hint.textContent = affordable ? '(' + r.balance.toFixed(0) + ' available)' : ''+window.icon("warning",14)+' only ' + r.balance.toFixed(0) + ' available';
                hint.style.color = affordable ? 'var(--t3)' : 'var(--rd)';
            }
        }).catch(function(){});
    }
    if (selQuality) selQuality.addEventListener('change', _updateCost);
    if (selAspect)  selAspect.addEventListener('change',  _updateCost);
    if (selCount)   selCount.addEventListener('change',   _updateCost);
    _updateCost();
}

async function _crGenerate() {
    if (_cr.generating) return;
    var prompt = (document.getElementById('cr-prompt') || {}).value || '';
    if (!prompt.trim()) { _crToast('Please enter a prompt', 'warning'); return; }

    _cr.generating = true;
    var btn = document.getElementById('cr-gen-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Generating…'; }
    var out = document.getElementById('cr-gen-output');
    if (out) out.innerHTML = '<div class="cr-gen-spinner"><div class="cr-spinner"></div><div style="margin-top:12px;color:var(--t3)">Generating image…</div></div>';

    try {
        var payload = {
            prompt:           prompt.trim(),
            aspect_ratio:     (document.getElementById('cr-aspect')            || {}).value || '1:1',
            quality:          (document.getElementById('cr-quality')           || {}).value || 'standard',
            style:            (document.getElementById('cr-style')             || {}).value || 'vivid',
            count:            parseInt((document.getElementById('cr-count')    || {}).value) || 1,
            channel_preset:   (document.getElementById('cr-channel-preset')   || {}).value || '',
            industry_preset:  (document.getElementById('cr-industry-preset')  || {}).value || '',
            brand_kit_id:     parseInt((document.getElementById('cr-brand-kit')|| {}).value) || 0,
            workspace_id:     1,
        };

        fetch(_crUrl('/log-event'),{method:'POST',headers:_crHeaders(),body:JSON.stringify({event_type:'generation_requested',context:{channel_preset:payload.channel_preset,count:payload.count,source:'creative_engine'}})}).catch(function(){});
        var result = await _crPost('/generate/image', payload);

        if (!result.success) throw new Error(result.message || 'Generation failed');

        var assets = result.assets || [];
        _cr.assets = assets.concat(_cr.assets);

        if (out) {
            out.innerHTML = assets.map(function(a) {
                return '<div class="cr-result-card">' +
                    '<img src="' + _e(a.public_url) + '" alt="generated" loading="lazy">' +
                    '<div class="cr-result-meta">' +
                        '<div class="cr-result-prompt">' + _e((a.revised_prompt || a.prompt || '').substring(0,80)) + '…</div>' +
                        '<div class="cr-result-actions">' +
                            '<button class="cr-btn cr-btn-sm" onclick="_crSaveToLibrary(' + a.id + ')">'+window.icon("save",14)+' Save</button>' +
                            '<button class="cr-btn cr-btn-sm cr-btn-outline" onclick="window.open(\'' + _e(a.public_url) + '\',\'_blank\')">⬇ Download</button>' +
                            (_cr.pickMode ? '<button class="cr-btn cr-btn-sm cr-btn-primary" onclick="_crPickAsset(' + JSON.stringify(a).replace(/"/g,'&quot;') + ')">✓ Use This</button>' : '') +
                        '</div>' +
                        '<div style="font-size:10px;color:var(--t3);margin-top:4px">Cost: ' + _fmtCost(a.credit_cost) + '</div>' +
                    '</div>' +
                '</div>';
            }).join('');
        }

        _crToast('Generated ' + assets.length + ' image' + (assets.length > 1 ? 's' : '') + '!', 'success');

    } catch(e) {
        if (out) out.innerHTML = '<div style="padding:30px;text-align:center;color:var(--rd)">'+window.icon("close",14)+' ' + _e(e.message) + '</div>';
        _crToast('Generation failed: ' + e.message, 'error');
        console.error('[LuCreative] generate:', e);
    } finally {
        _cr.generating = false;
        if (btn) { btn.disabled = false; btn.textContent = ''+window.icon("ai",14)+' Generate'; }
    }
}

function _crSaveToLibrary(assetId) {
    _crToast('Asset is already saved to your Media Library ✓', 'success');
}

function _crPickAsset(assetData) {
    if (_cr.pickCb && typeof _cr.pickCb === 'function') {
        _cr.pickCb(assetData);
        _cr.pickMode = false;
        _cr.pickCb   = null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: GENERATE VIDEO — VIDEO888 (Phase 2B — LIVE)
// ══════════════════════════════════════════════════════════════════════════════

var _vidState = {
    generating:   false,
    polling:      false,
    pollTimer:    null,
    currentJobId: 0,
    lastResult:   null,
};

function _crViewGenerateVideo() {
    return '<div class="cr-card cr-video-card" style="max-width:680px;margin:0 auto">' +
        '<div class="cr-card-title">'+window.icon("video",14)+' Video Generator <span class="cr-badge cr-badge-live">LIVE</span></div>' +
        '<p class="cr-card-desc">Generate AI videos. CIMS memory check runs before each generation — same inputs won\'t regenerate if a valid record exists.</p>' +
        '<div class="cr-field-group">' +
            '<label class="cr-label">Prompt / Script <span class="cr-req">*</span></label>' +
            '<textarea id="cr-vid-prompt" class="cr-textarea" rows="4" placeholder="Luxury interior design transformation — slow pan through modern living room, natural light, warm tones…"></textarea>' +
        '</div>' +
        '<div class="cr-field-row">' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Duration</label>' +
                '<select id="cr-vid-duration" class="cr-select" onchange="_crOnDurationChange(this.value)">' +
                    '<option value="5">5 seconds</option>' +
                    '<option value="10">10 seconds</option>' +
                    '<option value="15">15 seconds ✦ Multi-scene</option>' +
                    '<option value="20">20 seconds ✦ Multi-scene</option>' +
                    '<option value="30">30 seconds ✦ Multi-scene</option>' +
                '</select>' +
                '<div id="cr-multi-scene-hint" style="display:none;margin-top:5px;font-size:11px;color:var(--t3);padding:6px 10px;background:rgba(108,92,231,.08);border-radius:6px;border-left:3px solid var(--p)">' +
                    '✦ Multi-scene mode: video split into scenes and stitched automatically.' +
                '</div>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Format</label>' +
                '<select id="cr-vid-aspect" class="cr-select">' +
                    '<option value="16:9">Landscape (16:9)</option>' +
                    '<option value="9:16">Portrait / Reel (9:16)</option>' +
                    '<option value="1:1">Square (1:1)</option>' +
                    '<option value="4:5">Tall (4:5)</option>' +
                '</select>' +
            '</div>' +
        '</div>' +
        '<div class="cr-field-row">' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Industry</label>' +
                '<select id="cr-vid-industry" class="cr-select">' +
                    '<option value="">— Select —</option>' +
                    '<option value="interior_design">Interior Design</option>' +
                    '<option value="real_estate">Real Estate</option>' +
                    '<option value="ecommerce">Ecommerce</option>' +
                    '<option value="restaurant">Restaurant</option>' +
                    '<option value="healthcare">Healthcare</option>' +
                    '<option value="events">Events</option>' +
                '</select>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Platform</label>' +
                '<select id="cr-vid-platform" class="cr-select">' +
                    '<option value="">— Select —</option>' +
                    '<option value="instagram">Instagram Reel</option>' +
                    '<option value="tiktok">TikTok</option>' +
                    '<option value="youtube">YouTube Shorts</option>' +
                    '<option value="facebook">Facebook</option>' +
                    '<option value="linkedin">LinkedIn</option>' +
                    '<option value="website">Website</option>' +
                '</select>' +
            '</div>' +
        '</div>' +
        '<div class="cr-notice cr-notice-info cr-notice-sm" style="margin-bottom:14px">' +
            ''+window.icon("ai",14)+' <strong>CIMS Active</strong> — Same prompt = reuse (no re-render). <strong>AI render time: 1–4 min</strong> per video. Elapsed time shown during render.' +
        '</div>' +
        '<div class="cr-gen-actions">' +
            '<button class="cr-btn cr-btn-primary cr-btn-lg" id="cr-vid-btn">'+window.icon("video",14)+' Generate Video</button>' +
        '</div>' +
        '<div id="cr-vid-output" style="margin-top:16px;"></div>' +
    '</div>';
}

function _crOnDurationChange(val) {
    var hint = document.getElementById('cr-multi-scene-hint');
    if (hint) hint.style.display = (parseInt(val) > 10) ? 'block' : 'none';
    var btn  = document.getElementById('cr-vid-btn');
    if (btn) btn.textContent = (parseInt(val) > 10) ? ''+window.icon("video",14)+' Generate Multi-Scene' : ''+window.icon("video",14)+' Generate Video';
}

function _crBindVideo() {
    var btn = document.getElementById('cr-vid-btn');
    if (btn) btn.addEventListener('click', _crGenerateVideo);
}

async function _crGenerateVideo() {
    if (_vidState.generating) return;

    var prompt   = ((document.getElementById('cr-vid-prompt')   || {}).value || '').trim();
    var duration = parseInt((document.getElementById('cr-vid-duration') || {}).value) || 5;
    var aspect   = (document.getElementById('cr-vid-aspect')   || {}).value || '16:9';
    var industry = (document.getElementById('cr-vid-industry') || {}).value || '';
    var platform = (document.getElementById('cr-vid-platform') || {}).value || '';

    if (!prompt) { _crToast('Please enter a prompt', 'warning'); return; }

    _vidState.generating = true;
    var btn = document.getElementById('cr-vid-btn');
    var out = document.getElementById('cr-vid-output');
    if (btn) { btn.disabled = true; btn.textContent = '\u23F3 Generating\u2026'; }
    if (out) {
        out.innerHTML =
            '<div class="cr-vid-progress">' +
            '<div class="cr-vid-progress-bar"><div class="cr-vid-progress-fill" id="cr-vid-prog-fill" style="width:0%"></div></div>' +
            '<div class="cr-vid-progress-label" id="cr-vid-prog-label">\uD83D\uDD0D Running source-of-truth check\u2026</div>' +
            '</div>';
    }

    function setProgress(pct, label) {
        var pf = document.getElementById('cr-vid-prog-fill');
        var pl = document.getElementById('cr-vid-prog-label');
        if (pf) pf.style.width  = Math.max(0, Math.min(100, pct)) + '%';
        if (pl) pl.textContent = label;
    }

    try {
        setProgress(15, '\uD83E\uDDE0 CIMS check\u2026');

        var crBase = (window.LU_API_BASE || '/api');
        var apiUrl = crBase + '/api/creative/video/generate';

        setProgress(30, '\uD83C\uDFA6 Submitting to Creative Engine\u2026 (AI render: 1\u20134 min)');

        var res = await fetch(apiUrl, {
            method:  'POST',
            headers: _crHeaders(),
            body:    JSON.stringify({
                prompt:       prompt,
                duration:     duration,
                aspect_ratio: aspect,
                industry:     industry,
                platform:     platform,
                workspace_id: 1,
            }),
        });

        var body;
        try { body = await res.json(); } catch(e) { body = {}; }

        if (!res.ok) {
            var errMsg = (body && body.message) ? body.message : ('HTTP ' + res.status);
            throw new Error(errMsg);
        }
        if (!body.success) {
            throw new Error((body && body.message) ? body.message : 'Generation failed');
        }

        var data = body.data;
        if (!data) throw new Error('No data in response');

        var isMulti = (data.mode === 'multi_scene');
        setProgress(65, isMulti ? '\uD83C\uDFAC Multi-scene in progress\u2026' : (data.is_async ? '\u23F3 Task submitted \u2014 AI rendering in progress... updates every 3s' : '\uD83D\uDCBE Saving\u2026'));

        // ── ASYNC POLLING (Runway) ─────────────────────────────────────────────
        if (data.is_async && data.job_id) {
            var jobId    = data.job_id;
            // MiniMax takes 4-8min. 72x5s = 360s = 6min ceiling.
            // MiniMax: 1-4 min for 6s video, 3-6 min for 10s video
            var POLL_INTERVAL = 3000; // 3s
            var maxPolls   = 120;     // 120 x 3s = 6 min max
            var pollN      = 0;
            var pollDone   = false;
            var startedAt  = Date.now();

            while (pollN < maxPolls && !pollDone) {
                pollN++;
                await new Promise(function(r) { setTimeout(r, POLL_INTERVAL); });

                var pollRes;
                try {
                    pollRes = await fetch(crBase + '/api/creative/video/jobs/' + jobId + '/status', { headers: _crHeaders() });
                } catch(netErr) {
                    // Transient network error — keep trying
                    console.warn('[LuCreative] Poll network error:', netErr);
                    continue;
                }

                var pollBody;
                try { pollBody = await pollRes.json(); } catch(e) { continue; }
                if (!pollBody || !pollBody.data) continue;

                var pd = pollBody.data;
                var pStatus = (pd.status || '').toLowerCase();

                if (pStatus === 'completed') {
                    if (!pd.video_url) {
                        throw new Error('Video job completed but no URL returned. Check error_log for [VIDEO888] entries.');
                    }
                    data.video_url = pd.video_url;
                    if (pd.asset_id)  data.asset_id  = pd.asset_id;
                    data.is_async = false;
                    setProgress(100, '\\u2705 Done!');
                    pollDone = true;
                } else if (pStatus === 'failed' || pStatus === 'timeout') {
                    throw new Error(pd.message || 'Video generation failed on provider side');
                } else {
                    // queued or processing
                    var elapsed = Math.round((Date.now() - startedAt) / 1000);
                    var elStr   = elapsed < 60 ? elapsed + 's' : Math.floor(elapsed/60) + 'm ' + (elapsed%60) + 's';
                    var pPct    = Math.min(pd.progress || (15 + pollN), 92);
                    var pLbl    = pStatus === 'queued'
                        ? ('\uD83D\uDD51 Queued at provider... [' + elStr + ']')
                        : ('\u23F3 Rendering... [' + elStr + ' elapsed]');
                    setProgress(Math.min(pPct, 95), pLbl);
                }
            }

            if (!pollDone) {
                throw new Error('Video is taking longer than expected. It will finish in the background — check the Media Library in a few minutes.');
            }
        } else {
            setProgress(100, '\u2705 Done!');
        }

        // ── SUCCESS ────────────────────────────────────────────────────────────
        // Confirm we have a real video URL before showing success
        if (data.stitch_failed) {
            // PART 4: Stitch failed but scenes are available
            var sceneCount = (data.scenes || []).length;
            var stitchMsg = '\u26A0\uFE0F Scene generation succeeded but the final video could not be assembled. ' + sceneCount + ' scene(s) saved to your Media Library.';
            if (out) out.innerHTML = '<div class="cr-notice cr-notice-warning">' + stitchMsg + '</div>';
            _crToast(stitchMsg, 'warning');
            return;
        }
        if (!data.video_url && data.decision !== 'reused') {
            throw new Error('Video generation failed: no output received. Check server logs if this persists.');
        }

        _vidState.lastResult = data;
        setTimeout(function() { _crRenderVideoResult(data, out); }, 250);
        _crToast(data.decision === 'reused' ? '\u2705 Reused from memory!' : '\u2705 Video generated!', 'success');
        _crLoadDashStats();

    } catch(err) {
        console.error('[LuCreative] video generate error:', err);
        var errHtml = '<div class="cr-notice cr-notice-error">\u274C <strong>Generation failed</strong><br>' + _e(err.message) + '</div>';
        if (out) out.innerHTML = errHtml;
        _crToast('Failed: ' + err.message, 'error');
    } finally {
        _vidState.generating = false;
        if (btn) { btn.disabled = false; btn.textContent = '\uD83C\uDFA6 Generate Video'; }
    }
}

function _crRenderVideoResult(data, container) {
    if (!container) return;

    var isReused = (data.decision === 'reused');

    var videoHtml = '';
    if (data.video_url) {
        var ext = data.video_url.split('.').pop().toLowerCase();
        var mp4Src  = (ext === 'mp4')  ? data.video_url : '';
        var webmSrc = (ext === 'webm') ? data.video_url : '';
        videoHtml =
            '<div class="cr-vid-preview-wrap">' +
            '<video class="cr-vid-preview" controls playsinline preload="metadata" ' +
                'style="width:100%;max-height:420px;display:block;background:#0F1117;"' +
                (mp4Src  ? ' src="' + _e(mp4Src)  + '"' : '') +
                (webmSrc ? ' src="' + _e(webmSrc) + '"' : '') +
            '>' +
            (mp4Src  ? '<source src="' + _e(mp4Src)  + '" type="video/mp4">'  : '') +
            (webmSrc ? '<source src="' + _e(webmSrc) + '" type="video/webm">' : '') +
            'Your browser does not support video playback. ' +
            '<a href="' + _e(data.video_url) + '" download>Download video</a>' +
            '</video>' +
            '</div>';
    } else {
        videoHtml = '<div class="cr-notice cr-notice-info">\uD83D\uDCF9 Video processing. Memory record saved \u2014 check the Media Library.</div>';
    }

    var memBadge = data.memory_id
        ? '<span class="cr-badge cr-badge-memory" title="CIMS Memory Record #' + data.memory_id + '">\uD83E\uDDE0 CIMS #' + data.memory_id + '</span>'
        : '';
    var decisionBadge = isReused
        ? '<span class="cr-badge cr-badge-reused">\u267B\uFE0F Reused from Memory</span>'
        : '<span class="cr-badge cr-badge-new">\u2728 AI Generated</span>';
    var aiBadge = '<span class="cr-badge cr-badge-provider">\u2728 LevelUp AI</span>';

    // Build scene breakdown if multi-scene
    var sceneHtml = '';
    if (data.mode === 'multi_scene' && data.scenes && data.scenes.length) {
        var sceneRows = data.scenes.map(function(s,i) {
            var ok = s.success ? ''+window.icon("check",14)+'' : ''+window.icon("close",14)+'';
            return '<div class="cr-scene-row">' + ok + ' <strong>Scene ' + (i+1) + '</strong> (' + s.label + ', ' + s.duration + 's) — ' + _e((s.prompt||'').substring(0,60)) + '…</div>';
        }).join('');
        sceneHtml = '<div class="cr-scene-breakdown"><div class="cr-scene-title">'+window.icon("video",14)+' Scene Breakdown</div>' + sceneRows + '</div>';
    }

    container.innerHTML =
        '<div class="cr-vid-result">' +
            '<div class="cr-vid-result-header">' + decisionBadge + ' ' + memBadge + ' ' + aiBadge + '</div>' +
            sceneHtml +
            videoHtml +
            (data.video_url
                ? '<div class="cr-vid-result-actions">' +
                  '<a class="cr-btn cr-btn-sm cr-btn-secondary" href="' + _e(data.video_url) + '" download target="_blank">\u2B07 Download</a> ' +
                  '<button class="cr-btn cr-btn-sm" onclick="_crNav(\'library\')">\uD83D\uDCDA View Library</button>' +
                  '</div>'
                : '') +
            (data.message ? '<div class="cr-vid-result-msg">' + _e(data.message) + '</div>' : '') +
        '</div>';
}

async function _crQueueVideo() { return _crGenerateVideo(); }

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: MEDIA LIBRARY
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewLibrary() {
    var channelOpts = '<option value="">All Channels</option>' + _cr.presets.channel.map(function(p) {
        return '<option value="' + _e(p.slug) + '">' + _e(p.name) + '</option>';
    }).join('');

    var assets = _crFilteredAssets();

    var gridHTML = assets.length ? assets.map(function(a) {
        return '<div class="cr-lib-card" onclick="' + (_cr.pickMode ? '_crPickFromLibrary(' + a.id + ')' : '_crOpenAsset(' + a.id + ')') + '">' +
            '<div class="cr-lib-thumb">' +
                '<img src="' + _e(a.thumbnail_url || a.public_url) + '" alt="" loading="lazy" onerror="this.parentElement.innerHTML=\''+window.icon("image",14)+'\'">' +
                (_cr.pickMode ? '<div class="cr-pick-overlay">✓ Use</div>' : '') +
            '</div>' +
            '<div class="cr-lib-meta">' +
                '<div class="cr-lib-prompt">' + _e((a.prompt||'').substring(0,50)) + '</div>' +
                '<div class="cr-lib-info">' + _e(a.channel_preset || a.type) + ' · ' + _fmtDate(a.created_at) + '</div>' +
                '<div class="cr-lib-actions">' +
                    '<button class="cr-btn cr-btn-xs" onclick="event.stopPropagation();_crInsertIntoBuilder(' + a.id + ')" title="Use in Builder">'+window.icon("more",14)+'</button>' +
                    '<button class="cr-btn cr-btn-xs" onclick="event.stopPropagation();_crUseInSocial(' + a.id + ')" title="Use in Social">'+window.icon("message",14)+'</button>' +
                    '<button class="cr-btn cr-btn-xs" onclick="event.stopPropagation();_crUseInMarketing(' + a.id + ')" title="Use in Marketing">'+window.icon("rocket",14)+'</button>' +
                    '<button class="cr-btn cr-btn-xs" onclick="event.stopPropagation();window.open(\'' + _e(a.public_url) + '\',\'_blank\')" title="Download">⬇</button>' +
                    '<button class="cr-btn cr-btn-xs" onclick="event.stopPropagation();_crToggleFav(' + a.id + ',this)" title="' + (a.is_favorite ? 'Remove favorite' : 'Save as favorite') + '" style="color:' + (a.is_favorite ? 'var(--am)' : 'var(--t3)') + '">' + (a.is_favorite ? '★' : '☆') + '</button>' +
                            '<button class="cr-btn cr-btn-xs cr-btn-danger" onclick="event.stopPropagation();_crDeleteAsset(' + a.id + ')" title="Delete">'+window.icon("delete",14)+'</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('') : '<div class="cr-lib-empty">No assets yet. <button class="cr-link" onclick="_crNav(\'generate-image\')">Generate your first image →</button></div>';

    return '<div class="cr-lib-wrap">' +
        '<div class="cr-lib-toolbar">' +
            '<input id="cr-lib-search" class="cr-input cr-search" type="text" placeholder="Search prompts…" value="' + _e(_cr.searchQ) + '">' +
            '<select id="cr-lib-type" class="cr-select cr-select-sm">' +
                '<option value="">All Types</option>' +
                '<option value="image"' + (_cr.filterType==='image' ? ' selected' : '') + '>Images</option>' +
                '<option value="video"' + (_cr.filterType==='video' ? ' selected' : '') + '>Videos</option>' +
            '</select>' +
            '<select id="cr-lib-preset" class="cr-select cr-select-sm">' + channelOpts + '</select>' +
            '<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);cursor:pointer"><input type="checkbox" id="cr-lib-favonly" style="accent-color:var(--am)" onchange="_crLoadLibrary()"> '+window.icon("star",14)+' Favorites</label>' +
            '<button class="cr-btn cr-btn-sm" onclick="_crLoadLibrary()">'+window.icon("search",14)+' Filter</button>' +
            '<button class="cr-btn cr-btn-sm cr-btn-primary" onclick="_crNav(\'generate-image\')">'+window.icon("ai",14)+' New</button>' +
        '</div>' +
        '<div class="cr-lib-grid" id="cr-lib-grid">' + gridHTML + '</div>' +
    '</div>';
}

function _crFilteredAssets() {
    var assets = _cr.assets || [];
    if (_cr.filterType)   assets = assets.filter(function(a){ return a.type === _cr.filterType; });
    if (_cr.filterPreset) assets = assets.filter(function(a){ return a.channel_preset === _cr.filterPreset; });
    if (_cr.searchQ)      assets = assets.filter(function(a){ return (a.prompt||'').toLowerCase().includes(_cr.searchQ.toLowerCase()); });
    return assets;
}

function _crBindLibrary() {
    var searchEl = document.getElementById('cr-lib-search');
    if (searchEl) searchEl.addEventListener('input', function(){ _cr.searchQ = this.value; _crRefreshLibGrid(); });
}

function _crRefreshLibGrid() {
    var grid = document.getElementById('cr-lib-grid');
    if (!grid) return;
    var assets = _crFilteredAssets();
    if (!assets.length) { grid.innerHTML = '<div class="cr-lib-empty">No assets found.</div>'; return; }
    grid.innerHTML = assets.map(function(a) {
        return '<div class="cr-lib-card">' +
            '<div class="cr-lib-thumb"><img src="' + _e(a.thumbnail_url || a.public_url) + '" alt="" loading="lazy" onerror="this.parentElement.innerHTML=\''+window.icon("image",14)+'\'"></div>' +
            '<div class="cr-lib-meta"><div class="cr-lib-prompt">' + _e((a.prompt||'').substring(0,50)) + '</div>' +
            '<div class="cr-lib-info">' + _e(a.channel_preset || a.type) + ' · ' + _fmtDate(a.created_at) + '</div></div>' +
        '</div>';
    }).join('');
}

async function _crLoadLibrary() {
    var typeEl   = document.getElementById('cr-lib-type');
    var presetEl = document.getElementById('cr-lib-preset');
    var searchEl = document.getElementById('cr-lib-search');
    _cr.filterType   = typeEl   ? typeEl.value   : '';
    _cr.filterPreset = presetEl ? presetEl.value : '';
    _cr.searchQ      = searchEl ? searchEl.value : '';
    try {
        var favOnly = (document.getElementById('cr-lib-favonly') || {}).checked;
        var params = '?limit=60';
        if (_cr.filterType)   params += '&type='           + encodeURIComponent(_cr.filterType);
        if (_cr.filterPreset) params += '&channel_preset=' + encodeURIComponent(_cr.filterPreset);
        if (_cr.searchQ)      params += '&search='         + encodeURIComponent(_cr.searchQ);
        if (favOnly)          params += '&is_favorite=1';
        var res = await _crGet('/assets' + params);
        _cr.assets = res.assets || [];
        _crRefreshLibGrid();
    } catch(e) { _crToast('Failed to load: ' + e.message, 'error'); }
}

function _crOpenAsset(id) {
    var asset = _cr.assets.find(function(a){ return String(a.id) === String(id); });
    if (!asset) return;
    // Open a simple preview modal
    var existing = document.getElementById('cr-preview-modal');
    if (existing) existing.remove();
    var modal = document.createElement('div');
    modal.id = 'cr-preview-modal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;padding:20px;';
    modal.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:16px;max-width:700px;width:100%;padding:20px;position:relative;">' +
        '<button onclick="document.getElementById(\'cr-preview-modal\').remove()" style="position:absolute;top:12px;right:12px;background:var(--s2);border:none;color:var(--t2);font-size:16px;cursor:pointer;border-radius:6px;padding:4px 8px">✕</button>' +
        '<img src="' + _e(asset.public_url) + '" style="width:100%;border-radius:10px;margin-bottom:16px">' +
        '<p style="color:var(--t2);font-size:12px;margin:0 0 8px">' + _e(asset.revised_prompt || asset.prompt || '') + '</p>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
            '<button class="cr-btn cr-btn-primary cr-btn-sm" onclick="_crInsertIntoBuilder(' + asset.id + ')">'+window.icon("more",14)+' Builder</button>' +
            '<button class="cr-btn cr-btn-sm" onclick="_crUseInSocial(' + asset.id + ')">'+window.icon("message",14)+' Social</button>' +
            '<button class="cr-btn cr-btn-sm" onclick="_crUseInMarketing(' + asset.id + ')">'+window.icon("rocket",14)+' Marketing</button>' +
            '<button class="cr-btn cr-btn-sm cr-btn-outline" onclick="window.open(\'' + _e(asset.public_url) + '\',\'_blank\')">⬇ Download</button>' +
        '</div>' +
        '<div style="margin-top:10px;font-size:10px;color:var(--t3)">Cost: ' + _fmtCost(asset.credit_cost) + ' · ' + _fmtDate(asset.created_at) + '</div>' +
    '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e){ if (e.target === modal) modal.remove(); });
}

function _crToggleFav(id, btn) {
    fetch(_crUrl('/assets/' + parseInt(id) + '/favorite'), {
        method: 'POST', headers: _crHeaders(), body: '{}'
    }).then(function(r){ return r.json(); }).then(function(res) {
        if (btn) {
            btn.textContent = res.is_favorite ? '★' : '☆';
            btn.style.color  = res.is_favorite ? 'var(--am)' : 'var(--t3)';
            btn.title        = res.is_favorite ? 'Remove favorite' : 'Save as favorite';
        }
        // Update in-memory asset
        var a = _cr.assets.find(function(x){ return String(x.id) === String(id); });
        if (a) a.is_favorite = res.is_favorite ? 1 : 0;
        _crToast(res.is_favorite ? ''+window.icon("star",14)+' Saved' : 'Removed', 'info');
    }).catch(function(){ _crToast('Failed', 'error'); });
}

async function _crDeleteAsset(id) {
    if (!confirm('Delete this asset from your library?')) return;
    try {
        await _crDelete('/assets/' + id);
        _cr.assets = _cr.assets.filter(function(a){ return String(a.id) !== String(id); });
        _crRefreshLibGrid();
        _crToast('Asset deleted', 'success');
    } catch(e) { _crToast('Delete failed: ' + e.message, 'error'); }
}

function _crInsertIntoBuilder(assetId) {
    var asset = _cr.assets.find(function(a){ return String(a.id) === String(assetId); });
    if (!asset) return;
    // Call Builder's image setter if available — luCreativeInsertImage is called by Builder
    if (typeof window.luCreativeInsertImage === 'function') {
        window.luCreativeInsertImage(asset.public_url);
        _crToast('Image inserted into Builder ✓', 'success');
    } else {
        // Copy URL to clipboard as fallback
        navigator.clipboard.writeText(asset.public_url).then(function(){
            _crToast('URL copied — paste into Builder image field', 'info');
        });
    }
    // Close preview modal if open
    var modal = document.getElementById('cr-preview-modal');
    if (modal) modal.remove();
}

function _crUseInSocial(assetId) {
    var asset = _cr.assets.find(function(a){ return String(a.id) === String(assetId); });
    if (!asset) return;
    // Emit event for Social engine to pick up
    window.dispatchEvent(new CustomEvent('lu:creative:insert', { detail: { context: 'social', asset: asset } }));
    if (typeof window.nav === 'function') window.nav('social');
    _crToast('Asset ready in Social ✓', 'success');
    var modal = document.getElementById('cr-preview-modal'); if (modal) modal.remove();
}

function _crUseInMarketing(assetId) {
    var asset = _cr.assets.find(function(a){ return String(a.id) === String(assetId); });
    if (!asset) return;
    window.dispatchEvent(new CustomEvent('lu:creative:insert', { detail: { context: 'marketing', asset: asset } }));
    if (typeof window.nav === 'function') window.nav('marketing');
    _crToast('Asset ready in Marketing ✓', 'success');
    var modal = document.getElementById('cr-preview-modal'); if (modal) modal.remove();
}

function _crPickFromLibrary(id) {
    var asset = _cr.assets.find(function(a){ return String(a.id) === String(id); });
    if (asset && _cr.pickCb) {
        _cr.pickCb(asset);
        _cr.pickMode = false;
        _cr.pickCb   = null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: BRAND KITS
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewBrandKits() {
    var listHTML = _cr.brandKits.length ? _cr.brandKits.map(function(k) {
        return '<div class="cr-kit-card">' +
            '<div class="cr-kit-header">' +
                '<div class="cr-kit-name">' + _e(k.name) + (k.is_default ? ' <span class="cr-badge">Default</span>' : '') + '</div>' +
                '<div class="cr-kit-actions">' +
                    '<button class="cr-btn cr-btn-xs" onclick="_crEditKit(' + k.id + ')">Edit</button>' +
                    '<button class="cr-btn cr-btn-xs cr-btn-danger" onclick="_crDeleteKit(' + k.id + ')">Delete</button>' +
                '</div>' +
            '</div>' +
            '<div class="cr-kit-meta">' + _e(k.industry || '') + (k.tone ? ' · ' + _e(k.tone) : '') + '</div>' +
            '<div class="cr-kit-keywords">' + _e((k.style_keywords||'').substring(0,80)) + '</div>' +
        '</div>';
    }).join('') : '<div style="color:var(--t3);text-align:center;padding:30px">No brand kits yet.</div>';

    return '<div class="cr-bk-wrap">' +
        '<div class="cr-section-header">' +
            '<h3>Brand Kits (' + _cr.brandKits.length + ')</h3>' +
            '<button class="cr-btn cr-btn-primary cr-btn-sm" onclick="_crNewKit()">+ New Brand Kit</button>' +
        '</div>' +
        '<div id="cr-kit-list">' + listHTML + '</div>' +
        '<div id="cr-kit-form" style="display:none">' + _crKitForm(null) + '</div>' +
    '</div>';
}

function _crKitForm(kit) {
    var k = kit || {};
    return '<div class="cr-card" style="margin-top:16px">' +
        '<div class="cr-card-title">' + (k.id ? 'Edit' : 'New') + ' Brand Kit</div>' +
        '<input type="hidden" id="cr-kit-id" value="' + _e(k.id||'') + '">' +
        '<div class="cr-field-row">' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Kit Name *</label>' +
                '<input id="cr-kit-name" class="cr-input" value="' + _e(k.name||'') + '" placeholder="Brand Name">' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Industry</label>' +
                '<input id="cr-kit-industry" class="cr-input" value="' + _e(k.industry||'') + '" placeholder="e.g. Real Estate">' +
            '</div>' +
        '</div>' +
        '<div class="cr-field-group">' +
            '<label class="cr-label">Style Keywords (comma-separated)</label>' +
            '<input id="cr-kit-style" class="cr-input" value="' + _e(k.style_keywords||'') + '" placeholder="luxury, modern, bright, minimalist">' +
        '</div>' +
        '<div class="cr-field-group">' +
            '<label class="cr-label">Keywords to AVOID</label>' +
            '<input id="cr-kit-avoid" class="cr-input" value="' + _e(k.avoid_keywords||'') + '" placeholder="cartoon, dark, blurry">' +
        '</div>' +
        '<div class="cr-field-row">' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Visual Tone</label>' +
                '<input id="cr-kit-tone" class="cr-input" value="' + _e(k.tone||'') + '" placeholder="premium, approachable, bold">' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Logo URL</label>' +
                '<input id="cr-kit-logo" class="cr-input" type="url" value="' + _e(k.logo_url||'') + '" placeholder="https://...">' +
            '</div>' +
        '</div>' +
        '<div class="cr-gen-actions">' +
            '<button class="cr-btn cr-btn-primary" onclick="_crSaveKit()">'+window.icon("save",14)+' Save Brand Kit</button>' +
            '<button class="cr-btn cr-btn-outline" onclick="_crCancelKit()">Cancel</button>' +
        '</div>' +
    '</div>';
}

function _crBindBrandKits() {}

function _crNewKit() {
    var form = document.getElementById('cr-kit-form');
    if (form) { form.style.display = 'block'; form.innerHTML = _crKitForm(null); form.scrollIntoView({behavior:'smooth'}); }
}

function _crEditKit(id) {
    var kit = _cr.brandKits.find(function(k){ return String(k.id) === String(id); });
    if (!kit) return;
    var form = document.getElementById('cr-kit-form');
    if (form) { form.style.display = 'block'; form.innerHTML = _crKitForm(kit); form.scrollIntoView({behavior:'smooth'}); }
}

async function _crSaveKit() {
    var id      = (document.getElementById('cr-kit-id') || {}).value || '';
    var name    = ((document.getElementById('cr-kit-name') || {}).value || '').trim();
    if (!name) { _crToast('Kit name required', 'warning'); return; }
    var payload = {
        name:             name,
        industry:         ((document.getElementById('cr-kit-industry') || {}).value || '').trim(),
        style_keywords:   ((document.getElementById('cr-kit-style')    || {}).value || '').trim(),
        avoid_keywords:   ((document.getElementById('cr-kit-avoid')    || {}).value || '').trim(),
        tone:             ((document.getElementById('cr-kit-tone')     || {}).value || '').trim(),
        logo_url:         ((document.getElementById('cr-kit-logo')     || {}).value || '').trim(),
        workspace_id:     1,
    };
    try {
        var result = id ? await _crPut('/brand-kits/' + id, payload) : await _crPost('/brand-kits', payload);
        if (id) {
            _cr.brandKits = _cr.brandKits.map(function(k){ return String(k.id) === String(id) ? result : k; });
        } else {
            _cr.brandKits.push(result);
        }
        _cr.view = 'brand-kits';
        _crRender();
        _crToast('Brand Kit saved ✓', 'success');
    } catch(e) { _crToast('Save failed: ' + e.message, 'error'); }
}

async function _crDeleteKit(id) {
    if (!confirm('Delete this brand kit?')) return;
    try {
        await _crDelete('/brand-kits/' + id);
        _cr.brandKits = _cr.brandKits.filter(function(k){ return String(k.id) !== String(id); });
        _crRender();
        _crToast('Brand Kit deleted', 'success');
    } catch(e) { _crToast('Delete failed: ' + e.message, 'error'); }
}

function _crCancelKit() {
    var form = document.getElementById('cr-kit-form');
    if (form) form.style.display = 'none';
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: PRESETS
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewPresets() {
    var chHTML = _cr.presets.channel.length ? _cr.presets.channel.map(function(p) {
        var cfg = p.config || {};
        return '<div class="cr-preset-card">' +
            '<div class="cr-preset-icon">'+window.icon("edit",14)+'</div>' +
            '<div class="cr-preset-body">' +
                '<div class="cr-preset-name">' + _e(p.name) + '</div>' +
                '<div class="cr-preset-meta">' + _e(cfg.aspect_ratio||'') +  + '</div>' +
                '<div class="cr-preset-tone">' + _e((cfg.tone||'').substring(0,60)) + '</div>' +
            '</div>' +
            '<button class="cr-btn cr-btn-xs cr-btn-primary" onclick="_crUsePreset(\'' + _e(p.slug) + '\')">Use</button>' +
        '</div>';
    }).join('') : '<p style="color:var(--t3)">No presets loaded.</p>';

    var indHTML = _cr.presets.industry.length ? _cr.presets.industry.map(function(p) {
        var cfg = p.config || {};
        return '<div class="cr-preset-card">' +
            '<div class="cr-preset-icon">'+window.icon("more",14)+'</div>' +
            '<div class="cr-preset-body">' +
                '<div class="cr-preset-name">' + _e(p.name) + '</div>' +
                '<div class="cr-preset-tone">' + _e((cfg.style_keywords||'').substring(0,70)) + '</div>' +
            '</div>' +
            '<button class="cr-btn cr-btn-xs cr-btn-outline" onclick="_crUseIndustryPreset(\'' + _e(p.slug) + '\')">Use</button>' +
        '</div>';
    }).join('') : '<p style="color:var(--t3)">No industry presets loaded.</p>';

    return '<div class="cr-presets-wrap">' +
        '<div class="cr-section-header"><h3>Channel Presets</h3></div>' +
        '<div class="cr-preset-grid">' + chHTML + '</div>' +
        '<div class="cr-section-header" style="margin-top:24px"><h3>Industry Presets</h3></div>' +
        '<div class="cr-preset-grid">' + indHTML + '</div>' +
    '</div>';
}

function _crUsePreset(slug) {
    _cr.view = 'generate-image';
    _crRender();
    setTimeout(function() {
        var el = document.getElementById('cr-channel-preset');
        if (el) { el.value = slug; el.dispatchEvent(new Event('change')); }
    }, 100);
}

function _crUseIndustryPreset(slug) {
    _cr.view = 'generate-image';
    _crRender();
    setTimeout(function() {
        var el = document.getElementById('cr-industry-preset');
        if (el) el.value = slug;
    }, 100);
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIEW: SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════

function _crViewSettings() {
    var s = _cr.settings || {};
    return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:900px">' +

        '<div>' +
        '<div class="cr-card">' +
            '<div id="cr-killswitch-banner" style="display:none;background:rgba(248,113,113,.15);border:1px solid var(--rd);border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between">' +'<div style="font-size:12px;color:var(--rd)">'+window.icon("close",14)+' <strong>Kill Switch ACTIVE</strong> — <span id="cr-ks-reason"></span></div>' +'<button onclick="_crDeactivateKillSwitch()" style="background:var(--rd);border:none;border-radius:5px;color:#fff;padding:4px 10px;font-size:11px;cursor:pointer;font-family:var(--fb)">Deactivate</button>' +'</div>' +'<div class="cr-card-title">'+window.icon("edit",14)+' Creative Engine</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Default Provider</label>' +
                '<select id="cr-set-provider" class="cr-select">' +
                    '<option value="openai"' + (s.default_provider === 'openai' ? ' selected' : '') + '>LevelUp Image Engine (Standard)</option>' +
                    '<option value="siliconflow"' + (s.default_provider === 'siliconflow' ? ' selected' : '') + '>LevelUp Image Engine (HD)</option>' +
                '</select>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Default Quality</label>' +
                '<select id="cr-set-quality" class="cr-select">' +
                    '<option value="standard"' + (s.default_quality !== 'hd' ? ' selected' : '') + '>Standard</option>' +
                    '<option value="hd"' + (s.default_quality === 'hd' ? ' selected' : '') + '>HD (2× credits)</option>' +
                '</select>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Image Engine API Key</label>' +
                '<div class="cr-input-row">' +
                    '<input id="cr-set-openai-key" class="cr-input" type="password" placeholder="sk-…">' +
                    '<button class="cr-btn cr-btn-sm" onclick="_crTestProvider(\'openai\')">Test</button>' +
                '</div>' +
                '<div id="cr-test-result-openai" style="margin-top:5px;font-size:11px;color:var(--t3)"></div>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Video Engine API Key <span style="font-size:10px;background:rgba(0,229,168,.15);color:var(--ac);padding:2px 7px;border-radius:10px;font-weight:600">PRIMARY</span></label>' +
                '<div class="cr-input-row">' +
                    '<input id="cr-set-minimax-key" class="cr-input" type="password" placeholder="Enter MiniMax API key…" autocomplete="off">' +
                    '<button class="cr-btn cr-btn-sm" onclick="_crTestMiniMaxKey()">Test</button>' +
                '</div>' +
                '<div id="cr-test-result-minimax" style="margin-top:5px;font-size:11px;color:var(--t3)"></div>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Video Engine API Key (Backup) <span style="font-size:10px;color:var(--t3);font-weight:400">(used if primary not configured)</span></label>' +
                '<div class="cr-input-row">' +
                    '<input id="cr-set-runway-key" class="cr-input" type="password" placeholder="rw-…" autocomplete="off">' +
                    '<button class="cr-btn cr-btn-sm" onclick="_crTestRunwayKey()">Test</button>' +
                '</div>' +
                '<div id="cr-test-result-runway" style="margin-top:5px;font-size:11px;color:var(--t3)"></div>' +
            '</div>' +
            '<div class="cr-notice cr-notice-info">API keys stored securely in WordPress options.</div>' +
            '<div class="cr-gen-actions"><button class="cr-btn cr-btn-primary" onclick="_crSaveSettings()">'+window.icon("save",14)+' Save</button></div>' +
        '</div>' +
        '</div>' +

        '<div>' +
        '<div class="cr-card" id="cr-credit-settings-card">' +
            '<div class="cr-card-title">'+window.icon("tag",14)+' CREDIT888 Settings</div>' +'<div class="cr-section-header" style="margin-bottom:8px"><h3>'+window.icon("close",14)+' Kill Switch</h3></div>' +'<div style="display:flex;gap:8px;margin-bottom:14px">' +'<button onclick="_crSetKillSwitch(true,\"all\")" style="flex:1;background:rgba(248,113,113,.15);border:1px solid var(--rd);border-radius:7px;color:var(--rd);padding:7px;font-size:11px;font-weight:700;cursor:pointer">'+window.icon("close",14)+' Kill All</button>' +'<button onclick="_crSetKillSwitch(true,\"agents\")" style="flex:1;background:rgba(245,158,11,.12);border:1px solid var(--am);border-radius:7px;color:var(--am);padding:7px;font-size:11px;font-weight:700;cursor:pointer">🤖 Kill Agents</button>' +'<button onclick="_crSetKillSwitch(true,\"generation\")" style="flex:1;background:rgba(108,92,231,.12);border:1px solid var(--p);border-radius:7px;color:var(--pu);padding:7px;font-size:11px;font-weight:700;cursor:pointer">'+window.icon("ai",14)+' Kill Gen</button>' +'<button onclick="_crSetKillSwitch(false)" style="flex:1;background:rgba(0,229,168,.1);border:1px solid var(--ac);border-radius:7px;color:var(--ac);padding:7px;font-size:11px;font-weight:700;cursor:pointer">✓ Resume All</button>' +'</div>' +
            '<div id="cr-credit-balance-widget" style="background:var(--s2);border-radius:10px;padding:14px;margin-bottom:14px;text-align:center">' +
                '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Current Balance</div>' +
                '<div id="cr-credit-balance-val" style="font-family:var(--fh);font-size:28px;font-weight:800;color:var(--ac)">—</div>' +
                '<div style="font-size:10px;color:var(--t3);margin-top:2px">credits</div>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Credit Enforcement</label>' +
                '<select id="cr-credit-enforce" class="cr-select">' +
                    '<option value="on">Enabled — block if insufficient</option>' +
                    '<option value="off">Disabled — dev/testing mode</option>' +
                '</select>' +
            '</div>' +
            '<div class="cr-field-row">' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Standard Image (credits)</label>' +
                    '<input id="cr-cost-img-std" class="cr-input" type="number" min="0" step="1" placeholder="10">' +
                '</div>' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">HD Image (credits)</label>' +
                    '<input id="cr-cost-img-hd" class="cr-input" type="number" min="0" step="1" placeholder="20">' +
                '</div>' +
            '</div>' +
            '<div class="cr-section-header"><h3>Safety Caps</h3></div>' +
            '<div class="cr-field-row">' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Max/Request</label>' +
                    '<input id="cr-cap-request" class="cr-input" type="number" min="1" placeholder="100">' +
                '</div>' +
                '<div class="cr-field-group">' +
                    '<label class="cr-label">Max/Min (Workspace)</label>' +
                    '<input id="cr-cap-minute" class="cr-input" type="number" min="1" placeholder="200">' +
                '</div>' +
            '</div>' +
            '<div class="cr-field-group">' +
                '<label class="cr-label">Max/Min (Per Agent)</label>' +
                '<input id="cr-cap-agent" class="cr-input" type="number" min="1" placeholder="50">' +
            '</div>' +
            '<div class="cr-gen-actions">' +
                '<button class="cr-btn cr-btn-primary" onclick="_crSaveCreditSettings()">'+window.icon("save",14)+' Save Credits Config</button>' +
                '<button class="cr-btn cr-btn-outline" onclick="_crAddTestCredits()">+ Add 100 (Test)</button>' +
            '</div>' +
            '<div id="cr-credit-save-result" style="margin-top:8px;font-size:11px;color:var(--t3)"></div>' +
        '</div>' +

        '<div class="cr-card" style="margin-top:14px">' +
            '<div class="cr-card-title">'+window.icon("chart",14)+' Platform Cost Map</div>' +
            '<div id="cr-cost-map-table">Loading…</div>' +
        '</div>' +
        '</div>' +

    '</div>';
}
function _crBindSettings() {
    // Load credit settings and balance
    _crGet('/credits/balance').then(function(r) {
        var el = document.getElementById('cr-credit-balance-val');
        if (el) el.textContent = (r.balance || 0).toFixed(0);
        var enf = document.getElementById('cr-credit-enforce');
        if (enf) enf.value = r.enforcement || 'on';
    }).catch(function(){});

    fetch((window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api')) + '/api/credits/settings', {
        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}
    }).then(function(r){ return r.json(); }).then(function(d) {
        var f = function(id, val) { var el = document.getElementById(id); if (el && val !== undefined) el.value = val; };
        f('cr-credit-enforce',  d.enforcement);
        f('cr-cost-img-std',    d.image_standard);
        f('cr-cost-img-hd',     d.image_hd);
        f('cr-cap-request',     d.max_per_request);
        f('cr-cap-minute',      d.max_per_minute);
        f('cr-cap-agent',       d.max_per_agent);
    }).catch(function(){});

    // Pre-fill API key indicators from provider_config
    _crGet('/settings').then(function(resp) {
        var pc = (resp && resp.provider_config) ? resp.provider_config : {};
        var f = function(elId, statusId, ok, label) {
            var el = document.getElementById(elId);
            var st = document.getElementById(statusId);
            if (el && ok) { el.placeholder = '••••••••••••••••  (key saved)'; }
            if (st && ok) { st.innerHTML = ''+window.icon("check",14)+' ' + label + ' key is configured'; }
        };
        f('cr-set-minimax-key', 'cr-test-result-minimax', pc.minimax && pc.minimax.has_key, 'Video Engine');
        f('cr-set-runway-key',  'cr-test-result-runway',  pc.runway  && pc.runway.has_key,  'Video Engine (Backup)');
        f('cr-set-openai-key',  'cr-test-result-openai',  pc.openai  && pc.openai.has_key,  'Image Engine');
    }).catch(function(){});

    // Load cost map
    fetch((window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api')) + '/api/credits/cost-map', {
        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')}
    }).then(function(r){ return r.json(); }).then(function(d) {
        var map = d.cost_map || {};
        var rows = Object.keys(map).map(function(k) {
            var v = map[k];
            var display = typeof v === 'object'
                ? Object.entries(v).map(function(e){ return e[0]+': '+e[1]; }).join(' · ')
                : String(v);
            return '<tr><td style="padding:4px 8px;color:var(--t2);font-size:11px">' + _e(k.replace(/_/g,' ')) + '</td>' +
                   '<td style="padding:4px 8px;color:var(--ac);font-size:11px;font-weight:600">' + _e(display) + '</td></tr>';
        }).join('');
        var el = document.getElementById('cr-cost-map-table');
        if (el) el.innerHTML = '<table style="width:100%;border-collapse:collapse">' +
            '<thead><tr><th style="text-align:left;font-size:10px;color:var(--t3);padding:4px 8px;border-bottom:1px solid var(--bd)">Task</th>' +
            '<th style="text-align:left;font-size:10px;color:var(--t3);padding:4px 8px;border-bottom:1px solid var(--bd)">Credits</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>';
    }).catch(function(){});
}

async function _crSaveCreditSettings() {
    var g = function(id) { var el = document.getElementById(id); return el ? el.value : null; };
    var payload = {};
    if (g('cr-credit-enforce')) payload.enforcement      = g('cr-credit-enforce');
    if (g('cr-cost-img-std'))   payload.image_standard   = parseFloat(g('cr-cost-img-std'));
    if (g('cr-cost-img-hd'))    payload.image_hd          = parseFloat(g('cr-cost-img-hd'));
    if (g('cr-cap-request'))    payload.max_per_request   = parseInt(g('cr-cap-request'));
    if (g('cr-cap-minute'))     payload.max_per_minute    = parseInt(g('cr-cap-minute'));
    if (g('cr-cap-agent'))      payload.max_per_agent     = parseInt(g('cr-cap-agent'));
    try {
        var res = await fetch(
            (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api')) + '/api/credits/settings',
            { method:'PUT', headers:_crHeaders(), body: JSON.stringify(payload) }
        );
        var data = await res.json();
        var el = document.getElementById('cr-credit-save-result');
        if (el) el.textContent = data.success ? ''+window.icon("check",14)+' Credit settings saved' : ''+window.icon("close",14)+' Save failed';
        if (data.success) _crToast('Credit settings saved ✓', 'success');
    } catch(e) { _crToast('Save failed: ' + e.message, 'error'); }
}

async function _crAddTestCredits() {
    // Calls lu_credit_add via a thin internal endpoint — for testing only
    try {
        // Use the existing test flow: generate with a short prompt adds balance awareness
        _crToast('Use WP Admin → Credit Service to add credits in production', 'info');
        // For dev: directly update via a test endpoint if available
        var res = await fetch(
            (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api')) + '/api/credits/add-test',
            { method:'POST', headers:_crHeaders(), body: JSON.stringify({ amount: 100, reason: 'test_grant' }) }
        );
        if (res.ok) {
            var d = await res.json();
            var el = document.getElementById('cr-credit-balance-val');
            if (el && d.balance_after !== undefined) el.textContent = d.balance_after.toFixed(0);
            _crToast('Added 100 test credits ✓', 'success');
        }
    } catch(e) { _crToast('Top up via WP Admin directly', 'info'); }
}

async function _crSaveSettings() {
    var openaiKey   = ((document.getElementById('cr-set-openai-key')   || {}).value || '').trim();
    var runwayKey   = ((document.getElementById('cr-set-runway-key')   || {}).value || '').trim();
    var minimaxKey  = ((document.getElementById('cr-set-minimax-key')  || {}).value || '').trim();

    var payload = {
        settings: {
            default_provider: (document.getElementById('cr-set-provider') || {}).value || 'openai',
            default_quality:  (document.getElementById('cr-set-quality')  || {}).value || 'standard',
        },
        provider_config: {},
    };

    if (openaiKey)  payload.provider_config.openai  = { api_key: openaiKey };
    if (runwayKey)  payload.provider_config.runway  = { api_key: runwayKey };
    if (minimaxKey) payload.provider_config.minimax = { api_key: minimaxKey };

    try {
        await _crPut('/settings', payload);
        _cr.settings = payload.settings;

        // Clear fields + show saved placeholder for each key that was entered
        var clearKey = function(elId, statusId, label) {
            var el = document.getElementById(elId);
            var st = document.getElementById(statusId);
            if (el) { el.value = ''; el.placeholder = '••••••••••••••••  (key saved)'; }
            if (st) st.innerHTML = ''+window.icon("check",14)+' ' + label + ' key saved';
        };
        if (minimaxKey) clearKey('cr-set-minimax-key', 'cr-test-result-minimax', 'Video Engine');
        if (runwayKey)  clearKey('cr-set-runway-key',  'cr-test-result-runway',  'Video Engine (Backup)');
        if (openaiKey)  clearKey('cr-set-openai-key',  'cr-test-result-openai',  'Image Engine');

        _crToast('Settings saved ✓', 'success');
    } catch(e) { _crToast('Save failed: ' + e.message, 'error'); }
}

async function _crTestProvider(provider) {
    var el = document.getElementById('cr-test-result-openai');
    if (el) el.innerHTML = '⏳ Testing…';
    try {
        // Save key first if entered
        var keyEl = document.getElementById('cr-set-' + provider + '-key');
        if (keyEl && keyEl.value.trim()) {
            await _crPut('/settings', { provider_config: { [provider]: { api_key: keyEl.value.trim() } } });
        }
        var result = await _crPost('/generate/image', {
            prompt: 'A simple test: solid blue square, minimal',
            aspect_ratio: '1:1', quality: 'standard', count: 1, workspace_id: 1,
        });
        if (el) el.innerHTML = ''+window.icon("check",14)+' Connected — test image generated (Job #' + result.job_id + ')';
        _cr.assets = (result.assets || []).concat(_cr.assets);
    } catch(e) {
        if (el) el.innerHTML = ''+window.icon("close",14)+' Failed: ' + _e(e.message);
    }
}

async function _crTestMiniMaxKey() {
    var el    = document.getElementById('cr-test-result-minimax');
    var keyEl = document.getElementById('cr-set-minimax-key');
    var key   = (keyEl ? keyEl.value.trim() : '');

    if (!key) {
        if (el) el.innerHTML = '<span style="color:var(--am)">'+window.icon("warning",14)+' Enter the Video Engine API key first</span>';
        return;
    }
    if (el) el.innerHTML = '⏳ Saving and testing…';

    try {
        // Save the key
        await _crPut('/settings', { provider_config: { minimax: { api_key: key } } });

                if (el) el.innerHTML = '\u2705 Video Engine key saved';
        if (keyEl) { keyEl.value = ''; keyEl.placeholder = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022  (key saved)'; }
        _crToast('Video Engine key saved \u2713', 'success');

    } catch(e) {
        if (el) el.innerHTML = '<span style="color:var(--rd)">'+window.icon("close",14)+' ' + _e(e.message) + '</span>';
    }
}

async function _crTestRunwayKey() {
    var el    = document.getElementById('cr-test-result-runway');
    var keyEl = document.getElementById('cr-set-runway-key');
    var key   = (keyEl ? keyEl.value.trim() : '');

    if (!key) {
        if (el) el.innerHTML = '<span style="color:var(--am)">'+window.icon("warning",14)+' Enter the Backup Video Engine API key first</span>';
        return;
    }
    if (el) el.innerHTML = '⏳ Saving and testing…';

    try {
        // Save the key first via provider_config
        await _crPut('/settings', { provider_config: { runway: { api_key: key } } });

        // Confirm the key is saved by checking providers endpoint
        if (el) el.innerHTML = '\u2705 Video Engine key saved';
        if (keyEl) { keyEl.value = ''; keyEl.placeholder = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022  (key saved)'; }
        _crToast('Video Engine key saved \u2713', 'success');

    } catch(e) {
        if (el) el.innerHTML = '<span style="color:var(--rd)">'+window.icon("close",14)+' ' + _e(e.message) + '</span>';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PUBLIC API — Builder / Social / Marketing integration
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * window.luCreativePickAsset(callback)
 *
 * Called by Builder, Social, Marketing to open Creative library in pick mode.
 * callback(asset) is called with the chosen asset object.
 * Opens library view — user can also switch to Generate Image and pick from output.
 */
window.luCreativePickAsset = function(callback) {
    if (typeof callback !== 'function') return;
    _cr.pickMode = true;
    _cr.pickCb   = callback;
    // Navigate to creative engine
    if (typeof window.nav === 'function') window.nav('creative');
    setTimeout(function() {
        _cr.view = 'library';
        _crRender();
        _crToast('Pick mode: click any image to use it', 'info');
    }, 200);
};

// ═══════════════════════════════════════════════════════════════════════════════
// LOADING HTML
// ═══════════════════════════════════════════════════════════════════════════════

function _crLoadingHTML() {
    return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:300px;gap:14px">' +
        '<div class="cr-spinner"></div>' +
        '<div style="color:var(--t3);font-size:13px">Loading Creative Engine…</div>' +
    '</div>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// CSS
// ═══════════════════════════════════════════════════════════════════════════════

function _crCSS() {
    return `
@keyframes crSlideIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
@keyframes crSpin    { to{transform:rotate(360deg)} }

.cr-wrap{display:flex;flex-direction:column;height:100%;background:var(--bg);overflow:hidden}
.cr-header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid var(--bd);flex-shrink:0}
.cr-header-left{display:flex;align-items:center;gap:12px}
.cr-logo{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--p),var(--ac));display:flex;align-items:center;justify-content:center;font-size:18px}
.cr-title{font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1)}
.cr-sub{font-size:11px;color:var(--t3)}
.cr-nav{display:flex;gap:4px;padding:8px 24px;border-bottom:1px solid var(--bd);flex-shrink:0;overflow-x:auto}
.cr-tab{background:transparent;border:none;color:var(--t2);padding:7px 14px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all .15s}
.cr-tab:hover{background:var(--s2);color:var(--t1)}
.cr-tab-active{background:var(--ps);color:var(--pu);font-weight:600}
.cr-content{flex:1;overflow-y:auto;padding:24px}

.cr-card{background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-bottom:16px}
.cr-card-title{font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px;text-transform:uppercase;letter-spacing:.04em}
.cr-field-group{margin-bottom:12px}
.cr-field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.cr-label{display:block;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}
.cr-input{width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;color:var(--t1);font-size:13px;font-family:var(--fb);box-sizing:border-box;outline:none;transition:border-color .15s}
.cr-input:focus{border-color:var(--p)}
.cr-textarea{width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--t1);font-size:13px;font-family:var(--fb);resize:vertical;box-sizing:border-box;outline:none;transition:border-color .15s}
.cr-textarea:focus{border-color:var(--p)}
.cr-select{width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;color:var(--t1);font-size:13px;font-family:var(--fb);outline:none;cursor:pointer}
.cr-select-sm{width:auto;min-width:130px}
.cr-input-row{display:flex;gap:8px;align-items:center}
.cr-search{min-width:200px}

.cr-btn{background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);padding:8px 14px;font-size:12px;font-family:var(--fb);cursor:pointer;transition:all .15s;white-space:nowrap}
.cr-btn:hover{border-color:var(--p);color:var(--p)}
.cr-btn-primary{background:var(--p);border-color:var(--p);color:#fff}
.cr-btn-primary:hover{background:#5b4dd4;border-color:#5b4dd4;color:#fff}
.cr-btn-outline{background:transparent;border-color:var(--bd2);color:var(--t2)}
.cr-btn-danger{border-color:var(--rd);color:var(--rd)}
.cr-btn-danger:hover{background:rgba(248,113,113,.1)}
.cr-btn-sm{padding:5px 10px;font-size:11px}
.cr-btn-xs{padding:3px 7px;font-size:10px}
.cr-btn-lg{padding:11px 22px;font-size:14px;font-weight:600}
.cr-btn:disabled{opacity:.5;cursor:not-allowed}
.cr-link{background:none;border:none;color:var(--ac);cursor:pointer;font-size:12px;padding:0;text-decoration:underline}

.cr-gen-layout{display:grid;grid-template-columns:380px 1fr;gap:20px;height:100%}
.cr-gen-left{overflow-y:auto}
.cr-gen-right{min-height:400px}
.cr-gen-output{background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:20px;min-height:400px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;align-content:start}
.cr-gen-empty{grid-column:1/-1;display:flex;flex-direction:column;align-items:center;justify-content:center;height:300px;color:var(--t3);font-size:13px;text-align:center;gap:8px}
.cr-gen-spinner{grid-column:1/-1;display:flex;flex-direction:column;align-items:center;justify-content:center;height:300px;gap:12px}
.cr-gen-actions{display:flex;gap:10px;margin-top:4px}

.cr-result-card{background:var(--s2);border:1px solid var(--bd);border-radius:10px;overflow:hidden}
.cr-result-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
.cr-result-meta{padding:10px}
.cr-result-prompt{font-size:11px;color:var(--t3);margin-bottom:8px;line-height:1.4}
.cr-result-actions{display:flex;gap:5px;flex-wrap:wrap}

.cr-mode-tabs{display:flex;gap:4px;margin-bottom:10px}
.cr-mode-tab{background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--t2);padding:5px 12px;font-size:11px;cursor:pointer;transition:all .15s}
.cr-mode-active{background:var(--ps);border-color:var(--p);color:var(--pu);font-weight:600}

.cr-cost-preview{margin-top:4px}
.cr-cost-chip{font-size:11px;color:var(--t3);background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:4px 10px;display:inline-block}

.cr-notice{border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:12px}
.cr-notice-info{background:rgba(108,92,231,.1);border:1px solid rgba(108,92,231,.3);color:var(--pu)}
.cr-notice-success{background:rgba(0,229,168,.1);border:1px solid rgba(0,229,168,.3);color:var(--ac)}
.cr-notice-warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--am)}

.cr-dash{display:flex;flex-direction:column;gap:16px}
.cr-stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.cr-stat-card{background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:8px}
.cr-stat-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px}
.cr-stat-val{font-family:var(--fh);font-size:22px;font-weight:700;color:var(--t1)}
.cr-stat-label{font-size:11px;color:var(--t3)}
.cr-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.cr-section-header h3{font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t2);margin:0;text-transform:uppercase;letter-spacing:.04em}
.cr-quick-actions{display:flex;gap:10px;flex-wrap:wrap}
.cr-qa-btn{background:var(--s1);border:1px solid var(--bd);border-radius:12px;color:var(--t1);padding:16px 20px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:110px;transition:all .15s;font-family:var(--fb)}
.cr-qa-btn:hover{border-color:var(--p);background:var(--ps)}
.cr-qa-btn span{font-size:11px;color:var(--t3)}
.cr-thumb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
.cr-thumb-card{background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .15s}
.cr-thumb-card:hover{border-color:var(--p)}
.cr-thumb-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
.cr-thumb-meta{font-size:10px;color:var(--t3);padding:6px 8px}

.cr-lib-wrap{display:flex;flex-direction:column;gap:12px}
.cr-lib-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cr-lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.cr-lib-card{background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .15s;position:relative}
.cr-lib-card:hover{border-color:var(--p)}
.cr-lib-thumb{aspect-ratio:1;overflow:hidden;background:var(--s2);display:flex;align-items:center;justify-content:center;font-size:32px}
.cr-lib-thumb img{width:100%;height:100%;object-fit:cover}
.cr-lib-meta{padding:8px}
.cr-lib-prompt{font-size:11px;color:var(--t2);line-height:1.4;margin-bottom:4px}
.cr-lib-info{font-size:10px;color:var(--t3);margin-bottom:6px}
.cr-lib-actions{display:flex;gap:3px;flex-wrap:wrap}
.cr-lib-empty{grid-column:1/-1;text-align:center;padding:40px;color:var(--t3);font-size:13px}
.cr-pick-overlay{position:absolute;inset:0;background:rgba(108,92,231,.7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;opacity:0;transition:opacity .15s}
.cr-lib-card:hover .cr-pick-overlay{opacity:1}

.cr-bk-wrap{display:flex;flex-direction:column;gap:12px}
.cr-kit-card{background:var(--s1);border:1px solid var(--bd);border-radius:12px;padding:16px;margin-bottom:8px}
.cr-kit-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.cr-kit-name{font-weight:700;color:var(--t1);font-size:14px}
.cr-kit-actions{display:flex;gap:6px}
.cr-kit-meta{font-size:11px;color:var(--t3);margin-bottom:4px}
.cr-kit-keywords{font-size:11px;color:var(--t2)}
.cr-badge{font-size:9px;background:var(--ac);color:#0F1117;border-radius:4px;padding:1px 5px;font-weight:700;vertical-align:middle}

.cr-presets-wrap{display:flex;flex-direction:column;gap:8px}
.cr-preset-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.cr-preset-card{background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px}
.cr-preset-icon{font-size:22px;flex-shrink:0}
.cr-preset-body{flex:1;min-width:0}
.cr-preset-name{font-weight:600;color:var(--t1);font-size:13px}
.cr-preset-meta{font-size:10px;color:var(--t3);margin:2px 0}
.cr-preset-tone{font-size:11px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.cr-spinner{width:28px;height:28px;border:3px solid rgba(108,92,231,.2);border-top-color:var(--p);border-radius:50%;animation:crSpin .7s linear infinite}
@keyframes bcmShimmer { 0%{background-position:-600px 0} 100%{background-position:600px 0} }
.bcm-shimmer{background:linear-gradient(90deg,var(--s2) 25%,var(--s3) 50%,var(--s2) 75%);background-size:600px 100%;animation:bcmShimmer 1.4s infinite linear}
.bcm-var-card:hover{border-color:var(--p)!important}
`;
}


// =============================================================================
// BUILDER INLINE GENERATION v2 — Variations + AI Thinking + Smart Defaults
//
// New in this version:
//   - 3 variation mode: generates 3 images, shows selectable grid
//   - AI Thinking UX: shimmer → staged progress messages → reveal
//   - Smart Defaults: hero/banner/blog auto-fills prompt + preset + aspect
//   - Selected image gets insert button; others saved to Media Library
// =============================================================================

// ── Smart section detection ───────────────────────────────────────────────────
var _bcmSectionMeta = {
    hero:      { preset:'website-hero',    aspect:'16:9', prompt:'Stunning hero image for a modern professional website, cinematic wide-angle photography, rich depth of field, premium brand feel' },
    banner:    { preset:'website-section', aspect:'16:9', prompt:'Clean professional website banner image, modern business aesthetic, wide landscape composition' },
    blog:      { preset:'blog-featured',   aspect:'16:9', prompt:'Engaging blog featured image, editorial photography style, modern and informative' },
    email:     { preset:'email-banner',    aspect:'16:9', prompt:'Professional email banner, clean and minimal, strong visual hierarchy' },
    social:    { preset:'social-square',   aspect:'1:1',  prompt:'Eye-catching social media post image, vibrant and engaging, platform-optimized' },
    gallery:   { preset:'website-section', aspect:'1:1',  prompt:'High-quality gallery image, professional photography, sharp and well-lit' },
    cta:       { preset:'website-section', aspect:'16:9', prompt:'Compelling call-to-action background image, aspirational photography, creates urgency and desire' },
    about:     { preset:'website-section', aspect:'16:9', prompt:'Authentic team or workspace photography, warm professional atmosphere, genuine and trustworthy' },
    services:  { preset:'website-section', aspect:'16:9', prompt:'Professional service illustration or photography, clean modern aesthetic, inspires confidence' },
    default:   { preset:'website-section', aspect:'16:9', prompt:'Professional website section image, modern business photography, clean and polished' },
};

function _bcmDetectSection(sectionLabel) {
    var sl = (sectionLabel || '').toLowerCase();
    var keys = Object.keys(_bcmSectionMeta);
    for (var i = 0; i < keys.length; i++) {
        if (keys[i] !== 'default' && sl.indexOf(keys[i]) !== -1) return keys[i];
    }
    return 'default';
}

// ── Staged progress messages ──────────────────────────────────────────────────
var _bcmStages = [
    { t: 0,    msg: '✦ Reading your prompt…',              pct: 8  },
    { t: 800,  msg: ''+window.icon("ai",14)+' Applying brand & preset styles…',  pct: 22 },
    { t: 2200, msg: ''+window.icon("ai",14)+' Building enriched creative brief…', pct: 38 },
    { t: 3800, msg: '✦ Generating image…',              pct: 52 },
    { t: 5500, msg: ''+window.icon("image",14)+' Rendering variations…',             pct: 68 },
    { t: 7200, msg: '📦 Downloading & storing assets…',    pct: 82 },
    { t: 8500, msg: '✓ Almost ready…',                     pct: 94 },
];
var _bcmStageTimers = [];

function _bcmStartStages() {
    _bcmClearStages();
    _bcmStages.forEach(function(s) {
        var t = setTimeout(function() {
            var msgEl = document.getElementById('bcm-stage-msg');
            var barEl = document.getElementById('bcm-progress-bar');
            if (msgEl) msgEl.textContent = s.msg;
            if (barEl) barEl.style.width = s.pct + '%';
        }, s.t);
        _bcmStageTimers.push(t);
    });
}

function _bcmClearStages() {
    _bcmStageTimers.forEach(function(t) { clearTimeout(t); });
    _bcmStageTimers = [];
}

// ── Shimmer HTML ──────────────────────────────────────────────────────────────
function _bcmShimmerGrid(count) {
    var cards = '';
    for (var i = 0; i < count; i++) {
        cards += '<div style="border-radius:10px;overflow:hidden;border:1px solid var(--bd);background:var(--s2)">' +
            '<div class="bcm-shimmer" style="width:100%;aspect-ratio:16/9"></div>' +
        '</div>';
    }
    return '<div style="display:grid;grid-template-columns:repeat(' + count + ',1fr);gap:10px;margin-bottom:14px">' + cards + '</div>';
}

// ── Main modal builder ────────────────────────────────────────────────────────
window._bldCreativeGenerate = function(si, ci, mi, mode) {
    var existing = document.getElementById('bld-creative-modal');
    if (existing) existing.remove();

    // Detect section context
    var sectionLabel = '';
    try {
        if (window.bldCurrentPage && window.bldCurrentPage.sections && window.bldCurrentPage.sections[si]) {
            sectionLabel = window.bldCurrentPage.sections[si].type ||
                           window.bldCurrentPage.sections[si].label || '';
        }
    } catch(e) {}

    var sectionKey  = _bcmDetectSection(sectionLabel);
    var defaults    = _bcmSectionMeta[sectionKey];
    var isHeroLike  = (sectionKey === 'hero' || sectionKey === 'banner' || sectionKey === 'cta');

    // Build preset options
    var presetOpts = '<option value="">No preset</option>';
    var presets = (_cr && _cr.presets && _cr.presets.channel) ? _cr.presets.channel : [];
    presets.forEach(function(p) {
        var sel = p.slug === defaults.preset ? ' selected' : '';
        presetOpts += '<option value="' + _e(p.slug) + '"' + sel + '>' + _e(p.name) + '</option>';
    });

    var modal = document.createElement('div');
    modal.id = 'bld-creative-modal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)';

    // Detect if hero-like for compact "smart mode" vs full control mode
    var compactMode = isHeroLike;

    modal.innerHTML =
        '<div id="bcm-inner" style="background:var(--s1);border:1px solid var(--bd2);border-radius:20px;width:100%;max-width:640px;padding:28px;position:relative;box-shadow:0 32px 80px rgba(0,0,0,.7);animation:crSlideIn .2s ease">' +

            // Header
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px">' +
                '<div style="display:flex;align-items:center;gap:12px">' +
                    '<div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--p),var(--ac));display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;box-shadow:0 4px 14px rgba(108,92,231,.4)">'+window.icon("ai",14)+'</div>' +
                    '<div>' +
                        '<div style="font-family:var(--fh);font-size:15px;font-weight:800;color:var(--t1)">AI Image Generator</div>' +
                        '<div style="font-size:11px;color:var(--t3)">3 variations · inserts directly into section</div>' +
                    '</div>' +
                '</div>' +
                '<button onclick="document.getElementById(\'bld-creative-modal\').remove()" style="background:var(--s2);border:1px solid var(--bd);color:var(--t3);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:14px;line-height:1">✕</button>' +
            '</div>' +

            // Smart default badge (shown for hero-like sections)
            (compactMode ?
                '<div style="background:rgba(0,229,168,.08);border:1px solid rgba(0,229,168,.25);border-radius:8px;padding:8px 12px;margin-bottom:14px;display:flex;align-items:center;gap:8px">' +
                    '<span style="font-size:14px">'+window.icon("ai",14)+'</span>' +
                    '<div><div style="font-size:11px;font-weight:700;color:var(--ac)">Smart Default Applied</div>' +
                    '<div style="font-size:10px;color:var(--t3)">' + _e(sectionLabel || sectionKey) + ' section · ' + defaults.aspect + ' · ' + _e(defaults.preset) + '</div></div>' +
                    '<button onclick="_bcmToggleAdvanced()" id="bcm-adv-toggle" style="margin-left:auto;background:transparent;border:1px solid var(--bd);color:var(--t3);border-radius:6px;padding:3px 8px;cursor:pointer;font-size:10px">Customise ›</button>' +
                '</div>' : '') +

            // Prompt
            '<div style="margin-bottom:14px">' +
                '<label style="display:block;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Creative Prompt</label>' +
                '<textarea id="bcm-prompt" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;color:var(--t1);font-size:13px;font-family:var(--fb);resize:vertical;box-sizing:border-box;outline:none;min-height:72px;line-height:1.5;transition:border-color .15s" placeholder="Describe the image…">' +
                    _e(defaults.prompt) +
                '</textarea>' +
            '</div>' +

            // Advanced controls (hidden in compact/smart mode for hero)
            '<div id="bcm-advanced" style="' + (compactMode ? 'display:none;' : '') + 'margin-bottom:14px">' +
                '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">' +
                    '<div>' +
                        '<label style="display:block;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Preset</label>' +
                        '<select id="bcm-preset" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;color:var(--t1);font-size:12px;font-family:var(--fb);outline:none">' + presetOpts + '</select>' +
                    '</div>' +
                    '<div>' +
                        '<label style="display:block;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Ratio</label>' +
                        '<select id="bcm-aspect" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;color:var(--t1);font-size:12px;font-family:var(--fb);outline:none">' +
                            '<option value="16:9"' + (defaults.aspect === '16:9' ? ' selected' : '') + '>16:9</option>' +
                            '<option value="1:1"'  + (defaults.aspect === '1:1'  ? ' selected' : '') + '>1:1</option>' +
                            '<option value="9:16"' + (defaults.aspect === '9:16' ? ' selected' : '') + '>9:16</option>' +
                        '</select>' +
                    '</div>' +
                    '<div>' +
                        '<label style="display:block;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Quality</label>' +
                        '<select id="bcm-quality" style="width:100%;background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;color:var(--t1);font-size:12px;font-family:var(--fb);outline:none">' +
                            '<option value="standard">Standard</option>' +
                            '<option value="hd">HD</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            // Hidden defaults for compact mode
            '<input type="hidden" id="bcm-preset-default" value="' + _e(defaults.preset) + '">' +
            '<input type="hidden" id="bcm-aspect-default" value="' + _e(defaults.aspect) + '">' +
            '<input type="hidden" id="bcm-compact-mode"  value="' + (compactMode ? '1' : '0') + '">' +
            '<input type="hidden" id="bcm-si"    value="' + si + '">' +
            '<input type="hidden" id="bcm-ci"    value="' + ci + '">' +
            '<input type="hidden" id="bcm-mi"    value="' + (mi !== null && mi !== undefined ? mi : '') + '">' +
            '<input type="hidden" id="bcm-mode"  value="' + _e(mode) + '">' +

            // Generate button
            '<button id="bcm-gen-btn" onclick="_bldCreativeRun()" ' +
                'style="width:100%;background:linear-gradient(135deg,var(--p),var(--ac));border:none;border-radius:12px;color:#fff;padding:13px;font-size:14px;font-weight:800;cursor:pointer;font-family:var(--fh);letter-spacing:.02em;box-shadow:0 4px 18px rgba(108,92,231,.4);transition:opacity .15s">' +
                ''+window.icon("ai",14)+' Generate 3 Variations' +
            '</button>' +

            // AI Thinking panel (hidden until generation starts)
            '<div id="bcm-thinking" style="display:none;margin-top:16px">' +
                // Progress bar
                '<div style="background:var(--s2);border-radius:99px;height:3px;margin-bottom:12px;overflow:hidden">' +
                    '<div id="bcm-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--p),var(--ac));border-radius:99px;transition:width .6s ease"></div>' +
                '</div>' +
                // Stage message
                '<div id="bcm-stage-msg" style="text-align:center;font-size:12px;color:var(--t3);margin-bottom:16px;min-height:18px">✦ Reading your prompt…</div>' +
                // Shimmer grid placeholder
                '<div id="bcm-shimmer-grid"></div>' +
            '</div>' +

            // Variations output (hidden until done)
            '<div id="bcm-output" style="display:none;margin-top:16px">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">' +
                    '<div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em">Select a variation</div>' +
                    '<button onclick="_bldCreativeRun()" style="background:transparent;border:1px solid var(--bd);color:var(--t3);border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;font-family:var(--fb)">↺ Regenerate</button>' +
                '</div>' +
                '<div id="bcm-variation-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px"></div>' +
                '<div id="bcm-revised-prompt" style="font-size:11px;color:var(--t3);margin-bottom:12px;line-height:1.5;padding:8px 12px;background:var(--s2);border-radius:8px;display:none"></div>' +
                '<button id="bcm-insert-btn" onclick="_bldCreativeInsert()" disabled ' +
                    'style="width:100%;background:var(--ac);border:none;border-radius:10px;color:#0F1117;padding:12px;font-size:14px;font-weight:800;cursor:pointer;font-family:var(--fh);opacity:.4;transition:opacity .2s">' +
                    '← Select a variation above to insert' +
                '</button>' +
            '</div>' +

            // Error
            '<div id="bcm-error" style="display:none;background:rgba(248,113,113,.1);border:1px solid var(--rd);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--rd);margin-top:12px"></div>' +

        '</div>';

    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });

    // Focus prompt
    setTimeout(function() {
        var ta = document.getElementById('bcm-prompt');
        if (ta) { ta.focus(); ta.select(); }

        // Inject shimmer placeholder
        var sg = document.getElementById('bcm-shimmer-grid');
        if (sg) sg.innerHTML = _bcmShimmerGrid(3);
    }, 50);
};

function _bcmToggleAdvanced() {
    var adv = document.getElementById('bcm-advanced');
    var btn = document.getElementById('bcm-adv-toggle');
    if (!adv) return;
    var hidden = adv.style.display === 'none';
    adv.style.display = hidden ? 'block' : 'none';
    if (btn) btn.textContent = hidden ? '‹ Less' : 'Customise ›';
}

// ── State ─────────────────────────────────────────────────────────────────────
var _bcmPendingAssets  = [];
var _bcmSelectedIdx    = -1;
var _bcmPendingAsset   = null;
var _bcmRefineAssetId  = null;
var _bcmCurrentPrompt  = '';    // clean base prompt (reset on new generation)
var _bcmBasePrompt     = '';    // original user prompt — never modified by refinements
var _bcmRefineDepth    = 0;     // chain depth counter — reset on fresh generate
var _bcmRefineHistory  = [];    // tweak history for clean rebuild
var _bcmCurrentPreset  = '';
var _bcmCurrentAspect  = '16:9';
var _bcmCurrentQuality = 'standard';

// ── Variation labels — shown under each card ───────────────────────────────────
var _bcmVariationLabels = [
    { icon: ''+window.icon("edit",14)+'', label: 'Standard',     hint: 'Balanced composition'       },
    { icon: ''+window.icon("refresh",14)+'', label: 'Alternative',  hint: 'Different angle & framing'  },
    { icon: ''+window.icon("ai",14)+'', label: 'Stylistic',    hint: 'Distinct mood & lighting'   },
];

window._bldCreativeRun = async function(refineAssetId, refineTweak) {
    // refineAssetId: if set, this is a refine call on a specific variation
    // refineTweak: optional user-typed tweak text

    var isRefine = !!refineAssetId;

    var prompt   = ((document.getElementById('bcm-prompt')  || {}).value || '').trim();
    var compact  = (document.getElementById('bcm-compact-mode') || {}).value === '1';
    var preset   = compact
        ? (document.getElementById('bcm-preset-default') || {}).value || 'website-section'
        : (document.getElementById('bcm-preset')         || {}).value || '';
    var aspect   = compact
        ? (document.getElementById('bcm-aspect-default') || {}).value || '16:9'
        : (document.getElementById('bcm-aspect')         || {}).value || '16:9';
    var quality  = (document.getElementById('bcm-quality') || {}).value || 'standard';
    var si       = parseInt((document.getElementById('bcm-si')   || {}).value) || 0;
    var ci       = parseInt((document.getElementById('bcm-ci')   || {}).value) || 0;
    var miRaw    = (document.getElementById('bcm-mi')   || {}).value;
    var mi       = miRaw !== '' && miRaw !== 'null' ? parseInt(miRaw) : null;
    var mode     = (document.getElementById('bcm-mode') || {}).value || 'new';

    // ── Prompt resolution with chain depth control ────────────────────────
    // Limit: after 2 stacked refinements, rebuild cleanly from base + latest tweak.
    // This prevents prompt bloat and style drift from stacked modifiers.
    var MAX_REFINE_DEPTH = 2;
    if (isRefine && refineTweak) {
        _bcmRefineDepth++;
        _bcmRefineHistory.push(refineTweak);
        if (_bcmRefineDepth > MAX_REFINE_DEPTH) {
            // Clean rebuild — discard stacked tweaks, use base + latest only
            prompt = refineTweak + ', ' + _bcmBasePrompt;
            console.log('[LuCreative] Refine depth exceeded — rebuilding from base');
        } else {
            prompt = refineTweak + '. ' + _bcmCurrentPrompt;
        }
        _bcmCurrentPrompt = prompt;
    } else if (!isRefine) {
        _bcmBasePrompt     = prompt;
        _bcmCurrentPrompt  = prompt;
        _bcmRefineDepth    = 0;
        _bcmRefineHistory  = [];
        _bcmCurrentPreset  = preset;
        _bcmCurrentAspect  = aspect;
        _bcmCurrentQuality = quality;
    }

    if (!prompt) {
        var errEl = document.getElementById('bcm-error');
        if (errEl) { errEl.style.display = 'block'; errEl.textContent = 'Please describe the image you want.'; }
        return;
    }

    // ── Show AI Thinking UX ──────────────────────────────────────────────────
    var btn      = document.getElementById('bcm-gen-btn');
    var thinking = document.getElementById('bcm-thinking');
    var output   = document.getElementById('bcm-output');
    var errEl    = document.getElementById('bcm-error');
    var shimmer  = document.getElementById('bcm-shimmer-grid');
    if (btn)      { btn.disabled = true; btn.style.opacity = '.5'; btn.textContent = isRefine ? '⏳ Refining…' : '⏳ Generating…'; }
    if (thinking) thinking.style.display = 'block';
    if (output)   output.style.display   = 'none';
    if (errEl)    errEl.style.display    = 'none';
    // In refine mode generate 1, else 3
    var genCount = isRefine ? 1 : 3;
    if (shimmer)  shimmer.innerHTML = _bcmShimmerGrid(genCount);
    if (!isRefine) { _bcmPendingAssets = []; _bcmSelectedIdx = -1; _bcmPendingAsset = null; }

    _bcmStartStages();
    var startTime = Date.now();

    try {
        var payload = {
            prompt:         prompt,
            aspect_ratio:   isRefine ? _bcmCurrentAspect  : aspect,
            quality:        isRefine ? _bcmCurrentQuality : quality,
            count:          genCount,
            channel_preset: isRefine ? _bcmCurrentPreset  : preset,
            workspace_id:   1,
        };

        // Log generation_requested for Creative Memory
        fetch(_crUrl('/log-event'), {
            method:'POST', headers:_crHeaders(),
            body:JSON.stringify({
                event_type: isRefine ? 'refinement_used' : 'generation_requested',
                context: {
                    prompt_length:   prompt.length,
                    channel_preset:  payload.channel_preset,
                    aspect_ratio:    payload.aspect_ratio,
                    quality:         payload.quality,
                    count:           genCount,
                    refine_depth:    _bcmRefineDepth,
                    is_rebuild:      _bcmRefineDepth > 2,
                }
            })
        }).catch(function(){});

        var result = await _crPost('/generate/image', payload);
        if (!result.success || !result.assets || !result.assets.length) {
            throw new Error(result.message || 'Generation failed — no assets returned.');
        }

        if (isRefine) {
            // In refine mode: replace only the selected card with the new asset
            _bcmPendingAssets[_bcmSelectedIdx] = result.assets[0];
        } else {
            _bcmPendingAssets = result.assets;
        }

        // Enforce minimum perceived duration
        var elapsed   = Date.now() - startTime;
        var remaining = Math.max(0, 6000 - elapsed);
        var barEl = document.getElementById('bcm-progress-bar');
        var msgEl = document.getElementById('bcm-stage-msg');
        if (barEl) barEl.style.width = '100%';
        if (msgEl) msgEl.textContent = isRefine ? '✓ Refined variation ready' : '✓ ' + _bcmPendingAssets.length + ' variations ready';
        await new Promise(function(r) { setTimeout(r, remaining); });
        _bcmClearStages();

        if (thinking) thinking.style.display = 'none';
        if (output)   output.style.display   = 'block';

        _bcmRenderVariationGrid(aspect || _bcmCurrentAspect);

        // Revised prompt note
        var rev   = result.assets[0] && result.assets[0].revised_prompt;
        var revEl = document.getElementById('bcm-revised-prompt');
        if (revEl && rev && rev !== payload.prompt) {
            revEl.style.display = 'block';
            revEl.innerHTML = '<span style="color:var(--pu);font-weight:600">✦ AI enhanced:</span> ' + _e(rev.substring(0, 140)) + '…';
        }

    } catch(e) {
        _bcmClearStages();
        if (thinking) thinking.style.display = 'none';
        if (errEl) {
            errEl.style.display = 'block';
            errEl.innerHTML = ''+window.icon("close",14)+' <strong>' + _e(e.message || 'Generation failed') + '</strong>' +
                '<div style="margin-top:4px;font-size:11px;color:var(--t3)">Check your Image Engine API key in Creative → Settings</div>';
        }
        console.error('[LuCreative Builder]', e);
    } finally {
        _bcmClearStages();
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.textContent = ''+window.icon("ai",14)+' Generate 3 Variations'; }
    }
};

// ── Render variation grid (called after generation + after refine) ─────────────
function _bcmRenderVariationGrid(aspect) {
    var grid = document.getElementById('bcm-variation-grid');
    if (!grid) return;
    grid.innerHTML = _bcmPendingAssets.map(function(a, idx) {
        var meta   = _bcmVariationLabels[idx] || { icon:'✦', label:'Variation ' + (idx+1), hint:'' };
        var isSel  = idx === _bcmSelectedIdx;
        return '<div id="bcm-var-' + idx + '" ' +
            'onclick="_bcmSelectVariation(' + idx + ')" ' +
            'style="border-radius:10px;overflow:hidden;border:2px solid ' + (isSel ? 'var(--ac)' : 'var(--bd)') + ';' +
                'cursor:pointer;position:relative;transition:border-color .18s,transform .18s,box-shadow .18s;background:var(--s2);' +
                (isSel ? 'transform:scale(1.03);box-shadow:0 0 0 3px rgba(0,229,168,.3)' : '') + '">' +

            // Image
            '<img src="' + _e(a.thumbnail_url || a.web_url || a.public_url) + '" ' +
                'style="width:100%;display:block;aspect-ratio:' + _bcmAspectCss(aspect) + ';object-fit:cover" ' +
                'alt="' + _e(meta.label) + '" loading="eager">' +

            // Number badge top-left
            '<div style="position:absolute;top:6px;left:6px;background:rgba(0,0,0,.65);color:#fff;font-size:10px;font-weight:700;border-radius:4px;padding:2px 7px;backdrop-filter:blur(2px)">' + (idx+1) + '</div>' +

            // Selected checkmark overlay
            '<div id="bcm-var-check-' + idx + '" style="display:' + (isSel ? 'flex' : 'none') + ';position:absolute;inset:0;background:rgba(0,229,168,.18);border-radius:8px;align-items:flex-start;justify-content:flex-end;padding:6px">' +
                '<div style="width:22px;height:22px;background:var(--ac);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#0F1117">✓</div>' +
            '</div>' +

            // Label bar
            '<div style="padding:7px 8px 8px;background:var(--s1);border-top:1px solid var(--bd)">' +
                '<div style="display:flex;align-items:center;justify-content:space-between">' +
                    '<div>' +
                        '<div style="font-size:11px;font-weight:700;color:var(--t1)">' + meta.icon + ' ' + _e(meta.label) + '</div>' +
                        '<div style="font-size:9px;color:var(--t3)">' + _e(meta.hint) + '</div>' +
                    '</div>' +
                    // Refine + Favorite buttons
                    '<div style="display:flex;gap:4px;align-items:center">' +
                        '<button id="bcm-refine-btn-' + idx + '" ' +
                            'onclick="event.stopPropagation();_bcmOpenRefine(' + idx + ')" ' +
                            'style="display:' + (isSel ? 'block' : 'none') + ';background:var(--s3);border:1px solid var(--bd);border-radius:5px;color:var(--t2);padding:3px 8px;font-size:10px;cursor:pointer;font-family:var(--fb);white-space:nowrap">Refine ›</button>' +
                        '<button id="bcm-fav-btn-' + idx + '" ' +
                            'onclick="event.stopPropagation();_bcmToggleFavoriteVar(' + idx + ')" ' +
                            'title="Save as favorite" ' +
                            'style="background:transparent;border:1px solid var(--bd);border-radius:5px;color:var(--t3);padding:3px 7px;font-size:11px;cursor:pointer;line-height:1;transition:color .15s">☆</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
}

// ── Select a variation ────────────────────────────────────────────────────────
function _bcmSelectVariation(idx) {
    _bcmSelectedIdx = idx;

    var assets = _bcmPendingAssets;
    for (var i = 0; i < assets.length; i++) {
        var card   = document.getElementById('bcm-var-' + i);
        var check  = document.getElementById('bcm-var-check-' + i);
        var refBtn = document.getElementById('bcm-refine-btn-' + i);
        if (card) {
            card.style.borderColor = (i === idx) ? 'var(--ac)' : 'var(--bd)';
            card.style.transform   = (i === idx) ? 'scale(1.03)' : 'scale(1)';
            card.style.boxShadow   = (i === idx) ? '0 0 0 3px rgba(0,229,168,.3)' : 'none';
        }
        if (check)  check.style.display  = (i === idx) ? 'flex'  : 'none';
        if (refBtn) refBtn.style.display = (i === idx) ? 'block' : 'none';
    }

    _bcmPendingAsset = {
        si:    parseInt((document.getElementById('bcm-si')   || {}).value) || 0,
        ci:    parseInt((document.getElementById('bcm-ci')   || {}).value) || 0,
        mi:    (function(){ var v=(document.getElementById('bcm-mi')||{}).value; return v && v!=='null' ? parseInt(v) : null; })(),
        mode:  (document.getElementById('bcm-mode') || {}).value || 'new',
        asset: assets[idx],
    };

    var btn = document.getElementById('bcm-insert-btn');
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.background = 'var(--ac)';
        btn.textContent = '✓ Insert Variation ' + (idx + 1) + ' into Page';
    }

    // ── Log variation_selected for Creative Memory ─────────────────────────
    var selAsset = _bcmPendingAssets[idx];
    if (selAsset && selAsset.id) {
        fetch(_crUrl('/log-event'), {
            method: 'POST', headers: _crHeaders(),
            body: JSON.stringify({
                event_type:    'variation_selected',
                asset_id:      parseInt(selAsset.id),
                variation_idx: idx,
                context: {
                    channel_preset:  _bcmCurrentPreset,
                    aspect_ratio:    _bcmCurrentAspect,
                    variation_label: (_bcmVariationLabels[idx] || {}).label || '',
                    refine_depth:    _bcmRefineDepth,
                }
            })
        }).catch(function(){});
    }
}

// ── Refine flow — opens inline tweak input below selected variation ────────────
function _bcmOpenRefine(idx) {
    // Remove any existing refine panel
    var existing = document.getElementById('bcm-refine-panel');
    if (existing) { existing.remove(); return; }

    var grid = document.getElementById('bcm-variation-grid');
    if (!grid) return;

    var panel = document.createElement('div');
    panel.id = 'bcm-refine-panel';
    panel.style.cssText = 'background:var(--s2);border:1px solid var(--bd2);border-radius:10px;padding:12px 14px;margin-top:10px;animation:crSlideIn .15s ease';
    panel.innerHTML =
        '<div style="font-size:11px;font-weight:700;color:var(--ac);margin-bottom:8px">✦ Refine Variation ' + (idx+1) + '</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">' +
            _bcmRefineChip('More cinematic') + _bcmRefineChip('Brighter & warmer') +
            _bcmRefineChip('Darker & moodier') + _bcmRefineChip('More minimal') +
            _bcmRefineChip('More vibrant') + _bcmRefineChip('Luxury premium feel') +
        '</div>' +
        '<div style="display:flex;gap:8px;align-items:center">' +
            '<input id="bcm-refine-input" type="text" placeholder="Or describe what to change…" ' +
                'style="flex:1;background:var(--s1);border:1px solid var(--bd);border-radius:7px;padding:8px 11px;color:var(--t1);font-size:12px;font-family:var(--fb);outline:none">' +
            '<button onclick="_bcmRunRefine(' + idx + ')" ' +
                'style="background:var(--p);border:none;border-radius:7px;color:#fff;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:var(--fb);white-space:nowrap">Refine '+window.icon("ai",14)+'</button>' +
        '</div>';

    grid.parentNode.insertBefore(panel, grid.nextSibling);

    var inp = document.getElementById('bcm-refine-input');
    if (inp) inp.focus();
}

function _bcmRefineChip(label) {
    return '<button onclick="document.getElementById(\'bcm-refine-input\').value=\'' + label + '\'" ' +
        'style="background:var(--s3);border:1px solid var(--bd);border-radius:5px;color:var(--t2);padding:3px 9px;font-size:10px;cursor:pointer;font-family:var(--fb)">' +
        label + '</button>';
}

function _bcmRunRefine(idx) {
    var tweak = ((document.getElementById('bcm-refine-input') || {}).value || '').trim();
    if (!tweak) { _crToast('Describe what to change first', 'warning'); return; }
    var panel = document.getElementById('bcm-refine-panel');
    if (panel) panel.remove();

    // Log the refine action for Creative Memory
    var selAsset = _bcmPendingAssets[idx];
    if (selAsset && selAsset.id) {
        fetch(_crUrl('/log-event'), {
            method: 'POST', headers: _crHeaders(),
            body: JSON.stringify({
                event_type:    'variation_refined',
                asset_id:      parseInt(selAsset.id),
                variation_idx: idx,
                context: {
                    tweak:       tweak,
                    refine_depth: _bcmRefineDepth + 1,
                    is_clean_rebuild: (_bcmRefineDepth + 1) > 2,
                }
            })
        }).catch(function(){});
    }

    // If hitting depth limit, show brief notice
    if (_bcmRefineDepth >= 2) {
        _crToast('Rebuilding cleanly from base + your latest change', 'info');
    }

    _bldCreativeRun(true, tweak);
}

// ── Favorite toggle — variation grid ─────────────────────────────────────────
function _bcmToggleFavoriteVar(idx) {
    var asset = _bcmPendingAssets[idx];
    if (!asset || !asset.id) return;
    var btn = document.getElementById('bcm-fav-btn-' + idx);
    var isFav = btn && btn.textContent === '★';
    fetch(_crUrl('/assets/' + parseInt(asset.id) + '/favorite'), {
        method: 'POST', headers: _crHeaders(), body: '{}'
    }).then(function(r){ return r.json(); }).then(function(res) {
        if (btn) {
            btn.textContent = res.is_favorite ? '★' : '☆';
            btn.style.color  = res.is_favorite ? 'var(--am)' : 'var(--t3)';
            btn.title        = res.is_favorite ? 'Saved as favorite!' : 'Save as favorite';
        }
        _crToast(res.is_favorite ? ''+window.icon("star",14)+' Saved as favorite' : 'Removed from favorites', 'info');
    }).catch(function(){ _crToast('Could not update favorite', 'error'); });
}

// ── Insert with confirmation glow ─────────────────────────────────────────────
window._bldCreativeInsert = function() {
    if (!_bcmPendingAsset) return;
    var d    = _bcmPendingAsset;
    var url  = d.asset.web_url || d.asset.public_url;
    var mode = d.mode;

    // ── 1. Transform modal into success state immediately ────────────────────
    var inner = document.getElementById('bcm-inner');
    if (inner) {
        inner.style.transition = 'transform .2s ease, box-shadow .3s ease';
        inner.style.boxShadow  = '0 0 0 3px var(--ac), 0 32px 80px rgba(0,229,168,.25)';
        inner.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 24px;text-align:center;gap:16px">' +
                // Success icon with glow pulse
                '<div id="bcm-success-icon" style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--p),var(--ac));display:flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 0 0 0 rgba(0,229,168,.6);animation:bcmGlowPulse 1.2s ease-out 2">' +
                    '✓' +
                '</div>' +
                '<div>' +
                    '<div style="font-family:var(--fh);font-size:18px;font-weight:800;color:var(--t1);margin-bottom:6px">'+window.icon("ai",14)+' Applied to your page</div>' +
                    '<div style="font-size:12px;color:var(--t3)">Image inserted into Builder section</div>' +
                '</div>' +
                // Thumbnail of inserted image
                '<img src="' + _e(d.asset.thumbnail_url || url) + '" ' +
                    'style="width:120px;border-radius:10px;border:2px solid var(--ac);box-shadow:0 0 20px rgba(0,229,168,.2)">' +
                '<div style="display:flex;gap:8px">' +
                    '<button onclick="document.getElementById(\'bld-creative-modal\').remove()" ' +
                        'style="background:var(--ac);border:none;border-radius:8px;color:#0F1117;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fb)">Done</button>' +
                    '<button onclick="_bcmReopenGenerate()" ' +
                        'style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);padding:9px 16px;font-size:13px;cursor:pointer;font-family:var(--fb)">Generate Another</button>' +
                '</div>' +
            '</div>';

        // Inject glow pulse keyframe if not already present
        if (!document.getElementById('bcm-glow-style')) {
            var style = document.createElement('style');
            style.id  = 'bcm-glow-style';
            style.textContent = '@keyframes bcmGlowPulse { 0%{box-shadow:0 0 0 0 rgba(0,229,168,.7)} 70%{box-shadow:0 0 0 20px rgba(0,229,168,0)} 100%{box-shadow:0 0 0 0 rgba(0,229,168,0)} }';
            document.head.appendChild(style);
        }
    }

    // ── 2. Write to component data + re-render Builder ───────────────────────
    try {
        if (mode === 'new') {
            var cmp = window.bldCurrentPage &&
                      window.bldCurrentPage.sections &&
                      window.bldCurrentPage.sections[d.si] &&
                      window.bldCurrentPage.sections[d.si].containers &&
                      window.bldCurrentPage.sections[d.si].containers[d.ci] &&
                      window.bldCurrentPage.sections[d.si].containers[d.ci].components &&
                      window.bldCurrentPage.sections[d.si].containers[d.ci].components[d.mi];
            if (cmp) { if (!cmp.content) cmp.content = {}; cmp.content.src = url; cmp.content.alt = 'AI Generated Image'; }
        } else {
            var cmp2 = window.bldCurrentPage &&
                       window.bldCurrentPage.sections &&
                       window.bldCurrentPage.sections[d.si] &&
                       window.bldCurrentPage.sections[d.si].components &&
                       window.bldCurrentPage.sections[d.si].components[d.ci];
            if (cmp2) {
                // PATCH 7: Previously wrote cmp2.src/cmp2.alt at root — renderer reads
                // cmp.content.src so the image never appeared. Mirror the mode='new' path.
                if (!cmp2.content) cmp2.content = {};
                cmp2.content.src = url;
                cmp2.content.alt = 'AI Generated Image';
                // Keep legacy root keys for Arthur find-by-type queries (backward compat)
                cmp2.src = url;
                cmp2.alt = 'AI Generated Image';
            }
        }

        var srcInput = document.getElementById('bld-prop-img-src') || document.getElementById('bld-p-src');
        if (srcInput) { srcInput.value = url; }

        if (typeof window.bldRenderCanvas === 'function') { window.bldDirty = true; window.bldRenderCanvas(); }

        // Track usage
        fetch(_crUrl('/assets/' + d.asset.id + '/link'), {
            method: 'POST', headers: _crHeaders(),
            body: JSON.stringify({ link_type: 'builder_section', link_id: d.si, workspace_id: 1 })
        }).catch(function(){});

    } catch(e) { console.error('[LuCreative] Insert failed:', e); }

    // ── 3. Auto-close after 4 seconds ────────────────────────────────────────
    setTimeout(function() {
        var modal = document.getElementById('bld-creative-modal');
        if (modal) {
            modal.style.transition = 'opacity .3s ease';
            modal.style.opacity    = '0';
            setTimeout(function() { if (modal.parentNode) modal.remove(); }, 300);
        }
    }, 4000);
};

function _bcmReopenGenerate() {
    var modal = document.getElementById('bld-creative-modal');
    if (modal) modal.remove();
    // Re-open with same si/ci/mi if available — stored values gone since modal rebuilt
    // Users can click ${window.icon('ai',14)} Generate with AI again from Builder
    if (typeof _crToast === 'function') _crToast('Click '+window.icon("ai",14)+' Generate with AI on any image component', 'info');
}

// ── window.luCreativeInsertImage — public API ─────────────────────────────────
window.luCreativeInsertImage = function(url) {
    if (!url) return;
    window.dispatchEvent(new CustomEvent('lu:creative:image_ready', { detail: { url: url } }));
    var srcInput = document.getElementById('bld-prop-img-src') || document.getElementById('bld-p-src');
    if (srcInput) { srcInput.value = url; srcInput.dispatchEvent(new Event('input', { bubbles: true })); }
};

// ── Kill switch controls ───────────────────────────────────────────────────────
async function _crSetKillSwitch(active, scope) {
    scope = scope || 'all';
    var reason = active ? (prompt('Reason for ' + (scope === 'all' ? 'full' : scope) + ' shutdown (optional):') || '') : '';
    try {
        var luBase = (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api'));
        var res = await fetch(luBase + '/api/credits/kill-switch', {
            method: 'POST', headers: _crHeaders(),
            body: JSON.stringify({ active: active, scope: scope, reason: reason })
        });
        var data = await res.json();
        if (data.success) {
            var ks = data.kill_switch || {};
            var banner = document.getElementById('cr-killswitch-banner');
            var reasonEl = document.getElementById('cr-ks-reason');
            if (banner) banner.style.display = ks.active ? 'flex' : 'none';
            if (reasonEl) reasonEl.textContent = ks.reason || '';
            _crToast(active ? (''+window.icon("close",14)+' Kill switch activated — ' + scope) : '✓ All systems resumed', active ? 'warning' : 'success');
        }
    } catch(e) { _crToast('Kill switch error: ' + e.message, 'error'); }
}

async function _crDeactivateKillSwitch() { await _crSetKillSwitch(false); }

// Load kill switch status on settings mount
var _crOrigBindSettings = typeof _crBindSettings === 'function' ? _crBindSettings : function(){};
_crBindSettings = function() {
    _crOrigBindSettings();
    // Check kill switch status
    var luBase = (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api'));
    fetch(luBase + '/api/credits/kill-switch', { headers: _crHeaders() })
        .then(function(r){ return r.json(); })
        .then(function(ks) {
            var banner = document.getElementById('cr-killswitch-banner');
            var reasonEl = document.getElementById('cr-ks-reason');
            if (banner) banner.style.display = ks.active ? 'flex' : 'none';
            if (reasonEl) reasonEl.textContent = ks.reason || '';
        }).catch(function(){});
};


// =============================================================================
// AGENT DECISION INTELLIGENCE — LU_Agent_Decision UI layer
//
// When lu_tools_execute returns status:'cost_warn' or status:'cost_abort',
// the platform SPA should surface this to the user as a decision panel.
// This function renders the multi-option response inline.
// =============================================================================

/**
 * Render a cost decision panel into a container element.
 * Called by the agent chat / tool runner when a cost_warn or cost_abort is returned.
 *
 * @param {object} decisionData   Response from /tools/decide or /tools/execute
 * @param {string} containerId    DOM element to render into
 * @param {function} onChoice     Callback(chosenOption) when user picks
 */
window.luRenderDecisionPanel = function(decisionData, containerId, onChoice) {
    var container = document.getElementById(containerId);
    if (!container) return;

    var d    = decisionData || {};
    var opts = d.options || [];
    var isAbort = d.decision === 'abort';
    var headerColor = isAbort ? 'var(--rd)' : 'var(--am)';
    var headerIcon  = isAbort ? '✗' : ''+window.icon("warning",14)+'';
    var headerLabel = isAbort ? 'Cannot Proceed' : 'Cost Warning';

    var optionCards = opts.map(function(opt, idx) {
        var isRecommended = !!opt.recommended;
        var isSkip        = opt.action === 'skip';
        var border = isRecommended ? 'var(--ac)' : 'var(--bd)';
        var badge  = isRecommended ? '<span style="font-size:9px;background:var(--ac);color:#0F1117;border-radius:3px;padding:1px 5px;font-weight:700;margin-left:6px">Recommended</span>' : '';
        return '<div style="border:1px solid ' + border + ';border-radius:10px;padding:12px;cursor:pointer;transition:border-color .15s;' + (isSkip ? 'opacity:.6' : '') + '" ' +
            'onclick="luHandleDecisionChoice(' + JSON.stringify(opt).replace(/"/g,'&quot;') + ',{log_id:' + (decisionData.log_id||0) + ',original_cost:' + (decisionData.cost||0) + ',agent_id:\"' + (decisionData.agent_id||'user') + '\",approval_token:\"' + (decisionData.approval_token||'') + '\"})">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">' +
                '<div style="font-size:12px;font-weight:700;color:var(--t1)">' + _e(opt.label) + badge + '</div>' +
                '<div style="font-size:11px;font-weight:600;color:' + (isSkip ? 'var(--t3)' : 'var(--ac)') + '">' + (opt.cost > 0 ? opt.cost + ' credits' : 'Free') + '</div>' +
            '</div>' +
            '<div style="font-size:11px;color:var(--t3)">' + _e(opt.description || '') + '</div>' +
        '</div>';
    }).join('');

    container.innerHTML = '<div style="background:var(--s1);border:1px solid ' + headerColor + ';border-radius:12px;padding:16px;margin:10px 0">' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">' +
            '<div style="width:28px;height:28px;border-radius:50%;background:' + headerColor + '22;color:' + headerColor + ';display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px">' + headerIcon + '</div>' +
            '<div>' +
                '<div style="font-weight:700;color:var(--t1);font-size:13px">' + headerLabel + '</div>' +
                '<div style="font-size:11px;color:var(--t3)">' + _e(d.message || d.reason || '') + '</div>' +
            '</div>' +
        '</div>' +
        '<div style="font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Choose an option:</div>' +
        '<div style="display:flex;flex-direction:column;gap:8px">' + optionCards + '</div>' +
    '</div>';

    // Store the callback for the choice handler
    window._luDecisionCallbacks = window._luDecisionCallbacks || {};
    window._luDecisionCallbacks[containerId] = onChoice;
};

window.luHandleDecisionChoice = function(option, context) {
    context = context || {};
    var luBase = (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api'));

    // ④ Post outcome telemetry
    fetch(luBase + '/api/tools/decide/outcome', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
        body: JSON.stringify({
            decision_row_id:       context.decision_row_id || 0,
            log_id:                context.log_id          || 0,
            option_index:          option.index            || 0,
            option_action:         option.action           || 'proceed',
            option_label:          option.label            || '',
            option_cost:           option.cost             || 0,
            option_was_recommended:option.recommended      || false,
            original_cost:         context.original_cost   || 0,
            agent_id:              context.agent_id        || 'user',
        })
    }).catch(function(){});

    // User feedback
    if (typeof _crToast === 'function') {
        if (option.action === 'skip') {
            _crToast('Skipped — credits preserved', 'info');
        } else if (option.recommended) {
            _crToast('✓ Using recommended option: ' + (option.label || '') + ' (' + (option.cost || 0) + ' credits)', 'success');
        } else {
            _crToast('Using: ' + (option.label || 'selected option') + ' (' + (option.cost || 0) + ' credits)', 'info');
        }
    }

    // Dispatch event — include approval_token so the executing engine can resume the call
    window.dispatchEvent(new CustomEvent('lu:agent:decision_made', {
        detail: {
            option:          option,
            approval_token:  context.approval_token || null,
            original_params: context.original_params || {},
        }
    }));

    // Clear panel
    document.querySelectorAll('[id*="decision-panel"]').forEach(function(p) { p.innerHTML = ''; });
};

/**
 * Pre-flight: call /tools/decide before showing tool execution UI.
 * Returns a promise resolving to the decision result.
 */
window.luPreflightToolDecision = async function(toolId, params, agentId) {
    agentId = agentId || 'user';
    try {
        var luBase = (window.LU_CFG ? (window.LU_API_BASE || '/api') : (window.LU_API_BASE || '/api'));
        var res = await fetch(luBase + '/api/tools/decide', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
            body: JSON.stringify({ tool_id: toolId, params: params, agent_id: agentId })
        });
        return await res.json();
    } catch(e) {
        console.error('[LuDecision] preflight failed:', e);
        return { decision: 'proceed', can_execute: true, cost: 0 };
    }
};

function _crScanWebsite() {
    var url = prompt('Enter a website URL to scan for marketing intelligence:\n(e.g. https://example.com)', 'https://');
    if (!url || url === 'https://') return;

    // Basic client-side URL validation
    try {
        var parsed = new URL(url);
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            alert('URL must start with http:// or https://');
            return;
        }
    } catch (e) {
        alert('Please enter a valid URL (e.g. https://example.com)');
        return;
    }

    // Show loading state
    _crToast('Scanning ' + url + ' …', 'info');

    // Call the REST endpoint
    var apiBase = (window.LU_CFG && window.LU_CFG.restUrl)
        ? (window.LU_API_BASE || '/api')
        : ((window.LU_API_BASE || '/api'));
    var nonce = (window.LU_CFG && '') ? '' : '';

    fetch(apiBase + '/api/creative/scan-url', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '')},
        body:    JSON.stringify({ url: url }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data || !data.success) {
            _crToast('Scan failed: ' + (data && data.error ? data.error : 'Unknown error'), 'error');
            return;
        }
        _crShowScanResults(url, data.data, data.blueprint_id);
    })
    .catch(function(err) {
        _crToast('Scan error: ' + err.message, 'error');
    });
}

function _crShowScanResults(url, d, blueprintId) {
    // Render results in a modal overlay
    var existing = document.getElementById('cr-scan-results-modal');
    if (existing) existing.remove();

    var hooks    = (d.hooks        || []).map(function(h) { return '<li>' + _e(h) + '</li>'; }).join('');
    var msgs     = (d.messaging    || []).map(function(m) { return '<li>' + _e(m.substring(0, 120)) + (m.length > 120 ? '…' : '') + '</li>'; }).join('');
    var ctas     = (d.cta_patterns || []).map(function(c) { return '<span style="display:inline-block;background:var(--p,#6C5CE7);color:#fff;border-radius:20px;padding:3px 10px;font-size:11px;margin:2px;">' + _e(c) + '</span>'; }).join(' ');
    var struct   = (d.structure    || []).map(function(s) { return '<span style="display:inline-block;background:var(--s3,#252A3A);border:1px solid var(--bd,#2a2d3e);border-radius:4px;padding:3px 8px;font-size:11px;margin:2px;">' + _e(s) + '</span>'; }).join(' ');
    var style    = d.style || {};
    var tone_color = style.tone === 'aggressive' ? 'var(--rd,#f87171)' : style.tone === 'casual' ? 'var(--ac,#00E5A8)' : 'var(--bl,#3B8BF5)';

    var modal = document.createElement('div');
    modal.id  = 'cr-scan-results-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';

    modal.innerHTML =
        '<div style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:12px;max-width:680px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 16px 60px rgba(0,0,0,.6);">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--bd,#2a2d3e);">' +
                '<div>' +
                    '<div style="font-size:15px;font-weight:700;color:var(--t1,#fff);font-family:Syne,sans-serif;">'+window.icon("globe",14)+' Website Intelligence</div>' +
                    '<div style="font-size:11px;color:var(--t3,#666);margin-top:2px;">' + _e(url) + (blueprintId ? ' · Blueprint #' + blueprintId + ' saved' : '') + '</div>' +
                '</div>' +
                '<button onclick="document.getElementById(\'cr-scan-results-modal\').remove()" style="background:none;border:none;color:var(--t3,#666);font-size:20px;cursor:pointer;line-height:1;padding:4px;">×</button>' +
            '</div>' +
            '<div style="padding:20px 22px;display:grid;gap:16px;">' +
                // Hooks
                '<div>' +
                    '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">'+window.icon("more",14)+' Hooks / Headlines</div>' +
                    '<ul style="margin:0;padding-left:18px;font-size:13px;color:var(--t2,#ccc);line-height:1.8;">' + (hooks || '<li style="color:var(--t3,#666);">None detected</li>') + '</ul>' +
                '</div>' +
                // Messaging
                '<div>' +
                    '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">'+window.icon("message",14)+' Key Messaging</div>' +
                    '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--t2,#ccc);line-height:1.7;">' + (msgs || '<li style="color:var(--t3,#666);">None detected</li>') + '</ul>' +
                '</div>' +
                // CTAs
                '<div>' +
                    '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">'+window.icon("rocket",14)+' CTA Patterns</div>' +
                    '<div>' + (ctas || '<span style="color:var(--t3,#666);font-size:12px;">None detected</span>') + '</div>' +
                '</div>' +
                // Structure
                '<div>' +
                    '<div style="font-size:10px;font-weight:700;color:var(--t3,#666);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">'+window.icon("more",14)+'️ Page Structure</div>' +
                    '<div>' + (struct || '<span style="color:var(--t3,#666);font-size:12px;">Not detected</span>') + '</div>' +
                '</div>' +
                // Style
                '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">' +
                    '<div style="background:var(--s2,#1E2230);border-radius:8px;padding:10px;text-align:center;">' +
                        '<div style="font-size:10px;color:var(--t3,#666);margin-bottom:4px;">Tone</div>' +
                        '<div style="font-size:13px;font-weight:700;color:' + tone_color + ';">' + _e(style.tone || '—') + '</div>' +
                    '</div>' +
                    '<div style="background:var(--s2,#1E2230);border-radius:8px;padding:10px;text-align:center;">' +
                        '<div style="font-size:10px;color:var(--t3,#666);margin-bottom:4px;">Format</div>' +
                        '<div style="font-size:13px;font-weight:700;color:var(--t1,#fff);">' + _e(style.format || '—') + '</div>' +
                    '</div>' +
                    '<div style="background:var(--s2,#1E2230);border-radius:8px;padding:10px;text-align:center;">' +
                        '<div style="font-size:10px;color:var(--t3,#666);margin-bottom:4px;">CTA Intensity</div>' +
                        '<div style="font-size:13px;font-weight:700;color:var(--am,#F59E0B);">' + _e(style.cta_intensity || '—') + '</div>' +
                    '</div>' +
                '</div>' +
                // Action buttons
                '<div style="display:flex;gap:8px;padding-top:4px;">' +
                    '<button onclick="_crUseScanForGeneration(' + JSON.stringify(d).replace(/"/g, '&quot;') + ')" style="flex:1;background:var(--p,#6C5CE7);border:none;border-radius:7px;color:#fff;padding:10px;font-size:13px;font-weight:600;cursor:pointer;">'+window.icon("ai",14)+' Use for Generation</button>' +
                    '<button onclick="document.getElementById(\'cr-scan-results-modal\').remove()" style="background:var(--s3,#252A3A);border:1px solid var(--bd,#2a2d3e);border-radius:7px;color:var(--t2,#ccc);padding:10px 16px;font-size:13px;cursor:pointer;">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

function _crUseScanForGeneration(scanData) {
    // Pre-populate the generation form with scan intelligence
    document.getElementById('cr-scan-results-modal') && document.getElementById('cr-scan-results-modal').remove();
    _crNav('generate-image');

    setTimeout(function() {
        // Try to find and pre-fill the prompt field
        var promptEl = document.getElementById('cr-prompt') || document.querySelector('[name="prompt"]') || document.querySelector('textarea');
        if (promptEl && scanData.hooks && scanData.hooks[0]) {
            var hook   = scanData.hooks[0];
            var cta    = scanData.cta_patterns && scanData.cta_patterns[0] ? ' CTA: "' + scanData.cta_patterns[0] + '"' : '';
            var tone   = scanData.style && scanData.style.tone ? '. Tone: ' + scanData.style.tone : '';
            promptEl.value = hook + cta + tone;
            promptEl.dispatchEvent(new Event('input'));
        }
        _crToast('Intelligence loaded from scan. Refine your prompt and generate!', 'success');
    }, 300);
}
// ── END creative-spa.js PATCH ─────────────────────────────────────────────────
