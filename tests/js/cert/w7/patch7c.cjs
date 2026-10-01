const fs = require('fs'); const P = '/var/www/levelup-staging/public/app/js/builder.js'; let s = fs.readFileSync(P, 'utf8');
const old = "  document.body.insertAdjacentHTML('beforeend', html);\n  window._t3SiteStatus = site.status || ''; window._t3IndustrySlug = '';   // EDITOR-3";
if (s.split(old).length !== 2) { console.error('anchor x' + (s.split(old).length - 1)); process.exit(1); }
s = s.replace(old, "  // EDITOR-ONCE-1 (fix-all 2026-10-01): /app/websites/{id}?edit={id} opened the editor twice on a reload — the router and the back\n  // guard's resume both call wsOpenSite — and the second, empty view sat on top of the first: a blank stage, panels unreachable.\n  var _exView = document.getElementById('template-editor-view');\n  if (_exView) { var _exFrame = _exView.querySelector('#t3-preview'); if (_exFrame && String(_exFrame.getAttribute('data-site')) === String(wsId)) return; _exView.remove(); }\n" + old);
fs.writeFileSync(P, s); console.log('EDITOR-ONCE-1 patched');
