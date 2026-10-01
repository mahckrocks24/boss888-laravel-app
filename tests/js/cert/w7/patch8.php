<?php
// CONNECT-1 (2026-10-01): the Websites page's "Connect Your Existing Website" — guarded route, no third-party thumbnail,
// public hosts only, indexed for SEO/Sarah, honest modal copy, no draft link for connected sites.
$root = '/var/www/levelup-staging'; $seen = [];
function rep(string $file, string $old, string $new, int $expect = 1): void {
    global $root, $seen; $p = "$root/$file"; $s = file_get_contents($p); $n = substr_count($s, $old);
    if ($n !== $expect) { fwrite(STDERR, "ANCHOR x$n (want $expect) in $file:\n" . substr($old, 0, 160) . "\n"); exit(1); }
    if (! isset($seen[$file])) { copy($p, "$p.before-connect1"); $seen[$file] = true; }
    file_put_contents($p, str_replace($old, $new, $s)); echo "ok  $file  (x$n)\n";
}

$f = 'routes/api.php';
rep($f, <<<'X'
Route::post('/builder/websites/connect-existing', function (\Illuminate\Http\Request $request) {
X, <<<'X'
// CONNECT-1 (2026-10-01): behind the same guard as every other builder route (it used to decode the token by hand, outside
// auth.jwt and traffic defence), only public http(s) hosts, our own screenshot instead of a third-party image service,
// and the connected site is indexed in seo_settings so Sarah's discovery and the SEO picker see it.
Route::middleware(['auth.jwt', 'traffic.defense'])->post('/builder/websites/connect-existing', function (\Illuminate\Http\Request $request) {
X);
rep($f, <<<'X'
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['success' => false, 'error' => 'Please enter a valid URL.'], 400);
    }
X, <<<'X'
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['success' => false, 'error' => 'Please enter a valid URL.'], 400);
    }
    // CONNECT-1: the server fetches and screenshots this address — public http(s) hosts only, never ourselves
    $__scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)); $__host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($__host !== '' && (str_ends_with($__host, '.levelupgrowth.io') || $__host === 'levelupgrowth.io')) {
        return response()->json(['success' => false, 'error' => 'That is a LevelUpGrowth site — it is already in your Websites.'], 400);
    }
    $__ip = $__host !== '' ? (filter_var($__host, FILTER_VALIDATE_IP) ? $__host : gethostbyname($__host)) : '';
    if (! in_array($__scheme, ['http', 'https'], true) || $__host === '' || $__host === 'localhost' || str_ends_with($__host, '.local')
        || ! filter_var($__ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return response()->json(['success' => false, 'error' => 'That address is not a public website.'], 400);
    }
X);
rep($f, <<<'X'
    $thumbnailUrl = 'https://image.thum.io/get/width/400/crop/600/' . urlencode($url);
X, <<<'X'
    $thumbnailUrl = null;   // CONNECT-1: our own screenshot once the row exists (below), never a third-party image service
X);
rep($f, <<<'X'
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'website_id' => $websiteId,
X, <<<'X'
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // CONNECT-1: indexed like any other site (Sarah's discovery, the SEO picker) and shot by our own tool
    try { \App\Engines\Builder\Support\Platform6::ensureSeoSiteUrl((int) $wsId, (int) $websiteId, (string) $__host, (string) $title); } catch (\Throwable $e) {}
    try { $thumbnailUrl = \App\Engines\Builder\Support\SiteThumbnail::generateForUrl((int) $websiteId, $url); } catch (\Throwable $e) { $thumbnailUrl = null; }

    return response()->json([
        'success' => true,
        'website_id' => $websiteId,
X);

$f = 'app/Engines/Builder/Support/SiteThumbnail.php';
rep($f, <<<'X'
    public static function generate(int $websiteId): ?string
    {
        $export = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($export)) { return null; }
X, <<<'X'
    /** CONNECT-1 (2026-10-01): a connected (external) website is shot at its own public address. */
    public static function generateForUrl(int $websiteId, string $url): ?string
    {
        return self::generate($websiteId, $url);
    }

    public static function generate(int $websiteId, ?string $urlOverride = null): ?string
    {
        $export = storage_path("app/public/sites/{$websiteId}/index.html");
        if ($urlOverride === null && ! is_file($export)) { return null; }
X);
rep($f, <<<'X'
        $url = rtrim((string) config('app.url'), '/') . "/storage/sites/{$websiteId}/index.html?thumb=" . time();
X, <<<'X'
        $url = $urlOverride ?? (rtrim((string) config('app.url'), '/') . "/storage/sites/{$websiteId}/index.html?thumb=" . time());
X);

$f = 'app/Engines/Builder/Services/BuilderService.php';
rep($f, "if (\\App\\Engines\\Builder\\Support\\Platform6::on() && \$s->publish_state !== 'published') \$s->draft_url",
        "if (\\App\\Engines\\Builder\\Support\\Platform6::on() && \$s->publish_state !== 'published' && (string) (\$s->type ?? '') !== 'external') \$s->draft_url");   // CONNECT-1: a connected site has no draft

$f = 'public/app/js/builder.js';
rep($f, " Sarah can analyze and improve it</div>'", " Sarah can read it and, on WordPress, publish to it</div>'");
rep($f, " SEO engine starts auditing it</div></div>'", " A first SEO audit runs right away (3 credits)</div></div>'");
$f = 'routes/api/authenticated/seo-01.php';
rep($f, <<<'X'
                ->where('status', 'published')   // RFC-0011 (Owner rule): drafts are not visible in the engines
                ->orderByDesc('id')
                ->get(['id', 'name', 'domain', 'subdomain', 'custom_domain', 'platform', 'status']);
X, <<<'X'
                ->whereIn('status', ['published', 'connected'])   // RFC-0011 (Owner rule): drafts are not visible in the engines; CONNECT-1: a connected site is live
                ->orderByDesc('id')
                ->get(['id', 'name', 'domain', 'subdomain', 'custom_domain', 'platform', 'status']);
X);
echo "CONNECT-1 APPLIED\n";
