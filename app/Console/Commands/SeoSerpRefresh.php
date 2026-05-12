<?php

namespace App\Console\Commands;

use App\Connectors\DataForSeoConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh top-20 SERP results for top-10 keywords (by volume) per workspace.
 * Mirrors the existing on-demand serp_analysis pattern: writes a parent
 * seo_audits row of type=serp, then bulk-inserts seo_serp_results rows
 * pointing at that audit_id (audit_id is NOT NULL on seo_serp_results).
 *
 * Workspaces are enumerated by joining seo_settings where key=site_url —
 * the workspaces table has no `url` or `status` column.
 */
class SeoSerpRefresh extends Command
{
    protected $signature   = 'seo:serp-refresh';
    protected $description = 'Refresh top-20 SERP results for top-10 keywords per workspace via DataForSEO';

    public function handle(DataForSeoConnector $dfs): int
    {
        if (!$dfs->isConfigured()) {
            $this->error('DataForSEO not configured.');
            return 1;
        }

        // Workspaces with a site_url set.
        $workspaceIds = DB::table('seo_settings')
            ->where('key', 'site_url')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->pluck('workspace_id')
            ->unique()
            ->values();

        if ($workspaceIds->isEmpty()) {
            $this->info('No workspaces with site_url configured.');
            return 0;
        }

        $this->info("Refreshing SERPs for {$workspaceIds->count()} workspace(s)…");
        $total = 0;
        $failed = 0;

        foreach ($workspaceIds as $wsId) {
            // Top 10 keywords by volume, stale > 24h. NULL volume sorts last.
            $keywords = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->where(function ($q) {
                    $q->whereNull('last_serp_check')
                      ->orWhere('last_serp_check', '<', now()->subHours(24));
                })
                ->orderByRaw('volume IS NULL, volume DESC')
                ->limit(10)
                ->get();

            if ($keywords->isEmpty()) { continue; }

            foreach ($keywords as $kw) {
                try {
                    $serp = $dfs->serpAnalysis($kw->keyword);
                    if (!($serp['success'] ?? false) || empty($serp['top_results'])) {
                        $this->line("  · ws={$wsId} [{$kw->keyword}] no results");
                        continue;
                    }

                    // Create parent audit row (type=serp) so the FK on
                    // seo_serp_results.audit_id is satisfied.
                    $auditId = DB::table('seo_audits')->insertGetId([
                        'workspace_id' => $wsId,
                        'url'          => $kw->keyword,
                        'type'         => 'serp',
                        'status'       => 'completed',
                        'score'        => null,
                        'results_json' => json_encode([
                            'source'        => 'seo:serp-refresh',
                            'keyword'       => $kw->keyword,
                            'serp_features' => $serp['serp_features'] ?? [],
                            'total_results' => $serp['total_results'] ?? 0,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $now = now();
                    $rows = [];
                    foreach ($serp['top_results'] as $i => $r) {
                        $rows[] = [
                            'workspace_id' => $wsId,
                            'keyword'      => $kw->keyword,
                            'audit_id'     => $auditId,
                            'position'     => $r['position'] ?? ($i + 1),
                            'rank'         => $r['position'] ?? ($i + 1),
                            'url'          => $r['url'] ?? null,
                            'title'        => $r['title'] ?? null,
                            'domain'       => $r['domain'] ?? null,
                            'snippet'      => $r['snippet'] ?? null,
                            'features'     => json_encode($serp['serp_features'] ?? []),
                            'checked_at'   => $now,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ];
                    }
                    if (!empty($rows)) {
                        DB::table('seo_serp_results')->insert($rows);
                    }

                    DB::table('seo_keywords')
                        ->where('id', $kw->id)
                        ->update([
                            'last_serp_check' => $now,
                            'updated_at'      => $now,
                        ]);

                    $this->line("  ✓ ws={$wsId} [{$kw->keyword}] " . count($rows) . " result(s) -> audit#{$auditId}");
                    $total++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('seo:serp-refresh failed', [
                        'workspace_id' => $wsId,
                        'keyword'      => $kw->keyword,
                        'error'        => $e->getMessage(),
                    ]);
                    $this->line("  ✗ ws={$wsId} [{$kw->keyword}] " . $e->getMessage());
                }
            }
        }

        $this->info("Done. Refreshed: {$total}. Failed: {$failed}.");
        return 0;
    }
}
