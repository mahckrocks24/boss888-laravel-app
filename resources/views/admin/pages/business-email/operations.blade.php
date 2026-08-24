{{-- Business Email — Operations + timeline + manual review
     (/admin/business-email/operations[?id=][&unresolved=1]) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  const id = B.qs('id');
  setContent(B.loading('operations'));

  // ── one operation: timeline + manual review ──────────────────────────────
  if (id) {
    const render = async () => {
      let d;
      try { d = await B.get('/operations/' + encodeURIComponent(id)); } catch (e) { setContent(B.handle(e)); return; }

      const o = d.operation, res = d.resolution || { allowed: [], forbidden: [] };

      let h = '<div style="margin-bottom:12px"><a href="/admin/business-email/operations" style="color:#60A5FA;font-size:13px">&larr; All operations</a></div>';
      h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">';
      h += '<span style="font-size:18px;font-weight:600">Operation ' + B.esc(o.id) + '</span>' + B.pill(o.state);
      if (o.needs_manual_review) h += '<span style="background:#F59E0B22;color:#F59E0B;border-radius:3px;padding:1px 8px;font-size:11px">needs a human decision</span>';
      h += '</div>';

      h += B.card('Request', B.table(['Fact','Value'], [
        ['Capability', '<code>'+B.esc(o.capability)+'</code>'],
        ['Subject', B.esc(o.owner_type) + (o.owner_id ? (' #' + B.esc(o.owner_id)) : ' <span style="color:#6B7280">(none — a create has no subject yet)</span>')],
        ['Workspace', B.esc(o.workspace_id)],
        ['Idempotency key', '<code style="word-break:break-all">'+B.esc(o.idempotency_key)+'</code>'],
        ['Attempts', B.esc(o.attempt_count) + ' of ' + B.esc(o.max_attempts)],
        ['Retry classification', B.esc(o.retry_classification || '—')],
        ['Auto-retry eligible', B.yesNo(o.auto_retry_eligible)],
        ['Actor', B.esc(o.actor_user_id || 'system')],
        ['Source', B.esc(o.source)],
        ['Terminal', B.yesNo(o.terminal)]
      ]));

      h += B.card('Provider outcome', B.table(['Fact','Value'], [
        ['Provider', o.provider ? '<code>'+B.esc(o.provider)+'</code>' : '<span style="color:#6B7280">none bound</span>'],
        ['Correlation id', o.provider_correlation_id ? '<code>'+B.esc(o.provider_correlation_id)+'</code>' : '&mdash;'],
        ['Failure code', o.failure_code ? '<code>'+B.esc(o.failure_code)+'</code>' : '&mdash;'],
        ['Summary', B.esc(o.failure_summary || '—')],
        ['Started', B.when(o.started_at)],
        ['Finished', B.when(o.finished_at)]
      ]), 'Operator diagnostics. None of this reaches a customer.');

      h += B.card('Timeline', B.table(['When','Event','Severity','Summary','Actor'],
        (d.timeline||[]).map(t => [B.when(t.at), '<code style="font-size:11px">'+B.esc(t.event)+'</code>',
          B.pill(t.severity), B.esc(t.summary || '—'), B.esc(t.actor || 'system')])),
        'requested &rarr; attempted &rarr; accepted &rarr; verified &rarr; failed &rarr; manual review &rarr; reconciled &rarr; compensated.');

      // ── manual review ──────────────────────────────────────────────────
      let panel = '';
      const allowed = res.allowed || [];

      panel += '<div style="font-size:13px;margin-bottom:10px">Every decision is recorded with your identity and your reason.</div>';
      panel += '<textarea id="be-reason" placeholder="Why are you taking this decision?" '
        + 'style="width:100%;min-height:64px;padding:8px;border:1px solid var(--border);border-radius:6px;background:transparent;color:inherit;font-size:13px;margin-bottom:10px"></textarea>';
      panel += '<div id="be-result" style="margin-bottom:10px"></div>';
      panel += '<div style="display:flex;gap:8px;flex-wrap:wrap">';

      const btn = (decision, label, tone) =>
        '<button data-decision="'+decision+'" class="be-resolve" style="padding:8px 14px;border-radius:6px;border:1px solid '+tone+'55;'
        + 'background:'+tone+'18;color:'+tone+';font-size:13px;cursor:pointer">'+B.esc(label)+'</button>';

      if (allowed.indexOf('confirm_by_read_back') >= 0) panel += btn('confirm', 'Run read-back', '#34D399');
      if (allowed.indexOf('retry') >= 0) panel += btn('retry', 'Retry', '#60A5FA');
      panel += btn('acknowledge', 'Acknowledge (stays unresolved)', '#F59E0B');
      panel += '</div>';

      if ((res.forbidden || []).length) {
        panel += '<div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px">';
        panel += '<div style="font-size:12px;color:var(--muted);margin-bottom:6px">Not permitted here, and why:</div>';
        (res.forbidden || []).forEach(f => {
          panel += '<div style="font-size:12px;margin-bottom:6px"><code>'+B.esc(f.action)+'</code> &mdash; '
            + '<span style="color:var(--muted)">'+B.esc(f.reason)+'</span></div>';
        });
        panel += '</div>';
      }

      h += B.card('Resolve', panel,
        'Acknowledging records that a human has seen this. It does NOT resolve the underlying condition.');

      setContent(h);

      document.querySelectorAll('.be-resolve').forEach(b => {
        b.addEventListener('click', async () => {
          const reason = (document.getElementById('be-reason') || {}).value || '';
          const out = document.getElementById('be-result');
          out.innerHTML = '<span style="color:var(--muted);font-size:13px">Working&hellip;</span>';

          const r = await B.post('/operations/' + encodeURIComponent(id) + '/resolve',
            { decision: b.getAttribute('data-decision'), reason: reason });

          const d2 = B.describeResult(r);
          const extra = (r.body && r.body.note) ? (' ' + r.body.note) : '';
          out.innerHTML = '<div style="color:'+d2.tone+';font-size:13px">'+B.esc(d2.text + extra)+'</div>';

          if (r.status === 200) { setTimeout(render, 700); }
        });
      });
    };

    await render();
    return;
  }

  // ── list ─────────────────────────────────────────────────────────────────
  const unresolvedOnly = B.qs('unresolved') === '1';

  let d;
  try {
    d = await B.get('/operations?per_page=100' + (unresolvedOnly ? '&unresolved=1' : ''));
  } catch (e) { setContent(B.handle(e)); return; }

  let h = '<div style="margin-bottom:12px;font-size:13px">'
    + (unresolvedOnly
      ? 'Showing unresolved only. <a href="/admin/business-email/operations" style="color:#60A5FA">Show all</a>'
      : '<a href="/admin/business-email/operations?unresolved=1" style="color:#60A5FA">Show only what needs a decision</a>')
    + '</div>';

  const rows = (d.operations || []).map(o => [
    '<a href="/admin/business-email/operations?id='+B.esc(o.id)+'" style="color:#60A5FA">'+B.esc(o.id)+'</a>',
    '<code style="font-size:11px">'+B.esc(o.capability)+'</code>',
    B.esc(o.owner_type) + (o.owner_id ? (' #'+B.esc(o.owner_id)) : ''),
    B.esc(o.workspace_id), B.pill(o.state),
    '<code style="font-size:11px;word-break:break-all">'+B.esc(o.idempotency_key)+'</code>',
    B.esc(o.attempt_count)+'/'+B.esc(o.max_attempts),
    B.esc(o.retry_classification || '—'),
    o.needs_manual_review ? '<span style="color:#F59E0B">yes</span>' : 'no',
    B.when(o.created_at)
  ]);

  h += B.card('Operations (' + ((d.meta && d.meta.total) || rows.length) + ')',
    B.table(['#','Capability','Subject','Workspace','State','Idempotency key','Attempts','Retry','Manual review','Created'], rows),
    'One row per governed action. An ambiguous operation is never retried automatically.');

  setContent(h);
});
</script>
