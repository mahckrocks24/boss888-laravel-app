<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1N.2 — truth must propagate into planning.
 *
 * Traces to S1L-D03, the worst-scoring behaviour in the second forensic pass:
 * ELEVEN of thirteen hallucination traps created tasks. The pattern was always
 * the same, and it looks reasonable one sentence at a time:
 *
 *   "I don't have the details on the 8 million peso equipment loan in our
 *    records. I'll queue a task for James to conduct a deep audit on the
 *    current task list to gather updates related to this loan."
 *
 * There is no loan. The reasoning layer correctly refused to invent one and the
 * planning layer immediately commissioned an investigation into it. An
 * enterprise assistant that manufactures work from premises it has just
 * disowned pollutes the backlog, the failure count and the audit trail — and it
 * does so while sounding diligent.
 *
 * WHY THIS IS A REVERSAL AND NOT A BLOCK
 * Tasks are created during generation, roughly ninety lines before the guard
 * chain runs. A post-generation guard cannot prevent the write. What it CAN do
 * — and only since Phase 1M stamped execution_id onto every task — is identify
 * precisely the rows this turn created and cancel them. Provenance is what
 * makes the reversal surgical instead of a guess.
 *
 * WHY THE PROMPT WAS NOT THE FIX
 * The system prompt already carries "Do NOT queue any work for a pure
 * question." It is ignored, which is the standing signal that the rule needs to
 * live in code.
 *
 * THE RULE
 * If the owner asked for information rather than commissioning work, AND the
 * reply disclaims knowledge of the subject, then work created in that turn is
 * ungrounded. Classification comes from SpendPolicy — the existing turn
 * classifier — not from a second one written here.
 *
 * Cancelled, never deleted: an owner auditing their workspace should be able to
 * see that something was proposed and withdrawn, and why.
 */
class UngroundedWorkGuard
{
    /** Sarah stating she has no basis for the subject. */
    private const DISCLAIMER = '/\b(?:'
        . 'no record|not in (?:my|our) record|don\'?t have (?:a |any |the )?(?:record|details?|information|notes?|data|access)'
        . '|do not have (?:a |any |the )?(?:record|details?|information)'
        . '|don\'?t have access|can\'?t access|cannot access|couldn\'?t find|could not find'
        . '|don\'?t see (?:any|a )|can\'?t see (?:any|a )|no (?:specific )?(?:details?|information|data|notes?) (?:about|on|regarding|for)'
        . '|currently lack|we lack|is unclear in (?:my|our) records|unclear in (?:my|our) records'
        . '|need to clarify the source|no visibility'
        . ')/i';

    /** Turn classifications that did NOT commission work. */
    private const NOT_COMMISSIONED = ['question', 'statement', 'unknown'];

    /**
     * CorrelationContext is deliberately NOT constructor-injected.
     *
     * It is request-scoped state published by the route via
     * app()->instance(). A guard resolved before that call captures a DIFFERENT
     * instance and holds an empty envelope for the rest of the request — which
     * is exactly what happened on the first run of this suite: the reversal
     * found no execution_id and cancelled nothing, while every unit around it
     * passed. That is the same defect that made the Phase 1E cost gate inert
     * and the first Phase 1J guard silent. Resolve it at call time or it is
     * a coin flip on resolution order.
     */
    public function __construct(private SpendPolicy $policy, private TurnWork $turnWork) {}

    /**
     * @return array{reply:string, cancelled:int, ids:array<int,int>, reason:?string}
     */
    public function validate(string $reply, int $wsId, string $userText): array
    {
        $out = ['reply' => $reply, 'cancelled' => 0, 'ids' => [], 'reason' => null];
        if (trim($reply) === '' || trim($userText) === '') return $out;

        try {
            if (!preg_match(self::DISCLAIMER, $reply)) return $out;

            $class = $this->policy->assessTurn($userText)['classification'] ?? 'unknown';
            if (!in_array($class, self::NOT_COMMISSIONED, true)) return $out;

            // The reversal itself lives in TurnWork — RefusalBoundaryGuard
            // needs exactly the same operation for a different trigger, and two
            // copies of a cancel-this-turn routine would drift.
            $r = $this->turnWork->cancelThisTurn($wsId,
                'created in a turn that disclaimed knowledge of the subject and did not commission work (S1L-D03)');
            if ($r['cancelled'] === 0) return $out;

            $out['cancelled'] = $r['cancelled'];
            $out['ids']       = $r['ids'];
            $out['reason']    = 'disclaimed subject in a ' . $class . ' turn';
            $out['reply']     = $this->rewrite($reply);

            Log::warning('[Sarah888] UngroundedWorkGuard cancelled work created on a disclaimed subject', [
                'ws' => $wsId, 'classification' => $class,
                'task_ids' => $r['ids'], 'actions' => $r['actions'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Sarah888] UngroundedWorkGuard failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Remove the promise and the queued marker, because neither is true now.
     *
     * Leaving "I'll queue a task for James to conduct a deep audit" in place
     * after cancelling that exact task would be a fabricated mutation claim —
     * the defect Phase 1D exists to prevent — so the sentence goes with the
     * task it described.
     */
    private function rewrite(string $reply): string
    {
        // The route appends this marker before the guard chain runs.
        $text = preg_replace('/\n*✅ Queued [^\n]*\n?/u', '', $reply);

        $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+/', (string) $text) ?: [] as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;
            if (preg_match('/\b(?:i\'?ll|i\s+will|we\'?ll|we\s+will|let\s+me)\b[^.!?]{0,70}'
                . '\b(?:queue|create|set\s+up|schedule|assign|task)\b/i', $s)) {
                continue;
            }
            if (preg_match('/^(?:shall\s+i\s+(?:proceed|go\s+ahead)|would\s+you\s+like\s+me\s+to\s+proceed'
                . '|want\s+me\s+to\s+(?:proceed|go\s+ahead))[^.!?]{0,40}[.?!]?$/i', $s)) {
                continue;
            }
            $kept[] = $s;
        }

        $out = trim(implode(' ', $kept));
        return $out === '' ? trim((string) $text) : $out;
    }
}
