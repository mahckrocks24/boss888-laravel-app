<?php
/**
 * SARAH888 Slice 1T — enumeration integrity + the bare imperative.
 *   S1T-D01  thousands separator read as a list separator
 *   S1T-D02  a scale of values split into one commitment per value
 *   S1T-D03  bare imperatives had no matcher at all
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ex = app(\App\Core\Sarah888\CommitmentExtractor::class);
$rm = new ReflectionMethod($ex, 'expandEnumeration'); $rm->setAccessible(true);
$split = fn(string $s) => $rm->invoke($ex, $s);

$pass = 0; $fail = 0;
function chk(string $label, bool $ok, string $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  PASS  %s\n", $label); }
    else     { $fail++; printf("  FAIL  %s   %s\n", $label, $detail); }
}
/** commitments only, titles */
function titles(array $r): array {
    return array_values(array_map(fn($c) => $c['title'] ?? '',
        array_filter($r, fn($c) => ($c['type'] ?? '') === 'commitment')));
}

echo "=== S1T-D01  thousands separator is not a list separator ===\n";
$r = $split('Clear the pallet invoice for 14,800 pounds and mark it settled.');
chk('14,800 never becomes "800 pounds"',
    $r === null || !in_array('800 pounds', array_map('trim', $r), true), json_encode($r));
$r = $split('Pay 1,250 pounds, order 2,400 tiles, book 3,000 flyers');
chk('three amounts kept intact, three items',
    is_array($r) && count($r) === 3 && str_contains(json_encode($r), '1,250')
    && str_contains(json_encode($r), '2,400') && str_contains(json_encode($r), '3,000'), json_encode($r));

echo "\n=== S1T-D02  a scale of values is one instruction ===\n";
chk('critical/high/medium/low not split', $split('Band them critical, high, medium, low') === null);
chk('urgent/normal/low not split',        $split('Mark the tickets urgent, normal, low') === null);
chk('one scale word does not disqualify a real list',
    is_array($x = $split('Do the stocktake, clean the CRM, mark the backlog low')) && count($x) === 3, json_encode($x));

echo "\n=== S1T-D02b genuine enumerations still split ===\n";
foreach ([
    'More for the list: pallet spec, barcode sheet, trade terms PDF, sample box, courier contract' => 5,
    'Three fronts this quarter: the wholesale push, the studio rebuild, and the workshop programme' => 3,
    'shoot 50 products, edit 50, caption 50, write 9 posts' => 4,
    'fix the kiln door seal, reprint trade terms, renew the vehicle insurance' => 3,
    'contact 30 trade leads, follow up 60 cold ones, train 5 on the portal, stocktake' => 4,
] as $s => $want) {
    $g = $split($s); $n = is_array($g) ? count($g) : 0;
    chk(sprintf('%-58s -> %d', substr($s,0,58), $want), $n === $want, "got $n");
}

echo "\n=== S1T-D03  bare imperatives are recorded ===\n";
foreach ([
    'Clear the pallet invoice for 14,800 pounds and mark it settled.',
    'Clear the pallet invoice.',
    'Pay the pallet invoice for 14,800 pounds.',
    'Ship 40 boxes to Bristol.',
    'Update the stockist map.',
    'Please rewrite the wholesale page.',
    'Reprint the trade terms.',
] as $s) {
    $t = titles($ex->extract($s));
    chk(sprintf('%-52s recorded', substr($s,0,52)), count($t) >= 1, json_encode($t));
}
$t = titles($ex->extract('Clear the pallet invoice for 14,800 pounds and mark it settled.'));
chk('the amount survives in the title', $t && str_contains($t[0], '14,800'), json_encode($t));

echo "\n=== S1T-D03 NEGATIVES — must NOT become commitments ===\n";
foreach ([
    'The invoice is due Friday.',
    'Nora owns the rebuild.',
    'Sarah booked the venue last week.',
    'Love the new logo.',
    'I like the packaging.',
    'We hate the courier.',
    'This is the third delay.',
    'There are 40 boxes left.',
    'It was the wrong pallet.',
] as $s) {
    $t = titles($ex->extract($s));
    chk(sprintf('%-52s not a commitment', substr($s,0,52)), count($t) === 0, json_encode($t));
}

echo "\n=== questions never auto-record ===\n";
foreach (['Can you clear the invoice?', 'Should we pay the invoice?'] as $s) {
    $r = array_filter($ex->extract($s), fn($c) => ($c['type'] ?? '') === 'commitment');
    $hi = array_filter($r, fn($c) => ($c['confidence'] ?? 0) >= 0.75);
    chk(sprintf('%-52s below confirm threshold', substr($s,0,52)), count($hi) === 0, json_encode(array_values($r)));
}

echo "\n=== other intents still win their clause ===\n";
chk('cancellation still a cancellation',
    ($ex->extract('Cancel the pottery podcast.')[0]['type'] ?? '') === 'cancellation',
    json_encode($ex->extract('Cancel the pottery podcast.')));

printf("\nTOTAL: %d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
