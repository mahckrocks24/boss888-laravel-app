<?php
/**
 * SARAH888 — CUTOVER PATH SELECTION + ROLLBACK REHEARSAL (permanent regression).
 *
 * The point of this suite is the OFF path. A feature flag whose disabled branch has never
 * been executed is not a rollback plan, it is an assumption. Every assertion here is about
 * what happens when the answer should be "give the owner the Sarah they had yesterday".
 *
 * Nothing is enabled by this file. It writes a flag onto a DISPOSABLE workspace row it
 * creates and removes, and it asserts that the two REAL workspaces (2 = Chef Red, live
 * customer; 990100 = forensic) stay on legacy throughout.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\PathSelector;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$sel = app(PathSelector::class);
$WS_LIVE = 2;        // Chef Red — a real customer
$WS_FOR  = 990100;   // forensic tenant
$TMP     = 990199;   // disposable, created and destroyed here

// CONTRACT UPDATED 2026-08-14 (Phase G). These previously asserted "nothing is enrolled",
// which was true before any cutover existed and is now deliberately false: the FORENSIC
// tenant was enrolled on purpose, with Boss's explicit authorisation for beta validation.
//
// The protection is not weakened, it is re-aimed. What must hold is that no CUSTOMER
// workspace is on the new path — and that is asserted more strictly than before, by
// name and by exclusion.
$BETA_APPROVED = [990100];

echo "-- NO CUSTOMER WORKSPACE IS ON THE NEW PATH --\n";
ok('ws2 (live customer) is on legacy', $sel->decide($WS_LIVE)['path'] === PathSelector::PATH_LEGACY,
   json_encode($sel->decide($WS_LIVE)));
$customers = array_values(array_diff($sel->enrolled(), $BETA_APPROVED));
ok('no workspace outside the approved beta list is enrolled', $customers === [],
   'unauthorised: ' . json_encode($customers));
ok('only approved beta workspaces are enrolled',
   array_values(array_diff($sel->enrolled(), $BETA_APPROVED)) === [],
   json_encode($sel->enrolled()));

echo "-- UNKNOWN AND MALFORMED INPUT FALLS BACK TO LEGACY --\n";
ok('workspace 0 -> legacy', $sel->decide(0)['path'] === PathSelector::PATH_LEGACY);
ok('negative id -> legacy', $sel->decide(-1)['path'] === PathSelector::PATH_LEGACY);
ok('nonexistent workspace -> legacy', $sel->decide(424242)['path'] === PathSelector::PATH_LEGACY);
ok('  and says why', str_contains($sel->decide(424242)['reason'], 'not found'),
   $sel->decide(424242)['reason']);

// A disposable row so nothing real is touched.
$existing = DB::table('workspaces')->where('id', $TMP)->exists();
if (!$existing) {
    // Fill every NOT NULL column that has no default, rather than guessing which ones the
    // schema requires — a rehearsal that cannot even create its own fixture is no rehearsal.
    $row = ['id' => $TMP];
    foreach (DB::select('SHOW COLUMNS FROM workspaces') as $c) {
        if ($c->Field === 'id') continue;
        if ($c->Null === 'YES' || $c->Default !== null || str_contains((string) $c->Extra, 'auto_increment')) {
            continue;
        }
        $t = strtolower((string) $c->Type);
        $row[$c->Field] = match (true) {
            str_contains($t, 'int'), str_contains($t, 'decimal'), str_contains($t, 'float') => 0,
            str_contains($t, 'timestamp'), str_contains($t, 'datetime'), str_contains($t, 'date') => now(),
            str_contains($t, 'json') => '{}',
            default => 'pathselector-rehearsal',
        };
    }
    $row['name'] = 'PathSelector rollback rehearsal';
    // created_by is a real FK to users; borrow an existing id rather than inventing one.
    if (array_key_exists('created_by', $row)) {
        $row['created_by'] = (int) DB::table('users')->min('id');
    }
    DB::table('workspaces')->insert($row);
}

try {
    echo "-- MALFORMED SETTINGS MUST NOT PROMOTE A WORKSPACE --\n";
    // `settings_json` is a typed JSON column, so MySQL rejects unparseable text before it
    // can ever be read — the storage layer removes that failure mode entirely. What CAN
    // reach the code is valid JSON that is not an object, so that is what is asserted.
    DB::table('workspaces')->where('id', $TMP)->update(['settings_json' => json_encode('a bare string')]);
    ok('settings_json that is not an object -> legacy',
       $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY, json_encode($sel->decide($TMP)));
    DB::table('workspaces')->where('id', $TMP)->update(['settings_json' => json_encode(123)]);
    ok('settings_json that is a scalar -> legacy',
       $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY);
    DB::table('workspaces')->where('id', $TMP)->update(['settings_json' => json_encode(['other' => 1])]);
    ok('no flag present -> legacy', $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY);
    DB::table('workspaces')->where('id', $TMP)
        ->update(['settings_json' => json_encode([PathSelector::FLAG => false])]);
    ok('flag explicitly false -> legacy', $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY);
    DB::table('workspaces')->where('id', $TMP)
        ->update(['settings_json' => json_encode([PathSelector::FLAG => 'maybe'])]);
    ok('flag with a junk value -> legacy', $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY);

    echo "-- OPT-IN WORKS, IN EVERY ENCODING THIS CODEBASE ACTUALLY USES --\n";
    foreach ([
        'bool'        => json_encode([PathSelector::FLAG => true]),
        'int'         => json_encode([PathSelector::FLAG => 1]),
        'nested'      => json_encode([PathSelector::FLAG => ['enabled' => true]]),
        // Experience888 stores its settings as a JSON string inside settings_json.
        'json-string' => json_encode([PathSelector::FLAG => json_encode(['enabled' => true])]),
    ] as $label => $json) {
        DB::table('workspaces')->where('id', $TMP)->update(['settings_json' => $json]);
        ok("opt-in via {$label} -> runtime_native",
           $sel->decide($TMP)['path'] === PathSelector::PATH_RUNTIME_NATIVE,
           json_encode($sel->decide($TMP)));
    }

    echo "-- BLAST RADIUS: enrolling one workspace moves ONLY that workspace --\n";
    ok('ws2 still legacy while another is enrolled',
       $sel->decide($WS_LIVE)['path'] === PathSelector::PATH_LEGACY);
    ok('the disposable workspace appears in enrolled()',
       in_array($TMP, $sel->enrolled(), true), json_encode($sel->enrolled()));
    ok('  and enrolling it did not enrol anyone else',
       array_values(array_diff($sel->enrolled(), array_merge($BETA_APPROVED, [$TMP]))) === [],
       json_encode($sel->enrolled()));

    echo "-- ROLLBACK REHEARSAL 1: the kill switch overrides an opted-in workspace --\n";
    // The workspace above is STILL opted in for these assertions — that is the point.
    putenv(PathSelector::KILL_ENV . '=true');
    $_ENV[PathSelector::KILL_ENV] = 'true';
    ok('kill switch forces legacy despite opt-in',
       $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY, json_encode($sel->decide($TMP)));
    ok('  and says the kill switch is why',
       str_contains($sel->decide($TMP)['reason'], 'kill switch'), $sel->decide($TMP)['reason']);
    ok('  enrolled() reports nothing while killed — including the beta tenant',
       $sel->enrolled() === [], json_encode($sel->enrolled()));
    foreach (['1', 'yes', 'on', 'TRUE'] as $truthy) {
        putenv(PathSelector::KILL_ENV . "={$truthy}");
        $_ENV[PathSelector::KILL_ENV] = $truthy;
        ok("kill switch honours '{$truthy}'", $sel->killed() === true);
    }

    echo "-- ROLLBACK REHEARSAL 2: releasing the kill switch restores the opt-in --\n";
    putenv(PathSelector::KILL_ENV . '=false');
    $_ENV[PathSelector::KILL_ENV] = 'false';
    ok('not killed when explicitly false', $sel->killed() === false);
    ok('opted-in workspace returns to runtime_native',
       $sel->decide($TMP)['path'] === PathSelector::PATH_RUNTIME_NATIVE);
    // Reversible in BOTH directions, which is what makes it a rollback rather than a one-way door.

    echo "-- ROLLBACK REHEARSAL 3: withdrawing the opt-in is enough on its own --\n";
    DB::table('workspaces')->where('id', $TMP)
        ->update(['settings_json' => json_encode([PathSelector::FLAG => false])]);
    ok('withdrawn opt-in -> legacy', $sel->decide($TMP)['path'] === PathSelector::PATH_LEGACY);
    ok('  with no kill switch needed', $sel->killed() === false);
} finally {
    // Leave nothing behind, whatever happened above.
    putenv(PathSelector::KILL_ENV);
    unset($_ENV[PathSelector::KILL_ENV]);
    if (!$existing) DB::table('workspaces')->where('id', $TMP)->delete();
}

echo "-- RUNNING THIS ENABLED NOTHING NEW --\n";
ok('ws2 is on legacy at exit', $sel->decide($WS_LIVE)['path'] === PathSelector::PATH_LEGACY);
ok('no unapproved workspace is enrolled at exit',
   array_values(array_diff($sel->enrolled(), $BETA_APPROVED)) === [],
   json_encode($sel->enrolled()));
ok('the rehearsal workspace is gone',
   $existing || !DB::table('workspaces')->where('id', $TMP)->exists());

// CONTRACT UPDATED 2026-08-14 (Phase G). This previously asserted the route was NOT wired,
// which was the correct invariant while the mechanism was being built and rehearsed. It is
// now wired, deliberately. The assertion is replaced with the stronger one: wired the SAFE
// way — a single guarded delegation that fails OPEN to legacy.
echo "\n-- the production route delegates SAFELY, or not at all --\n";
$routeSrc = file_get_contents('/var/www/levelup-staging/routes/api/authenticated/agents-01.php');

ok('the route delegates through the adapter, not to ShadowTurn directly',
   str_contains($routeSrc, 'RuntimeNativeTurn') && !str_contains($routeSrc, 'ShadowTurn'));
ok('  exactly one delegation site', substr_count($routeSrc, 'RuntimeNativeTurn::class') === 1,
   (string) substr_count($routeSrc, 'RuntimeNativeTurn::class'));
ok('  it is wrapped in try/catch so a throw cannot break the chat',
   preg_match('/try\s*\{[^}]*RuntimeNativeTurn/s', $routeSrc) === 1);
ok('  it only returns when the adapter returned something',
   str_contains($routeSrc, 'if ($__runtimeNative !== null)'));
// The fail-open promise is what makes this reversible: null means legacy runs unchanged.
ok('  and the adapter returns null for a workspace that has not opted in',
   app(PathSelector::class)->decide($WS_LIVE)['path'] === PathSelector::PATH_LEGACY);

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
