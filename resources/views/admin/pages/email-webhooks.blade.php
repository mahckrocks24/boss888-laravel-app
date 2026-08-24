{{--
    EMAIL888 EM-6 — Webhook Ingress.

    The delivery ledger answers "what happened to this message". This page
    answers the question underneath it: is the channel that TELLS us what
    happened actually working, and if not, since when, and whose fault.

    Every figure is read back from email_webhook_receipts and the ledger. The
    health verdict is allowed to say "idle" and "unassessable" — a webhook page
    that reports green because nothing has happened would convert an absence of
    evidence into a positive claim, which is the exact defect family this
    subsystem exists to eliminate.

    All interpolation goes through esc(). Every string on this page can be
    influenced by whoever called the endpoint, including a stranger.
--}}
<div class="wh-wrap">
  <style>
    .wh-wrap{font-size:14px}
    .wh-health{padding:14px 16px;border-radius:10px;border:1px solid #eee;margin-bottom:16px;background:#fff}
    .wh-verdict{display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
    .wh-healthy{background:#e6f7ec;color:#036d33}
    .wh-degraded{background:#fdecec;color:#9b1c1c}
    .wh-idle{background:#f5f5f5;color:#555}
    .wh-unassessable{background:#fff7e6;color:#92400e}
    .wh-health ul{margin:10px 0 0;padding-left:18px}
    .wh-health li{margin:4px 0;color:#444}
    .wh-facts{display:flex;gap:18px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0}
    .wh-fact b{display:block;font-size:18px;line-height:1.2}
    .wh-fact span{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666}
    .wh-sum{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
    .wh-chip{padding:8px 14px;border-radius:8px;background:#f5f5f5;cursor:pointer;border:1px solid transparent}
    .wh-chip.on{border-color:#F59E0B;background:#fff7e6}
    .wh-chip b{display:block;font-size:20px;line-height:1.1}
    .wh-chip span{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666}
    .wh-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
    .wh-filters input,.wh-filters select{padding:7px 9px;border:1px solid #ddd;border-radius:6px;font-size:13px}
    .wh-filters input[type=search]{min-width:280px}
    table.wh{width:100%;border-collapse:collapse}
    table.wh th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666;padding:8px;border-bottom:2px solid #eee}
    table.wh td{padding:8px;border-bottom:1px solid #f2f2f2;vertical-align:top}
    table.wh tr:hover{background:#fafafa;cursor:pointer}
    .st{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
    .st-accepted{background:#e6f7ec;color:#036d33}
    .st-refused{background:#fdecec;color:#9b1c1c}
    .st-ours{background:#fff7e6;color:#92400e}
    .wh-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
    .wh-muted{color:#888}
    .wh-panel{margin-top:18px;padding:16px;border:1px solid #eee;border-radius:10px;background:#fff}
    .wh-tl{list-style:none;padding:0;margin:0}
    .wh-tl li{padding:8px 0 8px 18px;border-left:2px solid #eee;position:relative}
    .wh-tl li:before{content:'';position:absolute;left:-5px;top:14px;width:8px;height:8px;border-radius:50%;background:#F59E0B}
    .wh-tl li.ok:before{background:#036d33}
    .wh-tl li.bad:before{background:#9b1c1c}
    .wh-kv{display:grid;grid-template-columns:200px 1fr;gap:6px 14px}
    .wh-kv dt{color:#666;font-size:12px}
    .wh-kv dd{margin:0;font-size:13px;word-break:break-all}
    .wh-note{margin-top:14px;padding:12px;border-radius:8px;background:#fafafa;border:1px solid #eee;font-size:13px}
    @media (prefers-color-scheme:dark){
      .wh-health,.wh-panel{background:#151922;border-color:#232833}
      .wh-health li{color:#bbb}.wh-facts{border-color:#232833}
      .wh-chip{background:#1f2430}.wh-chip span{color:#9aa}
      table.wh td{border-color:#232833}table.wh tr:hover{background:#1b1f28}
      .wh-note{background:#11141b;border-color:#232833}
    }
  </style>

  <div class="wh-health" id="whHealth">Loading health…</div>

  <div class="wh-sum" id="whSummary"></div>

  <div class="wh-filters">
    <input type="search" id="whSearch" placeholder="MessageID, request ID, client IP, reason text…">
    <select id="whAuth"><option value="">any authentication result</option></select>
    <select id="whProc"><option value="">any processing result</option></select>
    <select id="whEvent"><option value="">any event type</option></select>
    <input type="date" id="whFrom" title="received from">
    <input type="date" id="whTo" title="received to">
    <button id="whGo">Filter</button>
    <button id="whClear">Clear</button>
    <span class="wh-muted" id="whCount"></span>
  </div>

  <table class="wh">
    <thead><tr>
      <th>When</th><th>Authentication</th><th>Validation</th><th>Processing</th>
      <th>Event</th><th>MessageID</th><th>HTTP</th><th>ms</th><th>What happened</th>
    </tr></thead>
    <tbody id="whRows"><tr><td colspan="9" class="wh-muted">Loading…</td></tr></tbody>
  </table>

  <div id="whDetail"></div>
</div>

<script>
(function () {
  // Escape EVERYTHING. Unlike the ledger, most rows on this page describe
  // requests from people we refused — every field is attacker-influenced.
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const el  = id => document.getElementById(id);
  const fmt = t => t ? new Date(t).toLocaleString() : '—';

  let facets = null, activeAuth = '';

  function params() {
    const p = new URLSearchParams();
    const add = (k, v) => { if (v) p.set(k, v); };
    add('search', el('whSearch').value.trim());
    add('authentication_result', activeAuth || el('whAuth').value);
    add('processing_result', el('whProc').value);
    add('event_type', el('whEvent').value);
    add('from', el('whFrom').value);
    add('to', el('whTo').value);
    return p.toString();
  }

  function fillFacets(f) {
    if (facets) return;
    facets = f;
    const opt = (sel, vals) => (vals || []).forEach(v => {
      const o = document.createElement('option'); o.value = v; o.textContent = v; el(sel).appendChild(o);
    });
    opt('whAuth', f.authentication_results);
    opt('whProc', f.processing_results);
    opt('whEvent', f.event_types);
  }

  async function health() {
    const r = await fetch(apiUrl('admin/email888/webhooks/health'), { credentials: 'include' });
    const j = await r.json();
    if (!j.success) { el('whHealth').textContent = 'Could not load webhook health.'; return; }
    const h = j.health;

    // Latency has no value when there are no samples. Rendering 0ms from an
    // empty set would describe a fast channel that never carried anything.
    const lat = h.processing_latency_ms.samples
      ? `${esc(h.processing_latency_ms.p50)} / ${esc(h.processing_latency_ms.max)}`
      : '<span class="wh-muted">no samples</span>';

    const fact = (v, label) => `<div class="wh-fact"><b>${v}</b><span>${esc(label)}</span></div>`;

    el('whHealth').innerHTML = `
      <span class="wh-verdict wh-${esc(h.verdict)}">${esc(h.verdict)}</span>
      <ul>${h.reasons.map(r => `<li>${esc(r)}</li>`).join('')}</ul>
      <div class="wh-facts">
        ${fact(esc(h.accepted_24h), 'accepted 24h')}
        ${fact(esc(h.refusals_24h_total), 'refused 24h')}
        ${fact(esc(h.our_misconfiguration_24h), 'our misconfig 24h')}
        ${fact(esc(h.messages_sent_24h), 'sent 24h')}
        ${fact(esc(h.deliveries_owed_an_event), 'owed an event')}
        ${fact(esc(h.unmatched_events), 'unmatched events')}
        ${fact(lat, 'latency p50 / max ms')}
        ${fact(esc(fmt(h.last_accepted_at)), 'last accepted')}
      </div>
      <div class="wh-note">
        <strong>Replay:</strong> ${esc(j.replay.reason)}
        <br><strong>Instead:</strong> ${esc(j.replay.instead)}
      </div>`;
  }

  function summary(s) {
    // Server-side counts over the WHOLE table, never this page.
    const order = ['total', 'accepted', 'refused', 'refused_bad_basic',
                   'refused_bad_secret', 'refused_malformed_path',
                   'refused_unconfigured', 'refused_rate_limited'];
    const filterFor = k => (k === 'total' || k === 'refused') ? '' : k;

    el('whSummary').innerHTML = order.filter(k => k in s).map(k =>
      `<div class="wh-chip ${activeAuth === k ? 'on' : ''}" data-auth="${esc(filterFor(k))}">
         <b>${esc(s[k])}</b><span>${esc(k.replace(/_/g, ' '))}</span></div>`).join('');

    el('whSummary').querySelectorAll('.wh-chip').forEach(c =>
      c.onclick = () => { activeAuth = c.dataset.auth; el('whAuth').value = activeAuth; load(); });
  }

  function badge(d) {
    if (!d.is_refusal) return '<span class="st st-accepted">accepted</span>';
    const cls = d.indicts_our_config ? 'st-ours' : 'st-refused';
    return `<span class="st ${cls}">${esc(d.authentication_result)}</span>`;
  }

  async function load() {
    const r = await fetch(apiUrl('admin/email888/webhooks?' + params()), { credentials: 'include' });
    const j = await r.json();
    if (!j.success) { el('whRows').innerHTML = '<tr><td colspan="9">Could not load receipts.</td></tr>'; return; }

    fillFacets(j.facets); summary(j.summary);
    el('whCount').textContent = j.meta.total + ' attempt(s)';

    el('whRows').innerHTML = j.data.length ? j.data.map(d => `
      <tr data-id="${esc(d.id)}">
        <td class="wh-muted">${esc(fmt(d.received_at))}</td>
        <td>${badge(d)}</td>
        <td>${esc(d.validation_result || '—')}</td>
        <td>${esc(d.processing_result || '—')}</td>
        <td>${esc(d.event_type || '—')}</td>
        <td class="wh-mono">${esc(d.provider_message_id || '—')}</td>
        <td>${esc(d.http_status || '—')}</td>
        <td class="wh-muted">${esc(d.processing_latency_ms ?? '—')}</td>
        <td class="wh-muted">${esc(d.operator_visible_reason || '')}</td>
      </tr>`).join('') : '<tr><td colspan="9" class="wh-muted">No attempts match.</td></tr>';

    el('whRows').querySelectorAll('tr[data-id]').forEach(tr =>
      tr.onclick = () => detail(tr.dataset.id));
  }

  async function detail(id) {
    const r = await fetch(apiUrl('admin/email888/webhooks/' + id), { credentials: 'include' });
    const j = await r.json();
    if (!j.success) return;
    const d = j.receipt;

    const kv   = (k, v) => `<dt>${esc(k)}</dt><dd>${v}</dd>`;
    const mono = v => `<span class="wh-mono">${esc(v ?? '—')}</span>`;

    const linked = !j.delivery
      ? '<span class="wh-muted">This attempt carried no MessageID.</span>'
      : (j.delivery.found
          ? `Ledger #${esc(j.delivery.id)} — ${esc(j.delivery.state)} — ${esc(j.delivery.recipient_address)}`
          : `<span class="wh-muted">${esc(j.delivery.message)}</span>`);

    el('whDetail').innerHTML = `
      <div class="wh-panel">
        <h3 style="margin:0 0 4px">${badge(d)} attempt #${esc(d.id)}</h3>
        <p class="wh-muted" style="margin:0 0 14px">${esc(d.operator_visible_reason || '')}</p>

        <dl class="wh-kv">
          ${kv('Received', esc(fmt(d.received_at)))}
          ${kv('Provider', esc(d.provider))}
          ${kv('Endpoint', mono(d.endpoint))}
          ${kv('Request ID', mono(d.request_id))}
          ${kv('Client IP', mono(d.client_ip))}
          ${kv('Stream', esc(d.stream || '—'))}
          ${kv('Event type', esc(d.event_type || '—'))}
          ${kv('Provider MessageID', mono(d.provider_message_id))}
          ${kv('Payload digest', mono(d.payload_digest))}
          ${kv('HTTP status', esc(d.http_status))}
          ${kv('Processing latency', esc(d.processing_latency_ms ?? '—') + ' ms')}
          ${kv('Fully processed', esc(String(d.fully_processed)))}
          ${kv('Ledger row', linked)}
        </dl>

        <h4 style="margin:18px 0 6px">What actually happened</h4>
        <ul class="wh-tl">
          ${j.timeline.map(t => `<li class="${t.outcome === 'passed' || t.outcome === 'settled' || t.outcome === 'completed' ? 'ok' : (t.outcome === 'observed' ? '' : 'bad')}">
              <strong>${esc(t.stage)}</strong> — ${esc(t.outcome)}<br>
              <span class="wh-muted">${esc(t.detail)}</span></li>`).join('')}
        </ul>

        <div class="wh-note">
          <strong>What to do:</strong> ${esc(j.guidance)}
        </div>

        <div class="wh-note">
          <strong>Replay:</strong> ${esc(j.replay.reason)}
          <br><strong>Instead:</strong> ${esc(j.replay.instead)}
        </div>
      </div>`;
    el('whDetail').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  el('whGo').onclick    = () => { activeAuth = el('whAuth').value; load(); };
  el('whClear').onclick = () => {
    ['whSearch','whAuth','whProc','whEvent','whFrom','whTo'].forEach(i => el(i).value = '');
    activeAuth = ''; load();
  };
  el('whSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { activeAuth = ''; load(); } });

  health();
  load();
})();
</script>
