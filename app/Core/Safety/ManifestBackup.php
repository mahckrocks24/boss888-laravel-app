<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * CR-22 — authoritative, manifest-backed source backups.
 *
 * WHY THIS EXISTS (INC-2026-003)
 * Phase P *did* take a backup before writing. That is not the problem. The
 * problem is that it restored `api.php.bak-20260727-phaseP2` — a snapshot of a
 * file that no longer reflected production — and nothing checked whether the
 * snapshot was still current. Three phases of fixes were reverted silently.
 *
 * > A backup taken at the wrong moment is not a safety net. It is a loaded weapon.
 *
 * The rule this class enforces: **a backup without a manifest is not
 * restorable**, and a manifest that predates the current source is refused.
 */
final class ManifestBackup
{
    public const ROOT = '/root/route-backups';

    public function __construct(private string $root = self::ROOT) {}

    /**
     * Capture a snapshot with a manifest proving what was captured.
     *
     * @param string[] $paths absolute paths
     */
    public function capture(array $paths, string $label, string $owner, string $phase, array $extra = []): array
    {
        $stamp = gmdate('Ymd-His');
        $dir   = "{$this->root}/{$label}-{$stamp}";
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $manifest = [
            'label'        => $label,
            'owner'        => $owner,
            'phase'        => $phase,
            'captured_at'  => gmdate('c'),
            'git_revision' => trim((string) @shell_exec('cd /var/www/levelup-staging && git rev-parse HEAD 2>/dev/null')),
            'files'        => [],
            'route_count'  => $extra['route_count'] ?? null,
            'route_signature_sha256' => $extra['route_signature_sha256'] ?? null,
            'phase_markers'=> $extra['phase_markers'] ?? [],
            'notes'        => $extra['notes'] ?? null,
        ];

        foreach ($paths as $p) {
            if (!is_file($p)) {
                throw new RuntimeException("cannot capture missing file: {$p}");
            }
            $dest = $dir . '/' . str_replace('/', '__', ltrim($p, '/'));
            copy($p, $dest);
            $manifest['files'][$p] = [
                'sha256'   => hash_file('sha256', $p),
                'bytes'    => filesize($p),
                'stored_as'=> basename($dest),
            ];
        }

        file_put_contents("{$dir}/MANIFEST.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return ['dir' => $dir, 'manifest' => $manifest];
    }

    /**
     * Decide whether a snapshot may be restored over the CURRENT source.
     *
     * Fails closed. Returns a decision rather than throwing, so a caller can
     * show the operator a diff preview before anything is written.
     *
     * @return array{allowed:bool, reasons:array, stale_files:array, changed_since:array}
     */
    public function evaluateRestore(string $dir, array $onlyPaths = []): array
    {
        $mf = "{$dir}/MANIFEST.json";
        if (!is_file($mf)) {
            return ['allowed' => false, 'reasons' => [
                'NO_MANIFEST: this snapshot has no manifest and is therefore not authoritative. '
                . 'This is exactly what INC-2026-003 restored from.',
            ], 'stale_files' => [], 'changed_since' => []];
        }

        $manifest = json_decode((string) file_get_contents($mf), true);
        $reasons = $stale = $changed = [];

        foreach (($manifest['files'] ?? []) as $path => $meta) {
            if ($onlyPaths && !in_array($path, $onlyPaths, true)) {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $now = hash_file('sha256', $path);
            if ($now !== $meta['sha256']) {
                // The live file has moved on since this snapshot was taken.
                $changed[] = $path;
            }
        }

        if ($changed) {
            $stale = $changed;
            $reasons[] = 'SOURCE_HAS_ADVANCED: ' . implode(', ', $changed)
                . ' changed after this snapshot was captured (' . ($manifest['captured_at'] ?? '?') . '). '
                . 'Restoring would revert work done since. Reconstruct by re-applying edits on top of '
                . 'current source instead — that is how INC-2026-003 was repaired.';
        }

        // A restore that would touch files the caller did not ask for is refused.
        if ($onlyPaths) {
            $extra = array_diff(array_keys($manifest['files'] ?? []), $onlyPaths);
            if ($extra) {
                $reasons[] = 'WOULD_TOUCH_UNRELATED: ' . implode(', ', $extra)
                    . ' — restore module-by-module, never the whole snapshot.';
            }
        }

        return [
            'allowed'       => $reasons === [],
            'reasons'       => $reasons,
            'stale_files'   => $stale,
            'changed_since' => $changed,
            'manifest'      => $manifest,
        ];
    }

    /**
     * Restore specific paths, refusing unless evaluateRestore() allows it.
     * `$force` is the governed emergency override and is always audited.
     */
    public function restore(string $dir, array $paths, string $owner, bool $force = false): array
    {
        $eval = $this->evaluateRestore($dir, $paths);

        if (!$eval['allowed'] && !$force) {
            throw new RuntimeException("RESTORE REFUSED:\n  - " . implode("\n  - ", $eval['reasons']));
        }

        $restored = [];
        $manifest = $eval['manifest'] ?? [];
        foreach ($paths as $p) {
            $meta = $manifest['files'][$p] ?? null;
            if (!$meta) {
                throw new RuntimeException("snapshot does not contain {$p}");
            }
            $src = "{$dir}/" . $meta['stored_as'];
            if (!is_file($src)) {
                throw new RuntimeException("snapshot file missing: {$src}");
            }
            copy($src, $p);
            $restored[$p] = hash_file('sha256', $p);
        }

        @file_put_contents($this->root . '/restore-audit.log', json_encode([
            'at' => gmdate('c'), 'dir' => $dir, 'owner' => $owner, 'forced' => $force,
            'paths' => $paths, 'reasons_overridden' => $force ? $eval['reasons'] : [],
        ]) . "\n", FILE_APPEND | LOCK_EX);

        return ['restored' => $restored, 'forced' => $force];
    }

    /** @return array<int,array> snapshots newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->root . '/*/MANIFEST.json') as $mf) {
            $d = json_decode((string) file_get_contents($mf), true);
            $d['dir'] = dirname($mf);
            $out[] = $d;
        }
        usort($out, fn ($a, $b) => strcmp($b['captured_at'] ?? '', $a['captured_at'] ?? ''));

        return $out;
    }
}
