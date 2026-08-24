<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;

/**
 * EXPERIENCE888 — pattern formation, contradiction and decay.
 *
 * A pattern is a claim about a repeated relationship, and it is only ever as
 * good as the evidence rows linked to it. Every observation is stored in
 * experience_pattern_evidence, so any pattern can be rebuilt from its evidence
 * and any claim Sarah makes can be traced back to the rows that justify it.
 *
 * Nothing here promotes itself. Counts change, then confidence is recomputed
 * deterministically by ExperienceConfidence, then status follows from that.
 */
final class PatternEngine
{
    public const TYPE_ACTION_RECOVERY  = 'action_recovery';
    public const TYPE_PUBLISH_OUTCOME  = 'publish_outcome';
    public const TYPE_OWNER_PREFERENCE = 'owner_preference';
    public const TYPE_FAILURE_CLASS    = 'failure_class';

    /**
     * Add one observation to a pattern, creating it if new, then re-evaluate.
     *
     * @param string $direction 'supports' or 'contradicts'
     */
    public function observe(
        int $wsId,
        string $patternType,
        string $subject,
        string $direction,
        array $a = []
    ): int {
        if ($wsId <= 0) throw new \InvalidArgumentException('Experience888: workspace_id is required');
        if (!in_array($direction, ['supports', 'contradicts'], true))
            throw new \InvalidArgumentException("Experience888: direction must be supports|contradicts");

        $observedAt = $a['observed_at'] ?? now();

        $pattern = DB::table('experience_patterns')
            ->where('workspace_id', $wsId)
            ->where('pattern_type', $patternType)
            ->where('subject', $subject)
            ->first();

        if (!$pattern) {
            $id = (int) DB::table('experience_patterns')->insertGetId([
                'workspace_id'      => $wsId,
                'pattern_type'      => $patternType,
                'subject'           => $subject,
                'statement'         => $a['statement'] ?? "{$patternType} for {$subject}",
                'payload_json'      => isset($a['payload']) ? json_encode($a['payload']) : null,
                'first_observed_at' => $observedAt,
                'last_observed_at'  => $observedAt,
                'status'            => ExperienceConfidence::STATUS_HYPOTHESIS,
                'confidence_level'  => ExperienceConfidence::LOW,
                'created_at'        => now(), 'updated_at' => now(),
            ]);
            $pattern = DB::table('experience_patterns')->where('id', $id)->first();
        }

        // Evidence rows are unique per (pattern, event, outcome), so re-running
        // the ingestor cannot inflate a pattern's sample size.
        $eventId   = $a['event_id'] ?? null;
        $outcomeId = $a['outcome_id'] ?? null;
        $already = DB::table('experience_pattern_evidence')
            ->where('pattern_id', $pattern->id)
            ->where('event_id', $eventId)
            ->where('outcome_id', $outcomeId)
            ->exists();

        if (!$already) {
            DB::table('experience_pattern_evidence')->insert([
                'workspace_id' => $wsId,
                'pattern_id'   => $pattern->id,
                'event_id'     => $eventId,
                'outcome_id'   => $outcomeId,
                'direction'    => $direction,
                'weight'       => $a['weight'] ?? 1.0,
                'observed_at'  => $observedAt,
                'created_at'   => now(), 'updated_at' => now(),
            ]);
        }

        return $this->reevaluate($wsId, (int) $pattern->id);
    }

    /**
     * Recount from evidence and recompute confidence/status.
     *
     * Counts are always derived from the evidence table, never incremented in
     * place - a counter that drifts from its evidence is how a pattern ends up
     * claiming more support than it has.
     */
    public function reevaluate(int $wsId, int $patternId): int
    {
        $pattern = DB::table('experience_patterns')
            ->where('id', $patternId)->where('workspace_id', $wsId)->first();
        if (!$pattern) throw new \RuntimeException("Experience888: pattern {$patternId} not in workspace {$wsId}");

        $rows = DB::table('experience_pattern_evidence')
            ->where('pattern_id', $patternId)->where('workspace_id', $wsId)->get();

        $positive = $negative = 0;
        $first = $last = null;
        foreach ($rows as $r) {
            if ($r->direction === 'supports') $positive++; else $negative++;
            $t = strtotime((string) $r->observed_at);
            $first = $first === null ? $t : min($first, $t);
            $last  = $last  === null ? $t : max($last, $t);
        }

        $ageDays = $last === null ? null : (int) floor((time() - $last) / 86400);
        // `direction` already encodes support vs contradiction, so the negative
        // count IS the contradiction count. Passing it twice would penalise the
        // same evidence in both channels.
        $verdict = ExperienceConfidence::evaluate($positive, $negative, 0, $ageDays);

        DB::table('experience_patterns')->where('id', $patternId)->update([
            'sample_size'         => $positive + $negative,
            'positive_count'      => $positive,
            'negative_count'      => $negative,
            'contradiction_count' => $negative,
            'first_observed_at'   => $first ? date('Y-m-d H:i:s', $first) : null,
            'last_observed_at'    => $last ? date('Y-m-d H:i:s', $last) : null,
            'last_evaluated_at'   => now(),
            'confidence_level'    => $verdict['level'],
            'status'              => $verdict['status'],
            'updated_at'          => now(),
        ]);

        return $patternId;
    }

    /** Re-evaluate every pattern in a workspace, so ageing takes effect. */
    public function decaySweep(int $wsId): array
    {
        $changed = [];
        foreach (DB::table('experience_patterns')->where('workspace_id', $wsId)->get(['id','confidence_level','status']) as $p) {
            $before = $p->confidence_level . '/' . $p->status;
            $this->reevaluate($wsId, (int) $p->id);
            $after = DB::table('experience_patterns')->where('id', $p->id)->first(['confidence_level','status']);
            $now = $after->confidence_level . '/' . $after->status;
            if ($before !== $now) $changed[(int) $p->id] = "{$before} -> {$now}";
        }
        return $changed;
    }

    /**
     * Patterns worth telling Sarah about, strongest first.
     * Hypotheses and stale patterns are excluded: she must not assert them.
     */
    public function usable(int $wsId, ?string $type = null, int $limit = 5): array
    {
        $q = DB::table('experience_patterns')
            ->where('workspace_id', $wsId)
            ->whereIn('status', [ExperienceConfidence::STATUS_PATTERN, ExperienceConfidence::STATUS_EXPERIENCE]);
        if ($type) $q->where('pattern_type', $type);

        return $q->orderByRaw("FIELD(confidence_level,'HIGH','MEDIUM','LOW')")
                 ->orderByDesc('sample_size')
                 ->limit($limit)->get()->all();
    }

    /** The evidence behind a pattern - this is what "why do you think that?" answers. */
    public function explain(int $wsId, int $patternId): array
    {
        $p = DB::table('experience_patterns')
            ->where('id', $patternId)->where('workspace_id', $wsId)->first();
        if (!$p) return [];

        $ev = DB::table('experience_pattern_evidence')
            ->where('pattern_id', $patternId)->where('workspace_id', $wsId)
            ->orderBy('observed_at')->get();

        $age = $p->last_observed_at ? (int) floor((time() - strtotime((string) $p->last_observed_at)) / 86400) : null;
        // Must match reevaluate() exactly. contradiction_count mirrors
        // negative_count, so passing it as a third channel double-counted the
        // same evidence and made explain() report a number that never happened.
        $verdict = ExperienceConfidence::evaluate(
            (int) $p->positive_count, (int) $p->negative_count, 0, $age);

        return [
            'statement'   => $p->statement,
            'status'      => $p->status,
            'confidence'  => $p->confidence_level,
            'why'         => $verdict['reason'],
            'sample_size' => (int) $p->sample_size,
            'supporting'  => (int) $p->positive_count,
            'contradicting' => (int) $p->negative_count,
            'first_observed' => $p->first_observed_at,
            'last_observed'  => $p->last_observed_at,
            'evidence'    => array_map(fn($e) => [
                'direction' => $e->direction, 'event_id' => $e->event_id,
                'outcome_id' => $e->outcome_id, 'observed_at' => $e->observed_at,
            ], $ev->all()),
            'would_change_my_mind' => $p->status === ExperienceConfidence::STATUS_EXPERIENCE
                ? 'Contradicting outcomes reaching ' . $p->positive_count . ' would overturn this.'
                : 'More consistent observations would raise confidence; contradictions would lower it.',
        ];
    }
}
