<?php
/**
 * Platform Security 1.0 — ATOMIC ACTIVATION.
 *
 * Writes both files or neither. Refuses on any drift from the hashes recorded
 * when the artifacts were generated, because a file that moved since then may
 * belong to another engineer mid-edit.
 *
 * Usage: php .engineer888/deploy/platform-security-1.0/activate.php [--dry-run]
 */
$root = '/var/www/levelup-staging';
$dir  = $root . '/.engineer888/deploy/platform-security-1.0';
$dry  = in_array('--dry-run', $argv, true);

$manifest = json_decode(file_get_contents($dir . '/MANIFEST.json'), true);
if (! is_array($manifest)) { fwrite(STDERR, "MANIFEST unreadable\n"); exit(1); }

echo ($dry ? "DRY RUN" : "ACTIVATION") . " — platform-security-1.0\n";
echo "artifact generated_at: {$manifest['generated_at']}\n\n";

// ── Precondition: the cookie exemption must still be in place ────────
$bootSha = hash_file('sha256', $root . '/bootstrap/app.php');
$wantBoot = $manifest['preconditions']['bootstrap_app_sha256'];
echo "bootstrap/app.php  live={$bootSha}\n";
echo "                   want={$wantBoot}\n";
if ($bootSha !== $wantBoot) {
    fwrite(STDERR, "REFUSED: bootstrap/app.php changed. The cookie exemption is the precondition for\n");
    fwrite(STDERR, "         this activation; without it every admin request becomes a redirect loop.\n");
    exit(2);
}
echo "                   PASS\n\n";

// ── Verify every file, both directions, before writing anything ──────
$plan = [];
foreach ($manifest['files'] as $f) {
    $rel = $f['source_path'];
    $live = $root . '/' . $rel;
    $staged = $dir . '/staged/' . $rel;
    $rollback = $dir . '/rollback/' . $rel;

    $liveSha = hash_file('sha256', $live);
    $stagedSha = hash_file('sha256', $staged);
    $rollbackSha = hash_file('sha256', $rollback);

    echo "{$rel}\n";
    echo "   live     {$liveSha}\n";
    echo "   expected {$f['current_live_sha256']}\n";

    if ($liveSha === $f['intended_sha256']) {
        fwrite(STDERR, "   ALREADY ACTIVATED — nothing to do for this file.\n");
        exit(3);
    }
    if ($liveSha !== $f['current_live_sha256']) {
        fwrite(STDERR, "REFUSED: {$rel} drifted since artifact generation. Another session may own it now.\n");
        exit(4);
    }
    if ($stagedSha !== $f['intended_sha256'] || $stagedSha !== $f['artifact_sha256']) {
        fwrite(STDERR, "REFUSED: staged artifact for {$rel} does not match its recorded hash.\n");
        exit(5);
    }
    if ($rollbackSha !== $f['rollback_sha256'] || $rollbackSha !== $liveSha) {
        fwrite(STDERR, "REFUSED: rollback copy for {$rel} does not match the live file. Rollback would not restore.\n");
        exit(6);
    }

    $lint = trim(shell_exec('php -l ' . escapeshellarg($staged) . ' 2>&1'));
    if (! str_contains($lint, 'No syntax errors')) {
        fwrite(STDERR, "REFUSED: staged {$rel} fails lint: {$lint}\n");
        exit(7);
    }

    echo "   staged   {$stagedSha}  (syntax PASS)\n";
    echo "   rollback {$rollbackSha}  VERIFIED RESTORES LIVE\n";
    $plan[] = [$rel, $live, $staged, $f['intended_sha256']];
}

if ($dry) {
    echo "\nDRY RUN COMPLETE — every precondition passed. Nothing was written.\n";
    exit(0);
}

// ── Write. Both, now, with no work in between. ───────────────────────
echo "\nWRITING\n";
$written = [];
foreach ($plan as [$rel, $live, $staged, $intended]) {
    if (copy($staged, $live) !== true) {
        fwrite(STDERR, "WRITE FAILED on {$rel} — rolling back what was already written.\n");
        foreach ($written as [$wRel, $wLive]) {
            copy($dir . '/rollback/' . $wRel, $wLive);
            echo "   reverted {$wRel}\n";
        }
        exit(8);
    }
    $written[] = [$rel, $live];
    echo "   wrote {$rel}\n";
}

// ── Confirm what actually landed ─────────────────────────────────────
echo "\nPOST-WRITE VERIFICATION\n";
$bad = false;
foreach ($plan as [$rel, $live, $staged, $intended]) {
    $got = hash_file('sha256', $live);
    $ok = $got === $intended;
    echo "   {$rel}  " . ($ok ? 'MATCHES INTENDED' : "MISMATCH got={$got}") . "\n";
    if (! $ok) { $bad = true; }
}

if ($bad) {
    fwrite(STDERR, "\nPOST-WRITE MISMATCH — reverting.\n");
    foreach ($plan as [$rel, $live]) { copy($dir . '/rollback/' . $rel, $live); }
    exit(9);
}

echo "\nACTIVATED. Every administrator must sign in again.\n";
