<?php

namespace App\Core\Engineer888\Recovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The recovery manifest, somewhere a crash cannot reach.
 *
 * THE GAP THIS CLOSES. `RecoveryManifest::capture()` has always done the hard
 * part — hashing every destination and taking a verified backup before the first
 * byte. But the object it produced lived in a `WorkflowContext`, a plain
 * in-memory array, and `WorkflowEngine::recover()` read it from there. Every
 * ordinary failure was covered, because the same process handled the halt. The
 * one case that was not covered is the one that matters: the process ending
 * outright. A SIGKILL, an OOM kill, the queue worker's own timeout signal, a
 * deploy restart — any of them between the install and the halt handler took the
 * only record of what had just been written, leaving files changed and nothing
 * anywhere able to say what they had been.
 *
 * PERSISTED BEFORE THE WRITE, NEVER AFTER. Evidence written after the fact
 * describes a repository that has already moved. If this cannot be stored,
 * IMPLEMENT refuses and nothing is written — a rollback that cannot be recorded
 * is not a rollback anybody can perform later.
 *
 * IT DOES NOT REPLAY. Making recovery possible after process death and
 * performing it automatically are different problems; automatic replay of a
 * half-finished install belongs to a later phase and a human decision. This
 * stores the evidence, tracks which state it is in, and gets out of the way.
 */
final class DurableRecovery
{
    /** Written, verified, and nothing has touched the repository yet. */
    public const CAPTURED = 'CAPTURED';

    /** The installer has run. Files may have changed; recovery is live. */
    public const WRITTEN = 'WRITTEN';

    /** Verified with no rollback required. The evidence is history. */
    public const COMPLETE = 'COMPLETE';

    public const RECOVERED = 'RECOVERED';
    public const RECOVERY_BLOCKED = 'RECOVERY_BLOCKED';

    /**
     * Store the manifest, and prove it is readable back, before anything is written.
     *
     * @throws RuntimeException when the evidence cannot be made durable
     */
    public static function persist(
        RecoveryManifest $manifest,
        ?string $attemptUuid,
        string $repositoryPath,
    ): string {
        $uuid = (string) Str::uuid();
        $encoded = json_encode($manifest->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('the recovery manifest could not be encoded for storage');
        }

        DB::table('engineering_recovery_manifests')->insert([
            'uuid'                 => $uuid,
            'attempt_uuid'         => $attemptUuid,
            'task_uuid'            => $manifest->taskUuid,
            'candidate_uuid'       => $manifest->candidateUuid,
            'repository_path'      => $repositoryPath,
            'approval_fingerprint' => $manifest->approvalFingerprint,
            'manifest_fingerprint' => $manifest->fingerprint(),
            'manifest'             => $encoded,
            'state'                => self::CAPTURED,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // READ IT BACK. An insert that reported success and a row that can be
        // reconstructed are not the same claim, and this is the one place where
        // believing the first would cost a repository.
        $stored = self::find($uuid);

        if ($stored === null) {
            throw new RuntimeException('the recovery manifest was not durable after writing');
        }

        $reloaded = RecoveryManifest::fromArray(json_decode((string) $stored->manifest, true) ?: []);

        if ($reloaded->fingerprint() !== $manifest->fingerprint()) {
            throw new RuntimeException(
                'the stored recovery manifest does not reconstruct to the manifest that was captured'
            );
        }

        return $uuid;
    }

    /**
     * Attach what the installer actually did, and mark the evidence live.
     *
     * Recorded even when the install failed part-way: a partial write is exactly
     * the case a later operator needs the decisions for.
     *
     * @param array<int,array<string,mixed>> $decisions
     */
    public static function recordDecisions(string $uuid, array $decisions): void
    {
        DB::table('engineering_recovery_manifests')->where('uuid', $uuid)->update([
            'decisions'  => json_encode($decisions, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'state'      => self::WRITTEN,
            'updated_at' => now(),
        ]);
    }

    /** Verified, nothing to undo. The evidence stays; it stops being pending. */
    public static function markComplete(string $uuid): void
    {
        self::transition($uuid, self::COMPLETE, null);
    }

    /** @param array<string,mixed> $outcome the RecoveryService evidence */
    public static function markRecovered(string $uuid, string $state, array $outcome): void
    {
        self::transition($uuid, $state, $outcome);
    }

    public static function find(string $uuid): ?object
    {
        return DB::table('engineering_recovery_manifests')->where('uuid', $uuid)->first();
    }

    /** Rebuild the manifest object from durable storage. */
    public static function manifestFor(string $uuid): ?RecoveryManifest
    {
        $row = self::find($uuid);
        if ($row === null) { return null; }

        $decoded = json_decode((string) $row->manifest, true);

        return is_array($decoded) ? RecoveryManifest::fromArray($decoded) : null;
    }

    /**
     * Evidence describing a repository that may still be mid-change.
     *
     * CAPTURED means nothing was written; WRITTEN means it was, and nobody has
     * said what happened next. The second is what a crashed execution leaves
     * behind. Reported for a human, never acted on here.
     *
     * @return array<int,object>
     */
    public static function outstanding(?string $repositoryPath = null): array
    {
        $query = DB::table('engineering_recovery_manifests')
            ->whereIn('state', [self::CAPTURED, self::WRITTEN]);

        if ($repositoryPath !== null) {
            $query->where('repository_path', $repositoryPath);
        }

        return $query->orderBy('id')->get()->all();
    }

    /** @param array<string,mixed>|null $outcome */
    private static function transition(string $uuid, string $state, ?array $outcome): void
    {
        $update = ['state' => $state, 'completed_at' => now(), 'updated_at' => now()];

        if ($outcome !== null) {
            $update['outcome'] = json_encode($outcome, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        DB::table('engineering_recovery_manifests')->where('uuid', $uuid)->update($update);
    }
}
