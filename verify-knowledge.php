<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Engines\SEO\Services\SeoService;

$svc = app(SeoService::class);

foreach ([1, 7] as $wsId) {
    echo "============================================================\n";
    echo "WORKSPACE $wsId\n";
    echo "============================================================\n";
    $k = $svc->getKnowledge($wsId);
    echo "health_score:       ".var_export($k['health_score'], true)."\n";
    echo "content_health:\n";
    foreach ($k['content_health'] as $key => $v) {
        echo "  $key: ".var_export($v, true)."\n";
    }
    echo "link_health:\n";
    foreach ($k['link_health'] as $key => $v) {
        echo "  $key: ".var_export($v, true)."\n";
    }
    echo "top_issues: ".count($k['top_issues'])." issue group(s)\n";
    foreach ($k['top_issues'] as $iss) {
        echo "  - {$iss['count']} {$iss['type']}\n";
    }
    echo "keyword_rankings: ".count($k['keyword_rankings'])." tracked\n";
    echo "summary: ".$k['summary']."\n";
    echo "\n";
}

echo "=== Latest audit score (ws 7) for comparison ===\n";
$a = \Illuminate\Support\Facades\DB::table('seo_audits')->where('workspace_id', 7)->where('status', 'completed')->orderByDesc('id')->first();
if ($a) echo "  audit id={$a->id} score={$a->score} at={$a->created_at}\n";