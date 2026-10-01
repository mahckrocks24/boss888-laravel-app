// CONNECT-1 proof on the probe account (ws 1000110): guard without token, refusals, a real connect with our thumbnail, SEO list, no draft link.
const S = 'https://staging.levelupgrowth.io'; const fs = require('fs');
(async () => {
  const acct = JSON.parse(fs.readFileSync(__dirname + '/connect-acct.json', 'utf8'));
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.email, password: 'EdgeTest2026!q' }) })).json();
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json', 'content-type': 'application/json' };
  const post = async (body, headers) => { const r = await fetch(S + '/api/builder/websites/connect-existing', { method: 'POST', headers: headers || H, body: JSON.stringify(body) }); return r.status + ' ' + (await r.text()).slice(0, 160); };
  console.log('no token      ', await post({ url: 'https://example.com' }, { accept: 'application/json', 'content-type': 'application/json' }));
  console.log('private ip    ', await post({ url: 'http://127.0.0.1/' }));
  console.log('private range ', await post({ url: 'http://10.0.0.5/' }));
  console.log('our own site  ', await post({ url: 'https://crumb-four.levelupgrowth.io' }));
  console.log('ftp scheme    ', await post({ url: 'ftp://example.com/' }));
  // remove the earlier probe row so the limit of one allows a fresh connect
  const l0 = await (await fetch(S + '/api/builder/websites', { headers: H })).json();
  for (const s of (l0.websites || l0.data || l0)) { if (s.type === 'external') { const d = await fetch(S + '/api/builder/websites/' + s.id, { method: 'DELETE', headers: H }); console.log('delete', s.id, d.status); } }
  const t0 = Date.now();
  console.log('connect       ', await post({ url: 'https://example.com' }), Math.round((Date.now() - t0) / 1000) + 's');
  const l = await (await fetch(S + '/api/builder/websites', { headers: H })).json();
  const sites = (l.websites || l.data || l).filter(s => s.type === 'external');
  console.log('list:', JSON.stringify(sites.map(s => ({ id: s.id, type: s.type, status: s.status, name: s.name, external_url: s.external_url, platform: s.platform, thumb: s.thumbnail_url, draft_url: s.draft_url }))));
  if (sites[0] && sites[0].thumbnail_url) { const t = await fetch(S + sites[0].thumbnail_url); console.log('thumbnail', t.status, t.headers.get('content-type'), t.headers.get('content-length')); }
  await new Promise(r => setTimeout(r, 45000)); const l2 = await (await fetch(S + '/api/builder/websites', { headers: H })).json(); console.log('list after 45s:', JSON.stringify((l2.websites || l2.data || l2).filter(s => s.type === 'external').map(s => s.thumbnail_url))); if ((l2.websites || l2.data || l2).filter(s => s.type === 'external')[0].thumbnail_url) { const t2 = await fetch(S + (l2.websites || l2.data || l2).filter(s => s.type === 'external')[0].thumbnail_url); console.log('thumbnail', t2.status, t2.headers.get('content-type'), t2.headers.get('content-length')); }
  console.log('seo/sites', (await (await fetch(S + '/api/seo/sites', { headers: H })).text()).slice(0, 300));
})();
