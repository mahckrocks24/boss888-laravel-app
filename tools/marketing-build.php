#!/usr/bin/env php
<?php
/**
 * levelupgrowth.io rebuild — static site builder.
 *
 * Renders marketing-next/src/pages/** (PHP templates, outside the webroot) through src/layout.php into
 * public/marketing-next/dist/** as static HTML, plus the dynamic routes src/dynamic.php
 * produces from the platform (blog posts, categories). Copies src/assets and site.css/js,
 * writes sitemap.xml, blog/feed.xml and a build stamp. Data comes from src/data.php so no
 * number is ever typed into a page.
 *
 *   php tools/marketing-build.php            build everything
 *   php tools/marketing-build.php --list     list routes only
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__) . '/public/marketing-next';
// P0-1 (2026-09-07, REPORT-MARKETING-NEXT-FORENSIC): the PHP source is NOT under the webroot any more — nginx passed every
// *.php under public/ to php-fpm, so src/data.php (which reads .env) was executable over the web. Only dist/ is public.
$SRC = dirname(__DIR__) . '/marketing-next/src';
$DIST = $ROOT . '/dist';
// CUTOVER (2026-09-10) — the site was always authored for the apex: layout.php builds every canonical and
// og:url from $site['url'] . $page['route'], and the sitemap already lists https://levelupgrowth.io/... .
// The ONLY thing tying the build to the preview is the '/next/' literal in hand-written hrefs (139 of them
// across 29 source files, 5,556 occurrences in the emitted HTML). Rather than refactor 29 untracked files
// mid-cutover, the prefix is a BUILD PARAMETER applied to the emitted bytes — which is what a base-path
// helper would have produced anyway, at the one layer where every occurrence is visible.
//   --base=/next/  (default) -> the preview at /next/, unchanged
//   --base=/                 -> the apex build
//   --dist=<abs path>        -> emit somewhere other than dist/, so a cutover build never overwrites the preview
$BASE = '/next/';
foreach ($argv as $a) {
    if (str_starts_with($a, '--base=')) { $BASE = substr($a, 7); if ($BASE === '' || $BASE[0] !== '/') { $BASE = '/' . $BASE; } if (!str_ends_with($BASE, '/')) { $BASE .= '/'; } }
    if (str_starts_with($a, '--dist=')) { $DIST = rtrim(substr($a, 7), '/'); }
}
/** Rewrite the preview prefix to the configured base. No-op when the base IS the preview prefix. */
$rebase = static function (string $t) use ($BASE): string { return $BASE === '/next/' ? $t : str_replace('/next/', $BASE, $t); };
$INTERNAL = dirname(__DIR__) . '/marketing-next/internal-dist';   // P0-2: internal pages render here, never into dist
$list = in_array('--list', $argv, true);

require $SRC . '/helpers.php';
require $SRC . '/og.php';
if (in_array('--live', $argv, true)) { $GLOBALS['MN_PREVIEW'] = false; }   // CUTOVER: emit an indexable build
$data = require $SRC . '/data.php';
$data['api_base'] = $data['api_base'] ?? 'https://staging.levelupgrowth.io';

// dynamic routes (blog) — the platform is the source; pages may read $data['blog']
$dynamic = ['routes' => [], 'data' => []];
if (is_file($SRC . '/dynamic.php')) {
    $dynamic = (function (string $__f, array $data) { return require $__f; })($SRC . '/dynamic.php', $data);
    $data = array_merge($data, $dynamic['data'] ?? []);
}

$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($SRC . '/pages', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $rel = substr($f->getPathname(), strlen($SRC . '/pages/'));
    $route = '/' . preg_replace('#(^|/)index\.php$#', '', $rel);
    $route = preg_replace('#\.php$#', '/', $route);
    $route = '/' . ltrim($route === '' ? '/' : $route, '/');
    $pages[$route] = ['file' => $f->getPathname(), 'vars' => []];
}
foreach ($dynamic['routes'] as $r) {
    $pages[$r['route']] = ['file' => $SRC . '/' . $r['template'], 'vars' => $r['vars'] ?? []];
}
ksort($pages);

if ($list) { foreach ($pages as $r => $p) { echo str_pad($r, 60), substr($p['file'], strlen($SRC) + 1), "\n"; } exit; }

@mkdir($DIST, 0775, true);
$built = 0; $urls = [];
foreach ($pages as $route => $spec) {
    $page = ['title' => '', 'description' => '', 'route' => $route, 'og_image' => '', 'og_type' => 'website', 'noindex' => false, 'jsonld' => []];
    ob_start();
    $render = (function (string $__file, array &$page, array $data, array $__vars) { extract($__vars, EXTR_SKIP); require $__file; })->bindTo(null);
    $render($spec['file'], $page, $data, $spec['vars']);
    $content = ob_get_clean();
    // 2026-09-12 — the homepage shares a photograph of itself, not a generated title card.
    if ($page['og_image'] === '' && $route === '/' && is_file($SRC . '/assets/og/home-sarah.jpg')) {
        $page['og_image'] = rtrim($data['site']['url'] ?? '', '/') . '/next/assets/og/home-sarah.jpg';
    }
    if ($page['og_image'] === '') { $og = og_image($DIST, $route, $page['title'] !== '' ? $page['title'] : 'The business you own, run by an AI workforce you approve.', $spec['vars']['post']['category_label'] ?? ''); if ($og !== '') { $page['og_image'] = $data['site']['url'] . $og; } }
    $html = (function (string $__layout, array $page, string $content, array $data) { ob_start(); require $__layout; return ob_get_clean(); })($SRC . '/layout.php', $page, $content, $data);
    $out = $DIST . ($route === '/' ? '/index.html' : rtrim($route, '/') . '/index.html');
    @mkdir(dirname($out), 0775, true);
    file_put_contents($out, $rebase($html));
    $urls[$route] = isset($spec['vars']['post']['updated_at']) ? substr((string) $spec['vars']['post']['updated_at'], 0, 10) : date('Y-m-d');
    $built++;
}

// sitemap
$site = rtrim($data['site']['url'], '/');
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $route => $mod) {
    if (str_starts_with($route, '/start') || str_starts_with($route, '/404') || str_starts_with($route, '/launch') || str_starts_with($route, '/customers')) { continue; }   // customers: unlinked until a named customer exists (2026-09-08)
    $xml .= "  <url><loc>" . htmlspecialchars($site . $route) . "</loc><lastmod>$mod</lastmod></url>\n";
}
$xml .= "</urlset>\n";
file_put_contents("$DIST/sitemap.xml", $rebase($xml));

// RSS
$posts = $data['blog']['posts'] ?? [];
$rss = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\"><channel>\n<title>LevelUpGrowth Blog</title><link>$site/blog/</link><description>Playbooks, product notes and industry guides, written by Sarah.</description><language>en</language>\n<atom:link href=\"$site/blog/feed.xml\" rel=\"self\" type=\"application/rss+xml\"/>\n";
foreach (array_slice($posts, 0, 30) as $p) {
    $rss .= "<item><title>" . htmlspecialchars((string) $p['title']) . "</title><link>$site/blog/" . htmlspecialchars((string) $p['slug']) . "/</link><guid isPermaLink=\"true\">$site/blog/" . htmlspecialchars((string) $p['slug']) . "/</guid><pubDate>" . date(DATE_RSS, strtotime((string) $p['published_at'])) . "</pubDate><description>" . htmlspecialchars((string) $p['excerpt']) . "</description><category>" . htmlspecialchars((string) ($p['category_label'] ?? '')) . "</category></item>\n";
}
$rss .= "</channel></rss>\n";
@mkdir("$DIST/blog", 0775, true);
file_put_contents("$DIST/blog/feed.xml", $rebase($rss));

// static assets
foreach (['site.css', 'site.js'] as $asset) { if (is_file("$SRC/$asset")) { file_put_contents("$DIST/$asset", $rebase((string) file_get_contents("$SRC/$asset"))); } }
if (is_dir("$SRC/assets")) {
    $ai = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$SRC/assets", FilesystemIterator::SKIP_DOTS));
    foreach ($ai as $a) { $to = "$DIST/assets/" . substr($a->getPathname(), strlen("$SRC/assets/")); @mkdir(dirname($to), 0775, true); copy($a->getPathname(), $to); }
}
if (is_file("$DIST/404/index.html")) { copy("$DIST/404/index.html", "$DIST/404.html"); }
// prune: a route that is no longer built (an unpublished post, a page moved out of src/pages) must not linger in dist.
// Collect first, delete after the walk — deleting while iterating broke the iterator on the first run (2026-09-07).
$stale = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($DIST, FilesystemIterator::SKIP_DOTS)) as $pf) {
    if ($pf->getFilename() !== 'index.html') { continue; }
    $rel = substr(dirname($pf->getPathname()), strlen($DIST));
    $route = $rel === '' ? '/' : $rel . '/';
    // static page routes are keyed without a trailing slash, dynamic ones with it: accept either
    if ($route === '/' || isset($urls[$route]) || isset($urls[rtrim($route, '/')]) || str_starts_with($route, '/assets/')) { continue; }
    $stale[$route] = dirname($pf->getPathname());
}
foreach ($stale as $route => $dir) {
    if (is_file("$dir/index.html")) { unlink("$dir/index.html"); }
    if (array_diff(scandir($dir) ?: [], ['.', '..']) === []) { rmdir($dir); }
    echo "pruned stale route $route\n";
}

// internal pages (P0-2): rendered with the same layout and data, written OUTSIDE the webroot; read them over SSH/scp
if (is_dir("$SRC/internal")) {
    @mkdir($INTERNAL, 0770, true);
    $ii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$SRC/internal", FilesystemIterator::SKIP_DOTS));
    $ib = 0;
    foreach ($ii as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $rel = substr($f->getPathname(), strlen("$SRC/internal/"));
        $route = '/' . preg_replace('#(^|/)index\.php$#', '', $rel); $route = '/' . ltrim(preg_replace('#\.php$#', '/', $route), '/');
        $page = ['title' => '', 'description' => '', 'route' => $route, 'og_image' => '', 'og_type' => 'website', 'noindex' => true, 'jsonld' => []];
        ob_start();
        $render = (function (string $__file, array &$page, array $data, array $__vars) { extract($__vars, EXTR_SKIP); require $__file; })->bindTo(null);
        $render($f->getPathname(), $page, $data, []);
        $content = ob_get_clean();
        $html = (function (string $__layout, array $page, string $content, array $data) { ob_start(); require $__layout; return ob_get_clean(); })("$SRC/layout.php", $page, $content, $data);
        $out = $INTERNAL . rtrim($route, '/') . '/index.html'; @mkdir(dirname($out), 0770, true); file_put_contents($out, $html); $ib++;
    }
    echo "internal: $ib page(s) into $INTERNAL (not public)\n";
}

file_put_contents("$DIST/build.txt", 'built ' . date('c') . " pages=$built plans_version=" . ($data['plans_version'] ?? '-') . " posts=" . count($posts) . "\n");
echo "built $built pages into $DIST (" . count($posts) . " posts, sitemap + feed)\n";
