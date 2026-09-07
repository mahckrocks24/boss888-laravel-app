<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sarah may only name article numbers that belong to the workspace she is speaking in.
 *
 * Chef Red, 2026-09-01: asked for "the earliest ones written", she offered to publish articles 183, 184, 185,
 * 186 and 187. Chef Red's drafts start at 300. Four of those numbers belong to workspace 1 — the platform's
 * own workspace — and the fifth was a Chef Red article that had been live since May. Her own scope query had
 * returned the right answer (352, 354, 300, 661, 662) on the same turn.
 *
 * Nothing was retrieved across the tenancy boundary: both inventory queries are workspace-scoped and I checked
 * that none of those ids exist in workspace 2. She invented plausible consecutive numbers. But the effect on
 * the owner is the same either way — he was shown another tenant's article numbers and offered the chance to
 * publish them, and a number that looks like a fact gets treated as one.
 *
 * So an article number in a reply must be a number this workspace actually has. What cannot be verified does
 * not get to be stated.
 *
 * Runs alongside ForbiddenOfferGuard and BlockageClaimGuard, and like them works at sentence level.
 */
class ArticleIdClaimGuard
{
    /**
     * An explicit reference to article numbers, never a bare count.
     *
     * The connector matters: the sentence that started this was "The available drafts ARE 183, 184, 185,
     * 186, and 187", and a pattern demanding the numbers sit directly against the noun walked straight
     * past it. Up to 60 characters of ordinary English are allowed in between, but never a full stop — that would
     * let a count in one sentence bind to numbers in the next.
     */
    private const REFERENCE = '/\b(?:article|draft|post)s?\b[^.!?\n]{0,60}?#?\s*\d{1,6}(?:\s*(?:,|,?\s*and|&|\/|\s)\s*#?\d{1,6})*/i';

    /**
     * A sentence that OFFERS to publish, as opposed to one that REPORTS having published.
     *
     * The distinction is the whole point of the second check below. "I published 300 and 352" names
     * live articles and is true; "I recommend we publish 300 and 352" names the same live articles and
     * is false. Only the forward-looking form is corrected, so a truthful completion report is never
     * rewritten into a denial of work that really happened.
     */
    private const PUBLISH_OFFER = '/\b(?:recommend|suggest|propose|shall i|should i|can publish|could publish|'
                                . 'will publish|going to publish|plan to publish|ready to publish|let\'?s publish|'
                                . 'i\'?ll publish|we publish|publish the following|publish these|publish now)\b/i';

    /**
     * @return array{reply:string, corrected:bool, foreign:list<int>}
     */
    public function validate(string $reply, int $wsId): array
    {
        $out = ['reply' => $reply, 'corrected' => false, 'foreign' => []];

        if (trim($reply) === '' || ! preg_match(self::REFERENCE, $reply)) {
            return $out;
        }

        try {
            $claimed = $this->claimedIds($reply);
            if (! $claimed) {
                return $out;
            }

            $rows = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->whereIn('id', $claimed)
                ->get(['id', 'status']);

            $ours = $rows->map(fn ($r) => (int) $r->id)->all();
            $foreign = array_values(array_diff($claimed, $ours));

            // ALREADY DONE IS ALSO WRONG. Chef Red, 2026-09-01 17:53 — she offered to publish articles
            // 300, 352, 354, 661, 662 and 186. All six belong to this workspace, and five of them had gone
            // live at 14:59 that same afternoon; the owner had already told her so, twice. This guard let
            // it through because it only ever asked whether the numbers were OURS. They were. The question
            // it failed to ask is whether the work still needed doing.
            //
            // A number that is real but describes finished work is not a smaller error than an invented
            // one — it is the error the owner actually saw, and it reads as not listening.
            $alreadyLive = $rows->filter(fn ($r) => (string) $r->status !== 'draft')
                ->map(fn ($r) => (int) $r->id)->values()->all();

            $offering = (bool) preg_match(self::PUBLISH_OFFER, $reply);

            if (! $foreign && ! ($offering && $alreadyLive)) {
                return $out;
            }
        } catch (\Throwable $e) {
            // Unverifiable is not the same as wrong; say nothing rather than correct on a guess.
            Log::warning('[Sarah888] ArticleIdClaimGuard could not check the inventory', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);

            return $out;
        }

        $rewritten = $this->rewrite($reply, $wsId, $foreign, $offering ? $alreadyLive : []);
        if ($rewritten === $reply) {
            return $out;
        }

        Log::warning('[Sarah888] ArticleIdClaimGuard removed article numbers this workspace does not have', [
            'workspace_id' => $wsId, 'claimed_but_not_ours' => $foreign,
        ]);

        $out['reply'] = $rewritten;
        $out['corrected'] = true;
        $out['foreign'] = $foreign;

        return $out;
    }

    /**
     * RISK-0143 (2026-09-07, DEC-0042): the numbers in a phrase that could be ARTICLE ids. A task/meeting/proposal id, a
     * credit amount, a balance, a clock time or a date is not an article number — on the first live run "1 credit for the
     * article task", "write_article (task #32255)" and "balance 41" were all read as foreign article ids and cut.
     * @return list<int>
     */
    private static function articleNumbers(string $text): array
    {
        $t = preg_replace([
            '/\b(?:task|meeting|proposal|workspace|website|site|lead|campaign)s?\s*#?\s*\d{1,9}/i',
            '/\b\d[\d,\.]*\s*credits?\b/i',
            '/\b(?:balance|reserved|charged|deducted|spend|spent|cost|costs|total|worth)\b[^.!?\n]{0,25}?\d[\d,\.]*/i',
            '/\b\d{1,2}:\d{2}\b/', '/\b\d{4}-\d{2}-\d{2}\b/',
        ], ' ', $text);
        preg_match_all('/\d{1,6}/', (string) $t, $n);
        return array_values(array_unique(array_map('intval', $n[0])));
    }

    /** @return list<int> */
    private function claimedIds(string $reply): array
    {
        preg_match_all(self::REFERENCE, $reply, $m);
        $ids = [];

        foreach ($m[0] as $phrase) {
            foreach (self::articleNumbers($phrase) as $d) {
                $ids[] = $d;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Replace the sentences that name articles with the drafts this workspace actually has ready.
     *
     * Deliberately states the real candidates rather than simply deleting the claim: the owner asked which
     * drafts would go live, and an answer with the numbers cut out is not an answer.
     */
    /**
     * @param list<int> $foreign     numbers this workspace does not have at all
     * @param list<int> $alreadyLive numbers it has, but which are already published while being offered
     */
    private function rewrite(string $reply, int $wsId, array $foreign = [], array $alreadyLive = []): string
    {
        $ready = DB::table('articles')
            ->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')
            ->where(function ($q) { $q->whereNotNull('featured_image_url')->where('featured_image_url', '!=', ''); })
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', 'title']);

        $done = '';
        if ($alreadyLive) {
            sort($alreadyLive);
            $n = count($alreadyLive);
            $done = ($n === 1 ? 'Article #' . $alreadyLive[0] . ' is' : 'Articles #' . implode(', #', $alreadyLive) . ' are')
                  . ' already published — that work is done. ';
        }

        if ($ready->isEmpty()) {
            $truth = $done . 'There are no drafts left ready to publish in this workspace.';
        } else {
            $list = $ready->map(fn ($a) => '#' . $a->id . ' "' . mb_strimwidth((string) $a->title, 0, 48, '…') . '"')
                ->implode(', ');
            $truth = $done . 'The earliest drafts ready to publish are ' . $list . '.';
        }

        $parts = preg_split('/(?<=[.!?])\s+/', $reply, -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return $reply;
        }

        $kept = [];
        $replaced = false;
        $truthAdded = false;

        foreach ($parts as $sentence) {
            if (! preg_match(self::REFERENCE, $sentence)) {
                $kept[] = $sentence;
                continue;
            }

            // Which numbers does THIS sentence name, and is it wrong to name them here? A sentence that
            // reports finished work truthfully keeps its numbers; only an invented number, or an offer to
            // redo something already done, is replaced.
            $here = self::articleNumbers($sentence);   // RISK-0143: ids/credits/balances/times are not article numbers
            $namesForeign = (bool) array_intersect($here, $foreign);
            $reoffers = $alreadyLive
                && preg_match(self::PUBLISH_OFFER, $sentence)
                && array_intersect($here, $alreadyLive);

            if (! $namesForeign && ! $reoffers) {
                $kept[] = $sentence;
                continue;
            }

            // SF-07 (REPORT-0024 / DEC-0029, 2026-09-02): measured on 20+ turns — "What do you know about my business?"
            // answered with "The earliest drafts ready to publish are #960 …" because the model's answer carried a number
            // the workspace does not own ("#3", "0") in a sentence that had nothing to do with publishing. A foreign
            // number in a NON-publishing sentence is dropped with its sentence; only a publishing/drafts sentence, or a
            // re-offer of finished work, is replaced by the truth line — and that line is added once.
            $replaced = true;
            $aboutPublishing = $reoffers
                || preg_match('/\b(publish|publishing|published|draft|drafts|ready to|go live|goes live|live)\b/i', $sentence);
            if (! $aboutPublishing) {
                continue; // dropped, nothing injected
            }
            if (! $truthAdded) {
                $kept[] = $truth;
                $truthAdded = true;
            }
        }

        if (! $replaced) {
            return $reply;
        }
        $out = trim(implode(' ', $kept));
        return $out !== '' ? $out : $truth;
    }
}
