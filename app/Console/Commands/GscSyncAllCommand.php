<?php

namespace App\Console\Commands;

use App\Engines\SEO\Services\GscSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Google Search Console — daily sync for every connected workspace.
 *
 * For each gsc_connections row that is connected + has a chosen property,
 * pull the trailing-28-day performance data and upsert it into gsc_metrics.
 * Per-workspace failures (revoked token, quota) are logged and skipped so
 * one bad workspace never aborts the batch. Idempotent — safe to re-run.
 *
 * Flags:
 *   --workspace=ID   only this workspace
 *   --days=N         override the window (1..90, default 28)
 */
class GscSyncAllCommand extends Command
{
    protected $signature = 'gsc:sync-all
        {--workspace= : single workspace_id}
        {--days= : window size in days (1..90)}';

    protected $description = 'Sync Google Search Console performance data for all connected workspaces';

    public function handle(GscSyncService $sync): int
    {
        $q = DB::table('gsc_connections')->where('connected', true)->whereNotNull('site_url');
        if ($ws = $this->option('workspace')) {
            $q->where('workspace_id', (int) $ws);
        }
        $connections = $q->get();

        if ($connections->isEmpty()) {
            $this->info('[gsc:sync-all] No connected workspaces.');
            return self::SUCCESS;
        }

        $params = [];
        if ($days = $this->option('days')) {
            $params['days'] = (int) $days;
        }

        $okWs = 0; $totalRows = 0; $failed = 0;
        foreach ($connections as $c) {
            try {
                $res = $sync->sync((int) $c->workspace_id, $params);
                if ($res['success']) {
                    $okWs++;
                    $totalRows += (int) $res['rows_synced'];
                    $this->line("[gsc:sync-all] ws={$c->workspace_id} {$c->site_url} → {$res['rows_synced']} rows");
                } else {
                    $failed++;
                    $this->warn("[gsc:sync-all] ws={$c->workspace_id} skipped: {$res['message']}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[gsc:sync-all] ws={$c->workspace_id} failed: {$e->getMessage()}");
                \Illuminate\Support\Facades\Log::warning("[gsc:sync-all] ws={$c->workspace_id} failed: {$e->getMessage()}");
            }
        }

        $this->info("[gsc:sync-all] done — {$okWs} synced, {$failed} skipped/failed, {$totalRows} rows total.");
        return self::SUCCESS;
    }
}
