/**
 * LevelUp Growth — Business Email (customer portal).
 *
 * The customer-facing Business Email experience: overview, domain setup,
 * mailboxes, aliases, forwarding, storage and health.
 *
 * This module owns NO business logic. Every status, limit, warning, health
 * verdict and piece of wording comes from the API. Nothing is computed here
 * that a server already decided — in particular the browser never sends a
 * workspace id, never decides whether an action is permitted, and never decides
 * that something succeeded.
 *
 * BRANDING: the underlying mail service is never named, and there is nothing in
 * this file that could name it. The customer's Business Email provider is
 * LevelUp Growth.
 *
 * DESIGN: rendered through helpers handed to it by the infrastructure module
 * (pageShell, card, button, statusPill …), so the page frame, spacing and
 * typography are identical to Domains and Hosting rather than a lookalike
 * maintained twice.
 *
 * GATE: when the API reports the portal unavailable, this renders the SAME
 * coming-soon empty state customers see today. E4 changes nothing observable
 * until the feature is switched on.
 */
(function () {
  'use strict';

  var ctx = null;
  var _view = { name: 'overview', domainId: null, mailboxId: null };
  var _cache = {};
  var _busy = false;

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

  function api(path) {
    return ctx.req('GET', 'infrastructure/business-email' + path);
  }

  function act(domainId, action, body) {
    return ctx.req('POST', 'infrastructure/business-email/domains/' + domainId + '/actions/' + action, body || {});
  }

  /* Status pills come from the server's own vocabulary. The browser maps tone
   * to colour and nothing else — it never decides what state something is in. */
  var TONE = {
    good: '#34D399', progress: '#60A5FA', attention: '#F59E0B', neutral: '#6B7280'
  };

  function statusChip(status) {
    if (!status) { return ''; }
    var c = TONE[status.tone] || TONE.neutral;
    return '<span style="background:' + c + '22;color:' + c + ';border-radius:4px;padding:2px 9px;font-size:11px;'
      + 'font-weight:600;white-space:nowrap">' + esc(status.label) + '</span>';
  }

  function hint(text) {
    return text ? '<div style="font-size:12px;color:var(--muted);margin-top:4px">' + esc(text) + '</div>' : '';
  }

  /* "Not reported" must never render as zero. The API sends null for a measure
   * nobody has taken, and that distinction survives to the screen. */
  function measure(v, suffix) {
    if (v === null || v === undefined) {
      return '<span style="color:var(--muted)" title="not measured yet">&mdash;</span>';
    }
    return esc(v) + (suffix || '');
  }

  function allowanceBar(a, unit) {
    if (!a || a.limit === null) {
      return '<span style="font-size:12px;color:var(--muted)">' + esc(a ? a.used : 0) + (unit || '') + ' used</span>';
    }
    var pct = Math.min(100, a.percent || 0);
    var col = a.at_limit ? '#F87171' : (pct >= 80 ? '#F59E0B' : '#34D399');
    return '<div style="font-size:12px;color:var(--muted);margin-bottom:4px">'
      + esc(a.used) + (unit || '') + ' of ' + esc(a.limit) + (unit || '') + ' used</div>'
      + '<div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden">'
      + '<div style="height:100%;width:' + pct + '%;background:' + col + '"></div></div>';
  }

  function warningList(warnings) {
    if (!warnings || !warnings.length) { return ''; }
    var out = '';
    warnings.forEach(function (w) {
      var c = w.severity === 'high' ? '#F87171' : (w.severity === 'medium' ? '#F59E0B' : '#60A5FA');
      out += '<div style="border-left:3px solid ' + c + ';padding:8px 12px;margin-bottom:8px;background:' + c + '10">'
        + '<div style="font-size:13px">' + esc(w.message) + '</div>'
        + (w.action ? '<div style="font-size:12px;color:var(--muted);margin-top:3px">' + esc(w.action) + '</div>' : '')
        + '</div>';
    });
    return out;
  }

  /* A TITLED CARD, WHICH ctx.card IS NOT.
   *
   * The shared helper is card(inner, extra) — `extra` is spliced INSIDE the
   * card's style attribute. Calling it as card(title, body) silently pushed
   * every panel's markup into a style attribute; the browser bailed out at the
   * first quote and the pages only looked right by accident. Titles were lost
   * and a stray `">` rendered as visible text. One helper, called correctly. */
  function panel(title, inner) {
    var head = title
      ? '<div style="font:600 13px var(--fb);color:var(--t1);margin-bottom:10px">' + esc(title) + '</div>'
      : '';

    return ctx.card ? ctx.card(head + inner) : head + inner;
  }

  /* ------------------------------------------------------------ shell ----- */

  function shell(title, desc, body, crumbs) {
    return ctx.pageShell({
      breadcrumb: crumbs || [{ label: 'Hosting' }, { label: 'Email Accounts' }],
      title: title,
      desc: desc,
      body: body
    });
  }

  function tabs(active) {
    var items = [
      ['overview', 'Overview'], ['mailboxes', 'Mailboxes'], ['aliases', 'Aliases'],
      ['forwarding', 'Forwarding'], ['usage', 'Storage & Usage'], ['health', 'Health']
    ];
    var out = '<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:18px;border-bottom:1px solid var(--border)">';
    items.forEach(function (it) {
      var on = it[0] === active;
      out += '<button class="be-tab" data-tab="' + it[0] + '" style="padding:9px 14px;background:none;border:none;'
        + 'border-bottom:2px solid ' + (on ? 'var(--accent,#60A5FA)' : 'transparent') + ';color:'
        + (on ? 'inherit' : 'var(--muted)') + ';font-size:13px;font-weight:' + (on ? '600' : '400') + ';cursor:pointer">'
        + esc(it[1]) + '</button>';
    });
    return out + '</div>';
  }

  function bindTabs() {
    document.querySelectorAll('.be-tab').forEach(function (b) {
      b.addEventListener('click', function () { go(b.getAttribute('data-tab')); });
    });
  }

  function go(name, id) {
    _view = { name: name, domainId: name === 'setup' || name === 'domain' ? id : _view.domainId, mailboxId: name === 'mailbox' ? id : null };
    render();
  }

  /* --------------------------------------------------------- unavailable -- */

  /* The gate is closed. Render exactly what customers see today — the same
   * coming-soon state, not an error and not a broken screen. */
  function renderUnavailable(reason) {
    ctx.paintBody(shell('Email Accounts',
      'Professional email on your own domain, for you and your team.',
      ctx.enterpriseEmpty(ctx.ICONS.email, 'Business email is coming soon',
        'Create mailboxes like you@yourdomain.com for everyone on your team. Business email is set up on a domain you’ve connected.',
        [{ label: 'Connect a domain first', id: 'be-goto-domains' }],
        'Prerequisite: a connected custom domain. We’ll let you know the moment email is available.')));

    on('be-goto-domains', 'click', function () {
      if (window.infraOpenTab) { window.infraOpenTab('domains'); }
    });
  }

  function renderError(status) {
    ctx.paintBody(shell('Email Accounts', '',
      ctx.errorState
        ? ctx.errorState('We could not load Business Email just now.', 'Please try again in a moment.')
        : '<div style="padding:40px;text-align:center;color:var(--muted)">We could not load Business Email just now.</div>'));
  }

  /* ------------------------------------------------------------- views ---- */

  function render() {
    ctx.paintBody(shell('Email Accounts', '', ctx.loadingState ? ctx.loadingState() :
      '<div style="padding:40px;text-align:center;color:var(--muted)">Loading&hellip;</div>'));

    var name = _view.name;

    if (name === 'setup') { return renderSetup(); }
    if (name === 'mailboxes') { return renderMailboxes(); }
    if (name === 'mailbox') { return renderMailbox(); }
    if (name === 'aliases') { return renderAliases(); }
    if (name === 'forwarding') { return renderForwarding(); }
    if (name === 'usage') { return renderUsage(); }
    if (name === 'health') { return renderHealth(); }
    return renderOverview();
  }

  function renderOverview() {
    api('/overview').then(function (r) {
      if (r.status === 404 && r.json && r.json.available === false) { return renderUnavailable(r.json.reason); }
      if (r.status === 403) { return renderNotEntitled(r.json && r.json.reason); }
      if (!r.ok) { return renderError(r.status); }

      var d = r.json, s = d.summary || {}, a = d.allowances || {};
      var body = '';

      // Remember which domain the mailbox/alias/forwarding tabs act on. E4's
      // allowance is one domain per workspace, so the first is the only one;
      // when that limit rises this becomes an explicit selector rather than a
      // silent default.
      if (!_view.domainId && (d.domains || []).length) { _view.domainId = d.domains[0].id; }

      if (!(d.domains || []).length) {
        body = ctx.enterpriseEmpty(ctx.ICONS.email, 'No email set up yet',
          'Business Email is set up on a domain you have already connected.',
          [{ label: 'Go to Domains', id: 'be-goto-domains' }],
          'Once a domain is connected you can add the DNS records and create mailboxes.');
        ctx.paintBody(shell('Email Accounts', 'Professional email on your own domain.', body));
        on('be-goto-domains', 'click', function () { if (window.infraOpenTab) { window.infraOpenTab('domains'); } });
        return;
      }

      body += tabs('overview');

      if (s.action_required > 0) {
        body += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;'
          + 'padding:14px 16px;margin-bottom:16px;font-size:13px"><b>' + esc(s.action_required) + '</b> '
          + (s.action_required === 1 ? 'domain needs' : 'domains need') + ' your attention.</div>';
      }

      body += ctx.metricStrip ? ctx.metricStrip([
        { label: 'Domains', value: String(s.domain_count || 0) },
        { label: 'Mailboxes', value: String(s.mailbox_count || 0) },
        { label: 'Storage used', value: (s.storage_used_mb || 0) + ' MB' },
        { label: 'Needs attention', value: String(s.action_required || 0) }
      ]) : '';

      var rows = '';
      (d.domains || []).forEach(function (dom) {
        rows += '<div style="display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--border)">'
          + '<div style="flex:1"><div style="font-weight:600;font-size:14px">' + esc(dom.domain) + '</div>'
          + '<div style="font-size:12px;color:var(--muted);margin-top:2px">' + esc(dom.mailbox_count) + ' mailbox'
          + (dom.mailbox_count === 1 ? '' : 'es')
          + (dom.last_checked ? ' &middot; checked ' + esc(ctx.fmtTime(dom.last_checked)) : '') + '</div></div>'
          + statusChip(dom.status)
          + '<button class="be-open-setup" data-id="' + esc(dom.id) + '" style="padding:6px 12px;border-radius:6px;'
          + 'border:1px solid var(--border);background:none;color:inherit;font-size:12px;cursor:pointer">Setup</button>'
          + '</div>'
          + '<div style="font-size:12px;color:var(--muted);padding-bottom:12px">' + esc(dom.status.detail) + '</div>';
      });

      body += panel('Your domains', rows);

      body += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px">'
        + '<div style="flex:1;min-width:220px;border:1px solid var(--border);border-radius:8px;padding:14px">'
        + '<div style="font-size:12px;font-weight:600;margin-bottom:8px">Mailboxes</div>' + allowanceBar(a.mailboxes, '') + '</div>'
        + '<div style="flex:1;min-width:220px;border:1px solid var(--border);border-radius:8px;padding:14px">'
        + '<div style="font-size:12px;font-weight:600;margin-bottom:8px">Storage</div>' + allowanceBar(a.storage, ' MB') + '</div>'
        + '</div>';

      ctx.paintBody(shell('Email Accounts', 'Professional email on your own domain.', body));
      bindTabs();

      document.querySelectorAll('.be-open-setup').forEach(function (b) {
        b.addEventListener('click', function () { go('setup', parseInt(b.getAttribute('data-id'), 10)); });
      });
    });
  }

  function renderNotEntitled(reason) {
    ctx.paintBody(shell('Email Accounts', '',
      ctx.enterpriseEmpty(ctx.ICONS.email, 'Business Email is not on your plan',
        esc(reason || 'Business Email is not included in your plan.'),
        [], 'Talk to LevelUp Growth about adding it.')));
  }

  function renderSetup() {
    var id = _view.domainId;

    api('/domains/' + id + '/setup').then(function (r) {
      if (r.status === 404 && r.json && r.json.available === false) { return renderUnavailable(); }
      if (!r.ok || !r.json || !r.json.success) { return renderError(r.status); }

      var s = r.json.setup, body = tabs('overview');

      body += '<div style="margin-bottom:14px"><button id="be-back" style="background:none;border:none;color:#60A5FA;'
        + 'font-size:13px;cursor:pointer;padding:0">&larr; Back to overview</button></div>';

      body += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">'
        + '<span style="font-size:15px;font-weight:600">Domain setup</span>' + statusChip(s.status) + '</div>'
        + '<div style="font-size:13px;color:var(--muted);margin-bottom:16px">' + esc(s.status.detail) + '</div>';

      var rows = '';
      (s.records || []).forEach(function (rec, i) {
        rows += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:10px">'
          + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">'
          + '<span style="font-weight:600;font-size:13px">' + esc(rec.label) + '</span>'
          + '<span style="font-size:11px;color:var(--muted);border:1px solid var(--border);border-radius:3px;padding:1px 6px">'
          + esc(rec.technical) + '</span>'
          + (rec.required ? '' : '<span style="font-size:11px;color:var(--muted)">optional</span>')
          + '</div>'
          + field('Type', rec.type) + field('Name', rec.name) + field('Value', rec.value, 'be-copy-' + i)
          + (rec.priority !== null && rec.priority !== undefined ? field('Priority', rec.priority) : '')
          + (rec.ttl ? field('TTL', rec.ttl) : '')
          + '</div>';
      });

      if (!rows) {
        rows = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">'
          + 'No records yet. Start setup to get the records for this domain.</div>';
      }

      body += panel('DNS records to add', rows);
      body += hint(s.instructions);

      body += '<div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">'
        + '<button id="be-start" style="padding:9px 16px;border-radius:6px;border:1px solid var(--border);'
        + 'background:none;color:inherit;font-size:13px;cursor:pointer">Start setup</button>'
        + '<button id="be-check" style="padding:9px 16px;border-radius:6px;border:1px solid #60A5FA55;'
        + 'background:#60A5FA18;color:#60A5FA;font-size:13px;cursor:pointer">Check DNS</button></div>'
        + '<div id="be-result" style="margin-top:12px"></div>';

      // Verification comes from evidence. There is deliberately no "I have
      // added them" control that changes state — a customer asserting they
      // added a record is not the same as the record existing.
      body += hint('We confirm your records by checking DNS. Marking them added yourself does not verify them.');

      ctx.paintBody(shell('Email Accounts', '', body,
        [{ label: 'Hosting' }, { label: 'Email Accounts' }, { label: 'Domain setup' }]));

      bindTabs();
      on('be-back', 'click', function () { go('overview'); });
      on('be-start', 'click', function () { runAction(id, 'start-setup', {}, function () { go('setup', id); }); });
      on('be-check', 'click', function () { runAction(id, 'check-dns', {}, function () { go('setup', id); }); });

      (s.records || []).forEach(function (rec, i) {
        on('be-copy-' + i, 'click', function () {
          if (navigator.clipboard) { navigator.clipboard.writeText(rec.value); toast('Copied', 'success'); }
        });
      });
    });
  }

  function field(label, value, copyId) {
    return '<div style="display:flex;gap:10px;padding:5px 0;font-size:12px;align-items:flex-start">'
      + '<span style="color:var(--muted);width:70px;flex-shrink:0">' + esc(label) + '</span>'
      + '<code style="flex:1;word-break:break-all">' + esc(value) + '</code>'
      + (copyId ? '<button id="' + copyId + '" style="background:none;border:1px solid var(--border);border-radius:4px;'
        + 'color:var(--muted);font-size:11px;padding:2px 8px;cursor:pointer">Copy</button>' : '')
      + '</div>';
  }

  function renderMailboxes() {
    api('/mailboxes').then(function (r) {
      if (r.status === 404 && r.json && r.json.available === false) { return renderUnavailable(); }
      if (!r.ok) { return renderError(r.status); }

      var d = r.json, body = tabs('mailboxes');
      var actions = d.actions || {};

      body += '<div style="margin-bottom:14px">' + allowanceBar(d.allowance, '') + '</div>';

      var rows = '';
      (d.mailboxes || []).forEach(function (m) {
        rows += '<div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)">'
          + '<div style="flex:1"><div style="font-weight:600;font-size:13px">' + esc(m.address || m.local_part) + '</div>'
          + '<div style="font-size:12px;color:var(--muted)">' + esc(m.display_name || '') + '</div></div>'
          + '<div style="font-size:12px;color:var(--muted);width:120px">'
          + measure(m.storage_used_mb, ' MB') + ' / ' + esc(m.quota_mb) + ' MB</div>'
          + statusChip(m.status)
          + '<button class="be-open-mb" data-id="' + esc(m.id) + '" style="padding:5px 11px;border-radius:6px;'
          + 'border:1px solid var(--border);background:none;color:inherit;font-size:12px;cursor:pointer">Open</button>'
          + '</div>';
      });

      if (!rows) {
        rows = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No mailboxes yet.</div>';
      }

      body += panel('Mailboxes', rows);

      // The create form only appears when the server says the action is
      // available. An action we would predictably refuse is never offered.
      if (actions.mailbox_create && !(d.allowance && d.allowance.at_limit)) {
        body += panel('Add a mailbox', createForm());
      } else if (d.allowance && d.allowance.at_limit) {
        body += hint('You have used all the mailboxes included in your plan.');
      }

      body += '<div id="be-result" style="margin-top:12px"></div>';

      ctx.paintBody(shell('Email Accounts', '', body));
      bindTabs();

      document.querySelectorAll('.be-open-mb').forEach(function (b) {
        b.addEventListener('click', function () { go('mailbox', parseInt(b.getAttribute('data-id'), 10)); });
      });

      on('be-create', 'click', function () {
        var local = (h('be-local') || {}).value || '';
        var name = (h('be-name') || {}).value || '';
        runAction(_view.domainId, 'create-mailbox', { local_part: local, display_name: name },
          function () { go('mailboxes'); });
      });
    });
  }

  function createForm() {
    return '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">'
      + '<div style="flex:1;min-width:160px"><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Address</label>'
      + '<input id="be-local" placeholder="sales" style="width:100%;padding:8px;border:1px solid var(--border);'
      + 'border-radius:6px;background:transparent;color:inherit;font-size:13px"></div>'
      + '<div style="flex:1;min-width:160px"><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Display name</label>'
      + '<input id="be-name" placeholder="Sales Team" style="width:100%;padding:8px;border:1px solid var(--border);'
      + 'border-radius:6px;background:transparent;color:inherit;font-size:13px"></div>'
      + '<button id="be-create" style="padding:9px 16px;border-radius:6px;border:1px solid #34D39955;'
      + 'background:#34D39918;color:#34D399;font-size:13px;cursor:pointer">Create</button></div>';
  }

  function renderMailbox() {
    var id = _view.mailboxId;

    api('/mailboxes/' + id).then(function (r) {
      if (!r.ok || !r.json || !r.json.success) { return renderError(r.status); }

      var m = r.json.mailbox, actions = r.json.actions || {}, body = tabs('mailboxes');

      body += '<div style="margin-bottom:14px"><button id="be-back" style="background:none;border:none;color:#60A5FA;'
        + 'font-size:13px;cursor:pointer;padding:0">&larr; All mailboxes</button></div>';

      body += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">'
        + '<span style="font-size:16px;font-weight:600">' + esc(m.address) + '</span>' + statusChip(m.status) + '</div>';

      var rows = '<table style="width:100%;font-size:13px;border-collapse:collapse">'
        + row('Display name', esc(m.display_name || '—'))
        + row('Status', esc(m.status.detail))
        + row('Storage used', measure(m.storage_used_mb, ' MB'))
        + row('Included storage', esc(m.quota_mb) + ' MB')
        + row('Used', m.quota_used_percent === null ? '<span style="color:var(--muted)">not measured yet</span>' : esc(m.quota_used_percent) + '%')
        + row('Last activity', m.last_activity ? esc(ctx.fmtTime(m.last_activity)) : '—')
        + '</table>';

      body += panel('Mailbox', rows);

      // Password: state the truth rather than implying a secret exists.
      body += panel('Password',
        '<div style="font-size:13px;line-height:1.7">' + esc(r.json.password.note) + '</div>'
        + (actions.password_reset
          ? '<button id="be-reset" style="margin-top:10px;padding:8px 14px;border-radius:6px;border:1px solid var(--border);'
            + 'background:none;color:inherit;font-size:13px;cursor:pointer">Request password reset</button>' : ''));

      var controls = '';
      if (actions.mailbox_suspend && m.status.state === 'active') {
        controls += btn('be-suspend', 'Pause mailbox', '#F59E0B');
      }
      if (actions.mailbox_restore && m.status.state === 'suspended' && m.can_restore) {
        controls += btn('be-restore', 'Resume mailbox', '#34D399');
      }
      if (actions.mailbox_delete) {
        controls += btn('be-delete', 'Request removal', '#F87171');
      }
      if (controls) { body += panel('Manage', '<div style="display:flex;gap:8px;flex-wrap:wrap">' + controls + '</div>'); }

      var tl = r.json.timeline || [];
      var tlHtml = tl.length
        ? tl.map(function (t) {
            return '<div style="display:flex;gap:10px;padding:6px 0;font-size:12px;border-bottom:1px solid var(--border)">'
              + '<span style="color:var(--muted);width:130px;flex-shrink:0">' + esc(ctx.fmtTime(t.at)) + '</span>'
              + '<span>' + esc(t.message) + '</span></div>';
          }).join('')
        : '<div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">Nothing yet.</div>';

      body += panel('History', tlHtml);
      body += '<div id="be-result" style="margin-top:12px"></div>';

      ctx.paintBody(shell('Email Accounts', '', body,
        [{ label: 'Hosting' }, { label: 'Email Accounts' }, { label: esc(m.address) }]));

      bindTabs();
      on('be-back', 'click', function () { go('mailboxes'); });
      on('be-reset', 'click', function () { runAction(_view.domainId, 'reset-password', { id: id }); });
      on('be-suspend', 'click', function () { runAction(_view.domainId, 'suspend-mailbox', { id: id }, function () { go('mailbox', id); }); });
      on('be-restore', 'click', function () { runAction(_view.domainId, 'restore-mailbox', { id: id }, function () { go('mailbox', id); }); });
      on('be-delete', 'click', function () {
        window.luConfirm('Remove ' + m.address + '?', 'This cannot be undone once completed, and mail in the mailbox is lost.', { okLabel: 'Request removal', cancelLabel: 'Keep mailbox', danger: true })
          .then(function (ok) { if (!ok) { return; } runAction(_view.domainId, 'delete-mailbox', { id: id }); });
      });
    });
  }

  function row(k, v) {
    return '<tr><td style="padding:6px 0;color:var(--muted);width:170px;vertical-align:top">' + esc(k)
      + '</td><td style="padding:6px 0">' + v + '</td></tr>';
  }

  function btn(id, label, colour) {
    return '<button id="' + id + '" style="padding:8px 14px;border-radius:6px;border:1px solid ' + colour + '55;'
      + 'background:' + colour + '18;color:' + colour + ';font-size:13px;cursor:pointer">' + esc(label) + '</button>';
  }

  function renderAliases() {
    api('/aliases').then(function (r) {
      if (!r.ok) { return renderError(r.status); }
      var d = r.json, body = tabs('aliases');

      body += '<div style="margin-bottom:14px">' + allowanceBar(d.allowance, '') + '</div>';

      var rows = (d.aliases || []).map(function (a) {
        return '<div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--border)">'
          + '<div style="flex:1;font-size:13px"><b>' + esc(a.address || a.local_part) + '</b>'
          + '<div style="font-size:12px;color:var(--muted)">delivers to ' + esc(a.delivers_to || '—') + '</div></div>'
          + statusChip(a.status) + '</div>';
      }).join('') || '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No aliases yet.</div>';

      body += panel('Aliases', rows)
        + hint('An alias is another address that delivers into an existing mailbox.');

      ctx.paintBody(shell('Email Accounts', '', body));
      bindTabs();
    });
  }

  function renderForwarding() {
    api('/forwarders').then(function (r) {
      if (!r.ok) { return renderError(r.status); }
      var d = r.json, body = tabs('forwarding');

      body += '<div style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.3);border-radius:8px;'
        + 'padding:12px 14px;margin-bottom:14px;font-size:12px">' + esc(d.notice) + '</div>';

      body += '<div style="margin-bottom:14px">' + allowanceBar(d.allowance, '') + '</div>';

      var rows = (d.forwarders || []).map(function (f) {
        return '<div style="padding:11px 0;border-bottom:1px solid var(--border)">'
          + '<div style="display:flex;align-items:center;gap:12px">'
          + '<div style="flex:1;font-size:13px"><b>' + esc(f.address || f.local_part) + '</b>'
          + '<div style="font-size:12px;color:var(--muted)">forwards to ' + esc(f.forwards_to) + '</div></div>'
          + statusChip(f.status) + '</div>'
          + (f.delivery_warning
            ? '<div style="font-size:12px;color:#F59E0B;margin-top:6px">' + esc(f.delivery_warning) + '</div>' : '')
          + '</div>';
      }).join('') || '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No forwarding rules yet.</div>';

      body += panel('Forwarding', rows);

      ctx.paintBody(shell('Email Accounts', '', body));
      bindTabs();
    });
  }

  function renderUsage() {
    api('/usage').then(function (r) {
      if (!r.ok) { return renderError(r.status); }
      var d = r.json, body = tabs('usage');

      if (d.stale) {
        body += '<div style="background:rgba(107,114,128,.12);border:1px solid var(--border);border-radius:8px;'
          + 'padding:12px 14px;margin-bottom:14px;font-size:12px;color:var(--muted)">' + esc(d.stale_note) + '</div>';
      }

      if (d.approaching_limit) {
        body += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;'
          + 'padding:12px 14px;margin-bottom:14px;font-size:13px">You are approaching your included storage.</div>';
      }

      body += panel('Storage', allowanceBar(d.storage, ' MB')
        + (d.last_synchronized ? hint('Last updated ' + ctx.fmtTime(d.last_synchronized)) : hint('Not measured yet.')));

      var rows = (d.per_mailbox || []).map(function (m) {
        return '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px">'
          + '<div style="flex:1">' + esc(m.address) + '</div>'
          + '<div style="width:150px;color:var(--muted);font-size:12px">' + measure(m.storage_used_mb, ' MB')
          + ' / ' + esc(m.quota_mb) + ' MB</div>'
          + '<div style="width:60px;text-align:right;font-size:12px">'
          + (m.quota_used_percent === null ? '<span style="color:var(--muted)">&mdash;</span>' : esc(m.quota_used_percent) + '%')
          + '</div></div>';
      }).join('') || '<div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">No mailboxes yet.</div>';

      body += panel('Per mailbox', rows);

      ctx.paintBody(shell('Email Accounts', '', body));
      bindTabs();
    });
  }

  function renderHealth() {
    api('/health').then(function (r) {
      if (!r.ok) { return renderError(r.status); }
      var body = tabs('health');

      (r.json.domains || []).forEach(function (d) {
        var hh = d.health;
        var tone = hh.status === 'healthy' ? '#34D399'
          : (hh.status === 'action_required' ? '#F87171'
          : (hh.status === 'pending_verification' ? '#F59E0B' : '#6B7280'));

        var inner = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">'
          + '<span style="font-weight:600;font-size:14px">' + esc(d.domain) + '</span>'
          + '<span style="background:' + tone + '22;color:' + tone + ';border-radius:4px;padding:2px 9px;font-size:11px;font-weight:600">'
          + esc(hh.label) + '</span></div>'
          + '<div style="font-size:13px;color:var(--muted);margin-bottom:12px">' + esc(hh.detail) + '</div>';

        inner += warningList(hh.warnings);

        inner += '<table style="width:100%;font-size:13px;border-collapse:collapse;margin-top:8px">';
        (hh.checks || []).forEach(function (c) {
          var col = c.status === 'ok' ? '#34D399' : (c.status === 'not_found' ? '#F87171' : '#6B7280');
          var word = c.status === 'ok' ? 'Active' : (c.status === 'not_found' ? 'Not found' : 'Not checked');
          inner += '<tr style="border-bottom:1px solid var(--border)">'
            + '<td style="padding:8px 0"><b>' + esc(c.label) + '</b> '
            + '<span style="font-size:11px;color:var(--muted);border:1px solid var(--border);border-radius:3px;padding:1px 5px">'
            + esc(c.technical) + '</span>'
            + '<div style="font-size:12px;color:var(--muted);margin-top:2px">' + esc(c.why) + '</div></td>'
            + '<td style="padding:8px 0;text-align:right;color:' + col + ';font-size:12px;white-space:nowrap">' + esc(word) + '</td></tr>';
        });
        inner += '</table>';

        inner += '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-top:10px">'
          + '<span>' + esc(hh.service.label) + ': ' + (hh.service.available ? 'available' : 'temporarily unavailable') + '</span>'
          + '<span>' + (hh.last_checked ? 'Last checked ' + esc(ctx.fmtTime(hh.last_checked)) : 'Not checked yet') + '</span></div>';

        body += panel(null, inner);
      });

      ctx.paintBody(shell('Email Accounts', '', body));
      bindTabs();
    });
  }

  /* ------------------------------------------------------------ actions --- */

  /* The browser never decides an action succeeded. Every word shown here comes
   * from the server's own response. */
  function runAction(domainId, action, body, onDone) {
    if (_busy) { return; }
    _busy = true;

    var out = h('be-result');
    if (out) { out.innerHTML = '<span style="font-size:13px;color:var(--muted)">Working&hellip;</span>'; }

    act(domainId, action, body).then(function (r) {
      _busy = false;
      var j = r.json || {};
      var colour = j.state === 'done' ? '#34D399'
        : (j.state === 'pending' || j.state === 'awaiting_review' || j.state === 'under_review' ? '#F59E0B' : '#F87171');

      if (out) {
        out.innerHTML = '<div style="color:' + colour + ';font-size:13px">' + esc(j.message || 'Done.') + '</div>';
      }

      toast(j.message || 'Done', j.state === 'done' ? 'success' : 'info');

      // Only refresh on a confirmed change. Refreshing on "pending" would
      // repaint a screen that has not changed yet and read as a failure.
      if (j.state === 'done' && typeof onDone === 'function') { setTimeout(onDone, 600); }
    });
  }

  /* -------------------------------------------------------------- mount --- */

  window.luBusinessEmail = {
    mount: function (injected) {
      ctx = injected;
      _view = { name: 'overview', domainId: null, mailboxId: null };
      render();
    }
  };
})();
