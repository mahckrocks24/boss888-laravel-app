{{--
    EMAIL888 — Delivery Ledger.

    Every value on this page comes from the ledger. Nothing is inferred from a
    request completing, and "accepted" is never rendered as success — that
    distinction is the reason this screen exists.

    All interpolation goes through esc(); provider text is attacker-influenced
    (a bounce Description is written by the receiving server) and must never be
    injected as markup.
--}}
<div class="ed-wrap">
  <style>
    .ed-wrap{font-size:14px}
    .ed-sum{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
    .ed-chip{padding:8px 14px;border-radius:8px;background:#f5f5f5;cursor:pointer;border:1px solid transparent}
    .ed-chip.on{border-color:#F59E0B;background:#fff7e6}
    .ed-chip b{display:block;font-size:20px;line-height:1.1}
    .ed-chip span{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666}
    .ed-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
    .ed-filters input,.ed-filters select{padding:7px 9px;border:1px solid #ddd;border-radius:6px;font-size:13px}
    .ed-filters input[type=search]{min-width:280px}
    table.ed{width:100%;border-collapse:collapse}
    table.ed th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666;padding:8px;border-bottom:2px solid #eee}
    table.ed td{padding:8px;border-bottom:1px solid #f2f2f2;vertical-align:top}
    table.ed tr:hover{background:#fafafa;cursor:pointer}
    .st{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
    .st-delivered{background:#e6f7ec;color:#036d33}
    .st-accepted{background:#eef2ff;color:#3730a3}
    .st-queued{background:#f5f5f5;color:#555}
    .st-deferred{background:#fff7e6;color:#92400e}
    .st-bounced{background:#fdecec;color:#9b1c1c}
    .st-suppressed{background:#fdecec;color:#9b1c1c}
    .st-failed{background:#fdecec;color:#9b1c1c}
    .ed-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
    .ed-muted{color:#888}
    .ed-panel{margin-top:18px;padding:16px;border:1px solid #eee;border-radius:10px;background:#fff}
    .ed-tl{list-style:none;padding:0;margin:0}
    .ed-tl li{padding:8px 0 8px 18px;border-left:2px solid #eee;position:relative}
    .ed-tl li:before{content:'';position:absolute;left:-5px;top:14px;width:8px;height:8px;border-radius:50%;background:#F59E0B}
    .ed-kv{display:grid;grid-template-columns:200px 1fr;gap:6px 14px}
    .ed-kv dt{color:#666;font-size:12px}
    .ed-kv dd{margin:0;font-size:13px;word-break:break-all}
    pre.ed-ev{background:#fafafa;border:1px solid #eee;border-radius:6px;padding:10px;overflow:auto;font-size:12px;max-height:260px}
    @media (prefers-color-scheme:dark){
      .ed-chip{background:#1f2430}.ed-chip span{color:#9aa}
      table.ed td{border-color:#232833}table.ed tr:hover{background:#1b1f28}
      .ed-panel{background:#151922;border-color:#232833}pre.ed-ev{background:#11141b;border-color:#232833}
    }
  </style>

  <div class="ed-sum" id="edSummary"></div>

  <div class="ed-filters">
    <input type="search" id="edSearch" placeholder="recipient, subject, MessageID, correlation ID…">
    <select id="edState"><option value="">any state</option></select>
    <select id="edPurpose"><option value="">any purpose</option></select>
    <select id="edStream"><option value="">any stream</option></select>
    <select id="edWorkspace"><option value="">any workspace</option></select>
    <input type="date" id="edFrom" title="queued from">
    <input type="date" id="edTo" title="queued to">
    <button id="edGo">Filter</button>
    <button id="edClear">Clear</button>
    <span class="ed-muted" id="edCount"></span>
  </div>

  <table class="ed">
    <thead><tr>
      <th>When</th><th>State</th><th>Purpose</th><th>Recipient</th>
      <th>Sender</th><th>Stream</th><th>Latency</th><th>Failure</th>
    </tr></thead>
    <tbody id="edRows"><tr><td colspan="8" class="ed-muted">Loading…</td></tr></tbody>
  </table>

  <div id="edDetail"></div>
</div>

<script>
(function () {
  // Escape EVERYTHING. Provider text (a bounce Description) is written by the
  // receiving server, not by us.
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const el = id => document.getElementById(id);
  const fmt = t => t ? new Date(t).toLocaleString() : '—';
  const dur = s => s === null || s === undefined ? '—' : (s < 60 ? s + 's' : Math.round(s / 60) + 'm');

  let facets = null, activeState = '';

  function params() {
    const p = new URLSearchParams();
    const add = (k, v) => { if (v) p.set(k, v); };
    add('search', el('edSearch').value.trim());
    add('state', activeState || el('edState').value);
    add('purpose', el('edPurpose').value);
    add('stream', el('edStream').value);
    add('workspace_id', el('edWorkspace').value);
    add('from', el('edFrom').value);
    add('to', el('edTo').value);
    return p.toString();
  }

  function fillFacets(f) {
    if (facets) return;
    facets = f;
    const opt = (sel, vals) => vals.forEach(v => {
      const o = document.createElement('option'); o.value = v; o.textContent = v; el(sel).appendChild(o);
    });
    opt('edState', f.states); opt('edPurpose', f.purposes);
    opt('edStream', f.streams); opt('edWorkspace', f.workspaces);
  }

  function summary(s) {
    // Counts are computed server-side over the WHOLE ledger, not this page.
    const order = ['total','delivered','accepted','queued','deferred','bounced','suppressed','failed'];
    el('edSummary').innerHTML = order.filter(k => k in s).map(k =>
      `<div class="ed-chip ${activeState === k ? 'on' : ''}" data-state="${k === 'total' ? '' : esc(k)}">
         <b>${esc(s[k])}</b><span>${esc(k)}</span></div>`).join('');
    el('edSummary').querySelectorAll('.ed-chip').forEach(c =>
      c.onclick = () => { activeState = c.dataset.state; el('edState').value = activeState; load(); });
  }

  async function load() {
    const r = await fetch(apiUrl('admin/email888/deliveries?' + params()), { credentials: 'include' });
    const j = await r.json();
    if (!j.success) { el('edRows').innerHTML = '<tr><td colspan="8">Could not load the ledger.</td></tr>'; return; }

    fillFacets(j.facets); summary(j.summary);
    el('edCount').textContent = j.meta.total + ' message(s)';

    el('edRows').innerHTML = j.data.length ? j.data.map(d => `
      <tr data-id="${esc(d.id)}">
        <td class="ed-muted">${esc(fmt(d.queued_at))}</td>
        <td><span class="st st-${esc(d.state)}">${esc(d.state)}</span></td>
        <td>${esc(d.purpose)}</td>
        <td>${esc(d.recipient)}</td>
        <td class="ed-muted">${esc(d.sender)}</td>
        <td>${esc(d.stream)}</td>
        <td>${esc(dur(d.latency_seconds))}</td>
        <td class="ed-muted">${esc(d.failure_category || '')}</td>
      </tr>`).join('') : '<tr><td colspan="8" class="ed-muted">No messages match.</td></tr>';

    el('edRows').querySelectorAll('tr[data-id]').forEach(tr =>
      tr.onclick = () => detail(tr.dataset.id));
  }

  async function detail(id) {
    const r = await fetch(apiUrl('admin/email888/deliveries/' + id), { credentials: 'include' });
    const j = await r.json();
    if (!j.success) return;
    const d = j.delivery;

    const kv = (k, v) => `<dt>${esc(k)}</dt><dd>${v}</dd>`;
    const mono = v => `<span class="ed-mono">${esc(v ?? '—')}</span>`;

    el('edDetail').innerHTML = `
      <div class="ed-panel">
        <h3 style="margin:0 0 4px">${esc(d.subject || '(no subject)')}</h3>
        <p class="ed-muted" style="margin:0 0 14px">
          <span class="st st-${esc(d.state)}">${esc(d.state)}</span>
          ${d.is_terminal ? '' : ' <em>still in flight — not a final outcome</em>'}
        </p>
        <dl class="ed-kv">
          ${kv('Purpose', esc(d.purpose))}
          ${kv('Sender', esc(d.sender))}
          ${kv('Recipient', esc(d.recipient))}
          ${kv('Stream', esc(d.stream) + ' → ' + esc(d.provider_stream || '—'))}
          ${kv('Provider', esc(d.provider))}
          ${kv('Provider MessageID', mono(d.provider_message_id))}
          ${kv('Correlation ID', mono(d.correlation_id))}
          ${kv('Idempotency key', mono(d.idempotency_key))}
          ${kv('Workspace', d.workspace_id ? esc(d.workspace_id) : '<span class="ed-muted">platform</span>')}
          ${kv('Retryable', esc(String(d.retryable)))}
          ${kv('Failure category', esc(d.failure_category || '—'))}
          ${kv('Latency', esc(dur(d.latency_seconds)))}
        </dl>

        <h4 style="margin:18px 0 6px">Timeline</h4>
        <ul class="ed-tl">
          ${j.timeline.map(t => `<li>
              <strong>${esc(t.state)}</strong> — ${esc(fmt(t.at))}
              <span class="ed-muted">(${esc(t.source)})</span><br>
              <span class="ed-muted">${esc(t.note)}</span></li>`).join('')
            || '<li class="ed-muted">No transitions recorded.</li>'}
        </ul>

        <h4 style="margin:18px 0 6px">Provider evidence</h4>
        <pre class="ed-ev">${esc(JSON.stringify(d.provider_response, null, 2) || 'none')}</pre>

        <h4 style="margin:18px 0 6px">Provider events (${j.events.length})</h4>
        <pre class="ed-ev">${esc(JSON.stringify(j.events, null, 2))}</pre>
      </div>`;
    el('edDetail').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  el('edGo').onclick = () => { activeState = el('edState').value; load(); };
  el('edClear').onclick = () => {
    ['edSearch','edState','edPurpose','edStream','edWorkspace','edFrom','edTo'].forEach(i => el(i).value = '');
    activeState = ''; load();
  };
  el('edSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { activeState = ''; load(); } });

  load();
})();
</script>
