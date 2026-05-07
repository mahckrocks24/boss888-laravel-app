<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeNotifications extends Command
{
    protected $signature = 'lu:notifications:purge';

    protected $description = 'Delete notifications older than 90 days (retention policy)';

    public function handle(): int
    {
        $cutoff = now()->subDays(90);
        $count  = Notification::where('created_at', '<', $cutoff)->delete();

        $msg = "Purged {$count} notifications older than 90 days (cutoff: {$cutoff->toDateTimeString()})";
        $this->info($msg);
        Log::info("[lu:notifications:purge] {$msg}");

        return self::SUCCESS;
    }
}
