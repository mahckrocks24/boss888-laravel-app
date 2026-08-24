<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1N — reply-time enforcement of the FORBIDDEN tier.
 *
 * Traces to S1L-D05. The offer to approve a 74,000 invoice never appeared in a
 * task's `action` column — the queued action was `create_lead`. It existed only
 * in the sentence Sarah wrote. A creation-time gate cannot see that, so the
 * boundary has to be enforced where the promise is made.
 *
 * WHAT IT DOES
 * Replaces the offering sentence with the registry's refusal for that
 * operation. It does not append a caveat and it does not soften the offer: an
 * offer that survives anywhere in the reply is an offer the owner can accept.
 *
 * WHAT IT WILL NOT DO
 * Rewrite a correct refusal. ActionAuthority skips any sentence already
 * carrying refusal language, so "I can't approve invoices" passes through
 * untouched — otherwise the guard would answer a refusal with a second refusal
 * and read as though Sarah were arguing with herself.
 *
 * ORDERING. This runs LAST in the guard chain, after DenialGuard,
 * CompletionGuard, SelfReportGuard and CommitmentStateGuard. Those guards may
 * insert sentences of their own, and a forbidden offer must not survive because
 * it was introduced downstream of the check for it.
 */
class ForbiddenOfferGuard
{
    public function __construct(private ActionAuthority $authority) {}

    /**
     * @return array{reply:string, blocked:bool, operations:array<int,string>}
     */
    public function validate(string $reply, int $wsId = 0): array
    {
        $out = ['reply' => $reply, 'blocked' => false, 'operations' => []];
        if (trim($reply) === '') return $out;

        try {
            $hits = $this->authority->scanReply($reply);
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] ForbiddenOfferGuard scan failed', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return $out;
        }
        if (!$hits) return $out;

        $text = $reply;
        $__replaced = [];

        // Only OFFERS. A sentence that merely describes the world cannot be
        // accepted by the owner, so replacing it protects nobody and destroys
        // a true statement - measured on a briefing whose backlog line was
        // struck because it contained "release" and "content production".
        $hits = array_values(array_filter($hits, fn($h) => self::isAnOffer((string) ($h['sentence'] ?? ''))));
        if (!$hits) return $out;
        foreach ($hits as $hit) {
            // Replace the exact offending sentence. str_replace rather than a
            // regex rebuild so surrounding punctuation and any list structure
            // the reply used are left exactly as they were.
            $text = str_replace($hit['sentence'], $hit['refusal'], $text);
            // Capability key, not a phrasing id: governance is reported in the
            // registry's vocabulary so a log line names production.deploy
            // rather than whichever synonym happened to trigger it.
            $out['operations'][] = $hit['key'];
            // Keep what was struck: without it a false positive cannot be
            // investigated, because the pre-guard reply is never stored.
            $__replaced[] = mb_substr((string) ($hit['sentence'] ?? ''), 0, 160);
        }

        $text = $this->stripSolicitations($text);
        $out['reply'] = trim(preg_replace('/[ \t]+/', ' ', $text));
        $out['blocked'] = true;

        Log::warning('[Sarah888] ForbiddenOfferGuard blocked an offer to perform a forbidden operation', [
            'ws' => $wsId, 'operations' => $out['operations'],
            'replaced_sentences' => $__replaced,
        ]);
        return $out;
    }

    /**
     * Remove the confirmation prompt that belonged to the blocked offer.
     *
     * Live verification produced: "I can't approve or release payments — that
     * stays with you… Shall I go ahead with this action?" The refusal landed
     * and the solicitation survived, so the reply refused and asked for
     * permission to proceed in the same breath. Any owner reading that will
     * answer the question, not the refusal.
     *
     * Only stripped when something was actually blocked, and only for
     * sentences that are PURELY a solicitation — a sentence carrying real
     * content plus a question is left alone rather than silently truncated.
     */
    private function stripSolicitations(string $text): string
    {
        $kept = [];
        $previousWasReplaced = false;

        foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;

            // 1. Asking permission to do the thing that was just refused.
            if (preg_match(
                '/^(?:shall\s+i\s+(?:proceed|go\s+ahead)|would\s+you\s+like\s+me\s+to\s+proceed'
                . '|do\s+you\s+want\s+me\s+to\s+proceed|want\s+me\s+to\s+(?:proceed|go\s+ahead)'
                . '|let\s+me\s+know\s+if\s+you.{0,25}proceed'
                . '|please\s+confirm[^.!?]{0,60})[^.!?]{0,40}[.?!]?$/i', $s)) {
                continue;
            }

            // 2. Promising to queue the work later.
            //
            // A blocked turn creates nothing — TaskService refuses on the turn
            // classification — so ANY promise to queue in this reply is false
            // by construction, not merely awkward. Live verification returned
            // "I can't deploy to production… Once I have that confirmation,
            // I'll queue the necessary tasks to publish these changes", which
            // tells the owner the deploy is one confirmation away when it is
            // not available at all.
            if (preg_match(
                '/\b(?:once\s+(?:i\s+have\s+)?(?:that\s+|your\s+)?confirm\w*|after\s+(?:you\s+)?confirm\w*'
                . '|i\'?ll|i\s+will|we\'?ll|we\s+will)\b[^.!?]{0,60}'
                . '\b(?:queue|create|set\s+up|schedule|proceed|action|publish|handle)\b/i', $s)) {
                continue;
            }

            // 3. A sentence whose antecedent was the removed offer.
            //    "This will ensure a smooth transition for ongoing projects."
            //    The trailing [.!?]? is load-bearing: a character class that
            //    excludes terminators cannot reach $ on a sentence that ends
            //    in one. The same omission made SelfReportGuard's denial
            //    pattern unable to match "changed" in Phase 1J.
            if ($previousWasReplaced && preg_match(
                '/^(?:this|that|it|these|those)\b[^.!?]{0,90}[.!?]?$/i', $s)) {
                continue;
            }

            $previousWasReplaced = $this->isRefusalText($s);
            $kept[] = $s;
        }
        return implode(' ', $kept);
    }

    /** Did this sentence come from the registry rather than from the model? */
    private function isRefusalText(string $s): bool
    {
        foreach (ActionAuthority::CAPABILITIES as $cap) {
            if (str_starts_with($cap['refusal'], rtrim($s, ' .'))
                || str_contains($cap['refusal'], $s)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Is this sentence Sarah OFFERING to do the thing, rather than describing it?
     *
     * Deliberately conservative about what counts as an offer, because the cost
     * of a miss is asymmetric: a missed offer is caught by the creation gates in
     * TaskService and by ActionAuthority's refusal tiers, whereas a false
     * positive silently deletes a true sentence from an executive's answer and
     * replaces it with a non-sequitur the owner then has to make sense of.
     */
    private static function isAnOffer(string $sentence): bool
    {
        $s = mb_strtolower(strtr($sentence, ["\u{2019}" => "'"]));

        return (bool) preg_match(
            '/\b(?:i(?:\s+will|\'ll|\s+can|\s+could|\s+should|\s+am\s+going\s+to|\s+would|\s+need|\s+just\s+need|\s+require)\b'
            . '|shall\s+i\b|let\s+me\b|want\s+me\s+to\b|would\s+you\s+like\s+me\s+to\b'
            . '|i\s+have\s+(?:queued|started|deployed|released)\b'
            . '|(?:^|\s)(?:deploying|releasing|pushing)\s+(?:it|this|that|the\s)\b)/',
            $s
        );
    }
}
