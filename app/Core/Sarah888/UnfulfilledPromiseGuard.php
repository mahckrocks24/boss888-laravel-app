<?php

namespace App\Core\Sarah888;

/**
 * UnfulfilledPromiseGuard — SF-05 (REPORT-0024 / RISK-0130, 2026-09-01).
 *
 * Transcript, scratch ws 999993, 19:26 UTC: "I'll fetch the list of pages for Fable QA Cafe Two now. Please hold
 * on a moment while I get that information for you. I couldn't start this: • that action isn't available yet."
 * Nothing ran. CompletionGuard catches claims that work WAS done; nothing caught a promise that work is ABOUT
 * to be done. A promise the record cannot back is the same lie in the future tense.
 *
 * Deterministic. When nothing executed on the turn (no task created, no tool run), sentences that promise
 * imminent work or ask the owner to wait are removed. What remains — the answer, the truthful refusal — stays.
 * When something did execute, the reply is untouched: "I'll have that for you shortly" after a queued task is true.
 *
 * 2026-09-02 (run 2, turn 6): "I'll proceed with listing the pages for the site now" slipped through — "proceed"
 * was not in the verb list. Added proceed/begin/initiate/handle/dive/move forward/go ahead/take care.
 */
final class UnfulfilledPromiseGuard
{
    public const NOTHING_RAN = "I can't do that from here right now — nothing was run for it.";

    private const WORK_VERBS = 'fetch|check|pull|get|grab|look|gather|retrieve|run|start|queue|kick|publish|generate|write|build|create|schedule|update|review|analy[sz]e|audit|dig|find|search|compile|prepare|process|verify|confirm|proceed|begin|initiate|handle|dive|move forward|go ahead|take care|list|show|display|summari[sz]e|report|read|outline|draft|send|post|share|put together|set up|reach out|follow up';

    private const WORK_VERBS_ING = 'fetching|checking|pulling|getting|grabbing|looking|gathering|retrieving|running|starting|queuing|publishing|generating|writing|building|creating|scheduling|updating|reviewing|analy[sz]ing|auditing|searching|compiling|preparing|processing|verifying|confirming|proceeding|beginning|initiating|handling|diving|moving forward|going ahead|taking care|listing|showing|displaying|summari[sz]ing|reporting|reading|outlining|drafting|sending|posting|sharing|putting together|setting up|reaching out|following up';

    /**
     * @return array{reply:string, corrected:bool, removed:string[]}
     */
    public function validate(string $reply, bool $anythingExecuted): array
    {
        $reply = trim($reply);
        if ($anythingExecuted || $reply === '') {
            return ['reply' => $reply, 'corrected' => false, 'removed' => []];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $reply) ?: [$reply];
        $kept = [];
        $removed = [];
        foreach ($parts as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;
            if ($this->isPromise($s)) { $removed[] = $s; continue; }
            $kept[] = $s;
        }
        if (!$removed) {
            return ['reply' => $reply, 'corrected' => false, 'removed' => []];
        }
        $out = trim(implode(' ', $kept));
        if ($out === '') $out = self::NOTHING_RAN;
        return ['reply' => $out, 'corrected' => true, 'removed' => $removed];
    }

    private function patterns(): array
    {
        $v = self::WORK_VERBS;
        $ving = self::WORK_VERBS_ING;
        return [
            // "I'll fetch…", "let me check…", "I'm going to pull…", "I'll proceed with…"
            "/\\b(I['’]ll|I will|I['’]m going to|I am going to|I['’]m about to|let me)\\s+(now\\s+|just\\s+|quickly\\s+|go\\s+(ahead\\s+)?and\\s+)?({$v})\\b/iu",
            // "I'm fetching…", "I am now checking…"
            "/\\bI(?:['’]m| am)\\s+(now\\s+|currently\\s+|just\\s+)?({$ving})\\b/iu",
            // "Please hold on", "one moment", "bear with me", "give me a sec"
            '/\b(please\s+)?(hold on|hang on|hang tight|one moment|just a moment|one sec|bear with me|give me a (moment|sec|second|minute|few minutes)|please wait|stand by|sit tight)\b/iu',
            // "…while I get that information for you"
            '/\bwhile I (get|fetch|pull|check|gather|retrieve|look)\b/iu',
        ];
    }

    private function isPromise(string $sentence): bool
    {
        // "Let me know…" is an invitation, not a promise.
        if (preg_match('/\blet me know\b/iu', $sentence)) return false;
        // A truthful statement of a limit is never a promise, even with "I'll" in it ("I can't … but I'll flag it").
        if (preg_match('/\b(can[\'’]t|cannot|couldn[\'’]t|unable to|isn[\'’]t available|not available)\b/iu', $sentence)) return false;
        foreach ($this->patterns() as $rx) {
            if (preg_match($rx, $sentence)) return true;
        }
        return false;
    }
}
