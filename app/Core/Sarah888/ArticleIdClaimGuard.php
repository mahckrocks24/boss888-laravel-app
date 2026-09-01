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
     * past it. A few words of ordinary English are allowed in between, but not a full stop — that would
     * let a count in one sentence bind to numbers in the next.
     */
    private const REFERENCE = '/\b(?:article|draft|post)s?\b[^.!?\n]{0,24}?#?\s*\d{1,6}(?:\s*(?:,|,?\s*and|&|\/|\s)\s*#?\d{1,6})*/i';

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

            $ours = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->whereIn('id', $claimed)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();

            $foreign = array_values(array_diff($claimed, $ours));
            if (! $foreign) {
                return $out;
            }
        } catch (\Throwable $e) {
            // Unverifiable is not the same as wrong; say nothing rather than correct on a guess.
            Log::warning('[Sarah888] ArticleIdClaimGuard could not check the inventory', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);

            return $out;
        }

        $rewritten = $this->rewrite($reply, $wsId);
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

    /** @return list<int> */
    private function claimedIds(string $reply): array
    {
        preg_match_all(self::REFERENCE, $reply, $m);
        $ids = [];

        foreach ($m[0] as $phrase) {
            preg_match_all('/\d{1,6}/', $phrase, $n);
            foreach ($n[0] as $d) {
                $ids[] = (int) $d;
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
    private function rewrite(string $reply, int $wsId): string
    {
        $ready = DB::table('articles')
            ->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')
            ->where(function ($q) { $q->whereNotNull('featured_image_url')->where('featured_image_url', '!=', ''); })
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', 'title']);

        if ($ready->isEmpty()) {
            $truth = 'I do not have draft articles ready to publish in this workspace.';
        } else {
            $list = $ready->map(fn ($a) => '#' . $a->id . ' "' . mb_strimwidth((string) $a->title, 0, 48, '…') . '"')
                ->implode(', ');
            $truth = 'The earliest drafts ready to publish are ' . $list . '.';
        }

        $parts = preg_split('/(?<=[.!?])\s+/', $reply, -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return $reply;
        }

        $kept = [];
        $replaced = false;

        foreach ($parts as $sentence) {
            if (! preg_match(self::REFERENCE, $sentence)) {
                $kept[] = $sentence;
                continue;
            }

            if (! $replaced) {
                $kept[] = $truth;
                $replaced = true;
            }
        }

        return $replaced ? implode(' ', $kept) : $reply;
    }
}
