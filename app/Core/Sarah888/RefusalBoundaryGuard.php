<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1Q.2 — a refusal is a hard execution boundary.
 *
 * Traces to S1P-D01. In the third forensic pass the owner said "Do it. My
 * company, my call, I'm authorising it" after being told the mass email was not
 * possible, and Sarah replied:
 *
 *   "Since social media posting and email marketing are not part of the current
 *    LevelUp Growth product, I've queued a task to write a blog article about
 *    the box launch… I'll proceed with this task now. ✅ Queued 1 tasks."
 *
 * She refused the requested action and then executed a different one, unasked,
 * because it was safer. The owner authorised an email blast; they did not
 * authorise an article. Substituting a benign action for a refused one and
 * running it is still acting without authorisation — and it teaches the owner
 * that "I can't do that" is followed by something happening anyway, which makes
 * every future refusal unreadable.
 *
 * THE RULE (from the architectural ruling)
 * Sarah may refuse and may OFFER an alternative. She may not execute the
 * alternative in the same turn. The alternative requires a new explicit
 * authorisation, which by definition arrives in a later turn.
 *
 * THE EXCEPTION
 * If the owner authorised a fallback in the same breath — "send the email, or
 * if you can't, draft a post instead" — the substitute IS what they asked for
 * and it stands.
 */
class RefusalBoundaryGuard
{
    /** Sarah declining the thing that was asked for. */
    private const REFUSAL = '/\b(?:can\'?t|cannot|can not|won\'?t|unable to|not able to|'
        . 'not part of (?:the|our|my) current|not part of the current|isn\'?t part of|'
        . 'is not (?:part of|something)|not something I (?:can|am able)|'
        . 'do(?:es)?n\'?t have (?:the )?(?:ability|permission|access)|'
        . 'outside (?:of )?what I (?:can|am)|not within (?:my|what))\b/i';

    /** The owner pre-authorising a substitute in the same turn. */
    private const FALLBACK_AUTHORISED = '/\b(?:or\s+if\s+you\s+can\'?t|if\s+not,?\s+then|'
        . 'otherwise|failing\s+that|instead\s+then|if\s+that\'?s\s+not\s+possible|'
        . 'alternatively|either\s+way|whichever\s+you\s+can)\b/i';

    public function __construct(private TurnWork $turnWork) {}

    /**
     * @return array{reply:string, cancelled:int, ids:array<int,int>}
     */
    public function validate(string $reply, int $wsId, string $userText): array
    {
        $out = ['reply' => $reply, 'cancelled' => 0, 'ids' => []];
        if (trim($reply) === '') return $out;

        try {
            if (!preg_match(self::REFUSAL, $reply)) return $out;
            if (preg_match(self::FALLBACK_AUTHORISED, $userText)) return $out;

            $r = $this->turnWork->cancelThisTurn($wsId,
                'the reply refused the requested action; a substitute action may not run in the same turn (S1P-D01)');
            if ($r['cancelled'] === 0) return $out;

            $out['cancelled'] = $r['cancelled'];
            $out['ids']       = $r['ids'];
            $out['reply']     = $this->rewrite($reply);

            Log::warning('[Sarah888] RefusalBoundaryGuard withdrew a substitute action', [
                'ws' => $wsId, 'task_ids' => $r['ids'], 'actions' => $r['actions'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Sarah888] RefusalBoundaryGuard failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Turn the executed substitute back into an offer.
     *
     * The alternative is still worth proposing — it is often genuinely useful.
     * What changes is the tense: "I've queued a blog article" becomes an offer
     * the owner can accept, because nothing was queued once the reversal ran.
     */
    private function rewrite(string $reply): string
    {
        $text = preg_replace('/\n*✅ Queued [^\n]*\n?/u', '', $reply);

        $claim = '/\b(?:i\'?ve|i\s+have)\s+(?:already\s+)?queued\b'
               . '|\bi\'?ll\s+proceed\s+with\s+(?:this|that)\b'
               . '|\bproceeding\s+now\b'
               . '|\bi\'?ll\s+(?:queue|create|set\s+up|start)\b/i';

        $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+/', (string) $text) ?: [] as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;
            if (!preg_match($claim, $s)) { $kept[] = $s; continue; }

            // The refusal and the claim often share one sentence — "Since email
            // marketing is not part of the current product, I've queued a blog
            // article instead." Dropping that sentence would delete the refusal
            // along with the claim and leave a reply that reads as agreement.
            // Truncate at the claim clause and keep the refusal half.
            if (preg_match(self::REFUSAL, $s)) {
                $trimmed = preg_replace(
                    '/[,;]?\s*(?:and\s+|so\s+|but\s+)?(?:i\'?ve|i\s+have|i\'?ll|i\s+will)\s+'
                    . '(?:already\s+)?(?:queued|proceed|queue|create|set\s+up|start).*$/is', '', $s);
                $trimmed = trim((string) $trimmed);
                if ($trimmed !== '') $kept[] = rtrim($trimmed, ' ,;') . '.';
            }
        }

        $body = trim(implode(' ', $kept));
        return trim($body . ' I have not started anything in its place — say the word and I will.');
    }
}
