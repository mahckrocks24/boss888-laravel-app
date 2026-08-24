<?php
/**
 * ADMIN FORENSIC AUDIT — multi-page architecture.
 *
 * Rewritten 2026-07-29 for the one-page-per-menu-item admin. The previous
 * version cross-referenced a single 359KB Blade file: nav markup vs an inline
 * title map vs a `pages` object. Those three lists are gone, replaced by
 * config/admin_pages.php, so the drift they used to produce is now structurally
 * impossible and the checks that hunted for it would test nothing.
 *
 * What can still go wrong, and is checked here:
 *
 *   A  registry entry with no view file      -> 500 on click
 *   B  view file with no registry entry      -> unreachable page
 *   C  malformed or duplicate registry entry -> bad URL / collision
 *   D  slug that does not serve              -> broken menu item
 *   E  API path called with no live route    -> 404 at runtime
 *   F  admin route never called by the UI    -> orphan endpoint (info)
 *   G  route -> missing controller/method    -> 500 at runtime
 *   H  admin route missing auth/admin gate   -> SECURITY
 *   I  leftovers from the single-page admin  -> dead code
 *
 * Read-only.
 */
$root = '/var/www/levelup-staging';
require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

function h(string $s): void { echo "\n" . $s . "\n" . str_repeat('-', 76) . "\n"; }
function line(string $s): void { echo '  ' . $s . "\n"; }

$registry = require $root . '/config/admin_pages.php';
$viewDir  = $root . '/resources/views/admin/pages';

$views = [];
foreach (['/*.blade.php', '/*/*.blade.php'] as $g) {
    foreach (glob($viewDir . $g) as $f) {
        $views[] = str_replace([$viewDir . '/', '.blade.php'], '', $f);
    }
}
sort($views);

// JS the admin actually ships: shared bundles + every page's inline renderer.
$js = '';
foreach (['admin-core.js', 'admin-shared.js', 'admin-bella.js'] as $f) {
    $p = $root . '/public/js/' . $f;
    if (is_file($p)) { $js .= file_get_contents($p) . "\n"; }
}
$pageJs = '';
foreach (['/*.blade.php', '/*/*.blade.php'] as $g) {
    foreach (glob($viewDir . $g) as $f) { $pageJs .= file_get_contents($f) . "\n"; }
}
$allJs = $js . $pageJs;

// ── API paths the UI calls ──────────────────────────────────────────────
$apiPaths = [];
preg_match_all("/\bapi\(\s*'(\/[^'?]+)/", $allJs, $a1);
foreach ($a1[1] as $p) { $apiPaths[] = 'api/admin' . rtrim($p, '/'); }
preg_match_all("/_adsCall\(\s*'(\/[^'?]+)/", $allJs, $a2);
foreach ($a2[1] as $p) { $apiPaths[] = 'api/admin' . rtrim($p, '/'); }
preg_match_all("/fetch\(\s*'(\/api\/[^'?]+)/", $allJs, $a3);
foreach ($a3[1] as $p) { $apiPaths[] = rtrim(ltrim($p, '/'), '/'); }
$apiPaths = array_values(array_unique(array_map(fn ($p) => rtrim($p, '/'), $apiPaths)));
$apiPaths = array_values(array_filter($apiPaths, fn ($p) => $p !== 'api/admin' && $p !== 'api'));

// ── live admin routes ───────────────────────────────────────────────────
$routes = [];
foreach (Route::getRoutes()->getRoutes() as $r) {
    if (! str_starts_with($r->uri(), 'api/admin')) { continue; }
    $routes[] = [
        'uri' => $r->uri(),
        'base' => rtrim(explode('{', $r->uri())[0], '/'),
        'verbs' => array_values(array_diff($r->methods(), ['HEAD'])),
        'action' => $r->getActionName(),
        'mw' => $r->gatherMiddleware(),
    ];
}
function matchesRoute(string $path, array $routes): bool {
    foreach ($routes as $r) {
        if ($path === $r['uri'] || $path === $r['base']) { return true; }
        if ($r['base'] !== '' && str_starts_with($path, $r['base'] . '/')) { return true; }
        $re = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($r['uri'], '#')) . '$#';
        if (@preg_match($re, $path)) { return true; }
    }
    return false;
}

echo "\n════════════════════════════════════════════════════════════════════════════\n";
echo " ADMIN FORENSIC AUDIT — multi-page\n";
echo "════════════════════════════════════════════════════════════════════════════\n";

h('INVENTORY');
line('registry entries   : ' . count($registry));
line('page view files    : ' . count($views));
line('API paths called   : ' . count($apiPaths));
line('admin routes live  : ' . count($routes));
line('shared JS bytes    : ' . strlen($js));
line('page JS bytes      : ' . strlen($pageJs));

// A ──────────────────────────────────────────────────────────────────────
$noView = [];
foreach ($registry as $k => $e) {
    if (! in_array($e['slug'], $views, true)) { $noView[] = $k . ' (' . $e['slug'] . ')'; }
}
h('A. REGISTRY ENTRIES WITH NO VIEW FILE  (500 on click)');
if (! $noView) { line('none — every menu item has a renderer'); }
else { foreach ($noView as $x) { line('NO VIEW  ' . $x); } }

// B ──────────────────────────────────────────────────────────────────────
$slugs = array_column($registry, 'slug');
$orphanViews = array_values(array_diff($views, $slugs));
h('B. VIEW FILES WITH NO REGISTRY ENTRY  (unreachable page)');
if (! $orphanViews) { line('none'); }
else { foreach ($orphanViews as $x) { line('ORPHAN  ' . $x); } }

// C ──────────────────────────────────────────────────────────────────────
$malformed = [];
foreach ($registry as $k => $e) {
    foreach (['slug', 'group', 'label', 'title', 'icon'] as $f) {
        if (empty($e[$f])) { $malformed[] = "$k missing $f"; }
    }
    if (! preg_match('#^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*$#', $e['slug'] ?? '')) {
        $malformed[] = "$k bad slug '" . ($e['slug'] ?? '') . "'";
    }
}
if (count(array_unique($slugs)) !== count($slugs)) {
    $dupes = array_keys(array_filter(array_count_values($slugs), fn ($c) => $c > 1));
    foreach ($dupes as $d) { $malformed[] = "duplicate slug '$d'"; }
}
h('C. MALFORMED OR DUPLICATE REGISTRY ENTRIES');
if (! $malformed) { line('none — ' . count($slugs) . ' entries, ' . count(array_unique($slugs)) . ' distinct slugs'); }
else { foreach ($malformed as $x) { line('BAD  ' . $x); } }

// D ──────────────────────────────────────────────────────────────────────
// Resolve each slug through the real router rather than over HTTP, so the
// audit works without network and cannot be fooled by a cache.
$unroutable = [];
foreach ($registry as $k => $e) {
    $req = Illuminate\Http\Request::create('/admin/' . $e['slug'], 'GET');
    try {
        $matched = Route::getRoutes()->match($req);
        if (! str_contains((string) $matched->getActionName(), 'AdminPageController')) {
            $unroutable[] = $e['slug'] . ' -> ' . $matched->getActionName();
        }
    } catch (Throwable $ex) {
        $unroutable[] = $e['slug'] . ' -> ' . $ex->getMessage();
    }
}
h('D. SLUGS THAT DO NOT ROUTE TO THE ADMIN CONTROLLER');
if (! $unroutable) { line('all ' . count($registry) . ' slugs route to AdminPageController'); }
else { foreach ($unroutable as $x) { line('UNROUTED  ' . $x); } }

// E ──────────────────────────────────────────────────────────────────────
$missing = [];
foreach ($apiPaths as $p) { if (! matchesRoute($p, $routes)) { $missing[] = $p; } }
h('E. API PATHS CALLED BY THE UI WITH NO MATCHING ROUTE  (404 at runtime)');
if (! $missing) { line('none — every call the admin makes has a live route'); }
else { foreach ($missing as $p) { line('NO ROUTE  ' . $p); } }

// F ──────────────────────────────────────────────────────────────────────
$orphanRoutes = [];
foreach ($routes as $r) {
    $hit = false;
    foreach ($apiPaths as $p) {
        if ($p === $r['uri'] || $p === $r['base'] || str_starts_with($p, $r['base'] . '/')
            || str_starts_with($r['base'], $p)) { $hit = true; break; }
    }
    if (! $hit) { $orphanRoutes[] = implode(',', $r['verbs']) . ' ' . $r['uri']; }
}
h('F. ADMIN ROUTES NOT REFERENCED BY THE UI  (informational: API-only / CLI)');
line(count($orphanRoutes) . ' of ' . count($routes) . ' not referenced');

// G ──────────────────────────────────────────────────────────────────────
$broken = [];
foreach ($routes as $r) {
    if ($r['action'] === 'Closure' || ! str_contains($r['action'], '@')) { continue; }
    [$class, $method] = explode('@', $r['action']);
    if (! class_exists($class)) { $broken[] = $r['uri'] . ' -> class missing: ' . $class; continue; }
    if (! method_exists($class, $method)) { $broken[] = $r['uri'] . ' -> method missing: ' . $method; }
}
h('G. ROUTES WHOSE CONTROLLER OR METHOD DOES NOT EXIST  (500 at runtime)');
if (! $broken) { line('none — every controller route resolves'); }
else { foreach ($broken as $b) { line('BROKEN  ' . $b); } }

// H ──────────────────────────────────────────────────────────────────────
$adminClass = \App\Http\Middleware\AdminMiddleware::class;
$hasAdminGate = fn (array $mw) => in_array('admin', $mw, true)
    || in_array($adminClass, $mw, true) || in_array('\\' . $adminClass, $mw, true);

$exemptReason = [
    'api/admin/auth' => 'login endpoint — must be reachable unauthenticated',
    // VERIFIED by reading App\Http\Controllers\Auth\MfaController: each acts on
    // $request->user() and nothing else — self-service enrolment of the
    // caller's own factor, not a privileged operation on another account. Still
    // behind auth.jwt and DenyApiKeyAuth. Namespace inconsistency, not a gap.
    'api/admin/mfa/enrol'          => 'self-service on $request->user()',
    'api/admin/mfa/confirm'        => 'self-service on $request->user()',
    'api/admin/mfa/verify'         => 'self-service on $request->user()',
    'api/admin/mfa/recovery-codes' => 'self-service on $request->user()',
];
$ungated = []; $exempt = [];
foreach ($routes as $r) {
    if (in_array('auth.jwt', $r['mw'], true) && $hasAdminGate($r['mw'])) { continue; }
    $entry = implode(',', $r['verbs']) . ' ' . $r['uri'];
    if (isset($exemptReason[$r['uri']])) { $exempt[] = $entry . '  <- ' . $exemptReason[$r['uri']]; }
    else { $ungated[] = $entry . '   [' . implode(',', $r['mw']) . ']'; }
}
h('H. ADMIN ROUTES MISSING auth.jwt OR THE ADMIN GATE  (SECURITY)');
if (! $ungated) { line('none — every admin route carries both, or is a reviewed exception'); }
else { foreach ($ungated as $u) { line('UNGATED  ' . $u); } }
line('');
line('reviewed exceptions (' . count($exempt) . '):');
foreach ($exempt as $e) { line('  ' . $e); }

// I ──────────────────────────────────────────────────────────────────────
$leftovers = [];
if (is_file($root . '/resources/views/admin/app.blade.php')) { $leftovers[] = 'app.blade.php still present'; }
if (is_file($root . '/public/js/admin-pages.js')) { $leftovers[] = 'admin-pages.js still present'; }
foreach (['admin-section', 'admin-houseAccount', 'admin-media', 'bella_token'] as $needle) {
    $n = substr_count($allJs, $needle);
    if ($n > 0) { $leftovers[] = "$needle appears $n time(s)"; }
}
h('I. LEFTOVERS FROM THE SINGLE-PAGE ADMIN  (dead code)');
if (! $leftovers) { line('none — the monolith and its dead legacy blocks are gone'); }
else { foreach ($leftovers as $x) { line('LEFTOVER  ' . $x); } }

$blocking = count($noView) + count($orphanViews) + count($malformed)
          + count($unroutable) + count($missing) + count($broken) + count($ungated);

echo "\n════════════════════════════════════════════════════════════════════════════\n";
printf(" BLOCKING  A=%d B=%d C=%d D=%d E=%d G=%d H=%d   total=%d\n",
    count($noView), count($orphanViews), count($malformed), count($unroutable),
    count($missing), count($broken), count($ungated), $blocking);
printf(" HYGIENE   I=%d leftovers\n", count($leftovers));
printf(" INFO      F=%d unreferenced routes   %d reviewed exceptions\n",
    count($orphanRoutes), count($exempt));
echo "════════════════════════════════════════════════════════════════════════════\n";
