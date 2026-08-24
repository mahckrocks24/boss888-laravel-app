<?php
/**
 * SARAH888 Slice 1X — a request for a calculation must never become a
 * commitment (S1X-D01, found live on the forensic tenant).
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ex = app(\App\Core\Sarah888\CommitmentExtractor::class);
$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  PASS  %s\n", $l); }
    else     { $fail++; printf("  FAIL  %s   %s\n", $l, $d); }
}
function commitments(array $r): array {
    return array_values(array_filter($r, fn($h) => ($h['type'] ?? '') === 'commitment'
        && ($h['confidence'] ?? 0) >= 0.6));
}

echo "=== S1X-D01 requests for a computation are NOT commitments ===\n";
foreach ([
    'Work out 90 days after 1 October for me.',
    'Work out 24 days before 17 September.',
    'Tell me 30 days after 1 June.',
    'Show me what 2 weeks before 14 March is.',
    'Calculate 45 days after 3 April.',
    'Give me the date 10 days before 1 May.',
    'Remind me what 24 days before 17 September works out as.',
    'Figure out 6 weeks after 1 January 2027.',
    'What date is 24 days before 17 September?',
    'Can you work out 90 days after 1 October?',
] as $q) {
    $c = commitments($ex->extract($q));
    chk(sprintf('%-52s', substr($q, 0, 52)), count($c) === 0,
        json_encode(array_map(fn($h) => $h['title'], $c)));
}

echo "\n=== NEGATIVES — real work scheduled relative to a date STILL records ===\n";
foreach ([
    'Ship the order 3 days before 17 September.',
    'Send the invoice 30 days after 1 October.',
    'Publish the campaign 2 weeks before 14 March.',
    'Close the books 5 days after 31 March.',
    'Chase the stockists 10 days before 1 May.',
] as $q) {
    $c = commitments($ex->extract($q));
    chk(sprintf('%-52s', substr($q, 0, 52)), count($c) >= 1,
        json_encode(array_map(fn($h) => $h['title'], $c)));
}

echo "\n=== ordinary commitments are untouched by this guard ===\n";
foreach ([
    'Clear the pallet invoice for 14,800 pounds.',
    'Update the stockist map.',
    'We need to finish the studio rebuild by 30 July.',
    'Make sure the trade terms are reprinted.',
] as $q) {
    chk(sprintf('%-52s', substr($q, 0, 52)), count(commitments($ex->extract($q))) >= 1);
}

echo "\n=== ordinary questions still record nothing ===\n";
foreach ([
    'How many active commitments do we have right now?',
    'Who is carrying the most active commitments?',
    'Which deadline comes first from today?',
    "What is today's date?",
] as $q) {
    chk(sprintf('%-52s', substr($q, 0, 52)), count(commitments($ex->extract($q))) === 0);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
