<?php

namespace App\Jobs;

use App\Core\Growth\CheckinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** WATCH-1: the owner answered one of Sarah's check-ins — she learns from it. */
class CheckinAbsorbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $wsId, public int $checkinId, public int $messageId) { $this->onQueue('default'); }

    public function handle(CheckinService $checkins): void
    {
        try {
            $r = $checkins->absorb($this->wsId, $this->checkinId, $this->messageId);
            Log::info('[WATCH-1] check-in answer', ['ws' => $this->wsId, 'checkin' => $this->checkinId] + $r);
        } catch (\Throwable $e) {
            Log::warning('[WATCH-1] absorb failed', ['ws' => $this->wsId, 'e' => $e->getMessage()]);
        }
    }
}
