<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Core\Sarah888\SpendPolicy;

$p = app(SpendPolicy::class);
$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

echo "=== the 10 adversarial turns — 8 of these created work ===\n";
foreach ([
 'Cancel the glaze lab refit.'                 => true,
 'Actually, never mind — what is our SEO status?' => true,
 'Yes, do it.'                                 => true,   // authorisation, binds to a proposal
 'Do the thing we discussed.'                  => false,
 'Just handle it.'                             => false,
 'You know what to do.'                        => false,
 'Same as last time.'                          => false,
 'Sort it out for me.'                         => false,
 'Whatever you think is best.'                 => false,
 'Make it happen.'                             => false,
] as $q => $want) {
    $got = $p->specifiesAction($q);
    ok(sprintf('%-46s specifies=%s', substr($q,0,46), $want?'yes':'NO'), $got === $want,
       'got ' . var_export($got,true));
}

echo "\n=== NEGATIVES — real instructions must still specify ===\n";
foreach ([
 'Draft a short article about the autumn range.',
 'Publish the winter range article.',
 'Clear the pallet invoice for 14,800 pounds.',
 'Fix the orphan links on the affected pages.',
 'Send the trade newsletter to every stockist.',
 'Research competitor pricing.',
 'Generate a hero image for the winter range.',
 'Delete all failed tasks.',
] as $q) {
    ok(sprintf('%-46s specifies', substr($q,0,46)), $p->specifiesAction($q) === true);
}

echo "\n=== authorisations still pass (they bind to a proposal) ===\n";
foreach (['Yes, proceed.','Go ahead.','Approved.','Do it.','Proceed.'] as $q) {
    ok(sprintf('%-46s treated as specified', substr($q,0,46)), $p->specifiesAction($q) === true);
}

echo "\n=== the flag reaches assessTurn() ===\n";
$t = $p->assessTurn('Just handle it.');
ok('assessTurn carries specifies_action', array_key_exists('specifies_action',$t), json_encode(array_keys($t)));
ok('and it is false for a vague delegation', ($t['specifies_action'] ?? null) === false);
$t2 = $p->assessTurn('Draft an article about the autumn range.');
ok('and true for a real instruction', ($t2['specifies_action'] ?? null) === true);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail>0?1:0);
