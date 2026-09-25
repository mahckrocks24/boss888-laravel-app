/**
 * LevelUpGrowth — Domains.
 *
 * The customer-facing domain experience: search, cart, checkout, dashboard,
 * domain detail and order timeline.
 *
 * This module owns NO business logic. Every price, availability answer, status
 * and timeline step comes from the API. Nothing is computed here that a server
 * already decided — in particular the browser never sends a price, never sends
 * a workspace id, and never decides whether a domain is available.
 *
 * BRANDING: the orchestration engine and the upstream registrar are never
 * named. The customer buys from LevelUpGrowth.
 *
 * DESIGN: this module renders through helpers handed to it by the
 * infrastructure module (pageShell, card, button, statusPill …), so the page
 * frame, spacing and typography are identical to every other surface rather
 * than a lookalike maintained twice.
 */
(function () {
  'use strict';

  var ctx = null;              // design + transport helpers, injected on mount
  var _view = { name: 'list', domainId: null, orderId: null };
  var _domains = [];
  var _loading = false;
  var _search = { term: '', years: 3, result: null, busy: false, error: null };   /* DOMAIN-TERMS-1: the bundle is the default term */
  var _cart = [];
  var _checkingOut = false;
  // DOMAIN-OFFER-1 (RFC-0015): the website a search/cart is for ({id, name}); null = none chosen.
  var _for = null;
  var _table = { q: '', status: 'all', sort: 'domain', dir: 'asc', page: 1, per: 10 };

  var CART_KEY = 'lu.domains.cart';

  /* ------------------------------------------------------------- helpers -- */

  function esc(s) { return ctx.esc(s); }
  function h(id) { return document.getElementById(id); }

  function on(id, evt, fn) {
    var el = h(id);
    if (el) { el.addEventListener(evt, fn); }
  }

  function toast(msg, kind) {
    if (typeof window.showToast === 'function') { window.showToast(msg, kind || 'info'); }
  }

  function money(v) { return v == null ? '—' : String(v); }

  function fmtDate(d) {
    if (!d) { return '—'; }
    try {
      return new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return String(d); }
  }

  /* Cart lives per browser, keyed so one workspace's cart never shows in
   * another. The server re-prices everything at order time regardless, so a
   * stale cart cannot produce a stale price. */
  function cartKey() {
    var ws = (window.LU_WORKSPACE_ID || window.currentWorkspaceId || 'ws');
    return CART_KEY + '.' + ws;
  }

  function loadCart() {
    try {
      var raw = localStorage.getItem(cartKey());
      _cart = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(_cart)) { _cart = []; }
    } catch (e) { _cart = []; }
  }

  function saveCart() {
    try { localStorage.setItem(cartKey(), JSON.stringify(_cart)); } catch (e) { /* private mode */ }
  }

  function cartCount() { return _cart.length; }

  function inCart(domain) {
    return _cart.some(function (c) { return c.domain === domain; });
  }

  /* ---------------------------------------------------------------- views -- */

  function render() {
    if (_view.name === 'detail') { return renderDetail(); }
    if (_view.name === 'cart') { return renderCart(); }
    return renderList();
  }

  function shell(opts) {
    return ctx.pageShell(opts);
  }

  function cartButton() {
    var n = cartCount();
    return '<button id="lud-cart-btn" style="display:inline-flex;align-items:center;gap:7px;min-height:var(--touch-min,40px);' +
      'padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);' +
      'background:' + (n ? 'var(--p)' : 'transparent') + ';color:' + (n ? '#fff' : 'var(--t2)') + ';' +
      'border:1px solid ' + (n ? 'var(--p)' : 'var(--bd2)') + ';">' +
      'Cart' + (n ? ' (' + n + ')' : '') + '</button>';
  }

  /* ============================================================ SEARCH ==== */

  function searchPanel() {
    var r = _search.result;
    var body = '';

    if (_search.busy) {
      body = '<div role="status" aria-live="polite" style="padding:var(--sp-5);font:400 13px var(--fb);color:var(--t3);">' +
        'Checking availability…</div>';
    } else if (_search.error) {
      body = '<div role="alert" style="padding:var(--sp-5);border-left:3px solid var(--rd);background:var(--s1);' +
        'border-radius:var(--rg);font:400 13px var(--fb);color:var(--t2);">' + esc(_search.error) + '</div>';
    } else if (r) {
      body = searchResult(r);
    }

    var forLine = _for
      ? '<div role="status" style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-3);flex-wrap:wrap;' +
          'background:var(--s2);border:1px solid var(--bd);border-left:3px solid var(--ac);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);margin-bottom:var(--sp-4);font:400 13px var(--fb);color:var(--t2);">' +
          '<span>For <strong style="color:var(--t1);">' + esc(_for.name || ('website #' + _for.id)) + '</strong> — after payment we register it, set it up and connect it automatically.</span>' +
          '<button id="lud-for-clear" style="min-height:30px;padding:0 var(--sp-3);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Not for this website</button>' +
        '</div>'
      : '';

    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);margin-bottom:var(--sp-6);">' +
      forLine +
      '<label for="lud-q" style="display:block;font:600 12px var(--fb);color:var(--t2);margin-bottom:6px;">Find a domain</label>' +
      '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;">' +
        '<input id="lud-q" type="text" inputmode="url" autocomplete="off" spellcheck="false" ' +
          'placeholder="yourbrand.com" value="' + esc(_search.term) + '" ' +
          'style="flex:1 1 260px;min-width:0;min-height:42px;padding:0 var(--sp-4);border-radius:var(--r);' +
          'border:1px solid var(--bd2);background:var(--s0,var(--s1));color:var(--t1);font:400 14px var(--fb);">' +
        '<button id="lud-go" style="min-height:42px;padding:0 var(--sp-5);border-radius:var(--r);cursor:pointer;' +
          'font:600 13px var(--fb);background:var(--p);color:#fff;border:1px solid var(--p);">Search</button>' +
      '</div>' +
      '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:7px;">' +
        'Enter the full name including the ending, for example yourbrand.com</div>' +
      (body ? '<div style="margin-top:var(--sp-5);">' + body + '</div>' : '') +
    '</div>';
  }

  function searchResult(r) {
    // Unavailable, or the search itself could not be completed.
    if (r.blocked) {
      return '<div role="alert" style="padding:var(--sp-5);border-left:3px solid var(--am);background:var(--s1);' +
        'border-radius:var(--rg);font:400 13px var(--fb);color:var(--t2);">' + esc(r.blocked_reason || 'Search unavailable.') + '</div>';
    }

    if (r.available !== true) {
      return '<div style="padding:var(--sp-5);border:1px solid var(--bd);border-radius:var(--rg);">' +
        '<div style="font:600 15px var(--fh);color:var(--t1);">' + esc(r.domain || _search.term) + '</div>' +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:4px;">' +
          esc(r.reason || 'This domain is already registered.') + '</div>' +
        suggestionBlock() + '</div>';
    }

    /* DOMAIN-TERMS-1: two terms — one year at the list price, or the bundle with its $1 first year. */
    var terms = r.terms || [];
    if (terms.length && !terms.some(function (t) { return t.years === _search.years; })) { _search.years = terms[terms.length - 1].years; }
    var termCards = terms.map(function (t) {
      var on = t.years === _search.years;
      return '<label class="lud-term" style="flex:1 1 200px;display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--rg);cursor:pointer;' +
        'border:1px solid ' + (on ? 'var(--p)' : 'var(--bd2)') + ';background:' + (on ? 'var(--s2)' : 'transparent') + ';">' +
        '<input type="radio" name="lud-term" value="' + t.years + '"' + (on ? ' checked' : '') + ' style="margin-top:3px;width:16px;height:16px;cursor:pointer;">' +
        '<span style="min-width:0;">' +
          '<span style="display:block;font:600 14px var(--fh);color:var(--t1);">' + esc(t.label) + (t.headline ? ' · <span style="color:var(--ac);">' + esc(t.headline) + '</span>' : '') + '</span>' +
          '<span style="display:block;font:400 12px var(--fb);color:var(--t2);margin-top:3px;line-height:1.5;">' +
            (t.years > 1 ? esc(t.per_year[0]) + ' now for year 1, then ' + esc(t.per_year[1]) + ' a year · ' : '') + '<strong style="color:var(--t1);">' + esc(t.total) + ' total</strong></span>' +
        '</span></label>';
    }).join('');

    var already = inCart(r.domain);

    return '<div style="border:1px solid var(--ac);border-radius:var(--rg);padding:var(--sp-5);background:var(--s1);">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;">' +
        '<div style="min-width:0;">' +
          '<div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;">' +
            '<span style="font:600 17px var(--fh);color:var(--t1);word-break:break-all;">' + esc(r.domain) + '</span>' +
            ctx.statusPill('Available', 'var(--ac)') +
            (r.premium ? ctx.statusPill('Premium', 'var(--am)') : '') +
          '</div>' +
          '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:7px;">' +
            'Renews at ' + esc(money(r.renewal && r.renewal.retail)) + ' per year' + (r.discount_percent ? ' · ' + esc(r.discount_percent) + '% off today' : '') +
          '</div>' +
          (r.registration_period ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;">' +
            'Registered until ' + esc(fmtDate(r.registration_period.expires_on)) + '</div>' : '') +
        '</div>' +
        '<div style="text-align:right;flex:none;">' +
          '<div style="font:600 24px var(--fh);color:var(--t1);font-variant-numeric:tabular-nums;">' + esc((terms.length > 1 && terms[1].per_year) ? terms[1].per_year[0] : money(r.retail)) + '</div>' +
          '<div style="font:400 11px var(--fb);color:var(--t3);">' + (terms.length > 1 ? 'first year with the ' + terms[1].years + '-year plan' : 'per year') + ', excl. tax</div>' +
        '</div>' +
      '</div>' +
      (termCards ? '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-5);">' + termCards + '</div>' : '') +
      '<div style="display:flex;gap:var(--sp-3);align-items:center;margin-top:var(--sp-4);flex-wrap:wrap;">' +
        '<button id="lud-add" ' + (already ? 'disabled' : '') + ' style="min-height:38px;padding:0 var(--sp-5);border-radius:var(--r);' +
          'cursor:' + (already ? 'default' : 'pointer') + ';font:600 13px var(--fb);' +
          'background:' + (already ? 'transparent' : 'var(--p)') + ';color:' + (already ? 'var(--t3)' : '#fff') + ';' +
          'border:1px solid ' + (already ? 'var(--bd2)' : 'var(--p)') + ';">' +
          (already ? 'In cart' : 'Add to cart') + '</button>' +
      '</div>' +
      (r.premium ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:var(--sp-4);line-height:1.5;">' +
        'Premium names are priced individually and are non-refundable once registered.</div>' : '') +
    '</div>';
  }

  /* Suggestions are generated from the customer's own term. They are clearly
   * labelled as ideas to check — never as confirmed availability, because we
   * have not asked the registrar about them. */
  function suggestionBlock() {
    var base = String(_search.term || '').toLowerCase().replace(/^https?:\/\//, '').split('/')[0];
    var stem = base.split('.')[0];

    if (!stem) { return ''; }

    var ideas = [stem + '.net', stem + '.co', stem + '.io', 'get' + stem + '.com', stem + 'hq.com']
      .filter(function (d) { return d !== base; })
      .slice(0, 5);

    return '<div style="margin-top:var(--sp-5);">' +
      '<div style="font:600 12px var(--fb);color:var(--t2);margin-bottom:7px;">Other ideas to check</div>' +
      '<div style="display:flex;gap:7px;flex-wrap:wrap;">' +
        ideas.map(function (d) {
          return '<button class="lud-sugg" data-d="' + esc(d) + '" style="min-height:34px;padding:0 var(--sp-4);' +
            'border-radius:var(--r);cursor:pointer;font:400 12px var(--fb);background:transparent;' +
            'color:var(--t2);border:1px dashed var(--bd2);">' + esc(d) + '</button>';
        }).join('') +
      '</div>' +
      '<div style="font:400 11px var(--fb);color:var(--t3);margin-top:7px;">' +
        'We have not checked these yet — select one to search.</div>' +
    '</div>';
  }

  function doSearch(term) {
    var t = String(term || '').trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0];

    if (!t) { _search.error = 'Enter a domain name to search.'; render(); return; }

    if (!/^[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)+$/.test(t)) {
      _search.error = 'That does not look like a domain. Try something like yourbrand.com';
      _search.result = null;
      render();
      return;
    }

    _search.term = t;
    _search.busy = true;
    _search.error = null;
    _search.result = null;
    render();

    ctx.req('GET', 'domains/search?domain=' + encodeURIComponent(t) + '&years=' + _search.years)
      .then(function (r) {
        _search.busy = false;
        if (!r.ok) {
          _search.error = (r.json && r.json.error) || 'We could not complete that search. Please try again.';
        } else {
          _search.result = r.json || null;
        }
        render();
      })
      .catch(function () {
        _search.busy = false;
        _search.error = 'We could not reach the domain service. Please try again shortly.';
        render();
      });
  }

  /* ============================================================== LIST ==== */

  function visibleRows() {
    var rows = _domains.slice();
    var q = _table.q.trim().toLowerCase();

    if (q) { rows = rows.filter(function (d) { return String(d.domain).toLowerCase().indexOf(q) !== -1; }); }

    if (_table.status !== 'all') {
      rows = rows.filter(function (d) {
        if (_table.status === 'expiring') { return d.expiring_soon === true; }
        return d.status === _table.status;
      });
    }

    var dir = _table.dir === 'asc' ? 1 : -1;
    rows.sort(function (a, b) {
      var x, y;
      if (_table.sort === 'expires') { x = a.expires_on || ''; y = b.expires_on || ''; }
      else if (_table.sort === 'status') { x = a.status || ''; y = b.status || ''; }
      else { x = a.domain || ''; y = b.domain || ''; }
      return x < y ? -dir : x > y ? dir : 0;
    });

    return rows;
  }

  function renderList() {
    if (_loading) {
      ctx.paintBody(shell({
        breadcrumb: [{ label: 'Hosting' }, { label: 'Domains' }],
        title: 'Domains',
        desc: 'Search for a new domain, or manage the domains you already own.',
        body: ctx.loadingState('Loading your domains…')
      }));
      return;
    }

    var rows = visibleRows();
    var pages = Math.max(1, Math.ceil(rows.length / _table.per));
    if (_table.page > pages) { _table.page = pages; }
    var slice = rows.slice((_table.page - 1) * _table.per, _table.page * _table.per);

    var active = _domains.filter(function (d) { return d.status === 'active'; }).length;
    var expiring = _domains.filter(function (d) { return d.expiring_soon; }).length;

    var metrics = ctx.metricStrip([
      { label: 'Domains', value: String(_domains.length) },
      { label: 'Active', value: String(active) },
      { label: 'Expiring in 30 days', value: String(expiring), tone: expiring ? 'var(--am)' : 'var(--t1)' }
    ]);

    var body = searchPanel() + (_domains.length ? metrics + tableBlock(slice, rows.length, pages) : emptyState());

    ctx.paintBody(shell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Domains' }],
      title: 'Domains',
      desc: 'Search for a new domain, or manage the domains you already own.',
      actions: cartButton(),
      body: body
    }));

    bindList();
  }

  function emptyState() {
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);' +
      'padding:var(--sp-8);text-align:center;">' +
      '<div style="font:600 16px var(--fh);color:var(--t1);">You don\'t own any domains yet</div>' +
      '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:7px;max-width:52ch;margin-left:auto;margin-right:auto;line-height:1.6;">' +
      'Search above to find a domain for your business. Once you buy one, it appears here with its renewal date, ' +
      'name servers and full history.</div></div>';
  }

  function sortHead(key, label) {
    var on = _table.sort === key;
    var arrow = on ? (_table.dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th scope="col" style="text-align:left;padding:0 var(--sp-4) 9px;font:600 11px var(--fb);' +
      'color:var(--t3);letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;">' +
      '<button class="lud-sort" data-k="' + key + '" aria-label="Sort by ' + esc(label) + '" style="background:none;border:none;cursor:pointer;' +
      'font:inherit;color:' + (on ? 'var(--t1)' : 'var(--t3)') + ';padding:0;">' + esc(label) + arrow + '</button></th>';
  }

  function tableBlock(slice, total, pages) {
    var head =
      '<div style="display:flex;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap;margin-bottom:var(--sp-4);">' +
        '<input id="lud-filter" type="search" placeholder="Filter domains" value="' + esc(_table.q) + '" ' +
          'aria-label="Filter domains" style="flex:1 1 220px;min-height:38px;padding:0 var(--sp-4);border-radius:var(--r);' +
          'border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:400 13px var(--fb);">' +
        '<select id="lud-status" aria-label="Filter by status" style="min-height:38px;padding:0 var(--sp-3);border-radius:var(--r);' +
          'border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:400 13px var(--fb);">' +
          ['all', 'active', 'expiring', 'expired'].map(function (s) {
            var l = s === 'all' ? 'All statuses' : s === 'expiring' ? 'Expiring soon' : s.charAt(0).toUpperCase() + s.slice(1);
            return '<option value="' + s + '"' + (_table.status === s ? ' selected' : '') + '>' + l + '</option>';
          }).join('') +
        '</select>' +
      '</div>';

    if (!slice.length) {
      return head + '<div style="padding:var(--sp-6);text-align:center;font:400 13px var(--fb);color:var(--t3);' +
        'border:1px solid var(--bd);border-radius:var(--rg);">No domains match that filter.</div>';
    }

    var table =
      '<div style="overflow-x:auto;border:1px solid var(--bd);border-radius:var(--rg);background:var(--s1);">' +
      '<table style="width:100%;border-collapse:collapse;min-width:660px;">' +
      '<thead><tr style="border-bottom:1px solid var(--bd);">' +
        sortHead('domain', 'Domain') +
        sortHead('status', 'Status') +
        '<th scope="col" style="text-align:left;padding:0 var(--sp-4) 9px;font:600 11px var(--fb);color:var(--t3);letter-spacing:.04em;text-transform:uppercase;">Registered</th>' +
        sortHead('expires', 'Expires') +
        '<th scope="col" style="text-align:left;padding:0 var(--sp-4) 9px;font:600 11px var(--fb);color:var(--t3);letter-spacing:.04em;text-transform:uppercase;">Auto-renew</th>' +
        '<th scope="col" style="text-align:left;padding:0 var(--sp-4) 9px;font:600 11px var(--fb);color:var(--t3);letter-spacing:.04em;text-transform:uppercase;">Website</th>' +
        '<th scope="col" style="padding:0 var(--sp-4) 9px;"><span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Actions</span></th>' +
      '</tr></thead><tbody>' +
      slice.map(function (d) {
        var tone = d.status === 'active' ? (d.expiring_soon ? 'var(--am)' : 'var(--ac)') : 'var(--t3)';
        return '<tr style="border-bottom:1px solid var(--bd);">' +
          '<td style="padding:var(--sp-4);font:600 14px var(--fb);color:var(--t1);word-break:break-all;">' + esc(d.domain) + '</td>' +
          '<td style="padding:var(--sp-4);">' + ctx.statusPill(d.status_label || d.status, tone) + '</td>' +
          '<td style="padding:var(--sp-4);font:400 13px var(--fb);color:var(--t2);white-space:nowrap;">' + esc(fmtDate(d.registered_on)) + '</td>' +
          '<td style="padding:var(--sp-4);font:400 13px var(--fb);color:var(--t2);white-space:nowrap;">' + esc(fmtDate(d.expires_on)) +
            (d.days_until_expiry != null && d.expiring_soon ? '<div style="font:400 11px var(--fb);color:var(--am);">in ' + esc(d.days_until_expiry) + ' days</div>' : '') + '</td>' +
          '<td style="padding:var(--sp-4);font:400 13px var(--fb);color:var(--t2);">' + (d.auto_renew ? 'On' : 'Off') + '</td>' +
          '<td style="padding:var(--sp-4);font:400 13px var(--fb);color:var(--t2);">' + websiteCell(d) + '</td>' +
          '<td style="padding:var(--sp-4);text-align:right;white-space:nowrap;">' +
            '<button class="lud-open" data-id="' + esc(d.id) + '" style="min-height:34px;padding:0 var(--sp-4);border-radius:var(--r);' +
            'cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t1);border:1px solid var(--bd2);">Manage</button>' +
          '</td></tr>';
      }).join('') +
      '</tbody></table></div>';

    var pager = pages > 1
      ? '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-3);margin-top:var(--sp-4);flex-wrap:wrap;">' +
          '<div style="font:400 12px var(--fb);color:var(--t3);">Page ' + _table.page + ' of ' + pages + ' · ' + total + ' domains</div>' +
          '<div style="display:flex;gap:7px;">' +
            '<button id="lud-prev" ' + (_table.page <= 1 ? 'disabled' : '') + ' style="min-height:34px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Previous</button>' +
            '<button id="lud-next" ' + (_table.page >= pages ? 'disabled' : '') + ' style="min-height:34px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Next</button>' +
          '</div></div>'
      : '';

    return head + table + pager;
  }

  function bindList() {
    on('lud-go', 'click', function () { doSearch(h('lud-q') ? h('lud-q').value : ''); });
    on('lud-q', 'keydown', function (e) { if (e.key === 'Enter') { doSearch(e.target.value); } });
    Array.prototype.forEach.call(document.querySelectorAll('input[name="lud-term"]'), function (i) {
      i.addEventListener('change', function () { _search.years = parseInt(i.value, 10) || 1; render(); });
    });
    on('lud-add', 'click', function () { addToCart(); });
    on('lud-cart-btn', 'click', function () { _view = { name: 'cart' }; render(); });
    on('lud-for-clear', 'click', function () { _for = null; render(); });

    Array.prototype.forEach.call(document.querySelectorAll('.lud-sugg'), function (b) {
      b.addEventListener('click', function () { doSearch(b.getAttribute('data-d')); });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.lud-open'), function (b) {
      b.addEventListener('click', function () {
        _view = { name: 'detail', domainId: parseInt(b.getAttribute('data-id'), 10) };
        render();
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.lud-sort'), function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-k');
        if (_table.sort === k) { _table.dir = _table.dir === 'asc' ? 'desc' : 'asc'; }
        else { _table.sort = k; _table.dir = 'asc'; }
        render();
      });
    });

    on('lud-filter', 'input', function (e) { _table.q = e.target.value; _table.page = 1; render(); h('lud-filter') && h('lud-filter').focus(); });
    on('lud-status', 'change', function (e) { _table.status = e.target.value; _table.page = 1; render(); });
    on('lud-prev', 'click', function () { if (_table.page > 1) { _table.page--; render(); } });
    on('lud-next', 'click', function () { _table.page++; render(); });
  }

  function addToCart() {
    var r = _search.result;
    if (!r || r.available !== true) { return; }

    if (inCart(r.domain)) { toast('That domain is already in your cart.', 'info'); return; }

    var chosen = (r.terms || []).filter(function (t) { return t.years === _search.years; })[0] || null;
    _cart.push({
      domain: r.domain,
      years: _search.years,
      // Display only. The server re-prices at order time and its figure wins.
      price: chosen ? chosen.total : r.retail,
      price_minor: chosen ? chosen.total_minor : r.retail_minor,
      terms: (r.terms || []).map(function (t) { return { years: t.years, label: t.label, headline: t.headline, total: t.total, total_minor: t.total_minor, per_year: t.per_year }; }),
      // DOMAIN-OFFER-1: the website this domain is for; changeable in the cart.
      website_id: _for ? _for.id : null,
      website_name: _for ? _for.name : null
    });
    saveCart();
    toast(r.domain + ' added to your cart.', 'success');
    render();
  }

  /* ============================================================== CART ==== */

  function renderCart() {
    var body;

    if (!_cart.length) {
      body = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-8);text-align:center;">' +
        '<div style="font:600 15px var(--fh);color:var(--t1);">Your cart is empty</div>' +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:7px;">Search for a domain to get started.</div>' +
        '<div style="margin-top:var(--sp-6);">' + ctx.button('Search domains', 'id="lud-back"') + '</div></div>';
    } else {
      var subtotal = _cart.reduce(function (t, c) { return t + (c.price_minor || 0); }, 0);   // DOMAIN-TERMS-1: a line's price IS its term total

      body =
        '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden;">' +
        _cart.map(function (c, i) {
          var yearOpts = '';
          (c.terms && c.terms.length ? c.terms : [{ years: c.years, label: c.years + (c.years === 1 ? ' year' : ' years'), total: c.price }]).forEach(function (t) {
            yearOpts += '<option value="' + t.years + '"' + (t.years === c.years ? ' selected' : '') + '>' + esc(t.label) + (t.headline ? ' · ' + esc(t.headline) : '') + ' · ' + esc(t.total) + '</option>';
          });
          return '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);' +
            'padding:var(--sp-5);border-bottom:1px solid var(--bd);flex-wrap:wrap;">' +
            '<div style="min-width:0;flex:1 1 200px;">' +
              '<div style="font:600 15px var(--fh);color:var(--t1);word-break:break-all;">' + esc(c.domain) + '</div>' +
              '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;">' + (c.terms && c.years > 1 ? 'Domain registration · ' + esc((c.terms.filter(function (t) { return t.years === c.years; })[0] || {}).headline || '') + ', then ' + esc(((c.terms.filter(function (t) { return t.years === c.years; })[0] || {}).per_year || [])[1] || '') + ' a year' : 'Domain registration') + '</div>' +
              '<label style="display:flex;align-items:center;gap:7px;margin-top:8px;font:400 12px var(--fb);color:var(--t2);flex-wrap:wrap;">Use with' +
                '<select class="lud-site" data-i="' + i + '" aria-label="Website for ' + esc(c.domain) + '" style="min-height:32px;padding:0 var(--sp-3);border-radius:var(--r);border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:400 12px var(--fb);max-width:260px;">' +
                  siteOptions(c.website_id) + '</select></label>' +
            '</div>' +
            '<select class="lud-yr" data-i="' + i + '" aria-label="Registration period for ' + esc(c.domain) + '" ' +
              'style="min-height:36px;padding:0 var(--sp-3);border-radius:var(--r);border:1px solid var(--bd2);' +
              'background:var(--s1);color:var(--t1);font:400 13px var(--fb);">' + yearOpts + '</select>' +
            '<div style="font:600 15px var(--fh);color:var(--t1);min-width:80px;text-align:right;font-variant-numeric:tabular-nums;">' +
              esc(money(c.price)) + '</div>' +
            '<button class="lud-rm" data-i="' + i + '" aria-label="Remove ' + esc(c.domain) + '" ' +
              'style="min-height:34px;padding:0 var(--sp-3);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);' +
              'background:transparent;color:var(--t3);border:1px solid var(--bd2);">Remove</button>' +
          '</div>';
        }).join('') +
        '<div style="padding:var(--sp-5);">' +
          totalRow('Subtotal', '$' + (subtotal / 100).toFixed(2)) +
          totalRow('Tax', 'Calculated at checkout', true) +
          '<div style="height:1px;background:var(--bd);margin:var(--sp-4) 0;"></div>' +
          totalRow('Total due today', '$' + (subtotal / 100).toFixed(2), false, true) +
          '<div style="font:400 11px var(--fb);color:var(--t3);margin-top:7px;line-height:1.5;">' +
            'Final pricing and any applicable tax are confirmed on the secure payment page.</div>' +
          '<div style="display:flex;gap:var(--sp-3);margin-top:var(--sp-5);flex-wrap:wrap;">' +
            '<button id="lud-checkout" ' + (_checkingOut ? 'disabled' : '') + ' style="flex:1 1 200px;min-height:44px;border-radius:var(--r);' +
              'cursor:pointer;font:600 14px var(--fb);background:var(--p);color:#fff;border:1px solid var(--p);">' +
              (_checkingOut ? 'Starting secure checkout…' : 'Checkout') + '</button>' +
            '<button id="lud-back" style="min-height:44px;padding:0 var(--sp-5);border-radius:var(--r);cursor:pointer;' +
              'font:600 13px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Keep searching</button>' +
          '</div>' +
        '</div></div>';
    }

    ctx.paintBody(shell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Domains', nav: 'list' }, { label: 'Cart' }],
      title: 'Your cart',
      desc: 'Review your domains before checkout.',
      body: body
    }));

    on('lud-back', 'click', function () { _view = { name: 'list' }; render(); });
    on('lud-checkout', 'click', checkout);

    Array.prototype.forEach.call(document.querySelectorAll('.lud-rm'), function (b) {
      b.addEventListener('click', function () {
        _cart.splice(parseInt(b.getAttribute('data-i'), 10), 1);
        saveCart();
        render();
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.lud-yr'), function (s) {
      s.addEventListener('change', function () {
        var i = parseInt(s.getAttribute('data-i'), 10);
        _cart[i].years = parseInt(s.value, 10) || 1;
        var t = (_cart[i].terms || []).filter(function (x) { return x.years === _cart[i].years; })[0];
        if (t) { _cart[i].price = t.total; _cart[i].price_minor = t.total_minor; }   // DOMAIN-TERMS-1
        saveCart();
        render();
      });
    });

    // DOMAIN-OFFER-1: change which website a line is for.
    Array.prototype.forEach.call(document.querySelectorAll('.lud-site'), function (s) {
      s.addEventListener('change', function () {
        var i = parseInt(s.getAttribute('data-i'), 10);
        var id = parseInt(s.value, 10) || null;
        var site = sites().filter(function (w) { return Number(w.id) === id; })[0];
        _cart[i].website_id = id;
        _cart[i].website_name = site ? (site.name || site.title || ('Website #' + site.id)) : null;
        saveCart();
      });
    });
    ensureSites();
  }

  /* DOMAIN-OFFER-1: the workspace's websites, from the infrastructure engine (cached there). */
  function sites() { try { return (ctx.websites && ctx.websites()) || []; } catch (e) { return []; } }
  function ensureSites() {
    if (sites().length || !ctx.loadWebsites || _sitesAsked) { return; }
    _sitesAsked = true;
    ctx.loadWebsites().then(function () { if (_view.name === 'cart' || _view.name === 'detail') { render(); } });
  }
  var _sitesAsked = false;
  function siteOptions(selectedId) {
    var list = sites();
    var out = '<option value=""' + (!selectedId ? ' selected' : '') + '>No website yet</option>';
    list.forEach(function (w) {
      var name = w.name || w.title || ('Website #' + w.id);
      out += '<option value="' + esc(w.id) + '"' + (Number(w.id) === Number(selectedId) ? ' selected' : '') + '>' + esc(name) + '</option>';
    });
    if (selectedId && !list.filter(function (w) { return Number(w.id) === Number(selectedId); }).length) {
      out += '<option value="' + esc(selectedId) + '" selected>Website #' + esc(selectedId) + '</option>';
    }
    return out;
  }
  function websiteCell(d) {
    if (!d.website_id) { return '<span style="color:var(--t3);">—</span>'; }
    var j = d.journey || {};
    var tone = j.complete ? 'var(--ac)' : (d.connect_state === 'failed' || d.connect_state === 'stalled') ? 'var(--rd)' : 'var(--am)';
    return esc(d.website_name || ('Website #' + d.website_id)) +
      '<div>' + ctx.statusPill(j.complete ? 'Live' : 'Setting up', tone) + '</div>';
  }
  /* The five-step line. Exposed for the website's Domain tab so both surfaces draw it the same way. */
  function journeyLine(j) {
    var steps = (j && j.steps) || [];
    return '<ol style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;">' + steps.map(function (s) {
      var tone = s.state === 'done' ? 'var(--ac)' : s.state === 'current' ? 'var(--am)' : s.state === 'failed' ? 'var(--rd)' : 'var(--t3)';
      var mark = s.state === 'done' ? '\u2713 ' : s.state === 'failed' ? '\u26A0 ' : s.state === 'skipped' ? '\u2013 ' : '';
      return '<li title="' + esc(s.detail || '') + '" style="font:600 12px var(--fb);color:' + tone + ';padding:5px 11px;border:1px solid ' + tone + ';border-radius:999px;' +
        (s.state === 'current' ? 'background:var(--s2);' : '') + '">' + mark + esc(s.label) + '</li>';
    }).join('') + '</ol>';
  }

  function totalRow(label, value, muted, strong) {
    return '<div style="display:flex;justify-content:space-between;gap:var(--sp-4);padding:4px 0;">' +
      '<span style="font:' + (strong ? '600 15px' : '400 13px') + ' var(--fb);color:' + (strong ? 'var(--t1)' : 'var(--t2)') + ';">' + esc(label) + '</span>' +
      '<span style="font:' + (strong ? '600 17px var(--fh)' : '400 13px var(--fb)') + ';color:' +
        (muted ? 'var(--t3)' : 'var(--t1)') + ';font-variant-numeric:tabular-nums;">' + esc(value) + '</span></div>';
  }

  function checkout() {
    if (!_cart.length || _checkingOut) { return; }

    _checkingOut = true;
    render();

    var items = _cart.map(function (c) { return { domain: c.domain, years: c.years, website_id: c.website_id || null }; });
    // A stable key so a double-click cannot create two orders. The website is part of it: the same
    // names for a different website is a different order.
    var idem = 'cart-' + items.map(function (i) { return i.domain + ':' + i.years + ':' + (i.website_id || 0); }).join('|');

    ctx.req('POST', 'domains/orders', { items: items, idempotency_key: idem })
      .then(function (r) {
        if (!r.ok || !r.json || !r.json.order) {
          _checkingOut = false;
          var msg = (r.json && (r.json.error || (r.json.rejected && r.json.rejected.length && r.json.rejected[0].reason)))
            || 'We could not create your order. Please try again.';
          toast(msg, 'error');
          render();
          return;
        }

        if (r.json.rejected && r.json.rejected.length) {
          toast(r.json.rejected.length + ' domain(s) were unavailable and removed.', 'info');
        }

        return ctx.req('POST', 'domains/orders/' + r.json.order.id + '/checkout', {}).then(function (c) {
          if (c.ok && c.json && c.json.checkout_url) {
            // Cart is cleared only once we have a real payment session.
            _cart = [];
            saveCart();
            window.location.href = c.json.checkout_url;
            return;
          }
          _checkingOut = false;
          toast((c.json && c.json.error) || 'We could not start secure checkout. Please try again.', 'error');
          render();
        });
      })
      .catch(function () {
        _checkingOut = false;
        toast('We could not reach the checkout service. Please try again shortly.', 'error');
        render();
      });
  }

  /* ============================================================ DETAIL ==== */

  function renderDetail() {
    var d = _domains.filter(function (x) { return x.id === _view.domainId; })[0];

    if (!d) { _view = { name: 'list' }; render(); return; }

    ctx.paintBody(shell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Domains', nav: 'list' }, { label: d.domain }],
      title: d.domain,
      desc: 'Registration, renewal and name server details.',
      actions: '<button id="lud-refresh" style="min-height:var(--touch-min,40px);padding:0 var(--sp-4);border-radius:var(--r);' +
        'cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Refresh</button>' +
        '<button id="lud-back" style="min-height:var(--touch-min,40px);padding:0 var(--sp-4);border-radius:var(--r);' +
        'cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">All domains</button>',
      body: detailBody(d) + '<div id="lud-timeline">' + ctx.loadingState('Loading activity…') + '</div>'
    }));

    on('lud-back', 'click', function () { _view = { name: 'list' }; render(); });
    on('lud-refresh', 'click', function () { refreshDomain(d.id); });
    on('lud-copy-ns', 'click', function () { copyNs(d); });
    on('lud-ar', 'change', function (e) { setAutoRenew(d.id, e.target.checked); });

    // DOMAIN-OFFER-1: attach / detach / open the website.
    on('lud-attach', 'click', function () {
      var sel = h('lud-site-pick'); var id = sel ? parseInt(sel.value, 10) : 0;
      if (!id) { toast('Choose a website first.', 'info'); return; }
      var b = h('lud-attach'); if (b) { b.disabled = true; b.textContent = 'Setting up…'; }
      ctx.req('POST', 'domains/' + d.id + '/attach', { website_id: id }).then(function (r) {
        if (!r.ok) { toast((r.json && r.json.error) || 'We could not set that up just now.', 'error'); if (b) { b.disabled = false; b.textContent = 'Use with this website'; } return; }
        toast('Setting up ' + (r.json.domain && r.json.domain.hostname || d.domain) + ' for your website.', 'success');
        load(true);
      }).catch(function () { toast('We could not reach the service. Please try again.', 'error'); if (b) { b.disabled = false; b.textContent = 'Use with this website'; } });
    });
    on('lud-detach', 'click', function () {
      var go = function () {
        ctx.req('POST', 'domains/' + d.id + '/detach', {}).then(function (r) {
          if (!r.ok) { toast((r.json && r.json.error) || 'We could not detach it just now.', 'error'); return; }
          toast('Detached. The website answers at its LevelUp address again.', 'success');
          load(true);
        });
      };
      if (typeof window.luConfirm === 'function') {
        window.luConfirm('Detach this domain?', 'Visitors will use the .levelupgrowth.io address until a domain is connected again.', { okLabel: 'Detach', cancelLabel: 'Keep it', danger: true }).then(function (ok) { if (ok) { go(); } });
      } else { go(); }
    });
    on('lud-open-site', 'click', function () { if (ctx.openWebsite) { ctx.openWebsite(d.website_id); } });
    ensureSites();

    loadTimeline(d.id);
  }

  /* DOMAIN-OFFER-1: the Website card on a domain's detail screen. */
  function websiteBlock(d) {
    var j = d.journey || { steps: [], summary: '' };
    var inner;
    if (d.website_id) {
      inner =
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4);">' +
          '<div style="font:400 13px var(--fb);color:var(--t2);">' + esc(d.hostname || ('www.' + d.domain)) + ' → <strong style="color:var(--t1);">' + esc(d.website_name || ('Website #' + d.website_id)) + '</strong></div>' +
          ctx.statusPill(j.complete ? 'Live' : (d.connect_state === 'failed' || d.connect_state === 'stalled') ? 'Needs attention' : 'Setting up',
            j.complete ? 'var(--ac)' : (d.connect_state === 'failed' || d.connect_state === 'stalled') ? 'var(--rd)' : 'var(--am)') +
        '</div>' +
        journeyLine(j) +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:var(--sp-4);line-height:1.5;">' + esc(j.summary || '') + '</div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-4);">' +
          (j.live_url ? '<a href="' + esc(j.live_url) + '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;min-height:36px;padding:0 var(--sp-4);border-radius:var(--r);font:600 13px var(--fb);background:var(--p);color:#fff;border:1px solid var(--p);text-decoration:none;">Open ' + esc(d.hostname || d.domain) + '</a>' : '') +
          '<button id="lud-open-site" style="min-height:36px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 13px var(--fb);background:transparent;color:var(--t1);border:1px solid var(--bd2);">Open website settings</button>' +
          '<button id="lud-detach" style="min-height:36px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 13px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Detach</button>' +
        '</div>';
    } else {
      inner =
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:var(--sp-4);line-height:1.5;">Choose a website and we’ll point ' + esc(d.hostname || ('www.' + d.domain)) + ' at it, issue the certificate and switch it on — usually under 10 minutes, nothing to configure.</div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;align-items:center;">' +
          '<select id="lud-site-pick" aria-label="Website" style="min-height:38px;padding:0 var(--sp-3);border-radius:var(--r);border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:400 13px var(--fb);max-width:300px;">' + siteOptions(null) + '</select>' +
          '<button id="lud-attach" style="min-height:38px;padding:0 var(--sp-5);border-radius:var(--r);cursor:pointer;font:600 13px var(--fb);background:var(--p);color:#fff;border:1px solid var(--p);">Use with this website</button>' +
        '</div>' +
        (d.dns_state === 'external' ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:var(--sp-3);">This domain uses its own name servers, so you will add the records at your DNS provider when we show them.</div>' : '');
    }
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);margin-bottom:var(--sp-6);">' +
      ctx.sectionTitle('Website', 'Where this domain points.') + inner + '</div>';
  }

  function detailBody(d) {
    var ns = (d.nameservers || []);

    var overview =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-6);">' +
      [
        ['Status', d.status_label || d.status],
        ['Registered', fmtDate(d.registered_on)],
        ['Expires', fmtDate(d.expires_on)],
        ['Renews on', fmtDate(d.renewal_date)],
        ['Registrar', d.registrar || 'LevelUpGrowth'],
        ['DNS managed by', d.dns_managed_by || '—'],
        ['Transfer lock', d.transfer_lock ? 'On' : 'Off'],
        ['WHOIS privacy', d.privacy ? 'On' : 'Off']
      ].map(function (p) {
        return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-4) var(--sp-5);">' +
          '<div style="font:600 11px var(--fb);color:var(--t3);letter-spacing:.04em;text-transform:uppercase;">' + esc(p[0]) + '</div>' +
          '<div style="font:600 15px var(--fh);color:var(--t1);margin-top:5px;">' + esc(p[1]) + '</div></div>';
      }).join('') + '</div>';

    var expiryNote = d.expiring_soon
      ? '<div role="status" style="background:var(--s1);border:1px solid var(--bd);border-left:3px solid var(--am);' +
        'border-radius:var(--rg);padding:var(--sp-4) var(--sp-5);margin-bottom:var(--sp-6);font:400 13px var(--fb);color:var(--t2);">' +
        'This domain expires in ' + esc(d.days_until_expiry) + ' days. Turn on auto-renew so it does not lapse.</div>'
      : '';

    var renewal =
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);margin-bottom:var(--sp-6);">' +
      ctx.sectionTitle('Renewal', 'Keep your domain registered without having to remember the date.') +
      '<label style="display:flex;align-items:center;gap:var(--sp-3);cursor:pointer;">' +
        '<input id="lud-ar" type="checkbox" ' + (d.auto_renew ? 'checked' : '') + ' style="width:17px;height:17px;cursor:pointer;">' +
        '<span style="font:400 13px var(--fb);color:var(--t2);">Automatically renew this domain before it expires</span>' +
      '</label>' +
      '<div id="lud-ar-note" style="font:400 12px var(--fb);color:var(--t3);margin-top:7px;"></div>' +
      '</div>';

    var nameservers =
      '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);margin-bottom:var(--sp-6);">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;">' +
        '<div>' + ctx.sectionTitle('Name servers', 'These tell the internet where your domain points.') + '</div>' +
        (ns.length ? '<button id="lud-copy-ns" style="min-height:34px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;' +
          'font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">Copy</button>' : '') +
      '</div>' +
      (ns.length
        ? '<div style="font:400 13px/1.9 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--t1);word-break:break-all;">' +
          ns.map(function (n) { return esc(n); }).join('<br>') + '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t3);">Not yet available. Select Refresh to check again.</div>') +
      '</div>';

    return expiryNote + websiteBlock(d) + overview + renewal + nameservers;
  }

  function copyNs(d) {
    var text = (d.nameservers || []).join('\n');
    if (!text) { return; }

    var done = function () { toast('Name servers copied.', 'success'); };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
    } else {
      fallbackCopy(text, done);
    }
  }

  function fallbackCopy(text, done) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      done();
    } catch (e) { toast('Could not copy. Select the text manually.', 'error'); }
  }

  function setAutoRenew(id, enabled) {
    var note = h('lud-ar-note');
    if (note) { note.textContent = 'Saving…'; }

    ctx.req('POST', 'domains/' + id + '/auto-renew', { enabled: enabled })
      .then(function (r) {
        var j = r.json || {};

        if (!r.ok || !j.ok) {
          if (note) { note.textContent = j.message || 'We could not change auto-renew just now.'; }
          var cb = h('lud-ar');
          if (cb) { cb.checked = !enabled; }
          return;
        }

        // Trust the server's reported state, not the checkbox. If the registrar
        // has not confirmed the change, say so rather than showing it as done.
        var cbx = h('lud-ar');
        if (cbx) { cbx.checked = !!j.auto_renew; }
        if (note) { note.textContent = j.message || ''; }

        _domains.forEach(function (d) { if (d.id === id) { d.auto_renew = !!j.auto_renew; } });
        if (j.confirmed) { toast(j.message, 'success'); }
      })
      .catch(function () {
        if (note) { note.textContent = 'We could not reach the domain service. Please try again shortly.'; }
        var cb2 = h('lud-ar');
        if (cb2) { cb2.checked = !enabled; }
      });
  }

  function refreshDomain(id) {
    var btn = h('lud-refresh');
    if (btn) { btn.disabled = true; btn.textContent = 'Refreshing…'; }

    ctx.req('POST', 'domains/' + id + '/sync', {})
      .then(function () {
        toast('Refreshing domain details…', 'info');
        // The refresh runs in the background; re-read shortly after.
        setTimeout(function () { load(true); }, 2500);
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Refresh'; }
        toast('We could not refresh right now.', 'error');
      });
  }

  /* ========================================================== TIMELINE ==== */

  function loadTimeline(id) {
    ctx.req('GET', 'domains/' + id + '/timeline')
      .then(function (r) {
        var slot = h('lud-timeline');
        if (!slot) { return; }

        if (!r.ok || !r.json || !r.json.steps) {
          slot.innerHTML = '<div style="font:400 13px var(--fb);color:var(--t3);">Activity is not available right now.</div>';
          return;
        }

        slot.innerHTML = timelineBlock(r.json);
      })
      .catch(function () {
        var slot = h('lud-timeline');
        if (slot) { slot.innerHTML = '<div style="font:400 13px var(--fb);color:var(--t3);">Activity is not available right now.</div>'; }
      });
  }

  function timelineBlock(t) {
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);">' +
      ctx.sectionTitle('Activity', 'What has happened with this domain.') +
      '<ol style="list-style:none;margin:0;padding:0;">' +
      t.steps.map(function (s, i) {
        var done = s.state === 'done';
        var last = i === t.steps.length - 1;
        return '<li style="display:flex;gap:var(--sp-4);align-items:flex-start;">' +
          '<div style="display:flex;flex-direction:column;align-items:center;flex:none;">' +
            '<span aria-hidden="true" style="width:11px;height:11px;border-radius:50%;margin-top:5px;' +
              'background:' + (done ? 'var(--ac)' : 'transparent') + ';border:2px solid ' + (done ? 'var(--ac)' : 'var(--bd2)') + ';"></span>' +
            (last ? '' : '<span aria-hidden="true" style="width:2px;flex:1;min-height:26px;background:' + (done ? 'var(--ac)' : 'var(--bd)') + ';opacity:.5;"></span>') +
          '</div>' +
          '<div style="padding-bottom:' + (last ? '0' : 'var(--sp-4)') + ';min-width:0;">' +
            '<div style="font:600 13px var(--fb);color:' + (done ? 'var(--t1)' : 'var(--t3)') + ';">' + esc(s.label) +
              (done ? '' : ' <span style="font:400 11px var(--fb);color:var(--t3);">· pending</span>') + '</div>' +
            '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:2px;line-height:1.5;">' + esc(s.detail) + '</div>' +
            (s.at ? '<div style="font:400 11px var(--fb);color:var(--t3);margin-top:2px;">' + esc(ctx.fmtTime(s.at)) + '</div>' : '') +
          '</div></li>';
      }).join('') +
      '</ol></div>';
  }

  /* ============================================================== LOAD ==== */

  function load(quiet) {
    if (!quiet) { _loading = true; render(); }

    return ctx.req('GET', 'domains')
      .then(function (r) {
        _loading = false;
        _domains = (r.json && r.json.domains) || [];
        render();
      })
      .catch(function () {
        _loading = false;
        _domains = [];
        render();
      });
  }

  /* ============================================================ PUBLIC ==== */

  window.luDomains = {
    /**
     * Mount the module. `context` supplies the design helpers and the
     * authenticated transport from the infrastructure module, so this file
     * never duplicates either.
     */
    mount: function (context) {
      ctx = context;
      loadCart();

      // Returning from Stripe: land on the domain list and explain what happens
      // next, rather than dropping the customer on a blank screen.
      var hash = String(window.location.hash || '');
      var qs = {};
      try { (hash.split('?')[1] || '').split('&').forEach(function (kv) { var p = kv.split('='); if (p[0]) { qs[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); } }); } catch (e) {}

      if (hash.indexOf('purchase=success') !== -1) {
        _cart = [];
        saveCart();
        try { history.replaceState(null, '', window.location.pathname + window.location.search + '#domains'); } catch (e) {}
        // DOMAIN-OFFER-1: say what will actually happen — connection only when a website was chosen.
        var orderId = parseInt(qs.order, 10);
        var said = false;
        if (orderId) {
          ctx.req('GET', 'domains/orders/' + orderId).then(function (r) {
            var items = (r.json && r.json.order && r.json.order.items) || [];
            var withSite = items.filter(function (i) { return i.website_id; });
            if (withSite.length) {
              toast('Payment received. We are registering ' + withSite[0].domain + ' and connecting it to ' + (withSite[0].website_name || 'your website') + ' — usually under 10 minutes.', 'success');
            } else {
              toast('Payment received. We are registering your domain now — this usually takes under a minute.', 'success');
            }
            said = true;
          }).catch(function () {});
        }
        setTimeout(function () { if (!said && !orderId) { toast('Payment received. We are registering your domain now — this usually takes under a minute.', 'success'); } }, 0);
      } else if (hash.indexOf('purchase=cancelled') !== -1) {
        toast('Checkout cancelled. Your cart has been kept.', 'info');
        try { history.replaceState(null, '', window.location.pathname + window.location.search + '#domains'); } catch (e) {}
      }

      // DOMAIN-OFFER-1: arrived from a website (Domain tab, publish modal, Basic tile) or a deep link
      // #domains?for=<id>&q=<term>: search for that website; or open one bought domain's detail.
      var pre = window.__luDomainsPrefill || null; window.__luDomainsPrefill = null;
      var forId = (pre && pre.website_id) || parseInt(qs['for'], 10) || null;
      var term = (pre && pre.term) || qs.q || '';
      var openId = (pre && pre.open) || null;
      if (forId) {
        _for = { id: Number(forId), name: (pre && pre.website_name) || null };
        if (!_for.name && ctx.loadWebsites) {
          ctx.loadWebsites().then(function (list) {
            var w = (list || []).filter(function (x) { return Number(x.id) === Number(forId); })[0];
            if (w && _for && Number(_for.id) === Number(forId)) { _for.name = w.name || w.title || null; if (_view.name === 'list') { render(); } }
          });
        }
        if (qs['for']) { try { history.replaceState(null, '', window.location.pathname + window.location.search + '#domains'); } catch (e) {} }
      }

      _view = openId ? { name: 'detail', domainId: Number(openId) } : { name: 'list' };
      load().then(function () { if (term) { _search.term = term; doSearch(term); } });
    },

    journeyLine: journeyLine,

    openCart: function () { _view = { name: 'cart' }; render(); },

    // Exposed for tests and for the infrastructure module's own navigation.
    _state: function () {
      return { view: _view, cart: _cart.slice(), domains: _domains.length, table: JSON.parse(JSON.stringify(_table)) };
    }
  };
})();


/* DOMAINS-DEEPLINK-1 (2026-09-25): honour /app/#domains. The shell has no hash routing, so without this a
   signed-in customer sent here from the marketing basket, or returned here by Stripe, lands on the default
   view with the domains screen never shown. Wait for the shell to boot, then open Hosting on its Domains tab. */
(function () {
  function wanted() { try { return String(window.location.hash || '').indexOf('#domains') === 0; } catch (e) { return false; } }
  if (!wanted()) return;
  var tries = 0;
  var t = setInterval(function () {
    tries++;
    var booted = typeof window.nav === 'function' && !!document.querySelector('.view.active');
    if (!booted) { if (tries > 60) { clearInterval(t); } return; }   // 15s, then give up quietly
    clearInterval(t);
    window.__luInfraTab = 'domains';
    try { window.nav('infrastructure'); } catch (e) {}
  }, 250);
})();
