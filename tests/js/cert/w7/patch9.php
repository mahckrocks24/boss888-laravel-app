<?php
// HIDE-CONNECT-1 (Owner 2026-10-01: "I would like to hide that for now. we want new users to build a new website"):
// the existing-website door is hidden everywhere in the app unless storage/app/connectexisting.on exists.
$root = '/var/www/levelup-staging'; $seen = [];
function rep(string $file, string $old, string $new, int $expect = 1): void {
    global $root, $seen; $p = "$root/$file"; $s = file_get_contents($p); $n = substr_count($s, $old);
    if ($n !== $expect) { fwrite(STDERR, "ANCHOR x$n (want $expect) in $file:\n" . substr($old, 0, 160) . "\n"); exit(1); }
    if (! isset($seen[$file])) { copy($p, "$p.before-hideconnect1"); $seen[$file] = true; }
    file_put_contents($p, str_replace($old, $new, $s)); echo "ok  $file  (x$n)\n";
}

// 1. the route answers only when the switch is present
$f = 'routes/api.php';
rep($f, <<<'X'
Route::middleware(['auth.jwt', 'traffic.defense'])->post('/builder/websites/connect-existing', function (\Illuminate\Http\Request $request) {
X, <<<'X'
Route::middleware(['auth.jwt', 'traffic.defense'])->post('/builder/websites/connect-existing', function (\Illuminate\Http\Request $request) {
    // HIDE-CONNECT-1 (Owner 2026-10-01): new users build a new website; the door stays shut until storage/app/connectexisting.on exists
    if (! is_file(storage_path('app/connectexisting.on'))) return response()->json(['success' => false, 'error' => 'not_available'], 404);
X);

// 2. the flag the app reads
$f = 'routes/api/authenticated/builder-01.php';
rep($f, "'fonts' => \\App\\Engines\\Builder\\Support\\FontPairs::on(), ", "'fonts' => \\App\\Engines\\Builder\\Support\\FontPairs::on(), 'connect_existing' => is_file(storage_path('app/connectexisting.on')), ");

// 3. the Websites page button: hidden until the flag says otherwise
$f = 'public/app/index.html';
rep($f, '<button class="ct-btn" onclick="wsShowConnectModal()" style="border:1px solid rgba(108,92,231,.4);color:var(--pu)">',
        '<button class="ct-btn" id="ws-connect-existing-btn" onclick="wsShowConnectModal()" style="display:none;border:1px solid rgba(108,92,231,.4);color:var(--pu)">');

// 4. builder.js: the flag is read with the site list; the modal refuses while the door is shut
$f = 'public/app/js/builder.js';
rep($f, <<<'X'
async function wsLoadSites(){
  if (!window._luPolicyLoaded && window.LuAPI && LuAPI.refreshPolicy) { window._luPolicyLoaded = 1; try { LuAPI.refreshPolicy(); } catch (_e) {} }
X, <<<'X'
async function wsLoadSites(){
  if (!window._luPolicyLoaded && window.LuAPI && LuAPI.refreshPolicy) { window._luPolicyLoaded = 1; try { LuAPI.refreshPolicy(); } catch (_e) {} }
  // HIDE-CONNECT-1 (Owner 2026-10-01): the existing-website door shows only when the server says so (storage/app/connectexisting.on)
  if (!window._luFlagsPromise) { window._luFlagsPromise = fetch('/api/builder/flags', { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }, cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) { window._luFlags = j || {}; return window._luFlags; }).catch(function () { window._luFlags = window._luFlags || {}; return window._luFlags; }); }
  try { window._luFlagsPromise.then(function (j) { var b = document.getElementById('ws-connect-existing-btn'); if (b) b.style.display = (j && j.connect_existing) ? '' : 'none'; }); } catch (_f) {}
X);
rep($f, <<<'X'
function wsShowConnectModal() {
X, <<<'X'
function wsShowConnectModal() {
  if (!(window._luFlags && window._luFlags.connect_existing)) { if (typeof wsShowCreate === 'function') wsShowCreate(); return; }   // HIDE-CONNECT-1
X);

// 5. Arthur's "Create a Website" picker: one door while the second is shut — straight to Arthur
$f = 'public/app/js/arthur-chat.js';
rep($f, <<<'X'
window._bldShowTemplatePicker = function() {
    var existing = document.getElementById('lu-wizard-picker');
    if (existing) { existing.remove(); return; }
X, <<<'X'
window._bldShowTemplatePicker = function() {
    // HIDE-CONNECT-1 (Owner 2026-10-01): with the existing-website door shut there is one door, so it opens at once
    if (!(window._luFlags && window._luFlags.connect_existing)) { if (typeof window.wsShowCreate === 'function') { window.wsShowCreate(); return; } }
    var existing = document.getElementById('lu-wizard-picker');
    if (existing) { existing.remove(); return; }
X);
echo "HIDE-CONNECT-1 APPLIED\n";
