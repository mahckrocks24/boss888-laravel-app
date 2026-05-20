<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 49c — Keep aeo_traffic at 90 days. Each row is ~200B; at scale
 * (say 10k events/day) the table would otherwise grow ~6MB/month per
 * tenant. 90-day retention keeps reporting useful while bounding growth.
 */
class AeoCleanupTrafficCommand extends Command
{
    protected $signature = 'aeo:cleanup-traffic {--days=90 : Retention window in days}';
    protected $description = 'Delete aeo_traffic rows older than retention window';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = DB::table('aeo_traffic')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} aeo_traffic rows older than {$days} days");
        return self::SUCCESS;
    }
}
