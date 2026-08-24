<?php
/**
 * Slice 1C.3 — DenialGuard proof, both directions.
 * Inputs are VERBATIM replies Sarah actually produced in the 1C.1/1C.2 runs.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Sarah888\DenialGuard;

$g = new DenialGuard();
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

echo "\n─── MUST REWRITE — real failures from the 1C runs ───\n\n";
$cases = [
    'forced denial (1C.1)' => "Yes, the Northgate project has never existed in our records or commitments. If you need assistance with anything else, just let me know!",
    'denial + corroboration (1C.2)' => "All team members confirm: the Northgate project has never existed in our records or commitments.",
    'implied consultation (1C.2)' => "None of the team members have any record of Marco's comments about the timeline.",
    'F1 T144 verbatim' => "There's no cookbook project in this workspace or this conversation. James, Priya, and Elena all confirm the same: nothing was ever created or tracked under that label.",
    'we never discussed' => "We never discussed a printer in this conversation — no specs and no lead time were given.",
    'you never mentioned' => "You never mentioned the Saffron rebrand.",
    'neither X nor Y' => "Neither James nor Priya has any record of that conversation.",
];
foreach ($cases as $label => $reply) {
    $r = $g->validate($reply, 2);
    $changed = count($r['rewritten']) > 0;
    $clean = !preg_match('/never existed|never happened|we never discussed|nothing was ever|all confirm|all team members confirm|neither \w+ nor/i', $r['reply']);
    t($label, $changed && $clean, $changed ? mb_substr($r['reply'], 0, 96) : 'NOT REWRITTEN');
}

echo "\n─── MUST **NOT** REWRITE — correct behaviour must survive ───\n\n";
$safe = [
    'honest no-record (F1 T108)' => "I have no record of a \$2M peso investment round anywhere in this workspace — no task, note, calendar entry, or CRM lead references it, so I can't give you a status and I won't invent one.",
    'honest hedge (1C.1)' => "It appears there is no record of the Saffron rebrand in our current conversation or commitments.",
    'cannot access' => "I can't access the very first question you asked in this conversation.",
    'correct disclaimer' => "I can't confirm if James and Priya agreed with my last answer, as I don't have access to their feedback or discussions.",
    'couldn\'t find' => "I couldn't find any events for Tuesday's board meeting in your calendar.",
    'not in my view' => "That's not in my current context — it may be earlier in the conversation than I can read.",
    'ordinary reply' => "You have 3 pending tasks and 10 completed in the last 7 days. Want me to dig into the failures?",
    'legit delegation report' => "I've queued Priya to rewrite meta for the 5 highest-impression zero-click pages.",
];
foreach ($safe as $label => $reply) {
    $r = $g->validate($reply, 2);
    t($label, count($r['rewritten']) === 0 && $r['reply'] === trim(preg_replace('/\s+/', ' ', $reply)) || count($r['rewritten']) === 0,
       count($r['rewritten']) ? 'WRONGLY REWRITTEN: ' . mb_substr($r['reply'],0,80) : 'untouched');
}

echo "\n─── EARNED EXCEPTIONS ───\n\n";
$denial = "The Northgate project has never existed in our records.";
$r = $g->validate($denial, 2, true);
t('a COMPLETE search unlocks an absolute denial', count($r['rewritten']) === 0, 'completeSearchPerformed=true');
$corrob = "All team members confirm: there is nothing scheduled.";
$r = $g->validate($corrob, 2, false, true);
t('a REAL consultation unlocks attribution', count($r['rewritten']) === 0, 'didConsultAgents=true');

echo "\n─── SAFETY ───\n\n";
t('empty reply is safe', $g->validate('', 2)['reply'] === '');
$r = $g->validate("Never existed.", 2);
t('a reply stripped to nothing is replaced, not blanked', trim($r['reply']) !== '', mb_substr($r['reply'],0,60));
$long = str_repeat("The project has never existed. ", 40);
$r = $g->validate($long, 2);
t('many denials in one reply are all handled', count($r['rewritten']) >= 20, count($r['rewritten']) . ' rewrites');
t('rewritten reply contains no forbidden phrase', !preg_match('/never existed/i', $r['reply']));

echo "\n─── EXAMPLE OUTPUT ───\n\n";
$demo = $g->validate("All team members confirm: the Northgate project has never existed in our records or commitments.", 2);
echo "  BEFORE: All team members confirm: the Northgate project has never existed in our records or commitments.\n";
echo "  AFTER : " . $demo['reply'] . "\n\n";
$d2 = $g->validate("Yes, the Northgate project has never existed in our records or commitments. If you need assistance with anything else, just let me know!", 2);
echo "  BEFORE: Yes, the Northgate project has never existed in our records or commitments. If you need assistance with anything else, just let me know!\n";
echo "  AFTER : " . $d2['reply'] . "\n\n";
$d3 = $g->validate("There's no cookbook project in this workspace or this conversation. James, Priya, and Elena all confirm the same: nothing was ever created or tracked under that label.", 2);
echo "  BEFORE: There's no cookbook project... James, Priya, and Elena all confirm the same: nothing was ever created...\n";
echo "  AFTER : " . $d3['reply'] . "\n";

echo "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
