<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sarah may not tell the owner work is BLOCKED when nothing is blocked.
 *
 * Observed on Chef Red's workspace: the owner said "Hi Sarah" and her very next line was "we need to clear the
 * blockages and prepare the drafts for publication". Asked twice whether she was sure, she doubled down —
 * "Yes, there are still blockages" — and offered to "retry the blocked tasks". At that moment the workspace
 * had zero tasks in a blocked state and zero failures in the previous seven days.
 *
 * She was not hallucinating from nothing. She is handed every live commitment on every turn, and because
 * nothing in the platform could ever mark a commitment complete, promises from three weeks earlier
 * ("Publish the drafts then", 13 August) were still sitting in front of her. What she got wrong was the WORD:
 * an un-started backlog became "blockages", and she then prescribed a remedy — retrying blocked tasks — for a
 * condition that did not exist. That is worse than being unhelpful; it sends the owner looking for a fault in
 * their own product.
 *
 * The distinction this guard enforces is narrow and factual:
 *
 *   nothing has STOPPED the work   →  she may not call it blocked
 *   the work simply has not STARTED →  she may say exactly that, and should
 *
 * So the guard only fires when the claim is contradicted by the record, and it replaces the false sentence
 * with the true position rather than deleting it — the owner still needs to know where the drafts stand.
 *
 * Runs alongside ForbiddenOfferGuard and ConfirmationClaimGuard, and like them works at sentence level so the
 * rest of the reply survives untouched.
 */
class BlockageClaimGuard
{
    /** A claim that something is stuck, as opposed to merely unstarted. */
    private const CLAIM = '/\b(blockage|blockages|blocker|blockers|blocked)\b/i';

    /** Offers to clear a blockage that does not exist. */
    private const REMEDY = '/\b(retry|re-?drive|clear|unstick|resolve)\b[^.!?]{0,40}\b(blocked|blockage|blockages|blockers?)\b/i';

    /**
     * @return array{reply:string, corrected:bool, facts:array<string,int>}
     */
    public function validate(string $reply, int $wsId): array
    {
        $out = ['reply' => $reply, 'corrected' => false, 'facts' => []];

        if (trim($reply) === '' || ! preg_match(self::CLAIM, $reply)) {
            return $out;
        }

        try {
            $facts = $this->facts($wsId);
        } catch (\Throwable $e) {
            // If the record cannot be read, say nothing rather than correcting on a guess.
            Log::warning('[Sarah888] BlockageClaimGuard could not read the record', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);

            return $out;
        }

        $out['facts'] = $facts;

        // Something really is stuck — her wording stands.
        if ($facts['blocked'] > 0 || $facts['failed_7d'] > 0) {
            return $out;
        }

        $truth = $this->truth($facts);
        $rewritten = $this->rewrite($reply, $truth);

        if ($rewritten === $reply) {
            return $out;
        }

        Log::warning('[Sarah888] BlockageClaimGuard corrected a blockage claim the record contradicts', [
            'workspace_id' => $wsId,
            'facts'        => $facts,
        ]);

        $out['reply'] = $rewritten;
        $out['corrected'] = true;

        return $out;
    }

    /** @return array<string,int> */
    private function facts(int $wsId): array
    {
        $drafts = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft');

        return [
            'blocked'   => (int) DB::table('tasks')->where('workspace_id', $wsId)
                ->where('status', 'blocked')->count(),
            'failed_7d' => (int) DB::table('tasks')->where('workspace_id', $wsId)
                ->where('status', 'failed')->where('created_at', '>', now()->subDays(7))->count(),
            'drafts'    => (int) (clone $drafts)->count(),
            'no_image'  => (int) (clone $drafts)->where(function ($q) {
                $q->whereNull('featured_image_url')->orWhere('featured_image_url', '');
            })->count(),
        ];
    }

    /** The position as the record actually has it, in her own register. */
    private function truth(array $f): string
    {
        if ($f['drafts'] === 0) {
            return 'Nothing is blocked, and there are no drafts waiting.';
        }

        $s = "Nothing is blocked — {$f['drafts']} draft" . ($f['drafts'] === 1 ? '' : 's')
           . ' ' . ($f['drafts'] === 1 ? 'is' : 'are') . ' sitting ready and I simply have not been told to publish';

        if ($f['no_image'] > 0) {
            $s .= "; {$f['no_image']} still need" . ($f['no_image'] === 1 ? 's' : '')
                . ' a featured image, which I can generate first';
        }

        return $s . '.';
    }

    /**
     * Replace only the sentences that make the false claim. Everything else she said survives, including
     * anything correct about the drafts themselves.
     */
    private function rewrite(string $reply, string $truth): string
    {
        $parts = preg_split('/(?<=[.!?])\s+/', $reply, -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return $reply;
        }

        $kept = [];
        $replaced = false;

        foreach ($parts as $sentence) {
            $isClaim = (bool) preg_match(self::CLAIM, $sentence);
            $isRemedy = (bool) preg_match(self::REMEDY, $sentence);

            if (! $isClaim && ! $isRemedy) {
                $kept[] = $sentence;
                continue;
            }

            // The first offending sentence becomes the truth; any further ones simply go.
            if (! $replaced) {
                $kept[] = $truth;
                $replaced = true;
            }
        }

        return $replaced ? implode(' ', $kept) : $reply;
    }
}
