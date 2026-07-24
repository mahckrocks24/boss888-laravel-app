<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * studio:reap-stuck-renders — mark video designs whose export has been stuck in
 * `pending`/`processing` past a timeout as `failed`, so the editor never shows a
 * render "processing forever" (worker died, box restarted, ffmpeg killed, etc.).
 * This is the safety net for the exact failure the engine had before Phase 0.
 * Scheduled every 5 min. 2026-07-02.
 */
class ReapStuckRendersCommand extends Command
{
    protected $signature = 'studio:reap-stuck-renders {--minutes=20 : Consider a render stuck after this many minutes}';
    protected $description = 'Fail video exports stuck in pending/processing past the timeout (worker-death safety net).';

    public function handle(): int
    {
        $minutes = max(2, (int) $this->option('minutes'));
        $cutoff  = now()->subMinutes($minutes);

        $n = DB::table('studio_designs')
            ->where('design_type', 'video')
            ->whereIn('export_status', ['pending', 'processing'])
            ->where('updated_at', '<', $cutoff)
            ->update([
                'export_status' => 'failed',
                'export_error'  => "Render timed out after {$minutes}m (worker died or was killed) — auto-reaped. Try exporting again.",
                'updated_at'    => now(),
            ]);

        if ($n > 0) {
            Log::warning('[studio] reaped stuck video renders', ['count' => $n, 'older_than_min' => $minutes]);
        }
        $this->info("Reaped {$n} stuck render(s) (older than {$minutes}m).");
        return 0;
    }
}
