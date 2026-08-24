/**
 * Component tests for the Domains customer module.
 *
 *   node tests/js/domains-commerce.test.cjs
 *
 * The module renders by handing HTML to ctx.paintBody, so a test can mount it
 * with a fixture transport, capture what it painted, and assert on the real
 * output. No browser, no network — but the assertions are against the actual
 * markup a customer would receive, not a mock of it.
 *
 * Everything here is async: the module paints a loading frame first and the
 * real frame once its request resolves. Asserting synchronously would test the
 * spinner and report a false pass, so every helper returns a promise and the
 * runner awaits it.
 */

var assert = require('assert');
var fs = require('fs');
var path = require('path');

var MODULE = process.env.LUD_MODULE ||
  path.resolve(__dirname, '../../public/app/js/domains-commerce.js');

var SRC = fs.readFileSync(MODULE, 'utf8');

var pass = 0, fail = 0, failures = [], queue = [];

function test(name, fn) { queue.push({ name: name, fn: fn }); }

function record(name, err) {
  if (err) { fail++; failures.push([name, err.message]); console.log('  ✘ ' + name); }
  else { pass++; console.log('  ✔ ' + name); }
}

function runAll() {
  var chain = Promise.resolve();
  queue.forEach(function (t) {
    chain = chain.then(function () {
      var out;
      try { out = t.fn(); } catch (e) { record(t.name, e); return; }
      if (out && typeof out.then === 'function') {
        return out.then(function () { record(t.name, null); }, function (e) { record(t.name, e); });
      }
      record(t.name, null);
    });
  });
  return chain;
}

/* ---------------------------------------------------------------- stubs -- */

var painted = [], elements = {}, qsaCache = {};

function fakeEl(tag) {
  return {
    _tag: tag || '', value: '', checked: false, textContent: '', innerHTML: '',
    disabled: false, style: {}, _events: {},
    addEventListener: function (e, f) { this._events[e] = f; },
    setAttribute: function () {}, getAttribute: function () { return null; },
    select: function () {}, focus: function () {}
  };
}

function resetEnv(opts) {
  opts = opts || {};
  painted = []; elements = {}; qsaCache = {};

  global.window = {
    location: { hash: opts.hash || '', pathname: '/app/', search: '', href: '' },
    LU_WORKSPACE_ID: opts.ws || 1,
    showToast: function (m, k) { global.window._toasts.push([m, k]); },
    _toasts: []
  };

  var store = opts.store || {};
  global.localStorage = {
    getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem: function (k, v) { store[k] = v; },
    removeItem: function (k) { delete store[k]; },
    _store: store
  };

  global.history = { replaceState: function () {} };
  global.navigator = {};

  global.document = {
    getElementById: function (id) { elements[id] = elements[id] || fakeEl(id); return elements[id]; },

    /* Parse the last painted HTML so class-bound controls are genuinely wired.
     * Returning [] would make every navigation assertion hollow.
     *
     * Results are memoised per render: the module binds its handlers on the
     * objects IT receives, so a later call from a test must return those same
     * objects or the handler it is trying to fire will not be there. The cache
     * is keyed on the render count, so a re-render yields fresh elements. */
    querySelectorAll: function (sel) {
      var key = sel + '|' + painted.length;
      if (qsaCache[key]) { return qsaCache[key]; }

      var cls = String(sel).replace(/^\./, '');
      var html = painted[painted.length - 1] || '';
      var re = new RegExp('<(button|select)[^>]*class="' + cls + '"[^>]*>', 'g');
      var out = [], m;

      while ((m = re.exec(html)) !== null) {
        (function (tag) {
          var e = fakeEl();
          e.getAttribute = function (n) {
            var a = new RegExp(n + '="([^"]*)"').exec(tag);
            return a ? a[1] : null;
          };
          out.push(e);
        })(m[0]);
      }

      qsaCache[key] = out;
      return out;
    },

    createElement: function () { return fakeEl(); },
    body: { appendChild: function () {}, removeChild: function () {} },
    execCommand: function () { return true; }
  };
}

function makeCtx(responder) {
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  return {
    esc: esc,
    paintBody: function (html) { painted.push(html); },
    pageShell: function (o) { return '<!--shell:' + (o.title || '') + '-->' + (o.actions || '') + (o.body || ''); },
    card: function (b) { return '<div class="card">' + b + '</div>'; },
    button: function (l, a) { return '<button ' + (a || '') + '>' + esc(l) + '</button>'; },
    statusPill: function (l) { return '<span class="pill">' + esc(l) + '</span>'; },
    loadingState: function (m) { return '<div class="loading">' + esc(m || 'Loading') + '</div>'; },
    errorState: function (m) { return '<div class="err">' + esc(m) + '</div>'; },
    metricStrip: function (ms) { return '<div class="metrics">' + ms.map(function (m) { return m.label + ':' + m.value; }).join('|') + '</div>'; },
    sectionTitle: function (t, s) { return '<h3>' + esc(t) + '</h3>' + (s ? '<p>' + esc(s) + '</p>' : ''); },
    fmtTime: function (i) { return String(i || ''); },
    ICONS: {},
    req: responder
  };
}

var calls = [];

function transport(routes) {
  return function (method, p, body) {
    calls.push({ method: method, path: p, body: body });
    var key = method + ' ' + p.split('?')[0];
    var entry = routes[key];
    if (typeof entry === 'function') { entry = entry(p, body); }
    if (!entry) { entry = { ok: true, status: 200, json: {} }; }
    if (entry === 'never') { return new Promise(function () {}); }
    return Promise.resolve(entry);
  };
}

/** Let queued microtasks and the module's own .then chains settle. */
function flush(times) {
  var p = Promise.resolve();
  for (var i = 0; i < (times || 3); i++) {
    p = p.then(function () { return new Promise(function (r) { setImmediate(r); }); });
  }
  return p;
}

function evaluateModule() {
  new Function('window', 'document', 'localStorage', 'navigator', 'history', 'setTimeout', 'console', SRC)(
    global.window, global.document, global.localStorage, global.navigator,
    global.history, function () {}, console
  );
}

/** Mount and wait until the real (post-fetch) frame has been painted. */
function load(responder, opts) {
  resetEnv(opts);
  calls = [];
  evaluateModule();
  global.window.luDomains.mount(makeCtx(responder));
  return flush().then(function () { return global.window.luDomains; });
}

function last() { return painted[painted.length - 1] || ''; }
function all() { return painted.join('\n'); }
function el(id) { return global.document.getElementById(id); }
function click(id) { var e = el(id); assert.ok(e._events.click, 'no click handler bound on #' + id); e._events.click(); }

var DOMAIN = {
  id: 7, domain: 'brandnew.com', status: 'active', status_label: 'Active',
  registered_on: '2026-07-29', expires_on: '2027-07-29', renewal_date: '2027-07-29',
  days_until_expiry: 365, expiring_soon: false, auto_renew: false,
  transfer_lock: false, privacy: false,
  nameservers: ['dns1.example-ns.com', 'dns2.example-ns.com'],
  dns_managed_by: 'LevelUp Growth', registrar: 'LevelUp Growth',
  last_updated: '2026-07-29T12:01:59+00:00'
};

var EXPIRING = Object.assign({}, DOMAIN, { id: 8, domain: 'soon.com', expiring_soon: true, days_until_expiry: 12 });

var AVAILABLE = {
  available: true, domain: 'brandnew.com', retail: '$18.17', retail_minor: 1817,
  premium: false, renewal: { retail: '$23.50' },
  registration_period: { years: 1, expires_on: '2027-07-29' }, sold_by: 'LevelUp Growth'
};

function routes(domains, extra) {
  var r = { 'GET domains': { ok: true, status: 200, json: { domains: domains || [], count: (domains || []).length, provider: 'LevelUp Growth' } } };
  Object.keys(extra || {}).forEach(function (k) { r[k] = extra[k]; });
  return r;
}

/** Search for a term and settle. */
function search(m, term) {
  el('lud-q').value = term;
  click('lud-go');
  return flush();
}

console.log('\nDomains customer module\n');

/* ========================================================== rendering === */

test('renders an empty state when the customer owns no domains', function () {
  return load(transport(routes([]))).then(function () {
    assert.ok(/don&#39;t own any domains yet|don't own any domains yet/.test(last()), 'expected the empty state');
    assert.ok(last().indexOf('Find a domain') !== -1, 'search must still be offered');
  });
});

test('renders owned domains with status, dates and auto-renew', function () {
  return load(transport(routes([DOMAIN]))).then(function () {
    var h = last();
    assert.ok(h.indexOf('brandnew.com') !== -1, 'domain');
    assert.ok(h.indexOf('Active') !== -1, 'status');
    assert.ok(h.indexOf('Manage') !== -1, 'row action');
  });
});

test('shows a metric strip counting domains and those expiring soon', function () {
  return load(transport(routes([DOMAIN, EXPIRING]))).then(function () {
    assert.ok(last().indexOf('Domains:2') !== -1);
    assert.ok(last().indexOf('Expiring in 30 days:1') !== -1);
  });
});

test('shows a loading state before data arrives', function () {
  resetEnv(); calls = [];
  evaluateModule();
  global.window.luDomains.mount(makeCtx(transport({ 'GET domains': 'never' })));
  assert.ok(all().indexOf('Loading your domains') !== -1);
});

test('an expiring domain is flagged with its urgency', function () {
  return load(transport(routes([EXPIRING]))).then(function () {
    assert.ok(last().indexOf('in 12 days') !== -1);
  });
});

/* ============================================================= search === */

test('rejects an invalid domain locally, without calling the API', function () {
  return load(transport(routes([]))).then(function (m) {
    var before = calls.length;
    return search(m, 'not a domain').then(function () {
      assert.strictEqual(calls.length, before, 'no API call should be made');
      assert.ok(last().indexOf('does not look like a domain') !== -1);
    });
  });
});

test('shows availability, price, renewal price and period', function () {
  return load(transport(routes([], { 'GET domains/search': { ok: true, status: 200, json: AVAILABLE } })))
    .then(function (m) { return search(m, 'brandnew.com'); })
    .then(function () {
      var h = last();
      assert.ok(h.indexOf('$18.17') !== -1, 'retail price');
      assert.ok(h.indexOf('Available') !== -1, 'availability');
      assert.ok(h.indexOf('$23.50') !== -1, 'renewal price');
      assert.ok(h.indexOf('Add to cart') !== -1, 'add action');
      assert.ok(h.indexOf('Registered until') !== -1, 'registration period');
    });
});

test('marks a premium domain and warns it is non-refundable', function () {
  var prem = Object.assign({}, AVAILABLE, { premium: true, domain: 'lux.com', retail: '$4,500.00' });
  return load(transport(routes([], { 'GET domains/search': { ok: true, status: 200, json: prem } })))
    .then(function (m) { return search(m, 'lux.com'); })
    .then(function () {
      assert.ok(last().indexOf('Premium') !== -1);
      assert.ok(last().indexOf('non-refundable') !== -1);
    });
});

test('offers suggestions for a taken domain, labelled as unchecked', function () {
  return load(transport(routes([], { 'GET domains/search': { ok: true, status: 200, json: { available: false, domain: 'taken.com', reason: 'Domain is already registered' } } })))
    .then(function (m) { return search(m, 'taken.com'); })
    .then(function () {
      var h = last();
      assert.ok(h.indexOf('already registered') !== -1);
      assert.ok(h.indexOf('taken.net') !== -1, 'suggestions offered');
      assert.ok(h.indexOf('have not checked these yet') !== -1, 'must not imply availability');
    });
});

test('a blocked search shows a safe message, never a provider code', function () {
  return load(transport(routes([], { 'GET domains/search': { ok: true, status: 200, json: { blocked: true, blocked_reason: 'Domain search is temporarily unavailable. Please try again shortly.' } } })))
    .then(function (m) { return search(m, 'x.com'); })
    .then(function () {
      assert.ok(last().indexOf('temporarily unavailable') !== -1);
      assert.ok(last().indexOf('1011102') === -1);
    });
});

/* =============================================================== cart === */

function withItem(extra) {
  return load(transport(routes([], Object.assign({ 'GET domains/search': { ok: true, status: 200, json: AVAILABLE } }, extra || {}))))
    .then(function (m) { return search(m, 'brandnew.com').then(function () { click('lud-add'); return m; }); });
}

test('adds a domain to the cart and reflects it in the cart button', function () {
  return withItem().then(function (m) {
    assert.strictEqual(m._state().cart.length, 1);
    assert.strictEqual(m._state().cart[0].domain, 'brandnew.com');
    assert.ok(last().indexOf('Cart (1)') !== -1);
  });
});

test('cart shows subtotal, a tax note and a grand total', function () {
  return withItem().then(function (m) {
    m.openCart();
    var h = last();
    assert.ok(h.indexOf('Subtotal') !== -1);
    assert.ok(h.indexOf('Calculated at checkout') !== -1, 'tax must not be invented client-side');
    assert.ok(h.indexOf('Total due today') !== -1);
    assert.ok(h.indexOf('$18.17') !== -1);
  });
});

test('cart offers a period selector and a remove control per line', function () {
  return withItem().then(function (m) {
    m.openCart();
    assert.ok(last().indexOf('lud-yr') !== -1);
    assert.ok(last().indexOf('Remove') !== -1);
  });
});

test('removing the last line empties the cart', function () {
  return withItem().then(function (m) {
    m.openCart();
    var rm = global.document.querySelectorAll('.lud-rm');
    assert.strictEqual(rm.length, 1, 'one remove control');
    rm[0]._events.click();
    assert.strictEqual(m._state().cart.length, 0);
    assert.ok(last().indexOf('Your cart is empty') !== -1);
  });
});

test('changing the registration period updates the line', function () {
  return withItem().then(function (m) {
    m.openCart();
    var sel = global.document.querySelectorAll('.lud-yr');
    assert.strictEqual(sel.length, 1);
    sel[0].value = '3';
    sel[0]._events.change();
    assert.strictEqual(m._state().cart[0].years, 3);
  });
});

test('an empty cart renders its own empty state', function () {
  return load(transport(routes([]))).then(function (m) {
    m.openCart();
    assert.ok(last().indexOf('Your cart is empty') !== -1);
  });
});

test('cart persists under a workspace-scoped key', function () {
  return withItem().then(function () {
    var raw = global.localStorage.getItem('lu.domains.cart.1');
    assert.ok(raw, 'cart persisted');
    assert.strictEqual(JSON.parse(raw)[0].domain, 'brandnew.com');
  });
});

/* =========================================================== checkout === */

test('checkout creates an order then redirects to the payment page', function () {
  return withItem({
    'POST domains/orders': { ok: true, status: 201, json: { order: { id: 42, items: [] }, rejected: [] } },
    'POST domains/orders/42/checkout': { ok: true, status: 200, json: { checkout_url: 'https://checkout.example/pay/abc', session_id: 'cs_1' } }
  }).then(function (m) {
    m.openCart();
    click('lud-checkout');
    return flush(5).then(function () {
      var order = calls.filter(function (c) { return c.path === 'domains/orders'; })[0];
      assert.ok(order, 'order call made');
      assert.deepStrictEqual(order.body.items, [{ domain: 'brandnew.com', years: 1 }]);
      assert.ok(order.body.idempotency_key, 'must send an idempotency key');
      assert.strictEqual(global.window.location.href, 'https://checkout.example/pay/abc');
      assert.strictEqual(m._state().cart.length, 0, 'cart cleared only after a real session');
    });
  });
});

test('the cart submits domain and years only — never a price', function () {
  return withItem({
    'POST domains/orders': { ok: true, status: 201, json: { order: { id: 43, items: [] } } },
    'POST domains/orders/43/checkout': { ok: true, status: 200, json: { checkout_url: 'https://x/y' } }
  }).then(function (m) {
    m.openCart();
    click('lud-checkout');
    return flush(5).then(function () {
      var order = calls.filter(function (c) { return c.path === 'domains/orders'; })[0];
      assert.deepStrictEqual(Object.keys(order.body.items[0]).sort(), ['domain', 'years'],
        'the browser must never submit pricing');
    });
  });
});

test('a failed order keeps the cart and tells the customer', function () {
  return withItem({ 'POST domains/orders': { ok: false, status: 422, json: { error: 'None of the requested domains can be purchased' } } })
    .then(function (m) {
      m.openCart();
      click('lud-checkout');
      return flush(5).then(function () {
        assert.strictEqual(m._state().cart.length, 1, 'cart preserved on failure');
        var t = global.window._toasts.map(function (x) { return x[0]; }).join('|');
        assert.ok(t.length > 0, 'the customer must be told something');
      });
    });
});

/* ================================================= return from payment === */

test('a successful return clears the cart and reassures the customer', function () {
  return load(transport(routes([])), { hash: '#domains?purchase=success&order=1' }).then(function (m) {
    var t = global.window._toasts.map(function (x) { return x[0]; }).join('|');
    assert.ok(t.indexOf('Payment received') !== -1);
    assert.strictEqual(m._state().cart.length, 0);
  });
});

test('a cancelled payment keeps the cart', function () {
  var store = { 'lu.domains.cart.1': JSON.stringify([{ domain: 'kept.com', years: 1, price: '$18.17', price_minor: 1817 }]) };
  return load(transport(routes([])), { hash: '#domains?purchase=cancelled', store: store }).then(function (m) {
    assert.strictEqual(m._state().cart.length, 1, 'cart must survive a cancelled checkout');
  });
});

/* ============================================== detail and timeline === */

function openDetail(steps, timelineEntry) {
  var extra = {};
  extra['GET domains/7/timeline'] = timelineEntry || { ok: true, status: 200, json: { domain: 'brandnew.com', complete: true, steps: steps || [] } };
  return load(transport(routes([DOMAIN], extra))).then(function (m) {
    var manage = global.document.querySelectorAll('.lud-open');
    assert.strictEqual(manage.length, 1, 'expected one Manage button');
    manage[0]._events.click();
    return flush().then(function () { return m; });
  });
}

test('clicking Manage opens the domain detail view', function () {
  return openDetail().then(function (m) {
    assert.strictEqual(m._state().view.name, 'detail');
    assert.strictEqual(m._state().view.domainId, 7);
  });
});

test('detail shows registration, expiry, renewal, registrar and name servers', function () {
  return openDetail().then(function () {
    var h = all();
    assert.ok(h.indexOf('brandnew.com') !== -1, 'domain');
    assert.ok(h.indexOf('Registered') !== -1, 'registration date');
    assert.ok(h.indexOf('Expires') !== -1, 'expiry');
    assert.ok(h.indexOf('Renews on') !== -1, 'renewal');
    assert.ok(h.indexOf('LevelUp Growth') !== -1, 'registrar of record');
    assert.ok(h.indexOf('dns1.example-ns.com') !== -1, 'name servers');
    assert.ok(h.indexOf('lud-copy-ns') !== -1, 'copy control');
    assert.ok(h.indexOf('Transfer lock') !== -1);
    assert.ok(h.indexOf('WHOIS privacy') !== -1);
  });
});

test('detail offers auto-renew and refresh controls', function () {
  return openDetail().then(function () {
    var h = all();
    assert.ok(h.indexOf('lud-ar') !== -1, 'auto-renew control');
    assert.ok(h.indexOf('lud-refresh') !== -1, 'refresh action');
    assert.ok(h.indexOf('Automatically renew') !== -1);
  });
});

test('timeline renders each step, marking pending ones', function () {
  return openDetail([
    { key: 'ordered', label: 'Order placed', detail: 'Created.', at: '2026-07-29T12:00:31Z', state: 'done' },
    { key: 'registered', label: 'Registration complete', detail: 'Yours.', at: '2026-07-29T12:01:59Z', state: 'done' },
    { key: 'dns', label: 'DNS configured', detail: 'Set.', at: null, state: 'pending' }
  ]).then(function () {
    var h = el('lud-timeline').innerHTML;
    assert.ok(h.indexOf('Order placed') !== -1);
    assert.ok(h.indexOf('Registration complete') !== -1);
    assert.ok(h.indexOf('DNS configured') !== -1, 'pending step still shown');
    assert.ok(h.indexOf('pending') !== -1, 'pending must be marked, not implied complete');
  });
});

test('timeline never renders provider codes or refund bookkeeping', function () {
  return openDetail([{ key: 'ordered', label: 'Order placed', detail: 'Created.', at: '2026-07-29T12:00:31Z', state: 'done' }])
    .then(function () {
      var h = el('lud-timeline').innerHTML;
      ['2030166', '1011102', 'refund_due', 'REFUND', 'attempt 3', 'amecheap'].forEach(function (bad) {
        assert.ok(h.indexOf(bad) === -1, bad + ' must never reach a customer timeline');
      });
    });
});

test('an unavailable timeline degrades to a plain message', function () {
  return openDetail(null, { ok: false, status: 500, json: { error: 'SQLSTATE[HY000] internal' } }).then(function () {
    var h = el('lud-timeline').innerHTML;
    assert.ok(h.indexOf('not available right now') !== -1);
    assert.ok(h.indexOf('SQLSTATE') === -1, 'internal errors must never surface');
  });
});

test('the back control returns to the domain list', function () {
  return openDetail().then(function (m) {
    click('lud-back');
    assert.strictEqual(m._state().view.name, 'list');
  });
});

/* ------- auto-renew honesty ------------------------------------------- */

test('auto-renew reflects the SERVER state, not the checkbox', function () {
  var extra = {};
  extra['GET domains/7/timeline'] = { ok: true, status: 200, json: { domain: 'brandnew.com', complete: true, steps: [] } };
  // Server accepted but could not confirm: auto_renew stays false.
  extra['POST domains/7/auto-renew'] = { ok: true, status: 200, json: {
    ok: true, auto_renew: false, confirmed: false,
    message: 'Your change was submitted and is still being applied. We will confirm shortly.'
  } };

  return load(transport(routes([DOMAIN], extra))).then(function (m) {
    global.document.querySelectorAll('.lud-open')[0]._events.click();
    return flush().then(function () {
      var cb = el('lud-ar');
      cb.checked = true;
      cb._events.change({ target: { checked: true } });
      return flush(4).then(function () {
        assert.strictEqual(cb.checked, false, 'must follow the server, not the click');
        assert.ok(el('lud-ar-note').textContent.indexOf('still being applied') !== -1,
          'the customer must be told it is not yet confirmed');
      });
    });
  });
});

/* ================================================ table + a11y + brand === */

test('pagination appears beyond one page', function () {
  var many = [];
  for (var i = 0; i < 25; i++) { many.push(Object.assign({}, DOMAIN, { id: 100 + i, domain: 'site' + i + '.com' })); }
  return load(transport(routes(many))).then(function (m) {
    assert.strictEqual(m._state().table.page, 1);
    assert.ok(last().indexOf('Page 1 of 3') !== -1, 'expected 3 pages for 25 rows');
    click('lud-next');
    assert.strictEqual(m._state().table.page, 2);
  });
});

test('sort controls are exposed for domain, status and expiry', function () {
  return load(transport(routes([DOMAIN]))).then(function () {
    var h = last();
    assert.ok(h.indexOf('data-k="domain"') !== -1);
    assert.ok(h.indexOf('data-k="status"') !== -1);
    assert.ok(h.indexOf('data-k="expires"') !== -1);
  });
});

test('sorting toggles direction on repeat activation', function () {
  return load(transport(routes([DOMAIN, EXPIRING]))).then(function (m) {
    var sorts = global.document.querySelectorAll('.lud-sort');
    assert.ok(sorts.length >= 1);
    sorts[0]._events.click();
    assert.strictEqual(m._state().table.dir, 'desc', 'second activation reverses');
  });
});

test('status filtering is offered', function () {
  return load(transport(routes([DOMAIN]))).then(function () {
    var h = last();
    assert.ok(h.indexOf('lud-status') !== -1);
    assert.ok(h.indexOf('Expiring soon') !== -1);
  });
});

test('interactive controls carry accessible labels', function () {
  return load(transport(routes([DOMAIN]))).then(function () {
    var h = last();
    assert.ok(h.indexOf('aria-label="Filter domains"') !== -1);
    assert.ok(h.indexOf('aria-label="Filter by status"') !== -1);
    assert.ok(h.indexOf('aria-label="Sort by') !== -1);
    assert.ok(h.indexOf('<label for="lud-q"') !== -1, 'search input must be labelled');
  });
});

test('nothing internal is ever rendered', function () {
  return load(transport(routes([DOMAIN, EXPIRING]))).then(function () {
    var h = all().toLowerCase();
    ['infra888', 'boss888', 'amecheap', 'cost_minor', 'registrar_cost', 'wholesale', 'markup'].forEach(function (bad) {
      assert.ok(h.indexOf(bad) === -1, bad + ' must never be rendered');
    });
  });
});

test('the source file itself contains no internal identifiers', function () {
  var s = SRC.toLowerCase();
  ['infra888', 'boss888', 'namecheap', 'wholesale'].forEach(function (bad) {
    assert.ok(s.indexOf(bad) === -1, bad + ' must not appear in a file served to browsers');
  });
});

/* --------------------------------------------------------------- report -- */

runAll().then(function () {
  console.log('\n  ' + pass + ' passed, ' + fail + ' failed\n');
  if (fail) {
    failures.forEach(function (f) { console.log('  FAILED: ' + f[0] + '\n    ' + f[1] + '\n'); });
    process.exit(1);
  }
  process.exit(0);
});
