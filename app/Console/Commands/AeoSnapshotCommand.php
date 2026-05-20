<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 49b — Daily snapshot of per-workspace AEO score + enrichment rate.
 * Populates aeo_score_snapshots so the SPA can render a trend chart.
 */
class AeoSnapshotCommand extends Command
{
    protected $signature = 'aeo:snapshot {--workspace= : Only snapshot one workspace}';
    protected $description = 'Capture daily AEO score + enrichment counts per workspace';

    public function handle(): int
    {
        $wsIdFilter = $this->option('workspace');

        // Get all workspaces that have any AEO data
        $workspaces = DB::table('workspaces')
            ->when($wsIdFilter, fn($q) => $q->where('id', $wsIdFilter))
            ->whereNull('deleted_at')
            ->pluck('id');

        $count = 0;
        foreach ($workspaces as $wsId) {
            $auditAgg = DB::table('aeo_audits')
                ->where('workspace_id', $wsId)
                ->selectRaw('COUNT(*) as c, ROUND(AVG(score)) as avg_score')
                ->first();

            $articleAgg = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN aeo_enriched_at IS NOT NULL THEN 1 ELSE 0 END) as enriched')
                ->first();

            // Skip workspaces with no AEO activity at all to keep table clean.
            if (((int) ($auditAgg->c ?? 0)) === 0 && ((int) ($articleAgg->total ?? 0)) === 0) {
                continue;
            }

            DB::table('aeo_score_snapshots')->insert([
                'workspace_id'      => $wsId,
                'avg_score'         => $auditAgg->avg_score !== null ? (int) $auditAgg->avg_score : null,
                'audits_count'      => (int) ($auditAgg->c ?? 0),
                'articles_total'    => (int) ($articleAgg->total ?? 0),
                'articles_enriched' => (int) ($articleAgg->enriched ?? 0),
                'captured_at'       => now(),
            ]);
            $count++;
        }

        $this->info("Snapshot done: {$count} workspaces");
        return self::SUCCESS;
    }
}
