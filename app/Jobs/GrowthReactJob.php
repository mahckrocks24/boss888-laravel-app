<?php

namespace App\Jobs;

use App\Core\Growth\SignalReactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** WATCH-1: Sarah reacts to new signals for one business right away (results, the world, the owner's answers). */
class GrowthReactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 200;

    public function __construct(public int $wsId, public ?int $businessId) { $this->onQueue('default'); }

    public function handle(SignalReactor $reactor): void
    {
        try {
            $r = $reactor->react($this->wsId, $this->businessId);
            if (($r['reacted'] ?? 0) > 0) Log::info('[WATCH-1] react job', ['ws' => $this->wsId, 'biz' => $this->businessId] + $r);
        } catch (\Throwable $e) {
            Log::warning('[WATCH-1] react job failed', ['ws' => $this->wsId, 'e' => $e->getMessage()]);
        }
    }
}
