// FIX-ALL proofs over the API on harness site 1016 (ws 1000104): fonts panel backend, the pages note, page-vs-section intent.
const S = 'https://staging.levelupgrowth.io';
const fs = require('fs');
const WHAT = process.argv[2] || 'all';
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const tok = login.access_token || (login.data && login.data.access_token); if (!tok) { console.log('login failed', JSON.stringify(login).slice(0, 200)); process.exit(1); }
  const H = { authorization: 'Bearer ' + tok, accept: 'application/json', 'content-type': 'application/json' };
  const out = {};
  if (WHAT === 'all' || WHAT === 'fonts') {
    const flags = await (await fetch(S + '/api/builder/flags?site=1016', { headers: H })).json();
    console.log('flags', JSON.stringify(flags));
    const f = await (await fetch(S + '/api/builder/websites/1016/fonts', { headers: H })).json();
    console.log('fonts: success', f.success, '| current', f.current, '| static', f.is_static, '| style', JSON.stringify(f.style), '| pairs', (f.pairs || []).length, '| first', (f.pairs || []).slice(0, 4).map(p => p.id + (p.recommended ? '*' : '')).join(','), '| layer bytes', f.pairs && f.pairs[1] && f.pairs[1].layer.length, '| preview css', (f.preview_css || '').slice(0, 80));
    const a = await (await fetch(S + '/api/builder/websites/1016/fonts', { method: 'POST', headers: H, body: JSON.stringify({ pair: 'fraunces_worksans' }) })).json();
    console.log('apply fraunces:', JSON.stringify(a));
    const f2 = await (await fetch(S + '/api/builder/websites/1016/fonts', { headers: H })).json();
    console.log('current now', f2.current);
    const bad = await (await fetch(S + '/api/builder/websites/1016/fonts', { method: 'POST', headers: H, body: JSON.stringify({ pair: 'comic_sans' }) })).json();
    console.log('unknown pair:', JSON.stringify(bad));
    const d = await (await fetch(S + '/api/builder/websites/1016/fonts', { method: 'POST', headers: H, body: JSON.stringify({ pair: 'design' }) })).json();
    console.log("design's own:", JSON.stringify(d));
    const f3 = await (await fetch(S + '/api/builder/websites/1016/fonts', { headers: H })).json();
    console.log('current after revert', f3.current);
    const a2 = await (await fetch(S + '/api/builder/websites/1016/fonts', { method: 'POST', headers: H, body: JSON.stringify({ pair: 'dmserif_dmsans' }) })).json();
    console.log('apply dmserif (left on for the sheet):', JSON.stringify(a2));
  }
  if (WHAT === 'all' || WHAT === 'pages') {
    const brief = 'Crumb and Co is a small artisan bakery at 12 Elm Hill, Norwich NR3 1HN, UK. We bake sourdough, croissants and pastries daily and make custom birthday and wedding cakes to order. Phone 01603 555 214, email hello@crumbandco.co.uk, open Tue–Sun 7am–4pm. Style: warm and welcoming, terracotta and cream. Pages: Home, Menu, Cakes to Order, Contact.';
    const r = await (await fetch(S + '/api/builder/arthur/message', { method: 'POST', headers: H, body: JSON.stringify({ message: brief, history: [] }) })).json();
    console.log('chat type', r.type, '| ready', r.ready_to_confirm, '| pages', JSON.stringify(r.build_data && r.build_data.pages));
    console.log('REPLY >>>\n' + (r.reply || '').slice(0, 1600) + '\n<<<');
    fs.writeFileSync(__dirname + '/pages-reply.txt', r.reply || '');
  }
  if (WHAT === 'all' || WHAT === 'intent') {
    for (const msg of ['add a menu section after the services section', 'add a menu page']) {
      const t0 = Date.now();
      const e = await (await fetch(S + '/api/builder/pages/2077/arthur-edit', { method: 'POST', headers: H, body: JSON.stringify({ message: msg, context: {} }) })).json();
      console.log('EDIT "' + msg + '" ' + Math.round((Date.now() - t0) / 1000) + 's →', JSON.stringify({ success: e.success, kind: e.kind || (e.plan && e.plan.kind), credits: e.credits, applied: e.applied, message: (e.message || e.reply || '').slice(0, 260) }));
    }
  }
})();
