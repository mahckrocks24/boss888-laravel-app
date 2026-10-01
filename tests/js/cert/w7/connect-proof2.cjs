// Fresh account (0 websites) -> connect an existing site by URL -> list -> the deep-audit call the modal fires.
const S = 'https://staging.levelupgrowth.io'; const fs = require('fs');
(async () => {
  const email = 'lug-w7-' + Date.now().toString(36) + '@uberip.com';
  const reg = await (await fetch(S + '/api/auth/register', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ name: 'Connect Probe', email, password: 'EdgeTest2026!q', password_confirmation: 'EdgeTest2026!q', company: 'Connect Probe Ltd' }) })).json();
  const tok = reg.access_token; if (!tok) { console.log('register failed', JSON.stringify(reg).slice(0, 300)); process.exit(1); }
  console.log('ws', reg.current_workspace_id, email);
  fs.writeFileSync(__dirname + '/connect-acct.json', JSON.stringify({ email, ws: reg.current_workspace_id }));
  const H = { authorization: 'Bearer ' + tok, accept: 'application/json', 'content-type': 'application/json' };
  const url = process.argv[2] || 'https://example.com';
  const t0 = Date.now();
  const r = await fetch(S + '/api/builder/websites/connect-existing', { method: 'POST', headers: H, body: JSON.stringify({ url }) });
  const txt = await r.text(); let d = null; try { d = JSON.parse(txt); } catch (e) {}
  console.log('connect', r.status, Math.round((Date.now() - t0) / 1000) + 's', txt.slice(0, 500));
  const l = await (await fetch(S + '/api/builder/websites', { headers: H })).json();
  const sites = l.websites || l.data || l;
  console.log('list:', JSON.stringify((Array.isArray(sites) ? sites : []).map(s => ({ id: s.id, type: s.type, status: s.status, name: s.name, external_url: s.external_url, domain: s.domain, platform: s.platform, thumb: (s.thumbnail_url || '').slice(0, 60), draft_url: s.draft_url }))).slice(0, 900));
  if (d && d.website_id) {
    for (const body of [{ website_id: d.website_id }, { url }]) {
      const a = await fetch(S + '/api/seo/deep-audit', { method: 'POST', headers: H, body: JSON.stringify(body) });
      console.log('deep-audit', JSON.stringify(body), a.status, (await a.text()).slice(0, 300));
    }
    const seo = await (await fetch(S + '/api/seo/sites', { headers: H })).text(); console.log('seo/sites', seo.slice(0, 300));
  }
})();
