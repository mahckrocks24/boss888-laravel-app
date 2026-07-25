<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/tmp/NumericalValidator.php';

$V = new App\Core\Integrity\NumericalValidator();
$ev = $V->evidenceSet(2);
echo "evidence ints (sample): " . implode(',', array_slice(array_keys($ev['ints']), 0, 30)) . "\n";
echo "allowed CTRs: " . implode(',', $ev['ctrs']) . "\n\n";

$file = $argv[1] ?? '/tmp/ni_after.jsonl';
$totalStrips = 0; $respAffected = 0; $examples = [];
foreach (file($file) as $line) {
    $line = trim($line); if ($line === '') continue;
    $r = json_decode($line, true);
    $res = $V->validate($r['reply'], 2, $r['q']);
    if (!empty($res['stripped'])) {
        $respAffected++;
        $totalStrips += count($res['stripped']);
        if (count($examples) < 14) {
            $toks = array_map(fn($s) => $s['token'] . '[' . $s['reason'] . ']', $res['stripped']);
            $examples[] = "Q{$r['qid']} [{$r['cat']}] strips: " . implode(', ', $toks);
        }
    }
}
echo "==== VALIDATOR RUN on $file ====\n";
echo "responses with >=1 strip: $respAffected\n";
echo "total numbers stripped:   $totalStrips\n\n";
echo "-- sample strips (REVIEW for false positives) --\n";
foreach ($examples as $e) echo "  $e\n";

// show a full before/after for one heavy EVID case
echo "\n-- full before/after (first EVID with strips) --\n";
foreach (file($file) as $line) {
    $r = json_decode(trim($line), true);
    if ($r['cat'] !== 'EVID') continue;
    $res = $V->validate($r['reply'], 2, $r['q']);
    if (!empty($res['stripped'])) {
        echo "Q{$r['qid']}: {$r['q']}\n";
        echo "BEFORE: " . substr($r['reply'], 0, 260) . "\n";
        echo "AFTER : " . substr($res['reply'], 0, 260) . "\n";
        break;
    }
}
