/* LevelUp Growth — admin shared helpers and global handlers.
 *
 * Stage B of the multi-page conversion. The per-page renderers now live in
 * resources/views/admin/pages/**; what remains here is everything they share:
 *   - the _-prefixed helper members of the old `pages` object
 *   - the global handler functions the pages' onclick attributes call
 *
 * Served as a static file so it is fetched once and cached across pages.
 */

// Helpers hang off window.pages so a renderer bound to it can reach them
// through `this`, exactly as it did when they were siblings in one object.
Object.assign(window.pages, {


      // ══ ADS888 A2 — settings editing ════════════════════════════════════
      // A guardrail objection is rendered where the change is being made, with
      // the reason, and an explicit "apply anyway". Refusing without saying why
      // just gets worked around by the next person.

      _adsSettingsEdit: function (key) {
        const row = document.getElementById('set-row-' + key);
        if (!row) return;
        const cur = row.getAttribute('data-value');
        document.getElementById('set-edit-' + key).innerHTML =
          '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
          + '<input id="set-input-' + key + '" value="' + pages._adsEsc(cur) + '" '
          + 'style="flex:1;min-width:220px;background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 9px;font-size:12px;font-family:monospace">'
          + pages._adsBtn('Save', "pages._adsSettingSave('" + key + "',false)", 'primary')
          + pages._adsBtn('Reset', "pages._adsSettingReset('" + key + "')")
          + pages._adsBtn('Cancel', "document.getElementById('set-edit-" + key + "').innerHTML=''")
          + '</div><div id="set-msg-' + key + '"></div>';
      },


      _adsSettingSave: async function (key, force) {
        const el = document.getElementById('set-input-' + key);
        if (!el) return;
        const msg = document.getElementById('set-msg-' + key);
        const r = await pages._adsCall('/ads/settings/' + key, 'PUT', { value: el.value, force: !!force });

        if (r.ok) {
          window._adsMsg = {
            text: key + ' updated' + (r.data && r.data.forced ? ' (FORCED past the guardrail — recorded in the audit log)' : '')
                  + '. Takes effect within 60 seconds.',
            tone: r.data && r.data.forced ? 'warn' : 'good',
          };
          pages.adsSettings();
          return;
        }

        if (r.status === 403) { msg.innerHTML = pages._adsNotice(pages._adsEsc(r.error), 'warn'); return; }

        // The refusal, its reason, and a deliberate override.
        msg.innerHTML = '<div style="border:1px solid #F59E0B;border-radius:8px;padding:10px 12px;margin-top:8px">'
          + '<div style="font-size:12px;color:#F59E0B;line-height:1.6;margin-bottom:8px">' + pages._adsEsc(r.error) + '</div>'
          + pages._adsBtn('Apply anyway', "pages._adsSettingSave('" + key + "',true)", 'danger')
          + pages._adsBtn('Cancel', "document.getElementById('set-msg-" + key + "').innerHTML=''")
          + '</div>';
      },


      _adsSettingReset: async function (key) {
        const r = await pages._adsCall('/ads/settings/' + key + '/reset', 'POST', {});
        window._adsMsg = r.ok
          ? { text: key + ' reset to its shipped default.', tone: 'good' }
          : { text: r.error, tone: r.status === 403 ? 'warn' : 'bad' };
        pages.adsSettings();
      },

      // ══ ADS888 A3 — advertisers, campaigns, creatives ═══════════════════

      // The shared api() helper returns null on any non-2xx, which loses the
      // reason. Approval sits behind MFA step-up, and "nothing happened" is a
      // terrible way to communicate "re-authenticate" — so these calls read the
      // status themselves.
      _adsCall: async function (path, method, body, isForm) {
        const token = localStorage.getItem('lu_admin_token');
        const opts = { method: method || 'GET', headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } };
        if (body && !isForm) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        if (body && isForm) { opts.body = body; }
        let res;
        try { res = await fetch('/api/admin' + path, opts); }
        catch (e) { return { ok: false, status: 0, error: 'Network error — the request never reached the server.' }; }
        let data = null;
        try { data = await res.json(); } catch (e) {}
        if (res.ok) return { ok: true, status: res.status, data: data };
        if (res.status === 403) {
          return { ok: false, status: 403, error: (data && (data.message || data.error))
            || 'This action needs a fresh MFA step-up. Re-authenticate and try again.' };
        }
        return { ok: false, status: res.status,
          error: (data && (data.error || data.message)) || ('Request failed (HTTP ' + res.status + ')'),
          reasons: data && data.reasons };
      },


      _adsBtn: function (label, onclick, tone) {
        const bg = tone === 'primary' ? 'var(--p)' : (tone === 'danger' ? '#F87171' : 'var(--s2)');
        const fg = tone ? '#fff' : 'var(--text)';
        return '<button onclick="' + onclick + '" style="padding:6px 12px;background:' + bg + ';color:' + fg
          + ';border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px;margin-right:6px">' + label + '</button>';
      },


      _adsNotice: function (msg, tone) {
        const c = tone === 'bad' ? '#F87171' : (tone === 'good' ? '#00E5A8' : '#F59E0B');
        return '<div style="border:1px solid ' + c + ';border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:13px;color:' + c + '">' + msg + '</div>';
      },


      _adsShowAdvertiserForm: function () {
        const box = document.getElementById('ads-form');
        box.innerHTML = '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px">'
          + '<div style="font-weight:600;font-size:13px;margin-bottom:10px">New advertiser</div>'
          + pages._adsField('adv-name', 'Name')
          + pages._adsField('adv-email', 'Contact email')
          + pages._adsField('adv-cat', 'Category', 'retail, automotive, … (blocked categories are refused)')
          + pages._adsField('adv-domain', 'Click domain')
          + '<div style="margin-top:10px">' + pages._adsBtn('Create', 'pages._adsCreateAdvertiser()', 'primary')
          + pages._adsBtn('Cancel', "document.getElementById('ads-form').innerHTML=''") + '</div></div>';
      },


      _adsField: function (id, label, hint) {
        return '<div style="margin-bottom:8px"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px">' + label
          + (hint ? ' <span style="opacity:.7">' + hint + '</span>' : '') + '</label>'
          + '<input id="' + id + '" style="width:100%;max-width:460px;background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:7px 9px;font-size:13px"></div>';
      },


      _adsCreateAdvertiser: async function () {
        const v = function (id) { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
        const r = await pages._adsCall('/ads/advertisers', 'POST', {
          name: v('adv-name'), contact_email: v('adv-email') || null,
          category: v('adv-cat') || null, click_domain: v('adv-domain') || null,
        });
        window._adsMsg = r.ok ? { text: 'Advertiser created.', tone: 'good' } : { text: r.error, tone: 'bad' };
        pages.adsCampaigns();
      },


      _adsShowCampaignForm: function () {
        const advs = window._adsAdvertisers || [];
        let opts = '';
        advs.forEach(function (a) { opts += '<option value="' + a.id + '">' + pages._adsEsc(a.name) + '</option>'; });

        const box = document.getElementById('ads-form');
        box.innerHTML = '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px">'
          + '<div style="font-weight:600;font-size:13px;margin-bottom:10px">New campaign</div>'
          + (advs.length ? '' : pages._adsNotice('Create an advertiser first.', 'warn'))
          + '<div style="margin-bottom:8px"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px">Advertiser</label>'
          + '<select id="c-adv" style="background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:7px 9px;font-size:13px">' + opts + '</select></div>'
          + pages._adsField('c-name', 'Campaign name')
          + '<div style="display:flex;gap:10px;flex-wrap:wrap">'
          + '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px">Kind</label>'
          + '<select id="c-kind" style="background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:7px 9px;font-size:13px"><option value="paid">paid</option><option value="house">house</option></select></div>'
          + '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:3px">Pricing</label>'
          + '<select id="c-model" style="background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:7px 9px;font-size:13px"><option value="cpm">cpm</option><option value="cpc">cpc</option><option value="cpcv">cpcv</option><option value="flat">flat</option></select></div>'
          + '</div>'
          + pages._adsField('c-rate', 'Rate (dollars)', 'e.g. 2.50')
          + pages._adsField('c-budget', 'Total budget (dollars)', 'blank = uncapped')
          + pages._adsField('c-industry', 'Target industries', 'comma separated, e.g. dental,gym — blank = run of network')
          + pages._adsField('c-country', 'Target business countries', 'comma separated ISO codes, e.g. AE,GB')
          + '<div style="margin-top:10px">' + pages._adsBtn('Create as draft', 'pages._adsCreateCampaign()', 'primary')
          + pages._adsBtn('Check reach first', 'pages._adsReachFromForm()')
          + pages._adsBtn('Cancel', "document.getElementById('ads-form').innerHTML=''") + '</div>'
          + '<div id="ads-reach-result"></div></div>';
      },


      _adsTargetingFromForm: function () {
        const v = function (id) { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
        const list = function (s) { return s ? s.split(',').map(function (x) { return x.trim(); }).filter(Boolean) : []; };
        const t = [];
        const ind = list(v('c-industry'));
        const ctry = list(v('c-country'));
        if (ind.length) t.push({ dimension: 'industry', values: ind });
        if (ctry.length) t.push({ dimension: 'business_country', values: ctry });
        return t;
      },


      // Reach is checked BEFORE anything is sold. Its refusal is the point, so
      // it is rendered as prominently as a success.
      _adsReachFromForm: async function () {
        const box = document.getElementById('ads-reach-result');
        box.innerHTML = '<div style="color:var(--muted);font-size:12px;margin-top:10px">Estimating…</div>';
        const r = await pages._adsCall('/ads/reach', 'POST', { targeting: pages._adsTargetingFromForm() });
        if (!r.ok) { box.innerHTML = pages._adsNotice(pages._adsEsc(r.error), 'bad'); return; }
        box.innerHTML = pages._adsRenderReach(r.data);
      },


      _adsRenderReach: function (d) {
        const esc = pages._adsEsc;
        let h = '<div style="margin-top:12px;border:1px solid ' + (d.quotable ? 'rgba(0,229,168,.4)' : '#F59E0B')
          + ';border-radius:8px;padding:12px 14px">';
        h += '<div style="font-weight:600;font-size:13px;color:' + (d.quotable ? '#00E5A8' : '#F59E0B') + ';margin-bottom:6px">'
          + (d.quotable ? 'QUOTABLE' : 'NOT QUOTABLE') + '</div>';
        if (!d.quotable) h += '<div style="font-size:12px;color:var(--muted);margin-bottom:8px">' + esc(d.quote_refusal_reason) + '</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.9">'
          + 'Matching sites: <strong style="color:var(--t1)">' + esc(d.matched_sites) + '</strong><br>'
          + 'Sellable pool: <strong style="color:var(--t1)">' + esc(d.sellable_pool) + '</strong>'
          + ' (excluded as unsellable: ' + esc(d.unsellable_excluded) + ')<br>'
          + 'Observed impressions (' + esc(d.history_days) + 'd): <strong style="color:var(--t1)">' + pages._adsNum(d.observed_impressions) + '</strong><br>'
          + 'Projected / month: <strong style="color:var(--t1)">' + pages._adsNum(d.projected_monthly_impressions) + '</strong>'
          + '</div>';
        const rej = d.rejected_by_dimension || {};
        if (Object.keys(rej).length) {
          h += '<div style="margin-top:8px;font-size:12px;color:var(--muted)">Excluded by dimension: ';
          h += Object.keys(rej).map(function (k) { return esc(k) + ' (' + rej[k] + ')'; }).join(', ');
          h += '</div>';
        }
        return h + '</div>';
      },


      _adsShowReach: function () {
        document.getElementById('ads-form').innerHTML =
          '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px">'
          + '<div style="font-weight:600;font-size:13px;margin-bottom:10px">Reach estimator</div>'
          + '<div style="font-size:12px;color:var(--muted);margin-bottom:10px">Run this before quoting. It refuses to produce a number for inventory that does not exist.</div>'
          + pages._adsField('c-industry', 'Industries', 'comma separated')
          + pages._adsField('c-country', 'Business countries', 'comma separated ISO codes')
          + '<div style="margin-top:10px">' + pages._adsBtn('Estimate', 'pages._adsReachFromForm()', 'primary')
          + pages._adsBtn('Close', "document.getElementById('ads-form').innerHTML=''") + '</div>'
          + '<div id="ads-reach-result"></div></div>';
      },


      _adsCreateCampaign: async function () {
        const v = function (id) { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
        const dollars = function (s) { return s ? Math.round(parseFloat(s) * 1000000) : null; };
        const body = {
          advertiser_id: parseInt(v('c-adv'), 10),
          name: v('c-name'),
          kind: v('c-kind'),
          pricing_model: v('c-model'),
          rate_micros: dollars(v('c-rate')) || 0,
          budget_total_micros: dollars(v('c-budget')),
          targeting: pages._adsTargetingFromForm(),
        };
        const r = await pages._adsCall('/ads/campaigns', 'POST', body);
        window._adsMsg = r.ok
          ? { text: 'Campaign created as a draft. Upload and approve a creative before activating it.', tone: 'good' }
          : { text: r.error, tone: 'bad' };
        pages.adsCampaigns();
      },


      _adsSetCampaignStatus: async function (id, status) {
        const r = await pages._adsCall('/ads/campaigns/' + id, 'PUT', { status: status });
        window._adsMsg = r.ok ? { text: 'Campaign ' + status + '.', tone: 'good' } : { text: r.error, tone: 'bad' };
        pages.adsCampaigns();
      },


      // Preview at TRUE dimensions. The footer bar's sizing defect was invisible
      // precisely because nobody could see a creative rendered at its real size.
      _adsCreativeCard: function (c, actionable) {
        const esc = pages._adsEsc;
        const stateColour = c.review_state === 'approved' ? '#00E5A8'
          : (c.review_state === 'rejected' ? '#F87171' : '#F59E0B');

        let media = '';
        if (c.media_type === 'video' && c.video_url) {
          media = '<video src="' + esc(c.video_url) + '" poster="' + esc(c.poster_url || '') + '" controls muted playsinline '
            + 'style="max-width:260px;max-height:260px;border-radius:6px;background:#000"></video>';
        } else if (c.asset_url) {
          media = '<img src="' + esc(c.asset_url) + '" alt="" style="max-width:260px;max-height:260px;border-radius:6px;background:#000">';
        } else {
          media = '<div style="width:260px;height:120px;display:flex;align-items:center;justify-content:center;background:var(--s1);border-radius:6px;color:var(--muted);font-size:12px">no asset</div>';
        }

        let h = '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px;display:flex;gap:16px;flex-wrap:wrap">'
          + '<div>' + media + '</div>'
          + '<div style="flex:1;min-width:260px">'
          + '<div style="font-weight:600;font-size:13px;margin-bottom:4px">' + esc(c.campaign) + ' <span style="color:var(--muted);font-weight:400">&middot; ' + esc(c.kind) + '</span></div>'
          + '<div style="font-size:12px;color:var(--muted);line-height:1.9">'
          + 'Slot: ' + esc(c.slot) + '<br>'
          + 'Media: ' + esc(c.media_type || c.type)
          + (c.aspect_ratio ? ' &middot; ' + esc(c.aspect_ratio) : '')
          + (c.width ? ' &middot; ' + esc(c.width) + '&times;' + esc(c.height) : '')
          + (c.duration_ms ? ' &middot; ' + (Number(c.duration_ms) / 1000).toFixed(1) + 's' : '') + '<br>'
          + 'Click: ' + esc(c.click_url) + '<br>'
          + 'State: <strong style="color:' + stateColour + '">' + esc(c.review_state) + '</strong>'
          + (c.review_note ? ' &mdash; ' + esc(c.review_note) : '')
          + '</div>';

        if (actionable) {
          h += '<div style="margin-top:10px">'
            + '<input id="note-' + c.id + '" placeholder="Review note (required to reject)" '
            + 'style="width:100%;max-width:340px;background:var(--s1);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 9px;font-size:12px;margin-bottom:8px"><br>'
            + pages._adsBtn('Approve', 'pages._adsReview(' + c.id + ",'approve')", 'primary')
            + pages._adsBtn('Reject', 'pages._adsReview(' + c.id + ",'reject')", 'danger')
            + '</div>';
        }

        return h + '</div></div>';
      },


      _adsReview: async function (id, action) {
        const noteEl = document.getElementById('note-' + id);
        const note = noteEl ? noteEl.value.trim() : '';
        if (action === 'reject' && !note) {
          window._adsMsg = { text: 'A rejection needs a note — the advertiser has to be told why.', tone: 'warn' };
          pages.adsCreatives();
          return;
        }
        const r = await pages._adsCall('/ads/creatives/' + id + '/' + action, 'POST', { note: note || null });
        if (!r.ok && r.status === 403) {
          window._adsMsg = { text: r.error, tone: 'warn' };
        } else {
          window._adsMsg = r.ok
            ? { text: 'Creative ' + (action === 'approve' ? 'approved' : 'rejected') + '.', tone: 'good' }
            : { text: r.error, tone: 'bad' };
        }
        pages.adsCreatives();
      },


      // ══ ADS888 — advertising (read-only, phase A1) ══════════════════════
      // Every figure here comes from the same services the artisan commands
      // use, so the console and this screen cannot disagree.

      _adsEsc: function (s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
      },

      _adsPct: function (v) { return (Number(v || 0) * 100).toFixed(2) + '%'; },

      _adsMoney: function (micros) { return '$' + (Number(micros || 0) / 1000000).toFixed(2); },

      _adsNum: function (n) { return Number(n || 0).toLocaleString(); },


      // Freshness and timezone are furniture, not a footnote: the rollup is
      // nightly, so today's figures are partial and saying so is the difference
      // between an honest report and a misleading one.
      _adsFresh: function (d) {
        const esc = pages._adsEsc;
        if (!d || !d.freshness) return '';
        return '<div style="border:1px dashed var(--border);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:var(--muted)">'
          + esc(d.freshness.note) + ' &middot; all dates ' + esc(d.timezone || 'UTC') + '</div>';
      },


      _adsKpi: function (label, value, tone) {
        const esc = pages._adsEsc;
        const c = tone === 'warn' ? '#F59E0B' : (tone === 'bad' ? '#F87171' : (tone === 'good' ? '#00E5A8' : 'var(--t1)'));
        return '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;min-width:150px;flex:1">'
          + '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:6px">' + esc(label) + '</div>'
          + '<div style="font-size:20px;font-weight:600;color:' + c + '">' + esc(value) + '</div></div>';
      },


      // ── E1 M1 — shared render helpers (read-only) ────────────────────────
      _engEsc: function (s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
      },

      // A measurement that could not be taken renders as BLOCKED with its
      // reason — never as 0, never as green. This is the truthfulness rule.
      _engValue: function (m) {
        const esc = pages._engEsc;
        if (!m) return '<span style="color:#6B7280">not measured</span>';
        if (m.available === false) {
          return '<span style="color:#F59E0B">BLOCKED</span> <span style="color:var(--muted);font-size:12px">' + esc(m.blocked_reason) + '</span>';
        }
        return '<span>' + esc(typeof m.value === 'object' ? JSON.stringify(m.value) : m.value) + '</span>';
      },

      _engSource: function (src) {
        return src ? '<div style="font-size:11px;color:var(--muted);margin-top:4px">source: ' + pages._engEsc(src) + '</div>' : '';
      },


      async _templatesAdminWebsites() {
        var body = document.getElementById('tpl-tab-body');
        body.innerHTML = '<div class="loading">Loading...</div>';
        const data = await api('/templates');
        if (!data || !data.success) { body.innerHTML = '<div class="loading">Failed to load templates.</div>'; return; }
        const templates = data.templates || [];
        const stats = data.stats || {};
        const usage = data.usage || [];

        const statsHtml =
          '<div class="stats-grid" style="margin-bottom:16px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (stats.total_templates||0) + '</div><div class="stat-label">Total Templates</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + (stats.active_templates||0) + '</div><div class="stat-label">Active Templates</div></div>' +
            '<div class="stat-card"><div class="stat-value">' + (stats.total_industries||0) + '</div><div class="stat-label">Total Industries</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p);font-size:22px">' + (stats.most_used||'\u2014') + '</div><div class="stat-label">Most Used (' + (stats.most_used_count||0) + ' sites)</div></div>' +
          '</div>';

        const actionBar =
          '<div style="display:flex;justify-content:flex-end;margin-bottom:12px">' +
            '<button onclick="_admTemplatesOpenUpload()" style="background:var(--p);color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">+ Add Template</button>' +
          '</div>';

        body.innerHTML = statsHtml + actionBar + '<div id="tpl-library"></div><div id="tpl-usage" style="margin-top:32px"></div><div id="tpl-modal-root"></div>';

        document.getElementById('tpl-library').innerHTML = adminTable({
          id: 'tpl-library', data: templates,
          columns: [
            { key:'industry', label:'Industry', sortable:true, render:function(v,row){
                return '<strong>' + (row.name||v||'-') + '</strong>' +
                       '<div style="font-size:11px;color:var(--muted);font-family:monospace">' + (v||'-') + '</div>';
              } },
            { key:'variation',     label:'Variation', sortable:true, render:function(v){return badge(v||'luxury');} },
            { key:'block_count',   label:'Blocks',    sortable:true, render:function(v){return (v||0)+'';} },
            { key:'element_count', label:'Fields',    sortable:true, render:function(v){return (v||0)+'';} },
            { key:'field_count',   label:'Vars',      sortable:true, render:function(v){return (v||0)+'';} },
            { key:'is_active',     label:'Status',    sortable:true, render:function(v){return v?'<span class="badge badge-green">active</span>':'<span class="badge badge-amber">inactive</span>';} },
            { key:'sites_built',   label:'Usage',     sortable:true, render:function(v){return (v||0)+' sites';} },
            { key:'industry',      label:'Actions',   render:function(v,row){
                var preview = '<a href="/templates/'+v+'/preview" target="_blank" style="color:var(--p);font-size:12px;margin-right:12px">Preview</a>';
                var toggleLabel = row.is_active ? 'Disable' : 'Enable';
                var toggle = '<a href="#" onclick="_admTemplateToggle(event,\''+v+'\');return false;" style="color:var(--muted);font-size:12px;margin-right:12px">'+toggleLabel+'</a>';
                var clone = '<a href="#" onclick="_admTemplateClone(event,\''+v+'\');return false;" style="color:var(--p);font-size:12px">Clone as V2</a>';
                return preview + toggle + clone;
              } }
          ],
          searchFields: ['industry','name','variation','category'],
          defaultSort: { key:'industry', dir:'asc' },
          filters: [
            { key:'is_active', label:'Status',    options:[{value:'',label:'All'},{value:true,label:'Active'},{value:false,label:'Inactive'}] },
            { key:'variation', label:'Variation', options:[{value:'',label:'All'},{value:'luxury',label:'Luxury'},{value:'commercial',label:'Commercial'}] }
          ]
        });

        var usageRows = (usage||[]).map(function(u){
          return '<tr><td style="padding:10px 16px;border-top:1px solid var(--border)"><strong>'+u.industry+'</strong></td>' +
                 '<td style="text-align:right;padding:10px 16px;border-top:1px solid var(--border);font-variant-numeric:tabular-nums">'+u.sites_built+'</td></tr>';
        }).join('');
        document.getElementById('tpl-usage').innerHTML =
          '<h3 style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:12px">Template Usage (sites built per industry)</h3>' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;overflow:hidden">' +
            '<table style="width:100%;border-collapse:collapse">' +
              '<thead><tr style="background:var(--s2)"><th style="text-align:left;padding:10px 16px;font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.06em">Industry</th><th style="text-align:right;padding:10px 16px;font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.06em">Sites Built</th></tr></thead>' +
              '<tbody>' + (usageRows || '<tr><td colspan="2" style="padding:20px;text-align:center;color:var(--muted)">No sites built yet.</td></tr>') + '</tbody>' +
            '</table>' +
          '</div>';
      },


      // v1.4.4 (2026-05-30) — Page Templates tab body.
      // Lists Arthur's 17 code-defined page templates from
      // ArthurService::PAGE_TEMPLATE_CATALOGUE. Read-only — preview opens
      // a new tab to /page-templates/{slug}/preview (same chrome as
      // website-template preview).
      async _templatesAdminPages() {
        var body = document.getElementById('tpl-tab-body');
        body.innerHTML = '<div class="loading">Loading...</div>';
        const data = await api('/page-templates');
        if (!data || !data.success) { body.innerHTML = '<div class="loading">Failed to load page templates.</div>'; return; }
        const tpls = data.templates || [];
        const stats = data.stats || {};

        var catLabel = {
          universal: 'Universal',
          bookings_events: 'Bookings & Events',
          listings: 'Listings',
          visual_portfolios: 'Visual Portfolios',
          commerce_account: 'Commerce & Account'
        };

        var catCountsHtml = Object.keys(stats.category_counts || {}).map(function (k) {
          return '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + stats.category_counts[k] + '</div><div class="stat-label">' + (catLabel[k] || k) + '</div></div>';
        }).join('');

        var statsHtml =
          '<div class="stats-grid" style="margin-bottom:16px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (stats.total_templates || 0) + '</div><div class="stat-label">Total Page Templates</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + (stats.total_categories || 0) + '</div><div class="stat-label">Categories</div></div>' +
            catCountsHtml +
          '</div>';

        var infoBar =
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;padding:14px 18px;margin-bottom:16px;font-size:13px;color:var(--muted);line-height:1.6">' +
            '<strong style="color:var(--text)">About page templates:</strong> these are code-defined section stacks Sarah/Arthur can apply when a tenant asks to add a page (e.g. "add a booking page"). Each preview uses sample data from a representative industry. Aliases map common phrasings to the right template.' +
          '</div>';

        body.innerHTML = statsHtml + infoBar + '<div id="page-tpl-library"></div>';

        document.getElementById('page-tpl-library').innerHTML = adminTable({
          id: 'page-tpl-library', data: tpls,
          columns: [
            { key:'slug', label:'Template', sortable:true, render:function(v,row){
                return '<strong>' + (row.label || v) + '</strong>' +
                       '<div style="font-size:11px;color:var(--muted);font-family:monospace">' + v + '</div>';
              } },
            { key:'category', label:'Category', sortable:true, render:function(v){
                return badge(catLabel[v] || v);
              } },
            { key:'section_count', label:'Sections', sortable:true, render:function(v,row){
                var preview = (row.section_types||[]).slice(0,5).join(' → ');
                if ((row.section_types||[]).length > 5) preview += ' → …';
                return '<div>' + v + '</div><div style="font-size:11px;color:var(--muted);font-family:monospace">' + preview + '</div>';
              } },
            { key:'recommended_industries', label:'Industries', render:function(v){
                if (!Array.isArray(v) || v.length === 0) return '<span style="color:var(--muted)">—</span>';
                if (v.length === 1 && v[0] === '*') return '<span class="badge badge-green">any industry</span>';
                var shown = v.slice(0, 3).map(function(i){ return '<span class="badge" style="margin-right:4px">' + i + '</span>'; }).join('');
                var more = v.length > 3 ? '<span style="color:var(--muted);font-size:11px">+' + (v.length - 3) + '</span>' : '';
                return shown + more;
              } },
            { key:'aliases', label:'Aliases', render:function(v){
                if (!Array.isArray(v) || v.length === 0) return '<span style="color:var(--muted);font-size:11px">—</span>';
                return '<span style="font-family:monospace;font-size:11px;color:var(--muted)">' + v.join(', ') + '</span>';
              } },
            { key:'slug', label:'Actions', render:function(v,row){
                var preview = '<a href="' + (row.preview_url || ('/page-templates/' + v + '/preview')) + '" target="_blank" style="color:var(--p);font-size:12px;margin-right:12px">Preview</a>';
                var desc = '<a href="#" onclick="_admPageTemplateInfo(event,\'' + v + '\');return false;" style="color:var(--muted);font-size:12px">Details</a>';
                return preview + desc;
              } }
          ],
          searchFields: ['slug','label','description','category'],
          defaultSort: { key:'category', dir:'asc' },
          filters: [
            { key:'category', label:'Category', options:[
              {value:'',label:'All'},
              {value:'universal',label:'Universal'},
              {value:'bookings_events',label:'Bookings & Events'},
              {value:'listings',label:'Listings'},
              {value:'visual_portfolios',label:'Visual Portfolios'},
              {value:'commerce_account',label:'Commerce & Account'}
            ] }
          ]
        });
      }
});




    // -- Actions -------------------------------------------------------------------
    async function suspendUser(id, currentStatus) {
      const action = currentStatus === 'suspended' ? 'unsuspend' : 'suspend';
      var ok = await adminConfirm(action.charAt(0).toUpperCase() + action.slice(1) + ' this user?', 'Confirm Action'); if (!ok) return;
      await api('/users/' + id + '/' + action, 'POST');
      pages.users();
    }

    async function viewWorkspace(id) {
      const data = await api('/workspaces/' + id);
      if (!data) { (typeof showAdminToast === 'function') && showAdminToast('Failed to load — see console for details', 'error'); return; }
      const ws = data.workspace;
      showAdminToast(ws.name + ' \u2014 Plan: ' + (data.subscription?.plan?.name || 'Free') + ', Credits: ' + (data.credit?.balance || 0) + ', Tasks: ' + (data.task_count || 0) + ' (' + (data.task_completed || 0) + ' completed)', 'info');
    }

    async function retryTask(id) {
      await api('/tasks/' + id + '/retry', 'POST');
      pages.tasks();
    }

    async function recoverStale() {
      const btn = event.currentTarget;
      btn.disabled = true;
      btn.textContent = 'Recovering...';
      await api('/recover-stale', 'POST');
      btn.disabled = false;
      btn.textContent = 'Recover Stale';
      if (currentPage === 'queue') pages.queue();
      else pages.tasks();
    }

    // Phase 2E (2026-05-10) — orchestration tab "Recover Orphans" button.
    async function recoverOrphansAdmin() {
      const btn = event.currentTarget;
      btn.disabled = true;
      btn.textContent = 'Recovering...';
      const r = await api('/orchestration/recover-orphans', 'POST');
      btn.disabled = false;
      btn.textContent = 'Recover Orphans';
      if (typeof showAdminToast === 'function') {
        showAdminToast('Recovered ' + ((r && r.recovered) || 0) + ' orphan task(s)', 'info');
      }
      pages.orchestration();
    }

    async function saveSettings() {
      const inputs = document.querySelectorAll('[id^="setting_"]');
      const payload = {};
      inputs.forEach(input => {
        if (input.value) {
          const key = input.id.replace('setting_', '');
          payload[key] = input.value;
        }
      });
      if (!Object.keys(payload).length) { showAdminToast('No changes to save', 'info'); return; }
      const res = await api('/config', 'POST', payload);
      showAdminToast(res?.success ? 'Saved ' + res.updated + ' setting(s)' : 'Save failed', res?.success ? 'success' : 'error');
    }

    async function saveMemberRole(id) {
      const role = document.getElementById('role_' + id).value;
      const res = await api('/memberships/' + id, 'PUT', { role });
      if (res && res.success) {
        showAdminToast('Role updated', 'success');
        pages.memberships();
      } else {
        showAdminToast('Failed to update role', 'error');
      }
    }

    // -- New Actions (Phase 1) -----------------------------------------------------
    async function revokeSession(id) {
      var ok = await adminConfirm('Revoke this session? The user will be logged out.', 'Revoke Session'); if (!ok) return;
      const res = await api('/sessions/' + id + '/revoke', 'POST');
      if (res?.success) pages.sessions();
      else showAdminToast('Failed to revoke session', 'error');
    }

    async function adjustWorkspaceCredits(wsId, wsName) {
      const amount = await adminPrompt('Enter positive number to add, negative to deduct:', 'Adjust Credits for "' + wsName + '"');
      if (amount === null || amount === '') return;
      const num = parseInt(amount, 10);
      if (isNaN(num)) { showAdminToast('Invalid number', 'error'); return; }
      const reason = await adminPrompt('Enter reason for adjustment:', 'Reason');
      if (!reason) { showAdminToast('Reason is required', 'warning'); return; }
      const res = await api('/workspaces/' + wsId + '/credits', 'POST', { amount: num, reason: reason });
      if (res?.balance !== undefined) {
        showAdminToast('Credits adjusted. New balance: ' + res.balance, 'success');
        pages.credits();
      } else {
        showAdminToast('Failed to adjust credits', 'error');
      }
    }

    // ── House account mutations (added 2026-07-29, audit finding A) ────────
    // These call endpoints that already existed. Both write to the credit
    // ledger or to billing configuration, so each one confirms first and each
    // one re-renders from the server rather than patching the row in place —
    // the balance shown after a top-up is the balance the API reports, never a
    // number this page calculated.

    async function houseTopUp(id, name) {
      const raw = await adminPrompt('Credits to add (positive whole number):', 'Top Up "' + name + '"');
      if (raw === null || raw === '') return;
      const amount = parseInt(raw, 10);
      if (isNaN(amount) || amount <= 0) { showAdminToast('Enter a positive whole number', 'error'); return; }
      const ok = await adminConfirm(
        'Add ' + amount + ' credits to "' + name + '"?\n\nThis writes a house_account_manual_topup row to the credit ledger and cannot be undone from this screen.',
        'Confirm Top Up');
      if (!ok) return;
      const res = await api('/house-accounts/' + id + '/top-up', 'POST', { amount: amount });
      if (res && res.success) {
        showAdminToast('Topped up — new balance ' + res.new_balance, 'success');
        pages.houseAccount();
      } else {
        showAdminToast((res && res.error) || 'Top-up failed', 'error');
      }
    }

    async function houseAllowance(id, name, current) {
      const raw = await adminPrompt('Monthly credit allowance (whole number, 0 to disable):', 'Allowance for "' + name + '"', String(current || 0));
      if (raw === null || raw === '') return;
      const value = parseInt(raw, 10);
      if (isNaN(value) || value < 0) { showAdminToast('Enter a whole number of 0 or more', 'error'); return; }
      if (value === Number(current || 0)) { showAdminToast('Allowance unchanged', 'info'); return; }
      const res = await api('/house-accounts/' + id + '/settings', 'PUT', { monthly_credit_allowance: value });
      if (res && res.success) {
        showAdminToast('Allowance set to ' + value + '/mo', 'success');
        pages.houseAccount();
      } else {
        showAdminToast((res && res.error) || 'Update failed', 'error');
      }
    }

    async function houseToggleReplenish(id, name, current) {
      const next = !current;
      const ok = await adminConfirm(
        (next ? 'Enable' : 'Disable') + ' automatic monthly replenishment for "' + name + '"?' +
        (next ? '' : '\n\nWith this off the account will run to zero and its agents will stop once the balance is exhausted.'),
        next ? 'Enable Auto-Replenish' : 'Disable Auto-Replenish');
      if (!ok) return;
      const res = await api('/house-accounts/' + id + '/settings', 'PUT', { credits_auto_replenish: next });
      if (res && res.success) {
        showAdminToast('Auto-replenish ' + (next ? 'enabled' : 'disabled'), 'success');
        pages.houseAccount();
      } else {
        showAdminToast((res && res.error) || 'Update failed', 'error');
      }
    }

    async function retryFailedJob(id) {
      var ok = await adminConfirm('Remove this failed job from the queue? (Manual re-dispatch may be needed)', 'Retry Job'); if (!ok) return;
      const res = await api('/failed-jobs/' + id + '/retry', 'POST');
      if (res?.success) pages.queue();
      else showAdminToast('Failed to process', 'error');
    }

    async function deleteFailedJob(id) {
      var ok = await adminConfirm('Delete this failed job permanently?', 'Delete Job'); if (!ok) return;
      const res = await api('/failed-jobs/' + id, 'DELETE');
      if (res?.success) pages.queue();
      else showAdminToast('Failed to delete', 'error');
    }

    async function purgeFailedJobs() {
      var ok1 = await adminConfirm('PURGE ALL failed jobs? This cannot be undone.', 'Purge Jobs'); if (!ok1) return;
      var ok2 = await adminConfirm('Are you sure? This will delete ALL failed job records.', 'Final Confirmation'); if (!ok2) return;
      const res = await api('/failed-jobs/purge', 'POST');
      if (res?.success) {
        showAdminToast('Purged ' + (res.purged || 0) + ' failed jobs', 'success');
        pages.queue();
      } else {
        showAdminToast('Failed to purge', 'error');
      }
    }

    // -- New Actions (Phase 2 — AI Engines + Analytics) ----------------------------
    function showEngineDetail(el) {
      try {
        const e = JSON.parse(el.dataset.engine);
        const details = 'Engine: ' + e.name + '\n' +
          'Version: ' + (e.version || '1.0') + '\n' +
          'Status: ' + e.status + '\n' +
          'Routes: ' + (e.route_count || 0) + '\n' +
          'Total Tasks: ' + (e.total_tasks || 0) + '\n' +
          'Failed Tasks: ' + (e.failed_tasks || 0) + '\n' +
          'Last Execution: ' + (e.last_execution || 'Never') + '\n' +
          'Description: ' + (e.description || 'N/A');
        showAdminToast(details, 'info');
      } catch(err) { console.error('Engine detail error', err); }
    }

    // filterCapabilities replaced by adminTable system

    async function viewAnalyticsWorkspace(id) {
      const data = await api('/analytics/workspace/' + id);
      if (!data) { (typeof showAdminToast === 'function') && showAdminToast('Failed to load — see console for details', 'error'); return; }
      const ws = data.workspace || {};
      showAdminToast('Workspace: ' + (ws.name || id) + ' \u2014 Plan: ' + (ws.plan || 'N/A') + ', Tasks: ' + (ws.total_tasks || 0) + ', Credits Used: ' + (ws.credits_used || 0) + ', Members: ' + (ws.member_count || 0), 'info');
    }


    // ================================================================
    // BELLA AI CHAT INTERFACE
    // ================================================================

    // Persist history across SPA navigation (window-level)
    if (!window.bellaHistory) window.bellaHistory = [];
    let bellaOpen = false;

    function toggleBella() {
      bellaOpen = !bellaOpen;
      document.getElementById('bella-panel').classList.toggle('open', bellaOpen);
      document.getElementById('bella-overlay').classList.toggle('open', bellaOpen);
      document.getElementById('bella-toggle-btn').classList.toggle('active', bellaOpen);
      if (bellaOpen) {
        setTimeout(() => document.getElementById('bella-input').focus(), 300);
      }
    }

    // Simple markdown renderer
    function bellaRenderMarkdown(text) {
      if (!text) return '';
      let html = text;

      // Code blocks (``` ... ```)
      html = html.replace(/```([\s\S]*?)```/g, function(m, code) {
        return '<pre><code>' + code.replace(/</g, '&lt;').replace(/>/g, '&gt;').trim() + '</code></pre>';
      });

      // Inline code
      html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

      // Bold
      html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

      // Headers (## and ###)
      html = html.replace(/^### (.+)$/gm, '<h3 style="font-size:13px;font-weight:600;margin:6px 0 2px;color:var(--text)">$1</h3>');
      html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');

      // Unordered lists
      html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
      html = html.replace(/(<li>.*<\/li>)/s, function(m) {
        // Wrap consecutive <li> in <ul>
        return '<ul>' + m + '</ul>';
      });
      // Fix multiple <ul> wraps — merge consecutive
      html = html.replace(/<\/ul>\s*<ul>/g, '');

      // Numbered lists
      html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

      // Newlines to <br> (but not inside pre/code blocks)
      const parts = html.split(/(<pre>[\s\S]*?<\/pre>)/);
      html = parts.map((part, i) => {
        if (part.startsWith('<pre>')) return part;
        return part.replace(/\n/g, '<br>');
      }).join('');

      return html;
    }

    function bellaAddMessage(role, text) {
      const messages = document.getElementById('bella-messages');
      // Remove welcome message on first chat
      const welcome = document.getElementById('bella-welcome');
      if (welcome) welcome.remove();

      const div = document.createElement('div');

      if (role === 'user') {
        div.className = 'bella-msg bella-msg-user';
        div.textContent = text;
      } else if (role === 'bella') {
        div.className = 'bella-msg bella-msg-bella';
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' + bellaRenderMarkdown(text) + '</div>' +
          '</div>';
      } else if (role === 'image') {
        // Bella Session 3: render generated image inline
        div.className = 'bella-msg bella-msg-bella';
        const imgData = typeof text === 'object' ? text : { url: text, prompt: '' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(imgData.reply || 'Here\u2019s the image:') + '</div>' +
              '<img src="' + (imgData.url||'') + '" style="max-width:100%;border-radius:8px;border:1px solid var(--border);margin:4px 0" loading="lazy">' +
              '<div style="display:flex;gap:8px;margin-top:6px">' +
                '<a href="' + (imgData.url||'') + '" target="_blank" download style="font-size:11px;color:var(--p);text-decoration:none">&#8681; Download</a>' +
                (imgData.asset_id ? '<span style="font-size:10px;color:var(--muted)">Asset #' + imgData.asset_id + '</span>' : '') +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'document') {
        // Bella Session 4: render document card with Word + PDF download buttons
        div.className = 'bella-msg bella-msg-bella';
        const d = typeof text === 'object' ? text : { title: 'Document' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(d.reply || 'Document ready:') + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px">' +
                '<div style="font-size:28px;flex-shrink:0">&#128196;</div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (d.title||'Document').replace(/</g,'&lt;') + '</div>' +
                  '<div style="font-size:11px;color:var(--muted);margin-top:2px">' + (d.sections||'?') + ' sections</div>' +
                  '<div style="display:flex;gap:8px;margin-top:8px">' +
                    (d.docx_url ? '<a href="' + d.docx_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Word</a>' : '') +
                    (d.pdf_url ? '<a href="' + d.pdf_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PDF</a>' : '') +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'presentation') {
        // Bella Session 5: render presentation card with PPT + PDF download
        div.className = 'bella-msg bella-msg-bella';
        const p = typeof text === 'object' ? text : { title: 'Presentation' };
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(p.reply || 'Presentation ready:') + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px">' +
                '<div style="font-size:28px;flex-shrink:0">&#128202;</div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (p.title||'Presentation').replace(/</g,'&lt;') + '</div>' +
                  '<div style="font-size:11px;color:var(--muted);margin-top:2px">' + (p.slide_count||'?') + ' slides</div>' +
                  '<div style="display:flex;gap:8px;margin-top:8px">' +
                    (p.pptx_url ? '<a href="' + p.pptx_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PowerPoint</a>' : '') +
                    (p.pdf_url ? '<a href="' + p.pdf_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; PDF</a>' : '') +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</div>';
      } else if (role === 'video') {
        // Bella Session 6: render video card with player or progress indicator
        div.className = 'bella-msg bella-msg-bella';
        const v = typeof text === 'object' ? text : { prompt: 'Video' };
        const hasUrl = v.video_url && v.video_url !== '';
        div.innerHTML =
          '<div class="bella-msg-avatar-row">' +
            '<div class="bella-msg-mini-avatar">B</div>' +
            '<div class="bella-msg-content">' +
              '<div style="margin-bottom:6px;font-size:13px;color:var(--text)">' + bellaRenderMarkdown(v.reply || (hasUrl ? 'Video ready:' : 'Video is being generated...')) + '</div>' +
              '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:12px">' +
                (hasUrl
                  ? '<video src="' + v.video_url + '" controls style="width:100%;border-radius:6px;max-height:240px" preload="metadata"></video>'
                    + '<div style="display:flex;gap:8px;margin-top:8px">'
                    + '<a href="' + v.video_url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Download</a>'
                    + (v.asset_id ? '<span style="font-size:10px;color:var(--muted);align-self:center">Asset #' + v.asset_id + '</span>' : '')
                    + '</div>'
                  : '<div style="display:flex;align-items:center;gap:10px;padding:8px 0">'
                    + '<div style="font-size:24px">&#127909;</div>'
                    + '<div>'
                    + '<div style="font-size:13px;font-weight:600;color:var(--text)">' + (v.prompt||'Video').replace(/</g,'&lt;').slice(0,60) + '</div>'
                    + '<div style="font-size:11px;color:var(--muted)" id="bella-video-status-' + (v.asset_id||0) + '">'
                    + (v.status === 'in_progress' ? '&#9203; Generating... (polling every 5s)' : 'Status: ' + (v.status||'completed'))
                    + '</div></div></div>'
                ) +
              '</div>' +
            '</div>' +
          '</div>';
        // Start polling if still in progress
        if (!hasUrl && v.asset_id && v.status === 'in_progress') {
          bellaStartVideoPoll(v.asset_id);
        }
      } else if (role === 'error') {
        div.className = 'bella-error';
        div.textContent = text;
      }

      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
      return div;
    }

    function bellaShowTyping() {
      const messages = document.getElementById('bella-messages');
      const div = document.createElement('div');
      div.className = 'bella-typing';
      div.id = 'bella-typing';
      div.innerHTML =
        '<div class="bella-msg-mini-avatar">B</div>' +
        '<div class="bella-typing-dots">' +
          '<div class="bella-typing-dot"></div>' +
          '<div class="bella-typing-dot"></div>' +
          '<div class="bella-typing-dot"></div>' +
        '</div>';
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    function bellaHideTyping() {
      const el = document.getElementById('bella-typing');
      if (el) el.remove();
    }

    async function bellaChat(message) {
      if (!message || !message.trim()) return;
      message = message.trim();

      // Add user message
      bellaAddMessage('user', message);

      // Add to history
      window.bellaHistory.push({ role: 'user', content: message });

      // Show typing
      bellaShowTyping();

      // Disable input while processing
      const input = document.getElementById('bella-input');
      const sendBtn = document.getElementById('bella-send-btn');
      input.disabled = true;
      sendBtn.disabled = true;

      try {
        const res = await fetch('/api/admin/bella', {
          method: 'POST',
          headers: {
            'Authorization': 'Bearer ' + token,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            message: message,
            history: window.bellaHistory.slice(-18), // Send last 18 turns (9 exchanges)
            context_request: _bellaContext || undefined, // Platform stats injected silently
          }),
        });

        bellaHideTyping();

        if (!res.ok) {
          const errData = await res.json().catch(() => ({}));
          throw new Error(errData.error || errData.message || 'Request failed (' + res.status + ')');
        }

        const data = await res.json();
        const reply = data.reply || data.message || data.response || 'No response received.';

        // Bella Session 3+4+5: detect image/document/presentation responses
        if (data.type === 'image' && data.image_url) {
          bellaAddMessage('image', { url: data.image_url, reply: reply, asset_id: data.asset_id, prompt: data.prompt });
        } else if (data.type === 'document') {
          bellaAddMessage('document', { title: data.title, reply: reply, docx_url: data.docx_url, pdf_url: data.pdf_url, sections: data.sections });
        } else if (data.type === 'presentation') {
          bellaAddMessage('presentation', { title: data.title, reply: reply, pptx_url: data.pptx_url, pdf_url: data.pdf_url, slide_count: data.slide_count });
        } else if (data.type === 'video') {
          bellaAddMessage('video', { video_url: data.video_url, reply: reply, asset_id: data.asset_id, status: data.status, prompt: data.prompt, scene_count: data.scene_count });
        } else {
          bellaAddMessage('bella', reply);
        }

        // Store assistant reply in history
        window.bellaHistory.push({ role: 'assistant', content: reply });

        // Trim history to max 20 turns (10 exchanges)
        if (window.bellaHistory.length > 20) {
          window.bellaHistory = window.bellaHistory.slice(-20);
        }

      } catch (err) {
        bellaHideTyping();
        bellaAddMessage('error', 'Error: ' + err.message);
        console.error('Bella chat error:', err);
      } finally {
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
      }
    }

    function bellaSend() {
      const input = document.getElementById('bella-input');
      const msg = input.value;
      input.value = '';
      input.style.height = 'auto';
      bellaChat(msg);
    }

    function bellaQuickAction(action) {
      const prompts = {
        report: 'Generate a full system status report',
        users: 'Give me a summary of all users and their activity',
        credits: 'Show me credit balances and recent transactions across all workspaces',
        queue: 'What is the current queue status? Any failed jobs?',
      };
      const msg = prompts[action];
      if (msg) {
        // Open panel if not open
        if (!bellaOpen) toggleBella();
        bellaChat(msg);
      }
    }

    // Auto-resize textarea
    document.addEventListener('DOMContentLoaded', function() {
      const bellaInput = document.getElementById('bella-input');
      if (bellaInput) {
        bellaInput.addEventListener('input', function() {
          this.style.height = 'auto';
          this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        bellaInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            bellaSend();
          }
        });
      }
    });

    // Keyboard shortcut: Ctrl+B to toggle Bella
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        toggleBella();
      }
      // Escape to close
      if (e.key === 'Escape' && bellaOpen) {
        toggleBella();
      }
    });

    // ── Admin Template Library — row actions + upload modal ─────
    // Exposed on window so the table's onclick handlers can find them.
    window._admTemplateToggle = function(e, industry) {
      if (e && e.preventDefault) e.preventDefault();
      api('/templates/' + industry + '/toggle', 'POST').then(function(r){
        if (r && r.success) { pages.templatesAdmin(); }
        else alert('Toggle failed: ' + ((r && r.error) || 'unknown error'));
      });
    };
    window._admTemplateClone = function(e, industry) {
      if (e && e.preventDefault) e.preventDefault();
      if (!confirm('Clone "' + industry + '" as a commercial variant? A new folder "' + industry + '_commercial" will be created in storage/templates/.')) return;
      api('/templates/' + industry + '/clone', 'POST').then(function(r){
        if (r && r.success) { alert('Cloned to ' + r.cloned_to); pages.templatesAdmin(); }
        else alert('Clone failed: ' + ((r && r.error) || 'unknown error'));
      });
    };
    // v1.4.4 (2026-05-30) — Page template details popup
    window._admPageTemplateInfo = function(e, slug) {
      if (e && e.preventDefault) e.preventDefault();
      api('/page-templates').then(function(r){
        if (!r || !r.success) { alert('Failed to load page template details.'); return; }
        var tpl = (r.templates || []).find(function(t){ return t.slug === slug; });
        if (!tpl) { alert('Page template not found: ' + slug); return; }
        var industries = (tpl.recommended_industries || []).join(', ') || '—';
        var aliases    = (tpl.aliases || []).join(', ') || '—';
        var sections   = (tpl.section_types || []).join(' → ');
        var msg = tpl.label + ' (' + tpl.slug + ')\n\n'
                + 'Category: ' + tpl.category + '\n\n'
                + 'Description:\n' + tpl.description + '\n\n'
                + 'Recommended industries:\n' + industries + '\n\n'
                + 'Aliases:\n' + aliases + '\n\n'
                + 'Section stack (' + tpl.section_count + ' sections):\n' + sections;
        alert(msg);
      });
    };
    window._admTemplatesOpenUpload = function() {
      var root = document.getElementById('tpl-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="tpl-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'tpl-modal\')_admTemplatesCloseUpload()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:480px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">' +
              '<div style="font-weight:600;font-size:15px">Add Template</div>' +
              '<button type="button" onclick="_admTemplatesCloseUpload()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px;line-height:1">\u2715</button>' +
            '</div>' +
            '<form id="tpl-upload-form" onsubmit="_admTemplatesSubmit(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Industry Key (lowercase letters/numbers/underscore)<input name="industry" required pattern="[a-z0-9_]+" placeholder="e.g. dental_clinic" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Display Name<input name="name" required placeholder="e.g. Dental Clinic Premium" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Variation<select name="variation" style="display:block;width:100%;margin-top:6px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><option value="luxury">Luxury</option><option value="commercial">Commercial</option></select></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">template.html (max 2 MB)<input name="template_html" type="file" accept=".html,text/html" required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">manifest.json (max 1 MB)<input name="manifest_json" type="file" accept=".json,application/json" required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<div id="tpl-upload-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">' +
                '<button type="button" onclick="_admTemplatesCloseUpload()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button>' +
                '<button type="submit" style="background:var(--p);color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Upload</button>' +
              '</div>' +
            '</form>' +
          '</div>' +
        '</div>';
    };
    window._admTemplatesCloseUpload = function() {
      var root = document.getElementById('tpl-modal-root');
      if (root) root.innerHTML = '';
    };
    window._admTemplatesSubmit = async function(e) {
      e.preventDefault();
      var form = e.target;
      var fd = new FormData(form);
      var errEl = document.getElementById('tpl-upload-err');
      if (errEl) errEl.style.display = 'none';
      try {
        // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
        var resp = await fetch('/api/admin/templates/upload', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
          body: fd
        });
        var d = await resp.json().catch(function(){ return {}; });
        if (d && d.success) {
          _admTemplatesCloseUpload();
          pages.templatesAdmin();
        } else {
          if (errEl) { errEl.textContent = (d && d.error) || ('Upload failed (HTTP ' + resp.status + ')'); errEl.style.display = 'block'; }
        }
      } catch (err) {
        if (errEl) { errEl.textContent = 'Upload failed: ' + (err && err.message || err); errEl.style.display = 'block'; }
      }
    };

    // ── HTML escape polyfill ──
    // bld_escH comes from the main-app JS bundle (/app/js/*) and isn't
    // defined inside the admin blade context. Provide a local fallback so
    // both templatesAdmin() and the Media Library can escape user-supplied
    // strings safely when the main bundle isn't loaded.
    if (typeof window.bld_escH !== 'function') {
      window.bld_escH = function(s) {
        if (s === null || s === undefined) return '';
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };
    }

    // ── Admin Media Library — grid/list view, stats, filters, upload, arthur-uploads tab ──
    // Single coordinator that drives the whole assets page. State lives on
    // window._adminMedia so switching tabs / pages / filters is cheap.
    async function _mediaRender() {
      var st = window._adminMedia;
      setContent(
        _mediaShellHtml() +
        '<div id="media-stats-row" class="stats-grid" style="margin-bottom:16px"></div>' +
        '<div id="media-tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:16px">' +
          _mediaTabBtn('library', '\u{1F4C1} Library') +
          _mediaTabBtn('videos', '\u{1F3AC} Videos') +
          _mediaTabBtn('uploads', '\u{1F464} Website Uploads') +
        '</div>' +
        '<div id="media-body"></div>' +
        '<div id="media-modal-root"></div>'
      );
      await _mediaLoadStats();
      if (st.tab === 'uploads')      { await _mediaLoadArthurUploads(); }
      else if (st.tab === 'videos')  { await _mediaLoadVideos(); }
      else                            { await _mediaLoadLibrary(); }
    }

    function _mediaShellHtml() {
      return ''
        + '<style>'
        +   '.mediaTabBtn{padding:10px 18px;background:transparent;border:none;color:var(--muted);font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;letter-spacing:.02em}'
        +   '.mediaTabBtn.active{color:var(--p);border-bottom-color:var(--p)}'
        +   '.mediaCard{background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s,border-color .2s}'
        +   '.mediaCard:hover{border-color:var(--p);transform:translateY(-2px)}'
        +   '.mediaThumb{aspect-ratio:4/3;background-color:var(--s2);position:relative;overflow:hidden}'
        +   '.mediaThumb img,.mediaThumb video{width:100%;height:100%;object-fit:cover;display:block}'
        +   '.mediaThumbMissing::after{content:"Image unavailable";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px}'
        +   '.mediaBadge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.65);color:#fff;padding:4px 10px;border-radius:4px;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}'
        +   '.mediaBadgePlatform{background:var(--p)}'
        +   '.mediaMeta{padding:12px 14px;font-size:12px;color:var(--muted);display:flex;flex-direction:column;gap:6px;flex:1}'
        +   '.mediaMetaRow{display:flex;justify-content:space-between;gap:8px;font-size:11px}'
        +   '.mediaActions{display:flex;gap:4px;padding:10px 14px;border-top:1px solid var(--border);background:var(--s2)}'
        +   '.mediaActionBtn{flex:1;font-size:11px;padding:6px 8px;border:1px solid var(--border);background:transparent;color:var(--text);border-radius:4px;cursor:pointer;letter-spacing:.04em}'
        +   '.mediaActionBtn:hover{border-color:var(--p);color:var(--p)}'
        +   '.mediaActionBtn.danger:hover{border-color:#F87171;color:#F87171}'
        +   '.mediaFilters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}'
        +   '.mediaFilters input,.mediaFilters select{background:var(--s2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:6px;font-size:13px;font-family:inherit}'
        +   '.mediaFilters input{min-width:220px}'
        +   '.mediaPager{display:flex;gap:8px;align-items:center;justify-content:center;margin:20px 0;font-size:13px;color:var(--muted)}'
        +   '.mediaPager button{background:var(--s2);border:1px solid var(--border);color:var(--text);padding:7px 14px;border-radius:6px;cursor:pointer;font-size:13px}'
        +   '.mediaPager button:disabled{opacity:.4;cursor:not-allowed}'
        + '</style>';
    }

    function _mediaTabBtn(tab, label) {
      var active = window._adminMedia.tab === tab;
      return '<button class="mediaTabBtn' + (active?' active':'') + '" onclick="_mediaSwitchTab(\'' + tab + '\')">' + label + '</button>';
    }

    window._mediaSwitchTab = function(tab) {
      window._adminMedia.tab = tab;
      pages.assets();
    };

    async function _mediaLoadStats() {
      var res = await api('/media/stats');
      var row = document.getElementById('media-stats-row');
      if (!row) return;
      if (!res || !res.success) { row.innerHTML = '<div class="stat-card"><div class="stat-value">\u2014</div><div class="stat-label">Stats unavailable</div></div>'; return; }
      row.innerHTML =
        '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (res.total_files||0) + '</div><div class="stat-label">Total Files</div></div>' +
        '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + (res.platform||0) + '</div><div class="stat-label">Platform Assets</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + (res.workspace||0) + '</div><div class="stat-label">Workspace Uploads</div></div>' +
        '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (res.storage_human||'0 B') + '</div><div class="stat-label">Storage Used</div></div>';
    }

    async function _mediaLoadLibrary() {
      var st = window._adminMedia;
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading media...</div>';

      var qs = new URLSearchParams({
        page: st.page,
        per_page: st.per_page,
        search: st.filters.search || '',
        category: st.filters.category || '',
        industry: st.filters.industry || '',
        type: st.filters.type || '',
        source: st.filters.source || '',
      }).toString();
      var res = await api('/media?' + qs);
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load media</div>'; return; }

      var items = res.data || [];
      var filters = res.filters || { categories:[], industries:[], sources:[] };

      // Asset type sub-tabs (All / Images / Videos / Documents)
      var typeTabs = ['','image','video','document'].map(function(t){
        var label = t==='' ? 'All' : (t==='image'?'Images':(t==='video'?'Videos':'Documents'));
        var active = (st.filters.type||'') === t;
        return '<button onclick="_mediaSetFilter(\'type\',\''+t+'\')" style="padding:8px 16px;background:'+(active?'var(--p)':'transparent')+';color:'+(active?'#fff':'var(--muted)')+';border:1px solid '+(active?'var(--p)':'var(--border)')+';border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;letter-spacing:.04em">'+label+'</button>';
      }).join('');

      // Bulk selection badge (only in select mode)
      var selCount = (st.selected||[]).length;
      var selBar = st.selectMode ?
        '<span style="color:var(--muted);font-size:12px;padding:8px 10px">' + selCount + ' selected</span>' +
        '<button onclick="_mediaBulkDelete()" ' + (selCount?'':'disabled') + ' style="padding:8px 14px;background:'+(selCount?'#DC2626':'var(--s2)')+';color:#fff;border:none;border-radius:6px;cursor:'+(selCount?'pointer':'not-allowed')+';font-size:12px;font-weight:600;opacity:'+(selCount?1:.4)+'">Delete ' + (selCount||'') + '</button>' +
        '<button onclick="_mediaToggleSelectMode()" style="padding:8px 14px;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px">Cancel</button>'
        :
        '<button onclick="_mediaToggleSelectMode()" style="padding:8px 14px;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px">\u2611 Select</button>';

      var filterHtml =
        '<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">' + typeTabs + '</div>' +
        '<div class="mediaFilters">' +
          '<input type="search" id="media-search" placeholder="Search filename, description, tags\u2026" value="' + bld_escH(st.filters.search) + '" onkeydown="if(event.key===\'Enter\')_mediaApplySearch()">' +
          '<select id="media-category" onchange="_mediaSetFilter(\'category\',this.value)">' + _mediaOptions(['',...(filters.categories||[])], st.filters.category, function(v){return v===''?'All categories':_mediaCap(v);}) + '</select>' +
          '<select id="media-industry" onchange="_mediaSetFilter(\'industry\',this.value)">' + _mediaOptions(['',...(filters.industries||[])], st.filters.industry, function(v){return v===''?'All industries':_mediaCap(v);}) + '</select>' +
          '<select id="media-source" onchange="_mediaSetFilter(\'source\',this.value)">' + _mediaOptions(['',...(filters.sources||[])], st.filters.source, function(v){return v===''?'All sources':_mediaCap(v);}) + '</select>' +
          '<div style="margin-left:auto;display:flex;gap:6px;align-items:center">' +
            selBar +
            '<button onclick="_mediaSetView(\'grid\')" style="padding:8px 12px;background:' + (st.view==='grid'?'var(--p)':'var(--s2)') + ';color:' + (st.view==='grid'?'#fff':'var(--text)') + ';border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">\u2B1A Grid</button>' +
            '<button onclick="_mediaSetView(\'list\')" style="padding:8px 12px;background:' + (st.view==='list'?'var(--p)':'var(--s2)') + ';color:' + (st.view==='list'?'#fff':'var(--text)') + ';border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">\u2630 List</button>' +
            '<button onclick="_mediaOpenGenerate()" style="padding:8px 18px;background:var(--ac);color:#0F1117;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">\u2726 Generate</button>' +
            '<button onclick="_mediaOpenUpload()" style="padding:8px 18px;background:var(--p);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">+ Upload</button>' +
          '</div>' +
        '</div>';

      var gridOrList = st.view === 'list' ? _mediaListHtml(items) : _mediaGridHtml(items);
      var pager = _mediaPagerHtml(res);

      body.innerHTML = filterHtml + gridOrList + pager;
    }

    // Bulk selection + tag editor helpers
    window._mediaToggleSelectMode = function() {
      var st = window._adminMedia;
      st.selectMode = !st.selectMode;
      st.selected = [];
      _mediaLoadLibrary();
    };
    window._mediaToggleSelected = function(id) {
      var st = window._adminMedia;
      if (!st.selectMode) return;
      var idx = st.selected.indexOf(id);
      if (idx >= 0) st.selected.splice(idx, 1);
      else st.selected.push(id);
      _mediaLoadLibrary();
    };
    window._mediaBulkDelete = async function() {
      var st = window._adminMedia;
      if (!st.selected || !st.selected.length) return;
      if (!confirm('Delete ' + st.selected.length + ' media files? This removes the DB rows AND the files on disk.')) return;
      // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
      var r = await fetch('/api/admin/media/bulk-delete', { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+token}, body: JSON.stringify({ ids: st.selected }) });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) {
        alert('Deleted ' + d.rows_deleted + ' rows, removed ' + d.files_removed + ' files from disk.');
        st.selected = []; st.selectMode = false;
        _mediaLoadStats(); _mediaLoadLibrary();
      } else alert('Bulk delete failed: ' + ((d && d.error) || 'unknown'));
    };

    window._mediaEditTags = async function(id, currentTagsJson) {
      var current = [];
      try { current = JSON.parse(currentTagsJson || '[]') || []; } catch(e){}
      var next = prompt('Tags (comma-separated):', current.join(', '));
      if (next === null) return; // cancelled
      var tags = next.split(',').map(function(t){return t.trim();}).filter(function(t){return t;});
      // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
      var r = await fetch('/api/admin/media/' + id + '/tags', { method:'PATCH', headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+token}, body: JSON.stringify({ tags: tags }) });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) _mediaLoadLibrary();
      else alert('Tag update failed: ' + ((d && d.error) || 'unknown'));
    };

    window._mediaShowUsage = async function(id) {
      // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
      var r = await fetch('/api/admin/media/' + id + '/usage', { headers:{'Accept':'application/json','Authorization':'Bearer '+token} });
      var d = await r.json().catch(function(){return {};});
      if (!d || !d.success) { alert('Usage lookup failed'); return; }
      var lines = ['Media #' + id + ' — ' + (d.url || '')];
      lines.push('Total usage: ' + (d.total_usage||0));
      if (d.websites && d.websites.length) {
        lines.push('\nWebsites (' + d.websites.length + '):');
        d.websites.forEach(function(w){ lines.push('  \u2022 #' + w.id + ' ' + (w.name||'') + ' [' + (w.template_industry||'?') + ', ' + (w.status||'?') + ']'); });
      }
      if (d.articles && d.articles.length) {
        lines.push('\nArticles (' + d.articles.length + '):');
        d.articles.forEach(function(a){ lines.push('  \u2022 #' + a.id + ' ' + (a.title||'') + ' [' + (a.status||'?') + ']'); });
      }
      if (!d.total_usage) lines.push('\nNot used in any site or article yet.');
      alert(lines.join('\n'));
    };

    function _mediaFormatDuration(s) {
      if (!s || s <= 0) return '';
      var m = Math.floor(s/60), ss = s % 60;
      return m + ':' + (ss<10?'0':'') + ss;
    }

    function _mediaOptions(values, current, labelFn) {
      return values.map(function(v){
        var sel = (String(v) === String(current || '')) ? ' selected' : '';
        return '<option value="' + bld_escH(v) + '"' + sel + '>' + bld_escH(labelFn(v)) + '</option>';
      }).join('');
    }

    function _mediaCap(v) {
      if (!v) return v;
      return String(v).replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();});
    }

    window._mediaSetFilter = function(key, val) {
      window._adminMedia.filters[key] = val;
      window._adminMedia.page = 1;
      _mediaLoadLibrary();
    };
    window._mediaApplySearch = function() {
      var el = document.getElementById('media-search');
      window._adminMedia.filters.search = el ? el.value : '';
      window._adminMedia.page = 1;
      _mediaLoadLibrary();
    };
    window._mediaSetView = function(view) {
      window._adminMedia.view = view;
      _mediaLoadLibrary();
    };

    function _mediaGridHtml(items) {
      if (!items.length) return '<div style="padding:48px;text-align:center;color:var(--muted)">No media matches the current filters.</div>';
      var st = window._adminMedia;
      var cards = items.map(function(m){
        var isImage = (m.asset_type === 'image') || (m.mime_type && m.mime_type.indexOf('image/') === 0);
        var isVideo = (m.asset_type === 'video') || (m.mime_type && m.mime_type.indexOf('video/') === 0);
        var isDoc   = (m.asset_type === 'document');
        var url = m.url || '';
        var thumbSrc = m.thumbnail_url || (isImage ? url : '');
        var mediaEl;
        if (isVideo && url) {
          // Prefer the generated ffmpeg thumbnail; fall back to <video> preload poster
          if (thumbSrc) {
            mediaEl = '<img src="' + bld_escH(thumbSrc) + '" alt="' + bld_escH(m.filename||'') + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">';
          } else {
            mediaEl = '<video src="' + bld_escH(url) + '" preload="metadata" muted style="width:100%;height:100%;object-fit:cover;display:block" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0"></video>';
          }
        } else if (isImage && url) {
          mediaEl = '<img src="' + bld_escH(url) + '" alt="' + bld_escH(m.filename||'') + '" loading="lazy" onerror="this.style.display=\'none\';this.parentElement.classList.add(\'mediaThumbMissing\')" style="width:100%;height:100%;object-fit:cover;display:block">';
        } else if (isDoc) {
          mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:32px">\u{1F4C4}</div>';
        } else {
          mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px">File</div>';
        }
        var badge = m.category ? '<span class="mediaBadge">' + bld_escH(m.category) + '</span>' : '';
        var platformBadge = m.is_platform_asset ? '<span class="mediaBadge mediaBadgePlatform" style="top:auto;bottom:8px;left:8px">Platform</span>' : '';
        var typeBadge = isVideo ?
          '<div style="position:absolute;top:8px;right:8px;font-size:11px;color:#fff;background:rgba(0,0,0,.72);padding:4px 8px;border-radius:4px;letter-spacing:.08em;font-weight:600">\u25B6 ' + (_mediaFormatDuration(m.duration_seconds) || 'VIDEO') + '</div>' : '';
        var dims = (m.width && m.height) ? m.width + ' \u00D7 ' + m.height : (isVideo?'Video':'\u2014');
        var selected = (st.selected||[]).indexOf(m.id) >= 0;
        var selCheckbox = st.selectMode ?
          '<div onclick="event.stopPropagation();_mediaToggleSelected(' + m.id + ')" style="position:absolute;top:8px;left:8px;width:24px;height:24px;border-radius:50%;background:' + (selected?'var(--p)':'rgba(0,0,0,.6)') + ';border:2px solid #fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:700">' + (selected?'\u2713':'') + '</div>' : '';
        var tagsJson = m.tags ? (typeof m.tags === 'string' ? m.tags : JSON.stringify(m.tags)) : '[]';
        var tagsAttr = bld_escH(tagsJson);
        var clickCardAttr = st.selectMode ? ' onclick="_mediaToggleSelected(' + m.id + ')" style="cursor:pointer' + (selected?';outline:3px solid var(--p);outline-offset:-3px':'') + '"' : '';
        return '<div class="mediaCard"' + clickCardAttr + '>' +
                 '<div class="mediaThumb">' + mediaEl + badge + platformBadge + typeBadge + selCheckbox + '</div>' +
                 '<div class="mediaMeta">' +
                   '<div style="font-weight:500;color:var(--text);font-size:13px" title="' + bld_escH(m.filename||'') + '">' + bld_escH(truncate(m.filename,26)) + '</div>' +
                   '<div class="mediaMetaRow"><span>' + (m.industry ? bld_escH(_mediaCap(m.industry)) : '\u2014') + '</span><span>' + dims + '</span></div>' +
                   '<div class="mediaMetaRow"><span>' + _mediaHumanBytes(m.size_bytes||0) + '</span><span>Used ' + (m.use_count||0) + '\u00D7</span></div>' +
                 '</div>' +
                 '<div class="mediaActions">' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();window.open(\'' + (m.url||'#') + '\',\'_blank\')">Preview</button>' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();_mediaEditTags(' + m.id + ',\'' + tagsAttr + '\')">Tags</button>' +
                   '<button class="mediaActionBtn" onclick="event.stopPropagation();_mediaShowUsage(' + m.id + ')">Usage</button>' +
                   '<button class="mediaActionBtn danger" onclick="event.stopPropagation();_mediaDelete(' + m.id + ')">Delete</button>' +
                 '</div>' +
               '</div>';
      }).join('');
      return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">' + cards + '</div>';
    }

    function _mediaListHtml(items) {
      if (!items.length) return '<div style="padding:48px;text-align:center;color:var(--muted)">No media matches the current filters.</div>';
      var rows = items.map(function(m){
        return '<tr>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);font-size:12px;font-family:monospace"><a href="' + (m.url||'#') + '" target="_blank" style="color:var(--p)">' + bld_escH(truncate(m.filename,40)) + '</a></td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.category||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.industry||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + bld_escH(m.mime_type||'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + ((m.width&&m.height)?(m.width+'\u00D7'+m.height):'\u2014') + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + _mediaHumanBytes(m.size_bytes||0) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border);text-align:right">' + (m.use_count||0) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + (m.is_platform_asset?'<span class="badge badge-green">platform</span>':bld_escH(m.source||'\u2014')) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' + ts(m.created_at) + '</td>' +
          '<td style="padding:10px 12px;border-top:1px solid var(--border)">' +
            '<button class="mediaActionBtn" onclick="_mediaCopyUrl(\'' + (m.url||'') + '\')">Copy</button> ' +
            '<button class="mediaActionBtn danger" onclick="_mediaDelete(' + m.id + ')">Delete</button>' +
          '</td></tr>';
      }).join('');
      return '<div style="background:var(--s1);border:1px solid var(--border);border-radius:8px;overflow-x:auto">' +
               '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
                 '<thead><tr style="background:var(--s2)">' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">File</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Category</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Industry</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Mime</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Dims</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Size</th>' +
                   '<th style="text-align:right;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Used</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Source</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Created</th>' +
                   '<th style="text-align:left;padding:10px 12px;font-size:11px;color:var(--muted);letter-spacing:.06em">Actions</th>' +
                 '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function _mediaPagerHtml(res) {
      var total = res.total||0, tp = res.total_pages||1, p = res.page||1;
      if (tp <= 1) return '';
      return '<div class="mediaPager">' +
               '<button ' + (p<=1?'disabled':'') + ' onclick="_mediaGoPage(' + (p-1) + ')">\u2190 Prev</button>' +
               '<span>Page ' + p + ' / ' + tp + ' \u00B7 ' + total + ' total</span>' +
               '<button ' + (p>=tp?'disabled':'') + ' onclick="_mediaGoPage(' + (p+1) + ')">Next \u2192</button>' +
             '</div>';
    }
    window._mediaGoPage = function(p) {
      window._adminMedia.page = p;
      _mediaLoadLibrary();
    };

    function _mediaHumanBytes(b) {
      if (!b || b<=0) return '0 B';
      var u = ['B','KB','MB','GB'], i = Math.floor(Math.log(b)/Math.log(1024));
      i = Math.min(i, u.length-1);
      return (b/Math.pow(1024,i)).toFixed(i>1?2:0) + ' ' + u[i];
    }

    window._mediaCopyUrl = function(url) {
      if (!url) return;
      var abs = url.indexOf('http')===0 ? url : (location.origin + url);
      if (navigator.clipboard) navigator.clipboard.writeText(abs).then(function(){ alert('URL copied: ' + abs); });
      else { window.prompt('Copy URL:', abs); }
    };

    window._mediaDelete = async function(id) {
      if (!confirm('Delete media #' + id + '? This also removes the file from disk.')) return;
      // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
      var r = await fetch('/api/admin/media/' + id, { method: 'DELETE', headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } });
      var d = await r.json().catch(function(){return {};});
      if (d && d.success) { _mediaLoadStats(); _mediaLoadLibrary(); }
      else alert('Delete failed: ' + ((d && d.error) || 'unknown'));
    };

    async function _mediaLoadArthurUploads() {
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading Arthur uploads...</div>';
      var res = await api('/media/arthur-uploads');
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load Arthur uploads</div>'; return; }
      var items = res.data || [];
      var note = '<div style="background:var(--s1);border:1px solid var(--border);border-left:3px solid var(--p);padding:14px 18px;margin-bottom:16px;font-size:13px;color:var(--muted);border-radius:6px">These files were uploaded by users during the Arthur website-build flow. They are stored on disk under <code>storage/app/public/arthur-uploads/{workspace_id}/</code> and are not tracked in the <code>media</code> table.</div>';
      if (!items.length) { body.innerHTML = note + '<div style="padding:48px;text-align:center;color:var(--muted)">No Arthur uploads found.</div>'; return; }
      var rows = items.map(function(f){
        var isImage = f.mime_type && f.mime_type.indexOf('image/') === 0;
        var isVideo = f.mime_type && f.mime_type.indexOf('video/') === 0;
        var mediaEl;
        if (isImage) mediaEl = '<img src="' + bld_escH(f.url) + '" alt="' + bld_escH(f.filename) + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">';
        else if (isVideo) mediaEl = '<video src="' + bld_escH(f.url) + '" preload="metadata" muted style="width:100%;height:100%;object-fit:cover;display:block"></video>';
        else mediaEl = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px">File</div>';
        return '<div class="mediaCard">' +
                 '<div class="mediaThumb">' + mediaEl + '</div>' +
                 '<div class="mediaMeta">' +
                   '<div style="font-weight:500;color:var(--text);font-size:13px" title="' + bld_escH(f.filename) + '">' + bld_escH(truncate(f.filename,28)) + '</div>' +
                   '<div class="mediaMetaRow"><span>Workspace ' + (f.workspace_id||'\u2014') + '</span><span>' + _mediaHumanBytes(f.size_bytes||0) + '</span></div>' +
                   '<div class="mediaMetaRow"><span>' + ts(f.modified_at) + '</span><span>' + bld_escH(f.mime_type||'') + '</span></div>' +
                 '</div>' +
                 '<div class="mediaActions">' +
                   '<button class="mediaActionBtn" onclick="window.open(\'' + f.url + '\',\'_blank\')">Preview</button>' +
                   '<button class="mediaActionBtn" onclick="_mediaCopyUrl(\'' + f.url + '\')">Copy URL</button>' +
                 '</div>' +
               '</div>';
      }).join('');
      body.innerHTML = note + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">' + rows + '</div>';
    }

    // ── Videos tab ──
    // Filters the media library to mime_type=video/*. The creative engine
    // dual-write in CreativeService::completeAsset() lands completed
    // LevelUpGrowth Video / Runway generations here automatically. Still useful when
    // empty — renders an explainer so admins know where new videos will
    // appear.
    async function _mediaLoadVideos() {
      var body = document.getElementById('media-body');
      if (!body) return;
      body.innerHTML = '<div class="loading">Loading videos...</div>';
      var res = await api('/media?type=video&per_page=60');
      if (!res || !res.success) { body.innerHTML = '<div class="loading">Failed to load videos</div>'; return; }
      var items = res.data || [];
      var note = '<div style="background:var(--s1);border:1px solid var(--border);border-left:3px solid var(--p);padding:14px 18px;margin-bottom:16px;font-size:13px;color:var(--muted);border-radius:6px">Videos from the Creative engine (LevelUpGrowth Video, Runway, and future providers) appear here once generation completes. Every completed asset is dual-written into the <code>media</code> table, so new videos surface automatically \u2014 no backfill required.</div>';
      if (!items.length) {
        body.innerHTML = note +
          '<div style="padding:56px 24px;text-align:center;color:var(--muted);background:var(--s1);border:1px dashed var(--border);border-radius:10px">' +
            '<div style="font-size:40px;margin-bottom:12px;opacity:.6">\u{1F3AC}</div>' +
            '<div style="font-weight:600;color:var(--text);font-size:15px;margin-bottom:8px">No videos yet</div>' +
            '<div style="max-width:500px;margin:0 auto;line-height:1.65">No completed video generations in the <code>media</code> table yet. Videos will appear here after the Creative engine produces them via LevelUpGrowth Video or Runway. The dual-write hook is live \u2014 the next successful generation will land here automatically.</div>' +
          '</div>';
        return;
      }
      body.innerHTML = note + _mediaGridHtml(items);
    }

    // ── Upload modal ──
    window._mediaOpenUpload = function() {
      var root = document.getElementById('media-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="mu-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'mu-modal\')_mediaCloseUpload()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:480px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center"><div style="font-weight:600;font-size:15px">Upload Media</div><button type="button" onclick="_mediaCloseUpload()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px">\u2715</button></div>' +
            '<form id="mu-form" onsubmit="_mediaSubmitUpload(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Files (images, video, documents — up to 100 MB each, drop multiple)<input name="files[]" type="file" accept="image/*,video/*,.pdf,.doc,.docx" multiple required style="display:block;width:100%;margin-top:6px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Category<select name="category" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"><option value="">\u2014 Select \u2014</option><option value="hero">Hero</option><option value="gallery">Gallery</option><option value="website">Website</option><option value="blog">Blog</option><option value="logo">Logo</option><option value="team">Team</option><option value="other">Other</option></select></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Industry (optional)<input name="industry" type="text" placeholder="e.g. restaurant, technology, marketing_agency" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Description (optional)<input name="description" type="text" placeholder="Short description" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Tags (comma-separated, optional)<input name="tags" type="text" placeholder="luxury, warm, editorial" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px"></label>' +
              '<div id="mu-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end"><button type="button" onclick="_mediaCloseUpload()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button><button type="submit" style="background:var(--p);color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Upload</button></div>' +
            '</form>' +
          '</div>' +
        '</div>';
    };
    window._mediaCloseUpload = function() {
      var root = document.getElementById('media-modal-root');
      if (root) root.innerHTML = '';
    };
    window._mediaSubmitUpload = async function(e) {
      e.preventDefault();
      var form = e.target;
      var filesInput = form.querySelector('input[type=file]');
      var files = filesInput ? filesInput.files : null;
      var err = document.getElementById('mu-err');
      if (err) err.style.display = 'none';
      if (!files || !files.length) { if (err) { err.textContent = 'Pick at least one file.'; err.style.display = 'block'; } return; }

      // Choose single vs bulk endpoint based on file count.
      var multi = files.length > 1;
      var fd = new FormData();
      if (multi) {
        for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
      } else {
        fd.append('file', files[0]);
      }
      ['category','industry','description','tags'].forEach(function(k){
        var el = form.querySelector('[name="'+k+'"]');
        if (el && el.value) fd.append(k, el.value);
      });

      try {
        // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
        var endpoint = multi ? '/api/admin/media/bulk-upload' : '/api/admin/media/upload';
        var submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = multi ? ('Uploading ' + files.length + '...') : 'Uploading...'; }
        var r = await fetch(endpoint, { method:'POST', headers:{'Authorization':'Bearer '+token,'Accept':'application/json'}, body: fd });
        var d = await r.json().catch(function(){return {};});
        if (d && d.success) {
          _mediaCloseUpload();
          _mediaLoadStats();
          _mediaLoadLibrary();
          if (multi && d.failed_count > 0) {
            var msg = 'Uploaded ' + d.uploaded_count + ' OK, ' + d.failed_count + ' failed:\n';
            (d.failed||[]).forEach(function(f){ msg += '\u2022 ' + f.filename + ': ' + f.error + '\n'; });
            alert(msg);
          }
        } else {
          if (err) { err.textContent = (d && d.error) || ('Upload failed (' + r.status + ')'); err.style.display = 'block'; }
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Upload'; }
        }
      } catch (ex) {
        if (err) { err.textContent = 'Upload failed: ' + (ex && ex.message || ex); err.style.display = 'block'; }
      }
    };

    // ── Generate modal (T3.8) — LevelUpGrowth Image 3 admin-side image generation ──
    // Shape mirrors _mediaOpenUpload but submits JSON to /api/admin/media/generate.
    // T3.1D backend already inlines thumbnail generation, so the new image
    // appears in the grid with thumbnail on the post-success refresh.
    var _MEDIA_COST_MATRIX = {
      '1024x1024|standard': 0.04,
      '1024x1024|hd':       0.08,
      '1792x1024|standard': 0.08,
      '1792x1024|hd':       0.12,
      '1024x1792|standard': 0.08,
      '1024x1792|hd':       0.12
    };

    window._mediaGenSlug = function(s) {
      return String(s||'').toLowerCase()
        .replace(/[^a-z0-9\s-]/g,'').trim()
        .split(/\s+/).slice(0,5).join('-')
        .replace(/-+/g,'-').replace(/^-|-$/g,'') || 'image';
    };

    window._mediaGenAutoFilename = function(prompt) {
      var slug = _mediaGenSlug(prompt);
      var ts = Math.floor(Date.now()/1000);
      return slug + '_' + ts;
    };

    window._mediaGenUpdateCost = function() {
      var sizeEl = document.querySelector('#mg-form [name=size]');
      var qualEl = document.querySelector('#mg-form [name=quality]');
      var costEl = document.getElementById('mg-cost');
      if (!sizeEl || !qualEl || !costEl) return;
      var key = sizeEl.value + '|' + qualEl.value;
      var cost = _MEDIA_COST_MATRIX[key] || 0;
      costEl.textContent = 'Estimated cost: $' + cost.toFixed(2);
    };

    window._mediaGenUpdatePromptUI = function() {
      var ta = document.querySelector('#mg-form [name=prompt]');
      var counter = document.getElementById('mg-counter');
      var fnameEl = document.querySelector('#mg-form [name=filename]');
      if (!ta || !counter) return;
      var n = (ta.value || '').length;
      counter.textContent = n + '/1000';
      counter.style.color = n > 900 ? '#F87171' : 'var(--muted)';
      if (fnameEl && !fnameEl.value) {
        fnameEl.placeholder = ta.value ? _mediaGenAutoFilename(ta.value) : 'auto-generated from prompt';
      }
    };

    window._mediaOpenGenerate = function() {
      var root = document.getElementById('media-modal-root');
      if (!root) return;
      root.innerHTML =
        '<div id="mg-modal" data-busy="0" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target.id===\'mg-modal\' && event.currentTarget.dataset.busy!==\'1\')_mediaCloseGenerate()">' +
          '<div style="background:var(--s1);border:1px solid var(--border);border-radius:12px;width:520px;max-width:100%;max-height:90vh;overflow-y:auto">' +
            '<div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">' +
              '<div style="font-weight:600;font-size:15px">✦ Generate Image (LevelUpGrowth Image 3)</div>' +
              '<button type="button" onclick="_mediaCloseGenerate()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px">✕</button>' +
            '</div>' +
            '<form id="mg-form" onsubmit="_mediaSubmitGenerate(event)" style="padding:22px;display:flex;flex-direction:column;gap:14px">' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500;display:flex;justify-content:space-between;align-items:center">' +
                '<span>Prompt</span><span id="mg-counter" style="font-size:11px">0/1000</span>' +
              '</label>' +
              '<textarea name="prompt" required maxlength="1000" rows="4" oninput="_mediaGenUpdatePromptUI()" placeholder="Describe the image. Be specific: subject, style, lighting, no text, no people if you want stock-style." style="width:100%;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;min-height:90px"></textarea>' +
              '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                '<label style="font-size:12px;color:var(--muted);font-weight:500">Size' +
                  '<select name="size" onchange="_mediaGenUpdateCost()" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                    '<option value="1024x1024">1024×1024 (square)</option>' +
                    '<option value="1792x1024">1792×1024 (landscape)</option>' +
                    '<option value="1024x1792">1024×1792 (portrait)</option>' +
                  '</select>' +
                '</label>' +
                '<label style="font-size:12px;color:var(--muted);font-weight:500">Quality' +
                  '<select name="quality" onchange="_mediaGenUpdateCost()" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                    '<option value="standard">Standard</option>' +
                    '<option value="hd">HD</option>' +
                  '</select>' +
                '</label>' +
              '</div>' +
              '<div id="mg-cost" style="font-size:13px;color:var(--ac);font-weight:600">Estimated cost: $0.04</div>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Category' +
                '<select name="category" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
                  '<option value="">— Select —</option>' +
                  '<option value="hero">Hero</option>' +
                  '<option value="gallery">Gallery</option>' +
                  '<option value="website">Website</option>' +
                  '<option value="blog">Blog</option>' +
                  '<option value="logo">Logo</option>' +
                  '<option value="team">Team</option>' +
                  '<option value="other">Other</option>' +
                '</select>' +
              '</label>' +
              '<label style="font-size:12px;color:var(--muted);font-weight:500">Filename (optional)' +
                '<input name="filename" type="text" placeholder="auto-generated from prompt" style="display:block;width:100%;margin-top:6px;padding:10px;background:var(--s2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px">' +
              '</label>' +
              '<div id="mg-err" style="color:#F87171;font-size:12px;display:none"></div>' +
              '<div style="display:flex;gap:10px;justify-content:flex-end">' +
                '<button type="button" id="mg-cancel-btn" onclick="_mediaCloseGenerate()" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 18px;border-radius:6px;cursor:pointer;font-size:13px">Cancel</button>' +
                '<button type="submit" id="mg-submit-btn" style="background:var(--ac);color:#0F1117;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px">Generate</button>' +
              '</div>' +
            '</form>' +
          '</div>' +
        '</div>';
      _mediaGenUpdateCost();
    };

    window._mediaCloseGenerate = function() {
      var modal = document.getElementById('mg-modal');
      if (modal && modal.dataset.busy === '1') return;
      var root = document.getElementById('media-modal-root');
      if (root) root.innerHTML = '';
    };

    window._mediaShowToast = function(msg, ok) {
      var toast = document.createElement('div');
      toast.textContent = msg;
      toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:' +
        (ok ? 'var(--ac)' : '#F87171') + ';color:' + (ok ? '#0F1117' : '#fff') +
        ';padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.4);transition:opacity .3s';
      document.body.appendChild(toast);
      setTimeout(function(){ toast.style.opacity = '0'; }, 2700);
      setTimeout(function(){ toast.remove(); }, 3000);
    };

    window._mediaSubmitGenerate = async function(e) {
      e.preventDefault();
      var form = e.target;
      var modal = document.getElementById('mg-modal');
      var err = document.getElementById('mg-err');
      var submitBtn = document.getElementById('mg-submit-btn');
      var cancelBtn = document.getElementById('mg-cancel-btn');
      if (err) err.style.display = 'none';

      var prompt   = (form.querySelector('[name=prompt]')   || {}).value || '';
      var size     = (form.querySelector('[name=size]')     || {}).value || '1024x1024';
      var quality  = (form.querySelector('[name=quality]')  || {}).value || 'standard';
      var category = (form.querySelector('[name=category]') || {}).value || '';
      var filename = (form.querySelector('[name=filename]') || {}).value || '';

      prompt = prompt.trim();
      if (!prompt) {
        if (err) { err.textContent = 'Prompt is required.'; err.style.display = 'block'; }
        return;
      }
      if (!filename) filename = _mediaGenAutoFilename(prompt);
      var costKey = size + '|' + quality;
      var cost    = _MEDIA_COST_MATRIX[costKey] || 0;

      // Lock UI during generation
      if (modal) modal.dataset.busy = '1';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⟳ Generating… (~10s)'; }
      if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.style.opacity = '0.5'; cancelBtn.style.cursor = 'not-allowed'; }

      try {
        // 2026-07-29: was reading 'admin_token'/'lu_token', neither of which exists —
      // the admin key is 'lu_admin_token'. api() already holds the right one.
        var r = await fetch('/api/admin/media/generate', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + token
          },
          body: JSON.stringify({
            prompt: prompt,
            size: size,
            quality: quality,
            category: category,
            filename: filename
          })
        });
        var d = await r.json().catch(function(){ return {}; });
        if (d && d.success) {
          if (modal) modal.dataset.busy = '0';
          _mediaCloseGenerate();
          _mediaShowToast('✓ Image generated — $' + cost.toFixed(2) + ' charged', true);
          _mediaLoadStats();
          _mediaLoadLibrary();
        } else {
          if (modal) modal.dataset.busy = '0';
          if (err) { err.textContent = (d && d.error) || ('Generation failed (HTTP ' + r.status + ')'); err.style.display = 'block'; }
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Generate'; }
          if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; cancelBtn.style.cursor = 'pointer'; }
        }
      } catch (ex) {
        if (modal) modal.dataset.busy = '0';
        if (err) { err.textContent = 'Generation failed: ' + (ex && ex.message || ex); err.style.display = 'block'; }
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Generate'; }
        if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; cancelBtn.style.cursor = 'pointer'; }
      }
    };
