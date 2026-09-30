// Fail-first edges against the real API as a signed-in customer (the bakery account unless noted).
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const acct = JSON.parse(fs.readFileSync(path.join(__dirname, 'run-bakery', 'account.json'), 'utf8'));
const out = (k, v) => console.log(k.padEnd(34), typeof v === 'string' ? v : JSON.stringify(v).slice(0, 420));
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json' };
  // 1. addresses: taken, reserved, junk, unicode, very long
  for (const slug of ['my-site', 'crumb-and-co', 'admin', 'www', 'app', 'staging', 'api', 'levelupgrowth', 'mail', 'a', 'x--y', '-lead', 'UPPER', 'café', 'مطعم', 'a'.repeat(70), 'jay kicks', "o'neil"]) {
    const r = await fetch(S + '/api/builder/check-subdomain?slug=' + encodeURIComponent(slug) + '&exclude=0', { headers: H }); out('check-subdomain ' + slug.slice(0, 20), r.status + ' ' + (await r.text()).slice(0, 160));
  }
  // 2. hostile uploads
  const up = async (route, name, type, buf) => { const f = new FormData(); f.append(route.includes('logo') ? 'logo' : 'image', new Blob([buf], { type }), name); const r = await fetch(S + '/api/builder/' + route, { method: 'POST', headers: H, body: f }); return r.status + ' ' + (await r.text()).slice(0, 200); };
  out('logo: html as .png', await up('logo-upload-temp', 'evil.png', 'image/png', Buffer.from('<html><script>alert(1)</script></html>')));
  out('logo: svg with script', await up('logo-upload-temp', 'evil.svg', 'image/svg+xml', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>')));
  out('logo: php as .png', await up('logo-upload-temp', 'shell.php.png', 'image/png', Buffer.from('<?php echo 1; ?>')));
  out('logo: 5 MB', await up('logo-upload-temp', 'big.png', 'image/png', Buffer.concat([fs.readFileSync(path.join(__dirname, 'logo.png')), Buffer.alloc(5 * 1024 * 1024)])));
  out('image: exe as .jpg', await up('image-upload-temp', 'x.jpg', 'image/jpeg', Buffer.from('MZ\x90\x00this is not an image')));
  out('logo: real png', await up('logo-upload-temp', 'logo.png', 'image/png', fs.readFileSync(path.join(__dirname, 'logo.png'))));
  // 3. the built site's HTML endpoint: can another account read this workspace's draft?
  const other = JSON.parse(fs.readFileSync(path.join(__dirname, 'run-vague-phone', 'account.json'), 'utf8'));
  const lo = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: other.address, password: 'CertRun2026!x' }) })).json();
  const H2 = { authorization: 'Bearer ' + lo.access_token, accept: 'application/json' };
  for (const u of ['/api/builder/websites/997', '/api/builder/websites/997/pages', '/api/builder/websites/997/preview']) { const r = await fetch(S + u, { headers: H2 }); out('cross-tenant GET ' + u.replace('/api/builder/websites', ''), r.status + ' ' + (await r.text()).slice(0, 120)); }
  const r2 = await fetch(S + '/api/builder/websites/997/publish', { method: 'POST', headers: { ...H2, 'content-type': 'application/json' }, body: '{}' }); out('cross-tenant POST publish 997', r2.status + ' ' + (await r2.text()).slice(0, 120));
  const r3 = await fetch(S + '/api/builder/pages/2038/arthur-edit', { method: 'POST', headers: { ...H2, 'content-type': 'application/json' }, body: JSON.stringify({ instruction: 'Change the hero heading to HACKED' }) }); out('cross-tenant arthur-edit 2038', r3.status + ' ' + (await r3.text()).slice(0, 160));
  const d = await fetch(S + '/storage/sites/997/index.html'); out('unauth draft file /storage/sites/997', d.status + ' (anyone with the id can read the draft)');
})();
