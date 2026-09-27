<?php

namespace App\Jobs;

use App\Core\Growth\WatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** WATCH-1: one approved watch run (trends, competitors, mentions) for one business. */
class WatchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $watchId, public bool $manual = false) { $this->onQueue('default'); }

    public function handle(WatchService $watch): void
    {
        try {
            $watch->run($this->watchId, $this->manual);
        } catch (\Throwable $e) {
            Log::warning('[WATCH-1] run failed', ['watch' => $this->watchId, 'e' => $e->getMessage()]);
        }
    }
}
