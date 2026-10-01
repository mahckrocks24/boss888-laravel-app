<?php
// HIDE-CONNECT-1: the dormant "WordPress plugin — keep your WordPress site" door in marketing-next/src/journey-data.php
// (defined, rendered nowhere, absent from dist) is removed so no build can resurrect it.
$p = '/var/www/levelup-staging/marketing-next/src/journey-data.php'; $s = file_get_contents($p);
$old = <<<'X'
        [
            'name' => 'WordPress plugin',
            'icon' => 'globe',
            'live' => true,
            'href' => '/next/product/seo/',
            'line' => 'Keep your WordPress site. The connector plugin puts the audits, keywords, quick wins and the chatbot inside your own admin.',
        ],

X;
if (substr_count($s, $old) !== 1) { fwrite(STDERR, "anchor x" . substr_count($s, $old) . "\n"); exit(1); }
copy($p, $p . '.before-hideconnect1');
file_put_contents($p, str_replace($old, "        // HIDE-CONNECT-1 (Owner 2026-10-01): the 'keep your WordPress site' door is withdrawn — new users build a new website.\n", $s));
echo "ok journey-data door removed\n";
