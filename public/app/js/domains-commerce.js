/**
 * LevelUp Growth — Domains.
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
 * named. The customer buys from LevelUp Growth.
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
  var _search = { term: '', years: 1, result: null, busy: false, error: null };
  var _cart = [];
  var _checkingOut = false;
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

    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);margin-bottom:var(--sp-6);">' +
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

    var yearOpts = '';
    for (var y = 1; y <= 10; y++) {
      yearOpts += '<option value="' + y + '"' + (y === _search.years ? ' selected' : '') + '>' +
        y + (y === 1 ? ' year' : ' years') + '</option>';
    }

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
            'Renews at ' + esc(money(r.renewal && r.renewal.retail)) + ' per year' +
          '</div>' +
          (r.registration_period ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;">' +
            'Registered until ' + esc(fmtDate(r.registration_period.expires_on)) + '</div>' : '') +
        '</div>' +
        '<div style="text-align:right;flex:none;">' +
          '<div style="font:600 24px var(--fh);color:var(--t1);font-variant-numeric:tabular-nums;">' + esc(money(r.retail)) + '</div>' +
          '<div style="font:400 11px var(--fb);color:var(--t3);">first term, excl. tax</div>' +
        '</div>' +
      '</div>' +
      '<div style="display:flex;gap:var(--sp-3);align-items:center;margin-top:var(--sp-5);flex-wrap:wrap;">' +
        '<label for="lud-years" style="font:600 12px var(--fb);color:var(--t2);">Register for</label>' +
        '<select id="lud-years" style="min-height:38px;padding:0 var(--sp-3);border-radius:var(--r);' +
          'border:1px solid var(--bd2);background:var(--s1);color:var(--t1);font:400 13px var(--fb);">' + yearOpts + '</select>' +
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
    on('lud-years', 'change', function (e) { _search.years = parseInt(e.target.value, 10) || 1; });
    on('lud-add', 'click', function () { addToCart(); });
    on('lud-cart-btn', 'click', function () { _view = { name: 'cart' }; render(); });

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

    _cart.push({
      domain: r.domain,
      years: _search.years,
      // Display only. The server re-prices at order time and its figure wins.
      price: r.retail,
      price_minor: r.retail_minor
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
      var subtotal = _cart.reduce(function (t, c) { return t + (c.price_minor || 0) * (c.years || 1); }, 0);

      body =
        '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden;">' +
        _cart.map(function (c, i) {
          var yearOpts = '';
          for (var y = 1; y <= 10; y++) {
            yearOpts += '<option value="' + y + '"' + (y === c.years ? ' selected' : '') + '>' + y + (y === 1 ? ' year' : ' years') + '</option>';
          }
          return '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);' +
            'padding:var(--sp-5);border-bottom:1px solid var(--bd);flex-wrap:wrap;">' +
            '<div style="min-width:0;flex:1 1 200px;">' +
              '<div style="font:600 15px var(--fh);color:var(--t1);word-break:break-all;">' + esc(c.domain) + '</div>' +
              '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;">Domain registration</div>' +
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
        saveCart();
        render();
      });
    });
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

    var items = _cart.map(function (c) { return { domain: c.domain, years: c.years }; });
    // A stable key so a double-click cannot create two orders.
    var idem = 'cart-' + items.map(function (i) { return i.domain + ':' + i.years; }).join('|');

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

    loadTimeline(d.id);
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
        ['Registrar', d.registrar || 'LevelUp Growth'],
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

    return expiryNote + overview + renewal + nameservers;
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
      if (hash.indexOf('purchase=success') !== -1) {
        toast('Payment received. We are registering your domain now — this usually takes under a minute.', 'success');
        _cart = [];
        saveCart();
        try { history.replaceState(null, '', window.location.pathname + window.location.search + '#domains'); } catch (e) {}
      } else if (hash.indexOf('purchase=cancelled') !== -1) {
        toast('Checkout cancelled. Your cart has been kept.', 'info');
        try { history.replaceState(null, '', window.location.pathname + window.location.search + '#domains'); } catch (e) {}
      }

      _view = { name: 'list' };
      load();
    },

    openCart: function () { _view = { name: 'cart' }; render(); },

    // Exposed for tests and for the infrastructure module's own navigation.
    _state: function () {
      return { view: _view, cart: _cart.slice(), domains: _domains.length, table: JSON.parse(JSON.stringify(_table)) };
    }
  };
})();
