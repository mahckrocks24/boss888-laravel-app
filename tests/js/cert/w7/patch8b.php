<?php
$p = '/var/www/levelup-staging/routes/api.php'; $s = file_get_contents($p);
$old = "    try { \$thumbnailUrl = \App\Engines\Builder\Support\SiteThumbnail::generateForUrl((int) \$websiteId, \$url); } catch (\Throwable \$e) { \$thumbnailUrl = null; }";
$new = "    // the shot takes Chrome 25-35 s on this host: it is taken after the reply is sent, and the card shows it on its next load\n    try { \$__wid = (int) \$websiteId; \$__u = (string) \$url; dispatch(function () use (\$__wid, \$__u) { try { \App\Engines\Builder\Support\SiteThumbnail::generateForUrl(\$__wid, \$__u); } catch (\Throwable \$e) {} })->afterResponse(); } catch (\Throwable \$e) {}";
if (substr_count($s, $old) !== 1) { fwrite(STDERR, "anchor x" . substr_count($s, $old) . "\n"); exit(1); }
file_put_contents($p, str_replace($old, $new, $s)); echo "ok afterResponse\n";
