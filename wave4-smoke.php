<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Engines\SEO\Services\SeoAssistantService;

echo "============================================================\n";
echo "WAVE 4 SMOKE — proactive notifications\n";
echo "============================================================\n\n";

DB::table('seo_assistant_notifications')->where('workspace_id', 1)->delete();
echo "Clean slate for ws=1\n\n";

echo "── 1. SeoAssistantService::notify() writes both rows ──\n";
$svc = app(SeoAssistantService::class);
$svc->notify(1, 1, 'article_done',
    "Article ready: \"Test Article\"",
    "Your draft is in the library — 412 words.",
    "/app/?tab=write&article=123",
    ['article_id' => 123, 'word_count' => 412]
);

$notif = DB::table('seo_assistant_notifications')->where('workspace_id', 1)->first();
echo "  seo_assistant_notifications row: id={$notif->id} type={$notif->type} title='{$notif->title}'\n";
echo "  action_link={$notif->action_link}\n";

$msg = DB::table('seo_assistant_messages')->where('workspace_id', 1)->where('role', 'assistant_proactive')->orderByDesc('id')->first();
echo "  mirrored to seo_assistant_messages: " . ($msg ? "YES (id={$msg->id})" : "NO ✗") . "\n";

echo "\n── 2. Endpoint shapes ──\n";

// Simulate fetching unread count via the service
$count = DB::table('seo_assistant_notifications')
    ->where('workspace_id', 1)
    ->whereNull('read_at')
    ->whereNull('dismissed_at')
    ->count();
echo "  unread-count for ws 1: $count (expected: 1)\n";

// Simulate mark-read
DB::table('seo_assistant_notifications')
    ->where('workspace_id', 1)
    ->whereNull('read_at')
    ->update(['read_at' => now()]);
$countAfter = DB::table('seo_assistant_notifications')
    ->where('workspace_id', 1)
    ->whereNull('read_at')
    ->count();
echo "  unread after mark-read: $countAfter (expected: 0)\n";

echo "\n── 3. Multiple types — verify enum range ──\n";
$svc->notify(1, 1, 'audit_done', 'Audit complete — 73/100', 'Top issue: missing meta descriptions on 14 pages.', null, ['score' => 73]);
$svc->notify(1, 1, 'link_suggestions_done', 'Found 5 link opportunities', 'Each comes with anchor + target. Open chat for review.', null, ['count' => 5]);
$svc->notify(1, 1, 'link_inserted', 'Link added to "Article X"', 'Inserted at paragraph 2.', null, ['link_id' => 99]);

$rows = DB::table('seo_assistant_notifications')->where('workspace_id', 1)->whereNull('read_at')->get();
echo "  unread notifications now: " . $rows->count() . "\n";
foreach ($rows as $r) {
    echo "    type=$r->type title=$r->title\n";
}

echo "\n── 4. Chronological list endpoint shape ──\n";
$all = DB::table('seo_assistant_notifications')
    ->where('workspace_id', 1)
    ->whereNull('dismissed_at')
    ->orderByDesc('created_at')
    ->limit(20)
    ->get(['id', 'type', 'title', 'body', 'read_at', 'created_at']);
echo "  total returned: " . $all->count() . "\n";
echo "  recent 3:\n";
foreach ($all->take(3) as $r) {
    echo "    #$r->id [$r->type] read=" . ($r->read_at ? 'Y' : 'N') . " — $r->title\n";
}

echo "\nDONE\n";