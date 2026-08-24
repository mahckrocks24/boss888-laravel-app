{{-- Business Email — Reconciliation (/admin/business-email/reconciliation?email_domain_id=) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  const domainId = B.qs('email_domain_id');
  setContent(B.loading('reconciliation'));

  if (!domainId) {
    let list;
    try { list = await B.get('/domains?per_page=100'); } catch (e) { setContent(B.handle(e)); return; }

    const rows = (list.domains || []).map(x => [
      '<a href="/admin/business-email/reconciliation?email_domain_id='+B.esc(x.id)+'" style="color:#60A5FA">'+B.esc(x.domain)+'</a>',
      B.esc(x.workspace_id), B.pill(x.status), B.pill(x.health), B.esc(x.mailbox_count)
    ]);

    setContent(B.card('Choose a domain to reconcile',
      B.table(['Domain','Workspace','Lifecycle','Health','Mailboxes'], rows),
      'Reconciliation compares what we intend against what the service actually has. It never changes anything.'));
    return;
  }

  let d;
  try {
    d = await B.get('/reconciliation?email_domain_id=' + encodeURIComponent(domainId));
  } catch (e) { setContent(B.handle(e)); return; }

  const r = d.report || {};
  const findings = r.findings || [];

  let h = '<div style="margin-bottom:12px"><a href="/admin/business-email/reconciliation" style="color:#60A5FA;font-size:13px">&larr; All domains</a></div>';

  h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">';
  h += '<span style="font-size:18px;font-weight:600">' + B.esc(r.domain) + '</span>';
  h += B.pill(findings.length ? 'drift' : 'clean');
  h += '</div>';

  // Conclusiveness is the first thing an operator must see. Findings from an
  // incomplete read mean something different from findings from a complete one.
  if (r.conclusive === false) {
    h += B.notice('<b>This pass was inconclusive.</b> '
      + B.esc(r.inconclusive_reason || 'The service could not be fully enumerated.')
      + ' No "missing" or "unexpected" conclusion is drawn from a partial read &mdash; acting on one would '
      + 'recreate mailboxes that already exist, or delete objects that were simply on a later page.');
  }

  h += B.card('Comparison', B.table(['Fact','Value'], [
    ['Conclusive', B.yesNo(r.conclusive)],
    ['Objects we intend', B.esc(r.desired_object_count)],
    ['Objects observed', B.esc(r.observed_object_count)],
    ['Findings', B.esc(findings.length)]
  ]), 'Desired state is what INFRA888 holds. Observed state is what the service reports.');

  h += B.card('Findings',
    findings.length
      ? B.table(['Severity','Finding','Subject','Evidence','Resolution'],
          findings.map(f => [B.pill(f.severity), '<code style="font-size:11px">'+B.esc(f.kind)+'</code>',
            B.esc(f.subject), '<div style="max-width:340px">'+B.esc(f.summary)+'</div>'
              + (f.detail ? '<pre style="font-size:11px;color:var(--muted);margin:4px 0 0;white-space:pre-wrap">'+B.esc(JSON.stringify(f.detail))+'</pre>' : ''),
            '<span style="color:#F59E0B">unresolved</span>']))
      : B.empty('This domain agrees with the service.'),
    'A finding is a fact, not an instruction. Reconciliation records; it never repairs.');

  h += B.card('What the customer would be told',
    '<pre style="font-size:11px;white-space:pre-wrap;margin:0">' + B.esc(JSON.stringify(d.customer_projection_preview, null, 2)) + '</pre>',
    'Shown so an operator can confirm it leaks nothing. This is NOT a customer route — it carries counts and severities, never a provider reference or a diff of our binding table.');

  setContent(h);
});
</script>
