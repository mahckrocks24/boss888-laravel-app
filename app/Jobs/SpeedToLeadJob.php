<?php

namespace App\Jobs;

use App\Engines\CRM\Services\SarahClients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** CRM-SARAH-3: a new enquiry, about a minute old — Sarah writes the reply (and sends or shows it, as the owner chose). */
class SpeedToLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public int $leadId) { $this->onQueue('default'); }

    public function handle(SarahClients $sarah): void
    {
        $out = $sarah->onNewLead($this->leadId);
        Log::info('[CRM-SARAH-3] speed to lead', ['lead' => $this->leadId, 'result' => $out]);
    }
}
