<?php

/**
 * CUTOVER (2026-09-10) — serve the rebuilt marketing site at the ROOT of the production hosts.
 *
 * Included from the END of routes/web.php, on purpose. Laravel's RouteCollection keys on
 * method+URI and OVERWRITES, so for a URI defined in both places the LAST registration wins.
 * Included first (the intuitive choice) every legacy public/marketing/*.html route below
 * silently re-claimed / and /pricing while the new-only pages worked — which looks exactly
 * like a half-broken deploy. Deleting the include line is the complete rollback: the legacy
 * routes are untouched and serve again immediately.
 *
 * Only EXPLICIT routes are registered — one per built page, plus the site's own assets. There
 * is deliberately NO catch-all: a catch-all here would swallow /app, /api, /storage, /admin/*,
 * /plugin-connect and the published-customer-site host routing, and that failure would present
 * as the whole platform going down rather than as a marketing bug.
 *
 * The route list is derived from the build output at boot, so a rebuild needs no code change.
 */

use Illuminate\Support\Facades\Route;

$mnDist = public_path('marketing-next/dist-root');
if (! is_dir($mnDist)) {
    return;   // not built yet — the legacy marketing site keeps serving
}

/**
 * Serve a built file with the right type and a cache window Cloudflare can honour.
 *
 * Content-Type comes from the EXTENSION and is never guessed. response()->file() otherwise falls
 * back to Symfony's finfo guesser, which reads the file's bytes — and for text files that answers
 * 'text/plain'. Combined with the platform's X-Content-Type-Options: nosniff, the browser refused
 * the stylesheet and the script outright ("Refused to apply style ... MIME type ('text/plain')"),
 * so the cut-over site rendered completely unstyled while every status-code check returned 200.
 * Only the responsive sweep caught it.
 */
$mnTypes = [
    'html'  => 'text/html; charset=UTF-8',
    'css'   => 'text/css; charset=UTF-8',
    'js'    => 'application/javascript; charset=UTF-8',
    'xml'   => 'application/xml; charset=UTF-8',
    'json'  => 'application/json',
    'svg'   => 'image/svg+xml',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'webp'  => 'image/webp',
    'gif'   => 'image/gif',
    'ico'   => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff'  => 'font/woff',
    'txt'   => 'text/plain; charset=UTF-8',
    'mp4'   => 'video/mp4',
];

$mnServe = static function (string $file, int $maxAge) use ($mnTypes) {
    if (! is_file($file)) {
        abort(404);
    }
    // PRE-LAUNCH (2026-09-10, Owner: "we are not launching yet so keep LUG undiscoverable").
    // X-Robots-Tag is served on EVERY marketing response, not just the HTML: it is the only
    // signal that covers the sitemap, the RSS feed and the assets, and it is honoured even when
    // a crawler never parses the document. The baked-in <meta robots> stays as well - two
    // independent signals, because a rebuild with --live would silently drop the meta one.
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    // 2026-09-12 (Owner: Arthur "sometimes blank, sometimes gone"): a page cached for 5 minutes was being paired with
    // scripts rebuilt many times a day. HTML now always revalidates (Last-Modified/ETag, cheap); assets keep their TTL.
    $headers = ['Cache-Control' => ($ext === 'html' ? 'no-cache, must-revalidate' : 'public, max-age=' . $maxAge), 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
    if (isset($mnTypes[$ext])) {
        $headers['Content-Type'] = $mnTypes[$ext];
    }
    return response()->file($file, $headers);
};

// ── the built pages ───────────────────────────────────────────────────────────────────────
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mnDist, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getFilename() !== 'index.html') {
        continue;
    }
    $rel   = substr($f->getPathname(), strlen($mnDist));
    $route = str_replace('\\', '/', dirname($rel));
    $route = ($route === '/' || $route === '.' || $route === '') ? '/' : $route;
    if ($route === '/404') {
        continue;   // reachable as a page would turn a 404 into a 200
    }
    $file = $f->getPathname();
    Route::get($route, static fn () => $mnServe($file, 300));
}

// ── the site's own assets (they live under dist-root, not at the webroot) ────────────────
Route::get('/site.css', static fn () => $mnServe($mnDist . '/site.css', 86400));
Route::get('/site.js', static fn () => $mnServe($mnDist . '/site.js', 86400));
Route::get('/sitemap.xml', static fn () => $mnServe($mnDist . '/sitemap.xml', 3600));
Route::get('/blog/feed.xml', static fn () => $mnServe($mnDist . '/blog/feed.xml', 3600));
Route::get('/assets/{path}', static function (string $path) use ($mnDist, $mnServe) {
    // Contained to the assets directory: the resolved realpath must still sit inside it.
    $base   = realpath($mnDist . '/assets');
    $target = realpath($mnDist . '/assets/' . $path);
    if ($base === false || $target === false || ! str_starts_with($target, $base)) {
        abort(404);
    }
    return $mnServe($target, 31536000);
})->where('path', '.*');

// ── 301s from the legacy marketing URLs that no longer exist ─────────────────────────────
// Only pages that are GONE are redirected. /pricing, /about, /contact, /blog and / exist in the
// new site and are served above. /admin/*, /sign-up and /plugin-connect are app functions and are
// deliberately absent from this map — they must keep working exactly as they do today.
$mnGone = [
    '/ai-agents'    => '/product/ai-workforce',
    '/specialists'  => '/product/ai-workforce',
    '/ai-assistant' => '/product/ai-workforce',
    '/assistant'    => '/product/ai-workforce',
    '/builder'      => '/product/website-builder',
    '/calendar'     => '/product/calendar',
    '/creative'     => '/product/creative',
    '/crm'          => '/product/crm',
    '/video'        => '/product/video',
    '/email'        => '/product',
    '/features'     => '/product',
    '/comparison'   => '/compare',
    '/faq'          => '/help',
    '/use-cases'    => '/solutions',
    '/how-it-works' => '/',
    '/results'      => '/',
];
foreach ($mnGone as $from => $to) {
    Route::get($from, static fn () => redirect($to, 301)->header('X-Robots-Tag', 'noindex, nofollow, noarchive'));
}

// ── the legacy URL space that is still reachable ─────────────────────────────────────────
// PRE-LAUNCH (2026-09-10): two routes in web.php still served the OLD marketing site, which has
// no robots meta at all and is the version Google has actually indexed for months:
//   web.php:253  Route::get('/pages/{slug}')   — the old site's own canonical URL space
//                (the old pricing page declares <link rel=canonical .../pages/pricing/>)
//   web.php:588  Route::get('/blog/{slug}')    — the old blog post shell
// Both are re-registered here, so this file OWNS the whole legacy surface. Same-URI registrations
// overwrite, so these take the ORIGINAL positions of those two routes — which is why /blog/{slug}
// must serve a real post when one exists rather than blanket-redirecting: it is matched BEFORE the
// per-post routes registered above.
Route::get('/pages/{slug}', static fn (string $slug) => redirect('/', 301)
    ->header('X-Robots-Tag', 'noindex, nofollow, noarchive'))->where('slug', '.*');

Route::get('/blog/{slug}', static function (string $slug) use ($mnDist, $mnServe) {
    $post = $mnDist . '/blog/' . trim($slug, '/') . '/index.html';
    if (is_file($post)) {
        return $mnServe($post, 300);
    }
    return redirect('/blog', 301)->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
})->where('slug', '[a-z0-9\-]+');
