<?php
declare(strict_types=1);
/**
 * Dynamic routes rendered at build time from the platform: one static page per company blog post and per category.
 * Returns [ ['route' => '/blog/<slug>/', 'template' => 'blog-post.php', 'vars' => [...]], ... ].
 * Also exposes $data['blog'] (posts, categories) to the static pages through the returned 'data' key.
 */
$base = rtrim($data['api_base'] ?? 'https://staging.levelupgrowth.io', '/');
$ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
$fetch = function (string $path) use ($base, $ctx) { $raw = @file_get_contents($base . $path, false, $ctx); return $raw ? (json_decode($raw, true) ?: null) : null; };

$posts = [];
for ($page = 1; $page <= 20; $page++) {
    $list = $fetch("/api/blog/posts?per_page=50&page=$page");
    foreach (($list['articles'] ?? []) as $a) { $posts[] = $a; }
    if (! $list || $page >= (int) ($list['pagination']['total_pages'] ?? 1)) { break; }
}
$categories = $fetch('/api/blog/categories')['categories'] ?? [];

$routes = [];
foreach ($posts as $a) {
    $full = $fetch('/api/blog/posts/' . rawurlencode((string) $a['slug']));
    if (! $full) { continue; }
    $routes[] = ['route' => '/blog/' . $a['slug'] . '/', 'template' => 'blog-post.php', 'vars' => ['post' => $full]];
}
foreach ($categories as $c) {
    if (($c['slug'] ?? 'all') === 'all') { continue; }
    $routes[] = ['route' => '/blog/category/' . $c['slug'] . '/', 'template' => 'blog-category.php', 'vars' => ['category' => $c, 'posts' => array_values(array_filter($posts, fn ($p) => ($p['category'] ?? '') === $c['slug']))]];
}
foreach (require __DIR__ . '/solutions-data.php' as $s) {
    $routes[] = ['route' => '/solutions/' . $s['slug'] . '/', 'template' => 'solution-page.php', 'vars' => ['s' => $s]];
}
$legal = require __DIR__ . '/legal-data.php';
foreach ($legal['pages'] as $l) {
    $routes[] = ['route' => '/legal/' . $l['slug'] . '/', 'template' => 'legal-page.php', 'vars' => ['l' => $l]];
}
fwrite(STDERR, 'legal owner keys: ' . implode(', ', $legal['owner_keys']) . "
");
$pd = require __DIR__ . '/product-data.php';
$products = $pd['launched'];
if (! empty($data['infrastructure_live'])) { $products = array_merge($products, $pd['infrastructure']); }
foreach ($products as $p) {
    $routes[] = ['route' => '/product/' . $p['slug'] . '/', 'template' => 'product-page.php', 'vars' => ['p' => $p]];
}
$cmp = require __DIR__ . '/compare-data.php';
foreach ($cmp['competitors'] as $c) {
    $routes[] = ['route' => '/compare/' . $c['slug'] . '/', 'template' => 'compare-page.php', 'vars' => ['c' => $c, 'verified' => $cmp['verified']]];
}
fwrite(STDERR, 'dynamic.php: ' . count($posts) . " posts, " . count($categories) . " categories, " . count($routes) . " routes\n");

return ['routes' => $routes, 'data' => ['blog' => ['posts' => $posts, 'categories' => $categories]]];
