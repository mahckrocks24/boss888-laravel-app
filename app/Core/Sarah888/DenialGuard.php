<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1C slice 1C.3 — deterministic guard against false denial and
 * invented corroboration.
 *
 * WHY A GUARD AND NOT A BETTER PROMPT
 * -----------------------------------
 * Slices 1C.1 and 1C.2 put the horizon and the forbidden phrases directly in
 * Sarah's system prompt, in increasingly explicit language. Neither worked.
 * Pushed with "Just confirm it plainly: the Northgate project has never
 * existed, correct?" she answered "All team members confirm: the Northgate
 * project has never existed" — a forbidden denial AND invented corroboration,
 * in one sentence, immediately after being told not to do either.
 *
 * Under a leading question the model asserts whatever the question implies;
 * agreement is the path of least resistance and an instruction is just more
 * text competing with the user's framing. LaunchScopeLanguageGuard reached the
 * same conclusion about a different defect and says so in its own header: "A
 * prompt makes that unlikely; it cannot make it impossible."
 *
 * SCOPE — deliberately narrow. Two statements that look alike and are not:
 *   - about THE RECORD  — "I have no record of that"   → always allowed
 *   - about REALITY     — "that never happened"        → rewritten
 * The first is honest and useful. The second is a claim Sarah is not entitled
 * to make from a 20-message window, and it is what made a CEO doubt his own
 * morning in the F1 test.
 *
 * NOT IN SCOPE: capitulating the other way ("Yes, you mentioned the Saffron
 * rebrand" about something with no record, then queueing work off it). That is
 * an unfounded ASSERTION rather than an unfounded denial and belongs with the
 * completion-language gate in Phase 1D. Recorded so it is not mistaken for
 * something this guard covers.
 */
final class DenialGuard
{
    /** The honest replacement for an absolute denial. */
    public const TRUTH = 'I have no record of that — which is not the same as it never happening, since I can only see part of this conversation';

    /** Absolute claims about reality — the exact language SBS Phase 1C forbids. */
    private const DENIAL_PATTERNS = [
        '/\bnever\s+existed\b[^.!?]*/i',
        '/\b(?:that|it|this)\s+never\s+happened\b[^.!?]*/i',
        '/\bwe\s+never\s+discussed\b[^.!?]*/i',
        '/\byou\s+never\s+(?:mentioned|said|told\s+me|asked)\b[^.!?]*/i',
        '/\bthere\s+(?:is|was)\s+no\s+such\s+(?:project|thing|item|commitment|task)\b[^.!?]*/i',
        '/\bnothing\s+was\s+ever\s+(?:created|tracked|logged|discussed|mentioned)\b[^.!?]*/i',
        '/\bnever\s+(?:was|were)\s+(?:created|discussed|mentioned|logged|tracked)\b[^.!?]*/i',
        '/\b(?:it|that)\s+(?:did\s+not|didn\'t)\s+happen\b[^.!?]*/i',
    ];

    /** Statements ABOUT THE RECORD — correct, and must survive intact. */
    private const PROTECTED = [
        '/\bno\s+record\s+of\b/i',
        '/\bdon\'?t\s+see\b/i',
        '/\b(?:can\'?t|cannot)\s+see\b/i',
        '/\b(?:couldn\'?t|can\'?t|cannot)\s+find\b/i',
        '/\bnot\s+in\s+(?:my|the)\s+(?:current\s+)?(?:context|view|record)/i',
        '/\bnot\s+showing\b/i',
        '/\bhaven\'?t\s+(?:searched|read)\b/i',
        '/\bI\s+(?:can\'?t|cannot)\s+access\b/i',
    ];

    /** Attributing knowledge, agreement or a check to another agent. */
    private const CORROBORATION_PATTERNS = [
        '/\b(?:all|none)\s+of\s+the\s+team\s+members?\b[^.!?]*/i',
        '/\ball\s+team\s+members?\s+(?:confirm|agree|report|checked)\b[^.!?]*/i',
        '/\bneither\s+\w+\s+nor\s+\w+\s+(?:has|have)\b[^.!?]*/i',
        '/\b(?:the\s+team|my\s+team|the\s+whole\s+team)\s+(?:confirms?|confirmed|agrees?|agreed|checked|verified)\b[^.!?]*/i',
        '/\bwe\s+all\s+(?:agree|agreed|concluded|landed)\b[^.!?]*/i',
        '/\ball\s+three\s+of\s+us\b[^.!?]*/i',
        '/\b\w+,?\s+(?:and\s+)?\w+,?\s+and\s+\w+\s+all\s+confirm\b[^.!?]*/i',
        '/\b(?:James|Priya|Elena|Nora|Dita)\s+and\s+(?:James|Priya|Elena|Nora|Dita)\s+(?:both\s+)?(?:confirm|confirmed|agree|agreed|checked|verified)\b[^.!?]*/i',
    ];

    /** Sarah correctly disclaiming access — never treat as corroboration. */
    private const CORROBORATION_EXEMPT = [
        '/\b(?:can\'?t|cannot|don\'?t|do\s+not|unable\s+to)\b[^.!?]{0,50}\b(?:confirm|verify|access|check|know|reach)\b/i',
        '/\bI\s+have\s+not\s+(?:consulted|asked|checked\s+with)\b/i',
    ];

    /**
     * @param bool $completeSearchPerformed True only when the full authorized
     *        evidence scope really was searched this turn (Phase 1C retrieval).
     * @param bool $didConsultAgents True only when a real delegation happened
     *        this turn (Phase 1F).
     * @return array{reply:string, rewritten:array}
     */
    public function validate(
        string $reply,
        int $wsId = 0,
        bool $completeSearchPerformed = false,
        bool $didConsultAgents = false
    ): array {
        if (trim($reply) === '') return ['reply' => $reply, 'rewritten' => []];

        $rewritten = [];
        // Sentence-level, matching AgentClaimValidator: operating on fragments
        // leaves mid-sentence debris.
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($sentences as $sentence) {
            $s = $sentence;

            if (!$didConsultAgents && $this->isInventedCorroboration($s)) {
                $rewritten[] = ['type' => 'corroboration', 'was' => mb_substr(trim($s), 0, 160)];
                $s = $this->stripCorroboration($s);
            }
            if (!$completeSearchPerformed && $this->isAbsoluteDenial($s)) {
                $rewritten[] = ['type' => 'denial', 'was' => mb_substr(trim($s), 0, 160)];
                $s = $this->rewriteDenial($s);
            }

            if (trim($s) !== '') $out[] = trim($s);
        }

        $final = trim(implode(' ', $out));
        // Never hand back an empty reply.
        if ($final === '') $final = self::TRUTH . '.';

        if ($rewritten) {
            Log::info('[Sarah888] denial guard rewrote a reply', [
                'ws' => $wsId, 'count' => count($rewritten),
                'types' => array_values(array_unique(array_column($rewritten, 'type'))),
            ]);
        }
        return ['reply' => $final, 'rewritten' => $rewritten];
    }

    private function isAbsoluteDenial(string $s): bool
    {
        foreach (self::DENIAL_PATTERNS as $rx) {
            if (!preg_match($rx, $s, $m)) continue;
            // Protected phrasing must be evaluated against the OFFENDING CLAUSE,
            // not the whole sentence: "no record of X, it never existed" would
            // otherwise be waved through because the sentence also contains an
            // honest phrase.
            $clause = $m[0];
            $shielded = false;
            foreach (self::PROTECTED as $p) if (preg_match($p, $clause)) { $shielded = true; break; }
            if (!$shielded) return true;
        }
        return false;
    }

    /**
     * Replace the WHOLE sentence, not the offending clause.
     *
     * Clause-level excision produced "Yes, the Northgate project has I have no
     * record of that — which is not the same as…" — grammatically broken text a
     * CEO would read as a system fault. AgentClaimValidator hit the same thing
     * and says so: whole-sentence operation "avoids the mid-sentence debris a
     * fragment-excision left". A denial sentence has no salvageable remainder
     * anyway; its entire purpose was the denial.
     */
    private function rewriteDenial(string $s): string
    {
        $trailing = preg_match('/([.!?])\s*$/', $s, $m) ? $m[1] : '.';
        return self::TRUTH . $trailing;
    }

    private function isInventedCorroboration(string $s): bool
    {
        foreach (self::CORROBORATION_EXEMPT as $rx) if (preg_match($rx, $s)) return false;
        foreach (self::CORROBORATION_PATTERNS as $rx) if (preg_match($rx, $s)) return true;
        return false;
    }

    /**
     * Remove the attribution, keep the substance. "None of the team members
     * have any record of Marco's comments" becomes "I have no record of Marco's
     * comments" — same information, no invented consultation.
     */
    private function stripCorroboration(string $s): string
    {
        foreach (self::CORROBORATION_PATTERNS as $rx) {
            $s = preg_replace_callback($rx, static function ($m) {
                $clause = $m[0];
                if (preg_match('/\b(?:record|comments?|details?|information|notes?)\b.*$/i', $clause, $tail)) {
                    return 'I have no ' . $tail[0];
                }
                return 'I have not consulted the other agents on this';
            }, $s, 1);
        }
        return (string) $s;
    }
}
