<?php

namespace App\Core\Engineer888\Recovery;

use App\Core\Engineer888\Support\UnifiedDiff;

/**
 * A recovery, described precisely enough for a human to approve it.
 *
 * Sprint 9 could refuse an unsafe rollback and print manual steps. It could not
 * let anybody act on them without a shell, which meant the one situation that
 * most needs supervision — another engineer's work sitting where a rollback
 * wants to write — was the one situation the Command Center could not handle.
 *
 * A candidate is a snapshot of reality at the moment it was built: what is on
 * disk now, what was there before the workflow, what the backup holds, and what
 * would be done to each file. Its fingerprint covers all of that, so an approval
 * cannot outlive the state it was granted against.
 */
final class RecoveryCandidate
{
    public const VERSION = 'e888-recovery-candidate-v1';

    public const REMOVE_CREATED_FILE = 'REMOVE_CREATED_FILE';
    public const RESTORE_UPDATED_FILE = 'RESTORE_UPDATED_FILE';
    public const RESTORE_DELETED_FILE = 'RESTORE_DELETED_FILE';
    public const NO_ACTION = 'NO_ACTION';
    public const MANUAL_ONLY = 'MANUAL_ONLY';

    /** Actions this system will perform on a human's word. */
    public const EXECUTABLE = [
        self::REMOVE_CREATED_FILE, self::RESTORE_UPDATED_FILE, self::RESTORE_DELETED_FILE,
    ];

    public function __construct(
        public readonly string $uuid,
        public readonly string $workflowUuid,
        public readonly string $taskUuid,
        public readonly ?string $failedCandidateUuid,
        public readonly string $approvalFingerprint,
        public readonly string $manifestFingerprint,
        /** @var array<int,array<string,mixed>> */
        public readonly array $files,
        public readonly array $warnings,
        public readonly string $generatedAt,
    ) {}

    /**
     * Build from the manifest the workflow captured and what is on disk now.
     *
     * @param array<int,string> $written paths this workflow actually wrote
     */
    public static function build(
        string $repoPath,
        string $uuid,
        RecoveryManifest $manifest,
        array $written,
        callable $ownership,
    ): self {
        $files = [];
        $warnings = [];

        foreach ($manifest->entries as $entry) {
            $path = (string) $entry['path'];
            $absolute = rtrim($repoPath, '/') . '/' . ltrim($path, '/');
            $exists = is_file($absolute);
            $currentHash = $exists ? hash_file('sha256', $absolute) : null;

            $backupAbsolute = $entry['backup_path'] === null
                ? null
                : rtrim($repoPath, '/') . '/' . ltrim((string) $entry['backup_path'], '/');
            $backupHash = ($backupAbsolute !== null && is_file($backupAbsolute))
                ? hash_file('sha256', $backupAbsolute) : null;

            $ownershipNow = (string) $ownership($path);

            // Drift is measured against what THIS workflow wrote, not against
            // the pre-state. Of course the file changed — the workflow changed
            // it. The question is whether anything changed it since.
            $drift = $exists && $currentHash !== $entry['intended_hash'];

            [$action, $conflict] = self::decide($entry, $path, $exists, $drift, $written,
                $backupHash, $ownershipNow);

            $diff = null;
            if ($action === self::RESTORE_UPDATED_FILE && $backupAbsolute !== null && is_file($backupAbsolute)) {
                $diff = UnifiedDiff::between(
                    $exists ? (string) file_get_contents($absolute) : '',
                    (string) file_get_contents($backupAbsolute)
                );
            }

            if ($entry['backup_hash'] !== null && $backupHash !== null && $backupHash !== $entry['backup_hash']) {
                $warnings[] = "the backup for {$path} has changed since it was taken";
            }
            if ($ownershipNow !== $entry['ownership']) {
                $warnings[] = "ownership of {$path} changed from {$entry['ownership']} to {$ownershipNow}";
            }

            $files[] = [
                'path'             => $path,
                'action'           => $action,
                'conflict'         => $conflict,
                'manifest_action'  => $entry['action'],
                'existed_before'   => (bool) $entry['existed_before'],
                'pre_hash'         => $entry['pre_hash'],
                'intended_hash'    => $entry['intended_hash'],
                'current_exists'   => $exists,
                'current_hash'     => $currentHash,
                'drift'            => $drift,
                'backup_path'      => $entry['backup_path'],
                'backup_hash'      => $backupHash,
                'backup_recorded'  => $entry['backup_hash'],
                'ownership'        => $ownershipNow,
                'governed'         => (bool) $entry['governed'],
                'written'          => in_array($path, $written, true),
                'diff'             => $diff,
            ];
        }

        return new self($uuid, $manifest->workflowUuid, $manifest->taskUuid,
            $manifest->candidateUuid, $manifest->approvalFingerprint, $manifest->fingerprint(),
            $files, array_values(array_unique($warnings)), gmdate('Y-m-d\TH:i:s\Z'));
    }

    /**
     * What should happen to this file, and why it might not be automatic.
     *
     * @return array{0:string,1:?string}
     */
    private static function decide(array $entry, string $path, bool $exists, bool $drift, array $written, ?string $backupHash, string $ownership): array
    {
        if (! in_array($path, $written, true)) {
            return [self::NO_ACTION, null];
        }

        if ($drift) {
            return [self::MANUAL_ONLY,
                'the file has changed since this workflow wrote it — restoring would overwrite '
                . "somebody else's work"];
        }

        if ($entry['action'] === RecoveryManifest::CREATE) {
            return $exists
                ? [self::REMOVE_CREATED_FILE, null]
                : [self::NO_ACTION, null];   // already gone
        }

        if ($entry['backup_path'] === null) {
            return [self::MANUAL_ONLY, 'no backup was recorded, so the prior bytes cannot be produced'];
        }

        if ($backupHash === null) {
            return [self::MANUAL_ONLY, 'the recorded backup is missing from disk'];
        }

        if ($entry['backup_hash'] !== null && $backupHash !== $entry['backup_hash']) {
            return [self::MANUAL_ONLY, 'the backup has changed since it was taken and can no longer be trusted'];
        }

        if (! in_array($ownership, ['OWNED', 'SHARED_DECLARED'], true)) {
            return [self::MANUAL_ONLY, "this sprint can no longer prove it owns {$path} ({$ownership})"];
        }

        return $exists
            ? [self::RESTORE_UPDATED_FILE, null]
            : [self::RESTORE_DELETED_FILE, null];
    }

    /**
     * Identity of this exact recovery.
     *
     * Includes the CURRENT hash of every file, which is what makes an approval
     * expire by itself: if anything moves after the candidate was shown, the
     * fingerprint recomputed at execution differs and the write is refused.
     */
    public function fingerprint(): string
    {
        $parts = [self::VERSION, $this->uuid, $this->workflowUuid, $this->manifestFingerprint];

        $rows = [];
        foreach ($this->files as $file) {
            $rows[] = implode(':', [
                $file['path'],
                $file['action'],
                $file['current_hash'] ?? 'absent',
                $file['pre_hash'] ?? 'absent',
                $file['backup_hash'] ?? 'none',
                $file['conflict'] === null ? 'clear' : 'conflict',
            ]);
        }
        sort($rows);

        return hash('sha256', implode("\n", array_merge($parts, $rows)));
    }

    /** True when at least one file can actually be acted on. */
    public function isExecutable(): bool
    {
        foreach ($this->files as $file) {
            if (in_array($file['action'], self::EXECUTABLE, true)) { return true; }
        }

        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public function conflicts(): array
    {
        return array_values(array_filter($this->files, fn ($f) => $f['conflict'] !== null));
    }

    public function statement(): string
    {
        return "I approve recovery {$this->uuid} with fingerprint {$this->fingerprint()} "
             . 'and the exact actions shown.';
    }

    public function toArray(): array
    {
        return [
            'version'               => self::VERSION,
            'uuid'                  => $this->uuid,
            'workflow_uuid'         => $this->workflowUuid,
            'task_uuid'             => $this->taskUuid,
            'failed_candidate_uuid' => $this->failedCandidateUuid,
            'approval_fingerprint'  => $this->approvalFingerprint,
            'manifest_fingerprint'  => $this->manifestFingerprint,
            'files'                 => $this->files,
            'warnings'              => $this->warnings,
            'generated_at'          => $this->generatedAt,
            'fingerprint'           => $this->fingerprint(),
            'executable'            => $this->isExecutable(),
            'statement'             => $this->statement(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['workflow_uuid'] ?? ''),
            (string) ($data['task_uuid'] ?? ''),
            $data['failed_candidate_uuid'] ?? null,
            (string) ($data['approval_fingerprint'] ?? ''),
            (string) ($data['manifest_fingerprint'] ?? ''),
            (array) ($data['files'] ?? []),
            (array) ($data['warnings'] ?? []),
            (string) ($data['generated_at'] ?? ''),
        );
    }
}
