<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;

/**
 * EXPERIENCE888 — playbook promotion, application and demotion.
 *
 * A playbook is promoted experience, not remembered prose. Promotion is gated on
 * the evidence behind a pattern, never on a model deciding something sounds like
 * a good rule. The lifecycle is:
 *
 *   CANDIDATE -> ACTIVE -> DEGRADED -> DEPRECATED
 *                       \-> SUPERSEDED
 *
 * A playbook that stops working is demoted by its own run history. Nothing here
 * can authorise anything: a playbook describes what to propose, and the existing
 * two-turn authorization contract still decides whether it may happen.
 */
final class PlaybookGovernor
{
    public const CANDIDATE  = 'CANDIDATE';
    public const ACTIVE     = 'ACTIVE';
    public const DEGRADED   = 'DEGRADED';
    public const DEPRECATED = 'DEPRECATED';
    public const SUPERSEDED = 'SUPERSEDED';

    /** A pattern must be at least this strong before it can back a playbook. */
    public const PROMOTE_MIN_SAMPLE = 6;

    /** Consecutive-equivalent failure share that degrades an ACTIVE playbook. */
    public const DEGRADE_FAILURE_RATIO = 0.4;
    public const DEPRECATE_FAILURE_RATIO = 0.6;
    public const MIN_RUNS_BEFORE_DEMOTION = 3;

    /**
     * Promote a pattern into a playbook candidate, or activate it outright when
     * the evidence already justifies it.
     *
     * Refuses when the pattern is not strong enough. The refusal is the feature:
     * it is what stops one good week becoming a permanent rule.
     */
    public function promoteFromPattern(int $wsId, int $patternId, array $spec): array
    {
        $p = DB::table('experience_patterns')
            ->where('id', $patternId)->where('workspace_id', $wsId)->first();
        if (!$p) throw new \RuntimeException("Experience888: pattern {$patternId} not in workspace {$wsId}");

        if (!in_array($p->status, [ExperienceConfidence::STATUS_PATTERN, ExperienceConfidence::STATUS_EXPERIENCE], true))
            return ['promoted' => false, 'reason' => "pattern status is {$p->status}; only PATTERN or EXPERIENCE may be promoted"];

        if ((int) $p->sample_size < self::PROMOTE_MIN_SAMPLE)
            return ['promoted' => false, 'reason' => "sample size {$p->sample_size} is below the promotion floor of " . self::PROMOTE_MIN_SAMPLE];

        if ($p->confidence_level === ExperienceConfidence::LOW)
            return ['promoted' => false, 'reason' => 'confidence is LOW; a playbook needs at least MEDIUM'];

        $status = $p->confidence_level === ExperienceConfidence::HIGH ? self::ACTIVE : self::CANDIDATE;

        $existing = DB::table('experience_playbooks')
            ->where('workspace_id', $wsId)->where('name', $spec['name'])
            ->orderByDesc('version')->first();
        $version = $existing ? ((int) $existing->version + 1) : 1;
        if ($existing) {
            DB::table('experience_playbooks')->where('id', $existing->id)
                ->update(['status' => self::SUPERSEDED, 'updated_at' => now()]);
        }

        $id = (int) DB::table('experience_playbooks')->insertGetId([
            'workspace_id'             => $wsId,
            'name'                     => $spec['name'],
            'trigger_type'             => $spec['trigger_type'] ?? 'manual',
            'objective'                => $spec['objective'] ?? null,
            'preconditions_json'       => json_encode($spec['preconditions'] ?? []),
            'steps_json'               => json_encode($spec['steps'] ?? []),
            'expected_outcome_json'    => json_encode($spec['expected_outcome'] ?? []),
            'risks_json'               => json_encode($spec['risks'] ?? []),
            'reversal_conditions_json' => json_encode($spec['reversal_conditions'] ?? []),
            'evidence_count'           => (int) $p->sample_size,
            'confidence_level'         => $p->confidence_level,
            'status'                   => $status,
            'version'                  => $version,
            'last_validated_at'        => now(),
            'supersedes_id'            => $existing->id ?? null,
            'derived_from_pattern_id'  => $patternId,
            'created_at'               => now(), 'updated_at' => now(),
        ]);

        return ['promoted' => true, 'playbook_id' => $id, 'status' => $status, 'version' => $version,
                'reason' => "backed by pattern {$patternId}: {$p->sample_size} observations, {$p->confidence_level} confidence"];
    }

    /** Record an application. Returns the run id. */
    public function apply(int $wsId, int $playbookId, array $a = []): int
    {
        $pb = DB::table('experience_playbooks')
            ->where('id', $playbookId)->where('workspace_id', $wsId)->first();
        if (!$pb) throw new \RuntimeException("Experience888: playbook {$playbookId} not in workspace {$wsId}");

        if (in_array($pb->status, [self::DEPRECATED, self::SUPERSEDED], true))
            throw new \RuntimeException("Experience888: playbook {$playbookId} is {$pb->status} and must not be applied");

        return (int) DB::table('experience_playbook_runs')->insertGetId([
            'workspace_id'    => $wsId,
            'playbook_id'     => $playbookId,
            'conversation_id' => $a['conversation_id'] ?? null,
            'execution_id'    => $a['execution_id'] ?? null,
            'applied_at'      => $a['applied_at'] ?? now(),
            'result'          => 'pending',
            'notes'           => $a['notes'] ?? null,
            'created_at'      => now(), 'updated_at' => now(),
        ]);
    }

    /** Resolve a run, then let the run history decide the playbook's standing. */
    public function resolveRun(int $wsId, int $runId, string $result, ?int $outcomeId = null): array
    {
        if (!in_array($result, ['success', 'failure', 'abandoned'], true))
            throw new \InvalidArgumentException("Experience888: unknown run result {$result}");

        $run = DB::table('experience_playbook_runs')
            ->where('id', $runId)->where('workspace_id', $wsId)->first();
        if (!$run) throw new \RuntimeException("Experience888: run {$runId} not in workspace {$wsId}");

        DB::table('experience_playbook_runs')->where('id', $runId)->update([
            'result' => $result, 'outcome_id' => $outcomeId, 'updated_at' => now(),
        ]);

        return $this->reassess($wsId, (int) $run->playbook_id);
    }

    /**
     * Recount runs and set status. Counts come from the run rows, never from an
     * incremented column, so the standing always matches the evidence.
     */
    public function reassess(int $wsId, int $playbookId): array
    {
        $pb = DB::table('experience_playbooks')
            ->where('id', $playbookId)->where('workspace_id', $wsId)->first();
        if (!$pb) throw new \RuntimeException("Experience888: playbook {$playbookId} not in workspace {$wsId}");

        $runs = DB::table('experience_playbook_runs')
            ->where('playbook_id', $playbookId)->where('workspace_id', $wsId)
            ->whereIn('result', ['success', 'failure'])->get();

        $success = $runs->where('result', 'success')->count();
        $failure = $runs->where('result', 'failure')->count();
        $total   = $success + $failure;

        $status = $pb->status;
        $why = 'unchanged';

        if ($status !== self::SUPERSEDED && $total >= self::MIN_RUNS_BEFORE_DEMOTION) {
            $failRatio = $failure / $total;
            if ($failRatio >= self::DEPRECATE_FAILURE_RATIO) {
                $status = self::DEPRECATED;
                $why = "deprecated: {$failure} of {$total} applications failed";
            } elseif ($failRatio >= self::DEGRADE_FAILURE_RATIO) {
                $status = self::DEGRADED;
                $why = "degraded: {$failure} of {$total} applications failed";
            } elseif ($status === self::CANDIDATE && $success >= self::MIN_RUNS_BEFORE_DEMOTION) {
                $status = self::ACTIVE;
                $why = "activated: {$success} successful applications";
            }
        }

        $conf = ExperienceConfidence::evaluate($success, $failure, 0, null);

        DB::table('experience_playbooks')->where('id', $playbookId)->update([
            'success_count'     => $success,
            'failure_count'     => $failure,
            'status'            => $status,
            'confidence_level'  => $conf['level'],
            'last_validated_at' => now(),
            'updated_at'        => now(),
        ]);

        return ['status' => $status, 'success' => $success, 'failure' => $failure,
                'confidence' => $conf['level'], 'reason' => $why];
    }

    /** Playbooks Sarah may actually cite. DEGRADED is included but flagged. */
    public function usable(int $wsId, ?string $trigger = null, int $limit = 3): array
    {
        $q = DB::table('experience_playbooks')
            ->where('workspace_id', $wsId)
            ->whereIn('status', [self::ACTIVE, self::DEGRADED]);
        if ($trigger) $q->where('trigger_type', $trigger);

        return $q->orderByRaw("FIELD(status,'ACTIVE','DEGRADED')")
                 ->orderByRaw("FIELD(confidence_level,'HIGH','MEDIUM','LOW')")
                 ->limit($limit)->get()->all();
    }
}
