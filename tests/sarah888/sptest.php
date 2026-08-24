<?php
/** Slice 1E.1 — SpendPolicy proof. Turns are verbatim from F1 and this build. */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Sarah888\SpendPolicy;

$p = new SpendPolicy();
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}
$paid = ['action' => 'generate_image_mini', 'credit_cost' => 2, 'requires_approval' => false];
$free = ['action' => 'fix_orphans', 'credit_cost' => 0, 'requires_approval' => false];

echo "\n─── QUESTIONS MUST NOT BUY ANYTHING (F1-D07) ───\n\n";
foreach ([
    'F1 T29 verbatim'   => "Media: how many published articles do we have?",
    'F1 T17 verbatim'   => "Forget that for a second. What's my SEO position right now?",
    'probe question'    => "Give me a status on the Northgate project.",
    'probe question 2'  => "What did Marco say about the timeline?",
    'count question'    => "How many credits have been spent today?",
    'status request'    => "Status.",
    'plain statement'   => "Nora owns catering outreach.",
] as $label => $turn) {
    $g = $p->gate($paid, $p->assessTurn($turn));
    t($label, $g['requires_approval'] === true && $g['gated'] === true, $p->assessTurn($turn)['classification']);
}

echo "\n─── WORK REQUESTS MUST STILL RUN (autonomy preserved) ───\n\n";
foreach ([
    'direct commission' => "Write three articles on private chef services.",
    'generate'          => "Generate the missing featured images.",
    'imperative'        => "Fix the orphan pages.",
    'open mandate'      => "Just do it.",
    'approval reply'    => "Yes, go ahead.",
    'say the word'      => "Add the missing images.",
    'directive question'=> "Can you write the article for Morristown?",
] as $label => $turn) {
    $g = $p->gate($paid, $p->assessTurn($turn));
    t($label, $g['requires_approval'] === false && $g['gated'] === false, $p->assessTurn($turn)['classification']);
}

echo "\n─── FREE WORK IS UNAFFECTED ───\n\n";
$g = $p->gate($free, $p->assessTurn("How many published articles do we have?"));
t('zero-cost work still auto-runs from a question', $g['requires_approval'] === false && $g['gated'] === false);

echo "\n─── THE GATE CAN ONLY RAISE THE BAR ───\n\n";
$destructive = ['action' => 'delete_article', 'credit_cost' => 0, 'requires_approval' => true];
$g = $p->gate($destructive, $p->assessTurn("Delete the article."));
t('an already-required approval is never downgraded', $g['requires_approval'] === true);
$g = $p->gate(['action'=>'publish_article','credit_cost'=>5,'requires_approval'=>true], $p->assessTurn("Publish it."));
t('paid AND destructive stays required', $g['requires_approval'] === true);

echo "\n─── COST IS DISCLOSED BEFORE IT IS SPENT ───\n\n";
$d = $p->disclosure([['credit_cost'=>2],['credit_cost'=>2],['credit_cost'=>2],['credit_cost'=>2]]);
t('disclosure names the item count', str_contains($d, '4 items'));
t('disclosure names the total cost', str_contains($d, '8 credits'));
t('disclosure explains WHY it was held', str_contains($d, 'asked a question rather than asking me to spend'));
t('disclosure offers the path to proceed', str_contains($d, "Say the word"));
t('no disclosure when nothing was held', $p->disclosure([]) === '');

echo "\n─── SAFETY ───\n\n";
t('empty turn is not authorised', $p->assessTurn('')['authorized'] === false);
t('empty turn gates paid work', $p->gate($paid, $p->assessTurn(''))['requires_approval'] === true);

echo "\n─── THE ORIGINAL F1 FAILURE, REPLAYED ───\n\n";
$turn = $p->assessTurn("Media: how many published articles do we have?");
$tasks = array_fill(0, 4, ['action'=>'generate_image_mini','credit_cost'=>2,'requires_approval'=>false]);
$held = []; $ran = [];
foreach ($tasks as $tk) { $g = $p->gate($tk, $turn); $g['requires_approval'] ? $held[] = $tk : $ran[] = $tk; }
t('all 4 image tasks are held, none executed', count($held) === 4 && count($ran) === 0);
t('8 credits are NOT spent', array_sum(array_column($ran, 'credit_cost')) === 0);
echo "\n    Sarah would now say:" . $p->disclosure($held) . "\n";

echo "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
