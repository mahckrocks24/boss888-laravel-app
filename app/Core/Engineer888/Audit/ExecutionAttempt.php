<?php

namespace App\Core\Engineer888\Audit;

use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\PreImage;
use App\Core\Engineer888\Workflow\RepositoryExecutionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One execution, one row, written before it can go wrong.
 *
 * WHAT WAS MISSING. Every record of a run lived somewhere that a re-run or a
 * crash destroys. `engineering_task_stages` is deleted at the start of the next
 * run, so the trail of the run that died is erased by the run investigating it.
 * The recovery manifest lived in a `WorkflowContext` — a plain in-memory array —
 * so a SIGKILL between the install and the halt handler took the only record of
 * what had just been written to disk. The repository was left changed and
 * nothing anywhere knew what to change back.
 *
 * WHAT THIS IS NOT. It is not a log. A log line says something happened; this
 * says what the repository was, what was approved, which files were in scope and
 * what their hashes were on both sides of the attempt — enough for an operator
 * who arrives afterwards to reconstruct the state rather than infer it.
 *
 * APPEND-ONLY, ENFORCED HERE. Identity, hashes and evidence are written once.
 * `bind()` refuses to change a value it has already recorded, and `close()`
 * refuses to close an attempt twice. Filling a NULL that was always going to be
 * filled — the candidate is not known until IMPLEMENT resolves it — is not a
 * rewrite; changing a recorded value is, and it throws. This is application
 * enforcement, not a database constraint: a hand-written UPDATE would still
 * succeed, and the guarantee is that nothing in Engineer888 issues one.
 */
final class ExecutionAttempt
{
    public const OPEN = 'OPEN';
    public const COMPLETED = 'COMPLETED';
    public const BLOCKED = 'BLOCKED';
    public const FAILED = 'FAILED';
    public const RECOVERED = 'RECOVERED';
    public const RECOVERY_BLOCKED = 'RECOVERY_BLOCKED';

    /** Written once at open, or once at bind. Never changed afterwards. */
    private const IMMUTABLE = [
        'uuid', 'task_uuid', 'repository_path', 'lock_key', 'lock_owner', 'worker',
        'candidate_uuid', 'approval_id', 'approval_fingerprint', 'pre_image_fingerprint',
        'targets', 'repository_head_before', 'targets_hash_before', 'started_at',
    ];

    private function __construct(
        public readonly int $id,
        public readonly string $uuid,
        private readonly string $repositoryPath,
    ) {}

    public static function open(
        object $task,
        object $project,
        ?RepositoryExecutionLock $lock = null,
        bool $dryRun = false,
    ): self {
        $uuid = (string) Str::uuid();
        $repositoryPath = (string) $project->repository_path;

        $id = (int) DB::table('engineering_execution_attempts')->insertGetId([
            'uuid'                   => $uuid,
            'task_uuid'              => (string) $task->uuid,
            'repository_path'        => $repositoryPath,
            'lock_key'               => $lock?->key,
            'lock_owner'             => $lock?->owner,
            'worker'                 => self::worker(),
            'dry_run'                => $dryRun,
            'repository_head_before' => self::head($repositoryPath),
            'result'                 => self::OPEN,
            'started_at'             => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return new self($id, $uuid, $repositoryPath);
    }

    /**
     * Record what this attempt is actually executing, once it is known.
     *
     * @param array<int,string> $targets the approved paths, in scope for the hashes
     * @throws RuntimeException if any of it has already been recorded differently
     */
    public function bind(
        CandidateImplementation $candidate,
        string $candidateUuid,
        ?object $approval,
        array $targets,
    ): void {
        $values = [
            'candidate_uuid'        => $candidateUuid,
            'approval_id'           => $approval?->id,
            'approval_fingerprint'  => $approval?->fingerprint,
            'pre_image_fingerprint' => self::preImageFingerprint($candidate),
            'targets'               => json_encode(array_values($targets)),
            'targets_hash_before'   => self::targetsHash($this->repositoryPath, $targets),
        ];

        $this->guardImmutable($values);

        DB::table('engineering_execution_attempts')->where('id', $this->id)
            ->update($values + ['updated_at' => now()]);
    }

    /**
     * Close the attempt with its outcome and the state it left the files in.
     *
     * @param array<int,string> $targets so the after-hash covers the same set
     */
    public function close(string $result, ?string $workflowState = null, ?string $failure = null, ?string $commitSha = null): void
    {
        $row = $this->row();

        if ($row !== null && $row->finished_at !== null) {
            throw new RuntimeException(
                "execution attempt {$this->uuid} is already closed; a closed attempt is history and is not reopened"
            );
        }

        $targets = json_decode((string) ($row->targets ?? ''), true);

        DB::table('engineering_execution_attempts')->where('id', $this->id)->update([
            'result'                => $result,
            'workflow_state'        => $workflowState,
            'failure'               => $failure === null ? null : mb_substr($failure, 0, 60000),
            'commit_sha'            => $commitSha,
            'repository_head_after' => self::head($this->repositoryPath),
            'targets_hash_after'    => is_array($targets) && $targets !== []
                ? self::targetsHash($this->repositoryPath, $targets)
                : null,
            'finished_at'           => now(),
            'updated_at'            => now(),
        ]);
    }

    public function row(): ?object
    {
        return DB::table('engineering_execution_attempts')->where('id', $this->id)->first();
    }

    public static function find(string $uuid): ?object
    {
        return DB::table('engineering_execution_attempts')->where('uuid', $uuid)->first();
    }

    /**
     * Attempts that were opened and never closed.
     *
     * The shape of a process that died mid-execution. Reported, never repaired
     * automatically — a later operator decides, with the durable recovery
     * manifest beside it.
     *
     * @return array<int,object>
     */
    public static function unfinished(?string $repositoryPath = null): array
    {
        $query = DB::table('engineering_execution_attempts')->whereNull('finished_at');

        if ($repositoryPath !== null) {
            $query->where('repository_path', $repositoryPath);
        }

        return $query->orderBy('id')->get()->all();
    }

    /**
     * This execution's own footprint, hashed.
     *
     * Deliberately scoped to the files in play. A whole-tree hash in a
     * repository three sessions are working in would differ on every run for
     * reasons that have nothing to do with the change, which is a number that
     * looks like evidence and is not.
     *
     * @param array<int,string> $paths
     */
    public static function targetsHash(string $repositoryPath, array $paths): string
    {
        $root = realpath($repositoryPath);
        $parts = [];

        foreach ($paths as $path) {
            $path = (string) $path;
            $real = $root === false ? false : realpath($root . '/' . ltrim($path, '/'));

            $parts[] = $path . ':' . ($real !== false && is_file($real)
                ? hash_file('sha256', $real)
                : 'absent');
        }

        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /** The identity of the pre-image E1-A bound into the approval. */
    public static function preImageFingerprint(CandidateImplementation $candidate): ?string
    {
        $parts = PreImage::canonicalParts($candidate->preImage);

        return $parts === [] ? null : hash('sha256', implode("\n", $parts));
    }

    /**
     * The commit the tree was on, read without a shell.
     *
     * A worktree's `.git` is a file pointing elsewhere, so both shapes are
     * handled. Null when it cannot be determined — an unknown head is recorded
     * as unknown rather than guessed.
     */
    public static function head(string $repositoryPath): ?string
    {
        $gitPath = rtrim($repositoryPath, '/') . '/.git';

        if (is_file($gitPath)) {
            $pointer = trim((string) @file_get_contents($gitPath));
            if (! str_starts_with($pointer, 'gitdir: ')) { return null; }
            $gitPath = trim(substr($pointer, 8));
        }

        if (! is_dir($gitPath)) { return null; }

        $head = @file_get_contents($gitPath . '/HEAD');
        if ($head === false) { return null; }
        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
        }

        $ref = trim(substr($head, 5));

        if (is_file($gitPath . '/' . $ref)) {
            $sha = trim((string) @file_get_contents($gitPath . '/' . $ref));

            return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
        }

        // A packed ref: the loose file has been compacted away.
        $packed = @file_get_contents($gitPath . '/packed-refs');
        if ($packed !== false
            && preg_match('/^([0-9a-f]{40})\s+' . preg_quote($ref, '/') . '$/m', $packed, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private static function worker(): string
    {
        return (string) (gethostname() ?: 'unknown') . ':' . getmypid();
    }

    /**
     * Refuse to change anything already recorded.
     *
     * @param array<string,mixed> $values
     */
    private function guardImmutable(array $values): void
    {
        $row = $this->row();
        if ($row === null) { return; }

        foreach ($values as $column => $value) {
            if (! in_array($column, self::IMMUTABLE, true)) { continue; }

            $existing = $row->{$column} ?? null;

            if ($existing !== null && (string) $existing !== (string) $value) {
                throw new RuntimeException(
                    "execution attempt {$this->uuid} already recorded {$column}; execution evidence is "
                    . 'appended, never rewritten'
                );
            }
        }
    }
}
