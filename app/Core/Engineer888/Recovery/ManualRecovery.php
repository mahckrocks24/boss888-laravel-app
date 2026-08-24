<?php

namespace App\Core\Engineer888\Recovery;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recovery a human authorised, executed exactly as authorised.
 *
 * Every guarantee the automatic path has, and one more: the approver saw the
 * bytes. The fingerprint covers the CURRENT state of every file, so an approval
 * granted against a screen that has since gone stale simply stops matching —
 * expiry is a property of the contract rather than a timer somebody has to
 * remember to check.
 *
 * The scope never widens. Files the candidate marked MANUAL_ONLY or NO_ACTION
 * are not touched, whatever the approver typed.
 */
final class ManualRecovery
{
    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const EXPIRED = 'EXPIRED';
    public const REVOKED = 'REVOKED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const EXECUTED = 'EXECUTED';
    public const FAILED = 'FAILED';

    /** Long enough to read a diff, short enough that the tree has not moved. */
    public const TTL_MINUTES = 120;

    public function __construct(private readonly string $repoPath) {}

    /**
     * Build a candidate for a recovery that could not complete automatically.
     */
    public function candidateFor(int $recoveryId): ?RecoveryCandidate
    {
        $row = DB::table('engineering_recoveries')->find($recoveryId);
        if ($row === null) { return null; }

        $manifest = RecoveryManifest::fromArray(json_decode((string) $row->manifest, true) ?: []);
        $evidence = json_decode((string) $row->evidence, true) ?: [];

        // The paths this workflow actually wrote. A blocked recovery restored
        // nothing, so every path in the manifest that the workflow installed is
        // still a candidate for action.
        $written = array_column($evidence['problems'] ?? [], 'path');
        foreach ($evidence['performed'] ?? [] as $performed) {
            if (($performed['action'] ?? '') !== 'SKIP') { $written[] = $performed['path']; }
        }
        if ($written === []) { $written = $manifest->paths(); }

        $ownershipManifest = OwnershipManifest::active($this->repoPath);

        // DETERMINISTIC, not random. The identity has to survive being rebuilt:
        // approval stores one, execution recomputes another, and a fresh uuid
        // each time would make every approval unfindable.
        return RecoveryCandidate::build(
            $this->repoPath,
            'rec-' . $row->id . '-' . substr($manifest->fingerprint(), 0, 12),
            $manifest,
            array_values(array_unique($written)),
            fn (string $p) => $ownershipManifest?->classify($p)['status'] ?? 'UNKNOWN',
        );
    }

    /**
     * Record the candidate so an approval has something stable to bind to.
     */
    public function offer(int $recoveryId, RecoveryCandidate $candidate): object
    {
        // A newer candidate makes every open offer on this recovery stale.
        DB::table('engineering_recovery_approvals')
            ->where('recovery_id', $recoveryId)
            ->whereIn('status', [self::PENDING, self::APPROVED])
            ->update(['status' => self::SUPERSEDED, 'updated_at' => now()]);

        $id = DB::table('engineering_recovery_approvals')->insertGetId([
            'recovery_id'   => $recoveryId,
            'recovery_uuid' => $candidate->uuid,
            'task_uuid'     => $candidate->taskUuid,
            'fingerprint'   => $candidate->fingerprint(),
            'status'        => self::PENDING,
            'candidate'     => json_encode($candidate->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return DB::table('engineering_recovery_approvals')->find($id);
    }

    public function approve(string $recoveryUuid, string $fingerprint, string $statement, int $userId, string $name, ?string $comment = null): object
    {
        $row = $this->approvalRow($recoveryUuid);

        if ($row->status !== self::PENDING) {
            throw new RuntimeException("this recovery is {$row->status}, not pending");
        }

        if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
            throw new RuntimeException(
                'the fingerprint submitted does not match this recovery. The repository moved after the '
                . 'screen was rendered, so the actions shown are no longer the actions that would run.'
            );
        }

        $candidate = RecoveryCandidate::fromArray(json_decode((string) $row->candidate, true) ?: []);

        if (trim($statement) !== $candidate->statement()) {
            throw new RuntimeException('the approval statement does not match this recovery');
        }

        DB::table('engineering_recovery_approvals')->where('id', $row->id)->update([
            'status'           => self::APPROVED,
            'approver_user_id' => $userId,
            'approver_name'    => $name,
            'statement'        => $candidate->statement(),
            'comment'          => $comment,
            'approved_at'      => now(),
            'expires_at'       => now()->addMinutes(self::TTL_MINUTES),
            'updated_at'       => now(),
        ]);

        return DB::table('engineering_recovery_approvals')->find($row->id);
    }

    public function reject(string $recoveryUuid, int $userId, string $name, ?string $why = null): object
    {
        $row = $this->approvalRow($recoveryUuid);

        DB::table('engineering_recovery_approvals')->where('id', $row->id)->update([
            'status' => self::REJECTED, 'approver_user_id' => $userId, 'approver_name' => $name,
            'comment' => $why, 'updated_at' => now(),
        ]);

        return DB::table('engineering_recovery_approvals')->find($row->id);
    }

    public function revoke(string $recoveryUuid, string $name, ?string $why = null): object
    {
        $row = $this->approvalRow($recoveryUuid);

        DB::table('engineering_recovery_approvals')->where('id', $row->id)->update([
            'status' => self::REVOKED, 'approver_name' => $name, 'comment' => $why, 'updated_at' => now(),
        ]);

        return DB::table('engineering_recovery_approvals')->find($row->id);
    }

    /**
     * Execute exactly what was approved, or refuse and say which check failed.
     *
     * @return array<string,mixed>
     */
    public function execute(string $recoveryUuid, string $actor): array
    {
        $row = $this->approvalRow($recoveryUuid);

        if ($row->status !== self::APPROVED) {
            return $this->refuse($row, 'this recovery is ' . strtolower((string) $row->status)
                . ', so nothing may be written');
        }

        if ($row->expires_at !== null && now()->greaterThan($row->expires_at)) {
            DB::table('engineering_recovery_approvals')->where('id', $row->id)
                ->update(['status' => self::EXPIRED, 'updated_at' => now()]);

            return $this->refuse($row, 'the approval expired at ' . $row->expires_at
                . '. A recovery reviewed against an older tree is not a recovery anybody reviewed.');
        }

        $approved = RecoveryCandidate::fromArray(json_decode((string) $row->candidate, true) ?: []);

        // Rebuilt from disk RIGHT NOW. If anything moved since the approval, the
        // fingerprints differ and nothing is written.
        $current = $this->candidateFor((int) $row->recovery_id);

        if ($current === null) {
            return $this->refuse($row, 'the underlying recovery record has gone');
        }

        if (! hash_equals($approved->fingerprint(), $current->fingerprint())) {
            return $this->refuse($row,
                'the repository changed after this recovery was approved, so the approved actions no '
                . 'longer describe what would happen. Re-open the recovery to see the current state.',
                $this->differences($approved, $current));
        }

        $performed = [];
        $failures = [];

        foreach ($approved->files as $file) {
            $path = (string) $file['path'];

            if (! in_array($file['action'], RecoveryCandidate::EXECUTABLE, true)) {
                $performed[] = ['path' => $path, 'action' => $file['action'],
                                'detail' => 'not executable — left untouched', 'verified' => true];
                continue;
            }

            try {
                $performed[] = $this->act($file);
            } catch (\Throwable $e) {
                $failures[] = ['path' => $path, 'error' => $e->getMessage()];
            }
        }

        $status = $failures === [] ? self::EXECUTED : self::FAILED;

        $evidence = [
            'status'      => $status,
            'summary'     => $failures === []
                ? count(array_filter($performed, fn ($p) => $p['action'] !== RecoveryCandidate::NO_ACTION
                        && $p['action'] !== RecoveryCandidate::MANUAL_ONLY)) . ' approved action(s) executed'
                : count($failures) . ' action(s) failed',
            'performed'   => $performed,
            'problems'    => $failures,
            'fingerprint' => $approved->fingerprint(),
            'actor'       => $actor,
            'approved_by' => $row->approver_name,
            'at'          => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        DB::table('engineering_recovery_approvals')->where('id', $row->id)->update([
            'status' => $status, 'executed_at' => now(),
            'evidence' => json_encode($evidence, JSON_PARTIAL_OUTPUT_ON_ERROR), 'updated_at' => now(),
        ]);

        DB::table('engineering_recoveries')->where('id', $row->recovery_id)->update([
            'status'      => $status === self::EXECUTED
                ? RecoveryService::RECOVERED : RecoveryService::INCOMPLETE,
            'approved_by' => $row->approver_name,
            'approved_at' => $row->approved_at,
            'updated_at'  => now(),
        ]);

        return $evidence;
    }

    /** @return array<string,mixed> */
    private function act(array $file): array
    {
        $path = (string) $file['path'];
        $absolute = rtrim($this->repoPath, '/') . '/' . ltrim($path, '/');

        if ($file['action'] === RecoveryCandidate::REMOVE_CREATED_FILE) {
            if (is_file($absolute) && ! @unlink($absolute)) {
                throw new RuntimeException('could not remove the file');
            }
            if (is_file($absolute)) { throw new RuntimeException('the file is still present'); }

            return ['path' => $path, 'action' => 'REMOVED', 'verified' => true, 'hash_after' => null];
        }

        $backup = rtrim($this->repoPath, '/') . '/' . ltrim((string) $file['backup_path'], '/');
        if (! is_file($backup)) { throw new RuntimeException('the backup is missing'); }

        if (hash_file('sha256', $backup) !== $file['backup_hash']) {
            throw new RuntimeException('the backup changed between approval and execution');
        }

        $bytes = (string) file_get_contents($backup);
        if (@file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException('the restore write failed');
        }

        $after = hash_file('sha256', $absolute);
        if ($after !== $file['pre_hash']) {
            throw new RuntimeException('restored content does not match the pre-task hash');
        }

        return ['path' => $path, 'action' => 'RESTORED', 'verified' => true, 'hash_after' => $after];
    }

    /** @return array<int,string> */
    private function differences(RecoveryCandidate $approved, RecoveryCandidate $current): array
    {
        $byPath = [];
        foreach ($current->files as $file) { $byPath[$file['path']] = $file; }

        $differences = [];
        foreach ($approved->files as $file) {
            $now = $byPath[$file['path']] ?? null;
            if ($now === null) { $differences[] = $file['path'] . ' is no longer part of the recovery'; continue; }
            if ($now['current_hash'] !== $file['current_hash']) {
                $differences[] = $file['path'] . ' has changed on disk since approval';
            }
            if ($now['action'] !== $file['action']) {
                $differences[] = $file['path'] . " would now be {$now['action']}, not {$file['action']}";
            }
        }

        return $differences;
    }

    /** @return array<string,mixed> */
    private function refuse(object $row, string $why, array $detail = []): array
    {
        return [
            'status'      => 'REFUSED',
            'summary'     => $why,
            'performed'   => [],
            'problems'    => array_map(fn ($d) => ['reason' => $d], $detail),
            'fingerprint' => $row->fingerprint,
            'at'          => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function approvalRow(string $recoveryUuid): object
    {
        $row = DB::table('engineering_recovery_approvals')
            ->where('recovery_uuid', $recoveryUuid)->orderByDesc('id')->first();

        if ($row === null) { throw new RuntimeException("unknown recovery {$recoveryUuid}"); }

        return $row;
    }
}
