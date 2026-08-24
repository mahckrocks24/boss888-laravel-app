<?php
/**
 * EXPERIENCE888 suite 6 - ingestion eligibility policy.
 *
 * Proves the owner-beta exposure is closed: real customer workspaces are not
 * enrolled, the scheduled sweeps process only enrolled tenants, and nothing
 * mutates a disabled workspace.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Artisan};
use App\Core\Experience888\ExperienceEligibility;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$el = app(ExperienceEligibility::class);
$BETA = 990100;          // SARAH888 forensic tenant
$CUSTOMER = 2;           // Chef Red - real customer, and is_house_account = true

echo "-- ExperienceEligibilityTest --\n";

// Default is OFF for everything.
ok('a workspace is not enrolled by default', !$el->isEnabled(999864));
ok('real customer workspace is NOT swept for operational ingestion',
   !$el->isIngestionEnabled($CUSTOMER));
ok('real customer workspace DOES capture owner corrections (owner beta)',
   $el->isFeedbackEnabled($CUSTOMER));
ok('is_house_account does NOT imply operational ingestion',
   (bool) DB::table('workspaces')->where('id',$CUSTOMER)->value('is_house_account')
   && !$el->isIngestionEnabled($CUSTOMER),
   'ws2 house flag must not feed pattern formation');
ok('unknown workspace id is not enrolled', !$el->isEnabled(4242424));
ok('invalid workspace id is not enrolled', !$el->isEnabled(0));

// Enrol the beta tenant.
$el->enable($BETA, 'SARAH888 owner beta', 'engineering');
ok('beta workspace is enrolled', $el->isEnabled($BETA));
ok('enrolment recorded the reason', (function () use ($BETA) {
    $s = json_decode((string) DB::table('workspaces')->where('id',$BETA)->value('settings_json'), true);
    return ($s['experience888']['reason'] ?? '') === 'SARAH888 owner beta';
})());
ok('enrolment did not touch is_house_account',
   (int) DB::table('workspaces')->where('id',$BETA)->value('is_house_account') === 1);

// settings_json must be merged, not replaced.
$s = json_decode((string) DB::table('workspaces')->where('id',$BETA)->value('settings_json'), true);
ok('settings_json remains valid json', is_array($s));

ok('enabled list contains the beta workspace', in_array($BETA, $el->enabledWorkspaceIds(), true));
ok('ingestion sweep list excludes the customer',
   !in_array($CUSTOMER, $el->ingestionEnabledWorkspaceIds(), true),
   json_encode($el->ingestionEnabledWorkspaceIds()));
ok('exactly one workspace is swept for operational ingestion',
   count($el->ingestionEnabledWorkspaceIds()) === 1,
   json_encode($el->ingestionEnabledWorkspaceIds()));

// -- SCHEDULED SWEEP RESPECTS THE GATE ------------------------------------
echo "-- ExperienceScheduledEligibilityTest --\n";

$beforeCustomerEvents = DB::table('experience_events')->where('workspace_id',$CUSTOMER)->count();
$beforeCustomerCommit = DB::table('sarah_commitments')->where('workspace_id',$CUSTOMER)
    ->where('verification_state','!=','unverified')->count();

Artisan::call('experience888:ingest', ['--all' => true]);
$out = Artisan::output();

ok('sweep names the enrolled workspace', str_contains($out, (string) $BETA), $out);
ok('sweep does NOT name the customer workspace',
   !preg_match('/ws\s+' . $CUSTOMER . '\b/', $out), $out);
ok('customer workspace gained no events',
   DB::table('experience_events')->where('workspace_id',$CUSTOMER)->count() === $beforeCustomerEvents);
ok('verifyCommitments did not mutate the customer workspace',
   DB::table('sarah_commitments')->where('workspace_id',$CUSTOMER)
     ->where('verification_state','!=','unverified')->count() === $beforeCustomerCommit);

// Decay sweep honours the same gate.
Artisan::call('experience888:decay', ['--all' => true]);
$dout = Artisan::output();
ok('decay sweep does not name the customer workspace',
   !preg_match('/ws\s+' . $CUSTOMER . '\b/', $dout), $dout);

// Dry run stays non-mutating.
$before = DB::table('experience_events')->where('workspace_id',$BETA)->count();
Artisan::call('experience888:ingest', ['--all' => true, '--dry-run' => true]);
ok('dry run remains non-mutating',
   DB::table('experience_events')->where('workspace_id',$BETA)->count() === $before);

// Manual override still works for forensics, and warns.
Artisan::call('experience888:ingest', ['--workspace' => 999864, '--dry-run' => true]);
$mout = Artisan::output();
ok('manual --workspace works on a non-enrolled workspace', str_contains($mout, '999864'), $mout);
ok('manual override warns that it bypassed the policy',
   str_contains($mout, 'NOT enrolled'), $mout);

// Disable puts it back.
$el->disable($BETA, 'test toggle', 'engineering');
ok('disable removes enrolment', !$el->isEnabled($BETA));
Artisan::call('experience888:ingest', ['--all' => true]);
ok('sweep with nothing enrolled is a safe no-op',
   str_contains(Artisan::output(), 'No workspaces selected'));

// Restore beta enrolment - this is the state owner beta runs in.
$el->enable($BETA, 'SARAH888 owner beta', 'engineering');
ok('beta enrolment restored', $el->isEnabled($BETA));

echo "\n----------------------------------------\n";
printf("  passed: %d   failed: %d\n", $P, $F);
echo "\nENROLLED FOR OWNER BETA: " . json_encode($el->enabledWorkspaceIds()) . "\n";
exit($F > 0 ? 1 : 0);
