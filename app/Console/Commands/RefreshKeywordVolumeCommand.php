<?php

namespace App\Console\Commands;

use App\Connectors\DataForSeoConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DFS-F1 (2026-06-21) — batched MONTHLY search-volume refresh.
 *
 * Replaces the per-keyword DAILY keywordData() calls that seo:rank-track made
 * (the dominant DataForSEO cost leak: ~$0.05/task x 1 keyword x daily). Google
 * Ads search volume is a monthly metric and the endpoint bills per task (up to
 * 1000 keywords per call), so this refreshes volume/difficulty/cpc for ALL of a
 * workspace's tracked keywords in ONE call, once a month.
 *
 * Updates ONLY volume/difficulty/cpc — never rank fields (rank position is
 * owned by seo:track-ranks). Preserves the exact column mapping that
 * seo:rank-track used (volume, competition_index -> difficulty, cpc).
 */
class RefreshKeywordVolumeCommand extends Command
{
    protected $signature   = 'seo:refresh-volume {--workspace= : Limit to one workspace}';
    protected $description = 'Monthly batched DataForSEO search-volume/difficulty/CPC refresh for tracked keywords';

    public function handle(DataForSeoConnector $dfs): int
    {
        if (! $dfs->isConfigured()) {
            $this->error('DataForSEO not configured (DATAFORSEO_LOGIN/PASSWORD env missing).');
            return 1;
        }

        $wsFilter = $this->option('workspace');
        $workspaceIds = DB::table('seo_keywords')
            ->where('status', 'tracking')
            ->when($wsFilter, fn ($q) => $q->where('workspace_id', (int) $wsFilter))
            ->distinct()
            ->pluck('workspace_id');

        if ($workspaceIds->isEmpty()) {
            $this->info('No tracked keywords to refresh.');
            return 0;
        }

        $this->info("Refreshing search volume for {$workspaceIds->count()} workspace(s)…");
        $totalUpdated = 0;
        $totalCalls   = 0;

        foreach ($workspaceIds as $wsId) {
            $rows = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->where('status', 'tracking')
                ->get(['id', 'keyword', 'volume', 'difficulty', 'cpc']);
            if ($rows->isEmpty()) {
                continue;
            }

            // Batch in chunks of 100 (DataForSEO Google Ads search_volume max).
            foreach ($rows->chunk(100) as $chunk) {
                $keywords = $chunk->pluck('keyword')->values()->all();
                $resp = $dfs->keywordData($keywords);
                $totalCalls++;

                if (! ($resp['success'] ?? false)) {
                    $this->warn("  ws={$wsId}: keywordData failed — " . ($resp['error'] ?? 'unknown'));
                    Log::warning('seo:refresh-volume keywordData failed', [
                        'workspace' => $wsId, 'error' => $resp['error'] ?? null,
                    ]);
                    continue;
                }

                // Index returned rows by keyword for O(1) lookup.
                $byKeyword = [];
                foreach (($resp['keywords'] ?? []) as $r) {
                    if (! empty($r['keyword'])) {
                        $byKeyword[mb_strtolower($r['keyword'])] = $r;
                    }
                }

                foreach ($chunk as $kw) {
                    $data = $byKeyword[mb_strtolower($kw->keyword)] ?? null;
                    if (! $data) {
                        continue;
                    }
                    DB::table('seo_keywords')->where('id', $kw->id)->update([
                        'volume'     => $data['volume'] ?? $kw->volume,
                        'difficulty' => $data['competition_index'] ?? $kw->difficulty,
                        'cpc'        => $data['cpc'] ?? $kw->cpc,
                        'updated_at' => now(),
                    ]);
                    $totalUpdated++;
                }
            }
        }

        $this->info("Done. Workspaces: {$workspaceIds->count()} | DFS calls: {$totalCalls} | keywords updated: {$totalUpdated}");
        return 0;
    }
}
