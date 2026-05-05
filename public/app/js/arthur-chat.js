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
        '<div style="background:var(--s1,#161927);border:1px solid var(--bd);border-radius:20px;width:90%;max-width:600px;height:80vh;max-height:640px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.5)">'
        // Header
        + '<div style="padding:20px 24px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:12px">'
        + '<div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--p,#6C5CE7),#3B82F6);display:flex;align-items:center;justify-content:center;font-size:20px">⚡</div>'
        + '<div style="flex:1"><div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1)">Arthur — AI Website Builder</div>'
        + '<div style="font-size:11px;color:var(--t3)">Describe your business and I\'ll build your website</div></div>'
        + '<button onclick="document.getElementById(\'arthur-modal\').remove()" style="background:none;border:none;color:var(--t3);font-size:20px;cursor:pointer;padding:4px">\u2715</button></div>'
        // Progress bar
        + '<div id="arthur-progress" style="display:none;padding:8px 24px;border-bottom:1px solid var(--bd);gap:8px"></div>'
        // Chat feed
        + '<div id="arthur-feed" style="flex:1;overflow-y:auto;padding:16px 24px;display:flex;flex-direction:column;gap:12px"></div>'
        // Input
        + '<div style="padding:12px 20px;border-top:1px solid var(--bd);display:flex;gap:10px">'
        + '<input id="arthur-chat-input" type="text" placeholder="Tell me about your business..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--t1);padding:12px 16px;font-size:14px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\')_arthurSend()">'
        + '<button onclick="_arthurSend()" id="arthur-chat-send-btn" style="background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:10px;padding:12px 18px;font-size:14px;cursor:pointer;font-weight:600;white-space:nowrap">Send \u2192</button>'
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
        _openLine = "I'll build your " + _preIndustry.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); }) + " website. '+window.icon('star',18)+'\n\nWhat's your business name, and where are you based?";
    } else {
        _openLine = "Hi! I'm Arthur, and I'll be building your website today. '+window.icon('star',18)+'\n\nTell me about your business \u2014 what you do, where you're based, who your customers are, and any design preferences you have. The more you share, the better your website will be.\n\nYou can write it all in one go, or we can take it step by step \u2014 whatever works for you.";
    }
    _arthurAddMsg('arthur', _openLine);

    setTimeout(function() { var inp = document.getElementById('arthur-chat-input'); if (inp) inp.focus(); }, 200);
}

window._arthurSend = async function() {
    var inp = document.getElementById('arthur-chat-input');
    if (!inp) { console.log("[Arthur] No input element"); return; }
if (_arthur.busy) { console.log("[Arthur] Busy flag stuck — resetting"); _arthur.busy = false; }
    var msg = inp.value.trim();
    if (!msg) { console.log("[Arthur] Empty message"); return; }
    inp.value = '';

    _arthur.busy = true;
    var btn = document.getElementById('arthur-chat-send-btn');
    if (btn) { btn.disabled = true; btn.textContent = '...'; }

    _arthurAddMsg('user', msg);

    // FIX 1 — capture colors from this message before sending. If the
    // capture changes state.colors, Arthur acknowledges in the chat feed
    // so the user gets instant feedback.
    var _colorRes = window._arthurExtractColors(msg);
    if (_colorRes.changed) {
        var _c = _arthur.state.colors;
        var _bits = [];
        if (_c.primary)   _bits.push('**' + _c.primary   + '** as your primary colour');
        if (_c.secondary) _bits.push('**' + _c.secondary + '** as secondary');
        if (_c.accent)    _bits.push('**' + _c.accent    + '** as accent');
        if (_bits.length) _arthurAddMsg('arthur', "Got it \u2014 I'll use " + _bits.join(', ') + ".");
    }

    // Only show the 5-step build banner when we're actually about to build
    // (user has confirmed a template). Every other message — chat, extraction,
    // template_pick — shows the lightweight "Thinking..." indicator so the
    // UI does not lie about what is happening.
    var _isBuilding = !!(_arthur.state && _arthur.state.template_confirmed);
    if (_isBuilding) {
        _arthurShowBuilding();
    } else {
        _arthurAddMsg('typing', '');
    }

    try {
        var t = localStorage.getItem('lu_token') || '';
        var r = await fetch('/api/builder/arthur/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
            body: JSON.stringify({ message: msg, history: _arthur.history, state: _arthur.state, colors: _arthur.state.colors })
        });
        var d = await r.json();

        // Remove both indicators — whichever one was shown.
        var _b = document.getElementById('arthur-building'); if (_b) _b.remove();
        var _t = document.getElementById('arthur-typing');   if (_t) _t.remove();

        if (d.type === 'question') {
            _arthurAddMsg('arthur', d.message);
            // Merge server state back into local state; preserve colors we
            // captured client-side in case the server echoed an older state.
            var _srvState = d.state || {};
            var _keepColors = (_arthur.state && _arthur.state.colors) || { primary: null, secondary: null, accent: null };
            _arthur.state = _srvState;
            if (!_arthur.state.colors || (!_arthur.state.colors.primary && !_arthur.state.colors.secondary && !_arthur.state.colors.accent)) {
                _arthur.state.colors = _keepColors;
            }
            _arthurUpdateProgress(d.progress || []);

        } else if (d.type === 'confirm_details') {
            // BUG 1 FIX — server extracted multiple fields in one shot.
            // Show the bubble + [Yes, build it] / [Edit details] buttons.
            var _srvState = d.state || {};
            var _keepColors = (_arthur.state && _arthur.state.colors) || { primary: null, secondary: null, accent: null };
            _arthur.state = _srvState;
            if (!_arthur.state.colors || (!_arthur.state.colors.primary && !_arthur.state.colors.secondary && !_arthur.state.colors.accent)) {
                _arthur.state.colors = _keepColors;
            }
            _arthurUpdateProgress(d.progress || []);
            if (d.message) _arthurAddMsg('arthur', d.message);
            _arthurShowConfirmDetails();

        } else if (d.type === 'template_pick') {
            // Industry is known — server is asking the user to confirm
            // the template before generation fires.
            // Merge server state back into local state; preserve colors we
            // captured client-side in case the server echoed an older state.
            var _srvState = d.state || {};
            var _keepColors = (_arthur.state && _arthur.state.colors) || { primary: null, secondary: null, accent: null };
            _arthur.state = _srvState;
            if (!_arthur.state.colors || (!_arthur.state.colors.primary && !_arthur.state.colors.secondary && !_arthur.state.colors.accent)) {
                _arthur.state.colors = _keepColors;
            }
            _arthurUpdateProgress(d.progress || []);
            if (d.message) _arthurAddMsg('arthur', d.message);
            _arthurShowTemplatePick(d.templates || [], d.industry || _arthur.state.industry || '');

        } else if (d.type === 'template_slider') {
            // 2026-04-21 — Template slider retired server-side. If a stale
            // build still emits this, treat it as confirmed with the first
            // template in the list — state updates silently; the next user
            // message will progress the flow (server is already patched to
            // never send this type again once the backend deploy lands).
            var _srvState2 = d.state || {};
            var _keepColors2 = (_arthur.state && _arthur.state.colors) || { primary: null, secondary: null, accent: null };
            _arthur.state = _srvState2;
            if (!_arthur.state.colors || (!_arthur.state.colors.primary && !_arthur.state.colors.secondary && !_arthur.state.colors.accent)) {
                _arthur.state.colors = _keepColors2;
            }
            _arthur.state.template_confirmed = true;
            var _firstTpl = (d.templates && d.templates[0]) || null;
            if (_firstTpl && _firstTpl.industry) _arthur.state.industry = _firstTpl.industry;
            _arthurUpdateProgress(d.progress || []);
            try { console.info('[arthur] template_slider intercepted — silently confirming', _arthur.state.industry); } catch(_e) {}

        } else if (d.type === 'image_upload') {
            // Template confirmed — server is asking the user to upload
            // photos (gallery/team/services). Hero stays AI-generated.
            // Merge server state back into local state; preserve colors we
            // captured client-side in case the server echoed an older state.
            var _srvState = d.state || {};
            var _keepColors = (_arthur.state && _arthur.state.colors) || { primary: null, secondary: null, accent: null };
            _arthur.state = _srvState;
            if (!_arthur.state.colors || (!_arthur.state.colors.primary && !_arthur.state.colors.secondary && !_arthur.state.colors.accent)) {
                _arthur.state.colors = _keepColors;
            }
            _arthurUpdateProgress(d.progress || []);
            if (d.message) _arthurAddMsg('arthur', d.message);
            _arthurShowImageUpload(d.max || 10);

        } else if (d.type === 'logo_upload') {
            // T1 (2026-04-20) — wizard asks for optional logo.
            var _s3 = d.state || {};
            _arthur.state = _s3;
            _arthurUpdateProgress(d.progress || []);
            if (d.message) _arthurAddMsg('arthur', d.message);
            _arthurShowLogoUpload();

        } else if (d.type === 'palette_choice') {
            // T2 (2026-04-20) — server proposed palettes from logo colors.
            var _s4 = d.state || {};
            _arthur.state = _s4;
            _arthurUpdateProgress(d.progress || []);
            if (d.message) _arthurAddMsg('arthur', d.message);
            _arthurShowPaletteChoice(d.palettes || []);

        } else if (d.type === 'complete') {
            _arthurAddMsg('arthur', d.message);
            _arthurShowWebsiteCard(d.website_id, d.name, d.industry);
            // Notify external listeners (onboarding Step 3 hooks this to fire
            // POST /api/onboarding/complete and enter the dashboard).
            try {
                window.dispatchEvent(new CustomEvent('lu:website-generated', {
                    detail: {
                        website_id: d.website_id,
                        name: d.name,
                        industry: d.industry,
                        subdomain: d.subdomain || null,
                    },
                }));
            } catch (_e) {}
            // Reload websites list
            setTimeout(function() { if (typeof wsLoadSites === 'function') wsLoadSites(); }, 1500);

        } else if (d.type === 'error') {
            _arthurAddMsg('arthur', ''+window.icon('warning',18)+' ' + (d.message || 'Something went wrong.'));
        }

        _arthur.history.push({ role: 'user', content: msg });
        _arthur.history.push({ role: 'assistant', content: d.message || '' });

    } catch (e) {
        var _b2 = document.getElementById('arthur-building'); if (_b2) _b2.remove();
        var _t2 = document.getElementById('arthur-typing');   if (_t2) _t2.remove();
        _arthurAddMsg('arthur', ''+window.icon('warning',18)+' Error: ' + e.message);
    }

    _arthur.busy = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Send \u2192'; }
};

function _arthurAddMsg(role, text) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;

    if (role === 'typing') {
        feed.innerHTML += '<div id="arthur-typing" style="display:flex;gap:10px;align-items:flex-start"><div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--p),#3B82F6);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">'+window.icon('ai',18)+'</div><div style="padding:10px 14px;border-radius:12px;background:var(--s2);color:var(--t3);font-size:13px;font-style:italic;animation:pulse 1.5s infinite">Thinking...</div></div>';
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
        html = '<div style="display:flex;gap:10px;align-items:flex-start"><div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--p),#3B82F6);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">'+window.icon('ai',18)+'</div><div style="max-width:85%;padding:12px 16px;border-radius:14px;background:var(--s2);border:1px solid var(--bd);color:var(--t1);font-size:14px;line-height:1.7">' + parsed + '</div></div>';
    }

    feed.innerHTML += html;
    feed.scrollTop = feed.scrollHeight;
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
    feed.scrollTop = feed.scrollHeight;
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
        _arthurAddMsg('arthur', 'I could not find a template for that industry yet — I will build a generic version. Reply "build" to continue, or tell me a different industry.');
        return;
    }
    var prettyIndustry = (industry || '').replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
    var intro = '<div style="font-size:13px;color:var(--t2);margin-bottom:10px">Here ' + (templates.length === 1 ? 'is the' : 'are the') + ' <strong>' + bld_escH(prettyIndustry) + '</strong> template' + (templates.length === 1 ? '' : 's') + ' I recommend. Pick one to start building.</div>';
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
                    '<button onclick="_arthurConfirmTemplate(\'' + safeIndustry + '\')" style="flex:1.6;background:var(--p,#6C5CE7);border:none;color:#fff;padding:8px 10px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600">Use Template \u2192</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
    feed.innerHTML += '<div class="arthur-tpl-picker">' + intro + cards + '</div>';
    feed.scrollTop = feed.scrollHeight;
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
    feed.scrollTop = feed.scrollHeight;
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
    if (inp) inp.value = 'Use the ' + industry.replace(/_/g,' ') + ' template.';
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
    feed.scrollTop = feed.scrollHeight;

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

function _arthurShowWebsiteCard(websiteId, name, industry) {
    var feed = document.getElementById('arthur-feed');
    if (!feed) return;

    var card = '<div style="background:linear-gradient(135deg,rgba(108,92,231,.1),rgba(59,130,246,.1));border:1px solid rgba(108,92,231,.3);border-radius:16px;padding:20px;margin-top:8px">'
        + '<div style="font-size:13px;color:var(--pu);font-weight:600;margin-bottom:8px">'+window.icon('star',18)+' Website Created</div>'
        + '<div style="font-size:18px;font-weight:700;color:var(--t1);margin-bottom:4px">' + bld_escH(name) + '</div>'
        + '<div style="font-size:12px;color:var(--t3);margin-bottom:16px">' + bld_escH(industry) + ' template \u2022 draft</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
        + '<button onclick="wsPreviewSite(' + websiteId + ')" style="background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer">'+window.icon('eye',18)+' Preview</button>'
        + '<button onclick="document.getElementById(\'arthur-modal\').remove();wsDoPublish(' + websiteId + ')" style="background:var(--p);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer">'+window.icon('rocket',18)+' Publish</button>'
        + '<button onclick="document.getElementById(\'arthur-modal\').remove();wsLoadSites()" style="background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer">View in Websites</button>'
        + '</div></div>';

    feed.innerHTML += card;
    feed.scrollTop = feed.scrollHeight;

    // Disable input after completion
    var inp = document.getElementById('arthur-chat-input');
    if (inp) { inp.placeholder = 'Website created! Close to continue.'; inp.disabled = true; }
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
        "<div class=\"ab-step\" id=\"ab-s2\">\u23f3 Loading template...</div>" +
        "<div class=\"ab-step\" id=\"ab-s3\">\u23f3 Writing your content...</div>" +
        "<div class=\"ab-step\" id=\"ab-s4\">\u23f3 Generating hero image...</div>" +
        "<div class=\"ab-step\" id=\"ab-s5\">\u23f3 Deploying your website...</div>" +
        "</div></div>" +
        "<style>.ab-step{padding:5px 0;color:var(--t3);font-size:13px;transition:color .3s,opacity .3s}" +
        ".ab-step.done{color:var(--ac,#00E5A8)}" +
        "@keyframes arthurSpin{to{transform:rotate(360deg)}}</style>";
    
    feed.scrollTop = feed.scrollHeight;
    
    // Animate steps
    var steps = ["ab-s1","ab-s2","ab-s3","ab-s4","ab-s5"];
    var delays = [0, 2000, 4000, 7000, 10000];
    steps.forEach(function(id, i) {
        setTimeout(function() {
            var el = document.getElementById(id);
            if (el) { el.textContent = ""+window.icon('check',18)+"" + el.textContent.substring(1); el.classList.add("done"); }
        }, delays[i]);
    });
}
// Preview a template-generated website
window.wsPreviewSite = function(websiteId) {
    window.open(location.origin + '/storage/sites/' + websiteId + '/index.html', '_blank');
};


// ── Website Wizard menu — 2-option picker ─────────────────────────────────
// Triggered by sidebar nav item #ni-wizard (onclick="_bldShowTemplatePicker()").
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
        +   '<div style="width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#6C5CE7,#A855F7);display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0">'+window.icon('ai',18)+'</div>'
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
    feed.scrollTop = feed.scrollHeight;
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
    if (file.size > 2 * 1024 * 1024) { alert('Logo must be under 2MB.'); return; }
    var allowed = ['image/png','image/jpeg','image/svg+xml','image/webp'];
    if (allowed.indexOf(file.type) === -1) { alert('Logo must be PNG, JPG, SVG, or WEBP.'); return; }
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
    feed.scrollTop = feed.scrollHeight;
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