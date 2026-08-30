<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1J — self-report guard.
 *
 * Traces to F1-D09, which survived Phase 1D and reappeared verbatim in the
 * Phase 1H run. Asked "has anything you've done today changed data in this
 * workspace?", Sarah answered:
 *
 *   "In the last 7 days, we've completed 385 tasks (41 failed). Today, no
 *    changes have been made that affect the data in this workspace."
 *
 * 322 tasks had been created in that workspace during that conversation. Asked
 * again at the end — "what did you change in this workspace during this
 * conversation? Be exact" — she did not answer at all, and queued four more
 * tasks while not answering.
 *
 * WHY A GUARD AND NOT A BETTER PROMPT
 * ActionLedger already renders the evidence AND an explicit instruction:
 * "never answer 'nothing changed' while this ledger is non-empty". The model
 * was given the facts and the rule and produced the denial anyway. Rewording
 * that instruction would be tuning a prompt to paper over a defect, which is
 * exactly what this project forbids — and it would leave the failure earnable
 * again on the next model or temperature change. A deterministic check after
 * generation cannot be talked out of the truth, which is the same reason
 * DenialGuard and CompletionGuard exist.
 *
 * WHAT IT WILL NOT DO
 * It never invents work. Every number and name it writes comes from the
 * ledger, and when the ledger cannot be read it leaves the reply untouched
 * rather than asserting a correction it cannot prove.
 */
class SelfReportGuard
{
    /**
     * Sentences that assert nothing in this workspace was changed.
     *
     * Several alternatives below end in a word STEM ("chang", "creat") so that
     * one branch covers change/changed/changes. There is deliberately no
     * trailing \b: anchoring the end of a stem to a word boundary made every
     * inflected form unmatchable, which silently let three of the four verbatim
     * F1-D09 denials through while the suite still looked mostly green.
     */
    /**
     * Adverbs are allowed between the auxiliary and the verb. The live reply
     * "no data has been OFFICIALLY changed in the workspace yet" walked past a
     * pattern that required "been" to be followed directly by the verb — the
     * same adverb hole CompletionGuard had already been bitten by. ADV covers
     * the gap without letting the match run across a clause boundary.
     */
    private const ADV = '(?:\w+\s+){0,3}';

    private const DENIAL = '/\b(?:'
        . 'no\s+changes?\s+(?:have|has|were|was)\s+been?\s*' . self::ADV . '(?:made|applied)?'
        . '|no\s+changes?\s+(?:to|in|were|have)\b'
        . '|nothing\s+(?:has|have|was|were)?\s*(?:been\s+)?' . self::ADV
          . '(?:changed|modified|altered|created|queued|updated)'
        . '|nothing\s+(?:I|i)\W*(?:ve|have)\s+done\s+.{0,40}?chang'
        . '|(?:I|i)\s+(?:have\s*n[o\']t|haven\W?t|did\s*n[o\']t|didn\W?t)\s+(?:made\s+any\s+)?' . self::ADV
          . '(?:chang|modif|creat|queue|updat|alter)'
        . '|(?:no|not)\s+(?:any\s+)?(?:data|changes?)\s+(?:has|have|was|were)\s+(?:been\s+)?' . self::ADV
          . '(?:chang|modif|alter)'
        . '|there\s+(?:have|has)\s+been\s+no\s+changes?'
        // A third-person subject. Every branch above assumes the sentence opens
        // with "no"/"nothing" or a first-person "I have not", so Phase 1L's
        // "Today's actions have not altered any data yet" walked through
        // untouched while 83 tasks sat in the ledger. The object is pinned to
        // data/records/workspace so this cannot fire on ordinary progress
        // reporting like "the articles have not been published yet".
        // The data noun may sit on either side of the verb: "…have not altered
        // any DATA" but also "the WORKSPACE has not changed", "your RECORDS
        // have not been modified". Both are denials; only the word order
        // differs.
        . '|(?:have|has)\s+not\s+(?:\w+\s+){0,3}(?:chang|modif|alter)\w*'
          . '[^.!?]{0,30}\b(?:data|record|records|workspace|anything)\b'
        . '|\b(?:workspace|data|records?)\b[^.!?]{0,20}(?:have|has)\s+not\s+'
          . '(?:\w+\s+){0,3}(?:chang|modif|alter)\w*'
        . '|(?:no|nothing)\s+(?:\w+\s+){0,2}(?:was|were|has been|have been)\s+'
          . '(?:creat|queue|add)\w*[^.!?]{0,25}\b(?:today|this conversation|in this workspace)\b'
        . ')/i';

    /** The owner asking, in so many words, "what have you done?" */
    private const SELF_REPORT_QUESTION = '/\b(?:'
        . 'what\s+did\s+you\s+(?:change|do|queue|create)'
        . '|what\s+have\s+you\s+(?:changed|done|queued|created)'
        . '|has\s+anything\s+you\W*(?:ve|have)?\s*done\s+chang'
        . '|did\s+you\s+change\s+anything'
        . '|anything\s+you\W*(?:ve|have)\s+done\s+.{0,30}chang'
        . '|what\s+(?:changes|actions)\s+(?:did|have)\s+you'
        . ')\b/i';

    public function __construct(private ActionLedger $ledger) {}

    /**
     * @return array{reply:string, rewritten:bool, appended:bool, totals:array, scope:string}
     */
    public function validate(
        string $reply,
        int $wsId,
        ?string $conversationId = null,
        ?string $userText = null,
    ): array {
        $out = ['reply' => $reply, 'rewritten' => false, 'appended' => false,
                'totals' => [], 'scope' => ''];
        $scopeLabel = 'in this workspace today';

        try {
            // Prefer conversation scope, fall back to the workspace window.
            //
            // WHY THE FALLBACK IS NOT A FUDGE. A conversation-scoped ledger is
            // built by matching the executions in this conversation against the
            // execution_id stamped on each task — and nothing stamps it yet
            // (S1J-N01: 565 of 565 tasks on Chef Red carry created_via and none
            // carry execution_id). So the scoped ledger reports zero however
            // much work was done, and a guard that trusted it would read every
            // denial as truthful and stay silent — which is exactly what it did
            // on its first live run. Falling back to the workspace window keeps
            // the guard honest today, and the scope label below always states
            // which window the numbers actually describe, so the reply never
            // claims a precision the data does not have. When tasks do carry
            // execution_id, the scoped branch takes over with no change here.
            $l = null;
            if ($conversationId) {
                $scoped = $this->ledger->forConversation($wsId, $conversationId);
                if (($scoped['totals']['all'] ?? 0) > 0) {
                    $l = $scoped;
                    $scopeLabel = 'in this conversation';
                }
            }
            if ($l === null) {
                $l = $this->ledger->forWorkspace($wsId);
                $scopeLabel = 'in this workspace today';
            }
        } catch (\Throwable $e) {
            // Unprovable. A correction we cannot evidence is worse than the
            // denial we are trying to catch.
            Log::warning('[Sarah888] SelfReportGuard could not read the ledger', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return $out;
        }

        $t = $out['totals'] = $l['totals'];
        $scope = $scopeLabel;
        $out['scope'] = $scope;

        // Nothing happened: a denial is TRUE and must be left alone. This is
        // the case the guard must never break.
        if (($t['all'] ?? 0) === 0) return $out;

        // ── 1. A false denial is replaced with the evidence ────────────────
        // A reply often denies twice — once up front and once in a closing
        // restatement. Substituting the full ledger paragraph for each one
        // printed the same three clauses and the same credit total twice in a
        // single answer. The first denial carries the correction; any later one
        // is simply dropped, because it is the same false claim and the truth
        // has already been stated.
        $sentences = preg_split('/(?<=[.!?])\s+/', $reply) ?: [];
        $changed = false;
        foreach ($sentences as $i => $s) {
            if (!preg_match(self::DENIAL, $s)) continue;
            $sentences[$i] = $changed ? '' : $this->truth($l, $scope);
            $changed = true;
        }
        if ($changed) {
            $out['reply'] = trim(preg_replace('/\s+/', ' ',
                implode(' ', array_filter($sentences, static fn ($s) => trim($s) !== ''))));
            $out['rewritten'] = true;
            Log::info('[Sarah888] SelfReportGuard corrected a false no-change claim', [
                'ws' => $wsId, 'conversation' => $conversationId, 'totals' => $t,
            ]);
        }

        // ── 2. A direct question that went unanswered gets the facts ───────
        // The Phase 1H final turn asked "what did you change during this
        // conversation? Be exact" and got four paragraphs of recommendations
        // with no answer in them. Silence on this question is its own defect:
        // the owner cannot audit what they cannot see.
        if ($userText !== null && preg_match(self::SELF_REPORT_QUESTION, $userText)
            && !$out['rewritten'] && !$this->alreadyReports($out['reply'])) {
            $out['reply'] = rtrim($out['reply']) . "\n\n" . $this->truth($l, $scope);
            $out['appended'] = true;
            Log::info('[Sarah888] SelfReportGuard answered an unanswered self-report question', [
                'ws' => $wsId, 'conversation' => $conversationId, 'totals' => $t,
            ]);
        }

        return $out;
    }

    /**
     * Does the reply already account for the owner's OWN question, in its own
     * words?
     *
     * This must mean "I did N things", not "N things exist". The first version
     * accepted any "<number> task" and so treated the live reply "in the last 7
     * days, we've completed 422 tasks" as a self-report — a workspace-wide
     * weekly total, which is precisely the deflection the owner was cutting
     * through when they said "be exact". First person and a number, or nothing.
     */
    private function alreadyReports(string $reply): bool
    {
        return (bool) preg_match(
            '/\bI\s+(?:have\s+|just\s+|already\s+)*(?:queued|created|added|recorded|logged|made|changed)\b[^.!?]{0,60}\b\d+\b/i',
            $reply)
            || (bool) preg_match(
            '/\b\d+\s+\w{0,12}\s?tasks?\b[^.!?]{0,40}\b(?:for you|in this conversation|on your behalf)\b/i',
            $reply);
    }

    /** One sentence of ledger-backed fact. Never more than the ledger supports. */
    private function truth(array $l, string $scope): string
    {
        $t = $l['totals'];
        $bits = [];

        if ($t['mine'] > 0) {
            $bits[] = 'I queued ' . $t['mine'] . ' task' . ($t['mine'] === 1 ? '' : 's')
                    . ' myself' . ($this->names($l['mine']) ? ' (' . $this->names($l['mine']) . ')' : '');
        }
        if ($t['pending'] > 0) {
            $bits[] = $t['pending'] . ' item' . ($t['pending'] === 1 ? ' is' : 's are')
                    . ' waiting on your approval and have not run';
        }
        if ($t['automated'] > 0) {
            $bits[] = $t['automated'] . ' more ' . ($t['automated'] === 1 ? 'was' : 'were')
                    . ' run by background automation rather than by anything you asked me in chat';
        }
        if ($t['other'] > 0) {
            $bits[] = $t['other'] . ' ' . ($t['other'] === 1 ? 'was' : 'were') . ' created outside this chat';
        }
        if (!$bits) return 'To be exact ' . $scope . ': the action ledger records no changes.';

        // One sentence: a later guard that rewrites this sentence takes the credit clause with it (P6-f, 2026-08-30 —
        // before, "That consumed 5 credits on completed work." survived alone under "nothing has actually run").
        $line = 'To be exact, ' . $scope . ' ' . implode('; ', $bits);
        if ($t['credits_spent'] > 0) {
            $line .= ', which consumed ' . $t['credits_spent'] . ' credit'
                   . ($t['credits_spent'] === 1 ? '' : 's') . ' on completed work';
        }
        return $line . '.';
    }

    /** Up to three action names, so the count is checkable rather than asserted. */
    private function names(array $entries): string
    {
        $seen = [];
        foreach ($entries as $e) {
            $n = trim((string) ($e['action'] ?? ''));
            if ($n !== '' && !in_array($n, $seen, true)) $seen[] = $n;
            if (count($seen) === 3) break;
        }
        return implode(', ', $seen);
    }
}
