{{-- Business Email — shared renderers.
     Included by every Business Email page view. Follows the existing admin
     convention: data comes from /api/admin/* via the global api() helper,
     markup is built as a string and handed to setContent().

     Everything here is provider-neutral in NAME. Provider identity IS shown —
     this is the operator plane, and an operator cannot diagnose a failed
     provisioning without it. Nothing here is reachable by a customer. --}}
<script>
window.BE = (function () {
  const esc = (s) => String(s === null || s === undefined ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const COL = {
    active:'#34D399', enabled:'#34D399', succeeded:'#34D399', verified:'#34D399', healthy:'#34D399',
    provisioning:'#60A5FA', configuring:'#60A5FA', requested:'#60A5FA', running:'#60A5FA', queued:'#60A5FA',
    verifying_dns:'#60A5FA', dns_verified:'#60A5FA',
    reconciling:'#F59E0B', compensation_pending:'#F59E0B', timed_out:'#F59E0B', suspended:'#F59E0B',
    failed_retryable:'#F59E0B', drifted:'#F59E0B', degraded:'#F59E0B', unknown:'#6B7280',
    provisioning_failed:'#F87171', verification_failed:'#F87171', failed_terminal:'#F87171',
    terminated:'#F87171', deleted:'#6B7280', removed:'#6B7280', disabled:'#6B7280',
    critical:'#F87171', high:'#F59E0B', medium:'#FBBF24', low:'#60A5FA', info:'#6B7280'
  };

  const pill = (v) => {
    if (v === null || v === undefined || v === '') return '<span style="color:#6B7280">&mdash;</span>';
    const c = COL[String(v)] || '#6B7280';
    return '<span style="background:'+c+'22;color:'+c+';border-radius:3px;padding:1px 8px;font-size:11px;white-space:nowrap">'+esc(v)+'</span>';
  };

  const yesNo = (v) => v === true ? '<span style="color:#34D399">yes</span>'
    : v === false ? '<span style="color:#6B7280">no</span>'
    : '<span style="color:#6B7280">unknown</span>';

  // "Never measured" must never render as zero. E1 made that a schema rule;
  // this is the same rule at the last mile.
  const num = (v, suffix) => (v === null || v === undefined)
    ? '<span style="color:#6B7280" title="not reported">not reported</span>'
    : esc(v) + (suffix || '');

  const when = (v) => v ? '<span title="'+esc(v)+'">'+esc(String(v).replace('T',' ').substring(0,16))+'</span>'
                        : '<span style="color:#6B7280">&mdash;</span>';

  const card = (title, body, note) =>
    '<div style="border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px">'
    + (title ? '<div style="font-weight:600;font-size:14px;margin-bottom:10px">'+esc(title)+'</div>' : '')
    + (note ? '<div style="font-size:12px;color:var(--muted);margin-bottom:10px">'+note+'</div>' : '')
    + body + '</div>';

  const table = (headers, rows) => {
    if (!rows.length) return empty('Nothing to show yet.');
    let h = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
    h += '<thead><tr>' + headers.map(x =>
      '<th style="text-align:left;padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:500;white-space:nowrap">'+x+'</th>').join('') + '</tr></thead><tbody>';
    rows.forEach(r => { h += '<tr style="border-bottom:1px solid var(--border)">'
      + r.map(c => '<td style="padding:8px 6px;vertical-align:top">'+c+'</td>').join('') + '</tr>'; });
    return h + '</tbody></table></div>';
  };

  const empty = (msg) => '<div style="padding:28px;text-align:center;color:var(--muted);font-size:13px">'+esc(msg)+'</div>';

  const loading = (what) => '<div style="padding:40px;text-align:center;color:var(--muted)">Loading '+esc(what)+'&hellip;</div>';

  const problem = (msg, detail) =>
    '<div style="background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.35);border-radius:8px;padding:16px;margin-bottom:16px">'
    + '<div style="font-weight:600;margin-bottom:4px">'+esc(msg)+'</div>'
    + (detail ? '<div style="font-size:12px;color:var(--muted)">'+esc(detail)+'</div>' : '') + '</div>';

  const notice = (msg) =>
    '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:13px">'+msg+'</div>';

  // Every screen begins with an honest statement of what this area is. An
  // operator who does not know the provider is a fake will misread everything
  // on the page.
  const banner = (provider) => {
    if (!provider || provider.configured !== true) {
      return notice('<b>No email provider is configured.</b> Business Email is inert on this installation. '
        + 'Reads are shown for diagnosis; no action can be performed.');
    }
    const isFake = String(provider.provider || '').indexOf('fake-') === 0;
    return notice((isFake
      ? '<b>Simulated provider.</b> This installation is connected to a deterministic test double '
        + '(<code>'+esc(provider.provider)+'</code>). Nothing here creates a real mailbox.'
      : '<b>Provider connected:</b> <code>'+esc(provider.provider)+'</code>')
      + (provider.viable === false ? ' &mdash; <span style="color:#F87171">this provider is missing a required capability and must not be used.</span>' : ''));
  };

  // Gate-closed and permission-denied are DIFFERENT and must read differently.
  const handle = (err) => {
    if (!err) return problem('Could not load this screen.', 'The request returned nothing.');
    if (err.status === 404 && err.body && err.body.error === 'not_available') {
      return notice('<b>Business Email is not enabled on this installation.</b> '
        + esc(err.body.reason || '') + ' Nothing is missing &mdash; the area is switched off.');
    }
    if (err.status === 403) {
      return problem('You do not have permission to view this.',
        (err.body && err.body.capability) ? ('Required capability: ' + err.body.capability) : '');
    }
    return problem('Could not load this screen.', (err.body && err.body.reason) || ('HTTP ' + err.status));
  };

  // api() swallows the status code, and gate-closed vs forbidden vs error must
  // be told apart, so this reads the response directly.
  const get = async (path) => {
    const r = await fetch('/api/admin/business-email' + path, {
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('access_token') || ''), 'Accept': 'application/json' }
    });
    let body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) { const e = new Error('http'); e.status = r.status; e.body = body; throw e; }
    return body;
  };

  const post = async (path, payload) => {
    const r = await fetch('/api/admin/business-email' + path, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + (localStorage.getItem('access_token') || ''),
        'Accept': 'application/json', 'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload || {})
    });
    let body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    return { status: r.status, body: body };
  };

  // A result must never be rendered as done unless the server said verified.
  const describeResult = (res) => {
    const b = res.body || {};
    if (res.status === 200 && b.verified === true) return { tone:'#34D399', text:'Completed and confirmed.' };
    if (res.status === 202) return { tone:'#F59E0B', text:'Accepted by the service. Not yet confirmed — run a read-back to settle it.' };
    if (res.status === 428) return { tone:'#F59E0B', text: b.reason || 'This action needs confirmation.' };
    if (res.status === 501) return { tone:'#6B7280', text:'This service does not support that action.' };
    if (res.status === 503) return { tone:'#6B7280', text: b.message || 'Business Email is not available.' };
    if (res.status === 403) return { tone:'#F87171', text: b.reason || 'You do not have permission.' };
    if (res.status === 409) return { tone:'#F87171', text: b.reason || 'Refused.' };
    return { tone:'#F87171', text: b.message || b.reason || ('Refused (HTTP ' + res.status + ').') };
  };

  const qs = (k) => new URLSearchParams(window.location.search).get(k);

  return { esc, pill, yesNo, num, when, card, table, empty, loading, problem, notice,
           banner, handle, get, post, describeResult, qs };
})();
</script>
