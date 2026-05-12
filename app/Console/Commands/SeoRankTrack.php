<?php

namespace App\Console\Commands;

use App\Connectors\DataForSeoConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh volume / difficulty / CPC / current_rank for stale keywords via
 * DataForSEO. Stale = last_rank_check NULL or older than 24h. Caps at 100
 * per run to stay inside DataForSEO rate limits.
 *
 * Difficulty is sourced from DataForSEO's `competition_index` (0-100) — the
 * connector\'s keywordData() helper doesn\'t expose a "keyword_difficulty"
 * field on the Google Ads search-volume endpoint.
 *
 * Rank check uses trackKeywordRank (purpose-built helper) rather than
 * iterating serpAnalysis results manually — same DataForSEO endpoint
 * but with proper domain matching (handles www.* and subdomain edge cases).
 */
class SeoRankTrack extends Command
{
    protected $signature   = 'seo:rank-track';
    protected $description = 'Refresh volume + difficulty + CPC + current_rank for stale tracked keywords via DataForSEO';

    public function handle(DataForSeoConnector $dfs): int
    {
        if (!$dfs->isConfigured()) {
            $this->error('DataForSEO not configured (DATAFORSEO_LOGIN/PASSWORD env missing).');
            return 1;
        }

        $keywords = DB::table('seo_keywords')
            ->whereNull('last_rank_check')
            ->orWhere('last_rank_check', '<', now()->subHours(24))
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($keywords->isEmpty()) {
            $this->info('No stale keywords to refresh.');
            return 0;
        }

        $this->info("Refreshing {$keywords->count()} stale keyword(s)…");
        $updated = 0;
        $failed  = 0;

        foreach ($keywords as $kw) {
            try {
                // Resolve the workspace's site domain from seo_settings.
                $siteUrl = (string) DB::table('seo_settings')
                    ->where('workspace_id', $kw->workspace_id)
                    ->where('key', 'site_url')
                    ->value('value');
                $domain = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : null;

                // Fetch volume/CPC/competition for this keyword.
                $kwDataResp = $dfs->keywordData([$kw->keyword]);
                $kwRow = ($kwDataResp['success'] ?? false) && !empty($kwDataResp['keywords'])
                    ? $kwDataResp['keywords'][0]
                    : [];

                // Rank-track if we have a domain. If no site_url is configured
                // we can still update volume/difficulty/cpc — just skip rank.
                $newRank = null;
                $rankUrl = null;
                if ($domain) {
                    $rankResp = $dfs->trackKeywordRank($kw->keyword, $domain);
                    if ($rankResp['success'] ?? false) {
                        $newRank = isset($rankResp['position']) ? (int) $rankResp['position'] : null;
                        $rankUrl = $rankResp['url'] ?? null;
                    }
                }

                $update = [
                    'volume'          => $kwRow['volume'] ?? $kw->volume,
                    'difficulty'      => $kwRow['competition_index'] ?? $kw->difficulty,
                    'cpc'             => $kwRow['cpc'] ?? $kw->cpc,
                    'previous_rank'   => $kw->current_rank,
                    'current_rank'    => $newRank,
                    'rank_change'     => ($newRank !== null && $kw->current_rank !== null)
                                          ? ($kw->current_rank - $newRank)
                                          : null,
                    'last_rank_check' => now(),
                    'updated_at'      => now(),
                ];
                if ($rankUrl) { $update['rank_url'] = $rankUrl; }

                DB::table('seo_keywords')->where('id', $kw->id)->update($update);

                $rankStr = $newRank !== null ? "#{$newRank}" : 'NA';
                $this->line("  ✓ ws={$kw->workspace_id} [{$kw->keyword}] rank={$rankStr} vol=" . ($kwRow['volume'] ?? 'NA'));
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('seo:rank-track failed', [
                    'keyword_id' => $kw->id,
                    'keyword'    => $kw->keyword,
                    'workspace'  => $kw->workspace_id,
                    'error'      => $e->getMessage(),
                ]);
                $this->line("  ✗ ws={$kw->workspace_id} [{$kw->keyword}] " . $e->getMessage());
            }
        }

        $this->info("Done. Updated: {$updated}. Failed: {$failed}.");
        return 0;
    }
}
