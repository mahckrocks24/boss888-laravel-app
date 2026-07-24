<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * seo:sync-gsc-ranks — populate seo_keywords.current_rank from REAL Google
 * Search Console positions (gsc_metrics).
 *
 * WHY (2026-07-01 SEO↔Sarah alignment forensic): seo:track-ranks (DataForSEO)
 * has been failing since 2026-06-21, leaving seo_keywords.current_rank all NULL.
 * Sarah therefore reported every keyword "unranked / 0 in top 10 / goal OFF
 * TRACK" while GSC actually held real positions (e.g. "private chef new jersey"
 * @ pos 3). This ETL closes the GSC → seo_keywords gap so the gatherer, the
 * opportunity-zone growth rule and goal tracking read TRUE ranks.
 *
 * SCOPE (deliberate): EXACT normalized (lower+trim) query==keyword match only —
 * deterministic data plumbing that belongs in Laravel. Fuzzy keyword↔query
 * matching is "intelligence" and belongs in the Railway runtime (follow-up), so
 * we do NOT guess: an unmatched keyword is left untouched. A keyword with zero
 * GSC impressions genuinely has no search presence, so staying unranked is the
 * TRUE state, not a gap.
 */
class SyncGscRanksCommand extends Command
{
    protected $signature = 'seo:sync-gsc-ranks {--workspace= : Only this workspace} {--days=28 : GSC lookback window} {--dry-run : Report only, no writes}';
    protected $description = 'Populate seo_keywords.current_rank from real GSC positions (exact-normalized query match).';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $dry   = (bool) $this->option('dry-run');
        $wsOpt = $this->option('workspace');
        $since = now()->subDays($days)->toDateString();
        $hasHistory = Schema::hasTable('keyword_rank_history');

        $workspaces = $wsOpt !== null
            ? collect([(int) $wsOpt])
            : DB::table('gsc_metrics')->distinct()->pluck('workspace_id');

        $totKw = 0; $totMatched = 0;

        foreach ($workspaces as $wsId) {
            $keywords = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->get(['id', 'keyword', 'current_rank']);

            $matched = 0;
            foreach ($keywords as $kw) {
                $norm = mb_strtolower(trim((string) $kw->keyword));
                if ($norm === '') {
                    continue;
                }

                // Exact normalized GSC match, aggregated across pages/dates in window.
                $agg = DB::table('gsc_metrics')
                    ->where('workspace_id', $wsId)
                    ->whereRaw('LOWER(TRIM(query)) = ?', [$norm])
                    ->where('date', '>=', $since)
                    ->selectRaw('AVG(position) AS avg_pos, SUM(impressions) AS impr')
                    ->first();

                if (! $agg || $agg->avg_pos === null || (int) $agg->impr <= 0) {
                    continue; // no real GSC presence — leave untouched (truly unranked)
                }

                $newRank = max(1, (int) round((float) $agg->avg_pos));

                // Best-positioned page for this query → rank_url (informational).
                $best = DB::table('gsc_metrics')
                    ->where('workspace_id', $wsId)
                    ->whereRaw('LOWER(TRIM(query)) = ?', [$norm])
                    ->where('date', '>=', $since)
                    ->orderBy('position')
                    ->value('page');

                $matched++;

                if ($dry) {
                    $this->line(sprintf('  [ws%d] "%s"  %s → %d  (impr %d)',
                        $wsId, $kw->keyword,
                        $kw->current_rank === null ? 'NULL' : (string) $kw->current_rank,
                        $newRank, (int) $agg->impr));
                    continue;
                }

                $prev = $kw->current_rank;
                $rankUrl = $best ? mb_substr((string) $best, 0, 2048) : null;
                DB::table('seo_keywords')->where('id', $kw->id)->update([
                    'previous_rank'   => $prev,
                    'current_rank'    => $newRank,
                    'rank_change'     => ($prev !== null) ? ((int) $prev - $newRank) : 0,
                    'rank_source'     => 'gsc',
                    'last_rank_check' => now(),
                    'rank_url'        => $rankUrl,
                    'updated_at'      => now(),
                ]);

                // Append a rank-history row so the gatherer's rank_delta_win /
                // rank_loss rules (which read keyword_rank_history, captured_at
                // 7-10 days ago) can fire once history spans time. Fix #3.
                if ($hasHistory) {
                    DB::table('keyword_rank_history')->insert([
                        'workspace_id' => $wsId,
                        'keyword_id'   => $kw->id,
                        'rank'         => $newRank,
                        'rank_url'     => $rankUrl,
                        'source'       => 'gsc',
                        'captured_at'  => now(),
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            $totKw += count($keywords);
            $totMatched += $matched;
            $this->info(sprintf('ws %d: matched %d of %d keyword(s) from GSC (window %dd)%s',
                $wsId, $matched, count($keywords), $days, $dry ? ' [DRY RUN]' : ''));
        }

        Log::info('[seo:sync-gsc-ranks] complete', [
            'matched' => $totMatched, 'keywords' => $totKw, 'days' => $days, 'dry_run' => $dry,
        ]);
        $this->info("Done. Matched {$totMatched}/{$totKw} keyword(s) across " . count($workspaces) . " workspace(s).");
        return 0;
    }
}
