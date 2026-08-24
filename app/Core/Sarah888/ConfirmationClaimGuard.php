<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — a promise to act on confirmation must be backed by a real record.
 *
 * WHAT WAS MEASURED, 2026-08-09.
 * Five publish phrasings on the live chat path, five replies saying "Please
 * confirm that you want to publish the wholesale page now, and I will handle
 * the task immediately", and zero rows of any kind: no proposal, no approval,
 * no task. Sarah's promise and the system state disagreed. A later "yes" had
 * nothing to bind to, so whatever the planner did next silently became the
 * authorised thing.
 *
 * The two-turn protocol existed only as instructions in the model prompt
 * (routes/api/authenticated/agents-01.php), which told her to answer with a
 * confirmation question and an EMPTY create_tasks array. With nothing to
 * create, TaskService::create() was never called, so the ASK_FIRST gate that
 * builds the proposal never ran. The gate itself was healthy the whole time —
 * it had simply never been reached from chat.
 *
 * THE INVARIANT THIS ENFORCES
 * If a reply asks the owner to confirm a mutating or cost-bearing action, a
 * durable pending authorization must exist before that reply is persisted.
 * Where one exists, the reply must also disclose what approving it costs.
 * Where none exists, the reply may not claim that a later confirmation will
 * trigger execution — because it will not.
 *
 * WHY REPLY-TIME, NOT CREATION-TIME
 * The same reason ForbiddenOfferGuard exists (S1L-D05): the offer never appears
 * in any row. It exists only in the sentence Sarah wrote. A creation-time gate
 * cannot see a promise that created nothing, so the boundary has to be enforced
 * where the promise is made.
 *
 * WHY IT DOES NOT FIRE ON EVERY QUESTION
 * "Shall I explain that in more detail?" is a confirmation request too, and
 * rewriting it would be absurd. The guard fires only where a solicitation
 * coincides with a verb that changes the world or spends money. Asking to
 * confirm a read-only answer commits nothing, so there is nothing to bind.
 *
 * ORDERING. This runs after ForbiddenOfferGuard, for the reason that guard
 * gives: a promise must not survive because it was introduced downstream of the
 * check for it. A forbidden offer is removed first; whatever legitimate
 * solicitation remains is then either backed by a record or corrected.
 */
class ConfirmationClaimGuard
{
    public function __construct(private ChatActionProposal $proposals) {}

    /** The reply solicits a confirmation that would authorise something. */
    private const SOLICITATIONS = [
        '/\bplease\s+confirm\b/i',
        '/\bconfirm\b[^.!?]{0,80}\b(?:and|then)\b[^.!?]{0,40}\bi(?:\s+will|\'ll)\b/i',
        '/\bif\s+you\s+confirm\b/i',
        '/\bonce\s+you\s+confirm\b/i',
        '/\bshall\s+i\b/i',
        '/\bwould\s+you\s+like\s+me\s+to\b/i',
        '/\bdo\s+you\s+want\s+me\s+to\b/i',
        '/\blet\s+me\s+know\s+and\s+i(?:\s+will|\'ll)\b/i',
        '/\bsay\s+the\s+word\b/i',
        '/\bjust\s+say\s+(?:yes|the\s+word)\b/i',
        '/\bapprove\b[^.!?]{0,80}\band\b[^.!?]{0,40}\bi(?:\s+will|\'ll)\b/i',
        '/\bgive\s+me\s+the\s+go[\s-]?ahead\b/i',
    ];

    /**
     * Verbs whose execution changes live state or spends money.
     *
     * Deliberately narrow. A guard that fired on "update" or "change" would
     * rewrite half of Sarah's ordinary conversation, and a guard that rewrites
     * ordinary conversation gets switched off.
     */
    private const MUTATING = [
        'publish', 'unpublish', 'delete', 'remove', 'destroy', 'wipe',
        'send', 'email', 'newsletter', 'broadcast',
        'deploy', 'launch', 'go live',
        'buy', 'purchase', 'order', 'charge', 'refund', 'cancel',
        'schedule', 'post',
    ];

    /**
     * @return array{reply:string, fired:bool, state:string, pending:int, verbs:array<int,string>}
     *         state: not_applicable | bound | unbound
     */
    public function validate(string $reply, int $wsId, ?string $conversationId = null): array
    {
        $out = ['reply' => $reply, 'fired' => false, 'state' => 'not_applicable',
                'pending' => 0, 'verbs' => []];

        if (trim($reply) === '') return $out;

        // Typographic apostrophes broke a governance scorer once already; a
        // reply written with them must match exactly as one written with ASCII.
        $norm = $this->normalise($reply);

        if (!$this->solicitsConfirmation($norm)) return $out;

        $verbs = $this->mutatingVerbs($norm);
        if (!$verbs) return $out;                 // read-only confirmation: nothing to bind
        $out['verbs'] = $verbs;

        try {
            $pending = $this->proposals->pending($wsId, $conversationId);
        } catch (\Throwable $e) {
            // A guard that cannot read the record must not invent a verdict.
            Log::warning('[Sarah888] ConfirmationClaimGuard could not read pending state', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return $out;
        }
        $out['pending'] = count($pending);

        if ($pending) {
            // Backed by a record. The remaining duty is disclosure: an approval
            // given without knowing the cost is not informed consent.
            $out['state'] = 'bound';
            // P1-3: this used to append the proposal disclosure directly, and
            // agents-01.php appended the SAME text a few lines later — the
            // str_contains() checks below only caught it when the earlier
            // writer happened to use the exact wording tested for. Recorded
            // now; TurnStatusEmitter emits it once or not at all.
            if (!str_contains($norm, 'awaiting your approval')
                && !str_contains($norm, 'nothing has been created')) {
                $ids = array_map(static fn ($p) => (int) $p->id, $pending);
                sort($ids);
                app(TurnStatusEmitter::class)->note(
                    TurnStatusEmitter::PROPOSAL,
                    $this->proposals->disclosure($wsId, $conversationId),
                    implode(',', $ids));
            }
            return $out;
        }

        // Nothing pending. The promise is false, so it does not get to stand.
        $out['state'] = 'unbound';
        $out['reply'] = $this->correct($reply);
        $out['fired'] = true;

        Log::warning('[Sarah888] ConfirmationClaimGuard — confirmation promised with no pending authorization', [
            'ws' => $wsId, 'conversation_id' => $conversationId, 'verbs' => $verbs,
        ]);

        return $out;
    }

    /**
     * Replace the unbacked promise with what is actually true.
     *
     * Sentence-level, like ForbiddenOfferGuard, so any surrounding context Sarah
     * gave — the state she reported, the reasoning she showed — survives. Only
     * the sentence making the false commitment is replaced. A caveat appended
     * underneath would leave the promise intact above it, and a promise that
     * survives anywhere in the reply is a promise the owner can act on.
     */
    private function correct(string $reply): string
    {
        $truth = "I haven't set that up yet — nothing is queued and nothing is waiting "
               . "for your approval, so there is nothing for a \"yes\" to authorise. "
               . "Tell me to do it and I'll put it in front of you properly, with the cost, "
               . "before anything runs.";

        $sentences = preg_split('/(?<=[.!?])\s+/u', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$sentences) return $truth;

        $kept = []; $replaced = false;
        foreach ($sentences as $s) {
            if ($this->solicitsConfirmation($this->normalise($s))) {
                if (!$replaced) { $kept[] = $truth; $replaced = true; }
                continue;                          // drop every soliciting sentence
            }
            $kept[] = $s;
        }
        if (!$replaced) $kept[] = $truth;          // solicitation spanned sentences

        return trim(preg_replace('/[ \t]+/', ' ', implode(' ', $kept)));
    }

    private function solicitsConfirmation(string $normalised): bool
    {
        foreach (self::SOLICITATIONS as $re) {
            if (preg_match($re, $normalised)) return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private function mutatingVerbs(string $normalised): array
    {
        $hits = [];
        foreach (self::MUTATING as $v) {
            if (preg_match('/\b' . preg_quote($v, '/') . '\w*/i', $normalised)) $hits[] = $v;
        }
        return array_values(array_unique($hits));
    }

    private function normalise(string $s): string
    {
        return mb_strtolower(strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
        ]));
    }
}
