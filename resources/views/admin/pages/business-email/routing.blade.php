{{-- Business Email — Aliases & Forwarding (/admin/business-email/routing) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  setContent(B.loading('routing'));

  let d;
  try { d = await B.get('/routing'); } catch (e) { setContent(B.handle(e)); return; }

  const aliases = d.aliases || [], forwarders = d.forwarders || [], catchAlls = d.catch_alls || [];

  let h = '';

  // Loop-blocked forwarders come first: they are the reason mail is not being
  // relayed, and burying them under a list would hide the actionable thing.
  const blocked = forwarders.filter(f => f.loop_blocked);

  if (blocked.length) {
    h += B.notice('<b>' + blocked.length + ' forwarding rule' + (blocked.length === 1 ? '' : 's')
      + ' cannot be provisioned.</b> A rule whose loop check has not cleared is blocked on purpose &mdash; '
      + 'an unevaluated forwarder is not assumed safe. Mail amplification damages a third party who never '
      + 'agreed to anything.');
  }

  h += B.card('Aliases (' + aliases.length + ')',
    B.table(['Source','Target','Type','State','Domain'],
      aliases.map(a => [B.esc(a.source_local_part),
        B.esc(a.target_address || ('mailbox ' + a.target_mailbox_id)),
        B.esc(a.target_type), B.pill(a.status), B.esc(a.email_domain_id)])),
    'An alias delivers into a mailbox we operate. It stores no mail of its own.');

  h += B.card('Forwarders (' + forwarders.length + ')',
    B.table(['Source','Destination','Loop check','Blocked','State','Hops'],
      forwarders.map(f => [B.esc(f.source_local_part), B.esc(f.destination_address),
        B.pill(f.loop_check)
          + (f.loop_check_reason ? '<div style="font-size:11px;color:var(--muted);margin-top:3px;max-width:340px">'+B.esc(f.loop_check_reason)+'</div>' : ''),
        f.loop_blocked ? '<span style="color:#F59E0B">yes</span>' : '<span style="color:#34D399">no</span>',
        B.pill(f.status), B.num(f.loop_check_hops)])),
    'A forwarder relays outside the domain. "safe" means no loop is visible in records we hold &mdash; it is not a proof that none exists.');

  h += B.card('Catch-all (' + catchAlls.length + ')',
    B.table(['Domain','State','Target type','Target'],
      catchAlls.map(c => [B.esc(c.email_domain_id), B.pill(c.status), B.esc(c.target_type || '—'),
        B.esc(c.target_address || (c.target_mailbox_id ? ('mailbox ' + c.target_mailbox_id) : '—'))])),
    'A catch-all accepts mail for every unassigned address and is a well-known spam magnet. Off is the safe default.');

  setContent(h);
});
</script>
