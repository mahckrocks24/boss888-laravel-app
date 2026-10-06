<?php

namespace App\Jobs;

use App\Engines\CRM\Services\LeadsAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** LEADS-W1 (DEC-0089): read a new enquiry (hot / warm / cold and what to do); a hot one reaches the owner's phone. */
class LeadRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $leadId) {}

    public function handle(LeadsAssistant $la): void
    {
        $la->onNewLead($this->leadId);
    }
}
