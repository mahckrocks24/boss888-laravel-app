<?php

namespace App\Console\Commands;

use App\Engines\Creative\Services\CreativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * video:finalize-pending — browser-independent completion worker for video jobs.
 *
 * 2026-08-10 — Before this, video generation returned in_progress immediately
 * and only advanced when a client HTTP-polled /assets/{id}/poll. If the tab
 * closed, the asset was stranded in_progress forever (and any reserved credit
 * with it). This command drives every in_progress video asset through
 * CreativeService::pollVideoJob() — poll provider → download → stitch →
 * finalize → credit settle — on a schedule, no browser required.
 *
 * Idempotent + bounded: ScenePlannerService::pollJob skips completed/failed/
 * timed_out scenes and caps at MAX_POLL_ATTEMPTS (→ timed_out); completeAsset/
 * failAsset move the asset out of in_progress so it is never re-picked, so there
 * is no duplicate finalization and no duplicate settlement.
 */
class VideoFinalizePendingCommand extends Command
{
    protected $signature = 'video:finalize-pending {--asset= : Only this asset id} {--limit=200 : Max assets per run}';
    protected $description = 'Advance in-progress video assets to completion without a browser (poll → finalize).';

    public function handle(CreativeService $creative): int
    {
        $q = DB::table('assets')
            ->where('type', 'video')
            ->where('status', 'in_progress')
            ->whereNull('deleted_at');

        if ($this->option('asset')) {
            $q->where('id', (int) $this->option('asset'));
        }

        $assetIds = $q->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');

        if ($assetIds->isEmpty()) {
            $this->info('No in-progress video assets.');
            return self::SUCCESS;
        }

        $done = 0; $failed = 0; $still = 0;
        foreach ($assetIds as $assetId) {
            try {
                $res    = $creative->pollVideoJob((int) $assetId);
                $status = $res['status'] ?? 'unknown';
                if ($status === 'completed')   { $done++; }
                elseif ($status === 'failed')  { $failed++; }
                else                            { $still++; }
                $this->line("  asset {$assetId} → {$status}");
            } catch (\Throwable $e) {
                Log::warning('[video:finalize-pending] error', ['asset' => $assetId, 'error' => $e->getMessage()]);
                $this->warn("  asset {$assetId} → error: {$e->getMessage()}");
            }
        }

        $this->info("Done. completed={$done} failed={$failed} still_in_progress={$still} of {$assetIds->count()}.");
        return self::SUCCESS;
    }
}
