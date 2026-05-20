<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Engines\SEO\Services\BuilderPageIndexer;

/**
 * Wave 52 — Reindex Laravel builder pages into seo_content_index.
 *
 * Backfills published pages that were created before Wave 52's on-publish
 * hook existed. Also runs daily as a catch-all in case the hook missed
 * something (cache invalidation, mid-flight publish failure, etc.).
 *
 * Use --workspace=ID to target a single workspace.
 */
class ReindexBuilderPagesCommand extends Command
{
    protected $signature = 'seo:reindex-builder-pages {--workspace= : Only this workspace ID}';
    protected $description = 'Reindex Laravel builder pages into seo_content_index';

    public function handle(BuilderPageIndexer $indexer): int
    {
        $wsFilter = $this->option('workspace');

        $workspaces = DB::table('workspaces')
            ->when($wsFilter, fn($q) => $q->where('id', $wsFilter))
            ->pluck('id');

        $total = 0;
        foreach ($workspaces as $wsId) {
            $n = $indexer->indexWorkspace((int) $wsId);
            if ($n > 0) {
                $this->line("  ws={$wsId}: {$n} pages indexed");
                $total += $n;
            }
        }
        $this->info("Reindex done: {$total} pages across " . $workspaces->count() . " workspaces");
        return self::SUCCESS;
    }
}
