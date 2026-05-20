<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

echo "============================================================\n";
echo "WAVE 7 SMOKE — publish route + chat notification\n";
echo "============================================================\n\n";

// Use the test article 26 (ws=1, draft state from earlier smoke)
$article = DB::table('articles')->where('id', 26)->first();
if (!$article) {
    echo "Test article 26 not found — using latest ws=1 article instead\n";
    $article = DB::table('articles')->where('workspace_id', 1)->orderByDesc('id')->first();
}
echo "Test article: id={$article->id} status={$article->status} wp_post_id=" . ($article->wp_post_id ?? 'NULL') . "\n";

// Reset state — clean Priya thread + reset test article
DB::table('agent_messages')->where('workspace_id', 1)->where('agent_slug', 'priya')->where('role', 'agent')->delete();
DB::table('articles')->where('id', $article->id)->update(['status' => 'draft', 'wp_post_id' => null, 'published_at' => null]);

echo "\n── 1. Workspace 1 site_url + webhook_secret config ──\n";
$siteUrl = DB::table('seo_settings')->where('workspace_id', 1)->where('key', 'site_url')->value('value');
$secret = DB::table('seo_settings')->where('workspace_id', 1)->where('key', 'webhook_secret')->value('value');
echo "  site_url: " . ($siteUrl ?? 'NOT SET') . "\n";
echo "  webhook_secret: " . ($secret ? '[set, len ' . strlen($secret) . ']' : 'NOT SET') . "\n";

echo "\n── 2. Invoke the publish route via API (with workspace api_key) ──\n";
$apiKey = DB::table('api_keys')->where('workspace_id', 1)->where('is_active', true)->orderBy('id')->value('key');
echo "  using ws-1 api_key: " . ($apiKey ? substr($apiKey, 0, 16) . '…' : 'NONE') . "\n";

$resp = Http::withHeaders([
    'Host'           => 'staging.levelupgrowth.io',
    'X-API-KEY'      => $apiKey,
    'X-Workspace-ID' => '1',
    'Accept'         => 'application/json',
    'Content-Type'   => 'application/json',
])->timeout(45)->post("http://127.0.0.1/api/write/articles/{$article->id}/publish", []);

echo "  HTTP status: " . $resp->status() . "\n";
echo "  body: " . substr($resp->body(), 0, 600) . "\n";

echo "\n── 3. Article state after publish attempt ──\n";
$after = DB::table('articles')->where('id', $article->id)->first();
echo "  status: $after->status\n";
echo "  published_at: " . ($after->published_at ?? 'NULL') . "\n";
echo "  wp_post_id: " . ($after->wp_post_id ?? 'NULL') . "\n";

echo "\n── 4. Priya chat thread after publish ──\n";
$priya = DB::table('agent_messages')
    ->where('workspace_id', 1)
    ->where('agent_slug', 'priya')
    ->where('role', 'agent')
    ->orderByDesc('id')
    ->first();
if ($priya) {
    echo "  message id=$priya->id sender=$priya->sender\n";
    echo "  content[0..200]: " . mb_substr($priya->content, 0, 200) . "\n";
    echo "  metadata: " . substr($priya->metadata_json ?? '{}', 0, 200) . "\n";
} else {
    echo "  no Priya message (expected when site_not_configured early-exits)\n";
}

echo "\n── 5. Verify /messages/unread-count picks up Priya badge ──\n";
$counts = DB::table('agent_messages')
    ->where('workspace_id', 1)
    ->where('role', 'agent')
    ->whereNull('read_at')
    ->selectRaw('agent_slug, COUNT(*) as cnt')
    ->groupBy('agent_slug')
    ->pluck('cnt', 'agent_slug')
    ->toArray();
echo "  by_agent unread: " . json_encode($counts) . "\n";

echo "\n── 6. Re-publish call should detect 'already' if wp_post_id was set ──\n";
if ($after->wp_post_id) {
    $resp2 = Http::withHeaders([
        'Host' => 'staging.levelupgrowth.io',
        'X-API-KEY' => $apiKey,
        'X-Workspace-ID' => '1',
        'Accept' => 'application/json',
    ])->timeout(15)->post("http://127.0.0.1/api/write/articles/{$article->id}/publish", []);
    echo "  HTTP: " . $resp2->status() . "  body: " . substr($resp2->body(), 0, 200) . "\n";
} else {
    echo "  skipped — wp_post_id is NULL (first publish didn't reach WP)\n";
}

echo "\nDONE\n";