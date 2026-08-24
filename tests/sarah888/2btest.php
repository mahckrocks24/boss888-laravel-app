<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p = app(App\Core\Sarah888\SpendPolicy::class);

$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

echo "=== display verbs must NOT authorise work ===\n";
foreach ([
 'List the trade launch items.',
 'Show me the open tasks.',
 'Summarise the commitment record.',
 'Tell me the status.',
 'Give me the status.',
 'List the commitments with no owner.',
] as $q) {
    $t = $p->assessTurn($q);
    ok(sprintf('%-40s class=%-10s auth=%s', substr($q,0,40), $t['classification'], var_export($t['authorized'],true)),
       $t['authorized'] === false, json_encode($t));
}

echo "\n=== NEGATIVES — real work must stay authorised ===\n";
foreach ([
 'Write a list of blog ideas.',
 'Create a list of leads for the trade launch.',
 'Draft an article about the autumn range.',
 'Publish the winter article.',
 'Generate a hero image.',
 'Send the trade newsletter.',
] as $q) {
    $t = $p->assessTurn($q);
    ok(sprintf('%-40s class=%-10s auth=%s', substr($q,0,40), $t['classification'], var_export($t['authorized'],true)),
       $t['authorized'] === true, json_encode($t));
}

echo "\n=== 'can you write the article?' must remain a work order ===\n";
$t = $p->assessTurn('Can you write the article?');
ok('directive-question still authorised', $t['authorized'] === true, json_encode($t));

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail>0?1:0);
