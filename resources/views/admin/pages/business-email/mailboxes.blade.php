{{-- Business Email — Mailboxes + Mailbox Detail (/admin/business-email/mailboxes[?id=]) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  const id = B.qs('id');
  setContent(B.loading(id ? 'mailbox' : 'mailboxes'));

  if (id) {
    let d;
    try { d = await B.get('/mailboxes/' + encodeURIComponent(id)); } catch (e) { setContent(B.handle(e)); return; }

    const m = d.mailbox, bind = d.provider_binding || {};

    let h = '<div style="margin-bottom:12px"><a href="/admin/business-email/mailboxes" style="color:#60A5FA;font-size:13px">&larr; All mailboxes</a></div>';
    h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">';
    h += '<span style="font-size:18px;font-weight:600">' + B.esc(m.address || m.local_part) + '</span>' + B.pill(m.status);
    h += '<span style="font-size:12px;color:var(--muted)">workspace ' + B.esc(m.workspace_id) + '</span></div>';

    h += B.card('Lifecycle & quota', B.table(['Fact','Value'], [
      ['State', B.pill(m.status)],
      ['Previous state', m.previous_state ? B.pill(m.previous_state) : '&mdash;'],
      ['Suspended', B.yesNo(m.suspended)],
      ['Suspension reason', B.esc(m.suspension_reason_code || 'none')],
      ['Customer may restore', B.yesNo(m.can_restore_myself)],
      ['Quota', B.num(m.quota_mb, ' MB')],
      ['Storage used', B.num(m.storage_used_mb, ' MB')],
      ['Quota used', m.quota_used_percent === null ? '<span style="color:#6B7280" title="never measured">not measured</span>' : (B.esc(m.quota_used_percent) + '%')],
      ['Usage observed', B.when(m.usage_observed_at)],
      ['Last activity', B.when(m.last_activity_at)],
      ['Password set at', B.when(m.password_set_at)],
      ['Last password reset', B.when(m.last_password_reset_at)]
    ]), 'A never-measured figure reads "not measured". It is never shown as zero.');

    h += B.card('Provider binding', bind.bound
      ? B.table(['Fact','Value'], [
          ['Provider', '<code>'+B.esc(bind.provider)+'</code>'],
          ['Reference', '<code style="word-break:break-all">'+B.esc(bind.provider_ref)+'</code>'],
          ['Normalized state', B.pill(bind.normalized_state)],
          ['Last synced', B.when(bind.last_synced_at)],
          ['Stale', B.yesNo(bind.stale)]
        ])
      : B.empty('Not bound to any provider object.'),
      'The reference is opaque and is never parsed. It appears on no customer surface.');

    h += B.card('Credentials',
      '<div style="font-size:13px;line-height:1.7">'
      + 'No password is stored by LevelUp for this mailbox, and none can be retrieved. '
      + 'Authentication is owned by the service. A reset asks the service to run its own recovery flow; '
      + 'it returns nothing that could be shown here.'
      + '</div>',
      'This is a property of the provider contract, not a limitation of this screen.');

    h += B.card('Operations', B.table(['#','Capability','State','Attempts','Retry','Manual review','Finished'],
      (d.operations||[]).map(o => ['<a href="/admin/business-email/operations?id='+B.esc(o.id)+'" style="color:#60A5FA">'+B.esc(o.id)+'</a>',
        B.esc(o.capability), B.pill(o.state), B.esc(o.attempt_count)+'/'+B.esc(o.max_attempts),
        B.esc(o.retry_classification||'—'),
        o.needs_manual_review ? '<span style="color:#F59E0B">yes</span>' : 'no', B.when(o.finished_at)])));

    h += B.card('Usage history', B.table(['Observed','Storage','Quota','Sent','Received','Confidence'],
      (d.usage||[]).map(u => [B.when(u.observed_at), B.num(u.storage_used_mb,' MB'), B.num(u.storage_quota_mb,' MB'),
        B.num(u.messages_sent), B.num(u.messages_received), B.pill(u.confidence)])));

    setContent(h);
    return;
  }

  let d;
  try { d = await B.get('/mailboxes?per_page=100'); } catch (e) { setContent(B.handle(e)); return; }

  const rows = (d.mailboxes || []).map(m => [
    '<a href="/admin/business-email/mailboxes?id='+B.esc(m.id)+'" style="color:#60A5FA">'+B.esc(m.address || m.local_part)+'</a>',
    B.esc(m.workspace_id), B.pill(m.status), B.num(m.quota_mb,' MB'), B.num(m.storage_used_mb,' MB'),
    m.quota_used_percent === null ? '<span style="color:#6B7280">not measured</span>' : (B.esc(m.quota_used_percent)+'%'),
    B.when(m.usage_observed_at),
    m.provider_binding && m.provider_binding.bound ? B.pill('bound') : '<span style="color:#6B7280">unbound</span>'
  ]);

  setContent(B.card('Mailboxes (' + ((d.meta && d.meta.total) || rows.length) + ')',
    B.table(['Address','Workspace','State','Quota','Used','%','Observed','Binding'], rows)));
});
</script>
