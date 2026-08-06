<?php

namespace App\Core\Engineer888\Recovery;

use RuntimeException;

/**
 * Exactly what the repository looked like before a workflow touched it.
 *
 * Written BEFORE the first byte is installed, because after the write the
 * pre-state is gone and any reconstruction is a guess. If a pre-state or a
 * backup cannot be proved for even one file, IMPLEMENT refuses — the whole
 * point is that a failed verification leaves nothing behind, and a partial
 * manifest cannot promise that.
 *
 * Sprint 8 ended with an approved file installed and its task failed, sitting
 * in the running application unverified. This is the record that makes undoing
 * it deterministic rather than a judgement call at three in the morning.
 */
final class RecoveryManifest
{
    public const VERSION = 'e888-recovery-v1';

    public const CREATE = 'CREATE';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';

    /** @param array<int,array<string,mixed>> $entries */
    public function __construct(
        public readonly string $workflowUuid,
        public readonly string $taskUuid,
        public readonly string $candidateUuid,
        public readonly string $approvalFingerprint,
        public readonly array $entries,
        public readonly string $capturedAt,
    ) {}

    /**
     * Capture the pre-write state of every destination.
     *
     * @param array<string,string> $changeSet destination => intended content
     * @throws RuntimeException when a pre-state cannot be established
     */
    public static function capture(
        string $repoPath,
        string $workflowUuid,
        string $taskUuid,
        string $candidateUuid,
        string $approvalFingerprint,
        array $changeSet,
        callable $ownership,
        callable $governed,
        string $backupDir,
    ): self {
        $entries = [];

        // The backup is taken HERE, before anything is written, and verified by
        // hash. SafeInstaller takes its own afterwards, which is a fine record
        // and a poor guarantee: by the time it exists the original is already
        // gone, so nothing could have refused on its absence.
        $absoluteBackupDir = str_starts_with($backupDir, '/')
            ? $backupDir : rtrim($repoPath, '/') . '/' . $backupDir;

        if (! is_dir($absoluteBackupDir) && ! @mkdir($absoluteBackupDir, 0775, true) && ! is_dir($absoluteBackupDir)) {
            throw new RuntimeException("cannot create the recovery backup directory {$backupDir}");
        }

        foreach ($changeSet as $destination => $content) {
            $absolute = rtrim($repoPath, '/') . '/' . ltrim($destination, '/');
            $exists = is_file($absolute);

            if ($exists && ! is_readable($absolute)) {
                // Cannot hash it, so cannot prove it was restored. Refusing here
                // is the difference between a rollback and a hope.
                throw new RuntimeException(
                    "cannot read the current {$destination}, so its pre-write state cannot be proved"
                );
            }

            $preHash = $exists ? hash_file('sha256', $absolute) : null;
            $backupRelative = null;
            $backupHash = null;

            if ($exists) {
                $name = str_replace('/', '__', ltrim($destination, '/')) . '.pre-' . substr((string) $preHash, 0, 12);
                $backupAbsolute = $absoluteBackupDir . '/' . $name;

                if (! @copy($absolute, $backupAbsolute)) {
                    throw new RuntimeException("cannot back up {$destination} before writing it");
                }

                $backupHash = hash_file('sha256', $backupAbsolute);

                if ($backupHash !== $preHash) {
                    throw new RuntimeException("the backup of {$destination} does not match the file it copied");
                }

                $backupRelative = rtrim($backupDir, '/') . '/' . $name;
            }

            $entries[] = [
                'path'             => $destination,
                'action'           => $exists ? self::UPDATE : self::CREATE,
                'existed_before'   => $exists,
                'pre_hash'         => $preHash,
                'pre_bytes'        => $exists ? filesize($absolute) : 0,
                'intended_hash'    => hash('sha256', $content),
                'intended_bytes'   => strlen($content),
                'backup_path'      => $backupRelative,
                'backup_hash'      => $backupHash,
                'ownership'        => (string) $ownership($destination),
                'governed'         => (bool) $governed($destination),
            ];
        }

        return new self($workflowUuid, $taskUuid, $candidateUuid, $approvalFingerprint,
            $entries, gmdate('Y-m-d\TH:i:s\Z'));
    }

    /**
     * Attach the backup SafeInstaller took, and prove it matches the pre-state.
     *
     * A backup whose hash differs from the pre-write hash is not a backup of
     * this file, and restoring it would write bytes nobody has ever seen.
     */
    public function withBackup(string $path, ?string $backupPath, string $repoPath): self
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            if ($entry['path'] !== $path) { $entries[] = $entry; continue; }

            if ($backupPath !== null) {
                $absolute = str_starts_with($backupPath, '/')
                    ? $backupPath
                    : rtrim($repoPath, '/') . '/' . $backupPath;

                if (! is_file($absolute)) {
                    throw new RuntimeException("the recorded backup for {$path} does not exist at {$backupPath}");
                }

                $hash = hash_file('sha256', $absolute);

                if ($entry['pre_hash'] !== null && $hash !== $entry['pre_hash']) {
                    throw new RuntimeException(
                        "the backup for {$path} does not match the file that was there before the write"
                    );
                }

                $entry['backup_path'] = $backupPath;
                $entry['backup_hash'] = $hash;
            }

            $entries[] = $entry;
        }

        return new self($this->workflowUuid, $this->taskUuid, $this->candidateUuid,
            $this->approvalFingerprint, $entries, $this->capturedAt);
    }

    /**
     * Is this manifest good enough to promise a clean undo?
     *
     * @return array<int,string> reasons it is not; empty means it is
     */
    public function deficiencies(): array
    {
        $problems = [];

        if ($this->entries === []) { $problems[] = 'the manifest covers no files'; }

        foreach ($this->entries as $entry) {
            if ($entry['action'] === self::UPDATE) {
                if ($entry['pre_hash'] === null) {
                    $problems[] = $entry['path'] . ': an update with no pre-write hash cannot be restored';
                }
                if ($entry['backup_path'] === null) {
                    $problems[] = $entry['path'] . ': an update with no verified backup cannot be restored';
                }
            }
            if ($entry['action'] === self::CREATE && $entry['existed_before']) {
                $problems[] = $entry['path'] . ': recorded as a creation but the file already existed';
            }
        }

        return $problems;
    }

    /** Identity of this recovery plan, for a human to approve. */
    public function fingerprint(): string
    {
        $parts = [self::VERSION, $this->taskUuid, $this->candidateUuid, $this->approvalFingerprint];

        foreach ($this->entries as $entry) {
            $parts[] = implode(':', [
                $entry['path'], $entry['action'],
                $entry['pre_hash'] ?? 'absent',
                $entry['backup_hash'] ?? 'none',
                $entry['intended_hash'],
            ]);
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /** @return array<int,string> */
    public function paths(): array
    {
        return array_column($this->entries, 'path');
    }

    public function toArray(): array
    {
        return [
            'version'              => self::VERSION,
            'workflow_uuid'        => $this->workflowUuid,
            'task_uuid'            => $this->taskUuid,
            'candidate_uuid'       => $this->candidateUuid,
            'approval_fingerprint' => $this->approvalFingerprint,
            'captured_at'          => $this->capturedAt,
            'entries'              => $this->entries,
            'fingerprint'          => $this->fingerprint(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['workflow_uuid'] ?? ''),
            (string) ($data['task_uuid'] ?? ''),
            (string) ($data['candidate_uuid'] ?? ''),
            (string) ($data['approval_fingerprint'] ?? ''),
            (array) ($data['entries'] ?? []),
            (string) ($data['captured_at'] ?? ''),
        );
    }
}
