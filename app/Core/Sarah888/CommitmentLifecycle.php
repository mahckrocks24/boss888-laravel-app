<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commitments have to be able to END.
 *
 * CommitmentStore has always had claimComplete() and verify(). Nothing in the platform ever called either of
 * them. Measured across every workspace: 267 active, 12 cancelled, 12 superseded — and zero completed, zero
 * verified, zero expired. A promise could be made, and could be withdrawn by hand, but could never be
 * finished. So they accumulated: 83 of them live in Chef Red's workspace, the oldest from 7 August, all
 * handed to Sarah on every single turn. She read that permanent backlog and reported it as blockages.
 *
 * There is no automatic link from a commitment to the work that would satisfy it — `related_task_id` is never
 * populated, and `execution_id` identifies the conversation turn that created the commitment, not a unit of
 * work that completes. So this closes them on the only two grounds that can be defended:
 *
 *   SATISFIED  the record shows the thing asked for is now true. Only claimed where it can be checked
 *              directly, and the evidence is written onto the row.
 *   STALE      nothing has touched it for weeks. It is not completed and is never recorded as completed —
 *              it expires, which is a terminal state the model already defines and nothing ever used.
 *
 * Deliberately NOT here: any attempt to guess completion from wording. A commitment that cannot be checked
 * stays live until it goes stale, because wrongly reporting a promise as kept is the failure this whole area
 * exists to prevent.
 */
class CommitmentLifecycle
{
    /** Untouched for this long and it is no longer driving anything. */
    public const STALE_DAYS = 21;

    public function __construct(private CommitmentStore $store) {}

    /**
     * @return array{satisfied:int, expired:int, detail:list<array{id:int,title:string,outcome:string,why:string}>}
     */
    public function sweep(int $wsId, bool $apply = false): array
    {
        $detail = [];
        $satisfied = 0;
        $expired = 0;

        foreach ($this->store->live($wsId) as $c) {
            $why = $this->satisfiedBecause($wsId, $c);

            if ($why !== null) {
                $satisfied++;
                $detail[] = ['id' => (int) $c->id, 'title' => (string) ($c->title ?? ''),
                             'outcome' => 'completed', 'why' => $why];

                if ($apply) {
                    $this->store->claimComplete($wsId, (int) $c->id);
                    $this->store->verify($wsId, (int) $c->id, $why);
                }
                continue;
            }

            if ($this->isStale($c)) {
                $expired++;
                $age = $this->ageInDays($c);
                $detail[] = ['id' => (int) $c->id, 'title' => (string) ($c->title ?? ''),
                             'outcome' => 'expired', 'why' => "no activity for {$age} days"];

                if ($apply) {
                    DB::table('sarah_commitments')
                        ->where('workspace_id', $wsId)->where('id', $c->id)
                        ->update([
                            'status' => 'expired',
                            'cancellation_reason' => "expired: untouched for {$age} days",
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if ($apply && ($satisfied || $expired)) {
            Log::info('[Sarah888] commitment sweep closed stale and satisfied promises', [
                'workspace_id' => $wsId, 'satisfied' => $satisfied, 'expired' => $expired,
            ]);
        }

        return ['satisfied' => $satisfied, 'expired' => $expired, 'detail' => $detail];
    }

    /**
     * Can the record show this promise is now kept? Returns the evidence, or null when it cannot be checked.
     *
     * Only families whose completion is directly observable are listed. Anything else is left alone: a
     * commitment reported as kept on a guess is a worse defect than one left open.
     */
    private function satisfiedBecause(int $wsId, object $c): ?string
    {
        $title = mb_strtolower((string) ($c->title ?? '') . ' ' . (string) ($c->description ?? ''));

        // "publish the drafts" — checkable: are there drafts left?
        if (preg_match('/\b(publish|publishing)\b/', $title) && preg_match('/\b(draft|drafts|article|articles)\b/', $title)) {
            $drafts = (int) DB::table('articles')->where('workspace_id', $wsId)
                ->where('status', 'draft')->whereNull('deleted_at')->count();

            if ($drafts === 0) {
                return 'no drafts remain in this workspace, so the publishing this promised is done';
            }

            return null;   // still outstanding, and honestly so
        }

        // "generate the missing featured images" — checkable the same way.
        if (preg_match('/\bfeatured image|missing image|images\b/', $title)) {
            $missing = (int) DB::table('articles')->where('workspace_id', $wsId)
                ->where('status', 'draft')->whereNull('deleted_at')
                ->where(function ($q) { $q->whereNull('featured_image_url')->orWhere('featured_image_url', ''); })
                ->count();

            if ($missing === 0) {
                return 'every draft now carries a featured image';
            }

            return null;
        }

        return null;
    }

    private function isStale(object $c): bool
    {
        $touched = $c->updated_at ?? $c->created_at;
        if (! $touched) {
            return false;
        }

        // Compared as an absolute instant on purpose. Carbon 3 changed the SIGN of diffInDays, so
        // now()->diffInDays($past) comes back negative and every staleness test silently answered false —
        // which is exactly why the first run of this sweep closed nothing while 76 rows sat 25 days old.
        return \Carbon\Carbon::parse($touched)->lessThanOrEqualTo(now()->subDays(self::STALE_DAYS));
    }

    /** Whole days since the row was last touched, always positive. */
    private function ageInDays(object $c): int
    {
        $touched = $c->updated_at ?? $c->created_at;

        return $touched ? (int) \Carbon\Carbon::parse($touched)->diffInDays(now()) : 0;
    }
}
