{{-- Business Email — Domains + Domain Detail (/admin/business-email/domains[?id=]) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  const id = B.qs('id');
  setContent(B.loading(id ? 'domain' : 'domains'));

  // ── detail ───────────────────────────────────────────────────────────────
  if (id) {
    let d;
    try { d = await B.get('/domains/' + encodeURIComponent(id)); } catch (e) { setContent(B.handle(e)); return; }

    const dom = d.domain;
    let h = '<div style="margin-bottom:12px"><a href="/admin/business-email/domains" style="color:#60A5FA;font-size:13px">&larr; All domains</a></div>';
    h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">';
    h += '<span style="font-size:18px;font-weight:600">' + B.esc(dom.domain) + '</span>' + B.pill(dom.status);
    h += '<span style="font-size:12px;color:var(--muted)">workspace ' + B.esc(dom.workspace_id) + '</span></div>';

    h += B.card('Overview', B.table(['Fact','Value'], [
      ['Lifecycle', B.pill(dom.status)],
      ['Previous state', dom.previous_state ? B.pill(dom.previous_state) : '&mdash;'],
      ['Verification', B.pill(dom.verification)],
      ['DNS verified at', B.when(dom.dns_verified_at)],
      ['Custody', B.pill(dom.custody)],
      ['Health', B.pill(dom.health)],
      ['Last checked', B.when(dom.last_checked_at)],
      ['Provider binding', dom.provider_connection_id ? ('connection ' + B.esc(dom.provider_connection_id)) : '<span style="color:#6B7280">not bound</span>'],
      ['Suspension reason', dom.suspension_reason ? B.esc(dom.suspension_reason) : '&mdash;']
    ]));

    // DNS requirements — structured fields only. Provider prose never reaches
    // this screen because MailDnsRecord has nowhere to put it.
    const recs = d.dns_requirements || [];
    h += B.card('DNS requirements',
      recs.length
        ? B.table(['Purpose','Type','Name','Value','Priority','TTL','Required'],
            recs.map(r => [B.pill(r.purpose), B.esc(r.type), '<code>'+B.esc(r.name)+'</code>',
              '<code style="word-break:break-all">'+B.esc(r.value)+'</code>',
              B.num(r.priority), B.num(r.ttl), B.yesNo(r.required)]))
        : B.empty('Not requested yet. Run "Issue DNS requirements" to ask the service what this domain needs.'),
      d.dns_observed_at ? ('Observed ' + B.esc(d.dns_observed_at)) : null);

    const mb = d.mailboxes || [];
    h += B.card('Mailboxes (' + mb.length + ')', B.table(
      ['Address','State','Quota','Used','Binding','Observed'],
      mb.map(m => ['<a href="/admin/business-email/mailboxes?id='+B.esc(m.id)+'" style="color:#60A5FA">'+B.esc(m.address || m.local_part)+'</a>',
        B.pill(m.status), B.num(m.quota_mb,' MB'), B.num(m.storage_used_mb,' MB'),
        m.provider_binding && m.provider_binding.bound ? '<code style="font-size:11px">'+B.esc(m.provider_binding.provider_ref)+'</code>' : '<span style="color:#6B7280">unbound</span>',
        B.when(m.usage_observed_at)])));

    const al = d.aliases || [];
    h += B.card('Aliases (' + al.length + ')', B.table(['Source','Target','State'],
      al.map(a => [B.esc(a.source_local_part), B.esc(a.target_address || ('mailbox ' + a.target_mailbox_id)), B.pill(a.status)])));

    const fw = d.forwarders || [];
    h += B.card('Forwarding (' + fw.length + ')', B.table(['Source','Destination','Loop check','State'],
      fw.map(f => [B.esc(f.source_local_part), B.esc(f.destination_address),
        B.pill(f.loop_check) + (f.loop_check_reason ? '<div style="font-size:11px;color:var(--muted);margin-top:3px">'+B.esc(f.loop_check_reason)+'</div>' : ''),
        B.pill(f.status)])));

    const ca = d.catch_all;
    h += B.card('Catch-all', ca
      ? B.table(['Fact','Value'], [['State', B.pill(ca.status)],
          ['Target', B.esc(ca.target_address || (ca.target_mailbox_id ? ('mailbox ' + ca.target_mailbox_id) : '')) || '&mdash;']])
      : B.empty('No catch-all configured. Unassigned addresses are rejected, which is the safe default.'));

    const us = d.usage || [];
    h += B.card('Usage', B.table(['Observed','Scope','Storage','Quota','Sent','Received','Source','Confidence'],
      us.map(u => [B.when(u.observed_at), B.esc(u.scope), B.num(u.storage_used_mb,' MB'),
        B.num(u.storage_quota_mb,' MB'), B.num(u.messages_sent), B.num(u.messages_received),
        B.esc(u.source), B.pill(u.confidence)])),
      'An unreported measure shows as "not reported", never as zero.');

    h += B.card('Operations', B.table(['#','Capability','State','Attempts','Retry','Manual review','Started'],
      (d.operations||[]).map(o => ['<a href="/admin/business-email/operations?id='+B.esc(o.id)+'" style="color:#60A5FA">'+B.esc(o.id)+'</a>',
        B.esc(o.capability), B.pill(o.state), B.esc(o.attempt_count)+'/'+B.esc(o.max_attempts),
        B.esc(o.retry_classification||'—'), o.needs_manual_review ? '<span style="color:#F59E0B">yes</span>' : 'no',
        B.when(o.started_at)])));

    h += '<div style="margin-bottom:16px"><a href="/admin/business-email/reconciliation?email_domain_id='+B.esc(dom.id)+'" style="color:#60A5FA;font-size:13px">Run reconciliation for this domain &rarr;</a></div>';

    h += B.card('Audit', B.table(['When','Event','Severity','Summary','Actor'],
      (d.audit||[]).map(a => [B.when(a.at), '<code style="font-size:11px">'+B.esc(a.event)+'</code>',
        B.pill(a.severity), B.esc(a.summary), B.esc(a.actor||'system')])));

    setContent(h);
    return;
  }

  // ── list ─────────────────────────────────────────────────────────────────
  let d;
  try { d = await B.get('/domains?per_page=100'); } catch (e) { setContent(B.handle(e)); return; }

  const rows = (d.domains || []).map(x => [
    '<a href="/admin/business-email/domains?id='+B.esc(x.id)+'" style="color:#60A5FA">'+B.esc(x.domain)+'</a>',
    B.esc(x.workspace_id), B.pill(x.status), B.pill(x.verification), B.pill(x.custody), B.pill(x.health),
    B.esc(x.mailbox_count),
    x.provider_binding && x.provider_binding.bound
      ? B.pill('bound') + (x.provider_binding.stale ? ' <span style="font-size:11px;color:#F59E0B">stale</span>' : '')
      : '<span style="color:#6B7280">unbound</span>',
    B.when(x.dns_verified_at)
  ]);

  setContent(
    B.card('Domains (' + ((d.meta && d.meta.total) || rows.length) + ')',
      B.table(['Domain','Workspace','Lifecycle','Verification','Custody','Health','Mailboxes','Binding','Verified'], rows),
      'Cross-tenant by design: this is the platform operator console.')
  );
});
</script>
