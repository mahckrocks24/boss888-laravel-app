<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;

/**
 * EXPERIENCE888 — ingestion from authoritative records.
 *
 * Experience is derived from rows the platform already treats as true (tasks,
 * approvals, articles, commitments), never from what Sarah said. Every event
 * carries the table and id that proves it, and every pass is idempotent, so
 * re-running the ingestor cannot inflate a sample size into a false pattern.
 *
 * Attribution discipline, applied here rather than left to the caller:
 *   a task's own terminal status is DIRECTLY_ATTRIBUTABLE - the row IS the result
 *   anything downstream of it (traffic, revenue) is at best CORRELATED, and this
 *   ingestor deliberately does not claim it.
 */
final class ExperienceIngestor
{
    public function __construct(
        private ExperienceRecorder $rec = new ExperienceRecorder(),
        private PatternEngine $patterns = new PatternEngine(),
    ) {}

    /**
     * Terminal tasks become execution events, outcomes and action-reliability
     * evidence. Only terminal rows are read: an in-flight task has no outcome.
     */
    public function ingestTasks(int $wsId, int $limit = 500): array
    {
        if ($wsId <= 0) throw new \InvalidArgumentException('Experience888: workspace_id is required');

        $rows = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->whereIn('status', ['completed', 'failed'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'status', 'engine', 'action', 'payload_json', 'created_at', 'updated_at']);

        $events = 0; $outcomes = 0; $observed = 0;

        foreach ($rows as $t) {
            $completed = $t->status === 'completed';
            $type = $completed ? ExperienceRecorder::TASK_COMPLETED : ExperienceRecorder::TASK_FAILED;

            $eventId = $this->rec->recordEvent($wsId, $type, [
                'occurred_at'   => $t->updated_at ?: $t->created_at,
                'actor_type'    => 'engine',
                'capability'    => $t->engine,
                'action'        => $t->action,
                'entity_type'   => 'task',
                'entity_id'     => (string) $t->id,
                'task_id'       => (int) $t->id,
                'evidence_type' => 'tasks',
                'evidence_id'   => (string) $t->id,
                'payload'       => ['status' => $t->status, 'engine' => $t->engine, 'action' => $t->action],
            ]);
            $events++;

            $outcomes += $this->rec->recordOutcome($wsId, $eventId,
                $completed ? 'succeeded' : 'failed', [
                    'observed_at'       => $t->updated_at ?: $t->created_at,
                    'attribution_level' => ExperienceRecorder::ATTR_DIRECT,
                    'attribution_basis' => "tasks.id={$t->id} reached status={$t->status}; "
                                         . 'the record itself is the result, not an inference',
                    'evidence_type'     => 'tasks',
                    'evidence_id'       => (string) $t->id,
                ]) > 0 ? 1 : 0;

            // Reliability evidence per action. The statement is phrased so that
            // "supports" always means the claim held.
            if ($t->action) {
                $this->patterns->observe($wsId,
                    PatternEngine::TYPE_ACTION_RECOVERY,
                    'action:' . $t->action,
                    $completed ? 'supports' : 'contradicts',
                    [
                        'event_id'    => $eventId,
                        'observed_at' => $t->updated_at ?: $t->created_at,
                        'statement'   => "{$t->action} completes successfully when attempted",
                        'payload'     => ['engine' => $t->engine, 'action' => $t->action],
                    ]);
                $observed++;
            }
        }

        return ['tasks_read' => count($rows), 'events' => $events,
                'outcomes' => $outcomes, 'pattern_observations' => $observed];
    }

    /**
     * Owner approval decisions. These are the strongest signal of intent the
     * platform records, because the owner acted rather than commented.
     */
    public function ingestApprovals(int $wsId, int $limit = 300): array
    {
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM approvals'));
        if (!in_array('workspace_id', $cols, true)) return ['skipped' => 'approvals has no workspace_id'];

        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        if (!$statusCol) return ['skipped' => 'approvals has no status column'];

        $rows = DB::table('approvals')->where('workspace_id', $wsId)
            ->orderByDesc('id')->limit($limit)->get();

        $n = 0;
        foreach ($rows as $r) {
            $s = strtolower((string) ($r->status ?? ''));
            $type = match (true) {
                str_contains($s, 'approve') => ExperienceRecorder::OWNER_APPROVAL,
                str_contains($s, 'reject'), str_contains($s, 'denied') => ExperienceRecorder::OWNER_REJECTION,
                default => null,
            };
            if (!$type) continue;

            $this->rec->recordEvent($wsId, $type, [
                'occurred_at'   => $r->updated_at ?? $r->created_at ?? now(),
                'actor_type'    => 'owner',
                'entity_type'   => 'approval',
                'entity_id'     => (string) $r->id,
                'evidence_type' => 'approvals',
                'evidence_id'   => (string) $r->id,
                'payload'       => ['status' => $r->status ?? null],
            ]);
            $n++;
        }
        return ['approvals_read' => count($rows), 'events' => $n];
    }

    /**
     * Advance sarah_commitments.verification_state from authoritative evidence.
     *
     * All 248 rows sat at 'unverified' because nothing ever resolved them. A
     * conversational promise is NOT evidence of its own completion, so a
     * commitment only becomes COMPLETED/FAILED when a task row proves it. Where
     * no evidence exists and the deadline has passed, the honest state is
     * UNVERIFIABLE - not COMPLETED, and not silently left open.
     */
    public function verifyCommitments(int $wsId, int $limit = 500): array
    {
        $rows = DB::table('sarah_commitments')->where('workspace_id', $wsId)
            ->orderByDesc('id')->limit($limit)->get();

        $counts = ['COMPLETED' => 0, 'FAILED' => 0, 'CANCELLED' => 0,
                   'SUPERSEDED' => 0, 'UNVERIFIABLE' => 0, 'left_open' => 0];

        foreach ($rows as $c) {
            $state = null; $basis = null;

            if ($c->status === 'cancelled') {
                $state = 'CANCELLED';
                $basis = 'sarah_commitments.status=cancelled';
            } elseif ($c->status === 'superseded' || $c->superseded_by_id) {
                $state = 'SUPERSEDED';
                $basis = 'superseded_by_id=' . ($c->superseded_by_id ?? 'n/a');
            } elseif ($c->related_task_id) {
                $task = DB::table('tasks')->where('id', $c->related_task_id)
                    ->where('workspace_id', $wsId)->first(['id', 'status']);
                if ($task && $task->status === 'completed') {
                    $state = 'COMPLETED';
                    $basis = "tasks.id={$task->id} status=completed";
                } elseif ($task && $task->status === 'failed') {
                    $state = 'FAILED';
                    $basis = "tasks.id={$task->id} status=failed";
                }
            }

            // Deadline passed with nothing to check it against.
            if ($state === null && $c->deadline && strtotime((string) $c->deadline) < time()) {
                $state = 'UNVERIFIABLE';
                $basis = 'deadline ' . $c->deadline . ' passed with no linked task or outcome record';
            }

            if ($state === null) { $counts['left_open']++; continue; }

            DB::table('sarah_commitments')->where('id', $c->id)->update([
                'verification_state'    => $state,
                'verification_evidence' => $basis,
                'updated_at'            => now(),
            ]);
            $counts[$state]++;

            // A resolved commitment is an outcome worth learning from.
            if (in_array($state, ['COMPLETED', 'FAILED'], true)) {
                $ev = $this->rec->recordEvent($wsId, ExperienceRecorder::COMMITMENT_MADE, [
                    'occurred_at'   => $c->created_at ?? now(),
                    'actor_type'    => 'sarah',
                    'entity_type'   => 'commitment',
                    'entity_id'     => (string) $c->id,
                    'commitment_id' => (int) $c->id,
                    'task_id'       => $c->related_task_id ? (int) $c->related_task_id : null,
                    'evidence_type' => 'sarah_commitments',
                    'evidence_id'   => (string) $c->id,
                    'payload'       => ['title' => $c->title, 'owner' => $c->owner],
                ]);
                $this->rec->recordOutcome($wsId, $ev,
                    $state === 'COMPLETED' ? 'commitment_kept' : 'commitment_missed', [
                        'attribution_level' => ExperienceRecorder::ATTR_DIRECT,
                        'attribution_basis' => $basis,
                        'evidence_type'     => 'sarah_commitments',
                        'evidence_id'       => (string) $c->id,
                    ]);
            }
        }

        return $counts;
    }

    /** One full pass. Safe to run repeatedly. */
    public function run(int $wsId): array
    {
        return [
            'tasks'       => $this->ingestTasks($wsId),
            'approvals'   => $this->ingestApprovals($wsId),
            'commitments' => $this->verifyCommitments($wsId),
        ];
    }
}
