<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Engines\SEO\Services\SeoAssistantService;
use App\Core\Agents\AgentMessageService;

echo "============================================================\n";
echo "WAVE 5 SMOKE — platform messaging unification\n";
echo "============================================================\n\n";

// Clean slate for ws=1 / james
DB::table('agent_messages')->where('workspace_id', 1)->where('agent_slug', 'james')->where('role', 'agent')->delete();
DB::table('notifications')->where('workspace_id', 1)->where('type', 'agent.task_completed')->delete();
echo "Clean: James thread + agent.task_completed notifications wiped for ws=1\n\n";

echo "── 1. AgentMessageService::postAsAgent works (standalone) ──\n";
$svc = app(AgentMessageService::class);
$id = $svc->postAsAgent(1, 'james', "Hello from James! Task XYZ is done.", ['notification_type' => 'test']);
echo "  inserted agent_messages id=$id\n";

$row = DB::table('agent_messages')->where('id', $id)->first();
echo "  sender=$row->sender, role=$row->role, read_at=" . ($row->read_at ?? 'NULL') . "\n";

echo "\n── 2. /messages/unread-count reflects the new James message ──\n";
$counts = DB::table('agent_messages')
    ->where('workspace_id', 1)
    ->where('role', 'agent')
    ->whereNull('read_at')
    ->selectRaw('agent_slug, COUNT(*) as cnt')
    ->groupBy('agent_slug')
    ->pluck('cnt', 'agent_slug')
    ->toArray();
echo "  by_agent (unread): " . json_encode($counts) . "\n";
echo "  james unread: " . ($counts['james'] ?? 0) . " (expected: >=1)\n";

echo "\n── 3. SeoAssistantService::notify writes to BOTH agent_messages + notifications ──\n";
$assistant = app(SeoAssistantService::class);
$ref = new ReflectionProperty($assistant, 'currentUserId');
$ref->setAccessible(true);
$ref->setValue($assistant, 1);  // simulate user_id=1 for the call
$assistant->notify(
    1, 1, 'article_done',
    'Article ready: "Test Article"',
    'Your draft is in the library — 412 words.',
    '/app/?tab=write&article=999',
    ['article_id' => 999]
);

$jamesAfter = DB::table('agent_messages')
    ->where('workspace_id', 1)->where('agent_slug', 'james')->where('role', 'agent')->count();
$notifAfter = DB::table('notifications')
    ->where('workspace_id', 1)->where('type', 'agent.task_completed')->count();
echo "  agent_messages (james, agent role) count after: $jamesAfter (expected: 2 — one from step 1, one from notify)\n";
echo "  notifications (agent.task_completed) count after: $notifAfter (expected: 1)\n";

// Inspect the notification
$n = DB::table('notifications')->where('type', 'agent.task_completed')->orderByDesc('id')->first();
if ($n) {
    echo "  notification id=$n->id channel=$n->channel category=$n->category severity=$n->severity\n";
    echo "  title=$n->title\n";
    echo "  body=" . mb_substr($n->body ?? '', 0, 80) . "\n";
    echo "  action_url=$n->action_url\n";
    echo "  user_id=$n->user_id (expected: 1)\n";
}

echo "\n── 4. markAgentThreadRead clears James badge ──\n";
$marked = $svc->markAgentThreadRead(1, 'james');
echo "  rows marked read: $marked\n";
$counts2 = DB::table('agent_messages')
    ->where('workspace_id', 1)
    ->where('role', 'agent')
    ->whereNull('read_at')
    ->selectRaw('agent_slug, COUNT(*) as cnt')
    ->groupBy('agent_slug')
    ->pluck('cnt', 'agent_slug')
    ->toArray();
echo "  by_agent after mark-read: " . json_encode($counts2) . "\n";
echo "  james unread after: " . ($counts2['james'] ?? 0) . " (expected: 0)\n";

echo "\n── 5. Verify Sarah pattern is identical (would just postAsAgent with 'sarah') ──\n";
$sid = $svc->postAsAgent(1, 'sarah', 'Sarah test: monthly strategy ready.', ['origin' => 'wave5_smoke']);
echo "  sarah message id=$sid\n";
$sarahUnread = DB::table('agent_messages')
    ->where('workspace_id', 1)->where('agent_slug', 'sarah')->where('role', 'agent')->whereNull('read_at')->count();
echo "  sarah unread: $sarahUnread (expected: 1 — confirms the pattern works for any agent)\n";

// Cleanup
DB::table('agent_messages')->where('id', $sid)->delete();

echo "\nDONE\n";