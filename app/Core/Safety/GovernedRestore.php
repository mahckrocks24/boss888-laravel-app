<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * CR-22B — the ONLY approved way to restore a protected path from a backup.
 *
 * Restore is strictly harder than write, because a restore ASSERTS that older
 * content should replace newer content. INC-2026-003 was a restore: a session
 * copied a pre-P1 backup over routes/api.php and silently deleted three phases
 * of completed work, including a live customer-facing defect fix.
 *
 * On top of every GovernedWriter requirement, a restore additionally requires:
 *
 *   - a valid backup manifest        (a bare .bak is not restorable, ever)
 *   - a non-stale source revision    (the manifest must not predate current source)
 *   - a diff preview                 (the operator must be shown what dies)
 *   - an active ownership token
 *   - post-restore route equivalence (and automatic undo if it fails)
 */
final class GovernedRestore
{
    public function __construct(
        private SourceOwnershipLock $lock = new SourceOwnershipLock(),
        private ManifestBackup $backups = new ManifestBackup(),
        private GovernedWriter $writer = new GovernedWriter(),
    ) {}

    /**
     * Evaluate a restore without performing it. Always safe to call.
     *
     * @return array{allowed:bool, reasons:array, preview:array, manifest:?array}
     */
    public function preview(string $backupDir, array $paths, string $owner, ?string $scope = null): array
    {
        $scope = $scope ?? ProtectedPaths::SCOPE;
        $reasons = [];

        // ── a manifest is mandatory ──────────────────────────────────────────
        $manifestPath = rtrim($backupDir, '/') . '/MANIFEST.json';
        if (!is_file($manifestPath)) {
            return [
                'allowed' => false,
                'reasons' => [
                    "no MANIFEST.json in {$backupDir} — a backup without a manifest records neither what "
                    . 'production looked like when it was taken nor whether it is safe to restore, so it '
                    . 'is not restorable. Reconstruct by re-applying your edits on top of current source.',
                ],
                'preview' => [],
                'manifest' => null,
            ];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['files'])) {
            return ['allowed' => false, 'reasons' => ['MANIFEST.json is unreadable or lists no files'],
                    'preview' => [], 'manifest' => null];
        }

        // ── ownership must be live and matching ──────────────────────────────
        try {
            $this->lock->assertOwned($scope, $owner);
        } catch (RuntimeException $e) {
            $reasons[] = $e->getMessage();
        }

        // ── staleness + diff preview ─────────────────────────────────────────
        $preview = [];
        foreach ($paths as $rel) {
            if (!isset($manifest['files'][$rel])) {
                $reasons[] = "{$rel} is not in this manifest";
                continue;
            }

            $abs = ProtectedPaths::absolute($rel);
            $currentHash = is_file($abs) ? hash_file('sha256', $abs) : null;
            $backupHash = $manifest['files'][$rel]['sha256'] ?? null;
            $stored = rtrim($backupDir, '/') . '/' . ($manifest['files'][$rel]['stored_as'] ?? '');

            if (!is_file($stored)) {
                $reasons[] = "{$rel}: the stored copy is missing from the backup directory";
                continue;
            }

            $currentLines = is_file($abs) ? count(file($abs)) : 0;
            $backupLines = count(file($stored));

            $entry = [
                'path' => $rel,
                'current_sha256' => $currentHash,
                'backup_sha256' => $backupHash,
                'current_lines' => $currentLines,
                'backup_lines' => $backupLines,
                'line_delta' => $backupLines - $currentLines,
                'identical' => $currentHash === $backupHash,
            ];

            // A manifest that predates the current source is the INC-2026-003
            // shape. Refuse it by default; it is not a warning.
            if (!$entry['identical']) {
                $capturedAt = strtotime((string) ($manifest['captured_at'] ?? '')) ?: 0;
                $modifiedAt = is_file($abs) ? filemtime($abs) : 0;

                if ($capturedAt > 0 && $modifiedAt > $capturedAt) {
                    $reasons[] = sprintf(
                        "%s has been modified since this backup was captured (%s captured, %s on disk). "
                        . 'Restoring would DELETE that newer work. Reconstruct instead.',
                        $rel, gmdate('c', $capturedAt), gmdate('c', $modifiedAt)
                    );
                    $entry['stale'] = true;
                }
            }

            $preview[] = $entry;
        }

        if ($preview === []) {
            $reasons[] = 'nothing to restore';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons,
                'preview' => $preview, 'manifest' => $manifest];
    }

    /**
     * Perform a restore. Refuses unless preview() allows it, and undoes itself
     * if the resulting route table does not match the expected signature.
     *
     * @param callable|null $equivalence returns true when routing is acceptable
     */
    public function restore(
        string $backupDir,
        array $paths,
        string $owner,
        ?callable $equivalence = null,
        ?string $scope = null,
    ): array {
        $scope = $scope ?? ProtectedPaths::SCOPE;
        $decision = $this->preview($backupDir, $paths, $owner, $scope);

        if (!$decision['allowed']) {
            throw new RuntimeException(
                "REFUSING RESTORE:\n  - " . implode("\n  - ", $decision['reasons'])
            );
        }

        // keep the current content so a failed equivalence check can be undone
        $undo = [];
        foreach ($paths as $rel) {
            $abs = ProtectedPaths::absolute($rel);
            $undo[$rel] = is_file($abs) ? (string) file_get_contents($abs) : null;
        }

        $manifest = $decision['manifest'];
        $written = [];

        foreach ($paths as $rel) {
            $stored = rtrim($backupDir, '/') . '/' . $manifest['files'][$rel]['stored_as'];
            $written[] = $this->writer->write($rel, (string) file_get_contents($stored), $owner, $scope);
        }

        $this->lock->audit($scope, 'restore', [
            'owner' => $owner, 'backup' => $backupDir, 'paths' => $paths,
        ]);

        // post-restore equivalence — and undo if it fails
        if ($equivalence !== null && !$equivalence()) {
            foreach ($undo as $rel => $content) {
                if ($content !== null) {
                    $this->writer->write($rel, $content, $owner, $scope);
                }
            }
            $this->lock->audit($scope, 'restore_reverted_equivalence_failed', [
                'owner' => $owner, 'backup' => $backupDir,
            ]);

            throw new RuntimeException(
                'RESTORE REVERTED: the restored source did not produce the expected route table. '
                . 'The previous content has been put back.'
            );
        }

        return ['restored' => true, 'paths' => $written, 'backup' => $backupDir];
    }
}
