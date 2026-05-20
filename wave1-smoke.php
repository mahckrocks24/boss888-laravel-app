<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "============================================================\n";
echo "WAVE 1 SMOKE — admin user, ws 1\n";
echo "============================================================\n\n";

// Reset state: clear admin's disclaimer + any existing chat rows for ws 1
DB::table('users')->where('id', 1)->update(['seo_assistant_disclaimer_accepted_at' => null]);
DB::table('seo_assistant_messages')->where('workspace_id', 1)->delete();
echo "Reset: admin.disclaimer = null, chat log cleared\n\n";

$svc = app(\App\Engines\SEO\Services\SeoAssistantService::class);

echo "--- 1. First call with user_id, no acceptance yet ---\n";
$r1 = $svc->handle(1, 'show me link suggestions', ['source' => 'smoke', 'user_id' => 1]);
echo "  keys: " . implode(',', array_keys($r1)) . "\n";
echo "  disclaimer_required: " . var_export($r1['disclaimer_required'] ?? null, true) . "\n";
echo "  response: " . mb_substr($r1['response'] ?? '', 0, 80) . "...\n";

$msgCount1 = DB::table('seo_assistant_messages')->where('workspace_id', 1)->count();
echo "  seo_assistant_messages rows after call: $msgCount1 (expected: 0 — disclaimer gate blocks persistence)\n";
echo "\n";

echo "--- 2. Accept disclaimer (simulating endpoint) ---\n";
DB::table('users')->where('id', 1)->update(['seo_assistant_disclaimer_accepted_at' => now()]);
$acc = DB::table('users')->where('id', 1)->value('seo_assistant_disclaimer_accepted_at');
echo "  users.disclaimer_accepted_at = $acc\n\n";

echo "--- 3. Second call after acceptance ---\n";
$r2 = $svc->handle(1, 'show me link suggestions', ['source' => 'smoke', 'user_id' => 1]);
echo "  keys: " . implode(',', array_keys($r2)) . "\n";
echo "  disclaimer_required: " . var_export($r2['disclaimer_required'] ?? null, true) . " (expected: null/absent)\n";
echo "  response: " . mb_substr($r2['response'] ?? '', 0, 200) . "...\n";

$msgCount2 = DB::table('seo_assistant_messages')->where('workspace_id', 1)->count();
echo "  seo_assistant_messages rows now: $msgCount2 (expected >= 2 — user msg + assistant reply)\n\n";

echo "--- 4. Inspect persisted messages ---\n";
$rows = DB::table('seo_assistant_messages')->where('workspace_id', 1)->orderBy('id')->get();
foreach ($rows as $r) {
    echo sprintf("  id=%d user=%s role=%s @ %s :: %s\n",
        $r->id,
        $r->user_id ?? 'null',
        $r->role,
        $r->created_at,
        mb_substr($r->content, 0, 60)
    );
}
echo "\n";

echo "--- 5. Test /assistant/disclaimer-status endpoint shape ---\n";
$accVal = DB::table('users')->where('id', 1)->value('seo_assistant_disclaimer_accepted_at');
echo "  accepted=" . ($accVal !== null ? 'true' : 'false') . " accepted_at=$accVal\n";
echo "  disclaimer text len = " . strlen(\App\Engines\SEO\Services\SeoAssistantService::DISCLAIMER_TEXT) . "\n";
echo "  retention days = " . \App\Engines\SEO\Services\SeoAssistantService::DB_RETENTION_DAYS . "\n\n";

echo "--- 6. Test purge command (dry-run with high days so nothing deletes) ---\n";
$cmd = \Illuminate\Support\Facades\Artisan::call('seo:purge-chat-history', ['--days' => 365]);
echo "  exit code: $cmd\n";
echo "  output: " . trim(\Illuminate\Support\Facades\Artisan::output()) . "\n";

echo "\nDONE\n";