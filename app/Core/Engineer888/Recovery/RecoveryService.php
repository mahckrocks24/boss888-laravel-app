<?php

namespace App\Core\Engineer888\Recovery;

use Illuminate\Support\Facades\DB;

/**
 * Put the repository back exactly as it was, or refuse and say why.
 *
 * The refusal matters more than the restore. Between IMPLEMENT writing and
 * VERIFY failing, another engineer may have edited the same file — this
 * repository has three sessions working in it simultaneously — and a rollback
 * that overwrites their work in the name of tidiness is a worse outcome than
 * the unverified file it was cleaning up.
 *
 * So every entry is checked against what is on disk right now. If anything
 * differs from what this workflow wrote, that file is left alone and the whole
 * recovery is reported BLOCKED with manual instructions.
 */
final class RecoveryService
{
    public const RECOVERED = 'FAILED_RECOVERED';
    public const INCOMPLETE = 'FAILED_RECOVERY_INCOMPLETE';
    public const BLOCKED = 'FAILED_RECOVERY_BLOCKED';

    public function __construct(private readonly string $repoPath) {}

    /**
     * Restore every file this workflow wrote.
     *
     * @param array<int,array<string,mixed>> $decisions SafeInstaller decisions
     * @return array<string,mixed>
     */
    public function restore(RecoveryManifest $manifest, array $decisions, string $actor = 'engineer888'): array
    {
        $written = $this->writtenPaths($decisions);
        $actions = [];
        $conflicts = [];

        // Nothing is touched until every entry has been inspected. A partial
        // restore that stops halfway leaves a state neither this workflow nor
        // the engineer can describe.
        foreach ($manifest->entries as $entry) {
            $path = (string) $entry['path'];

            if (! in_array($path, $written, true)) {
                $actions[] = ['path' => $path, 'action' => 'SKIP',
                              'detail' => 'this workflow never wrote it'];
                continue;
            }

            $absolute = $this->absolute($path);
            $conflict = $this->conflictFor($entry, $absolute);

            if ($conflict !== null) {
                $conflicts[] = ['path' => $path, 'reason' => $conflict,
                                'current_hash' => is_file($absolute) ? hash_file('sha256', $absolute) : null,
                                'expected_hash' => $entry['intended_hash']];
                continue;
            }

            $actions[] = ['path' => $path, 'action' => $entry['action'] === RecoveryManifest::CREATE
                ? 'REMOVE' : 'RESTORE', 'detail' => 'planned'];
        }

        if ($conflicts !== []) {
            return $this->result(self::BLOCKED, $manifest, [], $conflicts,
                'the repository moved under this workflow; nothing was restored', $actor);
        }

        // Second pass: act.
        $performed = [];
        $failures = [];

        foreach ($manifest->entries as $entry) {
            $path = (string) $entry['path'];
            if (! in_array($path, $written, true)) { continue; }

            $absolute = $this->absolute($path);

            try {
                if ($entry['action'] === RecoveryManifest::CREATE) {
                    if (is_file($absolute) && ! @unlink($absolute)) {
                        throw new \RuntimeException('could not remove the created file');
                    }
                    if (is_file($absolute)) { throw new \RuntimeException('the file is still present after removal'); }

                    $performed[] = ['path' => $path, 'action' => 'REMOVED',
                                    'verified' => true, 'hash_after' => null];
                    continue;
                }

                $backup = $this->absolute((string) $entry['backup_path']);
                if (! is_file($backup)) { throw new \RuntimeException('the backup is missing'); }

                $bytes = @file_get_contents($backup);
                if ($bytes === false) { throw new \RuntimeException('the backup could not be read'); }

                if (@file_put_contents($absolute, $bytes) === false) {
                    throw new \RuntimeException('the restore write failed');
                }

                // A restore is only a restore if the bytes match what was there.
                $after = hash_file('sha256', $absolute);
                if ($after !== $entry['pre_hash']) {
                    throw new \RuntimeException(
                        'restored content does not match the pre-write hash (' . substr($after, 0, 12)
                        . ' vs ' . substr((string) $entry['pre_hash'], 0, 12) . ')'
                    );
                }

                $performed[] = ['path' => $path, 'action' => 'RESTORED',
                                'verified' => true, 'hash_after' => $after];
            } catch (\Throwable $e) {
                $failures[] = ['path' => $path, 'error' => $e->getMessage()];
            }
        }

        $status = $failures === [] ? self::RECOVERED : self::INCOMPLETE;

        return $this->result($status, $manifest, $performed, $failures,
            $failures === []
                ? count($performed) . ' file(s) restored to their pre-task state'
                : count($failures) . ' file(s) could not be restored',
            $actor);
    }

    /**
     * Why this entry cannot be safely restored, or null.
     *
     * The test is not "did the file change" — of course it changed, this
     * workflow changed it. The test is "is it still exactly what THIS workflow
     * wrote". Anything else means somebody edited it afterwards.
     */
    private function conflictFor(array $entry, string $absolute): ?string
    {
        $path = (string) $entry['path'];

        if (! is_file($absolute)) {
            return $entry['action'] === RecoveryManifest::CREATE
                ? null   // already gone; nothing to undo
                : "{$path} no longer exists, so restoring would recreate a file somebody deleted";
        }

        $current = hash_file('sha256', $absolute);

        if ($current !== $entry['intended_hash']) {
            return "{$path} has changed since this workflow wrote it — another engineer's work would be "
                 . 'overwritten by a rollback';
        }

        if ($entry['action'] === RecoveryManifest::UPDATE) {
            if ($entry['backup_path'] === null) { return "{$path} has no recorded backup"; }

            $backup = $this->absolute((string) $entry['backup_path']);
            if (! is_file($backup)) { return "{$path}: the backup at {$entry['backup_path']} is missing"; }

            if (hash_file('sha256', $backup) !== $entry['backup_hash']) {
                return "{$path}: the backup itself has changed since it was taken";
            }
        }

        return null;
    }

    /** @return array<int,string> */
    private function writtenPaths(array $decisions): array
    {
        $written = [];
        foreach ($decisions as $decision) {
            if (in_array($decision['state'] ?? '', ['INSTALL', 'UPDATE', 'OVERRIDDEN'], true)) {
                $written[] = (string) $decision['destination'];
            }
        }

        return $written;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->repoPath, '/') . '/' . ltrim($path, '/');
    }

    private function result(string $status, RecoveryManifest $manifest, array $performed, array $problems, string $summary, string $actor): array
    {
        $evidence = [
            'status'      => $status,
            'summary'     => $summary,
            'fingerprint' => $manifest->fingerprint(),
            'performed'   => $performed,
            'problems'    => $problems,
            'actor'       => $actor,
            'at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'manual'      => $status === self::BLOCKED ? $this->manualInstructions($manifest, $problems) : [],
        ];

        DB::table('engineering_recoveries')->insert([
            'task_uuid'      => $manifest->taskUuid,
            'candidate_uuid' => $manifest->candidateUuid,
            'fingerprint'    => $manifest->fingerprint(),
            'status'         => $status,
            'manifest'       => json_encode($manifest->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR),
            'evidence'       => json_encode($evidence, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'actor'          => $actor,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $evidence;
    }

    /**
     * What a human must do, in order, when automatic recovery refuses.
     *
     * @return array<int,string>
     */
    private function manualInstructions(RecoveryManifest $manifest, array $conflicts): array
    {
        $steps = [
            'Automatic recovery refused because the repository changed after this workflow wrote to it. '
            . 'Nothing was restored and no other engineer\'s work was touched.',
        ];

        foreach ($conflicts as $conflict) {
            $entry = null;
            foreach ($manifest->entries as $candidate) {
                if ($candidate['path'] === $conflict['path']) { $entry = $candidate; break; }
            }

            $steps[] = $conflict['path'] . ' — ' . $conflict['reason'];

            if ($entry === null) { continue; }

            if ($entry['action'] === RecoveryManifest::CREATE) {
                $steps[] = '  This workflow created it. If the current content is not wanted, delete '
                         . $entry['path'] . '. If somebody has since built on it, keep it and close the task manually.';
            } else {
                $steps[] = '  Pre-task content is at ' . ($entry['backup_path'] ?? 'NO BACKUP RECORDED')
                         . ' (sha256 ' . substr((string) $entry['pre_hash'], 0, 16) . '…). Compare it with the '
                         . 'current file before restoring anything.';
            }
        }

        $steps[] = 'When the file is in the state you want, mark the task resolved in the Command Center.';

        return $steps;
    }
}
