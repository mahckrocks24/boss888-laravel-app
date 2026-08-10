<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Audit\ExecutionAttempt;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Recovery\DurableRecovery;
use App\Core\Engineer888\Recovery\RecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The canonical engineering process.
 *
 * Fourteen stages, in order, halting on the first that blocks or fails. Nothing
 * downstream of a halt runs — a task whose tests failed never reaches deployment
 * verification, and a task nobody approved never reaches implementation.
 *
 * The engine holds no engineering knowledge of its own. Every stage delegates to
 * a capability built and proved in an earlier sprint. That is deliberate: this
 * sprint adds sequence, audit and gating, not analysis.
 *
 * PROJECTS, NOT A CODEBASE. The engine resolves its repository, manifest and
 * test configuration from a project row. LevelUp Growth is Project #001; adding
 * Studio888 or ChefListed is an insert, not a change here.
 */
final class WorkflowEngine
{
    /** The lifecycle, in order. */
    public const LIFECYCLE = [
        ReceiveStage::class,
        UnderstandStage::class,
        AnalyzeStage::class,
        PlanStage::class,
        EstimateStage::class,
        IdentifyFilesStage::class,
        IdentifyRisksStage::class,
        RequestApprovalStage::class,
        ImplementStage::class,
        VerifyStage::class,
        DeployVerifyStage::class,
        DocumentStage::class,
        LearnStage::class,
        PromoteStage::class,
    ];

    /** @return array<int,Stage> */
    public static function stages(): array
    {
        return array_map(fn (string $class) => new $class(), self::LIFECYCLE);
    }

    // ── projects ────────────────────────────────────────────────────────

    public function registerProject(array $attributes): object
    {
        $key = $attributes['key'] ?? Str::slug($attributes['name'] ?? 'project');

        $existing = DB::table('engineering_projects')->where('key', $key)->first();
        if ($existing !== null) { return $existing; }

        $id = DB::table('engineering_projects')->insertGetId([
            'company'            => $attributes['company'] ?? 'LevelUp Growth',
            'key'                => $key,
            'name'               => $attributes['name'] ?? $key,
            'repository_path'    => $attributes['repository_path'],
            'ownership_manifest' => $attributes['ownership_manifest'] ?? null,
            'test_database'      => $attributes['test_database'] ?? null,
            'phpunit_config'     => $attributes['phpunit_config'] ?? null,
            'metadata'           => isset($attributes['metadata']) ? json_encode($attributes['metadata']) : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return DB::table('engineering_projects')->find($id);
    }

    public function project(string $key): ?object
    {
        return DB::table('engineering_projects')->where('key', $key)->first();
    }

    // ── tasks ───────────────────────────────────────────────────────────

    /** @return object the created task row */
    public function createTask(string $projectKey, array $attributes): object
    {
        $project = $this->project($projectKey);
        if ($project === null) { throw new RuntimeException("unknown project: {$projectKey}"); }

        $manifest = OwnershipManifest::active($project->repository_path);
        $uuid = (string) Str::uuid();

        $id = DB::table('engineering_tasks')->insertGetId([
            'uuid'                => $uuid,
            'project_id'          => $project->id,
            'title'               => $attributes['title'] ?? '',
            'description'         => $attributes['description'] ?? '',
            'kind'                => $attributes['kind'] ?? 'feature',
            'priority'            => $attributes['priority'] ?? 'normal',
            'requested_by'        => $attributes['requested_by'] ?? null,
            'acceptance_criteria' => json_encode($attributes['acceptance_criteria'] ?? []),
            'constraints'         => json_encode($attributes['constraints'] ?? []),
            'modules'             => json_encode($attributes['modules'] ?? []),
            'change_set'          => json_encode($attributes['change_set'] ?? []),
            'unknowns'            => json_encode($attributes['unknowns'] ?? []),
            'status'              => 'received',
            'session'             => $manifest?->session(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return DB::table('engineering_tasks')->find($id);
    }

    public function task(string $uuid): ?object
    {
        return DB::table('engineering_tasks')->where('uuid', $uuid)->first();
    }

    public function approve(string $uuid, string $approver, ?string $note = null): object
    {
        $task = $this->task($uuid);
        if ($task === null) { throw new RuntimeException("unknown task: {$uuid}"); }

        DB::table('engineering_tasks')->where('id', $task->id)->update([
            'approved_by'   => $approver,
            'approved_at'   => now(),
            'approval_note' => $note,
            'updated_at'    => now(),
        ]);

        return $this->task($uuid);
    }

    // ── execution ───────────────────────────────────────────────────────

    /**
     * Run the lifecycle. Halts on the first stage that blocks or fails.
     *
     * @return array<string,mixed>
     */
    public function run(string $uuid, bool $dryRun = false): array
    {
        $task = $this->task($uuid);
        if ($task === null) { throw new RuntimeException("unknown task: {$uuid}"); }

        $project = DB::table('engineering_projects')->find($task->project_id);
        if ($project === null) { throw new RuntimeException('task has no project'); }

        // ONE REPOSITORY, ONE EXECUTION.
        //
        // Acquired here, before the stage history is deleted, because that
        // delete is itself destructive: a refused run that had already wiped the
        // trail would destroy the evidence of the run it was refused in favour
        // of. Everything past this point — PLAN, IMPLEMENT, VERIFY and the
        // recovery that follows a halt — happens with the tree held.
        //
        // The release is in `finally`, and it is deliberately NOT at the end of
        // IMPLEMENT. Recovery decides what to restore by asking whether a file
        // is still exactly what this workflow wrote; a second workflow writing
        // during that question makes the answer meaningless.
        $lock = RepositoryExecutionLock::forRepository((string) $project->repository_path, $uuid);

        if (! $lock->acquire()) {
            return $this->refuseContended($task, $project, $uuid, $lock);
        }

        try {
            return $this->lifecycle($task, $project, $uuid, $dryRun, $lock);
        } finally {
            $lock->release();
        }
    }

    /**
     * The lifecycle proper. Only ever entered while the repository lock is held.
     *
     * @return array<string,mixed>
     */
    private function lifecycle(
        object $task,
        object $project,
        string $uuid,
        bool $dryRun,
        ?RepositoryExecutionLock $lock = null,
    ): array {
        $context = new WorkflowContext($task, $project, $project->repository_path);

        // OPENED INSIDE THE LOCK, BEFORE THE TRAIL IS TOUCHED.
        //
        // The line below deletes this task's entire stage history, which is why
        // the stage table can never be the record of an execution: the run that
        // would investigate a crash is the run that erases its evidence. The
        // attempt row is append-only and survives that, and survives the process
        // ending outright, which the stage trail and the in-memory context do not.
        $attempt = ExecutionAttempt::open($task, $project, $lock, $dryRun);
        $context->set('execution.attempt', $attempt);

        // Re-running clears prior stage records so the trail describes THIS run.
        DB::table('engineering_task_stages')->where('task_id', $task->id)->delete();

        $results = [];
        $halted = null;
        $position = 0;

        foreach (self::stages() as $stage) {
            $position++;

            if ($dryRun && in_array($stage->name(), ['IMPLEMENT', 'DEPLOY_VERIFY', 'PROMOTE'], true)) {
                $result = StageResult::skipped($stage->name() . ' skipped',
                    'dry run: this stage writes, and a dry run must not');
            } else {
                $started = microtime(true);
                try {
                    $result = $stage->run($context);
                } catch (\Throwable $e) {
                    $result = StageResult::failed('stage threw',
                        get_class($e) . ': ' . substr($e->getMessage(), 0, 300));
                }
                $durationMs = (int) round((microtime(true) - $started) * 1000);
            }

            $this->recordStage($task->id, $stage, $position, $result, $durationMs ?? 0);
            $results[] = ['stage' => $stage->name(), 'result' => $result];

            DB::table('engineering_tasks')->where('id', $task->id)
                ->update(['current_stage' => $stage->name(), 'updated_at' => now()]);

            if ($result->halts()) { $halted = $stage->name(); break; }
        }

        $status = $halted === null ? 'completed'
            : (end($results)['result']->status === StageResult::BLOCKED ? 'blocked' : 'failed');

        // A HALT AFTER A WRITE IS NOT THE END OF THE STORY.
        //
        // Sprint 8 stopped correctly at VERIFY and left the installed file in
        // the running application. Correct, and not safe. If this run wrote
        // anything and then halted, the repository is put back before the task
        // is reported.
        $recovery = null;
        if ($halted !== null) {
            $recovery = $this->recover($task, $context, $position, $results);
            if ($recovery !== null) { $status = $recovery['status']; }
        }

        DB::table('engineering_tasks')->where('id', $task->id)
            ->update(['status' => $status, 'updated_at' => now()]);

        $this->closeAttempt($attempt, $context, $status, $halted, $results, $recovery);

        return [
            'task'    => $this->task($uuid),
            'project' => $project,
            'status'  => $status,
            'halted_at' => $halted,
            'stages'  => $results,
            'context' => $context,
            'recovery' => $recovery,
        ];
    }

    /**
     * Refused: another workflow is already executing against this repository.
     *
     * FAIL CLOSED AND FAIL QUIET. Nothing here deletes a stage row, runs a
     * stage, reasons, or reaches SafeInstaller. The run does not wait, retry or
     * steal the lock — a worker blocked on a full-suite verification is a
     * worker that has stopped working, and forcing the lock would interrupt a
     * process part-way through writing files.
     *
     * THE TASK ROW IS LEFT ALONE WHEN THE HOLDER IS THIS SAME TASK. A duplicate
     * dispatch is refused by the same lock, and marking the task blocked would
     * report a live execution as stopped. When some OTHER task holds the
     * repository this task genuinely cannot proceed, and says so.
     *
     * @return array<string,mixed>
     */
    private function refuseContended(object $task, object $project, string $uuid, RepositoryExecutionLock $lock): array
    {
        $holder = $lock->heldBy();
        $heldBySameTask = ($holder['task_uuid'] ?? null) === $uuid;

        $detail = 'another Engineer888 workflow is already executing against '
            . $lock->repositoryPath
            . ($holder === null
                ? '. Nothing was read, written or deleted.'
                : ', started at ' . ($holder['acquired_at'] ?? 'an unrecorded time')
                  . ' for task ' . ($holder['task_uuid'] ?? 'unknown')
                  . '. Nothing was read, written or deleted.');

        if (! $heldBySameTask) {
            DB::table('engineering_tasks')->where('id', $task->id)
                ->update(['status' => 'blocked', 'updated_at' => now()]);
        }

        Log::warning('[engineer888] ' . RepositoryExecutionLock::CONTENDED, [
            'task' => $uuid, 'repository' => $lock->repositoryPath,
            'holder' => $holder, 'same_task' => $heldBySameTask,
        ]);

        return [
            'task'      => $this->task($uuid),
            'project'   => $project,
            'status'    => 'blocked',
            'halted_at' => RepositoryExecutionLock::CONTENDED,
            'stages'    => [],
            'context'   => new WorkflowContext($task, $project, (string) $project->repository_path),
            'recovery'  => null,
            'lock'      => [
                'contended'  => true,
                'reason'     => RepositoryExecutionLock::CONTENDED,
                'detail'     => $detail,
                'key'        => $lock->key,
                'repository' => $lock->repositoryPath,
                'holder'     => $holder,
                'same_task'  => $heldBySameTask,
            ],
        ];
    }

    /**
     * Restore what this run wrote, when it wrote something and then stopped.
     *
     * Returns null when there is nothing to undo — the common case, because
     * most halts happen before IMPLEMENT. Recovery never re-reasons, never
     * continues execution, and never touches a file this run did not write.
     *
     * @return array<string,mixed>|null
     */
    private function recover(object $task, WorkflowContext $context, int $position, array $results): ?array
    {
        $manifest = $context->get('implement.recovery_manifest');
        $decisions = $context->get('implement.decisions', []);

        $written = array_values(array_filter($decisions,
            fn ($d) => in_array($d['state'] ?? '', ['INSTALL', 'UPDATE', 'OVERRIDDEN'], true)));

        if ($manifest === null || $written === []) {
            return null;   // nothing reached the repository
        }

        DB::table('engineering_tasks')->where('id', $task->id)
            ->update(['status' => 'recovering', 'updated_at' => now()]);

        $started = microtime(true);

        try {
            $evidence = (new RecoveryService($context->repoPath))
                ->restore($manifest, $decisions, (string) ($task->session ?? 'engineer888'));
        } catch (\Throwable $e) {
            $evidence = [
                'status'  => RecoveryService::INCOMPLETE,
                'summary' => 'the recovery itself threw: ' . get_class($e) . ': ' . $e->getMessage(),
                'performed' => [], 'problems' => [], 'manual' => [],
                'fingerprint' => $manifest->fingerprint(),
            ];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        DB::table('engineering_task_stages')->insert([
            'task_id'     => $task->id,
            'stage'       => 'RECOVERY',
            'position'    => $position + 1,
            'status'      => $evidence['status'] === RecoveryService::RECOVERED
                ? StageResult::OK : StageResult::FAILED,
            'summary'     => $evidence['summary'],
            'inputs'      => json_encode([
                'purpose'  => 'Restore the exact pre-task state of every file this run wrote, or refuse and say why.',
                'declared' => ['implement.recovery_manifest', 'implement.decisions'],
            ]),
            'outputs'     => json_encode($evidence, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'evidence'    => json_encode([
                'failure_modes' => [
                    'a file changed after this run wrote it — another engineer\'s work',
                    'a backup is missing or no longer matches what it copied',
                    'the recovery manifest is incomplete',
                ],
                'verification' => 'every restored file is re-hashed and compared with the pre-write hash',
                'recovery'     => 'when automatic recovery refuses, the manual steps are recorded verbatim',
            ], JSON_PARTIAL_OUTPUT_ON_ERROR),
            'failure'     => $evidence['status'] === RecoveryService::RECOVERED ? null
                : implode("\n", $evidence['manual'] ?? [$evidence['summary']]),
            'duration_ms' => $durationMs,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $evidence;
    }

    /**
     * Close the attempt, and settle the durable recovery evidence with it.
     *
     * Both are best-effort at this point and neither may break the run: the
     * work is already done and the caller is entitled to its result. A failure
     * to close is logged and leaves the attempt open, which is exactly how an
     * abandoned execution should look to whoever reads the table next.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<string,mixed>|null       $recovery
     */
    private function closeAttempt(
        ExecutionAttempt $attempt,
        WorkflowContext $context,
        string $status,
        ?string $halted,
        array $results,
        ?array $recovery,
    ): void {
        $failure = null;
        foreach ($results as $entry) {
            if ($entry['result']->failure !== null) {
                $failure = $entry['stage'] . ': ' . $entry['result']->failure;
            }
        }

        $recovered = $recovery !== null && ($recovery['status'] ?? '') === RecoveryService::RECOVERED;

        $result = match (true) {
            $recovery !== null => $recovered ? ExecutionAttempt::RECOVERED : ExecutionAttempt::RECOVERY_BLOCKED,
            $halted === null   => ExecutionAttempt::COMPLETED,
            $status === 'blocked' => ExecutionAttempt::BLOCKED,
            default            => ExecutionAttempt::FAILED,
        };

        // The evidence stops being outstanding only when its own question has
        // been answered: either a rollback happened, or none was needed. A
        // process that dies before this line leaves it outstanding, which is
        // the whole point of storing it.
        $record = $context->get('implement.recovery_record');

        if (is_string($record) && $record !== '') {
            try {
                if ($recovery !== null) {
                    DurableRecovery::markRecovered($record,
                        $recovered ? DurableRecovery::RECOVERED : DurableRecovery::RECOVERY_BLOCKED,
                        $recovery);
                } else {
                    DurableRecovery::markComplete($record);
                }
            } catch (\Throwable $e) {
                Log::error('[engineer888] could not settle the durable recovery evidence', [
                    'record' => $record, 'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $attempt->close($result, $halted, $failure);
        } catch (\Throwable $e) {
            Log::error('[engineer888] could not close the execution attempt', [
                'attempt' => $attempt->uuid, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordStage(int $taskId, Stage $stage, int $position, StageResult $result, int $durationMs): void
    {
        DB::table('engineering_task_stages')->insert([
            'task_id'     => $taskId,
            'stage'       => $stage->name(),
            'position'    => $position,
            'status'      => $result->status,
            'summary'     => mb_substr($result->summary, 0, 60000),
            // The stage contract is recorded with the run, so an audit reads what
            // the stage promised alongside what it did.
            'inputs'      => json_encode(['declared' => $stage->inputs(), 'purpose' => $stage->purpose()]),
            'outputs'     => json_encode($result->outputs, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'evidence'    => json_encode([
                'evidence'      => $result->evidence,
                'failure_modes' => $stage->failureModes(),
                'verification'  => $stage->verification(),
                'recovery'      => $stage->recovery(),
            ], JSON_PARTIAL_OUTPUT_ON_ERROR),
            'failure'     => $result->failure !== null ? mb_substr($result->failure, 0, 60000) : null,
            'duration_ms' => $durationMs,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
