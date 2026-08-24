<?php
/**
 * Platform Security 1.0 — ATOMIC ROLLBACK.
 *
 * Restores routes/web.php and AdminPageController.php to their exact
 * pre-activation bytes. Deliberately does NOT revert the bootstrap/app.php
 * cookie exemption: that is a standalone repair of a real defect, it is inert
 * while the middleware is unattached, and reverting it would remove the fix
 * without removing anything that depends on it.
 *
 * Usage: php .engineer888/deploy/platform-security-1.0/rollback.php [--force]
 */
$root = '/var/www/levelup-staging';
$dir  = $root . '/.engineer888/deploy/platform-security-1.0';
$force = in_array('--force', $argv, true);

$manifest = json_decode(file_get_contents($dir . '/MANIFEST.json'), true);
if (! is_array($manifest)) { fwrite(STDERR, "MANIFEST unreadable\n"); exit(1); }

echo "ROLLBACK — platform-security-1.0\n\n";

$plan = [];
foreach ($manifest['files'] as $f) {
    $rel = $f['source_path'];
    $live = $root . '/' . $rel;
    $rollback = $dir . '/rollback/' . $rel;

    $liveSha = hash_file('sha256', $live);
    $rollbackSha = hash_file('sha256', $rollback);

    echo "{$rel}\n";
    echo "   live     {$liveSha}\n";
    echo "   restores {$rollbackSha}\n";

    if ($rollbackSha !== $f['rollback_sha256']) {
        fwrite(STDERR, "REFUSED: the rollback copy of {$rel} is not the one that was recorded.\n");
        exit(2);
    }

    if ($liveSha === $f['rollback_sha256']) {
        echo "   already at pre-activation state\n";
        continue;
    }

    // The expected case is live == intended (we activated). Anything else means
    // someone edited the file after activation, and restoring would discard it.
    if ($liveSha !== $f['intended_sha256'] && ! $force) {
        fwrite(STDERR, "REFUSED: {$rel} is neither the activated nor the pre-activation version.\n");
        fwrite(STDERR, "         Someone edited it after activation. Re-run with --force to discard that edit.\n");
        exit(3);
    }

    $plan[] = [$rel, $live, $rollback, $f['rollback_sha256']];
}

if ($plan === []) {
    echo "\nNothing to roll back.\n";
    exit(0);
}

echo "\nRESTORING\n";
foreach ($plan as [$rel, $live, $rollback, $want]) {
    copy($rollback, $live);
    echo "   restored {$rel}\n";
}

echo "\nVERIFICATION\n";
$bad = false;
foreach ($plan as [$rel, $live, $rollback, $want]) {
    $got = hash_file('sha256', $live);
    $lint = trim(shell_exec('php -l ' . escapeshellarg($live) . ' 2>&1'));
    $ok = $got === $want && str_contains($lint, 'No syntax errors');
    echo "   {$rel}  " . ($ok ? 'RESTORED + syntax PASS' : "FAILED got={$got} lint={$lint}") . "\n";
    if (! $ok) { $bad = true; }
}

if ($bad) {
    fwrite(STDERR, "\nROLLBACK DID NOT VERIFY. Manual intervention required.\n");
    exit(4);
}

echo "\nROLLED BACK. The admin console is back to its pre-activation behaviour.\n";
echo "NOTE: administrators holding a cookie from the activated window simply keep\n";
echo "      using the console; the cookie is ignored again, as it was before.\n";
