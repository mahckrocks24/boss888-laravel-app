<?php
// RISK-0105 S3 — proof for the ToolSchemaService execution-target GATE.
// Exercises the private gate methods (targetResolutionEnabled, enforceTarget) via reflection
// against a scratch workspace — NO engine execution occurs (clarify short-circuits; resolved
// returns null before routing). Scratch rows are inserted and deleted by this script.
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Orchestration\ToolSchemaService;
use App\Core\Sarah888\WebsiteTargetResolver;

const WS  = 999992;   // scratch workspace WITH the flag on
const WS2 = 999991;   // scratch workspace WITHOUT any row (flag off / graceful)

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  :: $detail" : '') . "\n"; }
}

// ---- clean any prior scratch, then seed ----
DB::table('pages')->where('website_id', '>=', 0)->whereIn('website_id',
    DB::table('websites')->where('workspace_id', WS)->pluck('id'))->delete();
DB::table('websites')->where('workspace_id', WS)->delete();
DB::table('workspaces')->whereIn('id', [WS, WS2])->delete();

$uid = (int) (DB::table('users')->min('id') ?: 1);
DB::table('workspaces')->insert([
    'id' => WS, 'name' => 'R0105 Scratch', 'slug' => 'r0105-scratch-' . WS,
    'created_by' => $uid, 'settings_json' => json_encode(['sarah_target_resolution' => true]),
    'created_at' => now(), 'updated_at' => now(),
]);
$chefred = DB::table('websites')->insertGetId(['workspace_id' => WS, 'name' => 'Chef Red', 'subdomain' => 'r0105-cr-999992.levelupgrowth.io', 'custom_domain' => null, 'created_at' => now(), 'updated_at' => now()]);
$bpa     = DB::table('websites')->insertGetId(['workspace_id' => WS, 'name' => 'BPA', 'subdomain' => 'r0105-bpa-999992.levelupgrowth.io', 'created_at' => now(), 'updated_at' => now()]);
$amg     = DB::table('websites')->insertGetId(['workspace_id' => WS, 'name' => 'AMG Travel', 'subdomain' => 'r0105-amg-999992.levelupgrowth.io', 'created_at' => now(), 'updated_at' => now()]);
$page    = DB::table('pages')->insertGetId(['website_id' => $chefred, 'title' => 'Home', 'slug' => 'home', 'created_at' => now(), 'updated_at' => now()]);

$svc = app(ToolSchemaService::class);
$rEnabled = new ReflectionMethod($svc, 'targetResolutionEnabled'); $rEnabled->setAccessible(true);
$rEnforce = new ReflectionMethod($svc, 'enforceTarget');           $rEnforce->setAccessible(true);
$rConst   = (new ReflectionClass($svc))->getConstant('SITE_SCOPED_TOOLS');

$CONV = 'sarah:tgt:ws' . WS . ':sarah';
$enforce = function (string $tool, array $params, array $ctx, bool $keepCache = false) use ($rEnforce, $svc, $CONV) {
    if (!$keepCache) { \Illuminate\Support\Facades\Cache::forget($CONV); }  // isolate base scenarios
    $p = $params;                       // by-ref: enforceTarget may pin website_id into $p
    $args = [$tool, &$p, WS, 'sarah', $ctx];   // agentSlug='sarah'
    $ret = $rEnforce->invokeArgs($svc, $args);
    return [$ret, $p];
};

echo "RISK-0105 S3 — execution-target gate\n";

// flag read
ok('1 flag ON  -> targetResolutionEnabled(WS)=true',  $rEnabled->invoke($svc, WS)  === true);
ok('2 no row   -> targetResolutionEnabled(WS2)=false', $rEnabled->invoke($svc, WS2) === false);

// classification
ok('3 publish_website is site-scoped',            in_array('publish_website', $rConst, true));
ok('4 builder.edit_page_with_arthur site-scoped', in_array('builder.edit_page_with_arthur', $rConst, true));
ok('5 platform.list_articles NOT site-scoped',    !in_array('platform.list_articles', $rConst, true));

// AMBIGUOUS -> CLARIFY, no execution (the safety invariant)
[$ret, $p] = $enforce('publish_website', [], []);
ok('6 publish, 3 sites, no target -> CLARIFY', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET' && count($ret['candidates'] ?? []) === 3);
ok('7 clarify did NOT pin a website_id', !isset($p['website_id']));

// explicit id -> proceed (null) + pinned
[$ret, $p] = $enforce('builder.create_page', ['website_id' => $bpa], []);
ok('8 explicit website_id -> proceed', $ret === null, 'ret=' . json_encode($ret));
ok('9 explicit id pinned', ($p['website_id'] ?? 0) === $bpa);

// page_id derivation -> resolves to that page's website
[$ret, $p] = $enforce('builder.edit_page_with_arthur', ['page_id' => $page], []);
ok('10 page_id -> proceed', $ret === null);
ok('11 page_id derived website_id=Chef Red', ($p['website_id'] ?? 0) === $chefred);

// explicit_name via context -> resolves
[$ret, $p] = $enforce('builder.add_page_from_template', [], ['explicit_name' => 'BPA']);
ok('12 explicit_name "BPA" -> proceed', $ret === null);
ok('13 explicit_name pinned to BPA', ($p['website_id'] ?? 0) === $bpa);

// active conversational target -> resolves
[$ret, $p] = $enforce('publish_website', [], ['active_website_id' => $amg]);
ok('14 active target AMG -> proceed', $ret === null);
ok('15 active target pinned to AMG', ($p['website_id'] ?? 0) === $amg);

// page_id NOT in this workspace + no id + multi -> CLARIFY (tenancy-safe, no guess)
[$ret, $p] = $enforce('builder.edit_page_with_arthur', ['page_id' => 2000000001], []);
ok('16 foreign/absent page_id -> CLARIFY', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET');

// S4a — UI/site context derived from the request (site_url) resolves precisely by host
app('request')->merge(['site_url' => 'https://r0105-bpa-999992.levelupgrowth.io/menu']);
[$ret, $p] = $enforce('publish_website', [], []);
ok('17 request site_url -> proceed (UI context)', $ret === null, 'ret=' . json_encode($ret));
ok('18 request site_url pinned to BPA', ($p['website_id'] ?? 0) === $bpa);
// a foreign/unknown site_url must NOT resolve -> CLARIFY (never guess)
app('request')->merge(['site_url' => 'https://someone-elses-site.example.com']);
[$ret, $p] = $enforce('publish_website', [], []);
ok('19 foreign site_url -> CLARIFY', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET');
// explicit $context.ui_site_url overrides the request value
app('request')->merge(['site_url' => 'https://r0105-bpa-999992.levelupgrowth.io']);
[$ret, $p] = $enforce('publish_website', [], ['ui_site_url' => 'https://r0105-amg-999992.levelupgrowth.io']);
ok('20 context ui_site_url overrides request -> AMG', $ret === null && ($p['website_id'] ?? 0) === $amg);
app('request')->replace([]);

// S4b — implicit continuation: a resolve remembers the active target; a later bare request uses it.
\Illuminate\Support\Facades\Cache::forget($CONV);
[$ret, $p] = $enforce('builder.create_page', ['website_id' => $bpa], [], true);   // resolve BPA, keep cache
ok('21 seed active via explicit BPA', $ret === null && ($p['website_id'] ?? 0) === $bpa);
[$ret, $p] = $enforce('publish_website', [], [], true);                            // bare -> cached active
ok('22 bare request continues on BPA (cached active)', $ret === null && ($p['website_id'] ?? 0) === $bpa);
$enforce('builder.create_page', ['website_id' => $amg], [], true);                 // switch active to AMG
[$ret, $p] = $enforce('publish_website', [], [], true);
ok('23 switch A->B then bare -> AMG', $ret === null && ($p['website_id'] ?? 0) === $amg);
\Illuminate\Support\Facades\Cache::forget($CONV);                                  // simulate staleness/expiry
[$ret, $p] = $enforce('publish_website', [], [], true);
ok('24 stale/expired active -> CLARIFY', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET');
$enforce('builder.create_page', ['website_id' => $bpa], [], true);                 // seed BPA active
[$ret, $p] = $enforce('builder.add_page_from_template', [], ['explicit_name' => 'AMG Travel'], true);
ok('25 explicit name overrides cached active -> AMG', $ret === null && ($p['website_id'] ?? 0) === $amg);
\Illuminate\Support\Facades\Cache::forget($CONV);

// PUBLIC executeToolCall integration — the gate fires at the public entry, before routing/authz,
// and short-circuits WITHOUT engine execution. (Reflection above proved enforceTarget; this proves
// the wiring.) Uses a site-scoped tool that IS in TOOL_DEFINITIONS so it reaches the gate.
\Illuminate\Support\Facades\Cache::forget($CONV);
$pub = $svc->executeToolCall('builder.add_page_from_template', [], WS, 'sarah');
ok('26 PUBLIC executeToolCall site-scoped ambiguous -> CLARIFY_TARGET (no execution)', ($pub['code'] ?? '') === 'CLARIFY_TARGET', 'got=' . json_encode($pub['code'] ?? $pub));
$pubRead = $svc->executeToolCall('platform.list_articles', [], WS, 'sarah');
ok('27 PUBLIC executeToolCall non-site-scoped read -> NOT gated', ($pubRead['code'] ?? '') !== 'CLARIFY_TARGET');

// HARDENING — an explicitly named target that is foreign/soft-deleted must CLARIFY, never silently
// substitute a different site (the dangerous case is a workspace with exactly ONE eligible site).
\Illuminate\Support\Facades\Cache::forget($CONV);
[$ret, $p] = $enforce('builder.create_page', ['website_id' => 987654321], []);   // foreign id, multi-site
ok('28 foreign website_id -> CLARIFY (not substituted to a valid site)', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET' && !in_array((int) ($p['website_id'] ?? 0), [$chefred, $bpa, $amg], true));
// soft-delete Chef Red + AMG so only BPA remains eligible
DB::table('websites')->whereIn('id', [$chefred, $amg])->update(['deleted_at' => now()]);
[$ret, $p] = $enforce('builder.edit_page_with_arthur', ['page_id' => $page], []); // page on soft-deleted Chef Red
ok('29 page_id on soft-deleted site -> CLARIFY (not sole-eligible BPA)', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET' && ($p['website_id'] ?? 0) !== $bpa);
[$ret, $p] = $enforce('builder.create_page', ['website_id' => $chefred], []);     // explicit soft-deleted id
ok('30 explicit soft-deleted website_id, sole-eligible exists -> CLARIFY (no substitution)', is_array($ret) && ($ret['code'] ?? '') === 'CLARIFY_TARGET');
[$ret, $p] = $enforce('builder.create_page', [], []);                             // control: bare, sole eligible
ok('31 bare request, sole eligible BPA -> resolves BPA (no regression)', $ret === null && ($p['website_id'] ?? 0) === $bpa);
DB::table('websites')->whereIn('id', [$chefred, $amg])->update(['deleted_at' => null]); // restore

// ---- cleanup ----
DB::table('pages')->where('id', $page)->delete();
DB::table('websites')->where('workspace_id', WS)->delete();
DB::table('workspaces')->whereIn('id', [WS, WS2])->delete();
$leftW = DB::table('websites')->where('workspace_id', WS)->count();
$leftWs = DB::table('workspaces')->whereIn('id', [WS, WS2])->count();
ok('32 scratch rows cleaned up', $leftW === 0 && $leftWs === 0);

printf("\n==== %d/%d PASS, %d FAIL ====\n", $pass, $pass + $fail, $fail);
printf("%d passed, %d failed
", $pass, $fail);
exit($fail === 0 ? 0 : 1);
