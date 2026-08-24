{{-- Business Email — Health (/admin/business-email/health) --}}
@include('admin.pages.business-email._helpers')
<script>
window.page = (async () => {
  const B = window.BE;
  setContent(B.loading('health'));

  let d, obs = null;
  try { d = await B.get('/health'); } catch (e) { setContent(B.handle(e)); return; }
  try { obs = await B.get('/observations?per_page=25'); } catch (e) { obs = null; }

  let h = B.banner({ configured: d.provider_configured, provider: d.provider, viable: true });

  h += B.card('Provider', B.table(['Fact','Value'], [
    ['Configured', B.yesNo(d.provider_configured)],
    ['Identifier', d.provider ? '<code>'+B.esc(d.provider)+'</code>' : '<span style="color:#6B7280">none</span>']
  ]));

  const byHealth = d.domains_by_health || {};
  h += B.card('Domain health',
    Object.keys(byHealth).length
      ? B.table(['Health','Domains'], Object.keys(byHealth).map(k => [B.pill(k), '<b>'+B.esc(byHealth[k])+'</b>']))
      : B.empty('No domains yet.'));

  h += B.card('Observation freshness', B.table(['Fact','Value'], [
    ['Domains never observed', B.esc(d.stale_usage_domains)],
    ['Reported at', B.esc(d.observed_at)]
  ]), 'A domain that has never been observed is not healthy — it is unknown. The two are shown separately on purpose.');

  const caps = d.capabilities || {};
  if (caps.available === false) {
    h += B.card('Capability support', B.empty(caps.reason || 'No provider is configured.'));
  } else {
    const missing = Object.keys(caps).filter(k => !caps[k]);
    h += B.card('Capability support',
      missing.length
        ? '<div style="font-size:13px;margin-bottom:8px">Unsupported by this provider:</div>'
          + B.table(['Capability'], missing.map(k => ['<code>'+B.esc(k)+'</code>']))
        : '<div style="font-size:13px;color:#34D399">Every contracted capability is supported.</div>');
  }

  if (obs && (obs.observations || []).length) {
    h += B.card('Recent observations',
      B.table(['Observed','Subject','Custody','Confidence','Drift','Severity'],
        obs.observations.map(o => [B.when(o.observed_at), B.esc(o.subject), B.pill(o.custody),
          B.pill(o.confidence), o.has_drift ? '<span style="color:#F59E0B">'+B.esc(o.drift_count)+'</span>' : '<span style="color:#34D399">none</span>',
          o.highest_severity ? B.pill(o.highest_severity) : '&mdash;'])),
      'Recorded in the estate-wide observation registry, alongside DNS and certificate facts.');
  } else {
    h += B.card('Recent observations', B.empty('No Business Email observations have been recorded yet.'));
  }

  setContent(h);
});
</script>
