/**
 * BUILDER888 D0 regression — every Builder handler the served UI wires must exist.
 *
 *   node tests/js/builder-handlers-defined.test.cjs
 *
 * 2026-08-28: "Edit Page" opened a canvas-editor shell whose handlers
 * (bldOpenEditor, bldLeftTab, bldRenderCanvas, 20 in all) had been removed
 * (2026-04-17) and stubbed (2026-05-05). The customer saw "Loading page…"
 * forever plus a ReferenceError. No test looked at whether an onclick target
 * was defined, so the dead shell survived four months of green suites.
 *
 * This test reads the SERVED files (public/app/index.html + the scripts it
 * loads) and asserts that every `onclick="name("` / `onkeydown` / inline-call
 * whose name is Builder-owned (bld*, ws*, _ws*, _t3*) resolves to a real
 * definition somewhere in the served bundle. It also asserts the dead shell
 * stays gone.
 */
var assert = require('assert');
var fs = require('fs');
var path = require('path');

var ROOT = path.resolve(__dirname, '../../public/app');
var html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');

// Every script the page actually loads, in order.
var scripts = [];
html.replace(/<script[^>]+src="\/app\/js\/([^"?]+)(?:\?[^"]*)?"/g, function (_m, f) { scripts.push(f); return _m; });
assert.ok(scripts.indexOf('builder.js') !== -1, 'index.html loads builder.js');

var bundle = html + '\n' + scripts.map(function (f) {
  var p = path.join(ROOT, 'js', f);
  return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';
}).join('\n');

var builderJs = fs.readFileSync(path.join(ROOT, 'js', 'builder.js'), 'utf8');

// Handler names wired from HTML attributes (index.html + string templates in builder.js).
var wired = {};
var attrRe = /on(?:click|keydown|change|submit|input)\s*=\s*(?:\\?["']|&quot;)\s*(?:if\s*\([^)]*\)\s*\{\s*)?([A-Za-z_$][\w$]*)\s*\(/g;
[html, builderJs].forEach(function (src) {
  var m;
  while ((m = attrRe.exec(src))) {
    var name = m[1];
    if (/^(bld|ws|_ws|_t3)/.test(name)) wired[name] = (wired[name] || 0) + 1;
  }
});
// Direct calls made by the page-editor path.
['wsEditSitePage', '_wsShowPageEditor', '_wsPageEditorReload', '_wsClosePageEditor', '_wsPageEditorSetDevice', '_t3ArthurSend', 'wsPublishFromEditor']
  .forEach(function (n) { wired[n] = (wired[n] || 0) + 1; });

function isDefined(name) {
  var q = name.replace(/\$/g, '\\$');
  var re = new RegExp('(?:^|[^\\w$])(?:async\\s+)?function\\s+' + q + '\\s*\\(|(?:^|[^\\w$.])(?:var|let|const)\\s+' + q + '\\s*=|window\\.' + q + '\\s*=|(?:^|[^\\w$.])' + q + '\\s*=\\s*(?:async\\s*)?function', 'm');
  return re.test(bundle);
}

var pass = 0, fail = 0;
function check(name, ok, detail) {
  if (ok) { pass++; console.log('  ✔ ' + name); }
  else { fail++; console.log('  ✘ ' + name + (detail ? ' — ' + detail : '')); }
}

var names = Object.keys(wired).sort();
check('at least 20 Builder handlers are wired in the served UI', names.length >= 20, String(names.length));
names.forEach(function (n) { check('handler defined: ' + n, isDefined(n)); });

// The dead canvas-editor shell must not come back.
check('#view-builder dead shell absent from index.html', html.indexOf('id="view-builder"') === -1);
check('no bldLeftTab call site in served bundle', !/bldLeftTab\s*\(/.test(bundle));
check('wsEditSitePage no longer routes to the stubbed bldOpenEditor', !/bldOpenEditor\s*\(\s*pageId/.test(builderJs));
check('page editor opens a sandboxed preview (no allow-same-origin)', /id="t3-preview"[^>]*sandbox="[^"]*allow-scripts[^"]*"/.test(builderJs) && !/id="t3-preview"[^>]*sandbox="[^"]*allow-same-origin/.test(builderJs));
check('WITH DOMAIN stat counts custom_domain', /custom_domain\s*\|\|\s*s\.domain/.test(builderJs));

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
