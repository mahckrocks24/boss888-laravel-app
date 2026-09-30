<?php
// PLATFORM-6 (RFC-0021 wave 6): every published site gets its address in seo_settings (site_url, site_name), and
// Sarah's page discovery runs again for the workspaces that had nothing indexed. Idempotent. Run through lu-eval.
$set = 0; $had = 0; $indexed = [];
foreach (DB::table('websites')->where('status', 'published')->whereNull('deleted_at')->get(['id', 'workspace_id', 'subdomain', 'custom_domain', 'name']) as $w) {
    $sub = $w->custom_domain ?: $w->subdomain; if (! $sub) continue;
    $has = DB::table('seo_settings')->where('workspace_id', (int) $w->workspace_id)->where('website_id', (int) $w->id)->where('key', 'site_url')->exists();
    if ($has) { $had++; continue; }
    if (\App\Engines\Builder\Support\Platform6::ensureSeoSiteUrl((int) $w->workspace_id, (int) $w->id, (string) $sub, (string) $w->name)) { $set++; $indexed[(int) $w->workspace_id] = true; }
}
$ran = 0; $ok = 0;
foreach (array_keys($indexed) as $ws) {
    try { $r = app(\App\Engines\SEO\Services\PageDiscoveryService::class)->discover($ws); $ran++; if (is_array($r) && (($r['success'] ?? true) !== false)) $ok++; } catch (\Throwable $e) { echo "ws $ws discovery: " . $e->getMessage() . "\n"; }
}
echo "site_url set: $set, already had: $had, discovery re-run: $ran (ok $ok)\n";
