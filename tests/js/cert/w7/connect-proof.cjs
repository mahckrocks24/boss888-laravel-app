// Connect an existing website (the Websites page's "Connect Your Existing Website") on harness ws 1000104, then look at what the list shows.
const S = 'https://staging.levelupgrowth.io';
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json', 'content-type': 'application/json' };
  const url = process.argv[2] || 'https://example.com';
  const t0 = Date.now();
  const r = await fetch(S + '/api/builder/websites/connect-existing', { method: 'POST', headers: H, body: JSON.stringify({ url }) });
  const txt = await r.text(); let d = null; try { d = JSON.parse(txt); } catch (e) {}
  console.log('connect', r.status, Math.round((Date.now() - t0) / 1000) + 's', (txt || '').slice(0, 600));
  const l = await (await fetch(S + '/api/builder/websites', { headers: H })).json();
  const sites = l.websites || l.data || l;
  console.log('list:', JSON.stringify((Array.isArray(sites) ? sites : []).map(s => ({ id: s.id, type: s.type, status: s.status, name: s.name, external_url: s.external_url, domain: s.domain, thumb: (s.thumbnail_url || '').slice(0, 50) }))).slice(0, 900));
  if (d && d.website_id) {
    const a = await fetch(S + '/api/seo/deep-audit', { method: 'POST', headers: H, body: JSON.stringify({ website_id: d.website_id, url }) });
    console.log('deep-audit', a.status, (await a.text()).slice(0, 300));
  }
})();
