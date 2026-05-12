<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // 2026-05-13 Phase 1 — SEO scheduled jobs.
        $schedule->command('seo:insights')->daily()->withoutOverlapping();
        $schedule->command('seo:authority-score')->weekly()->sundays()->at('03:00')->withoutOverlapping();
        $schedule->command('seo:outbound-check')->twiceDaily(2, 14)->withoutOverlapping();

        // 2026-05-15 Phase 3 — semantic clustering rebuild.
        $schedule->command('seo:cluster')->weekly()->sundays()->at('04:00')->withoutOverlapping();

        // 2026-05-13 — DataForSEO rank + SERP refresh.
        $schedule->command('seo:rank-track')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('seo:serp-refresh')->dailyAt('03:30')->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
