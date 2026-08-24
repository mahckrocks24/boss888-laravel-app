{{-- Business Email — Providers (/admin/business-email/providers) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  setContent(B.loading('providers'));

  let d;
  try { d = await B.get('/providers'); } catch (e) { setContent(B.handle(e)); return; }

  let h = B.banner(d.summary);

  const conns = d.connections || [];
  h += B.card('Connections (' + conns.length + ')',
    conns.length
      ? B.table(['#','Provider','Label','Workspace','State','Credential configured','Health','Last check','Resources'],
          conns.map(c => [B.esc(c.id), '<code>'+B.esc(c.provider)+'</code>', B.esc(c.label || '—'),
            c.workspace_id ? B.esc(c.workspace_id) : '<span style="color:#6B7280">platform-wide</span>',
            B.pill(c.state), B.yesNo(c.credential_reference_present),
            B.pill(c.last_health_state || 'unknown'), B.when(c.last_health_check_at), B.esc(c.resource_count)]))
      : B.empty('No email provider connection is configured. Business Email is inert.'),
    'A connection is a configured integration. The credential itself lives elsewhere, encrypted, and is never returned by this API.');

  const policy = d.credential_policy || {};
  h += B.card('Credential handling', B.table(['Rule','Value'], [
    ['Secret returned after creation', B.yesNo(policy.secret_returned_after_creation)],
    ['Stored encrypted', B.yesNo(policy.stored_encrypted)],
    ['Displayed', B.esc(policy.displayed)],
    ['Rotation', B.esc(policy.rotation)],
    ['Every change audited', B.yesNo(policy.audited)]
  ]), 'There is no endpoint on this console that can return a secret value. The projection reports only whether one is configured.');

  const caps = d.capabilities || {};
  if (caps.available === false) {
    h += B.card('Capability support', B.empty(caps.reason || 'No provider is configured.'));
  } else {
    const keys = Object.keys(caps);
    h += B.card('Capability support (' + keys.filter(k => caps[k]).length + ' of ' + keys.length + ')',
      B.table(['Capability','Supported'], keys.map(k => ['<code>'+B.esc(k)+'</code>', B.yesNo(caps[k])])),
      'An action the provider cannot perform is refused BEFORE a governed operation is created. That is a configuration fact, not a provider failure — retrying it could never succeed.');
  }

  setContent(h);
});
</script>
