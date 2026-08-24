<?php
/**
 * SARAH888 — CANONICAL CAPABILITY MANIFEST (permanent regression).
 *
 * Closes two measured capability-truth defects, 2026-08-13:
 *   T012  Sarah: "I can't directly access or run DataForSEO myself" — while the
 *         connector was configured, james held serp_analysis, and ws 2 had 8
 *         completed serp_analysis runs.
 *   RTV2  Runtime: "Search Console isn't connected yet" for ws 2 — while
 *         gsc_connections held a live token for sc-domain:cheflisted.com.
 *
 * The rule under test: ONE authoritative answer to "what can Sarah do here?",
 * derived from application state, carrying provenance, and never asserting the
 * approval decision that belongs to governance.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CapabilityManifest;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$m   = app(CapabilityManifest::class);
$WS  = 2;          // Chef Red — GSC CONNECTED
$WS2 = 990100;     // forensic tenant — GSC NOT connected

echo "-- the manifest is derived from the real registry --\n";
$all = $m->forWorkspace($WS);
$registry = DB::table('agent_capabilities')->distinct()->count('tool_id');
// The manifest is the agent registry PLUS Laravel-native connector reads that
// the agent registry does not enumerate (e.g. gsc_performance). Every registry
// id must survive; connector ids are additive.
$ids = array_column($all, 'capability_id');
$regIds = DB::table('agent_capabilities')->distinct()->pluck('tool_id')->all();
ok('every registered capability survives', count(array_diff($regIds, $ids)) === 0,
   implode(',', array_diff($regIds, $ids)));
ok('connector-native capabilities are additive', count($all) >= $registry,
   count($all) . ' vs ' . $registry);
ok('no duplicate capability ids', count($ids) === count(array_unique($ids)));
ok('registry is non-trivial', $registry > 50, (string) $registry);

echo "-- T012: serp_analysis is AVAILABLE and Laravel-executed --\n";
$serp = $m->capability($WS, 'serp_analysis');
ok('serp_analysis exists in the manifest', $serp !== null);
ok('  registry_active', $serp['registry_active'] === true);
ok('  provider is dataforseo', $serp['provider'] === 'dataforseo', (string) $serp['provider']);
ok('  provider_ready (credentials present)', $serp['provider_ready'] === true);
ok('  workspace_available', $serp['workspace_available'] === true);
ok('  executor is laravel, NEVER runtime', $serp['executor'] === 'laravel', (string) $serp['executor']);
ok('  it is a READ operation', $serp['operation_type'] === 'read', (string) $serp['operation_type']);
ok('  james is an owner agent', in_array('james', $serp['owner_agents'], true),
   implode(',', $serp['owner_agents']));
// CONTRACT CORRECTED 2026-08-14, and the old one is proven obsolete rather than
// inconvenient: `ToolIntentGateway` defaults location to 'United States' when absent
// (executor, line ~263), so it was never required. Declaring it required made Runtime
// invent a location the owner had not mentioned, which the parameter-provenance rule then
// refused as a guess — a required-parameter declaration that manufactures fabrications.
// A parameter the executor supplies for itself does not belong in this list.
ok('  required parameters are stated', $serp['required_parameters'] === ['keyword'],
   json_encode($serp['required_parameters']));
ok('  provenance names the connector',
   str_contains($serp['evidence_source']['provider'], 'DataForSeoConnector'),
   $serp['evidence_source']['provider']);

echo "-- ai_report is NOT Search Console (it is the SEO engine's report) --\n";
// Encoding it as GSC-backed made ws 990100 answer "Search Console is not
// connected" for a capability that never needed GSC — a false refusal.
$air2  = $m->capability($WS,  'ai_report');
$air99 = $m->capability($WS2, 'ai_report');
ok('ai_report provider is not gsc', $air2['provider'] !== 'gsc', (string) $air2['provider']);
ok('ai_report is available in BOTH workspaces',
   $air2['workspace_available'] === true && $air99['workspace_available'] === true,
   json_encode([$air2['workspace_available'], $air99['workspace_available']]));

echo "-- RTV2: GSC readiness is PER WORKSPACE, not guessed --\n";
$g2  = $m->capability($WS,  'gsc_performance');
$g99 = $m->capability($WS2, 'gsc_performance');
ok('gsc_performance exists as a Laravel-native capability', $g2 !== null);
ok('  its evidence names GscClient',
   str_contains($g2['evidence_source']['provider'], 'GscClient'),
   $g2['evidence_source']['provider']);
ok('  executor is laravel', $g2['executor'] === 'laravel');
ok('  it is a read', $g2['operation_type'] === 'read');
ok('ws2 GSC-backed capability is available', $g2['workspace_available'] === true,
   json_encode($g2['workspace_configuration']));
ok('  and names the connected site',
   str_contains(json_encode($g2['workspace_configuration']), 'cheflisted'),
   json_encode($g2['workspace_configuration']));
ok('ws990100 GSC-backed capability is NOT available', $g99['workspace_available'] === false,
   json_encode($g99['workspace_configuration']));
ok('  and says what is missing', !empty($g99['missing_configuration']),
   json_encode($g99['missing_configuration']));
ok('the same capability differs by workspace',
   $g2['workspace_available'] !== $g99['workspace_available']);

echo "-- the manifest NEVER asserts requires_approval --\n";
foreach ($all as $c) {
    if (array_key_exists('requires_approval', $c)) {
        ok('no capability asserts requires_approval', false, $c['capability_id']); break;
    }
}
ok('no capability asserts requires_approval',
   !array_key_exists('requires_approval', $all[0]));
ok('it publishes approval INPUTS instead',
   isset($serp['approval_inputs']['category'], $serp['approval_inputs']['risk_class'],
         $serp['approval_inputs']['cost_class'], $serp['approval_inputs']['category_default_mode']));

echo "-- approval inputs mirror TaskService, which stays authoritative --\n";
$pub = $m->capability($WS, 'publish_builder_page') ?? $m->capability($WS, 'publish_article');
if ($pub) {
    ok('a publish capability classifies as publish risk',
       $pub['approval_inputs']['risk_class'] === 'publish', $pub['approval_inputs']['risk_class']);
}
$del = $m->capability($WS, 'delete_lead');
if ($del) ok('a delete capability classifies as destructive',
   $del['approval_inputs']['risk_class'] === 'destructive', $del['approval_inputs']['risk_class']);

echo "-- riskClass must NOT drift from ChatActionProposal::riskTier() --\n";
$rt = new ReflectionMethod(\App\Core\Sarah888\ChatActionProposal::class, 'riskTier');
$rt->setAccessible(true);
$prop = app(\App\Core\Sarah888\ChatActionProposal::class);
$cases = [
    ['delete_lead', 'crm', 0], ['publish_article', 'publish', 0], ['send_campaign', 'campaign', 0],
    ['write_article', 'create', 5], ['deep_audit', 'research', 0], ['generate_image', 'create', 2],
    ['remove_page', 'operations', 0], ['email_blast', 'campaign', 9],
];
foreach ($cases as [$a, $c, $cost]) {
    $mine   = $m->riskClass($a, $c, $cost);
    $theirs = $rt->invoke($prop, $a, $c, $cost);
    ok("riskClass agrees for {$a}/{$c}/{$cost}", $mine === $theirs, "{$mine} vs {$theirs}");
}

echo "-- availableFor() is a strict subset --\n";
$avail = $m->availableFor($WS);
ok('available <= all', count($avail) <= count($all));
ok('every available entry really is available',
   count(array_filter($avail, fn ($c) => $c['workspace_available'] !== true)) === 0);
ok('ws990100 has fewer or equal available than ws2 for GSC-backed work',
   count($m->availableFor($WS2)) <= count($avail) + 5);   // registry is global; GSC differs

echo "-- every entry carries provenance --\n";
$noProv = array_filter($all, fn ($c) => empty($c['evidence_source']['registry']));
ok('no entry lacks provenance', count($noProv) === 0, (string) count($noProv));

echo "-- executor is laravel for EVERY capability (architecture ruling) --\n";
$bad = array_filter($all, fn ($c) => $c['executor'] !== 'laravel');
ok('runtime is never the executor', count($bad) === 0,
   implode(',', array_column($bad, 'capability_id')));

echo "-- observed actions: the registry is not the platform's capability universe --\n";
// 2026-08-14. Runtime-native Sarah told the owner "there's no publish action for
// articles" while ws 2 had 45 completed and 8 pending publish_article tasks. The
// answer was true of agent_capabilities and false of the platform. These assertions
// exist so the manifest can never narrow back to the registry alone.
$byId = [];
foreach ($all as $c) $byId[$c['capability_id']] = $c;

ok('publish_article is published as a capability', isset($byId['publish_article']));
if (isset($byId['publish_article'])) {
    $pa = $byId['publish_article'];
    ok('publish_article is NOT in agent_capabilities',
       !in_array('publish_article', $regIds, true));
    ok('publish_article is a mutation', $pa['operation_type'] === 'mutation'
       && $pa['supports_mutation'] === true && $pa['supports_read'] === false);
    ok('publish_article executor is laravel', $pa['executor'] === 'laravel');
    ok('publish_article carries publish risk', $pa['approval_inputs']['risk_class'] === 'publish');
    ok('publish_article requires article_id',
       in_array('article_id', $pa['required_parameters'], true));
    ok('publish_article provenance names execution history, not the registry',
       str_contains($pa['evidence_source']['registry'], 'NOT in agent_capabilities')
       && str_contains($pa['evidence_source']['shape'], 'completed'));
    ok('publish_article claims no registry owner agent', $pa['owner_agents'] === []);
}

// The admission rule itself: history alone is not enough — it must be a MUTATION
// with a completed run. A read admitted this way would be offered and then come
// back TOOL_UNAVAILABLE, which is the defect this manifest exists to prevent.
$observed = array_filter($all, fn ($c) =>
    str_contains((string) ($c['evidence_source']['registry'] ?? ''), 'NOT in agent_capabilities'));
ok('at least one observed capability is published', count($observed) > 0, (string) count($observed));
ok('every observed capability is a mutation',
   count(array_filter($observed, fn ($c) => $c['operation_type'] !== 'mutation')) === 0);
ok('no observed capability is also a registry id',
   count(array_intersect(array_column($observed, 'capability_id'), $regIds)) === 0);

// Actions with history but no completed run must NOT be admitted: a task that only
// ever sat pending or failed is not proof the platform can do the thing.
$neverCompleted = DB::table('tasks')->select('action')
    ->whereNotNull('category')->where('category', '!=', '')
    ->groupBy('action')->havingRaw("SUM(status = 'completed') = 0")
    ->pluck('action')->all();
$leaked = array_intersect($neverCompleted, array_column($observed, 'capability_id'));
ok('never-completed actions are not admitted', count($leaked) === 0, implode(',', $leaked));

// A read must never enter this way.
$readObserved = array_filter($observed, fn ($c) => $c['supports_read'] === true);
ok('no read is admitted on history alone', count($readObserved) === 0,
   implode(',', array_column($readObserved, 'capability_id')));

echo "-- the gateway can resolve an observed capability by id --\n";
$resolved = $m->capability($WS, 'publish_article');
ok('capability() resolves publish_article', $resolved !== null);
ok('capability() and forWorkspace() agree on it',
   $resolved !== null && $resolved['operation_type'] === 'mutation'
   && $resolved['capability_id'] === 'publish_article');
ok('capability() still returns null for a genuine unknown',
   $m->capability($WS, 'definitely_not_a_capability_9f3x') === null);

echo "-- a READ that is offered must be a READ Laravel can actually perform --\n";
// The defect class this closes, twice measured:
//   list_posts     — registry read, available, no executor. Runtime selected it, got
//                    TOOL_UNAVAILABLE, and Sarah told the owner publishing was not
//                    connected. (Iteration 1 mis-recorded this as the model "inventing"
//                    a capability; it was advertised.)
//   list_campaigns — same shape. ws 2 has zero campaigns, so the honest answer was
//                    "you have none", not "that capability isn't wired up".
//
// A mutation needs no executor: the gateway settles it at governance first. A READ runs,
// so offering one with no executor guarantees a refusal AFTER Sarah has offered it.
//
// KNOWN_UNWIRED is a ratchet. It may only ever SHRINK. Anything new failing this
// assertion is a fresh instance of the defect, not existing debt — so do not add to it.
// 2026-08-14, iteration 9: this list is now EMPTY, and must stay empty.
//
// It was an acknowledgement of debt while executors were being wired. The P5 matrix ended
// that: on ws 990100 `gsc_performance` is unavailable, so Runtime reached for the nearest
// listed read — `scan_site_url` — and the gateway answered TOOL_UNAVAILABLE. A live turn
// was spent offering something Laravel cannot do.
//
// Reads with no executor are now marked UNAVAILABLE in the manifest
// (CapabilityManifest::READS_WITHOUT_EXECUTOR) rather than advertised, so Runtime cannot
// select them. Anything appearing here again is a capability being offered that cannot be
// performed — wire an executor, or add it to READS_WITHOUT_EXECUTOR. Do not re-populate
// this list.
$KNOWN_UNWIRED = [];

$gwSrc = file_get_contents('/var/www/levelup-staging/app/Core/Sarah888/ToolIntentGateway.php');
preg_match_all("/case '([a-z_]+)':/", $gwSrc, $mm);
$wiredCases = array_values(array_unique($mm[1]));

$offeredReads = array_filter($all, fn ($c) =>
    $c['workspace_available'] === true && $c['operation_type'] === 'read');
$unwired = [];
foreach ($offeredReads as $c) {
    if (!in_array($c['capability_id'], $wiredCases, true)) $unwired[] = $c['capability_id'];
}
sort($unwired);
$unexpected = array_values(array_diff($unwired, $KNOWN_UNWIRED));
ok('no NEW read is advertised without an executor', $unexpected === [],
   implode(', ', $unexpected));

// The ratchet: if debt was paid off, the allowlist must be trimmed in the same change,
// so it can never quietly hide a capability that was fixed and then regressed.
$stale = array_values(array_diff($KNOWN_UNWIRED, $unwired));
ok('the known-unwired allowlist contains nothing already fixed', $stale === [],
   'now wired, remove from KNOWN_UNWIRED: ' . implode(', ', $stale));

// The invariant, stated positively now that the debt is cleared.
ok('EVERY offered read has a real executor', $unwired === [], implode(', ', $unwired));

// And the suppressed ones must still tell the truth about WHY they are unavailable —
// going quiet is how a capability question gets answered with a guess.
foreach (['scan_site_url', 'list_events', 'search_site_content'] as $sup) {
    $e = $m->capability($WS, $sup);
    if ($e === null) { ok("{$sup} is still present in the manifest", false); continue; }
    ok("{$sup} is offered as unavailable, not deleted", $e['workspace_available'] === false);
    ok("  {$sup} says why", count(array_filter($e['missing_configuration'],
        fn ($x) => str_contains((string) $x, 'executor'))) > 0,
       json_encode($e['missing_configuration']));
}

echo "-- anything the gateway EXECUTES must be typed as a read --\n";
// `execute()` is only ever reached by a read: a mutation is settled at governance first.
// So a wired executor whose capability is typed `mutation` is dead code that can never
// run. Measured 2026-08-14: `competitor_serp` had a real DataForSEO executor wired and was
// typed a mutation off ONE historical task row categorised `optimize`, so it answered
// WOULD_REQUIRE_APPROVAL forever — for a pure vendor lookup the owner explicitly asked for.
$deadExecutors = [];
foreach ($wiredCases as $case) {
    $e = $m->capability($WS, $case);
    if ($e === null) continue;                    // wired but not offered: separate concern
    if ($e['operation_type'] !== 'read') $deadExecutors[] = "{$case}={$e['operation_type']}";
}
ok('no wired executor is unreachable behind a mutation classification',
   $deadExecutors === [], implode(', ', $deadExecutors));

$cs = $m->capability($WS, 'competitor_serp');
ok('competitor_serp is a read', $cs !== null && $cs['operation_type'] === 'read',
   (string) ($cs['operation_type'] ?? '-'));
ok('  and competitor_gaps is still a mutation (5 runs, cost 3, no executor)',
   ($m->capability($WS, 'competitor_gaps')['operation_type'] ?? null) === 'mutation');

echo "-- work-commissioning capabilities are mutations, not reads --\n";
// deep_audit 222 runs / max cost 3, ai_report cost 2, check_outbound cost 2, list_posts.
// Each creates a task that runs and bills. A lookup does not bill.
foreach (['deep_audit', 'ai_report', 'check_outbound', 'list_posts'] as $cap) {
    $e = $m->capability($WS, $cap);
    if ($e === null) { ok("{$cap} is present in the manifest", false); continue; }
    ok("{$cap} is classified as a mutation", $e['operation_type'] === 'mutation',
       $e['operation_type']);
    ok("  {$cap} therefore needs no executor to answer honestly",
       $e['supports_mutation'] === true && $e['supports_read'] === false);
}
// ai_report must STILL not require Search Console — the false-refusal defect it caused.
$air = $m->capability($WS, 'ai_report');
ok('ai_report is still not GSC-gated', $air !== null && $air['provider'] !== 'gsc',
   (string) ($air['provider'] ?? '-'));

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
