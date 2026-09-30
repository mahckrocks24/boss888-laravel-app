<?php
// DRAFT-5 (RFC-0021 wave 5): give every published site a live copy equal to what it serves today, so the switch
// changes nothing visitors see and every edit from now on starts a draft. Idempotent; skips sites that have one.
// Usage: sudo -u www-data php /tmp/lu-eval.php tools/draft-baseline.php  (or any Laravel-booted context)
$done = 0; $skipped = 0; $none = 0;
foreach (DB::table('websites')->where('status', 'published')->whereNull('deleted_at')->pluck('id') as $id) {
    $id = (int) $id;
    if (\App\Engines\Builder\Support\DraftEdits::hasLive($id)) { $skipped++; continue; }
    if (! is_file(storage_path("app/public/sites/{$id}/index.html"))) { $none++; continue; }   // renderer-served, no export
    $r = \App\Engines\Builder\Support\DraftEdits::promote($id);
    if (! empty($r['promoted'])) $done++;
}
echo "live copies made: {$done}, already had one: {$skipped}, no static export: {$none}\n";
