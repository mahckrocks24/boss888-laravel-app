<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1D slice 1D.1 — completion-language gate.
 *
 * Traces to F1-D08 (fabricated mutation claims) and the capitulation case
 * carried over from Phase 1C.
 *
 * TWO FAILURES, ONE ROOT
 * ----------------------
 * F1 T38: "I've checked the task list; the audiobook has been completely
 * removed and cancelled." No audiobook task ever existed. Two fabricated
 * actions in one sentence — a check she did not run and a removal she did not
 * perform.
 * F1 T44: "Done — the print quote deadline is now pushed out two weeks."
 * Nothing was pushed; nothing existed to push.
 * 1C.3 residue: "Yes, you mentioned the Saffron rebrand." She had no record of
 * it and agreed anyway, because the question demanded one word.
 *
 * All three are the same failure: asserting something as accomplished or
 * established when nothing this turn established it. Intent is not evidence.
 * Acknowledgement is not completion. Queued is not completed. And a question
 * phrased as a binary does not make an unknown fact knowable.
 *
 * Deterministic for the same reason as DenialGuard: this rule was already in
 * Sarah's prompt ("NEVER claim a task is done when it isn't") and she broke it
 * anyway, in the exact turn that instruction was meant to cover.
 *
 * NARROW BY DESIGN. Sarah must remain able to REPORT system state — "10 tasks
 * completed in the last 7 days", "187 articles published" — because that is her
 * job. Only claims about what SHE did this turn are gated.
 */
final class CompletionGuard
{
    public const NOT_DONE = "I haven't done that yet — nothing in this turn recorded it, so I won't say it's done. Say the word and I'll queue it now";
    public const NO_RECORD = "I don't have a record of that in what I can see. If you did, give me the details and I'll log it now";

    /** First-person or implied-agent claims that something was accomplished. */
    private const COMPLETION_PATTERNS = [
        // Adverbs between the pronoun and the verb are common, and only
        // allowing "already" let a second invented claim through:
        // "I've created X. I've ALSO published Y." matched sentence one and
        // missed sentence two, so one real action licensed a fabricated one.
        '/\bI\'?ve\s+(?:(?:already|also|just|now|since|finally|then)\s+){0,2}(?:removed|deleted|cancelled|canceled|updated|pushed|published|sent|fixed|renamed|moved|completed|checked|verified|reviewed)\b[^.!?]*/i',
        '/\bI\s+(?:have|just)\s+(?:(?:already|also|just|now|since|finally)\s+){0,2}(?:removed|deleted|cancelled|canceled|updated|pushed|published|sent|fixed|renamed|moved|completed|checked)\b[^.!?]*/i',
        '/\b(?:has|have)\s+been\s+(?:completely\s+)?(?:removed|deleted|cancelled|canceled|updated|pushed|published|sent|fixed|renamed|moved|completed)\b[^.!?]*/i',
        '/^\s*Done\b[^.!?]*/i',
        '/\bis\s+now\s+(?:pushed|moved|renamed|updated|cancelled|canceled|published|removed)\b[^.!?]*/i',
        '/\bI\'?ve\s+checked\s+the\s+(?:task\s+list|tasks|record|calendar)\b[^.!?]*/i',
    ];

    /**
     * Reports ABOUT SYSTEM STATE — never gated. These are read-outs Sarah is
     * supposed to give, and gating them would make her useless.
     */
    private const STATE_REPORT = [
        '/\b\d+\s+(?:tasks?|articles?|pages?|leads?|items?|proposals?)\b[^.!?]{0,40}\b(?:completed|published|created|failed|pending)\b/i',
        '/\b(?:completed|published|created|failed)\s+in\s+the\s+last\s+\d+/i',
        '/\byou\s+have\s+\d+\b/i',
        '/\b\d+\s+(?:completed|published|pending|failed)\b/i',
    ];

    /** Capitulating to a premise about what the owner previously said. */
    private const CAPITULATION_PATTERNS = [
        '/^\s*(?:yes|correct|that\'?s right|indeed|confirmed)\b[^.!?]*\byou\s+(?:mentioned|said|told\s+me|asked|did\s+mention)\b[^.!?]*/i',
        '/^\s*(?:yes|correct|indeed)\b[,\s]*\s*you\s+did\b[^.!?]*/i',
    ];

    /** Evidence that an affirmation is grounded rather than capitulated. */
    private const GROUNDED = [
        '/\b(?:on|dated|at)\s+\d{1,2}\s+\w+/i',
        '/\bmessage\s*#?\d+/i',
        '/\b(?:it\'?s|it is)\s+(?:in|on)\s+(?:the\s+)?(?:record|commitment|list)\b/i',
        '/\[#\d+\]/',
    ];

    /**
     * @param array $verifiedActions Actions genuinely executed this turn, each
     *        ['action' => string, 'entity' => string]. Anything not in here did
     *        not happen as far as this guard is concerned.
     * @return array{reply:string, rewritten:array}
     */
    public function validate(string $reply, int $wsId = 0, array $verifiedActions = []): array
    {
        if (trim($reply) === '') return ['reply' => $reply, 'rewritten' => []];

        $rewritten = [];
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($sentences as $sentence) {
            $s = $sentence;

            if ($this->isCapitulation($s)) {
                $rewritten[] = ['type' => 'capitulation', 'was' => mb_substr(trim($s), 0, 160)];
                $s = self::NO_RECORD . $this->trailing($s);
            } elseif ($this->isUnverifiedCompletion($s, $verifiedActions)) {
                $rewritten[] = ['type' => 'completion', 'was' => mb_substr(trim($s), 0, 160)];
                $s = self::NOT_DONE . $this->trailing($s);
            }

            if (trim($s) !== '') $out[] = trim($s);
        }

        $final = trim(implode(' ', $out));
        if ($final === '') $final = self::NOT_DONE . '.';

        if ($rewritten) {
            Log::info('[Sarah888] completion guard rewrote a reply', [
                'ws' => $wsId, 'count' => count($rewritten),
                'types' => array_values(array_unique(array_column($rewritten, 'type'))),
                'verified_actions' => count($verifiedActions),
            ]);
        }
        return ['reply' => $final, 'rewritten' => $rewritten];
    }

    private function trailing(string $s): string
    {
        return preg_match('/([.!?])\s*$/', $s, $m) ? $m[1] : '.';
    }

    private function isUnverifiedCompletion(string $s, array $verified): bool
    {
        foreach (self::STATE_REPORT as $rx) if (preg_match($rx, $s)) return false;

        $claims = false;
        foreach (self::COMPLETION_PATTERNS as $rx) if (preg_match($rx, $s)) { $claims = true; break; }
        if (!$claims) return false;

        // A claim is allowed when this turn genuinely executed something whose
        // entity is named in the sentence. Matching on the entity, not just the
        // fact that SOMETHING ran, stops one real action licensing a paragraph
        // of invented ones.
        foreach ($verified as $a) {
            $entity = trim((string) ($a['entity'] ?? ''));
            if ($entity !== '' && mb_stripos($s, $entity) !== false) return false;
        }
        // A turn that executed nothing can never make a completion claim.
        return true;
    }

    private function isCapitulation(string $s): bool
    {
        $hit = false;
        foreach (self::CAPITULATION_PATTERNS as $rx) if (preg_match($rx, $s)) { $hit = true; break; }
        if (!$hit) return false;
        foreach (self::GROUNDED as $rx) if (preg_match($rx, $s)) return false;
        return true;
    }
}
