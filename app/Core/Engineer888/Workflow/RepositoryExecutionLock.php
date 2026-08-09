<?php

namespace App\Core\Engineer888\Workflow;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One repository, one execution at a time.
 *
 * WHAT THIS PREVENTS. Engineer888 writes through SafeInstaller, which is
 * idempotent when it runs alone and unsafe when it does not. Two workflows in
 * the same tree interleave in three separate ways, and each of them loses work
 * silently rather than loudly:
 *
 *   - `InstallManifest` is read into memory at the start of a run and written
 *     back after every file. Two runs each hold a stale copy, and the later
 *     save discards the other's provenance records — after which every file it
 *     forgot reads as UNKNOWN_PROVENANCE and refuses.
 *   - `WorkflowEngine::run()` deletes the task's entire stage history before
 *     replaying it. A second run erases the first run's trail while the first
 *     is still writing it.
 *   - Recovery decides whether to restore a file by asking whether it is still
 *     byte-for-byte what this workflow wrote. Another workflow writing during
 *     that inspection turns a clean rollback into a refusal, or worse, into a
 *     rollback of somebody else's bytes.
 *
 * THE RESOURCE IS THE TREE, NOT THE TASK. The key derives from the canonical
 * repository path and nothing else. Two different tasks that happen to target
 * the same repository are exactly as dangerous as one task dispatched twice, so
 * they contend on the same lock. A card UUID, a task UUID or a job ID would all
 * name the wrong thing and leave the real hazard unguarded.
 *
 * IT NEVER STEALS AND NEVER WAITS. `get()` is a single atomic attempt: a run
 * that cannot have the repository is refused immediately and truthfully. There
 * is no blocking acquire, because a worker asleep on a lock for the length of a
 * full-suite verification is a worker that has stopped being a worker, and no
 * force-acquire, because the only thing on the other end of this lock is a
 * process that is part-way through writing files.
 */
final class RepositoryExecutionLock
{
    /** The canonical reason a run was refused. Persisted and logged verbatim. */
    public const CONTENDED = 'EXECUTION_LOCK_CONTENDED';

    /**
     * How long the lock survives without anybody releasing it.
     *
     * THE FLOOR. `Engineer888WorkflowJob::$timeout` is 1800s, which is the
     * longest a workflow can run in-process, and the lock must comfortably
     * outlive that. A TTL that expired under a live run would be worse than no
     * lock at all: it would hand the repository to a second workflow while the
     * first was still installing, which is the precise scenario this class
     * exists to make impossible.
     *
     * THE CEILING. The release lives in a `finally`, so every ordinary exit —
     * completed, blocked, failed, thrown — frees the lock immediately. What
     * `finally` cannot survive is the queue worker being killed outright: the
     * job timeout is delivered as a signal that ends the process, and a SIGKILL
     * or an OOM kill gives no warning at all. In those cases the TTL is the only
     * thing that ever frees the repository, so it must be finite.
     *
     * 2700s = the 1800s job ceiling plus 900s of headroom for queue scheduling
     * and a slow full-suite run. A hard-killed worker therefore blocks the
     * repository for at most 45 minutes, and never for ever.
     */
    public const TTL_SECONDS = 2700;

    private const PREFIX = 'engineer888:repository-execution:';

    /** The companion key. Informational only — never consulted to decide. */
    private const HOLDER_SUFFIX = ':holder';

    private ?Lock $lock = null;

    private bool $held = false;

    private function __construct(
        public readonly string $repositoryPath,
        public readonly string $key,
        public readonly string $owner,
        public readonly ?string $taskUuid,
    ) {}

    public static function forRepository(string $repositoryPath, ?string $taskUuid = null): self
    {
        $canonical = self::canonicalise($repositoryPath);

        return new self(
            $canonical,
            self::PREFIX . hash('sha256', $canonical),
            // Identifies this attempt, not this repository. Laravel compares it
            // on release so a contender can never free the holder's lock.
            ($taskUuid ?? 'unknown') . ':' . getmypid() . ':' . Str::uuid(),
            $taskUuid,
        );
    }

    /**
     * One repository, one key, however the path was spelled.
     *
     * `realpath()` first, so a trailing slash, a `.` segment or a symlinked
     * checkout all collapse onto the same lock. When the path does not resolve
     * — a project row pointing at a tree that is not there — the normalised
     * string is used instead: still deterministic, and refusing to produce a
     * key would mean refusing to lock, which is the wrong way to fail.
     */
    public static function canonicalise(string $repositoryPath): string
    {
        $real = realpath($repositoryPath);

        if ($real !== false) { return $real; }

        return '/' . trim(str_replace('\\', '/', $repositoryPath), '/');
    }

    /** One atomic attempt. True means this process now owns the repository. */
    public function acquire(): bool
    {
        $this->lock = Cache::lock($this->key, self::TTL_SECONDS, $this->owner);
        $this->held = (bool) $this->lock->get();

        if ($this->held) {
            // Written after the lock is won, so it can only ever describe the
            // real holder. Nothing reads this to decide anything — it exists so
            // a refusal can say who is in there rather than only that somebody is.
            Cache::put($this->key . self::HOLDER_SUFFIX, [
                'task_uuid'       => $this->taskUuid,
                'owner'           => $this->owner,
                'pid'             => getmypid(),
                'repository_path' => $this->repositoryPath,
                'acquired_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            ], self::TTL_SECONDS);
        }

        return $this->held;
    }

    /**
     * Release, but only what we actually hold.
     *
     * The holder record goes first. Releasing the lock first would open a window
     * in which another run could acquire and publish its own holder record, and
     * this call would then delete theirs.
     */
    public function release(): void
    {
        if (! $this->held) { return; }

        Cache::forget($this->key . self::HOLDER_SUFFIX);
        $this->lock?->release();

        $this->held = false;
        $this->lock = null;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    /** Best-effort description of whoever holds it, or null. */
    public function heldBy(): ?array
    {
        $holder = Cache::get($this->key . self::HOLDER_SUFFIX);

        return is_array($holder) ? $holder : null;
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
