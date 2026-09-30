// Build-level edges on a fresh account via the same API the wizard uses: script in the business name, double submit, low credits.
const S = 'https://staging.levelupgrowth.io';
(async () => {
  const email = 'lug-edge-' + Date.now().toString(36) + '@uberip.com';
  const reg = await (await fetch(S + '/api/auth/register', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ name: 'Edge Tester', email, password: 'EdgeTest2026!q', password_confirmation: 'EdgeTest2026!q' }) })).json();
  const H = { authorization: 'Bearer ' + reg.access_token, accept: 'application/json', 'content-type': 'application/json' }; console.log('ws', reg.current_workspace_id);
  const history = []; const say = async (m) => { const r = await (await fetch(S + '/api/builder/arthur/message', { method: 'POST', headers: H, body: JSON.stringify({ message: m, history }) })).json(); history.push({ role: 'user', content: m }, { role: 'assistant', content: r.reply || '' }); return r; };
  const r1 = await say('My cafe is called <img src=x onerror=alert(1)>Bean"Scene</script><script>alert(2)</script>. We are a coffee shop in Bristol, 5 Park Street, phone 0117 555 0100. Modern look.');
  console.log('ARTHUR on hostile name:', (r1.reply || '').slice(0, 300).replace(/\s+/g, ' '), '| type', r1.type, '| build_data name:', r1.build_data && r1.build_data.business_name);
  const r2 = r1.type === 'confirm' || r1.build_data ? r1 : await say('Yes that is right, the name is exactly as I typed it. Go ahead.');
  console.log('turn2 type', r2.type, 'name:', r2.build_data && r2.build_data.business_name);
  // double submit: two confirm calls at once
  const confirm = () => fetch(S + '/api/builder/arthur/message', { method: 'POST', headers: H, body: JSON.stringify({ confirm: true, build_data: r2.build_data || r1.build_data || {} }) }).then(async r => ({ s: r.status, j: await r.json().catch(() => ({})) }));
  const t0 = Date.now(); const [a, b] = await Promise.all([confirm(), confirm()]);
  console.log('DOUBLE SUBMIT', Math.round((Date.now() - t0) / 1000) + 's', '| A', a.s, a.j.build_outcome, a.j.website_id, a.j.credits_charged, a.j.build_error || '', '| B', b.s, b.j.build_outcome, b.j.website_id, b.j.credits_charged, b.j.build_error || '');
  const sites = await (await fetch(S + '/api/builder/websites', { headers: H })).json(); const list = sites.websites || sites.data || sites; console.log('websites now:', Array.isArray(list) ? list.map(w => w.id + ':' + (w.name || w.title)) : list);
  const id = a.j.website_id || b.j.website_id; if (id) { const html = await (await fetch(S + '/storage/sites/' + id + '/index.html')).text(); console.log('XSS in built HTML: raw <script>alert(2)', /<script>alert\(2\)/.test(html), '| raw onerror=alert', /<img[^>]+onerror=alert/i.test(html), '| escaped form present', /&lt;script&gt;|&lt;img/.test(html)); }
  require('fs').writeFileSync(__dirname + '/edge-account.json', JSON.stringify({ email, ws: reg.current_workspace_id, tok: reg.access_token, build_data: r2.build_data || r1.build_data }));
})();
