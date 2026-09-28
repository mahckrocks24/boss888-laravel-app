<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** LIFECYCLE-1: social nudges (2 h and day 1 after sign-up) and the day-before trial notice. Every 15 minutes. */
class LifecycleTick extends Command
{
    protected $signature = 'lifecycle:tick';
    protected $description = 'Send the lifecycle emails that are due (social nudges, trial ending tomorrow)';

    public function handle(): int
    {
        $n = app(\App\Core\Lifecycle\LifecycleEmails::class)->tick();
        $this->info(json_encode($n));
        if (array_sum($n) > 0) \Illuminate\Support\Facades\Log::info('[LIFECYCLE-1] tick', $n);
        return self::SUCCESS;
    }
}