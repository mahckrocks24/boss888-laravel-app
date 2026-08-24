<?php
/**
 * SARAH888 Slice 1W — retrieval integration and paraphrase robustness.
 *
 * The ruling requires that derived answers not depend on wording memorised
 * from the certification script, so the date path is probed with many
 * phrasings of the same question, plus phrasings that must NOT trigger it.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Core\Sarah888\{CognitiveFrame, TemporalAnchor};

$ws = 990100;
$cf = app(CognitiveFrame::class);
$ta = app(TemporalAnchor::class);
$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  PASS  %s\n", $l); }
    else     { $fail++; printf("  FAIL  %s   %s\n", $l, $d); }
}

echo "=== the SAME question, many phrasings — all must compute 2026-08-24 ===\n";
$want = '2026-08-24';
$phrasings = [
    'What date is 24 days before 17 September?',
    '24 days before 17 September',
    'whats 24 days before 17 sept',
    'Can you work out 24 days before 17 September for me?',
    'I need the date 24 days before 17 September please',
    'If something is due 17 September, what is 24 days before that?',   // trailing words
    'Tell me 24 days before 17 Sep 2026.',
    'remind me what 24 days before 17 September works out as',
    '24 DAYS BEFORE 17 SEPTEMBER',
    'hey — 24 days before 17 September, what is it?',
];
foreach ($phrasings as $p) {
    $r = $ta->resolveExpression($p, $ws);
    chk(sprintf('%-58s', substr($p, 0, 58)), $r !== null && $r['value'] === $want, $r['value'] ?? 'null');
}

echo "\n=== other units and directions ===\n";
foreach ([
    ['6 weeks after 1 January 2027',  '2027-02-12'],
    ['2 months before 1 March 2027',  '2027-01-01'],
    ['1 year before 1 June 2027',     '2026-06-01'],
    ['10 days from 1 December 2026',  '2026-12-11'],
    ['30 days prior to 1 May 2027',   '2027-04-01'],
] as [$expr, $exp]) {
    $r = $ta->resolveExpression($expr, $ws);
    chk(sprintf('%-34s = %s', $expr, $exp), $r && $r['value'] === $exp, $r['value'] ?? 'null');
}

echo "\n=== must NOT fire (no arithmetic asked for) ===\n";
foreach ([
    'How many active commitments do we have?',
    'Who owns the studio rebuild?',
    'What happened last week?',
    'Cancel the pottery podcast',
    'We shipped 24 boxes after the September order',   // numbers + month, but no relation
] as $p) {
    $fr = $cf->build($ws, 'sarah', CognitiveFrame::BUDGET_CHARS, $p);
    chk(sprintf('%-58s', substr($p, 0, 58)), !str_contains($fr['frame'], 'COMPUTED FOR THIS TURN'));
}

echo "\n=== unresolvable arithmetic falls through rather than guessing ===\n";
foreach ([
    '24 days before the thing we discussed',
    '5 days after whenever that was',
] as $p) {
    $fr = $cf->build($ws, 'sarah', CognitiveFrame::BUDGET_CHARS, $p);
    chk(sprintf('%-58s', substr($p, 0, 58)), !str_contains($fr['frame'], 'COMPUTED FOR THIS TURN'));
}

echo "\n=== anchoring on a commitment the workspace actually knows ===\n";
$r = $ta->resolveExpression('7 days before the studio rebuild', $ws);
chk('resolves against a real commitment deadline', $r !== null && $r['source_domain'] === 'sarah_commitments',
    json_encode($r ? [$r['value'], $r['source_domain'], $r['source_ids']] : null));
chk('and carries the source record id', $r && count($r['source_ids']) === 1);

echo "\n=== the frame always carries the anchor, whatever the turn ===\n";
foreach (['anything', 'What date is 24 days before 17 September?', ''] as $p) {
    $fr = $cf->build($ws, 'sarah', CognitiveFrame::BUDGET_CHARS, $p);
    chk(sprintf('anchor present for %-30s', '"' . substr($p,0,28) . '"'),
        str_contains($fr['frame'], date('Y-m-d')) && $fr['chars'] <= CognitiveFrame::BUDGET_CHARS);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
