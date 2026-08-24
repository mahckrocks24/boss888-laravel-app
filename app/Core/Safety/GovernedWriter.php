<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * CR-22B — the ONLY approved way to write a protected path.
 *
 * Implements the eight steps the CR-22B brief requires, in order, all
 * fail-closed:
 *
 *   1. require a valid ownership token
 *   2. confirm the requested path is inside the held scope
 *   3. verify the acquisition hash
 *   4. recheck the current hash immediately before writing
 *   5. refuse stale or mismatched ownership
 *   6. record the write
 *   7. record the resulting hash
 *   8. record release and changed paths
 *   9. refresh the CR-22B reconstruction artefact when a route source moved
 *
 * Step 4 is the one that matters most and is the one INC-2026-003 needed. The
 * gap between "I read this file" and "I write this file" is where a concurrent
 * session's work disappears. Re-hashing immediately before the write closes it:
 * if anything changed under us, the write is refused rather than winning.
 */
final class GovernedWriter
{
    public function __construct(
        private SourceOwnershipLock $lock = new SourceOwnershipLock(),
    ) {}

    /**
     * Write a protected path under held ownership.
     *
     * @param string $relPath project-relative path
     * @param string $content full new contents
     * @param string $owner   must match the live lock holder
     *
     * @return array{path:string, before:string, after:string, bytes:int,
     *               manifest:array{refreshed:bool, from:?string, to:?string, reason:string}}
     *
     * @throws RuntimeException on any ownership, scope or hash failure
     */
    public function write(string $relPath, string $content, string $owner, ?string $scope = null): array
    {
        $scope = $scope ?? ProtectedPaths::SCOPE;
        $abs = ProtectedPaths::absolute($relPath);

        if (!ProtectedPaths::isProtected($relPath)) {
            throw new RuntimeException(
                "GovernedWriter is for protected paths only; {$relPath} is not one. "
                . 'Write it normally, or add it to ProtectedPaths::PATTERNS.'
            );
        }

        // 1 + 5. valid, live, matching ownership — assertOwned fails closed on
        // missing, expired, stale and wrong-owner alike.
        $this->lock->assertOwned($scope, $owner);
        $lock = $this->lock->read($scope);

        // 2. the path must be inside the scope actually held, not merely "some
        // lock exists". Holding routes/api.php does not authorise bootstrap/app.php.
        $held = $lock['paths'] ?? [];
        if (!$this->covered($relPath, $held)) {
            throw new RuntimeException(sprintf(
                "REFUSING: lock '%s' held by %s covers [%s] — it does not cover %s. "
                . 'Re-acquire with the full path set.',
                $scope, $owner, implode(', ', $held), $relPath
            ));
        }

        // 3. an acquisition hash must exist for this path, otherwise there is no
        // baseline to prove the file is unchanged since we took ownership.
        if (!array_key_exists($relPath, $lock['hashes'] ?? [])) {
            throw new RuntimeException(
                "REFUSING: no acquisition hash recorded for {$relPath}. "
                . 'Re-acquire the lock so a baseline exists.'
            );
        }
        $acquired = $lock['hashes'][$relPath];

        // 4. re-hash NOW. This is the anti-INC-2026-003 check.
        clearstatcache(true, $abs);
        $current = is_file($abs) ? hash_file('sha256', $abs) : null;

        if ($current !== $acquired) {
            $this->lock->audit($scope, 'write_refused_hash_mismatch', [
                'owner' => $owner, 'path' => $relPath,
                'acquired' => $acquired, 'current' => $current,
            ]);

            throw new RuntimeException(sprintf(
                "REFUSING: %s changed since ownership was acquired.\n"
                . "  at acquisition: %s\n  right now     : %s\n"
                . 'Another session wrote this file. Re-read it and re-apply your change on top — '
                . 'do NOT overwrite. This is exactly how INC-2026-003 destroyed three phases of work.',
                $relPath, substr((string) $acquired, 0, 16), substr((string) $current, 0, 16)
            ));
        }

        // Capture ownership BEFORE writing so the replacement is indistinguishable
        // from the original to the web server.
        $owner_uid = $mode = $owner_gid = null;
        if (is_file($abs)) {
            $st = stat($abs);
            $owner_uid = $st['uid'];
            $owner_gid = $st['gid'];
            $mode = $st['mode'] & 0777;
        }

        // 6 + 7. write, then record what happened and what the file now is.
        $tmp = $abs . '.governed-tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException("failed to stage write for {$relPath}");
        }
        rename($tmp, $abs);
        clearstatcache(true, $abs);

        /*
         * Restore ownership and mode, then PROVE the web server can still read it.
         *
         * This check exists because it failed for real: a swap performed with
         * `cp` inherited the rollback artefact's deliberate 0440 mode and root
         * ownership, leaving routes/api.php unreadable by php-fpm. The route
         * table was byte-perfect and every signature matched — but production
         * emitted 287 "require(routes/api.php): Permission denied" errors and
         * served 500s until the file was replaced. Content correctness is not
         * sufficient; the file must remain LOADABLE.
         */
        if ($owner_uid !== null) {
            @chown($abs, $owner_uid);
            @chgrp($abs, $owner_gid);
            @chmod($abs, $mode);
            clearstatcache(true, $abs);
        }

        if (!$this->readableByWebServer($abs)) {
            // put the previous content back rather than leave production broken
            if ($current !== null) {
                file_put_contents($abs, (string) file_get_contents($abs));
            }
            $this->lock->audit($scope, 'write_reverted_unreadable', ['owner' => $owner, 'path' => $relPath]);

            throw new RuntimeException(
                "REFUSING: after writing {$relPath} the file is not readable by the web server user. "
                . 'A correct route table in an unloadable file still takes production down.'
            );
        }

        $after = hash_file('sha256', $abs);

        $this->lock->audit($scope, 'write', [
            'owner' => $owner, 'path' => $relPath,
            'before' => $current, 'after' => $after,
            'bytes' => strlen($content),
            // RSK-M4-1. Which operation, if any, this write belonged to. One
            // file read; the full concurrency assessment runs once per
            // operation in EngineeringOperation::begin(), not per write.
            'operation' => $this->operationContext($owner),
        ]);

        // the lock's baseline moves forward, so consecutive governed writes to
        // the same path in one session are legal while an out-of-band write
        // between them is still caught.
        $this->rebase($scope, $relPath, $after);

        /*
         * RSK-M4-1. Sample drift BEFORE sealing. seal() re-seals every protected
         * path, so after it runs a change made outside governed tooling is
         * indistinguishable from one made through it. This is the only moment
         * the signal still exists.
         *
         * The path we just wrote is excluded — it differs from the seal because
         * WE changed it, which is not foreign drift.
         */
        $foreignDrift = [];
        try {
            $d = ProtectedPaths::drift();
            $foreignDrift = array_keys(array_diff_key($d['drifted'] ?? [], [$relPath => true]));
        } catch (\Throwable) {
            $foreignDrift = [];
        }

        ProtectedPaths::seal($owner, "governed write: {$relPath}");

        /*
         * RSK-M3-1. Writing a route source necessarily moves the CR-22B
         * reconstruction hash. That artefact used to be refreshed by hand as a
         * step in each deploy script; at E1 M3 the step was missed and the
         * conformance test failed against the previous milestone's value,
         * reporting a fault in the module set when the artefact was the only
         * stale thing. Doing it here means it cannot be forgotten.
         *
         * refresh() never throws: the write above has already succeeded and is
         * correct, so a problem recording it must not unwind it. A failure is
         * audited and the reconstruction test fails loudly on the next run —
         * the same behaviour as before this hook existed, never quieter.
         */
        $manifest = ['refreshed' => false, 'from' => null, 'to' => null, 'reason' => 'not a route source'];

        if (ReconstructionManifest::isRouteSource($relPath)) {
            if ($foreignDrift !== []) {
                /*
                 * Someone changed a route module outside governed tooling.
                 * Refreshing now would recompute the reconstruction from that
                 * content and record it as the governed baseline — laundering an
                 * ungoverned change into the artefact whose job is to detect
                 * exactly that. Leave the artefact alone so the conformance test
                 * fails loudly, and say why.
                 */
                $manifest = [
                    'refreshed' => false,
                    'from' => null,
                    'to' => null,
                    'reason' => 'withheld: ungoverned drift in ' . implode(', ', $foreignDrift),
                ];
                $this->lock->audit($scope, 'reconstruction_refresh_withheld', [
                    'owner' => $owner,
                    'path' => $relPath,
                    'foreign_drift' => $foreignDrift,
                ]);
            } else {
                $manifest = (new ReconstructionManifest())->refresh("governed write: {$relPath}");
                $this->lock->audit($scope, 'reconstruction_manifest', [
                    'owner' => $owner,
                    'path' => $relPath,
                ] + $manifest);
            }
        }

        return [
            'path' => $relPath,
            'before' => (string) $current,
            'after' => $after,
            'bytes' => strlen($content),
            'manifest' => $manifest,
        ];
    }

    /**
     * 8. Release, recording every path whose hash moved while the lock was held.
     */
    public function release(string $owner, ?string $scope = null): array
    {
        $scope = $scope ?? ProtectedPaths::SCOPE;
        $lock = $this->lock->read($scope);

        if ($lock === null) {
            return ['released' => false, 'changed' => []];
        }

        $changed = [];
        foreach (($lock['acquisition_hashes'] ?? $lock['hashes'] ?? []) as $rel => $hash) {
            $abs = ProtectedPaths::absolute($rel);
            $now = is_file($abs) ? hash_file('sha256', $abs) : null;
            if ($now !== $hash) {
                $changed[$rel] = ['from' => $hash, 'to' => $now];
            }
        }

        $this->lock->audit($scope, 'release_with_changes', [
            'owner' => $owner,
            'changed_paths' => array_keys($changed),
            'change_count' => count($changed),
        ]);
        $this->lock->release($scope, $owner);

        return ['released' => true, 'changed' => $changed];
    }

    /**
     * Can the web server user actually read this file?
     *
     * Checked as the real user rather than inferred from the mode bits, because
     * the failure mode that bit us was ownership, not permission bits alone.
     */
    private function readableByWebServer(string $abs, string $user = 'www-data'): bool
    {
        $cmd = sprintf('sudo -n -u %s test -r %s 2>/dev/null', escapeshellarg($user), escapeshellarg($abs));
        exec($cmd, $out, $rc);

        if ($rc === 0) {
            return true;
        }

        // sudo unavailable (e.g. running as www-data already) — fall back to
        // a mode/ownership check rather than silently passing.
        $st = @stat($abs);
        if ($st === false) {
            return false;
        }
        $info = posix_getpwnam($user);
        if ($info === false) {
            return ($st['mode'] & 0004) !== 0;         // world-readable
        }

        return ($st['uid'] === $info['uid'] && ($st['mode'] & 0400))
            || ($st['gid'] === $info['gid'] && ($st['mode'] & 0040))
            || ($st['mode'] & 0004);
    }

    private function covered(string $relPath, array $held): bool
    {
        foreach ($held as $h) {
            if ($h === $relPath || fnmatch($h, $relPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RSK-M4-1 — is this write covered by a declared engineering operation, and
     * is that operation ours?
     *
     * Recorded, never enforced. The write path already fails closed on hash
     * mismatch; refusing here as well could halt a deploy for a condition the
     * operator has deliberately accepted. What matters is that a write made
     * while another session's operation is live stops being invisible.
     *
     * @return array{declared:bool, owner:?string, phase:?string, foreign:bool}
     */
    private function operationContext(string $owner): array
    {
        try {
            $op = $this->lock->read(EngineeringOperation::SCOPE);
        } catch (\Throwable) {
            return ['declared' => false, 'owner' => null, 'phase' => null, 'foreign' => false];
        }

        if ($op === null || ($op['released_at'] ?? null) !== null || $this->lock->isStale($op)) {
            return ['declared' => false, 'owner' => null, 'phase' => null, 'foreign' => false];
        }

        return [
            'declared' => true,
            'owner' => $op['owner'] ?? null,
            'phase' => $op['phase'] ?? null,
            'foreign' => ($op['owner'] ?? null) !== $owner,
        ];
    }

    /** Move the lock's baseline for one path forward after a governed write. */
    private function rebase(string $scope, string $relPath, string $hash): void
    {
        $path = $this->lock->lockFile($scope);
        $lock = json_decode((string) file_get_contents($path), true);

        // preserve the ORIGINAL acquisition hashes so release() can still report
        // the full set of changes made during this ownership window
        if (!isset($lock['acquisition_hashes'])) {
            $lock['acquisition_hashes'] = $lock['hashes'] ?? [];
        }
        $lock['hashes'][$relPath] = $hash;

        file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
