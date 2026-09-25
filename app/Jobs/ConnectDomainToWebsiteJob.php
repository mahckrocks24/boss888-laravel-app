<?php

namespace App\Jobs;

use App\Models\CustomerDomain;
use App\Services\Domains\DomainLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CONNECT-AUTO-1 (RFC-0015): attach www.<domain> to the linked website through the
 * locked custom-hostname path. One attempt per run; progress after the hostname exists
 * is polled by `domains:link-sweep`, not by re-queuing this job, so there is no chain to
 * lose across restarts and nothing to loop.
 */
class ConnectDomainToWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly string $domain)
    {
    }

    public function uniqueId(): string
    {
        return 'domain-connect-' . $this->domain;
    }

    public function handle(): void
    {
        $d = CustomerDomain::where('domain', $this->domain)->first();

        if ($d === null || ! $d->website_id) {
            return;
        }

        $svc = DomainLinkService::make();

        if ($d->connect_state === DomainLinkService::CONNECT_CONNECTING) {
            $svc->poll($d);

            return;
        }

        if (in_array($d->connect_state, [DomainLinkService::CONNECT_PENDING, DomainLinkService::CONNECT_WAITING], true)) {
            $r = $svc->connect($d);
            Log::info('ConnectDomainToWebsiteJob', ['domain' => $this->domain, 'state' => $r['state'], 'done' => $r['done']]);
        }
    }
}
