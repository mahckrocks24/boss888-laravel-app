<?php
/**
 * SARAH888 — NO PAID OR MUTATING ACTION ON A GUESSED PARAMETER (permanent regression).
 *
 * Closes the measured non-determinism of 2026-08-14: asked "Can you check where we rank?"
 * — a question containing no keyword — Sarah billed a live DataForSEO call for a keyword
 * carried from an earlier turn in 2 of 4 runs, and asked which keyword in the other 2.
 *
 * Every intent here is built through fromRuntime(), because that is what the model does and
 * it is the only path the rule polices. Direct construction is code stating a value, not a
 * model inferring one.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ToolIntent, ToolIntentGateway, ToolResult, SpendContext,
                       ParameterProvenance, CapabilityPricing};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$WS = 2;
$gw = app(ToolIntentGateway::class);
$pp = app(ParameterProvenance::class);
app(SpendContext::class)->setTurn(
    ['authorized' => true, 'reason' => 'paramtest', 'classification' => 'authorisation'], $WS);

$runtimeIntent = function (string $cap, array $params, string $ownerMessage, ?string $conv = null) use ($WS) {
    return ToolIntent::fromRuntime(
        ['capability_id' => $cap, 'parameters' => $params], $WS,
        ['conversation_id' => $conv, 'owner_message' => $ownerMessage]
    )['intent'];
};

$balance = fn () => (float) DB::table('credits')->where('workspace_id', $WS)->value('balance');

echo "-- THE OWNER SAID IT: execution proceeds --\n";
$i = $runtimeIntent('serp_analysis',
    ['keyword' => 'private chef New Jersey', 'location' => 'United States'],
    'Run a SERP check for "private chef New Jersey".');
$c = $pp->classify($i, ['keyword']);
ok('keyword classified EXPLICIT', $c['provenance']['keyword'] === ParameterProvenance::EXPLICIT,
   $c['provenance']['keyword']);
ok('nothing unsafe', $c['unsafe'] === [], implode(',', $c['unsafe']));
// `location` is deliberately NOT required — the executor defaults it. A defaulted
// parameter is not a guess, and demanding one is what made the model invent a country.
ok('location is not demanded of the owner',
   app(\App\Core\Sarah888\CapabilityManifest::class)
       ->capability($WS, 'serp_analysis')['required_parameters'] === ['keyword']);

echo "-- THE OWNER DID NOT SAY IT: refused, and NOT billed --\n";
// This is the exact defect: the question has no keyword, the model supplies one anyway.
$b = $balance();
$i = $runtimeIntent('serp_analysis',
    ['keyword' => 'private chef New Jersey', 'location' => 'United States'],
    'Can you check where we rank?');
$c = $pp->classify($i, ['keyword', 'location']);
ok('an unsaid keyword is INFERRED_UNSAFE',
   $c['provenance']['keyword'] === ParameterProvenance::INFERRED_UNSAFE, $c['provenance']['keyword']);
$r = $gw->handle($i);
ok('  the gateway REFUSES it', $r->status === ToolResult::REFUSED, $r->status);
ok('  nothing executed', $r->executed() === false);
ok('  no credit was spent on the guess', $balance() === $b, "{$b} -> " . $balance());
ok('  and it asks for what it needs', stripos($r->message, 'keyword') !== false,
   (string) $r->message);

echo "-- A MISSING parameter is refused for the same reason --\n";
$i = $runtimeIntent('serp_analysis', ['location' => 'United States'], 'Check the SERPs.');
$c = $pp->classify($i, ['keyword', 'location']);
ok('absent keyword is MISSING', $c['provenance']['keyword'] === ParameterProvenance::MISSING);
ok('  refused', $gw->handle($i)->status === ToolResult::REFUSED);

echo "-- MUTATIONS are policed too, not just paid reads --\n";
// A wrong inferred article_id would ask the owner to approve publishing the wrong article.
$i = $runtimeIntent('publish_article', ['article_id' => 41], 'Publish the drafts.');
$c = $pp->classify($i, ['article_id']);
ok('an unsaid article_id is not safe',
   !in_array($c['provenance']['article_id'], ParameterProvenance::SAFE, true),
   $c['provenance']['article_id']);
$r = $gw->handle($i, true);
ok('  refused rather than proposed for approval', $r->status === ToolResult::WOULD_BE_REFUSED, $r->status);

$i = $runtimeIntent('publish_article', ['article_id' => 41], 'Publish article 41 for me.');
ok('an article_id the owner NAMED is accepted',
   $pp->classify($i, ['article_id'])['provenance']['article_id'] === ParameterProvenance::EXPLICIT);
ok('  and reaches governance', $gw->handle($i, true)->status === ToolResult::WOULD_REQUIRE_APPROVAL);

echo "-- FREE LOCAL READS ARE NOT INTERROGATED --\n";
// Guessing a filter on a table read costs nothing; asking every time would be insufferable.
foreach (['list_articles', 'list_leads', 'recent_tasks', 'get_queue'] as $cap) {
    $i = $runtimeIntent($cap, ['status' => 'draft'], 'How are things looking?');
    $r = $gw->handle($i);
    ok("{$cap} still executes", $r->status === ToolResult::SUCCEEDED, $r->status);
}

echo "-- CONVERSATION_RESOLVED: said earlier, and only one candidate --\n";
$conv = 'paramtest-conv-' . getmypid();
DB::table('agent_messages')->insert([
    'workspace_id' => $WS, 'agent_slug' => 'sarah-shadow', 'sender' => 'Owner', 'role' => 'user',
    'content' => 'Run a SERP check for "private chef New Jersey".',
    'metadata_json' => json_encode(['conversation_id' => $conv, 'shadow' => true]),
    'created_at' => now(), 'updated_at' => now(),
]);
try {
    $i = $runtimeIntent('serp_analysis',
        ['keyword' => 'private chef New Jersey', 'location' => 'United States'],
        'And now?', $conv);
    $cl = $pp->classify($i, ['keyword', 'location'])['provenance']['keyword'];
    ok('a keyword named earlier in THIS conversation resolves',
       $cl === ParameterProvenance::CONVERSATION_RESOLVED, $cl);

    // Two competing candidates in the same conversation must NOT resolve.
    DB::table('agent_messages')->insert([
        'workspace_id' => $WS, 'agent_slug' => 'sarah-shadow', 'sender' => 'Owner', 'role' => 'user',
        'content' => 'Actually also check "personal chef Chatham".',
        'metadata_json' => json_encode(['conversation_id' => $conv, 'shadow' => true]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $i = $runtimeIntent('serp_analysis',
        ['keyword' => 'private chef New Jersey', 'location' => 'United States'],
        'And now?', $conv);
    $cl = $pp->classify($i, ['keyword', 'location'])['provenance']['keyword'];
    ok('two candidates in the conversation make it AMBIGUOUS, not resolved',
       $cl !== ParameterProvenance::CONVERSATION_RESOLVED, $cl);
} finally {
    DB::table('agent_messages')
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) = ?", [$conv])
        ->delete();
}

echo "-- programmatic callers are NOT policed (they state, they do not guess) --\n";
$direct = new ToolIntent('serp_analysis', $WS,
    ['keyword' => 'anything at all', 'location' => 'United States']);
ok('a directly-built intent is not refused for provenance',
   $direct->requestedBy === null);

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
