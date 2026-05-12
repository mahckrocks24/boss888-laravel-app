<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * 2026-05-13 — Laravel 11 routes scheduled tasks through bootstrap/app.php.
     * This Kernel::schedule() method is no longer invoked. All previous
     * entries (seo:insights / seo:authority-score / seo:outbound-check /
     * seo:cluster) moved to bootstrap/app.php in commit landing today.
     *
     * Method kept empty (rather than deleted) so anything in the framework
     * that still calls it has a safe no-op.
     */
    protected function schedule(Schedule $schedule): void
    {
        // intentionally empty — see bootstrap/app.php for the live scheduler.
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
