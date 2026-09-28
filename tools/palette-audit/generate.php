<?php
/*
 * PALETTE AUDIT generator (Owner 2026-09-28: "audit all and make sure we never ever encounter this same problem").
 * For each template: render it with its own defaults, then apply each palette through the REAL palette switch
 * (ArthurService::applyPalette) on one scratch, non-public QA site, and save the result as
 * public/palaudit-<token>/<template>/<palette>.html for a browser to measure. Every palette starts from the
 * template's original page (the first switch a customer makes). Run with: nice -n 19 php boot.php palaudit_gen.php [from] [count]
 */
$WS = 999993; $TOKEN = 'pa7c19e3';
$out = public_path("palaudit-$TOKEN"); @mkdir($out, 0755, true);
$from = (int) ($argv[2] ?? 0); $count = (int) ($argv[3] ?? 1000);

// one scratch site, private (no subdomain, draft), reused for every template
$sid = (int) DB::table('websites')->where('workspace_id', $WS)->where('name', 'Palette audit scratch (not public)')->value('id');
if (! $sid) {
    $sid = (int) DB::table('websites')->insertGetId(['workspace_id' => $WS, 'name' => 'Palette audit scratch (not public)', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
}
$root = storage_path("app/public/sites/$sid"); @mkdir($root, 0755, true);
$ts = app(App\Engines\Builder\Services\TemplateService::class);
$ar = app(App\Engines\Builder\Services\ArthurService::class);
$themes = App\Engines\Builder\Support\ColorTheme::all();
$slugs = array_values(array_filter(array_map('basename', glob(storage_path('templates/*'), GLOB_ONLYDIR)), fn ($s) => is_file(storage_path("templates/$s/template.html"))));
sort($slugs);
$slugs = array_slice($slugs, $from, $count);
if (!empty($argv[4])) $slugs = array_values(array_intersect($slugs, explode(",", $argv[4])));
$t0 = microtime(true); $n = 0; $fail = [];
foreach ($slugs as $slug) {
    $m = $ts->getManifest($slug) ?: [];
    $vars = [];
    foreach (($m['variables'] ?? []) as $k => $spec) $vars[$k] = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec;
    $vars = array_merge($vars, ['business_name' => 'Northgate & Co', 'logo' => 'Northgate & Co', 'city' => 'Bristol', 'location' => 'Bristol']);
    try { $base = $ts->render($slug, $vars); } catch (Throwable $e) { $fail[] = "$slug render: " . $e->getMessage(); continue; }
    @mkdir("$out/$slug", 0755, true);
    file_put_contents("$out/$slug/_original.html", $base);
    foreach ($themes as $t) {
        // start every palette from the template's original page and record
        file_put_contents("$root/index.html", $base);
        DB::table('websites')->where('id', $sid)->update(['settings_json' => json_encode(['template' => $slug, 'industry' => $m['industry'] ?? $slug]), 'template_variables' => json_encode($vars), 'template' => $slug, 'updated_at' => now()]);
        try {
            $r = $ar->applyPalette($WS, $sid, (string) $t['id']);
            if (empty($r['success'])) { $fail[] = "$slug/{$t['id']}: " . ($r['message'] ?? 'failed'); continue; }
            copy("$root/index.html", "$out/$slug/{$t['id']}.html"); $n++;
        } catch (Throwable $e) { $fail[] = "$slug/{$t['id']}: " . $e->getMessage(); }
    }
    // the scratch site's history is noise
    foreach (glob("$root/.history/*") ?: [] as $h) { is_dir($h) ? exec('rm -rf ' . escapeshellarg($h)) : @unlink($h); }
}
file_put_contents("$out/_failures.txt", implode("\n", $fail) . "\n", FILE_APPEND);
echo "templates " . count($slugs) . " pages $n failures " . count($fail) . " in " . round(microtime(true) - $t0, 1) . "s (scratch site $sid)\n";
echo implode("\n", array_slice($fail, 0, 5)), "\n";
