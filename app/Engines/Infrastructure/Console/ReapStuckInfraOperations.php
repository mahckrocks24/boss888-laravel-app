<?php

namespace App\Engines\Infrastructure\Console;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Services\InfraEventRecorder;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovers stuck infrastructure operations (directive §14).
 *
 * Follows the established house reaper pattern (`credits:reap-orphans`,
 * `tasks:recover-orphans`, `studio:reap-stuck-renders`).
 *
 * SAFETY RULES ENCODED HERE:
 *   - Never approves anything. An operation stuck in awaiting_approval is left
 *     alone — that is a human decision, not a recovery action.
 *   - Never blindly repeats an irreversible operation. Registry metadata decides.
 *   - A timed-out RUNNING operation moves to compensation_pending, NOT back to
 *     queued: we do not know whether the provider applied the change, so it must
 *     be reconciled rather than retried.
 *   - Respects max_attempts.
 *   - Locks rows with lockForUpdate so two concurrent reapers cannot both
 *     recover the same operation.
 *   - Preserves the original actor and workspace on every record it touches.
 */
class ReapStuckInfraOperations extends Command
{
    protected $signature = 'infra:reap-operations {--dry-run : Report without changing anything}';

    protected $description = 'Recover infrastructure operations stuck in queued, running or compensation_pending';

    public function handle(InfraEventRecorder $events): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stuckMinutes = (int) config('infrastructure.operations.stuck_after_minutes', 30);
        $cutoff = now()->subMinutes($stuckMinutes);

        // Deliberately reads WITHOUT the workspace scope: the reaper is a
        // platform-level sweeper. It re-enters each row's own workspace context
        // before mutating, so actor/workspace attribution is preserved.
        $ids = InfraOperation::withoutWorkspaceScope()
            ->whereIn('state', OperationState::recoverable())
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No stuck infrastructure operations.');
            return self::SUCCESS;
        }

        $this->info("Found {$ids->count()} candidate operation(s) older than {$stuckMinutes}m.");

        $recovered = 0;
        $terminal = 0;
        $alerted = 0;

        foreach ($ids as $id) {
            $decision = DB::transaction(function () use ($id, $cutoff, $dryRun, $events) {
                /** @var InfraOperation|null $op */
                $op = InfraOperation::withoutWorkspaceScope()->lockForUpdate()->find($id);

                // Re-check under the lock — another reaper may have handled it.
                if (!$op || !in_array($op->state, OperationState::recoverable(), true)
                    || $op->updated_at >= $cutoff) {
                    return 'skipped';
                }

                $wsId = (int) $op->workspace_id;
                $from = (string) $op->state;

                return WorkspaceContext::run($wsId, function () use ($op, $wsId, $from, $dryRun, $events) {
                    $exhausted = (int) $op->attempt_count >= (int) $op->max_attempts;

                    // RUNNING past its deadline: provider outcome UNKNOWN.
                    if ($from === OperationState::RUNNING) {
                        $target = OperationState::TIMED_OUT;
                        $summary = 'The operation did not complete in time and is being reconciled.';
                    } elseif ($from === OperationState::QUEUED) {
                        // Never started. Safe to retry if attempts remain.
                        $target = $exhausted ? OperationState::TIMED_OUT : OperationState::RUNNING;
                        $summary = $exhausted
                            ? 'The operation could not be started after repeated attempts.'
                            : 'The operation was picked up again after a delay.';
                    } else { // COMPENSATION_PENDING
                        $target = OperationState::FAILED_TERMINAL;
                        $summary = 'The operation needs manual review.';
                    }

                    if ($dryRun) {
                        return "dry:{$from}->{$target}";
                    }

                    // QUEUED -> RUNNING is a legal retry; everything else here is
                    // a failure/timeout branch.
                    if ($target === OperationState::RUNNING) {
                        $op->attempt_count = (int) $op->attempt_count + 1;
                        $op->started_at = now();
                    } else {
                        $op->finished_at = now();
                        $op->failure_summary = $summary;
                    }

                    $op->transitionTo($target);
                    $op->save();

                    $events->record(
                        workspaceId: $wsId,
                        event: 'operation_reaped',
                        ownerType: (string) $op->owner_type,
                        ownerId: $op->id,
                        severity: $target === OperationState::RUNNING
                            ? InfraEvent::SEVERITY_WARNING
                            : InfraEvent::SEVERITY_ERROR,
                        fromState: $from,
                        toState: $target,
                        summary: $summary,
                        context: [
                            'attempt'      => $op->attempt_count,
                            'max_attempts' => $op->max_attempts,
                            'reaper'       => true,
                        ],
                        actorUserId: $op->actor_user_id, // original actor preserved
                        source: 'system',
                        operationId: $op->id,
                        provider: $op->provider,
                    );

                    if ($target === OperationState::TIMED_OUT) {
                        // Reconciliation against provider truth is required before
                        // anything else may happen to this operation.
                        $op->transitionTo(OperationState::COMPENSATION_PENDING)->save();

                        return 'alert';
                    }

                    return $target === OperationState::RUNNING ? 'recovered' : 'terminal';
                });
            });

            match ($decision) {
                'recovered' => $recovered++,
                'terminal'  => $terminal++,
                'alert'     => $alerted++,
                default     => null,
            };

            if (str_starts_with((string) $decision, 'dry:')) {
                $this->line("  [dry-run] operation {$id}: {$decision}");
            }
        }

        $this->info("Recovered: {$recovered}  Terminal: {$terminal}  Needs reconciliation: {$alerted}");

        return self::SUCCESS;
    }
}
