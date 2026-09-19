/**
 * Arthur AI Website Builder Chat — T2
 * Replaces old template picker with conversational AI builder.
 * Smart: detailed message → builds immediately. Vague → asks follow-ups.
 */

var _arthur = { history: [], state: { colors: { primary: null, secondary: null, accent: null } }, busy: false };

// ── FIX 1: color capture — named colors + hex codes ──
// Runs client-side before every /arthur/message POST so the server sees
// the current brand-color state each turn. Extraction is additive — existing
// state.colors sticks unless the user explicitly overrides by role.
window._ARTHUR_COLOR_WORDS = [
    'black','white','red','blue','navy','gold','green','purple','violet','orange','pink','grey','gray',
    'brown','teal','yellow','cyan','rose','emerald','indigo','silver','cream','beige','ivory','charcoal',
    'maroon','crimson','bronze','copper','mint','coral','magenta','turquoise','olive','lime','peach','sand'
];
window._arthurExtractColors = function(msg) {
    if (!msg || typeof msg !== 'string') return { changed: false, named: [], hex: [] };
    var hex   = (msg.match(/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/g) || []).map(function(h){ return h.toLowerCase(); });
    var rx    = new RegExp('\\b(' + window._ARTHUR_COLOR_WORDS.join('|') + ')\\b', 'gi');
    var named = (msg.match(rx) || []).map(function(w){ return w.toLowerCase(); });
    var seen = {}, dedup = [];
    named.forEach(function(n){ if (!seen[n]) { seen[n] = 1; dedup.push(n); } });

    _arthur.state.colors = _arthur.state.colors || { primary: null, secondary: null, accent: null };
    var before = JSON.stringify(_arthur.state.colors);

    // Role-targeted phrases win: "use blue as primary", "set red as accent"
    var explicit = /(?:use|make|set)\s+([#a-zA-Z0-9]+)\s+(?:as|for)(?:\s+the)?\s+(primary|main|secondary|accent|brand)/gi;
    var m;
    while ((m = explicit.exec(msg)) !== null) {
        var val  = m[1].toLowerCase();
        var role = m[2].toLowerCase();
        if (role === 'main' || role === 'brand') role = 'primary';
        if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(val) || window._ARTHUR_COLOR_WORDS.indexOf(val) >= 0) {
            _arthur.state.colors[role] = val;
        }
    }

    // Hex codes + remaining named colors flow into primary/secondary/accent.
    var pool  = hex.concat(dedup.filter(function(n){ return !/^#/.test(n); }));
    var slots = ['primary','secondary','accent'];
    pool.forEach(function(c){
        for (var i = 0; i < slots.length; i++) {
            if (!_arthur.state.colors[slots[i]]) { _arthur.state.colors[slots[i]] = c; break; }
        }
    });

    var after = JSON.stringify(_arthur.state.colors);
    return { changed: before !== after, named: dedup, hex: hex };
};

window.wsShowArthurWizard = function(prefillArg) {
    // Show Arthur chat modal instead of old template picker.
    // prefillArg may be:
    //   - string  → legacy industry-slug prefill (back-compat)
    //   - object  → onboarding Step 3 prefill with full business context
    var existing = document.getElementById('arthur-modal');
    if (existing) { existing.remove(); return; }

    _arthur = { history: [], state: { colors: { primary: null, secondary: null, accent: null } }, busy: false };
    var _prefillObj = (prefillArg && typeof prefillArg === 'object') ? prefillArg : null;
    var _preIndustry = _prefillObj
        ? (_prefillObj.industry || null)
        : ((prefillArg && typeof prefillArg === 'string') ? prefillArg : null);
    if (_preIndustry) { _arthur.state.industry = _preIndustry; }
    if (_prefillObj) {
        // Seed the extractor's required fields so "Build my website now" short-circuits
        // past the missing-info questions. ArthurService merges non-empty state
        // non-destructively, so LLM extraction on the auto-send won't wipe these.
        if (_prefillObj.business_name) _arthur.state.business_name = _prefillObj.business_name;
        if (_prefillObj.location)      _arthur.state.location      = _prefillObj.location;
        if (_prefillObj.services && _prefillObj.services.length) _arthur.state.services = _prefillObj.services;
        if (_prefillObj.target_market) _arthur.state.target_market = _prefillObj.target_market;
        if (_prefillObj.logo_url)      _arthur.state.logo_url      = _prefillObj.logo_url;
        if (_prefillObj.brand_color)   _arthur.state.colors.primary = _prefillObj.brand_color;
        _arthur.onboardingMode = true;
        _arthur.onboardingContext = _prefillObj;
    }

    var ov = document.createElement('div');
    ov.id = 'arthur-modal';
    ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
    ov.innerHTML =
        '<div class="lu-arthur-modal" style="background:var(--s1,#161927);border:1px solid var(--bd);border-radius:20px;width:90%;max-width:600px;height:80vh;max-height:640px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.5)">'
        // Header
        + '<div style="padding:20px 24px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:12px">'
        + '<div style="width:40px;height:40px;border-radius:12px;background:var(--s2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden"><img src="/img/logo-icon-48.png" alt="Arthur" width="30" height="30" style="display:block"></div>'
        + '<div style="flex:1"><div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1)">Arthur — AI Website Builder</div>'
        + '<div style="font-size:11px;color:var(--t3)">Launch Your Websites in Minutes.</div></div>'
        + '<button onclick="document.getElementById(\'arthur-modal\').remove()" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer;padding:4px">\u2715</button></div>'
        // Progress bar
        + '<div id="arthur-progress" style="display:none;padding:8px 24px;border-bottom:1px solid var(--bd);gap:8px"></div>'
        // Chat feed
        + '<div id="arthur-feed" style="flex:1;overflow-y:auto;padding:16px 24px;display:flex;flex-direction:column;gap:12px"></div>'
        // Input
        + '<div style="padding:12px 20px;border-top:1px solid var(--bd);display:flex;gap:10px;align-items:flex-end">'
        + '<textarea id="arthur-chat-input" rows="1" placeholder="Tell me about your business..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--t1);padding:12px 16px;font-size:14px;line-height:1.45;outline:none;font-family:inherit;resize:none;overflow-y:auto;height:44px;max-height:85px" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();_arthurSend();}" oninput="this.style.height=\'auto\';this.style.height=Math.min(this.scrollHeight,85)+\'px\'"></textarea>'
        + '<button onclick="_arthurSend()" id="arthur-chat-send-btn" aria-label="Send" title="Send" style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;flex:0 0 42px;padding:0;background:#6C5CE7;color:#fff;border:none;border-radius:12px;cursor:pointer"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg></button>'
        + '</div></div>';
    ov.addEventListener('click', function(e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);

    // Show initial Arthur greeting
    var _openLine;
    if (_prefillObj) {
        var _uName = (_prefillObj.user_name || '').split(/\s+/)[0] || '';
        var _prettyInd = (_prefillObj.industry || '').replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        _openLine = (_uName ? ("Hi " + _uName + "! ") : "")
            + "I've got everything I need from your onboarding \u2014 building a professional website for "
            + (_prefillObj.business_name || 'your business')
            + (_prettyInd ? (" (" + _prettyInd + ")") : '')
            + " now."
            + (_prefillObj.location ? (" Based in " + _prefillObj.location + ".") : '')
            + "\n\nI'll use your brand colors and logo from signup. One moment while I get started\u2026";
    } else if (_preIndustry) {
        _openLine = "I'll build your " + _preIndustry.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); }) + " website. \u26a1\n\nWhat's your business name, and where are you based?";
    } else {
        _openLine = "Hi! I'm Arthur, and I'll be building your website today. \u26a1\n\nTell me about your business \u2014 what you do, where you're based, who your customers are, and any design preferences you have. The more you share, the better your website will be.\n\nYou can write it all in one go, or we can take it step by step \u2014 whatever works for you.";
    }
    _arthurAddMsg('arthur', _openLine);

    // 2026-09-12 — never take focus on a touch device: it opens the keyboard the moment the page loads.
    try {
        var _finePointer = !window.matchMedia || window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (_finePointer) setTimeout(function() { var inp = document.getElementById('arthur-chat-input'); if (inp) inp.focus(); }, 200);
    } catch (_f) {}
}

window._arthurSend = async function() {
    // PATCH (Arthur confirm + logo flow, 2026-05-09)
    // Server contract is now:
    //   type='question' → continue conversation
    //   type='confirm'  → render summary + Upload Logo / Build buttons
    //   type='complete' → website built, show success card

    // PATCH (panel-bypass-removed, 2026-05-09) — Send button and Enter key
    // ALWAYS send a chat message. Previously they delegated to
    // _arthurConfirmBuild() if the confirm panel existed in the DOM, but
    // that caused builds to fire when the panel was invisible (a CSS
    // issue) and the user typed text trying to engage the chat. The
    // explicit "Build My Website" button in the panel is the ONLY path
    // that triggers a build.
    var inp = document.getElementById('arthur-chat-input');
    if (!inp) return;
    if (_arthur.busy) { _arthur.busy = false; }
    var msg = inp.value.trim();
    if (!msg) return;
    inp.value = '';
    inp.style.height = '44px';   // a textarea keeps its grown height until told otherwise

    _arthur.busy = true;
    var btn = document.getElementById('arthur-chat-send-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '.55'; }   // icon button: dim, never re-label

    _arthurAddMsg('user', msg);
    _arthurAddMsg('typing', '');

    try {
        var t = localStorage.getItem('lu_token') || '';
        var r = await fetch('/api/builder/arthur/message', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
            body:    JSON.stringify({ message: msg, history: _arthur.history || [] })
        });
        var d = await r.json();

        var _t = document.getElementById('arthur-typing'); if (_t) _t.remove();

        // Always render Arthur's reply
        var reply = d.reply || d.message || '';
        if (reply) _arthurAddMsg('arthur', reply);

        // Sync history with server (canonical merged copy)
        if (Array.isArray(d.history)) {
            _arthur.history = d.history;
        } else {
            _arthur.history.push({ role: 'user',   content: msg });
            _arthur.history.push({ role: 'arthur', content: reply });
        }

        try { console.log('[arthur] response type=' + d.type, { ready_to_confirm: d.ready_to_confirm, ready_to_build: d.ready_to_build, has_build_data: !!(d.build_data && d.build_data.business_name) }); } catch(_){}

        // PATCH (panel-not-rendering safety net, 2026-05-09) — sometimes the
        // LLM produces the summary text but forgets to set ready_to_confirm
        // in the JSON, so d.type comes back as 'question'. Detect the summary
        // pattern (🏢 + 📍 emoji markers + bold Business label) and treat it
        // as a confirm regardless of the server flag.
        var looksLikeSummary = false;
        try {
            looksLikeSummary = typeof reply === 'string'
                && reply.indexOf('🏢') !== -1
                && reply.indexOf('📍') !== -1
                && /\bBusiness\b/i.test(reply);
        } catch(_) {}

        // type='confirm' → show action buttons (Upload Logo + Build)
        if (d.type === 'confirm' || (looksLikeSummary && d.type !== 'complete')) {
            try { console.log('[arthur] rendering confirm panel (server type=' + d.type + ', looksLikeSummary=' + looksLikeSummary + ')'); } catch(_){}
            // Build a fallback build_data from history if server didn't send one
            var bd = (d.build_data && d.build_data.business_name) ? d.build_data : (window._arthurBuildData || {});
            if (Array.isArray(d.themes) && d.themes.length) { window._arthurThemes = d.themes; if (!window._arthurColorsTouched) window._arthurTheme = d.themes[0]; }
            if (Array.isArray(d.themes_all) && d.themes_all.length) { window._arthurThemesAll = d.themes_all; }
            // setTimeout 100ms — let the summary message bubble paint to the
            // DOM before we append the panel below it. Avoids race conditions
            // with feed scroll/layout reflow.
            setTimeout(function(){ _arthurShowConfirmActions(bd); }, 100);
        }
        else if (d.type === 'palette_choice' && Array.isArray(d.palettes) && d.palettes.length) {
            _arthur.state.palettes_proposed = d.palettes;
            _arthurShowPaletteChoice(d.palettes);
        }
        // type='complete' OR legacy ready_to_build → website was built
        // BUILDER888 P1-8B — a failed build now truthfully reports type='error'
        // instead of 'complete'; the renderer below shows the safe message.
        else if (d.type === 'complete' || d.type === 'error' || d.ready_to_build === true) {
            _arthurRenderBuildResult(d);
            // 2026-09-12 — a finished build changes the workspace's website list. Reload it now so the site
            // the visitor just watched being built is selectable immediately, with no manual refresh.
            if (d.type === 'complete') {
                try {
                    if (window.LU_Website && typeof window.LU_Website.refresh === 'function') {
                        window.LU_Website.refresh().then(function (list) {
                            var id = d.website_id || (d.website && d.website.id) || null;
                            if (id && typeof window.LU_Website.set === 'function') { try { window.LU_Website.set(id); } catch (_s) {} }
                            try { console.log('[arthur] website cache refreshed after build', { count: (list || []).length, selected: id }); } catch (_l) {}
                        }).catch(function () {});
                    }
                } catch (_r) {}
            }
        }
    } catch (e) {
        var _t2 = document.getElementById('arthur-typing'); if (_t2) _t2.remove();
        _arthurAddMsg('arthur', '⚠️ Error: ' + e.message);
    }

    _arthur.busy = false;
    if (btn) { btn.disabled = false; btn.style.opacity = ''; }
};

// ── Confirm panel — premium dark redesign, 2026-05-09 ────────────
// Compact row layout matching the platform's dark aesthetic. Four
// horizontal rows separated by hairline borders: logo / photos /
// colors / build. Purple accent on upload buttons with hover.
function _arthurShowConfirmActions(buildData) {
    try { console.log('[arthur] _arthurShowConfirmActions called', { buildData: buildData }); } catch(_){}
    try {
        return _arthurShowConfirmActionsImpl(buildData);
    } catch (eOuter) {
        try { console.error('[arthur] panel rendering threw — falling back to plain build button', eOuter); } catch(_){}
        // Fallback: render a plain Build button so the user is never stranded.
        var fbFeed = document.getElementById('arthur-feed');
        if (fbFeed) {
            if (buildData) window._arthurBuildData = buildData;
            window._arthurLogoUrl = window._arthurLogoUrl || '';
            window._arthurImages  = window._arthurImages  || [];
            window._arthurColors  = window._arthurColors  || { primary: '#6C5CE7', secondary: '#3B8BF5' };
            var fb = document.createElement('div');
            fb.id = 'arthur-confirm-panel';
            fb.style.cssText = 'margin-top:16px;padding:16px;background:#15151A;border:1px solid #2A2A33;border-radius:12px;text-align:center';
            fb.innerHTML =
                '<div style="font-size:13px;color:#fff;margin-bottom:12px">Ready to build your website?</div>' +
                '<button onclick="_arthurConfirmBuild()" style="padding:12px 28px;background:#6C5CE7;border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:600;cursor:pointer">⚡ Build My Website</button>';
            fbFeed.appendChild(fb);
            try { fb.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(_){}
        }
    }
}

function _arthurShowConfirmActionsImpl(buildData) {
    var feed = document.getElementById('arthur-feed')
            || document.getElementById('arthur-messages')
            || document.querySelector('.arthur-messages');
    if (!feed) { try { console.error('[arthur] confirm panel: no feed container found'); } catch(_){} return; }
    var prev = document.getElementById('arthur-confirm-panel');
    if (prev) prev.remove();

    // Reset confirm-state stores
    if (buildData) window._arthurBuildData = buildData;
    window._arthurLogoUrl = '';
    window._arthurImages  = [];
    window._arthurColors  = { primary: '#6C5CE7', secondary: '#3B8BF5' };
    window._arthurColorsTouched = false;
    window._arthurStep = { cur: 1, done: {}, meta: {} };
    window._arthurShowAllThemes = false;

    // STEPS (2026-09-05) — one ask at a time: Logo → Photos → Colour theme → Build. Every colour, size and radius
    // comes from the app's tokens so the card belongs to the modal in both themes (the old panel was a hard-coded
    // dark box floating in a light modal). Styles are injected once.
    if (!document.getElementById('arthur-steps-css')) {
        var css = document.createElement('style'); css.id = 'arthur-steps-css';
        css.textContent =
            '.ar-steps{flex:0 0 auto;min-height:0;margin-top:14px;border:1px solid var(--bd);border-radius:16px;background:var(--s1);overflow:hidden;box-shadow:0 12px 32px rgba(0,0,0,.08)}' +
            '.ar-steps-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--bd);background:var(--s2)}' +
            '.ar-steps-title{font-size:13px;font-weight:700;color:var(--t1)}.ar-steps-sub{font-size:11px;color:var(--t3);margin-top:2px}' +
            '.ar-prog{display:flex;align-items:center;gap:6px}.ar-prog span{width:26px;height:4px;border-radius:2px;background:var(--bd);transition:background .2s}' +
            '.ar-prog span.on{background:var(--p)}.ar-prog span.done{background:#10B981}.ar-prog b{font-size:11px;color:var(--t3);font-weight:600;margin-left:6px;white-space:nowrap}' +
            '.ar-step{border-bottom:1px solid var(--bd)}.ar-step:last-of-type{border-bottom:none}' +
            '.ar-step-bar{display:flex;align-items:center;gap:12px;padding:14px 18px;width:100%;text-align:left;font:inherit;color:var(--t1);background:transparent;border:none;cursor:pointer}' +
            '.ar-step-bar:hover{background:var(--s2)}.ar-step.active .ar-step-bar{cursor:default;background:transparent}' +
            '.ar-step-num{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;background:var(--s2);color:var(--t2);border:1px solid var(--bd);flex:0 0 26px}' +
            '.ar-step.active .ar-step-num{background:var(--p);color:#fff;border-color:var(--p)}.ar-step.done .ar-step-num{background:#10B981;color:#fff;border-color:transparent}' +
            '.ar-step-name{font-size:13px;font-weight:600}.ar-step-hint{font-size:11px;color:var(--t3);margin-top:1px}' +
            '.ar-step-meta{font-size:11px;color:var(--t3);margin-left:auto;white-space:nowrap}.ar-step.done .ar-step-meta{color:#10B981}' +
            '.ar-step-body{display:none;padding:0 18px 18px 56px}.ar-step.active .ar-step-body{display:block}' +
            '.ar-drop{display:flex;align-items:center;gap:14px;padding:16px;border:1.5px dashed var(--bd);border-radius:12px;background:var(--s2);cursor:pointer;transition:border-color .15s,background .15s}' +
            '.ar-drop:hover{border-color:var(--p)}.ar-drop-ico{width:44px;height:44px;border-radius:10px;background:var(--s1);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 44px}' +
            '.ar-drop-t{font-size:13px;font-weight:600;color:var(--t1)}.ar-drop-s{font-size:11px;color:var(--t3);margin-top:2px}' +
            '.ar-status{font-size:11px;color:var(--t3);min-height:16px;margin-top:8px}' +
            '.ar-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:14px}' +
            '.ar-btn{font:inherit;font-size:12px;font-weight:600;border-radius:9px;padding:9px 14px;cursor:pointer;border:1px solid var(--bd);background:var(--s1);color:var(--t1);transition:background .15s}' +
            '.ar-btn:hover{background:var(--s2)}.ar-btn.primary{background:var(--p);color:#fff;border-color:var(--p)}.ar-btn.primary:hover{filter:brightness(1.08)}' +
            '.ar-btn.ghost{background:transparent;border-color:transparent;color:var(--t3)}.ar-btn.ghost:hover{color:var(--t1);background:var(--s2)}' +
            '#arthur-theme-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}' +
            '.ar-theme{display:flex;flex-direction:column;gap:8px;padding:10px;border-radius:12px;border:1.5px solid var(--bd);background:var(--s1);cursor:pointer;text-align:left;font:inherit;color:var(--t1);min-width:0;transition:border-color .15s,box-shadow .15s}' +
            '.ar-theme:hover{border-color:var(--t3)}.ar-theme.on{border-color:var(--p);box-shadow:0 0 0 3px rgba(108,92,231,.18)}' +
            '.ar-theme-bar{display:flex;height:26px;border-radius:8px;overflow:hidden}.ar-theme-bar span{flex:1}.ar-theme-bar span:first-child{flex:2}' +
            '.ar-theme-name{font-size:12px;font-weight:600;display:flex;justify-content:space-between;align-items:center}.ar-theme-name i{font-style:normal;font-size:11px;color:var(--p)}' +
            '#arthur-own-colors{display:none;margin-top:12px;gap:18px;flex-wrap:wrap;align-items:center}' +
            '.ar-swatch{display:inline-flex;align-items:center;gap:8px;font-size:11px;color:var(--t3)}.ar-swatch label{position:relative;width:34px;height:34px;border-radius:9px;border:1px solid var(--bd);overflow:hidden;cursor:pointer;display:inline-block}' +
            '.ar-swatch input[type=color]{position:absolute;inset:-8px;width:60px;height:60px;border:none;padding:0;cursor:pointer}.ar-swatch code{font-family:ui-monospace,monospace;font-size:11px;color:var(--t2)}' +
            '#arthur-color-note{font-size:11px;color:#10B981;min-height:14px;margin-top:8px}' +
            '#arthur-img-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:10px}' +
            '.ar-build{padding:16px 18px;background:var(--s2);border-top:1px solid var(--bd)}' +
            '.ar-build .cta{width:100%;padding:14px;border-radius:12px;border:none;background:var(--p);color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:filter .15s}.ar-build .cta:hover{filter:brightness(1.08)}' +
            '.ar-build-hint{text-align:center;margin-top:8px;font-size:11px;color:var(--t3)}' +
            '@media(max-width:560px){.ar-step-body{padding-left:18px}#arthur-theme-row{grid-template-columns:1fr}#arthur-img-grid{grid-template-columns:repeat(4,1fr)}}';
        document.head.appendChild(css);
    }

    var bd  = window._arthurBuildData || {};
    var biz = bd.business_name ? bld_escH(bd.business_name) : 'your business';

    var panel = document.createElement('div');
    panel.id = 'arthur-confirm-panel';
    panel.className = 'ar-steps';
    panel.innerHTML =
        '<div class="ar-steps-head">' +
          '<div><div class="ar-steps-title">Finishing touches for ' + biz + '</div>' +
          '<div class="ar-steps-sub">Three quick, optional steps — skip any of them and I\'ll use sensible defaults.</div></div>' +
          '<div class="ar-prog" id="arthur-steps-prog"><span></span><span></span><span></span><b>1 / 3</b></div>' +
        '</div>' +

        // ── STEP 1 · LOGO ──
        '<section class="ar-step active" id="arthur-step-1">' +
          '<button type="button" class="ar-step-bar" onclick="_arthurStepGo(1)">' +
            '<span class="ar-step-num">1</span>' +
            '<span><div class="ar-step-name">Logo</div><div class="ar-step-hint">Goes in the navigation and footer</div></span>' +
            '<span class="ar-step-meta" id="arthur-step-meta-1"></span>' +
          '</button>' +
          '<div class="ar-step-body">' +
            '<label class="ar-drop" data-arthur-upload="1">' +
              '<input type="file" id="arthur-logo-input" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none" onchange="_arthurConfirmLogoChosen(this)">' +
              '<span class="ar-drop-ico">📎</span>' +
              '<span><div class="ar-drop-t">Upload your logo</div><div class="ar-drop-s">PNG, JPG, SVG or WEBP · under 2 MB · I\'ll read your brand colours from it</div></span>' +
            '</label>' +
            '<div id="arthur-logo-status" class="ar-status"></div>' +
            '<div class="ar-actions"><button type="button" class="ar-btn ghost" onclick="_arthurStepSkip(1)">No logo yet — skip</button></div>' +
          '</div>' +
        '</section>' +

        // ── STEP 2 · PHOTOS ──
        '<section class="ar-step" id="arthur-step-2">' +
          '<button type="button" class="ar-step-bar" onclick="_arthurStepGo(2)">' +
            '<span class="ar-step-num">2</span>' +
            '<span><div class="ar-step-name">Photos</div><div class="ar-step-hint">Team, workspace, past work — placed across the site</div></span>' +
            '<span class="ar-step-meta" id="arthur-step-meta-2"></span>' +
          '</button>' +
          '<div class="ar-step-body">' +
            '<label class="ar-drop" data-arthur-upload="1">' +
              '<input type="file" id="arthur-images-input" accept=".jpg,.jpeg,.png,.webp" multiple style="display:none" onchange="_arthurConfirmImagesChosen(this)">' +
              '<span class="ar-drop-ico">🖼</span>' +
              '<span><div class="ar-drop-t">Add up to 10 photos</div><div class="ar-drop-s">JPG, PNG or WEBP · optimised automatically · <span id="arthur-img-count">0 / 10</span></div></span>' +
            '</label>' +
            '<div id="arthur-img-status" class="ar-status"></div>' +
            '<div id="arthur-img-grid"></div>' +
            '<div class="ar-actions">' +
              '<button type="button" class="ar-btn ghost" onclick="_arthurStepSkip(2)">Use stock imagery — skip</button>' +
              '<button type="button" class="ar-btn primary" onclick="_arthurStepDone(2)">Continue</button>' +
            '</div>' +
          '</div>' +
        '</section>' +

        // ── STEP 3 · COLOUR THEME ──
        '<section class="ar-step" id="arthur-step-3">' +
          '<button type="button" class="ar-step-bar" onclick="_arthurStepGo(3)">' +
            '<span class="ar-step-num">3</span>' +
            '<span><div class="ar-step-name">Colour theme</div><div class="ar-step-hint">Chosen for your style and industry — every theme is tuned for contrast</div></span>' +
            '<span class="ar-step-meta" id="arthur-step-meta-3"></span>' +
          '</button>' +
          '<div class="ar-step-body">' +
            '<div id="arthur-theme-row"></div>' +
            '<div id="arthur-own-colors">' +
              '<span class="ar-swatch">Primary <label><input type="color" id="arthur-color-primary" value="#6C5CE7" oninput="_arthurColorChanged(\'primary\',this.value)"></label><code id="arthur-color-primary-hex">#6C5CE7</code></span>' +
              '<span class="ar-swatch">Secondary <label><input type="color" id="arthur-color-secondary" value="#3B8BF5" oninput="_arthurColorChanged(\'secondary\',this.value)"></label><code id="arthur-color-secondary-hex">#3B8BF5</code></span>' +
            '</div>' +
            '<div id="arthur-color-note"></div>' +
            '<div class="ar-actions" style="justify-content:space-between">' +
              '<button type="button" id="arthur-themes-toggle" class="ar-btn ghost" onclick="window._arthurShowAllThemes=!window._arthurShowAllThemes;_arthurRenderThemes()">Show all themes</button>' +
              '<button type="button" id="arthur-own-colors-btn" class="ar-btn ghost" onclick="_arthurToggleOwnColors()">Use my own colours</button>' +
            '</div>' +
          '</div>' +
        '</section>' +

        // ── BUILD ──
        '<div class="ar-build">' +
          '<button id="arthur-confirm-build-btn" type="button" class="cta" onclick="_arthurConfirmBuild()">⚡ Build my website</button>' +
          '<div class="ar-build-hint">About a minute · you can edit everything afterwards</div>' +
        '</div>';

    feed.appendChild(panel);
    try { _arthurRenderThemes(); } catch(_){}
    _arthurStepPaint();

    function scrollToPanel() { try { feed.scrollTop = feed.scrollHeight; } catch (_) {} }
    if (typeof requestAnimationFrame === 'function') requestAnimationFrame(scrollToPanel);
    setTimeout(scrollToPanel, 120);
    setTimeout(function(){ try { panel.scrollIntoView({ behavior: 'smooth', block: 'end' }); } catch (_) {} }, 250);
}

// ── Step state ────────────────────────────────────────────────────────────────────────────────────────
function _arthurStepPaint() {
    var st = window._arthurStep || { cur: 1, done: {}, meta: {} };
    for (var i = 1; i <= 3; i++) {
        var sec = document.getElementById('arthur-step-' + i);
        if (!sec) continue;
        sec.classList.toggle('active', st.cur === i);
        sec.classList.toggle('done', !!st.done[i] && st.cur !== i);
        var meta = document.getElementById('arthur-step-meta-' + i);
        if (meta) meta.textContent = (st.cur === i) ? '' : (st.meta[i] || '');
    }
    var prog = document.getElementById('arthur-steps-prog');
    if (prog) {
        var bars = prog.querySelectorAll('span');
        for (var j = 0; j < bars.length; j++) { bars[j].className = st.done[j + 1] ? 'done' : (st.cur === j + 1 ? 'on' : ''); }
        var b = prog.querySelector('b'); if (b) b.textContent = Math.min(st.cur, 3) + ' / 3';
    }
}
window._arthurStepGo = function (n) {
    if (!window._arthurStep) window._arthurStep = { cur: 1, done: {}, meta: {} };
    window._arthurStep.cur = n;
    _arthurStepPaint();
};
window._arthurStepDone = function (n, meta) {
    if (!window._arthurStep) window._arthurStep = { cur: 1, done: {}, meta: {} };
    var st = window._arthurStep;
    st.done[n] = true;
    if (meta !== undefined) st.meta[n] = meta;
    else if (n === 2) { var k = (window._arthurImages || []).length; st.meta[2] = k ? k + ' photo' + (k > 1 ? 's' : '') : 'Stock imagery'; }
    if (st.cur === n && n < 3) st.cur = n + 1;
    _arthurStepPaint();
};
window._arthurStepSkip = function (n) {
    _arthurStepDone(n, n === 1 ? 'Skipped — no logo' : n === 2 ? 'Stock imagery' : 'Skipped');
};
window._arthurStepMeta = function (n, meta) {
    if (!window._arthurStep) return;
    window._arthurStep.meta[n] = meta;
    _arthurStepPaint();
};
// Live color picker handler — wired via inline oninput attribute on the
// <input type="color"> elements. Accepts the role name + new hex value,
// updates window._arthurColors and the inline hex label.
window._arthurColorChanged = function(role, hex) {
    if (!window._arthurColors) window._arthurColors = {};
    window._arthurColors[role] = hex;
    window._arthurColorsTouched = true;
    try { _arthurRenderThemes(); } catch(_){}
    var label = document.getElementById('arthur-color-' + role + '-hex');
    if (label) label.textContent = (hex || '').toUpperCase();
};

// Restore the chat input to typeable state (called when the panel goes
// away, either because build started or build returned an error).
function _arthurResetChatInput() {
    var inp = document.getElementById('arthur-chat-input');
    if (!inp) return;
    inp.removeAttribute('disabled');
    if (inp.dataset.prevPlaceholder !== undefined) {
        inp.setAttribute('placeholder', inp.dataset.prevPlaceholder);
        delete inp.dataset.prevPlaceholder;
    }
}

// ── Logo upload — auto-extracts brand colors via ColorExtractorService ──
// Accepts either an Event (legacy `addEventListener` wiring) OR the input
// element itself (new inline `onchange="_arthurConfirmLogoChosen(this)"`).
window._arthurConfirmLogoChosen = function(arg) {
    var el = (arg && arg.target && arg.target.files !== undefined) ? arg.target
           : (arg && arg.files !== undefined) ? arg
           : null;
    if (!el) return;
    var file = el.files && el.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { showToast('Logo must be under 2MB.', 'warning'); return; }
    var allowed = ['image/png','image/jpeg','image/svg+xml','image/webp'];
    if (allowed.indexOf(file.type) === -1) { showToast('Logo must be PNG, JPG, SVG, or WEBP.', 'warning'); return; }
    var st = document.getElementById('arthur-logo-status');
    if (st) { st.textContent = 'Uploading logo…'; st.style.color = 'var(--t3,#888)'; }
    var fd = new FormData();
    fd.append('logo', file);
    var token = localStorage.getItem('lu_token') || '';
    fetch('/api/builder/logo-upload-temp', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: fd
    }).then(function(r){ return r.json().catch(function(){ return {}; }); }).then(function(d){
        if (!d || d.error || d.success === false) {
            if (st) { st.textContent = 'Upload failed: ' + ((d && (d.error || d.message)) || 'unknown'); st.style.color = '#F87171'; }
            return;
        }
        window._arthurLogoUrl = d.temp_url || '';
        try { _arthurStepDone(1, 'Logo added'); } catch(_){}

        // Auto-fill brand colors from the first palette returned by
        // ColorExtractorService (if any).
        var colorsApplied = false;
        if (Array.isArray(d.palettes) && d.palettes.length) {
            var p = d.palettes[0];
            if (p && p.primary && p.secondary) {
                window._arthurColors.primary   = p.primary;
                window._arthurColors.secondary = p.secondary;
                window._arthurColorsTouched    = true; // brand colours from the logo beat a theme
                try { var ownBox = document.getElementById('arthur-own-colors'); if (ownBox) ownBox.style.display = 'flex'; _arthurRenderThemes(); } catch(_){}
                var pri = document.getElementById('arthur-color-primary');
                var sec = document.getElementById('arthur-color-secondary');
                var pHx = document.getElementById('arthur-color-primary-hex');
                var sHx = document.getElementById('arthur-color-secondary-hex');
                if (pri) pri.value = p.primary;
                if (sec) sec.value = p.secondary;
                if (pHx) pHx.textContent = p.primary.toUpperCase();
                if (sHx) sHx.textContent = p.secondary.toUpperCase();
                var note = document.getElementById('arthur-color-note');
                if (note) note.textContent = '✓ Auto-detected from logo';
                colorsApplied = true;
            }
        }
        if (st) {
            st.textContent = colorsApplied ? '✅ Logo uploaded — brand colors detected' : '✅ Logo uploaded';
            st.style.color = '#10B981';
        }
    }).catch(function(err){
        if (st) { st.textContent = 'Network error: ' + err.message; st.style.color = '#F87171'; }
    });
};

// ── Image upload — multi-select, queued, auto-optimized server-side ──
// Accepts either Event or input element (see _arthurConfirmLogoChosen).
window._arthurConfirmImagesChosen = function(arg) {
    var el = (arg && arg.target && arg.target.files !== undefined) ? arg.target
           : (arg && arg.files !== undefined) ? arg
           : null;
    if (!el) return;
    var files = Array.from(el.files || []);
    if (!files.length) return;
    var st = document.getElementById('arthur-img-status');
    var current = (window._arthurImages || []).length;
    var slotsLeft = 10 - current;
    if (slotsLeft <= 0) {
        if (st) { st.textContent = 'You\'ve already added 10 images.'; st.style.color = '#F87171'; }
        el.value = '';
        return;
    }
    var queue = files.slice(0, slotsLeft);
    if (files.length > slotsLeft && st) {
        st.textContent = 'Only first ' + slotsLeft + ' image(s) accepted (10-image cap).';
        st.style.color = 'var(--t3,#888)';
    }
    el.value = ''; // allow re-pick of same files
    queue.forEach(function(f){ _arthurUploadOneImage(f); });
};

function _arthurUploadOneImage(file) {
    var st = document.getElementById('arthur-img-status');
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        if (st) { st.textContent = file.name + ' too large (max 5 MB).'; st.style.color = '#F87171'; }
        return;
    }
    var allowed = ['image/png','image/jpeg','image/webp'];
    if (allowed.indexOf(file.type) === -1) {
        if (st) { st.textContent = file.name + ' rejected: PNG / JPG / WEBP only.'; st.style.color = '#F87171'; }
        return;
    }
    if (st) { st.textContent = 'Optimizing ' + file.name + '…'; st.style.color = 'var(--t3,#888)'; }

    var fd = new FormData();
    fd.append('image', file);
    var token = localStorage.getItem('lu_token') || '';
    var origSize = file.size;
    var fileName = file.name;

    fetch('/api/builder/image-upload-temp', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: fd
    }).then(function(r){ return r.json().catch(function(){ return {}; }); }).then(function(d){
        if (!d || d.error || d.success === false) {
            if (st) { st.textContent = fileName + ' failed: ' + ((d && (d.error || d.message)) || 'unknown'); st.style.color = '#F87171'; }
            return;
        }
        if (!Array.isArray(window._arthurImages)) window._arthurImages = [];
        window._arthurImages.push(d.temp_url);
        _arthurRenderImageGrid();
        var origKb = Math.round(origSize / 1024);
        var optKb  = Math.round((d.optimized_size || 0) / 1024);
        if (st) {
            st.textContent = fileName + ' — ' + origKb + 'KB → ' + optKb + 'KB ✅';
            st.style.color = '#10B981';
        }
    }).catch(function(err){
        if (st) { st.textContent = 'Network error: ' + err.message; st.style.color = '#F87171'; }
    });
}

function _arthurRenderImageGrid() {
    var grid = document.getElementById('arthur-img-grid');
    var counter = document.getElementById('arthur-img-count')
               || document.getElementById('arthur-img-counter');
    if (!grid) return;
    var imgs = window._arthurImages || [];
    if (counter) counter.textContent = imgs.length + ' / 10';
    try { if (window._arthurStep && window._arthurStep.done[2]) _arthurStepMeta(2, imgs.length ? imgs.length + ' photo' + (imgs.length > 1 ? 's' : '') : 'Stock imagery'); } catch(_){}
    grid.innerHTML = imgs.map(function(url, idx){
        return '<div style="position:relative;width:100%;padding-top:100%;border-radius:8px;overflow:hidden;border:1px solid var(--bd,#333);background:var(--s1,#0d0d0d)">' +
          '<img src="' + url + '" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">' +
          '<button type="button" onclick="_arthurRemoveImage(' + idx + ')" style="position:absolute;top:4px;right:4px;width:22px;height:22px;border:none;border-radius:50%;background:rgba(0,0,0,0.65);color:#fff;font-size:13px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Remove">×</button>' +
        '</div>';
    }).join('');
}

window._arthurRemoveImage = function(idx) {
    if (!Array.isArray(window._arthurImages)) return;
    window._arthurImages.splice(idx, 1);
    _arthurRenderImageGrid();
};

window._arthurConfirmBuild = async function() {
    var box = document.getElementById('arthur-confirm-panel');
    if (box) box.remove();
    _arthurResetChatInput();
    _arthurAddMsg('user', 'Build my website.');

    var feed = document.getElementById('arthur-feed');
    var animBox = null;
    if (feed) {
        animBox = document.createElement('div');
        animBox.id = 'arthur-build-anim';
        animBox.style.cssText = 'display:flex;gap:10px;align-items:flex-start;margin:8px 0';
        animBox.innerHTML =
          '<div style="width:28px;height:28px;border-radius:8px;background:var(--s2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden"><img src="/img/logo-icon-48.png" alt="Arthur" width="20" height="20" style="display:block"></div>' +
          '<div id="arthur-build-step" style="padding:10px 14px;border-radius:12px;background:var(--s2,#1a1a1a);color:var(--t1,#fff);font-size:13px;animation:pulse 1.5s infinite">🎨 Selecting your design...</div>';
        feed.appendChild(animBox);
        animBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        _arthurShowBuildAnimation(document.getElementById('arthur-build-step'));
    }

    try {
        var token = localStorage.getItem('lu_token') || '';
        var colors = window._arthurColors || { primary: '#6C5CE7', secondary: '#3B8BF5' };
        var r = await fetch('/api/builder/arthur/message', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + token },
            body:    JSON.stringify({
                confirm:         true,
                logo_url:        window._arthurLogoUrl || '',
                images:          window._arthurImages || [],
                primary_color:   (window._arthurColorsTouched || !window._arthurTheme) ? colors.primary   : undefined,
                secondary_color: (window._arthurColorsTouched || !window._arthurTheme) ? colors.secondary : undefined,
                palette:         (window._arthurTheme && !window._arthurColorsTouched) ? window._arthurTheme : undefined,
                build_data:      window._arthurBuildData || {},
            })
        });
        var d = await r.json();
        var anim = document.getElementById('arthur-build-anim');
        if (anim) anim.remove();
        _arthurRenderBuildResult(d);
    } catch (e) {
        var anim2 = document.getElementById('arthur-build-anim');
        if (anim2) anim2.remove();
        _arthurAddMsg('arthur', '⚠️ Build error: ' + e.message);
    }
};

// 5-step build progress animation, 3s per step = ~15s total to match
// the new ~14-20s build duration (post DALL-E gallery removal — only
// the hero generation is on the critical path now, and that's cached
// per-industry as a platform asset so it's near-instant).
var _arthurBuildSteps = [
    '🎨 Selecting your design...',
    '✍️  Writing your content...',
    '🖼️  Placing your images...',
    '⚡ Building your website...',
    '✅ Almost ready...'
];
function _arthurShowBuildAnimation(el) {
    if (!el) return;
    var i = 0;
    el.textContent = _arthurBuildSteps[0];
    var t = setInterval(function() {
        i++;
        if (i < _arthurBuildSteps.length) {
            el.textContent = _arthurBuildSteps[i];
        } else {
            clearInterval(t);
        }
    }, 3000);
}

// Shared post-build renderer — used by both the legacy ready_to_build
// path and the new confirm POST response.
function _arthurRenderBuildResult(d) {
    if (!d) {
        _arthurAddMsg('arthur', '⚠️ Build failed: empty response from server. Check storage/logs/laravel.log.');
        return;
    }
    if ((d.build_outcome === 'error') || d.type === 'error' || d.build_error) {
        var msg = d.build_error || d.error || d.message || 'Build failed. Check storage/logs/laravel.log.';
        _arthurAddMsg('arthur', '⚠️ ' + msg);
        return;
    }
    if (d.website_id) {
        var bdata = d.build_data || window._arthurBuildData || {};
        _arthurShowWebsiteCard(d.website_id, bdata.business_name || 'Your Website', bdata.industry || '', d.workspace_id || null);
        // DEC-0045 (2026-09-11): the draft is priced — say what it cost and refresh the sidebar balance now.
        if (d.credits_charged) {
            _arthurAddMsg('arthur', 'This draft used ' + d.credits_charged + ' credits' + (typeof d.credits_available === 'number' ? ' — ' + d.credits_available + ' left in your balance.' : '.'));
            try { if (typeof _checkTrialStatus === 'function') _checkTrialStatus(); } catch (_) {}
        }
        try {
            window.dispatchEvent(new CustomEvent('lu:website-generated', {
                detail: {
                    website_id: d.website_id,
                    name:       bdata.business_name || '',
                    industry:   bdata.industry || '',
                    subdomain:  d.website_url || null,
                },
            }));
        } catch (_e) {}
        setTimeout(function() { if (typeof wsLoadSites === 'function') wsLoadSites(); }, 1500);
    } else {
        // Surface whatever the server actually said instead of the generic
        // "didn't return an ID" — typical causes are PHP timeouts, nginx
        // upstream timeouts, or LLM provider errors.
        var detail = d.error || d.message || d.build_error || 'No website_id returned. Check storage/logs/laravel.log.';
        _arthurAddMsg('arthur', '⚠️ Build incomplete: ' + detail);
    }
}

function _arthurAddMsg(role, text) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;

    if (role === 'typing') {
        feed.innerHTML += '<div id="arthur-typing" style="display:flex;gap:10px;align-items:flex-start"><div style="width:28px;height:28px;border-radius:8px;background:var(--s2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden"><img src="/img/logo-icon-48.png" alt="Arthur" width="20" height="20" style="display:block"></div><div style="padding:10px 14px;border-radius:12px;background:var(--s2);color:var(--t3);font-size:13px;font-style:italic;animation:pulse 1.5s infinite">Thinking...</div></div>';
        feed.scrollTop = feed.scrollHeight;
        return;
    }

    var isUser = role === 'user';
    // Parse basic markdown: **bold**, _italic_, \n
    var parsed = bld_escH(text)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/_(.+?)_/g, '<em style="color:var(--t3)">$1</em>')
        .replace(/\n/g, '<br>');

    var html;
    if (isUser) {
        html = '<div style="display:flex;justify-content:flex-end"><div style="max-width:80%;padding:12px 16px;border-radius:14px;background:var(--p,#6C5CE7);color:#fff;font-size:14px;line-height:1.6">' + parsed + '</div></div>';
    } else {
        html = '<div style="display:flex;gap:10px;align-items:flex-start"><div style="width:28px;height:28px;border-radius:8px;background:var(--s2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden"><img src="/img/logo-icon-48.png" alt="Arthur" width="20" height="20" style="display:block"></div><div style="max-width:85%;padding:12px 16px;border-radius:14px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);font-size:14px;line-height:1.7">' + parsed + '</div></div>';
    }

    feed.innerHTML += html;
    // Role-aware scroll: user messages keep scrollTop (they saw their own
    // input); agent messages scroll to top so the user sees the START.
    if (isUser) {
        feed.scrollTop = feed.scrollHeight;
    } else if (feed.lastElementChild) {
        feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// BUG 1 FIX — inline [Yes, build it] / [Edit details] buttons shown
// after the server's one-shot extraction. Yes → state.details_confirmed
// and a follow-up send that routes into the template picker. Edit → we
// re-enable the input so the user can correct any field in plain text.
window._arthurShowConfirmDetails = function() {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;
    feed.innerHTML +=
        '<div id="arthur-confirm-details" style="display:flex;gap:10px;flex-wrap:wrap;padding:4px 0 8px 38px">' +
            '<button onclick="_arthurConfirmDetails()" style="background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer">'+window.icon('check',18)+' Yes, build it</button>' +
            '<button onclick="_arthurEditDetails()" style="background:transparent;color:var(--t1);border:1px solid var(--bd);border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer">'+window.icon('edit',18)+' Edit details</button>' +
        '</div>';
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
window._arthurConfirmDetails = function() {
    var el = document.getElementById('arthur-confirm-details'); if (el) el.remove();
    _arthur.state = _arthur.state || {};
    _arthur.state.details_confirmed = true;
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Yes, build it.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};
window._arthurEditDetails = function() {
    var el = document.getElementById('arthur-confirm-details'); if (el) el.remove();
    _arthurAddMsg('arthur', 'Sure \u2014 tell me what to change. For example: "the business is actually called XYZ" or "change location to Abu Dhabi" or "add photography to services".');
    var inp = document.getElementById('arthur-chat-input');
    if (inp) { inp.placeholder = 'Tell me what to change...'; try { inp.focus(); } catch(e){} }
};

// Inline filtered template picker shown after industry is extracted.
// Server decides which templates match the industry and returns them;
// we render cards inline in the chat feed. Clicking "Use Template"
// sends a confirmation back to the server with template_confirmed=true.
function _arthurShowTemplatePick(templates, industry) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;
    if (!templates.length) {
        _arthurAddMsg('arthur', 'I could not find a design for that industry yet — I will build a custom version from scratch. Reply "build" to continue, or tell me a different industry.');
        return;
    }
    var prettyIndustry = (industry || '').replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
    var intro = '<div style="font-size:13px;color:var(--t2);margin-bottom:10px">Here ' + (templates.length === 1 ? 'is the' : 'are the') + ' <strong>' + bld_escH(prettyIndustry) + '</strong> design' + (templates.length === 1 ? '' : 's') + ' I recommend. Pick one to start building.</div>';
    var cards = templates.map(function(t) {
        var thumb = t.thumbnail || '';
        var blocks = t.block_count || t.blocks || 0;
        var fields = t.field_count || t.variables || 0;
        var prev = t.preview_url || ('/templates/' + t.industry + '/preview');
        var safeIndustry = String(t.industry || industry || '').replace(/[^a-zA-Z0-9_\-]/g,'');
        return '<div class="arthur-tpl-card" style="background:var(--s2);border:1px solid var(--bd);border-radius:12px;overflow:hidden;margin-bottom:10px">' +
            '<div style="position:relative;aspect-ratio:16/9;background:#1e2230;overflow:hidden">' +
                (thumb ? '<img src="' + thumb + '" alt="' + bld_escH(t.name||'') + '" style="width:100%;height:100%;object-fit:cover;display:block">' : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--t3);font-size:40px">'+window.icon('more',18)+'</div>') +
            '</div>' +
            '<div style="padding:14px 16px 16px">' +
                '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:10px">' +
                    '<div style="font-size:15px;font-weight:600;color:var(--t1)">' + bld_escH(t.name||'') + '</div>' +
                    '<div style="font-size:11px;color:var(--t3)">' + blocks + ' blocks \u00B7 ' + fields + ' fields</div>' +
                '</div>' +
                '<div style="display:flex;gap:8px">' +
                    '<button onclick="window.open(\'' + prev + '\', \'_blank\')" style="flex:1;background:transparent;border:1px solid var(--bd);color:var(--t1);padding:8px 10px;border-radius:7px;cursor:pointer;font-size:12px">\u{1F441} Preview</button>' +
                    '<button onclick="_arthurConfirmTemplate(\'' + safeIndustry + '\')" style="flex:1.6;background:var(--p,#6C5CE7);border:none;color:#fff;padding:8px 10px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600">Use This Design \u2192</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
    feed.innerHTML += '<div class="arthur-tpl-picker">' + intro + cards + '</div>';
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Template SLIDER (FIX 3/4 2026-04-20) ────────────────────────
// Horizontal card picker for ambiguous industries (e.g. real_estate
// vs real_estate_broker). Each card: 16:9 thumb, bold name, short
// description, [Choose This] button.
function _arthurShowTemplateSlider(templates) {
    var feed = document.getElementById('arthur-feed');
    if (!feed || !templates || !templates.length) return;
    var cards = templates.map(function(t) {
        var safeIndustry = String(t.industry || '').replace(/[^a-zA-Z0-9_\-]/g, '');
        var thumb = t.thumbnail || '/storage/builder-heroes/' + safeIndustry + '.jpg';
        return '<div class="arthur-tpl-slider-card" style="flex:0 0 260px;background:var(--s2);border:1px solid var(--bd);border-radius:12px;overflow:hidden;display:flex;flex-direction:column">' +
            '<div style="aspect-ratio:16/9;background:#1e2230;overflow:hidden">' +
                '<img src="' + bld_escH(thumb) + '" alt="' + bld_escH(t.name||'') + '" style="width:100%;height:100%;object-fit:cover;display:block" onerror="this.style.display=\'none\'">' +
            '</div>' +
            '<div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px;flex:1">' +
                '<div style="font-size:14px;font-weight:700;color:var(--t1)">' + bld_escH(t.name || '') + '</div>' +
                '<div style="font-size:12px;color:var(--t2);line-height:1.45;flex:1">' + bld_escH(t.description || '') + '</div>' +
                '<button onclick="_arthurConfirmTemplate(\'' + safeIndustry + '\')" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:8px 12px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;margin-top:4px">Choose This \u2192</button>' +
            '</div>' +
        '</div>';
    }).join('');
    feed.innerHTML += '<div class="arthur-tpl-slider" style="display:flex;gap:12px;overflow-x:auto;padding:8px 0;scroll-snap-type:x mandatory">' + cards + '</div>';
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Called when the user picks a template from the inline picker.
// Flags state.template_confirmed + template_industry and fires a
// follow-up message so the server proceeds to generation.
window._arthurConfirmTemplate = function(industry) {
    if (!industry) return;
    _arthur.state.template_confirmed = true;
    _arthur.state.template_industry  = industry;
    _arthur.state.industry = industry;
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Use the ' + industry.replace(/_/g,' ') + ' design.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};

// ── Image upload UI — shown after template is confirmed ──
// Max N files (default 10), client-side cap + server cap.
// Files are uploaded ONE AT A TIME (each HTTP request stays under
// PHP post_max_size) to /api/builder/arthur/upload. Server returns
// { url }. URLs accumulate in _arthur._uploadUrls; on "Build", they
// get flushed into state.uploaded_images and the build is triggered.
window._arthurShowImageUpload = function(max) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;
    max = Math.max(1, Math.min(max || 10, 10));
    _arthur._uploadUrls = [];
    _arthur._uploadMax  = max;

    var box =
        '<div id="arthur-upload" style="background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:20px;margin:4px 0">' +
            '<div id="au-empty" style="text-align:center">' +
                '<div style="font-size:40px;margin-bottom:8px">\u{1F4F7}</div>' +
                '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:6px">Add photos of your business</div>' +
                '<div style="font-size:12px;color:var(--t3);margin-bottom:16px">Up to ' + max + ' photos \u00B7 JPG, PNG, WebP or GIF \u00B7 max 2 MB each</div>' +
                '<label for="au-input" style="display:inline-block;background:var(--p,#6C5CE7);color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">Choose Photos</label>' +
                '<input id="au-input" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">' +
                '<div style="margin-top:14px">' +
                    '<button id="au-skip" style="background:transparent;border:none;color:var(--t3);font-size:12px;text-decoration:underline;cursor:pointer">Skip \u2014 build with stock photos</button>' +
                '</div>' +
            '</div>' +
            '<div id="au-list" style="display:none">' +
                '<div id="au-thumbs" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;margin-bottom:14px"></div>' +
                '<div id="au-status" style="font-size:12px;color:var(--t3);margin-bottom:14px;text-align:center"></div>' +
                '<div style="display:flex;gap:8px">' +
                    '<button id="au-add-more" style="flex:1;background:transparent;border:1px solid var(--bd);color:var(--t1);padding:10px;border-radius:7px;font-size:12px;cursor:pointer">+ Add more</button>' +
                    '<button id="au-build" style="flex:1.6;background:var(--p,#6C5CE7);border:none;color:#fff;padding:10px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer">Build with these photos \u2192</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    feed.innerHTML += box;
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });

    var inp   = document.getElementById('au-input');
    var skip  = document.getElementById('au-skip');
    var more  = document.getElementById('au-add-more');
    var build = document.getElementById('au-build');
    if (inp)   inp.addEventListener('change', function(e){ _arthurHandleFiles(e.target.files); });
    if (skip)  skip.addEventListener('click', _arthurSkipUpload);
    if (more)  more.addEventListener('click', function(){ inp.click(); });
    if (build) build.addEventListener('click', _arthurFinishUpload);
};

window._arthurHandleFiles = async function(fileList) {
    if (!fileList || !fileList.length) return;
    var max = _arthur._uploadMax || 10;
    var remaining = max - (_arthur._uploadUrls || []).length;
    if (remaining <= 0) { _arthurUploadStatus('Maximum ' + max + ' photos reached.', 'warn'); return; }

    var files = Array.prototype.slice.call(fileList, 0, remaining);
    document.getElementById('au-empty').style.display = 'none';
    document.getElementById('au-list').style.display = 'block';

    var thumbs = document.getElementById('au-thumbs');
    var token  = localStorage.getItem('lu_token') || '';

    for (var i = 0; i < files.length; i++) {
        var f = files[i];
        // Client-side caps
        if (f.size > 2 * 1024 * 1024) { _arthurUploadStatus('"' + f.name + '" is too big (max 2 MB). Skipped.', 'warn'); continue; }
        if (!/^image\/(jpeg|png|webp|gif)$/i.test(f.type)) { _arthurUploadStatus('"' + f.name + '" is not a supported image. Skipped.', 'warn'); continue; }

        // Add placeholder thumb (local preview) immediately
        var thumbId = 'au-thumb-' + Date.now() + '-' + i;
        var url = URL.createObjectURL(f);
        thumbs.innerHTML += '<div id="' + thumbId + '" style="position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;background:var(--s1);border:1px solid var(--bd)"><img src="' + url + '" style="width:100%;height:100%;object-fit:cover"><div class="au-spinner" style="position:absolute;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px">\u21BB</div></div>';

        _arthurUploadStatus('Uploading ' + ((_arthur._uploadUrls.length || 0) + 1) + ' of ' + max + '...', 'info');

        try {
            var fd = new FormData();
            fd.append('image', f);
            var resp = await fetch('/api/builder/arthur/upload', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token },
                body: fd
            });
            var data = await resp.json();
            if (data && data.success && data.url) {
                _arthur._uploadUrls.push(data.url);
                // Replace spinner with checkmark
                var thumb = document.getElementById(thumbId);
                if (thumb) {
                    var sp = thumb.querySelector('.au-spinner');
                    if (sp) sp.innerHTML = '<span style="color:#00E5A8">\u2713</span>';
                    setTimeout(function(t){ return function(){ var s = t.querySelector('.au-spinner'); if (s) s.remove(); }; }(thumb), 600);
                }
            } else {
                _arthurUploadStatus(data && data.error ? data.error : 'Upload failed for one photo.', 'warn');
                var tb = document.getElementById(thumbId); if (tb) tb.remove();
            }
        } catch (err) {
            _arthurUploadStatus('Upload failed: ' + err.message, 'warn');
            var tb2 = document.getElementById(thumbId); if (tb2) tb2.remove();
        }
    }

    var n = (_arthur._uploadUrls || []).length;
    _arthurUploadStatus(n + ' of ' + max + ' photos uploaded. Add more or click Build.', 'info');
    if (n >= max) {
        var addBtn = document.getElementById('au-add-more'); if (addBtn) addBtn.disabled = true;
    }
};

function _arthurUploadStatus(msg, kind) {
    var el = document.getElementById('au-status');
    if (!el) return;
    var color = kind === 'warn' ? '#F87171' : 'var(--t3)';
    el.style.color = color;
    el.textContent = msg;
}

window._arthurSkipUpload = function() {
    var box = document.getElementById('arthur-upload');
    if (box) box.remove();
    _arthur.state.uploaded_images = [];
    _arthur.state.images_done = true;
    _arthurAddMsg('user', 'Skip photo upload \u2014 build with stock imagery.');
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Build the site now.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};

window._arthurFinishUpload = function() {
    var box = document.getElementById('arthur-upload');
    var urls = (_arthur._uploadUrls || []).slice(0, _arthur._uploadMax || 10);
    _arthur.state.uploaded_images = urls;
    _arthur.state.images_done = true;
    if (box) box.remove();
    _arthurAddMsg('user', 'Use these ' + urls.length + ' photo' + (urls.length === 1 ? '' : 's') + ' on my site.');
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Build the site now.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};

function _arthurUpdateProgress(progress) {
    var el = document.getElementById('arthur-progress');
    if (!el || !progress.length) return;
    el.style.display = 'flex';
    el.innerHTML = progress.map(function(p) {
        var icon = p.done ? ''+window.icon('check',18)+'' : ''+window.icon('info',18)+'';
        var color = p.done ? 'var(--ac,#00E5A8)' : 'var(--t3)';
        return '<span style="font-size:11px;color:' + color + '">' + icon + ' ' + bld_escH(p.label) + '</span>';
    }).join('');
}

// BUILDER888 D1 (2026-08-28) — website = workspace: an additional site is provisioned in
// its OWN workspace (ArthurService::provisionWebsiteWorkspace) and the build response
// carries that workspace_id ("FE switches to it"). The FE never did: "Open in Editor"
// ran wsOpenSite() inside the OLD workspace, whose tenancy filter returned no pages,
// and the customer was shown "No pages yet / + Add First Page" for a site that had
// just been built. Now: switch the session to the site's workspace (fresh token, same
// mechanism as the SEO workspace switcher), then deep-link to /app/websites/{id}.
function _arthurCurrentWs() {
    try {
        var t = localStorage.getItem('lu_token') || '';
        var p = JSON.parse(atob(t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
        return parseInt(p.ws || p.workspace_id || 0, 10) || 0;
    } catch (_e) { return 0; }
}
// ARTHUR EDITOR FIX (2026-09-10) — "Open in Editor" used to call wsOpenSite() directly. That had two
// faults and the customer saw the same thing from both: Sarah's chat, with no error. (1) wsOpenSite reads
// the undeclared global wsSites on its first statement and threw for every new signup (fixed in
// builder.js, kept defensive here); the call was neither awaited nor caught, so the rejection was silent.
// (2) Even when it worked it only rewrote the DOM *inside* view-websites and pushed the URL — it never
// made that view active, so a non-template site left the customer on whatever view they were already on.
// nav('websites', {tail}) is the path that loads the grid, activates the view and deep-links the site, and
// it works in Basic mode because 'websites' is in BASIC_VISIBLE.
function _arthurEnterEditor(websiteId) {
    var fell = false;
    var fallback = function (err) {
        if (fell) return; fell = true;
        if (err) console.error('[Arthur] open editor failed:', err);
        window.location.href = '/app/websites/' + websiteId;
    };
    try {
        if (typeof window.nav === 'function') {
            var r = window.nav('websites', { tail: String(websiteId) });
            if (r && typeof r.catch === 'function') r.catch(fallback);
            return;
        }
    } catch (e) { fallback(e); return; }
    fallback(null);
}

window._arthurOpenBuiltSite = function (websiteId, workspaceId) {
    var m = document.getElementById('arthur-modal'); if (m) m.remove();
    var target = parseInt(workspaceId || 0, 10) || 0;
    if (!target || target === _arthurCurrentWs()) {
        _arthurEnterEditor(websiteId);
        return;
    }
    if (typeof showToast === 'function') showToast('Opening your new website…', 'info');
    fetch(window.location.origin + '/api/auth/switch-workspace', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ workspace_id: target }),
        cache: 'no-store'
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.access_token) {
            localStorage.setItem('lu_token', d.access_token);
            if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
            try { localStorage.setItem('lu_workspace_id', String(d.current_workspace_id || target)); } catch (_e) {}
            window.location.href = '/app/websites/' + websiteId;
        } else if (typeof showToast === 'function') {
            showToast('We couldn’t switch to the new website’s workspace. Please try again.', 'error');
        }
    }).catch(function () {
        if (typeof showToast === 'function') showToast('We couldn’t switch to the new website’s workspace. Please try again.', 'error');
    });
};

function _arthurShowWebsiteCard(websiteId, name, industry, workspaceId) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;
    var switching = !!(workspaceId && parseInt(workspaceId, 10) !== _arthurCurrentWs());

    var card = '<div style="background:linear-gradient(135deg,rgba(108,92,231,.1),rgba(59,130,246,.1));border:1px solid rgba(108,92,231,.3);border-radius:16px;padding:20px;margin-top:8px">'
        + '<div style="font-size:13px;color:var(--pu);font-weight:600;margin-bottom:8px">⚡ Website Created</div>'
        + '<div style="font-size:18px;font-weight:700;color:var(--t1);margin-bottom:4px">' + bld_escH(name) + '</div>'
        + '<div style="font-size:12px;color:var(--t3);margin-bottom:16px">' + bld_escH(industry) + ' website \u2022 draft</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
        + '<a href="/storage/sites/' + websiteId + '/index.html" target="_blank" rel="noopener" style="background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px">'+window.icon('eye',18)+' Preview</a>'
        + (switching ? '<div style="font-size:12px;color:var(--t2);margin:-8px 0 12px">This website lives in its own workspace \u2014 opening it switches you there.</div>' : '')
        + '<button onclick="_arthurOpenBuiltSite(' + websiteId + ', ' + (parseInt(workspaceId || 0, 10) || 0) + ')" style="background:var(--p);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px">'+window.icon('edit',18)+' Open in Editor</button>'
        + '</div></div>';

    feed.innerHTML += card;
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // PATCH (FIX 3, 2026-05-09) \u2014 placeholder updated to direct the user
    // toward the editor instead of "Close to continue".
    var inp = document.getElementById('arthur-chat-input');
    if (inp) { inp.placeholder = 'Your website is ready! Open it in the editor to customize.'; inp.disabled = true; }
}



// Building animation
function _arthurShowBuilding() {
    var feed = document.getElementById("arthur-feed");
    if (!feed) return;
    
    feed.innerHTML += 
        "<div id=\"arthur-building\" style=\"padding:16px;background:var(--s2,#1e2030);border:1px solid var(--bd);border-radius:14px;margin:4px 0\">" +
        "<div style=\"display:flex;align-items:center;gap:10px;margin-bottom:14px\">" +
        "<div style=\"width:20px;height:20px;border:2px solid var(--bd);border-top-color:var(--p,#6C5CE7);border-radius:50%;animation:arthurSpin .8s linear infinite\"></div>" +
        "<span style=\"color:var(--t1);font-weight:600;font-size:14px\">Building your website...</span></div>" +
        "<div id=\"arthur-build-steps\">" +
        "<div class=\"ab-step\" id=\"ab-s1\">\u23f3 Understanding your business...</div>" +
        "<div class=\"ab-step\" id=\"ab-s2\">\u23f3 Loading your design...</div>" +
        "<div class=\"ab-step\" id=\"ab-s3\">\u23f3 Writing your content...</div>" +
        "<div class=\"ab-step\" id=\"ab-s4\">\u23f3 Generating hero image...</div>" +
        "<div class=\"ab-step\" id=\"ab-s5\">\u23f3 Deploying your website...</div>" +
        "</div></div>" +
        "<style>.ab-step{padding:5px 0;color:var(--t3);font-size:13px;transition:color .3s,opacity .3s}" +
        ".ab-step.done{color:var(--ac,#00E5A8)}" +
        "@keyframes arthurSpin{to{transform:rotate(360deg)}}</style>";
    
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
    
    // Animate steps
    var steps = ["ab-s1","ab-s2","ab-s3","ab-s4","ab-s5"];
    var delays = [0, 2000, 4000, 7000, 10000];
    steps.forEach(function(id, i) {
        setTimeout(function() {
            var el = document.getElementById(id);
            // CR-01 (2026-07-26): this used to assign window.icon()'s SVG STRING
            // to .textContent, which renders the raw <svg …> tag as visible
            // characters. An icon is a UI component and never message text.
            if (el) {
                var _label = el.textContent.substring(1);
                el.textContent = '';
                var _ic = document.createElement('span');
                _ic.style.cssText = 'display:inline-flex;vertical-align:-3px;margin-right:6px';
                _ic.innerHTML = window.icon('check', 18);
                el.appendChild(_ic);
                el.appendChild(document.createTextNode(_label));
                el.classList.add("done");
            }
        }, delays[i]);
    });
}
// Preview a template-generated website
window.wsPreviewSite = function(websiteId) {
    window.open(location.origin + '/storage/sites/' + websiteId + '/index.html', '_blank');
};


// ── Website Wizard menu — 2-option picker ─────────────────────────────────
// Opened by the homepage-widget landing (index.html) when no brief is attached; the sidebar item that used to call it
// was retired in REPORT-0061 B2 (2026-09-19) — Websites › New website (wsShowCreate) is the one door.
// Option 1: Build with Arthur  → window.wsShowCreate()
// Option 2: Use Existing Website → wsShowConnectModal()
window._bldShowTemplatePicker = function() {
    var existing = document.getElementById('lu-wizard-picker');
    if (existing) { existing.remove(); return; }

    var ov = document.createElement('div');
    ov.id = 'lu-wizard-picker';
    ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
    ov.addEventListener('click', function(e){ if (e.target === ov) ov.remove(); });

    var card =
        '<div style="background:var(--s1,#161927);border:1px solid var(--bd);border-radius:16px;width:90%;max-width:560px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.5)">'
        + '<div style="padding:20px 24px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between">'
        +   '<div><div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1)">Create a Website</div>'
        +   '<div style="font-size:12px;color:var(--t3);margin-top:2px">Build a new one with AI, or connect one you already own.</div></div>'
        +   '<button id="lu-wiz-close" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer;padding:4px">\u2715</button>'
        + '</div>'
        + '<div style="padding:20px 24px;display:flex;flex-direction:column;gap:12px">'

        // Option 1 — Build with Arthur
        + '<div id="lu-wiz-opt-arthur" style="display:flex;align-items:center;gap:14px;padding:16px 18px;background:linear-gradient(135deg,rgba(108,92,231,.08),rgba(168,85,247,.08));border:1px solid rgba(108,92,231,.3);border-radius:12px;cursor:pointer;transition:all .2s">'
        +   (window.luAvatar ? luAvatar('arthur', 52) : '<div style="width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#6C5CE7,#A855F7);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0">'+window.icon('ai',18)+'</div>')
        +   '<div style="flex:1">'
        +     '<div style="font-size:15px;font-weight:700;color:var(--t1)">Build with Arthur</div>'
        +     '<div style="font-size:12px;color:var(--t3);margin-top:2px">Chat with AI and get your website built in seconds</div>'
        +   '</div>'
        +   '<div style="font-size:20px;color:#6C5CE7">\u2192</div>'
        + '</div>'

        // Option 2 — Use Existing Website
        + '<div id="lu-wiz-opt-existing" style="display:flex;align-items:center;gap:14px;padding:16px 18px;background:linear-gradient(135deg,rgba(0,229,168,.06),rgba(16,185,129,.06));border:1px solid rgba(0,229,168,.25);border-radius:12px;cursor:pointer;transition:all .2s">'
        +   '<div style="width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#00E5A8,#10B981);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0">'+window.icon('link',18)+'</div>'
        +   '<div style="flex:1">'
        +     '<div style="font-size:15px;font-weight:700;color:var(--t1)">Use Existing Website</div>'
        +     '<div style="font-size:12px;color:var(--t3);margin-top:2px">Connect your WordPress or HTML site by URL</div>'
        +   '</div>'
        +   '<div style="font-size:20px;color:#00E5A8">\u2192</div>'
        + '</div>'

        + '</div>'
        + '</div>';

    ov.innerHTML = card;
    document.body.appendChild(ov);

    document.getElementById('lu-wiz-close').addEventListener('click', function(){ ov.remove(); });

    document.getElementById('lu-wiz-opt-arthur').addEventListener('click', function(){
        ov.remove();
        if (typeof window.wsShowCreate === 'function') window.wsShowCreate();
        else if (typeof showToast === 'function') showToast('Arthur builder is not loaded', 'error');
    });

    document.getElementById('lu-wiz-opt-existing').addEventListener('click', function(){
        ov.remove();
        if (typeof wsShowConnectModal === 'function') wsShowConnectModal();
        else if (typeof showToast === 'function') showToast('Connect flow is not loaded', 'error');
    });

    // Simple hover affordance without extra CSS rules.
    ['lu-wiz-opt-arthur','lu-wiz-opt-existing'].forEach(function(id){
        var el = document.getElementById(id);
        el.addEventListener('mouseenter', function(){ el.style.transform = 'translateY(-1px)'; el.style.boxShadow = '0 8px 24px rgba(0,0,0,.35)'; });
        el.addEventListener('mouseleave', function(){ el.style.transform = ''; el.style.boxShadow = ''; });
    });
};


// ═══════════════════════════════════════════════════════════════
// Entry point: Arthur wizard first, gallery later.
// "+ New Website" button → wsShowCreate() → Arthur wizard opens and
// collects business_name + industry + location. Only AFTER the industry
// is known does Arthur (server-side, type: 'template_pick') surface a
// filtered template gallery inline. User confirms → generation fires.
// wsShowGallery() is still exposed separately for anyone who wants to
// browse templates directly (e.g. a "Browse templates" link), but it
// is NOT wired to "+ New Website" anymore.
// ═══════════════════════════════════════════════════════════════
window.wsShowCreate = function() {
    // Close any pre-existing gallery modal so we never double-stack.
    var stale = document.getElementById('tpl-gallery-modal');
    if (stale) stale.remove();
    // Open Arthur wizard with no pre-filled industry; it will collect
    // business_name + industry + location, then the server returns a
    // template_pick response which renders a filtered gallery inline.
    window.wsShowArthurWizard();
};

window.wsShowGallery = function() {
    var existing = document.getElementById('tpl-gallery-modal');
    if (existing) { existing.remove(); return; }

    var ov = document.createElement('div');
    ov.id = 'tpl-gallery-modal';
    ov.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(11,15,25,.97);backdrop-filter:blur(10px);overflow-y:auto';
    ov.innerHTML = '' +
        '<div style="max-width:1280px;margin:0 auto;padding:40px 24px">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px">' +
        '<div style="display:flex;flex-direction:column;gap:4px">' +
        '<h2 style="font-size:22px;font-weight:700;color:#fff;margin:0">Browse Templates</h2>' +
        '<div style="color:rgba(255,255,255,.55);font-size:13px">Preview any template live. Use "+ New Website" to start building.</div>' +
        '</div>' +
        '<div style="display:flex;align-items:center;gap:10px">' +
        '<input id="tpl-search" type="search" placeholder="Search templates…" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;padding:10px 14px;border-radius:8px;font-size:13px;width:240px;outline:none">' +
        '<button onclick="document.getElementById(\'tpl-gallery-modal\').remove()" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;width:38px;height:38px;border-radius:8px;cursor:pointer;font-size:18px">&times;</button>' +
        '</div>' +
        '</div>' +
        '<div id="tpl-filters" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:28px"></div>' +
        '<div id="tpl-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px"><div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:rgba(255,255,255,.4)">Loading templates…</div></div>' +
        '</div>';
    ov.addEventListener('click', function(e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);

    var _allTemplates = [];
    var _activeIndustry = '';

    function renderFilters() {
        var industries = [''].concat(_allTemplates.map(function(t){ return t.industry; }).filter(function(v,i,a){ return a.indexOf(v) === i; }));
        var html = industries.map(function(ind) {
            var label = ind === '' ? 'All' : ind.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
            var active = ind === _activeIndustry;
            var style = 'padding:8px 16px;border-radius:20px;border:1px solid ' + (active ? 'var(--p,#6C5CE7)' : 'rgba(255,255,255,.12)') + ';background:' + (active ? 'rgba(108,92,231,.15)' : 'transparent') + ';color:' + (active ? '#fff' : 'rgba(255,255,255,.7)') + ';font-size:12px;letter-spacing:.04em;cursor:pointer;text-transform:capitalize';
            return '<button class="tpl-pill" data-industry="' + ind + '" style="' + style + '">' + label + '</button>';
        }).join('');
        document.getElementById('tpl-filters').innerHTML = html;
        document.querySelectorAll('.tpl-pill').forEach(function(b) {
            b.addEventListener('click', function() {
                _activeIndustry = b.dataset.industry;
                renderFilters();
                renderGrid();
            });
        });
    }

    function renderGrid() {
        var q = (document.getElementById('tpl-search').value || '').toLowerCase().trim();
        var list = _allTemplates.filter(function(t) {
            if (_activeIndustry && t.industry !== _activeIndustry) return false;
            if (q) {
                var blob = (t.name + ' ' + t.industry + ' ' + (t.description || '')).toLowerCase();
                if (blob.indexOf(q) === -1) return false;
            }
            return true;
        });

        var grid = document.getElementById('tpl-grid');
        if (!list.length) {
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:rgba(255,255,255,.4)">No templates match.</div>';
            return;
        }
        grid.innerHTML = list.map(function(t) {
            var thumb = t.thumbnail || '';
            var variation = t.variation || 'luxury';
            var blocks = t.block_count || t.blocks || 0;
            var fields = t.field_count || t.variables || 0;
            var industryLabel = t.industry.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
            return '<div class="tpl-card" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden;transition:transform .25s, border-color .25s;display:flex;flex-direction:column">' +
                '<div style="position:relative;aspect-ratio:16/9;background:#1e2230;overflow:hidden">' +
                    (thumb ? '<img src="' + thumb + '" alt="' + t.name + '" style="width:100%;height:100%;object-fit:cover;display:block">' : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.25);font-size:48px">◼</div>') +
                    '<div style="position:absolute;inset:0;background:linear-gradient(to top, rgba(0,0,0,.7) 0%, rgba(0,0,0,0) 55%);pointer-events:none"></div>' +
                    '<div style="position:absolute;top:10px;left:10px;display:flex;gap:6px">' +
                        '<span style="font-size:9px;letter-spacing:.16em;text-transform:uppercase;background:rgba(255,255,255,.15);backdrop-filter:blur(6px);color:#fff;padding:4px 8px;border-radius:3px;font-weight:600">' + industryLabel + '</span>' +
                        '<span style="font-size:9px;letter-spacing:.16em;text-transform:uppercase;background:rgba(108,92,231,.55);color:#fff;padding:4px 8px;border-radius:3px;font-weight:600">' + variation + '</span>' +
                    '</div>' +
                '</div>' +
                '<div style="padding:18px 18px 20px;display:flex;flex-direction:column;gap:12px;flex:1">' +
                    '<div>' +
                        '<div style="font-size:16px;font-weight:600;color:#fff;margin-bottom:3px">' + t.name + '</div>' +
                        '<div style="font-size:12px;color:rgba(255,255,255,.45)">' + blocks + ' blocks · ' + fields + ' fields</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px;margin-top:auto">' +
                        '<button onclick="window.open(\'' + t.preview_url + '\', \'_blank\')" style="flex:1;background:transparent;border:1px solid rgba(255,255,255,.15);color:#fff;padding:10px 12px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:500">&#128269; Preview</button>' +
                        '<button onclick="document.getElementById(\'tpl-gallery-modal\').remove();window.wsShowArthurWizard(\'' + t.industry + '\')" style="flex:1.6;background:var(--p,#6C5CE7);border:none;color:#fff;padding:10px 12px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600">Use Template &rarr;</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }).join('');

        // Hover lift
        document.querySelectorAll('.tpl-card').forEach(function(c) {
            c.addEventListener('mouseenter', function(){ c.style.transform = 'translateY(-3px)'; c.style.borderColor = 'rgba(108,92,231,.35)'; });
            c.addEventListener('mouseleave', function(){ c.style.transform = ''; c.style.borderColor = 'rgba(255,255,255,.08)'; });
        });
    }

    // Responsive: 3 → 2 → 1 column by viewport width.
    function applyResponsive() {
        var g = document.getElementById('tpl-grid'); if (!g) return;
        var w = window.innerWidth;
        g.style.gridTemplateColumns = w < 640 ? '1fr' : (w < 980 ? 'repeat(2,1fr)' : 'repeat(3,1fr)');
    }
    applyResponsive();
    window.addEventListener('resize', applyResponsive);

    // Search
    setTimeout(function() {
        var s = document.getElementById('tpl-search');
        if (s) s.addEventListener('input', function() { renderGrid(); });
    }, 100);

    // Load templates
    (async function() {
        try {
            var t = localStorage.getItem('lu_token') || '';
            var r = await fetch('/api/builder/templates', {
                headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' }
            });
            var d = await r.json();
            _allTemplates = d.templates || [];
            renderFilters();
            renderGrid();
        } catch (e) {
            document.getElementById('tpl-grid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:#F87171">Failed to load templates: ' + e.message + '</div>';
        }
    })();

    // If the URL has ?template=<industry>, auto-open Arthur wizard with that industry.
    (function() {
        try {
            var params = new URLSearchParams(window.location.search);
            var pre = params.get('template');
            if (pre) {
                setTimeout(function() {
                    document.getElementById('tpl-gallery-modal').remove();
                    window.wsShowArthurWizard(pre);
                }, 100);
            }
        } catch(e){}
    })();
};

// ── Logo upload step (T1 2026-04-20) ─────────────────────────────
function _arthurShowLogoUpload() {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;
    var box = document.createElement('div');
    box.id = 'arthur-logo-upload';
    box.style.cssText = 'background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:16px;margin:10px 0';
    box.innerHTML =
        '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
          '<button onclick="_arthurPickLogo()" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">'+window.icon('attach',18)+' Upload Logo</button>' +
          '<button onclick="_arthurSkipLogo()" style="background:transparent;border:1px solid var(--bd);color:var(--t1);padding:10px 16px;border-radius:8px;cursor:pointer;font-size:13px">Skip, use text logo</button>' +
        '</div>' +
        '<input type="file" id="arthur-logo-file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none">' +
        '<div id="arthur-logo-status" style="font-size:12px;color:var(--t3);margin-top:10px;min-height:16px"></div>';
    feed.appendChild(box);
    var fi = document.getElementById('arthur-logo-file');
    if (fi) fi.onchange = _arthurLogoFileChosen;
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

window._arthurPickLogo = function() {
    var fi = document.getElementById('arthur-logo-file');
    if (fi) fi.click();
};

window._arthurSkipLogo = function() {
    var box = document.getElementById('arthur-logo-upload');
    if (box) box.remove();
    _arthur.state.logo_decided = true;
    _arthur.state.logo_upload  = false;
    _arthur.state.logo_url     = '';
    _arthurAddMsg('user', 'Skip logo — use text.');
    _arthurAddMsg('arthur', "No problem — I'll use your business name as the logo. You can add one later in the editor.");
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Build the site now.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};

function _arthurLogoFileChosen(ev) {
    var file = ev.target.files && ev.target.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { showToast('Logo must be under 2MB.', 'warning'); return; }
    var allowed = ['image/png','image/jpeg','image/svg+xml','image/webp'];
    if (allowed.indexOf(file.type) === -1) { showToast('Logo must be PNG, JPG, SVG, or WEBP.', 'warning'); return; }
    var st = document.getElementById('arthur-logo-status');
    if (st) { st.textContent = 'Uploading logo…'; st.style.color = 'var(--t3)'; }
    var fd = new FormData();
    fd.append('logo', file);
    var token = localStorage.getItem('lu_token') || '';
    fetch('/api/builder/logo-upload-temp', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: fd
    }).then(function(r){ return r.json().catch(function(){ return {}; }); }).then(function(d){
        if (!d || d.success === false) {
            if (st) { st.textContent = 'Upload failed: ' + (d && d.error || 'unknown'); st.style.color = '#F87171'; }
            return;
        }
        _arthur.state.logo_decided   = true;
        _arthur.state.logo_upload    = true;
        _arthur.state.logo_url       = d.temp_url || '';
        _arthur.state.logo_temp_path = d.temp_path || '';
        var box = document.getElementById('arthur-logo-upload');
        if (box) box.remove();
        _arthurAddMsg('user', 'I uploaded my logo.');
        _arthurAddMsg('arthur', 'Logo received \u2713 I\u2019ll use it on your website.');
        // If server returned palette suggestions, show them inline.
        if (Array.isArray(d.palettes) && d.palettes.length) {
            _arthur.state.palettes_proposed = d.palettes;
            _arthurAddMsg('arthur', 'I found these colors in your logo. Which palette works for your brand?');
            _arthurShowPaletteChoice(d.palettes);
        } else {
            var inp = document.getElementById('arthur-chat-input');
            if (inp) inp.value = 'Build the site now.';
            if (typeof window._arthurSend === 'function') window._arthurSend();
        }
    }).catch(function(err){
        if (st) { st.textContent = 'Network error: ' + err.message; st.style.color = '#F87171'; }
    });
}

// ── Palette chooser (T2 2026-04-20) ──────────────────────────────
function _arthurShowPaletteChoice(palettes) {
    var feed = document.getElementById('arthur-feed');
    if (!feed || !palettes || !palettes.length) return;
    var row = palettes.map(function(p, idx) {
        var swatches = ['primary','secondary','accent','bg','text'].map(function(k){
            var c = p[k] || '#888';
            return '<div title="' + k + ': ' + c + '" style="width:28px;height:28px;border-radius:6px;background:' + c + ';border:1px solid rgba(255,255,255,0.1)"></div>';
        }).join('');
        var safeId = String(p.id || 'pal_' + idx).replace(/[^a-zA-Z0-9_]/g, '');
        return '<div class="arthur-pal-card" style="flex:0 0 220px;background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px">' +
            '<div style="font-size:13px;font-weight:700;color:var(--t1)">' + bld_escH(p.label || p.id || 'Palette') + '</div>' +
            '<div style="display:flex;gap:6px">' + swatches + '</div>' +
            '<button onclick="_arthurConfirmPalette(\'' + safeId + '\')" style="background:var(--p,#6C5CE7);border:none;color:#fff;padding:8px 12px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600">Choose This</button>' +
        '</div>';
    }).join('');
    feed.innerHTML += '<div id="arthur-palette-row" class="arthur-pal-row" style="display:flex;gap:10px;overflow-x:auto;padding:8px 0">' + row + '</div>';
    if (feed.lastElementChild) feed.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

window._arthurConfirmPalette = function(paletteId) {
    var list = (_arthur.state && _arthur.state.palettes_proposed) || [];
    var pick = null;
    for (var i = 0; i < list.length; i++) {
        if ((list[i].id || ('pal_' + i)) === paletteId) { pick = list[i]; break; }
    }
    if (!pick) pick = list[0] || null;
    if (!pick) return;
    _arthur.state.palette = pick;
    var row = document.getElementById('arthur-palette-row');
    if (row) row.remove();
    _arthurAddMsg('user', 'Use the "' + (pick.label || pick.id) + '" palette.');
    var inp = document.getElementById('arthur-chat-input');
    if (inp) inp.value = 'Build the site now.';
    if (typeof window._arthurSend === 'function') window._arthurSend();
};
// ── COLOUR THEMES (2026-09-05) ────────────────────────────────────────────────────────────────────────
// Curated palettes replace the bare colour picker. The server proposes themes for the business (d.themes on the
// confirm response); this is only a fallback so the panel never renders empty. Every theme is contrast-checked.
var _ARTHUR_FALLBACK_THEMES = [
  { id: 'lagoon',        label: 'Lagoon',        primary: '#0F4C81', secondary: '#38BDF8', accent: '#126592', bg: '#F6FAFE', text: '#0F2233', source: 'theme' },
  { id: 'coral_reef',    label: 'Coral Reef',    primary: '#C8502F', secondary: '#F4A261', accent: '#2A9D8F', bg: '#FFFDF9', text: '#1F2933', source: 'theme' },
  { id: 'midnight_gold', label: 'Midnight Gold', primary: '#1A2744', secondary: '#C9943A', accent: '#A9782A', bg: '#FDFBF7', text: '#1A2233', source: 'theme' },
  { id: 'sage_linen',    label: 'Sage Linen',    primary: '#3F5B4F', secondary: '#D9CFC1', accent: '#5E8266', bg: '#FBFAF7', text: '#22302A', source: 'theme' }
];
function _arthurThemeList() {
    return (window._arthurThemes && window._arthurThemes.length) ? window._arthurThemes : _ARTHUR_FALLBACK_THEMES;
}
function _arthurThemeListAll() {
    var all = (window._arthurThemesAll && window._arthurThemesAll.length) ? window._arthurThemesAll : [];
    var rec = _arthurThemeList(), seen = {}, out = [];
    rec.concat(all).forEach(function (t) { if (t && t.id && !seen[t.id]) { seen[t.id] = 1; out.push(t); } });
    return out;
}
window._arthurRenderThemes = function () {
    var row = document.getElementById('arthur-theme-row');
    if (!row) return;
    var rec = _arthurThemeList();
    var all = _arthurThemeListAll();
    var list = window._arthurShowAllThemes ? all : rec;
    if (!window._arthurTheme && !window._arthurColorsTouched) window._arthurTheme = rec[0];
    var toggle = document.getElementById('arthur-themes-toggle');
    if (toggle) { toggle.style.display = all.length > rec.length ? '' : 'none'; toggle.textContent = window._arthurShowAllThemes ? 'Show recommended only' : 'Show all ' + all.length + ' themes'; }
    row.innerHTML = list.map(function (t) {
        var on = !!(window._arthurTheme && window._arthurTheme.id === t.id && !window._arthurColorsTouched);
        var bar = ['primary', 'secondary', 'accent'].map(function (k) { return '<span style="background:' + (t[k] || '#888') + '"></span>'; }).join('');
        return '<button type="button" class="ar-theme' + (on ? ' on' : '') + '" onclick="_arthurPickTheme(\'' + String(t.id).replace(/[^a-zA-Z0-9_]/g, '') + '\')" aria-pressed="' + (on ? 'true' : 'false') + '">' +
            '<span class="ar-theme-bar">' + bar + '</span>' +
            '<span class="ar-theme-name">' + bld_escH(t.label || t.id) + (on ? '<i>Selected ✓</i>' : '') + '</span>' +
            '</button>';
    }).join('');
    var note = document.getElementById('arthur-color-note');
    if (note) note.textContent = window._arthurColorsTouched
        ? 'Using your own colours — Arthur tunes them for contrast automatically'
        : '';
    var btn = document.getElementById('arthur-own-colors-btn');
    if (btn) btn.textContent = window._arthurColorsTouched ? 'Back to themes' : 'Use my own colours';
    try { _arthurStepMeta(3, window._arthurColorsTouched ? 'Own colours' : (window._arthurTheme ? 'Theme: ' + (window._arthurTheme.label || window._arthurTheme.id) : '')); } catch(_){}
};window._arthurPickTheme = function (id) {
    var list = _arthurThemeListAll();
    for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) { window._arthurTheme = list[i]; break; } }
    window._arthurColorsTouched = false;
    var own = document.getElementById('arthur-own-colors');
    if (own) own.style.display = 'none';
    _arthurRenderThemes();
};
window._arthurToggleOwnColors = function () {
    var own = document.getElementById('arthur-own-colors');
    if (!own) return;
    var show = own.style.display === 'none' || own.style.display === '';
    own.style.display = show ? 'flex' : 'none';
    window._arthurColorsTouched = show;
    if (show) { try { _arthurStepGo(3); } catch(_){} }
    if (!show && !window._arthurTheme) window._arthurTheme = _arthurThemeList()[0];
    _arthurRenderThemes();
};