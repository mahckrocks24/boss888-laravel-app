{{-- Business Email — Overview (/admin/business-email/overview) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  setContent(B.loading('Business Email'));

  let d, recon = null;
  try { d = await B.get('/overview'); } catch (e) { setContent(B.handle(e)); return; }

  const stat = (label, value, tone) =>
    '<div style="flex:1;min-width:150px;border:1px solid var(--border);border-radius:8px;padding:14px">'
    + '<div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">'+B.esc(label)+'</div>'
    + '<div style="font-size:24px;font-weight:600;margin-top:6px;color:'+(tone||'inherit')+'">'+value+'</div></div>';

  let h = B.banner(d.provider);

  const unresolved = (d.operations && d.operations.unresolved) || 0;

  h += '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">';
  h += stat('Domains', B.esc(d.domains.total));
  h += stat('Mailboxes', B.esc(d.mailboxes.total));
  h += stat('Storage used', B.esc(d.storage_used_mb) + ' <span style="font-size:13px;color:var(--muted)">MB</span>');
  h += stat('Unresolved operations', B.esc(unresolved), unresolved > 0 ? '#F59E0B' : '#34D399');
  h += '</div>';

  const byState = (title, map) => {
    const keys = Object.keys(map || {});
    if (!keys.length) return B.card(title, B.empty('None yet.'));
    return B.card(title, B.table(['State', 'Count'],
      keys.map(k => [B.pill(k), '<b>' + B.esc(map[k]) + '</b>'])));
  };

  h += '<div style="display:flex;gap:16px;flex-wrap:wrap">';
  h += '<div style="flex:1;min-width:280px">' + byState('Domains by lifecycle', d.domains.by_lifecycle) + '</div>';
  h += '<div style="flex:1;min-width:280px">' + byState('Mailboxes by lifecycle', d.mailboxes.by_lifecycle) + '</div>';
  h += '</div>';

  // Manual review is the number an operator needs first: it is the count of
  // things nobody has decided about.
  h += B.card('Needs a human decision',
    unresolved === 0
      ? B.empty('Nothing is waiting on an operator.')
      : '<div style="font-size:13px">There ' + (unresolved === 1 ? 'is' : 'are') + ' <b>' + B.esc(unresolved)
        + '</b> operation' + (unresolved === 1 ? '' : 's') + ' in an unresolved state. '
        + '<a href="/admin/business-email/operations?unresolved=1" style="color:#60A5FA">Review them &rarr;</a></div>',
    'Ambiguous and failed operations never resolve themselves. An operation left here is a customer whose mail may not be working.');

  h += B.card('Provider',
    B.table(['Fact', 'Value'], [
      ['Configured', B.yesNo(d.provider.configured)],
      ['Identifier', d.provider.provider ? '<code>' + B.esc(d.provider.provider) + '</code>' : '<span style="color:#6B7280">none</span>'],
      ['Meets required capabilities', B.yesNo(d.provider.viable)]
    ]),
    'Operator plane. None of this is visible on any customer surface.');

  h += '<div style="font-size:11px;color:var(--muted);text-align:right">Observed ' + B.esc(d.observed_at) + '</div>';

  setContent(h);
});
</script>
